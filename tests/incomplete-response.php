<?php
/**
 * Offline native incomplete-response retry regression; no site bootstrap or I/O.
 * Reuses memory storage seams, actual Bhranti Agent/Workspace/Diagnostics,
 * the installed connector SSE parser and WordPress exception mapping/AI DTOs.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/incomplete-response.php
 */
require __DIR__ . '/product-ux.php';
require ABSPATH . 'wp-includes/php-ai-client/autoload.php';
require ABSPATH . 'wp-includes/ai-client/class-wp-ai-client-prompt-builder.php';
require ABSPATH . 'wp-content/plugins/littlemcp/src/ResponseParser.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-knowledge.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-progress.php';
$GLOBALS['ir_checks_start'] = $GLOBALS['ux_checks'];
$GLOBALS['ir_calls'] = 0;
$GLOBALS['ir_reply'] = null;
$GLOBALS['wp_version'] = 'offline-native';
function get_translations_for_domain( $domain ) { return new class { public function translate( $message ) { return $message; } }; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function get_bloginfo( $key ) { return 'Offline test site'; }
function home_url( $path = '' ) { return 'https://bhranti.wp.local' . $path; }
function wp_get_theme() { return new class { public function get( $key ) { return 'Fixture theme'; } }; }
function wp_count_posts( $type ) { return (object) array( 'publish' => 0 ); }
function add_action( ...$args ) { return true; }
function remove_action( ...$args ) { return true; }
final class Bhranti_Plugins { public static function catalog() { return array(); } }
final class Bhranti_Media { public static function catalog() { return array(); } }
final class BhrantiIncompletePrompt {
    public function using_system_instruction( $value ) { return $this; }
    public function as_json_response( $value ) { return $this; }
    public function using_request_options( $value ) { return $this; }
    public function generate_text() { ++$GLOBALS['ir_calls']; return $GLOBALS['ir_reply']; }
}
$GLOBALS['ux_prompt_factory'] = static function( $history ) { return new BhrantiIncompletePrompt(); };
function ir_error( $sse ) {
    try { LittleMCP\ChatGPT\ResponseParser::parse( $sse ); }
    catch ( RuntimeException $exception ) {
        $native = (new ReflectionClass( WP_AI_Client_Prompt_Builder::class ))->newInstanceWithoutConstructor();
        return (new ReflectionMethod( WP_AI_Client_Prompt_Builder::class, 'exception_to_wp_error' ))->invoke( $native, $exception );
    }
    throw new RuntimeException( 'Native parser unexpectedly accepted an incomplete response.' );
}
function ir_run( $id ) { $run = ux_run( 'incomplete-' . $id ); $run['phase'] = ''; $run['context'] = array(); $run['plan_retries'] = 1; update_post_meta( $id, 'bhranti_run', $run ); return $run; }
function ir_step( $id, $reply ) { $GLOBALS['ir_reply'] = $reply; $run = get_post_meta( $id, 'bhranti_run', true ); return Bhranti_Agent::step( array( 'id' => $id, 'run_id' => $run['id'], 'defer_tool' => true ) ); }
function ir_due( $id ) { $run = get_post_meta( $id, 'bhranti_run', true ); $run['retry_at'] = time() - 1; update_post_meta( $id, 'bhranti_run', $run ); }

$incomplete = array(
    'data: {"type":"response.output_text.delta","delta":"private-partial-marker"}' . "\n\n",
    'data: {"type":"response.incomplete","response":{"status":"incomplete"}}' . "\n\n",
    'data: {"type":"response.completed","response":{"id":"fixture","status":"completed"}}',
);
foreach ( $incomplete as $sse ) {
    $error = ir_error( $sse ); $diagnostic = Bhranti_Diagnostics::classify( $error );
    ux_check( 'prompt_builder_error' === $error->get_error_code() && 500 === $error->get_error_data()['status'], 'Actual native exception mapping matches production failure.' );
    ux_check( 'incomplete_response' === $diagnostic['reason'] && 'runtime' === $diagnostic['exception_class'], 'Exact native parser failure is classified without inspecting payload.' );
    $post = ux_session( 100 + $GLOBALS['ir_calls'] ); $run = ir_run( $post->ID ); $GLOBALS['ir_reply'] = $error;
    $result = ux_private( 'ask', $post, $run );
    ux_check( is_wp_error( $result ) && true === $result->get_error_data()['transient'], 'Known native incomplete result uses failed-model retry classification.' );
}
$error = ir_error( $incomplete[0] );
$post = ux_session( 120 ); $run = ir_run( 120 ); $history = $run['history'];
foreach ( array( 5, 15, 30 ) as $index => $delay ) {
    $before = time(); $calls = $GLOBALS['ir_calls']; $result = ir_step( 120, $error ); $saved = get_post_meta( 120, 'bhranti_run', true );
    ux_check( ! is_wp_error( $result ) && 'running' === $saved['status'] && '' === $saved['phase'], 'Repeated incomplete response stays recoverable beyond the malformed-plan allowance.' );
    ux_check( $index + 1 === $saved['request_retries'] && $saved['retry_at'] >= $before + $delay && $saved['retry_at'] <= time() + $delay, 'Incomplete retries use existing bounded deadlines.' );
    ux_check( 1 === $saved['plan_retries'] && $history === $saved['history'] && ! isset( $saved['pending_plan'], $saved['next_action'] ), 'No incomplete output becomes a plan or discards completed tool history.' );
    ux_check( $calls + 1 === $GLOBALS['ir_calls'], 'Exactly one mocked model request per incomplete attempt.' );
    ir_step( 120, $error );
    ux_check( $calls + 1 === $GLOBALS['ir_calls'] && $saved === get_post_meta( 120, 'bhranti_run', true ), 'Early browser/background step cannot bypass retry deadline.' );
    ir_due( 120 );
}
$result = ir_step( 120, $error ); $failed = get_post_meta( 120, 'bhranti_run', true );
ux_check( 'failed' === $failed['status'] && 3 === $failed['request_retries'] && 'model_request' === $failed['resume_checkpoint']['kind'], 'Fourth consecutive failure stops with the exact safe model checkpoint.' );
ux_check( $result['run']['can_resume'] && $history === $failed['history'], 'Verified incomplete failure permits explicit Resume without losing successful actions.' );
$calls = $GLOBALS['ir_calls']; $resume = Bhranti_Agent::resume( array( 'id' => 120, 'run_id' => $failed['id'] ) );
ux_check( ! is_wp_error( $resume ) && $calls === $GLOBALS['ir_calls'] && 0 === get_post_meta( 120, 'bhranti_run', true )['request_retries'], 'Human Resume renews budget without making an inline request.' );
$valid = wp_json_encode( array( 'type' => 'tool', 'content' => 'Read pages.', 'tool' => 'wp_request', 'args' => '{"method":"GET","path":"/wp/v2/pages"}' ) );
$result = ir_step( 120, $valid ); $prepared = get_post_meta( 120, 'bhranti_run', true );
ux_check( 'prepared' === $prepared['phase'] && 0 === $prepared['request_retries'] && ! isset( $prepared['retry_at'] ) && 1 === $prepared['plan_retries'], 'Valid plan resets consecutive request retries without renewing malformed-plan allowance.' );
ux_private( 'tool_result', $post, $prepared, array( 'summary' => 'Saved pages read.', 'data' => array() ), $prepared['pending_plan'] );
$prior = get_post_meta( 120, 'bhranti_run', true ); ir_step( 120, $error ); $saved = get_post_meta( 120, 'bhranti_run', true );
ux_check( 1 === $saved['request_retries'] && $prior['history'] === $saved['history'], 'A later incomplete request receives a fresh consecutive budget and preserves completed read feedback.' );
ir_due( 120 ); ir_step( 120, $valid ); $renewed = get_post_meta( 120, 'bhranti_run', true );
ux_check( 'prepared' === $renewed['phase'] && 0 === $renewed['request_retries'] && ! isset( $renewed['retry_at'] ) && 1 === $renewed['plan_retries'], 'A valid plan following an actual incomplete retry resets only the consecutive request budget.' );

// Explicit denied/unsupported errors do not acquire connection retries or Resume.
$blocked = array(
    new WP_Error( 'prompt_client_error', 'Native authentication refused.', array( 'status' => 401 ) ),
    new WP_Error( 'prompt_client_error', 'Native permission refused.', array( 'status' => 403 ) ),
    new WP_Error( 'prompt_client_error', 'Native usage refused.', array( 'status' => 429 ) ),
    new WP_Error( 'prompt_builder_error', 'Your ChatGPT plan usage limit has been reached. Try again after your limit resets.', array( 'status' => 500 ) ),
    new WP_Error( 'prompt_invalid_argument', 'Unsupported native schema.', array( 'status' => 400 ) ),
    new WP_Error( 'prompt_builder_error', 'ChatGPT returned invalid JSON for the requested structured output.', array( 'status' => 500 ) ),
    new WP_Error( 'prompt_prevented', 'Native approval blocked.', array( 'status' => 403 ) ),
    new WP_Error( 'prompt_builder_error', $error->get_error_message(), array( 'status' => 429 ) ),
);
foreach ( $blocked as $index => $blocked_error ) {
    $id = 140 + $index; $post = ux_session( $id ); ir_run( $id ); $GLOBALS['ir_reply'] = $blocked_error;
    $classification = ux_private( 'ask', $post, get_post_meta( $id, 'bhranti_run', true ) );
    ux_check( ! $classification->get_error_data()['transient'], 'Auth/usage/schema/prevented response does not gain bounded request retry.' );
    $result = ir_step( $id, $blocked_error ); $saved = get_post_meta( $id, 'bhranti_run', true );
    ux_check( 'failed' === $saved['status'] && ! isset( $saved['retry_at'], $saved['request_retries'] ) && ! $result['run']['can_resume'], 'Nontransient response remains terminal after existing malformed allowance and cannot Resume.' );
}
$post = ux_session( 160 ); $uncertain = ir_run( 160 );
$uncertain['resume_checkpoint'] = array( 'kind' => 'model_request', 'step' => $uncertain['step'] );
ux_private( 'tool_result', $post, $uncertain, new WP_Error( 'bhranti_commerce_native', 'Possible saved product.', array( 'uncertain_action' => true ) ), array( 'tool' => 'commerce_request', 'args' => array( 'method' => 'POST' ) ) );
$calls = $GLOBALS['ir_calls']; $result = ir_step( 160, $error );
ux_check( 'failed' === $result['run']['status'] && ! $result['run']['can_resume'] && $calls === $GLOBALS['ir_calls'], 'Incomplete-request support cannot retry or replan an uncertain mutation.' );
ux_check( false === strpos( wp_json_encode( $GLOBALS['ux_meta'] ), 'private-partial-marker' ), 'Partial native response payload is never persisted into history or audit.' );
echo 'PASS ' . ( $GLOBALS['ux_checks'] - $GLOBALS['ir_checks_start'] ) . " incomplete-response checks with native parser/mapping; no DB, HTTP or AI.\n";
