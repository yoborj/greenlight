<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Faster page-to-page navigation with the browser's Speculation Rules: when a visitor hovers a link
 * (or starts to tap it), the next page is fetched (prefetch) or fully prepared (prerender) ahead of the
 * click. WordPress 6.8+ already does this conservatively; Greenlight sets the mode and makes it start
 * on hover. On older WordPress it prints the same rules itself. Logged-out visitors only, as in core,
 * and never for wp-admin, the login page or links with a nonce.
 */
class Speculation {

	const MODES = array( 'off', 'prefetch', 'prerender' );

	public static function init() {
		add_filter( 'wp_speculation_rules_configuration', array( __CLASS__, 'configuration' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( __CLASS__, 'exclude' ) );
		if ( ! function_exists( 'wp_get_speculation_rules_configuration' ) ) {
			add_action( 'wp_head', array( __CLASS__, 'print_rules' ), 100 ); // WordPress before 6.8.
		}
	}

	private static function mode() {
		$mode = Settings::get( 'speculation' );
		return Settings::get( 'enabled' ) && in_array( $mode, self::MODES, true ) ? $mode : 'core';
	}

	/** WordPress 6.8+: "off" turns core's rules off too; otherwise start on hover. */
	public static function configuration( $config ) {
		$mode = self::mode();
		if ( 'off' === $mode ) {
			return null;
		}
		if ( 'core' === $mode || ! is_array( $config ) ) {
			return $config; // Paused, or core left it off (logged in, no pretty permalinks).
		}
		return array( 'mode' => $mode, 'eagerness' => 'moderate' );
	}

	/** The custom login address never gets fetched ahead. */
	public static function exclude( $paths ) {
		$login = Hide_Login::url();
		if ( $login ) {
			$paths[] = untrailingslashit( (string) wp_parse_url( $login, PHP_URL_PATH ) ) . '*';
		}
		return $paths;
	}

	/** Older WordPress: the same rule core would print, set to start on hover. */
	public static function print_rules() {
		$mode = self::mode();
		if ( ! in_array( $mode, array( 'prefetch', 'prerender' ), true ) || is_user_logged_in() || ! get_option( 'permalink_structure' ) || ! Optimizer::in_scope() ) {
			return;
		}
		$base = trailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$not  = array( $base . 'wp-*.php', $base . 'wp-admin/*', $base . 'wp-content/*', $base . '*\\?*(^|&)_wpnonce=*' );
		foreach ( self::exclude( array() ) as $path ) {
			$not[] = $path;
		}
		$rules = array(
			$mode => array(
				array(
					'source'    => 'document',
					'where'     => array(
						'and' => array(
							array( 'href_matches' => $base . '*' ),
							array( 'not' => array( 'href_matches' => $not ) ),
							array( 'not' => array( 'selector_matches' => 'a[rel~="nofollow"]' ) ),
							array( 'not' => array( 'selector_matches' => '.no-' . $mode . ', .no-' . $mode . ' a' ) ),
						),
					),
					'eagerness' => 'moderate',
				),
			),
		);
		echo '<script type="speculationrules">' . wp_json_encode( $rules, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}
}
