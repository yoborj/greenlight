<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress extras most sites don't need on the front end, each behind a setting.
 */
class Cleanup {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'run' ), 5 );
	}

	public static function run() {
		$s = Settings::all();
		if ( empty( $s['enabled'] ) || ( ! is_admin() && isset( $_GET['greenlight'] ) && 'off' === $_GET['greenlight'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		if ( $s['no_emojis'] ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
			add_filter( 'emoji_svg_url', '__return_false' );
			add_filter( 'tiny_mce_plugins', function ( $p ) { return is_array( $p ) ? array_diff( $p, array( 'wpemoji' ) ) : $p; } );
		}

		if ( $s['no_embeds'] ) {
			// Stops other sites embedding this one as a card and drops wp-embed.js; YouTube etc. still embed.
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
			add_action( 'wp_footer', function () { wp_dequeue_script( 'wp-embed' ); } );
		}

		if ( $s['clean_head'] ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}

		if ( $s['no_dashicons'] ) {
			add_action( 'wp_enqueue_scripts', function () {
				if ( ! is_user_logged_in() ) {
					wp_dequeue_style( 'dashicons' );
				}
			}, 100 );
		}

		if ( $s['heartbeat'] ) {
			// No Heartbeat on the front end; once a minute in wp-admin (post editor keeps autosave/locks).
			add_action( 'wp_enqueue_scripts', function () { wp_deregister_script( 'heartbeat' ); }, 1 );
			add_filter( 'heartbeat_settings', function ( $settings ) { $settings['interval'] = 60; return $settings; } );
		}

		if ( $s['no_jquery_migrate'] ) {
			add_action( 'wp_default_scripts', function ( $scripts ) {
				if ( ! is_admin() && isset( $scripts->registered['jquery'] ) ) {
					$scripts->registered['jquery']->deps = array_diff( $scripts->registered['jquery']->deps, array( 'jquery-migrate' ) );
				}
			} );
		}

		if ( $s['no_block_css'] ) {
			// Only for classic themes, and only on pages without blocks.
			add_action( 'wp_enqueue_scripts', function () {
				if ( wp_is_block_theme() || ( is_singular() && has_blocks( get_post() ) ) ) {
					return;
				}
				foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'global-styles', 'classic-theme-styles' ) as $h ) {
					wp_dequeue_style( $h );
				}
			}, 100 );
		}
	}
}
