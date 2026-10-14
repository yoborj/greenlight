<?php
namespace SpeedKit;

defined( 'ABSPATH' ) || exit;

/**
 * Delays third-party scripts (analytics, pixels, chat, widgets) until the visitor first interacts
 * (or an optional timeout). They then load in their original order.
 *
 * Unlike "delay all JavaScript", the theme's and plugins' own scripts are never touched: delaying
 * them is what kept HFW's fade-in sections invisible and its cookie banner hidden until the first
 * tap. Only scripts matching the pattern list are delayed, and the exclusion list always wins.
 */
class Delay {

	const TYPE = 'text/speedkit-delayed';

	public static function apply( $html, array &$report ) {
		$patterns   = Settings::lines( 'delay_patterns' );
		$exclusions = Settings::lines( 'delay_exclusions' );
		if ( ! $patterns ) {
			return $html;
		}
		$count = 0;
		$html  = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			function ( $m ) use ( $patterns, $exclusions, &$count ) {
				$attrs = $m[1];
				$body  = $m[2];
				$type  = preg_match( '/\stype=(["\'])(.*?)\1/i', $attrs, $t ) ? strtolower( trim( $t[2] ) ) : '';
				if ( '' !== $type && ! in_array( $type, array( 'text/javascript', 'application/javascript', 'module' ), true ) ) {
					return $m[0]; // JSON, templates, already-delayed scripts…
				}
				if ( false !== stripos( $attrs, 'data-speedkit-keep' ) || false !== stripos( $attrs, 'data-no-delay' ) ) {
					return $m[0];
				}
				$hay = $attrs . ' ' . $body;
				if ( ! Html::matches_any( $hay, $patterns ) || Html::matches_any( $hay, $exclusions ) ) {
					return $m[0];
				}
				$count++;
				$new = preg_replace( '/\stype=(["\']).*?\1/i', '', $attrs );
				$new = preg_replace( '/\s(src)=/i', ' data-speedkit-src=', $new, 1 );
				$new = preg_replace( '/\s(async|defer)(?=[\s>]|$|=)/i', ' data-speedkit-$1', $new );
				if ( 'module' === $type ) {
					$new .= ' data-speedkit-module';
				}
				return '<script type="' . self::TYPE . '"' . $new . '>' . $body . '</script>';
			},
			$html
		);
		if ( $count ) {
			$report['delayed'] = $count;
			$timeout = (int) Settings::get( 'delay_timeout' );
			// gtag() is just "push to dataLayer"; defining it early keeps on-page calls (form tracking,
			// click events) from failing before Google's script loads. No other stubs: e.g. Meta's
			// snippet skips itself if fbq already exists.
			$stub = '<script id="speedkit-delay-stub">window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){dataLayer.push(arguments);};</script>';
			$html = Html::after_head_start( $html, $stub );
			$html = Html::before_body_end( $html, '<script id="speedkit-delay">' . self::loader( $timeout ) . '</script>' );
		}
		return $html;
	}

	/** Loads delayed scripts in document order on the first interaction (or after the timeout). */
	private static function loader( $timeout ) {
		return '(function(){var started=false,ev=["keydown","mousedown","mousemove","touchstart","wheel","scroll","pointerdown"];'
			. 'function go(){if(started)return;started=true;ev.forEach(function(e){removeEventListener(e,go,{passive:true});});'
			. 'var q=[].slice.call(document.querySelectorAll(\'script[type="' . self::TYPE . '"]\'));'
			. '(function next(){var s=q.shift();if(!s)return;var n=document.createElement("script");'
			. 'for(var i=0;i<s.attributes.length;i++){var a=s.attributes[i];if(a.name==="type"||a.name.indexOf("data-speedkit-")===0)continue;n.setAttribute(a.name,a.value);}'
			. 'if(s.hasAttribute("data-speedkit-module"))n.type="module";'
			. 'var src=s.getAttribute("data-speedkit-src");'
			. 'if(src){n.src=src;n.async=false;var d=false,f=function(){if(!d){d=true;next();}};n.onload=f;n.onerror=f;setTimeout(f,4000);s.parentNode.replaceChild(n,s);}'
			. 'else{n.text=s.textContent;s.parentNode.replaceChild(n,s);next();}})();}'
			. 'ev.forEach(function(e){addEventListener(e,go,{passive:true});});'
			. ( $timeout > 0 ? 'addEventListener("load",function(){setTimeout(go,' . ( $timeout * 1000 ) . ');});' : '' )
			. '})();';
	}
}
