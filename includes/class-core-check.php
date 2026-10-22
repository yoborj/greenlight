<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Daily core file check, the same logic as `wp core verify-checksums`: every core file against the
 * WordPress.org checksums for this version (wp-content is skipped), plus files in wp-admin, wp-includes
 * or a root wp-* file that core doesn't ship. Also flags PHP files under wp-content/uploads.
 *
 * Runs in batches of a few seconds. The checksums are downloaded once per WordPress version.
 */
class Core_Check {

	const STATE    = 'greenlight_core_check';
	const PER_STEP = 500; // Files hashed per batch, at most.
	const PHP      = '/\.(php\d?|phtml|phar)$/i';

	public static function state() {
		$state = get_option( self::STATE );
		return ( is_array( $state ) ? $state : array() ) + array(
			'phase'    => 'idle', // files → extra → uploads → idle
			'offset'   => 0,
			'queue'    => array(),
			'found'    => self::empty_found(),
			'started'  => 0,
			'finished' => 0,
			'last'     => null, // Counts from the last finished check.
			'alerted'  => array(),
			'error'    => '',
		);
	}

	private static function empty_found() {
		return array( 'modified' => array(), 'missing' => array(), 'extra' => array(), 'uploads' => array() );
	}

	private static function save( array $state ) {
		update_option( self::STATE, $state, false );
	}

	/** Begin a check, unless one started in the last day is still going. */
	public static function start( $force = false ) {
		$state = self::state();
		if ( ! $force && 'idle' !== $state['phase'] && $state['started'] > time() - DAY_IN_SECONDS ) {
			return;
		}
		$state = array_merge( $state, array( 'phase' => 'files', 'offset' => 0, 'queue' => array(), 'found' => self::empty_found(), 'started' => time(), 'error' => '' ) );
		self::save( $state );
	}

	/** WordPress.org checksums for this version and locale, without wp-content. Cached for a month. */
	private static function checksums() {
		global $wp_version, $wp_local_package;
		$locale = empty( $wp_local_package ) ? 'en_US' : $wp_local_package;
		$key    = 'greenlight_core_sums_' . md5( $wp_version . $locale );
		$sums   = get_transient( $key );
		if ( is_array( $sums ) ) {
			return $sums;
		}
		require_once ABSPATH . 'wp-admin/includes/update.php';
		$sums = get_core_checksums( $wp_version, $locale );
		if ( ! is_array( $sums ) && 'en_US' !== $locale ) {
			$sums = get_core_checksums( $wp_version, 'en_US' );
		}
		if ( ! is_array( $sums ) || ! $sums ) {
			return null;
		}
		$sums = array_filter( $sums, function ( $file ) { return 0 !== strpos( $file, 'wp-content/' ); }, ARRAY_FILTER_USE_KEY );
		ksort( $sums );
		set_transient( $key, $sums, MONTH_IN_SECONDS );
		return $sums;
	}

