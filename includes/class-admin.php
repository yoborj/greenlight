<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Greenlight, the "Clear caches" toolbar button, and conflict notices.
 */
class Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'toolbar' ), 100 );
		add_action( 'admin_post_greenlight_purge', array( __CLASS__, 'handle_purge' ) );
		add_action( 'admin_post_greenlight_fonts_clear', array( __CLASS__, 'handle_fonts_clear' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GREENLIGHT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_options_page( 'Greenlight', 'Greenlight', 'manage_options', 'greenlight', array( __CLASS__, 'page' ) );
	}

	public static function register() {
		register_setting( 'greenlight', Settings::OPTION, array( 'sanitize_callback' => array( 'Greenlight\\Settings', 'sanitize' ) ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=greenlight' ) ) . '">Settings</a>' );
		return $links;
	}

	public static function toolbar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bar->add_node( array(
			'id'    => 'greenlight-purge',
			'title' => 'Clear caches',
			'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=greenlight_purge' ), 'greenlight_purge' ),
			'meta'  => array( 'title' => 'Greenlight: clear the host page cache and the object cache' ),
		) );
	}

	public static function handle_purge() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'greenlight_purge' ) ) {
			wp_die( 'Not allowed.' );
		}
		$done = Purge::all( 'toolbar' );
		set_transient( 'greenlight_notice_' . get_current_user_id(), 'Caches cleared: ' . implode( ', ', $done ) . '.', 60 );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	public static function handle_fonts_clear() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'greenlight_fonts_clear' ) ) {
			wp_die( 'Not allowed.' );
		}
		$n = Fonts::clear();
		Purge::all( 'fonts cleared' );
		set_transient( 'greenlight_notice_' . get_current_user_id(), "Removed {$n} self-hosted font set(s). They rebuild on the next page view.", 60 );
		wp_safe_redirect( admin_url( 'options-general.php?page=greenlight' ) );
		exit;
	}

	/** Other plugins that do the same jobs; running two on one job causes double work or breakage. */
	private static function conflicts() {
		$found = array();
		$map   = array(
			'WP Rocket'       => array( 'defined', 'WP_ROCKET_VERSION', 'turn off its Lazyload, Delay JavaScript and Remove Unused CSS, or Greenlight\'s equivalents' ),
			'Autoptimize'     => array( 'defined', 'AUTOPTIMIZE_PLUGIN_VERSION', 'turn off its lazy-load and Google Fonts options' ),
			'Perfmatters'     => array( 'defined', 'PERFMATTERS_VERSION', 'pick one plugin for delay, lazy-load, fonts and cleanup' ),
			'LiteSpeed Cache' => array( 'defined', 'LSCWP_V', 'turn off its image lazy-load, font and JS delay options' ),
			'W3 Total Cache'  => array( 'defined', 'W3TC', 'turn off its lazy-load and minify/defer options' ),
			'SiteGround Speed Optimizer' => array( 'defined', 'SiteGround_Optimizer\\VERSION', 'turn off its lazy-load, font and JS options' ),
			'Flying Scripts'  => array( 'defined', 'FLYING_SCRIPTS_VERSION', 'use one script-delay tool' ),
		);
		foreach ( $map as $name => $c ) {
			if ( defined( $c[1] ) ) {
				$found[ $name ] = $c[2];
			}
		}
		return $found;
	}

	private static function fields() {
		return array(
			'General'           => array(
				'enabled' => array( 'check', 'Greenlight is on', 'Turn off to serve pages untouched (cleanup and cache clearing also stop). Add ?greenlight=off to any URL to compare one page.' ),
			),
			'Hero image (LCP)'  => array(
				'lcp'            => array( 'check', 'Prioritise the hero image', 'Finds the first big image (an <img> or a CSS background) and loads it first: no lazy-loading, fetchpriority="high", preloaded in <head>.' ),
				'lcp_phone_copy' => array( 'check', 'Smaller hero on phones', 'For CSS-background heroes from the media library, phones get the 1536px copy instead of the full-size original.' ),
				'lcp_webp'       => array( 'check', 'WebP copy of the hero', 'Makes a WebP version of the hero image (once, in the background) and serves it instead of the JPEG/PNG. The original file is not changed.' ),
				'lcp_skip'       => array( 'lines', 'Never treat as the hero', 'One per line, matched against the image URL, class, alt and id.' ),
			),
			'Images and embeds' => array(
				'lazy'             => array( 'check', 'Lazy-load images below the fold', 'Uses the browser\'s own loading="lazy". The first images and the hero always load at once.' ),
				'lazy_eager_count' => array( 'number', 'Images that always load at once', 'Counted from the top of the page (logo, hero…). 2 suits most sites.' ),
				'lazy_iframes'     => array( 'check', 'Lazy-load iframes', 'Maps, forms and other embeds load when scrolled near.' ),
				'dimensions'       => array( 'check', 'Add missing width and height', 'From the file name or media library, so the page doesn\'t jump as images load.' ),
				'responsive'       => array( 'check', 'Responsive sizes for theme images', 'Media-library images printed without srcset get WordPress\'s smaller copies, so phones and small slots download small files.' ),
				'youtube_facade'   => array( 'check', 'YouTube: thumbnail until clicked', 'Replaces YouTube iframes with their thumbnail and a play button; the player loads on click.' ),
				'delay_iframes'    => array( 'lines', 'Load these embeds on the first interaction', 'Iframes whose address contains any of these (maps, Vimeo, review and booking widgets) load on the first scroll, tap or mouse move instead of with the page. One per line; empty to turn off.' ),
			),
			'Fonts'             => array(
				'fonts_local'   => array( 'check', 'Self-host Google Fonts', 'Downloads Google Fonts stylesheets and files to this site (in the background) and serves them from here.' ),
				'fonts_display' => array( 'select', 'font-display', 'swap: text shows at once in a fallback font. optional: no layout shift, but a slow first visit may keep the fallback font.', array( 'swap', 'optional', 'fallback' ) ),
				'fonts_preload' => array( 'check', 'Preload the main font files', 'The Latin files of each family are requested before the page draws.' ),
			),
			'Third-party scripts' => array(
				'delay'            => array( 'check', 'Delay third-party scripts until the visitor interacts', 'Analytics, pixels, chat and widgets load on the first scroll, tap, click, key press or mouse move. The theme\'s and plugins\' own scripts are never delayed.' ),
				'delay_timeout'    => array( 'number', 'Also load them after this many seconds (0 = only on interaction)', 'Set e.g. 6 to also count visitors who never touch the page in analytics.' ),
				'delay_patterns'   => array( 'lines', 'Delay scripts that contain', 'One per line, matched against the script URL and inline script text.' ),
				'delay_exclusions' => array( 'lines', 'Never delay scripts that contain', 'Consent tools must run at once. Call tracking is excluded so numbers swap before a visitor taps one.' ),
				'defer_head'       => array( 'check', 'Defer scripts that block the first paint', 'Scripts in <head> load without holding up rendering. WordPress only defers a script when everything that depends on it can wait; jQuery and scripts with inline code are left alone.' ),
				'jquery_footer'    => array( 'check', 'Load jQuery in the footer', 'Moves jQuery and the scripts that need it below the page content. Each page is checked before it is sent: if any code earlier on the page uses jQuery, that page keeps jQuery in <head>.' ),
				'defer_exclusions' => array( 'lines', 'Never defer (script handles or URL parts)', 'One per line, e.g. a script an inline snippet in the theme calls straight away.' ),
			),
			'WordPress cleanup' => array(
				'no_emojis'         => array( 'check', 'Remove the emoji script', 'Browsers show emoji natively.' ),
				'no_embeds'         => array( 'check', 'Remove WordPress embed discovery', 'Stops other WordPress sites embedding this one as a card. YouTube and other embeds still work.' ),
				'clean_head'        => array( 'check', 'Remove header clutter', 'RSD, Windows Live Writer, shortlink and generator tags.' ),
				'no_dashicons'      => array( 'check', 'No Dashicons for visitors', 'The admin icon font is only loaded for logged-in users.' ),
				'heartbeat'         => array( 'check', 'Calmer Heartbeat', 'Off on the front end; once a minute in wp-admin.' ),
				'no_jquery_migrate' => array( 'check', 'Remove jQuery Migrate', 'Only if nothing on the site relies on old jQuery code. Test after turning on.' ),
				'no_block_css'      => array( 'check', 'No block-editor CSS on classic themes', 'Only on pages without blocks, and never on block themes.' ),
			),
			'Hosting'           => array(
				'purge_on_save' => array( 'check', 'Clear host caches when content changes', 'After publishing or updating posts, menus, the Customizer, themes or plugins.' ),
				'preconnect'    => array( 'check', 'Preconnect to third-party servers the first screen needs', 'At most three, from the page\'s <head>.' ),
			),
		);
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = Settings::all();
		$notice = get_transient( 'greenlight_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'greenlight_notice_' . get_current_user_id() );
		}
		$host   = Purge::host();
		$fonts  = glob( Fonts::dir()['path'] . '*/meta.json' ) ?: array();
		?>
		<div class="wrap greenlight">
			<h1>Greenlight</h1>
			<?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
			<?php foreach ( self::conflicts() as $name => $advice ) : ?>
				<div class="notice notice-warning"><p><strong><?php echo esc_html( $name ); ?> is active.</strong> It overlaps with Greenlight: <?php echo esc_html( $advice ); ?>.</p></div>
			<?php endforeach; ?>
			<p>
				<strong>Page cache:</strong> <?php echo $host ? esc_html( $host . ' caches pages at the server; Greenlight clears it when content changes.' ) : 'no host page cache detected. Greenlight has no page cache of its own; use your host\'s or a caching plugin.'; ?>
				&nbsp;·&nbsp; <strong>Self-hosted font sets:</strong> <?php echo (int) count( $fonts ); ?>
				&nbsp;·&nbsp; <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=greenlight_purge' ), 'greenlight_purge' ) ); ?>">Clear caches</a>
				<?php if ( $fonts ) : ?>&nbsp;·&nbsp; <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=greenlight_fonts_clear' ), 'greenlight_fonts_clear' ) ); ?>">Re-download fonts</a><?php endif; ?>
			</p>
			<p class="description">Check a page with and without Greenlight: open it with <code>?greenlight=off</code> added to the URL, and look for the <code>&lt;!-- Greenlight … --&gt;</code> line at the end of the page source.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'greenlight' ); ?>
				<?php foreach ( self::fields() as $section => $fields ) : ?>
					<h2><?php echo esc_html( $section ); ?></h2>
					<table class="form-table" role="presentation">
						<?php foreach ( $fields as $key => $f ) : $name = Settings::OPTION . '[' . $key . ']'; $id = 'greenlight-' . $key; ?>
							<tr>
								<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $f[1] ); ?></label></th>
								<td>
									<?php if ( 'check' === $f[0] ) : ?>
										<label><input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?>> <?php echo esc_html( $f[2] ); ?></label>
									<?php elseif ( 'number' === $f[0] ) : ?>
										<input type="number" min="0" max="60" class="small-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>">
										<p class="description"><?php echo esc_html( $f[2] ); ?></p>
									<?php elseif ( 'select' === $f[0] ) : ?>
										<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"><?php foreach ( $f[3] as $o ) : ?><option value="<?php echo esc_attr( $o ); ?>" <?php selected( $s[ $key ], $o ); ?>><?php echo esc_html( $o ); ?></option><?php endforeach; ?></select>
										<p class="description"><?php echo esc_html( $f[2] ); ?></p>
									<?php else : ?>
										<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="6" class="large-text code"><?php echo esc_textarea( $s[ $key ] ); ?></textarea>
										<p class="description"><?php echo esc_html( $f[2] ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endforeach; ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
