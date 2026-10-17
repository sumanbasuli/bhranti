<?php
/**
 * Offline audit-capture regression for real Agent/Actions code.
 *
 * Storage, identities and scheduling are in-memory seams. No wp-load.php,
 * database, HTTP, AI, provider settings or real site content is touched.
 * Run: php tests/audit-capture.php
 *
 * @package Bhranti
 */

$core_root = getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Documents/WordPress/Plugins';
define( 'ABSPATH', rtrim( $core_root, '/' ) . '/' );
require ABSPATH . 'wp-includes/class-wp-error.php';

$GLOBALS['ac_checks'] = 0;
$GLOBALS['ac_meta'] = array();
$GLOBALS['ac_messages'] = array();
$GLOBALS['ac_audits'] = array();
$GLOBALS['ac_cleared'] = array();
$GLOBALS['ac_post'] = (object) array( 'ID' => 17, 'post_title' => 'Private conversation title', 'post_author' => 7 );
function __( $text, $domain = 'default' ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_slash( $value ) { return $value; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['ac_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['ac_meta'][ $id ][ $key ] = $value; return true; }
function get_post( $id ) { return 17 === $id ? $GLOBALS['ac_post'] : null; }
function get_current_user_id() { return 7; }
function ac_check( $condition, $label ) {
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    ++$GLOBALS['ac_checks'];
}
final class Bhranti_Workspace {
    public static function session_data( $post ) { return array( 'messages' => $GLOBALS['ac_messages'], 'run' => get_post_meta( $post->ID, 'bhranti_run', true ) ); }
    public static function message( $role, $content ) { return array( 'role' => $role, 'content' => $content ); }
    public static function save_messages( $id, $messages, $title ) { $GLOBALS['ac_messages'] = $messages; return true; }
    public static function mutate( $callback ) { return $callback(); }
    public static function owned_session( $id ) { return get_post( $id ); }
}
final class Bhranti_Audit {
    public static function record( ...$args ) { $GLOBALS['ac_audits'][] = $args; return count( $GLOBALS['ac_audits'] ); }
}
final class Bhranti_Runner {
    public static function clear( ...$args ) { $GLOBALS['ac_cleared'][] = $args; return true; }
}
require dirname( __DIR__ ) . '/includes/class-bhranti-credentials.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-agent.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-actions.php';

$ac_finish = new ReflectionMethod( Bhranti_Agent::class, 'finish' );
$ac_context = new ReflectionMethod( Bhranti_Actions::class, 'audit_command' );
$ac_run = array( 'id' => '00000000-0000-4000-8000-000000000001', 'requester_id' => 7, 'actor_id' => 11, 'step' => 9, 'status' => 'running', 'phase' => 'action', 'confirmation' => array( 'command' => 'private action' ), 'pending_plan' => array( 'secret' => 'not for audit' ) );
$ac_private = 'A private answer, page content, prompt and provider error must never enter an activity log.';
$ac_summaries = array();
foreach ( array( 'complete', 'awaiting_input', 'incomplete', 'cancelled', 'failed' ) as $ac_status ) {
    $GLOBALS['ac_audits'] = $GLOBALS['ac_messages'] = array();
    $ac_result = $ac_finish->invoke( null, $GLOBALS['ac_post'], $ac_run, $ac_private, $ac_status );
    $ac_event = $GLOBALS['ac_audits'][0];
    ac_check( $ac_result['run']['status'] === $ac_status && 9 === $ac_result['run']['step'] && '' === $ac_result['run']['phase'], 'Finishing preserves the real status and step.' );
    ac_check( ! isset( $ac_result['run']['confirmation'], $ac_result['run']['pending_plan'] ) && array( 17, $ac_run['id'], 7 ) === end( $GLOBALS['ac_cleared'] ), 'Existing handover and scheduling cleanup still run.' );
    ac_check( 'run_' . $ac_status === $ac_event[0] && $ac_status === $ac_event[3]['status'] && 9 === $ac_event[3]['step'] && $ac_run['id'] === $ac_event[3]['run_id'], 'Terminal audit identifies actual run, status and step.' );
    ac_check( $ac_private === $ac_result['messages'][0]['content'] && ! str_contains( json_encode( $ac_event ), $ac_private ) && ! str_contains( json_encode( $ac_event ), 'private action' ), 'Final text stays in the private chat, never the activity record.' );
    ac_check( 7 === $ac_event[4] && 11 === $ac_event[5], 'Both human and executing identities are preserved.' );
    $ac_summaries[] = $ac_event[1];
}
ac_check( count( $ac_summaries ) === count( array_unique( $ac_summaries ) ), 'Complete, failed, incomplete, stopped and question outcomes have distinct explanations.' );

foreach ( array( 'interrupted_step', 'step_limit', 'ai_request_failed', 'invalid_plan', 'audit_failed', 'unsaved_editor', 'prepared_missing', 'ai_retries_exhausted', 'uncertain_action', 'retained_action_limit', 'repeated_rejection', 'duplicate_prevented', 'approval_expired', 'approval_denied', 'approval_conflict', 'user_stopped' ) as $ac_reason ) {
    $GLOBALS['ac_audits'] = $GLOBALS['ac_messages'] = array();
    $ac_finish->invoke( null, $GLOBALS['ac_post'], $ac_run, $ac_private, 'failed', $ac_reason );
    $ac_event = $GLOBALS['ac_audits'][0];
    ac_check( $ac_reason === $ac_event[3]['reason'] && strlen( $ac_event[1] ) > 30 && ! str_contains( json_encode( $ac_event ), $ac_private ), 'Known stop reasons retain a fixed explanation without private text.' );
}
$GLOBALS['ac_audits'] = array();
$ac_finish->invoke( null, $GLOBALS['ac_post'], $ac_run, $ac_private, 'failed', $ac_private );
ac_check( 'failed' === $GLOBALS['ac_audits'][0][3]['reason'] && ! str_contains( json_encode( $GLOBALS['ac_audits'] ), $ac_private ), 'An arbitrary diagnostic string cannot become audit text or metadata.' );

$ac_safe = $ac_context->invoke( null, array( 'tool' => 'wp_request', 'args' => array( 'method' => 'post', 'path' => '/wp/v2/pages/123', 'params' => array( 'password' => 'secret', 'content' => $ac_private ) ) ) );
ac_check( array( 'tool' => 'wp_request', 'method' => 'POST', 'path' => '/wp/v2/pages/123' ) === $ac_safe, 'A refused action keeps useful tool/method/path identifiers but never parameter values.' );
foreach ( array( 'https://private.example/path?token=secret', '/wp/v2/pages?password=secret', '/wp/v2/pages#secret', '/wp/v2/../../wp-config.php', '/wp/v2/pages%3Fsecret', "/wp/v2/pages\nsecret", '/wp/v2/' . str_repeat( 'x', 281 ) ) as $ac_path ) {
    $ac_context_data = $ac_context->invoke( null, array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => $ac_path ) ) );
    ac_check( ! isset( $ac_context_data['path'] ) && 'POST' === $ac_context_data['method'], 'Unreviewed URLs, secrets, control characters and oversized paths are excluded.' );
}
foreach ( array( null, 'private text', array( 'tool' => 'private-secret', 'args' => array() ), array( 'tool' => array(), 'args' => array() ) ) as $ac_bad ) {
    ac_check( array() === $ac_context->invoke( null, $ac_bad ), 'Malformed or arbitrary tool identity is not copied to audit metadata.' );
}
$ac_search = $ac_context->invoke( null, array( 'tool' => 'site_search', 'args' => array( 'search' => $ac_private ) ) );
ac_check( array( 'tool' => 'site_search', 'method' => 'GET', 'path' => '/wp/v2/search' ) === $ac_search, 'Site-search diagnostics identify the endpoint without search text.' );
$ac_bad_method = $ac_context->invoke( null, array( 'tool' => 'wp_request', 'args' => array( 'method' => $ac_private, 'path' => '/wp/v2/pages/123' ) ) );
ac_check( ! isset( $ac_bad_method['method'] ) && '/wp/v2/pages/123' === $ac_bad_method['path'], 'Malformed method cannot leak raw proposed values.' );
fwrite( STDOUT, 'Audit capture: ' . $GLOBALS['ac_checks'] . " checks passed; no database, HTTP, AI or live site used.\n" );
