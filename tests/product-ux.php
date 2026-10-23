<?php
/**
 * Isolated product-state regression using the real staged backend classes.
 *
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/product-ux.php
 * Native WP_Error is loaded without wp-load.php. Storage, identity, scheduling
 * and audit seams are in-memory fixtures: no DB, HTTP, AI or live-site writes.
 */

require_once __DIR__ . '/offline-requester-identity.php';
$core_root = getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Documents/WordPress/Plugins';
define( 'ABSPATH', rtrim( $core_root, '/' ) . '/' );
require_once ABSPATH . 'wp-includes/class-wp-error.php';

$GLOBALS['ux_meta']    = array();
$GLOBALS['ux_posts']   = array();
$GLOBALS['ux_options'] = array( 'bhranti_actor_id' => 11 );
$GLOBALS['ux_uuid']    = 0;
$GLOBALS['ux_checks']  = 0;
$GLOBALS['ux_actor']   = (object) array( 'ID' => 11, 'roles' => array( 'bhranti_agent' ), 'caps' => array( 'bhranti_agent' => true ) );

function __( $text, $domain = 'default' ) { return $text; }
function do_action( $hook, ...$args ) {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function get_current_user_id() { return 7; }
function get_current_blog_id() { return 1; }
function get_userdata( $id ) { return 11 === (int) $id ? $GLOBALS['ux_actor'] : null; }
function get_user_meta( $id, $key, $single = false ) { return 11 === (int) $id && 'bhranti_managed_actor' === $key ? 1 : ''; }
function wp_slash( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_ai_client_prompt( $history = array() ) { if ( isset( $GLOBALS['ux_prompt_factory'] ) ) { return ( $GLOBALS['ux_prompt_factory'] )( $history ); } throw new RuntimeException( 'This isolated test must not request AI.' ); }
function wp_get_connectors() { return array( 'fixture' => array( 'type' => 'ai_provider' ) ); }
function wp_generate_uuid4() { return sprintf( '00000000-0000-4000-8000-%012d', ++$GLOBALS['ux_uuid'] ); }
function sanitize_text_field( $value ) { return $value; }
function get_post_datetime( $post, $field = 'date', $source = 'local' ) { return new DateTimeImmutable( '2026-10-09T00:00:00Z' ); }
function get_post( $id ) { return $GLOBALS['ux_posts'][ (int) $id ] ?? null; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['ux_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['ux_meta'][ $id ][ $key ] = $value; return true; }
function get_option( $key, $default = false ) { return $GLOBALS['ux_options'][ $key ] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = true ) {
    if ( array_key_exists( $key, $GLOBALS['ux_options'] ) ) { return false; }
    $GLOBALS['ux_options'][ $key ] = $value;
    return true;
}
function delete_option( $key ) { unset( $GLOBALS['ux_options'][ $key ] ); return true; }
/** In-memory lock boundary for this fixture's real Agent orchestration. */
final class Bhranti_Lock {
    public static function acquire( $name, $ttl ) {
        if ( self::active( $name, $ttl ) ) { return false; }
        delete_option( $name );
        $token = array( 'token' => wp_generate_uuid4(), 'expires' => time() + $ttl );
        return add_option( $name, $token, '', false ) ? $token : false;
    }
    public static function owns( $name, $token ) { return is_array( $token ) && $token === get_option( $name ) && ( $token['expires'] ?? 0 ) > time(); }
    public static function release( $name, $token ) { if ( ! self::owns( $name, $token ) ) { return false; } return delete_option( $name ); }
    public static function active( $name, $ttl ) {
        $value = get_option( $name );
        if ( is_array( $value ) && isset( $value['expires'] ) ) { return (int) $value['expires'] > time(); }
        if ( is_int( $value ) ) { return $value >= time() - $ttl; }
        return false !== $value;
    }
}
function wp_update_post( $data, $wp_error = false ) {
    if ( ! isset( $GLOBALS['ux_posts'][ $data['ID'] ] ) ) { return new WP_Error( 'fixture_missing' ); }
    $GLOBALS['ux_posts'][ $data['ID'] ]->post_title = $data['post_title'];
    return $data['ID'];
}
function get_posts( $args ) {
    $posts = array_filter( $GLOBALS['ux_posts'], static function ( $post ) use ( $args ) {
        return $post->post_type === $args['post_type'] && $post->post_status === $args['post_status'] && $post->post_parent === $args['post_parent'];
    } );
    usort( $posts, static function ( $left, $right ) { return $right->ID <=> $left->ID; } );
    return array_slice( $posts, 0, $args['posts_per_page'] );
}

final class Bhranti_Runner {
    public static function schedule( $session, $run, $requester ) { return true; }
    public static function clear( $session, $run, $requester ) { return true; }
    public static function editor_status( $requester ) { return array( 'dirty' => false, 'active' => false, 'write_blocked' => false, 'stale_dirty' => false ); }
}
final class Bhranti_Chat {
    public static function render( $text ) { return '<p>' . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . '</p>'; }
}
final class Bhranti_Settings {
    public static function profile( $id ) { return array( 'id' => $id, 'max_steps' => 128 ); }
    public static function all() { return array( 'permissions' => array() ); }
}
final class Bhranti_Actions {
    public static function ensure_actor() { return Bhranti_Identity::requester(); }
    public static function catalog() { return array(); }
}
final class BhrantiUXClient {
    public static function defaultRegistry() { return new self(); }
    public function hasProvider( $id ) { return 'fixture' === $id; }
    public function isProviderConfigured( $id ) { return 'fixture' === $id; }
}
class_alias( 'BhrantiUXClient', 'WordPress\\AiClient\\AiClient' );
final class Bhranti_Audit {
    public static $next_id = 1000;
    public static function record( $type, $summary, $session, $details = array(), $requester = 0, $actor = 0 ) {
        $id = ++self::$next_id;
        $requester = $requester ?: get_current_user_id();
        $GLOBALS['ux_posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'bhranti_event', 'post_status' => 'private', 'post_parent' => $session, 'post_author' => $requester );
        update_post_meta( $id, 'bhranti_event_data', array( 'type' => $type, 'summary' => $summary, 'session_id' => $session, 'requester_id' => $requester, 'actor_id' => $actor, 'details' => $details ) );
        return $id;
    }
}

require dirname( __DIR__ ) . '/includes/class-bhranti-credentials.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-workspace.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-agent.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-diagnostics.php';

function ux_check( $condition, $message ) {
    ++$GLOBALS['ux_checks'];
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function ux_private( $method, ...$args ) {
    $reflection = new ReflectionMethod( 'Bhranti_Agent', $method );
    return $reflection->invoke( null, ...$args );
}
function ux_session( $id ) {
    $GLOBALS['ux_posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_parent' => 0, 'post_author' => 7, 'post_title' => 'Isolated fixture' );
    update_post_meta( $id, 'bhranti_messages', array() );
    return get_post( $id );
}
function ux_run( $id ) {
    return array( 'id' => $id, 'status' => 'running', 'phase' => '', 'step' => 4, 'requester_id' => 7, 'actor_id' => 7, 'history' => array( array( 'role' => 'user', 'content' => 'Existing successful image attachment 123 and write feedback.' ) ), 'configuration' => array( 'max_steps' => 128 ) );
}
function ux_transport_failure( $post, $run, $legacy = false ) {
    Bhranti_Audit::record( 'ai_failed', 'Native connection failed.', $post->ID, array( 'run_id' => $run['id'], 'reason' => 'network', 'code' => 'prompt_builder_error', 'status' => 500 ), 7, 7 );
    $run['resume_checkpoint'] = array( 'kind' => 'model_request', 'step' => $run['step'] );
    $result = ux_private( 'finish', $post, $run, 'The AI connection failed after three retries. Completed actions and images are saved. Use Resume task to continue.', 'failed' );
    ux_check( ! is_wp_error( $result ), 'Terminal fixture did not save.' );
    if ( $legacy ) {
        $messages = get_post_meta( $post->ID, 'bhranti_messages', true );
        unset( $messages[ count( $messages ) - 1 ]['run_id'] );
        update_post_meta( $post->ID, 'bhranti_messages', $messages );
    }
}

// New error binding and conservative presentation retain the original source.
$post = ux_session( 1 );
$run = ux_run( 'current-run' );
$original = array(
    array( 'id' => 'old-error', 'role' => 'error', 'content' => 'An unrelated failure.', 'run_id' => 'older-run', 'created' => 'original-time', 'custom' => array( 'preserved' => true ) ),
    array( 'id' => 'reply', 'role' => 'assistant', 'content' => '<strong>Original reply</strong>', 'created' => 'original-time' ),
);
update_post_meta( 1, 'bhranti_messages', $original );
ux_transport_failure( $post, $run );
$before_messages = get_post_meta( 1, 'bhranti_messages', true );
$before_run = get_post_meta( 1, 'bhranti_run', true );
$terminal = end( $before_messages );
ux_check( 'current-run' === $terminal['run_id'] && 'error' === $terminal['role'], 'Terminal errors must bind their run.' );
ux_check( array_slice( $before_messages, 0, 2 ) === $original, 'Finishing altered earlier raw messages.' );
ux_check( Bhranti_Agent::state( 1 )['run']['can_resume'], 'Exact transport failure must remain resumable.' );
$resumed = Bhranti_Agent::resume( array( 'id' => 1, 'run_id' => 'current-run' ) );
ux_check( ! is_wp_error( $resumed ), 'Proven model request did not resume.' );
ux_check( get_post_meta( 1, 'bhranti_messages', true ) === $before_messages, 'Resume rewrote raw messages or metadata.' );
$after_run = get_post_meta( 1, 'bhranti_run', true );
ux_check( array_slice( $after_run['history'], 0, count( $before_run['history'] ) ) === $before_run['history'], 'Resume discarded successful tool history.' );
ux_check( count( $after_run['history'] ) === count( $before_run['history'] ) + 1, 'Resume must add only its fixed recovery instruction.' );
ux_check( $after_run['resolved_error_ids'] === array( $terminal['id'] ), 'Resume resolved an unrelated run error.' );
ux_check( ! isset( $resumed['messages'][0]['resolved'] ) && true === $resumed['messages'][2]['resolved'], 'Public resolution must affect only the proven terminal error.' );
ux_check( ! isset( $resumed['run']['resolved_error_ids'] ), 'Private resolution metadata leaked in public run state.' );
ux_check( isset( $resumed['messages'][1]['rendered'] ), 'Assistant static preview was lost.' );
Bhranti_Workspace::save_messages( 1, $resumed['messages'], $post->post_title );
ux_check( get_post_meta( 1, 'bhranti_messages', true ) === $before_messages, 'Derived resolved/rendered fields were persisted into source.' );
ux_private( 'finish', $post, $after_run, 'Done.', 'complete' );
$completed = get_post_meta( 1, 'bhranti_messages', true );
ux_check( array_slice( $completed, 0, count( $before_messages ) ) === $before_messages, 'Completion changed previously stored history.' );
ux_check( ! isset( $completed[ count( $completed ) - 1 ]['run_id'] ), 'Completed replies gained unnecessary error metadata.' );
ux_check( Bhranti_Workspace::session_data( $post )['messages'][2]['resolved'], 'Resolved errors resurfaced after completion.' );
$next_request = Bhranti_Agent::start( array( 'id' => 1, 'content' => 'Continue with a new request.', 'context' => array() ) );
ux_check( ! is_wp_error( $next_request ) && 'current-run' !== $next_request['run']['id'], 'Next request did not create a new run.' );
ux_check( $next_request['messages'][2]['resolved'] && ! isset( $next_request['messages'][0]['resolved'] ), 'A new run lost prior resolution or resolved another run.' );
ux_check( array_slice( get_post_meta( 1, 'bhranti_messages', true ), 0, count( $completed ) ) === $completed, 'Starting a new run rewrote prior raw messages.' );

// Legacy resolution requires the existing exact last-audit proof and only its last error.
$legacy = ux_session( 2 );
update_post_meta( 2, 'bhranti_messages', array( array( 'id' => 'unbound-old', 'role' => 'error', 'content' => 'Earlier unbound error.', 'created' => 'original-time' ) ) );
ux_transport_failure( $legacy, ux_run( 'legacy-run' ), true );
$legacy_raw = get_post_meta( 2, 'bhranti_messages', true );
$legacy_result = Bhranti_Agent::resume( array( 'id' => 2, 'run_id' => 'legacy-run' ) );
ux_check( ! is_wp_error( $legacy_result ), 'Exact legacy failure did not resume.' );
ux_check( get_post_meta( 2, 'bhranti_messages', true ) === $legacy_raw, 'Legacy resolution rewrote source.' );
ux_check( ! isset( $legacy_result['messages'][0]['resolved'] ) && $legacy_result['messages'][1]['resolved'], 'Legacy resolution must not infer unrelated earlier errors.' );
$unsafe = ux_session( 3 );
ux_private( 'finish', $unsafe, ux_run( 'unsafe-run' ), 'Uncertain write failed.', 'failed' );
$unsafe_raw = get_post_meta( 3, 'bhranti_messages', true );
$unsafe_result = Bhranti_Agent::resume( array( 'id' => 3, 'run_id' => 'unsafe-run' ) );
ux_check( is_wp_error( $unsafe_result ) && 'bhranti_resume_unavailable' === $unsafe_result->get_error_code(), 'Uncertain actions must not gain Resume.' );
ux_check( get_post_meta( 3, 'bhranti_messages', true ) === $unsafe_raw && ! isset( get_post_meta( 3, 'bhranti_run', true )['resolved_error_ids'] ), 'Rejected Resume changed history or resolution.' );

// Canonical execution determines refresh metadata; failures never claim a write.
$event_post = ux_session( 4 );
$result = array( 'summary' => 'Reviewed operation completed.', 'data' => array( 'content' => 'Private result' ), 'provider_payload' => 'Never expose', 'target' => array( 'id' => 123, 'type' => 'page', 'title' => 'Fixture', 'url' => '/wp-admin/post.php?post=123&action=edit' ), 'action_id' => 'fixture_action', 'rollback_available' => true );
$cases = array(
    array( 'wp_request', array( 'method' => 'GET' ), 'read' ),
    array( 'wp_request', array( 'method' => 'PATCH' ), 'write' ),
    array( 'wp_request', array( 'method' => 'DELETE' ), 'write' ),
    array( 'plugin_request', array( 'method' => 'POST' ), 'write' ),
    array( 'plugin_request', array( 'method' => 'GET' ), 'read' ),
    array( 'commerce_request', array( 'method' => 'POST' ), 'write' ),
    array( 'commerce_request', array( 'method' => 'GET' ), 'read' ),
    array( 'bhranti_task_limit', array( 'method' => 'POST' ), 'write' ),
    array( 'bhranti_task_limit', array( 'method' => 'GET' ), 'read' ),
    array( 'plugin_search', array(), 'read' ),
    array( 'site_search', array(), 'read' ),
    array( 'open_item', array(), 'read' ),
    array( 'generate_image', array(), 'image' ),
);
foreach ( $cases as $case ) {
    ux_private( 'tool_result', $event_post, ux_run( 'operation-run' ), $result, array( 'tool' => $case[0], 'args' => $case[1] ) );
    $events = get_post_meta( 4, 'bhranti_events', true );
    $event = end( $events );
    ux_check( true === $event['success'] && $case[2] === $event['operation'] && 'operation-run' === $event['run_id'], 'Operation metadata did not follow actual command.' );
    ux_check( ! isset( $event['data'], $event['provider_payload'] ) && $event['target'] === $result['target'], 'Public operation event leaked result data or lost safe target.' );
}
ux_private( 'event', 4, $result, 'operation-run', 'rollback' );
$events = get_post_meta( 4, 'bhranti_events', true );
ux_check( 'rollback' === end( $events )['operation'], 'Rollback must have distinct refresh metadata.' );
ux_private( 'tool_result', $event_post, ux_run( 'failure-run' ), new WP_Error( 'blocked', 'Reviewed policy blocked this action.' ), array( 'tool' => 'wp_request', 'args' => array( 'method' => array( 'POST' ) ) ) );
$events = get_post_meta( 4, 'bhranti_events', true );
$error_event = end( $events );
ux_check( false === $error_event['success'] && 'failure-run' === $error_event['run_id'] && ! isset( $error_event['operation'], $error_event['target'], $error_event['action_id'] ), 'Failed/malformed command must never trigger refresh or fatal.' );
$count = count( $events );
ux_private( 'tool_result', $event_post, ux_run( 'approval-run' ), array( 'confirmation_required' => true, 'summary' => 'Approve deletion.', 'command' => array( 'tool' => 'wp_request', 'args' => array( 'method' => 'DELETE' ) ), 'fingerprint' => 'safe-fingerprint', 'target' => $result['target'] ), array( 'tool' => 'wp_request', 'args' => array( 'method' => 'DELETE' ) ) );
ux_check( count( get_post_meta( 4, 'bhranti_events', true ) ) === $count, 'Awaiting human approval must not claim a completed write.' );
ux_check( 'awaiting_confirmation' === get_post_meta( 4, 'bhranti_run', true )['status'], 'Human-only deletion gate changed.' );

// A possible native write followed by an error cannot trigger another model plan.
$uncertain_post = ux_session( 6 );
$uncertain_run = ux_run( 'uncertain-run' );
$uncertain_run['phase'] = 'action';
$uncertain_run['resume_checkpoint'] = array( 'kind' => 'model_request', 'step' => 4 );
$uncertain_result = ux_private( 'tool_result', $uncertain_post, $uncertain_run, new WP_Error( 'bhranti_commerce_storage', 'Inspect the saved product.', array( 'status' => 500, 'uncertain_action' => true ) ), array( 'tool' => 'commerce_request', 'args' => array( 'method' => 'POST' ) ) );
$uncertain_saved = get_post_meta( 6, 'bhranti_run', true );
ux_check( ! is_wp_error( $uncertain_result ) && 'failed' === $uncertain_saved['status'], 'Uncertain commerce write continued planning.' );
ux_check( ! isset( $uncertain_saved['resume_checkpoint'], $uncertain_saved['next_action'] ) && ! $uncertain_result['run']['can_resume'], 'Uncertain commerce write acquired a replay/Resume path.' );
ux_check( $uncertain_saved['history'][0] === $uncertain_run['history'][0] && 2 === count( $uncertain_saved['history'] ), 'Stopping discarded prior successful actions or its failed feedback.' );
$uncertain_events = get_post_meta( 6, 'bhranti_events', true );
ux_check( false === end( $uncertain_events )['success'] && ! isset( end( $uncertain_events )['operation'] ), 'Uncertain write claimed verified success.' );
$uncertain_messages = get_post_meta( 6, 'bhranti_messages', true );
Bhranti_Agent::step( array( 'id' => 6, 'run_id' => 'uncertain-run', 'defer_tool' => true ) );
ux_check( get_post_meta( 6, 'bhranti_run', true ) === $uncertain_saved && get_post_meta( 6, 'bhranti_messages', true ) === $uncertain_messages, 'A later step replanned a possibly completed mutation.' );
$preflight_post = ux_session( 7 );
ux_private( 'tool_result', $preflight_post, ux_run( 'preflight-run' ), new WP_Error( 'bhranti_commerce_invalid', 'Use a valid native field.', array( 'status' => 400 ) ), array( 'tool' => 'commerce_request', 'args' => array( 'method' => 'POST' ) ) );
ux_check( 'running' === get_post_meta( 7, 'bhranti_run', true )['status'], 'A safe rejected preflight must still allow the model to correct its fields.' );

// Resolution lists stay bounded even after repeated proven failures.
$bounded = ux_session( 5 );
$bounded_run = ux_run( 'bounded-run' );
$bounded_run['resolved_error_ids'] = array_map( static function ( $id ) { return 'old-' . $id; }, range( 1, 205 ) );
ux_transport_failure( $bounded, $bounded_run );
Bhranti_Agent::resume( array( 'id' => 5, 'run_id' => 'bounded-run' ) );
$bounded_ids = get_post_meta( 5, 'bhranti_run', true )['resolved_error_ids'];
ux_check( 200 === count( $bounded_ids ), 'Private resolution list exceeded its cap.' );
ux_check( 'old-7' === $bounded_ids[0], 'Resolution must retain its most recent IDs.' );
ux_check( get_post_meta( 5, 'bhranti_messages', true )[0]['id'] === $bounded_ids[199], 'Latest error resolution was dropped.' );

echo 'PASS ' . $GLOBALS['ux_checks'] . " product UX checks; no DB, HTTP or AI.\n";
