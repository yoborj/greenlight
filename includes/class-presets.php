<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Settings presets: one site's Greenlight settings as a JSON file, applied to another site in one
 * step (Overview → Settings preset, or `wp greenlight export` / `import`). Site-specific values stay
 * out: the page measured for real-user scores, and the PageSpeed API key unless asked for.
 */
class Presets {

	const FORMAT = 1;

	/** Never travel with a preset. */
	const SITE_ONLY = array( 'field_data_url' );

	public static function export( $with_key = false ) {
		$settings = Settings::all();
		foreach ( self::SITE_ONLY as $key ) {
			unset( $settings[ $key ] );
		}
		if ( ! $with_key ) {
			unset( $settings['psi_api_key'] );
		}
		ksort( $settings );
		return array(
			'greenlight_preset'  => self::FORMAT,
			'greenlight_version' => GREENLIGHT_VERSION,
			'exported'           => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'site'               => home_url( '/' ),
			'settings'           => $settings,
		);
	}

	/**
	 * Apply a preset (decoded JSON). Unknown keys are ignored and values go through the usual settings
	 * checks. Returns the keys that changed, or a WP_Error explaining why nothing was applied.
	 */
	public static function import( $preset, $dry_run = false ) {
		if ( ! is_array( $preset ) || ( $preset['greenlight_preset'] ?? null ) !== self::FORMAT || ! isset( $preset['settings'] ) || ! is_array( $preset['settings'] ) ) {
			return new \WP_Error( 'greenlight_preset_invalid', 'That isn\'t a Greenlight preset file (it needs "greenlight_preset": 1 and a "settings" object).' );
		}
		$current  = Settings::all();
		$incoming = array_intersect_key( $preset['settings'], Settings::defaults() );
		foreach ( self::SITE_ONLY as $key ) {
			unset( $incoming[ $key ] );
		}
		$next    = Settings::sanitize( array_merge( $current, $incoming ) );
		$changed = array();
		foreach ( $next as $key => $value ) {
			if ( (string) $value !== (string) ( $current[ $key ] ?? '' ) ) {
				$changed[] = $key;
			}
		}
		if ( $changed && ! $dry_run ) {
			update_option( Settings::OPTION, $next );
		}
		return $changed;
	}
}
