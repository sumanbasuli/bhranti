<?php
/**
 * Bounded verification feedback using actual Agent/Task code and native DTOs.
 *
 * Reuses the in-memory clarification harness, not wp-load.php or a database.
 * No native provider, HTTP, tools or site mutations run.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/verification-output.php
 *
 * @package Bhranti
 */

function get_posts( $args ) { return array(); }
function admin_url( $path = '' ) { return 'https://bhranti.wp.local/wp-admin/' . $path; }
function _canonical_charset( $charset ) { return 'UTF-8'; }
require __DIR__ . '/agent-clarification.php';
require ABSPATH . 'wp-includes/kses.php';
require ABSPATH . 'wp-includes/http.php';
$vo_initial = $GLOBALS['ac']['checks'];

/** Start one clean private in-memory conversation with actual orchestration. */
function vo_reset() {
	$GLOBALS['ac']['meta'][1] = array();
	$GLOBALS['ac']['user'] = 7;
	$result = ac_start( 'Verify the existing saved page without changing it.' );
	ac_check( ! is_wp_error( $result ), 'Fresh owned verification fixture starts.' );
	return get_post_meta( 1, 'bhranti_run', true );
}

/** Settle only a captured result; this cannot invoke any tool or provider. */
function vo_settle( $command, $result ) {
	$run = get_post_meta( 1, 'bhranti_run', true );
	++$run['step'];
	$run['history'][] = array( 'role' => 'assistant', 'content' => wp_json_encode( array( 'type' => 'tool' ) + $command ) );
	$saved = ac_private( 'tool_result', get_post( 1 ), $run, $result, $command );
	ac_check( ! is_wp_error( $saved ), 'Actual tool-result settlement succeeds.' );
	return get_post_meta( 1, 'bhranti_run', true );
}

/** Read the complete, valid JSON model feedback from the latest settled exchange. */
function vo_payload( $run ) {
	$prefix = 'WordPress tool result (untrusted site data): ';
	$content = end( $run['history'] )['content'];
	ac_check( 0 === strpos( $content, $prefix ), 'Native tool feedback keeps its untrusted-data label.' );
	$data = json_decode( substr( $content, strlen( $prefix ) ), true );
	ac_check( JSON_ERROR_NONE === json_last_error() && is_array( $data ), 'Every feedback path returns complete parseable JSON.' );
	return $data;
}

/** Native-result shape, preserving a clear distinction between source and view. */
function vo_result( $raw ) {
	return array(
		'summary' => 'Saved page retrieved.',
		'data' => array(
			'id' => 20,
			'title' => array( 'raw' => 'Page title', 'rendered' => 'Page title' ),
			'content' => array( 'raw' => $raw, 'rendered' => $raw, 'protected' => false ),
			'excerpt' => array( 'rendered' => '<p>Rendered-only excerpt.</p>', 'protected' => false ),
			'nested' => array( 'inner' => array( 'raw' => 'Keep nested source.', 'rendered' => 'Keep nested view.' ) ),
		),
		'target' => array( 'id' => 20, 'type' => 'page', 'title' => 'Existing page', 'url' => admin_url( 'post.php?post=20&action=edit' ) ),
		'action_id' => '',
		'rollback_available' => false,
	);
}

