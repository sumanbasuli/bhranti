<?php
/**
 * Actual Agent/Task/Runner orchestration with native DTOs and in-memory seams.
 *
 * The Render seam supplies deterministic captured browser results; the separate
 * render-engine tests exercise actual browser measurement and report validation.
 * No wp-load.php, database, native provider, browser, HTTP or tool dispatch runs.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/rendered-lifecycle.php
 *
 * @package Bhranti
 */

function get_posts( $args ) { return array(); }
function admin_url( $path = '' ) { return 'https://bhranti.wp.local/wp-admin/' . $path; }
function _canonical_charset( $charset ) { return 'UTF-8'; }

/** Deterministic browser boundary; it cannot save content or request a provider. */
final class Bhranti_Render {
	public static $prepared = 0;
	public static $results = 0;
	public static $revision = '';
	public static $environment = '';
	public static $permission = true;
	public static function is_command( $command ) { return 'bhranti_inspect' === ( $command['tool'] ?? '' ) && 'rendered' === ( $command['args']['resource'] ?? '' ); }
	public static function prepare( $command, $owner, $session, $run ) {
		++self::$prepared;
		if ( ! self::$permission || array_diff( array_keys( $command['args'] ), array( 'resource', 'path' ) ) ) { return new WP_Error( 'bhranti_render_denied', 'Check unavailable.' ); }
		return array( 'id' => wp_generate_uuid4(), 'run_id' => $run['id'], 'step' => $run['step'], 'path' => $command['args']['path'], 'post_id' => 20, 'url' => 'https://bhranti.wp.local/?page_id=20', 'revision' => self::$revision, 'environment_revision' => self::$environment, 'marker' => hash( 'sha256', 'fixture-marker-' . self::$prepared ), 'expires' => time() + 600, 'target' => array( 'id' => 20, 'type' => 'page', 'title' => 'Existing page', 'url' => admin_url( 'post.php?post=20&action=edit' ) ), 'viewports' => array( array( 'width' => 1280, 'height' => 900 ), array( 'width' => 390, 'height' => 844 ), array( 'width' => 320, 'height' => 844 ) ), 'engine' => 'axe-core', 'version' => '4.14.0' );
	}
	public static function public_job( $job ) { return $job; }
	public static function current( $job, $owner, $session ) { return self::$permission && $job['revision'] === self::$revision && $job['environment_revision'] === self::$environment; }
	public static function result( $job, $report, $owner, $session ) {
		++self::$results;
		if ( ! self::current( $job, $owner, $session ) || $job['expires'] <= time() || 'invalid' === ( $report['status'] ?? '' ) ) { return new WP_Error( 'bhranti_render_invalid', 'Check report rejected.' ); }
		$passed = 'complete' === $report['status'] && empty( $report['needs_review'] );
		return array( 'summary' => 'Actual bounded browser check result.', 'data' => array( 'status' => $report['status'], 'passed' => $passed, 'findings' => array() ), 'render_report' => $report, 'content_revision' => $job['revision'], 'render_check' => array( 'version' => 1, 'job_id' => $job['id'], 'source_revision' => $job['revision'], 'environment_revision' => $job['environment_revision'], 'observed_at' => time(), 'passed' => $passed, 'needs_review' => $report['needs_review'] ?? 0, 'viewports' => array( 1280, 390, 320 ), 'engine' => 'axe-core', 'engine_version' => '4.14.0' ), 'target' => $job['target'], 'rollback_available' => false );
	}
	public static function valid_receipt( $check ) {
		return is_array( $check ) && 1 === ( $check['version'] ?? null ) && is_bool( $check['passed'] ?? null ) && is_int( $check['needs_review'] ?? null ) && isset( $check['job_id'], $check['source_revision'], $check['environment_revision'] ) && array( 1280, 390, 320 ) === ( $check['viewports'] ?? null );
	}
	public static function valid_evidence( $receipt, $owner, $session ) {
		$check = $receipt['render_check'] ?? null;
		return self::valid_receipt( $check ) && self::$permission && true === $check['passed'] && 0 === $check['needs_review'] && $check['source_revision'] === self::$revision && $check['environment_revision'] === self::$environment;
	}
}

require __DIR__ . '/agent-clarification.php';
require ABSPATH . 'wp-includes/kses.php';
require ABSPATH . 'wp-includes/http.php';
$rb_initial = $GLOBALS['ac']['checks'];
$GLOBALS['rb_routes'] = array();
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['rb_routes'][ $namespace . $route ] = $args; }

