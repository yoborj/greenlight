<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Defer render-blocking scripts in <head> (First Contentful Paint, Speed Index).
 *
 * Uses WordPress's own loading strategies (WP 6.3+): WordPress only applies "defer" when every
 * script that depends on it can also wait, and otherwise prints it as before. On top of that,
 * jQuery and any script with inline code attached are left alone, because inline code runs at once
 * and may call the script before a deferred copy has loaded.
 */
class Defer {

	public static function init() {
		add_action( 'wp_print_scripts', array( __CLASS__, 'mark' ), 1 );
	}

	public static function mark() {
		if ( is_admin() || ! Settings::get( 'enabled' ) || ! Settings::get( 'defer_head' ) || ! Optimizer::should_run() ) {
			return;
		}
		$scripts = wp_scripts();
		$keep    = array_merge( array( 'jquery', 'jquery-core', 'jquery-migrate' ), Settings::lines( 'defer_exclusions' ) );
		$count   = 0;
		foreach ( $scripts->queue as $handle ) {
			self::mark_one( $scripts, $handle, $keep, $count );
		}
		$GLOBALS['greenlight_deferred'] = $count;
		if ( Settings::get( 'jquery_footer' ) ) {
			self::jquery_to_footer( $scripts );
		}
	}

	/**
	 * Move jQuery and every <head> script that needs it to the footer, where WordPress prints them in
	 * dependency order with their inline code. Optimizer::process() then checks the finished page and
	 * moves them back into <head> if anything earlier on the page still needs jQuery.
	 */
	private static function jquery_to_footer( \WP_Scripts $scripts ) {
		$needs = function ( $handle, $seen = array() ) use ( &$needs, $scripts ) {
			$s = $scripts->registered[ $handle ] ?? null;
			if ( ! $s || isset( $seen[ $handle ] ) ) {
				return false;
			}
			$seen[ $handle ] = true;
			foreach ( $s->deps as $d ) {
				if ( in_array( $d, array( 'jquery', 'jquery-core' ), true ) || $needs( $d, $seen ) ) {
					return true;
				}
			}
			return false;
		};
		$moved = array();
		foreach ( array( 'jquery', 'jquery-core', 'jquery-migrate' ) as $h ) {
			if ( isset( $scripts->registered[ $h ] ) && empty( $scripts->registered[ $h ]->extra['group'] ) ) {
				$scripts->add_data( $h, 'group', 1 );
				$moved[] = $h;
			}
		}
		foreach ( $scripts->registered as $h => $s ) {
			if ( empty( $s->extra['group'] ) && $needs( $h ) ) {
				$scripts->add_data( $h, 'group', 1 );
				$moved[] = $h;
			}
		}
		$GLOBALS['greenlight_jquery_moved'] = $moved;
	}

	/**
	 * Safety net for jquery_to_footer(): if inline code or an unknown blocking script comes before
	 * jQuery in the finished page, put the moved scripts back at the end of <head> (as before).
	 */
	public static function verify_jquery( $html, array &$report ) {
		$moved = $GLOBALS['greenlight_jquery_moved'] ?? array();
		if ( ! $moved || false === ( $jq = strpos( $html, "id='jquery-core-js'" ) ?: strpos( $html, 'id="jquery-core-js"' ) ) ) {
			return $html;
		}
		$ids = array();
		foreach ( $moved as $h ) {
			foreach ( array( '-js', '-js-before', '-js-after', '-js-extra', '-js-translations' ) as $suffix ) {
				$ids[ $h . $suffix ] = true;
			}
		}
		$unsafe = null;
		$culprit = null;
		$before = substr( $html, 0, $jq );
		if ( preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $before, $all, PREG_SET_ORDER ) ) {
			foreach ( $all as $m ) {
				$id = preg_match( '/\sid=(["\'])(.*?)\1/i', $m[1], $im ) ? $im[2] : '';
				if ( isset( $ids[ $id ] ) || preg_match( '/\stype=(["\'])(?!text\/javascript|module)/i', $m[1] ) ) {
					continue; // One of ours, or not JavaScript (JSON, delayed…).
				}
				$src = preg_match( '/\ssrc=(["\'])(.*?)\1/i', $m[1], $sm ) ? $sm[2] : '';
				if ( '' === $src && preg_match( '/\bjQuery\b|\$\(\s*(?:document|function|window)/', $m[2] ) ) {
					$unsafe  = 'inline code uses jQuery' . ( $id ? " ({$id})" : '' );
					$culprit = $m[0];
					break;
				}
				// An external, blocking script WordPress doesn't know about might use jQuery too.
				if ( '' !== $src && ! preg_match( '/\s(async|defer)\b/i', $m[1] ) && ! preg_match( '/-js$/', $id ) ) {
					$unsafe  = 'unregistered blocking script ' . wp_basename( strtok( $src, '?' ) );
					$culprit = $m[0];
					break;
				}
			}
		}
		if ( ! $unsafe ) {
			$report['jquery'] = 'footer';
			return $html;
		}
		// Undo: take the moved tags out (in order) and put them back right before the first code that needs them.
		$block = '';
		$html  = preg_replace_callback(
			'#<script\b[^>]*\sid=(["\'])([^"\']+)\1[^>]*>.*?</script>\s*#is',
			function ( $m ) use ( $ids, &$block ) {
				if ( isset( $ids[ $m[2] ] ) ) {
					$block .= $m[0];
					return '';
				}
				return $m[0];
			},
			$html
		);
		$report['jquery'] = 'kept early (' . $unsafe . ')';
		$at = strpos( $html, $culprit );
		return false === $at ? Html::before_head_end( $html, $block ) : substr_replace( $html, $block, $at, 0 );
	}

	private static function mark_one( \WP_Scripts $scripts, $handle, array $keep, &$count ) {
		$s = $scripts->registered[ $handle ] ?? null;
		if ( ! $s || ! $s->src || in_array( $handle, $keep, true ) || Html::matches_any( (string) $s->src, $keep ) ) {
			return;
		}
		// Footer scripts don't block rendering; scripts that already have a strategy are fine.
		if ( ! empty( $s->extra['group'] ) || ! empty( $s->extra['strategy'] ) ) {
			return;
		}
		if ( ! empty( $s->extra['before'] ) || ! empty( $s->extra['after'] ) || ! empty( $s->extra['data'] ) ) {
			return; // Inline code attached (including wp_localize_script data).
		}
		$scripts->add_data( $handle, 'strategy', 'defer' );
		$count++;
	}
}
