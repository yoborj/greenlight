<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Limit login attempts: after a number of failed logins from one address within 15 minutes, that
 * address can't log in for 15 minutes; each further lockout that day doubles it, up to 24 hours.
 * Counts live in transients and are written only on a failed login, so normal traffic costs nothing.
 *
 * The visitor's address comes from REMOTE_ADDR, except behind Cloudflare (its published ranges, which
 * then pass CF-Connecting-IP) or a private-network proxy (which passes X-Forwarded-For). Anything else
 * could be faked by the visitor, so it is ignored. `greenlight_client_ip` overrides for other setups.
 */
class Login_Guard {

	const WINDOW = 15 * MINUTE_IN_SECONDS;
	const MAX    = DAY_IN_SECONDS;

	/** Cloudflare's published edge ranges (cloudflare.com/ips). */
	const CLOUDFLARE = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
		'190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	);

	public static function init() {
		if ( ! Settings::get( 'login_limit' ) ) {
			return;
		}
		add_filter( 'authenticate', array( __CLASS__, 'block' ), 30, 3 );
		add_action( 'wp_login_failed', array( __CLASS__, 'failed' ) );
		add_action( 'wp_login', array( __CLASS__, 'succeeded' ) );
	}

	public static function ip() {
		$remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ip     = $remote;
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::in_ranges( $remote, self::CLOUDFLARE ) ) {
			$ip = trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && filter_var( $remote, FILTER_VALIDATE_IP ) && ! filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			// A proxy on the private network: the first public address it reports is the visitor.
			foreach ( explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) as $hop ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$hop = trim( $hop );
				if ( filter_var( $hop, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					$ip = $hop;
					break;
				}
			}
		}
		$ip = (string) apply_filters( 'greenlight_client_ip', $ip, $remote );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** Whether an IPv4 or IPv6 address is in any of the CIDR ranges. */
	public static function in_ranges( $ip, array $ranges ) {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) {
			return false;
		}
		foreach ( $ranges as $range ) {
			list( $net, $bits ) = explode( '/', $range );
			$netbin             = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $netbin || strlen( $netbin ) !== strlen( $bin ) ) {
				continue;
			}
			$bits  = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			$rest  = $bits % 8;
			if ( substr( $bin, 0, $bytes ) !== substr( $netbin, 0, $bytes ) ) {
				continue;
			}
			if ( ! $rest || ( ord( $bin[ $bytes ] ) >> ( 8 - $rest ) ) === ( ord( $netbin[ $bytes ] ) >> ( 8 - $rest ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function key( $ip ) {
		return 'greenlight_login_' . substr( md5( $ip ), 0, 16 );
	}

	/** { fails, first, locked_until, lockouts, day } */
	private static function record( $ip ) {
		$r = get_transient( self::key( $ip ) );
		return ( is_array( $r ) ? $r : array() ) + array( 'fails' => 0, 'first' => 0, 'locked_until' => 0, 'lockouts' => 0, 'day' => 0 );
	}

	public static function locked_for( $ip ) {
		$r = self::record( $ip );
		return max( 0, $r['locked_until'] - time() );
	}

	/** Before the password is even checked: a locked address gets the same answer whatever it sends. */
	public static function block( $user, $username, $password ) {
		$ip   = self::ip();
		$wait = $ip ? self::locked_for( $ip ) : 0;
		if ( ! $wait ) {
			return $user;
		}
		remove_action( 'wp_login_failed', array( __CLASS__, 'failed' ) ); // A blocked try doesn't extend the lockout.
		return new \WP_Error( 'greenlight_locked', sprintf( '<strong>Error:</strong> Too many failed logins. Try again in %s.', human_time_diff( time(), time() + $wait ) ) );
	}

	public static function failed() {
		$ip = self::ip();
		if ( ! $ip ) {
			return;
		}
		$r   = self::record( $ip );
		$now = time();
		if ( $r['first'] < $now - self::WINDOW ) {
			$r['fails'] = 0;
			$r['first'] = $now;
		}
		if ( $r['day'] < $now - DAY_IN_SECONDS ) {
			$r['lockouts'] = 0;
			$r['day']      = $now;
		}
		$r['fails']++;
		if ( $r['fails'] >= max( 3, (int) Settings::get( 'login_limit_attempts' ) ) ) {
			$r['lockouts']++;
			$r['locked_until'] = $now + min( self::MAX, self::WINDOW * ( 2 ** ( $r['lockouts'] - 1 ) ) );
			$r['fails']        = 0;
		}
		set_transient( self::key( $ip ), $r, DAY_IN_SECONDS );
	}

	public static function succeeded() {
		$ip = self::ip();
		if ( $ip ) {
			delete_transient( self::key( $ip ) );
		}
	}
}
