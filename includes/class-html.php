<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Small helpers for editing the finished page HTML.
 */
class Html {

	/** Insert markup right before </head> (or at the top if there is no head). */
	public static function before_head_end( $html, $markup ) {
		if ( '' === $markup ) {
			return $html;
		}
		$pos = stripos( $html, '</head>' );
		return false === $pos ? $markup . $html : substr_replace( $html, $markup, $pos, 0 );
	}

	/**
	 * Insert markup early in <head>: right after the viewport meta tag when there is one, otherwise
	 * right after <head> (and <meta charset>). After the viewport tag matters: until Chrome has seen it,
	 * a phone counts as 980px wide, so a preload with media="(min-width: 768px)" would still download.
	 */
	public static function after_head_start( $html, $markup ) {
		if ( '' === $markup ) {
			return $html;
		}
		$head_end = stripos( $html, '</head>' );
		if ( false !== $head_end && preg_match( '/<meta\s[^>]*name=["\']viewport["\'][^>]*>/i', substr( $html, 0, $head_end ), $v, PREG_OFFSET_CAPTURE ) ) {
			return substr_replace( $html, $markup, $v[0][1] + strlen( $v[0][0] ), 0 );
		}
		if ( preg_match( '/<head\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$at = $m[0][1] + strlen( $m[0][0] );
			// Keep <meta charset> first if it is there.
			if ( preg_match( '/\G\s*<meta\s+charset=[^>]*>/i', $html, $c, 0, $at ) ) {
				$at += strlen( $c[0] );
			}
			return substr_replace( $html, $markup, $at, 0 );
		}
		return self::before_head_end( $html, $markup );
	}

	/** Insert markup right before the last </body>. */
	public static function before_body_end( $html, $markup ) {
		$pos = strripos( $html, '</body>' );
		return false === $pos ? $html . $markup : substr_replace( $html, $markup, $pos, 0 );
	}

	/** Where <body> starts; image work only looks at the body. */
	public static function body_offset( $html ) {
		return preg_match( '/<body\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ? $m[0][1] : 0;
	}

	public static function matches_any( $haystack, array $needles ) {
		foreach ( $needles as $n ) {
			if ( '' !== $n && false !== stripos( $haystack, $n ) ) {
				return $n;
			}
		}
		return false;
	}

	/** First url(...) in an inline style. */
	public static function style_bg_url( $style ) {
		if ( preg_match( '/background(?:-image)?\s*:[^;]*url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', html_entity_decode( (string) $style ), $m ) ) {
			return trim( $m[2] );
		}
		return null;
	}

	/** Is this URL a file in this site's uploads folder? */
	public static function is_upload( $url ) {
		$uploads = wp_get_upload_dir();
		$base    = preg_replace( '#^https?:#', '', $uploads['baseurl'] );
		return 0 === strpos( preg_replace( '#^https?:#', '', $url ), $base );
	}

	/**
	 * Width/height for an image URL: from a "-1024x683" size suffix, or the attachment's metadata.
	 * Cached per request and in the object cache.
	 */
	public static function image_size( $url ) {
		static $seen = array(), $lookups = 0;
		$url = strtok( $url, '?' );
		if ( isset( $seen[ $url ] ) ) {
			return $seen[ $url ];
		}
		if ( preg_match( '/-(\d{2,5})x(\d{2,5})\.(?:jpe?g|png|gif|webp|avif)$/i', $url, $m ) ) {
			return $seen[ $url ] = array( (int) $m[1], (int) $m[2] );
		}
		$size = null;
		if ( self::is_upload( $url ) && $lookups < 30 ) {
			$key   = 'greenlight_isz_' . md5( $url );
			$cache = wp_cache_get( $key, 'greenlight' );
			if ( false !== $cache ) {
				return $seen[ $url ] = $cache ?: null;
			}
			$lookups++;
			$id   = attachment_url_to_postid( $url );
			$meta = $id ? wp_get_attachment_metadata( $id ) : null;
			if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
				$size = array( (int) $meta['width'], (int) $meta['height'] );
			}
			wp_cache_set( $key, $size ?: 0, 'greenlight', DAY_IN_SECONDS );
		}
		return $seen[ $url ] = $size;
	}

	/** The 2048px copy of an uploaded image when the original is wider (WordPress makes it), or null. */
	public static function desktop_copy( $url ) {
		if ( ! self::is_upload( $url ) || preg_match( '/-\d{2,5}x\d{2,5}\.\w+$/', strtok( $url, '?' ) ) ) {
			return null;
		}
		$id   = attachment_url_to_postid( strtok( $url, '?' ) );
		$meta = $id ? wp_get_attachment_metadata( $id ) : null;
		if ( empty( $meta['width'] ) || $meta['width'] <= 2048 ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $id, '2048x2048' );
		return ( $src && ! empty( $src[3] ) ) ? $src[0] : null;
	}

	/** srcset for an uploaded image (all the sizes WordPress made), or null. */
	public static function srcset( $url ) {
		static $lookups = 0;
		if ( ! self::is_upload( $url ) || $lookups >= 40 ) {
			return null;
		}
		$lookups++;
		$clean = strtok( $url, '?' );
		$id    = attachment_url_to_postid( preg_replace( '/-\d{2,5}x\d{2,5}(\.\w+)$/', '$1', $clean ) ) ?: attachment_url_to_postid( $clean );
		if ( ! $id ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( empty( $meta['width'] ) ) {
			return null;
		}
		// Size the srcset from the file this <img> actually points at.
		$size = preg_match( '/-(\d{2,5})x(\d{2,5})\.\w+$/', $clean, $m ) ? array( (int) $m[1], (int) $m[2] ) : array( (int) $meta['width'], (int) $meta['height'] );
		$set  = wp_calculate_image_srcset( $size, $url, $meta, $id );
		return $set ?: null;
	}

	/** Local file path for an uploads URL, or null. */
	public static function upload_path( $url ) {
		if ( ! self::is_upload( $url ) ) {
			return null;
		}
		$u   = wp_get_upload_dir();
		$rel = substr( preg_replace( '#^https?:#', '', strtok( $url, '?' ) ), strlen( preg_replace( '#^https?:#', '', $u['baseurl'] ) ) );
		return $u['basedir'] . $rel;
	}

	/**
	 * WebP copy of an uploaded JPEG/PNG ("photo.jpg" → "photo.jpg.webp"): its URL when it exists;
	 * otherwise a background job makes it and the original URL is returned for now.
	 */
	public static function webp( $url ) {
		if ( ! preg_match( '/\.(jpe?g|png)$/i', strtok( $url, '?' ) ) ) {
			return $url;
		}
		$path = self::upload_path( $url );
		if ( ! $path || ! is_readable( $path ) ) {
			return $url;
		}
		if ( is_readable( $path . '.webp' ) ) {
			return strtok( $url, '?' ) . '.webp';
		}
		if ( ! wp_next_scheduled( 'greenlight_make_webp', array( $path ) ) ) {
			wp_schedule_single_event( time(), 'greenlight_make_webp', array( $path ) );
		}
		return $url;
	}

	/** Background job: write "<file>.webp" next to an uploaded JPEG/PNG (kept only if smaller). */
	public static function make_webp( $path ) {
		$u = wp_get_upload_dir();
		if ( 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( $u['basedir'] ) ) || ! is_readable( $path ) || file_exists( $path . '.webp' ) ) {
			return;
		}
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return;
		}
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return;
		}
		$editor->set_quality( 78 );
		$saved = $editor->save( $path . '.tmp.webp', 'image/webp' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return;
		}
		if ( filesize( $saved['path'] ) < filesize( $path ) ) {
			rename( $saved['path'], $path . '.webp' );
			do_action( 'greenlight_purge_all', 'webp made' );
		} else {
			unlink( $saved['path'] );
		}
	}

	/** A lighter copy of an uploaded image for phones (the 1536px or 1024px size), or null. */
	public static function phone_copy( $url ) {
		if ( ! self::is_upload( $url ) || preg_match( '/-\d{2,5}x\d{2,5}\.\w+$/', strtok( $url, '?' ) ) ) {
			return null;
		}
		$id = attachment_url_to_postid( strtok( $url, '?' ) );
		if ( ! $id ) {
			return null;
		}
		foreach ( array( '1536x1536', 'large' ) as $size ) {
			$src = wp_get_attachment_image_src( $id, $size );
			if ( $src && ! empty( $src[3] ) && $src[1] < 2000 ) {
				return $src[0];
			}
		}
		return null;
	}
}
