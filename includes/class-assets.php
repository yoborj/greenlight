<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin files only where they're used. Form, feed and slider plugins load their CSS and JS on every
 * page; on a page whose finished HTML has none of the plugin's markup, those files are left out.
 * Decided per page from the HTML itself, so a form in a footer widget or a page builder still counts.
 * Only each plugin's own handles go: never shared ones like jQuery or wp-i18n.
 */
class Assets {

	/** key => [ markers (any one means the page uses it), handles (wp_enqueue_* names) ]. */
	const RULES = array(
		'contact-form-7'    => array( array( 'wpcf7-form', 'class="wpcf7' ), array( 'contact-form-7', 'contact-form-7-rtl', 'swv', 'wpcf7-recaptcha', 'google-recaptcha' ) ),
		'fluent-forms'      => array( array( 'fluentform' ), array( 'fluent-form-styles', 'fluentform-public-default', 'fluent-form-submission', 'fluentform-advanced' ) ),
		'formidable'        => array( array( 'frm_forms', 'frm-show-form' ), array( 'formidable', 'formidable-js' ) ),
		'mailchimp-for-wp'  => array( array( 'mc4wp-form' ), array( 'mc4wp-form-basic', 'mc4wp-form-themes', 'mc4wp-forms-api' ) ),
		'smash-balloon-ig'  => array( array( 'sb_instagram', 'sbi_item' ), array( 'sbi_styles', 'sbi_scripts' ) ),
		'slider-revolution' => array( array( 'rev_slider', '<rs-module' ), array( 'rs-plugin-settings', 'tp-tools', 'revmin', 'sr7css', 'sr7', 'sr7page' ) ),
	);

	public static function default_rules() {
		return array_keys( self::RULES );
	}

	/**
	 * Built-in rules by key, or a custom line "handle-a, handle-b | marker" (the plugin's handles, then
	 * a piece of markup only pages using it have).
	 */
	private static function rule( $entry ) {
		if ( isset( self::RULES[ $entry ] ) ) {
			return self::RULES[ $entry ];
		}
		if ( false === strpos( $entry, '|' ) ) {
			return null;
		}
		list( $handles, $marker ) = array_map( 'trim', explode( '|', $entry, 2 ) );
		$handles                  = array_filter( array_map( 'trim', explode( ',', $handles ) ) );
		return ( $handles && '' !== $marker ) ? array( array( $marker ), $handles ) : null;
	}

	public static function apply( $html, array &$report ) {
		$skipped = 0;
		foreach ( Settings::lines( 'asset_rules' ) as $entry ) {
			$rule = self::rule( $entry );
			if ( ! $rule ) {
				continue;
			}
			list( $markers, $handles ) = $rule;
			$without = $html;
			$removed = 0;
			foreach ( $handles as $handle ) {
				$h       = preg_quote( $handle, '#' );
				$without = preg_replace(
					array(
						'#<link\b[^>]*\bid=(["\'])' . $h . '-css\1[^>]*>\s*#i',
						'#<style\b[^>]*\bid=(["\'])' . $h . '-inline-css\1[^>]*>.*?</style>\s*#is',
						'#<script\b[^>]*\bid=(["\'])' . $h . '-js(?:-extra|-before|-after|-translations)?\1[^>]*>.*?</script>\s*#is',
					),
					'',
					$without,
					-1,
					$n
				);
				if ( null === $without ) {
					break; // A PCRE limit on a huge page: leave this plugin's files alone.
				}
				$removed += (int) $n;
			}
			// The plugin's own files can mention its markup (config, translations): look only at the rest.
			if ( $removed && null !== $without && ! Html::matches_any( $without, $markers ) ) {
				$html     = $without;
				$skipped += $removed;
			}
		}
		if ( $skipped ) {
			$report['plugin files skipped'] = $skipped;
		}
		return $html;
	}
}
