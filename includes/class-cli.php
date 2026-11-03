<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI: wp greenlight status | purge | fonts [--clear] | set <key> <value> | security [--format=json]
 *         | scores [--refresh] [--format=json] | compare [<path>] [--format=json]
 *         | export [--with-key] | import <file> [--dry-run] | digest [--send] [--to=<email>]
 */
class CLI {

	/**
	 * Show settings, host and font cache.
	 */
	public function status() {
		$s = Settings::all();
		\WP_CLI::line( 'Greenlight ' . GREENLIGHT_VERSION . ( $s['enabled'] ? ' — on' : ' — OFF' ) );
		\WP_CLI::line( 'Host page cache: ' . ( Purge::host() ?: 'none detected (Greenlight has no page cache of its own)' ) );
		$fonts = glob( Fonts::dir()['path'] . '*/meta.json' ) ?: array();
		\WP_CLI::line( 'Self-hosted font sets: ' . count( $fonts ) );
		\WP_CLI::line( 'Login page: ' . ( Hide_Login::url() ?: wp_login_url() ) );
		foreach ( $s as $k => $v ) {
			if ( is_int( $v ) ) {
				\WP_CLI::line( sprintf( '  %-20s %s', $k, $v ) );
			}
		}
	}

	/**
	 * Clear the host's page caches (and the object cache).
	 */
	public function purge() {
		\WP_CLI::success( 'Cleared: ' . implode( ', ', Purge::all( 'wp-cli' ) ) );
	}

	/**
	 * Rebuild self-hosted Google Fonts.
	 *
	 * [--clear]
	 * : Delete the local copies first (they rebuild on the next page view).
	 */
	public function fonts( $args, $assoc ) {
		if ( isset( $assoc['clear'] ) ) {
			\WP_CLI::success( 'Removed ' . Fonts::clear() . ' font set(s).' );
			return;
		}
		$n = 0;
		foreach ( glob( Fonts::dir()['path'] . '*/meta.json' ) ?: array() as $f ) {
			$meta = json_decode( (string) file_get_contents( $f ), true );
			if ( ! empty( $meta['href'] ) ) {
				Fonts::build( $meta['href'] );
				$n++;
			}
		}
		\WP_CLI::success( "Rebuilt {$n} font set(s)." );
	}

	/**
	 * Change one setting.
	 *
	 * <key>
	 * : Setting name (see `wp greenlight status`).
	 *
	 * <value>
	 * : New value (1/0 for switches).
	 */
	public function set( $args ) {
		list( $key, $value ) = $args;
		$all = Settings::all();
		if ( ! array_key_exists( $key, $all ) ) {
			\WP_CLI::error( "Unknown setting {$key}." );
		}
		$all[ $key ] = $value;
		update_option( Settings::OPTION, Settings::sanitize( $all ) );
		\WP_CLI::success( "{$key} = " . Settings::get( $key ) );
	}

