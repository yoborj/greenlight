<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Weekly digest: one email per site with real visitors' Core Web Vitals (now, a week ago and the oldest
 * day kept) and the week's security alerts. Off by default; made for sending to the client. Built from
 * what Greenlight already stores, so it adds one email a week and no requests.
 */
class Digest {

	const HOOK = 'greenlight_weekly_digest';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'send' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$next = wp_next_scheduled( self::HOOK );
		if ( Settings::get( 'weekly_digest' ) && ! $next ) {
			// Monday 8:00 in the site's time zone.
			$when = new \DateTime( 'next monday 08:00', wp_timezone() );
			wp_schedule_event( $when->getTimestamp(), 'weekly', self::HOOK );
		} elseif ( ! Settings::get( 'weekly_digest' ) && $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	public static function recipient() {
		return Settings::get( 'digest_email' ) ?: Alerts::recipient();
	}

	/** The scores and alerts the digest reports, as data (also what `wp greenlight digest` prints). */
	public static function data() {
		$f      = Field_Data::summary();
		$state  = Field_Data::state();
		$week   = gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );
		$scores = array();
		foreach ( array( 'mobile', 'desktop' ) as $form ) {
			$rows = (array) ( $state['history'][ $form ] ?? array() );
			$then = null;
			foreach ( $rows as $row ) {
				if ( $row['date'] <= $week ) {
					$then = $row; // The latest day at least a week old.
				}
			}
			$scores[ $form ] = array(
				'now'   => $f[ $form ],
				'week'  => $then,
				'first' => $f['first'][ $form ] ?? null,
			);
		}
		$since  = time() - 7 * DAY_IN_SECONDS;
		$alerts = array_values( array_filter( Alerts::all(), function ( $a ) use ( $since ) { return strtotime( $a['time'] ) >= $since; } ) );
		$counts = array( 'high' => 0, 'medium' => 0, 'low' => 0 );
		foreach ( $alerts as $a ) {
			$counts[ $a['severity'] ] = ( $counts[ $a['severity'] ] ?? 0 ) + 1;
		}
		$core = Core_Check::state();
		return array(
			'site'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'      => home_url( '/' ),
			'enabled'  => (bool) Settings::get( 'enabled' ),
			'scores'   => $scores,
			'has_key'  => $f['has_key'],
			'alerts'   => array_slice( $alerts, 0, 6 ),
			'counts'   => $counts,
			'core'     => Settings::get( 'core_file_check' ) && $core['finished'] ? $core['finished'] : null,
			'overview' => admin_url( 'admin.php?page=greenlight' ),
		);
	}

