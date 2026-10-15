<?php
/**
 * Production Render/Task syntax validation using actual native DTO fixtures.
 * No wp-load.php, database, browser, HTTP or provider calls.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/rendered-receipts.php
 *
 * @package Bhranti
 */

require __DIR__ . '/agent-clarification.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-render.php';
$rr_initial = $GLOBALS['ac']['checks'];
$run = get_post_meta( 1, 'bhranti_run', true );
$check = array( 'version' => 1, 'job_id' => wp_generate_uuid4(), 'source_revision' => hash( 'sha256', 'Exact saved raw content.' ), 'environment_revision' => hash( 'sha256', 'Exact native rendering state.' ), 'observed_at' => time() - 3600, 'passed' => true, 'needs_review' => 0, 'viewports' => array( 1280, 390, 320 ), 'engine' => 'axe-core', 'engine_version' => '4.14.0' );
ac_check( Bhranti_Render::valid_receipt( $check ), 'Actual Render syntax validation accepts a complete fixed-profile observation.' );
foreach ( array(
	array( 'version' => 2 ), array( 'job_id' => '../invalid' ), array( 'source_revision' => str_repeat( 'f', 63 ) ), array( 'environment_revision' => null ), array( 'observed_at' => '1' ), array( 'observed_at' => 0 ), array( 'passed' => 'true' ), array( 'needs_review' => -1 ), array( 'needs_review' => 241 ), array( 'needs_review' => 1 ), array( 'viewports' => array( 1280, 320, 390 ) ), array( 'viewports' => array( 1280, 390 ) ), array( 'engine' => 'custom-engine' ), array( 'engine_version' => 'unknown' ), array( 'raw_html' => '<p>Unrequested payload.</p>' ),
) as $change ) {
	ac_check( false === Bhranti_Render::valid_receipt( array_merge( $check, $change ) ), 'Malformed, extended or mismatched profile metadata fails the actual syntax validator.' );
}
foreach ( array_keys( $check ) as $key ) {
	$missing = $check; unset( $missing[ $key ] );
	ac_check( false === Bhranti_Render::valid_receipt( $missing ), 'Every required receipt field must be present.' );
}
$review = $check; $review['passed'] = false; $review['needs_review'] = 1;
ac_check( Bhranti_Render::valid_receipt( $review ), 'A genuine needs-review observation is valid historical data rather than passing evidence.' );
$receipt = array( 'id' => $run['id'] . ':1', 'run_id' => $run['id'], 'step' => 1, 'tool' => 'bhranti_inspect', 'method' => 'GET', 'path' => '/wp/v2/pages/20', 'operation' => 'read', 'success' => true, 'code' => '', 'entity' => 'page:20', 'references' => array(), 'target' => array( 'id' => 20, 'type' => 'page' ), 'fields' => array(), 'coverage' => false, 'content_revision' => $check['source_revision'], 'render_check' => $check );
$validator = new ReflectionMethod( Bhranti_Task::class, 'valid_receipts' );
ac_check( true === $validator->invoke( null, array( $receipt ), $run['id'] ), 'Actual Task syntax validation accepts production Render metadata without imposing an arbitrary observation age.' );
foreach ( array( array( 'content_revision' => hash( 'sha256', 'Different source.' ) ), array( 'tool' => 'wp_request' ), array( 'method' => 'POST' ), array( 'operation' => 'write' ), array( 'success' => false ), array( 'render_check' => array_merge( $check, array( 'unknown' => true ) ) ) ) as $change ) {
	ac_check( false === $validator->invoke( null, array( array_merge( $receipt, $change ) ), $run['id'] ), 'Render observations cannot masquerade as another source, tool, method or operation.' );
}
$failed_receipt = $receipt; $failed_receipt['render_check'] = $review;
ac_check( false === Bhranti_Render::valid_evidence( $failed_receipt, 7, 1 ), 'Actual evidence validator rejects needs-review before any native target read.' );
$future = $receipt; $future['render_check']['observed_at'] = time() + 3600;
ac_check( false === Bhranti_Render::valid_evidence( $future, 7, 1 ), 'Future-dated observations cannot become evidence or trigger a native target read.' );
$run['receipts'] = array( $receipt ); update_post_meta( 1, 'bhranti_run', wp_slash( $run ) );
ac_check( true === Bhranti_Task::archive( 1, $run ), 'Historical production browser metadata archives without rescanning a page.' );
ac_check( $receipt === Bhranti_Task::export_data( 1 )['runs'][ $run['id'] ][0], 'Native privacy export retains the exact original structured observation.' );
$historical = $receipt;
$historical['render_check']['engine_version'] = '4.13.0';
ac_check( Bhranti_Render::valid_receipt( $historical['render_check'] ), 'Historical bounded engine versions remain valid observation syntax.' );
ac_check( false === Bhranti_Render::valid_evidence( $historical, 7, 1 ), 'An older engine cannot satisfy current evidence or trigger a target read.' );
ac_check( true === $validator->invoke( null, array( $historical ), $run['id'] ), 'Task receipt storage accepts historical engine provenance.' );
$run['receipts'] = array( $historical );
update_post_meta( 1, 'bhranti_run', wp_slash( $run ) );
ac_check( true === Bhranti_Task::archive( 1, $run ), 'Historical engine observations can still be retained through native task archives.' );
ac_check( $historical === Bhranti_Task::export_data( 1 )['runs'][ $run['id'] ][0], 'Privacy export preserves the older engine version without rewriting its provenance.' );
echo 'PASS ' . ( $GLOBALS['ac']['checks'] - $rr_initial ) . " production rendered-receipt syntax/archive checks with native DTOs; no site bootstrap, browser, HTTP or provider.\n";
