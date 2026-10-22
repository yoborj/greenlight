<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Content check, built to stay quiet on normal editing:
 * - Each update of a published post is compared with the version before it; only what was added counts.
 * - Findings: scripts, iframes/objects/embeds, inline event handlers and javascript: links, forms that
 *   post to another site, meta refresh redirects, and links hidden on purpose (display:none, zero font
 *   size, off-screen) that point to another site. Plain visible links never count.
 * - Domains already in the content at launch (the baseline), the trusted list (by default the embeds
 *   Greenlight knows: maps, YouTube, Vimeo, reviews, booking and forms) and this site are trusted.
 * - Severity by who edited: with unfiltered_html, trusted domains don't alert; without it, anything kses
 *   should have stripped is high. Content changed with no edit or revision behind it (a direct database
 *   write, found by the daily scan) is always high.
 * - Spam words never alert on their own; added ones raise the severity one step.
 */
class Content_Check {

	const STATE = 'greenlight_content_state';
	const META  = '_greenlight_content_hash';
	const BATCH = 100;

	const RANK = array( 'low' => 1, 'medium' => 2, 'high' => 3 );

	/** Finding kinds kses removes for users without unfiltered_html. */
	const KSES_STRIPS = array( 'script', 'embed', 'inline code', 'redirect' );

	public static function init() {
		if ( Settings::get( 'content_check' ) ) {
			add_action( 'wp_after_insert_post', array( __CLASS__, 'on_save' ), 20, 4 );
		}
	}