/** Only in-memory fixtures and the real public planning path are used. */
function rb_start( $defer = true ) {
	$GLOBALS['ac']['meta'][1] = array();
	$GLOBALS['ac']['user'] = 7;
	$GLOBALS['ac']['cron'] = array();
	Bhranti_Render::$revision = hash( 'sha256', 'Native saved page.' );
	Bhranti_Render::$environment = hash( 'sha256', 'Native theme and design state.' );
	Bhranti_Render::$permission = true;
	$started = ac_start( 'Check the saved draft using the supported automated browser profile.' );
	ac_check( ! is_wp_error( $started ), 'Owned browser task starts.' );
	$command = array( 'resource' => 'rendered', 'path' => '/wp/v2/pages/20' );
	$GLOBALS['ac']['reply'] = ac_envelope( 'tool', 'Check the saved desktop and mobile layout.', 'bhranti_inspect', wp_json_encode( $command ) );
	$next = Bhranti_Agent::step( array( 'id' => 1, 'run_id' => $started['run']['id'], 'defer_tool' => $defer ) );
	ac_check( ! is_wp_error( $next ), 'One model planning turn creates a browser job.' );
	return get_post_meta( 1, 'bhranti_run', true );
}
/** Bind an owned browser POST without exposing any executable command. */
function rb_request( $run, $report = null ) {
	$request = array( 'id' => 1, 'run_id' => $run['id'], 'prepared_step' => $run['step'], 'job_id' => $run['render_job']['id'] );
	if ( null !== $report ) { $request['report'] = $report; }
	return $request;
}
/** Native metadata-only checkpoint tool; never content actions. */
function rb_notes( $status, $evidence ) {
	$state = Bhranti_Task::state( 1 );
	return Bhranti_Task::execute( array( 'tool' => 'bhranti_task', 'args' => array( 'method' => 'POST', 'params' => array( 'revision' => $state['revision'], 'objective' => 'Finish the supported automated browser checks.', 'decisions' => array( 'Full human accessibility conformance review is outside this automated profile.' ), 'outcomes' => array(), 'checks' => array( array( 'id' => 'rendered-profile', 'title' => 'Automated rendered layout/accessibility checks', 'status' => $status, 'evidence' => $evidence ) ) ) ) ), 7, 7, 1 );
}
function rb_error( $result, $label ) { ac_check( is_wp_error( $result ), $label ); }

Bhranti_Agent::register_routes();
foreach ( array( 'render-start', 'render-result' ) as $route ) {
	$registered = $GLOBALS['rb_routes']['bhranti/v1/sessions/(?P<id>\d+)/' . $route];
	ac_check( 'POST' === $registered['methods'] && array( 'Bhranti_Workspace', 'can_access' ) === $registered['permission_callback'] && ! empty( $registered['args']['prepared_step']['required'] ) && ! empty( $registered['args']['job_id']['required'] ), 'Browser endpoints keep native authenticated owner access and exact bindings.' );
}
foreach ( array( true, false ) as $defer ) {
	$run = rb_start( $defer );
	$state = Bhranti_Agent::state( 1 );
	ac_check( 'browser' === $run['phase'] && 'running' === $run['status'] && $run['pending_plan']['args'] === array( 'resource' => 'rendered', 'path' => '/wp/v2/pages/20' ), 'Deferred and direct model steps both preserve the canonical command in browser phase.' );
	ac_check( isset( $state['run']['render_job'], $state['run']['next_action'] ) && false === $state['run']['background'] && false === $state['run']['next_action']['write'], 'Owned public state exposes the job as read-only foreground work.' );
	ac_check( ! wp_next_scheduled( 'bhranti_run_tick', array( 1, $run['id'], 7 ) ), 'Browser phase clears the exact background event.' );
	$calls = $GLOBALS['ac']['calls']; $before = $run;
	for ( $index = 0; $index < 3; ++$index ) { ac_step( $run['id'], ac_envelope( 'reply', 'This must not run.' ) ); Bhranti_Runner::tick( 1, $run['id'], 7 ); Bhranti_Runner::schedule( 1, $run['id'], 7 ); }
	ac_check( $calls === $GLOBALS['ac']['calls'] && $before === get_post_meta( 1, 'bhranti_run', true ) && ! wp_next_scheduled( 'bhranti_run_tick', array( 1, $run['id'], 7 ) ), 'Browser waiting spends no model steps, makes no tool calls and cannot be advanced by Cron.' );
}