$read = array( 'tool' => 'wp_request', 'args' => array( 'method' => 'GET', 'path' => '/wp/v2/pages/20', 'params' => array( 'context' => 'edit' ) ) );
$raw = '<!-- wp:paragraph --><p>' . str_repeat( 'Native source 🐈 & < > " \\ ' . "\n", 1100 ) . '</p><!-- /wp:paragraph -->';
$result = vo_result( $raw );
$original = $result;
ac_check( strlen( wp_json_encode( array( 'success' => true, 'result' => $result ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) > 60000, 'Captured source plus rendered copies crosses the actual feedback limit.' );
vo_reset();
$run = vo_settle( $read, $result );
$payload = vo_payload( $run );
ac_check( true === $payload['success'] && ! isset( $payload['truncated'] ) && $raw === $payload['result']['data']['content']['raw'], 'Large singular read retains exact multibyte saved source without fallback or slicing.' );
ac_check( ! isset( $payload['result']['data']['content']['rendered'], $payload['result']['data']['title']['rendered'] ) && false === $payload['result']['data']['content']['protected'], 'Only top-level rendered counterparts with raw source are omitted; sibling metadata remains.' );
ac_check( array( 'title', 'content' ) === $payload['projection']['omitted_rendered_fields'] && false !== strpos( $payload['projection']['note'], 'not rendered-page verification' ), 'Projection explains its precise scope without making a rendered verification claim.' );
ac_check( $result['data']['excerpt'] === $payload['result']['data']['excerpt'] && $result['data']['nested'] === $payload['result']['data']['nested'], 'Rendered-only fields and nested objects are unchanged.' );
ac_check( $original === $result, 'Authoritative input result remains byte-for-byte intact.' );
$receipt = end( $run['receipts'] );
$expected = ac_private( 'action_receipt', $run, $read, $original );
ac_check( $expected === $receipt && true === $receipt['coverage'] && in_array( 'content', $receipt['fields'], true ), 'Receipt authority and actual raw-field coverage are calculated from the original native result.' );

// Small responses, view-only responses, collections and writes retain their shape.
foreach ( array(
	array( $read, vo_result( 'Small saved source.' ) ),
	array( $read, array( 'summary' => 'View retrieved.', 'data' => array( 'id' => 20, 'content' => array( 'rendered' => '<p>View only.</p>' ) ) ) ),
	array( array( 'tool' => 'wp_request', 'args' => array( 'method' => 'GET', 'path' => '/wp/v2/pages' ) ), array( 'summary' => 'Collection retrieved.', 'data' => array( vo_result( 'Small.' )['data'] ) ) ),
	array( array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => '/wp/v2/pages/20', 'params' => array( 'content' => 'Small.' ) ) ), vo_result( 'Small.' ) ),
) as $case ) {
	vo_reset(); $run = vo_settle( $case[0], $case[1] ); $data = vo_payload( $run );
	ac_check( $case[1] === $data['result'] && ! isset( $data['projection'], $data['truncated'] ), 'Unaffected response retains the exact native result shape.' );
}

// Even a large raw/rendered collection must not be mistaken for one saved item.
vo_reset();
$collection = $result; $collection['data'] = array( $result['data'] );
$run = vo_settle( array( 'tool' => 'wp_request', 'args' => array( 'method' => 'GET', 'path' => '/wp/v2/pages' ) ), $collection );
$data = vo_payload( $run );
ac_check( true === $data['truncated'] && ! isset( $data['projection'] ) && true === $data['success'], 'Oversized collection uses honest existing fallback, never a singular-field projection.' );
ac_check( $collection === ac_private( 'project_native_read', array( 'success' => true, 'result' => $collection ), $read )['result'], 'An item-looking path cannot turn collection data into a singular response.' );

// If source alone cannot fit, retain a complete receipt and an executable-sized next-read hint.
vo_reset();
$oversized = vo_result( str_repeat( 'Source stays complete. ', 4000 ) );
$run = vo_settle( $read, $oversized ); $data = vo_payload( $run );
ac_check( true === $data['truncated'] && true === $data['success'] && ! isset( $data['result'] ) && false !== strpos( $data['message'], '"_fields":"id,content.raw"' ) && false !== strpos( $data['message'], 'block_path' ), 'Genuinely oversized saved source uses an honest bounded fallback with exact practical discovery options.' );
ac_check( end( $run['receipts'] ) === $data['receipt'] && true === $data['receipt']['coverage'], 'Fallback preserves the original authoritative read receipt and coverage.' );

