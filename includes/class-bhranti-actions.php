<?php
/**
 * Reviewed core WordPress actions with a managed actor and durable rollback.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Intersect the requesting administrator's permissions with a dedicated actor.
 */
final class Bhranti_Actions {

	/**
	 * Block interactive authentication for managed service accounts.
	 */
	public static function register() {
		add_filter( 'authenticate', array( __CLASS__, 'block_login' ), 100, 1 );
		add_filter( 'determine_current_user', array( __CLASS__, 'block_authenticated_actor' ), 100, 1 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'application_passwords' ), 100, 2 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'password_reset' ), 100, 2 );
	}

	/**
	 * Reject managed actors after normal WordPress authentication.
	 *
	 * @param WP_User|WP_Error|null $user Authentication result.
	 * @return WP_User|WP_Error|null
	 */
	public static function block_login( $user ) {
		if ( $user instanceof WP_User && get_user_meta( $user->ID, 'bhranti_managed_actor', true ) ) {
			return new WP_Error( 'bhranti_actor_login', __( 'Bhranti is a managed service account and cannot sign in.', 'bhranti' ) );
		}
		return $user;
	}

	/**
	 * Reject existing cookies and alternative authentication for managed actors.
	 *
	 * @param int|false $user_id Authenticated user ID.
	 * @return int|false
	 */
	public static function block_authenticated_actor( $user_id ) {
		return $user_id && get_user_meta( $user_id, 'bhranti_managed_actor', true ) ? false : $user_id;
	}

	/**
	 * Disable application passwords for managed actors.
	 *
	 * @param bool    $available Current availability.
	 * @param WP_User $user      Account.
	 * @return bool
	 */
	public static function application_passwords( $available, $user ) {
		return get_user_meta( $user->ID, 'bhranti_managed_actor', true ) ? false : $available;
	}

	/**
	 * Disable password resets for managed actors.
	 *
	 * @param bool|WP_Error $allowed Current permission.
	 * @param int           $user_id Account ID.
	 * @return bool|WP_Error
	 */
	public static function password_reset( $allowed, $user_id ) {
		return get_user_meta( $user_id, 'bhranti_managed_actor', true ) ? false : $allowed;
	}

	/**
	 * Create or validate a site-specific actor without claiming an existing account.
	 *
	 * @return int|WP_Error Managed actor ID.
	 */
	public static function ensure_actor() {
		$lock = array(
			'token'   => wp_generate_uuid4(),
			'expires' => time() + 60,
		);
		if ( ! add_option( 'bhranti_actor_setup_lock', $lock, '', false ) ) {
			$previous = get_option( 'bhranti_actor_setup_lock' );
			if ( is_array( $previous ) && (int) ( $previous['expires'] ?? 0 ) < time() && get_option( 'bhranti_actor_setup_lock' ) === $previous ) {
				delete_option( 'bhranti_actor_setup_lock' );
			}
			if ( ! add_option( 'bhranti_actor_setup_lock', $lock, '', false ) ) {
				return self::error( 'bhranti_actor_busy', __( 'Another request is preparing Bhranti. Try again shortly.', 'bhranti' ), 409 );
			}
		}
		try {
			return self::prepare_actor();
		} finally {
			if ( get_option( 'bhranti_actor_setup_lock' ) === $lock ) {
				delete_option( 'bhranti_actor_setup_lock' );
			}
		}
	}

	/**
	 * Provision while holding the site-wide actor setup lock.
	 *
	 * @return int|WP_Error Managed actor ID.
	 */
	private static function prepare_actor() {
		$capabilities  = array_fill_keys(
			array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'edit_private_pages', 'publish_pages', 'read_private_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages', 'manage_categories', 'moderate_comments', 'upload_files', 'edit_theme_options', 'manage_options', 'list_users' ),
			true
		);
		$service_email = 'bhranti+' . get_current_blog_id() . '@example.invalid';
		$actor_id      = absint( get_option( 'bhranti_actor_id' ) );
		$actor         = $actor_id ? get_userdata( $actor_id ) : false;
		if ( $actor_id && ( ! $actor || (int) get_user_meta( $actor_id, 'bhranti_managed_actor', true ) !== get_current_blog_id() || array( 'bhranti_agent' ) !== $actor->roles || array( 'bhranti_agent' => true ) !== $actor->caps ) ) {
			return self::error( 'bhranti_actor_invalid', __( 'The managed Bhranti account needs administrator review.', 'bhranti' ), 409 );
		}
		$role = get_role( 'bhranti_agent' );
		if ( $role && ! get_option( 'bhranti_role_owned' ) ) {
			return self::error( 'bhranti_actor_role', __( 'An existing role uses the Bhranti role name. It has not been changed.', 'bhranti' ), 409 );
		}
		if ( $actor && $role && $role->capabilities === $capabilities && $actor->user_email ) {
			return $actor_id;
		}
		if ( ! current_user_can( 'create_users' ) || ! current_user_can( 'manage_options' ) ) {
			return self::error( 'bhranti_actor_permission', __( 'An administrator who can create users must initialize Bhranti.', 'bhranti' ), 403 );
		}
		$logged = self::audit( 'actor_started', __( 'Preparing the managed Bhranti account.', 'bhranti' ), 0, array(), get_current_user_id(), $actor_id );
		if ( is_wp_error( $logged ) ) {
			return $logged;
		}
		if ( ! $role ) {
			if ( ! add_option( 'bhranti_role_owned', true, '', false ) ) {
				return self::error( 'bhranti_actor_storage', __( 'WordPress could not reserve the Bhranti role.', 'bhranti' ), 500 );
			}
			$role = add_role( 'bhranti_agent', __( 'Bhranti agent', 'bhranti' ), $capabilities );
		}
		if ( ! $role ) {
			return self::error( 'bhranti_actor_role', __( 'WordPress could not create the Bhranti role.', 'bhranti' ), 500 );
		}
		foreach ( array_keys( $role->capabilities ) as $capability ) {
			if ( ! isset( $capabilities[ $capability ] ) ) {
				$role->remove_cap( $capability );
			}
		}
		foreach ( $capabilities as $capability => $granted ) {
			$role->add_cap( $capability, $granted );
		}
		if ( $actor && ! $actor->user_email ) {
			$updated = wp_update_user(
				array(
					'ID'         => $actor_id,
					'user_email' => $service_email,
				)
			);
			if ( is_wp_error( $updated ) || get_userdata( $actor_id )->user_email !== $service_email ) {
				return self::error( 'bhranti_actor_storage', __( 'WordPress could not save the managed comment-author identity.', 'bhranti' ), 500 );
			}
		}
		if ( ! $actor ) {
			$login = 'bhranti';
			if ( username_exists( $login ) ) {
				$login = 'bhranti_agent_' . get_current_blog_id();
			}
			if ( username_exists( $login ) ) {
				$login .= '_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
			}
			$actor_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 64, true, true ),
					'user_email'   => $service_email,
					'display_name' => 'Bhranti',
					'role'         => 'bhranti_agent',
					'meta_input'   => array( 'bhranti_managed_actor' => get_current_blog_id() ),
				)
			);
			if ( is_wp_error( $actor_id ) ) {
				self::audit( 'actor_failed', __( 'The managed Bhranti account could not be created.', 'bhranti' ), 0, array( 'code' => $actor_id->get_error_code() ), get_current_user_id(), 0 );
				return self::error( 'bhranti_actor_create', __( 'WordPress could not create the managed Bhranti account.', 'bhranti' ), 500 );
			}
			update_option( 'bhranti_actor_id', $actor_id, false );
			if ( (int) get_option( 'bhranti_actor_id' ) !== $actor_id || (int) get_user_meta( $actor_id, 'bhranti_managed_actor', true ) !== get_current_blog_id() ) {
				return self::error( 'bhranti_actor_storage', __( 'WordPress could not save the managed account identity.', 'bhranti' ), 500 );
			}
		}
		self::audit( 'actor_completed', __( 'Managed Bhranti account ready.', 'bhranti' ), 0, array(), get_current_user_id(), $actor_id );
		return $actor_id;
	}

	/**
	 * Describe tools for the native AI connector's agent prompt.
	 *
	 * @return array Tool definitions keyed by tool name.
	 */
	public static function catalog() {
		$catalog                               = array(
			'wp_request'  => array(
				'description' => 'Use reviewed core /wp/v2 routes. GET discovers content, types, taxonomies, comments, public users, installed themes, templates, styles and safe settings. GET /wp/v2/themes lists installed themes and global_styles_id for an existing readable user-style record (null when absent or unavailable); theme writes are unavailable. There is no /wp/v2/global-styles collection. Read active theme defaults at /wp/v2/global-styles/themes/{stylesheet}; read/update existing user overrides at /wp/v2/global-styles/{global_styles_id}. Never guess a style ID or create one during discovery. GET /wp/v2/block-types or /wp/v2/block-types/namespace lists up to 200 compact installed block names; GET /wp/v2/block-types/namespace/name reads its native attributes/supports without scripts, asset URLs or rendering. Use exact discovered block names for complex layouts; block metadata is read-only. POST/PUT/PATCH creates or updates content and comments, updates media descriptions, existing terms/menus, published templates, navigation, styles and safe settings. DELETE only trashes reversible content/comments after human confirmation. No arbitrary routes, user changes, secrets, files or core changes. Use plugin_request for reviewed plugin management and generate_image for native AI image creation. Use GET to discover IDs/schema before writing. New taxonomy terms and classic menus, arbitrary media upload, template source resets, deletion of existing templates and permanent deletes are unsupported. Examples: {"method":"GET","path":"/wp/v2/types"}, {"method":"GET","path":"/wp/v2/taxonomies"}, {"method":"POST","path":"/wp/v2/pages","params":{"title":"New page","content":"<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->","status":"draft"}}. Do not send OPTIONS requests.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'method' => array(
							'type' => 'string',
							'enum' => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
						),
						'path'   => array(
							'type'        => 'string',
							'description' => 'Relative core REST route beginning /wp/v2/.',
						),
						'params' => array(
							'type'        => 'object',
							'description' => 'Only actual core route parameters. No author changes, password, meta, force, source, _embed or credential fields. GET may filter content by author ID and use _fields as a comma-separated string or array of up to 50 field paths, for example id,title.raw,status. Field selection only reduces already reviewed response data; it is unavailable on writes. Collections are limited to 20 items.',
						),
					),
					'required'             => array( 'method', 'path' ),
					'additionalProperties' => false,
				),
			),
			'site_search' => array(
				'description' => 'Search WordPress content or terms by text, using the native core search endpoint.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'   => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'type'     => array(
							'type' => 'string',
							'enum' => array( 'post', 'term', 'post-format' ),
						),
						'subtype'  => array( 'type' => 'string' ),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 20,
						),
					),
					'required'             => array( 'search' ),
					'additionalProperties' => false,
				),
			),
			'open_item'   => array(
				'description' => 'Read an existing item and return its native WordPress link. Pass its reviewed /wp/v2 item path.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array( 'path' => array( 'type' => 'string' ) ),
					'required'             => array( 'path' ),
					'additionalProperties' => false,
				),
			),
		);
		$catalog['wp_request']['description'] .= ' To recover earlier wording, GET /wp/v2/posts/ID/revisions or /wp/v2/pages/ID/revisions lists up to 20 saved revision records; GET that path plus /REVISION_ID reads its original raw title/content/excerpt. These are read-only, exclude autosaves and pin the parent in the URL. Discover revisions rather than asking the human to paste earlier text. Compare the requested earlier section with current saved content, then use the existing current-revision content_patch to restore only that section; never overwrite unrelated later changes with an entire old page.';
		if ( class_exists( 'Bhranti_Content_Patch' ) ) {
			$catalog['wp_request']['parameters']['properties']['content_patch'] = Bhranti_Content_Patch::schema();
		}
		return $catalog + ( class_exists( 'Bhranti_Task' ) ? Bhranti_Task::catalog() : array() ) + ( class_exists( 'Bhranti_Skills' ) ? Bhranti_Skills::catalog() : array() ) + ( class_exists( 'Bhranti_Inspect' ) ? Bhranti_Inspect::catalog() : array() ) + ( class_exists( 'Bhranti_Forms' ) ? Bhranti_Forms::catalog() : array() ) + Bhranti_Settings::task_catalog() + Bhranti_Commerce::catalog() + Bhranti_References::catalog() + ( class_exists( 'Bhranti_Memory' ) ? Bhranti_Memory::catalog() : array() );
	}

	/**
	 * Execute a reviewed command; only the authenticated human flow may confirm.
	 *
	 * @param array  $command      Tool and argument object.
	 * @param int    $requester_id Requesting administrator.
	 * @param int    $session_id   Owned workspace session.
	 * @param bool   $confirmed    Explicit human confirmation from the REST handler.
	 * @param string $expected_fingerprint State reviewed by the human confirmation flow.
	 * @return array|WP_Error Result or confirmation proposal.
	 */
	public static function execute( $command, $requester_id, $session_id, $confirmed = false, $expected_fingerprint = '' ) {
		$owner = self::owner( $requester_id, $session_id );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		if ( is_array( $command ) && in_array( $command['tool'] ?? '', array( 'bhranti_task', 'bhranti_skill', 'bhranti_inspect' ), true ) ) {
			$actor_id = self::ensure_actor();
			if ( is_wp_error( $actor_id ) ) {
				return $actor_id;
			}
			if ( array_diff( array_keys( $command ), array( 'tool', 'args' ) ) || ! user_can( $requester_id, 'manage_options' ) || ! user_can( $actor_id, 'manage_options' ) || is_wp_error( rest_validate_value_from_schema( $command['args'] ?? null, self::catalog()[ $command['tool'] ]['parameters'], 'args' ) ) ) {
				return self::error( 'bhranti_action_blocked', __( 'Use an available specialist tool with its documented fields.', 'bhranti' ), 403 );
			}
			if ( 'bhranti_task' === $command['tool'] ) {
				$result = Bhranti_Task::execute( $command, $requester_id, $actor_id, $session_id );
				return is_wp_error( $result ) ? $result : $result + array( 'metadata_only' => true );
			}
			if ( 'bhranti_inspect' === $command['tool'] ) {
				$result = Bhranti_Inspect::execute( $command, $requester_id, $session_id );
				return is_wp_error( $result ) ? $result : $result + array( 'metadata_only' => 'content' !== $command['args']['resource'] );
			}
			$details = array(
				'tool' => 'bhranti_skill',
				'name' => $command['args']['name'],
			);
			$logged  = self::audit( 'action_started', __( 'Loading bundled specialist guidance.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
			if ( is_wp_error( $logged ) ) {
				return $logged;
			}
			$result = Bhranti_Skills::read( $command['args']['name'] );
			$logged = self::audit( is_wp_error( $result ) ? 'action_failed' : 'action_completed', __( 'Bundled guidance lookup finished.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
			if ( is_wp_error( $logged ) ) {
				return $logged;
			}
			return is_wp_error( $result ) ? $result : array(
				'summary'            => __( 'Specialist WordPress guidance loaded.', 'bhranti' ),
				'data'               => $result,
				'metadata_only'      => true,
				'rollback_available' => false,
			);
		}
		if ( is_array( $command ) && in_array( $command['tool'] ?? '', array( 'plugin_request', 'plugin_search' ), true ) ) {
			return Bhranti_Plugins::execute( $command, $requester_id, $session_id, $confirmed, $expected_fingerprint );
		}
		if ( is_array( $command ) && 'bhranti_task_limit' === ( $command['tool'] ?? '' ) ) {
			return Bhranti_Settings::task_execute( $command, $requester_id, $session_id );
		}
		if ( is_array( $command ) && 'commerce_request' === ( $command['tool'] ?? '' ) ) {
			return Bhranti_Commerce::execute( $command, $requester_id, $session_id, $confirmed, $expected_fingerprint );
		}
		if ( is_array( $command ) && 'form_request' === ( $command['tool'] ?? '' ) ) {
			return Bhranti_Forms::execute( $command, $requester_id, $session_id );
		}
		if ( is_array( $command ) && 'generate_image' === ( $command['tool'] ?? '' ) ) {
			return Bhranti_Media::execute( $command, $requester_id, $session_id );
		}
		if ( is_array( $command ) && 'bhranti_memory' === ( $command['tool'] ?? '' ) ) {
			return Bhranti_Memory::execute( $command, $requester_id, $session_id );
		}
		if ( is_array( $command ) && 'reference_page' === ( $command['tool'] ?? '' ) ) {
			return Bhranti_References::execute( $command, $requester_id, $session_id );
		}
		$actor_id = self::ensure_actor();
		if ( is_wp_error( $actor_id ) ) {
			return $actor_id;
		}
		$review = self::review( $command, $requester_id, $actor_id );
		if ( is_wp_error( $review ) ) {
			self::audit( 'action_blocked', __( 'A tool request was blocked by Bhranti guardrails.', 'bhranti' ), $session_id, array( 'code' => $review->get_error_code() ), $requester_id, $actor_id );
			return $review;
		}
		$command = $review['command'];
		$method  = $command['args']['method'];
		$path    = $command['args']['path'];
		$details = array(
			'method' => $method,
			'path'   => $path,
		);
		if ( 'GET' === $method ) {
			$logged = self::audit( 'action_started', __( 'Reading WordPress information.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
			if ( is_wp_error( $logged ) ) {
				return $logged;
			}
			if ( 'WP_REST_Block_Types_Controller' === $review['class'] ) {
				// Metadata reads must not evaluate a block's optional variation callback.
				$attributes = $review['request']->get_attributes();
				$review['request']->set_param( '_fields', self::block_fields( 'get_items' === $attributes['callback'][1] ) );
			} elseif ( 'WP_REST_Themes_Controller' === $review['class'] ) {
				// Native theme links create a global-styles post when none exists.
				$review['request']->set_param( '_fields', Bhranti_Site::theme_fields() );
			} elseif ( 'WP_REST_Revisions_Controller' === $review['class'] ) {
				$attributes = $review['request']->get_attributes();
				$review['request']->set_param( 'context', 'edit' );
				$review['request']->set_param( '_fields', self::revision_fields( 'get_items' === $attributes['callback'][1] ) );
			}
			$data = self::dispatch( $review['request'], $actor_id );
			if ( ! is_wp_error( $data ) && 'WP_REST_Revisions_Controller' === $review['class'] ) {
				$data = self::revision_data( $data, $review['request']->get_url_params() );
			}
			if ( is_wp_error( $data ) ) {
				self::audit( 'action_failed', __( 'WordPress could not complete the read request.', 'bhranti' ), $session_id, $details + array( 'code' => $data->get_error_code() ), $requester_id, $actor_id );
				return $data;
			}
			$target = self::target( $review, $data );
			self::audit( 'action_completed', __( 'WordPress information retrieved.', 'bhranti' ), $session_id, $details + array( 'target' => $target ), $requester_id, $actor_id );
			$data = 'WP_REST_Themes_Controller' === $review['class'] ? Bhranti_Site::theme_data( $data, $requester_id, $actor_id ) : self::public_data( $data, $review['class'] );
			if ( $review['fields'] ) {
				$projection = new WP_REST_Request( 'GET' );
				$projection->set_query_params( array( '_fields' => $review['fields'] ) );
				$data = rest_filter_response_fields( new WP_REST_Response( $data ), rest_get_server(), $projection )->get_data();
			}
			return array(
				'summary'            => __( 'WordPress information retrieved.', 'bhranti' ),
				'data'               => $data,
				'target'             => $target,
				'action_id'          => '',
				'rollback_available' => false,
			);
		}

		$policy = self::policy( $review );
		if ( is_wp_error( $policy ) ) {
			self::audit( 'action_blocked', $policy->get_error_message(), $session_id, $details, $requester_id, $actor_id );
			return $policy;
		}
		$before = array();
		if ( ! $review['create'] ) {
			$before = self::read_item( $path, $requester_id, $actor_id );
			if ( is_wp_error( $before ) ) {
				return $before;
			}
		}
		$policy = self::policy( $review, $before );
		if ( is_wp_error( $policy ) ) {
			self::audit( 'action_blocked', $policy->get_error_message(), $session_id, $details, $requester_id, $actor_id );
			return $policy;
		}
		$content_patch = isset( $command['args']['content_patch'] );
		if ( $content_patch ) {
			if ( ! self::object_id( $review, $before ) ) {
				return self::error( 'bhranti_content_patch_invalid', __( 'Save this template as a native database item before using bounded content edits.', 'bhranti' ), 409 );
			}
			$patch_revision = $command['args']['content_patch']['revision'];
			$content        = Bhranti_Content_Patch::apply( self::raw_value( $before['content'] ?? null ), $command['args']['content_patch'] );
			if ( is_wp_error( $content ) ) {
				self::audit( 'action_blocked', $content->get_error_message(), $session_id, $details + array( 'code' => $content->get_error_code() ), $requester_id, $actor_id );
				return $content;
			}
			// The harness patch never enters native request parameters or snapshots.
			$native = array(
				'tool' => 'wp_request',
				'args' => array(
					'method' => 'POST',
					'path'   => $path,
					'params' => array( 'content' => $content ),
				),
			);
			$review = self::review( $native, $requester_id, $actor_id );
			if ( is_wp_error( $review ) ) {
				self::audit( 'action_blocked', $review->get_error_message(), $session_id, $details + array( 'code' => $review->get_error_code() ), $requester_id, $actor_id );
				return $review;
			}
			$command = $review['command'];
		}
		if ( class_exists( 'Bhranti_Content_Quality' ) ) {
			foreach ( array( 'content', 'excerpt', 'caption', 'description' ) as $field ) {
				if ( isset( $command['args']['params'][ $field ] ) && is_string( $command['args']['params'][ $field ] ) ) {
					$quality = Bhranti_Content_Quality::validate( $command['args']['params'][ $field ], self::raw_value( $before[ $field ] ?? '' ) );
					if ( is_wp_error( $quality ) ) {
						self::audit( 'action_blocked', __( 'New content needs authored-content corrections before saving.', 'bhranti' ), $session_id, $details + array( 'code' => $quality->get_error_code() ), $requester_id, $actor_id );
						return $quality;
					}
				}
			}
		}
		$preserved = self::preserves_existing_content( $review, $before, $actor_id );
		if ( is_wp_error( $preserved ) ) {
			self::audit(
				'action_blocked',
				__( 'The actor cannot preserve this item’s existing markup.', 'bhranti' ),
				$session_id,
				$details + array(
					'code'   => $preserved->get_error_code(),
					'target' => self::target( $review, $before ),
				),
				$requester_id,
				$actor_id
			);
			return $preserved;
		}
		$target      = self::target( $review, $before );
		$aux         = self::auxiliary( $review, $command['args']['params'] );
		$fingerprint = self::fingerprint( $review, $before, $aux );
		if ( $content_patch ) {
			// fingerprint() just loaded this native object afresh. Bind that same
			// baseline to the source used for expansion, before accepting its hash.
			$source = get_post( self::object_id( $review, $before ) );
			if ( ! $source || ! hash_equals( $patch_revision, hash( 'sha256', $source->post_content ) ) ) {
				self::audit( 'action_blocked', __( 'The saved content changed before the section could be saved.', 'bhranti' ), $session_id, $details + array( 'target' => $target ), $requester_id, $actor_id );
				return self::error( 'bhranti_content_patch_conflict', __( 'The saved content changed. Inspect its current full revision before preparing another section.', 'bhranti' ), 409 );
			}
		}
		if ( $expected_fingerprint && ! hash_equals( $expected_fingerprint, $fingerprint ) ) {
			self::audit( 'action_blocked', __( 'The item changed after the action was reviewed.', 'bhranti' ), $session_id, $details + array( 'target' => $target ), $requester_id, $actor_id );
			return self::error( 'bhranti_rollback_conflict', __( 'The item changed after you reviewed it. Review the action again.', 'bhranti' ), 409 );
		}
		if ( 'DELETE' === $method ) {
			$trashable = self::trashable( $review, $before );
			if ( is_wp_error( $trashable ) ) {
				self::audit( 'action_blocked', __( 'Deletion was blocked because this object cannot be restored.', 'bhranti' ), $session_id, $details + array( 'target' => $target ), $requester_id, $actor_id );
				return $trashable;
			}
			if ( ! $confirmed ) {
				self::audit( 'action_blocked', __( 'Moving an item to Trash requires confirmation.', 'bhranti' ), $session_id, $details + array( 'target' => $target ), $requester_id, $actor_id );
				return array(
					'confirmation_required' => true,
					'command'               => $command,
					'summary'               => __( 'Move this item to Trash? You can restore it using the action log.', 'bhranti' ),
					'target'                => $target,
					'fingerprint'           => $fingerprint,
				);
			}
		}
		$kind = $review['create'] || ( 'WP_REST_Templates_Controller' === $review['class'] && empty( $before['wp_id'] ) ) ? 'create' : ( 'DELETE' === $method ? 'trash' : 'update' );
		if ( 'create' === $kind && EMPTY_TRASH_DAYS <= 0 ) {
			return self::error( 'bhranti_action_blocked', __( 'Enable WordPress Trash before creating items with rollback.', 'bhranti' ), 409 );
		}
		if ( 'WP_REST_Templates_Controller' === $review['class'] && ( ( ! empty( $before['wp_id'] ) && 'publish' !== ( $before['status'] ?? '' ) ) || ( isset( $command['args']['params']['status'] ) && 'publish' !== $command['args']['params']['status'] ) ) ) {
			return self::error( 'bhranti_action_blocked', __( 'WordPress templates must remain published for a restorable update.', 'bhranti' ), 403 );
		}
		$restore = array();
		if ( 'update' === $kind ) {
			foreach ( array_keys( $command['args']['params'] ) as $field ) {
				if ( ! array_key_exists( $field, $before ) ) {
					return self::error( 'bhranti_action_blocked', __( 'WordPress did not expose a restorable value for this field.', 'bhranti' ), 409 );
				}
				$restore[ $field ] = self::raw_value( $before[ $field ] );
				if ( self::unsafe_value( $restore[ $field ] ) ) {
					return self::error( 'bhranti_action_blocked', __( 'This field contains executable code that Bhranti cannot safely restore.', 'bhranti' ), 403 );
				}
			}
		}
		if ( 'update' === $kind ) {
			$restorable = self::review(
				array(
					'tool' => 'wp_request',
					'args' => array(
						'method' => 'POST',
						'path'   => $path,
						'params' => $restore,
					),
				),
				$requester_id,
				$actor_id
			);
			if ( is_wp_error( $restorable ) ) {
				self::audit( 'action_blocked', __( 'The previous field values cannot be safely restored.', 'bhranti' ), $session_id, $details + array( 'target' => $target ), $requester_id, $actor_id );
				return self::error( 'bhranti_action_blocked', __( 'WordPress cannot safely restore the previous values for this update.', 'bhranti' ), 409 );
			}
		}
		$action_id = wp_generate_uuid4();
		$snapshot  = array(
			'id'                 => $action_id,
			'requester_id'       => (int) $requester_id,
			'session_id'         => (int) $session_id,
			'actor_id'           => $actor_id,
			'command'            => $command,
			'class'              => $review['class'],
			'type'               => $review['type'],
			'kind'               => $kind,
			'state'              => 'pending',
			'created'            => time(),
			'expires'            => time() + 7 * DAY_IN_SECONDS,
			'target'             => $target,
			'object_id'          => self::object_id( $review, $before ),
			'restore'            => $restore,
			'prior_status'       => $before['status'] ?? '',
			'before_fingerprint' => $fingerprint,
			'after_fingerprint'  => '',
			'aux'                => $aux,
		);
		$saved     = self::save_snapshot( $session_id, $snapshot );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$details += array(
			'target'    => $target,
			'action_id' => $action_id,
		);
		$logged   = self::audit( 'action_started', __( 'Applying a reviewed WordPress action as Bhranti.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
		if ( is_wp_error( $logged ) ) {
			return $logged;
		}
		$current_fingerprint = self::fingerprint( $review, $before, $aux );
		if ( ! $review['create'] && ( $current_fingerprint !== $fingerprint || ( $expected_fingerprint && ! hash_equals( $expected_fingerprint, $current_fingerprint ) ) ) ) {
			$snapshot['state'] = 'failed';
			self::save_snapshot( $session_id, $snapshot );
			self::audit( 'action_blocked', __( 'The item changed before the action could run.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
			return self::error( 'bhranti_rollback_conflict', __( 'The item changed before the action could run. Review it again.', 'bhranti' ), 409 );
		}
		$policy = self::policy( $review, $before );
		if ( is_wp_error( $policy ) ) {
			$snapshot['state'] = 'failed';
			self::save_snapshot( $session_id, $snapshot );
			self::audit( 'action_blocked', $policy->get_error_message(), $session_id, $details, $requester_id, $actor_id );
			return $policy;
		}
		try {
			$data = self::dispatch( $review['request'], $actor_id );
			if ( is_wp_error( $data ) ) {
				$snapshot['state'] = 'uncertain';
				self::save_snapshot( $session_id, $snapshot );
				self::audit( 'action_failed', __( 'WordPress could not complete the action. Review the item before retrying.', 'bhranti' ), $session_id, $details + array( 'code' => $data->get_error_code() ), $requester_id, $actor_id );
				return self::uncertain( $data );
			}
			$snapshot['target']            = self::target( $review, $data );
			$snapshot['object_id']         = self::object_id( $review, $data );
			$snapshot['after_fingerprint'] = self::fingerprint( $review, $data, $aux );
			$snapshot['state']             = 'completed';
			if ( 'create' === $kind ) {
				$snapshot['command']['args']['path'] = rtrim( $path, '/' ) . '/' . $data['id'];
				if ( ! $review['create'] ) {
					$snapshot['command']['args']['path'] = $path;
				}
			}
			$saved = self::save_snapshot( $session_id, $snapshot );
			if ( is_wp_error( $saved ) ) {
				self::audit( 'action_failed', __( 'The action ran, but its rollback record could not be finalized. Review the item.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
				return self::uncertain( self::error( 'bhranti_action_storage', __( 'The action ran, but the rollback record could not be finalized.', 'bhranti' ), 500 ) );
			}
			$summary = 'DELETE' === $method ? __( 'Item moved to Trash by Bhranti.', 'bhranti' ) : __( 'WordPress action completed by Bhranti.', 'bhranti' );
			self::audit(
				'action_completed',
				$summary,
				$session_id,
				array(
					'method'    => $method,
					'path'      => $path,
					'target'    => $snapshot['target'],
					'action_id' => $action_id,
				),
				$requester_id,
				$actor_id
			);
			$result = array(
				'summary'            => $summary,
				'created'            => (bool) $review['create'],
				'data'               => self::public_data( $data, $review['class'] ),
				'target'             => $snapshot['target'],
				'action_id'          => $action_id,
				'rollback_available' => true,
			);
			if ( $content_patch ) {
				$result['data'] = array_intersect_key( $result['data'], array_flip( array( 'id', 'type', 'status', 'link' ) ) );
				if ( isset( $data['content']['raw'] ) && is_string( $data['content']['raw'] ) ) {
					$result['content_revision']         = hash( 'sha256', $data['content']['raw'] );
					$result['data']['content_revision'] = $result['content_revision'];
					$result['data']['content_bytes']    = strlen( $data['content']['raw'] );
				}
			}
			return $result;
		} catch ( Throwable $error ) {
			return self::uncertain( self::error( 'bhranti_action_failed', __( 'The action may have run, but its final result could not be verified. Review the item before continuing.', 'bhranti' ), 500 ) );
		}
	}

	/**
	 * Restore an action only if the owned record is fresh and the item is unchanged.
	 *
	 * @param string $action_id    Action UUID.
	 * @param int    $requester_id Requesting administrator.
	 * @param int    $session_id   Owned workspace session.
	 * @return array|WP_Error
	 */
	public static function rollback( $action_id, $requester_id, $session_id ) {
		$owner = self::owner( $requester_id, $session_id );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		if ( 0 === strpos( $action_id, 'form_' ) ) {
			return Bhranti_Forms::rollback( $action_id, $requester_id, $session_id );
		}
		if ( 0 === strpos( $action_id, 'task_' ) ) {
			return Bhranti_Settings::task_rollback( $action_id, $requester_id, $session_id );
		}
		if ( 0 === strpos( $action_id, 'shop_' ) ) {
			return Bhranti_Commerce::rollback( $action_id, $requester_id, $session_id );
		}
		if ( 0 === strpos( $action_id, 'plugin_' ) ) {
			return Bhranti_Plugins::rollback( $action_id, $requester_id, $session_id );
		}
		$records  = get_post_meta( $session_id, 'bhranti_actions', true );
		$snapshot = is_array( $records ) ? ( $records[ $action_id ] ?? null ) : null;
		if ( ! is_array( $snapshot ) || (int) $snapshot['requester_id'] !== (int) $requester_id || (int) $snapshot['session_id'] !== (int) $session_id || 'completed' !== $snapshot['state'] ) {
			return self::error( 'bhranti_rollback_missing', __( 'This rollback is unavailable or has already been used.', 'bhranti' ), 404 );
		}
		if ( $snapshot['expires'] < time() ) {
			return self::error( 'bhranti_rollback_expired', __( 'The seven-day rollback window has expired.', 'bhranti' ), 410 );
		}
		$actor_id = self::ensure_actor();
		if ( is_wp_error( $actor_id ) ) {
			return $actor_id;
		}
		$path   = $snapshot['command']['args']['path'];
		$review = self::review(
			array(
				'tool' => 'wp_request',
				'args' => array(
					'method' => 'GET',
					'path'   => $path,
					'params' => array(),
				),
			),
			$requester_id,
			$actor_id
		);
		if ( is_wp_error( $review ) ) {
			return $review;
		}
		$current = self::read_item( $path, $requester_id, $actor_id );
		if ( ! is_wp_error( $current ) ) {
			$preserved = self::preserves_existing_content( $review, $current, $actor_id );
			if ( is_wp_error( $preserved ) ) {
				self::audit(
					'rollback_blocked',
					__( 'The actor cannot preserve this item’s existing markup.', 'bhranti' ),
					$session_id,
					array(
						'path'      => $path,
						'action_id' => $action_id,
						'code'      => $preserved->get_error_code(),
					),
					$requester_id,
					$actor_id
				);
				return $preserved;
			}
		}
		if ( is_wp_error( $current ) || self::fingerprint( $review, $current, $snapshot['aux'] ) !== $snapshot['after_fingerprint'] ) {
			self::audit(
				'rollback_blocked',
				__( 'Rollback was blocked because the item has changed.', 'bhranti' ),
				$session_id,
				array(
					'path'      => $path,
					'action_id' => $action_id,
					'target'    => $snapshot['target'],
				),
				$requester_id,
				$actor_id
			);
			return self::error( 'bhranti_rollback_conflict', __( 'This item changed after Bhranti acted. Rollback would overwrite later changes, so it was blocked.', 'bhranti' ), 409 );
		}
		if ( isset( $snapshot['aux']['trash_post'] ) ) {
			$prior = $snapshot['aux']['trash_post'];
			$post  = get_post( $snapshot['object_id'] );
			if ( ! $post || wp_unique_post_slug( $prior['post_name'], $post->ID, $snapshot['prior_status'], $post->post_type, $post->post_parent ) !== $prior['post_name'] ) {
				return self::error( 'bhranti_rollback_conflict', __( 'The original slug is now used by another item. Rollback was blocked.', 'bhranti' ), 409 );
			}
			if ( 'future' === $snapshot['prior_status'] && strtotime( $prior['post_date_gmt'] . ' GMT' ) < time() + MINUTE_IN_SECONDS ) {
				return self::error( 'bhranti_rollback_conflict', __( 'The original publication time has passed. Choose a new schedule before restoring this item.', 'bhranti' ), 409 );
			}
		}
		$restore = 'trash' === $snapshot['kind'] ? array( 'status' => $snapshot['prior_status'] ) : $snapshot['restore'];
		$write   = self::review(
			array(
				'tool' => 'wp_request',
				'args' => array(
					'method' => 'create' === $snapshot['kind'] ? 'DELETE' : 'POST',
					'path'   => $path,
					'params' => $restore,
				),
			),
			$requester_id,
			$actor_id,
			'create' === $snapshot['kind']
		);
		if ( is_wp_error( $write ) ) {
			return $write;
		}
		if ( 'create' === $snapshot['kind'] && EMPTY_TRASH_DAYS <= 0 ) {
			return self::error( 'bhranti_action_blocked', __( 'WordPress Trash must be enabled to undo this creation.', 'bhranti' ), 409 );
		}
		$details = array(
			'method'    => $write['command']['args']['method'],
			'path'      => $path,
			'action_id' => $action_id,
			'target'    => $snapshot['target'],
		);
		$logged  = self::audit( 'rollback_started', __( 'Restoring the previous WordPress state as Bhranti.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
		if ( is_wp_error( $logged ) ) {
			return $logged;
		}
		$snapshot['state'] = 'rolling_back';
		$saved             = self::save_snapshot( $session_id, $snapshot );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		if ( self::fingerprint( $review, $current, $snapshot['aux'] ) !== $snapshot['after_fingerprint'] ) {
			$snapshot['state'] = 'completed';
			self::save_snapshot( $session_id, $snapshot );
			self::audit( 'rollback_blocked', __( 'The item changed before rollback could run.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
			return self::error( 'bhranti_rollback_conflict', __( 'The item changed before rollback could run. Review its current state.', 'bhranti' ), 409 );
		}
		$previous_user = get_current_user_id();
		try {
			wp_set_current_user( $actor_id );
			if ( 'create' === $snapshot['kind'] && 'WP_REST_Menu_Items_Controller' === $snapshot['class'] ) {
				$data = wp_trash_post( $snapshot['object_id'] ) ? array(
					'id'     => $snapshot['target']['id'],
					'status' => 'trash',
					'title'  => $snapshot['target']['title'],
				) : self::error( 'bhranti_rollback_failed', __( 'WordPress could not move the created menu item to Trash.', 'bhranti' ), 500 );
			} else {
				if ( 'trash' === $snapshot['kind'] ) {
					$untrashed = 'WP_REST_Comments_Controller' === $snapshot['class'] ? wp_untrash_comment( $snapshot['object_id'] ) : wp_untrash_post( $snapshot['object_id'] );
					if ( ! $untrashed ) {
						$data = self::error( 'bhranti_rollback_failed', __( 'WordPress could not restore the item from Trash.', 'bhranti' ), 500 );
					}
				}
				if ( ! isset( $data ) || ! is_wp_error( $data ) ) {
					$data = self::dispatch( $write['request'], $actor_id );
				}
			}
			if ( ! is_wp_error( $data ) && ! empty( $snapshot['aux'] ) ) {
				$native_fields = $snapshot['aux']['trash_post'] ?? ( $snapshot['aux']['post_dates'] ?? array() );
				if ( $native_fields ) {
					$restored = wp_update_post(
						wp_slash(
							array(
								'ID'        => $snapshot['object_id'],
								'edit_date' => true,
							) + $native_fields
						),
						true
					);
					if ( is_wp_error( $restored ) || ! $restored ) {
						$data = self::error( 'bhranti_rollback_failed', __( 'WordPress could not restore the previous slug or publication dates.', 'bhranti' ), 500 );
					}
					if ( ! is_wp_error( $data ) ) {
						$post = get_post( $snapshot['object_id'] );
						foreach ( $native_fields as $field => $value ) {
							if ( ! $post || $post->$field !== $value ) {
								$data = self::error( 'bhranti_rollback_failed', __( 'WordPress could not restore the previous slug or publication dates.', 'bhranti' ), 500 );
								break;
							}
						}
					}
				}
				if ( ! is_wp_error( $data ) && isset( $snapshot['aux']['trash_meta'] ) ) {
					foreach ( $snapshot['aux']['trash_meta'] as $key => $values ) {
						delete_post_meta( $snapshot['object_id'], $key );
						foreach ( $values as $value ) {
							add_post_meta( $snapshot['object_id'], $key, wp_slash( $value ) );
						}
						if ( get_post_meta( $snapshot['object_id'], $key, false ) !== $values ) {
							$data = self::error( 'bhranti_rollback_failed', __( 'WordPress could not restore the previous Trash metadata.', 'bhranti' ), 500 );
							break;
						}
					}
				}
				if ( ! is_wp_error( $data ) && ! empty( $snapshot['aux']['was_sticky'] ) ) {
					stick_post( $snapshot['object_id'] );
					if ( ! is_sticky( $snapshot['object_id'] ) ) {
						$data = self::error( 'bhranti_rollback_failed', __( 'WordPress could not restore the previous sticky setting.', 'bhranti' ), 500 );
					}
				}
				if ( ! is_wp_error( $data ) && isset( $snapshot['aux']['front_page_options'] ) ) {
					foreach ( $snapshot['aux']['front_page_options'] as $option_name => $value ) {
						update_option( $option_name, $value );
						if ( (string) get_option( $option_name ) !== (string) $value ) {
							$data = self::error( 'bhranti_rollback_failed', __( 'WordPress could not restore the previous front-page settings.', 'bhranti' ), 500 );
							break;
						}
					}
				}
				if ( array_key_exists( 'nav_menu_locations', $snapshot['aux'] ) ) {
					set_theme_mod( 'nav_menu_locations', $snapshot['aux']['nav_menu_locations'] );
				}
				if ( array_key_exists( 'nav_menu_options', $snapshot['aux'] ) ) {
					update_option( 'nav_menu_options', $snapshot['aux']['nav_menu_options'] );
				}
			}
		} finally {
			wp_set_current_user( $previous_user );
		}
		if ( ! is_wp_error( $data ) && 'create' !== $snapshot['kind'] ) {
			$data = self::read_item( $path, $requester_id, $actor_id );
		}
		if ( is_wp_error( $data ) ) {
			self::audit( 'rollback_failed', __( 'WordPress could not complete the rollback. Review the item.', 'bhranti' ), $session_id, $details + array( 'code' => $data->get_error_code() ), $requester_id, $actor_id );
			return $data;
		}
		$snapshot['target']      = self::target( $write, $data );
		$snapshot['state']       = 'rolled_back';
		$snapshot['rolled_back'] = time();
		$saved                   = self::save_snapshot( $session_id, $snapshot );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$summary = __( 'Previous WordPress state restored by Bhranti.', 'bhranti' );
		self::audit( 'rollback_completed', $summary, $session_id, $details, $requester_id, $actor_id );
		return array(
			'summary'            => $summary,
			'data'               => self::public_data( $data, $snapshot['class'] ),
			'target'             => $snapshot['target'],
			'action_id'          => $action_id,
			'rollback_available' => false,
		);
	}

	/**
	 * Validate ownership before accessing private snapshots.
	 *
	 * @param int $requester_id Requester.
	 * @param int $session_id   Session.
	 * @return true|WP_Error
	 */
	private static function owner( $requester_id, $session_id ) {
		$post = get_post( $session_id );
		if ( get_current_user_id() !== (int) $requester_id || ! user_can( $requester_id, 'manage_options' ) || ! $post || 'bhranti_session' !== $post->post_type || 'private' !== $post->post_status || (int) $post->post_author !== (int) $requester_id ) {
			return self::error( 'bhranti_action_owner', __( 'This workspace action is not available to your account.', 'bhranti' ), 403 );
		}
		return true;
	}

	/**
	 * Match only actual core controllers, validate schemas, and check both actors.
	 *
	 * @param array $command      Command.
	 * @param int   $requester_id Requester.
	 * @param int   $actor_id     Actor.
	 * @param bool  $creation_undo Allow native Trash for a created classic menu item.
	 * @return array|WP_Error Reviewed request.
	 */
	private static function review( $command, $requester_id, $actor_id, $creation_undo = false ) {
		if ( ! is_array( $command ) || array_diff( array_keys( $command ), array( 'tool', 'args' ) ) || ! isset( $command['tool'], $command['args'] ) || ! is_array( $command['args'] ) ) {
			return self::error( 'bhranti_action_invalid', __( 'A tool name and argument object are required.', 'bhranti' ) );
		}
		$catalog = self::catalog();
		if ( ! isset( $catalog[ $command['tool'] ] ) ) {
			return self::error( 'bhranti_action_blocked', __( 'This tool is not available.', 'bhranti' ), 403 );
		}
		$valid = rest_validate_value_from_schema( $command['args'], $catalog[ $command['tool'] ]['parameters'], 'args' );
		if ( is_wp_error( $valid ) ) {
			return self::error( 'bhranti_action_invalid', __( 'The tool arguments do not match the tool schema.', 'bhranti' ) );
		}
		$args = $command['args'];
		if ( 'site_search' === $command['tool'] ) {
			$args = array(
				'method' => 'GET',
				'path'   => '/wp/v2/search',
				'params' => $args,
			);
		} elseif ( 'open_item' === $command['tool'] ) {
			$args = array(
				'method' => 'GET',
				'path'   => $args['path'],
				'params' => array(),
			);
		}
		$method = strtoupper( $args['method'] );
		$method = in_array( $method, array( 'PUT', 'PATCH' ), true ) ? 'POST' : $method;
		$path   = $args['path'];
		$params = $args['params'] ?? array();
		if ( ! is_string( $path ) || 0 !== strpos( $path, '/wp/v2/' ) || preg_match( '/[\\\\%?#\x00-\x20]|\.\./', $path ) || strlen( wp_json_encode( $params ) ) > 250000 ) {
			return self::error( 'bhranti_action_blocked', __( 'Only relative reviewed WordPress core routes are allowed.', 'bhranti' ), 403 );
		}
		if ( isset( $params['status'] ) && 'trash' === $params['status'] && 'GET' !== $method ) {
			if ( array_diff( array_keys( $params ), array( 'status' ) ) ) {
				return self::error( 'bhranti_action_blocked', __( 'Trashing must be a separate, confirmed action.', 'bhranti' ), 403 );
			}
			$method = 'DELETE';
			$params = array();
		}
		$matched = self::handler( $method, $path );
		if ( is_wp_error( $matched ) ) {
			return $matched;
		}
		$endpoint   = $matched['endpoint'];
		$controller = $endpoint['callback'][0];
		$class      = get_class( $controller );
		if ( $matched['public_schema'] && isset( $params['context'] ) && ! in_array( $params['context'], array( 'view', 'embed' ), true ) ) {
			return self::error( 'bhranti_action_blocked', __( 'Public post type discovery is limited to its view and embed contexts.', 'bhranti' ), 403 );
		}
		$schema  = $controller->get_item_schema();
		$type    = $schema['title'] ?? basename( $path );
		$create  = 'POST' === $method && 'create_item' === $endpoint['callback'][1];
		$allowed = self::write_fields( $class, $type, $schema );
		$patch   = array_key_exists( 'content_patch', $args );
		if ( $patch ) {
			$valid = Bhranti_Content_Patch::validate( $args, $class, $type, $endpoint['callback'][1] );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		if ( 'GET' !== $method && empty( $allowed ) ) {
			return self::error( 'bhranti_action_blocked', __( 'This WordPress resource is available for reading only.', 'bhranti' ), 403 );
		}
		if ( $create && in_array( $class, array( 'WP_REST_Terms_Controller', 'WP_REST_Menus_Controller', 'WP_REST_Attachments_Controller' ), true ) ) {
			return self::error( 'bhranti_action_blocked', __( 'Creation is unavailable because this resource has no supported Trash rollback.', 'bhranti' ), 403 );
		}
		if ( 'DELETE' === $method && ( in_array( $class, array( 'WP_REST_Terms_Controller', 'WP_REST_Menus_Controller', 'WP_REST_Settings_Controller', 'WP_REST_Global_Styles_Controller' ), true ) || ( in_array( $class, array( 'WP_REST_Menu_Items_Controller', 'WP_REST_Templates_Controller' ), true ) && ! $creation_undo ) ) ) {
			return self::error( 'bhranti_action_blocked', __( 'This resource cannot be safely moved to Trash.', 'bhranti' ), 403 );
		}
		$fields = array();
		if ( 'GET' === $method && array_key_exists( '_fields', $params ) ) {
			$fields = is_string( $params['_fields'] ) ? wp_parse_list( $params['_fields'] ) : $params['_fields'];
			$valid  = rest_validate_value_from_schema(
				$fields,
				array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 50,
					'items'    => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 128,
						'pattern'   => '^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$',
					),
				),
				'_fields'
			);
			if ( ! is_array( $fields ) || is_wp_error( $valid ) ) {
				return self::error( 'bhranti_action_invalid', __( 'Use _fields with 1–50 field names or dotted field paths, as a comma-separated string or array.', 'bhranti' ) );
			}
			// Project only safe response data; native permissions, targets and recovery reads retain complete records.
			unset( $params['_fields'] );
		}
		foreach ( $params as $key => $value ) {
			if ( 'WP_REST_Revisions_Controller' === $class && 'parent' === $key ) {
				return self::error( 'bhranti_action_blocked', __( 'The revision parent must remain the item selected in the route.', 'bhranti' ), 403 );
			}
			if ( in_array( $key, array( 'force', 'password', 'meta', 'author_email', 'author_ip', 'author_user_agent', 'source', '_fields', '_embed', 'id' ), true ) || ! isset( $endpoint['args'][ $key ] ) || ( 'GET' !== $method && ! in_array( $key, $allowed, true ) ) ) {
				return self::error( 'bhranti_action_blocked', __( 'A parameter is outside the reviewed route schema or rollback policy.', 'bhranti' ), 403 );
			}
			if ( null === $value ) {
				return self::error( 'bhranti_action_invalid', __( 'Null parameters are not supported by Bhranti actions.', 'bhranti' ) );
			}
		}
		if ( 'GET' !== $method && self::unsafe_value( $params ) ) {
			return self::error( 'bhranti_action_blocked', __( 'Executable code and JavaScript URLs are outside Bhranti content actions.', 'bhranti' ), 403 );
		}
		if ( 'POST' === $method && ! $params && ! $patch ) {
			return self::error( 'bhranti_action_invalid', __( 'A write must specify the fields to change.', 'bhranti' ) );
		}
		if ( isset( $params['per_page'] ) && ( (int) $params['per_page'] < 1 || (int) $params['per_page'] > 20 ) ) {
			return self::error( 'bhranti_action_invalid', __( 'Read at most 20 items at a time.', 'bhranti' ) );
		}
		if ( 'GET' === $method && isset( $endpoint['args']['per_page'] ) && ! isset( $params['per_page'] ) ) {
			$params['per_page'] = 20;
		}
		if ( 'WP_REST_Users_Controller' === $class ) {
			if ( isset( $params['context'] ) && 'view' !== $params['context'] ) {
				return self::error( 'bhranti_action_blocked', __( 'User information is limited to its public view.', 'bhranti' ), 403 );
			}
			$params['context'] = 'view';
		}
		if ( 'DELETE' === $method ) {
			$params['force'] = false;
		}
		$request = new WP_REST_Request( $method, $path );
		$request->set_attributes( $endpoint );
		$request->set_url_params( $matched['url_params'] );
		$request->set_default_params( self::defaults( $endpoint ) );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		$valid = $request->has_valid_params();
		if ( ! is_wp_error( $valid ) ) {
			$valid = $request->sanitize_params();
		}
		if ( is_wp_error( $valid ) ) {
			return self::error( 'bhranti_action_invalid', __( 'WordPress rejected these route parameters.', 'bhranti' ) );
		}
		$previous_user = get_current_user_id();
		$missing_block = false;
		try {
			foreach ( array( $requester_id, $actor_id ) as $user_id ) {
				wp_set_current_user( $user_id );
				if ( 'WP_REST_Revisions_Controller' === $class && ! current_user_can( 'read_post', (int) $matched['url_params']['parent'] ) ) {
					return self::error( 'bhranti_action_permission', __( 'Both accounts must be able to read the revision parent.', 'bhranti' ), 403 );
				}
				$check      = static function () use ( $endpoint, $request ) {
					return call_user_func( $endpoint['permission_callback'], $request );
				};
				$permission = (int) $user_id === (int) $actor_id && Bhranti_Site::is_theme_request( $request ) ? Bhranti_Site::actor_read( $request, $actor_id, $check ) : $check();
				// Some plugins register blocks only in the editor, not in scheduled requests.
				if ( 'GET' === $method && 'WP_REST_Block_Types_Controller' === $class && is_wp_error( $permission ) && 'rest_block_type_invalid' === $permission->get_error_code() && 404 === ( $permission->get_error_data()['status'] ?? 0 ) ) {
					$missing_block = true;
					continue;
				}
				if ( is_wp_error( $permission ) || false === $permission || null === $permission ) {
					return self::error( 'bhranti_action_permission', __( 'Both your account and Bhranti must have permission for this action.', 'bhranti' ), 403 );
				}
			}
			if ( $missing_block ) {
				return self::error( 'bhranti_block_metadata_unavailable', __( 'This block is not registered in the current WordPress request. Discover available block names before continuing.', 'bhranti' ), 404 );
			}
		} finally {
			wp_set_current_user( $previous_user );
		}
		if ( 'WP_REST_Revisions_Controller' === $class && isset( $matched['url_params']['id'] ) ) {
			$revision = get_post( (int) $matched['url_params']['id'] );
			if ( ! $revision || 'revision' !== $revision->post_type || (int) $revision->post_parent !== (int) $matched['url_params']['parent'] ) {
				return self::error( 'bhranti_revision_parent', __( 'The returned revision does not belong to the selected item.', 'bhranti' ), 403 );
			}
			if ( wp_is_post_autosave( $revision ) ) {
				return self::error( 'bhranti_revision_autosave', __( 'Choose a saved revision rather than an unsaved editor autosave.', 'bhranti' ), 403 );
			}
		}
		unset( $params['force'] );
		$reviewed_args = array(
			'method' => $method,
			'path'   => $path,
			'params' => $params,
		);
		if ( $patch ) {
			$reviewed_args['content_patch'] = $args['content_patch'];
		}
		return array(
			'command' => array(
				'tool' => 'wp_request',
				'args' => $reviewed_args,
			),
			'request' => $request,
			'class'   => $class,
			'type'    => $type,
			'create'  => $create,
			'fields'  => $fields,
		);
	}

	/**
	 * Keep switches authoritative for every write, while allowing recovery.
	 *
	 * @param array $review Reviewed core operation.
	 * @param array $before Existing raw item when known.
	 * @return true|WP_Error
	 */
	private static function policy( $review, $before = array() ) {
		$method = $review['command']['args']['method'];
		if ( 'GET' === $method ) {
			return true;
		}
		$groups = array(
			'WP_REST_Comments_Controller'      => 'manage_comments',
			'WP_REST_Attachments_Controller'   => 'manage_media',
			'WP_REST_Menu_Items_Controller'    => 'manage_navigation',
			'WP_REST_Menus_Controller'         => 'manage_navigation',
			'WP_REST_Templates_Controller'     => 'manage_design',
			'WP_REST_Global_Styles_Controller' => 'manage_design',
			'WP_REST_Settings_Controller'      => 'manage_site_settings',
		);
		$group  = $groups[ $review['class'] ] ?? ( in_array( $review['type'], array( 'wp_navigation', 'nav_menu' ), true ) ? 'manage_navigation' : 'edit_content' );
		$keys   = 'DELETE' === $method ? array( 'trash_content' ) : array( $group );
		if ( 'DELETE' === $method && 'edit_content' !== $group ) {
			$keys[] = $group;
		}
		$params = $review['command']['args']['params'];
		if ( 'DELETE' !== $method && 'edit_content' === $group && 'WP_REST_Posts_Controller' === $review['class'] && ( in_array( $params['status'] ?? '', array( 'publish', 'private', 'future' ), true ) || in_array( $before['status'] ?? '', array( 'publish', 'private', 'future' ), true ) ) ) {
			$keys[] = 'publish_content';
		}
		foreach ( $keys as $key ) {
			$allowed = Bhranti_Settings::allows( $key );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}
		return true;
	}

	/**
	 * Locate an exact reviewed core controller and its real route schema.
	 *
	 * @param string $path Relative native route.
	 * @param string $method Native REST method.
	 * @param string $field Optional exact parameter name.
	 * @return array|WP_Error
	 */
	public static function route_schema( $path, $method = 'GET', $field = '' ) {
		if ( ! is_string( $path ) || 0 !== strpos( $path, '/wp/v2/' ) || strlen( $path ) > 240 || preg_match( '/[\\\\%?#\x00-\x20]|\.\./', $path ) || ! in_array( $method, array( 'GET', 'POST', 'DELETE' ), true ) ) {
			return self::error( 'bhranti_action_blocked', __( 'Inspect an exact reviewed WordPress core route.', 'bhranti' ), 403 );
		}
		$matched = self::handler( $method, $path );
		if ( is_wp_error( $matched ) ) {
			return $matched;
		}
		$endpoint   = $matched['endpoint'];
		$controller = $endpoint['callback'][0];
		$class      = get_class( $controller );
		$schema     = $controller->get_item_schema();
		$allowed    = self::write_fields( $class, $schema['title'] ?? basename( $path ), $schema );
		$create     = 'POST' === $method && 'create_item' === $endpoint['callback'][1];
		if ( 'GET' !== $method && ( ! $allowed || ( $create && in_array( $class, array( 'WP_REST_Terms_Controller', 'WP_REST_Menus_Controller', 'WP_REST_Attachments_Controller' ), true ) ) || ( 'DELETE' === $method && in_array( $class, array( 'WP_REST_Terms_Controller', 'WP_REST_Menus_Controller', 'WP_REST_Settings_Controller', 'WP_REST_Global_Styles_Controller', 'WP_REST_Menu_Items_Controller', 'WP_REST_Templates_Controller' ), true ) ) ) ) {
			return self::error( 'bhranti_action_blocked', __( 'This operation is outside the supported native recovery policy.', 'bhranti' ), 403 );
		}
		$params = array();
		foreach ( $endpoint['args'] ?? array() as $key => $argument ) {
			if ( 'WP_REST_Revisions_Controller' === $class && 'parent' === $key ) {
				continue;
			}
			if ( in_array( $key, array( 'force', 'password', 'meta', 'author_email', 'author_ip', 'author_user_agent', 'source', '_embed', 'id' ), true ) || ( 'GET' !== $method && ! in_array( $key, $allowed, true ) ) || ( '' !== $field && $field !== $key ) ) {
				continue;
			}
			$params[ $key ] = self::schema_data( $argument );
			if ( 'POST' === $method && 'WP_REST_Posts_Controller' === $class && in_array( $key, array( 'title', 'content', 'excerpt' ), true ) ) {
				$params[ $key ]['input_note'] = 'Native post writes accept a plain string or an object with raw text. rendered and protected describe the response, not writable input. Prefer a plain string.';
			}
		}
		if ( '' !== $field && ! $params ) {
			return self::error( 'bhranti_action_blocked', __( 'That parameter is not available through the reviewed operation.', 'bhranti' ), 403 );
		}
		if ( strlen( wp_json_encode( $params ) ) > 40000 ) {
			return self::error( 'bhranti_inspect_limit', __( 'This schema is large; select one exact parameter with field.', 'bhranti' ) );
		}
		return array(
			'summary' => __( 'Reviewed native parameter schema retrieved.', 'bhranti' ),
			'data'    => array(
				'path'         => $path,
				'method'       => $method,
				'parameters'   => $params,
				'verification' => 'Bounded parameter schema and explicit input notes only; callbacks and large/nested schema details are omitted. Both account permissions, current switches, valid values, approvals and editor safety are checked again on execution.',
			),
		);
	}

	/**
	 * Read only native raw fields so content inspection cannot render dynamic blocks.
	 *
	 * @param string $path Exact native item route.
	 * @param int    $requester_id Human.
	 * @param int    $session_id Owned conversation.
	 * @return array|WP_Error
	 */
	public static function inspect_source( $path, $requester_id, $session_id ) {
		$owner = self::owner( $requester_id, $session_id );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$actor_id = self::ensure_actor();
		if ( is_wp_error( $actor_id ) ) {
			return $actor_id;
		}
		$review = self::review(
			array(
				'tool' => 'wp_request',
				'args' => array(
					'method' => 'GET',
					'path'   => $path,
					'params' => array( 'context' => 'edit' ),
				),
			),
			$requester_id,
			$actor_id
		);
		if ( is_wp_error( $review ) ) {
			return $review;
		}
		if ( ! in_array( $review['class'], array( 'WP_REST_Posts_Controller', 'WP_REST_Templates_Controller' ), true ) ) {
			return self::error( 'bhranti_action_blocked', __( 'Inspect a saved post, page, navigation, pattern or template document.', 'bhranti' ), 403 );
		}
		$review['request']->set_param( '_fields', 'id,type,link,title.raw,content.raw' );
		$data = self::dispatch( $review['request'], $actor_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return array(
			'data'   => self::public_data( $data, $review['class'] ),
			'target' => self::target( $review, $data ),
		);
	}

	/**
	 * Remove executable callbacks and bound native schema data recursively.
	 *
	 * @param array $schema Native parameter schema.
	 * @param int   $depth Nesting depth.
	 * @return array Inert partial schema, with explicit truncation markers.
	 */
	private static function schema_data( $schema, $depth = 0 ) {
		if ( ! is_array( $schema ) || $depth > 6 ) {
			return array( 'truncated' => true );
		}
		$result = array();
		foreach ( array( 'type', 'description', 'required', 'readonly', 'context', 'default', 'enum', 'format', 'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'pattern', 'additionalProperties' ) as $key ) {
			if ( ! array_key_exists( $key, $schema ) ) {
				continue;
			}
			$value = $schema[ $key ];
			if ( is_scalar( $value ) || null === $value ) {
				$result[ $key ] = is_string( $value ) ? mb_substr( sanitize_text_field( $value ), 0, 1000 ) : $value;
			} elseif ( is_array( $value ) && count( $value ) <= 50 && count( array_filter( $value, 'is_scalar' ) ) === count( $value ) ) {
				$result[ $key ] = array_map(
					static function ( $item ) {
						return is_string( $item ) ? mb_substr( sanitize_text_field( $item ), 0, 200 ) : $item;
					},
					$value
				);
			} else {
				$result['truncated'] = true;
			}
		}
		if ( isset( $schema['items'] ) ) {
			$result['items'] = self::schema_data( $schema['items'], $depth + 1 );
		}
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			$result['properties'] = array();
			foreach ( array_slice( $schema['properties'], 0, 25, true ) as $name => $property ) {
				$result['properties'][ $name ] = self::schema_data( $property, $depth + 1 );
			}
			if ( count( $schema['properties'] ) > 25 ) {
				$result['truncated'] = true;
			}
		}
		return $result;
	}

	/**
	 * Match only supported native core controller handlers.
	 *
	 * @param string $method Native REST method.
	 * @param string $path Relative route.
	 * @return array|WP_Error
	 */
	private static function handler( $method, $path ) {
		$classes = array( 'WP_REST_Posts_Controller', 'WP_REST_Attachments_Controller', 'WP_REST_Comments_Controller', 'WP_REST_Terms_Controller', 'WP_REST_Menus_Controller', 'WP_REST_Menu_Items_Controller', 'WP_REST_Templates_Controller', 'WP_REST_Global_Styles_Controller', 'WP_REST_Post_Types_Controller', 'WP_REST_Taxonomies_Controller', 'WP_REST_Users_Controller', 'WP_REST_Search_Controller', 'WP_REST_Settings_Controller', 'WP_REST_Themes_Controller', 'WP_REST_Block_Types_Controller', 'WP_REST_Revisions_Controller' );
		$server  = rest_get_server();
		foreach ( $server->get_routes() as $route => $endpoints ) {
			if ( ! preg_match( '@^' . $route . '$@i', $path, $matches ) ) {
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				if ( empty( $endpoint['methods'][ $method ] ) || ! is_array( $endpoint['callback'] ) || ! is_object( $endpoint['callback'][0] ) || ! in_array( get_class( $endpoint['callback'][0] ), $classes, true ) || ! in_array( $endpoint['callback'][1], array( 'get_item', 'get_items', 'create_item', 'update_item', 'delete_item', 'get_theme_item', 'get_theme_items' ), true ) ) {
					continue;
				}
				$controller = $endpoint['callback'][0];
				$options    = $server->get_route_options( $route );
				if ( 'WP_REST_Revisions_Controller' === get_class( $controller ) ) {
					if ( 'GET' !== $method || ! preg_match( '#\A/wp/v2/(posts|pages)/[1-9][0-9]*/revisions(?:/[1-9][0-9]*)?\z#', $path, $revision_match ) ) {
						continue;
					}
					$native_route = '/wp/v2/' . $revision_match[1] . '/(?P<parent>[\d]+)/revisions';
					$post_type    = get_post_type_object( 'pages' === $revision_match[1] ? 'page' : 'post' );
					if ( ! in_array( $route, array( $native_route, $native_route . '/(?P<id>[\d]+)' ), true ) || ( $endpoint['callback'][1] ?? '' ) !== ( $route === $native_route ? 'get_items' : 'get_item' ) || ! $post_type || $post_type->get_revisions_rest_controller() !== $controller || array( $controller, 'get_public_item_schema' ) !== ( $options['schema'] ?? null ) ) {
						continue;
					}
				}
				$public_schema     = 'GET' === $method && '/wp/v2/types/(?P<type>[\w-]+)' === $route && 'WP_REST_Post_Types_Controller' === get_class( $controller ) && 'get_item' === $endpoint['callback'][1] && '__return_true' === $endpoint['permission_callback'] && array( $controller, 'get_public_item_schema' ) === ( $options['schema'] ?? null );
				$permission_method = $endpoint['callback'][1] . '_permissions_check';
				// Native Settings uses its read permission check for both reading and updating.
				if ( 'WP_REST_Settings_Controller' === get_class( $controller ) && 'update_item' === $endpoint['callback'][1] ) {
					$permission_method = 'get_item_permissions_check';
				}
				if ( 'WP_REST_Search_Controller' === get_class( $controller ) && 'get_items' === $endpoint['callback'][1] ) {
					$permission_method = 'get_items_permission_check';
				}
				if ( ! $public_schema && ( ! is_array( $endpoint['permission_callback'] ) || $endpoint['permission_callback'][0] !== $controller || ( $endpoint['permission_callback'][1] ?? '' ) !== $permission_method ) ) {
					continue;
				}
				return array(
					'endpoint'      => $endpoint,
					'url_params'    => array_filter( $matches, 'is_string', ARRAY_FILTER_USE_KEY ),
					'public_schema' => $public_schema,
				);
			}
		}
		return self::error( 'bhranti_action_blocked', __( 'This route or method is outside the reviewed WordPress core actions.', 'bhranti' ), 403 );
	}

	/**
	 * Reject raw executable content before WordPress performs any write.
	 *
	 * @param mixed $value A tool parameter or nested value.
	 * @return bool
	 */
	private static function unsafe_value( $value ) {
		if ( is_string( $value ) ) {
			return (bool) preg_match( '/<\?(?:php\b|=)|<\s*script\b|\bjavascript\s*:/i', $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $nested ) {
				if ( self::unsafe_value( $nested ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Return reviewed writable fields, excluding arbitrary metadata and secrets.
	 *
	 * @param string $controller_class Controller class.
	 * @param string $type   Resource type.
	 * @param array  $schema Actual resource schema.
	 * @return array
	 */
	private static function write_fields( $controller_class, $type, $schema ) {
		$fields  = array(
			'WP_REST_Posts_Controller'         => array( 'title', 'content', 'excerpt', 'slug', 'status', 'parent', 'menu_order', 'comment_status', 'ping_status', 'featured_media', 'format', 'sticky', 'template', 'date', 'date_gmt' ),
			'WP_REST_Attachments_Controller'   => array( 'title', 'caption', 'description', 'alt_text', 'post' ),
			'WP_REST_Comments_Controller'      => array( 'content', 'status', 'post', 'parent' ),
			'WP_REST_Terms_Controller'         => array( 'name', 'slug', 'description', 'parent' ),
			'WP_REST_Menus_Controller'         => array( 'name', 'slug', 'description', 'locations', 'auto_add' ),
			'WP_REST_Menu_Items_Controller'    => array( 'title', 'type', 'object', 'object_id', 'parent', 'menu_order', 'url', 'target', 'attr_title', 'description', 'classes', 'xfn', 'menus', 'status' ),
			'WP_REST_Templates_Controller'     => array( 'slug', 'title', 'content', 'description', 'status', 'area' ),
			'WP_REST_Global_Styles_Controller' => array( 'title', 'styles', 'settings' ),
			'WP_REST_Settings_Controller'      => self::settings_fields(),
		);
		$allowed = $fields[ $controller_class ] ?? array();
		if ( 'WP_REST_Posts_Controller' === $controller_class ) {
			foreach ( get_object_taxonomies( $type, 'objects' ) as $taxonomy ) {
				if ( $taxonomy->show_in_rest ) {
					$allowed[] = $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name;
				}
			}
		}
		return array_values( array_intersect( $allowed, array_keys( $schema['properties'] ?? array() ) ) );
	}

	/**
	 * Keep site settings independent of credentials, access policy and code.
	 *
	 * @return array
	 */
	private static function settings_fields() {
		return array( 'title', 'description', 'timezone', 'date_format', 'time_format', 'start_of_week', 'posts_per_page', 'default_category', 'default_comment_status', 'default_ping_status', 'show_on_front', 'page_on_front', 'page_for_posts' );
	}

	/**
	 * Collect default argument values exactly as the REST server does.
	 *
	 * @param array $endpoint Route handler.
	 * @return array
	 */
	private static function defaults( $endpoint ) {
		$defaults = array();
		foreach ( $endpoint['args'] ?? array() as $key => $schema ) {
			if ( isset( $schema['default'] ) ) {
				$defaults[ $key ] = $schema['default'];
			}
		}
		return $defaults;
	}

	/**
	 * Dispatch as the actor, restoring the caller even on exceptions.
	 *
	 * @param WP_REST_Request $request  Validated request.
	 * @param int             $actor_id Actor ID.
	 * @return array|WP_Error
	 */
	private static function dispatch( $request, $actor_id ) {
		$previous_user = get_current_user_id();
		try {
			wp_set_current_user( $actor_id );
			$dispatch = static function () use ( $request ) {
				return rest_do_request( $request );
			};
			$response = Bhranti_Site::is_theme_request( $request ) ? Bhranti_Site::actor_read( $request, $actor_id, $dispatch ) : $dispatch();
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( $response->is_error() ) {
				$data = $response->get_data();
				return self::error( $data['code'] ?? 'bhranti_action_failed', __( 'WordPress could not complete the reviewed request.', 'bhranti' ), $response->get_status() );
			}
			return $response->get_data();
		} catch ( Throwable $error ) {
			return self::error( 'bhranti_action_failed', __( 'The reviewed WordPress request could not be completed.', 'bhranti' ), 500 );
		} finally {
			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * Read restorable edit values with both actors' permission checks.
	 *
	 * @param string $path         Item path.
	 * @param int    $requester_id Requester.
	 * @param int    $actor_id     Actor.
	 * @return array|WP_Error
	 */
	private static function read_item( $path, $requester_id, $actor_id ) {
		$handler = self::handler( 'GET', $path );
		if ( is_wp_error( $handler ) ) {
			return $handler;
		}
		$params = isset( $handler['endpoint']['args']['context'] ) ? array( 'context' => 'edit' ) : array();
		$review = self::review(
			array(
				'tool' => 'wp_request',
				'args' => array(
					'method' => 'GET',
					'path'   => $path,
					'params' => $params,
				),
			),
			$requester_id,
			$actor_id
		);
		return is_wp_error( $review ) ? $review : self::dispatch( $review['request'], $actor_id );
	}

	/**
	 * Extract a raw field from WordPress's display/raw response shape.
	 *
	 * @param mixed $value REST value.
	 * @return mixed
	 */
	private static function raw_value( $value ) {
		return is_array( $value ) && array_key_exists( 'raw', $value ) ? $value['raw'] : $value;
	}

	/**
	 * Build a human-readable target without logging content.
	 *
	 * @param array $review Reviewed request.
	 * @param array $data   REST response.
	 * @return array
	 */
	private static function target( $review, $data ) {
		$title = self::raw_value( $data['title'] ?? ( $data['name'] ?? $review['type'] ) );
		$id    = $data['id'] ?? '';
		$url   = $id && ! in_array( $review['class'], array( 'WP_REST_Post_Types_Controller', 'WP_REST_Taxonomies_Controller', 'WP_REST_Search_Controller' ), true ) ? ( $data['link'] ?? ( $data['url'] ?? '' ) ) : '';
		$trash = 'trash' === ( $data['status'] ?? '' );
		if ( $id && is_numeric( $id ) && in_array( $review['class'], array( 'WP_REST_Posts_Controller', 'WP_REST_Attachments_Controller' ), true ) ) {
			$url = $trash ? add_query_arg(
				array(
					'post_status' => 'trash',
					'post_type'   => $review['type'],
				),
				admin_url( 'edit.php' )
			) : get_edit_post_link( absint( $id ), 'raw' );
		}
		if ( $id && 'WP_REST_Templates_Controller' === $review['class'] ) {
			$url = add_query_arg( 'p', '/' . $review['type'] . ( $trash ? '' : '/' . $id ), admin_url( 'site-editor.php' ) );
		}
		if ( $id && 'WP_REST_Global_Styles_Controller' === $review['class'] ) {
			$url = add_query_arg( 'p', '/styles', admin_url( 'site-editor.php' ) );
		}
		if ( $id && ( 'WP_REST_Menu_Items_Controller' === $review['class'] || 'WP_REST_Menus_Controller' === $review['class'] ) ) {
			$url = admin_url( 'nav-menus.php' );
		}
		if ( $id && 'WP_REST_Comments_Controller' === $review['class'] ) {
			$url = $trash ? add_query_arg( 'comment_status', 'trash', admin_url( 'edit-comments.php' ) ) : add_query_arg(
				array(
					'action' => 'editcomment',
					'c'      => $id,
				),
				admin_url( 'comment.php' )
			);
		}
		if ( $id && 'WP_REST_Terms_Controller' === $review['class'] ) {
			$url = add_query_arg(
				array(
					'action'   => 'edit',
					'taxonomy' => $review['type'],
					'tag_ID'   => $id,
				),
				admin_url( 'edit-tags.php' )
			);
		}
		if ( 'WP_REST_Settings_Controller' === $review['class'] ) {
			$url = admin_url( 'options-general.php' );
		}
		return array(
			'id'    => $id,
			'type'  => $review['type'],
			'title' => is_scalar( $title ) ? wp_strip_all_tags( (string) $title ) : $review['type'],
			'url'   => esc_url_raw( $url ),
		);
	}

	/**
	 * Resolve the database object behind a REST item.
	 *
	 * @param array $review Reviewed request.
	 * @param array $data   REST response.
	 * @return int
	 */
	private static function object_id( $review, $data ) {
		return absint( 'WP_REST_Templates_Controller' === $review['class'] ? ( $data['wp_id'] ?? 0 ) : ( $data['id'] ?? 0 ) );
	}

	/**
	 * Hash full object state so later manual edits block rollback.
	 *
	 * @param array $review Reviewed request.
	 * @param array $data   Current REST data.
	 * @param array $aux    Related state affected by this action.
	 * @return string
	 */
	private static function fingerprint( $review, $data, $aux = array() ) {
		$id = self::object_id( $review, $data );
		self::refresh_state_cache( $review, $id );
		$state = $data;
		if ( 'WP_REST_Settings_Controller' === $review['class'] ) {
			$state = array();
			foreach ( get_registered_settings() as $option_name => $setting ) {
				$rest_name = is_array( $setting['show_in_rest'] ?? false ) ? ( $setting['show_in_rest']['name'] ?? $option_name ) : $option_name;
				if ( in_array( $rest_name, self::settings_fields(), true ) ) {
					$state[ $rest_name ] = get_option( $option_name );
				}
			}
		} elseif ( 'WP_REST_Comments_Controller' === $review['class'] && $id ) {
			$comment = get_comment( $id );
			$state   = array(
				'comment' => $comment ? (array) $comment : null,
				'meta'    => get_comment_meta( $id ),
			);
		} elseif ( in_array( $review['class'], array( 'WP_REST_Terms_Controller', 'WP_REST_Menus_Controller' ), true ) && $id ) {
			$state = array(
				'term' => get_term( $id ),
				'meta' => get_term_meta( $id ),
			);
			if ( 'WP_REST_Menus_Controller' === $review['class'] ) {
				$state['locations'] = get_theme_mod( 'nav_menu_locations', array() );
				$state['options']   = get_option( 'nav_menu_options', array() );
			}
		} elseif ( $id && in_array( $review['class'], array( 'WP_REST_Posts_Controller', 'WP_REST_Attachments_Controller', 'WP_REST_Menu_Items_Controller', 'WP_REST_Templates_Controller', 'WP_REST_Global_Styles_Controller' ), true ) ) {
			$post = get_post( $id );
			$meta = get_post_meta( $id );
			unset( $meta['_edit_lock'], $meta['_edit_last'] );
			$state = array(
				'post'   => $post ? $post->to_array() : null,
				'meta'   => $meta,
				'terms'  => array(),
				'sticky' => is_sticky( $id ),
			);
			if ( $post ) {
				foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
					$state['terms'][ $taxonomy ] = wp_get_object_terms(
						$id,
						$taxonomy,
						array(
							'fields'  => 'ids',
							'orderby' => 'term_id',
						)
					);
				}
			}
		}
		if ( isset( $aux['front_page_options'] ) ) {
			foreach ( array_keys( $aux['front_page_options'] ) as $option_name ) {
				$state['front_page_options'][ $option_name ] = get_option( $option_name );
			}
		}
		return hash( 'sha256', wp_json_encode( $state ) );
	}

	/**
	 * Read persisted state afresh when another request may have edited the item.
	 *
	 * @param array $review Reviewed resource.
	 * @param int   $id     Database object ID.
	 */
	private static function refresh_state_cache( $review, $id ) {
		$controller_class = $review['class'];
		if ( 'WP_REST_Settings_Controller' === $controller_class || 'WP_REST_Menus_Controller' === $controller_class || in_array( $controller_class, array( 'WP_REST_Posts_Controller', 'WP_REST_Menu_Items_Controller' ), true ) ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			foreach ( array( 'sticky_posts', 'page_on_front', 'page_for_posts', 'show_on_front' ) as $option_name ) {
				wp_cache_delete( $option_name, 'options' );
			}
			if ( 'WP_REST_Settings_Controller' === $controller_class ) {
				foreach ( get_registered_settings() as $option_name => $setting ) {
					$rest_name = is_array( $setting['show_in_rest'] ?? false ) ? ( $setting['show_in_rest']['name'] ?? $option_name ) : $option_name;
					if ( in_array( $rest_name, self::settings_fields(), true ) ) {
						wp_cache_delete( $option_name, 'options' );
					}
				}
			} elseif ( 'WP_REST_Menus_Controller' === $controller_class ) {
				wp_cache_delete( 'nav_menu_options', 'options' );
				wp_cache_delete( 'theme_mods_' . get_option( 'stylesheet' ), 'options' );
			}
		}
		if ( ! $id ) {
			return;
		}
		if ( 'WP_REST_Comments_Controller' === $controller_class ) {
			wp_cache_delete( $id, 'comment' );
			wp_cache_delete( $id, 'comment_meta' );
		} elseif ( in_array( $controller_class, array( 'WP_REST_Terms_Controller', 'WP_REST_Menus_Controller' ), true ) ) {
			wp_cache_delete( $id, 'terms' );
			wp_cache_delete( $id, 'term_meta' );
		} elseif ( in_array( $controller_class, array( 'WP_REST_Posts_Controller', 'WP_REST_Attachments_Controller', 'WP_REST_Menu_Items_Controller', 'WP_REST_Templates_Controller', 'WP_REST_Global_Styles_Controller' ), true ) ) {
			wp_cache_delete( $id, 'posts' );
			wp_cache_delete( $id, 'post_meta' );
			$post = get_post( $id );
			if ( $post ) {
				foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
					wp_cache_delete( $id, $taxonomy . '_relationships' );
				}
				wp_cache_set_terms_last_changed();
			}
		}
	}

	/**
	 * Avoid stripping existing administrator-authored markup on status-only writes.
	 *
	 * @param array $review   Reviewed resource.
	 * @param array $data     Existing REST item.
	 * @param int   $actor_id Managed user.
	 * @return true|WP_Error
	 */
	private static function preserves_existing_content( $review, $data, $actor_id ) {
		$id = self::object_id( $review, $data );
		if ( ! $id || ! in_array( $review['class'], array( 'WP_REST_Posts_Controller', 'WP_REST_Attachments_Controller', 'WP_REST_Menu_Items_Controller', 'WP_REST_Templates_Controller', 'WP_REST_Global_Styles_Controller' ), true ) ) {
			return true;
		}
		self::refresh_state_cache( $review, $id );
		$post = get_post( $id );
		if ( ! $post ) {
			return self::error( 'bhranti_action_preservation', __( 'This item is no longer available for a safe write.', 'bhranti' ), 409 );
		}
		$previous_user = get_current_user_id();
		try {
			wp_set_current_user( $actor_id );
			foreach ( array(
				'post_content'          => 'post',
				'post_excerpt'          => 'post',
				'post_title'            => 'title_save_pre',
				'post_content_filtered' => 'post',
			) as $field => $context ) {
				$content = $post->$field;
				if ( wp_kses( $content, $context ) !== $content ) {
					return self::error( 'bhranti_action_preservation', __( 'This item contains markup Bhranti cannot preserve with its allowed permissions. Edit it in WordPress first.', 'bhranti' ), 403 );
				}
			}
		} finally {
			wp_set_current_user( $previous_user );
		}
		return true;
	}

	/**
	 * Capture publication dates and menu options changed implicitly by core.
	 *
	 * @param array $review Reviewed request.
	 * @param array $params Requested fields.
	 * @return array
	 */
	private static function auxiliary( $review, $params ) {
		$aux = array();
		$id  = absint( $review['request']->get_param( 'id' ) );
		if ( $id && 'WP_REST_Posts_Controller' === $review['class'] && ( 'DELETE' === $review['request']->get_method() || isset( $params['status'] ) || isset( $params['date'] ) || isset( $params['date_gmt'] ) ) ) {
			self::refresh_state_cache( $review, $id );
			$post = get_post( $id );
			if ( $post ) {
				$aux['post_dates'] = array(
					'post_date'     => $post->post_date,
					'post_date_gmt' => $post->post_date_gmt,
				);
				if ( 'DELETE' === $review['request']->get_method() ) {
					$aux['trash_post'] = array( 'post_name' => $post->post_name ) + $aux['post_dates'];
					foreach ( array( '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_wp_trash_meta_comments_status' ) as $key ) {
						$aux['trash_meta'][ $key ] = get_post_meta( $id, $key, false );
					}
					$aux['was_sticky'] = is_sticky( $id );
					if ( 'page' === $post->post_type && ( (int) get_option( 'page_on_front' ) === $id || (int) get_option( 'page_for_posts' ) === $id ) ) {
						foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts' ) as $option_name ) {
							$aux['front_page_options'][ $option_name ] = get_option( $option_name );
						}
					}
				}
			}
		}
		if ( 'WP_REST_Menus_Controller' === $review['class'] ) {
			if ( isset( $params['locations'] ) ) {
				$aux['nav_menu_locations'] = get_theme_mod( 'nav_menu_locations', array() );
			}
			if ( isset( $params['auto_add'] ) ) {
				$aux['nav_menu_options'] = get_option( 'nav_menu_options', array() );
			}
		}
		return $aux;
	}

	/**
	 * Refuse irreversible deletes, including sites with Trash disabled.
	 *
	 * @param array $review Reviewed request.
	 * @param array $data   Item.
	 * @return true|WP_Error
	 */
	private static function trashable( $review, $data ) {
		$id = self::object_id( $review, $data );
		if ( EMPTY_TRASH_DAYS <= 0 || ! $id || 'trash' === ( $data['status'] ?? '' ) || ( 'WP_REST_Attachments_Controller' === $review['class'] && ( ! defined( 'MEDIA_TRASH' ) || ! MEDIA_TRASH ) ) || ( 'WP_REST_Templates_Controller' === $review['class'] && 'custom' !== ( $data['source'] ?? '' ) ) ) {
			return self::error( 'bhranti_action_blocked', __( 'This item has no supported reversible Trash operation.', 'bhranti' ), 403 );
		}
		return true;
	}

	/**
	 * Verify durable storage before any mutation can run.
	 *
	 * @param int   $session_id Session.
	 * @param array $snapshot   Action record.
	 * @return true|WP_Error
	 */
	private static function save_snapshot( $session_id, $snapshot ) {
		$records                    = get_post_meta( $session_id, 'bhranti_actions', true );
		$records                    = is_array( $records ) ? self::array_values_deep( $records ) : array();
		$snapshot                   = self::array_values_deep( $snapshot );
		$records[ $snapshot['id'] ] = $snapshot;
		if ( strlen( wp_json_encode( $records ) ) > 5000000 ) {
			return self::error( 'bhranti_action_storage', __( 'This conversation has reached its rollback storage limit. Start a new conversation.', 'bhranti' ), 409 );
		}
		update_post_meta( $session_id, 'bhranti_actions', wp_slash( $records ) );
		if ( get_post_meta( $session_id, 'bhranti_actions', true ) !== $records ) {
			return self::error( 'bhranti_action_storage', __( 'WordPress could not save the rollback record. No further action was taken.', 'bhranti' ), 500 );
		}
		return true;
	}

	/**
	 * Normalize REST objects so serialized records retain identical value shapes.
	 *
	 * @param mixed $value Native REST value.
	 * @return mixed
	 */
	private static function array_values_deep( $value ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $nested ) {
				$value[ $key ] = self::array_values_deep( $nested );
			}
		}
		return $value;
	}

	/**
	 * Project known public fields so plugin additions cannot expose credentials.
	 *
	 * @param array  $data  REST data.
	 * @param string $controller_class Controller class.
	 * @return array
	 */
	private static function public_data( $data, $controller_class ) {
		if ( 'WP_REST_Block_Types_Controller' === $controller_class ) {
			if ( isset( $data[0] ) ) {
				return array_map(
					static function ( $item ) {
						return array_intersect_key( $item, array_flip( self::block_fields( true ) ) );
					},
					array_slice( $data, 0, 200 )
				);
			}
			$data = array_intersect_key( $data, array_flip( self::block_fields( false ) ) );
			if ( isset( $data['styles'] ) && is_array( $data['styles'] ) ) {
				$data['styles'] = array_map(
					static function ( $style ) {
						return is_array( $style ) ? array_intersect_key( $style, array_flip( array( 'name', 'label', 'is_default' ) ) ) : array();
					},
					array_slice( $data['styles'], 0, 20 )
				);
			}
			return $data;
		}
		if ( 'WP_REST_Themes_Controller' === $controller_class ) {
			return Bhranti_Site::theme_data( $data );
		}
		if ( isset( $data[0] ) ) {
			return array_map(
				static function ( $item ) use ( $controller_class ) {
					return is_array( $item ) ? self::public_data( $item, $controller_class ) : $item;
				},
				$data
			);
		}
		if ( in_array( $controller_class, array( 'WP_REST_Post_Types_Controller', 'WP_REST_Taxonomies_Controller' ), true ) && ! isset( $data['name'] ) ) {
			return array_map(
				static function ( $item ) use ( $controller_class ) {
					return is_array( $item ) ? self::public_data( $item, $controller_class ) : $item;
				},
				$data
			);
		}
		$allowed = 'WP_REST_Settings_Controller' === $controller_class ? self::settings_fields() : array( 'id', 'wp_id', 'date', 'date_gmt', 'modified', 'modified_gmt', 'slug', 'status', 'type', 'link', 'url', 'title', 'name', 'content', 'excerpt', 'description', 'caption', 'alt_text', 'parent', 'post', 'menu_order', 'featured_media', 'comment_status', 'ping_status', 'format', 'sticky', 'template', 'categories', 'tags', 'taxonomy', 'count', 'rest_base', 'rest_namespace', 'supports', 'labels', 'hierarchical', 'subtype', 'source', 'area', 'theme', 'styles', 'settings', 'locations', 'auto_add', 'menus', 'object', 'object_id', 'target', 'attr_title', 'classes', 'xfn' );
		if ( 'WP_REST_Users_Controller' === $controller_class ) {
			$allowed = array( 'id', 'name', 'slug', 'url', 'description' );
		}
		$public = array_intersect_key( $data, array_flip( $allowed ) );
		return 'WP_REST_Attachments_Controller' === $controller_class ? array_merge( $public, self::media_data( $data ) ) : $public;
	}

	/**
	 * Keep revision reads inert: list identity first, then read one saved source.
	 *
	 * @param bool $collection Native collection rather than an exact revision.
	 * @return string[]
	 */
	private static function revision_fields( $collection ) {
		$fields = array( 'id', 'parent', 'date', 'date_gmt', 'modified', 'modified_gmt' );
		return $collection ? $fields : array_merge( $fields, array( 'title.raw', 'content.raw', 'excerpt.raw' ) );
	}

	/**
	 * Reject foreign revisions and omit autosaves before exposing any response.
	 *
	 * @param array $data Native response data.
	 * @param array $selectors Exact URL selectors, never query overrides.
	 * @return array|WP_Error
	 */
	private static function revision_data( $data, $selectors ) {
		$item    = isset( $selectors['id'] );
		$records = $item ? array( $data ) : $data;
		if ( ! is_array( $records ) || count( $records ) > 20 ) {
			return self::error( 'bhranti_revision_limit', __( 'Read at most twenty saved revisions at a time.', 'bhranti' ) );
		}
		$result = array();
		foreach ( $records as $record ) {
			$post = is_array( $record ) && is_int( $record['id'] ?? null ) ? get_post( $record['id'] ) : null;
			if ( ! $post || 'revision' !== $post->post_type || (int) $post->post_parent !== (int) $selectors['parent'] || (int) ( $record['parent'] ?? 0 ) !== (int) $selectors['parent'] || ( $item && (int) $post->ID !== (int) $selectors['id'] ) ) {
				return self::error( 'bhranti_revision_parent', __( 'The returned revision does not belong to the selected item.', 'bhranti' ), 403 );
			}
			if ( wp_is_post_autosave( $post->ID ) ) {
				if ( $item ) {
					return self::error( 'bhranti_revision_autosave', __( 'Choose a saved revision rather than an unsaved editor autosave.', 'bhranti' ), 403 );
				}
				continue;
			}
			$safe = array_intersect_key( $record, array_flip( self::revision_fields( true ) ) );
			if ( $item ) {
				foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
					if ( is_string( $record[ $field ]['raw'] ?? null ) ) {
						$safe[ $field ] = array( 'raw' => $record[ $field ]['raw'] );
					}
				}
			}
			$result[] = $safe;
		}
		if ( strlen( wp_json_encode( $result ) ) > 1048576 ) {
			return self::error( 'bhranti_revision_limit', __( 'This saved revision exceeds the bounded source-read limit.', 'bhranti' ) );
		}
		return $item ? $result[0] : $result;
	}

	/**
	 * Expose usable native upload references without image metadata or server paths.
	 *
	 * @param array $data Native attachment response.
	 * @return array Bounded public media fields.
	 */
	private static function media_data( $data ) {
		$public = array();
		$url    = self::media_url( $data['source_url'] ?? null );
		if ( $url ) {
			$public['source_url'] = $url;
		}
		if ( in_array( $data['media_type'] ?? null, array( 'image', 'file' ), true ) ) {
			$public['media_type'] = $data['media_type'];
		}
		if ( is_string( $data['mime_type'] ?? null ) && preg_match( '#\A[a-zA-Z0-9.+_-]{1,64}/[a-zA-Z0-9.+_-]{1,127}\z#', $data['mime_type'] ) ) {
			$public['mime_type'] = $data['mime_type'];
		}
		$details = $data['media_details'] ?? null;
		if ( ! is_array( $details ) ) {
			return $public;
		}
		$safe = array();
		foreach ( array( 'width', 'height' ) as $dimension ) {
			if ( is_int( $details[ $dimension ] ?? null ) && $details[ $dimension ] > 0 && $details[ $dimension ] <= 32768 ) {
				$safe[ $dimension ] = $details[ $dimension ];
			}
		}
		if ( is_array( $details['sizes'] ?? null ) ) {
			foreach ( array_slice( $details['sizes'], 0, 20, true ) as $name => $size ) {
				if ( ! is_string( $name ) || ! preg_match( '/\A[a-zA-Z0-9_-]{1,64}\z/', $name ) || ! is_array( $size ) ) {
					continue;
				}
				$item = self::media_data( array_intersect_key( $size, array_flip( array( 'source_url', 'mime_type' ) ) ) );
				foreach ( array( 'width', 'height' ) as $dimension ) {
					if ( is_int( $size[ $dimension ] ?? null ) && $size[ $dimension ] > 0 && $size[ $dimension ] <= 32768 ) {
						$item[ $dimension ] = $size[ $dimension ];
					}
				}
				if ( ! empty( $item['source_url'] ) ) {
					$safe['sizes'][ $name ] = $item;
				}
			}
		}
		if ( $safe ) {
			$public['media_details'] = $safe;
		}
		return $public;
	}

	/**
	 * Keep public HTTP media URLs free of credentials, queries and fragments.
	 *
	 * @param mixed $value Native media URL.
	 * @return string Safe public URL or an empty string.
	 */
	private static function media_url( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 2048 ) {
			return '';
		}
		$parts = wp_parse_url( esc_url_raw( $value, array( 'http', 'https' ) ) );
		if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return '';
		}
		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $parts['path'];
	}

	/**
	 * Keep block discovery metadata free of assets and executable callbacks.
	 *
	 * @param bool $collection Return compact collection fields.
	 * @return string[]
	 */
	private static function block_fields( $collection ) {
		return $collection ? array( 'name', 'title', 'category', 'is_dynamic' ) : array( 'name', 'title', 'description', 'category', 'attributes', 'supports', 'is_dynamic', 'uses_context', 'provides_context', 'styles', 'api_version' );
	}

	/**
	 * Persist the audit trail; writes must stop if logging fails.
	 *
	 * @param string $type         Event.
	 * @param string $summary      Safe summary.
	 * @param int    $session_id   Session.
	 * @param array  $details      Method, route and target identifiers only.
	 * @param int    $requester_id Requester.
	 * @param int    $actor_id     Actor.
	 * @return mixed|WP_Error
	 */
	private static function audit( $type, $summary, $session_id, $details, $requester_id, $actor_id ) {
		if ( ! class_exists( 'Bhranti_Audit' ) ) {
			return self::error( 'bhranti_action_storage', __( 'The action log is unavailable. No action was taken.', 'bhranti' ), 500 );
		}
		if ( isset( $details['target'] ) ) {
			$details['target_id']   = $details['target']['id'] ?? '';
			$details['target_type'] = $details['target']['type'] ?? '';
			unset( $details['target'] );
		}
		$result = Bhranti_Audit::record( $type, $summary, $session_id, $details, $requester_id, $actor_id );
		return false === $result ? self::error( 'bhranti_action_storage', __( 'WordPress could not persist the action log.', 'bhranti' ), 500 ) : $result;
	}

	/**
	 * Return a safe error without echoing prompts, content or credentials.
	 *
	 * @param string $code    Machine-readable code.
	 * @param string $message User-facing explanation.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * A native mutation may have saved before a callback or finalization failed.
	 *
	 * @param WP_Error $error Sanitized post-dispatch failure.
	 * @return WP_Error Error that must never trigger an automatic action retry.
	 */
	private static function uncertain( $error ) {
		$data = $error->get_error_data();
		$error->add_data( array_merge( is_array( $data ) ? $data : array( 'status' => 500 ), array( 'uncertain_action' => true ) ) );
		return $error;
	}
}
