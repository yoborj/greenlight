<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the HTML optimizations on each front-end page through one output buffer.
 * Add ?greenlight=off to any URL to see the page without them (useful for before/after checks);
 * email obfuscation and script drift (Security tab) use the same buffer and stay on.
 */
class Optimizer {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'start' ), 1 );
	}

	/** Speed optimizations run on this request. */
	private static $optimize = false;

	/** Script drift looks at this page (logged-out views only: what visitors and the page cache get). */
	private static $drift = false;

	/**
	 * A plain front-end HTML page: never wp-admin, AJAX, cron, REST, feeds, embeds, previews or the
	 * Customizer. The Security tab works in this scope whatever the master switch or ?greenlight=off say:
	 * anyone could add ?greenlight=off to a URL to strip its security headers.
	 */
	public static function in_scope() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed() || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DONOTGREENLIGHT' ) && DONOTGREENLIGHT ) ) {
			return false;
		}
		return 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
	}

	public static function should_run() {
		if ( ! Settings::get( 'enabled' ) || ! self::in_scope() ) {
			return false;
		}
		if ( isset( $_GET['greenlight'] ) && 'off' === $_GET['greenlight'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		return (bool) apply_filters( 'greenlight_should_optimize', true );
	}

	public static function start() {
		self::$optimize = self::should_run();
		$scope          = self::$optimize || self::in_scope();
		self::$drift    = $scope && Settings::get( 'script_drift' ) && ! is_user_logged_in();
		if ( self::$optimize || self::$drift || ( $scope && Settings::get( 'email_obfuscation' ) ) ) {
			ob_start( array( __CLASS__, 'process' ) );
		}
		if ( self::$drift ) {
			add_action( 'shutdown', array( 'Greenlight\\Drift', 'save' ), 2 ); // Right after WordPress flushes this buffer.
		}
	}

	public static function process( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) < 255 || false === stripos( $html, '<html' ) || http_response_code() !== 200 ) {
			return $html;
		}
		foreach ( headers_list() as $h ) {
			if ( 0 === stripos( $h, 'content-type:' ) && false === stripos( $h, 'text/html' ) ) {
				return $html;
			}
		}
		$t0     = microtime( true );
		$report = array();
		$out    = $html;
		if ( self::$drift ) {
			try {
				Drift::observe( $html ); // Reads the page as the theme printed it; never changes it.
			} catch ( \Throwable $e ) {
				$report['script drift'] = 'skipped';
			}
		}
		try {
			if ( self::$optimize ) {
				$out = self::optimize( $out, $report );
			}
			if ( Settings::get( 'email_obfuscation' ) ) {
				$out = Security::obfuscate_emails( $out, $report );
			}
		} catch ( \Throwable $e ) {
			// Never break a page: serve it unchanged and say why in the HTML comment.
			$report = array( 'error' => $e->getMessage() );
			$out    = $html;
		}
		$summary = array();
		foreach ( $report as $k => $v ) {
			$summary[] = $k . ': ' . $v;
		}
		return $out . "\n<!-- Greenlight " . GREENLIGHT_VERSION . ' (' . round( ( microtime( true ) - $t0 ) * 1000 ) . ' ms): ' . esc_html( implode( ', ', $summary ) ?: 'nothing to change' ) . ' -->';
	}

	private static function optimize( $out, array &$report ) {
		$out = Defer::verify_jquery( $out, $report );
		if ( Settings::get( 'fonts_local' ) ) {
			$out = Fonts::apply( $out, $report );
		}
		if ( Settings::get( 'lcp' ) ) {
			$out = Lcp::apply( $out, $report );
		}
		if ( Lazy::needed() ) {
			$out = Lazy::apply( $out, $report );
		}
		if ( Settings::get( 'delay' ) ) {
			$out = Delay::apply( $out, $report );
		}
		if ( Settings::get( 'preconnect' ) ) {
			$out = self::preconnect( $out, $report );
		}
		return $out;
	}

	/**
	 * Preconnect to the other servers the first screen needs (stylesheets, the hero image, scripts
	 * that are not delayed), so the connection is ready when the browser asks. At most 3.
	 */
	private static function preconnect( $html, array &$report ) {
		$home = wp_parse_url( home_url() );
		$own  = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$hosts = array();
		$head  = substr( $html, 0, max( 0, (int) stripos( $html, '</head>' ) ) );
		if ( preg_match_all( '#<(?:link[^>]+rel=["\'](?:stylesheet|preload)["\'][^>]*href|script[^>]*\ssrc)=["\'](https?:)?//([^/"\']+)#i', $head, $m ) ) {
			foreach ( $m[2] as $h ) {
				if ( strtolower( $h ) !== strtolower( $own ) && ! preg_match( '/^fonts\.(googleapis|gstatic)\.com$/', $h ) ) {
					$hosts[ $h ] = true;
				}
			}
		}
		$hosts = array_slice( array_keys( $hosts ), 0, 3 );
		if ( ! $hosts ) {
			return $html;
		}
		$links = '';
		foreach ( $hosts as $h ) {
			if ( false === stripos( $html, 'rel="preconnect" href="https://' . $h ) ) {
				$links .= '<link rel="preconnect" href="https://' . esc_attr( $h ) . '" crossorigin>' . "\n";
			}
		}
		$report['preconnect'] = count( $hosts );
		return Html::after_head_start( $html, $links );
	}
}
