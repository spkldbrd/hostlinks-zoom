<?php
/**
 * Daily job that turns off sent share links after 30 days.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HLZ_Cron {

	const HOOK = 'hlz_expire_shares';

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		self::schedule();
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run(): void {
		if ( ! HLZ_Settings::has_credentials() ) {
			return;
		}
		HLZ_Shares::expire_due();
	}
}
