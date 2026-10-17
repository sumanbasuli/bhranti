<?php
/**
 * Native audit query, presentation and NDJSON checks in an isolated offline site.
 *
 * BHRANTI_AUDIT_TEST_WP_ROOT=/private/tmp/bhranti-content-patch-qa-20261015 php tests/audit.php
 *
 * @package Bhranti
 */

$audit_root = realpath( getenv( 'BHRANTI_AUDIT_TEST_WP_ROOT' ) ?: '' );
if ( '/private/tmp/bhranti-content-patch-qa-20261015' !== $audit_root ) {
	fwrite( STDERR, "Use only the independent private audit QA runtime.\n" );
	exit( 1 );
}
define( 'WP_USE_THEMES', false );
require $audit_root . '/wp-load.php';
if ( 'https://bhranti-checker.invalid' !== get_option( 'home' ) || 'https://bhranti-checker.invalid' !== get_option( 'siteurl' ) || get_option( 'active_plugins' ) || ! DISABLE_WP_CRON || realpath( DB_DIR ) !== realpath( $audit_root . '/wp-content/database' ) ) {
	fwrite( STDERR, "The isolated offline native WordPress runtime is required.\n" );
	exit( 1 );
}
require_once dirname( __DIR__ ) . '/includes/class-bhranti-audit.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
Bhranti_Audit::register_storage();
register_post_type( 'bhranti_session', array( 'public' => false, 'supports' => array( 'author' ) ) );
$audit_checks  = 0;
$audit_http    = 0;
$audit_mail    = 0;
$audit_owner   = 0;
$audit_other   = 0;
$audit_actor   = 0;
$audit_ids     = array();
$audit_failure = null;
$audit_marker  = 'bhranti-audit-' . str_replace( '-', '', wp_generate_uuid4() );
$audit_user    = get_current_user_id();
$audit_before  = array();
foreach ( array( 'posts', 'postmeta', 'users', 'usermeta' ) as $audit_table ) {
	$audit_before[ $audit_table ] = hash( 'sha256', wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . $wpdb->$audit_table . ' ORDER BY 1,2', ARRAY_A ) ) );
}
$audit_http_guard = static function () { ++$GLOBALS['audit_http']; return new WP_Error( 'audit_offline', 'Network disabled.' ); };
$audit_mail_guard = static function () { ++$GLOBALS['audit_mail']; return false; };
add_filter( 'pre_http_request', $audit_http_guard );
add_filter( 'pre_wp_mail', $audit_mail_guard );

/** Assert one concrete behavior. */
function audit_check( $condition, $label ) {
	if ( ! $condition ) { throw new RuntimeException( $label ); }
	++$GLOBALS['audit_checks'];
}
/** Create and track one event through the real mandatory logger. */
function audit_event( $type, $message, $session, $details = array(), $owner = 0, $actor = 0 ) {
	$id = Bhranti_Audit::record( $type, $message, $session, $details, $owner ?: $GLOBALS['audit_owner'], $actor ?: $GLOBALS['audit_actor'] );
	audit_check( is_int( $id ) && $id > 0, 'Native audit record persisted.' );
	$GLOBALS['audit_ids'][] = $id;
	return $id;
}
/** Create one historical record without modifying its old metadata. */
function audit_legacy( $type, $message, $session, $details = array() ) {
	$event = array( 'id' => wp_generate_uuid4(), 'type' => $type, 'message' => $message, 'created' => '2023-02-03T12:00:00+00:00', 'session_id' => $session, 'requester_id' => $GLOBALS['audit_owner'], 'requester' => $GLOBALS['audit_marker'], 'actor_id' => $GLOBALS['audit_actor'], 'actor' => 'audit-actor', 'details' => $details );
	$id = wp_insert_post( wp_slash( array( 'post_type' => 'bhranti_event', 'post_status' => 'private', 'post_author' => $GLOBALS['audit_owner'], 'post_parent' => $session, 'post_date' => '2023-02-03 12:00:00', 'post_date_gmt' => '2023-02-03 12:00:00', 'meta_input' => array( 'bhranti_event_data' => $event, 'bhranti_session_id' => $session ) ) ), true );
	audit_check( is_int( $id ) && $id > 0, 'Historical fixture persisted.' );
	$GLOBALS['audit_ids'][] = $id;
	return array( $id, $event );
}

