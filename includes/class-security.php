<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Hardening that WP Engine leaves to the site: security headers, hidden usernames, XML-RPC, the file
 * editor, email addresses in pages, application passwords. Firewall, backups and malware scanning are
 * the host's job. Unlike the speed features, these ignore the master switch and ?greenlight=off.
 */
class Security {

	/** The feature switches, in the order `wp greenlight security --format=json` lists them. */
	const FEATURES = array( 'headers', 'hsts', 'csp_report_only', 'hide_usernames', 'xmlrpc_off', 'file_editor_off', 'email_obfuscation', 'app_passwords_off', 'admin_alerts', 'script_drift', 'core_file_check', 'content_check' );

	/** DISALLOW_FILE_EDIT was already set (in wp-config.php) before Greenlight could. */
	private static $file_edit_preset = false;

	public static function init() {
		$s = Settings::all();

		if ( defined( 'DISALLOW_FILE_EDIT' ) ) {
			self::$file_edit_preset = true;
		} elseif ( $s['file_editor_off'] ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		if ( $s['xmlrpc_off'] ) {
			if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
				// Stop before the rest of WordPress loads: password-guessing bots hammer this file.
				status_header( 403 );
				header( 'Content-Type: text/plain; charset=utf-8' );
				echo 'XML-RPC is turned off on this site.';
				exit;
			}
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
			add_filter( 'wp_headers', function ( $headers ) { unset( $headers['X-Pingback'] ); return $headers; } );
			add_action( 'admin_notices', array( __CLASS__, 'jetpack_notice' ) );
		}

		if ( $s['app_passwords_off'] ) {
			add_filter( 'wp_is_application_passwords_available', '__return_false' );
		}

		if ( $s['hide_usernames'] ) {
			add_action( 'template_redirect', array( __CLASS__, 'author_redirect' ), 0 ); // Before redirect_canonical() turns ?author=1 into /author/admin/.
			add_filter( 'rest_endpoints', array( __CLASS__, 'rest_endpoints' ) );
			add_filter( 'wp_sitemaps_add_provider', function ( $provider, $name ) { return 'users' === $name ? false : $provider; }, 10, 2 );
			add_filter( 'oembed_response_data', array( __CLASS__, 'oembed_data' ) );
		}

		if ( $s['headers'] || $s['hsts'] || $s['csp_report_only'] ) {
			add_action( 'template_redirect', array( __CLASS__, 'send_headers' ), 1 );
		}
	}

	public static function file_edit_preset() {
		return self::$file_edit_preset;
	}

	/** Front-end HTML pages only, the scope the speed features use. Headers already set (by the host or another plugin) win. */
	public static function send_headers() {
		if ( headers_sent() || ! Optimizer::in_scope() ) {
			return;
		}
		try {
			$s   = Settings::all();
			$set = array();
			if ( $s['headers'] ) {
				$set['X-Content-Type-Options'] = 'nosniff';
				$set['Referrer-Policy']        = 'strict-origin-when-cross-origin';
				$set['X-Frame-Options']        = 'SAMEORIGIN';
				$set['Permissions-Policy']     = 'camera=(), microphone=(), geolocation=()';
			}
			if ( $s['hsts'] && is_ssl() ) {
				$set['Strict-Transport-Security'] = 'max-age=' . (int) $s['hsts_max_age'];
			}
			if ( $s['csp_report_only'] ) {
				// Report-only, never enforced: Greenlight's inline delayed-script loader has no nonce and would be blocked.
				$set['Content-Security-Policy-Report-Only'] = trim( str_replace( array( "\r", "\n" ), ' ', (string) $s['csp_policy'] ) );
			}
			$sent = array();
			foreach ( headers_list() as $h ) {
				$sent[ strtolower( trim( strtok( $h, ':' ) ) ) ] = true;
			}
			foreach ( $set as $name => $value ) {
				if ( '' !== $value && ! isset( $sent[ strtolower( $name ) ] ) ) {
					header( $name . ': ' . $value );
				}
			}
		} catch ( \Throwable $e ) {
			return; // The page goes out without them.
		}
	}

