<?php
/**
 * Private workspace storage and the native WordPress AI integration.
 *
 * @package Bhranti
 */

use WordPress\AiClient\AiClient;
defined( 'ABSPATH' ) || exit;

/**
 * Administrators own conversations; reviewed actions run as the managed Bhranti user.
 */
final class Bhranti_Workspace {

	/**
	 * Register private storage, without exposing a public post type or REST collection.
	 */
	public static function register_storage() {
		register_post_type(
			'bhranti_session',
			array(
				'public'           => false,
				'show_ui'          => false,
				'show_in_rest'     => false,
				'can_export'       => false,
				'supports'         => array( 'title', 'author' ),
				'capability_type'  => 'post',
				'map_meta_cap'     => true,
				'delete_with_user' => true,
			)
		);
	}

	/**
	 * Add an independent WordPress administration menu.
	 */
	public static function register_menu() {
		add_menu_page( __( 'Bhranti Settings', 'bhranti' ), __( 'Bhranti', 'bhranti' ), 'manage_options', 'bhranti', array( __CLASS__, 'render' ), 'dashicons-format-chat', 58 );
		add_submenu_page( 'bhranti', __( 'Bhranti Settings', 'bhranti' ), __( 'Settings', 'bhranti' ), 'manage_options', 'bhranti', array( __CLASS__, 'render' ) );
		add_submenu_page( 'bhranti', __( 'Bhranti Logs', 'bhranti' ), __( 'Logs', 'bhranti' ), 'manage_options', 'bhranti-logs', array( __CLASS__, 'render_logs' ) );
	}

	/**
	 * Keep activity in its own native administration page.
	 */
	public static function render_logs() {
		Bhranti_Audit::render();
	}

