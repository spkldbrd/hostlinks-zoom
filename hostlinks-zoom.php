<?php
/**
 * Plugin Name: Hostlinks Zoom
 * Plugin URI:  https://digitalsolution.com
 * Description: Match past Zoom classes to cloud recordings and email replay links to active registrants.
 * Version:     1.0.1
 * Author:      Digital Solution
 * Author URI:  https://digitalsolution.com
 * License:     GPL2
 * Requires PHP: 8.0
 * Requires Plugins: hostlinks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HLZ_VERSION', '1.0.1' );
define( 'HLZ_DB_VERSION', '1.0.0' );
define( 'HLZ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HLZ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HLZ_PLUGIN_FILE', __FILE__ );

require_once HLZ_PLUGIN_DIR . 'includes/class-hlz-db.php';
require_once HLZ_PLUGIN_DIR . 'includes/class-hlz-settings.php';
require_once HLZ_PLUGIN_DIR . 'includes/class-hlz-bridge.php';
require_once HLZ_PLUGIN_DIR . 'includes/class-hlz-zoom.php';
require_once HLZ_PLUGIN_DIR . 'includes/class-hlz-templates.php';
require_once HLZ_PLUGIN_DIR . 'includes/class-hlz-shares.php';
require_once HLZ_PLUGIN_DIR . 'includes/class-hlz-matcher.php';
require_once HLZ_PLUGIN_DIR . 'includes/class-hlz-recipients.php';
require_once HLZ_PLUGIN_DIR . 'includes/class-hlz-mailer.php';
require_once HLZ_PLUGIN_DIR . 'includes/class-hlz-cron.php';
require_once HLZ_PLUGIN_DIR . 'includes/class-hlz-updater.php';
require_once HLZ_PLUGIN_DIR . 'admin/class-hlz-admin.php';

HLZ_Updater::init( __FILE__, 'spkldbrd', 'hostlinks-zoom' );

register_activation_hook( __FILE__, array( 'HLZ_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HLZ_Cron', 'unschedule' ) );

add_action( 'plugins_loaded', function () {
	HLZ_DB::maybe_upgrade();
	HLZ_Cron::init();
	if ( is_admin() ) {
		HLZ_Admin::init();
	}
}, 20 );
