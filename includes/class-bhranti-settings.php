<?php
/**
 * Human-managed site prompts, guardrails and Markdown agent profiles.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/** Store profile documents as data, without executing or rendering their contents. */
final class Bhranti_Settings {

	/** Register the authenticated settings endpoint and its administration assets. */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/** Register administrator-only reads and revision-checked JSON updates. */
	public static function register_routes() {
		register_rest_route(
			'bhranti/v1',
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'config'   => array(
							'type'     => 'object',
							'required' => true,
						),
						'revision' => array(
							'type'      => 'string',
							'required'  => true,
							'minLength' => 1,
							'maxLength' => 36,
						),
					),
				),
			)
		);
	}

	/**
	 * Core REST cookie authentication enforces the WordPress nonce independently.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		$user = wp_get_current_user();
		return current_user_can( 'manage_options' ) && ! get_user_meta( $user->ID, 'bhranti_managed_actor', true ) && ! in_array( 'bhranti_agent', $user->roles, true );
	}

	/**
	 * Load the native React settings screen only on Bhranti's main menu page.
	 *
	 * @param string $hook Current administration page.
	 */
	public static function enqueue( $hook ) {
		if ( 'toplevel_page_bhranti' !== $hook || ! self::can_manage() ) {
			return;
		}
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'bhranti-settings', plugins_url( 'assets/settings.css', BHRANTI_FILE ), array( 'wp-components' ), BHRANTI_VERSION );
		wp_enqueue_script( 'bhranti-settings', plugins_url( 'assets/settings.js', BHRANTI_FILE ), array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ), BHRANTI_VERSION, true );
		wp_set_script_translations( 'bhranti-settings', 'bhranti' );
		wp_add_inline_script(
			'bhranti-settings',
			'window.bhrantiSettingsPage = ' . wp_json_encode(
				array(
					'apiPath'                 => '/bhranti/v1/settings',
					'pluginDeletionAvailable' => defined( 'BHRANTI_ALLOW_IRREVERSIBLE_PLUGIN_DELETION' ) && BHRANTI_ALLOW_IRREVERSIBLE_PLUGIN_DELETION,
					'route'                   => add_query_arg( 'page', 'bhranti', admin_url( 'admin.php' ) ),
				),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . ';',
			'before'
		);
	}

	/**
	 * Return fresh defaults without creating an option on a read request.
	 *
	 * @return array
	 */
	private static function defaults() {
		return array(
			'permissions'   => self::permission_defaults(),
			'max_steps'     => 128,
			'system_prompt' => '',
			'guardrails'    => '',
			'agents'        => array(
				array(
					'id'       => 'bhranti',
					'name'     => 'Bhranti',
					'agent_md' => '',
					'skills'   => array(),
				),
			),
		);
	}

	/** Return the reviewed action switches, with routine actions enabled. */
	public static function permission_defaults() {
		return array(
			'edit_content'         => true,
			'publish_content'      => true,
			'trash_content'        => true,
			'manage_comments'      => true,
			'manage_media'         => true,
			'manage_navigation'    => true,
			'manage_design'        => true,
			'manage_site_settings' => true,
			'plugins_install'      => true,
			'plugins_activate'     => true,
			'plugins_delete'       => false,
		);
	}

	/**
	 * Check current site policy before each new action, independently of prompts.
	 *
	 * @param string $permission Reviewed action group.
	 * @return true|WP_Error
	 */
	public static function allows( $permission ) {
		self::fresh_option( 'bhranti_settings' );
		$stored = self::read();
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		if ( true !== ( $stored['config']['permissions'][ $permission ] ?? false ) ) {
			return self::error( 'bhranti_action_disabled', __( 'This action is turned off in Bhranti Settings → Action permissions.', 'bhranti' ), 403 );
		}
		return true;
	}

	/**
	 * Return the site configuration for the agent, without exposing storage metadata.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = self::read();
		return is_wp_error( $stored ) ? self::defaults() : $stored['config'];
	}

	/**
	 * Return concise profile choices without their Markdown documents.
	 *
	 * @return array
	 */
	public static function summaries() {
		$config = self::all();
		return array_map(
			static function ( $agent ) {
				return array(
					'id'          => $agent['id'],
					'name'        => $agent['name'],
					'skill_count' => count( $agent['skills'] ),
				);
			},
			$config['agents']
		);
	}

	/**
	 * Resolve a human-saved agent and its shared site instructions.
	 *
	 * @param string $id Stable profile ID.
	 * @return array|WP_Error
	 */
	public static function profile( $id ) {
		$stored = self::read();
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		foreach ( $stored['config']['agents'] as $agent ) {
			if ( $agent['id'] === $id ) {
				return array(
					'id'            => $agent['id'],
					'name'          => $agent['name'],
					'max_steps'     => $stored['config']['max_steps'],
					'system_prompt' => $stored['config']['system_prompt'],
					'guardrails'    => $stored['config']['guardrails'],
					'agent_md'      => $agent['agent_md'],
					'skills'        => $agent['skills'],
				);
			}
		}
		return self::error( 'bhranti_agent_missing', __( 'This agent profile is no longer available. Choose another agent.', 'bhranti' ), 404 );
	}

	/**
	 * Return the complete editor configuration and its optimistic revision.
	 *
	 * @return array|WP_Error
	 */
	public static function get() {
		return self::read();
	}

	/**
	 * Save one complete configuration while holding a site-wide revision lock.
	 *
	 * @param WP_REST_Request $request Exact JSON configuration and revision.
	 * @return array|WP_Error
	 */
	public static function save( $request ) {
		if ( ! self::can_manage() ) {
			return self::error( 'bhranti_settings_permission', __( 'Only an administrator can change Bhranti settings.', 'bhranti' ), 403 );
		}
		$body = $request->get_json_params();
		if ( ! self::shape( $body, array( 'config', 'revision' ) ) || ! is_string( $body['revision'] ) || ! preg_match( '/^(?:initial|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/D', $body['revision'] ) ) {
			return self::error( 'bhranti_settings_invalid', __( 'Send a JSON object containing only config and its current revision.', 'bhranti' ), 400 );
		}
		$config = self::validate( $body['config'] );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$lock = array(
			'token'   => wp_generate_uuid4(),
			'expires' => time() + 60,
		);
		if ( ! add_option( 'bhranti_settings_lock', $lock, '', false ) ) {
			$previous = self::fresh_option( 'bhranti_settings_lock' );
			if ( is_array( $previous ) && (int) ( $previous['expires'] ?? 0 ) < time() && self::fresh_option( 'bhranti_settings_lock' ) === $previous ) {
				delete_option( 'bhranti_settings_lock' );
			}
			if ( ! add_option( 'bhranti_settings_lock', $lock, '', false ) ) {
				return self::error( 'bhranti_settings_busy', __( 'Another administrator is saving settings. Try again shortly.', 'bhranti' ), 409 );
			}
		}
		try {
			$prior          = self::fresh_option( 'bhranti_settings' );
			$prior_autoload = array_key_exists( 'bhranti_settings', wp_load_alloptions( true ) );
			$old            = self::read();
			if ( is_wp_error( $old ) ) {
				return $old;
			}
			if ( ! hash_equals( $old['revision'], $body['revision'] ) ) {
				return self::error( 'bhranti_settings_conflict', __( 'Another administrator changed these settings. Reload before saving your changes.', 'bhranti' ), 409 );
			}
			$logged = self::audit( 'settings_started', __( 'An administrator requested a Bhranti settings update.', 'bhranti' ) );
			if ( is_wp_error( $logged ) ) {
				return $logged;
			}
			if ( self::fresh_option( 'bhranti_settings' ) !== $prior ) {
				return self::error( 'bhranti_settings_conflict', __( 'These settings changed before they could be saved. Reload and try again.', 'bhranti' ), 409 );
			}
			if ( self::fresh_option( 'bhranti_settings_lock' ) !== $lock ) {
				return self::error( 'bhranti_settings_busy', __( 'The settings save lock expired. Reload and try again.', 'bhranti' ), 409 );
			}
			$next = array(
				'config'   => $config,
				'revision' => wp_generate_uuid4(),
			);
			update_option( 'bhranti_settings', $next, false );
			if ( self::fresh_option( 'bhranti_settings' ) !== $next ) {
				$restored = self::restore( $prior, $prior_autoload );
				self::audit( 'settings_failed', __( 'WordPress could not persist the settings update.', 'bhranti' ) );
				if ( is_wp_error( $restored ) ) {
					return $restored;
				}
				return self::error( 'bhranti_settings_storage', __( 'WordPress could not save the settings. Reload before trying again.', 'bhranti' ), 500 );
			}
			$logged = self::audit( 'settings_completed', __( 'Bhranti settings updated by an administrator.', 'bhranti' ) );
			if ( is_wp_error( $logged ) ) {
				$restored = self::restore( $prior, $prior_autoload );
				return is_wp_error( $restored ) ? $restored : $logged;
			}
			return $next;
		} finally {
			if ( self::fresh_option( 'bhranti_settings_lock' ) === $lock ) {
				delete_option( 'bhranti_settings_lock' );
			}
		}
	}

	/**
	 * Read a verified stored wrapper; corrupt data is never silently overwritten.
	 *
	 * @return array|WP_Error
	 */
	private static function read() {
		$stored = get_option( 'bhranti_settings', null );
		if ( null === $stored ) {
			return array(
				'config'   => self::defaults(),
				'revision' => 'initial',
			);
		}
		if ( is_array( $stored ) && self::shape( $stored['config'] ?? null, array( 'system_prompt', 'guardrails', 'agents' ) ) ) {
			$stored['config']['permissions'] = self::permission_defaults();
		}
		if ( ! self::shape( $stored, array( 'config', 'revision' ) ) || ! is_string( $stored['revision'] ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $stored['revision'] ) ) {
			return self::error( 'bhranti_settings_storage', __( 'The saved settings need administrator review before they can be changed.', 'bhranti' ), 500 );
		}
		$config = self::validate( $stored['config'] );
		if ( is_wp_error( $config ) ) {
			return self::error( 'bhranti_settings_storage', __( 'The saved settings need administrator review before they can be changed.', 'bhranti' ), 500 );
		}
		// Normalize legacy reads in memory only; a human save persists the new field.
		$stored['config'] = $config;
		return $stored;
	}

	/**
	 * Validate exact shapes and limits without modifying a Markdown byte.
	 *
	 * @param mixed $config Complete configuration.
	 * @return array|WP_Error
	 */
	private static function validate( $config ) {
		if ( self::shape( $config, array( 'permissions', 'system_prompt', 'guardrails', 'agents' ) ) ) {
			$config['max_steps'] = 128;
		}
		if ( ! self::shape( $config, array( 'permissions', 'max_steps', 'system_prompt', 'guardrails', 'agents' ) ) || ! self::text( $config['system_prompt'], 16000 ) || ! self::text( $config['guardrails'], 16000 ) || ! self::list_value( $config['agents'], 20, true ) ) {
			return self::error( 'bhranti_settings_invalid', __( 'Use the complete configuration, with up to 20 agents and Markdown documents of at most 16,000 characters.', 'bhranti' ), 400 );
		}
		if ( ! is_int( $config['max_steps'] ) || $config['max_steps'] < 12 || $config['max_steps'] > 1000 ) {
			return self::error( 'bhranti_settings_invalid', __( 'Task step limit must be a whole number from 12 to 1,000.', 'bhranti' ), 400 );
		}
		if ( ! self::shape( $config['permissions'], array_keys( self::permission_defaults() ) ) || array_filter(
			$config['permissions'],
			static function ( $value ) {
				return ! is_bool( $value ); }
		) ) {
			return self::error( 'bhranti_settings_invalid', __( 'Send each reviewed action permission as a true or false switch.', 'bhranti' ), 400 );
		}
		$ids = array();
		foreach ( $config['agents'] as $agent ) {
			if ( ! self::shape( $agent, array( 'id', 'name', 'agent_md', 'skills' ) ) || ! self::identifier( $agent['id'] ) || isset( $ids[ $agent['id'] ] ) || ! self::text( $agent['name'], 100, true ) || ! self::text( $agent['agent_md'], 16000 ) || ! self::list_value( $agent['skills'], 20 ) ) {
				return self::error( 'bhranti_settings_invalid', __( 'Each agent needs a unique stable ID, a name, an agent Markdown document, and at most 20 skills.', 'bhranti' ), 400 );
			}
			$ids[ $agent['id'] ] = true;
			$skill_ids           = array();
			foreach ( $agent['skills'] as $skill ) {
				if ( ! self::shape( $skill, array( 'id', 'name', 'skill_md' ) ) || ! self::identifier( $skill['id'] ) || isset( $skill_ids[ $skill['id'] ] ) || ! self::text( $skill['name'], 100, true ) || ! self::text( $skill['skill_md'], 16000 ) ) {
					return self::error( 'bhranti_settings_invalid', __( 'Each skill needs a unique stable ID, a name, and its Markdown document.', 'bhranti' ), 400 );
				}
				$skill_ids[ $skill['id'] ] = true;
			}
		}
		if ( ! isset( $ids['bhranti'] ) ) {
			return self::error( 'bhranti_settings_invalid', __( 'Keep the built-in Bhranti agent in the configuration.', 'bhranti' ), 400 );
		}
		$encoded = wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded || mb_strlen( $encoded, 'UTF-8' ) > 200000 ) {
			return self::error( 'bhranti_settings_invalid', __( 'Keep the complete settings configuration within 200,000 characters.', 'bhranti' ), 400 );
		}
		return $config;
	}

	/**
	 * Require exact keys; arbitrary extra fields are rejected.
	 *
	 * @param mixed $value Object represented as an associative array.
	 * @param array $keys Required keys.
	 * @return bool
	 */
	private static function shape( $value, $keys ) {
		return is_array( $value ) && ! array_diff( $keys, array_keys( $value ) ) && ! array_diff( array_keys( $value ), $keys );
	}

	/**
	 * Require a bounded sequential list compatible with PHP 7.4.
	 *
	 * @param mixed $value List.
	 * @param int   $limit Maximum items.
	 * @param bool  $required Whether one item is required.
	 * @return bool
	 */
	private static function list_value( $value, $limit, $required = false ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		$count = count( $value );
		return $count <= $limit && ( 0 === $count ? ! $required : array_keys( $value ) === range( 0, $count - 1 ) );
	}

	/**
	 * Stable IDs never become filesystem paths or executable identifiers.
	 *
	 * @param mixed $value ID.
	 * @return bool
	 */
	private static function identifier( $value ) {
		return is_string( $value ) && (bool) preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $value );
	}

	/**
	 * Preserve valid UTF-8 exactly, including Markdown whitespace and backslashes.
	 *
	 * @param mixed $value Text.
	 * @param int   $limit Maximum Unicode characters.
	 * @param bool  $required Whether nonblank text is required.
	 * @return bool
	 */
	private static function text( $value, $limit, $required = false ) {
		return is_string( $value ) && wp_check_invalid_utf8( $value, true ) === $value && mb_strlen( $value, 'UTF-8' ) <= $limit && ( ! $required || '' !== trim( $value ) );
	}

	/**
	 * Observe persisted values after concurrent requests or write verification.
	 *
	 * @param string $name Owned option key.
	 * @return mixed
	 */
	private static function fresh_option( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return get_option( $name, null );
	}

	/**
	 * Undo a failed save before returning an error to the editor.
	 *
	 * @param mixed $prior Prior wrapper; null means the option did not exist.
	 * @param bool  $autoload Whether the prior option was autoloaded.
	 * @return true|WP_Error
	 */
	private static function restore( $prior, $autoload ) {
		if ( null === $prior ) {
			delete_option( 'bhranti_settings' );
		} else {
			update_option( 'bhranti_settings', $prior, $autoload );
		}
		if ( self::fresh_option( 'bhranti_settings' ) !== $prior ) {
			return self::error( 'bhranti_settings_storage', __( 'The settings could not be safely finalized. Reload and inspect the activity log before trying again.', 'bhranti' ), 500 );
		}
		if ( null !== $prior && array_key_exists( 'bhranti_settings', wp_load_alloptions( true ) ) !== $autoload ) {
			return self::error( 'bhranti_settings_storage', __( 'The settings could not be safely finalized. Reload and inspect the activity log before trying again.', 'bhranti' ), 500 );
		}
		return true;
	}

	/**
	 * Log metadata only, with the human administrator as both identities.
	 *
	 * @param string $type Event code.
	 * @param string $message Safe summary.
	 * @return int|WP_Error
	 */
	private static function audit( $type, $message ) {
		return Bhranti_Audit::record(
			$type,
			$message,
			0,
			array(
				'method' => 'POST',
				'path'   => '/bhranti/v1/settings',
			),
			get_current_user_id(),
			get_current_user_id()
		);
	}

	/**
	 * Return a public error without document contents or database payloads.
	 *
	 * @param string $code Error code.
	 * @param string $message Description.
	 * @param int    $status HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
