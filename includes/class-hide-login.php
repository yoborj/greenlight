<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Custom login URL, the job WPS Hide Login does: the login page lives at /<slug>/, while wp-login.php
 * shows the theme's 404 and wp-admin sends logged-out visitors to /404. Every login, logout and
 * password-reset link WordPress makes points to the new address. Locked out?
 * `wp greenlight set hide_login 0` brings wp-login.php back.
 */
class Hide_Login {

	/** The login address unless one is set: /backend-login/. */
	const DEFAULT_SLUG = 'backend-login';

	/** Never usable as the login address: WordPress's own paths and the words bots try first. */
	const RESERVED = array( 'admin', 'login', 'wp-admin', 'wp-login', 'wp-login-php', 'dashboard', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'xmlrpc', 'sitemap', 'wp-sitemap' );

	private static $slug = '';

	/** What this request is: 'login' (the new address) or 'hidden' (wp-login.php asked for directly). */
	private static $mode = '';

	public static function init() {
		$s = Settings::all();
		if ( ! $s['hide_login'] || ! self::valid( $s['login_slug'] ) ) {
			return;
		}
		self::$slug = $s['login_slug'];
		add_filter( 'site_url', array( __CLASS__, 'filter_url' ), 10, 1 );
		add_filter( 'network_site_url', array( __CLASS__, 'filter_url' ), 10, 1 );
		add_filter( 'wp_redirect', array( __CLASS__, 'filter_url' ), 10, 1 );
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 ); // /login and /admin would lead bots to it.
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'plugins_loaded', array( __CLASS__, 'route' ), 9999 );
			add_action( 'wp_loaded', array( __CLASS__, 'serve' ) );
		}
	}

	public static function valid( $slug ) {
		return is_string( $slug ) && preg_match( '/^[a-z0-9][a-z0-9-]{2,49}$/', $slug ) && ! in_array( $slug, self::RESERVED, true );
	}

	/** The address to use when none (or one that isn't allowed) is set. */
	public static function suggest() {
		return self::DEFAULT_SLUG;
	}

	/** The login page's address, or null while the feature is off. */
	public static function url( $scheme = null ) {
		if ( '' === self::$slug ) {
			return null;
		}
		if ( get_option( 'permalink_structure' ) ) {
			return user_trailingslashit( home_url( '/' . self::$slug, $scheme ) );
		}
		return home_url( '/', $scheme ) . '?' . self::$slug;
	}

	private static function home_path() {
		return untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
	}

	private static function is_postpass() {
		return isset( $_GET['action'] ) && 'postpass' === $_GET['action']; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** plugins_loaded: work out what this request is before WordPress parses it. */
	public static function route() {
		global $pagenow;
		$path = untrailingslashit( (string) wp_parse_url( rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ), PHP_URL_PATH ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = self::home_path();
		if ( $path === $home . '/' . self::$slug || ( ! get_option( 'permalink_structure' ) && isset( $_GET[ self::$slug ] ) && '' === $_GET[ self::$slug ] && ( $path === $home || $path === $home . '/index.php' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::$mode = 'login';
			$pagenow    = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		} elseif ( ! is_admin() && preg_match( '#/wp-(login|register)\.php$#', $path ) && ! self::is_postpass() ) {
			self::$mode = 'hidden';
		}
	}

	/** wp_loaded: serve the login page, a 404, or let the request through. */
	public static function serve() {
		global $pagenow;
		if ( self::is_postpass() ) {
			return; // Password-protected posts post to wp-login.php?action=postpass.
		}
		if ( is_admin() && ! is_user_logged_in() && ! wp_doing_ajax() && ! wp_doing_cron() && 'admin-post.php' !== $pagenow ) {
			wp_safe_redirect( home_url( user_trailingslashit( '404' ) ) );
			exit;
		}
		if ( 'hidden' === self::$mode ) {
			self::theme_404();
		}
		if ( 'login' !== self::$mode ) {
			return;
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( get_option( 'permalink_structure' ) && user_trailingslashit( $path ) !== $path ) { // One address: /sign-in → /sign-in/
			$query = wp_unslash( $_SERVER['QUERY_STRING'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_safe_redirect( self::url() . ( '' !== $query ? '?' . $query : '' ) );
			exit;
		}
		if ( is_user_logged_in() && ! isset( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( admin_url() );
			exit;
		}
		self::load_login();
	}

	/** wp-login.php reads these as globals; declaring them here keeps its top-level assignments global too. */
	private static function load_login() {
		global $error, $interim_login, $action, $user_login, $redirect_to; // phpcs:ignore
		require_once ABSPATH . 'wp-login.php';
		exit;
	}

	/** The theme's own 404 page, exactly as for any missing address. */
	private static function theme_404() {
		global $pagenow;
		$pagenow                = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$_SERVER['REQUEST_URI'] = self::home_path() . '/' . str_repeat( '-/', 8 );
		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', true );
		}
		wp();
		require_once ABSPATH . WPINC . '/template-loader.php';
		exit;
	}

	/** Point this site's wp-login.php links (not other sites', not ?action=postpass) to the new address. */
	public static function filter_url( $url ) {
		if ( ! is_string( $url ) || false === strpos( $url, 'wp-login.php' ) || false !== strpos( $url, 'action=postpass' ) ) {
			return $url;
		}
		$parts = explode( '?', $url, 2 );
		if ( ! preg_match( '#(^|/)wp-login\.php$#', $parts[0] ) ) {
			return $url; // wp-login.php only inside the query string, e.g. a redirect_to.
		}
		$host = wp_parse_url( $parts[0], PHP_URL_HOST );
		if ( $host && strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) && strtolower( $host ) !== strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ) ) {
			return $url; // Another site's login, e.g. wordpress.com for Jetpack.
		}
		$new = self::url( wp_parse_url( $parts[0], PHP_URL_SCHEME ) );
		if ( ! isset( $parts[1] ) || '' === $parts[1] ) {
			return $new;
		}
		return $new . ( false === strpos( $new, '?' ) ? '?' : '&' ) . $parts[1];
	}
}
