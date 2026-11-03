<?php
namespace Greenlight;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities API (WordPress 6.9+): lets Claude and other MCP clients check and run Greenlight, through
 * the MCP Adapter plugin or the REST API (wp-abilities/v1). All need manage_options; only
 * greenlight/purge changes anything. On older WordPress nothing is registered.
 */
class Abilities {

	public static function init() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	public static function category() {
		wp_register_ability_category(
			'greenlight',
			array(
				'label'       => 'Greenlight',
				'description' => 'Core Web Vitals fixes, security hardening and alerts for this site.',
			)
		);
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Client exposure for WordPress 6.9 and later: `public` (7.0+ and the MCP Adapter), `show_in_rest`
	 * (what 6.9 reads) and `mcp.type` (an MCP tool).
	 */
	private static function meta( $readonly, $idempotent = true ) {
		return array(
			'annotations'  => array( 'readonly' => $readonly, 'destructive' => false, 'idempotent' => $idempotent ),
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array( 'type' => 'tool' ),
		);
	}

	public static function register() {
		$alert = array(
			'type'       => 'object',
			'properties' => array(
				'id'       => array( 'type' => 'string' ),
				'type'     => array( 'type' => 'string', 'enum' => array( 'admin_change', 'script_drift', 'core_files', 'content_check' ) ),
				'severity' => array( 'type' => 'string', 'enum' => array( 'high', 'medium', 'low' ) ),
				'time'     => array( 'type' => 'string' ),
				'message'  => array( 'type' => 'string' ),
				'url'      => array( 'type' => array( 'string', 'null' ) ),
				'post_id'  => array( 'type' => array( 'integer', 'null' ) ),
			),
		);
		$features = array();
		foreach ( Security::FEATURES as $key ) {
			$features[ $key ] = array( 'type' => 'boolean' );
		}

		wp_register_ability(
			'greenlight/status',
			array(
				'label'               => 'Greenlight status',
				'description'         => 'Greenlight version, whether it is on, every switch (1/0), the host page cache, self-hosted fonts, the login page address, overlapping plugins, and real-user Core Web Vitals from the Chrome UX Report (75th percentile, mobile and desktop) when an API key is set. Read-only.',
				'category'            => 'greenlight',
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'greenlight_version' => array( 'type' => 'string' ),
						'enabled'            => array( 'type' => 'boolean' ),
						'host_page_cache'    => array( 'type' => array( 'string', 'null' ) ),
						'font_sets'          => array( 'type' => 'integer' ),
						'login_url'          => array( 'type' => 'string' ),
						'settings'           => array( 'type' => 'object' ),
						'conflicts'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'field_data'         => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => function () { return Report::status(); },
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => self::meta( true ),
			)
		);

		wp_register_ability(
			'greenlight/security-report',
			array(
				'label'               => 'Greenlight security report',
				'description'         => 'The Security tab as data: which hardening and alert features are on, the script baseline, trusted embed domains, and the stored alerts (newest first). Same schema 1 JSON as `wp greenlight security --format=json`. Read-only.',
				'category'            => 'greenlight',
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'greenlight_version'    => array( 'type' => 'string' ),
						'schema'                => array( 'type' => 'integer' ),
						'features'              => array( 'type' => 'object', 'properties' => $features ),
						'script_baseline'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'trusted_embed_domains' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'alerts'                => array( 'type' => 'array', 'items' => $alert ),
					),
				),
				'execute_callback'    => function () { return Security::report(); },
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => self::meta( true ),
			)
		);

		wp_register_ability(
			'greenlight/purge',
			array(
				'label'               => 'Clear Greenlight caches',
				'description'         => 'Clears the host page cache (WP Engine, SiteGround, Kinsta, Pantheon) and the object cache, like the "Clear caches" toolbar button. Returns the layers cleared. The only Greenlight ability that changes anything; pages rebuild on their next view.',
				'category'            => 'greenlight',
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'cleared' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
				),
				'execute_callback'    => function () { return array( 'cleared' => Purge::all( 'ability' ) ); },
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => self::meta( false ),
			)
		);

		wp_register_ability(
			'greenlight/compare',
			array(
				'label'               => 'Compare a page with and without Greenlight',
				'description'         => 'Loads one page of this site twice, with Greenlight and with ?greenlight=off (both past the host page cache), and reports for each: response time, HTML size, render-blocking head scripts, delayed scripts, embeds held until interaction, lazy images, preloads, hero priority, third-party hosts loaded at once, and Greenlight\'s own summary comment. Read-only.',
				'category'            => 'greenlight',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'path' => array(
							'type'        => 'string',
							'description' => 'Path on this site, e.g. "/" or "/contact/". Default "/".',
							'default'     => '/',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'url'     => array( 'type' => 'string' ),
						'with'    => array( 'type' => 'object' ),
						'without' => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => function ( $input = null ) { return Report::compare( is_array( $input ) && isset( $input['path'] ) ? $input['path'] : '/' ); },
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => self::meta( true ),
			)
		);
	}
}