try {
	foreach ( array( 'owner', 'other', 'actor' ) as $audit_identity ) {
		$audit_id = wp_insert_user( array( 'user_login' => $audit_marker . '-' . $audit_identity, 'user_email' => $audit_marker . '-' . $audit_identity . '@example.invalid', 'user_pass' => wp_generate_password( 40 ), 'role' => 'administrator' ) );
		audit_check( is_int( $audit_id ), 'Independent identity created.' );
		${'audit_' . $audit_identity} = $audit_id;
	}
	wp_set_current_user( $audit_owner );
	$audit_session = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $audit_owner, 'post_title' => $audit_marker ) );
	$audit_ids[] = $audit_session;
	$audit_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_author' => $audit_owner, 'post_title' => $audit_marker ) );
	$audit_ids[] = $audit_page;
	$audit_run = wp_generate_uuid4();
	update_post_meta( $audit_session, 'bhranti_run', array( 'id' => $audit_run, 'status' => 'running', 'requester_id' => $audit_owner, 'actor_id' => $audit_actor, 'step' => 6 ) );
	$audit_first = audit_event( 'action_completed', 'A catalog read completed.', $audit_session, array( 'method' => 'GET', 'path' => '/wp/v2/pages/' . $audit_page . '?password=never-log-this', 'tool' => 'wp_request', 'params' => array( 'content' => 'private-prompt-not-a-log' ), 'password' => 'private-credential', 'provider_body' => 'private-provider-response', 'target' => array( 'type' => 'page', 'id' => $audit_page ) ) );
	$audit_evidence = get_post_meta( $audit_first, 'bhranti_event_data', true );
	audit_check( $audit_run === $audit_evidence['details']['run_id'] && '6' === $audit_evidence['details']['step'], 'Exact owned active run and step are correlated.' );
	audit_check( 'wp_request' === $audit_evidence['details']['tool'] && '/wp/v2/pages/' . $audit_page === $audit_evidence['details']['path'], 'Tool and query-free native path are retained.' );
	audit_check( ! str_contains( wp_json_encode( $audit_evidence ), 'private-' ) && ! str_contains( wp_json_encode( $audit_evidence ), 'never-log-this' ), 'Prompts, provider bodies, credentials and URL query values are excluded.' );
	$audit_desc = Bhranti_Audit::describe( $audit_evidence );
	audit_check( 'success' === $audit_desc['status'] && 'content' === $audit_desc['category'] && 'WordPress information read' === $audit_desc['title'], 'Read completion has a meaningful outcome and title.' );
	audit_check( str_contains( $audit_desc['target_url'], 'post=' . $audit_page ) && str_contains( $audit_desc['target_label'], (string) $audit_page ), 'Authorized native item edit link is derived from exact target.' );
	wp_set_current_user( 0 );
	audit_check( '' === Bhranti_Audit::describe( $audit_evidence )['target_url'], 'Unauthorized viewers receive no edit link.' );
	wp_set_current_user( $audit_owner );
	$audit_other_record = audit_event( 'ai_failed', 'An unrelated account had a connection problem.', 0, array( 'reason' => 'network' ), $audit_other );
	$audit_other_evidence = get_post_meta( $audit_other_record, 'bhranti_event_data', true );
	audit_check( ! isset( $audit_other_evidence['details']['run_id'] ), 'Unrelated requester never inherits another run.' );
	$audit_wrong = audit_event( 'action_started', 'An older action ID was supplied.', $audit_session, array( 'run_id' => 'other-owned-run' ) );
	audit_check( ! isset( get_post_meta( $audit_wrong, 'bhranti_event_data', true )['details']['step'] ), 'Explicit different run never inherits current step.' );
	for ( $audit_n = 0; $audit_n < 58; ++$audit_n ) {
		audit_event( 'action_completed', 'Inventory observation ' . $audit_n, $audit_session, array( 'method' => 'GET', 'path' => '/wp/v2/posts', 'action_id' => 'audit-action-' . $audit_n ) );
	}
	$audit_err = audit_event( 'ai_failed', 'A connection request failed.', $audit_session, array( 'reason' => 'network', 'code' => 'prompt_network_error', 'status' => 502 ) );
	$audit_pending = audit_event( 'confirmation_requested', 'A delete request needs approval.', $audit_session );
	$audit_redacted = audit_event( 'system_info', 'api_key=neverexposethis Bearer abcdefghijklmnop https://example.invalid/?key=neverexposeurl', $audit_session );
	audit_check( ! str_contains( wp_json_encode( get_post_meta( $audit_redacted, 'bhranti_event_data', true ) ), 'neverexpose' ), 'Common credential-shaped summary values and URLs are redacted.' );
	list( $audit_legacy, $audit_old ) = audit_legacy( 'plugin_rollback_failed', 'Historical plugin undo failed; literal needle%_ remains.', $audit_session, array( 'run_id' => 'old-audit-run', 'method' => 'POST', 'path' => '/wp/v2/plugins/test-plugin/test-plugin', 'code' => 'native_conflict' ) );
	list( $audit_legacy_content ) = audit_legacy( 'action_completed', 'Historical catalog inspection.', $audit_session, array( 'method' => 'GET', 'path' => '/wc/v3/products', 'run_id' => 'old-audit-run' ) );
	$audit_all = Bhranti_Audit::query( array( 'per_page' => 100 ), $audit_owner );
	audit_check( ! is_wp_error( $audit_all ) && 65 === $audit_all['total'] && 1 === $audit_all['pages'], 'Total includes indexed and historical events, excluding other users.' );
	$audit_page1 = Bhranti_Audit::query( array( 'per_page' => 20, 'page' => 1 ), $audit_owner );
	$audit_page2 = Bhranti_Audit::query( array( 'per_page' => 20, 'page' => 2 ), $audit_owner );
	audit_check( 20 === count( $audit_page1['events'] ) && 4 === $audit_page1['pages'] && ! array_intersect( array_column( $audit_page1['events'], 'event_id' ), array_column( $audit_page2['events'], 'event_id' ) ), 'Exact totals and deterministic nonoverlapping bounded pages.' );
	$audit_desc_ids = array_column( $audit_all['events'], 'event_id' );
	$audit_sorted_ids = $audit_desc_ids;
	rsort( $audit_sorted_ids );
	audit_check( $audit_desc_ids === $audit_sorted_ids && $audit_legacy_content === $audit_all['latest_id'], 'Event order is strictly newest native ID, independent of old timestamps.' );
	audit_check( 50 === count( Bhranti_Audit::events( $audit_owner, 1, $audit_session ) ), 'Legacy events API retains a 50-event page.' );
	$audit_empty = Bhranti_Audit::query( array( 'page' => 999 ), $audit_owner );
	audit_check( array() === $audit_empty['events'] && 65 === $audit_empty['total'], 'Empty distant page still reports correct total.' );
	$audit_error_result = Bhranti_Audit::query( array( 'status' => 'error' ), $audit_owner );
	audit_check( 2 === $audit_error_result['total'] && in_array( $audit_legacy, array_column( $audit_error_result['events'], 'event_id' ), true ), 'Failure filter includes exact historical and new failures.' );
	$audit_undo = Bhranti_Audit::query( array( 'category' => 'undo' ), $audit_owner );
	audit_check( 1 === $audit_undo['total'] && $audit_legacy === $audit_undo['events'][0]['event_id'], 'Historical category precedence distinguishes plugin Undo from plugins.' );
	$audit_store = Bhranti_Audit::query( array( 'category' => 'commerce' ), $audit_owner );
	audit_check( 1 === $audit_store['total'] && $audit_legacy_content === $audit_store['events'][0]['event_id'], 'Historical native store path is categorized without migration.' );
	$audit_search = Bhranti_Audit::query( array( 'search' => 'needle%_' ), $audit_owner );
	audit_check( 1 === $audit_search['total'] && $audit_legacy === $audit_search['events'][0]['event_id'], 'Search safely escapes literal SQL wildcard characters in legacy records.' );
	audit_check( 1 === Bhranti_Audit::query( array( 'search' => 'audit-action-32' ), $audit_owner )['total'], 'Safe technical identifiers are searchable in new records.' );
	audit_check( 0 === Bhranti_Audit::query( array( 'search' => "' OR 1=1 --" ), $audit_owner )['total'], 'SQL-shaped search text remains literal data.' );
	audit_check( 2 === Bhranti_Audit::query( array( 'run_id' => 'old-audit-run', 'actor_id' => $audit_actor, 'session_id' => $audit_session ), $audit_owner )['total'], 'Combined old run, acting user and conversation filters match correctly.' );
	audit_check( 0 === Bhranti_Audit::query( array( 'requester_id' => $audit_other ), $audit_owner )['total'], 'An enforced requester scope cannot be overridden by another requester filter.' );
	audit_check( 2 === Bhranti_Audit::query( array( 'from' => '2023-02-03', 'to' => '2023-02-03' ), $audit_owner )['total'], 'Inclusive historical date filtering uses native date evidence.' );
	foreach ( array( array( 'from' => '2023-02-30' ), array( 'from' => '2025-04-02', 'to' => '2025-04-01' ), array( 'status' => 'imagined-success' ), array( 'category' => 'secrets' ), array( 'search' => array( 'bad' ) ), array( 'actor_id' => -1 ) ) as $audit_bad ) {
		audit_check( is_wp_error( Bhranti_Audit::query( $audit_bad ) ), 'Invalid filter rejects rather than widening the query.' );
	}
	$audit_snapshot = $audit_page1['latest_id'];
	$audit_later = audit_event( 'run_complete', 'A newer unrelated task completed.', $audit_session );
	$audit_stable = Bhranti_Audit::query( array( 'per_page' => 20, 'max_id' => $audit_snapshot ), $audit_owner );
	audit_check( $audit_page1['events'] === $audit_stable['events'] && 65 === $audit_stable['total'], 'Export snapshot remains stable after later inserts.' );
	audit_check( 0 === Bhranti_Audit::query( array( 'max_id' => 0 ), $audit_owner )['total'], 'Explicit zero snapshot contains no events.' );
	$audit_export = Bhranti_Audit::export_event( $audit_search['events'][0] );
	$audit_json = json_decode( $audit_export, true );
	audit_check( str_ends_with( $audit_export, "\n" ) && 1 === substr_count( $audit_export, "\n" ) && 'bhranti.activity' === $audit_json['format'] && 1 === $audit_json['version'], 'Export is one versioned NDJSON object per line.' );
	audit_check( '2023-02-03T12:00:00Z' === $audit_json['timestamp'] && $audit_old['id'] === $audit_json['id'] && $audit_legacy === $audit_json['event_id'] && 'error' === $audit_json['level'] && $audit_actor === $audit_json['actor']['id'], 'Export preserves UTC, UUID, native ID, status and identities.' );
	audit_check( $audit_old === get_post_meta( $audit_legacy, 'bhranti_event_data', true ) && ! metadata_exists( 'post', $audit_legacy, 'bhranti_event_index' ), 'Reading, explaining and exporting historical evidence does not rewrite it.' );
	$audit_failed_desc = Bhranti_Audit::describe( get_post_meta( $audit_err, 'bhranti_event_data', true ) );
	audit_check( 'error' === $audit_failed_desc['status'] && str_contains( $audit_failed_desc['next_step'], 'saved work' ), 'Failure explanation supplies grounded next step without inventing success or Undo.' );
	audit_check( 'pending' === Bhranti_Audit::describe( get_post_meta( $audit_pending, 'bhranti_event_data', true ) )['status'], 'Historical approval request is an event status, not a claim of completion.' );
	$audit_fail_guard = static function ( $pre, $id, $key ) { return 'bhranti_event_search' === $key ? false : $pre; };
	add_filter( 'add_post_metadata', $audit_fail_guard, 10, 3 );
	try { $audit_failure_result = Bhranti_Audit::record( 'action_started', 'This cannot run without complete evidence.', $audit_session, array(), $audit_owner, $audit_actor ); } finally { remove_filter( 'add_post_metadata', $audit_fail_guard, 10 ); }
	audit_check( is_wp_error( $audit_failure_result ) && 'bhranti_audit_failed' === $audit_failure_result->get_error_code() && 66 === Bhranti_Audit::query( array(), $audit_owner )['total'], 'Index persistence failure stops operation and removes partial log.' );
	$audit_native_done = get_post_meta( $audit_later, 'bhranti_event_data', true );
	audit_check( 'success' === Bhranti_Audit::describe( $audit_native_done )['status'] && 'Task finished' === Bhranti_Audit::describe( $audit_native_done )['title'], 'The actual run_complete lifecycle code is a completed task event.' );
	$audit_matrix = array(
		array( 'run_complete', array(), 'success', 'task' ),
		array( 'action_completed', array( 'method' => 'GET', 'path' => 'bhranti-logs' ), 'success', 'system' ),
		array( 'action_completed', array( 'method' => 'POST', 'path' => '/bhranti/v1/task-limit', 'target_type' => 'bhranti_settings' ), 'success', 'settings' ),
		array( 'action_blocked', array( 'path' => '/wc/v3/products' ), 'warning', 'commerce' ),
		array( 'media_completed', array(), 'success', 'media' ),
		array( 'ai_request_retry', array(), 'pending', 'ai' ),
		array( 'confirmation_requested', array(), 'pending', 'approval' ),
		array( 'actor_completed', array(), 'success', 'system' ),
		array( 'memory_result', array(), 'success', 'memory' ),
		array( 'action_completed', array( 'path' => '/wp/v2/posts', 'reason' => 'plugin_failed', 'run_id' => 'run_failed' ), 'success', 'content' ),
	);
	foreach ( $audit_matrix as $audit_index => $audit_case ) {
		list( $audit_type, $audit_details, $audit_status, $audit_category ) = $audit_case;
		$audit_message = 'Classificationprobe' . $audit_index . 'x';
		$audit_new_id = audit_event( $audit_type, $audit_message, $audit_session, $audit_details );
		list( $audit_old_id, $audit_old_event ) = audit_legacy( $audit_type, $audit_message, $audit_session, $audit_details );
		$audit_classification = Bhranti_Audit::query( array( 'search' => $audit_message, 'status' => $audit_status, 'category' => $audit_category ), $audit_owner );
		audit_check( 2 === $audit_classification['total'], 'Current and historical status/category classification agree: ' . $audit_type . ' (' . $audit_category . ').' );
		$audit_description = Bhranti_Audit::describe( $audit_old_event );
		audit_check( $audit_status === $audit_description['status'] && $audit_category === $audit_description['category'], 'Presentation uses the same category/status rules as historical queries.' );
		if ( 'bhranti-logs' === ( $audit_details['path'] ?? '' ) ) { audit_check( 'Activity logs read' === $audit_description['title'], 'Log inspection has a human activity-log title.' ); }
		if ( '/bhranti/v1/task-limit' === ( $audit_details['path'] ?? '' ) ) { audit_check( str_contains( $audit_description['target_url'], 'page=bhranti' ), 'Actual native task-limit target links to Bhranti Settings.' ); }
	}
	foreach ( array( 'step_limit', 'unsaved_editor', 'uncertain_action', 'invalid_plan', 'ai_retries_exhausted', 'approval_expired', 'approval_denied', 'approval_conflict' ) as $audit_reason ) {
		$audit_reason_desc = Bhranti_Audit::describe( array( 'type' => 'run_incomplete', 'details' => array( 'reason' => $audit_reason ) ) );
		audit_check( '' !== $audit_reason_desc['next_step'], 'Known terminal reason supplies a grounded next step: ' . $audit_reason );
	}

	$audit_raw_message = 'The native WordPress AI request failed. See its error code for diagnosis.';
	foreach ( array( 'structured_output', 'incomplete_response', 'invalid_response', 'output_limit', 'authentication', 'permission', 'prevented', 'model_unavailable', 'network', 'upstream', 'usage_limit', 'unsupported_prompt', 'protocol_version' ) as $audit_reason ) {
		$audit_diagnostic_event = array( 'id' => wp_generate_uuid4(), 'event_id' => 1, 'type' => 'ai_failed', 'message' => $audit_raw_message, 'created' => '2023-02-03T12:00:00+00:00', 'details' => array( 'reason' => $audit_reason, 'code' => 'prompt_builder_error' ) );
		$audit_diagnostic_desc = Bhranti_Audit::describe( $audit_diagnostic_event );
		audit_check( $audit_raw_message !== $audit_diagnostic_desc['explanation'] && 'error' === $audit_diagnostic_desc['status'], 'Known native reason explains its historical failure without changing the outcome: ' . $audit_reason );
		audit_check( str_contains( $audit_diagnostic_desc['next_step'], 'related task activity for later retries or recovery' ) && ! str_contains( $audit_diagnostic_desc['next_step'], 'Check Logs' ), 'Known reason directs the reader to later task evidence and a conditional next step.' );
		$audit_diagnostic_export = json_decode( Bhranti_Audit::export_event( $audit_diagnostic_event ), true );
		audit_check( $audit_raw_message === $audit_diagnostic_export['message'] && $audit_reason === $audit_diagnostic_export['details']['reason'], 'Readable explanation preserves the original export message and diagnostic evidence.' );
	}
	$audit_unknown_desc = Bhranti_Audit::describe( array( 'type' => 'ai_failed', 'message' => $audit_raw_message, 'details' => array( 'reason' => 'new_unknown_reason' ) ) );
	audit_check( $audit_raw_message === $audit_unknown_desc['explanation'], 'Unknown diagnostics retain the original fixed summary rather than inventing a cause.' );
	$audit_native_permission = Bhranti_Audit::describe( array( 'type' => 'action_failed', 'message' => 'Native WordPress action denied.', 'details' => array( 'reason' => 'permission' ) ) );
	audit_check( 'Native WordPress action denied.' === $audit_native_permission['explanation'], 'Ordinary WordPress permissions are not mislabeled as AI connector failures.' );

	// Real context links use UUID task IDs and must include untouched historical records.
	$audit_context_session = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $audit_owner, 'post_title' => $audit_marker . '-context' ) );
	$audit_ids[] = $audit_context_session;
	$audit_context_run = wp_generate_uuid4();
	$audit_context_new = audit_event( 'action_completed', 'A context-linked read completed.', $audit_context_session, array( 'run_id' => $audit_context_run, 'method' => 'GET', 'path' => '/wp/v2/pages' ) );
	list( $audit_context_old, $audit_context_evidence ) = audit_legacy( 'ai_failed', 'A historical context-linked request failed.', $audit_context_session, array( 'run_id' => $audit_context_run, 'code' => 'prompt_network_error', 'reason' => 'network', 'params' => array( 'content' => 'private-legacy-content-never-display' ), 'provider_body' => 'private-legacy-response-never-display' ) );
	list( $audit_context_other ) = audit_legacy( 'ai_completed', 'A different task in the same conversation completed.', $audit_context_session, array( 'run_id' => $audit_context_run . '-different' ) );
	$audit_context_conversation = Bhranti_Audit::query( array( 'session_id' => $audit_context_session, 'per_page' => 100 ), $audit_owner );
	audit_check( 3 === $audit_context_conversation['total'] && array( $audit_context_other, $audit_context_old, $audit_context_new ) === array_column( $audit_context_conversation['events'], 'event_id' ), 'Conversation context returns indexed and historical events across its tasks, newest first.' );
	$audit_context_task = Bhranti_Audit::query( array( 'run_id' => $audit_context_run, 'per_page' => 100 ), $audit_owner );
	audit_check( 2 === $audit_context_task['total'] && array( $audit_context_old, $audit_context_new ) === array_column( $audit_context_task['events'], 'event_id' ), 'UUID task context returns exact indexed and historical events without prefix collisions.' );
	audit_check( 2 === Bhranti_Audit::query( array( 'session_id' => $audit_context_session, 'run_id' => $audit_context_run ), $audit_owner )['total'] && 0 === Bhranti_Audit::query( array( 'session_id' => $audit_session, 'run_id' => $audit_context_run ), $audit_owner )['total'], 'Combined conversation and task scopes cannot include another conversation.' );
	$audit_context_export = Bhranti_Audit::export_event( $audit_context_task['events'][0] );
	$audit_context_json = json_decode( $audit_context_export, true );
	audit_check( $audit_context_run === $audit_context_json['run_id'] && 'prompt_network_error' === $audit_context_json['code'] && 'A historical context-linked request failed.' === $audit_context_json['message'] && ! str_contains( $audit_context_export, 'private-legacy-' ), 'Raw context evidence retains diagnostic identifiers and the recorded summary while omitting historical content and provider payloads.' );
	audit_check( $audit_context_evidence === get_post_meta( $audit_context_old, 'bhranti_event_data', true ) && ! metadata_exists( 'post', $audit_context_old, 'bhranti_event_index' ), 'Context reads and safe raw export never migrate or alter historical evidence.' );

} catch ( Throwable $audit_error ) {
	$audit_failure = $audit_error;
} finally {
	foreach ( array_reverse( $audit_ids ) as $audit_id ) { wp_delete_post( $audit_id, true ); }
	foreach ( array( $audit_owner, $audit_other, $audit_actor ) as $audit_id ) { if ( $audit_id ) { wp_delete_user( $audit_id ); } }
	wp_set_current_user( $audit_user );
	remove_filter( 'pre_http_request', $audit_http_guard );
	remove_filter( 'pre_wp_mail', $audit_mail_guard );
	foreach ( $audit_before as $audit_table => $audit_hash ) {
		if ( $audit_hash !== hash( 'sha256', wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . $wpdb->$audit_table . ' ORDER BY 1,2', ARRAY_A ) ) ) ) {
			$audit_failure = new RuntimeException( 'Fixture cleanup changed a pre-existing table: ' . $audit_table );
		}
	}
}
if ( $audit_failure ) { fwrite( STDERR, 'FAIL: ' . $audit_failure->getMessage() . "\n" ); exit( 1 ); }
audit_check( 0 === $audit_http && 0 === $audit_mail, 'No provider/network/mail requests occurred.' );
echo wp_json_encode( array( 'checks' => $audit_checks, 'network_requests' => $audit_http, 'mail_requests' => $audit_mail, 'cleanup' => 'verified' ) ) . "\n";
