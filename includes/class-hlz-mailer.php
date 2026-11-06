<?php
/**
 * Fill a class template and send one HTML message per registrant.
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
	 * Plain seeded letters become paragraphs so the editor can bold them.
	 */
	public static function body_for_editor( string $body ): string {
		return self::preserve_line_breaks( $body );
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
		$body      = self::preserve_line_breaks( (string) $template->body );
		$note_html = trim( $note ) === '' ? '' : nl2br( esc_html( $note ) );
		if ( trim( $note ) === '' ) {
			$body = preg_replace( '/\{note\}(?:\s|<br\s*\/?>)*/i', '', $body );
		}

		$body = str_replace(
			array( '{class_name}', '{dates}', '{note}', '{zoom}' ),
			array( esc_html( $class_name ), esc_html( $dates ), $note_html, self::zoom_block( $recordings ) ),
			$body
		);

		$body = preg_replace( '/<p>(?:\s|&nbsp;|<br\s*\/?>)*<\/p>/i', '', $body );

		return trim( (string) $body );
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
			return esc_html( '(Share links appear here after they are prepared.)' );
		}

		$ready = HLZ_Shares::in_date_order( $ready );
		$multi = count( $ready ) > 1;
		$blocks = array();
		$day_number = 0;
		foreach ( $ready as $row ) {
			$day_number++;
			$url   = esc_url( trim( (string) $row->share_url ) );
			$label = esc_html( trim( (string) $row->share_url ) );
			$pass  = esc_html( trim( (string) $row->passcode ) );
			$html  = '';
			if ( $multi ) {
				$html .= '<strong>Day ' . $day_number . ':</strong><br>';
			}
			$html    .= '<a href="' . $url . '">' . $label . '</a><br>';
			$html    .= 'Passcode: ' . $pass;
			$blocks[] = $html;
		}
		return implode( '<br><br>', $blocks );
	}

	/**
	 * @param array<int,array{name:string,email:string,status:string}> $recipients
	 * @param array<int,object> $recordings
	 * @return array{sent:int,failed:int,skipped:int}
	 */
	public static function send( array $event, object $template, array $recordings, array $recipients, string $note, bool $failures_only ): array {
		$eve_id  = (int) $event['eve_id'];
		$subject = self::render_subject( $template, $event );
		$body    = self::message_html( self::render( $template, $event, $recordings, $note ) );
		$headers = self::headers();

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

	/**
	 * One copy to a single address. Does not log a class send or start the share clock.
	 *
	 * @param array<int,object> $recordings
	 * @return true|WP_Error
	 */
	public static function send_test( array $event, object $template, array $recordings, string $note, string $to ) {
		$to = sanitize_email( $to );
		if ( ! is_email( $to ) ) {
			return new WP_Error( 'hlz_test', 'Enter a valid email address for the test.' );
		}

		$subject = '[Test] ' . self::render_subject( $template, $event );
		$banner  = '<p><em>This is a test. It was not sent to the class, and it did not start the 30-day share clock.</em></p>';
		$body    = self::message_html( $banner . self::render( $template, $event, $recordings, $note ) );
		$ok      = wp_mail( $to, $subject, $body, self::headers() );
		if ( ! $ok ) {
			return new WP_Error( 'hlz_test', 'The test email did not send. Check SMTP2GO.' );
		}
		return true;
	}

	/**
	 * @return array<int,string>
	 */
	private static function headers(): array {
		$from = HLZ_Settings::get();
		return array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . self::from_header( $from['from_name'], $from['from_email'] ),
		);
	}

	private static function message_html( string $body ): string {
		return '<div style="font-family:Calibri,Arial,sans-serif;font-size:16px;line-height:1.45;color:#222;">' . $body . '</div>';
	}

	private static function is_html( string $body ): bool {
		return (bool) preg_match( '/<(p|br|strong|b|em|i|u|a|ul|ol|li|div|span|h[1-6])\b/i', $body );
	}

	/**
	 * A letter with a link is HTML, but the line breaks around that link are still plain text.
	 * Turn those leftover returns into breaks without touching the tags.
	 */
	private static function preserve_line_breaks( string $body ): string {
		if ( ! self::is_html( $body ) ) {
			return wpautop( $body );
		}
		if ( ! preg_match( "/\n|\r/", $body ) ) {
			return $body;
		}
		$body  = str_replace( array( "\r\n", "\r" ), "\n", $body );
		$parts = preg_split( '/(<[^>]+>)/', $body, -1, PREG_SPLIT_DELIM_CAPTURE );
		$html  = '';
		foreach ( (array) $parts as $part ) {
			if ( $part === '' ) {
				continue;
			}
			if ( $part[0] === '<' ) {
				$html .= $part;
				continue;
			}
			if ( trim( $part ) === '' ) {
				continue;
			}
			$html .= str_replace( "\n", '<br>', $part );
		}
		return $html;
	}

	private static function from_header( string $name, string $email ): string {
		$name = trim( str_replace( array( "\r", "\n" ), '', $name ) );
		if ( $name === '' ) {
			return $email;
		}
		return $name . ' <' . $email . '>';
	}
}
