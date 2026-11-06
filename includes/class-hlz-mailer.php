<?php
/**
 * Fill a class template and send one plain-text message per registrant.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HLZ_Mailer {

	const NOTE_TTL = HOUR_IN_SECONDS;

	public static function note_key( int $eve_id ): string {
		return 'hlz_note_' . get_current_user_id() . '_' . $eve_id;
	}

	public static function remembered_note( int $eve_id ): string {
		$note = get_transient( self::note_key( $eve_id ) );
		return is_string( $note ) ? $note : '';
	}

	public static function remember_note( int $eve_id, string $note ): void {
		set_transient( self::note_key( $eve_id ), $note, self::NOTE_TTL );
	}

	public static function template_for_event( array $event ): ?object {
		$override = get_option( 'hlz_template_overrides', array() );
		$eve_id   = (int) $event['eve_id'];
		if ( is_array( $override ) && ! empty( $override[ $eve_id ] ) ) {
			$row = HLZ_Templates::get( (string) $override[ $eve_id ] );
			if ( $row ) {
				return $row;
			}
		}
		$slug = HLZ_Templates::detect( HLZ_Bridge::event_title( $event ) );
		return $slug === '' ? null : HLZ_Templates::get( $slug );
	}

	public static function set_template_override( int $eve_id, string $slug ): void {
		$map = get_option( 'hlz_template_overrides', array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		if ( $slug === '' ) {
			unset( $map[ $eve_id ] );
		} else {
			$map[ $eve_id ] = $slug;
		}
		update_option( 'hlz_template_overrides', $map, false );
	}

	/**
	 * @param array<int,object> $recordings keyed by day_index
	 */
	public static function render( object $template, array $event, array $recordings, string $note ): string {
		$dates = HLZ_Bridge::format_date_range(
			(string) ( $event['eve_start'] ?? '' ),
			(string) ( $event['eve_end'] ?? '' )
		);
		$class_name = (string) $template->label;
		$body       = (string) $template->body;

		$body = str_replace(
			array( '{class_name}', '{dates}', '{note}', '{zoom}' ),
			array( $class_name, $dates, $note, self::zoom_block( $recordings ) ),
			$body
		);

		if ( trim( $note ) === '' ) {
			$body = preg_replace( "/\n{3,}/", "\n\n", $body );
		}

		return trim( (string) $body ) . "\n";
	}

	public static function render_subject( object $template, array $event ): string {
		$dates = HLZ_Bridge::format_date_range(
			(string) ( $event['eve_start'] ?? '' ),
			(string) ( $event['eve_end'] ?? '' )
		);
		return str_replace(
			array( '{class_name}', '{dates}' ),
			array( (string) $template->label, $dates ),
			(string) $template->subject
		);
	}

	/**
	 * @param array<int,object> $recordings
	 */
	public static function zoom_block( array $recordings ): string {
		$ready = array();
		foreach ( $recordings as $row ) {
			$url = trim( (string) $row->share_url );
			$pass = trim( (string) $row->passcode );
			if ( $url === '' || $pass === '' || (string) $row->status === 'off' ) {
				continue;
			}
			$ready[] = $row;
		}
		if ( empty( $ready ) ) {
			return '(Share links appear here after they are prepared.)';
		}

		$multi = false;
		foreach ( $recordings as $row ) {
			if ( (int) $row->day_index > 1 ) {
				$multi = true;
			}
		}
		$lines = array();
		foreach ( $ready as $row ) {
			if ( $multi ) {
				$lines[] = 'Day ' . (int) $row->day_index . ':';
			}
			$lines[] = trim( (string) $row->share_url );
			$lines[] = 'Passcode: ' . trim( (string) $row->passcode );
			$lines[] = '';
		}
		return trim( implode( "\n", $lines ) );
	}

	/**
	 * @param array<int,array{name:string,email:string,status:string}> $recipients
	 * @param array<int,object> $recordings
	 * @return array{sent:int,failed:int,skipped:int}
	 */
	public static function send( array $event, object $template, array $recordings, array $recipients, string $note, bool $failures_only ): array {
		$eve_id  = (int) $event['eve_id'];
		$subject = self::render_subject( $template, $event );
		$body    = self::render( $template, $event, $recordings, $note );
		$from    = HLZ_Settings::get();
		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . self::from_header( $from['from_name'], $from['from_email'] ),
		);

		$prior = array();
		foreach ( HLZ_Shares::sends_for_event( $eve_id ) as $row ) {
			$prior[ strtolower( (string) $row->email ) ] = $row;
		}

		$sent    = 0;
		$failed  = 0;
		$skipped = 0;

		foreach ( $recipients as $person ) {
			$email = strtolower( $person['email'] );
			$prev  = $prior[ $email ] ?? null;
			$had_error = $prev && trim( (string) $prev->error ) !== '';
			$already   = $prev && trim( (string) $prev->sent_at ) !== '' && ! $had_error;

			if ( $failures_only ) {
				if ( ! $had_error ) {
					$skipped++;
					continue;
				}
			} elseif ( $already || $had_error ) {
				$skipped++;
				continue;
			}

			$ok = wp_mail( $person['email'], $subject, $body, $headers );
			if ( $ok ) {
				HLZ_Shares::record_send( $eve_id, $email, '' );
				$sent++;
			} else {
				HLZ_Shares::record_send( $eve_id, $email, 'wp_mail returned false. Check SMTP2GO.' );
				$failed++;
			}
		}

		if ( $sent > 0 ) {
			HLZ_Shares::mark_sent( $eve_id );
		}

		return array(
			'sent'    => $sent,
			'failed'  => $failed,
			'skipped' => $skipped,
		);
	}

	private static function from_header( string $name, string $email ): string {
		$name = trim( str_replace( array( "\r", "\n" ), '', $name ) );
		if ( $name === '' ) {
			return $email;
		}
		return $name . ' <' . $email . '>';
	}
}
