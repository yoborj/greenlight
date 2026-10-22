<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Security alerts: stored in one option (newest first, last 200), emailed once per request when
 * medium or high. Also watches admin changes, and runs the daily checks in small batches: a few
 * seconds per WP-Cron run, a couple of minutes apart, so WP Engine never sees a heavy cron job.
 */
class Alerts {

	const OPTION = 'greenlight_alerts';
	const KEEP   = 200;
	const DAILY  = 'greenlight_security_daily';
	const BATCH  = 'greenlight_security_batch';

	/** Alerts to email at the end of this request. */
	private static $mail = array();

	public static function init() {
		add_action( self::DAILY, array( __CLASS__, 'daily' ) );
		add_action( self::BATCH, array( __CLASS__, 'batch' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, function ( $name, $value ) { Alerts::settings_changed( array(), $value ); }, 10, 2 );
		if ( Settings::get( 'admin_alerts' ) ) {
			add_action( 'user_register', array( __CLASS__, 'on_user_register' ) );
			add_action( 'set_user_role', array( __CLASS__, 'on_set_user_role' ), 10, 3 );
			add_action( 'add_user_role', array( __CLASS__, 'on_add_user_role' ), 10, 2 );
			add_action( 'activated_plugin', array( __CLASS__, 'on_activated_plugin' ) );
			add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader' ), 10, 2 );
			add_action( 'switch_theme', array( __CLASS__, 'on_switch_theme' ), 10, 3 );
		}
	}

	public static function all() {
		$alerts = get_option( self::OPTION, array() );
		return is_array( $alerts ) ? $alerts : array();
	}

	/**
	 * Store an alert (and queue its email). $extra holds fields for the settings page only, e.g. the
	 * domains a "Trust" button offers; export() leaves them out.
	 */
	public static function add( $type, $severity, $message, $url = null, $post_id = null, array $extra = array() ) {
		$alert = array(
			'id'       => substr( md5( uniqid( '', true ) . $message ), 0, 12 ),
			'type'     => $type,
			'severity' => $severity,
			'time'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'message'  => $message,
			'url'      => $url ? (string) $url : null,
			'post_id'  => $post_id ? (int) $post_id : null,
		) + $extra;
		$alerts = self::all();
		array_unshift( $alerts, $alert );
		update_option( self::OPTION, array_slice( $alerts, 0, self::KEEP ), false );
		if ( 'low' !== $severity ) {
			self::$mail[] = $alert;
			if ( 1 === count( self::$mail ) ) {
				add_action( 'shutdown', array( __CLASS__, 'send' ), 99 ); // Also runs when added during shutdown (script drift saves at priority 2).
			}
		}
		return $alert;
	}

	/** Exactly the alert fields of the JSON contract, nothing else. */
	public static function export() {
		$out = array();
		foreach ( self::all() as $a ) {
			$out[] = array(
				'id'       => (string) $a['id'],
				'type'     => (string) $a['type'],
				'severity' => (string) $a['severity'],
				'time'     => (string) $a['time'],
				'message'  => (string) $a['message'],
				'url'      => isset( $a['url'] ) && '' !== $a['url'] ? (string) $a['url'] : null,
				'post_id'  => isset( $a['post_id'] ) ? (int) $a['post_id'] : null,
			);
		}
		return $out;
	}

	public static function clear() {
		delete_option( self::OPTION );
	}

	public static function recipient() {
		return Settings::get( 'alert_email' ) ?: get_option( 'admin_email' );
	}

	/** One email for everything this request found. */
	public static function send() {
		$alerts     = self::$mail;
		self::$mail = array();
		if ( ! $alerts ) {
			return;
		}
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = 1 === count( $alerts )
			? sprintf( '[%s] Security alert: %s', $site, wp_html_excerpt( $alerts[0]['message'], 80, '…' ) )
			: sprintf( '[%s] %d security alerts', $site, count( $alerts ) );
		$body = '';
		foreach ( $alerts as $a ) {
			$body .= strtoupper( $a['severity'] ) . ': ' . $a['message'] . "\n" . ( $a['url'] ? $a['url'] . "\n" : '' ) . "\n";
		}
		$body .= 'All alerts: ' . admin_url( 'admin.php?page=greenlight&tab=security' ) . "\n— Greenlight on " . home_url( '/' ) . "\n";
		wp_mail( self::recipient(), $subject, $body );
	}

	/* Admin changes. */

	private static function who() {
		$user = wp_get_current_user();
		if ( $user && $user->exists() ) {
			return '“' . $user->user_login . '”';
		}
		return ( defined( 'WP_CLI' ) && WP_CLI ) ? 'WP-CLI' : 'no logged-in user';
	}

	/** Users who became administrators this request, and who among them was registered this request. */
	private static $admins     = array();
	private static $registered = array();

	/**
	 * One alert per user and request, however many role hooks fire. Decided at shutdown: user_register
	 * fires after the role is set, so only then is it clear whether the account is new.
	 */
	private static function new_admin( $user_id ) {
		if ( ! self::$admins ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_admins' ), 5 );
		}
		self::$admins[ $user_id ] = self::who(); // Who did it, while they are still the current user.
	}

	public static function flush_admins() {
		foreach ( self::$admins as $user_id => $who ) {
			$user = get_userdata( $user_id );
			if ( ! $user || ! in_array( 'administrator', (array) $user->roles, true ) ) {
				continue;
			}
			$message = isset( self::$registered[ $user_id ] )
				? sprintf( 'New administrator “%s” (%s) created by %s.', $user->user_login, $user->user_email, $who )
				: sprintf( '“%s” (%s) was made an administrator by %s.', $user->user_login, $user->user_email, $who );
			self::add( 'admin_change', 'high', $message, admin_url( 'user-edit.php?user_id=' . (int) $user_id ) );
		}
		self::$admins = array();
	}

	public static function on_user_register( $user_id ) {
		self::$registered[ $user_id ] = true;
		$user = get_userdata( $user_id );
		if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
			self::new_admin( $user_id );
		}
	}

