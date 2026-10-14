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
			'lcp_desktop_copy'   => 1,
			'lcp_skip'           => "logo\nicon\navatar\ngravatar\nemoji\nspinner\nplaceholder",

			// Lazy-loading.
			'lazy'               => 1,
			'lazy_eager_count'   => 2,
			'lazy_iframes'       => 1,
			'dimensions'         => 1,
			'responsive'         => 1,
			'youtube_facade'     => 1,
			'embeds_delay'       => 1,
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
			'js.hs-banner.com',
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

	/** List settings whose entries can be switched on and off one by one. */
	const LISTS = array( 'lcp_skip', 'delay_patterns', 'delay_exclusions', 'defer_exclusions', 'delay_iframes' );

	/** Active entries of a list setting (one per line; a line starting with "#" is switched off). */
	public static function lines( $key ) {
		$out = array();
		foreach ( self::entries( $key ) as $e ) {
			if ( $e['on'] ) {
				$out[] = $e['value'];
			}
		}
		return $out;
	}

	/** Every entry of a list setting: [ ['value' => …, 'on' => bool], … ]. */
	public static function entries( $key ) {
		$out = array();
		foreach ( explode( "\n", (string) self::get( $key ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line ) {
				continue;
			}
			$off   = 0 === strpos( $line, '#' );
			$out[] = array( 'value' => trim( $off ? substr( $line, 1 ) : $line ), 'on' => ! $off );
		}
		return $out;
	}

	/** A readable name for a list entry, when it's one we know. */
	public static function label( $value ) {
		static $names = array(
			'googletagmanager.com/gtag/js' => 'Google Analytics 4 / Google Ads (gtag.js)',
			'googletagmanager.com/gtm.js'  => 'Google Tag Manager',
			'google-analytics.com/analytics.js' => 'Universal Analytics (old)',
			'connect.facebook.net'         => 'Meta (Facebook) Pixel',
			'static.hotjar.com'            => 'Hotjar',
			'script.hotjar.com'            => 'Hotjar (loader)',
			'clarity.ms'                   => 'Microsoft Clarity',
			'snap.licdn.com'               => 'LinkedIn Insight Tag',
			'analytics.tiktok.com'         => 'TikTok Pixel',
			'js.hs-scripts.com'            => 'HubSpot tracking',
			'js.hs-analytics.net'          => 'HubSpot analytics',
			'js.hs-banner.com'             => 'HubSpot cookie banner',
			'bat.bing.com'                 => 'Microsoft Ads (Bing UET)',
			'embed.tawk.to'                => 'Tawk.to chat',
			'widget.intercom.io'           => 'Intercom chat',
			'js.intercomcdn.com'           => 'Intercom chat (files)',
			'js.driftt.com'                => 'Drift chat',
			'client.crisp.chat'            => 'Crisp chat',
			'static.zdassets.com'          => 'Zendesk chat',
			'platform.twitter.com'         => 'X (Twitter) embeds',
			'assets.pinterest.com'         => 'Pinterest',
			'cdn.userway.org'              => 'UserWay accessibility widget',
			'gtm.start'                    => 'Google Tag Manager (inline snippet)',
			"gtag('config'"                => 'Google Analytics setup (inline)',
			'gtag("config"'                => 'Google Analytics setup (inline)',
			"fbq('init'"                   => 'Meta Pixel setup (inline)',
			'fbq("init"'                   => 'Meta Pixel setup (inline)',
			'_linkedin_partner_id'         => 'LinkedIn setup (inline)',
			'ttq.load'                     => 'TikTok setup (inline)',
			'clarity.ms/tag'               => 'Microsoft Clarity setup (inline)',
			'hotjar.com/c/hotjar-'         => 'Hotjar setup (inline)',
			'consent'                      => 'Anything that mentions "consent"',
			'cookieyes'                    => 'CookieYes',
			'cookie-law-info'              => 'CookieYes (GDPR Cookie Consent)',
			'complianz'                    => 'Complianz',
			'cmplz'                        => 'Complianz (scripts)',
			'cookiebot'                    => 'Cookiebot',
			'onetrust'                     => 'OneTrust',
			'termly'                       => 'Termly',
			'iubenda'                      => 'iubenda',
			'usercentrics'                 => 'Usercentrics',
			'tctm.co'                      => 'CallTrackingMetrics',
			'calltrackingmetrics'          => 'CallTrackingMetrics',
			'callrail'                     => 'CallRail',
			'recaptcha'                    => 'Google reCAPTCHA',
			'turnstile'                    => 'Cloudflare Turnstile',
			'google.com/maps'              => 'Google Maps',
			'maps.google.'                 => 'Google Maps (old embed)',
			'player.vimeo.com'             => 'Vimeo',
			'widget.trustpilot.com'        => 'Trustpilot',
			'calendly.com'                 => 'Calendly',
		);
		return $names[ $value ] ?? null;
	}

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$out      = array();
		foreach ( $defaults as $key => $default ) {
			if ( is_int( $default ) ) {
				$out[ $key ] = isset( $input[ $key ] ) ? max( 0, (int) $input[ $key ] ) : 0;
			} elseif ( 'fonts_display' === $key ) {
				$out[ $key ] = in_array( $input[ $key ] ?? '', array( 'swap', 'optional', 'fallback' ), true ) ? $input[ $key ] : 'swap';
			} elseif ( in_array( $key, self::LISTS, true ) && isset( $input[ $key . '__items' ] ) ) {
				// From the settings page: one toggle (and remove box) per entry, plus new entries.
				$on    = array_map( 'intval', (array) ( $input[ $key . '__on' ] ?? array() ) );
				$del   = array_map( 'intval', (array) ( $input[ $key . '__del' ] ?? array() ) );
				$lines = array();
				foreach ( (array) $input[ $key . '__items' ] as $i => $value ) {
					$value = trim( sanitize_text_field( wp_unslash( $value ) ) );
					if ( '' !== $value && ! in_array( (int) $i, $del, true ) ) {
						$lines[] = ( in_array( (int) $i, $on, true ) ? '' : '#' ) . $value;
					}
				}
				foreach ( explode( "\n", sanitize_textarea_field( wp_unslash( $input[ $key . '__add' ] ?? '' ) ) ) as $new ) {
					$new = trim( $new );
					if ( '' !== $new ) {
						$lines[] = ltrim( $new, '#' );
					}
				}
				$out[ $key ] = implode( "\n", array_unique( $lines ) );
			} else {
				$out[ $key ] = isset( $input[ $key ] ) ? sanitize_textarea_field( wp_unslash( $input[ $key ] ) ) : $default;
			}
		}
		$out['lazy_eager_count'] = min( 10, $out['lazy_eager_count'] );
		$out['delay_timeout']    = min( 60, $out['delay_timeout'] );
		return $out;
	}
}
