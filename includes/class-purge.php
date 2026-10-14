<?php
namespace SpeedKit;

defined( 'ABSPATH' ) || exit;

/**
 * Clears the host's page caches when content changes. Speed Kit has no page cache of its own:
 * WP Engine (and Kinsta, SiteGround, Flywheel, Pantheon…) already cache pages at the server.
 */
class Purge {

	public static function init() {
		add_action( 'speedkit_purge_all', array( __CLASS__, 'all' ) );
		if ( ! Settings::get( 'purge_on_save' ) ) {
			return;
		}
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		foreach ( array( 'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'edited_term', 'upgrader_process_complete', 'update_option_' . Settings::OPTION ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'all_later' ) );
		}
	}

	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		self::all_later();
	}

	/** Purge once at the end of the request, however many changes triggered it. */
	public static function all_later() {
		static $queued = false;
		if ( ! $queued ) {
			$queued = true;
			add_action( 'shutdown', function () { Purge::all( 'content changed' ); } );
		}
	}

	/** Purge every cache layer we know about. Returns the layers that were cleared. */
	public static function all( $why = '' ) {
		$done = array();
		wp_cache_flush();
		$done[] = 'object cache';

		// WP Engine: memcached, Varnish page cache, CDN.
		if ( class_exists( '\WpeCommon' ) ) {
			if ( method_exists( '\WpeCommon', 'purge_memcached' ) ) { \WpeCommon::purge_memcached(); }
			if ( method_exists( '\WpeCommon', 'purge_varnish_cache' ) ) { \WpeCommon::purge_varnish_cache(); }
			if ( method_exists( '\WpeCommon', 'clear_cdn_cache' ) ) { \WpeCommon::clear_cdn_cache(); }
			$done[] = 'WP Engine';
		}
		// SiteGround.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$done[] = 'SiteGround';
		}
		// Kinsta (mu-plugin).
		if ( class_exists( '\Kinsta\Cache' ) && isset( $GLOBALS['kinsta_cache'] ) && is_object( $GLOBALS['kinsta_cache'] ) && isset( $GLOBALS['kinsta_cache']->kinsta_cache_purge ) ) {
			$GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_complete_caches();
			$done[] = 'Kinsta';
		}
		// Pantheon.
		if ( function_exists( 'pantheon_wp_clear_edge_all' ) ) {
			pantheon_wp_clear_edge_all();
			$done[] = 'Pantheon';
		}
		// Other caching plugins that listen for these.
		do_action( 'litespeed_purge_all' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$done[] = 'WP Rocket';
		}
		do_action( 'speedkit_purged', $done, $why );
		return $done;
	}

	/** Which host-level page cache the site sits behind, if any. */
	public static function host() {
		if ( class_exists( '\WpeCommon' ) || function_exists( 'is_wpe' ) ) {
			return 'WP Engine';
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			return 'SiteGround';
		}
		if ( defined( 'KINSTAMU_VERSION' ) ) {
			return 'Kinsta';
		}
		if ( defined( 'PANTHEON_ENVIRONMENT' ) ) {
			return 'Pantheon';
		}
		if ( defined( 'FLYWHEEL_CONFIG_DIR' ) ) {
			return 'Flywheel';
		}
		return null;
	}
}
