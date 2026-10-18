<?php
/**
 * Durable site-local activity records, kept separate from chat messages.
 *
 * @package Bhranti
 */

defined( 'ABSPATH' ) || exit;

/** Audit successful, failed, denied and cancelled operations with both identities. */
final class Bhranti_Audit {
	/** Register nonpublic log storage. */
	public static function register_storage() {
		register_post_type(
			'bhranti_event',
			array(
				'public'           => false,
				'show_ui'          => false,
				'show_in_rest'     => false,
				'can_export'       => false,
				'supports'         => array( 'author' ),
				'capability_type'  => 'bhranti_event',
				// These records are reached only through Bhranti's owner-checked routes.
				// Deny generic WordPress post APIs, including XML-RPC and super admins.
				'capabilities'     => array_fill_keys(
					array(
						'edit_post',
						'read_post',
						'delete_post',
						'edit_posts',
						'edit_others_posts',
						'edit_private_posts',
						'edit_published_posts',
						'delete_posts',
						'delete_others_posts',
						'delete_private_posts',
						'delete_published_posts',
						'publish_posts',
						'read_private_posts',
						'create_posts',
						'read',
					),
					'do_not_allow'
				),
				'map_meta_cap'     => false,
				'delete_with_user' => true,
			)
		);
	}