	/** Work until $until. Returns true when there is more to do. */
	public static function step( $until ) {
		$state = self::state();
		if ( 'idle' === $state['phase'] ) {
			return false;
		}

		if ( 'files' === $state['phase'] ) {
			$sums = self::checksums();
			if ( ! $sums ) {
				$state['phase'] = 'idle';
				$state['error'] = 'Couldn\'t get the checksums from WordPress.org. Trying again tomorrow.';
				self::save( $state );
				return false;
			}
			$files = array_keys( $sums );
			$total = count( $files );
			$stop  = min( $total, $state['offset'] + self::PER_STEP );
			for ( ; $state['offset'] < $stop && microtime( true ) < $until; $state['offset']++ ) {
				$file = $files[ $state['offset'] ];
				if ( ! file_exists( ABSPATH . $file ) ) {
					$state['found']['missing'][] = $file;
				} elseif ( md5_file( ABSPATH . $file ) !== $sums[ $file ] ) {
					$state['found']['modified'][] = $file;
				}
			}
			if ( $state['offset'] >= $total ) {
				$state['phase'] = 'extra';
			}
			self::save( $state );
			return true;
		}

		if ( 'extra' === $state['phase'] ) {
			$sums = self::checksums() ?: array();
			foreach ( self::core_files() as $file ) {
				if ( ! isset( $sums[ $file ] ) ) {
					$state['found']['extra'][] = $file;
				}
			}
			$state['phase'] = 'uploads';
			$state['queue'] = array( untrailingslashit( wp_get_upload_dir()['basedir'] ) );
			self::save( $state );
			return true;
		}

		// Uploads: one folder at a time, so a big media library spreads over several runs.
		$dirs = 0;
		while ( $state['queue'] && $dirs < 200 && microtime( true ) < $until ) {
			$dir = array_shift( $state['queue'] );
			$dirs++;
			foreach ( (array) @scandir( $dir ) as $name ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( '' === (string) $name || '.' === $name || '..' === $name ) {
					continue;
				}
				$path = $dir . '/' . $name;
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					$state['queue'][] = $path;
				} elseif ( preg_match( self::PHP, $name ) && ! self::is_stub( $path ) ) {
					$state['found']['uploads'][] = self::relative( $path );
				}
			}
		}
		if ( $state['queue'] ) {
			self::save( $state );
			return true;
		}
		self::finish( $state );
		return false;
	}

	/** Files under wp-admin, wp-includes and root wp-* files (not wp-config.php), as verify-checksums lists them. */
	private static function core_files() {
		$out = array();
		foreach ( array( 'wp-admin', WPINC ) as $dir ) {
			if ( ! is_dir( ABSPATH . $dir ) ) {
				continue;
			}
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( ABSPATH . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( $f->isFile() ) {
					$out[] = $dir . '/' . str_replace( '\\', '/', substr( $f->getPathname(), strlen( ABSPATH . $dir ) + 1 ) );
				}
			}
		}
		foreach ( glob( ABSPATH . 'wp-*' ) ?: array() as $f ) {
			if ( is_file( $f ) && 'wp-config.php' !== basename( $f ) ) {
				$out[] = basename( $f );
			}
		}
		return $out;
	}

	/** "<?php // Silence is golden." placeholders that plugins drop into upload folders. */
	private static function is_stub( $path ) {
		if ( filesize( $path ) > 200 ) {
			return false;
		}
		$code = (string) file_get_contents( $path );
		return (bool) preg_match( '#^\s*<\?php\s*(?:(?://|\#)[^\n]*\s*|/\*.*?\*/\s*)*(?:\?>)?\s*$#s', $code );
	}

	private static function relative( $path ) {
		$root = wp_normalize_path( ABSPATH );
		$path = wp_normalize_path( $path );
		return 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
	}

	/** Alert on what is new since the last check (a changed file counts as new again). */
	private static function finish( array $state ) {
		$now = array();
		foreach ( $state['found'] as $kind => $files ) {
			foreach ( $files as $file ) {
				$now[ $kind . ':' . $file ] = 'missing' === $kind ? '' : (string) @md5_file( ABSPATH . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		$new = array();
		foreach ( $now as $key => $hash ) {
			if ( ! isset( $state['alerted'][ $key ] ) || $state['alerted'][ $key ] !== $hash ) {
				list( $kind, $file ) = explode( ':', $key, 2 );
				$new[ $kind ][]      = $file;
			}
		}
		$changed = array_merge( $new['modified'] ?? array(), $new['extra'] ?? array() );
		if ( $changed ) {
			Alerts::add( 'core_files', 'high', sprintf( '%d WordPress core file(s) changed or added (not as on WordPress.org): %s', count( $changed ), self::list_files( $changed ) ) );
		}
		if ( ! empty( $new['uploads'] ) ) {
			Alerts::add( 'core_files', 'high', sprintf( '%d PHP file(s) in uploads, where only media belongs: %s', count( $new['uploads'] ), self::list_files( $new['uploads'] ) ) );
		}
		if ( ! empty( $new['missing'] ) ) {
			Alerts::add( 'core_files', 'low', sprintf( '%d WordPress core file(s) missing: %s', count( $new['missing'] ), self::list_files( $new['missing'] ) ) );
		}
		$state['alerted']  = $now;
		$state['phase']    = 'idle';
		$state['queue']    = array();
		$state['finished'] = time();
		$state['last']     = array_map( 'count', $state['found'] );
		self::save( $state );
	}

	private static function list_files( array $files ) {
		$shown = array_slice( $files, 0, 8 );
		return implode( ', ', $shown ) . ( count( $files ) > 8 ? sprintf( ' and %d more', count( $files ) - 8 ) : '' ) . '.';
	}
}
