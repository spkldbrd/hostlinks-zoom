<?php
/**
 * Plugin settings. Zoom secrets stay in this option, never in the repo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HLZ_Settings {

	const OPTION = 'hlz_settings';

	public static function defaults(): array {
		return array(
			'account_id'    => '',
			'client_id'     => '',
			'client_secret' => '',
			'host_email'    => 'zoom@grantwritingusa.com',
			'from_email'    => 'cs@grantwritingusa.com',
			'from_name'     => 'Client Services Team',
		);
	}

	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	public static function update( array $input ): void {
		$current = self::get();
		$secret  = isset( $input['client_secret'] ) ? trim( (string) $input['client_secret'] ) : '';

		$next = array(
			'account_id'    => sanitize_text_field( $input['account_id'] ?? '' ),
			'client_id'     => sanitize_text_field( $input['client_id'] ?? '' ),
			'client_secret' => $secret !== '' ? $secret : $current['client_secret'],
			'host_email'    => sanitize_email( $input['host_email'] ?? '' ),
			'from_email'    => sanitize_email( $input['from_email'] ?? '' ),
			'from_name'     => sanitize_text_field( $input['from_name'] ?? '' ),
		);

		if ( $next['host_email'] === '' ) {
			$next['host_email'] = self::defaults()['host_email'];
		}
		if ( $next['from_email'] === '' ) {
			$next['from_email'] = self::defaults()['from_email'];
		}
		if ( $next['from_name'] === '' ) {
			$next['from_name'] = self::defaults()['from_name'];
		}

		update_option( self::OPTION, $next, false );
		delete_transient( 'hlz_zoom_token' );
	}

	public static function has_credentials(): bool {
		$s = self::get();
		return $s['account_id'] !== '' && $s['client_id'] !== '' && $s['client_secret'] !== '';
	}
}
