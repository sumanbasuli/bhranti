<?php
/**
 * Real native task persistence, action receipts and privacy in a disposable site.
 *
 * BHRANTI_TASK_NATIVE_WP_ROOT=/private/tmp/bhranti-task-native-qa-20261014 php tests/task-native.php
 * Availability is process-local; no model, transport, mail or Cron callback runs.
 *
 * @package Bhranti
 */

$tn_root = realpath( getenv( 'BHRANTI_TASK_NATIVE_WP_ROOT' ) ?: '' );
if ( '/private/tmp/bhranti-task-native-qa-20261014' !== $tn_root ) {
	fwrite( STDERR, "Use only the dedicated private task-native QA runtime.\n" );
	exit( 1 );
}
define( 'WP_USE_THEMES', false );
require $tn_root . '/wp-load.php';
if ( 'https://bhranti-checker.invalid' !== get_option( 'home' ) || get_option( 'active_plugins' ) || ! DISABLE_WP_CRON ) {
	fwrite( STDERR, "The isolated offline runtime configuration is required.\n" );
	exit( 1 );
}
require dirname( __DIR__ ) . '/bhranti.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
Bhranti_Workspace::register_storage();
Bhranti_Audit::register_storage();

$tn_checks   = 0;
$tn_http     = 0;
$tn_mail     = 0;
$tn_model    = 0;
$tn_cron     = 0;
$tn_owner    = 0;
$tn_other    = 0;
$tn_actor    = 0;
$tn_session  = 0;
$tn_sessions = array();
$tn_posts    = array();
$tn_runs     = array();
$tn_marker   = 'bhranti-task-native-' . str_replace( '-', '', wp_generate_uuid4() );
$tn_user_before = get_current_user_id();
$tn_options_before = array();
foreach ( array( 'wp_user_roles', 'bhranti_actor_id', 'bhranti_role_owned', 'bhranti_actor_setup_lock', 'bhranti_settings', 'blogname', 'blogdescription', 'cron' ) as $tn_key ) {
	$tn_options_before[ $tn_key ] = get_option( $tn_key, null );
}
$tn_baseline = array();
$tn_users_before = array_map( 'intval', get_users( array( 'fields' => 'ID' ) ) );
foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => array_keys( get_post_stati() ), 'numberposts' => -1 ) ) as $tn_post ) {
	$tn_baseline[ $tn_post->ID ] = hash( 'sha256', wp_json_encode( array( $tn_post, get_post_meta( $tn_post->ID ) ) ) );
}
$tn_http_guard = static function () {
	++$GLOBALS['tn_http'];
	return new WP_Error( 'bhranti_task_qa_offline', 'Outbound requests are disabled.' );
};
$tn_mail_guard = static function () {
	++$GLOBALS['tn_mail'];
	return false;
};
$tn_schedule_guard = static function ( $pre, $event ) {
	if ( 'bhranti_run_tick' === $event->hook && in_array( (int) ( $event->args[0] ?? 0 ), $GLOBALS['tn_sessions'], true ) && (int) ( $event->args[2] ?? 0 ) === $GLOBALS['tn_owner'] ) {
		return $pre;
	}
	return new WP_Error( 'bhranti_task_qa_schedule', 'Only owned QA run events may be queued.' );
};
$tn_cron_guard = static function () {
	++$GLOBALS['tn_cron'];
	throw new RuntimeException( 'Native QA must not execute a Cron callback.' );
};
add_filter( 'pre_http_request', $tn_http_guard );
add_filter( 'pre_wp_mail', $tn_mail_guard );
add_filter( 'pre_schedule_event', $tn_schedule_guard, 10, 2 );
add_action( 'bhranti_run_tick', $tn_cron_guard, -1000 );
$tn_failure = null;

/** No data values, credentials or arbitrary error payloads reach test output. */
function tn_check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
	++$GLOBALS['tn_checks'];
}

/** Use actual private orchestration around real stored native action results. */
function tn_agent( $method, ...$args ) {
	$reflection = new ReflectionMethod( 'Bhranti_Agent', $method );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, ...$args );
}

