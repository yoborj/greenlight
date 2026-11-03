<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only views of Greenlight on this site, shared by WP-CLI and the abilities (MCP).
 */
class Report {

	/** Version, switches, host cache, fonts, login page and real-user scores. */
	public static function status() {
		$s        = Settings::all();
		$switches = array();
		foreach ( $s as $key => $value ) {
			if ( is_int( $value ) ) {
				$switches[ $key ] = $value;
			}
		}
		return array(
			'greenlight_version' => GREENLIGHT_VERSION,
			'enabled'            => ! empty( $s['enabled'] ),
			'host_page_cache'    => Purge::host(),
			'font_sets'          => count( glob( Fonts::dir()['path'] . '*/meta.json' ) ?: array() ),
			'login_url'          => Hide_Login::url() ?: wp_login_url(),
			'settings'           => $switches,
			'conflicts'          => array_keys( Admin::conflicts() ),
			'field_data'         => Field_Data::summary(),
		);
	}

	/**
	 * Fetch one page of this site with Greenlight on and with ?greenlight=off, and count what changed.
	 * Both requests carry a throwaway query argument, so the host's page cache answers neither.
	 */
	public static function compare( $path = '/' ) {
		$path = '/' . ltrim( (string) wp_parse_url( (string) $path, PHP_URL_PATH ), '/' );
		$url  = home_url( $path );
		$out  = array( 'url' => $url );
		foreach ( array( 'with' => array(), 'without' => array( 'greenlight' => 'off' ) ) as $side => $args ) {
			$t0       = microtime( true );
			$response = wp_remote_get(
				add_query_arg( $args + array( 'greenlight_compare' => wp_generate_password( 8, false ) ), $url ),
				array( 'timeout' => 30, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ), 'headers' => array( 'Cache-Control' => 'no-cache' ) )
			);
			if ( is_wp_error( $response ) ) {
				return new \WP_Error( 'greenlight_compare_failed', 'Could not load ' . $url . ': ' . $response->get_error_message() );
			}
			$out[ $side ] = self::measure( (string) wp_remote_retrieve_body( $response ), (int) wp_remote_retrieve_response_code( $response ), microtime( true ) - $t0 );
		}
		return $out;
	}

	/** What a page asks the browser to do before and after the first interaction. */
	private static function measure( $html, $code, $seconds ) {
		$head_end = stripos( $html, '</head>' );
		$head     = false === $head_end ? '' : substr( $html, 0, $head_end );
		$blocking = 0;
		if ( preg_match_all( '#<script\b[^>]*\ssrc=[^>]*>#i', $head, $m ) ) {
			foreach ( $m[0] as $tag ) {
				if ( ! preg_match( '/\s(async|defer)\b|type=["\']?(module|text\/greenlight-delayed)/i', $tag ) ) {
					$blocking++;
				}
			}
		}
		preg_match_all( '#<img\b[^>]*>#i', $html, $imgs );
		$lazy = 0;
		foreach ( $imgs[0] as $img ) {
			$lazy += preg_match( '/\sloading=["\']?lazy/i', $img ) ? 1 : 0;
		}
		return array(
			'status'                       => $code,
			'seconds'                      => round( $seconds, 2 ),
			'html_kb'                      => round( strlen( $html ) / 1024, 1 ),
			'render_blocking_head_scripts' => $blocking,
			'scripts_delayed'              => substr_count( $html, '<script type="text/greenlight-delayed"' ), // Not the loader's own selector.
			'iframes_on_interaction'       => substr_count( $html, 'data-greenlight-iframe=' ),
			'images'                       => count( $imgs[0] ),
			'images_lazy'                  => $lazy,
			'preloads'                     => preg_match_all( '#<link\b[^>]*rel=["\']?preload#i', $html ),
			'hero_priority'                => (bool) preg_match( '/fetchpriority=["\']?high/i', $html ),
			'third_party_hosts'            => self::hosts( $html ),
			'greenlight_summary'           => preg_match( '/<!-- (Greenlight [^>]*?) -->/', $html, $c ) ? $c[1] : null,
		);
	}

	/** Other sites the page loads scripts, styles or fonts from right away (delayed scripts don't count). */
	private static function hosts( $html ) {
		$hosts = array();
		if ( preg_match_all( '#<(?:script|link)\b[^>]*\s(?:src|href)=["\']?((?:https?:)?//[^"\'\s>]+)#i', $html, $m ) ) {
			foreach ( $m[1] as $url ) {
				$host = Security::external_host( $url );
				if ( $host ) {
					$hosts[ $host ] = true;
				}
			}
		}
		$hosts = array_keys( $hosts );
		sort( $hosts );
		return $hosts;
	}
}
