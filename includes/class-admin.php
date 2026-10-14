<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * The Greenlight admin menu page, the "Clear caches" toolbar button, and conflict notices.
 */
class Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'toolbar' ), 100 );
		add_action( 'admin_post_greenlight_purge', array( __CLASS__, 'handle_purge' ) );
		add_action( 'admin_post_greenlight_fonts_clear', array( __CLASS__, 'handle_fonts_clear' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GREENLIGHT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		// Its own menu item, just below Settings. The icon is a traffic light; WordPress recolours it to match the admin theme.
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="M10 1a4 4 0 0 1 4 4v10a4 4 0 0 1-8 0V5a4 4 0 0 1 4-4zm0 2.6a1.9 1.9 0 1 0 0 3.8 1.9 1.9 0 0 0 0-3.8zm0 4.5a1.9 1.9 0 1 0 0 3.8 1.9 1.9 0 0 0 0-3.8zM10 13a2.1 2.1 0 1 0 0 4.2A2.1 2.1 0 0 0 10 13z"/></svg>' );
		add_menu_page( 'Greenlight', 'Greenlight', 'manage_options', 'greenlight', array( __CLASS__, 'page' ), $icon, 81 );
	}

	public static function register() {
		register_setting( 'greenlight', Settings::OPTION, array( 'sanitize_callback' => array( 'Greenlight\\Settings', 'sanitize' ) ) );
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_greenlight' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'greenlight-admin', GREENLIGHT_URL . 'assets/admin.css', array(), GREENLIGHT_VERSION );
		wp_enqueue_script( 'greenlight-admin', GREENLIGHT_URL . 'assets/admin.js', array(), GREENLIGHT_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=greenlight' ) ) . '">Settings</a>' );
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
		wp_safe_redirect( admin_url( 'admin.php?page=greenlight' ) );
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


	/**
	 * The settings, one tab per area. Field: [ type, label, description, extras ].
	 * Extras: requires (the switch it works under), badge, min/max/unit (number), options (choice).
	 */
	private static function tabs() {
		return array(
			'hero'    => array(
				'title'   => 'Hero image',
				'icon'    => 'hero',
				'intro'   => 'Shows the biggest first-screen image first.',
				'metrics' => array( 'LCP' ),
				'fields'  => array(
					'lcp'              => array( 'check', 'Prioritise the hero image', 'Loaded first, never lazy, preloaded.' ),
					'lcp_phone_copy'   => array( 'check', 'Smaller hero on phones', 'Phones get the 1536px copy.', array( 'requires' => 'lcp' ) ),
					'lcp_desktop_copy' => array( 'check', 'Cap the hero at 2048px', 'Uses the 2048px copy of bigger originals.', array( 'requires' => 'lcp' ) ),
					'lcp_webp'         => array( 'check', 'WebP copy of the hero', 'Serves a lighter WebP. The original stays.', array( 'requires' => 'lcp' ) ),
					'lcp_skip'         => array( 'lines', 'Never treat as the hero', 'Logos, icons and badges to skip.', array( 'requires' => 'lcp' ) ),
				),
			),
			'images'  => array(
				'title'   => 'Images & embeds',
				'icon'    => 'images',
				'intro'   => 'Off-screen images and embeds wait. Nothing jumps.',
				'metrics' => array( 'LCP', 'CLS', 'SI' ),
				'fields'  => array(
					'lazy'             => array( 'check', 'Lazy-load images below the fold', 'Images load as they scroll into view.' ),
					'lazy_eager_count' => array( 'number', 'Always load the first', 'From the top of the page. 2 suits most sites.', array( 'requires' => 'lazy', 'min' => 0, 'max' => 10, 'unit' => 'images' ) ),
					'dimensions'       => array( 'check', 'Add missing image sizes', 'Stops the page jumping as images load.' ),
					'responsive'       => array( 'check', 'Responsive theme images', 'Smaller files for phones.' ),
					'lazy_iframes'     => array( 'check', 'Lazy-load iframes', 'Embeds load when scrolled near.' ),
					'youtube_facade'   => array( 'check', 'YouTube: thumbnail until clicked', 'The player loads on click.' ),
					'embeds_delay'     => array( 'check', 'Delay heavy embeds', 'Maps, Vimeo, reviews and booking wait for the first scroll or tap.' ),
					'delay_iframes'    => array( 'lines', 'Embeds to hold back', 'Switch off to load with the page.', array( 'requires' => 'embeds_delay' ) ),
				),
			),
			'fonts'   => array(
				'title'   => 'Fonts',
				'icon'    => 'fonts',
				'intro'   => 'Google Fonts served from this site.',
				'metrics' => array( 'FCP', 'CLS' ),
				'fields'  => array(
					'fonts_local'   => array( 'check', 'Self-host Google Fonts', 'No second server for fonts.' ),
					'fonts_preload' => array( 'check', 'Preload the main font files', 'Main font files load before the page draws.', array( 'requires' => 'fonts_local' ) ),
					'fonts_display' => array( 'choice', 'While fonts load', 'font-display for self-hosted fonts.', array(
						'requires' => 'fonts_local',
						'options'  => array(
							'swap'     => 'Text shows at once. Best score.',
							'optional' => 'No shift. May keep the fallback font.',
							'fallback' => 'Short wait, then the fallback.',
						),
					) ),
				),
			),
			'scripts' => array(
				'title'   => 'Scripts',
				'icon'    => 'scripts',
				'intro'   => 'Trackers and widgets wait for the first interaction.',
				'metrics' => array( 'TBT', 'FCP', 'SI' ),
				'fields'  => array(
					'delay'            => array( 'check', 'Delay third-party scripts', 'Analytics, pixels and chat load on the first scroll, tap or click.' ),
					'delay_timeout'    => array( 'number', 'Load them anyway after', 'So analytics still counts visitors who never scroll or tap. 10 is standard; 0 = never.', array( 'requires' => 'delay', 'min' => 0, 'max' => 60, 'unit' => 'seconds' ) ),
					'delay_patterns'   => array( 'lines', 'Scripts to delay', 'Switch off to load with the page.', array( 'requires' => 'delay' ) ),
					'delay_exclusions' => array( 'lines', 'Never delay', 'Always load at once: consent and call tracking.', array( 'requires' => 'delay' ) ),
					'defer_head'       => array( 'check', 'Defer render-blocking scripts', 'Head scripts stop blocking the first paint.' ),
					'jquery_footer'    => array( 'check', 'Load jQuery in the footer', 'Pages that need it early keep it in the head.', array( 'requires' => 'defer_head', 'badge' => array( 'info', 'Checked on every page' ) ) ),
					'defer_exclusions' => array( 'lines', 'Never defer', 'Scripts the theme calls straight away.', array( 'requires' => 'defer_head' ) ),
				),
			),
			'cleanup' => array(
				'title'   => 'Cleanup',
				'icon'    => 'cleanup',
				'intro'   => 'Removes WordPress extras most sites don\'t use.',
				'metrics' => array( 'FCP' ),
				'fields'  => array(
					'no_emojis'         => array( 'check', 'Remove the emoji script', 'Browsers show emoji natively.' ),
					'no_embeds'         => array( 'check', 'Remove embed discovery', 'Your embeds still work.' ),
					'clean_head'        => array( 'check', 'Remove header clutter', 'RSD, shortlink and generator tags.' ),
					'no_dashicons'      => array( 'check', 'No Dashicons for visitors', 'Admin icons only when logged in.' ),
					'heartbeat'         => array( 'check', 'Calmer Heartbeat', 'Off for visitors, once a minute in admin.' ),
					'no_jquery_migrate' => array( 'check', 'Remove jQuery Migrate', 'Only if no old jQuery code runs.', array( 'badge' => array( 'warn', 'Test after turning on' ) ) ),
					'no_block_css'      => array( 'check', 'No block CSS on classic themes', 'Pages without blocks only.', array( 'badge' => array( 'warn', 'Test after turning on' ) ) ),
				),
			),
			'hosting' => array(
				'title'   => 'Hosting',
				'icon'    => 'hosting',
				'intro'   => 'Keeps your host\'s page cache fresh.',
				'metrics' => array( 'FCP' ),
				'fields'  => array(
					'purge_on_save' => array( 'check', 'Clear host cache on changes', 'After posts, menus, themes or plugins change.' ),
					'preconnect'    => array( 'check', 'Preconnect to first-screen servers', 'Up to three servers.' ),
				),
			),
		);
	}

	/** Small line icons (24px grid, drawn with currentColor). */
	private static function icon( $name ) {
		$paths = array(
			'overview' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
			'hero'     => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
			'images'   => '<rect x="7" y="3" width="14" height="14" rx="2.5"/><path d="M3 7.5V18a3 3 0 0 0 3 3h10.5"/><path d="M21 13l-4-4-6 6"/>',
			'fonts'    => '<path d="M4 7V4.5h16V7"/><path d="M12 4.5V20"/><path d="M9 20h6"/>',
			'scripts'  => '<path d="M8 8l-4 4 4 4"/><path d="M16 8l4 4-4 4"/><path d="M13.5 5l-3 14"/>',
			'cleanup'  => '<path d="M11 3l1.7 4.5L17 9.2l-4.3 1.7L11 15.5l-1.7-4.6L5 9.2l4.3-1.7z"/><path d="M18.5 14.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>',
			'hosting'  => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
			'bolt'     => '<path d="M13 2.5L4.5 13.5H11l-1 8 8.5-11H12z"/>',
			'refresh'  => '<path d="M20 12a8 8 0 1 1-2.4-5.7L20 8.5"/><path d="M20 3.5v5h-5"/>',
			'external' => '<path d="M14 4h6v6"/><path d="M20 4l-8.5 8.5"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
			'trash'    => '<path d="M4 7h16"/><path d="M9.5 7V4.5h5V7"/><path d="M6.5 7l.9 12.5a1 1 0 0 0 1 .9h7.2a1 1 0 0 0 1-.9L17.5 7"/>',
			'undo'     => '<path d="M9 14L4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
			'search'   => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
			'warn'     => '<path d="M12 3.5l9.5 16.5h-19z"/><path d="M12 10v4.5M12 17.5h.01"/>',
			'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
			'server'   => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
			'link'     => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
		);
		return '<svg class="gl-ico" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	private static function logo() {
		return '<svg class="gl-logo" viewBox="0 0 40 40" width="40" height="40" aria-hidden="true" focusable="false">'
			. '<rect width="40" height="40" rx="11" fill="#0d2a1b"/>'
			. '<rect x="13" y="5" width="14" height="30" rx="7" fill="#163d29"/>'
			. '<circle cx="20" cy="12" r="3.6" fill="#2e5440"/><circle cx="20" cy="20" r="3.6" fill="#2e5440"/>'
			. '<circle cx="20" cy="28" r="6" fill="#3ddc84" opacity=".28"/><circle cx="20" cy="28" r="3.6" fill="#3ddc84"/>'
			. '</svg>';
	}

	/** Checked switches / all switches in a tab, for the counters. */
	private static function tally( array $fields, array $s ) {
		$on    = 0;
		$total = 0;
		foreach ( $fields as $key => $f ) {
			if ( 'check' === $f[0] ) {
				$total++;
				$on += empty( $s[ $key ] ) ? 0 : 1;
			}
		}
		return array( $on, $total );
	}

	private static function switch_input( $id, $name, $value, $checked, $label_id = '' ) {
		return '<span class="gl-switch"><input type="checkbox" role="switch" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( $checked ? ' checked' : '' ) . ( $label_id ? ' aria-labelledby="' . esc_attr( $label_id ) . '"' : '' ) . '><span class="gl-track" aria-hidden="true"></span></span>';
	}

	/** A switch, number or choice row inside a card. */
	private static function render_row( $key, array $f, array $s, array $labels ) {
		$x     = $f[3] ?? array();
		$id    = 'greenlight-' . $key;
		$name  = Settings::OPTION . '[' . $key . ']';
		$req   = $x['requires'] ?? '';
		$class = 'gl-row gl-row-' . $f[0] . ( $req && empty( $s[ $req ] ) ? ' is-inactive' : '' );
		?>
		<div class="<?php echo esc_attr( $class ); ?>"<?php echo $req ? ' data-requires="' . esc_attr( $req ) . '"' : ''; ?>>
			<div class="gl-row-text">
				<div class="gl-row-title">
					<?php if ( 'choice' === $f[0] ) : ?>
						<span id="<?php echo esc_attr( $id ); ?>-label"><?php echo esc_html( $f[1] ); ?></span>
					<?php else : ?>
						<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $f[1] ); ?></label>
					<?php endif; ?>
					<?php if ( ! empty( $x['badge'] ) ) : ?><span class="gl-badge gl-badge-<?php echo esc_attr( $x['badge'][0] ); ?>"><?php echo esc_html( $x['badge'][1] ); ?></span><?php endif; ?>
				</div>
				<p class="gl-row-desc"><?php echo esc_html( $f[2] ); ?></p>
				<?php if ( $req ) : ?><p class="gl-needs"><?php echo self::icon( 'link' ); ?>Needs “<?php echo esc_html( $labels[ $req ] ?? $req ); ?>”</p><?php endif; ?>
			</div>
			<div class="gl-row-control">
				<?php if ( 'check' === $f[0] ) : ?>
					<?php echo self::switch_input( $id, $name, '1', ! empty( $s[ $key ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php elseif ( 'number' === $f[0] ) : ?>
					<span class="gl-number">
						<input type="number" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" min="<?php echo (int) $x['min']; ?>" max="<?php echo (int) $x['max']; ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>" inputmode="numeric">
						<span class="gl-unit"><?php echo esc_html( $x['unit'] ); ?></span>
					</span>
				<?php endif; ?>
			</div>
			<?php if ( 'choice' === $f[0] ) : ?>
				<div class="gl-choices" role="radiogroup" aria-labelledby="<?php echo esc_attr( $id ); ?>-label">
					<?php foreach ( $x['options'] as $value => $help ) : ?>
						<label class="gl-choice">
							<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $s[ $key ], $value ); ?>>
							<span class="gl-choice-body"><strong><?php echo esc_html( $value ); ?></strong><span><?php echo esc_html( $help ); ?></span></span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** A list setting: one row per service (its switch covers all its patterns), a remove button, and a box to add more. */
	private static function render_list( $key, array $f, array $s, array $labels ) {
		$x      = $f[3] ?? array();
		$req    = $x['requires'] ?? '';
		$groups = Settings::groups( $key );
		$on     = count( array_filter( wp_list_pluck( $groups, 'on' ) ) );
		$base   = Settings::OPTION . '[' . $key;
		?>
		<section class="gl-card gl-list-card<?php echo $req && empty( $s[ $req ] ) ? ' is-inactive' : ''; ?>"<?php echo $req ? ' data-requires="' . esc_attr( $req ) . '"' : ''; ?> data-list="<?php echo esc_attr( $key ); ?>">
			<header class="gl-list-head">
				<div>
					<h3 id="gl-list-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $f[1] ); ?></h3>
					<p class="gl-row-desc"><?php echo esc_html( $f[2] ); ?></p>
					<?php if ( $req ) : ?><p class="gl-needs"><?php echo self::icon( 'link' ); ?>Needs “<?php echo esc_html( $labels[ $req ] ?? $req ); ?>”</p><?php endif; ?>
				</div>
				<span class="gl-count" data-count><?php echo (int) $on; ?> on<?php echo count( $groups ) > $on ? ' · ' . (int) ( count( $groups ) - $on ) . ' off' : ''; ?></span>
			</header>
			<?php if ( count( $groups ) > 8 ) : ?>
				<div class="gl-search">
					<?php echo self::icon( 'search' ); ?>
					<input type="search" placeholder="Filter <?php echo (int) count( $groups ); ?> entries" aria-label="Filter <?php echo esc_attr( $f[1] ); ?>" data-filter>
				</div>
			<?php endif; ?>
			<ul class="gl-items" aria-labelledby="gl-list-<?php echo esc_attr( $key ); ?>">
				<?php foreach ( $groups as $n => $g ) : $tid = 'gl-' . $key . '-' . $n; $idx = implode( ',', $g['indexes'] ); $name = $g['label'] ?? $g['values'][0]; ?>
					<li class="gl-item" data-search="<?php echo esc_attr( strtolower( $g['label'] . ' ' . implode( ' ', $g['values'] ) ) ); ?>">
						<?php foreach ( $g['indexes'] as $j => $i ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $base . '__items]' ); ?>[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $g['values'][ $j ] ); ?>">
						<?php endforeach; ?>
						<label class="gl-item-main" for="<?php echo esc_attr( $tid ); ?>">
							<?php echo self::switch_input( $tid, $base . '__on][]', $idx, $g['on'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<span class="gl-item-text">
								<?php if ( $g['label'] ) : ?>
									<strong><?php echo esc_html( $g['label'] ); ?></strong>
									<span class="gl-patterns"><?php foreach ( $g['values'] as $v ) : ?><code><?php echo esc_html( $v ); ?></code><?php endforeach; ?></span>
								<?php else : ?>
									<strong class="gl-custom"><?php echo esc_html( $g['values'][0] ); ?></strong>
								<?php endif; ?>
							</span>
						</label>
						<label class="gl-remove" title="Remove">
							<input type="checkbox" name="<?php echo esc_attr( $base . '__del]' ); ?>[]" value="<?php echo esc_attr( $idx ); ?>">
							<span class="gl-remove-do"><?php echo self::icon( 'trash' ); ?><span class="screen-reader-text">Remove <?php echo esc_html( $name ); ?></span></span>
							<span class="gl-remove-undo"><?php echo self::icon( 'undo' ); ?>Undo</span>
						</label>
					</li>
				<?php endforeach; ?>
				<?php if ( ! $groups ) : ?><li class="gl-empty">Nothing here yet.</li><?php endif; ?>
			</ul>
			<input type="hidden" name="<?php echo esc_attr( $base . '__items]' ); ?>[]" value="">
			<div class="gl-add">
				<label for="gl-add-<?php echo esc_attr( $key ); ?>">Add <span>one per line</span></label>
				<textarea id="gl-add-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $base . '__add]' ); ?>" rows="1" spellcheck="false" placeholder="example.com/widget.js"></textarea>
			</div>
		</section>
		<?php
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = Settings::all();
		$tabs   = self::tabs();
		$notice = get_transient( 'greenlight_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'greenlight_notice_' . get_current_user_id() );
		}
		$host      = Purge::host();
		$fonts     = glob( Fonts::dir()['path'] . '*/meta.json' ) ?: array();
		$conflicts = self::conflicts();
		$active    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'overview' !== $active && ! isset( $tabs[ $active ] ) ) {
			$active = 'overview';
		}
		$labels = array();
		$on     = 0;
		$total  = 0;
		foreach ( $tabs as $t ) {
			foreach ( $t['fields'] as $key => $f ) {
				$labels[ $key ] = $f[1];
			}
			list( $a, $b ) = self::tally( $t['fields'], $s );
			$on    += $a;
			$total += $b;
		}
		$purge_url = wp_nonce_url( admin_url( 'admin-post.php?action=greenlight_purge' ), 'greenlight_purge' );
		$fonts_url = wp_nonce_url( admin_url( 'admin-post.php?action=greenlight_fonts_clear' ), 'greenlight_fonts_clear' );
		$nav       = array( 'overview' => array( 'title' => 'Overview', 'icon' => 'overview' ) ) + $tabs;
		?>
		<div class="wrap gl-wrap">
			<h1 class="screen-reader-text">Greenlight</h1>
			<form method="post" action="options.php" id="gl-app" class="gl-app<?php echo empty( $s['enabled'] ) ? ' is-off' : ''; ?>" data-home="<?php echo esc_url( home_url( '/' ) ); ?>">
				<script>document.getElementById('gl-app').classList.add('gl-js');</script>
				<?php settings_fields( 'greenlight' ); ?>

				<header class="gl-head">
					<div class="gl-brand">
						<?php echo self::logo(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<div>
							<div class="gl-brand-name">Greenlight <span class="gl-version">v<?php echo esc_html( GREENLIGHT_VERSION ); ?></span></div>
							<div class="gl-brand-tag">Core Web Vitals, without breaking the site</div>
						</div>
					</div>
					<div class="gl-head-side">
						<ul class="gl-chips" aria-label="Status">
							<li class="gl-chip<?php echo $host ? ' is-good' : ''; ?>"><?php echo self::icon( 'server' ); ?><?php echo $host ? esc_html( $host . ' page cache' ) : 'No host page cache'; ?></li>
							<li class="gl-chip<?php echo $fonts ? ' is-good' : ''; ?>"><?php echo self::icon( 'fonts' ); ?><?php echo (int) count( $fonts ); ?> font set<?php echo 1 === count( $fonts ) ? '' : 's'; ?> self-hosted</li>
							<li class="gl-chip <?php echo $conflicts ? 'is-warn' : 'is-good'; ?>"><?php echo self::icon( $conflicts ? 'warn' : 'check' ); ?><?php echo $conflicts ? (int) count( $conflicts ) . ' overlapping plugin' . ( 1 === count( $conflicts ) ? '' : 's' ) : 'No conflicts'; ?></li>
						</ul>
						<label class="gl-master" for="greenlight-enabled">
							<span class="gl-master-text"><span class="gl-master-on">Optimizing</span><span class="gl-master-off">Paused</span></span>
							<?php echo self::switch_input( 'greenlight-enabled', Settings::OPTION . '[enabled]', '1', ! empty( $s['enabled'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</label>
					</div>
				</header>
				<hr class="wp-header-end">
				<?php settings_errors(); ?>

				<?php if ( $notice ) : ?>
					<div class="gl-toast" role="status"><?php echo self::icon( 'check' ); ?><?php echo esc_html( $notice ); ?></div>
				<?php endif; ?>
				<div class="gl-off-banner" role="status"><?php echo self::icon( 'warn' ); ?><div><strong>Greenlight is paused.</strong> Pages are served untouched. Switch on and save to resume.</div></div>

				<nav class="gl-tabs" role="tablist" aria-label="Greenlight settings">
					<?php foreach ( $nav as $slug => $t ) : ?>
						<button type="button" role="tab" class="gl-tab" id="gl-tab-<?php echo esc_attr( $slug ); ?>" aria-controls="gl-panel-<?php echo esc_attr( $slug ); ?>" aria-selected="<?php echo $slug === $active ? 'true' : 'false'; ?>" tabindex="<?php echo $slug === $active ? '0' : '-1'; ?>" data-tab="<?php echo esc_attr( $slug ); ?>">
							<?php echo self::icon( $t['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<span><?php echo esc_html( $t['title'] ); ?></span>
							<?php if ( isset( $t['fields'] ) ) : list( $a, $b ) = self::tally( $t['fields'], $s ); ?>
								<span class="gl-tab-count" data-tally="<?php echo esc_attr( $slug ); ?>"><?php echo (int) $a; ?>/<?php echo (int) $b; ?></span>
							<?php endif; ?>
						</button>
					<?php endforeach; ?>
				</nav>

				<section class="gl-panel<?php echo 'overview' === $active ? ' is-active' : ''; ?>" role="tabpanel" id="gl-panel-overview" aria-labelledby="gl-tab-overview" tabindex="0">
					<div class="gl-overview">
						<div class="gl-overview-main">
							<div class="gl-card gl-score">
								<div class="gl-ring" style="--p:<?php echo $total ? round( $on / $total * 100 ) : 0; ?>" data-ring>
									<span><strong data-total-on><?php echo (int) $on; ?></strong>of <?php echo (int) $total; ?></span>
								</div>
								<div>
									<h2>Optimizations on</h2>
									<p>Safe defaults. Pick an area to fine-tune it.</p>
								</div>
							</div>
							<?php if ( $conflicts ) : ?>
								<div class="gl-card gl-alert">
									<?php echo self::icon( 'warn' ); ?>
									<div>
										<h3>Overlapping plugins</h3>
										<ul>
											<?php foreach ( $conflicts as $name => $advice ) : ?>
												<li><strong><?php echo esc_html( $name ); ?>:</strong> <?php echo esc_html( $advice ); ?>.</li>
											<?php endforeach; ?>
										</ul>
									</div>
								</div>
							<?php endif; ?>
							<div class="gl-tiles">
								<?php foreach ( $tabs as $slug => $t ) : list( $a, $b ) = self::tally( $t['fields'], $s ); ?>
									<button type="button" class="gl-tile" data-goto="<?php echo esc_attr( $slug ); ?>">
										<span class="gl-tile-top">
											<span class="gl-tile-ico"><?php echo self::icon( $t['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
											<span class="gl-metrics"><?php foreach ( $t['metrics'] as $m ) : ?><span class="gl-metric"><?php echo esc_html( $m ); ?></span><?php endforeach; ?></span>
										</span>
										<strong class="gl-tile-title"><?php echo esc_html( $t['title'] ); ?></strong>
										<span class="gl-tile-text"><?php echo esc_html( $t['intro'] ); ?></span>
										<span class="gl-bar"><span style="width:<?php echo $b ? round( $a / $b * 100 ) : 0; ?>%" data-bar="<?php echo esc_attr( $slug ); ?>"></span></span>
										<span class="gl-tile-foot" data-tile-tally="<?php echo esc_attr( $slug ); ?>"><?php echo (int) $a; ?> of <?php echo (int) $b; ?> on</span>
									</button>
								<?php endforeach; ?>
							</div>
						</div>
						<aside class="gl-overview-side">
							<div class="gl-card gl-side-card">
								<h3>Compare a page</h3>
								<p>Open a page with and without Greenlight.</p>
								<div class="gl-compare">
									<span class="gl-compare-host"><?php echo esc_html( preg_replace( '#^https?://#', '', untrailingslashit( home_url() ) ) ); ?></span>
									<input type="text" value="/" aria-label="Page path" spellcheck="false" data-compare>
								</div>
								<div class="gl-btn-row">
									<button type="button" class="gl-btn gl-btn-soft" data-compare-open="on"><?php echo self::icon( 'external' ); ?>With Greenlight</button>
									<button type="button" class="gl-btn gl-btn-ghost" data-compare-open="off"><?php echo self::icon( 'external' ); ?>Without</button>
								</div>
							</div>
							<div class="gl-card gl-side-card">
								<h3>Caches</h3>
								<p><?php echo $host ? esc_html( $host . ' page cache, cleared on every change.' ) : 'No host page cache found.'; ?></p>
								<div class="gl-btn-row">
									<a class="gl-btn gl-btn-soft" href="<?php echo esc_url( $purge_url ); ?>"><?php echo self::icon( 'bolt' ); ?>Clear caches</a>
									<?php if ( $fonts ) : ?><a class="gl-btn gl-btn-ghost" href="<?php echo esc_url( $fonts_url ); ?>"><?php echo self::icon( 'refresh' ); ?>Re-download fonts</a><?php endif; ?>
								</div>
							</div>
							<div class="gl-card gl-side-card gl-tip">
								<h3>Command line</h3>
								<p><code>wp greenlight status</code></p>
							</div>
						</aside>
					</div>
				</section>

				<?php foreach ( $tabs as $slug => $t ) : ?>
					<section class="gl-panel<?php echo $slug === $active ? ' is-active' : ''; ?>" role="tabpanel" id="gl-panel-<?php echo esc_attr( $slug ); ?>" aria-labelledby="gl-tab-<?php echo esc_attr( $slug ); ?>" tabindex="0" data-panel="<?php echo esc_attr( $slug ); ?>">
						<div class="gl-panel-head">
							<span class="gl-panel-ico"><?php echo self::icon( $t['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<div>
								<h2><?php echo esc_html( $t['title'] ); ?> <span class="gl-metrics"><?php foreach ( $t['metrics'] as $m ) : ?><span class="gl-metric"><?php echo esc_html( $m ); ?></span><?php endforeach; ?></span></h2>
								<p><?php echo esc_html( $t['intro'] ); ?></p>
							</div>
						</div>
						<?php
						$group = array();
						$flush = function () use ( &$group, $s, $labels ) {
							if ( $group ) {
								echo '<div class="gl-card gl-rows">';
								foreach ( $group as $k => $f ) {
									self::render_row( $k, $f, $s, $labels );
								}
								echo '</div>';
								$group = array();
							}
						};
						foreach ( $t['fields'] as $key => $f ) {
							if ( 'lines' === $f[0] ) {
								$flush();
								self::render_list( $key, $f, $s, $labels );
							} else {
								$group[ $key ] = $f;
							}
						}
						$flush();
						?>
					</section>
				<?php endforeach; ?>

				<div class="gl-savebar">
					<span class="gl-savebar-status"><span class="gl-dot" aria-hidden="true"></span><span data-save-status>All changes saved</span></span>
					<span class="gl-btn-row">
						<button type="button" class="gl-btn gl-btn-ghost" data-discard hidden>Discard</button>
						<button type="submit" name="submit" class="gl-btn gl-btn-primary">Save changes</button>
					</span>
				</div>
			</form>
		</div>
		<?php
	}
}
