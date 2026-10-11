<?php
/**
 * Native task-limit action checks using disposable sessions and a local AI model.
 *
 * Run only after coordinated deployment on the explicitly selected site:
 * BHRANTI_TEST_WP_ROOT=/path/to/wordpress BHRANTI_TEST_ALLOW_MUTATION=1 php tests/task-limit.php
 *
 * @package Bhranti
 */

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ProviderRegistry;

define( 'BHRANTI_QA_SUPPORT_ONLY', true );
require __DIR__ . '/integration.php';
if ( ! method_exists( 'Bhranti_Settings', 'task_execute' ) || ! class_exists( 'Bhranti_Knowledge' ) || realpath( BHRANTI_FILE ) !== realpath( WP_PLUGIN_DIR . '/bhranti/bhranti.php' ) ) {
	fwrite( STDERR, "Deploy the task-limit-capable plugin on https://bhranti.wp.local/ before testing.\n" );
	exit( 1 );
}

$task_checks = 0;
$task_users = array();
$task_sessions = array();
$task_http = array();
$task_failure = null;
$task_original_user = get_current_user_id();
$task_original_option = null;
$task_original_lock = null;
$task_last_option = null;
$task_captured = false;
$task_marker = 'bhranti-task-limit-qa-' . wp_generate_uuid4();
$task_registry_property = new ReflectionProperty( AiClient::class, 'defaultRegistry' );
$task_registry_property->setAccessible( true );
$task_original_registry = AiClient::defaultRegistry();
$task_original_dispatcher = AiClient::getEventDispatcher();
$task_original_cache = AiClient::getCache();

/** Assert behavior without exposing saved documents or connector configuration. */
function bhranti_task_check( $condition, $label ) {
	global $task_checks;
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	++$task_checks;
}

/** Read exact fixture bytes for cleanup, including absence and autoload. */
function bhranti_task_raw( $name = 'bhranti_settings' ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name=%s", $name ), ARRAY_A );
}

/** Restore an exact fixture snapshot only after the cleanup concurrency guard. */
function bhranti_task_restore( $name, $snapshot ) {
	global $wpdb;
	if ( null === $snapshot ) {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );
	} elseif ( null === bhranti_task_raw( $name ) ) {
		$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $snapshot['option_value'], 'autoload' => $snapshot['autoload'] ), array( '%s', '%s', '%s' ) );
	} else {
		$wpdb->update( $wpdb->options, $snapshot, array( 'option_name' => $name ), array( '%s', '%s' ), array( '%s' ) );
	}
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
}

/** Invoke the actual reviewed executor and track only our resulting option state. */
function bhranti_task_action( $args, $owner, $session ) {
	global $task_last_option;
	$result = Bhranti_Actions::execute( array( 'tool' => 'bhranti_task_limit', 'args' => $args ), $owner, $session );
	$task_last_option = bhranti_task_raw();
	return $result;
}

/** Route through native REST, including schema and human permission checks. */
function bhranti_task_rest( $method, $path, $body = array(), $json = false ) {
	$request = new WP_REST_Request( $method, '/bhranti/v1' . $path );
	if ( $json ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	} else {
		$request->set_body_params( $body );
	}
	return rest_do_request( $request );
}

/** Save a human-owned fixture through the existing full Settings endpoint. */
function bhranti_task_human_save( $config ) {
	global $task_last_option;
	$before = Bhranti_Settings::get();
	$result = bhranti_task_rest( 'POST', '/settings', array( 'config' => $config, 'revision' => $before['revision'] ), true );
	bhranti_task_check( 200 === $result->get_status(), 'Human Settings fixture saves through its unchanged endpoint' );
	$task_last_option = bhranti_task_raw();
	return $result->get_data();
}

$task_http_guard = static function ( $response, $args, $url ) use ( &$task_http ) {
	$task_http[] = $url;
	return new WP_Error( 'bhranti_task_qa_offline', 'Task-limit QA is offline.' );
};
$task_runner_veto = bhranti_qa_runner_veto( static function () { return $GLOBALS['task_sessions']; } );
add_filter( 'pre_http_request', $task_http_guard, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );

