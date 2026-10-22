<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Script drift: the external domains this site's pages load scripts from. A baseline is learned
 * (the home page right away, then every logged-out page view for a day); after that, each new
 * domain raises one alert. Scripts Greenlight knows (the delay and never-delay lists) are named
 * in the alert and rated medium; unknown ones are high.
 */
class Drift {

	const OPTION = 'greenlight_scripts';
	const LEARN  = DAY_IN_SECONDS;

	/** Domains this request saw that aren't in the baseline yet: [ host => script URL ]. */
	private static $pending = array();

	/** { baseline: [hosts], alerted: [hosts], learning_until: timestamp } or null before the first look. */
	public static function state() {
		$state = get_option( self::OPTION );
		return is_array( $state ) ? $state + array( 'baseline' => array(), 'alerted' => array(), 'learning_until' => 0 ) : null;
	}

	public static function baseline() {
		$state = self::state();
		return $state ? $state['baseline'] : array();
	}

	/** External script hosts in a page, each with its first script URL. Delayed scripts count too. */
	public static function scripts( $html ) {
		$out = array();
		if ( preg_match_all( '#<script\b[^>]*?\s(?:data-greenlight-)?src\s*=\s*(["\']?)([^"\'\s>]+)\1#i', $html, $m ) ) {
			foreach ( $m[2] as $src ) {
				$host = Security::external_host( $src );
				if ( $host && ! isset( $out[ $host ] ) ) {
					$out[ $host ] = html_entity_decode( $src, ENT_QUOTES );
				}
			}
		}
		return $out;
	}

	/** Runs inside the page's output buffer: compare only, write nothing (save() does that at shutdown). */
	public static function observe( $html ) {
		$state         = self::state();
		$known         = $state ? array_flip( array_merge( $state['baseline'], $state['alerted'] ) ) : array();
		self::$pending = array_diff_key( self::scripts( $html ), $known );
	}

	public static function save() {
		$found         = self::$pending;
		self::$pending = array();
		if ( ! $found ) {
			return;
		}
		try {
			$state = self::state() ?: self::fresh();
			if ( $state['learning_until'] > time() ) {
				$state['baseline'] = self::merge( $state['baseline'], array_keys( $found ) );
				update_option( self::OPTION, $state, true );
				return;
			}
			$home    = wp_parse_url( home_url() );
			$path    = strtok( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$url     = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $path;
			$post_id = is_singular() ? get_queried_object_id() : null;
			$state['alerted'] = self::merge( $state['alerted'], array_keys( $found ) );
			update_option( self::OPTION, $state, true ); // Before alerting: once per domain, even if mail is slow.
			foreach ( $found as $host => $src ) {
				$service = self::service( $src );
				if ( $service ) {
					Alerts::add( 'script_drift', 'medium', sprintf( 'New script domain %s (%s) on %s.', $host, $service, $path ), $url, $post_id, array( 'domain' => $host ) );
				} else {
					Alerts::add( 'script_drift', 'high', sprintf( 'New script domain %s on %s, not a service Greenlight knows. Check which plugin, theme or tag added it.', $host, $path ), $url, $post_id, array( 'domain' => $host ) );
				}
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** The service a script belongs to, from the Scripts tab's lists (switched-off entries included). */
	public static function service( $src ) {
		$patterns = array_merge( wp_list_pluck( Settings::entries( 'delay_patterns' ), 'value' ), wp_list_pluck( Settings::entries( 'delay_exclusions' ), 'value' ) );
		$pattern  = Html::matches_any( $src, $patterns );
		return $pattern ? ( Settings::label( $pattern ) ?: $pattern ) : null;
	}

	/** Start a new baseline: the home page's script domains now, then a day of learning from real page views. */
	public static function reset() {
		$state = self::fresh();
		$page  = wp_remote_get( home_url( '/' ), array( 'timeout' => 10, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ) );
		if ( ! is_wp_error( $page ) ) {
			$state['baseline'] = self::merge( array(), array_keys( self::scripts( (string) wp_remote_retrieve_body( $page ) ) ) );
		}
		update_option( self::OPTION, $state, true );
		return $state;
	}

	private static function fresh() {
		return array( 'baseline' => array(), 'alerted' => array(), 'learning_until' => time() + self::LEARN );
	}

	private static function merge( array $a, array $b ) {
		$all = array_values( array_unique( array_merge( $a, $b ) ) );
		sort( $all );
		return $all;
	}
}
