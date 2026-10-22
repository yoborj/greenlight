<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI: wp greenlight status | purge | fonts [--clear] | set <key> <value> | security [--format=json]
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
}
