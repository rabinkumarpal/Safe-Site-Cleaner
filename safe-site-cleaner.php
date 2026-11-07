<?php
/**
 * Plugin Name:       Safe Site Cleaner
 * Description:       Finds unused plugins, media, leftovers and database bloat, and applies speed rules you can switch on and off. Checks real pages before and after, moves files to quarantine instead of deleting, and rolls back automatically if anything breaks.
 * Version:           1.1.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Plugin URI:        https://github.com/rabinkumarpal/Safe-Site-Cleaner
 * Author:            rabinkumarpal
 * Author URI:        https://github.com/rabinkumarpal
 * License:           GPL-2.0-or-later
 * Text Domain:       safe-site-cleaner
 */

defined( 'ABSPATH' ) || exit;

define( 'SSC_VERSION', '1.1.0' );
define( 'SSC_FILE', __FILE__ );
define( 'SSC_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSC_SLUG', 'safe-site-cleaner' );

require_once SSC_DIR . 'includes/class-ssc-store.php';
require_once SSC_DIR . 'includes/class-ssc-rules.php';
require_once SSC_DIR . 'includes/class-ssc-backup.php';
require_once SSC_DIR . 'includes/class-ssc-refs.php';
require_once SSC_DIR . 'includes/class-ssc-sitecheck.php';
require_once SSC_DIR . 'includes/class-ssc-analyzer.php';
require_once SSC_DIR . 'includes/class-ssc-engine.php';
require_once SSC_DIR . 'includes/class-ssc-rest.php';
require_once SSC_DIR . 'includes/class-ssc-admin.php';
require_once SSC_DIR . 'includes/speed/class-ssc-speed-rules.php';
require_once SSC_DIR . 'includes/speed/class-ssc-speed-recipes.php';
require_once SSC_DIR . 'includes/speed/class-ssc-speed.php';

register_activation_hook(
	__FILE__,
	static function () {
		if ( is_multisite() ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'Safe Site Cleaner does not support multisite. It was not activated, and nothing was changed.', 'safe-site-cleaner' ) );
		}
	}
);

add_action( 'rest_api_init', array( 'SSC_Rest', 'register' ) );
add_action( 'admin_menu', array( 'SSC_Admin', 'menu' ) );
SSC_Speed::boot();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SSC_DIR . 'includes/class-ssc-cli.php';
	WP_CLI::add_command( 'ssc', 'SSC_CLI' );
}