	/** ?author=1 redirects to /author/<username>/ for anyone who asks; send logged-out visitors home instead. */
	public static function author_redirect() {
		if ( ! is_user_logged_in() && isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/** No /wp/v2/users for logged-out visitors. The block editor is logged in, so it keeps its author lists. */
	public static function rest_endpoints( $endpoints ) {
		if ( ! is_user_logged_in() ) {
			foreach ( array_keys( $endpoints ) as $route ) {
				if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
					unset( $endpoints[ $route ] );
				}
			}
		}
		return $endpoints;
	}

	/** oEmbed responses name the author and link to /author/<username>/. */
	public static function oembed_data( $data ) {
		if ( ! is_user_logged_in() && is_array( $data ) ) {
			unset( $data['author_name'], $data['author_url'] );
		}
		return $data;
	}

	/** The overlapping-plugin warning for Jetpack, on every admin screen: Jetpack breaks without XML-RPC. */
	public static function jetpack_notice() {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$conflicts = Admin::conflicts();
		if ( empty( $conflicts['Jetpack'] ) || ! current_user_can( 'manage_options' ) || ( $screen && 'toplevel_page_greenlight' === $screen->id ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>Greenlight · Jetpack:</strong> %s. <a href="%s">Security settings</a></p></div>',
			esc_html( ucfirst( $conflicts['Jetpack'] ) ),
			esc_url( admin_url( 'admin.php?page=greenlight&tab=security' ) )
		);
	}

	/**
	 * Encode email addresses with antispambot(): in the page's text and in href="mailto:…". Script and
	 * style contents, comments and every other attribute are left exactly as they are.
	 */
	public static function obfuscate_emails( $html, array &$report ) {
		$email = '(?<![\w.%+@-])[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}(?![\w@-])';
		$count = 0;
		$out   = preg_replace_callback(
			'#<!--.*?-->|<(script|style|textarea|noscript|template)\b[^>]*>.*?</\1\s*>|<[^>]*>|' . $email . '#is',
			function ( $m ) use ( &$count, $email ) {
				$t = $m[0];
				if ( '<' !== $t[0] ) {
					$count++;
					return antispambot( $t );
				}
				if ( ! preg_match( '/^<a\s/i', $t ) ) {
					return $t;
				}
				return preg_replace_callback(
					'#(\shref\s*=\s*["\']?\s*mailto:)(' . $email . ')#i',
					function ( $h ) use ( &$count ) {
						$count++;
						return $h[1] . antispambot( $h[2] );
					},
					$t
				);
			},
			$html
		);
		if ( null === $out ) {
			return $html; // A PCRE limit on a huge page: leave it.
		}
		if ( $count ) {
			$report['emails'] = $count;
		}
		return $out;
	}

	/**
	 * Everything `wp greenlight security --format=json` prints. The QA app reads this: keep the shape
	 * (and bump "schema" if it ever has to change).
	 */
	public static function report() {
		$s        = Settings::all();
		$features = array();
		foreach ( self::FEATURES as $key ) {
			$features[ $key ] = ! empty( $s[ $key ] );
		}
		return array(
			'greenlight_version'    => GREENLIGHT_VERSION,
			'schema'                => 1,
			'features'              => $features,
			'script_baseline'       => array_values( Drift::baseline() ),
			'trusted_embed_domains' => array_values( Content_Check::embed_domains() ),
			'alerts'                => Alerts::export(),
		);
	}

	/* Domains. Hosts are compared lowercase without "www.", and a domain covers its subdomains. */

	public static function bare_host( $host ) {
		$host = strtolower( trim( (string) $host, ". \t\n\r" ) );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	public static function own_host() {
		return self::bare_host( wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	public static function in_domains( $host, array $domains ) {
		foreach ( $domains as $d ) {
			if ( '' !== $d && ( $host === $d || substr( $host, -strlen( $d ) - 1 ) === '.' . $d ) ) {
				return true;
			}
		}
		return false;
	}

	public static function is_own( $host ) {
		return self::in_domains( $host, array( self::own_host() ) );
	}

	/** Host of a URL: this site's host for relative URLs, null for data:, javascript:, mailto: and the like. */
	public static function url_host( $url ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES ) );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			return $host ? self::bare_host( $host ) : null;
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			return null;
		}
		return self::own_host();
	}

	/** Host of a URL on another site, or null. */
	public static function external_host( $url ) {
		$host = self::url_host( $url );
		return ( $host && ! self::is_own( $host ) ) ? $host : null;
	}

	/** "https://www.Example.com/path" or "*.example.com" → "example.com"; '' if it isn't a domain. */
	public static function domain( $entry ) {
		$entry = preg_replace( '#^[a-z]+://#i', '', trim( (string) $entry ) );
		$entry = preg_replace( '#[/:?\#].*$#', '', ltrim( $entry, '*.' ) );
		if ( '.' === substr( $entry, -1 ) ) {
			return ''; // A partial pattern such as "maps.google.", not a domain.
		}
		$entry = self::bare_host( $entry );
		return preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $entry ) ? $entry : '';
	}
}
