<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * WebP copies of every uploaded JPEG/PNG a page shows, and of new uploads. Pages switch an image to
 * its WebP copy once it exists; missing copies go into a queue that WP-Cron works through a few
 * seconds at a time, two minutes apart, clearing the host cache once per batch (not per image).
 * Originals are never changed.
 */
class Images {

	const QUEUE = 'greenlight_webp_queue';
	const HOOK  = 'greenlight_webp_batch';
	const MAX   = 500; // Waiting at most; the rest are picked up when pages show them again.

	/** Paths found during this request, saved once at shutdown. */
	private static $found = array();

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'batch' ) );
		if ( Settings::get( 'webp_images' ) ) {
			add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 20, 2 );
		}
	}

	public static function queue( $path ) {
		if ( ! self::$found ) {
			add_action( 'shutdown', array( __CLASS__, 'save' ), 3 ); // After the page's buffer is flushed.
		}
		self::$found[ $path ] = true;
	}

	public static function save() {
		if ( ! self::$found ) {
			return;
		}
		$queue = get_option( self::QUEUE, array() );
		$queue = array_slice( array_unique( array_merge( is_array( $queue ) ? $queue : array(), array_keys( self::$found ) ) ), 0, self::MAX );
		self::$found = array();
		update_option( self::QUEUE, $queue, false );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/** A new upload: queue the original and every size WordPress made. */
	public static function on_upload( $meta, $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( $file && preg_match( '/\.(jpe?g|png)$/i', $file ) ) {
			self::queue( $file );
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
				if ( ! empty( $size['file'] ) ) {
					self::queue( path_join( dirname( $file ), $size['file'] ) );
				}
			}
		}
		return $meta;
	}

	/** A few seconds of conversions; one cache purge if anything was made; back in two minutes if more wait. */
	public static function batch() {
		$queue = get_option( self::QUEUE, array() );
		$queue = is_array( $queue ) ? $queue : array();
		$until = microtime( true ) + 3;
		$made  = 0;
		while ( $queue && microtime( true ) < $until ) {
			$path = array_shift( $queue );
			try {
				$made += Html::make_webp( $path, false ) ? 1 : 0;
			} catch ( \Throwable $e ) {
				continue; // One broken image doesn't stop the rest.
			}
		}
		update_option( self::QUEUE, $queue, false );
		if ( $made ) {
			do_action( 'greenlight_purge_all', $made . ' webp made' );
		}
		if ( $queue ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::HOOK );
		}
		return $made;
	}

	/** Swap an <img>'s src and srcset to WebP copies that exist; queue the ones that don't. */
	public static function swap( \WP_HTML_Tag_Processor $p, $src ) {
		if ( ! preg_match( '/\.(jpe?g|png)(\?|$)/i', $src ) || ! Html::is_upload( $src ) ) {
			return false;
		}
		$new = Html::webp( $src, true );
		if ( $new !== $src ) {
			$p->set_attribute( 'src', $new );
		}
		$set = $p->get_attribute( 'srcset' );
		if ( is_string( $set ) && '' !== $set ) {
			$p->set_attribute( 'srcset', preg_replace_callback( '/(\S+)(\s+\d+(?:\.\d+)?[wx])/', function ( $c ) { return Html::webp( $c[1], true ) . $c[2]; }, $set ) );
		}
		return $new !== $src;
	}
}
