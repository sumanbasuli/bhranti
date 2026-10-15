<?php
/**
 * Offline recovery receipt and canonical-command regressions.
 *
 * Run: php tests/recovery-commands.php
 * Uses only the in-memory product fixture; no WordPress bootstrap, AI or network.
 *
 * @package Bhranti
 */

require __DIR__ . '/product-ux.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-task.php';
$rc_initial = $GLOBALS['ux_checks'];
$rc_run = ux_run( 240 );
$rc_run['recovery_stage'] = 1;
$rc_command = array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => '/wp/v2/pages', 'params' => array( 'title' => 'Saved draft', 'status' => 'draft' ) ) );
$rc_result = array( 'target' => array( 'id' => 42, 'type' => 'page', 'title' => 'Saved draft' ), 'data' => array( 'id' => 42 ) );
$rc_receipt = ux_private( 'action_receipt', $rc_run, $rc_command, $rc_result );
$rc_run['receipts'] = array( $rc_receipt );
ux_check( $rc_receipt === ux_private( 'completed_command', $rc_run, $rc_command ), 'An exact successful creation is reused during recovery.' );
foreach ( array( 'POST', 'PUT', 'PATCH' ) as $rc_method ) {
    $rc_alias = $rc_command;
    $rc_alias['intent'] = 'A new public description is not a different action.';
    $rc_alias['args']['method'] = $rc_method;
    $rc_alias['args']['params'] = array_reverse( $rc_alias['args']['params'], true );
    $rc_alias['args']['params']['context'] = 'edit';
    ux_check( $rc_receipt === ux_private( 'completed_command', $rc_run, $rc_alias ), 'Equivalent native method, forced context, key order and display intent cannot bypass creation deduplication.' );
}
$rc_bad = $rc_command;
$rc_bad['args']['params'] = 'invalid model params';
ux_check( is_string( ux_private( 'command_hash', $rc_bad ) ), 'Pre-schema fingerprinting handles malformed params without throwing or dispatching.' );
ux_check( null === ux_private( 'completed_command', $rc_run, $rc_bad ), 'Malformed params cannot match a valid successful write.' );
$rc_update_a = $rc_command;
$rc_update_a['args']['path'] = '/wp/v2/pages/42';
$rc_update_b = $rc_update_a;
$rc_update_b['args']['params']['title'] = 'Later revision';
$rc_run['step']++;
$rc_receipt_a = ux_private( 'action_receipt', $rc_run, $rc_update_a, $rc_result );
$rc_run['step']++;
$rc_receipt_b = ux_private( 'action_receipt', $rc_run, $rc_update_b, $rc_result );
$rc_run['receipts'][] = $rc_receipt_a;
$rc_run['receipts'][] = $rc_receipt_b;
ux_check( null === ux_private( 'completed_command', $rc_run, $rc_update_a ), 'An older superseded update does not prevent an intentional later restoration.' );
ux_check( $rc_receipt_b === ux_private( 'completed_command', $rc_run, $rc_update_b ), 'The latest settled update is not replayed.' );
ux_check( $rc_receipt === ux_private( 'completed_command', $rc_run, $rc_command ), 'Creation stays protected after later edits of the created item.' );
$rc_nested_create = $rc_command;
$rc_nested_create['args']['path'] = '/wp/v2/library/books';
$rc_run['step']++;
$rc_nested_receipt = ux_private( 'action_receipt', $rc_run, $rc_nested_create, $rc_result + array( 'created' => true ) );
$rc_run['receipts'][] = $rc_nested_receipt;
$rc_run['step']++;
$rc_nested_update = $rc_nested_create;
$rc_nested_update['args']['path'] .= '/42';
$rc_nested_update['args']['params']['title'] = 'Changed later';
$rc_run['receipts'][] = ux_private( 'action_receipt', $rc_run, $rc_nested_update, $rc_result + array( 'created' => false ) );
ux_check( $rc_nested_receipt === ux_private( 'completed_command', $rc_run, $rc_nested_create ), 'Native creation metadata protects nested registered collection bases after later edits.' );
$rc_image = array( 'tool' => 'generate_image', 'args' => array( 'prompt' => 'An original illustration', 'title' => 'Orbit relay hero' ) );
$rc_run['step']++;
$rc_image_receipt = ux_private( 'action_receipt', $rc_run, $rc_image, array( 'target' => array( 'id' => 88, 'type' => 'attachment', 'title' => 'Orbit relay hero' ), 'data' => array( 'id' => 88, 'source_url' => 'https://example.test/hero.png' ) ) );
$rc_run['receipts'][] = $rc_image_receipt;
$rc_image['args']['prompt'] = 'A rephrased prompt must not regenerate the saved image';
$rc_image['args']['title'] = ' ORBIT  RELAY HERO ';
ux_check( $rc_image_receipt === ux_private( 'completed_command', $rc_run, $rc_image ), 'Normalized image title reuses the actual saved asset after prompt rephrasing.' );
$rc_image['args']['title'] = 'Orbit relay second illustration';
ux_check( null === ux_private( 'completed_command', $rc_run, $rc_image ), 'A distinct unfinished image is not suppressed.' );
$rc_next_run = $rc_run;
$rc_next_run['id'] = wp_generate_uuid4();
ux_check( null === ux_private( 'completed_command', $rc_next_run, $rc_command ), 'Old-run receipts never suppress a new human task.' );
$rc_fresh = $rc_run;
unset( $rc_fresh['recovery_stage'] );
ux_check( null === ux_private( 'completed_command', $rc_fresh, $rc_command ), 'Normal focused actions retain their existing behavior before recovery.' );
$rc_patch = array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => '/wp/v2/pages/42', 'content_patch' => array( 'revision' => str_repeat( 'a', 64 ), 'operation' => 'append', 'content' => '<!-- wp:paragraph --><p>New section.</p><!-- /wp:paragraph -->' ) ) );
$rc_run['step']++;
$rc_patch_receipt = ux_private( 'action_receipt', $rc_run, $rc_patch, $rc_result + array( 'content_revision' => str_repeat( 'b', 64 ) ) );
ux_check( true === $rc_patch_receipt['coverage'] && array( 'content' ) === $rc_patch_receipt['fields'], 'Patch receipt covers the actual content field, never a fake native patch field.' );
ux_check( str_repeat( 'b', 64 ) === $rc_patch_receipt['content_revision'], 'The saved full content revision survives in the immutable receipt.' );
$rc_validator = new ReflectionMethod( 'Bhranti_Task', 'valid_receipts' );
ux_check( $rc_validator->invoke( null, array( $rc_patch_receipt ), $rc_run['id'] ), 'Native receipt validation accepts bounded command and revision hashes.' );
ux_check( $rc_validator->invoke( null, array( $rc_nested_receipt ), $rc_run['id'] ), 'Native receipt validation accepts a verified creation boolean.' );
$rc_patch_receipt['command_hash'] = 'not-a-valid-hash';
ux_check( ! $rc_validator->invoke( null, array( $rc_patch_receipt ), $rc_run['id'] ), 'Receipt validation rejects malformed canonical fingerprints.' );
printf( "PASS %d recovery command/receipt checks; no DB, HTTP or AI.\n", $GLOBALS['ux_checks'] - $rc_initial );
