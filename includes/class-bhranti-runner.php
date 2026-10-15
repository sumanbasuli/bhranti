<?php
/**
 * Native WordPress Cron continuation and per-tab editor write protection.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/** Continue owned tasks one step at a time without approving destructive actions. */
final class Bhranti_Runner {

	/**
	 * Server-only dispatch scope; no client body field can set this flag.
	 *
	 * @var bool
	 */
	private static $background = false;

	/** Register native continuation and the authenticated editor sideband. */
	public static function register() {
		add_action( 'bhranti_run_tick', array( __CLASS__, 'tick' ), 10, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_session' ), 10, 2 );
	}

	/**
	 * Identify a server-owned background tick for the final Agent write guard.
	 *
	 * @return bool
	 */
	public static function is_background() {
		return self::$background;
	}

	/**
	 * Clear the exact current run when WordPress deletes its private conversation.
	 *
	 * @param int     $post_id Post being deleted by a native API.
	 * @param WP_Post $post Native post object.
	 */
	public static function delete_session( $post_id, $post ) {
		if ( ! $post || 'bhranti_session' !== $post->post_type ) {
			return;
		}
		$run = get_post_meta( $post_id, 'bhranti_run', true );
		if ( is_array( $run ) && (int) ( $run['requester_id'] ?? 0 ) === (int) $post->post_author ) {
			self::clear( $post_id, $run['id'] ?? '', $run['requester_id'] ?? 0 );
		}
	}

	/** Register editor heartbeats independently of the long-running workspace lock. */
	public static function register_routes() {
		register_rest_route(
			'bhranti/v1',
			'/editor-state',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'editor_state' ),
				'permission_callback' => array( 'Bhranti_Workspace', 'can_access' ),
				'args'                => array(
					'token'           => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 36,
					),
					'editor_active'   => array(
						'type'     => 'boolean',
						'required' => true,
					),
					'unsaved_changes' => array(
						'type'     => 'boolean',
						'required' => true,
					),
					'release_stale'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Schedule one owned run through WordPress, without a custom loopback request.
	 *
	 * WP-Cron needs normal site traffic or an external native cron invocation.
	 *
	 * @param int    $session_id Private conversation.
	 * @param string $run_id Current run UUID.
	 * @param int    $requester_id Authorizing administrator.
	 * @return true|WP_Error
	 */
	public static function schedule( $session_id, $run_id, $requester_id ) {
		return self::queue( $session_id, $run_id, $requester_id, 1 );
	}

	/**
	 * Remove only the events for this exact run and requesting identity.
	 *
	 * @param int    $session_id Private conversation.
	 * @param string $run_id Current run UUID.
	 * @param int    $requester_id Authorizing administrator.
	 * @return int|WP_Error Count of removed events.
	 */
	public static function clear( $session_id, $run_id, $requester_id ) {
		if ( ! self::identifiers( $session_id, $run_id, $requester_id ) ) {
			return self::error( 'bhranti_runner_invalid', __( 'The background task identity is invalid.', 'bhranti' ), 400 );
		}
		return wp_clear_scheduled_hook( 'bhranti_run_tick', array( (int) $session_id, $run_id, (int) $requester_id ), true );
	}

	/**
	 * Run one native REST planning or prepared execution request as its human owner.
	 *
	 * Active HTTP work is never duplicated. An interrupted phase goes to Agent's
	 * existing stop path; this runner never reconstructs or repeats uncertain work.
	 *
	 * @param int    $session_id Private conversation.
	 * @param string $run_id Current run UUID.
	 * @param int    $requester_id Authorizing administrator.
	 * @return mixed Native response, requeue result or a safe error.
	 */
	public static function tick( $session_id, $run_id, $requester_id ) {
		$run = self::owned_run( $session_id, $run_id, $requester_id );
		if ( is_wp_error( $run ) ) {
			self::clear( $session_id, $run_id, $requester_id );
			return $run;
		}
		self::clear( $session_id, $run_id, $requester_id );
		if ( 'running' !== ( $run['status'] ?? '' ) || 'browser' === ( $run['phase'] ?? '' ) ) {
			return false;
		}
		$previous_user       = get_current_user_id();
		$previous_background = self::$background;
		try {
			self::$background = true;
			clean_user_cache( $requester_id );
			wp_set_current_user( $requester_id );
			if ( ! current_user_can( 'manage_options' ) || ! Bhranti_Workspace::can_access() || get_user_meta( $requester_id, 'bhranti_managed_actor', true ) ) {
				Bhranti_Audit::record(
					'background_blocked',
					__( 'Background continuation stopped because the requesting administrator no longer has permission.', 'bhranti' ),
					$session_id,
					array(
						'run_id' => $run_id,
						'code'   => 'bhranti_runner_permission',
					),
					$requester_id,
					$run['actor_id'] ?? $requester_id
				);
				return self::error( 'bhranti_runner_permission', __( 'This administrator can no longer continue the background task.', 'bhranti' ), 403 );
			}
			$phase = $run['phase'] ?? '';
			if ( '' === $phase && (int) ( $run['retry_at'] ?? 0 ) > time() ) {
				return self::queue( $session_id, $run_id, $requester_id, 1 );
			}
			if ( in_array( $phase, array( 'model', 'action' ), true ) ) {
				wp_cache_delete( 'bhranti_lock_' . $requester_id, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				$lock = (int) get_option( 'bhranti_lock_' . $requester_id, 0 );
				if ( $lock && $lock >= time() - 600 ) {
					return self::queue( $session_id, $run_id, $requester_id, 10 );
				}
			}
			$action = 'step';
			$params = array(
				'run_id'     => $run_id,
				'defer_tool' => true,
			);
			if ( 'prepared' === $phase ) {
				if ( self::editor_blocked( $requester_id, self::is_write( $run ) ) ) {
					return self::queue( $session_id, $run_id, $requester_id, 10 );
				}
				$action = 'execute';
				$params = array(
					'run_id'          => $run_id,
					'prepared_step'   => (int) ( $run['step'] ?? 0 ),
					'unsaved_changes' => false,
				);
			}
			$request = new WP_REST_Request( 'POST', '/bhranti/v1/sessions/' . (int) $session_id . '/' . $action );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
			// Persist a watchdog before any provider call or site action can begin.
			$watchdog = self::queue( $session_id, $run_id, $requester_id, 10 );
			if ( is_wp_error( $watchdog ) ) {
				return $watchdog;
			}
			$response = rest_do_request( $request );
			if ( is_wp_error( $response ) || $response->is_error() ) {
				$error = is_wp_error( $response ) ? $response : $response->as_error();
				if ( in_array( $error->get_error_code(), array( 'bhranti_busy', 'bhranti_unsaved_changes', 'bhranti_editor_busy' ), true ) ) {
					return self::queue( $session_id, $run_id, $requester_id, 10 );
				}
				self::clear( $session_id, $run_id, $requester_id );
				Bhranti_Audit::record(
					'background_failed',
					__( 'Background continuation stopped because its native step failed. Review the conversation and operation logs before continuing.', 'bhranti' ),
					$session_id,
					array(
						'run_id' => $run_id,
						'code'   => 'bhranti_runner_step_failed',
					),
					$requester_id,
					$run['actor_id'] ?? $requester_id
				);
				return $error;
			}
			$latest = self::owned_run( $session_id, $run_id, $requester_id );
			if ( ! is_wp_error( $latest ) && 'running' === ( $latest['status'] ?? '' ) ) {
				self::clear( $session_id, $run_id, $requester_id );
				$queued = self::queue( $session_id, $run_id, $requester_id, 1 );
				if ( is_wp_error( $queued ) ) {
					return $queued;
				}
			} else {
				self::clear( $session_id, $run_id, $requester_id );
			}
			return $response;
		} finally {
			self::$background = $previous_background;
			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * Block writes while any tab is actively editing or retains unsaved work.
	 *
	 * Expired dirty tabs remain sticky until the same tab reports clean or a
	 * human explicitly releases stale tabs through the editor-state endpoint.
	 *
	 * @param int  $requester_id Human owner.
	 * @param bool $write Whether the pending operation can write site state.
	 * @return bool
	 */
	public static function editor_blocked( $requester_id, $write ) {
		if ( ! $write ) {
			return false;
		}
		$status = self::editor_status( $requester_id );
		return is_wp_error( $status ) || $status['write_blocked'];
	}

	/**
	 * Protect foreground writes against any tab's sticky unsaved changes.
	 *
	 * A clean active editor does not block an explicitly requested foreground edit.
	 *
	 * @param int $requester_id Human owner.
	 * @return bool
	 */
	public static function editor_dirty( $requester_id ) {
		$status = self::editor_status( $requester_id );
		return is_wp_error( $status ) || $status['dirty'];
	}

	/**
	 * Expose safe aggregate pause information to the owned conversation response.
	 *
	 * @param int $requester_id Human owner.
	 * @return array|WP_Error
	 */
	public static function editor_status( $requester_id ) {
		$states = self::editor_states( $requester_id );
		return is_wp_error( $states ) ? $states : self::editor_summary( $states );
	}

	/**
	 * Update only the authenticated user's own tab marker with bounded native CAS.
	 *
	 * This endpoint deliberately does not acquire the workspace's AI request lock.
	 * No editor titles, content or browser URLs are stored in these markers.
	 *
	 * @param WP_REST_Request $request Strict native JSON heartbeat.
	 * @return array|WP_Error Public aggregate editor state, without tab tokens.
	 */
	public static function editor_state( $request ) {
		if ( ! Bhranti_Workspace::can_access() || get_user_meta( get_current_user_id(), 'bhranti_managed_actor', true ) ) {
			return self::error( 'bhranti_editor_permission', __( 'Only the requesting administrator can report an editor state.', 'bhranti' ), 403 );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array_diff( array_keys( $body ), array( 'token', 'editor_active', 'unsaved_changes', 'release_stale' ) ) || ! self::uuid( $body['token'] ?? null ) || ! is_bool( $body['editor_active'] ?? null ) || ! is_bool( $body['unsaved_changes'] ?? null ) || ( array_key_exists( 'release_stale', $body ) && ! is_bool( $body['release_stale'] ) ) || ( ! empty( $body['release_stale'] ) && $body['unsaved_changes'] ) ) {
			return self::error( 'bhranti_editor_invalid', __( 'Send a valid tab token and boolean editor state; save or discard this editor before resuming stale tabs.', 'bhranti' ), 400 );
		}
		$user_id = get_current_user_id();
		$key     = 'bhranti_editor_state_' . get_current_blog_id();
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$prior = self::editor_states( $user_id );
			if ( is_wp_error( $prior ) ) {
				return $prior;
			}
			$next = $prior;
			$now  = time();
			foreach ( $next as $token => $state ) {
				if ( ( ! $state['active'] && ! $state['dirty'] ) || ( $state['expires'] <= $now && ( ! $state['dirty'] || ! empty( $body['release_stale'] ) ) ) ) {
					unset( $next[ $token ] );
				}
			}
			if ( $body['editor_active'] || $body['unsaved_changes'] ) {
				$next[ $body['token'] ] = array(
					'active'  => $body['editor_active'],
					'dirty'   => $body['unsaved_changes'],
					'expires' => $now + 120,
				);
			} else {
				unset( $next[ $body['token'] ] );
			}
			if ( count( $next ) > 20 ) {
				return self::error( 'bhranti_editor_limit', __( 'Too many editor tabs are being tracked. Close clean tabs or explicitly resume stale tabs first.', 'bhranti' ), 409 );
			}
			if ( $next === $prior ) {
				return self::editor_summary( $next );
			}
			// Empty previous arrays cannot safely serve as update_user_meta's CAS value.
			if ( ! $prior ) {
				add_user_meta( $user_id, $key, $next, true );
			} elseif ( ! $next ) {
				delete_user_meta( $user_id, $key, $prior );
			} else {
				update_user_meta( $user_id, $key, $next, $prior );
			}
			$persisted = self::editor_states( $user_id );
			if ( ! is_wp_error( $persisted ) && $persisted === $next ) {
				return self::editor_summary( $next );
			}
		}
		return self::error( 'bhranti_editor_busy', __( 'The editor state changed in another tab. Try again shortly; writes remain paused.', 'bhranti' ), 409 );
	}

	/**
	 * Read fresh bounded per-site tab markers, failing closed on ambiguous storage.
	 *
	 * @param int $user_id Requesting administrator.
	 * @return array|WP_Error
	 */
	private static function editor_states( $user_id ) {
		wp_cache_delete( $user_id, 'user_meta' );
		$rows = get_user_meta( $user_id, 'bhranti_editor_state_' . get_current_blog_id(), false );
		if ( ! $rows ) {
			return array();
		}
		if ( 1 !== count( $rows ) || ! is_array( $rows[0] ) || ! $rows[0] || count( $rows[0] ) > 20 ) {
			return self::error( 'bhranti_editor_storage', __( 'Editor state needs administrator review before background writes can continue.', 'bhranti' ), 500 );
		}
		foreach ( $rows[0] as $token => $state ) {
			if ( ! self::uuid( $token ) || ! is_array( $state ) || array_diff( array_keys( $state ), array( 'active', 'dirty', 'expires' ) ) || ! is_bool( $state['active'] ?? null ) || ! is_bool( $state['dirty'] ?? null ) || ! is_int( $state['expires'] ?? null ) || $state['expires'] < 1 ) {
				return self::error( 'bhranti_editor_storage', __( 'Editor state needs administrator review before background writes can continue.', 'bhranti' ), 500 );
			}
		}
		return $rows[0];
	}

	/**
	 * Report aggregate protection without revealing another tab's identity.
	 *
	 * @param array $states Validated tab markers.
	 * @return array
	 */
	private static function editor_summary( $states ) {
		$result = array(
			'write_blocked' => false,
			'active'        => false,
			'dirty'         => false,
			'stale_dirty'   => false,
		);
		foreach ( $states as $state ) {
			$result['active']      = $result['active'] || ( $state['active'] && $state['expires'] > time() );
			$result['dirty']       = $result['dirty'] || $state['dirty'];
			$result['stale_dirty'] = $result['stale_dirty'] || ( $state['dirty'] && $state['expires'] <= time() );
		}
		$result['write_blocked'] = $result['active'] || $result['dirty'];
		return $result;
	}

	/**
	 * Review a prepared command's ability to write, independent of display text.
	 *
	 * @param array $run Persisted owned run.
	 * @return bool
	 */
	private static function is_write( $run ) {
		$command = $run['pending_plan'] ?? array();
		if ( ! is_array( $command ) || ! is_string( $command['tool'] ?? null ) || ! is_array( $command['args'] ?? null ) ) {
			return true;
		}
		if ( 'bhranti_inspect' === $command['tool'] && 'rendered' === ( $command['args']['resource'] ?? '' ) ) {
			return false;
		}
		if ( in_array( $command['tool'] ?? '', array( 'site_search', 'open_item', 'plugin_search' ), true ) ) {
			return false;
		}
		$method = $command['args']['method'] ?? 'GET';
		return ! in_array( $command['tool'], array( 'wp_request', 'plugin_request', 'form_request' ), true ) || ! is_string( $method ) || 'GET' !== strtoupper( $method );
	}

	/**
	 * Schedule one event after fresh ownership and current-run checks.
	 *
	 * @param int    $session_id Private conversation.
	 * @param string $run_id Current run UUID.
	 * @param int    $requester_id Authorizing administrator.
	 * @param int    $delay Native continuation delay in seconds.
	 * @return true|WP_Error
	 */
	private static function queue( $session_id, $run_id, $requester_id, $delay ) {
		$run = self::owned_run( $session_id, $run_id, $requester_id );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		if ( 'running' !== ( $run['status'] ?? '' ) || 'browser' === ( $run['phase'] ?? '' ) ) {
			self::clear( $session_id, $run_id, $requester_id );
			return true;
		}
		$args      = array( (int) $session_id, $run_id, (int) $requester_id );
		$retry_at  = '' === ( $run['phase'] ?? '' ) ? (int) ( $run['retry_at'] ?? 0 ) : 0;
		$scheduled = wp_next_scheduled( 'bhranti_run_tick', $args );
		if ( $scheduled && $scheduled < $retry_at ) {
			$cleared = self::clear( $session_id, $run_id, $requester_id );
			if ( is_wp_error( $cleared ) || false === $cleared ) {
				return is_wp_error( $cleared ) ? $cleared : self::error( 'bhranti_runner_storage', __( 'WordPress could not reschedule the connection retry.', 'bhranti' ), 500 );
			}
			$scheduled = false;
		}
		if ( $scheduled ) {
			return true;
		}
		$queued = wp_schedule_single_event( max( time() + $delay, $retry_at ), 'bhranti_run_tick', $args, true );
		if ( is_wp_error( $queued ) && 'duplicate_event' === $queued->get_error_code() && wp_next_scheduled( 'bhranti_run_tick', $args ) ) {
			return true;
		}
		if ( ! $queued || is_wp_error( $queued ) ) {
			Bhranti_Audit::record(
				'background_failed',
				__( 'WordPress could not schedule background continuation. Review the conversation before resuming.', 'bhranti' ),
				$session_id,
				array(
					'run_id' => $run_id,
					'code'   => 'bhranti_runner_storage',
				),
				$requester_id,
				$run['actor_id'] ?? $requester_id
			);
			return is_wp_error( $queued ) ? $queued : self::error( 'bhranti_runner_storage', __( 'WordPress could not schedule the next background step. Continue from the conversation when the site is available.', 'bhranti' ), 500 );
		}
		return true;
	}

	/**
	 * Verify fresh private ownership and the server-owned requesting identity.
	 *
	 * @param int    $session_id Private conversation.
	 * @param string $run_id Current run UUID.
	 * @param int    $requester_id Authorizing administrator.
	 * @return array|WP_Error
	 */
	private static function owned_run( $session_id, $run_id, $requester_id ) {
		if ( ! self::identifiers( $session_id, $run_id, $requester_id ) ) {
			return self::error( 'bhranti_runner_invalid', __( 'The background task identity is invalid.', 'bhranti' ), 400 );
		}
		clean_post_cache( $session_id );
		wp_cache_delete( $session_id, 'post_meta' );
		$post = get_post( $session_id );
		$run  = get_post_meta( $session_id, 'bhranti_run', true );
		if ( ! $post || 'bhranti_session' !== $post->post_type || 'private' !== $post->post_status || (int) $post->post_author !== (int) $requester_id || ! is_array( $run ) || ( $run['id'] ?? '' ) !== $run_id || (int) ( $run['requester_id'] ?? 0 ) !== (int) $requester_id ) {
			return self::error( 'bhranti_runner_owner', __( 'This background task is no longer owned or active.', 'bhranti' ), 409 );
		}
		return $run;
	}

	/**
	 * Require native positive IDs and a generated UUID, never payload arguments.
	 *
	 * @param mixed $session_id Private conversation ID.
	 * @param mixed $run_id Run UUID.
	 * @param mixed $requester_id Human owner ID.
	 * @return bool
	 */
	private static function identifiers( $session_id, $run_id, $requester_id ) {
		return is_numeric( $session_id ) && (int) $session_id > 0 && (string) (int) $session_id === (string) $session_id && is_numeric( $requester_id ) && (int) $requester_id > 0 && (string) (int) $requester_id === (string) $requester_id && self::uuid( $run_id );
	}

	/**
	 * Validate server and tab identifiers without accepting arbitrary data.
	 *
	 * @param mixed $value UUID.
	 * @return bool
	 */
	private static function uuid( $value ) {
		return is_string( $value ) && (bool) preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $value );
	}

	/**
	 * Return a safe error without private run or editor content.
	 *
	 * @param string $code Public code.
	 * @param string $message Fixed explanation.
	 * @param int    $status HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
