<?php
/**
 * Zoom Server-to-Server OAuth and cloud recording calls.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HLZ_Zoom {

	const API = 'https://api.zoom.us/v2';

	/**
	 * @return string|WP_Error
	 */
	public static function token() {
		$cached = get_transient( 'hlz_zoom_token' );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		$settings = HLZ_Settings::get();
		if ( $settings['account_id'] === '' || $settings['client_id'] === '' || $settings['client_secret'] === '' ) {
			return new WP_Error( 'hlz_zoom_config', 'Zoom Account ID, Client ID, and Client Secret are required. Add them under Hostlinks Zoom → Settings.' );
		}

		$response = wp_remote_post(
			'https://zoom.us/oauth/token',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $settings['client_id'] . ':' . $settings['client_secret'] ),
					'Accept'        => 'application/json',
				),
				'body'    => array(
					'grant_type' => 'account_credentials',
					'account_id' => $settings['account_id'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
			$message = is_array( $body ) ? (string) ( $body['reason'] ?? $body['error'] ?? $body['message'] ?? '' ) : '';
			if ( $message === '' ) {
				$message = 'Zoom rejected the access token request (HTTP ' . $code . ').';
			}
			return new WP_Error( 'hlz_zoom_token', $message );
		}

		$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 60 );
		set_transient( 'hlz_zoom_token', (string) $body['access_token'], $ttl );
		return (string) $body['access_token'];
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @return array<string,mixed>|WP_Error
	 */
	public static function request( string $method, string $path, ?array $body = null ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		);
		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING );

		if ( $code === 204 ) {
			return array();
		}
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $decoded ) ? (string) ( $decoded['message'] ?? '' ) : '';
			if ( $message === '' ) {
				$message = 'Zoom API error (HTTP ' . $code . ').';
			}
			return new WP_Error( 'hlz_zoom_api', $message, array( 'status' => $code ) );
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Cloud recordings for the configured host between two Y-m-d dates, inclusive.
	 * Zoom allows about one month per request, so longer spans are split.
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function list_recordings( string $from, string $to ) {
		$settings = HLZ_Settings::get();
		$host     = $settings['host_email'];
		if ( $host === '' ) {
			return new WP_Error( 'hlz_zoom_host', 'Zoom host email is empty.' );
		}

		$start = HLZ_Bridge::parse_date( $from );
		$end   = HLZ_Bridge::parse_date( $to );
		if ( ! $start || ! $end ) {
			return new WP_Error( 'hlz_zoom_dates', 'A valid date range is required to list recordings.' );
		}
		if ( $end < $start ) {
			$swap  = $start;
			$start = $end;
			$end   = $swap;
		}

		$meetings = array();
		$cursor   = clone $start;
		$guard    = 0;
		while ( $cursor <= $end && $guard < 24 ) {
			$chunk_end = clone $cursor;
			$chunk_end->modify( '+29 days' );
			if ( $chunk_end > $end ) {
				$chunk_end = clone $end;
			}

			$page  = self::list_recordings_page( $host, $cursor->format( 'Y-m-d' ), $chunk_end->format( 'Y-m-d' ) );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			$meetings = array_merge( $meetings, $page );

			$cursor = clone $chunk_end;
			$cursor->modify( '+1 day' );
			$guard++;
		}

		return $meetings;
	}

	/**
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private static function list_recordings_page( string $host, string $from, string $to ) {
		$collected = array();
		$token     = '';
		$guard     = 0;

		do {
			$path = '/users/' . rawurlencode( $host ) . '/recordings?page_size=300&from=' . rawurlencode( $from ) . '&to=' . rawurlencode( $to );
			if ( $token !== '' ) {
				$path .= '&next_page_token=' . rawurlencode( $token );
			}
			$result = self::request( 'GET', $path );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! empty( $result['meetings'] ) && is_array( $result['meetings'] ) ) {
				foreach ( $result['meetings'] as $meeting ) {
					if ( is_array( $meeting ) ) {
						$collected[] = $meeting;
					}
				}
			}
			$token = isset( $result['next_page_token'] ) ? (string) $result['next_page_token'] : '';
			$guard++;
		} while ( $token !== '' && $guard < 20 );

		return $collected;
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_meeting_recordings( string $meeting_id ) {
		$meeting_id = trim( $meeting_id );
		if ( $meeting_id === '' ) {
			return new WP_Error( 'hlz_zoom_meeting', 'Meeting ID is missing.' );
		}
		return self::request( 'GET', '/meetings/' . self::encode_meeting_id( $meeting_id ) . '/recordings' );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return true|WP_Error
	 */
	public static function update_recording_settings( string $meeting_id, array $settings ) {
		$result = self::request(
			'PATCH',
			'/meetings/' . self::encode_meeting_id( $meeting_id ) . '/recordings/settings',
			$settings
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Public link, passcode, no download, no registration.
	 *
	 * @return true|WP_Error
	 */
	public static function publish_share( string $meeting_id, string $passcode ) {
		return self::update_recording_settings(
			$meeting_id,
			array(
				'share_recording'         => 'publicly',
				'password'                => $passcode,
				'viewer_download'         => false,
				'on_demand'               => false,
				'approval_type'           => 2,
				'recording_authentication'=> false,
			)
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function unpublish_share( string $meeting_id ) {
		return self::update_recording_settings(
			$meeting_id,
			array(
				'share_recording' => 'none',
			)
		);
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	public static function test_connection(): array {
		$end   = wp_date( 'Y-m-d' );
		$start = HLZ_Bridge::shift_date( $end, -6 );
		$list  = self::list_recordings( $start, $end );
		if ( is_wp_error( $list ) ) {
			return array(
				'ok'      => false,
				'message' => $list->get_error_message(),
			);
		}
		$host = HLZ_Settings::get()['host_email'];
		return array(
			'ok'      => true,
			'message' => 'Connected. ' . count( $list ) . ' cloud recording' . ( count( $list ) === 1 ? '' : 's' ) . ' for ' . $host . ' in the last 7 days.',
		);
	}

	public static function generate_passcode(): string {
		$lower   = 'abcdefghijkmnopqrstuvwxyz';
		$upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
		$digits  = '23456789';
		$special = '!@#$%&*';
		$chars   = array(
			self::rand_char( $lower ),
			self::rand_char( $upper ),
			self::rand_char( $digits ),
			self::rand_char( $special ),
		);
		$pool = $lower . $upper . $digits . $special;
		while ( count( $chars ) < 8 ) {
			$chars[] = self::rand_char( $pool );
		}
		shuffle( $chars );
		return implode( '', $chars );
	}

	private static function rand_char( string $pool ): string {
		$max = strlen( $pool ) - 1;
		return $pool[ random_int( 0, $max ) ];
	}

	/**
	 * Numeric meeting IDs are used as-is. UUIDs that contain slashes are double-encoded.
	 */
	public static function encode_meeting_id( string $meeting_id ): string {
		if ( str_contains( $meeting_id, '/' ) ) {
			return rawurlencode( rawurlencode( $meeting_id ) );
		}
		return rawurlencode( $meeting_id );
	}
}