	public static function on_set_user_role( $user_id, $role, $old_roles ) {
		if ( 'administrator' === $role && ! in_array( 'administrator', (array) $old_roles, true ) ) {
			self::new_admin( $user_id );
		}
	}

	public static function on_add_user_role( $user_id, $role ) {
		if ( 'administrator' === $role ) {
			self::new_admin( $user_id );
		}
	}

	public static function on_activated_plugin( $plugin ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = WP_PLUGIN_DIR . '/' . $plugin;
		$name = is_readable( $file ) ? get_plugin_data( $file, false, false )['Name'] : '';
		self::add( 'admin_change', 'medium', sprintf( 'Plugin activated: %s by %s.', $name ?: $plugin, self::who() ), admin_url( 'plugins.php' ) );
	}

	/** New plugins and themes only; updates are routine. */
	public static function on_upgrader( $upgrader, $extra ) {
		$type = $extra['type'] ?? '';
		if ( 'install' !== ( $extra['action'] ?? '' ) || ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return;
		}
		$data = 'plugin' === $type ? ( $upgrader->new_plugin_data ?? array() ) : ( $upgrader->new_theme_data ?? array() );
		$name = $data['Name'] ?? ( $upgrader->result['destination_name'] ?? 'unknown' );
		self::add( 'admin_change', 'medium', sprintf( '%s installed: %s by %s.', ucfirst( $type ), $name, self::who() ), admin_url( 'plugin' === $type ? 'plugins.php' : 'themes.php' ) );
	}

	public static function on_switch_theme( $new_name, $new_theme = null, $old_theme = null ) {
		$old = $old_theme instanceof \WP_Theme ? $old_theme->get( 'Name' ) : '?';
		self::add( 'admin_change', 'medium', sprintf( 'Theme switched from %s to %s by %s.', $old, $new_name, self::who() ), admin_url( 'themes.php' ) );
	}

	/* Scheduling. */

	/** The daily event exists only while a daily check is on. Checked in wp-admin, cron and WP-CLI, not on visitor pages. */
	public static function schedule() {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$want = Settings::get( 'core_file_check' ) || Settings::get( 'content_check' );
		$next = wp_next_scheduled( self::DAILY );
		if ( $want && ! $next ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'daily', self::DAILY );
		} elseif ( ! $want && $next ) {
			wp_clear_scheduled_hook( self::DAILY );
		}
	}

	public static function daily() {
		if ( Settings::get( 'core_file_check' ) ) {
			Core_Check::start();
		}
		if ( Settings::get( 'content_check' ) ) {
			Content_Check::start_scan();
		}
		self::batch();
	}

	/** A few seconds of work; if there is more, come back in two minutes. */
	public static function batch() {
		$until = microtime( true ) + 3;
		$more  = false;
		try {
			if ( Settings::get( 'content_check' ) ) {
				$more = Content_Check::step( $until ) || $more;
			}
			if ( Settings::get( 'core_file_check' ) ) {
				$more = Core_Check::step( $until ) || $more;
			}
		} catch ( \Throwable $e ) {
			$more = false; // Try again tomorrow rather than in a loop.
		}
		if ( $more ) {
			self::soon( 2 * MINUTE_IN_SECONDS );
		}
	}

	public static function soon( $delay = 30 ) {
		if ( ! wp_next_scheduled( self::BATCH ) ) {
			wp_schedule_single_event( time() + $delay, self::BATCH );
		}
	}

	/** WP-CLI: start both checks and run them to the end now. */
	public static function run_now() {
		if ( Settings::get( 'core_file_check' ) ) {
			Core_Check::start( true );
		}
		if ( Settings::get( 'content_check' ) ) {
			Content_Check::start_scan();
		}
		do {
			$until = microtime( true ) + 30;
			$more  = ( Settings::get( 'content_check' ) && Content_Check::step( $until ) ) | ( Settings::get( 'core_file_check' ) && Core_Check::step( $until ) );
		} while ( $more );
	}

	/** Switching a check on starts its baseline. */
	public static function settings_changed( $old, $new ) {
		$was = wp_parse_args( is_array( $old ) ? $old : array(), Settings::defaults() );
		if ( ! empty( $new['content_check'] ) && empty( $was['content_check'] ) ) {
			Content_Check::start_baseline();
		}
		if ( ! empty( $new['script_drift'] ) && empty( $was['script_drift'] ) ) {
			Drift::reset();
		}
	}
}