$legacy = $run; $legacy['phase'] = 'prepared'; unset( $legacy['render_job'] ); update_post_meta( 1, 'bhranti_run', $legacy );
$executed = Bhranti_Agent::execute( array( 'id' => 1, 'run_id' => $legacy['id'], 'prepared_step' => $legacy['step'], 'unsaved_changes' => true ) );
$run = get_post_meta( 1, 'bhranti_run', true );
ac_check( ! is_wp_error( $executed ) && 'browser' === $run['phase'] && $legacy['step'] === $run['step'], 'An already prepared browser command routes before native action consumption, even with unsaved editor content.' );
ac_check( false === ( new ReflectionMethod( Bhranti_Runner::class, 'is_write' ) )->invoke( null, $run ), 'Browser measurement is a genuine read for native editor safeguards.' );

$request = rb_request( $run ); $count = Bhranti_Render::$prepared;
ac_check( ! is_wp_error( Bhranti_Agent::render_start( $request ) ) && $count === Bhranti_Render::$prepared, 'A current nonexpired start is an idempotent state read.' );
$expired = $run; $expired['render_job']['expires'] = time() - 1; update_post_meta( 1, 'bhranti_run', $expired );
Bhranti_Agent::render_start( $request ); $renewed = get_post_meta( 1, 'bhranti_run', true );
ac_check( $run['step'] === $renewed['step'] && $run['pending_plan'] === $renewed['pending_plan'] && $run['render_job']['id'] !== $renewed['render_job']['id'], 'An expired job renews the exact read at the same model step.' );
$old = $request; $old['report'] = array( 'status' => 'complete' );
rb_error( Bhranti_Agent::render_result( $old ), 'A renewed job rejects the old worker report.' );
Bhranti_Render::$environment = hash( 'sha256', 'New native design state.' );
Bhranti_Agent::render_start( rb_request( $renewed ) ); $run = get_post_meta( 1, 'bhranti_run', true );
ac_check( $renewed['step'] === $run['step'] && $renewed['render_job']['id'] !== $run['render_job']['id'] && Bhranti_Render::$environment === $run['render_job']['environment_revision'], 'Changed rendering state renews without a model turn or stale-report loop.' );
foreach ( array( array( 'run_id' => '00000000-0000-4000-8000-999999999999' ), array( 'prepared_step' => $run['step'] + 1 ), array( 'job_id' => '00000000-0000-4000-8000-999999999999' ) ) as $change ) {
	$bad = array_merge( rb_request( $run, array( 'status' => 'complete' ) ), $change );
	rb_error( Bhranti_Agent::render_result( $bad ), 'Wrong run, step or job cannot settle a browser report.' );
}
$GLOBALS['ac']['user'] = 8;
rb_error( Bhranti_Agent::render_result( rb_request( $run, array( 'status' => 'complete' ) ) ), 'A different owner cannot submit or observe the job.' );
$GLOBALS['ac']['user'] = 7;
$GLOBALS['ac']['options']['bhranti_lock_7'] = time(); $prior_calls = Bhranti_Render::$results;
rb_error( Bhranti_Agent::render_result( rb_request( $run, array( 'status' => 'complete' ) ) ), 'Existing workspace lock serializes browser result settlement.' );
ac_check( $prior_calls === Bhranti_Render::$results, 'A contended owner lock cannot call the report helper.' );
unset( $GLOBALS['ac']['options']['bhranti_lock_7'] );
rb_error( Bhranti_Agent::render_result( rb_request( $run, array( 'status' => 'invalid' ) ) ), 'Helper validation errors do not consume the job or become proof.' );
ac_check( $run === get_post_meta( 1, 'bhranti_run', true ), 'Rejected report leaves the same durable browser job intact.' );
$report = array( 'status' => 'complete', 'details' => str_repeat( 'private-full-report-marker ', 2600 ) );
$request = rb_request( $run, $report );
ac_check( ! is_wp_error( Bhranti_Agent::render_result( $request ) ), 'A validated normal report settles once.' );
$settled = get_post_meta( 1, 'bhranti_run', true );
$receipt = end( $settled['receipts'] );
ac_check( '' === $settled['phase'] && ! isset( $settled['render_job'], $settled['pending_plan'] ) && true === $receipt['render_check']['passed'] && 'read' === $receipt['operation'] && false === $receipt['coverage'], 'Settlement clears job, stores narrow browser evidence and never invents raw content coverage.' );
ac_check( wp_next_scheduled( 'bhranti_run_tick', array( 1, $run['id'], 7 ) ), 'Accepted report resumes ordinary background planning.' );
$events = get_post_meta( 1, 'bhranti_events', true );
ac_check( $report === end( $events )['render_report'] && $receipt['render_check'] === end( $events )['render_check'] && false === strpos( wp_json_encode( $settled['history'] ), 'private-full-report-marker' ), 'Full bounded report stays in private event/export data while compact actionable result feeds the model.' );
$results = Bhranti_Render::$results;
Bhranti_Agent::render_result( $request );
ac_check( $results === Bhranti_Render::$results && $settled === get_post_meta( 1, 'bhranti_run', true ) && $events === get_post_meta( 1, 'bhranti_events', true ), 'Lost-response duplicate is idempotent: no new helper call, receipt, event or model step.' );
ac_check( ! is_wp_error( rb_notes( 'rendered_check', array( $receipt['id'] ) ) ) && array() === Bhranti_Task::remaining( 1 ), 'One passing current profile receipt closes only its scoped automated task check.' );
rb_error( rb_notes( 'read_back', array( $receipt['id'], $receipt['id'] ) ), 'A browser receipt cannot masquerade as complete saved-field readback evidence.' );
Bhranti_Render::$environment = hash( 'sha256', 'Changed again.' );
ac_check( 'pending' === Bhranti_Task::state( 1 )['checks'][0]['status'] && Bhranti_Task::remaining( 1 ), 'Changed environment invalidates displayed evidence and restores required unfinished work.' );
ac_check( true === Bhranti_Task::archive( 1, $settled ) && isset( Bhranti_Task::export_data( 1 )['runs'][ $run['id'] ][0]['render_check'] ), 'Stale observations still archive/export honestly as historical syntax-valid evidence.' );
Bhranti_Render::$environment = $receipt['render_check']['environment_revision'];
ac_check( ! is_wp_error( rb_notes( 'manual', array() ) ) && Bhranti_Task::remaining( 1 ), 'Existing required manual checks remain blocking.' );
$later = ac_private( 'action_receipt', array_merge( $settled, array( 'step' => $receipt['step'] + 1 ) ), array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => '/wp/v2/pages/20', 'params' => array( 'content' => 'Changed.' ) ) ), array( 'summary' => 'Saved.', 'target' => $receipt['target'], 'data' => array( 'id' => 20 ) ) );
$updated = $settled; $updated['receipts'][] = $later; update_post_meta( 1, 'bhranti_run', $updated );
rb_error( rb_notes( 'rendered_check', array( $receipt['id'] ) ), 'A later same-target write invalidates even otherwise-current browser metadata.' );