	/**
	 * Render the mount point and preserve WordPress admin chrome.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><div id="bhranti-settings-app"></div><noscript><p>' . esc_html__( 'Enable JavaScript to edit Bhranti settings.', 'bhranti' ) . '</p></noscript></div>';
	}

	/**
	 * Register authenticated endpoints.
	 */
	public static function register_routes() {
		$permission = array( __CLASS__, 'can_access' );
		register_rest_route(
			'bhranti/v1',
			'/workspace',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'workspace' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			'bhranti/v1',
			'/sessions',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_session' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			'bhranti/v1',
			'/sessions/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_session' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_session' ),
					'permission_callback' => $permission,
				),
			)
		);
		register_rest_route(
			'bhranti/v1',
			'/sessions/(?P<id>\d+)/messages',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'send_message' ),
				'permission_callback' => $permission,
				'args'                => array(
					'agent_id' => array(
						'type'      => 'string',
						'default'   => 'bhranti',
						'minLength' => 1,
						'maxLength' => 64,
						'pattern'   => '^[a-z][a-z0-9_-]{0,63}$',
					),
					'context'  => array(
						'type'     => 'object',
						'required' => false,
					),
					'content'  => array(
						'required'          => true,
						'type'              => 'string',
						'minLength'         => 1,
						'maxLength'         => 12000,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Require an administrator; cookie requests also pass WordPress REST nonce checks.
	 *
	 * @return bool
	 */
	public static function can_access() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Honor bounded native AI request timeouts within this call's HTTP scope.
	 *
	 * Some local runtimes reset cURL's timeout after WordPress applies the
	 * request options. Keep its configured duration without changing transport,
	 * connection timeout, authentication, TLS or connector approval checks.
	 *
	 * @param callable $callback Native WordPress AI request.
	 * @return mixed Unmodified callback result.
	 */
	public static function ai_request( callable $callback ) {
		$timeout = static function ( $handle, $request ) {
			$duration = is_array( $request ) ? ( $request['timeout'] ?? null ) : null;
			if ( ! is_numeric( $duration ) || ! is_finite( (float) $duration ) || (float) $duration <= 0 || (float) $duration > 180 || ! function_exists( 'curl_setopt' ) || ! defined( 'CURLOPT_TIMEOUT_MS' ) ) {
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Adjust only WordPress's existing handle in its native HTTP hook; the AI Client still owns and authenticates the request.
			curl_setopt( $handle, CURLOPT_TIMEOUT_MS, (int) ceil( (float) $duration * 1000 ) );
		};
		add_action( 'http_api_curl', $timeout, PHP_INT_MAX, 2 );
		try {
			return $callback();
		} finally {
			remove_action( 'http_api_curl', $timeout, PHP_INT_MAX );
		}
	}

	/**
	 * Build the same small, non-sensitive summary shown in the interface.
	 *
	 * @return array
	 */
	public static function site_context() {
		global $wp_version;
		return array(
			'name'      => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			'url'       => home_url( '/' ),
			'wordpress' => $wp_version,
			'theme'     => wp_get_theme()->get( 'Name' ),
			'posts'     => (int) wp_count_posts( 'post' )->publish,
			'pages'     => (int) wp_count_posts( 'page' )->publish,
		);
	}

	/**
	 * Inspect local configuration only: visiting Bhranti must not contact providers.
	 *
	 * @return array
	 */
	public static function ai_status() {
		$available = false;
		if ( function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_get_connectors' ) && ( ! defined( 'WP_AI_SUPPORT' ) || WP_AI_SUPPORT ) ) {
			try {
				$registry = AiClient::defaultRegistry();
				foreach ( wp_get_connectors() as $id => $connector ) {
					if ( 'ai_provider' === $connector['type'] && $registry->hasProvider( $id ) && $registry->isProviderConfigured( $id ) ) {
						$available = true;
						break;
					}
				}
			} catch ( Throwable $error ) {
				$available = false;
			}
		}
		return array(
			'available' => $available,
			'enabled'   => $available,
		);
	}

	/**
	 * Return the current user's workspace.
	 *
	 * @return array
	 */
	public static function workspace() {
		$user     = wp_get_current_user();
		$sessions = get_posts(
			array(
				'post_type'      => 'bhranti_session',
				'post_status'    => 'private',
				'author'         => $user->ID,
				'posts_per_page' => 50,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		return array(
			'site'       => self::site_context(),
			'user'       => array(
				'name'     => $user->display_name,
				'initials' => strtoupper( mb_substr( $user->display_name, 0, 1 ) ),
			),
			'ai'         => self::ai_status(),
			'agents'     => Bhranti_Settings::summaries(),
			'sessions'   => array_map( array( __CLASS__, 'session_summary' ), $sessions ),
			'activity'   => self::activity(),
			'actor'      => self::actor_summary(),
			'guardrails' => array(
				'mode'        => 'reviewed_actions',
				'description' => __( 'Reviewed WordPress API actions run as Bhranti with your permissions. Deletions require confirmation. Supported changes include conflict-checked rollback.', 'bhranti' ),
			),
		);
	}

	/**
	 * Summarize a conversation without loading its messages.
	 *
	 * @param WP_Post $post Session.
	 *
	 * @return array
	 */
	public static function session_summary( $post ) {
		return array(
			'id'      => $post->ID,
			'title'   => $post->post_title,
			'updated' => get_post_datetime( $post, 'modified', 'gmt' )->format( 'c' ),
		);
	}

	/**
	 * Resolve a session owned by the current administrator.
	 *
	 * @param int $id Session ID.
	 *
	 * @return WP_Post|WP_Error
	 */
	public static function owned_session( $id ) {
		$post = get_post( absint( $id ) );
		if ( ! $post || 'bhranti_session' !== $post->post_type || 'private' !== $post->post_status || get_current_user_id() !== (int) $post->post_author ) {
			return new WP_Error( 'bhranti_session_missing', __( 'This conversation could not be found.', 'bhranti' ), array( 'status' => 404 ) );
		}
		return $post;
	}

	/**
	 * Read stored JSON as data.
	 *
	 * @param WP_Post $post Session.
	 *
	 * @return array
	 */
	public static function session_data( $post ) {
		$data             = self::session_summary( $post );
		$data['created']  = get_post_datetime( $post, 'date', 'gmt' )->format( 'c' );
		$messages         = get_post_meta( $post->ID, 'bhranti_messages', true );
		$data['messages'] = is_array( $messages ) ? $messages : array();
		$run              = get_post_meta( $post->ID, 'bhranti_run', true );
		$resolved         = is_array( $run['resolved_error_ids'] ?? null ) ? array_fill_keys( array_filter( $run['resolved_error_ids'], 'is_string' ), true ) : array();
		foreach ( $data['messages'] as &$message ) {
			if ( 'assistant' === ( $message['role'] ?? '' ) && is_string( $message['content'] ?? null ) ) {
				$message['rendered'] = Bhranti_Chat::render( $message['content'] );
			}
			if ( 'error' === ( $message['role'] ?? '' ) && isset( $resolved[ $message['id'] ?? '' ] ) ) {
				$message['resolved'] = true;
			}
		}
		unset( $message );
		return array_merge( $data, Bhranti_Agent::state( $post->ID ) );
	}

	/**
	 * Read an owned conversation.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array|WP_Error
	 */
	public static function get_session( $request ) {
		$post = self::owned_session( $request['id'] );
		return is_wp_error( $post ) ? $post : self::session_data( $post );
	}

	/**
	 * Serialize mutations from multiple browser tabs.
	 *
	 * @param callable $callback Operation.
	 *
	 * @return mixed
	 */
	public static function mutate( $callback ) {
		$key = 'bhranti_lock_' . get_current_user_id();
		// A stale lock can only outlive a request after an interrupted PHP process.
		$lock = (int) get_option( $key, 0 );
		if ( $lock && $lock < time() - 600 ) {
			delete_option( $key );
		}
		if ( ! add_option( $key, time(), '', false ) ) {
			return new WP_Error( 'bhranti_busy', __( 'Another Bhranti request is running. Wait a moment and try again.', 'bhranti' ), array( 'status' => 409 ) );
		}
		try {
			return $callback();
		} finally {
			delete_option( $key );
		}
	}

	/**
	 * Create a private conversation.
	 *
	 * @return array|WP_Error
	 */
	public static function create_session() {
		return self::mutate(
			static function () {
				$id = wp_insert_post(
					array(
						'post_type'   => 'bhranti_session',
						'post_status' => 'private',
						'post_author' => get_current_user_id(),
						'post_title'  => __( 'New conversation', 'bhranti' ),
					),
					true
				);
				if ( is_wp_error( $id ) ) {
					return new WP_Error( 'bhranti_storage', __( 'WordPress could not save the conversation. Try again.', 'bhranti' ), array( 'status' => 500 ) );
				}
				$logged = self::log( 'session_created', __( 'Conversation started.', 'bhranti' ), $id );
				if ( is_wp_error( $logged ) ) {
					wp_delete_post( $id, true );
					return $logged;
				}
				return self::session_data( get_post( $id ) );
			}
		);
	}

	/**
	 * Remove only Bhranti's own conversation data.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array|WP_Error
	 */
	public static function delete_session( $request ) {
		return self::mutate(
			static function () use ( $request ) {
				$post = self::owned_session( $request['id'] );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				$logged = self::log( 'session_delete_started', __( 'Conversation deletion requested; its rollback records will also be removed.', 'bhranti' ), $post->ID );
				if ( is_wp_error( $logged ) ) {
					return $logged;
				}
				wp_delete_post( $post->ID, true );
				if ( get_post( $post->ID ) ) {
					return new WP_Error( 'bhranti_storage', __( 'WordPress could not delete the conversation. Try again.', 'bhranti' ), array( 'status' => 500 ) );
				}
				self::log( 'session_deleted', __( 'Conversation deleted.', 'bhranti' ), $post->ID );
				return array( 'deleted' => true );
			}
		);
	}

	/**
	 * Append a message and start the authenticated native AI action loop.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array|WP_Error
	 */
	public static function send_message( $request ) {
		return Bhranti_Agent::start( $request );
	}

	/**
	 * Construct a display message.
	 *
	 * @param string $role Message role.
	 *
	 * @param string $content Text.
	 *
	 * @return array
	 */
	public static function message( $role, $content ) {
		return array(
			'id'      => wp_generate_uuid4(),
			'role'    => $role,
			'content' => $content,
			'created' => gmdate( 'c' ),
		);
	}

	/**
	 * Preserve message text through the metadata API.
	 *
	 * @param int    $id Session.
	 *
	 * @param array  $messages History.
	 *
	 * @param string $title Title.
	 *
	 * @return int|WP_Error
	 */
	public static function save_messages( $id, $messages, $title ) {
		foreach ( $messages as &$message ) {
			// Display HTML is derived on read; preserve only the original response in storage.
			unset( $message['rendered'], $message['resolved'] );
		}
		unset( $message );
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'         => $id,
					'post_title' => sanitize_text_field( $title ),
				)
			),
			true
		);
		if ( ! is_wp_error( $result ) ) {
			update_post_meta( $id, 'bhranti_messages', wp_slash( $messages ) );
			if ( get_post_meta( $id, 'bhranti_messages', true ) === $messages ) {
				return $result;
			}
		}
		return new WP_Error( 'bhranti_storage', __( 'WordPress could not save the message. Try again.', 'bhranti' ), array( 'status' => 500 ) );
	}

	/**
	 * Fetch the current user's latest page of durable site activity.
	 *
	 * @return array
	 */
	private static function activity() {
		return Bhranti_Audit::events( get_current_user_id() );
	}

	/**
	 * Log metadata without duplicating prompt text or provider error payloads.
	 *
	 * @param string $type Event.
	 *
	 * @param string $message Summary.
	 *
	 * @param int    $session_id Session.
	 */
	private static function log( $type, $message, $session_id = 0 ) {
		return Bhranti_Audit::record( $type, $message, $session_id );
	}

	/** Return the managed account without creating it on a read request.
	 *
	 * @return array
	 */
	private static function actor_summary() {
		$id   = (int) get_option( 'bhranti_actor_id' );
		$user = get_userdata( $id );
		return array(
			'id'   => $id,
			'name' => $user ? $user->display_name : __( 'Bhranti', 'bhranti' ),
		);
	}

	/**
	 * Supply a privacy-policy disclosure through the core helper.
	 */
	public static function privacy_policy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Bhranti', '<p>' . esc_html__( 'Bhranti stores private administrator conversations, run state, rollback snapshots, activity records, and shared site-wide prompt/agent/skill settings on this WordPress site. Shared settings remain until replaced or uninstalled; run configuration snapshots are included in conversation export and erasure. Activity records identify the requesting and acting users. Bhranti uses a dedicated managed account for reviewed WordPress API actions. Administrator action switches control permitted operations. Native plugin uninstall is opt-in and requires human approval; it may permanently remove plugin data that reinstalling does not recover. AI providers are configured exclusively in WordPress Settings → Connectors. Sending a message shares that message, up to 12 recent conversation messages, basic site information, current page and selected block context, attached rendered-page HTML and visible text, images or screenshots the administrator explicitly chooses to attach, inspected public design-reference pages, selected system/agent/skill instructions, image-generation prompts, and the site content and tool results needed for the task through the WordPress AI Client with the configured provider. A chosen image remains attached to its original message during the same task’s question-and-answer intake and is shared with the provider for that continuation, unless removed or replaced. It is not automatically carried into a completed task’s next request or a new conversation. Retained images are part of private run export and erasure, not public Media Library uploads. Provider terms and privacy policies apply. Generated images are saved as normal Media Library attachments; restoring page content does not remove those images. Credentials and security settings are excluded from tools and logs. Bhranti has no telemetry or connector credentials. Native WordPress scheduling continues submitted tasks in the background until completed, stopped or awaiting human input or approval. Per-tab editor protection stores only a random tab identifier, active/unsaved flags and expiry; it is included in native privacy cleanup. Administrators can delete conversations; doing so also removes their rollback snapshots. WordPress personal-data tools include Bhranti data. Uninstall removes workspace data but preserves site content created or edited through the agent.', 'bhranti' ) . '</p>' );
		}
	}
}
