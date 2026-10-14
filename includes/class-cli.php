<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI: wp greenlight status | purge | fonts [--clear] | set <key> <value>
 */
class CLI {

	/**
	 * Show settings, host and font cache.
	 */
	public function status() {
		$s = Settings::all();
		\WP_CLI::line( 'Greenlight ' . GREENLIGHT_VERSION . ( $s['enabled'] ? ' — on' : ' — OFF' ) );
		\WP_CLI::line( 'Host page cache: ' . ( Purge::host() ?: 'none detected (Greenlight has no page cache of its own)' ) );
		$fonts = glob( Fonts::dir()['path'] . '*/meta.json' ) ?: array();
		\WP_CLI::line( 'Self-hosted font sets: ' . count( $fonts ) );
		foreach ( $s as $k => $v ) {
			if ( is_int( $v ) ) {
				\WP_CLI::line( sprintf( '  %-20s %s', $k, $v ) );
			}
		}
	}

	/**
	 * Clear the host's page caches (and the object cache).
	 */
	public function purge() {
		\WP_CLI::success( 'Cleared: ' . implode( ', ', Purge::all( 'wp-cli' ) ) );
	}

	/**
	 * Rebuild self-hosted Google Fonts.
	 *
	 * [--clear]
	 * : Delete the local copies first (they rebuild on the next page view).
	 */
	public function fonts( $args, $assoc ) {
		if ( isset( $assoc['clear'] ) ) {
			\WP_CLI::success( 'Removed ' . Fonts::clear() . ' font set(s).' );
			return;
		}
		$n = 0;
		foreach ( glob( Fonts::dir()['path'] . '*/meta.json' ) ?: array() as $f ) {
			$meta = json_decode( (string) file_get_contents( $f ), true );
			if ( ! empty( $meta['href'] ) ) {
				Fonts::build( $meta['href'] );
				$n++;
			}
		}
		\WP_CLI::success( "Rebuilt {$n} font set(s)." );
	}

	/**
	 * Change one setting.
	 *
	 * <key>
	 * : Setting name (see `wp greenlight status`).
	 *
	 * <value>
	 * : New value (1/0 for switches).
	 */
	public function set( $args ) {
		list( $key, $value ) = $args;
		$all = Settings::all();
		if ( ! array_key_exists( $key, $all ) ) {
			\WP_CLI::error( "Unknown setting {$key}." );
		}
		$all[ $key ] = $value;
		update_option( Settings::OPTION, Settings::sanitize( $all ) );
		\WP_CLI::success( "{$key} = " . Settings::get( $key ) );
	}
}