/** Dispatch native Actions, then settle its unchanged result into real metadata. */
function tn_tool( $command ) {
	$session = $GLOBALS['tn_session'];
	$run = get_post_meta( $session, 'bhranti_run', true );
	++$run['step'];
	$run['phase'] = 'action';
	$run['history'][] = array( 'role' => 'assistant', 'content' => wp_json_encode( array( 'type' => 'tool' ) + $command ) );
	tn_check( ! is_wp_error( tn_agent( 'save_run', $session, $run ) ), 'Native run records the executing step.' );
	$result = Bhranti_Actions::execute( $command, $GLOBALS['tn_owner'], $session );
	$settled = tn_agent( 'tool_result', get_post( $session ), $run, $result, $command );
	tn_check( ! is_wp_error( $settled ), 'The exact native result settles into a persisted receipt.' );
	$run = get_post_meta( $session, 'bhranti_run', true );
	$GLOBALS['tn_last_receipt'] = end( $run['receipts'] );
	return $result;
}

/** Read/update the real owned checkpoint through the action dispatcher. */
function tn_task( $method, $params = array() ) {
	return tn_tool( array( 'tool' => 'bhranti_task', 'args' => array( 'method' => $method, 'params' => $params ) ) );
}

/** Make an owned WordPress conversation without creating site-visible content. */
function tn_session( $owner, $suffix ) {
	$id = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $owner, 'post_title' => $GLOBALS['tn_marker'] . '-' . $suffix ), true );
	tn_check( is_int( $id ), 'A native isolated conversation fixture is created.' );
	$GLOBALS['tn_sessions'][] = $id;
	return $id;
}

/** Restore native user capabilities after a scoped revocation case. */
function tn_restore_caps( $id, $caps ) {
	update_user_meta( $id, $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities', $caps );
	clean_user_cache( $id );
	wp_set_current_user( 0 );
	wp_set_current_user( $GLOBALS['tn_owner'] );
}

/** Native registry availability only; attempting any model work is a failure. */
final class BhrantiTaskNativeAvailability implements WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface {
	public function isConfigured(): bool {
		return true;
	}
}

/** Native registry wiring may obtain this object; every discovery method is denied. */
final class BhrantiTaskNativeDirectory implements WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface {
	public function listModelMetadata(): array {
		++$GLOBALS['tn_model'];
		throw new RuntimeException( 'Native QA must not discover models.' );
	}
	public function hasModelMetadata( string $model_id ): bool {
		++$GLOBALS['tn_model'];
		throw new RuntimeException( 'Native QA must not discover models.' );
	}
	public function getModelMetadata( string $model_id ): WordPress\AiClient\Providers\Models\DTO\ModelMetadata {
		++$GLOBALS['tn_model'];
		throw new RuntimeException( 'Native QA must not discover models.' );
	}
}

/** Process-local fixture uses no credentials, request transport or persistent settings. */
final class BhrantiTaskNativeProvider implements WordPress\AiClient\Providers\Contracts\ProviderInterface {
	public static function metadata(): WordPress\AiClient\Providers\DTO\ProviderMetadata {
		return new WordPress\AiClient\Providers\DTO\ProviderMetadata( 'bhranti-task-native-fixture', 'Offline availability fixture', WordPress\AiClient\Providers\Enums\ProviderTypeEnum::server() );
	}
	public static function availability(): WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface {
		return new BhrantiTaskNativeAvailability();
	}
	public static function model( string $model_id, ?WordPress\AiClient\Providers\Models\DTO\ModelConfig $model_config = null ): WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
		++$GLOBALS['tn_model'];
		throw new RuntimeException( 'Native QA must not request a model.' );
	}
	public static function modelMetadataDirectory(): WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface {
		return new BhrantiTaskNativeDirectory();
	}
}

