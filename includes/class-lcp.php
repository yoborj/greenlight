<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Hero image (Largest Contentful Paint).
 *
 * Finds the first big image near the top of the page, either an <img> or an element with an inline
 * background-image (hero sections often are), then:
 * - removes lazy-loading from it (lazy-loading the hero delays LCP by seconds),
 * - gives it fetchpriority="high" and preloads it in <head>,
 * - for inline backgrounds from the media library, sends phones the 1536px copy instead of the
 *   original (HFW: 340-410 KB originals vs 140-170 KB, same look on a phone).
 */
class Lcp {

	/** @var string|null The chosen hero image URL (used by Lazy to keep it eager). */
	public static $url = null;

	public static function apply( $html, array &$report ) {
		$skip   = Settings::lines( 'lcp_skip' );
		$start  = Html::body_offset( $html );
		$head   = substr( $html, 0, $start );
		$p      = new \WP_HTML_Tag_Processor( substr( $html, $start ) );
		$seen   = 0;
		$found  = false;
		$styles = '';
		$links  = '';

		while ( $seen < 20 && $p->next_tag() ) {
			$tag = $p->get_tag();

			if ( 'IMG' === $tag ) {
				$seen++;
				$src = self::img_src( $p );
				if ( ! $src || self::skip( $p, $src, $skip ) ) {
					continue;
				}
				$w = (int) $p->get_attribute( 'width' );
				$h = (int) $p->get_attribute( 'height' );
				if ( $w && $h && $w * $h < 200 * 200 ) {
					continue; // Too small to be the hero (icons, logos).
				}
				// Undo other lazy-loaders on this one image.
				$lazy_src = $p->get_attribute( 'data-src' ) ?: $p->get_attribute( 'data-lazy-src' );
				if ( $lazy_src && ( ! $p->get_attribute( 'src' ) || 0 === strpos( (string) $p->get_attribute( 'src' ), 'data:' ) ) ) {
					$p->set_attribute( 'src', $lazy_src );
					$srcset = $p->get_attribute( 'data-srcset' ) ?: $p->get_attribute( 'data-lazy-srcset' );
					if ( $srcset ) {
						$p->set_attribute( 'srcset', $srcset );
					}
				}
				if ( Settings::get( 'lcp_webp' ) ) {
					$src = Html::webp( $src );
					$p->set_attribute( 'src', $src );
					$set = $p->get_attribute( 'srcset' );
					if ( is_string( $set ) && '' !== $set ) {
						$p->set_attribute( 'srcset', preg_replace_callback( '/(\S+)(\s+\d+[wx])/', function ( $c ) { return Html::webp( $c[1] ) . $c[2]; }, $set ) );
					}
				}
				$p->remove_attribute( 'loading' );
				$p->set_attribute( 'fetchpriority', 'high' );
				$p->set_attribute( 'data-greenlight-lcp', '1' );
				$srcset = $p->get_attribute( 'srcset' );
				$sizes  = $p->get_attribute( 'sizes' );
				$links .= '<link rel="preload" as="image" href="' . esc_url( $src ) . '"' . ( $srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '"' . ( $sizes ? ' imagesizes="' . esc_attr( $sizes ) . '"' : '' ) : '' ) . ' fetchpriority="high">' . "\n";
				self::$url = $src;
				$found     = 'img';
				break;
			}

			$style = $p->get_attribute( 'style' );
			if ( is_string( $style ) && false !== stripos( $style, 'url(' ) ) {
				$seen++;
				$url = Html::style_bg_url( $style );
				if ( ! $url || 0 === strpos( $url, 'data:' ) || Html::matches_any( $url . ' ' . (string) $p->get_attribute( 'class' ), $skip ) ) {
					continue;
				}
				// Other lazy-loaders keep backgrounds in data-bg; nothing to undo for inline styles.
				$p->set_attribute( 'data-greenlight-lcp', '1' );
				$phone   = Settings::get( 'lcp_phone_copy' ) ? Html::phone_copy( $url ) : null;
				$desktop = Settings::get( 'lcp_desktop_copy' ) ? Html::desktop_copy( $url ) : null;
				if ( $desktop ) {
					// Originals wider than 2048px are wasted on a hero; use WordPress's 2048px copy everywhere.
					$style = str_replace( $url, $desktop, $style );
					$url   = $desktop;
				}
				if ( Settings::get( 'lcp_webp' ) ) {
					$webp  = Html::webp( $url );
					$style = str_replace( $url, $webp, $style );
					$url   = $webp;
					$phone = $phone ? Html::webp( $phone ) : null;
				}
				$p->set_attribute( 'style', $style );
				if ( $phone ) {
					$p->set_attribute( 'style', rtrim( $style, "; \t" ) . ";--greenlight-phone-bg:url('" . esc_url( $phone ) . "')" );
					$links  .= '<link rel="preload" as="image" href="' . esc_url( $phone ) . '" media="(max-width: 767px)" fetchpriority="high">' . "\n";
					$links  .= '<link rel="preload" as="image" href="' . esc_url( $url ) . '" media="(min-width: 768px)" fetchpriority="high">' . "\n";
					$styles .= '@media (max-width:767px){[data-greenlight-lcp][style*="--greenlight-phone-bg"]{background-image:var(--greenlight-phone-bg)!important}}';
				} else {
					$links .= '<link rel="preload" as="image" href="' . esc_url( $url ) . '" fetchpriority="high">' . "\n";
				}
				self::$url = $url;
				$found     = 'background';
				break;
			}
		}

		if ( ! $found ) {
			return $html;
		}
		$html = $head . $p->get_updated_html();
		if ( $styles ) {
			$links .= '<style id="greenlight-lcp">' . $styles . '</style>' . "\n";
		}
		$report['lcp'] = $found . ' ' . wp_basename( strtok( self::$url, '?' ) );
		return Html::after_head_start( $html, $links );
	}

	private static function img_src( \WP_HTML_Tag_Processor $p ) {
		foreach ( array( 'src', 'data-src', 'data-lazy-src' ) as $attr ) {
			$v = $p->get_attribute( $attr );
			if ( is_string( $v ) && '' !== $v && 0 !== strpos( $v, 'data:' ) ) {
				return $v;
			}
		}
		return null;
	}

	private static function skip( \WP_HTML_Tag_Processor $p, $src, array $skip ) {
		if ( preg_match( '/\.svg(\?|$)/i', $src ) ) {
			return true;
		}
		$hay = $src . ' ' . $p->get_attribute( 'class' ) . ' ' . $p->get_attribute( 'alt' ) . ' ' . $p->get_attribute( 'id' );
		return (bool) Html::matches_any( (string) $hay, $skip );
	}
}
