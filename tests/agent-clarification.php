<?php
/**
 * Offline clarification and public intent regression with the real Agent/Runner.
 * Native WordPress formatting and AI message DTOs; in-memory storage, scheduling
 * and identity. No wp-load.php, DB, HTTP, provider calls or site mutations.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/agent-clarification.php
 */
$core_root = getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Documents/WordPress/Plugins';
define( 'ABSPATH', rtrim( $core_root, '/' ) . '/' );
require ABSPATH . 'wp-includes/class-wp-error.php';
require ABSPATH . 'wp-includes/formatting.php';
require ABSPATH . 'wp-includes/utf8.php';
$GLOBALS['ac'] = array( 'posts' => array(), 'meta' => array(), 'options' => array(), 'cron' => array(), 'events' => array(), 'uuid' => 0, 'calls' => 0, 'checks' => 0, 'user' => 7 );
$GLOBALS['wp_version'] = 'offline-native';
function __( $text, $domain = 'default' ) { return $text; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function do_action( ...$args ) {}
function add_action( ...$args ) {}
function remove_action( ...$args ) {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function is_utf8_charset( $value = null ) { return true; }
function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
function get_current_user_id() { return $GLOBALS['ac']['user']; }
function get_current_blog_id() { return 1; }
function get_user_meta( $id, $key, $single = false ) { return $single ? '' : array(); }
function user_can( $id, $cap, ...$args ) { return in_array( (int) $id, array( 7, 11 ), true ); }
function wp_cache_delete( ...$args ) {}
function clean_post_cache( ...$args ) {}
function get_post( $id ) { return $GLOBALS['ac']['posts'][ $id ] ?? null; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['ac']['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { if ( ( $GLOBALS['ac']['fail_meta'] ?? '' ) === $key ) { return $GLOBALS['ac']['fail_meta_error'] ?? false; } $GLOBALS['ac']['meta'][ $id ][ $key ] = wp_unslash( $value ); return true; }
function get_option( $key, $default = false ) { return $GLOBALS['ac']['options'][ $key ] ?? $default; }
function add_option( $key, $value, ...$args ) { if ( isset( $GLOBALS['ac']['options'][ $key ] ) ) { return false; } $GLOBALS['ac']['options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['ac']['options'][ $key ] ); return true; }
function wp_update_post( $value, $wp_error = false ) { $value = wp_unslash( $value ); $GLOBALS['ac']['posts'][ $value['ID'] ]->post_title = $value['post_title']; return $value['ID']; }
function get_post_datetime( ...$args ) { return new DateTimeImmutable( '2026-10-12T00:00:00Z' ); }
function wp_generate_uuid4() { return sprintf( '00000000-0000-4000-8000-%012d', ++$GLOBALS['ac']['uuid'] ); }
function get_edit_post_link( $id, $context = 'display' ) { return 'https://bhranti.wp.local/wp-admin/post.php?post=' . $id . '&action=edit'; }
function wp_next_scheduled( $hook, $args ) { return $GLOBALS['ac']['cron'][ $hook ][ json_encode( $args ) ] ?? false; }
function wp_schedule_single_event( $time, $hook, $args, $wp_error = false ) { $GLOBALS['ac']['cron'][ $hook ][ json_encode( $args ) ] = $time; return true; }
function wp_clear_scheduled_hook( $hook, $args, $wp_error = false ) { $count = (int) isset( $GLOBALS['ac']['cron'][ $hook ][ json_encode( $args ) ] ); unset( $GLOBALS['ac']['cron'][ $hook ][ json_encode( $args ) ] ); return $count; }
function get_bloginfo( $key ) { return 'Offline fixture'; }
function home_url( $path = '' ) { return 'https://bhranti.wp.local' . $path; }
function wp_get_theme() { return new class { public function get( $key ) { return 'Fixture'; } }; }
function wp_count_posts( $type ) { return (object) array( 'publish' => 0 ); }
function get_translations_for_domain( $domain ) { return new class { public function translate( $text ) { return $text; } }; }
function wp_get_connectors() { return array( 'fixture' => array( 'type' => 'ai_provider' ) ); }
function wp_ai_client_prompt( $history ) { $GLOBALS['ac']['history'] = $history; return new BhrantiClarificationPrompt(); }
final class BhrantiClarificationRegistry {
    public static function defaultRegistry() { return new self(); }
    public function hasProvider( $id ) { return 'fixture' === $id; }
    public function isProviderConfigured( $id ) { return 'fixture' === $id; }
}
class_alias( 'BhrantiClarificationRegistry', 'WordPress\\AiClient\\AiClient' );
require ABSPATH . 'wp-includes/php-ai-client/autoload.php';
final class BhrantiClarificationPrompt {
    public function using_system_instruction( $text ) { $GLOBALS['ac']['instruction'] = $text; return $this; }
    public function as_json_response( $schema ) { $GLOBALS['ac']['schema'] = $schema; return $this; }
    public function using_request_options( $options ) { return $this; }
    public function generate_text() { ++$GLOBALS['ac']['calls']; return $GLOBALS['ac']['reply']; }
}
final class Bhranti_Settings { public static function profile( $id ) { return array( 'id' => $id, 'max_steps' => 128 ); } public static function all() { return array( 'permissions' => array() ); } }
final class Bhranti_Actions { public static function ensure_actor() { return 11; } public static function catalog() { return array(); } public static function execute( ...$args ) { throw new RuntimeException( 'Unexpected tool execution.' ); } }
final class Bhranti_Plugins { public static function catalog() { return array(); } }
final class Bhranti_Media { public static function catalog() { return array(); } }
final class Bhranti_Chat { public static function render( $text ) { return '<p>' . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . '</p>'; } }
final class Bhranti_Audit { public static function record( $type, $summary, $session, $details = array(), $requester = 0, $actor = 0 ) { if ( ( $GLOBALS['ac']['fail_audit'] ?? '' ) === $type ) { return new WP_Error( 'audit_storage', 'Audit unavailable.' ); } $GLOBALS['ac']['events'][] = compact( 'type', 'summary', 'session', 'details', 'requester', 'actor' ); return count( $GLOBALS['ac']['events'] ); } }
foreach ( array( 'workspace', 'skills', 'task', 'agent', 'runner', 'knowledge', 'progress', 'diagnostics' ) as $class ) { require dirname( __DIR__ ) . '/includes/class-bhranti-' . $class . '.php'; }
function ac_check( $value, $message ) { ++$GLOBALS['ac']['checks']; if ( ! $value ) { throw new RuntimeException( $message ); } }
function ac_private( $method, ...$args ) { return ( new ReflectionMethod( Bhranti_Agent::class, $method ) )->invoke( null, ...$args ); }
function ac_envelope( $type, $content, $tool = 'none', $args = '{}' ) { return wp_json_encode( compact( 'type', 'content', 'tool', 'args' ) ); }
function ac_start( $content ) { return Bhranti_Agent::start( array( 'id' => 1, 'content' => $content, 'context' => array() ) ); }
function ac_step( $run, $reply ) { $GLOBALS['ac']['reply'] = $reply; return Bhranti_Agent::step( array( 'id' => 1, 'run_id' => $run, 'defer_tool' => true ) ); }

$GLOBALS['ac']['posts'][1] = (object) array( 'ID' => 1, 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => 7, 'post_title' => 'Fixture' );
$GLOBALS['ac']['posts'][20] = (object) array( 'ID' => 20, 'post_type' => 'page', 'post_status' => 'draft', 'post_author' => 7, 'post_title' => 'Existing page' );

// The fixed native envelope accepts explicit questions, never infers them from prose.
$question = ac_envelope( 'question', 'What is the business name, and should this be a preview or published?' );
ac_check( array( 'type' => 'question', 'content' => 'What is the business name, and should this be a preview or published?' ) === ac_private( 'parse', $question ), 'Question envelope normalizes to a non-action response.' );
foreach ( array(
    ac_envelope( 'question', 'Choose?', 'wp_request' ),
    ac_envelope( 'question', 'Choose?', 'none', '{"method":"POST"}' ),
    ac_envelope( 'question', '   ' ),
    ac_envelope( 'question', array( 'invalid' ) ),
    '{"type":"question","content":"Choose?","tool":"none","args":"{}","approved":true}',
) as $invalid ) { ac_check( is_wp_error( ac_private( 'parse', $invalid ) ), 'Malformed question cannot carry an action or approval.' ); }
ac_check( 'reply' === ac_private( 'parse', '{"type":"reply","content":"An ordinary answer with a question mark?"}' )['type'], 'Legacy replies retain completion semantics without text heuristics.' );

// Native plain-text sanitation; intent cannot change the command, target or control flags.
$args = array( 'method' => 'POST', 'path' => '/wp/v2/pages/20', 'params' => array( 'content' => 'private-content-marker' ) );
$description = "<strong>Prepare</strong>\n this page <script>private-script-marker</script>" . str_repeat( 'é', 270 );
$parsed = ac_private( 'parse', ac_envelope( 'tool', $description, 'wp_request', wp_json_encode( $args ) ) );
ac_check( $args === $parsed['args'] && 'wp_request' === $parsed['tool'], 'Public intent does not modify canonical tool arguments.' );
ac_check( mb_strlen( $parsed['intent'] ) === 240 && 0 === strpos( $parsed['intent'], 'Prepare this page ' ) && false === strpos( $parsed['intent'], '<' ) && false === strpos( $parsed['intent'], 'private-script-marker' ), 'Native sanitation strips markup, script text and whitespace then bounds Unicode characters.' );
$command = array( 'tool' => 'wp_request', 'args' => $args );
$base = Bhranti_Progress::describe( $command, 7, 11 );
$progress = Bhranti_Progress::describe( $command, 7, 11, $description );
ac_check( $parsed['intent'] === $progress['intent'], 'Public progress applies the same bounded native sanitation.' );
unset( $progress['intent'] );
ac_check( $base === $progress && $base['write'] && $base['navigation'] && 20 === $base['target']['id'], 'Intent cannot replace derived summary, operation, target or write/navigation state.' );
$legacy = array( 'type' => 'tool', 'tool' => 'wp_request', 'args' => $args );
ac_check( $legacy === ac_private( 'parse', wp_json_encode( $legacy ) ), 'Legacy tool commands remain compatible.' );
ac_check( ! isset( ac_private( 'parse', ac_envelope( 'tool', '', 'wp_request', wp_json_encode( $args ) ) )['intent'] ), 'Empty public content adds no intent.' );
ac_check( is_wp_error( ac_private( 'parse', wp_json_encode( $legacy + array( 'intent' => 'Forged field' ) ) ) ), 'Legacy extra fields cannot inject intent outside the fixed envelope.' );

// A real question finishes this planning run, clears only its Cron event and preserves chat.
$first = ac_start( 'Build a complete site for my business. You may publish it and choose a modern style.' ); $run = $first['run']['id'];
$unrelated = array( 2, wp_generate_uuid4(), 7 );
wp_schedule_single_event( time() + 60, 'bhranti_run_tick', $unrelated );
ac_check( wp_next_scheduled( 'bhranti_run_tick', array( 1, $run, 7 ) ), 'A submitted task schedules native continuation before planning.' );
$project_question = ac_envelope( 'question', 'I understand you want a published modern business site. What do you offer, who is it for, what should visitors do, and what pages or existing brand materials matter?' );
$waiting = ac_step( $run, $project_question ); $saved = get_post_meta( 1, 'bhranti_run', true );
ac_check( 'awaiting_input' === $waiting['run']['status'] && '' === $waiting['run']['phase'] && ! $waiting['run']['can_resume'], 'A question waits for input instead of claiming completion or offering model failure recovery.' );
ac_check( 'assistant' === $waiting['messages'][1]['role'] && 2 === count( $waiting['messages'] ), 'Question is a normal persisted assistant message.' );
ac_check( ! isset( $saved['pending_plan'], $saved['next_action'], $saved['confirmation'], $saved['retry_at'], $saved['resume_checkpoint'] ), 'Waiting has no tool, approval, retry deadline or recovery checkpoint.' );
ac_check( ! wp_next_scheduled( 'bhranti_run_tick', array( 1, $run, 7 ) ) && wp_next_scheduled( 'bhranti_run_tick', $unrelated ), 'Only the waiting run scheduled event is cleared.' );
$event = end( $GLOBALS['ac']['events'] );
ac_check( 'run_awaiting_input' === $event['type'] && 'awaiting_input' === $event['details']['status'] && 7 === $event['requester'] && 11 === $event['actor'], 'Waiting is separately audited with both identities.' );
$calls = $GLOBALS['ac']['calls'];
ac_step( $run, ac_envelope( 'reply', 'Must not run.' ) );
ac_check( false === Bhranti_Runner::tick( 1, $run, 7 ) && $calls === $GLOBALS['ac']['calls'], 'Stale browser steps and Cron ticks cannot continue a waiting question.' );
wp_schedule_single_event( time() + 1, 'bhranti_run_tick', array( 1, $run, 7 ) );
ac_check( true === Bhranti_Runner::schedule( 1, $run, 7 ) && ! wp_next_scheduled( 'bhranti_run_tick', array( 1, $run, 7 ) ), 'Even an explicit scheduler call removes a waiting run event.' );
ac_check( is_wp_error( Bhranti_Agent::resume( array( 'id' => 1, 'run_id' => $run ) ) ), 'Connection Resume cannot bypass a required human answer.' );
$GLOBALS['ac']['user'] = 8;
ac_check( is_wp_error( ac_start( 'An unrelated user answer.' ) ), 'Only the owned conversation can receive the next answer.' );
$GLOBALS['ac']['user'] = 7;

// A partial project answer can ask for remaining decisions without starting a write.
$second = ac_start( 'The name is Willow & Clay. You can publish it and choose a warm style.' );
$partial_run = $second['run']['id'];
ac_check( $partial_run !== $run && 'running' === $second['run']['status'] && 3 === count( $second['messages'] ), 'An ordinary answer starts a distinct run without losing the clarification.' );
ac_check( is_wp_error( ac_step( $run, $question ) ) && $calls === $GLOBALS['ac']['calls'], 'A stale request from the prior run cannot affect the new run.' );
$followup = ac_envelope( 'question', 'Willow & Clay will have a warm published site. What do you sell and to whom, what should visitors do and expect afterward, and which pages or brand materials should I use?' );
$waiting_again = ac_step( $partial_run, $followup );
$partial_saved = get_post_meta( 1, 'bhranti_run', true );
ac_check( 'awaiting_input' === $waiting_again['run']['status'] && 4 === count( $waiting_again['messages'] ) && ! isset( $partial_saved['pending_plan'], $partial_saved['next_action'] ), 'A genuine follow-up question preserves partial answers without preparing a mutation.' );
ac_check( ! wp_next_scheduled( 'bhranti_run_tick', array( 1, $partial_run, 7 ) ) && wp_next_scheduled( 'bhranti_run_tick', $unrelated ), 'The partial-answer question clears only its own background continuation.' );
$calls = $GLOBALS['ac']['calls'];
ac_check( false === Bhranti_Runner::tick( 1, $partial_run, 7 ) && $calls === $GLOBALS['ac']['calls'] && is_wp_error( Bhranti_Agent::resume( array( 'id' => 1, 'run_id' => $partial_run ) ) ), 'Background work and connection Resume cannot substitute for the remaining project answers.' );
$third = ac_start( 'We sell handmade gifts to local shoppers. Show the range and let people request an item in a form saved in the dashboard; staff confirm availability afterward. Include Home, Collection, About and Contact, preserving current contact details. We have no brand assets or references; choose the visual design.' );
$next = $third['run']['id'];
ac_check( $next !== $partial_run && 'running' === $third['run']['status'] && 5 === count( $third['messages'] ), 'The actual remaining answer starts a distinct scheduled run with all prior decisions retained.' );
ac_check( is_wp_error( ac_step( $partial_run, $followup ) ) && $calls === $GLOBALS['ac']['calls'], 'A delayed partial-answer request cannot replace the newly answered run.' );
$intent = 'Build the agreed pages around handmade gifts and a staff-confirmed enquiry.';
$prepared = ac_step( $next, ac_envelope( 'tool', $intent, 'wp_request', wp_json_encode( $args ) ) );
$private = get_post_meta( 1, 'bhranti_run', true );
ac_check( $command === $private['pending_plan'] && 'prepared' === $prepared['run']['phase'], 'Only canonical tool and arguments enter the prepared execution plan.' );
ac_check( $intent === $prepared['run']['next_action']['intent'] && ! isset( $prepared['run']['pending_plan'], $prepared['run']['history'], $prepared['run']['configuration'] ), 'Only bounded display intent accompanies public action metadata.' );
ac_check( false === strpos( wp_json_encode( $prepared ), 'private-content-marker' ), 'Private parameter values never leak through public prepared state.' );
$history = array_map( static function( $message ) { return $message->getParts()[0]->getText(); }, $GLOBALS['ac']['history'] );
ac_check( array_column( array_slice( $prepared['messages'], 0, 5 ), 'content' ) === $history, 'Native AI messages preserve the request, both clarification rounds and both answers in order.' );
ac_check( in_array( 'question', $GLOBALS['ac']['schema']['properties']['type']['enum'], true ), 'The native structured response schema advertises explicit questions.' );
ac_check( array( 'type', 'content', 'tool', 'args' ) === array_keys( $GLOBALS['ac']['schema']['properties'] ), 'Project intake uses the existing four-field native response contract.' );

// Bundled design policy reaches the native model independently of administrator profiles.
$skill = Bhranti_Knowledge::design_skill();
ac_check( is_string( $skill ) && strlen( $skill ) < 16384 && false !== strpos( $GLOBALS['ac']['instruction'], $skill ), 'Actual native prompt includes the complete bounded bundled design skill.' );
ac_check( in_array( 'form_request', $GLOBALS['ac']['schema']['properties']['tool']['enum'], true ), 'Native structured output advertises the reviewed form definition adapter.' );
$form_plan = ac_private( 'parse', ac_envelope( 'tool', 'Read existing forms.', 'form_request', '{"method":"GET","path":"/contact-form-7/v1"}' ) );
ac_check( 'form_request' === $form_plan['tool'] && 'GET' === $form_plan['args']['method'], 'Form requests survive the real strict-envelope parser without guessed HTML.' );
ac_check( false === ( new ReflectionMethod( Bhranti_Runner::class, 'is_write' ) )->invoke( null, array( 'pending_plan' => array( 'tool' => 'form_request', 'args' => array( 'method' => 'GET' ) ) ) ), 'Form discovery remains a genuine read when an editor is open.' );
ac_check( true === ( new ReflectionMethod( Bhranti_Runner::class, 'is_write' ) )->invoke( null, array( 'pending_plan' => array( 'tool' => 'form_request', 'args' => array( 'method' => 'POST' ) ) ) ), 'Native form setup retains foreground/background editor write safeguards.' );

// Normal replies retain prior completion behavior, including older compact envelopes.
ac_private( 'tool_result', get_post( 1 ), $private, array( 'summary' => 'Fixture result.', 'data' => array() ), $private['pending_plan'] );
$done = ac_step( $next, '{"type":"reply","content":"The requested preview is saved."}' );
ac_check( 'complete' === $done['run']['status'] && ! wp_next_scheduled( 'bhranti_run_tick', array( 1, $next, 7 ) ), 'Existing reply completion behavior remains compatible and clears scheduling.' );

// Focused edits retain their direct path; project intake does not add a runtime gate.
$focused = ac_start( 'Change the title of page 20 to Opening hours.' );
$focused_args = array( 'method' => 'POST', 'path' => '/wp/v2/pages/20', 'params' => array( 'title' => 'Opening hours' ) );
$focused_ready = ac_step( $focused['run']['id'], ac_envelope( 'tool', 'Apply the requested page title.', 'wp_request', wp_json_encode( $focused_args ) ) );
$focused_saved = get_post_meta( 1, 'bhranti_run', true );
ac_check( 'prepared' === $focused_ready['run']['phase'] && 'running' === $focused_ready['run']['status'] && $focused_args === $focused_saved['pending_plan']['args'], 'A clear focused edit can prepare its action directly without a project questionnaire.' );
ac_check( wp_next_scheduled( 'bhranti_run_tick', array( 1, $focused['run']['id'], 7 ) ), 'A directly prepared focused edit retains normal native continuation.' );
echo 'PASS ' . $GLOBALS['ac']['checks'] . " clarification/intent checks with native formatting and Agent/Runner; no DB, HTTP or AI.\n";
