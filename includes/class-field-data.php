<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Real-user Core Web Vitals: the Chrome UX Report (CrUX) field data from the PageSpeed Insights API,
 * fetched once a day by WP-Cron (mobile, then desktop two minutes later, one request per run).
 * Page views never read or write any of it. Google needs an API key for this; without one,
 * nothing is requested.
 */
class Field_Data {

	const OPTION  = 'greenlight_field_data';
	const DAILY   = 'greenlight_field_data';
	const DESKTOP = 'greenlight_field_data_desktop';
	const KEEP    = 120; // Days of history per form factor.
	const API     = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/** CrUX metric → short name. CLS comes as a percentile × 100. */
	const METRICS = array(
		'LARGEST_CONTENTFUL_PAINT_MS'     => 'lcp',
		'INTERACTION_TO_NEXT_PAINT'       => 'inp',
		'CUMULATIVE_LAYOUT_SHIFT_SCORE'   => 'cls',
		'FIRST_CONTENTFUL_PAINT_MS'       => 'fcp',
		'EXPERIMENTAL_TIME_TO_FIRST_BYTE' => 'ttfb',
	);

	const RATING = array( 'FAST' => 'good', 'AVERAGE' => 'needs-improvement', 'SLOW' => 'poor' );

	public static function init() {
		add_action( self::DAILY, array( __CLASS__, 'run_mobile' ), 10, 1 );
		add_action( self::DESKTOP, array( __CLASS__, 'run_desktop' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/** On while switched on and a key is set. Checked in wp-admin, cron and WP-CLI only. */
	public static function schedule() {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$next = wp_next_scheduled( self::DAILY );
		if ( self::ready() && ! $next ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::DAILY );
		} elseif ( ! self::ready() && $next ) {
			wp_unschedule_hook( self::DAILY );
			wp_unschedule_hook( self::DESKTOP );
		}
	}

	public static function ready() {
		return Settings::get( 'field_data' ) && '' !== trim( (string) Settings::get( 'psi_api_key' ) );
	}

	/** The page measured: the setting, or the home page (a staging site can point at production). */
	public static function url() {
		$url = trim( (string) Settings::get( 'field_data_url' ) );
		return $url ? $url : home_url( '/' );
	}

	public static function state() {
		$state = get_option( self::OPTION );
		return ( is_array( $state ) ? $state : array() ) + array(
			'url'     => '',
			'updated' => 0,
			'error'   => '',
			'mobile'  => null,
			'desktop' => null,
			'history' => array( 'mobile' => array(), 'desktop' => array() ),
		);
	}

	/** $now: "Check now" on the Overview, a one-off run beside the daily one. */
	public static function run_mobile( $now = '' ) {
		self::fetch( 'mobile' );
		if ( ! wp_next_scheduled( self::DESKTOP ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::DESKTOP );
		}
	}

	public static function run_desktop() {
		self::fetch( 'desktop' );
	}

	/** One PageSpeed Insights request, asking only for the field data (no Lighthouse report comes back). */
	public static function fetch( $form ) {
		if ( ! self::ready() ) {
			return false;
		}
		$state = self::state();
		$url   = self::url();
		$api   = add_query_arg(
			array(
				'url'      => rawurlencode( $url ),
				'strategy' => $form,
				'category' => 'performance',
				'fields'   => 'analysisUTCTimestamp,loadingExperience,originLoadingExperience',
				'key'      => rawurlencode( trim( (string) Settings::get( 'psi_api_key' ) ) ),
			),
			self::API
		);
		$response = wp_remote_get( $api, array( 'timeout' => 60 ) );
		$body     = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_wp_error( $response ) || ! is_array( $body ) || isset( $body['error'] ) ) {
			$state['error'] = 'PageSpeed Insights: ' . ( is_wp_error( $response ) ? $response->get_error_message() : ( $body['error']['message'] ?? 'unreadable answer (HTTP ' . wp_remote_retrieve_response_code( $response ) . ')' ) );
			update_option( self::OPTION, $state, false );
			return false;
		}
		$result = self::parse( $body );
		$state['url']     = $url;
		$state['updated'] = time();
		$state['error']   = '';
		$state[ $form ]   = $result;
		if ( $result ) {
			$day  = gmdate( 'Y-m-d' );
			$rows = array_values( array_filter( (array) ( $state['history'][ $form ] ?? array() ), function ( $r ) use ( $day ) { return $r['date'] !== $day; } ) );
			$rows[] = array( 'date' => $day, 'scope' => $result['scope'], 'lcp' => $result['metrics']['lcp']['p75'] ?? null, 'inp' => $result['metrics']['inp']['p75'] ?? null, 'cls' => $result['metrics']['cls']['p75'] ?? null );
			$state['history'][ $form ] = array_slice( $rows, -self::KEEP );
		}
		update_option( self::OPTION, $state, false );
		return true;
	}

	/**
	 * The page's own field data when CrUX has enough of it, otherwise the whole site's (origin).
	 * Null when Chrome users haven't visited enough for either: common on small local-business sites.
	 */
	public static function parse( array $body ) {
		$page   = $body['loadingExperience'] ?? array();
		$origin = $body['originLoadingExperience'] ?? array();
		if ( ! empty( $page['metrics'] ) && empty( $page['origin_fallback'] ) ) {
			$use   = $page;
			$scope = 'page';
		} elseif ( ! empty( $origin['metrics'] ) ) {
			$use   = $origin;
			$scope = 'origin';
		} else {
			return null;
		}
		$metrics = array();
		foreach ( self::METRICS as $key => $short ) {
			if ( isset( $use['metrics'][ $key ]['percentile'] ) ) {
				$p75               = $use['metrics'][ $key ]['percentile'];
				$metrics[ $short ] = array(
					'p75'    => 'cls' === $short ? round( $p75 / 100, 2 ) : (int) $p75,
					'rating' => self::RATING[ $use['metrics'][ $key ]['category'] ?? '' ] ?? null,
				);
			}
		}
		// Core Web Vitals pass when LCP, INP and CLS are all good at the 75th percentile (INP may be missing).
		$passes = null;
		if ( isset( $metrics['lcp'], $metrics['cls'] ) ) {
			$passes = 'good' === $metrics['lcp']['rating'] && 'good' === $metrics['cls']['rating'] && ( ! isset( $metrics['inp'] ) || 'good' === $metrics['inp']['rating'] );
		}
		return array( 'scope' => $scope, 'passes' => $passes, 'metrics' => $metrics );
	}

	/** For the Overview, `wp greenlight scores` and the status ability. */
	public static function summary() {
		$state = self::state();
		$out   = array(
			'enabled' => (bool) Settings::get( 'field_data' ),
			'has_key' => '' !== trim( (string) Settings::get( 'psi_api_key' ) ),
			'url'     => $state['url'] ?: self::url(),
			'updated' => $state['updated'] ? gmdate( 'Y-m-d\TH:i:s\Z', $state['updated'] ) : null,
			'error'   => $state['error'] ?: null,
			'mobile'  => $state['mobile'],
			'desktop' => $state['desktop'],
			'first'   => array(),
		);
		foreach ( array( 'mobile', 'desktop' ) as $form ) {
			$rows = (array) ( $state['history'][ $form ] ?? array() );
			if ( count( $rows ) > 1 ) {
				$out['first'][ $form ] = $rows[0]; // The oldest day kept: the "before" for "since Greenlight".
			}
		}
		return $out;
	}

	/** "2.1 s", "180 ms", "0.05". */
	public static function format( $metric, $value ) {
		if ( null === $value ) {
			return '—';
		}
		if ( 'cls' === $metric ) {
			return number_format( (float) $value, 2 );
		}
		return $value >= 1000 ? number_format( $value / 1000, 1 ) . ' s' : (int) $value . ' ms';
	}
}
