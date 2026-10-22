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
			'delay_timeout'      => 10, // Also load them after 10 s, so analytics counts visitors who never interact.
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

			// Security: hardening. These key names are part of `wp greenlight security --format=json`.
			'headers'            => 1,
			'hsts'               => 0,
			'hsts_max_age'       => 300, // Start short: browsers remember HSTS for this long.
			'csp_report_only'    => 0,
			'csp_policy'         => "default-src 'self' https: data: blob: 'unsafe-inline' 'unsafe-eval'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'",
			'hide_usernames'     => 1,
			'xmlrpc_off'         => 1,
			'file_editor_off'    => 1,
			'email_obfuscation'  => 1,
			'app_passwords_off'  => 0, // Zapier and other integrations sign in with these.
			'hide_login'         => 0, // Moves the login page: off until someone chooses to.
			'login_slug'         => '',

			// Security: alerts. Off until switched on: they email the site admin address, often the client's.
			'alert_email'        => '',
			'admin_alerts'       => 0,
			'script_drift'       => 0,
			'core_file_check'    => 0,
			'content_check'      => 0,
			'content_trusted'    => implode( "\n", self::default_trusted_domains() ),
			'content_spam_words' => "viagra\ncialis\ncasino\ngambling\nbetting\npoker\npayday loan\nporn\nxxx\nescort\nreplica watches\nessay writing\nbuy followers\ncheap pills",
		);
	}

	/**
	 * Domains the content check trusts in embeds and links: the embeds Greenlight holds back (maps,
	 * Vimeo, YouTube, reviews, booking) plus the forms local-business sites embed.
	 */
	public static function default_trusted_domains() {
		return array(
			'google.com',
			'youtube.com',
			'youtube-nocookie.com',
			'vimeo.com',
			'trustpilot.com',
			'calendly.com',
			'acuityscheduling.com',
			'hsforms.com',
			'hsforms.net',
			'hubspot.com',
			'jotform.com',
			'typeform.com',
			'elfsight.com',
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
	const LISTS = array( 'lcp_skip', 'delay_patterns', 'delay_exclusions', 'defer_exclusions', 'delay_iframes', 'content_trusted', 'content_spam_words' );

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
	/** Known services and the patterns that belong to each, so the settings page shows one row per service. */
	const SERVICES = array(
		'Google Analytics / Ads (gtag.js)' => array( 'googletagmanager.com/gtag/js', "gtag('config'", 'gtag("config"' ),
		'Google Tag Manager'               => array( 'googletagmanager.com/gtm.js', 'gtm.start' ),
		'Universal Analytics (old)'        => array( 'google-analytics.com/analytics.js' ),
		'Meta (Facebook) Pixel'            => array( 'connect.facebook.net', "fbq('init'", 'fbq("init"' ),
		'Hotjar'                           => array( 'static.hotjar.com', 'script.hotjar.com', 'hotjar.com/c/hotjar-' ),
		'Microsoft Clarity'                => array( 'clarity.ms', 'clarity.ms/tag' ),
		'LinkedIn Insight Tag'             => array( 'snap.licdn.com', '_linkedin_partner_id' ),
		'TikTok Pixel'                     => array( 'analytics.tiktok.com', 'ttq.load' ),
		'HubSpot tracking'                 => array( 'js.hs-scripts.com', 'js.hs-analytics.net' ),
		'HubSpot cookie banner'            => array( 'js.hs-banner.com' ),
		'Microsoft Ads (Bing UET)'         => array( 'bat.bing.com' ),
		'Tawk.to chat'                     => array( 'embed.tawk.to' ),
		'Intercom chat'                    => array( 'widget.intercom.io', 'js.intercomcdn.com' ),
		'Drift chat'                       => array( 'js.driftt.com' ),
		'Crisp chat'                       => array( 'client.crisp.chat' ),
		'Zendesk chat'                     => array( 'static.zdassets.com' ),
		'X (Twitter) embeds'               => array( 'platform.twitter.com' ),
		'Pinterest'                        => array( 'assets.pinterest.com' ),
		'UserWay accessibility widget'     => array( 'cdn.userway.org' ),
		'Anything mentioning “consent”'    => array( 'consent' ),
		'CookieYes'                        => array( 'cookieyes', 'cookie-law-info' ),
		'Complianz'                        => array( 'complianz', 'cmplz' ),
		'Cookiebot'                        => array( 'cookiebot' ),
		'OneTrust'                         => array( 'onetrust' ),
		'Termly'                           => array( 'termly' ),
		'iubenda'                          => array( 'iubenda' ),
		'Usercentrics'                     => array( 'usercentrics' ),
		'CallTrackingMetrics'              => array( 'tctm.co', 'calltrackingmetrics' ),
		'CallRail'                         => array( 'callrail' ),
		'Google reCAPTCHA'                 => array( 'recaptcha' ),
		'Cloudflare Turnstile'             => array( 'turnstile' ),
		'Google Maps'                      => array( 'google.com/maps', 'maps.google.' ),
		'Vimeo'                            => array( 'player.vimeo.com', 'vimeo.com' ),
		'Trustpilot'                       => array( 'widget.trustpilot.com', 'trustpilot.com' ),
		'Calendly'                         => array( 'calendly.com' ),
		'Google Maps and Forms'            => array( 'google.com' ),
		'YouTube'                          => array( 'youtube.com', 'youtube-nocookie.com' ),
		'Acuity Scheduling'                => array( 'acuityscheduling.com' ),
		'HubSpot forms'                    => array( 'hsforms.com', 'hsforms.net', 'hubspot.com' ),
		'Jotform'                          => array( 'jotform.com' ),
		'Typeform'                         => array( 'typeform.com' ),
		'Elfsight widgets'                 => array( 'elfsight.com' ),
	);

	/** The service a pattern belongs to, or null for a custom entry. */
	public static function label( $value ) {
		static $names = null;
		if ( null === $names ) {
			$names = array();
			foreach ( self::SERVICES as $name => $patterns ) {
				foreach ( $patterns as $pattern ) {
					$names[ $pattern ] = $name;
				}
			}
		}
		return $names[ $value ] ?? null;
	}

	/**
	 * A list's entries grouped one row per service (custom entries are their own row).
	 * Each group: label, values, indexes (into entries()), on (true if any of its patterns is on).
	 */
	public static function groups( $key ) {
		$groups = array();
		foreach ( self::entries( $key ) as $i => $e ) {
			$label = self::label( $e['value'] );
			$id    = $label ?? ' ' . $e['value'];
			if ( ! isset( $groups[ $id ] ) ) {
				$groups[ $id ] = array( 'label' => $label, 'values' => array(), 'indexes' => array(), 'on' => false );
			}
			$groups[ $id ]['values'][]  = $e['value'];
			$groups[ $id ]['indexes'][] = $i;
			$groups[ $id ]['on']        = $groups[ $id ]['on'] || $e['on'];
		}
		return array_values( $groups );
	}

	/** "3,4,5" and plain indexes from the settings form → ints. */
	private static function indexes( $values ) {
		$out = array();
		foreach ( (array) $values as $v ) {
			foreach ( explode( ',', (string) $v ) as $i ) {
				if ( is_numeric( $i ) ) {
					$out[] = (int) $i;
				}
			}
		}
		return $out;
	}

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$out      = array();
		foreach ( $defaults as $key => $default ) {
			if ( is_int( $default ) ) {
				$out[ $key ] = isset( $input[ $key ] ) ? max( 0, (int) $input[ $key ] ) : 0;
			} elseif ( 'alert_email' === $key ) {
				$out[ $key ] = sanitize_email( wp_unslash( $input[ $key ] ?? '' ) );
			} elseif ( 'login_slug' === $key ) {
				$out[ $key ] = sanitize_title( wp_unslash( $input[ $key ] ?? '' ) );
			} elseif ( 'csp_policy' === $key ) {
				// Sent as a header: one line, no line breaks.
				$out[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ?? '' ) ) ?: $default;
			} elseif ( 'fonts_display' === $key ) {
				$out[ $key ] = in_array( $input[ $key ] ?? '', array( 'swap', 'optional', 'fallback' ), true ) ? $input[ $key ] : 'swap';
			} elseif ( in_array( $key, self::LISTS, true ) && isset( $input[ $key . '__items' ] ) ) {
				// From the settings page: one toggle (and remove box) per entry, plus new entries.
				// A row's switch covers all of a service's patterns: its value is "3,4,5".
				$on    = self::indexes( $input[ $key . '__on' ] ?? array() );
				$del   = self::indexes( $input[ $key . '__del' ] ?? array() );
				$lines = array();
				foreach ( (array) $input[ $key . '__items' ] as $i => $value ) {
					$value = trim( sanitize_text_field( wp_unslash( $value ) ) );
					if ( '' !== $value && ! in_array( (int) $i, $del, true ) && ! isset( $lines[ $value ] ) ) {
						$lines[ $value ] = ( in_array( (int) $i, $on, true ) ? '' : '#' ) . $value;
					}
				}
				foreach ( explode( "\n", sanitize_textarea_field( wp_unslash( $input[ $key . '__add' ] ?? '' ) ) ) as $new ) {
					$new = ltrim( trim( $new ), '#' );
					if ( '' !== $new ) {
						$lines[ $new ] = $new; // Adding an entry that is already there (even switched off) turns it on.
					}
				}
				$out[ $key ] = implode( "\n", $lines );
			} else {
				$out[ $key ] = isset( $input[ $key ] ) ? sanitize_textarea_field( wp_unslash( $input[ $key ] ) ) : $default;
			}
		}
		$out['lazy_eager_count'] = min( 10, $out['lazy_eager_count'] );
		$out['delay_timeout']    = min( 60, $out['delay_timeout'] );
		$out['hsts_max_age']     = min( 63072000, $out['hsts_max_age'] );
		if ( $out['hide_login'] && ! Hide_Login::valid( $out['login_slug'] ) ) {
			// No login address, or one that isn't allowed: pick one rather than leave the switch doing nothing.
			$out['login_slug'] = Hide_Login::suggest();
		}
		return $out;
	}
}
