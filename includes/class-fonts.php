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

	const HOOK      = 'greenlight_build_fonts';
	const FALLBACKS = 'greenlight_font_fallbacks';

	/**
	 * The local fonts fallbacks are built on: units per em and weighted average width (measured with
	 * ttf_metrics(), as below), plus the local() names to ask for. "Serif" is used when the page's own
	 * stack ends in serif.
	 */
	const BASES = array(
		''       => array( 2048, 904, 'local("Arial"), local("ArialMT"), local("Helvetica Neue"), local("Helvetica")' ),
		' Serif' => array( 2048, 819, 'local("Times New Roman"), local("TimesNewRomanPSMT"), local("Times")' ),
	);
	const UA   = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0 Safari/537.36';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'build' ) );
		add_action( self::FALLBACKS, array( __CLASS__, 'fallbacks' ) );
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
		$fallbacks = array();
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
			if ( Settings::get( 'fonts_fallback' ) ) {
				if ( ! isset( $meta['fallbacks'] ) ) {
					if ( ! wp_next_scheduled( self::FALLBACKS, array( $href ) ) ) {
						wp_schedule_single_event( time(), self::FALLBACKS, array( $href ) ); // A set built before 0.5.0.
					}
				} else {
					$fallbacks += (array) $meta['fallbacks'];
				}
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
			if ( $fallbacks ) {
				$html = self::use_fallbacks( $html, $fallbacks, $report );
			}
		}
		return $html;
	}

	/**
	 * Put each self-hosted family's size-matched fallback right after it in the page's font stacks
	 * ("Roboto", sans-serif → "Roboto", "Roboto Fallback", sans-serif; a stack ending in serif gets the
	 * Times-based "… Fallback Serif"), so the text keeps its size and line breaks while the web font loads. Only stacks set in the page's own <style> blocks (block
	 * themes' global styles, page builders); a theme's external stylesheet keeps its own fallback.
	 */
	private static function use_fallbacks( $html, array $map, array &$report ) {
		$lower = array_change_key_case( $map, CASE_LOWER );
		$count = 0;
		$out   = preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style>)#is',
			function ( $s ) use ( $lower, &$count ) {
				$css = preg_replace_callback(
					'/((?:font-family|--[\w-]*font-family[\w-]*)\s*:\s*)([^;{}<]+)/i',
					function ( $d ) use ( $lower, &$count ) {
						$parts = array_map( 'trim', explode( ',', $d[2] ) );
						$serif = in_array( 'serif', array_map( 'strtolower', $parts ), true ) ? ' Serif' : '';
						$new   = array();
						foreach ( $parts as $i => $part ) {
							$new[] = $part;
							$name  = strtolower( trim( preg_replace( '/\s*!important$/i', '', $part ), '\'" ' ) );
							$next  = isset( $parts[ $i + 1 ] ) ? strtolower( trim( $parts[ $i + 1 ], '\'" ' ) ) : '';
							if ( isset( $lower[ $name ] ) && 0 !== strpos( $next, strtolower( $lower[ $name ] ) ) && false === stripos( $part, '!important' ) ) {
								$new[] = '"' . $lower[ $name ] . $serif . '"';
								$count++;
							}
						}
						return $d[1] . implode( ', ', $new );
					},
					$s[2]
				);
				return $s[1] . ( null === $css ? $s[2] : $css ) . $s[3];
			},
			$html
		);
		if ( null === $out ) {
			return $html;
		}
		if ( $count ) {
			$report['font fallbacks'] = $count;
		}
		return $out;
	}

	/**
	 * Background job: measure each family in a font set (its TrueType file, which Google serves to a
	 * client that doesn't name a browser) and add size-matched fallback faces to fonts.css: one on
	 * Arial, one on Times New Roman.
	 */
	public static function fallbacks( $href ) {
		$key  = self::key( $href );
		$d    = self::dir( $key );
		$meta = is_readable( $d['path'] . '/meta.json' ) ? json_decode( (string) file_get_contents( $d['path'] . '/meta.json' ), true ) : null;
		if ( ! $meta || isset( $meta['fallbacks'] ) || ! is_readable( $d['path'] . '/fonts.css' ) ) {
			return;
		}
		$meta['fallbacks'] = array(); // Measured once, even if a family can't be.
		$r = wp_remote_get( $href, array( 'timeout' => 20, 'user-agent' => 'Greenlight' ) );
		$faces = array();
		if ( ! is_wp_error( $r ) && preg_match_all( '#@font-face\s*\{([^}]*)\}#i', (string) wp_remote_retrieve_body( $r ), $blocks ) ) {
			foreach ( $blocks[1] as $block ) {
				if ( ! preg_match( '/font-family:\s*[\'"]?([^;\'"]+)/i', $block, $fam ) || ! preg_match( '#url\(([^)]+\.ttf)\)#i', $block, $url ) || preg_match( '/font-style:\s*italic/i', $block ) ) {
					continue;
				}
				$weight = preg_match( '/font-weight:\s*(\d+)/i', $block, $w ) ? (int) $w[1] : 400;
				// The regular weight measures best; otherwise the first one listed.
				if ( ! isset( $faces[ $fam[1] ] ) || 400 === $weight ) {
					$faces[ $fam[1] ] = trim( $url[1], '\'" ' );
				}
			}
		}
		$css = '';
		foreach ( $faces as $family => $url ) {
			$res = wp_remote_get( $url, array( 'timeout' => 20 ) );
			$m   = is_wp_error( $res ) ? null : self::ttf_metrics( (string) wp_remote_retrieve_body( $res ) );
			if ( ! $m ) {
				continue;
			}
			$name = str_replace( '"', '', $family ) . ' Fallback';
			foreach ( self::BASES as $suffix => $base ) {
				$size = ( $m['avg'] / $m['upem'] ) / ( $base[1] / $base[0] );
				$css .= sprintf(
					"@font-face {\n  font-family: \"%s\";\n  src: %s;\n  ascent-override: %.2f%%;\n  descent-override: %.2f%%;\n  line-gap-override: %.2f%%;\n  size-adjust: %.2f%%;\n}\n",
					$name . $suffix,
					$base[2],
					$m['ascent'] / ( $m['upem'] * $size ) * 100,
					abs( $m['descent'] ) / ( $m['upem'] * $size ) * 100,
					$m['gap'] / ( $m['upem'] * $size ) * 100,
					$size * 100
				);
			}
			$meta['fallbacks'][ $family ] = $name;
		}
		if ( $css ) {
			file_put_contents( $d['path'] . '/fonts.css', "\n/* Size-matched fallbacks: text keeps its size while the fonts load. */\n" . $css, FILE_APPEND );
			$meta['built'] = time(); // A new ?v= so cached copies of fonts.css refresh.
		}
		file_put_contents( $d['path'] . '/meta.json', wp_json_encode( $meta ) );
		if ( $css ) {
			do_action( 'greenlight_purge_all', 'font fallbacks' );
		}
	}

	/** English letter frequencies (%) and a space roughly every five characters: what "average width" means here. */
	const WEIGHTS = array( ' ' => 22.0, 'a' => 8.167, 'b' => 1.492, 'c' => 2.782, 'd' => 4.253, 'e' => 12.702, 'f' => 2.228, 'g' => 2.015, 'h' => 6.094, 'i' => 6.966, 'j' => 0.153, 'k' => 0.772, 'l' => 4.025, 'm' => 2.406, 'n' => 6.749, 'o' => 7.507, 'p' => 1.929, 'q' => 0.095, 'r' => 5.987, 's' => 6.327, 't' => 9.056, 'u' => 2.758, 'v' => 0.978, 'w' => 2.360, 'x' => 0.150, 'y' => 1.974, 'z' => 0.074 );

	/**
	 * Units per em, ascent, descent and line gap (hhea), and the frequency-weighted average width of
	 * a–z and space (cmap + hmtx), the way capsize and Next.js size fallbacks. Null if it isn't a
	 * readable TrueType file.
	 */
	public static function ttf_metrics( $bin ) {
		$len = strlen( $bin );
		if ( $len < 12 || ! in_array( substr( $bin, 0, 4 ), array( "\x00\x01\x00\x00", 'true' ), true ) ) {
			return null;
		}
		$u16 = function ( $at ) use ( $bin, $len ) { return $at + 2 <= $len ? unpack( 'n', substr( $bin, $at, 2 ) )[1] : 0; };
		$s16 = function ( $at ) use ( $u16 ) { $v = $u16( $at ); return $v >= 0x8000 ? $v - 0x10000 : $v; };
		$u32 = function ( $at ) use ( $bin, $len ) { return $at + 4 <= $len ? unpack( 'N', substr( $bin, $at, 4 ) )[1] : 0; };
		$tables = array();
		for ( $i = 0, $n = $u16( 4 ); $i < $n; $i++ ) {
			$tables[ substr( $bin, 12 + 16 * $i, 4 ) ] = $u32( 12 + 16 * $i + 8 );
		}
		if ( ! isset( $tables['head'], $tables['hhea'], $tables['hmtx'], $tables['cmap'] ) ) {
			return null;
		}
		$upem = $u16( $tables['head'] + 18 );
		$hhea = $tables['hhea'];
		$nhm  = $u16( $hhea + 34 );
		// cmap: the Windows Unicode (3,1) or Unicode (0,x) subtable in format 4.
		$cmap = $tables['cmap'];
		$sub  = 0;
		for ( $i = 0, $n = $u16( $cmap + 2 ); $i < $n; $i++ ) {
			$rec = $cmap + 4 + 8 * $i;
			$at  = $cmap + $u32( $rec + 4 );
			if ( 4 === $u16( $at ) && ( ( 3 === $u16( $rec ) && 1 === $u16( $rec + 2 ) ) || 0 === $u16( $rec ) ) ) {
				$sub = $at;
				break;
			}
		}
		if ( ! $sub || ! $upem || ! $nhm ) {
			return null;
		}
		$segs   = $u16( $sub + 6 ) / 2;
		$ends   = $sub + 14;
		$starts = $ends + 2 * $segs + 2;
		$deltas = $starts + 2 * $segs;
		$ranges = $deltas + 2 * $segs;
		$glyph  = function ( $c ) use ( $segs, $ends, $starts, $deltas, $ranges, $u16 ) {
			for ( $i = 0; $i < $segs; $i++ ) {
				if ( $u16( $ends + 2 * $i ) < $c ) {
					continue;
				}
				$start = $u16( $starts + 2 * $i );
				if ( $start > $c ) {
					return 0;
				}
				$delta = $u16( $deltas + 2 * $i );
				$range = $u16( $ranges + 2 * $i );
				if ( ! $range ) {
					return ( $c + $delta ) & 0xFFFF;
				}
				$g = $u16( $ranges + 2 * $i + $range + 2 * ( $c - $start ) );
				return $g ? ( $g + $delta ) & 0xFFFF : 0;
			}
			return 0;
		};
		$sum   = 0;
		$total = 0;
		foreach ( self::WEIGHTS as $char => $weight ) {
			$g = $glyph( ord( $char ) );
			if ( ! $g ) {
				continue;
			}
			$sum   += $u16( $tables['hmtx'] + 4 * min( $g, $nhm - 1 ) ) * $weight;
			$total += $weight;
		}
		if ( ! $total ) {
			return null;
		}
		return array(
			'upem'    => $upem,
			'ascent'  => $s16( $hhea + 4 ),
			'descent' => $s16( $hhea + 6 ),
			'gap'     => $s16( $hhea + 8 ),
			'avg'     => $sum / $total,
		);
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
						$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) );
						if ( ! preg_match( '/\.(woff2?|ttf|otf)$/i', $name ) || 0 !== strpos( $src, 'https://fonts.gstatic.com/' ) ) {
							return $u[0]; // Only font files from Google's font server are ever written to disk.
						}
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
		file_put_contents( $d['path'] . '/fonts.css', '/* Self-hosted by Greenlight from ' . str_replace( '*/', '', $href ) . " */\n" . $css );
		file_put_contents( $d['path'] . '/meta.json', wp_json_encode( array( 'href' => $href, 'built' => time(), 'files' => array_keys( $files ), 'preload' => array_keys( $preload ) ) ) );
		if ( Settings::get( 'fonts_fallback' ) ) {
			self::fallbacks( $href );
		}
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
