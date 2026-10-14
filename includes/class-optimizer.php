<?php
namespace SpeedKit;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the HTML optimizations on each front-end page through one output buffer.
 * Add ?speedkit=off to any URL to see the page without them (useful for before/after checks).
 */
class Optimizer {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'start' ), 1 );
	}

	public static function should_run() {
		if ( ! Settings::get( 'enabled' ) || is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed() || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DONOTSPEEDKIT' ) && DONOTSPEEDKIT ) ) {
			return false;
		}
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) || ( isset( $_GET['speedkit'] ) && 'off' === $_GET['speedkit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		return (bool) apply_filters( 'speedkit_should_optimize', true );
	}

	public static function start() {
		if ( self::should_run() ) {
			ob_start( array( __CLASS__, 'process' ) );
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
		try {
			$out = Defer::verify_jquery( $out, $report );
			if ( Settings::get( 'fonts_local' ) ) {
				$out = Fonts::apply( $out, $report );
			}
			if ( Settings::get( 'lcp' ) ) {
				$out = Lcp::apply( $out, $report );
			}
			if ( Settings::get( 'lazy' ) ) {
				$out = Lazy::apply( $out, $report );
			}
			if ( Settings::get( 'delay' ) ) {
				$out = Delay::apply( $out, $report );
			}
			if ( Settings::get( 'preconnect' ) ) {
				$out = self::preconnect( $out, $report );
			}
		} catch ( \Throwable $e ) {
			// Never break a page: serve it unoptimized and say why in the HTML comment.
			$report = array( 'error' => $e->getMessage() );
			$out    = $html;
		}
		$summary = array();
		foreach ( $report as $k => $v ) {
			$summary[] = $k . ': ' . $v;
		}
		return $out . "\n<!-- Speed Kit " . SPEEDKIT_VERSION . ' (' . round( ( microtime( true ) - $t0 ) * 1000 ) . ' ms): ' . esc_html( implode( ', ', $summary ) ?: 'nothing to change' ) . ' -->';
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