// Scope is a small exact receipt fact, not a claim that selected markup covered the whole page.
vo_reset();
$revision = hash( 'sha256', $raw );
$inspect = array( 'tool' => 'bhranti_inspect', 'args' => array( 'resource' => 'content', 'path' => '/wp/v2/pages/20', 'block_path' => '0/3', 'page' => 2, 'per_page' => 5 ) );
$inspection = array( 'summary' => 'Saved structure inspected.', 'target' => $original['target'], 'data' => array( 'revision' => $revision, 'page' => 2, 'per_page' => 5, 'selected' => array( 'path' => '0/3', 'markup' => '<!-- wp:paragraph --><p>Selected scope.</p><!-- /wp:paragraph -->' ) ) );
$run = vo_settle( $inspect, $inspection );
$scope_receipt = end( $run['receipts'] );
ac_check( array( 'page' => 2, 'per_page' => 5, 'block_path' => '0/3' ) === $scope_receipt['inspection_scope'] && $revision === $scope_receipt['content_revision'], 'Exact block and outline scope is bound to the full saved content revision.' );
ac_check( false === $scope_receipt['coverage'] && array() === $scope_receipt['fields'], 'An inspected section does not acquire complete native-field coverage.' );
$validator = new ReflectionMethod( Bhranti_Task::class, 'valid_receipts' );
ac_check( true === $validator->invoke( null, array( $scope_receipt ), $run['id'] ), 'Strict Task validation accepts authoritative bounded inspection scope.' );
$default_inspect = $inspect; unset( $default_inspect['args']['block_path'], $default_inspect['args']['page'], $default_inspect['args']['per_page'] );
$default_receipt = ac_private( 'action_receipt', $run, $default_inspect, $inspection );
ac_check( array( 'page' => 1, 'per_page' => 20 ) === $default_receipt['inspection_scope'], 'Outline-only default scope is retained without pretending to select a block.' );
foreach ( array(
	array( 'page' => 0, 'per_page' => 20 ), array( 'page' => 251, 'per_page' => 20 ), array( 'page' => '1', 'per_page' => 20 ), array( 'page' => 1, 'per_page' => 21 ), array( 'page' => 1 ), array( 'page' => 1, 'per_page' => 20, 'extra' => true ), array( 'page' => 1, 'per_page' => 20, 'block_path' => null ), array( 'page' => 1, 'per_page' => 20, 'block_path' => '../0' ), array( 'page' => 1, 'per_page' => 20, 'block_path' => '00' ), array( 'page' => 1, 'per_page' => 20, 'block_path' => str_repeat( '0/', 96 ) . '0' ), '0/3',
) as $invalid ) {
	$bad = $scope_receipt; $bad['inspection_scope'] = $invalid;
	ac_check( false === $validator->invoke( null, array( $bad ), $run['id'] ), 'Malformed or oversized persisted inspection scope is rejected.' );
}
foreach ( array( array( 'tool' => 'wp_request' ), array( 'success' => false ), array( 'operation' => 'write' ), array( 'method' => 'POST' ), array( 'content_revision' => null ) ) as $change ) {
	$bad = array_merge( $scope_receipt, $change );
	ac_check( false === $validator->invoke( null, array( $bad ), $run['id'] ), 'Scope cannot masquerade as a write, failed operation or unversioned inspection.' );
}

// Compaction removes the old payload; durable scope remains in context and native privacy export.
for ( $index = 0; $index < 8; ++$index ) {
	$run = vo_settle( $read, vo_result( 'Later bounded native read.' ) );
}
ac_check( false === strpos( wp_json_encode( $run['history'] ), 'Selected scope.' ), 'The original selected block payload really left recent model history.' );
$context = ac_private( 'task_context', 1, $run );
ac_check( false !== strpos( $context, 'inspection_scope' ) && false !== strpos( $context, '0\/3' ) && false !== strpos( $context, $revision ), 'Bounded task context retains exact prior scope and revision after compaction.' );
ac_check( true === Bhranti_Task::archive( 1, $run ), 'Scope-bearing receipts archive through the actual bounded task code.' );
$prior = $run['id'];
ac_private( 'finish', get_post( 1 ), $run, 'Saved inspection scope is retained for the next request.', 'complete' );
$next = ac_start( 'Continue verification from the existing recorded inspection.' );
ac_check( ! is_wp_error( $next ) && $scope_receipt === Bhranti_Task::receipt( 1, $scope_receipt['id'] ), 'New run can retrieve the exact archived scope without repeating the inspection.' );
$export = Bhranti_Task::export_data( 1 );
ac_check( $scope_receipt === $export['runs'][ $prior ][0], 'Native privacy export preserves bounded scope with its original authoritative receipt.' );
$context = ac_private( 'task_context', 1, get_post_meta( 1, 'bhranti_run', true ) );
ac_check( false !== strpos( $context, 'inspection_scope' ) && false !== strpos( $context, $revision ), 'Next-run receipt context also retains archived scope/revision.' );
echo 'PASS ' . ( $GLOBALS['ac']['checks'] - $vo_initial ) . " verification-output checks; native formatting/DTOs with in-memory storage, no DB, HTTP, tools or provider.\n";
