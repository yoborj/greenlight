<?php
/**
 * Plugin Name:       Speed Kit
 * Description:       Front-end speed fixes that move Core Web Vitals: hero image priority, self-hosted fonts, delayed third-party scripts, smarter lazy-loading, WordPress cleanup and host cache purging. No page cache: managed hosts like WP Engine already cache pages.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       speed-kit
 *
 * "Speed Kit" is a working name; rename the plugin header, the folder and the SPEEDKIT_ prefix together.
 */

defined( 'ABSPATH' ) || exit;

define( 'SPEEDKIT_VERSION', '0.1.0' );
define( 'SPEEDKIT_FILE', __FILE__ );
define( 'SPEEDKIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPEEDKIT_URL', plugin_dir_url( __FILE__ ) );

require_once SPEEDKIT_DIR . 'includes/class-settings.php';
require_once SPEEDKIT_DIR . 'includes/class-html.php';
require_once SPEEDKIT_DIR . 'includes/class-lcp.php';
require_once SPEEDKIT_DIR . 'includes/class-lazy.php';
require_once SPEEDKIT_DIR . 'includes/class-fonts.php';
require_once SPEEDKIT_DIR . 'includes/class-delay.php';
require_once SPEEDKIT_DIR . 'includes/class-defer.php';
require_once SPEEDKIT_DIR . 'includes/class-cleanup.php';
require_once SPEEDKIT_DIR . 'includes/class-purge.php';
require_once SPEEDKIT_DIR . 'includes/class-optimizer.php';
require_once SPEEDKIT_DIR . 'includes/class-admin.php';

SpeedKit\Cleanup::init();
SpeedKit\Purge::init();
SpeedKit\Fonts::init();
add_action( 'speedkit_make_webp', array( 'SpeedKit\\Html', 'make_webp' ) );
SpeedKit\Optimizer::init();
SpeedKit\Defer::init();
SpeedKit\Admin::init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SPEEDKIT_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'speedkit', 'SpeedKit\\CLI' );
}
