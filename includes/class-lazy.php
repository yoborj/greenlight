<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Lazy-loading done right:
 * - the first few images (and the hero) load at once; everything below loads lazily (native loading="lazy"),
 * - iframes (maps, embeds) load lazily,
 * - images without width/height get them from the file or media library, so the page doesn't jump,
 * - YouTube embeds become a thumbnail that loads the player on click (saves ~1 MB of player code).
 */
class Lazy {

	/** Each image/embed option works on its own: lazy-loading off must not also switch off the others. */
	public static function needed() {
		foreach ( array( 'lazy', 'lazy_iframes', 'dimensions', 'responsive', 'youtube_facade', 'embeds_delay' ) as $key ) {
			if ( Settings::get( $key ) ) {
				return true;
			}
		}
		return false;
	}

	public static function apply( $html, array &$report ) {
		if ( Settings::get( 'youtube_facade' ) ) {
			$html = self::youtube( $html, $report );
		}
		$start   = Html::body_offset( $html );
		$head    = substr( $html, 0, $start );
		$p       = new \WP_HTML_Tag_Processor( substr( $html, $start ) );
		$eager   = (int) Settings::get( 'lazy_eager_count' );
		$dims    = (bool) Settings::get( 'dimensions' );
		$iframes = (bool) Settings::get( 'lazy_iframes' );
		$lazy    = (bool) Settings::get( 'lazy' );
		$index   = 0;
		$past_hero = false; // Images after the hero are below the first screen: always lazy.
		$n_lazy  = 0;
		$n_dims  = 0;
		$n_shape = 0;
		$n_srcset   = 0;
		$n_iframes  = 0;
		$delay_iframes = Settings::get( 'embeds_delay' ) ? Settings::lines( 'delay_iframes' ) : array();
		$responsive = (bool) Settings::get( 'responsive' );

		while ( $p->next_tag() ) {
			$tag = $p->get_tag();
			if ( 'IMG' !== $tag && $p->get_attribute( 'data-greenlight-lcp' ) ) {
				$past_hero = true; // A CSS-background hero.
			}
			if ( 'IMG' === $tag ) {
				$src = $p->get_attribute( 'src' );
				if ( ! is_string( $src ) || '' === $src || 0 === strpos( $src, 'data:' ) ) {
					continue; // Placeholder from another lazy-loader: leave it to that plugin.
				}
				$index++;
				$is_hero = $p->get_attribute( 'data-greenlight-lcp' ) || 'high' === $p->get_attribute( 'fetchpriority' );
				if ( ! $lazy ) {
					// Lazy-loading switched off: leave loading="" as the theme printed it.
				} elseif ( $is_hero || ( ! $past_hero && $index <= $eager ) ) {
					if ( 'lazy' === $p->get_attribute( 'loading' ) ) {
						$p->remove_attribute( 'loading' );
					}
				} elseif ( null === $p->get_attribute( 'loading' ) ) {
					$p->set_attribute( 'loading', 'lazy' );
					$n_lazy++;
				}
				if ( $is_hero ) {
					$past_hero = true;
				}
				if ( $lazy && null === $p->get_attribute( 'decoding' ) && ! $is_hero ) {
					$p->set_attribute( 'decoding', 'async' );
				}
				if ( $dims ) {
					$w = $p->get_attribute( 'width' );
					$h = $p->get_attribute( 'height' );
					if ( ( null === $w || null === $h ) && ! preg_match( '/\.svg(\?|$)/i', $src ) ) {
						$size = Html::image_size( $src );
						if ( $size ) {
							// Sides Greenlight adds give the browser the image's shape, never its size: the theme's
							// CSS may set only one side (e.g. a logo at height: 40px), and an added attribute on the
							// other side would stretch the image. data-greenlight-size lists the sides we added; the
							// zero-specificity rule below keeps them at "auto" unless the theme's CSS sizes them.
							if ( null === $w && null === $h ) {
								$p->set_attribute( 'width', (string) $size[0] );
								$p->set_attribute( 'height', (string) $size[1] );
								$p->set_attribute( 'data-greenlight-size', 'width height' );
							} elseif ( null === $h && is_numeric( $w ) ) {
								$p->set_attribute( 'height', (string) round( $w * $size[1] / $size[0] ) );
								$p->set_attribute( 'data-greenlight-size', 'height' );
							} elseif ( null === $w && is_numeric( $h ) ) {
								$p->set_attribute( 'width', (string) round( $h * $size[0] / $size[1] ) );
								$p->set_attribute( 'data-greenlight-size', 'width' );
							}
							$n_shape++;
							$n_dims++;
						}
					}
				}
				if ( $responsive && null === $p->get_attribute( 'srcset' ) && ! $is_hero ) {
					$set = Html::srcset( $src );
					if ( $set ) {
						$p->set_attribute( 'srcset', $set );
						// "auto" lets the browser use the laid-out width of lazy images (Chrome 126+); others use the fallback.
						$fallback = $p->get_attribute( 'width' ) && is_numeric( $p->get_attribute( 'width' ) ) ? '(max-width: ' . (int) $p->get_attribute( 'width' ) . 'px) 100vw, ' . (int) $p->get_attribute( 'width' ) . 'px' : '100vw';
						if ( null === $p->get_attribute( 'sizes' ) ) {
							$p->set_attribute( 'sizes', ( 'lazy' === $p->get_attribute( 'loading' ) ? 'auto, ' : '' ) . $fallback );
						}
						$n_srcset++;
					}
				}
			} elseif ( 'IFRAME' === $tag ) {
				$isrc = (string) $p->get_attribute( 'src' );
				if ( $delay_iframes && '' !== $isrc && Html::matches_any( $isrc, $delay_iframes ) ) {
					// Load on the first interaction (like third-party scripts): maps alone are ~450 KB.
					$p->set_attribute( 'data-greenlight-iframe', $isrc );
					$p->remove_attribute( 'src' );
					$n_iframes++;
					continue;
				}
				if ( $iframes && null === $p->get_attribute( 'loading' ) && $isrc ) {
					$p->set_attribute( 'loading', 'lazy' );
					$n_lazy++;
				}
			}
		}
		$report['lazy']       = $n_lazy;
		$report['dimensions'] = $n_dims;
		$report['srcset']     = $n_srcset;
		$html = $head . $p->get_updated_html();
		if ( $n_shape ) {
			// Zero specificity: any width/height the theme's CSS sets wins; otherwise an added side stays "auto", so the
			// image shows exactly as it did before. The attributes still give the browser the aspect ratio.
			$html = Html::before_head_end( $html, '<style id="greenlight-size">:where(img[data-greenlight-size~="width"]){width:auto}:where(img[data-greenlight-size~="height"]){height:auto}</style>' );
		}
		if ( $n_iframes ) {
			$report['embeds on interaction'] = $n_iframes;
			$html = Html::before_body_end( $html, '<script id="greenlight-iframes">(function(){var ev=["keydown","mousedown","mousemove","touchstart","wheel","scroll","pointerdown"],done=false;function go(){if(done)return;done=true;ev.forEach(function(e){removeEventListener(e,go,{passive:true});});document.querySelectorAll("iframe[data-greenlight-iframe]").forEach(function(f){f.src=f.getAttribute("data-greenlight-iframe");f.removeAttribute("data-greenlight-iframe");});}ev.forEach(function(e){addEventListener(e,go,{passive:true});});})();</script>' );
		}
		return $html;
	}