foreach ( array( array( 'status' => 'complete', 'needs_review' => 1 ), array( 'status' => 'failed' ) ) as $failure ) {
	$run = rb_start();
	Bhranti_Agent::render_result( rb_request( $run, $failure ) ); $saved = get_post_meta( 1, 'bhranti_run', true );
	$failed_receipt = end( $saved['receipts'] );
	ac_check( false === $failed_receipt['render_check']['passed'] && '' === $saved['phase'], 'Genuine failed/review measurement settles as completed measurement, never passing evidence.' );
	rb_error( rb_notes( 'rendered_check', array( $failed_receipt['id'] ) ), 'Needs-review and failed browser results cannot complete the automated acceptance check.' );
}
$run = rb_start(); $late = rb_request( $run, array( 'status' => 'complete' ) );
Bhranti_Agent::cancel( array( 'id' => 1, 'run_id' => $run['id'] ) );
$cancelled = get_post_meta( 1, 'bhranti_run', true );
ac_check( 'cancelled' === $cancelled['status'] && ! isset( $cancelled['render_job'], $cancelled['pending_plan'] ) && ! wp_next_scheduled( 'bhranti_run_tick', array( 1, $run['id'], 7 ) ), 'Stop clears pending browser capability and native scheduling.' );
rb_error( Bhranti_Agent::render_result( $late ), 'A stopped worker cannot submit a late result.' );
ac_check( false === Bhranti_Agent::state( 1 )['run']['can_resume'], 'A browser wait/cancel is not mistaken for a failed model request.' );
echo 'PASS ' . ( $GLOBALS['ac']['checks'] - $rb_initial ) . " rendered lifecycle/evidence checks with actual Agent/Task/Runner and native DTOs; no DB, provider, browser or content actions.\n";
