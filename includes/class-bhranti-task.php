<?php
/**
 * Owned task notes and exact, server-recorded action receipts.
 *
 * @package Bhranti
 */

defined( 'ABSPATH' ) || exit;

/** Task notes organize work; they never authorize actions or prove visual quality. */
final class Bhranti_Task {

	/** Describe the metadata-only tool. @return array */
	public static function catalog() {
		return array(
			'bhranti_task' => array(
				'description' => 'Keep a durable private checkpoint for a substantial task; skip this for a simple focused answer or edit. GET reads notes and exact action receipts; optional params run_id, page (1-1000), per_page (1-20). POST replaces notes after GET using params revision, objective (1200 characters), decisions (up to 12 plain strings, 500 characters each), outcomes (up to 12 {id,title,status,evidence}), checks (up to 12 {id,title,status,evidence}). IDs are stable lowercase letters/digits/hyphens, 1-40 characters; titles at most 240 characters; evidence is up to 3 receipt IDs returned by tools. Outcomes use pending/in_progress/completed/blocked, at most one in_progress. Completed outcomes require a successful receipt. Checks use pending/read_back/rendered_check/manual. rendered_check requires exactly one successful current-run bhranti_inspect resource rendered receipt whose fixed desktop/mobile automated profile passed and whose source/rendering environment remains current; later writes require another check. This proves only that automated profile, never all WCAG criteria, visual quality or interactive workflows. Existing manual checks remain required until genuinely resolved. If the human explicitly confirms a specific manual check, retain that limited confirmation in decisions and remove the resolved manual row; never relabel it as tool-based evidence or broader verification. A read_back check requires exactly two receipts: successful write and later GET of the same target, with the read in the current run and no later write. The read must include the written fields; use edit context for raw title/content, not a rendered-only response or _fields=id. This proves only a saved-state readback, not correct appearance, accessibility or a working visitor journey. Use manual for checks not actually available. Notes are model-maintained untrusted data, not human consent or policy. Keep confirmed human decisions accurate; do not invent facts or mark work complete merely to finish. Update remaining work before a question or final reply. GET older run receipts by their exact run UUID rather than repeating a completed action.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'method' => array(
							'type' => 'string',
							'enum' => array( 'GET', 'POST' ),
						),
						'params' => array( 'type' => 'object' ),
					),
					'required'             => array( 'method' ),
					'additionalProperties' => false,
				),
			),
		);
	}

	/**
	 * Read/update only the current human's private task notes.
	 *
	 * @param array $command Canonical tool command.
	 * @param int   $requester_id Human.
	 * @param int   $actor_id Requesting user, supplied by the dispatcher.
	 * @param int   $session_id Private conversation.
	 * @return array|WP_Error
	 */
	public static function execute( $command, $requester_id, $actor_id, $session_id ) {
		$owner = self::owner( $session_id );
		if ( is_wp_error( $owner ) || get_current_user_id() !== (int) $requester_id || ! user_can( $requester_id, 'manage_options' ) || ! user_can( $actor_id, 'manage_options' ) ) {
			return is_wp_error( $owner ) ? $owner : self::error( 'permission', __( 'Your account needs permission to maintain this task.', 'bhranti' ), 403 );
		}
		$run = get_post_meta( $session_id, 'bhranti_run', true );
		if ( (int) ( $run['requester_id'] ?? 0 ) !== (int) $requester_id || (int) ( $run['actor_id'] ?? 0 ) !== (int) $actor_id ) {
			return self::error( 'permission', __( 'This task belongs to a different request.', 'bhranti' ), 403 );
		}
		$args   = $command['args'] ?? array();
		$method = $args['method'] ?? '';
		$params = $args['params'] ?? array();
		if ( 'bhranti_task' !== ( $command['tool'] ?? '' ) || ! is_array( $args ) || array_diff( array_keys( $args ), array( 'method', 'params' ) ) || ! in_array( $method, array( 'GET', 'POST' ), true ) || ! is_array( $params ) ) {
			return self::error( 'schema', __( 'Use the task tool’s documented fields.', 'bhranti' ) );
		}
		$logged = Bhranti_Audit::record( 'task_started', __( 'Reading or updating the private task checkpoint.', 'bhranti' ), $session_id, array( 'method' => $method ), $requester_id, $actor_id );
		if ( is_wp_error( $logged ) ) {
			return $logged;
		}
		$result = self::operate( $session_id, $method, $params );
		$audit  = Bhranti_Audit::record(
			is_wp_error( $result ) ? 'task_failed' : 'task_completed',
			is_wp_error( $result ) ? __( 'The task checkpoint request could not be completed.', 'bhranti' ) : __( 'The private task checkpoint is available.', 'bhranti' ),
			$session_id,
			array(
				'method' => $method,
				'code'   => is_wp_error( $result ) ? $result->get_error_code() : '',
			),
			$requester_id,
			$actor_id
		);
		return is_wp_error( $audit ) ? $audit : $result;
	}

	/**
	 * Validate the entire replacement before changing metadata.
	 *
	 * @param int    $session_id Session.
	 * @param string $method GET or POST.
	 * @param array  $params Strict parameters.
	 * @return array|WP_Error
	 */
	private static function operate( $session_id, $method, $params ) {
		$state = self::state( $session_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( 'GET' === $method ) {
			$page     = $params['page'] ?? 1;
			$per_page = $params['per_page'] ?? 10;
			$run      = get_post_meta( $session_id, 'bhranti_run', true );
			$run_id   = $params['run_id'] ?? ( $run['id'] ?? '' );
			if ( array_diff( array_keys( $params ), array( 'run_id', 'page', 'per_page' ) ) || ! is_int( $page ) || $page < 1 || $page > 1000 || ! is_int( $per_page ) || $per_page < 1 || $per_page > 20 || ! self::uuid( $run_id ) ) {
				return self::error( 'schema', __( 'Use a valid task run and bounded receipt page.', 'bhranti' ) );
			}
			$receipts = self::receipts( $session_id, $run_id );
			return array(
				'summary'            => __( 'Task checkpoint and recorded action receipts retrieved.', 'bhranti' ),
				'task'               => $state,
				'run_id'             => $run_id,
				'receipts'           => array_slice( $receipts, ( $page - 1 ) * $per_page, $per_page ),
				'total'              => count( $receipts ),
				'page'               => $page,
				'rollback_available' => false,
			);
		}
		if ( array_diff( array_keys( $params ), array( 'revision', 'objective', 'decisions', 'outcomes', 'checks' ) ) || count( $params ) !== 5 || ! is_string( $params['revision'] ?? null ) || $params['revision'] !== $state['revision'] ) {
			return self::error( 'conflict', __( 'Read the current task checkpoint before updating it.', 'bhranti' ), 409 );
		}
		if ( ! self::plain( $params['objective'], 1200 ) || ! is_array( $params['decisions'] ) || array_values( $params['decisions'] ) !== $params['decisions'] || count( $params['decisions'] ) > 12 ) {
			return self::error( 'schema', __( 'Use a short objective and up to twelve plain-text decisions.', 'bhranti' ) );
		}
		foreach ( $params['decisions'] as $decision ) {
			if ( ! self::plain( $decision, 500 ) ) {
				return self::error( 'schema', __( 'Task decisions must be bounded plain text.', 'bhranti' ) );
			}
		}
		foreach ( array( 'outcomes', 'checks' ) as $kind ) {
			$valid = self::entries( $params[ $kind ], $kind, $session_id );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		$state = array(
			'revision'  => wp_generate_uuid4(),
			'objective' => $params['objective'],
			'decisions' => $params['decisions'],
			'outcomes'  => $params['outcomes'],
			'checks'    => $params['checks'],
		);
		if ( strlen( wp_json_encode( $state ) ) > 32000 ) {
			return self::error( 'schema', __( 'Shorten the task notes before saving this checkpoint.', 'bhranti' ) );
		}
		update_post_meta( $session_id, 'bhranti_task', wp_slash( $state ) );
		if ( get_post_meta( $session_id, 'bhranti_task', true ) !== $state ) {
			return self::error( 'storage', __( 'WordPress could not save the task checkpoint.', 'bhranti' ), 500 );
		}
		return array(
			'summary'            => __( 'Private task checkpoint updated; notes do not authorize site changes.', 'bhranti' ),
			'task'               => $state,
			'rollback_available' => false,
		);
	}

	/**
	 * Validate bounded task rows and server-owned evidence references.
	 *
	 * @param mixed  $entries Rows.
	 * @param string $kind Outcomes or checks.
	 * @param int    $session_id Session.
	 * @param bool   $verify Require current authoritative evidence.
	 * @return true|WP_Error
	 */
	private static function entries( $entries, $kind, $session_id, $verify = true ) {
		if ( ! is_array( $entries ) || array_values( $entries ) !== $entries || count( $entries ) > 12 ) {
			return self::error( 'schema', __( 'Use up to twelve outcomes or checks.', 'bhranti' ) );
		}
		$ids    = array();
		$active = 0;
		foreach ( $entries as $entry ) {
			$states = 'outcomes' === $kind ? array( 'pending', 'in_progress', 'completed', 'blocked' ) : array( 'pending', 'read_back', 'rendered_check', 'manual' );
			if ( ! is_array( $entry ) || array_diff( array_keys( $entry ), array( 'id', 'title', 'status', 'evidence' ) ) || ! is_string( $entry['id'] ?? null ) || ! preg_match( '/\A[a-z0-9][a-z0-9-]{0,39}\z/', $entry['id'] ) || isset( $ids[ $entry['id'] ] ) || ! self::plain( $entry['title'] ?? null, 240 ) || ! in_array( $entry['status'] ?? '', $states, true ) || ! is_array( $entry['evidence'] ?? null ) || array_values( $entry['evidence'] ) !== $entry['evidence'] || count( $entry['evidence'] ) > 3 ) {
				return self::error( 'schema', __( 'Use unique task IDs, short titles, documented states and exact receipt IDs.', 'bhranti' ) );
			}
			$ids[ $entry['id'] ] = true;
			$active             += 'in_progress' === $entry['status'] ? 1 : 0;
			foreach ( $entry['evidence'] as $reference ) {
				if ( ! is_string( $reference ) || ! preg_match( '/\A[a-f0-9-]{36}:[1-9][0-9]{0,3}\z/', $reference ) ) {
					return self::error( 'evidence', __( 'Use an exact recorded receipt ID.', 'bhranti' ) );
				}
				if ( ! $verify ) {
					continue;
				}
				$receipt = self::receipt( $session_id, $reference );
				if ( ! $receipt || empty( $receipt['success'] ) || in_array( $receipt['tool'], array( 'bhranti_task', 'bhranti_skill' ), true ) ) {
					return self::error( 'evidence', __( 'Task evidence must refer to a successful recorded action in this conversation.', 'bhranti' ) );
				}
			}
			if ( 'completed' === $entry['status'] && ! $entry['evidence'] ) {
				return self::error( 'evidence', __( 'A completed outcome needs a recorded action; a note alone does not prove completion.', 'bhranti' ) );
			}
			if ( $verify && 'read_back' === $entry['status'] && ! self::read_back( $session_id, $entry['evidence'] ) ) {
				return self::error( 'evidence', __( 'A saved-state check needs a write and a later current-run read of the same item, with no newer write. Visual and visitor checks remain manual.', 'bhranti' ) );
			}
			if ( $verify && 'rendered_check' === $entry['status'] && ! self::rendered_check( $session_id, $entry['evidence'] ) ) {
				return self::error( 'evidence', __( 'An automated rendered check needs one current-run passing browser receipt for the unchanged saved item and rendering environment. It does not certify all WCAG criteria or interactive workflows.', 'bhranti' ) );
			}
		}
		return $active > 1 ? self::error( 'schema', __( 'Keep only one task outcome in progress.', 'bhranti' ) ) : true;
	}

	/**
	 * Return display-safe notes after checking conversation ownership.
	 *
	 * @param int $session_id Session.
	 * @return array|WP_Error
	 */
	public static function state( $session_id ) {
		$owner = self::owner( $session_id );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$value = get_post_meta( $session_id, 'bhranti_task', true );
		if ( '' === $value ) {
			return array(
				'revision'  => 'initial',
				'objective' => '',
				'decisions' => array(),
				'outcomes'  => array(),
				'checks'    => array(),
			);
		}
		if ( ! is_array( $value ) || strlen( wp_json_encode( $value ) ) > 32000 || ! self::uuid( $value['revision'] ?? '' ) || ! self::plain( $value['objective'] ?? null, 1200 ) || ! is_array( $value['decisions'] ?? null ) || ! is_array( $value['outcomes'] ?? null ) || ! is_array( $value['checks'] ?? null ) || array_diff( array_keys( $value ), array( 'revision', 'objective', 'decisions', 'outcomes', 'checks' ) ) ) {
			return self::error( 'storage', __( 'The saved task checkpoint needs review.', 'bhranti' ), 500 );
		}
		if ( array_values( $value['decisions'] ) !== $value['decisions'] || count( $value['decisions'] ) > 12 ) {
			return self::error( 'storage', __( 'The saved task checkpoint needs review.', 'bhranti' ), 500 );
		}
		foreach ( $value['decisions'] as $decision ) {
			if ( ! self::plain( $decision, 500 ) ) {
				return self::error( 'storage', __( 'The saved task checkpoint needs review.', 'bhranti' ), 500 );
			}
		}
		foreach ( array( 'outcomes', 'checks' ) as $kind ) {
			if ( is_wp_error( self::entries( $value[ $kind ], $kind, $session_id, false ) ) ) {
				return self::error( 'storage', __( 'The saved task checkpoint needs review.', 'bhranti' ), 500 );
			}
		}
		foreach ( $value['checks'] as $index => $check ) {
			if ( ( 'read_back' === $check['status'] && ! self::read_back( $session_id, $check['evidence'] ) ) || ( 'rendered_check' === $check['status'] && ! self::rendered_check( $session_id, $check['evidence'] ) ) ) {
				// Display current evidence honestly without rewriting historical notes.
				$value['checks'][ $index ]['status'] = 'pending';
			}
		}
		return $value;
	}

	/**
	 * List unresolved work without claiming a model's notes verify the result.
	 *
	 * @param int $session_id Session.
	 * @return array
	 */
	public static function remaining( $session_id ) {
		$state = self::state( $session_id );
		if ( is_wp_error( $state ) ) {
			return array( __( 'Review the saved task checkpoint.', 'bhranti' ) );
		}
		$remaining = array();
		foreach ( $state['outcomes'] as $entry ) {
			if ( 'completed' !== $entry['status'] ) {
				$remaining[] = $entry['title'];
			}
		}
		foreach ( $state['checks'] as $entry ) {
			$verified = ( 'read_back' === $entry['status'] && self::read_back( $session_id, $entry['evidence'] ) ) || ( 'rendered_check' === $entry['status'] && self::rendered_check( $session_id, $entry['evidence'] ) );
			if ( ! $verified ) {
				$remaining[] = $entry['title'];
			}
		}
		return array_slice( array_unique( $remaining ), 0, 24 );
	}

	/**
	 * Archive one bounded run before a new question/answer run replaces it.
	 *
	 * @param int   $session_id Session.
	 * @param array $run Existing authoritative run.
	 * @return true|WP_Error
	 */
	public static function archive( $session_id, $run ) {
		if ( empty( $run['receipts'] ) ) {
			return true;
		}
		$owner = self::owner( $session_id );
		if ( is_wp_error( $owner ) || ! self::uuid( $run['id'] ?? '' ) || ! self::valid_receipts( $run['receipts'], $run['id'] ) ) {
			return is_wp_error( $owner ) ? $owner : self::error( 'storage', __( 'The previous task receipts could not be retained.', 'bhranti' ), 500 );
		}
		$index = self::archive_index( $session_id );
		if ( is_wp_error( $index ) ) {
			return $index;
		}
		if ( ! in_array( $run['id'], $index, true ) ) {
			if ( count( $index ) >= 100 ) {
				return self::error( 'full', __( 'This conversation has reached its retained task limit. Start a new conversation; its saved actions and notes remain available here.', 'bhranti' ), 409 );
			}
			$index[] = $run['id'];
		}
		$bytes = strlen( wp_json_encode( $run['receipts'] ) );
		foreach ( $index as $run_id ) {
			if ( $run_id === $run['id'] ) {
				continue;
			}
			$items = get_post_meta( $session_id, 'bhranti_receipts_' . $run_id, true );
			if ( ! self::valid_receipts( $items, $run_id ) ) {
				return self::error( 'storage', __( 'The previous task receipts could not be retained.', 'bhranti' ), 500 );
			}
			$bytes += strlen( wp_json_encode( $items ) );
		}
		if ( $bytes > 4000000 ) {
			return self::error( 'full', __( 'This conversation has reached its retained task limit. Start a new conversation; its saved actions and notes remain available here.', 'bhranti' ), 409 );
		}
		// Reserve the finite index first. A failed receipt write is retried from
		// the still-current run; neither failure discards existing receipts.
		update_post_meta( $session_id, 'bhranti_receipt_runs', wp_slash( $index ) );
		if ( get_post_meta( $session_id, 'bhranti_receipt_runs', true ) !== $index ) {
			return self::error( 'storage', __( 'The previous task receipts could not be retained.', 'bhranti' ), 500 );
		}
		$key = 'bhranti_receipts_' . $run['id'];
		update_post_meta( $session_id, $key, wp_slash( $run['receipts'] ) );
		return get_post_meta( $session_id, $key, true ) === $run['receipts'] ? true : self::error( 'storage', __( 'The previous task receipts could not be retained.', 'bhranti' ), 500 );
	}

	/**
	 * Native privacy export after its caller has selected the requested owner's posts.
	 *
	 * @param int $session_id Previously owner-selected private conversation.
	 * @return array
	 */
	public static function export_data( $session_id ) {
		$post = get_post( $session_id );
		if ( ! $post || 'bhranti_session' !== $post->post_type ) {
			return array();
		}
		$index = self::archive_index( $session_id );
		$data  = array(
			'checkpoint' => get_post_meta( $session_id, 'bhranti_task', true ),
			'runs'       => array(),
		);
		foreach ( is_array( $index ) ? $index : array() as $run_id ) {
			$items = get_post_meta( $session_id, 'bhranti_receipts_' . $run_id, true );
			if ( self::valid_receipts( $items, $run_id ) ) {
				$data['runs'][ $run_id ] = $items;
			}
		}
		$run = get_post_meta( $session_id, 'bhranti_run', true );
		if ( self::uuid( $run['id'] ?? '' ) && self::valid_receipts( $run['receipts'] ?? null, $run['id'] ) ) {
			$data['runs'][ $run['id'] ] = $run['receipts'];
		}
		return $data;
	}

	/**
	 * Read only the finite archive index, never enumerate arbitrary metadata.
	 *
	 * @param int $session_id Conversation.
	 * @return array|WP_Error
	 */
	private static function archive_index( $session_id ) {
		$index = get_post_meta( $session_id, 'bhranti_receipt_runs', true );
		if ( '' === $index ) {
			return array();
		}
		if ( ! is_array( $index ) || array_values( $index ) !== $index || count( $index ) > 100 ) {
			return self::error( 'storage', __( 'The task receipt index needs review.', 'bhranti' ), 500 );
		}
		foreach ( $index as $run_id ) {
			if ( ! self::uuid( $run_id ) ) {
				return self::error( 'storage', __( 'The task receipt index needs review.', 'bhranti' ), 500 );
			}
		}
		return array_values( array_unique( $index ) );
	}

	/**
	 * Read a bounded owned receipt page's source; never run a prior command.
	 *
	 * @param int    $session_id Session.
	 * @param string $run_id UUID.
	 * @return array
	 */
	public static function receipts( $session_id, $run_id ) {
		if ( ! self::uuid( $run_id ) || is_wp_error( self::owner( $session_id ) ) ) {
			return array();
		}
		$run   = get_post_meta( $session_id, 'bhranti_run', true );
		$items = ( $run['id'] ?? '' ) === $run_id ? ( $run['receipts'] ?? array() ) : get_post_meta( $session_id, 'bhranti_receipts_' . $run_id, true );
		return self::valid_receipts( $items, $run_id ) ? $items : array();
	}

	/**
	 * Reject malformed stored evidence; notes cannot supply authoritative receipts.
	 *
	 * @param mixed  $items Stored receipts.
	 * @param string $run_id Expected run UUID.
	 * @return bool
	 */
	private static function valid_receipts( $items, $run_id ) {
		if ( ! is_array( $items ) || array_values( $items ) !== $items || count( $items ) > 1000 || strlen( wp_json_encode( $items ) ) > 3000000 ) {
			return false;
		}
		$steps = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_diff( array_keys( $item ), array( 'id', 'run_id', 'step', 'tool', 'method', 'path', 'operation', 'success', 'code', 'entity', 'references', 'target', 'action_id', 'fields', 'coverage', 'command_hash', 'content_revision', 'created', 'inspection_scope', 'render_check' ) ) || ! is_int( $item['step'] ?? null ) || $item['step'] < 1 || $item['step'] > 1000 || isset( $steps[ $item['step'] ] ) || ( $item['run_id'] ?? '' ) !== $run_id || ( $item['id'] ?? '' ) !== $run_id . ':' . $item['step'] || ! is_bool( $item['success'] ?? null ) || ! in_array( $item['operation'] ?? '', array( 'read', 'write', 'image', 'metadata' ), true ) ) {
				return false;
			}
			$steps[ $item['step'] ] = true;
			if ( isset( $item['created'] ) && ! is_bool( $item['created'] ) ) {
				return false;
			}
			foreach ( array( 'command_hash', 'content_revision' ) as $key ) {
				if ( isset( $item[ $key ] ) && ( ! is_string( $item[ $key ] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $item[ $key ] ) ) ) {
					return false;
				}
			}
			if ( array_key_exists( 'inspection_scope', $item ) ) {
				$scope = $item['inspection_scope'];
				if ( 'bhranti_inspect' !== ( $item['tool'] ?? '' ) || true !== $item['success'] || 'read' !== $item['operation'] || 'GET' !== ( $item['method'] ?? '' ) || ! isset( $item['content_revision'] ) || ! is_array( $scope ) || array_diff( array_keys( $scope ), array( 'page', 'per_page', 'block_path' ) ) || ! is_int( $scope['page'] ?? null ) || $scope['page'] < 1 || $scope['page'] > 250 || ! is_int( $scope['per_page'] ?? null ) || $scope['per_page'] < 1 || $scope['per_page'] > 20 || ( array_key_exists( 'block_path', $scope ) && ( ! is_string( $scope['block_path'] ) || strlen( $scope['block_path'] ) > 190 || ! preg_match( '#\A(?:0|[1-9][0-9]*)(?:/(?:0|[1-9][0-9]*))*\z#', $scope['block_path'] ) ) ) ) {
					return false;
				}
			}
			if ( array_key_exists( 'render_check', $item ) && ( 'bhranti_inspect' !== ( $item['tool'] ?? '' ) || true !== $item['success'] || 'read' !== $item['operation'] || 'GET' !== ( $item['method'] ?? '' ) || ! class_exists( 'Bhranti_Render' ) || ! Bhranti_Render::valid_receipt( $item['render_check'] ) || ( $item['content_revision'] ?? '' ) !== $item['render_check']['source_revision'] ) ) {
				return false;
			}
			if ( $item['success'] ) {
				if ( ! is_bool( $item['coverage'] ?? null ) || ! is_array( $item['fields'] ?? null ) || array_values( $item['fields'] ) !== $item['fields'] || count( $item['fields'] ) > 128 ) {
					return false;
				}
				foreach ( $item['fields'] as $field ) {
					if ( ! is_string( $field ) || strlen( $field ) > 160 || ! preg_match( '#\A[a-zA-Z0-9_./-]+\z#', $field ) ) {
						return false;
					}
				}
			}
			foreach ( array(
				'tool'   => 50,
				'method' => 12,
				'path'   => 500,
				'code'   => 100,
				'entity' => 600,
			) as $key => $limit ) {
				if ( ! is_string( $item[ $key ] ?? null ) || strlen( $item[ $key ] ) > $limit ) {
					return false;
				}
			}
			if ( isset( $item['action_id'] ) && ( ! is_string( $item['action_id'] ) || strlen( $item['action_id'] ) > 100 ) ) {
				return false;
			}
			$target = $item['target'] ?? array();
			if ( ! self::reference_fields(
				$target,
				array(
					'id'    => 256,
					'type'  => 100,
					'title' => 500,
					'url'   => 2048,
				)
			) || ! is_array( $item['references'] ?? null ) || array_values( $item['references'] ) !== $item['references'] || count( $item['references'] ) > 100 ) {
				return false;
			}
			foreach ( $item['references'] as $reference ) {
				if ( ! self::reference_fields(
					$reference,
					array(
						'id'         => 256,
						'plugin'     => 256,
						'slug'       => 256,
						'url'        => 2048,
						'source_url' => 2048,
						'shortcode'  => 2048,
					)
				) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Bound saved reference projections, excluding raw site or provider payloads.
	 *
	 * @param mixed $fields Projection.
	 * @param array $allowed Keys and byte limits.
	 * @return bool
	 */
	private static function reference_fields( $fields, $allowed ) {
		if ( ! is_array( $fields ) || array_diff( array_keys( $fields ), array_keys( $allowed ) ) ) {
			return false;
		}
		foreach ( $fields as $key => $value ) {
			if ( ! is_int( $value ) && ( ! is_string( $value ) || strlen( $value ) > $allowed[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolve only a receipt belonging to this owned conversation.
	 *
	 * @param int   $session_id Session.
	 * @param mixed $reference Receipt identifier.
	 * @return array
	 */
	public static function receipt( $session_id, $reference ) {
		if ( ! is_string( $reference ) || ! preg_match( '/\A([a-f0-9-]{36}):([1-9][0-9]{0,3})\z/', $reference, $match ) || (int) $match[2] > 1000 ) {
			return array();
		}
		foreach ( self::receipts( $session_id, $match[1] ) as $receipt ) {
			if ( ( $receipt['id'] ?? '' ) === $reference ) {
				return $receipt;
			}
		}
		return array();
	}

	/**
	 * Prove only that the saved target was read after its recorded write.
	 *
	 * @param int   $session_id Session.
	 * @param array $references Write receipt followed by read receipt.
	 * @return bool
	 */
	private static function read_back( $session_id, $references ) {
		if ( count( $references ) !== 2 ) {
			return false;
		}
		$write = self::receipt( $session_id, $references[0] );
		$read  = self::receipt( $session_id, $references[1] );
		$run   = get_post_meta( $session_id, 'bhranti_run', true );
		if ( empty( $write['success'] ) || empty( $read['success'] ) || empty( $write['entity'] ) || ( $read['entity'] ?? '' ) !== $write['entity'] || ! in_array( $write['operation'] ?? '', array( 'write', 'image' ), true ) || 'GET' !== ( $read['method'] ?? '' ) || ! in_array( $read['tool'] ?? '', array( 'wp_request', 'open_item', 'plugin_request', 'commerce_request', 'form_request', 'bhranti_task_limit' ), true ) || ( $read['run_id'] ?? '' ) !== ( $run['id'] ?? '' ) || ( $read['run_id'] === $write['run_id'] && $write['step'] >= $read['step'] ) ) {
			return false;
		}
		if ( empty( $write['coverage'] ) || empty( $read['coverage'] ) || empty( $write['fields'] ) || array_diff( $write['fields'], $read['fields'] ) ) {
			return false;
		}
		foreach ( $run['receipts'] ?? array() as $receipt ) {
			if ( ! empty( $receipt['success'] ) && 'write' === ( $receipt['operation'] ?? '' ) && ( $receipt['entity'] ?? '' ) === $read['entity'] && $receipt['step'] > $read['step'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Prove only the fixed automated browser profile for a current saved target.
	 *
	 * @param int   $session_id Owned conversation.
	 * @param array $references One exact successful browser receipt.
	 * @return bool
	 */
	private static function rendered_check( $session_id, $references ) {
		if ( 1 !== count( $references ) || ! class_exists( 'Bhranti_Render' ) ) {
			return false;
		}
		$receipt = self::receipt( $session_id, $references[0] );
		$run     = get_post_meta( $session_id, 'bhranti_run', true );
		if ( empty( $receipt['success'] ) || 'bhranti_inspect' !== ( $receipt['tool'] ?? '' ) || 'GET' !== ( $receipt['method'] ?? '' ) || 'read' !== ( $receipt['operation'] ?? '' ) || empty( $receipt['entity'] ) || ( $receipt['run_id'] ?? '' ) !== ( $run['id'] ?? '' ) || ! Bhranti_Render::valid_evidence( $receipt, get_current_user_id(), $session_id ) ) {
			return false;
		}
		foreach ( $run['receipts'] ?? array() as $later ) {
			if ( ! empty( $later['success'] ) && 'write' === ( $later['operation'] ?? '' ) && ( $later['entity'] ?? '' ) === $receipt['entity'] && $later['step'] > $receipt['step'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Require an owned conversation.
	 *
	 * @param int $session_id Session.
	 * @return object|WP_Error
	 */
	private static function owner( $session_id ) {
		return Bhranti_Workspace::owned_session( $session_id );
	}

	/**
	 * Validate an exact UUID.
	 *
	 * @param mixed $value UUID.
	 * @return bool
	 */
	private static function uuid( $value ) {
		return is_string( $value ) && (bool) preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $value );
	}

	/**
	 * Require bounded plain text.
	 *
	 * @param mixed $value Text.
	 * @param int   $limit Characters.
	 * @return bool
	 */
	private static function plain( $value, $limit ) {
		return is_string( $value ) && '' !== trim( $value ) && mb_strlen( $value ) <= $limit && sanitize_text_field( $value ) === $value;
	}

	/**
	 * Return a safe task error.
	 *
	 * @param string $code Suffix.
	 * @param string $message Fixed safe copy.
	 * @param int    $status HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status = 400 ) {
		return new WP_Error( 'bhranti_task_' . $code, $message, array( 'status' => $status ) );
	}
}