	/**
	 * Save bounded operation evidence before an operation can run.
	 *
	 * @param string $type Event code.
	 * @param string $summary Public operation summary, never a prompt or raw provider error.
	 * @param int    $session_id Conversation.
	 * @param array  $details Nonsecret operation metadata.
	 * @param int    $requester_id Requesting user; defaults to current user.
	 * @param int    $actor_id Executing user; defaults to requester.
	 * @return int|WP_Error
	 */
	public static function record( $type, $summary, $session_id = 0, $details = array(), $requester_id = 0, $actor_id = 0 ) {
		$requester_id = $requester_id ? absint( $requester_id ) : get_current_user_id();
		$actor_id     = $actor_id ? absint( $actor_id ) : $requester_id;
		$requester    = get_userdata( $requester_id );
		$actor        = get_userdata( $actor_id );
		$details      = is_array( $details ) ? $details : array();
		$session_id   = absint( $session_id );
		// Correlate only an exact owned, currently active run. Never attach an unrelated old run.
		if ( $session_id ) {
			$session = get_post( $session_id );
			$run     = get_post_meta( $session_id, 'bhranti_run', true );
			if ( $session && 'bhranti_session' === $session->post_type && (int) $session->post_author === $requester_id && is_array( $run ) && (int) ( $run['requester_id'] ?? 0 ) === $requester_id && (int) ( $run['actor_id'] ?? 0 ) === $actor_id && 'running' === ( $run['status'] ?? '' ) && ! empty( $run['id'] ) && ( empty( $details['run_id'] ) || $details['run_id'] === $run['id'] ) ) {
				$details['run_id'] = $run['id'];
				if ( ! isset( $details['step'] ) && isset( $run['step'] ) ) {
					$details['step'] = $run['step'];
				}
			}
		}
		$event       = array(
			'id'           => wp_generate_uuid4(),
			'type'         => sanitize_key( $type ),
			'message'      => self::safe_text( $summary, 600 ),
			'created'      => gmdate( 'c' ),
			'session_id'   => $session_id,
			'requester_id' => $requester_id,
			'requester'    => $requester ? $requester->user_login : '',
			'actor_id'     => $actor_id,
			'actor'        => $actor ? $actor->user_login : '',
			'details'      => self::safe_details( $details ),
		);
		$description = self::describe( $event );
		$meta        = array(
			'bhranti_event_data'     => $event,
			'bhranti_session_id'     => $session_id,
			'bhranti_event_status'   => $description['status'],
			'bhranti_event_category' => $description['category'],
			'bhranti_event_actor_id' => $actor_id,
			'bhranti_event_run_id'   => $event['details']['run_id'] ?? '',
			'bhranti_event_search'   => self::search_text( $event, $description ),
			'bhranti_event_index'    => 1,
		);
		$id          = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => 'bhranti_event',
					'post_status' => 'private',
					'post_author' => $requester_id,
					'post_parent' => $session_id,
					'meta_input'  => $meta,
				)
			),
			true
		);
		$valid       = ! is_wp_error( $id ) && $id;
		if ( $valid ) {
			foreach ( $meta as $key => $value ) {
				if ( get_post_meta( $id, $key, true ) !== ( is_array( $value ) ? $value : (string) $value ) ) {
					$valid = false;
					break;
				}
			}
		}
		if ( ! $valid ) {
			if ( ! is_wp_error( $id ) && $id ) {
				wp_delete_post( $id, true );
			}
			return new WP_Error( 'bhranti_audit_failed', __( 'WordPress could not save the activity log. The operation was stopped.', 'bhranti' ), array( 'status' => 500 ) );
		}
		return $id;
	}

	/**
	 * Read a legacy 50-event page. Permissions remain the caller's responsibility.
	 *
	 * @param int $requester_id Human filter; zero means site-wide.
	 * @param int $page Page number.
	 * @param int $session_id Optional conversation filter.
	 * @return array
	 */
	public static function events( $requester_id = 0, $page = 1, $session_id = 0 ) {
		$result = self::query(
			array(
				'page'       => $page,
				'per_page'   => 50,
				'session_id' => $session_id,
			),
			$requester_id
		);
		return is_wp_error( $result ) ? array() : $result['events'];
	}

	/**
	 * Validate a bounded read-only filter set. Caller enforces capabilities and ownership.
	 *
	 * @param array $filters Read filters.
	 * @return array|WP_Error
	 */
	public static function filters( $filters = array() ) {
		if ( ! is_array( $filters ) ) {
			return self::filter_error();
		}
		$normalized = array(
			'search'       => '',
			'status'       => '',
			'category'     => '',
			'requester_id' => 0,
			'actor_id'     => 0,
			'session_id'   => 0,
			'run_id'       => '',
			'from'         => '',
			'to'           => '',
			'page'         => 1,
			'per_page'     => 25,
		);
		if ( array_diff( array_keys( $filters ), array_merge( array_keys( $normalized ), array( 'max_id' ) ) ) ) {
			return self::filter_error();
		}
		foreach ( $filters as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				return self::filter_error();
			}
			if ( in_array( $key, array( 'requester_id', 'actor_id', 'session_id', 'max_id', 'page', 'per_page' ), true ) ) {
				if ( ! preg_match( '/^[0-9]+$/', (string) $value ) || (float) $value > PHP_INT_MAX ) {
					return self::filter_error();
				}
				$normalized[ $key ] = (int) $value;
			} else {
				$normalized[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		$normalized['page']     = max( 1, $normalized['page'] );
		$normalized['per_page'] = max( 1, min( 100, $normalized['per_page'] ) );
		$normalized['search']   = mb_substr( $normalized['search'], 0, 200 );
		if ( $normalized['page'] > intdiv( PHP_INT_MAX, $normalized['per_page'] ) ) {
			return self::filter_error();
		}
		if ( ( $normalized['status'] && ! isset( self::status_options()[ $normalized['status'] ] ) ) || ( $normalized['category'] && ! isset( self::category_options()[ $normalized['category'] ] ) ) || ( $normalized['run_id'] && ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/', $normalized['run_id'] ) ) ) {
			return self::filter_error();
		}
		foreach ( array( 'from', 'to' ) as $key ) {
			$value = $normalized[ $key ];
			if ( $value && ( ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $value, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) ) {
				return self::filter_error();
			}
		}
		if ( $normalized['from'] && $normalized['to'] && $normalized['from'] > $normalized['to'] ) {
			return self::filter_error();
		}
		return $normalized;
	}

	/**
	 * Query a deterministic page without hydrating the full event collection.
	 *
	 * Legacy serialized records are searched in SQL, without migrating evidence.
	 * Dates are inclusive site-local calendar dates; exported timestamps stay UTC.
	 * A max_id snapshot keeps paged downloads stable when later events arrive.
	 *
	 * @param array $filters Safe read-only filters.
	 * @param int   $requester_id Mandatory owner scope when nonzero.
	 * @return array|WP_Error Events, total, page, per_page, pages and latest_id.
	 */
	public static function query( $filters = array(), $requester_id = 0 ) {
		global $wpdb;
		$filters = self::filters( $filters );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}
		$where = array( "p.post_type = 'bhranti_event'", "p.post_status = 'private'" );
		if ( $requester_id && $filters['requester_id'] && (int) $requester_id !== $filters['requester_id'] ) {
			$where[] = '1 = 0';
		}
		$owner = $requester_id ? absint( $requester_id ) : $filters['requester_id'];
		if ( $owner ) {
			$where[] = $wpdb->prepare( 'p.post_author = %d', $owner );
		}
		if ( $filters['session_id'] ) {
			$where[] = $wpdb->prepare( 'p.post_parent = %d', $filters['session_id'] );
		}
		if ( isset( $filters['max_id'] ) ) {
			$where[] = $wpdb->prepare( 'p.ID <= %d', $filters['max_id'] );
		}
		foreach ( array( 'from', 'to' ) as $key ) {
			if ( $filters[ $key ] ) {
				$date    = new DateTimeImmutable( $filters[ $key ] . ( 'from' === $key ? ' 00:00:00' : ' 23:59:59' ), wp_timezone() );
				$utc     = $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
				$where[] = $wpdb->prepare( 'p.post_date_gmt ' . ( 'from' === $key ? '>=' : '<=' ) . ' %s', $utc ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Operator is chosen from two literal branches.
			}
		}
		$legacy = "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} ix WHERE ix.post_id = p.ID AND ix.meta_key = 'bhranti_event_index')";
		foreach ( array( 'status', 'category' ) as $key ) {
			if ( $filters[ $key ] ) {
				$current = self::meta_condition( 'bhranti_event_' . $key, $filters[ $key ] );
				$old     = 'status' === $key ? self::legacy_status_sql( $filters[ $key ] ) : self::legacy_category_sql( $filters[ $key ] );
				$where[] = '(' . $current . ' OR (' . $legacy . ' AND ' . $old . '))';
			}
		}
		foreach ( array( 'actor_id', 'run_id' ) as $key ) {
			if ( $filters[ $key ] ) {
				$current = self::meta_condition( 'bhranti_event_' . $key, (string) $filters[ $key ] );
				$old     = self::serialized_field_sql( $key, (string) $filters[ $key ], 'actor_id' === $key );
				$where[] = '(' . $current . ' OR (' . $legacy . ' AND ' . $old . '))';
			}
		}
		if ( $filters['search'] ) {
			$pattern = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$current = self::meta_condition( 'bhranti_event_search', $pattern, true );
			$old     = self::meta_condition( 'bhranti_event_data', $pattern, true );
			$where[] = '(' . $current . ' OR (' . $legacy . ' AND ' . $old . '))';
		}
		$clause = implode( ' AND ', $where );
		// Every interpolated fragment is private, fixed SQL or individually prepared above.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only private fixed SQL and individually prepared values form the clause; count must share all filters with the bounded ID query, including legacy metadata.
		$summary = $wpdb->get_row( "SELECT COUNT(*) AS total, MAX(p.ID) AS latest_id FROM {$wpdb->posts} p WHERE {$clause}", ARRAY_A );
		if ( null === $summary || $wpdb->last_error ) {
			return new WP_Error( 'bhranti_logs_read', __( 'WordPress could not read the activity log. Try again.', 'bhranti' ), array( 'status' => 500 ) );
		}
		$total     = (int) $summary['total'];
		$latest_id = (int) $summary['latest_id'];
		$offset    = ( $filters['page'] - 1 ) * $filters['per_page'];
		$limit     = $wpdb->prepare( ' LIMIT %d OFFSET %d', $filters['per_page'], $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Same private fixed SQL and individually prepared values; only this page of IDs is read.
		$ids = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE {$clause} ORDER BY p.ID DESC {$limit}" );
		if ( $wpdb->last_error ) {
			return new WP_Error( 'bhranti_logs_read', __( 'WordPress could not read the activity log. Try again.', 'bhranti' ), array( 'status' => 500 ) );
		}
		update_meta_cache( 'post', $ids );
		$events = array();
		foreach ( $ids as $id ) {
			$event = get_post_meta( $id, 'bhranti_event_data', true );
			if ( is_array( $event ) ) {
				$event['event_id'] = (int) $id;
				$event['details']  = self::safe_details( $event['details'] ?? array() );
				$events[]          = $event;
			}
		}
		return array(
			'events'    => $events,
			'total'     => $total,
			'page'      => $filters['page'],
			'per_page'  => $filters['per_page'],
			'pages'     => (int) ceil( $total / $filters['per_page'] ),
			'latest_id' => $latest_id,
		);
	}

	/**
	 * List outcome filters describing an event, rather than the task’s current state.
	 *
	 * @return array
	 */
	public static function status_options() {
		return array(
			'success' => __( 'Completed', 'bhranti' ),
			'error'   => __( 'Failed', 'bhranti' ),
			'warning' => __( 'Needs attention', 'bhranti' ),
			'pending' => __( 'Started / waiting', 'bhranti' ),
			'info'    => __( 'Information', 'bhranti' ),
		);
	}

	/**
	 * List public activity categories.
	 *
	 * @return array
	 */
	public static function category_options() {
		return array(
			'task'     => __( 'Tasks & recovery', 'bhranti' ),
			'ai'       => __( 'AI requests', 'bhranti' ),
			'content'  => __( 'Site content', 'bhranti' ),
			'media'    => __( 'Images & media', 'bhranti' ),
			'plugins'  => __( 'Plugins', 'bhranti' ),
			'commerce' => __( 'Store', 'bhranti' ),
			'settings' => __( 'Settings', 'bhranti' ),
			'memory'   => __( 'Memory', 'bhranti' ),
			'approval' => __( 'Approvals', 'bhranti' ),
			'undo'     => __( 'Undo', 'bhranti' ),
			'system'   => __( 'System', 'bhranti' ),
		);
	}

	/**
	 * Explain the recorded event without reclassifying a failed task as recovered.
	 *
	 * @param array $event Saved audit evidence.
	 * @return array Human presentation and labeled technical evidence.
	 */
	public static function describe( $event ) {
		$type     = sanitize_key( $event['type'] ?? '' );
		$details  = self::safe_details( $event['details'] ?? array() );
		$status   = self::status( $type );
		$category = self::category( $type, $details );
		$titles   = array(
			'run_started'            => __( 'Task started', 'bhranti' ),
			'run_complete'           => __( 'Task finished', 'bhranti' ),
			'run_completed'          => __( 'Task finished', 'bhranti' ),
			'run_failed'             => __( 'Task stopped with an error', 'bhranti' ),
			'run_incomplete'         => __( 'Task stopped with work remaining', 'bhranti' ),
			'run_cancelled'          => __( 'Task stopped', 'bhranti' ),
			'run_awaiting_input'     => __( 'Waiting for your answer', 'bhranti' ),
			'ai_started'             => __( 'Asking the AI for the next step', 'bhranti' ),
			'ai_completed'           => __( 'AI returned a next step', 'bhranti' ),
			'ai_failed'              => __( 'AI request failed', 'bhranti' ),
			'ai_plan_failed'         => __( 'AI response could not be used', 'bhranti' ),
			'ai_plan_retry'          => __( 'Requesting a readable AI response', 'bhranti' ),
			'ai_request_retry'       => __( 'AI retry scheduled', 'bhranti' ),
			'ai_request_recovery'    => __( 'Continuing from the saved checkpoint', 'bhranti' ),
			'ai_resumed'             => __( 'Saved task resumed', 'bhranti' ),
			'action_prepared'        => __( 'Next action prepared', 'bhranti' ),
			'action_started'         => __( 'WordPress action started', 'bhranti' ),
			'action_completed'       => __( 'WordPress action completed', 'bhranti' ),
			'action_failed'          => __( 'WordPress action failed', 'bhranti' ),
			'action_blocked'         => __( 'WordPress action blocked', 'bhranti' ),
			'action_reused'          => __( 'Completed action reused', 'bhranti' ),
			'confirmation_requested' => __( 'Approval requested', 'bhranti' ),
			'confirmation_approved'  => __( 'Approval given', 'bhranti' ),
			'confirmation_denied'    => __( 'Approval declined', 'bhranti' ),
			'media_started'          => __( 'Image generation started', 'bhranti' ),
			'media_saving'           => __( 'Saving the generated image', 'bhranti' ),
			'media_completed'        => __( 'Image saved to the Media Library', 'bhranti' ),
			'media_failed'           => __( 'Image request failed', 'bhranti' ),
			'media_blocked'          => __( 'Image request blocked', 'bhranti' ),
			'render_prepared'        => __( 'Desktop and mobile checks prepared', 'bhranti' ),
			'task_started'           => __( 'Task checkpoint opened', 'bhranti' ),
			'task_completed'         => __( 'Task checkpoint saved or read', 'bhranti' ),
			'task_failed'            => __( 'Task checkpoint unavailable', 'bhranti' ),
			'background_blocked'     => __( 'Background continuation blocked', 'bhranti' ),
			'background_failed'      => __( 'Background continuation failed', 'bhranti' ),
			'actor_started'          => __( 'Setting up Bhranti’s WordPress account', 'bhranti' ),
			'actor_completed'        => __( 'Bhranti’s WordPress account is ready', 'bhranti' ),
			'actor_failed'           => __( 'Bhranti’s account setup failed', 'bhranti' ),
		);
		$title    = $titles[ $type ] ?? self::safe_text( str_replace( '_', ' ', ucfirst( $type ) ), 100 );
		if ( 'action_completed' === $type && 'GET' === ( $details['method'] ?? '' ) ) {
			$title = __( 'WordPress information read', 'bhranti' );
		} elseif ( 'action_started' === $type && 'GET' === ( $details['method'] ?? '' ) ) {
			$title = __( 'Reading WordPress information', 'bhranti' );
		}
		if ( 'bhranti-logs' === ( $details['path'] ?? '' ) ) {
			if ( 'action_started' === $type ) {
				$title = __( 'Reading activity logs', 'bhranti' );
			} elseif ( 'action_completed' === $type ) {
				$title = __( 'Activity logs read', 'bhranti' );
			} elseif ( 'action_failed' === $type ) {
				$title = __( 'Activity logs could not be read', 'bhranti' );
			}
		} elseif ( 'task_completed' === $type ) {
			$title = 'GET' === ( $details['method'] ?? '' ) ? __( 'Task checkpoint read', 'bhranti' ) : __( 'Task checkpoint updated', 'bhranti' );
		}
		$explanation = self::safe_text( $event['message'] ?? '', 600 );
		$next_step   = '';
		$code        = $details['code'] ?? '';
		$reason      = $details['reason'] ?? '';
		if ( 'run_awaiting_input' === $type ) {
			$next_step = __( 'Open the conversation and answer Bhranti’s question to continue.', 'bhranti' );
		} elseif ( 'confirmation_requested' === $type || 'plugin_confirmation_required' === $type ) {
			$next_step = __( 'Review the approval card in the conversation, then approve or decline it.', 'bhranti' );
		} elseif ( 'action_reused' === $type ) {
			$explanation = __( 'A saved successful result was reused. This event did not run the action again.', 'bhranti' );
		} elseif ( in_array( $reason, array( 'network', 'upstream', 'incomplete_response' ), true ) && 'error' === $status ) {
			$next_step = __( 'Review the conversation for its saved work and available retry. This event alone does not mean any earlier action was undone.', 'bhranti' );
		} elseif ( in_array( $code, array( 'bhranti_action_conflict', 'bhranti_rollback_conflict', 'bhranti_settings_conflict', 'bhranti_task_limit_conflict' ), true ) ) {
			$next_step = __( 'The saved item changed. Review its current version before requesting a new change or Undo.', 'bhranti' );
		} elseif ( 'step_limit' === $reason ) {
			$next_step = __( 'Review completed work in the conversation and continue the remaining work in a new task. The Task step limit in Settings applies to new tasks.', 'bhranti' );
		} elseif ( 'unsaved_editor' === $reason ) {
			$next_step = __( 'Save or discard your unsaved editor changes, then resume the conversation.', 'bhranti' );
		} elseif ( 'uncertain_action' === $reason ) {
			$next_step = __( 'Check the affected item before continuing: the action may have saved. Do not repeat the write until its result is known.', 'bhranti' );
		} elseif ( in_array( $reason, array( 'invalid_plan', 'repeated_rejection', 'duplicate_prevented' ), true ) ) {
			$next_step = __( 'Review the saved work in the conversation, then ask Bhranti to continue the remaining steps with a different approach.', 'bhranti' );
		} elseif ( 'ai_retries_exhausted' === $reason ) {
			$next_step = __( 'Check the native connection in Settings → Connectors, then use the conversation’s available retry to continue saved work.', 'bhranti' );
		} elseif ( 'approval_expired' === $reason ) {
			$next_step = __( 'Open the conversation and request fresh approval for the action.', 'bhranti' );
		} elseif ( 'approval_denied' === $reason ) {
			$next_step = __( 'The approval was declined. Ask again in the conversation only if you want to request that action again.', 'bhranti' );
		} elseif ( 'approval_conflict' === $reason ) {
			$next_step = __( 'Review the item’s current state before requesting a new action and approval.', 'bhranti' );
		} elseif ( 'run_incomplete' === $type ) {
			$next_step = __( 'Open the conversation to review completed work and the remaining steps.', 'bhranti' );
		} elseif ( 'error' === $status ) {
			$next_step = __( 'Review this event’s error code and the nearby events in its conversation before retrying.', 'bhranti' );
		}
		$diagnostic = self::ai_explanation( $type, $reason );
		if ( $diagnostic ) {
			$explanation = $diagnostic['explanation'];
			$next_step   = $diagnostic['next_step'];
		}
		$target   = self::target( $details );
		$labels   = array(
			'method'          => __( 'Method', 'bhranti' ),
			'path'            => __( 'WordPress route', 'bhranti' ),
			'tool'            => __( 'Tool', 'bhranti' ),
			'resource'        => __( 'Resource', 'bhranti' ),
			'action_id'       => __( 'Action ID', 'bhranti' ),
			'run_id'          => __( 'Task ID', 'bhranti' ),
			'job_id'          => __( 'Browser check ID', 'bhranti' ),
			'step'            => __( 'Step', 'bhranti' ),
			'target_id'       => __( 'Item ID', 'bhranti' ),
			'target_type'     => __( 'Item type', 'bhranti' ),
			'status'          => __( 'Recorded status', 'bhranti' ),
			'code'            => __( 'Error / result code', 'bhranti' ),
			'reason'          => __( 'Reason code', 'bhranti' ),
			'exception_class' => __( 'Error class', 'bhranti' ),
			'attempt'         => __( 'Attempt', 'bhranti' ),
			'duration_ms'     => __( 'Duration (ms)', 'bhranti' ),
			'operation'       => __( 'Operation', 'bhranti' ),
			'field'           => __( 'Field', 'bhranti' ),
			'result_count'    => __( 'Result count', 'bhranti' ),
		);
		$metadata = array( __( 'Event code', 'bhranti' ) => $type );
		foreach ( $details as $key => $value ) {
			if ( isset( $labels[ $key ] ) && '' !== (string) $value ) {
				$metadata[ $labels[ $key ] ] = $value;
			}
		}
		return array(
			'title'          => $title,
			'status'         => $status,
			'status_label'   => self::status_options()[ $status ],
			'category'       => $category,
			'category_label' => self::category_options()[ $category ],
			'explanation'    => $explanation,
			'next_step'      => $next_step,
			'target_label'   => $target['label'],
			'target_url'     => $target['url'],
			'metadata'       => $metadata,
		);
	}

	/**
	 * Explain finite native diagnostic reasons in the past tense for historical logs.
	 *
	 * The original evidence remains in storage and exports. Later recovery is
	 * separate activity; a historical failure never proves a connection is broken now.
	 *
	 * @param string $type Recorded event code.
	 * @param string $reason Finite reason emitted by Bhranti_Diagnostics.
	 * @return array Empty for unknown or non-AI diagnostics.
	 */
	private static function ai_explanation( $type, $reason ) {
		if ( ! str_starts_with( $type, 'ai_' ) || 'error' !== self::status( $type ) ) {
			return array();
		}
		$explanations = array(
			'structured_output'   => __( 'The AI returned an unreadable action plan for this request. No action ran from that response.', 'bhranti' ),
			'incomplete_response' => __( 'The AI response ended before this request could finish.', 'bhranti' ),
			'invalid_response'    => __( 'The AI response could not be used to complete this request.', 'bhranti' ),
			'output_limit'        => __( 'The AI response exceeded the output limit for this request.', 'bhranti' ),
			'authentication'      => __( 'The native AI connector could not authenticate this request.', 'bhranti' ),
			'permission'          => __( 'The native AI connector did not have permission to complete this request.', 'bhranti' ),
			'prevented'           => __( 'The native AI client blocked this request before it could complete.', 'bhranti' ),
			'model_unavailable'   => __( 'The AI model was unavailable or could not handle this request.', 'bhranti' ),
			'network'             => __( 'The native AI request encountered a connection problem.', 'bhranti' ),
			'upstream'            => __( 'The AI service returned an error for this request.', 'bhranti' ),
			'usage_limit'         => __( 'The AI provider reported a usage limit for this request.', 'bhranti' ),
			'unsupported_prompt'  => __( 'The AI connector did not support this request’s input or options.', 'bhranti' ),
			'protocol_version'    => __( 'The AI connector reported an incompatible protocol version for this request.', 'bhranti' ),
		);
		if ( ! isset( $explanations[ $reason ] ) ) {
			return array();
		}
		$next_step = __( 'Check related task activity for later retries or recovery.', 'bhranti' );
		switch ( $reason ) {
			case 'structured_output':
			case 'incomplete_response':
			case 'invalid_response':
			case 'output_limit':
				$next_step .= ' ' . __( 'If work is still unfinished, review saved work in the conversation and continue with a smaller next step.', 'bhranti' );
				break;
			case 'authentication':
				$next_step .= ' ' . __( 'If the task still needs attention, check the connection in Settings → Connectors before retrying.', 'bhranti' );
				break;
			case 'permission':
			case 'prevented':
				$next_step .= ' ' . __( 'If the request remains blocked, review native connector approvals and your account permissions.', 'bhranti' );
				break;
			case 'model_unavailable':
				$next_step .= ' ' . __( 'If work is still unfinished, check the available model in Settings → Connectors.', 'bhranti' );
				break;
			case 'network':
			case 'upstream':
				$next_step .= ' ' . __( 'If work is still unfinished, review the conversation’s saved work and available retry. Earlier completed actions were not undone by this event.', 'bhranti' );
				break;
			case 'usage_limit':
				$next_step .= ' ' . __( 'If the limit still applies, check the provider’s allowance or wait for it to reset before retrying.', 'bhranti' );
				break;
			case 'unsupported_prompt':
			case 'protocol_version':
				$next_step .= ' ' . __( 'If the task still needs attention, check connector updates and the selected model before retrying.', 'bhranti' );
				break;
		}
		return array(
			'explanation' => $explanations[ $reason ],
			'next_step'   => $next_step,
		);
	}

	/**
	 * Encode a standard NDJSON event line with stable raw evidence and UTC time.
	 *
	 * @param array $event Saved event.
	 * @return string JSON object followed by one newline.
	 */
	public static function export_event( $event ) {
		$description = self::describe( $event );
		$details     = self::safe_details( $event['details'] ?? array() );
		$timestamp   = strtotime( $event['created'] ?? '' );
		$record      = array(
			'format'     => 'bhranti.activity',
			'version'    => 1,
			'timestamp'  => $timestamp ? gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) : null,
			'event_id'   => absint( $event['event_id'] ?? 0 ),
			'id'         => self::safe_text( $event['id'] ?? '', 100 ),
			'level'      => array(
				'error'   => 'error',
				'warning' => 'warning',
			)[ $description['status'] ] ?? 'info',
			'status'     => $description['status'],
			'category'   => $description['category'],
			'event'      => sanitize_key( $event['type'] ?? '' ),
			'message'    => self::safe_text( $event['message'] ?? '', 600 ),
			'requester'  => array(
				'id'       => absint( $event['requester_id'] ?? 0 ),
				'username' => self::safe_text( $event['requester'] ?? '', 100 ),
			),
			'actor'      => array(
				'id'       => absint( $event['actor_id'] ?? 0 ),
				'username' => self::safe_text( $event['actor'] ?? '', 100 ),
			),
			'session_id' => absint( $event['session_id'] ?? 0 ),
			'run_id'     => $details['run_id'] ?? null,
			'action_id'  => $details['action_id'] ?? null,
			'target'     => array(
				'id'   => $details['target_id'] ?? null,
				'type' => $details['target_type'] ?? null,
			),
			'method'     => $details['method'] ?? null,
			'path'       => $details['path'] ?? null,
			'code'       => $details['code'] ?? null,
			'details'    => (object) $details,
		);
		return wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
	}

	/** Render the separately scoped native log screen. */
	public static function render() {
		Bhranti_Log_Screen::render();
	}

	/**
	 * Build a public invalid-filter error.
	 *
	 * @return WP_Error
	 */
	private static function filter_error() {
		return new WP_Error( 'bhranti_logs_filters', __( 'Choose valid log filters and an inclusive date range.', 'bhranti' ), array( 'status' => 400 ) );
	}

	/**
	 * Keep text inert, bounded, and strip common credential-bearing values.
	 *
	 * @param mixed $value Candidate public text.
	 * @param int   $length Maximum characters.
	 * @return string
	 */
	private static function safe_text( $value, $length = 300 ) {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		$value = preg_replace( '/(?:Bearer\s+[A-Za-z0-9._~+\/-]+=*|sk-[A-Za-z0-9_-]{10,}|(?:password|api[_ -]?key|access[_ -]?token|authorization)\s*[:=]\s*[^\s,;]+)/i', '[redacted]', $value );
		$value = preg_replace( '#https?://[^\s<>]+#i', '[URL omitted]', $value );
		return mb_substr( $value, 0, $length );
	}

	/**
	 * Retain identifiers only; never retain parameters, prompts, content or provider bodies.
	 *
	 * @param array $details Candidate operation details.
	 * @return array
	 */
	private static function safe_details( $details ) {
		if ( ! is_array( $details ) ) {
			return array();
		}
		if ( isset( $details['target'] ) && is_array( $details['target'] ) ) {
			$details['target_id']   = $details['target']['id'] ?? '';
			$details['target_type'] = $details['target']['type'] ?? '';
		}
		$safe = array();
		foreach ( array( 'method', 'path', 'action_id', 'target_id', 'target_type', 'status', 'code', 'reason', 'exception_class', 'run_id', 'tool', 'resource', 'job_id', 'step', 'attempt', 'duration_ms', 'operation', 'field', 'result_count' ) as $key ) {
			if ( ! isset( $details[ $key ] ) || ! is_scalar( $details[ $key ] ) ) {
				continue;
			}
			$value = self::safe_text( $details[ $key ] );
			if ( 'path' === $key ) {
				$value = explode( '?', explode( '#', $value )[0] )[0];
				if ( ! preg_match( '#^/?[a-zA-Z0-9_./:%-]{1,300}$#', $value ) ) {
					continue;
				}
			} elseif ( ! preg_match( '#^[a-zA-Z0-9_./: -]{0,300}$#', str_replace( chr( 92 ), '', $value ) ) ) {
				continue;
			}
			$safe[ $key ] = $value;
		}
		return $safe;
	}

	/**
	 * Search only safe identifiers and the public event summary in new records.
	 *
	 * @param array $event Evidence.
	 * @param array $description Presentation.
	 * @return string
	 */
	private static function search_text( $event, $description ) {
		return implode( ' ', array_merge( array( $event['id'], $event['type'], $event['message'], (string) $event['session_id'], $event['requester'], (string) $event['requester_id'], $event['actor'], (string) $event['actor_id'], $description['title'], $description['category_label'], $description['status_label'] ), array_values( $event['details'] ) ) );
	}

	/**
	 * Match one indexed or legacy metadata value with a prepared local subquery.
	 *
	 * @param string $key Metadata key.
	 * @param string $value Expected value or escaped LIKE pattern.
	 * @param bool   $like Pattern comparison.
	 * @return string Prepared SQL expression.
	 */
	private static function meta_condition( $key, $value, $like = false ) {
		global $wpdb;
		return $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->postmeta} em WHERE em.post_id = p.ID AND em.meta_key = %s AND em.meta_value " . ( $like ? 'LIKE' : '=' ) . ' %s)', $key, $value ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Both operator branches are literal SQL.
	}

	/**
	 * Query one exact historical field without PHP deserializing the collection.
	 *
	 * @param string $key Fixed field name.
	 * @param string $value Exact value.
	 * @param bool   $integer Historical integer rather than string.
	 * @return string Prepared SQL expression.
	 */
	private static function serialized_field_sql( $key, $value, $integer = false ) {
		global $wpdb;
		$fragment = 's:' . strlen( $key ) . ':"' . $key . '";' . ( $integer ? 'i:' . absint( $value ) . ';' : 's:' . strlen( $value ) . ':"' . $value . '";' );
		return self::meta_condition( 'bhranti_event_data', '%' . $wpdb->esc_like( $fragment ) . '%', true );
	}

	/**
	 * Match historical event codes by a fixed suffix/prefix.
	 *
	 * @param string $prefix Fixed code prefix.
	 * @param string $suffix Fixed code suffix.
	 * @return string Prepared SQL expression.
	 */
	private static function legacy_type_sql( $prefix = '', $suffix = '' ) {
		global $wpdb;
		return self::legacy_string_sql( 'type', $wpdb->esc_like( $prefix ) . '%' . $wpdb->esc_like( $suffix ) );
	}

	/**
	 * Match only the exact old serialized scalar, rather than a later key or value.
	 *
	 * The legacy identifiers are plain scalar strings. SUBSTR and INSTR are native
	 * to MySQL and SQLite, and keep historical filtering entirely in the database.
	 *
	 * @param string $key Fixed legacy field name.
	 * @param string $pattern Escaped LIKE pattern.
	 * @return string Prepared SQL expression.
	 */
	private static function legacy_string_sql( $key, $pattern ) {
		global $wpdb;
		$anchor = '"' . $key . '";s:';
		$tail   = $wpdb->prepare( 'SUBSTR(em.meta_value, INSTR(em.meta_value, %s) + %d)', $anchor, strlen( $anchor ) );
		$body   = "SUBSTR({$tail}, INSTR({$tail}, ':\"') + 2)";
		$value  = "SUBSTR({$body}, 1, INSTR({$body}, '\";') - 1)";
		$check  = $wpdb->prepare( 'INSTR(em.meta_value, %s) > 0', $anchor );
		$match  = $wpdb->prepare( ' LIKE %s', $pattern );
		return "EXISTS (SELECT 1 FROM {$wpdb->postmeta} em WHERE em.post_id = p.ID AND em.meta_key = 'bhranti_event_data' AND {$check} AND {$value}{$match})";
	}
	/**
	 * Event suffix groups; these exact rules also drive historical SQL filters.
	 *
	 * @return array
	 */
	private static function status_suffixes() {
		return array(
			'error'   => array( '_failed', '_error' ),
			'warning' => array( '_blocked', '_denied', '_cancelled', '_incomplete', '_stopped' ),
			'pending' => array( '_started', '_prepared', '_saving', '_requested', '_required', '_awaiting_input', '_retry', '_recovery', '_resumed', '_paused' ),
			'success' => array( '_complete', '_completed', '_result', '_reused', '_approved' ),
		);
	}

	/**
	 * Classify only the event's own lifecycle code, never a subsequent task result.
	 *
	 * @param string $type Event code.
	 * @return string
	 */
	private static function status( $type ) {
		foreach ( self::status_suffixes() as $status => $suffixes ) {
			foreach ( $suffixes as $suffix ) {
				if ( str_ends_with( $type, $suffix ) ) {
					return $status;
				}
			}
		}
		return 'info';
	}

	/**
	 * Build historical lifecycle classification using the same fixed suffixes.
	 *
	 * @param string $status Status key.
	 * @return string Prepared SQL expression.
	 */
	private static function legacy_status_sql( $status ) {
		$matches = array();
		foreach ( self::status_suffixes() as $candidate => $suffixes ) {
			if ( 'info' !== $status && $candidate !== $status ) {
				continue;
			}
			foreach ( $suffixes as $suffix ) {
				$matches[] = self::legacy_type_sql( '', $suffix );
			}
		}
		return ( 'info' === $status ? 'NOT ' : '' ) . '(' . implode( ' OR ', $matches ) . ')';
	}

	/**
	 * Define category prefixes with first-match precedence.
	 *
	 * @return array
	 */
	private static function category_prefixes() {
		return array(
			'undo'     => array( 'rollback_', 'plugin_rollback_' ),
			'approval' => array( 'confirmation_' ),
			'ai'       => array( 'ai_' ),
			'task'     => array( 'run_', 'task_', 'background_' ),
			'media'    => array( 'media_', 'bhranti_image_' ),
			'plugins'  => array( 'plugin_' ),
			'commerce' => array( 'commerce_' ),
			'settings' => array( 'settings_' ),
			'memory'   => array( 'memory_' ),
			'system'   => array( 'actor_', 'reference_' ),
		);
	}

	/**
	 * Classify known resource identifiers in ordinary WordPress actions.
	 *
	 * @return array Fixed category path fragments.
	 */
	private static function category_paths() {
		return array(
			'commerce' => array( '/wc/' ),
			'plugins'  => array( '/wp/v2/plugins' ),
			'media'    => array( '/wp/v2/media' ),
			'settings' => array( '/bhranti/v1/settings', '/bhranti/v1/task-limit', 'bhranti-settings', 'bhranti-task-limit', '/wp/v2/settings' ),
			'system'   => array( 'bhranti-logs' ),
		);
	}

	/**
	 * Classify metadata without reading content or chat titles.
	 *
	 * @param string $type Event code.
	 * @param array  $details Safe metadata.
	 * @return string
	 */
	private static function category( $type, $details ) {
		foreach ( self::category_prefixes() as $category => $prefixes ) {
			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( $type, $prefix ) ) {
					return $category;
				}
			}
		}
		foreach ( self::category_paths() as $category => $paths ) {
			foreach ( $paths as $path ) {
				if ( str_starts_with( $details['path'] ?? '', $path ) ) {
					return $category;
				}
			}
		}
		return 'content';
	}

	/**
	 * Historical category filters preserve classification precedence.
	 *
	 * @param string $category Category key.
	 * @return string Prepared SQL expression.
	 */
	private static function legacy_category_sql( $category ) {
		global $wpdb;
		$previous = array();
		$wanted   = array();
		foreach ( self::category_prefixes() as $candidate => $prefixes ) {
			foreach ( $prefixes as $prefix ) {
				$expression = self::legacy_type_sql( $prefix );
				if ( $candidate === $category ) {
					$wanted[] = '(' . $expression . ( $previous ? ' AND NOT (' . implode( ' OR ', $previous ) . ')' : '' ) . ')';
				}
				$previous[] = $expression;
			}
		}
		foreach ( self::category_paths() as $candidate => $paths ) {
			foreach ( $paths as $path ) {
				$expression = self::legacy_string_sql( 'path', $wpdb->esc_like( $path ) . '%' );
				if ( $candidate === $category ) {
					$wanted[] = '(' . $expression . ' AND NOT (' . implode( ' OR ', $previous ) . '))';
				}
				$previous[] = $expression;
			}
		}
		return 'content' === $category ? 'NOT (' . implode( ' OR ', $previous ) . ')' : '(' . implode( ' OR ', $wanted ) . ')';
	}

	/**
	 * Link only recognized native screens, and only with the viewer's permission.
	 *
	 * @param array $details Safe event identifiers.
	 * @return array Label and same-site native edit URL.
	 */
	private static function target( $details ) {
		$type  = $details['target_type'] ?? '';
		$id    = $details['target_id'] ?? '';
		$path  = $details['path'] ?? '';
		$label = '';
		$url   = '';
		if ( ! $id && preg_match( '#^/wp/v2/(posts|pages|media|templates|template-parts)/([0-9]+)$#', $path, $matches ) ) {
			$id   = $matches[2];
			$type = array(
				'posts'          => 'post',
				'pages'          => 'page',
				'media'          => 'attachment',
				'templates'      => 'wp_template',
				'template-parts' => 'wp_template_part',
			)[ $matches[1] ];
		}
		$types = array(
			'post'               => __( 'Post', 'bhranti' ),
			'page'               => __( 'Page', 'bhranti' ),
			'attachment'         => __( 'Media item', 'bhranti' ),
			'product'            => __( 'Product', 'bhranti' ),
			'wp_template'        => __( 'Template', 'bhranti' ),
			'wp_template_part'   => __( 'Template part', 'bhranti' ),
			'wp_navigation'      => __( 'Navigation', 'bhranti' ),
			'wpcf7_contact_form' => __( 'Contact form', 'bhranti' ),
		);
		if ( isset( $types[ $type ] ) && ctype_digit( (string) $id ) && (int) $id > 0 ) {
			$label = $types[ $type ] . ' #' . $id;
			$post  = get_post( (int) $id );
			if ( $post && $post->post_type === $type && current_user_can( 'edit_post', $post->ID ) ) {
				$url = (string) get_edit_post_link( $post->ID, 'raw' );
			}
		} elseif ( 'plugin' === $type || str_starts_with( $path, '/wp/v2/plugins' ) ) {
			/* translators: %s: Native plugin identifier. */
			$label = $id ? sprintf( __( 'Plugin: %s', 'bhranti' ), $id ) : __( 'Plugins', 'bhranti' );
			$url   = current_user_can( 'activate_plugins' ) ? admin_url( 'plugins.php' ) : '';
		} elseif ( in_array( $type, array( 'bhranti_task_limit', 'bhranti_settings' ), true ) || str_starts_with( $path, '/bhranti/v1/task-limit' ) || str_starts_with( $path, '/bhranti/v1/settings' ) || str_contains( $path, 'bhranti-task-limit' ) || str_contains( $path, 'bhranti-settings' ) ) {
			$label = __( 'Bhranti settings', 'bhranti' );
			$url   = current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=bhranti' ) : '';
		} elseif ( $type || $id ) {
			$label = trim( $type . ( $id ? ' #' . $id : '' ) );
		}
		return array(
			'label' => $label,
			'url'   => $url,
		);
	}
}