try {
	$tn_owner = wp_insert_user( array( 'user_login' => $tn_marker, 'user_pass' => wp_generate_password( 40 ), 'user_email' => $tn_marker . '@example.invalid', 'role' => 'administrator' ) );
	$tn_other = wp_insert_user( array( 'user_login' => $tn_marker . '-other', 'user_pass' => wp_generate_password( 40 ), 'user_email' => $tn_marker . '-other@example.invalid', 'role' => 'administrator' ) );
	tn_check( is_int( $tn_owner ) && is_int( $tn_other ), 'Fresh isolated native user fixtures exist.' );
	wp_set_current_user( $tn_owner );
	$tn_actor = Bhranti_Actions::ensure_actor();
	tn_check( is_int( $tn_actor ), 'Managed native actor initializes in the private QA runtime.' );
	$tn_actor_caps = get_userdata( $tn_actor )->caps;
	$tn_owner_caps = get_userdata( $tn_owner )->caps;
	$tn_session = tn_session( $tn_owner, 'primary' );
	$tn_second = tn_session( $tn_owner, 'second' );
	$tn_foreign = tn_session( $tn_other, 'foreign' );
	update_post_meta( $tn_foreign, 'bhranti_task_foreign_fixture', $tn_marker . '-unrelated-private-data' );
	$tn_foreign_before = hash( 'sha256', wp_json_encode( array( get_post( $tn_foreign ), get_post_meta( $tn_foreign ) ) ) );
	$tn_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_author' => $tn_owner, 'post_title' => $tn_marker . '-page', 'post_content' => '<!-- wp:paragraph --><p>Original fixture.</p><!-- /wp:paragraph -->' ), true );
	tn_check( is_int( $tn_page ), 'A native draft page fixture is created.' );
	$tn_posts[] = $tn_page;
	$tn_path = '/wp/v2/pages/' . $tn_page;
	WordPress\AiClient\AiClient::defaultRegistry()->registerProvider( BhrantiTaskNativeProvider::class );
	WP_Connector_Registry::get_instance()->register( 'bhranti-task-native-fixture', array( 'name' => 'Offline availability fixture', 'type' => 'ai_provider', 'authentication' => array( 'method' => 'none' ) ) );
	tn_check( Bhranti_Workspace::ai_status()['available'], 'Native start can check fixture availability without contacting a provider.' );
	$started = Bhranti_Agent::start( array( 'id' => $tn_session, 'content' => 'Prepare the requested visitor guide and site title.', 'context' => array() ) );
	tn_check( ! is_wp_error( $started ), 'Actual Agent start stores a native owned run.' );
	$tn_run = get_post_meta( $tn_session, 'bhranti_run', true );
	$tn_runs[] = array( $tn_session, $tn_run['id'], $tn_owner );
	tn_check( wp_next_scheduled( 'bhranti_run_tick', end( $tn_runs ) ), 'Actual native run scheduling is stored without executing Cron.' );
	$initial = tn_task( 'GET' );
	tn_check( ! is_wp_error( $initial ) && 'initial' === $initial['task']['revision'], 'Real metadata starts without invented notes or completion.' );
	$tn_notes = array(
		'revision' => 'initial',
		'objective' => "Complete the owner's façade and preserve a literal \\ path.",
		'decisions' => array( "The owner's confirmed wording uses café and \\ notation." ),
		'outcomes' => array(
			array( 'id' => 'guide', 'title' => 'Save the visitor guide', 'status' => 'in_progress', 'evidence' => array() ),
			array( 'id' => 'title', 'title' => 'Set the site title', 'status' => 'pending', 'evidence' => array() ),
		),
		'checks' => array(
			array( 'id' => 'guide-readback', 'title' => 'Read the saved guide', 'status' => 'pending', 'evidence' => array() ),
			array( 'id' => 'settings-readback', 'title' => 'Read the saved site title', 'status' => 'pending', 'evidence' => array() ),
		),
	);
	$notes_saved = tn_task( 'POST', $tn_notes );
	tn_check( ! is_wp_error( $notes_saved ), 'Native checkpoint with Unicode, apostrophes and backslashes is accepted.' );
	tn_check( $notes_saved['task'] === get_post_meta( $tn_session, 'bhranti_task', true ), 'wp_slash and native metadata preserve the entire checkpoint exactly.' );
	$conflict = tn_task( 'POST', $tn_notes );
	tn_check( is_wp_error( $conflict ) && 'bhranti_task_conflict' === $conflict->get_error_code() && $notes_saved['task'] === get_post_meta( $tn_session, 'bhranti_task', true ), 'Stale native revision cannot overwrite the saved checkpoint.' );
	$tn_notes = $notes_saved['task'];
	$tn_content = '<!-- wp:paragraph --><p>The visitor’s guide includes café information and a confirmed contact route.</p><!-- /wp:paragraph -->';
	$page_write = tn_tool( array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => $tn_path, 'params' => array( 'title' => $tn_marker . '-saved-guide', 'content' => $tn_content ) ) ) );
	tn_check( ! is_wp_error( $page_write ) && $tn_content === get_post( $tn_page )->post_content, 'Actual native page controller saves the requested block content.' );
	$page_write_id = $tn_last_receipt['id'];
	tn_check( 'page:' . $tn_page === $tn_last_receipt['entity'] && array( 'title', 'content' ) === $tn_last_receipt['fields'], 'Native page write receipt records its target and changed fields only.' );
	$id_only = tn_tool( array( 'tool' => 'wp_request', 'args' => array( 'method' => 'GET', 'path' => $tn_path, 'params' => array( 'context' => 'edit', '_fields' => 'id' ) ) ) );
	tn_check( ! is_wp_error( $id_only ) && array( 'id' ) === array_keys( $id_only['data'] ), 'Native id-only projection returns no authored content.' );
	$bad_notes = $tn_notes;
	$bad_notes['checks'][0] = array_merge( $bad_notes['checks'][0], array( 'status' => 'read_back', 'evidence' => array( $page_write_id, $tn_last_receipt['id'] ) ) );
	$bad_check = tn_task( 'POST', $bad_notes );
	tn_check( is_wp_error( $bad_check ) && 'bhranti_task_evidence' === $bad_check->get_error_code(), 'A real id-only read cannot prove saved content readback.' );
	$view_only = tn_tool( array( 'tool' => 'wp_request', 'args' => array( 'method' => 'GET', 'path' => $tn_path, 'params' => array( 'context' => 'view', '_fields' => 'id,title,content' ) ) ) );
	tn_check( ! is_wp_error( $view_only ) && isset( $view_only['data']['content']['rendered'] ) && ! isset( $view_only['data']['content']['raw'] ), 'Native view context returns rendered content without source.' );
	$bad_notes['checks'][0]['evidence'][1] = $tn_last_receipt['id'];
	$bad_check = tn_task( 'POST', $bad_notes );
	tn_check( is_wp_error( $bad_check ) && 'bhranti_task_evidence' === $bad_check->get_error_code(), 'A real rendered-only read cannot prove authored saved-state readback.' );
	$page_read = tn_tool( array( 'tool' => 'wp_request', 'args' => array( 'method' => 'GET', 'path' => $tn_path, 'params' => array( 'context' => 'edit', '_fields' => 'id,title.raw,content.raw' ) ) ) );
	tn_check( ! is_wp_error( $page_read ) && $tn_content === $page_read['data']['content']['raw'], 'Native edit-context read returns the exact saved source.' );
	$page_read_id = $tn_last_receipt['id'];
	$settings_write = tn_tool( array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => '/wp/v2/settings', 'params' => array( 'title' => $tn_marker . '-site-title' ) ) ) );
	tn_check( ! is_wp_error( $settings_write ) && $tn_marker . '-site-title' === get_option( 'blogname' ), 'Actual native settings controller saves the title' . ( is_wp_error( $settings_write ) ? ' (' . $settings_write->get_error_code() . ')' : '' ) . '.' );
	$settings_write_id = $tn_last_receipt['id'];
	tn_check( 'wp_request:/wp/v2/settings' === $tn_last_receipt['entity'], 'Native singleton settings write has an exact stable receipt identity.' );
	$settings_read = tn_tool( array( 'tool' => 'wp_request', 'args' => array( 'method' => 'GET', 'path' => '/wp/v2/settings', 'params' => array( '_fields' => 'title' ) ) ) );
	tn_check( ! is_wp_error( $settings_read ) && $tn_marker . '-site-title' === $settings_read['data']['title'], 'Actual native settings read retrieves the exact saved value.' );
	$settings_read_id = $tn_last_receipt['id'];
	$tn_notes['outcomes'][0]['status'] = 'completed';
	$tn_notes['outcomes'][0]['evidence'] = array( $page_write_id );
	$tn_notes['outcomes'][1]['status'] = 'completed';
	$tn_notes['outcomes'][1]['evidence'] = array( $settings_write_id );
	$tn_notes['checks'][0]['status'] = 'read_back';
	$tn_notes['checks'][0]['evidence'] = array( $page_write_id, $page_read_id );
	$tn_notes['checks'][1]['status'] = 'read_back';
	$tn_notes['checks'][1]['evidence'] = array( $settings_write_id, $settings_read_id );
	$verified = tn_task( 'POST', $tn_notes );
	tn_check( ! is_wp_error( $verified ) && ! Bhranti_Task::remaining( $tn_session ), 'Real page and singleton settings receipts satisfy saved-state checks.' );
	$canonical_notes = $verified['task'];
	$second_write = tn_tool( array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => $tn_path, 'params' => array( 'title' => $tn_marker . '-updated-guide' ) ) ) );
	tn_check( ! is_wp_error( $second_write ), 'A later real native edit succeeds.' );
	$public_notes = Bhranti_Agent::state( $tn_session )['task'];
	tn_check( 'pending' === $public_notes['checks'][0]['status'] && 'read_back' === $public_notes['checks'][1]['status'], 'Public notes immediately distinguish stale page readback from valid settings readback.' );
	tn_check( $canonical_notes === get_post_meta( $tn_session, 'bhranti_task', true ) && $canonical_notes['revision'] === $public_notes['revision'], 'Stale display projection never rewrites saved notes or revision.' );
	$tn_run = get_post_meta( $tn_session, 'bhranti_run', true );
	$prior_id = $tn_run['id'];
	$prior_receipts = $tn_run['receipts'];
	$waiting = tn_agent( 'finish', get_post( $tn_session ), $tn_run, 'Which confirmed contact wording should the visitor guide use?', 'awaiting_input' );
	tn_check( ! is_wp_error( $waiting ) && 'awaiting_input' === $waiting['run']['status'] && ! wp_next_scheduled( 'bhranti_run_tick', array( $tn_session, $prior_id, $tn_owner ) ), 'Native question finish persists the message and removes only its own scheduled continuation.' );
	$continued = Bhranti_Agent::start( array( 'id' => $tn_session, 'content' => 'Use the existing contact details and retain the revised title.', 'context' => array() ) );
	tn_check( ! is_wp_error( $continued ), 'The actual human answer starts a new run without a provider call.' );
	$tn_run = get_post_meta( $tn_session, 'bhranti_run', true );
	$tn_runs[] = array( $tn_session, $tn_run['id'], $tn_owner );
	tn_check( $prior_id === $tn_run['previous_run_id'] && $prior_receipts === Bhranti_Task::receipts( $tn_session, $prior_id ), 'Native start archives every exact prior receipt before replacing run state.' );
	tn_check( $canonical_notes === get_post_meta( $tn_session, 'bhranti_task', true ), 'New native run preserves the canonical checkpoint.' );
	$archive = tn_task( 'GET', array( 'run_id' => $prior_id, 'page' => 1, 'per_page' => 20 ) );
	tn_check( ! is_wp_error( $archive ) && count( $prior_receipts ) === $archive['total'] && $page_write_id === Bhranti_Task::receipt( $tn_session, $page_write_id )['id'], 'Archived receipt lookup remains private, exact and paginated after rollover.' );
	$before_denials = get_post_meta( $tn_session, 'bhranti_task', true );
	wp_set_current_user( $tn_other );
	tn_check( is_wp_error( Bhranti_Task::state( $tn_session ) ) && array() === Bhranti_Task::receipts( $tn_session, $prior_id ), 'A different native administrator cannot read notes or archived private IDs.' );
	wp_set_current_user( $tn_owner );
	tn_check( is_wp_error( Bhranti_Task::execute( array( 'tool' => 'bhranti_task', 'args' => array( 'method' => 'GET' ) ), $tn_owner, $tn_other, $tn_session ) ), 'A capable but unbound actor cannot access this run.' );
	get_userdata( $tn_owner )->add_cap( 'manage_options', false );
	wp_set_current_user( 0 );
	wp_set_current_user( $tn_owner );
	tn_check( is_wp_error( Bhranti_Task::execute( array( 'tool' => 'bhranti_task', 'args' => array( 'method' => 'GET' ) ), $tn_owner, $tn_actor, $tn_session ) ), 'Native requester capability revocation is honored immediately.' );
	tn_restore_caps( $tn_owner, $tn_owner_caps );
	get_userdata( $tn_actor )->add_cap( 'manage_options', false );
	tn_check( is_wp_error( Bhranti_Task::execute( array( 'tool' => 'bhranti_task', 'args' => array( 'method' => 'GET' ) ), $tn_owner, $tn_actor, $tn_session ) ), 'Native actor capability revocation is honored immediately.' );
	tn_restore_caps( $tn_actor, $tn_actor_caps );
	tn_check( $before_denials === get_post_meta( $tn_session, 'bhranti_task', true ), 'Denied requests never mutate task notes.' );
	$owner_email = get_userdata( $tn_owner )->user_email;
	$seen_sessions = array();
	$export_done = false;
	$export_contains_archive = false;
	for ( $export_page = 1; $export_page <= 10; ++$export_page ) {
		$export = Bhranti_Privacy::export( $owner_email, $export_page );
		$batch_sessions = 0;
		foreach ( $export['data'] as $item ) {
			if ( 'bhranti-conversations' !== $item['group_id'] ) {
				continue;
			}
			++$batch_sessions;
			$seen_sessions[] = $item['item_id'];
			foreach ( $item['data'] as $entry ) {
				if ( 'Request context and action results' === $entry['name'] ) {
					$decoded = json_decode( html_entity_decode( $entry['value'], ENT_QUOTES, 'UTF-8' ), true );
					$export_contains_archive = $export_contains_archive || isset( $decoded['task_checkpoints']['runs'][ $prior_id ] );
				}
			}
		}
		tn_check( $batch_sessions <= 1 && false === strpos( wp_json_encode( $export ), $tn_marker . '-unrelated-private-data' ), 'Native exporter batches one owned conversation and excludes another owner’s data.' );
		if ( $export['done'] ) {
			$export_done = true;
			break;
		}
	}
	tn_check( $export_done && 2 === count( array_unique( $seen_sessions ) ) && 2 === count( $seen_sessions ) && $export_contains_archive, 'Native export completes pagination and includes canonical checkpoints and exact archives once.' );
	$erase_done = false;
	for ( $erase_page = 1; $erase_page <= 10; ++$erase_page ) {
		$erased = Bhranti_Privacy::erase( $owner_email, $erase_page );
		tn_check( ! $erased['items_retained'], 'Native erasure removes the owner’s workspace without retained fixture records.' );
		if ( $erased['done'] ) {
			$erase_done = true;
			break;
		}
	}
	tn_check( $erase_done && ! get_post( $tn_session ) && ! get_post( $tn_second ) && array() === get_post_meta( $tn_session ) && array() === get_post_meta( $tn_second ), 'Native erasure removes sessions and every checkpoint/archive/snapshot metadata field.' );
	tn_check( ! wp_next_scheduled( 'bhranti_run_tick', end( $tn_runs ) ), 'Deleting the active owned session clears its exact native scheduled continuation.' );
	tn_check( $tn_foreign_before === hash( 'sha256', wp_json_encode( array( get_post( $tn_foreign ), get_post_meta( $tn_foreign ) ) ) ) && $tn_content === get_post( $tn_page )->post_content, 'Privacy cleanup preserves unrelated conversation data and created site content.' );
	tn_check( 0 === $tn_http && 0 === $tn_mail && 0 === $tn_model && 0 === $tn_cron, 'No HTTP, mail, model discovery, provider or Cron callback ran.' );
} catch ( Throwable $error ) {
	$tn_failure = $error->getMessage();
} finally {
	if ( isset( $tn_owner_caps ) && $tn_owner && get_userdata( $tn_owner ) ) {
		tn_restore_caps( $tn_owner, $tn_owner_caps );
	}
	if ( isset( $tn_actor_caps ) && $tn_actor && get_userdata( $tn_actor ) ) {
		tn_restore_caps( $tn_actor, $tn_actor_caps );
	}
	wp_set_current_user( $tn_user_before );
	foreach ( $tn_runs as $tn_args ) {
		wp_clear_scheduled_hook( 'bhranti_run_tick', $tn_args, true );
	}
	foreach ( array_merge( $tn_sessions, $tn_posts ) as $tn_id ) {
		if ( get_post( $tn_id ) ) {
			wp_delete_post( $tn_id, true );
		}
	}
	foreach ( array( $tn_owner, $tn_other ) as $tn_id ) {
		if ( ! $tn_id || ! get_userdata( $tn_id ) ) {
			continue;
		}
		foreach ( get_posts( array( 'post_type' => 'bhranti_event', 'post_status' => 'private', 'author' => $tn_id, 'numberposts' => -1 ) ) as $tn_event ) {
			wp_delete_post( $tn_event->ID, true );
		}
		if ( 0 === strpos( get_userdata( $tn_id )->user_login, $tn_marker ) ) {
			wp_delete_user( $tn_id );
		}
		delete_option( 'bhranti_lock_' . $tn_id );
	}
	if ( $tn_actor && ! $tn_options_before['bhranti_actor_id'] && get_user_meta( $tn_actor, 'bhranti_managed_actor', true ) ) {
		wp_delete_user( $tn_actor );
	}
	foreach ( $tn_options_before as $tn_key => $tn_value ) {
		if ( null === $tn_value ) {
			delete_option( $tn_key );
		} else {
			update_option( $tn_key, $tn_value );
		}
	}
	remove_filter( 'pre_http_request', $tn_http_guard );
	remove_filter( 'pre_wp_mail', $tn_mail_guard );
	remove_filter( 'pre_schedule_event', $tn_schedule_guard, 10 );
	remove_action( 'bhranti_run_tick', $tn_cron_guard, -1000 );
}
foreach ( array_merge( $tn_sessions, $tn_posts ) as $tn_id ) {
	if ( get_post( $tn_id ) ) {
		$tn_failure = 'An isolated task fixture was not removed.';
	}
}
foreach ( $tn_baseline as $tn_id => $tn_hash ) {
	if ( $tn_hash !== hash( 'sha256', wp_json_encode( array( get_post( $tn_id ), get_post_meta( $tn_id ) ) ) ) ) {
		$tn_failure = 'An existing isolated seed record changed.';
	}
}
foreach ( $tn_options_before as $tn_key => $tn_value ) {
	if ( get_option( $tn_key, null ) !== $tn_value ) {
		$tn_failure = 'An isolated role, scheduler or setting option was not restored.';
	}
}
if ( $tn_users_before !== array_map( 'intval', get_users( array( 'fields' => 'ID' ) ) ) ) {
	$tn_failure = 'An isolated user fixture was not removed.';
}
if ( $tn_http || $tn_mail || $tn_model || $tn_cron ) {
	$tn_failure = 'An outbound or model request occurred during isolated cleanup.';
}
if ( $tn_failure ) {
	fwrite( STDERR, 'FAIL: ' . $tn_failure . "\n" );
	exit( 1 );
}
echo 'PASS: ' . $tn_checks . " native task/action/persistence/privacy checks; isolated fixtures cleaned, no external calls.\n";
