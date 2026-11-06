<?php
/**
 * Tables for templates, recording shares, and the send log.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HLZ_DB {

	public static function templates_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hlz_templates';
	}

	public static function recordings_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hlz_recordings';
	}

	public static function sends_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hlz_sends';
	}

	public static function activate(): void {
		if ( ! defined( 'HOSTLINKS_VERSION' ) ) {
			deactivate_plugins( plugin_basename( HLZ_PLUGIN_FILE ) );
			wp_die(
				'Hostlinks Zoom requires the Hostlinks plugin to be installed and active.',
				'Activation Error',
				array( 'back_link' => true )
			);
		}

		self::create_tables();
		HLZ_Templates::seed_missing();
		HLZ_Cron::schedule();
		update_option( 'hlz_db_version', HLZ_DB_VERSION );
		update_option( 'hlz_version', HLZ_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'hlz_db_version' ) === HLZ_DB_VERSION ) {
			return;
		}
		self::create_tables();
		if ( class_exists( 'HLZ_Templates', false ) ) {
			HLZ_Templates::seed_missing();
		}
		update_option( 'hlz_db_version', HLZ_DB_VERSION );
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$templates = self::templates_table();
		dbDelta(
			"CREATE TABLE {$templates} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(64) NOT NULL DEFAULT '',
				label varchar(191) NOT NULL DEFAULT '',
				match_phrase varchar(191) NOT NULL DEFAULT '',
				subject varchar(191) NOT NULL DEFAULT '',
				body longtext NOT NULL,
				updated_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug)
			) {$charset};"
		);

		$recordings = self::recordings_table();
		dbDelta(
			"CREATE TABLE {$recordings} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				eve_id bigint(20) unsigned NOT NULL DEFAULT 0,
				day_index tinyint(3) unsigned NOT NULL DEFAULT 1,
				meeting_id varchar(64) NOT NULL DEFAULT '',
				meeting_uuid varchar(128) NOT NULL DEFAULT '',
				topic varchar(500) NOT NULL DEFAULT '',
				recording_date date DEFAULT NULL,
				share_url text NULL,
				passcode varchar(20) NOT NULL DEFAULT '',
				share_origin varchar(32) NOT NULL DEFAULT '',
				match_source varchar(20) NOT NULL DEFAULT '',
				prepared_at datetime DEFAULT NULL,
				sent_at datetime DEFAULT NULL,
				expires_at datetime DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT '',
				last_error varchar(500) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY eve_day (eve_id, day_index)
			) {$charset};"
		);

		$sends = self::sends_table();
		dbDelta(
			"CREATE TABLE {$sends} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				eve_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(190) NOT NULL DEFAULT '',
				sent_at datetime DEFAULT NULL,
				error varchar(500) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY eve_email (eve_id, email),
				KEY eve_id (eve_id)
			) {$charset};"
		);
	}
}
