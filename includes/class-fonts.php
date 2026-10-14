<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Self-hosted Google Fonts.
 *
 * Google Fonts stylesheets block rendering and need two extra connections (fonts.googleapis.com,
 * fonts.gstatic.com). The font files then arrive late and the text reflows when they swap in, which
 * is what gave HFW its desktop layout shift (0.10–0.15; 0 after self-hosting + preloading).
 *
 * The first time a page uses a Google Fonts stylesheet, a background job downloads the CSS and the
 * woff2 files into uploads/greenlight/fonts/<hash>/. From then on pages link the local copy, preload
 * the Latin files, and drop the connections to Google. Nobody waits for the download.
 */
class Fonts {

	const HOOK = 'greenlight_build_fonts';
	const UA   = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0 Safari/537.36';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'build' ) );
	}

	public static function dir( $key = '' ) {
		$u = wp_get_upload_dir();
		return array(
			'path' => trailingslashit( $u['basedir'] ) . 'greenlight/fonts/' . $key,
			'url'  => trailingslashit( $u['baseurl'] ) . 'greenlight/fonts/' . $key,
		);
	}

	public static function key( $href ) {
		return substr( md5( html_entity_decode( $href ) . '|' . Settings::get( 'fonts_display' ) ), 0, 12 );
	}

	public static function apply( $html, array &$report ) {
		if ( ! preg_match_all( '#<link\b[^>]*href=(["\'])((?:https?:)?//fonts\.googleapis\.com/css2?\?[^"\']+)\1[^>]*>#i', $html, $all, PREG_SET_ORDER ) ) {
			return $html;
		}
		$preloads  = array();
		$localized = 0;
		foreach ( $all as $m ) {
			$tag  = $m[0];
			$href = html_entity_decode( $m[2] );
			if ( 0 === strpos( $href, '//' ) ) {
				$href = 'https:' . $href;
			}
			$key  = self::key( $href );
			$d    = self::dir( $key );
			$meta = is_readable( $d['path'] . '/meta.json' ) ? json_decode( (string) file_get_contents( $d['path'] . '/meta.json' ), true ) : null;
			if ( ! $meta || ! is_readable( $d['path'] . '/fonts.css' ) ) {
				if ( ! wp_next_scheduled( self::HOOK, array( $href ) ) ) {
					wp_schedule_single_event( time(), self::HOOK, array( $href ) );
				}
				$report['fonts'] = 'building';
				continue;
			}
			$local = '<link rel="stylesheet" id="greenlight-fonts-' . $key . '" href="' . esc_url( $d['url'] . '/fonts.css?v=' . $meta['built'] ) . '" media="all">';
			$html  = str_replace( $tag, $local, $html );
			foreach ( (array) ( $meta['preload'] ?? array() ) as $file ) {
				$preloads[] = $d['url'] . '/' . $file;
			}
			$localized++;
		}
		if ( $localized ) {
			// Connections to Google are no longer needed.
			$html = preg_replace( '#<link\b[^>]*rel=(["\'])(?:preconnect|dns-prefetch)\1[^>]*href=(["\'])(?:https?:)?//fonts\.(?:googleapis|gstatic)\.com/?\2[^>]*>\s*#i', '', $html );
			$html = preg_replace( '#<link\b[^>]*href=(["\'])(?:https?:)?//fonts\.(?:googleapis|gstatic)\.com/?\1[^>]*rel=(["\'])(?:preconnect|dns-prefetch)\2[^>]*>\s*#i', '', $html );
			if ( Settings::get( 'fonts_preload' ) && $preloads ) {
				$links = '';
				foreach ( array_slice( array_unique( $preloads ), 0, 4 ) as $u ) {
					$links .= '<link rel="preload" href="' . esc_url( $u ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
				}
				$html = Html::after_head_start( $html, $links );
			}
			$report['fonts'] = $localized . ' local';
		}
		return $html;
	}

	/** Background job: download a Google Fonts stylesheet and its files. */
	public static function build( $href ) {
		if ( ! preg_match( '#^https://fonts\.googleapis\.com/css2?\?#', $href ) ) {
			return;
		}
		$key = self::key( $href );
		$d   = self::dir( $key );
		$r   = wp_remote_get( $href, array( 'timeout' => 20, 'user-agent' => self::UA ) );
		if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) {
			return;
		}
		$css = wp_remote_retrieve_body( $r );
		if ( ! wp_mkdir_p( $d['path'] ) ) {
			return;
		}
		$files   = array();
		$preload = array();
		$display = Settings::get( 'fonts_display' );
		// Each @font-face block, with the subset comment Google puts before it (/* latin */).
		$css = preg_replace_callback(
			'#(/\*\s*([a-z-]+)\s*\*/\s*)?@font-face\s*\{([^}]*)\}#i',
			function ( $m ) use ( &$files, &$preload, $d, $display ) {
				$block = $m[3];
				$block = preg_replace_callback(
					'#url\(([^)]+)\)#',
					function ( $u ) use ( &$files, $d ) {
						$src  = trim( $u[1], '\'" ' );
						$name = sanitize_file_name( wp_basename( wp_parse_url( $src, PHP_URL_PATH ) ) );
						if ( ! isset( $files[ $name ] ) ) {
							$res = wp_remote_get( $src, array( 'timeout' => 20, 'user-agent' => self::UA ) );
							if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
								return $u[0]; // Keep Google's URL for this file.
							}
							file_put_contents( $d['path'] . '/' . $name, wp_remote_retrieve_body( $res ) );
							$files[ $name ] = true;
						}
						return 'url(' . $name . ')';
					},
					$block
				);
				$block = preg_match( '/font-display\s*:/i', $block ) ? preg_replace( '/font-display\s*:\s*[a-z]+/i', 'font-display: ' . $display, $block ) : rtrim( $block ) . "\n  font-display: " . $display . ";\n";
				if ( isset( $m[2] ) && 'latin' === strtolower( $m[2] ) && preg_match( '#url\(([^)]+\.woff2)\)#', $block, $f ) ) {
					$preload[ $f[1] ] = true;
				}
				return $m[1] . '@font-face {' . $block . '}';
			},
			$css
		);
		if ( ! $files ) {
			return;
		}
		file_put_contents( $d['path'] . '/fonts.css', "/* Self-hosted by Greenlight from {$href} */\n" . $css );
		file_put_contents( $d['path'] . '/meta.json', wp_json_encode( array( 'href' => $href, 'built' => time(), 'files' => array_keys( $files ), 'preload' => array_keys( $preload ) ) ) );
		do_action( 'greenlight_purge_all', 'fonts built' );
	}

	/** Delete every local font copy (they rebuild on the next page view). */
	public static function clear() {
		$d = self::dir();
		if ( ! is_dir( $d['path'] ) ) {
			return 0;
		}
		$n = 0;
		foreach ( glob( $d['path'] . '*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			array_map( 'unlink', glob( $dir . '/*' ) ?: array() );
			rmdir( $dir );
			$n++;
		}
		return $n;
	}
}
