<?php
/**
 * Plugin Name:       Greenlight
 * Description:       Front-end speed fixes that move Core Web Vitals: hero image priority, self-hosted fonts, delayed third-party scripts, smarter lazy-loading, WordPress cleanup and host cache purging. Plus the security hardening and change alerts a managed host leaves to the site. No page cache: managed hosts like WP Engine already cache pages.
 * Version:           0.2.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       greenlight
 */

defined( 'ABSPATH' ) || exit;

define( 'GREENLIGHT_VERSION', '0.2.0' );
define( 'GREENLIGHT_FILE', __FILE__ );
define( 'GREENLIGHT_DIR', plugin_dir_path( __FILE__ ) );
define( 'GREENLIGHT_URL', plugin_dir_url( __FILE__ ) );

require_once GREENLIGHT_DIR . 'includes/class-settings.php';
require_once GREENLIGHT_DIR . 'includes/class-html.php';
require_once GREENLIGHT_DIR . 'includes/class-lcp.php';
require_once GREENLIGHT_DIR . 'includes/class-lazy.php';
require_once GREENLIGHT_DIR . 'includes/class-fonts.php';
require_once GREENLIGHT_DIR . 'includes/class-delay.php';
require_once GREENLIGHT_DIR . 'includes/class-defer.php';
require_once GREENLIGHT_DIR . 'includes/class-cleanup.php';
require_once GREENLIGHT_DIR . 'includes/class-purge.php';
require_once GREENLIGHT_DIR . 'includes/class-optimizer.php';
require_once GREENLIGHT_DIR . 'includes/class-admin.php';
require_once GREENLIGHT_DIR . 'includes/class-security.php';
require_once GREENLIGHT_DIR . 'includes/class-alerts.php';
require_once GREENLIGHT_DIR . 'includes/class-drift.php';
require_once GREENLIGHT_DIR . 'includes/class-core-check.php';
require_once GREENLIGHT_DIR . 'includes/class-content-check.php';

Greenlight\Security::init(); // First: it may define DISALLOW_FILE_EDIT or stop an XML-RPC request.
Greenlight\Alerts::init();
Greenlight\Content_Check::init();
Greenlight\Cleanup::init();
Greenlight\Purge::init();
Greenlight\Fonts::init();
add_action( 'greenlight_make_webp', array( 'Greenlight\\Html', 'make_webp' ) );
Greenlight\Optimizer::init();
Greenlight\Defer::init();
Greenlight\Admin::init();

// Pages cached before activation (or still cached after deactivation) would keep their old version.
register_activation_hook( __FILE__, function () {
	add_action( 'shutdown', function () { Greenlight\Purge::all( 'activated' ); } );
	// Posts edited while Greenlight was off have no fingerprint yet: rebuild them rather than alert.
	if ( Greenlight\Settings::get( 'content_check' ) ) {
		Greenlight\Content_Check::start_baseline();
	}
} );
register_deactivation_hook( __FILE__, function () {
	Greenlight\Purge::all( 'deactivated' );
	wp_clear_scheduled_hook( Greenlight\Alerts::DAILY );
	wp_clear_scheduled_hook( Greenlight\Alerts::BATCH );
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once GREENLIGHT_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'greenlight', 'Greenlight\\CLI' );
}