try {
	$task_original_option = bhranti_task_raw();
	$task_original_lock = bhranti_task_raw( 'bhranti_settings_lock' );
	$task_last_option = $task_original_option;
	$task_captured = true;
	$lock = get_option( 'bhranti_settings_lock' );
	bhranti_task_check( ! is_array( $lock ) || (int) ( $lock['expires'] ?? 0 ) < time(), 'An existing active human Settings save is never interrupted' );
	$actor = (int) get_option( 'bhranti_actor_id' );
	bhranti_task_check( $actor > 0 && get_userdata( $actor ) && user_can( $actor, 'manage_options' ), 'Existing managed actor is preserved and available' );
	foreach ( array( 'administrator', 'administrator', 'editor' ) as $role ) {
		$login = 'bhranti_limit_qa_' . str_replace( '-', '', wp_generate_uuid4() );
		$user = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role, 'user_email' => $login . '@example.invalid' ) );
		bhranti_task_check( is_int( $user ) && $user > 0, 'Disposable capability fixture is created' );
		$task_users[] = $user;
	}
	list( $owner, $other, $editor ) = $task_users;
	wp_set_current_user( $owner );
	$session = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $owner, 'post_title' => $task_marker ), true );
	bhranti_task_check( is_int( $session ) && $session > 0, 'Owned private session is created' );
	$task_sessions[] = $session;
	update_post_meta( $session, 'bhranti_task_qa', $task_marker );
	$config = Bhranti_Settings::get()['config'];
	$config['max_steps'] = 128;
	$config['permissions']['manage_site_settings'] = true;
	$config['system_prompt'] .= "\n" . $task_marker . ':private-document';
	$saved = bhranti_task_human_save( $config );
	$read = bhranti_task_action( array( 'method' => 'GET' ), $owner, $session );
	bhranti_task_check( ! is_wp_error( $read ) && array( 'max_steps', 'revision' ) === array_keys( $read['data'] ) && 128 === $read['data']['max_steps'], 'Read exposes only task limit and optimistic revision' );
	bhranti_task_check( false === strpos( wp_json_encode( $read ), ':private-document' ) && false === $read['rollback_available'], 'Read never exposes prompts or offers fictitious Undo' );
	bhranti_task_check( $read['target']['url'] === add_query_arg( 'page', 'bhranti', admin_url( 'admin.php' ) ) && 'bhranti_settings' === $read['target']['type'], 'Settings target is the exact native Bhranti page' );
	bhranti_task_check( isset( Bhranti_Actions::catalog()['bhranti_task_limit'] ), 'Native agent catalog includes the narrow action' );
	$before = bhranti_task_raw();
	foreach ( array(
		array( 'method' => 'GET', 'config' => array() ),
		array( 'method' => 'GET', 'revision' => $saved['revision'] ),
		array( 'method' => 'POST', 'max_steps' => '129', 'revision' => $saved['revision'] ),
		array( 'method' => 'POST', 'max_steps' => 129.0, 'revision' => $saved['revision'] ),
		array( 'method' => 'POST', 'max_steps' => 11, 'revision' => $saved['revision'] ),
		array( 'method' => 'POST', 'max_steps' => 1001, 'revision' => $saved['revision'] ),
		array( 'method' => 'POST', 'max_steps' => 129 ),
		array( 'method' => 'POST', 'max_steps' => 129, 'revision' => 'bad' ),
		array( 'method' => 'DELETE' ),
		array( 'method' => 'POST', 'max_steps' => 129, 'revision' => $saved['revision'], 'permissions' => array() ),
		array( 'method' => 'POST', 'max_steps' => 129, 'revision' => $saved['revision'], 'system_prompt' => 'untrusted' ),
		array( 'method' => 'POST', 'max_steps' => 129, 'revision' => $saved['revision'], 'guardrails' => 'untrusted' ),
	) as $invalid ) {
		$error = bhranti_task_action( $invalid, $owner, $session );
		bhranti_task_check( is_wp_error( $error ) && 'bhranti_task_limit_invalid' === $error->get_error_code() && $before === bhranti_task_raw(), 'Malformed or escalatory argument preserves complete Settings bytes' );
	}
	foreach ( array( $other, $editor, $actor ) as $forbidden ) {
		wp_set_current_user( $forbidden );
		$error = bhranti_task_action( array( 'method' => 'GET' ), $forbidden, $session );
		bhranti_task_check( is_wp_error( $error ) && 'bhranti_action_owner' === $error->get_error_code(), 'Another account cannot inspect an owned session setting' );
	}
	wp_set_current_user( $actor );
	bhranti_task_check( 403 === bhranti_task_rest( 'GET', '/settings' )->get_status(), 'The actor still cannot read the full human Settings endpoint' );
	wp_set_current_user( $owner );
	$deny_actor = static function ( $caps, $requested, $args, $user ) use ( $actor ) {
		if ( $actor === $user->ID ) { $caps['manage_options'] = false; }
		return $caps;
	};
	add_filter( 'user_has_cap', $deny_actor, PHP_INT_MAX, 4 );
	try {
		$error = bhranti_task_action( array( 'method' => 'POST', 'max_steps' => 129, 'revision' => $saved['revision'] ), $owner, $session );
		bhranti_task_check( is_wp_error( $error ) && 'bhranti_task_limit_permission' === $error->get_error_code() && $before === bhranti_task_raw(), 'Actor capabilities never lend privileges to this action' );
	} finally { remove_filter( 'user_has_cap', $deny_actor, PHP_INT_MAX ); }

	$fixture_lock = array( 'token' => $task_marker, 'expires' => time() + 60 );
	delete_option( 'bhranti_settings_lock' );
	add_option( 'bhranti_settings_lock', $fixture_lock, '', false );
	try {
		$error = bhranti_task_action( array( 'method' => 'POST', 'max_steps' => 129, 'revision' => $saved['revision'] ), $owner, $session );
		bhranti_task_check( is_wp_error( $error ) && 'bhranti_settings_busy' === $error->get_error_code() && $before === bhranti_task_raw() && $fixture_lock === get_option( 'bhranti_settings_lock' ), 'Task action cannot bypass or release another Settings lock' );
	} finally { if ( $fixture_lock === get_option( 'bhranti_settings_lock' ) ) { delete_option( 'bhranti_settings_lock' ); } }
	$error = bhranti_task_action( array( 'method' => 'POST', 'max_steps' => 129, 'revision' => wp_generate_uuid4() ), $owner, $session );
	bhranti_task_check( is_wp_error( $error ) && 'bhranti_settings_conflict' === $error->get_error_code() && $before === bhranti_task_raw(), 'A stale revision never changes any configuration' );

	foreach ( array( 1, 2 ) as $fail_at ) {
		$attempts = 0;
		$audit_failure = static function ( $check, $id, $key ) use ( &$attempts, $fail_at ) {
			if ( 'bhranti_event_data' === $key && ++$attempts === $fail_at ) { return false; }
			return $check;
		};
		add_filter( 'add_post_metadata', $audit_failure, 10, 3 );
		try {
			$error = bhranti_task_action( array( 'method' => 'POST', 'max_steps' => 129, 'revision' => $saved['revision'] ), $owner, $session );
			bhranti_task_check( is_wp_error( $error ) && 'bhranti_audit_failed' === $error->get_error_code() && $before === bhranti_task_raw(), 'Initial/final audit failure restores exact Settings revision and autoload' );
		} finally { remove_filter( 'add_post_metadata', $audit_failure, 10 ); }
		bhranti_task_check( $owner === get_current_user_id() && false === get_option( 'bhranti_settings_lock' ), 'Failed managed execution restores identity and releases only its own lock' );
	}
	$snapshot_failure = static function ( $check, $id, $key ) use ( $session ) {
		return $session === $id && 'bhranti_actions' === $key ? false : $check;
	};
	add_filter( 'update_post_metadata', $snapshot_failure, 10, 3 );
	try {
		$error = bhranti_task_action( array( 'method' => 'POST', 'max_steps' => 129, 'revision' => $saved['revision'] ), $owner, $session );
		bhranti_task_check( is_wp_error( $error ) && 'bhranti_storage' === $error->get_error_code() && $before === bhranti_task_raw(), 'Durable snapshot failure prevents the setting write' );
	} finally { remove_filter( 'update_post_metadata', $snapshot_failure, 10 ); }

	$task_registry_property->setValue( null, new ProviderRegistry() );
	AiClient::setEventDispatcher( null );
	AiClient::setCache( null );
	AiClient::defaultRegistry()->registerProvider( Bhranti_QA_Provider::class );
	WP_Connector_Registry::get_instance()->register( 'bhranti-qa', array( 'name' => 'Bhranti local QA', 'type' => 'ai_provider', 'authentication' => array( 'method' => 'none' ) ) );
	$start = bhranti_task_rest( 'POST', '/sessions/' . $session . '/messages', array( 'content' => 'Set Bhranti Task step limit to 129.', 'context' => array(), 'agent_id' => 'bhranti' ) );
	bhranti_task_check( 200 === $start->get_status(), 'Task starts through the native agent REST harness (' . $start->get_status() . '/' . ( $start->get_data()['code'] ?? 'ok' ) . ')'  );
	$run_id = $start->get_data()['run']['id'];
	$read_args = array( 'method' => 'GET' );
	Bhranti_QA_Model::$responses = array( wp_json_encode( array( 'type' => 'tool', 'content' => 'Read the task limit.', 'tool' => 'bhranti_task_limit', 'args' => wp_json_encode( $read_args ) ) ) );
	$planned = bhranti_task_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $run_id, 'defer_tool' => true ) );
	bhranti_task_check( 200 === $planned->get_status() && 'prepared' === $planned->get_data()['run']['phase'] && false === $planned->get_data()['run']['next_action']['write'], 'Strict native plan prepares an actual read' );
	$executed = bhranti_task_rest( 'POST', '/sessions/' . $session . '/execute', array( 'run_id' => $run_id, 'prepared_step' => 1 ) );
	bhranti_task_check( 200 === $executed->get_status(), 'Prepared read executes through exact run/step binding' );
	$events = $executed->get_data()['events'];
	$read_event = end( $events );
	bhranti_task_check( 'read' === $read_event['operation'] && 128 === $read_event['task_limit']['max_steps'], 'Successful read event carries only narrow synchronization metadata' );
	$write_args = array( 'method' => 'POST', 'max_steps' => 129, 'revision' => $read_event['task_limit']['revision'] );
	Bhranti_QA_Model::$responses = array( wp_json_encode( array( 'type' => 'tool', 'content' => 'Save 129 for future tasks.', 'tool' => 'bhranti_task_limit', 'args' => wp_json_encode( $write_args ) ) ) );
	$planned = bhranti_task_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $run_id, 'defer_tool' => true ) );
	bhranti_task_check( 200 === $planned->get_status() && true === $planned->get_data()['run']['next_action']['write'] && 'bhranti_settings' === $planned->get_data()['run']['next_action']['target']['type'], 'Prepared change identifies the real Settings target and write' );
	$executed = bhranti_task_rest( 'POST', '/sessions/' . $session . '/execute', array( 'run_id' => $run_id, 'prepared_step' => 2 ) );
	bhranti_task_check( 200 === $executed->get_status(), 'Prepared task-limit write succeeds' );
	$task_last_option = bhranti_task_raw();
	$events = $executed->get_data()['events'];
	$write_event = end( $events );
	$action_id = $write_event['action_id'];
	bhranti_task_check( 'write' === $write_event['operation'] && 129 === $write_event['task_limit']['max_steps'] && true === $write_event['rollback_available'] && 0 === strpos( $action_id, 'task_' ), 'Successful write reports saved value and durable Undo' );
	bhranti_task_check( 128 === get_post_meta( $session, 'bhranti_run', true )['configuration']['max_steps'] && 129 === Bhranti_Settings::profile( 'bhranti' )['max_steps'], 'Active limit stays frozen while future profile uses 129' );
	$current_config = Bhranti_Settings::get()['config'];
	$expected_config = $config; $expected_config['max_steps'] = 129;
	bhranti_task_check( $expected_config === $current_config, 'No prompts, guardrails, agents, skills or action permissions changed' );
	$instruction = end( Bhranti_QA_Model::$calls )['config']['systemInstruction'];
	bhranti_task_check( false !== strpos( $instruction, Bhranti_Knowledge::instructions() ) && in_array( 'bhranti_task_limit', end( Bhranti_QA_Model::$calls )['config']['outputSchema']['properties']['tool']['enum'], true ), 'Native model gets built-in guidance and narrow strict-schema capability' );
	Bhranti_QA_Model::$responses = array( wp_json_encode( array( 'type' => 'reply', 'content' => 'Saved 129 for future tasks.', 'tool' => 'none', 'args' => '{}' ) ) );
	$done = bhranti_task_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $run_id, 'defer_tool' => true ) );
	bhranti_task_check( 200 === $done->get_status() && 'complete' === $done->get_data()['run']['status'], 'Task finishes without replaying successful changes' );
	$next_session = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $owner, 'post_title' => $task_marker ), true );
	bhranti_task_check( is_int( $next_session ) && $next_session > 0, 'Separate future-task fixture is created' );
	$task_sessions[] = $next_session;
	update_post_meta( $next_session, 'bhranti_task_qa', $task_marker );
	$future = bhranti_task_rest( 'POST', '/sessions/' . $next_session . '/messages', array( 'content' => 'Future task limit fixture.', 'context' => array(), 'agent_id' => 'bhranti' ) );
	bhranti_task_check( 200 === $future->get_status() && 129 === get_post_meta( $next_session, 'bhranti_run', true )['configuration']['max_steps'], 'The next submitted task captures the newly saved 129 limit' );
	$cancelled = bhranti_task_rest( 'POST', '/sessions/' . $next_session . '/cancel', array( 'run_id' => $future->get_data()['run']['id'] ) );
	bhranti_task_check( 200 === $cancelled->get_status(), 'Future fixture stops without invoking AI' );
	$before_undo_failure = bhranti_task_raw();
	$rollback_audit_attempts = 0;
	$rollback_audit_failure = static function ( $check, $id, $key ) use ( &$rollback_audit_attempts ) {
		if ( 'bhranti_event_data' === $key && 2 === ++$rollback_audit_attempts ) { return false; }
		return $check;
	};
	add_filter( 'add_post_metadata', $rollback_audit_failure, 10, 3 );
	try {
		$failed_undo = Bhranti_Actions::rollback( $action_id, $owner, $session );
		$task_last_option = bhranti_task_raw();
		bhranti_task_check( is_wp_error( $failed_undo ) && 'bhranti_audit_failed' === $failed_undo->get_error_code() && $before_undo_failure === bhranti_task_raw(), 'Final rollback audit failure restores exact saved Settings value/revision' );
		$records = get_post_meta( $session, 'bhranti_actions', true );
		bhranti_task_check( 'completed' === $records[ $action_id ]['state'], 'Proven safely failed rollback remains available without replaying an uncertain write' );
	} finally { remove_filter( 'add_post_metadata', $rollback_audit_failure, 10 ); }
	$undo = bhranti_task_rest( 'POST', '/sessions/' . $session . '/rollback', array( 'action_id' => $action_id ) );
	$task_last_option = bhranti_task_raw();
	bhranti_task_check( 200 === $undo->get_status() && 128 === Bhranti_Settings::get()['config']['max_steps'], 'Normal owned Undo restores only the prior task limit' );
	$events = $undo->get_data()['events']; $undo_event = end( $events );
	bhranti_task_check( 'rollback' === $undo_event['operation'] && 128 === $undo_event['task_limit']['max_steps'] && false === $undo_event['rollback_available'], 'Undo carries fresh saved value and ends its rollback availability' );
	$again = Bhranti_Actions::rollback( $action_id, $owner, $session );
	bhranti_task_check( is_wp_error( $again ) && 'bhranti_rollback_missing' === $again->get_error_code(), 'A used rollback cannot replay' );

	$read = bhranti_task_action( array( 'method' => 'GET' ), $owner, $session );
	$changed = bhranti_task_action( array( 'method' => 'POST', 'max_steps' => 129, 'revision' => $read['data']['revision'] ), $owner, $session );
	$human = Bhranti_Settings::get()['config']; $human['max_steps'] = 130;
	bhranti_task_human_save( $human );
	$before_conflict = bhranti_task_raw();
	$conflict = Bhranti_Actions::rollback( $changed['action_id'], $owner, $session );
	bhranti_task_check( is_wp_error( $conflict ) && 'bhranti_rollback_conflict' === $conflict->get_error_code() && $before_conflict === bhranti_task_raw(), 'Undo cannot overwrite a later human Settings save' );
	$human['permissions']['manage_site_settings'] = false;
	bhranti_task_human_save( $human );
	bhranti_task_check( ! isset( Bhranti_Actions::catalog()['bhranti_task_limit'] ), 'Disabled live switch removes the capability from the catalog' );
	$disabled = bhranti_task_action( array( 'method' => 'GET' ), $owner, $session );
	bhranti_task_check( is_wp_error( $disabled ) && 'bhranti_action_disabled' === $disabled->get_error_code(), 'A fabricated command cannot bypass the disabled switch' );
	bhranti_task_check( array() === $task_http, 'No real AI, remote request or mail was needed' );
	foreach ( Bhranti_Audit::events( $owner, 1, $session ) as $event ) {
		bhranti_task_check( false === strpos( wp_json_encode( $event ), ':private-document' ), 'Audit never copies private configuration text' );
		if ( '/bhranti/v1/task-limit' === ( $event['details']['path'] ?? '' ) ) {
			bhranti_task_check( $owner === $event['requester_id'] && $actor === $event['actor_id'], 'Task-limit audit distinguishes human requester and managed actor' );
		}
	}
} catch ( Throwable $error ) {
	$task_failure = $error;
} finally {
	Bhranti_QA_Model::$responses = array();
	$task_registry_property->setValue( null, $task_original_registry );
	AiClient::setEventDispatcher( $task_original_dispatcher );
	AiClient::setCache( $task_original_cache );
	if ( $task_captured && bhranti_task_raw() === $task_last_option ) {
		bhranti_task_restore( 'bhranti_settings', $task_original_option );
	} elseif ( $task_captured ) {
		$task_failure = $task_failure ?: new RuntimeException( 'FAIL: A concurrent Settings change was preserved; fixture option was not overwritten.' );
	}
	$current_lock = get_option( 'bhranti_settings_lock' );
	if ( ! $current_lock || ( is_array( $current_lock ) && ( $current_lock['token'] ?? '' ) === $task_marker ) ) {
		if ( $task_captured ) { bhranti_task_restore( 'bhranti_settings_lock', $task_original_lock ); }
	} elseif ( $task_captured && bhranti_task_raw( 'bhranti_settings_lock' ) !== $task_original_lock ) {
		$task_failure = $task_failure ?: new RuntimeException( 'FAIL: A concurrent Settings lock was preserved.' );
	}
	foreach ( $task_sessions as $session ) {
		$post = get_post( $session );
		if ( $post && 'bhranti_session' === $post->post_type && in_array( (int) $post->post_author, $task_users, true ) && $task_marker === get_post_meta( $session, 'bhranti_task_qa', true ) ) { wp_delete_post( $session, true ); }
	}
	foreach ( $task_users as $user ) {
		foreach ( get_posts( array( 'post_type' => 'bhranti_event', 'post_status' => 'private', 'author' => $user, 'posts_per_page' => -1 ) ) as $event ) { wp_delete_post( $event->ID, true ); }
		if ( ! function_exists( 'wp_delete_user' ) ) { require ABSPATH . 'wp-admin/includes/user.php'; }
		wp_delete_user( $user );
	}
	remove_filter( 'pre_schedule_event', $task_runner_veto, PHP_INT_MAX );
	remove_filter( 'pre_http_request', $task_http_guard, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
	wp_set_current_user( $task_original_user );
}
if ( $task_failure ) {
	fwrite( STDERR, $task_failure->getMessage() . "\n" );
	exit( 1 );
}
echo 'PASS: ' . $task_checks . " task-limit checks; exact original Settings storage restored; own users/sessions/audits removed.\n";