	/** Subject and HTML body. Inline styles only: email clients ignore stylesheets. */
	public static function build() {
		$d     = self::data();
		$green = '#16a34a';
		$rate  = array( 'good' => $green, 'needs-improvement' => '#d97706', 'poor' => '#dc2626' );
		$name  = array( 'lcp' => 'Loading (LCP)', 'inp' => 'Responsiveness (INP)', 'cls' => 'Visual stability (CLS)' );
		$cell  = 'padding:8px 10px;border-bottom:1px solid #e5ece8;font-size:14px;';
		$html  = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;color:#15241b;max-width:640px;margin:0 auto;">';
		$html .= '<div style="background:#0d2a1b;color:#fff;border-radius:14px;padding:20px 24px;"><div style="font-size:20px;font-weight:700;">' . esc_html( $d['site'] ) . '</div>';
		$html .= '<div style="color:#bfe9cf;font-size:14px;">Weekly site report · ' . esc_html( wp_date( get_option( 'date_format' ) ) ) . ' · ' . esc_html( preg_replace( '#^https?://#', '', untrailingslashit( $d['url'] ) ) ) . '</div></div>';

		$html .= '<h2 style="font-size:16px;margin:24px 0 8px;">Real visitors</h2>';
		if ( ! $d['has_key'] ) {
			$html .= '<p style="color:#56675d;font-size:14px;">Real-user scores aren\'t set up on this site yet.</p>';
		} else {
			foreach ( array( 'mobile' => 'Mobile', 'desktop' => 'Desktop' ) as $form => $title ) {
				$s   = $d['scores'][ $form ];
				$now = $s['now'];
				$html .= '<p style="margin:14px 0 6px;font-size:14px;"><strong>' . $title . '</strong> ';
				if ( ! $now ) {
					$html .= '<span style="color:#56675d;">not enough Chrome visitors yet</span></p>';
					continue;
				}
				$html .= null === $now['passes'] ? '' : ( $now['passes'] ? '<span style="color:' . $green . ';font-weight:600;">passes Core Web Vitals</span>' : '<span style="color:#d97706;font-weight:600;">doesn\'t pass Core Web Vitals yet</span>' );
				$html .= '</p><table style="border-collapse:collapse;width:100%;"><tr><th align="left" style="' . $cell . 'color:#56675d;font-weight:600;">Metric</th><th align="right" style="' . $cell . 'color:#56675d;font-weight:600;">Now</th><th align="right" style="' . $cell . 'color:#56675d;font-weight:600;">A week ago</th><th align="right" style="' . $cell . 'color:#56675d;font-weight:600;">' . ( $s['first'] ? esc_html( mysql2date( 'M j', $s['first']['date'] ) ) : 'First' ) . '</th></tr>';
				foreach ( $name as $key => $label ) {
					$m = $now['metrics'][ $key ] ?? null;
					if ( ! $m ) {
						continue;
					}
					$html .= '<tr><td style="' . $cell . '">' . $label . '</td><td align="right" style="' . $cell . 'font-weight:700;color:' . ( $rate[ $m['rating'] ] ?? '#15241b' ) . ';">' . esc_html( Field_Data::format( $key, $m['p75'] ) ) . '</td>'
						. '<td align="right" style="' . $cell . '">' . esc_html( Field_Data::format( $key, $s['week'][ $key ] ?? null ) ) . '</td>'
						. '<td align="right" style="' . $cell . '">' . esc_html( Field_Data::format( $key, $s['first'][ $key ] ?? null ) ) . '</td></tr>';
				}
				$html .= '</table>';
			}
			$html .= '<p style="color:#56675d;font-size:12px;">From Chrome users\' visits (Chrome UX Report): the 75th percentile of the last 28 days. Lower is better.</p>';
		}

		$html .= '<h2 style="font-size:16px;margin:24px 0 8px;">Security</h2>';
		$total = array_sum( $d['counts'] );
		if ( ! $total ) {
			$html .= '<p style="font-size:14px;">No security alerts this week.</p>';
		} else {
			$html .= '<p style="font-size:14px;">' . (int) $total . ' alert' . ( 1 === $total ? '' : 's' ) . ' this week: ' . (int) $d['counts']['high'] . ' high, ' . (int) $d['counts']['medium'] . ' medium, ' . (int) $d['counts']['low'] . ' low.</p><ul style="padding-left:18px;font-size:14px;">';
			foreach ( $d['alerts'] as $a ) {
				$html .= '<li style="margin:4px 0;"><strong style="text-transform:uppercase;font-size:11px;color:' . ( 'high' === $a['severity'] ? '#dc2626' : ( 'medium' === $a['severity'] ? '#d97706' : '#56675d' ) ) . ';">' . esc_html( $a['severity'] ) . '</strong> ' . esc_html( $a['message'] ) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( $d['core'] ) {
			$html .= '<p style="font-size:14px;color:#56675d;">WordPress core files last checked ' . esc_html( human_time_diff( $d['core'] ) ) . ' ago.</p>';
		}
		$html .= '<p style="font-size:12px;color:#8a9890;margin-top:28px;">Sent weekly by Greenlight' . ( $d['enabled'] ? '' : ' (speed optimizations are paused)' ) . ' · <a href="' . esc_url( $d['overview'] ) . '" style="color:' . $green . ';">Details in wp-admin</a></p></div>';

		return array( 'subject' => sprintf( '[%s] Weekly site report', $d['site'] ), 'html' => $html );
	}

	public static function send( $to = '' ) {
		$mail = self::build();
		return wp_mail( $to ?: self::recipient(), $mail['subject'], $mail['html'], array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