	/**
	 * Security switches, script baseline, trusted domains and alerts.
	 *
	 * The JSON is read by the QA app: its shape is a contract (see README), so keep it.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * [--reset-baseline]
	 * : Start a new script baseline: the home page's script domains now, then 24 hours of learning.
	 *
	 * [--trust=<domain>]
	 * : Add a domain to the content check's trusted domains.
	 *
	 * [--run-checks]
	 * : Run the core file check and the content check to the end now, instead of a few seconds at a time.
	 *
	 * [--clear-alerts]
	 * : Delete the stored alerts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp greenlight security --format=json
	 *     wp greenlight security --trust=forms.example.com
	 */
	public function security( $args, $assoc ) {
		$json = 'json' === ( $assoc['format'] ?? 'table' );
		$say  = function ( $msg ) use ( $json ) {
			if ( ! $json ) {
				\WP_CLI::success( $msg ); // JSON output stays pure JSON.
			}
		};
		if ( isset( $assoc['reset-baseline'] ) ) {
			$state = Drift::reset();
			$say( 'New script baseline: ' . count( $state['baseline'] ) . ' domain(s) from the home page; learning for 24 hours.' );
		}
		if ( ! empty( $assoc['trust'] ) ) {
			$domain = Content_Check::trust( $assoc['trust'] );
			if ( ! $domain ) {
				\WP_CLI::error( 'Not a domain: ' . $assoc['trust'] );
			}
			$say( "Trusted {$domain}." );
		}
		if ( isset( $assoc['clear-alerts'] ) ) {
			Alerts::clear();
			$say( 'Alerts cleared.' );
		}
		if ( isset( $assoc['run-checks'] ) ) {
			Alerts::run_now();
			$say( 'Checks finished.' );
		}

		$report = Security::report();
		if ( $json ) {
			\WP_CLI::line( wp_json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		\WP_CLI::line( 'Greenlight ' . $report['greenlight_version'] . ' security' );
		foreach ( $report['features'] as $key => $on ) {
			\WP_CLI::line( sprintf( '  %-20s %s', $key, $on ? 'on' : 'off' ) );
		}
		\WP_CLI::line( 'Script baseline: ' . ( implode( ', ', $report['script_baseline'] ) ?: 'empty' ) );
		\WP_CLI::line( 'Trusted embed domains: ' . implode( ', ', $report['trusted_embed_domains'] ) );
		\WP_CLI::line( 'Alerts: ' . count( $report['alerts'] ) );
		if ( $report['alerts'] ) {
			\WP_CLI\Utils\format_items( 'table', $report['alerts'], array( 'time', 'severity', 'type', 'message' ) );
		}
	}

	/**
	 * Real-user Core Web Vitals (CrUX via PageSpeed Insights), as shown on the Overview.
	 *
	 * [--refresh]
	 * : Ask PageSpeed Insights now (mobile and desktop) instead of showing the last daily check.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function scores( $args, $assoc ) {
		if ( isset( $assoc['refresh'] ) ) {
			if ( ! Field_Data::ready() ) {
				\WP_CLI::error( 'Set a PageSpeed Insights API key first: wp greenlight set psi_api_key <key>' );
			}
			Field_Data::fetch( 'mobile' );
			Field_Data::fetch( 'desktop' );
		}
		$f = Field_Data::summary();
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::line( wp_json_encode( $f, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		if ( ! $f['has_key'] ) {
			\WP_CLI::line( 'No PageSpeed Insights API key: wp greenlight set psi_api_key <key>' );
			return;
		}
		\WP_CLI::line( 'Real visitors (CrUX, 75th percentile, 28 days): ' . $f['url'] . ( $f['updated'] ? ', checked ' . $f['updated'] : ', not checked yet' ) );
		if ( $f['error'] ) {
			\WP_CLI::warning( $f['error'] );
		}
		$rows = array();
		foreach ( array( 'mobile', 'desktop' ) as $form ) {
			$d   = $f[ $form ];
			$row = array( 'form' => $form, 'scope' => $d ? $d['scope'] : 'not enough data', 'passes' => $d && null !== $d['passes'] ? ( $d['passes'] ? 'yes' : 'no' ) : '' );
			foreach ( array( 'lcp', 'inp', 'cls', 'fcp', 'ttfb' ) as $key ) {
				$m           = $d['metrics'][ $key ] ?? null;
				$row[ $key ] = $m ? Field_Data::format( $key, $m['p75'] ) . ( 'good' === $m['rating'] ? '' : ' (' . $m['rating'] . ')' ) : '';
			}
			$rows[] = $row;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'form', 'scope', 'passes', 'lcp', 'inp', 'cls', 'fcp', 'ttfb' ) );
	}

	/**
	 * Load a page with Greenlight and with ?greenlight=off, and show what changed.
	 *
	 * [<path>]
	 * : Path on this site. Default: /
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function compare( $args, $assoc ) {
		$result = Report::compare( $args[0] ?? '/' );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::line( wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		\WP_CLI::line( $result['url'] );
		$rows = array();
		foreach ( $result['with'] as $key => $value ) {
			if ( 'greenlight_summary' === $key ) {
				continue;
			}
			$show   = function ( $v ) { return is_array( $v ) ? ( implode( ', ', $v ) ?: '—' ) : ( is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) $v ); };
			$rows[] = array( 'measure' => $key, 'with' => $show( $value ), 'without' => $show( $result['without'][ $key ] ) );
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'measure', 'with', 'without' ) );
		\WP_CLI::line( $result['with']['greenlight_summary'] ?? '' );
	}

	/**
	 * Print this site's settings as a preset (JSON) for `wp greenlight import` on another site.
	 *
	 * [--with-key]
	 * : Include the PageSpeed Insights API key (one key can serve every site).
	 *
	 * ## EXAMPLES
	 *
	 *     wp greenlight export > greenlight-preset.json
	 */
	public function export( $args, $assoc ) {
		\WP_CLI::line( wp_json_encode( Presets::export( isset( $assoc['with-key'] ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Apply a preset made with `wp greenlight export` (or Download preset on the Overview).
	 *
	 * <file>
	 * : The preset file, or - to read it from standard input.
	 *
	 * [--dry-run]
	 * : Show what would change without changing it.
	 */
	public function import( $args, $assoc ) {
		$file = $args[0];
		$json = '-' === $file ? stream_get_contents( STDIN ) : ( is_readable( $file ) ? file_get_contents( $file ) : false );
		if ( false === $json ) {
			\WP_CLI::error( "Can't read {$file}." );
		}
		$dry    = isset( $assoc['dry-run'] );
		$result = Presets::import( json_decode( (string) $json, true ), $dry );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( ! $result ) {
			\WP_CLI::success( 'Nothing to change: this site already has these settings.' );
			return;
		}
		\WP_CLI::success( ( $dry ? 'Would change ' : 'Changed ' ) . count( $result ) . ' setting(s): ' . implode( ', ', $result ) );
	}

	/**
	 * Show (or send) the weekly report email.
	 *
	 * [--send]
	 * : Send it now instead of printing a summary.
	 *
	 * [--to=<email>]
	 * : Send it to this address instead of the configured one.
	 */
	public function digest( $args, $assoc ) {
		if ( isset( $assoc['send'] ) ) {
			$to = $assoc['to'] ?? Digest::recipient();
			Digest::send( $to ) ? \WP_CLI::success( "Report sent to {$to}." ) : \WP_CLI::error( "Couldn't send the report to {$to}." );
			return;
		}
		$d = Digest::data();
		\WP_CLI::line( Digest::build()['subject'] . ' → ' . Digest::recipient() );
		foreach ( $d['scores'] as $form => $s ) {
			$now = $s['now'];
			$row = $now ? ( null === $now['passes'] ? '' : ( $now['passes'] ? 'passes' : 'fails' ) ) : 'not enough data';
			foreach ( array( 'lcp', 'inp', 'cls' ) as $key ) {
				if ( isset( $now['metrics'][ $key ] ) ) {
					$row .= sprintf( ' · %s %s (week ago %s)', strtoupper( $key ), Field_Data::format( $key, $now['metrics'][ $key ]['p75'] ), Field_Data::format( $key, $s['week'][ $key ] ?? null ) );
				}
			}
			\WP_CLI::line( '  ' . $form . ': ' . $row );
		}
		\WP_CLI::line( '  alerts this week: ' . array_sum( $d['counts'] ) . ' (' . $d['counts']['high'] . ' high)' );
	}
}
