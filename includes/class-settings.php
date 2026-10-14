<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Settings with safe defaults. Stored in one option, `greenlight_settings`.
 */
class Settings {

	const OPTION = 'greenlight_settings';

	public static function defaults() {
		return array(
			'enabled'            => 1,

			// Hero / LCP image.
			'lcp'                => 1,
			'lcp_phone_copy'     => 1,
			'lcp_skip'           => "logo\nicon\navatar\ngravatar\nemoji\nspinner\nplaceholder",

			// Lazy-loading.
			'lazy'               => 1,
			'lazy_eager_count'   => 2,
			'lazy_iframes'       => 1,
			'dimensions'         => 1,
			'responsive'         => 1,
			'youtube_facade'     => 1,
			'delay_iframes'      => "google.com/maps\nmaps.google.\nplayer.vimeo.com\nwidget.trustpilot.com\ncalendly.com",
			'lcp_webp'           => 1,

			// Fonts.
			'fonts_local'        => 1,
			'fonts_display'      => 'swap',
			'fonts_preload'      => 1,

			// Third-party scripts.
			'delay'              => 1,
			'delay_timeout'      => 0,
			'delay_patterns'     => implode( "\n", self::default_delay_patterns() ),
			'delay_exclusions'   => implode( "\n", self::default_delay_exclusions() ),
			'defer_head'         => 1,
			'jquery_footer'      => 1,
			'defer_exclusions'   => '',

			// Cleanup.
			'no_emojis'          => 1,
			'no_embeds'          => 1,
			'clean_head'         => 1,
			'no_dashicons'       => 1,
			'heartbeat'          => 1,
			'no_jquery_migrate'  => 0,
			'no_block_css'       => 0,

			// Hosting.
			'purge_on_save'      => 1,
			'preconnect'         => 1,
		);
	}

	/** Script URLs (or parts of them) that are safe to load after the first interaction. */
	public static function default_delay_patterns() {
		return array(
			'googletagmanager.com/gtag/js',
			'googletagmanager.com/gtm.js',
			'google-analytics.com/analytics.js',
			'connect.facebook.net',
			'static.hotjar.com',
			'script.hotjar.com',
			'clarity.ms',
			'snap.licdn.com',
			'analytics.tiktok.com',
			'js.hs-scripts.com',
			'js.hs-analytics.net',
			'js.hs-banner.com',
			'bat.bing.com',
			'embed.tawk.to',
			'widget.intercom.io',
			'js.intercomcdn.com',
			'js.driftt.com',
			'client.crisp.chat',
			'static.zdassets.com',
			'platform.twitter.com',
			'assets.pinterest.com',
			'cdn.userway.org',
			// Inline snippets (matched against the script's text).
			'gtm.start',
			"gtag('config'",
			'gtag("config"',
			"fbq('init'",
			'fbq("init"',
			'_linkedin_partner_id',
			'ttq.load',
			'clarity.ms/tag',
			'hotjar.com/c/hotjar-',
		);
	}

	/**
	 * Never delayed, even when a pattern matches. Consent tools must run at once; call tracking swaps
	 * phone numbers and would miss callers who tap the number before any other interaction.
	 */
	public static function default_delay_exclusions() {
		return array(
			'consent',
			'cookieyes',
			'cookie-law-info',
			'complianz',
			'cmplz',
			'cookiebot',
			'onetrust',
			'termly',
			'iubenda',
			'usercentrics',
			'tctm.co',
			'calltrackingmetrics',
			'callrail',
			'recaptcha',
			'turnstile',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** Non-empty trimmed lines of a textarea setting. */
	public static function lines( $key ) {
		return array_values( array_filter( array_map( 'trim', explode( "\n", (string) self::get( $key ) ) ), 'strlen' ) );
	}

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$out      = array();
		foreach ( $defaults as $key => $default ) {
			if ( is_int( $default ) ) {
				$out[ $key ] = isset( $input[ $key ] ) ? max( 0, (int) $input[ $key ] ) : 0;
			} elseif ( 'fonts_display' === $key ) {
				$out[ $key ] = in_array( $input[ $key ] ?? '', array( 'swap', 'optional', 'fallback' ), true ) ? $input[ $key ] : 'swap';
			} else {
				$out[ $key ] = isset( $input[ $key ] ) ? sanitize_textarea_field( wp_unslash( $input[ $key ] ) ) : $default;
			}
		}
		$out['lazy_eager_count'] = min( 10, $out['lazy_eager_count'] );
		$out['delay_timeout']    = min( 60, $out['delay_timeout'] );
		return $out;
	}
}
