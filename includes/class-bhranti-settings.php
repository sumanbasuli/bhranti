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
					'memoryApiPath'           => '/bhranti/v1/memory',
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
		return self::persist( $config, $body['revision'] );
	}

	/**
	 * Share native validation, locking and atomic finalization for reviewed saves.
	 *
	 * @param array         $config Complete validated configuration.
	 * @param string        $revision Expected optimistic revision.
	 * @param array         $context Internal task identities; empty for the human form.
	 * @param callable|null $before_write Durable snapshot callback.
	 * @param callable|null $after_write Durable outcome callback.
	 * @return array|WP_Error
	 */
	private static function persist( $config, $revision, $context = array(), $before_write = null, $after_write = null ) {
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
			if ( ! hash_equals( $old['revision'], $revision ) ) {
				return self::error( 'bhranti_settings_conflict', __( 'Another administrator changed these settings. Reload before saving your changes.', 'bhranti' ), 409 );
			}
			$logged = self::audit( ! empty( $context['recovery'] ) ? 'rollback_started' : 'settings_started', __( 'A Bhranti settings update was requested.', 'bhranti' ), $context );
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
			if ( $before_write ) {
				$prepared = $before_write( $old, $next );
				if ( is_wp_error( $prepared ) ) {
					return $prepared;
				}
			}
			$allowed = $context ? self::task_authority( $context, ! empty( $context['recovery'] ) ) : true;
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
			// Snapshot/audit hooks can run other code: recheck immediately before the write.
			if ( self::fresh_option( 'bhranti_settings' ) !== $prior || self::fresh_option( 'bhranti_settings_lock' ) !== $lock ) {
				return self::error( 'bhranti_settings_conflict', __( 'These settings changed before they could be saved. Read the task limit again.', 'bhranti' ), 409 );
			}
			update_option( 'bhranti_settings', $next, false );
			if ( self::fresh_option( 'bhranti_settings' ) !== $next ) {
				$restored = self::restore( $prior, $prior_autoload, $next );
				self::audit( 'settings_failed', __( 'WordPress could not persist the settings update.', 'bhranti' ), $context );
				if ( is_wp_error( $restored ) ) {
					return $restored;
				}
				return self::error( 'bhranti_settings_storage', __( 'WordPress could not save the settings. Reload before trying again.', 'bhranti' ), 500 );
			}
			if ( $after_write ) {
				$recorded = $after_write( $next );
				if ( is_wp_error( $recorded ) ) {
					$restored = self::restore( $prior, $prior_autoload, $next );
					return is_wp_error( $restored ) ? $restored : $recorded;
				}
			}
			$logged = self::audit( ! empty( $context['recovery'] ) ? 'rollback_completed' : 'settings_completed', __( 'Bhranti settings updated.', 'bhranti' ), $context );
			if ( is_wp_error( $logged ) ) {
				$restored = self::restore( $prior, $prior_autoload, $next );
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
	 * Describe the only agent-editable Bhranti setting, independently of Markdown.
	 *
	 * @return array Tool catalog, or no tool when site-setting actions are disabled.
	 */
	public static function task_catalog() {
		if ( is_wp_error( self::allows( 'manage_site_settings' ) ) ) {
			return array();
		}
		return array(
			'bhranti_task_limit' => array(
				'description' => 'Read or change only Bhranti Task step limit. First read with {"method":"GET"}; it returns max_steps and revision. Then update with {"method":"POST","max_steps":129,"revision":"the returned revision"}. Use a whole number from 12 to 1000. The saved limit applies to future tasks; this task keeps its captured limit. No prompts, agents, guardrails or permission switches are accessible. A successful update supports Undo unless another Settings save intervenes.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'method'    => array(
							'type' => 'string',
							'enum' => array( 'GET', 'POST' ),
						),
						'max_steps' => array(
							'type'    => 'integer',
							'minimum' => 12,
							'maximum' => 1000,
						),
						'revision'  => array(
							'type'      => 'string',
							'maxLength' => 36,
						),
					),
					'required'             => array( 'method' ),
					'additionalProperties' => false,
				),
			),
		);
	}

	/**
	 * Read or update the bounded task limit through the shared native save engine.
	 *
	 * @param array $command Tool and exact arguments.
	 * @param int   $requester_id Authenticated human administrator.
	 * @param int   $session_id Owned private conversation.
	 * @return array|WP_Error
	 */
	public static function task_execute( $command, $requester_id, $session_id ) {
		$context = self::task_context( $requester_id, $session_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$args   = is_array( $command ) ? ( $command['args'] ?? null ) : null;
		$method = is_array( $args ) ? ( $args['method'] ?? '' ) : '';
		$valid  = self::shape( $command, array( 'tool', 'args' ) ) && 'bhranti_task_limit' === $command['tool'] && ( ( 'GET' === $method && self::shape( $args, array( 'method' ) ) ) || ( 'POST' === $method && self::shape( $args, array( 'method', 'max_steps', 'revision' ) ) && is_int( $args['max_steps'] ) && $args['max_steps'] >= 12 && $args['max_steps'] <= 1000 && is_string( $args['revision'] ) && preg_match( '/^(?:initial|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/D', $args['revision'] ) ) );
		if ( ! $valid ) {
			self::audit( 'action_blocked', __( 'An unsupported Bhranti settings request was blocked.', 'bhranti' ), $context + array( 'code' => 'bhranti_task_limit_invalid' ) );
			return self::error( 'bhranti_task_limit_invalid', __( 'Read the task limit with GET, or POST only max_steps (12–1,000) and its current revision.', 'bhranti' ), 400 );
		}
		$context['method'] = $method;
		$allowed           = self::task_authority( $context );
		if ( is_wp_error( $allowed ) ) {
			self::audit( 'action_blocked', __( 'Bhranti task-limit access was blocked.', 'bhranti' ), $context + array( 'code' => $allowed->get_error_code() ) );
			return $allowed;
		}
		self::fresh_option( 'bhranti_settings' );
		$old = self::read();
		if ( is_wp_error( $old ) ) {
			return $old;
		}
		if ( 'GET' === $method ) {
			$logged = self::audit( 'action_started', __( 'Reading Bhranti Task step limit.', 'bhranti' ), $context );
			if ( is_wp_error( $logged ) ) {
				return $logged;
			}
			$logged = self::audit( 'action_completed', __( 'Bhranti Task step limit retrieved.', 'bhranti' ), $context );
			return is_wp_error( $logged ) ? $logged : self::task_result( $old, '', false );
		}
		$config              = $old['config'];
		$config['max_steps'] = $args['max_steps'];
		$config              = self::validate( $config );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$context['action_id'] = 'task_' . wp_generate_uuid4();
		$snapshot             = array(
			'id'           => $context['action_id'],
			'requester_id' => (int) $requester_id,
			'actor_id'     => $context['actor_id'],
			'session_id'   => (int) $session_id,
			'expires'      => time() + 7 * DAY_IN_SECONDS,
			'kind'         => 'task_limit',
			'state'        => 'prepared',
			'prior_steps'  => $old['config']['max_steps'],
			'command'      => $command,
		);
		$snapshot_saved       = false;
		$before               = static function ( $current, $next ) use ( &$snapshot, &$snapshot_saved, $context ) {
			$snapshot['prior_steps']    = $current['config']['max_steps'];
			$snapshot['after_revision'] = $next['revision'];
			$snapshot['after_steps']    = $next['config']['max_steps'];
			$saved                      = self::task_snapshot( $context, $snapshot );
			$snapshot_saved             = true === $saved;
			return $saved;
		};
		$after                = static function () use ( &$snapshot, $context ) {
			$snapshot['state'] = 'completed';
			return self::task_snapshot( $context, $snapshot );
		};
		$original_user        = get_current_user_id();
		try {
			wp_set_current_user( $context['actor_id'] );
			$saved = self::persist( $config, $args['revision'], $context, $before, $after );
		} finally {
			wp_set_current_user( $original_user );
		}
		if ( is_wp_error( $saved ) ) {
			if ( $snapshot_saved ) {
				$snapshot['state'] = 'failed';
				self::task_snapshot( $context, $snapshot );
			}
			self::audit( 'action_failed', __( 'Bhranti Task step limit was not finalized. Read the setting before trying again.', 'bhranti' ), $context + array( 'code' => $saved->get_error_code() ) );
			return $saved;
		}
		return self::task_result( $saved, $context['action_id'], true );
	}

	/**
	 * Restore only this action's task limit while preserving all human configuration.
	 *
	 * @param string $action_id Owned snapshot identifier.
	 * @param int    $requester_id Human administrator.
	 * @param int    $session_id Owned conversation.
	 * @return array|WP_Error
	 */
	public static function task_rollback( $action_id, $requester_id, $session_id ) {
		$context = self::task_context( $requester_id, $session_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$context['action_id'] = $action_id;
		$context['method']    = 'POST';
		$context['recovery']  = true;
		$allowed              = self::task_authority( $context, true );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		wp_cache_delete( $session_id, 'post_meta' );
		$records  = get_post_meta( $session_id, 'bhranti_actions', true );
		$snapshot = is_array( $records ) ? ( $records[ $action_id ] ?? null ) : null;
		if ( ! is_array( $snapshot ) || 'task_limit' !== ( $snapshot['kind'] ?? '' ) || 'completed' !== ( $snapshot['state'] ?? '' ) || (int) ( $snapshot['requester_id'] ?? 0 ) !== (int) $requester_id || (int) ( $snapshot['session_id'] ?? 0 ) !== (int) $session_id ) {
			return self::error( 'bhranti_rollback_missing', __( 'This rollback is unavailable or has already been used.', 'bhranti' ), 404 );
		}
		if ( (int) ( $snapshot['expires'] ?? 0 ) < time() ) {
			return self::error( 'bhranti_rollback_expired', __( 'The seven-day rollback window has expired.', 'bhranti' ), 410 );
		}
		self::fresh_option( 'bhranti_settings' );
		$old = self::read();
		if ( is_wp_error( $old ) ) {
			return $old;
		}
		if ( ( $snapshot['after_revision'] ?? '' ) !== $old['revision'] || ( $snapshot['after_steps'] ?? null ) !== $old['config']['max_steps'] ) {
			self::audit( 'rollback_blocked', __( 'Settings changed after the task-limit action; Undo was blocked.', 'bhranti' ), $context );
			return self::error( 'bhranti_rollback_conflict', __( 'Bhranti settings changed after this action. Undo cannot overwrite those changes.', 'bhranti' ), 409 );
		}
		$config              = $old['config'];
		$config['max_steps'] = $snapshot['prior_steps'];
		$config              = self::validate( $config );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$rollback_started = false;
		$before           = static function () use ( &$snapshot, &$rollback_started, $context ) {
			wp_cache_delete( $context['session_id'], 'post_meta' );
			$records = get_post_meta( $context['session_id'], 'bhranti_actions', true );
			if ( ( $records[ $context['action_id'] ] ?? null ) !== $snapshot ) {
				return self::error( 'bhranti_rollback_conflict', __( 'The rollback record changed. Inspect the log before retrying.', 'bhranti' ), 409 );
			}
			$snapshot['state'] = 'rolling_back';
			$recorded          = self::task_snapshot( $context, $snapshot );
			$rollback_started  = true === $recorded;
			return $recorded;
		};
		$after            = static function () use ( &$snapshot, $context ) {
			$snapshot['state'] = 'rolled_back';
			return self::task_snapshot( $context, $snapshot );
		};
		$original_user    = get_current_user_id();
		try {
			wp_set_current_user( $context['actor_id'] );
			$saved = self::persist( $config, $old['revision'], $context, $before, $after );
		} finally {
			wp_set_current_user( $original_user );
		}
		if ( is_wp_error( $saved ) ) {
			if ( $rollback_started ) {
				self::fresh_option( 'bhranti_settings' );
				$current           = self::read();
				$snapshot['state'] = ! is_wp_error( $current ) && $current === $old ? 'completed' : 'failed';
				self::task_snapshot( $context, $snapshot );
			}
			self::audit( 'rollback_failed', __( 'The task-limit rollback was not finalized. Inspect Settings before retrying.', 'bhranti' ), $context + array( 'code' => $saved->get_error_code() ) );
			return $saved;
		}
		return self::task_result( $saved, $action_id, false, true );
	}

	/**
	 * Bind the reviewed operation to the current human and dedicated actor.
	 *
	 * @param int $requester_id Authenticated human.
	 * @param int $session_id Owned private conversation.
	 * @return array|WP_Error
	 */
	private static function task_context( $requester_id, $session_id ) {
		$post = get_post( $session_id );
		if ( get_current_user_id() !== (int) $requester_id || ! self::can_manage() || ! $post || 'bhranti_session' !== $post->post_type || 'private' !== $post->post_status || (int) $post->post_author !== (int) $requester_id ) {
			return self::error( 'bhranti_action_owner', __( 'This workspace action is not available to your account.', 'bhranti' ), 403 );
		}
		$actor_id = Bhranti_Actions::ensure_actor();
		if ( is_wp_error( $actor_id ) ) {
			return $actor_id;
		}
		return array(
			'requester_id' => (int) $requester_id,
			'actor_id'     => $actor_id,
			'session_id'   => (int) $session_id,
			'method'       => 'GET',
		);
	}

	/**
	 * Recheck both capabilities, ownership and live policy immediately before saving.
	 *
	 * @param array $context Internal identities.
	 * @param bool  $recovery Allow restoration after the policy is disabled.
	 * @return true|WP_Error
	 */
	private static function task_authority( $context, $recovery = false ) {
		clean_post_cache( $context['session_id'] );
		$post = get_post( $context['session_id'] );
		if ( ! user_can( $context['requester_id'], 'manage_options' ) || ! user_can( $context['actor_id'], 'manage_options' ) || ! $post || 'bhranti_session' !== $post->post_type || 'private' !== $post->post_status || (int) $post->post_author !== $context['requester_id'] ) {
			return self::error( 'bhranti_task_limit_permission', __( 'Both your account and Bhranti must be allowed to manage site settings.', 'bhranti' ), 403 );
		}
		return $recovery ? true : self::allows( 'manage_site_settings' );
	}

	/**
	 * Persist only small rollback metadata; prompts and Markdown are never copied.
	 *
	 * @param array $context Internal identities.
	 * @param array $snapshot Reviewed action state.
	 * @return true|WP_Error
	 */
	private static function task_snapshot( $context, $snapshot ) {
		$allowed = self::task_authority( $context, ! empty( $context['recovery'] ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		wp_cache_delete( $context['session_id'], 'post_meta' );
		$records                    = get_post_meta( $context['session_id'], 'bhranti_actions', true );
		$records                    = is_array( $records ) ? $records : array();
		$records[ $snapshot['id'] ] = $snapshot;
		if ( strlen( wp_json_encode( $records ) ) > 5 * MB_IN_BYTES ) {
			return self::error( 'bhranti_storage', __( 'This conversation’s rollback storage is full. Start a new chat.', 'bhranti' ), 500 );
		}
		update_post_meta( $context['session_id'], 'bhranti_actions', wp_slash( $records ) );
		wp_cache_delete( $context['session_id'], 'post_meta' );
		return get_post_meta( $context['session_id'], 'bhranti_actions', true ) === $records ? true : self::error( 'bhranti_storage', __( 'The rollback record could not be saved. No setting can be safely finalized.', 'bhranti' ), 500 );
	}

	/** Return the native Settings link without exposing other configuration. */
	public static function task_target() {
		return array(
			'id'    => 0,
			'type'  => 'bhranti_settings',
			'title' => __( 'Bhranti Settings', 'bhranti' ),
			'url'   => add_query_arg( 'page', 'bhranti', admin_url( 'admin.php' ) ),
		);
	}

	/**
	 * Project exactly the task limit and revision, never the full configuration.
	 *
	 * @param array  $stored Verified wrapper.
	 * @param string $action_id Snapshot ID, empty for reads.
	 * @param bool   $rollback_available Whether this action can be undone.
	 * @param bool   $restored Whether this result is an Undo.
	 * @return array
	 */
	private static function task_result( $stored, $action_id, $rollback_available, $restored = false ) {
		$limit = array(
			'max_steps' => $stored['config']['max_steps'],
			'revision'  => $stored['revision'],
		);
		/* translators: %d: Saved maximum number of task steps. */
		$summary = $action_id ? sprintf( $restored ? __( 'Task step limit restored to %d. Future tasks use this limit.', 'bhranti' ) : __( 'Task step limit saved as %d. Future tasks use this limit.', 'bhranti' ), $limit['max_steps'] ) : __( 'Bhranti Task step limit retrieved.', 'bhranti' );
		return array(
			'summary'            => $summary,
			'data'               => $limit,
			'task_limit'         => $limit,
			'target'             => self::task_target(),
			'action_id'          => $action_id,
			'rollback_available' => $rollback_available,
		);
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
	 * @param mixed $expected Wrapper written by this save.
	 * @return true|WP_Error
	 */
	private static function restore( $prior, $autoload, $expected ) {
		$current = self::fresh_option( 'bhranti_settings' );
		if ( $current !== $expected && $current !== $prior ) {
			return self::error( 'bhranti_settings_storage', __( 'Settings changed during finalization. Inspect the saved task limit and activity log before retrying.', 'bhranti' ), 500 );
		}
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
	 * Log metadata only, preserving explicit human and managed actor identities.
	 *
	 * @param string $type Event code.
	 * @param string $message Safe summary.
	 * @param array  $context Internal action metadata.
	 * @return int|WP_Error
	 */
	private static function audit( $type, $message, $context = array() ) {
		return Bhranti_Audit::record(
			$type,
			$message,
			$context['session_id'] ?? 0,
			array(
				'method'    => $context['method'] ?? 'POST',
				'path'      => $context ? '/bhranti/v1/task-limit' : '/bhranti/v1/settings',
				'action_id' => $context['action_id'] ?? '',
				'code'      => $context['code'] ?? '',
				'target'    => $context ? self::task_target() : array(),
			),
			$context['requester_id'] ?? get_current_user_id(),
			$context['actor_id'] ?? get_current_user_id()
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