	/** Post types whose content shows on the site, including synced patterns, templates and menus. */
	public static function types() {
		$types = array_diff( array_values( get_post_types( array( 'public' => true ) ) ), array( 'attachment' ) );
		return array_values( array_merge( $types, array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ) ) );
	}

	public static function state() {
		$state = get_option( self::STATE );
		return ( is_array( $state ) ? $state : array() ) + array(
			'phase'         => 'idle', // baseline | scan | idle
			'cursor'        => 0,
			'baseline'      => array(),
			'baseline_done' => 0,
			'scan_done'     => 0,
		);
	}

	private static function save( array $state ) {
		update_option( self::STATE, $state, false );
	}

	/* Trust. */

	/** The trusted list plus the hosts of the embeds held back under Images & embeds. For the JSON contract. */
	public static function embed_domains() {
		$out = array();
		foreach ( array_merge( Settings::lines( 'content_trusted' ), Settings::lines( 'delay_iframes' ) ) as $entry ) {
			$domain = Security::domain( $entry );
			if ( $domain ) {
				$out[ $domain ] = true;
			}
		}
		// player.vimeo.com is already covered by vimeo.com.
		$out = array_keys( $out );
		$out = array_values( array_filter( $out, function ( $d ) use ( $out ) { return ! Security::in_domains( $d, array_diff( $out, array( $d ) ) ); } ) );
		sort( $out );
		return $out;
	}

	public static function trusted() {
		return array_merge( self::embed_domains(), self::state()['baseline'] );
	}

	public static function is_trusted( $domain, array $trusted ) {
		return Security::is_own( $domain ) || Security::in_domains( $domain, $trusted );
	}

	/** "Trust this domain": add it to the trusted list (or switch it back on). */
	public static function trust( $domain ) {
		$domain = Security::domain( $domain );
		if ( ! $domain ) {
			return false;
		}
		$all   = Settings::all();
		$lines = array();
		foreach ( Settings::entries( 'content_trusted' ) as $e ) {
			$lines[ $e['value'] ] = ( $e['on'] ? '' : '#' ) . $e['value'];
		}
		$lines[ $domain ]       = $domain;
		$all['content_trusted'] = implode( "\n", $lines );
		update_option( Settings::OPTION, Settings::sanitize( $all ) );
		return $domain;
	}

	/* Edits made through WordPress. */

	public static function on_save( $post_id, $post, $update, $post_before ) {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ! in_array( $post->post_type, self::types(), true ) ) {
			return;
		}
		try {
			update_post_meta( $post_id, self::META, md5( $post->post_content ) );
			if ( 'publish' !== $post->post_status ) {
				return;
			}
			// Content added while the post was a draft counts as added when it goes live.
			$old = ( $post_before instanceof \WP_Post && 'publish' === $post_before->post_status ) ? $post_before->post_content : '';
			if ( $old !== $post->post_content ) {
				self::check( $post, $old, current_user_can( 'unfiltered_html' ) ? 'unfiltered' : 'filtered' );
			}
		} catch ( \Throwable $e ) {
			return; // Never get in the way of saving.
		}
	}

	/**
	 * Compare two versions and alert on what was added. $editor: unfiltered | filtered | database.
	 * $whole: the previous version is unknown, so everything counts as added (trusted domains still skip).
	 */
	public static function check( \WP_Post $post, $old, $editor, $whole = false ) {
		$before = array();
		foreach ( self::findings( $old ) as $f ) {
			$before[ $f['key'] ] = ( $before[ $f['key'] ] ?? 0 ) + 1;
		}
		$added = array();
		foreach ( self::findings( $post->post_content ) as $f ) {
			if ( ! empty( $before[ $f['key'] ] ) ) {
				$before[ $f['key'] ]--;
			} else {
				$added[] = $f;
			}
		}
		if ( ! $added ) {
			return null;
		}
		$spam     = self::spam_added( $old, $post->post_content );
		$trusted  = self::trusted();
		$severity = null;
		$labels   = array();
		$domains  = array();
		$bypassed = false; // Something kses strips for this editor got through.
		foreach ( $added as $f ) {
			$is_trusted = null !== $f['domain'] && self::is_trusted( $f['domain'], $trusted );
			$level      = self::severity( $f['kind'], $editor, $is_trusted, $whole );
			if ( ! $level ) {
				continue;
			}
			if ( $spam ) {
				$level = 'low' === $level ? 'medium' : 'high';
			}
			if ( ! $severity || self::RANK[ $level ] > self::RANK[ $severity ] ) {
				$severity = $level;
			}
			$labels[ $f['label'] ] = true;
			$bypassed              = $bypassed || ( 'filtered' === $editor && in_array( $f['kind'], self::KSES_STRIPS, true ) );
			if ( null !== $f['domain'] && ! $is_trusted ) {
				$domains[ $f['domain'] ] = true;
			}
		}
		if ( ! $severity ) {
			return null;
		}
		$labels = array_keys( $labels );
		$what   = implode( '; ', array_slice( $labels, 0, 4 ) ) . ( count( $labels ) > 4 ? sprintf( '; %d more', count( $labels ) - 4 ) : '' );
		$type   = get_post_type_object( $post->post_type );
		$title  = sprintf( '%s “%s” (#%d)', $type ? $type->labels->singular_name : $post->post_type, wp_html_excerpt( $post->post_title ?: '(no title)', 60, '…' ), $post->ID );
		if ( 'database' === $editor ) {
			$who = 'Changed outside WordPress: no edit or revision was recorded.';
		} else {
			$user = wp_get_current_user();
			$name = $user->exists() ? '“' . $user->user_login . '”' : 'no logged-in user';
			$who  = 'unfiltered' === $editor ? "Edited by {$name}." : "Edited by {$name}, who can't post unfiltered HTML." . ( $bypassed ? ' WordPress strips scripts and embeds for this user, so something got around it.' : '' );
		}
		$message = sprintf( '%s: added %s. %s', $title, $what, $who ) . ( $spam ? ' Spam words added: ' . implode( ', ', $spam ) . '.' : '' );
		$edit    = in_array( $post->post_type, array( 'wp_template', 'wp_template_part', 'wp_navigation' ), true ) ? admin_url( 'site-editor.php' ) : admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
		return Alerts::add( 'content_check', $severity, $message, $edit, $post->ID, array( 'domains' => array_keys( $domains ) ) );
	}

	private static function severity( $kind, $editor, $trusted, $whole ) {
		if ( 'database' === $editor ) {
			return ( $whole && $trusted ) ? null : 'high';
		}
		if ( 'filtered' === $editor ) {
			if ( in_array( $kind, self::KSES_STRIPS, true ) ) {
				return 'high'; // kses strips these for users without unfiltered_html: someone got around it.
			}
			return $trusted ? null : 'medium';
		}
		if ( $trusted ) {
			return null;
		}
		return 'inline code' === $kind ? 'low' : 'medium';
	}

	/** Spam words that appear more often in the new version (text and link targets). */
	private static function spam_added( $old, $new ) {
		$text = function ( $html ) {
			preg_match_all( '#\shref\s*=\s*["\']?([^"\'\s>]+)#i', $html, $m );
			return strtolower( wp_strip_all_tags( $html ) . ' ' . implode( ' ', $m[1] ) );
		};
		$a     = $text( $old );
		$b     = $text( $new );
		$added = array();
		foreach ( Settings::lines( 'content_spam_words' ) as $word ) {
			$word = strtolower( $word );
			if ( '' !== $word && substr_count( $b, $word ) > substr_count( $a, $word ) ) {
				$added[] = $word;
			}
		}
		return $added;
	}

	/**
	 * What in a piece of content could load code, embed another site or hide a link.
	 * Each: kind, domain (null for inline code), key (to compare versions), label.
	 */
	public static function findings( $html ) {
		if ( '' === trim( (string) $html ) ) {
			return array();
		}
		if ( ! class_exists( '\DOMDocument' ) ) {
			return self::findings_without_dom( $html );
		}
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$out = array();
		foreach ( $doc->getElementsByTagName( '*' ) as $el ) {
			$tag = strtolower( $el->nodeName );
			foreach ( $el->attributes as $attr ) {
				$name = strtolower( $attr->nodeName );
				if ( strlen( $name ) > 2 && 0 === strpos( $name, 'on' ) ) {
					$out[] = self::finding( 'inline code', null, "{$tag}|{$name}|" . md5( $attr->nodeValue ), "inline {$name} handler" );
				}
			}
			if ( 'script' === $tag ) {
				$src = trim( $el->getAttribute( 'src' ) );
				if ( '' === $src ) {
					$out[] = self::finding( 'inline code', null, 'script|' . md5( trim( $el->textContent ) ), 'inline script' );
				} else {
					$host  = Security::url_host( $src );
					$out[] = self::finding( 'script', $host, 'script|' . self::key_url( $src ), 'script from ' . ( $host ?: 'a ' . strtok( $src, ':' ) . ': URL' ) );
				}
			} elseif ( in_array( $tag, array( 'iframe', 'frame', 'embed', 'object' ), true ) ) {
				$src   = trim( $el->getAttribute( 'object' === $tag ? 'data' : 'src' ) ?: $el->getAttribute( 'data-src' ) );
				$host  = '' === $src ? Security::own_host() : Security::url_host( $src );
				$out[] = self::finding( 'embed', $host, $tag . '|' . self::key_url( $src ), $tag . ' from ' . ( $host ?: 'a ' . strtok( $src, ':' ) . ': URL' ) );
			} elseif ( 'form' === $tag ) {
				$host = Security::external_host( $el->getAttribute( 'action' ) );
				if ( $host ) {
					$out[] = self::finding( 'form', $host, 'form|' . self::key_url( $el->getAttribute( 'action' ) ), 'form posting to ' . $host );
				}
			} elseif ( 'meta' === $tag && 'refresh' === strtolower( $el->getAttribute( 'http-equiv' ) ) ) {
				$url  = preg_match( '/url\s*=\s*[\'"]?([^\'";\s]+)/i', $el->getAttribute( 'content' ), $m ) ? $m[1] : '';
				$host = Security::url_host( $url );
				$out[] = self::finding( 'redirect', $host, 'refresh|' . self::key_url( $url ), 'redirect to ' . ( $host ?: 'another page' ) );
			} elseif ( 'a' === $tag ) {
				$href = trim( $el->getAttribute( 'href' ) );
				if ( preg_match( '/^\s*javascript:/i', $href ) ) {
					$out[] = self::finding( 'inline code', null, 'a|' . md5( $href ), 'javascript: link' );
				} else {
					$host = Security::external_host( $href );
					if ( $host && self::hidden( $el ) ) {
						$out[] = self::finding( 'hidden link', $host, 'a|' . self::key_url( $href ), 'hidden link to ' . $host );
					}
				}
			}
		}
		return $out;
	}

	private static function finding( $kind, $domain, $key, $label ) {
		return array( 'kind' => $kind, 'domain' => $domain, 'key' => $kind . '|' . $key, 'label' => $label );
	}

	/** http/https doesn't make it a different element (an https migration isn't an addition). */
	private static function key_url( $url ) {
		return preg_replace( '#^https?:#i', '', trim( html_entity_decode( (string) $url, ENT_QUOTES ) ) );
	}

	/** Hidden on purpose by an inline style (or the hidden attribute) on the link or a parent. */
	private static function hidden( \DOMElement $el ) {
		for ( $n = $el; $n instanceof \DOMElement; $n = $n->parentNode ) {
			if ( $n->hasAttribute( 'hidden' ) ) {
				return true;
			}
			$style = strtolower( preg_replace( '/\s+/', '', $n->getAttribute( 'style' ) ) );
			if ( '' === $style ) {
				continue;
			}
			$style = ';' . $style;
			if ( preg_match( '/;(display:none|visibility:hidden)/', $style ) || preg_match( '/;font-size:0(\.0+)?(px|em|rem|pt|%)?(!important)?(;|$)/', $style ) ) {
				return true;
			}
			if ( preg_match_all( '/;(left|top|right|bottom|text-indent|margin-left|margin-top):-(\d+(?:\.\d+)?)(px|em|rem)/', $style, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $o ) {
					if ( (float) $o[2] >= ( 'px' === $o[3] ? 500 : 30 ) ) {
						return true; // Off-screen.
					}
				}
			}
		}
		return false;
	}

	/** Without PHP's DOM extension: scripts and embeds only (hidden links need the element tree). */
	private static function findings_without_dom( $html ) {
		$out = array();
		preg_match_all( '#<(script|iframe|embed|object)\b([^>]*)>#i', $html, $m, PREG_SET_ORDER );
		foreach ( $m as $t ) {
			$tag = strtolower( $t[1] );
			$src = preg_match( '#\s(?:src|data)\s*=\s*["\']?([^"\'\s>]+)#i', $t[2], $s ) ? $s[1] : '';
			if ( 'script' === $tag && '' === $src ) {
				$out[] = self::finding( 'inline code', null, 'script|' . md5( $t[0] ), 'inline script' );
				continue;
			}
			$host  = '' === $src ? Security::own_host() : Security::url_host( $src );
			$out[] = self::finding( 'script' === $tag ? 'script' : 'embed', $host, $tag . '|' . self::key_url( $src ), $tag . ' from ' . ( $host ?: 'an unknown source' ) );
		}
		return $out;
	}

	/** Other sites' hosts that content links to or loads from. */
	private static function hosts( $html ) {
		$out = array();
		if ( preg_match_all( '#\s(?:src|href|action|data)\s*=\s*["\']?((?:https?:)?//[^"\'\s>]+)#i', (string) $html, $m ) ) {
			foreach ( $m[1] as $url ) {
				$host = Security::external_host( $url );
				if ( $host ) {
					$out[ $host ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	/* The baseline and the daily scan for changes made outside WordPress. */

	/** Record every domain in published content as trusted, and fingerprint each post. Runs in batches. */
	public static function start_baseline() {
		$state             = self::state();
		$state['phase']    = 'baseline';
		$state['cursor']   = 0;
		$state['baseline'] = array();
		self::save( $state );
		Alerts::soon();
	}

	public static function start_scan() {
		$state = self::state();
		if ( 'idle' === $state['phase'] ) {
			$state['phase']  = $state['baseline_done'] ? 'scan' : 'baseline';
			$state['cursor'] = 0;
			self::save( $state );
		}
	}

	/** Work until $until. Returns true when there is more to do. */
	public static function step( $until ) {
		global $wpdb;
		$state = self::state();
		if ( 'idle' === $state['phase'] ) {
			return false;
		}
		$types = self::types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_content, m.meta_value AS hash FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.ID > %d AND p.post_status = 'publish' AND p.post_type IN ($in) ORDER BY p.ID LIMIT %d", array_merge( array( self::META, $state['cursor'] ), $types, array( self::BATCH ) ) ) );
			$seen = array();
			foreach ( $rows as $row ) {
				$state['cursor'] = (int) $row->ID;
				if ( isset( $seen[ $row->ID ] ) ) {
					continue; // A duplicate meta row.
				}
				$seen[ $row->ID ] = true;
				$hash             = md5( $row->post_content );
				if ( 'baseline' === $state['phase'] ) {
					$state['baseline'] = array_merge( $state['baseline'], self::hosts( $row->post_content ) );
					if ( $hash !== $row->hash ) {
						update_post_meta( $row->ID, self::META, $hash );
					}
				} elseif ( $hash !== $row->hash ) {
					self::changed_outside( (int) $row->ID, $row->hash, $hash );
				}
			}
			if ( 'baseline' === $state['phase'] ) {
				$state['baseline'] = array_values( array_unique( $state['baseline'] ) );
			}
			if ( count( $rows ) < self::BATCH ) {
				if ( 'baseline' === $state['phase'] ) {
					sort( $state['baseline'] );
					$state['baseline_done'] = time();
				} else {
					$state['scan_done'] = time();
				}
				$state['phase'] = 'idle';
				self::save( $state );
				return false;
			}
		} while ( microtime( true ) < $until );
		self::save( $state );
		return true;
	}

	/**
	 * The content no longer matches its fingerprint from the last save through WordPress. Compare with
	 * the latest revision when that revision is the version we fingerprinted; otherwise check it whole.
	 */
	private static function changed_outside( $post_id, $old_hash, $hash ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$prev = null;
		if ( $old_hash ) {
			foreach ( wp_get_post_revisions( $post_id, array( 'posts_per_page' => 1 ) ) as $rev ) {
				if ( md5( $rev->post_content ) === $old_hash ) {
					$prev = $rev->post_content;
				}
			}
		}
		update_post_meta( $post_id, self::META, $hash ); // Alert once per change.
		self::check( $post, (string) $prev, 'database', null === $prev );
	}
}