	/** <iframe src="youtube.com/embed/ID"> → thumbnail button that swaps in the player on click. */
	private static function youtube( $html, array &$report ) {
		$count = 0;
		$html  = preg_replace_callback(
			'#<iframe\b[^>]*\ssrc=(["\'])(https?:)?//(?:www\.)?(youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{6,20})[^"\']*)\1[^>]*>\s*</iframe>#i',
			function ( $m ) use ( &$count ) {
				$tag = $m[0];
				$id  = $m[4];
				$src = 'https://www.' . html_entity_decode( $m[3] );
				$src .= ( false === strpos( $src, '?' ) ? '?' : '&' ) . 'autoplay=1';
				$w   = preg_match( '/\swidth=["\']?(\d+)/i', $tag, $wm ) ? (int) $wm[1] : 16;
				$h   = preg_match( '/\sheight=["\']?(\d+)/i', $tag, $hm ) ? (int) $hm[1] : 9;
				$title = preg_match( '/\stitle=(["\'])(.*?)\1/i', $tag, $tm ) ? $tm[2] : 'YouTube video';
				$count++;
				return '<div class="greenlight-yt" style="aspect-ratio:' . $w . '/' . $h . '" data-src="' . esc_url( $src ) . '" data-title="' . esc_attr( $title ) . '">'
					. '<img src="https://i.ytimg.com/vi/' . esc_attr( $id ) . '/hqdefault.jpg" alt="" loading="lazy" decoding="async" width="480" height="360">'
					. '<button type="button" aria-label="' . esc_attr( 'Play video: ' . wp_strip_all_tags( $title ) ) . '"></button></div>';
			},
			$html
		);
		if ( $count ) {
			$report['youtube'] = $count;
			$css  = '<style id="greenlight-yt">.greenlight-yt{position:relative;width:100%;max-width:100%;background:#000;overflow:hidden;cursor:pointer}.greenlight-yt img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.greenlight-yt button{position:absolute;inset:0;margin:auto;width:68px;height:48px;border:0;border-radius:14px;background:rgba(23,23,23,.8);cursor:pointer}.greenlight-yt button::before{content:"";position:absolute;left:27px;top:14px;border-style:solid;border-width:10px 0 10px 17px;border-color:transparent transparent transparent #fff}.greenlight-yt:hover button,.greenlight-yt button:focus-visible{background:#f00}.greenlight-yt iframe{position:absolute;inset:0;width:100%;height:100%;border:0}</style>';
			$js   = '<script id="greenlight-yt-js">document.addEventListener("click",function(e){var b=e.target.closest(".greenlight-yt");if(!b||b.querySelector("iframe"))return;var f=document.createElement("iframe");f.src=b.dataset.src;f.title=b.dataset.title||"YouTube video";f.allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture";f.allowFullscreen=true;b.appendChild(f);});</script>';
			$html = Html::before_head_end( $html, $css );
			$html = Html::before_body_end( $html, $js );
		}
		return $html;
	}
}
