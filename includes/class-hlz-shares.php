<?php
/**
 * Stored recording matches, share state, and the per-address send log.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HLZ_Shares {

	/**
	 * @return array<int,object> keyed by day_index
	 */
	public static function for_event( int $eve_id ): array {
		global $wpdb;
		$table = HLZ_DB::recordings_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE eve_id = %d ORDER BY day_index ASC",
				$eve_id
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->day_index ] = $row;
		}
		return $out;
	}

	/**
	 * @param array<int,int> $eve_ids
	 * @return array<int,array<int,object>>
	 */
	public static function for_events( array $eve_ids ): array {
		global $wpdb;
		$eve_ids = array_values( array_filter( array_map( 'intval', $eve_ids ) ) );
		if ( empty( $eve_ids ) ) {
			return array();
		}
		$table        = HLZ_DB::recordings_table();
		$placeholders = implode( ',', array_fill( 0, count( $eve_ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE eve_id IN ($placeholders) ORDER BY eve_id ASC, day_index ASC",
				...$eve_ids
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->eve_id ][ (int) $row->day_index ] = $row;
		}
		return $out;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$table = HLZ_DB::recordings_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ) );
		return is_object( $row ) ? $row : null;
	}

	/**
	 * Rows a person can extend or turn off: prepared, currently shared, or recently turned off.
	 *
	 * @return array<int,object>
	 */
	public static function manageable(): array {
		global $wpdb;
		$table  = HLZ_DB::recordings_table();
		$cutoff = HLZ_Bridge::shift_date( wp_date( 'Y-m-d' ), -120 ) . ' 00:00:00';
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}`
				 WHERE status IN ('prepared','shared','off')
				   AND (
				     prepared_at >= %s
				     OR sent_at >= %s
				     OR expires_at >= %s
				   )
				 ORDER BY expires_at IS NULL, expires_at DESC, id DESC
				 LIMIT 100",
				$cutoff,
				$cutoff,
				$cutoff
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	public static function upsert_day( int $eve_id, int $day_index, array $fields ): void {
		global $wpdb;
		$table    = HLZ_DB::recordings_table();
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE eve_id = %d AND day_index = %d",
				$eve_id,
				$day_index
			)
		);

		$fields['eve_id']    = $eve_id;
		$fields['day_index'] = $day_index;

		if ( $existing ) {
			$wpdb->update( $table, $fields, array( 'id' => (int) $existing->id ) );
			return;
		}
		$wpdb->insert( $table, $fields );
	}

	public static function delete_day( int $eve_id, int $day_index ): void {
		global $wpdb;
		$wpdb->delete(
			HLZ_DB::recordings_table(),
			array(
				'eve_id'    => $eve_id,
				'day_index' => $day_index,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Turn sharing on, or keep a share Zoom already has.
	 *
	 * @param bool $replace Create a new passcode even when Zoom already has one.
	 * @return string|WP_Error kept, created, unchanged, or needs_passcode
	 */
	public static function prepare( object $row, bool $replace = false ) {
		$meeting_id = trim( (string) $row->meeting_id );
		if ( $meeting_id === '' ) {
			return new WP_Error( 'hlz_prepare', 'Choose a recording before preparing a share link.' );
		}

		if ( ! $replace && trim( (string) $row->share_url ) !== '' && trim( (string) $row->passcode ) !== '' ) {
			return 'unchanged';
		}

		if ( ! $replace ) {
			$existing = self::read_existing_share( $meeting_id );
			if ( is_wp_error( $existing ) ) {
				self::touch( (int) $row->id, array( 'last_error' => self::trim_error( $existing->get_error_message() ) ) );
				return $existing;
			}
			if ( $existing['shared'] && $existing['passcode'] !== '' ) {
				self::store_existing_share( $row, $existing );
				return 'kept';
			}
			if ( $existing['shared'] ) {
				self::touch(
					(int) $row->id,
					array(
						'share_url'    => $existing['url'],
						'share_origin' => $existing['mode'] === 'internally' ? 'needs_passcode_internal' : 'needs_passcode',
						'status'       => '',
						'last_error'   => 'Zoom already shared this recording, but did not return the passcode.',
					)
				);
				return 'needs_passcode';
			}
		}

		$passcode = ( ! $replace && trim( (string) $row->passcode ) !== '' )
			? trim( (string) $row->passcode )
			: HLZ_Zoom::generate_passcode();

		$published = HLZ_Zoom::publish_share( $meeting_id, $passcode );
		if ( is_wp_error( $published ) ) {
			self::touch(
				(int) $row->id,
				array(
					'last_error' => self::trim_error( $published->get_error_message() ),
				)
			);
			return $published;
		}

		$details = HLZ_Zoom::get_meeting_recordings( $meeting_id );
		if ( is_wp_error( $details ) ) {
			self::touch(
				(int) $row->id,
				array(
					'passcode'   => $passcode,
					'last_error' => self::trim_error( $details->get_error_message() ),
				)
			);
			return $details;
		}

		$share_url = trim( (string) ( $details['share_url'] ?? '' ) );
		if ( $share_url === '' ) {
			$error = new WP_Error( 'hlz_share_url', 'Zoom did not return a share link for meeting ' . $meeting_id . '.' );
			self::touch(
				(int) $row->id,
				array(
					'passcode'   => $passcode,
					'last_error' => $error->get_error_message(),
				)
			);
			return $error;
		}

		$status = ( (string) $row->sent_at !== '' ) ? 'shared' : 'prepared';
		self::touch(
			(int) $row->id,
			array(
				'share_url'    => $share_url,
				'passcode'     => $passcode,
				'share_origin' => 'created',
				'prepared_at'  => current_time( 'mysql' ),
				'status'       => $status,
				'last_error'   => '',
			)
		);
		return 'created';
	}

	/**
	 * @return array{kept:int,created:int,needs_passcode:int}|WP_Error
	 */
	public static function prepare_event( int $eve_id ) {
		$rows = self::for_event( $eve_id );
		if ( empty( $rows ) ) {
			return new WP_Error( 'hlz_prepare', 'Match a recording before preparing share links.' );
		}
		$summary = array(
			'kept'           => 0,
			'created'        => 0,
			'needs_passcode' => 0,
		);
		foreach ( $rows as $row ) {
			if ( trim( (string) $row->meeting_id ) === '' || (string) $row->status === 'off' ) {
				continue;
			}
			$result = self::prepare( $row, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( $result === 'kept' ) {
				$summary['kept']++;
			} elseif ( $result === 'created' ) {
				$summary['created']++;
			} elseif ( $result === 'needs_passcode' ) {
				$summary['needs_passcode']++;
			}
		}
		if ( $summary['kept'] + $summary['created'] + $summary['needs_passcode'] === 0 ) {
			$any = false;
			foreach ( $rows as $row ) {
				if ( trim( (string) $row->share_url ) !== '' && trim( (string) $row->passcode ) !== '' ) {
					$any = true;
				}
			}
			if ( ! $any ) {
				return new WP_Error( 'hlz_prepare', 'No matched recording is ready to share. A turned-off link stays off until you extend it.' );
			}
		}
		return $summary;
	}

	/**
	 * Save a passcode the user already knows. Does not change Zoom.
	 *
	 * @return true|WP_Error
	 */
	public static function save_known_passcode( int $id, string $passcode ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'hlz_share', 'Recording not found.' );
		}
		$passcode = trim( $passcode );
		if ( strlen( $passcode ) < 8 ) {
			return new WP_Error( 'hlz_share', 'Enter the existing Zoom passcode. It is at least 8 characters.' );
		}
		$origin = in_array( (string) $row->share_origin, array( 'existing_internal', 'needs_passcode_internal' ), true )
			? 'existing_internal'
			: 'existing';
		self::touch(
			$id,
			array(
				'passcode'     => $passcode,
				'share_origin' => $origin,
				'status'       => ( (string) $row->sent_at !== '' ) ? 'shared' : 'prepared',
				'prepared_at'  => (string) $row->prepared_at !== '' ? (string) $row->prepared_at : current_time( 'mysql' ),
				'last_error'   => '',
			)
		);
		return true;
	}

	/**
	 * @return array{url:string,passcode:string,mode:string,shared:bool}|WP_Error
	 */
	private static function read_existing_share( string $meeting_id ) {
		$settings = HLZ_Zoom::get_recording_settings( $meeting_id );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$details = HLZ_Zoom::get_meeting_recordings( $meeting_id );
		if ( is_wp_error( $details ) ) {
			return $details;
		}
		$mode = strtolower( trim( (string) ( $settings['share_recording'] ?? '' ) ) );
		$pass = trim( (string) ( $details['password'] ?? '' ) );
		if ( $pass === '' ) {
			$pass = trim( (string) ( $settings['password'] ?? '' ) );
		}
		$url = trim( (string) ( $details['share_url'] ?? '' ) );
		return array(
			'url'      => $url,
			'passcode' => $pass,
			'mode'     => $mode,
			'shared'   => in_array( $mode, array( 'publicly', 'internally' ), true ) && $url !== '',
		);
	}

	/**
	 * @param array{url:string,passcode:string,mode:string,shared:bool} $existing
	 */
	private static function store_existing_share( object $row, array $existing ): void {
		$origin = $existing['mode'] === 'internally' ? 'existing_internal' : 'existing';
		self::touch(
			(int) $row->id,
			array(
				'share_url'    => $existing['url'],
				'passcode'     => $existing['passcode'],
				'share_origin' => $origin,
				'prepared_at'  => current_time( 'mysql' ),
				'status'       => ( (string) $row->sent_at !== '' ) ? 'shared' : 'prepared',
				'last_error'   => '',
			)
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function extend( int $id ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'hlz_share', 'Recording not found.' );
		}
		$meeting_id = trim( (string) $row->meeting_id );
		$passcode   = trim( (string) $row->passcode );
		if ( $passcode === '' ) {
			$passcode = HLZ_Zoom::generate_passcode();
		}
		$published = HLZ_Zoom::publish_share( $meeting_id, $passcode );
		if ( is_wp_error( $published ) ) {
			self::touch( $id, array( 'last_error' => self::trim_error( $published->get_error_message() ) ) );
			return $published;
		}

		$details   = HLZ_Zoom::get_meeting_recordings( $meeting_id );
		$share_url = ( ! is_wp_error( $details ) ) ? trim( (string) ( $details['share_url'] ?? '' ) ) : '';
		$expires   = self::plus_days( 30 );
		$fields    = array(
			'passcode'   => $passcode,
			'expires_at' => $expires,
			'status'     => ( (string) $row->sent_at !== '' ) ? 'shared' : 'prepared',
			'last_error' => '',
		);
		if ( $share_url !== '' ) {
			$fields['share_url'] = $share_url;
		}
		if ( (string) $row->prepared_at === '' ) {
			$fields['prepared_at'] = current_time( 'mysql' );
		}
		self::touch( $id, $fields );
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function turn_off( int $id ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'hlz_share', 'Recording not found.' );
		}
		$meeting_id = trim( (string) $row->meeting_id );
		if ( $meeting_id === '' ) {
			return new WP_Error( 'hlz_share', 'This row has no Zoom meeting.' );
		}
		$result = HLZ_Zoom::unpublish_share( $meeting_id );
		if ( is_wp_error( $result ) ) {
			self::touch( $id, array( 'last_error' => self::trim_error( $result->get_error_message() ) ) );
			return $result;
		}
		self::touch(
			$id,
			array(
				'status'     => 'off',
				'last_error' => '',
			)
		);
		return true;
	}

	/**
	 * Sent shares whose 30 days are up.
	 *
	 * @return array{off:int,failed:int}
	 */
	public static function expire_due(): array {
		global $wpdb;
		$table = HLZ_DB::recordings_table();
		$now   = current_time( 'mysql' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}`
				 WHERE status = 'shared'
				   AND sent_at IS NOT NULL
				   AND expires_at IS NOT NULL
				   AND expires_at <= %s",
				$now
			)
		);

		$off    = 0;
		$failed = 0;
		foreach ( (array) $rows as $row ) {
			$result = self::turn_off( (int) $row->id );
			if ( is_wp_error( $result ) ) {
				$failed++;
				continue;
			}
			$off++;
		}
		return array(
			'off'    => $off,
			'failed' => $failed,
		);
	}

	/**
	 * Start the 30-day clock on prepared links for this class.
	 */
	public static function mark_sent( int $eve_id ): void {
		global $wpdb;
		$table = HLZ_DB::recordings_table();
		$rows  = self::for_event( $eve_id );
		$now   = current_time( 'mysql' );
		$exp   = self::plus_days( 30 );
		foreach ( $rows as $row ) {
			if ( trim( (string) $row->share_url ) === '' ) {
				continue;
			}
			if ( (string) $row->status === 'off' ) {
				continue;
			}
			$fields = array(
				'status' => 'shared',
			);
			if ( (string) $row->sent_at === '' ) {
				$fields['sent_at']    = $now;
				$fields['expires_at'] = $exp;
			}
			$wpdb->update( $table, $fields, array( 'id' => (int) $row->id ) );
		}
	}

	/**
	 * @return array<int,object>
	 */
	public static function sends_for_event( int $eve_id ): array {
		global $wpdb;
		$table = HLZ_DB::sends_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE eve_id = %d ORDER BY email ASC",
				$eve_id
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	public static function record_send( int $eve_id, string $email, string $error ): void {
		global $wpdb;
		$table = HLZ_DB::sends_table();
		$email = strtolower( trim( $email ) );
		$now   = $error === '' ? current_time( 'mysql' ) : null;
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE eve_id = %d AND email = %s",
				$eve_id,
				$email
			)
		);
		$data = array(
			'sent_at' => $now,
			'error'   => self::trim_error( $error ),
		);
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing->id ) );
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'eve_id'  => $eve_id,
				'email'   => $email,
				'sent_at' => $now,
				'error'   => self::trim_error( $error ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	private static function touch( int $id, array $fields ): void {
		global $wpdb;
		$wpdb->update( HLZ_DB::recordings_table(), $fields, array( 'id' => $id ) );
	}

	public static function plus_days( int $days ): string {
		$dt = new DateTime( 'now', wp_timezone() );
		$dt->modify( '+' . $days . ' days' );
		return $dt->format( 'Y-m-d H:i:s' );
	}

	private static function trim_error( string $message ): string {
		$message = trim( wp_strip_all_tags( $message ) );
		if ( strlen( $message ) > 500 ) {
			$message = substr( $message, 0, 497 ) . '...';
		}
		return $message;
	}
}
