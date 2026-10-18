<?php
/**
 * Offline credential-boundary regression with the real shared detector and Agent.
 *
 * No WordPress bootstrap, database, network, provider, site settings or files.
 * Run: php tests/credentials.php
 *
 * @package Bhranti
 */

define( 'ABSPATH', rtrim( getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Documents/WordPress/Plugins', '/' ) . '/' );
require ABSPATH . 'wp-includes/class-wp-error.php';

function __( $text, $domain = 'default' ) { return $text; }
function do_action( ...$args ) {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
function wp_slash( $value ) { return $value; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $text ) { return trim( strip_tags( str_replace( array( "\r", "\n", "\t" ), ' ', $text ) ) ); }
function wp_strip_all_tags( $text, $remove_breaks = false ) { return strip_tags( $text ); }
function wp_check_invalid_utf8( $text, $strip = false ) { return mb_check_encoding( $text, 'UTF-8' ) ? $text : ''; }
function absint( $value ) { return abs( (int) $value ); }
function get_current_user_id() { return 7; }
function get_post( $id ) { return $GLOBALS['cred_post']; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['cred_meta'][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { ++$GLOBALS['cred_effects']['meta']; $GLOBALS['cred_meta'][ $key ] = $value; return true; }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }
function home_url( $path = '' ) { return 'https://credentials.example.invalid' . $path; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_remote_request() { throw new RuntimeException( 'Network forbidden.' ); }
function wp_ai_client_prompt() { ++$GLOBALS['cred_effects']['ai']; throw new RuntimeException( 'AI forbidden.' ); }

final class Bhranti_Workspace {
	public static function mutate( $callback ) { return $callback(); }
	public static function owned_session( $id ) { return $GLOBALS['cred_post']; }
	public static function ai_status() { return array( 'available' => true ); }
	public static function session_data( $post ) { return array( 'messages' => $GLOBALS['cred_messages'] ); }
	public static function message( $role, $content ) { return array( 'id' => wp_generate_uuid4(), 'role' => $role, 'content' => $content ); }
	public static function save_messages( $id, $messages, $title ) { ++$GLOBALS['cred_effects']['messages']; $GLOBALS['cred_messages'] = $messages; $GLOBALS['cred_post']->post_title = $title; return true; }
}
final class Bhranti_Settings {
	public static function profile( $id ) { return $GLOBALS['cred_configuration']; }
}
final class Bhranti_Actions {
	public static function ensure_actor() { ++$GLOBALS['cred_effects']['actor']; return 11; }
}
final class Bhranti_Audit {
	public static function record( ...$args ) { ++$GLOBALS['cred_effects']['audit']; return 1; }
}
final class Bhranti_Runner {
	public static function schedule( ...$args ) { ++$GLOBALS['cred_effects']['schedule']; return true; }
	public static function clear( ...$args ) { ++$GLOBALS['cred_effects']['clear']; return true; }
}
final class Bhranti_Task {
	public static function archive( ...$args ) { ++$GLOBALS['cred_effects']['archive']; return true; }
}

require dirname( __DIR__ ) . '/includes/class-bhranti-credentials.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-agent.php';

$GLOBALS['cred_checks'] = 0;
function cred_check( $condition, $label ) {
	if ( ! $condition ) { throw new RuntimeException( $label ); }
	++$GLOBALS['cred_checks'];
}
function cred_reset() {
	$GLOBALS['cred_post'] = (object) array( 'ID' => 17, 'post_author' => 7, 'post_title' => 'Existing safe conversation' );
	$GLOBALS['cred_messages'] = array();
	$GLOBALS['cred_meta'] = array();
	$GLOBALS['cred_configuration'] = array( 'id' => 'bhranti', 'name' => 'Bhranti', 'max_steps' => 128, 'system_prompt' => '', 'guardrails' => '', 'agent_md' => '', 'skills' => array() );
	$GLOBALS['cred_effects'] = array_fill_keys( array( 'meta', 'messages', 'actor', 'audit', 'ai', 'schedule', 'clear', 'archive' ), 0 );
}
function cred_snapshot() {
	return wp_json_encode( array( $GLOBALS['cred_post'], $GLOBALS['cred_messages'], $GLOBALS['cred_meta'], $GLOBALS['cred_effects'] ) );
}
function cred_reject_start( $content, $context, $label ) {
	$before = cred_snapshot();
	$result = Bhranti_Agent::start( array( 'id' => 17, 'content' => $content, 'context' => $context ) );
	cred_check( is_wp_error( $result ) && 'bhranti_credentials' === $result->get_error_code(), $label . ' rejects with the fixed credential code.' );
	cred_check( $before === cred_snapshot(), $label . ' causes no chat/title/run/actor/audit/schedule/provider side effects.' );
	cred_check( ! Bhranti_Credentials::contains( $result->get_error_message() ), $label . ' error does not echo the supplied credential.' );
}

$cred_secrets = array(
	'password' => 'My password is QAZFixturePassword47!',
	'api_key' => 'Here is my API key: sk-proj-QAZFixtureApi1234567890AbCdEfGhIjKlMn',
	'bearer' => 'Authorization: Bearer QAZFixtureBearer1234567890AbCdEf',
	'basic' => 'Authorization: Basic ' . base64_encode( 'fixture-user:QAZFixtureBasicPassword47!' ),
	'private_key' => "-----BEGIN PRIVATE KEY-----\nUVpaRml4dHVyZVByaXZhdGVLZXlPbmx5\n-----END PRIVATE KEY-----",
	'credential_url' => 'https://fixture-user:QAZFixtureUrlPassword47@credentials.example.invalid/private',
	'json' => '{"password":"QAZFixtureJsonPassword47!"}',
	'json_encoded' => json_encode( '{"password":"QAZFixtureEncodedPassword47!"}' ),
	'url_encoded' => rawurlencode( 'password=QAZFixtureUrlEncodedPassword47!' ),
	'html_encoded' => 'password&#58; QAZFixtureHtmlPassword47!',
	'json_unicode' => '{"pa\\u0073sword":"QAZFixtureUnicodePassword47!"}',
	'zero_width' => "pa\u{200B}ssword: QAZFixtureZeroWidthPassword47!",
	'bracket_wrapped' => 'password: [QAZFixtureBracketPassword47!]',
	'brace_wrapped' => 'password: {QAZFixtureBracePassword47!}',
	'angle_wrapped' => 'password: <QAZFixtureAnglePassword47!>',
	'html_split' => '<b>password</b>:QAZFixtureHtmlSplitPassword47!',
	'html_adjacent' => '<p>Password</p><p>QAZFixtureAdjacentPassword47!</p>',
	'html_adjacent_nested' => '<div><strong>API key</strong></div><code>QAZFixtureNestedKey47!</code>',
	'html_adjacent_token' => '<span>Access token</span><span>QAZFixtureToken47!</span>',
	'php_db_password' => "define( 'DB_PASSWORD', 'QAZFixtureDefineDbPassword47!' );",
	'php_auth_key' => "define( 'AUTH_KEY', 'QAZFixtureDefineAuthKey47!' );",
	'percent_invalid_suffix' => rawurlencode( 'password=QAZFixturePercentPassword47!' ) . '%FF',
);
foreach ( $cred_secrets as $cred_name => $cred_secret ) {
	cred_check( Bhranti_Credentials::contains( $cred_secret ), 'Detector recognizes the fictional ' . $cred_name . ' format.' );
	cred_check( Bhranti_Credentials::contains( array( 'context' => array( 'nested' => $cred_secret ) ) ), 'Recursive arrays detect fictional ' . $cred_name . ' values.' );
	cred_check( Bhranti_Credentials::memory_secret( $cred_secret ), 'Memory rejects fictional ' . $cred_name . ' values.' );
	cred_reset();
	cred_reject_start( $cred_secret, array(), 'New ' . $cred_name . ' message' );
}

$cred_normal = array(
	'How do I reset my password?',
	'Never share passwords, API keys or private keys in chat.',
	'Explain how Authorization: Bearer works without showing a token.',
	'password=<your-password>',
	'api_key=YOUR_API_KEY',
	'Authorization: Bearer <token>',
	'password: [REDACTED]',
	'password: [YOUR_PASSWORD]',
	'<p>Password</p><p>[REDACTED]</p>',
	'<p>API key</p><p>Required</p>',
	'<p>How to reset password</p><p>Use Settings</p>',
	'password: {YOUR_PASSWORD}',
	'password: <YOUR_PASSWORD>',
	'Change the task step limit to 129.',
	'Can you tell me why task 34b86434-527f-4e2c-a5f9-ad546b7a5d66 failed?',
	'https://example.invalid/contact/',
	'I prefer warm earth colors.',
	'Give me basic information about this site.',
	'Improve the accessibility of this paragraph.',
	'Explain Bearer authentication.',
	'Explain Basic authentication.',
);
foreach ( $cred_normal as $cred_text ) {
	cred_check( ! Bhranti_Credentials::contains( $cred_text ), 'Normal requests, security discussion, placeholders and task IDs remain permitted.' );
}
cred_check( ! Bhranti_Credentials::contains( array( 'run_id' => '34b86434-527f-4e2c-a5f9-ad546b7a5d66', 'step' => 129, 'enabled' => true, 'empty' => null ) ), 'Ordinary typed metadata is not treated as credentials.' );
cred_check( Bhranti_Credentials::contains( array( 'password' => 'QAZFixtureStructuredPassword47!' ) ), 'Structured credential keys detect a value even without its label inside the text.' );
cred_check( Bhranti_Credentials::contains( array( 'password' => array( 'value' => 'QAZFixtureNestedPassword47!' ) ) ), 'A nested credential-key value cannot bypass the structured detector.' );
cred_check( Bhranti_Credentials::contains( array( 'credentials' => array( 'password' => array( 'values' => array( 'QAZFixtureDeepPassword47!' ) ) ) ) ), 'Credential keys remain sensitive through nested container values.' );
cred_check( ! Bhranti_Credentials::contains( array( 'password' => '[REDACTED]' ) ), 'Structured redacted placeholders remain safe.' );
cred_check( ! Bhranti_Credentials::contains( array( 'password' => array( 'value' => '[REDACTED]' ) ) ), 'Nested structured redacted placeholders remain safe.' );
cred_check( Bhranti_Credentials::memory_secret( 'QAZOpaqueFixtureToken1234567890AbCdEfGhIjKlMn' ), 'Memory also rejects a bare opaque token.' );
cred_check( ! Bhranti_Credentials::memory_secret( 'I prefer concise and friendly writing' ), 'Ordinary preference remains permitted by the shared memory predicate.' );
cred_check( ! Bhranti_Credentials::contains( Bhranti_Credentials::error()->get_error_message() ) && 'bhranti_credentials' === Bhranti_Credentials::error()->get_error_code(), 'Fixed safe credential errors never contain the triggering input.' );
$cred_result = array( 'success' => true, 'receipt' => array( 'run_id' => '34b86434-527f-4e2c-a5f9-ad546b7a5d66', 'operation' => 'read' ), 'data' => array( 'password' => 'QAZFixtureResultPassword47!', 'description' => $cred_secrets['bearer'], 'safe' => 'Ordinary page title' ) );
$cred_redacted = Bhranti_Credentials::redact( $cred_result );
cred_check( ! Bhranti_Credentials::contains( $cred_redacted ) && true === $cred_redacted['success'] && $cred_result['receipt'] === $cred_redacted['receipt'] && $cred_result['data']['safe'] === $cred_redacted['data']['safe'], 'Model-result withholding removes credential values while preserving successful receipt/status and unrelated content.' );
$cred_redacted = Bhranti_Credentials::redact( array( 'success' => true, 'description' => $cred_secrets['html_split'] ) );
cred_check( ! Bhranti_Credentials::contains( $cred_redacted ) && true === $cred_redacted['success'], 'HTML-split credential labels are withheld from tool observations without changing successful status.' );
$cred_redacted = Bhranti_Credentials::redact( array( 'success' => true, 'description' => $cred_secrets['html_adjacent'] ) );
cred_check( ! Bhranti_Credentials::contains( $cred_redacted ) && true === $cred_redacted['success'] && '[Sensitive text withheld]' === $cred_redacted['description'], 'Adjacent HTML credential labels are withheld from tool observations while preserving successful status.' );
$cred_redacted = Bhranti_Credentials::redact( array( 'success' => true, 'data' => array( 'password' => array( 'value' => 'QAZFixtureNestedResult47!' ), 'safe' => 'Ordinary page title' ) ) );
cred_check( ! Bhranti_Credentials::contains( $cred_redacted ) && true === $cred_redacted['success'] && '[Sensitive text withheld]' === $cred_redacted['data']['password'] && 'Ordinary page title' === $cred_redacted['data']['safe'], 'Nested credential-labeled results are withheld before model observation without losing unrelated data.' );

foreach ( array( 'title', 'selected_block' ) as $cred_context_field ) {
	cred_reset();
	$cred_context = 'title' === $cred_context_field ? array( 'title' => $cred_secrets['password'] ) : array( 'selected_block' => array( 'name' => 'core/paragraph', 'content' => $cred_secrets['password'] ) );
	cred_reject_start( 'Please inspect the current page.', $cred_context, 'Credential in ' . $cred_context_field . ' context' );
}
cred_reset();
cred_reject_start( 'Please inspect the selected paragraph.', array( 'selected_block' => array( 'name' => 'core/paragraph', 'content' => $cred_secrets['html_split'] ) ), 'HTML-split credential in selected-block context' );
cred_reset();
cred_reject_start( 'Please inspect the selected paragraph.', array( 'selected_block' => array( 'name' => 'core/paragraph', 'content' => $cred_secrets['html_adjacent'] ) ), 'Adjacent HTML credential in selected-block context' );
cred_reset();
$cred_request = new class( array( 'id' => 17, 'content' => 'password:QAZFixtureHtmlSplitPassword47!', 'context' => array() ) ) extends ArrayObject {
	public function get_json_params() { return array( 'content' => '<b>password</b>:QAZFixtureHtmlSplitPassword47!', 'context' => array() ); }
};
$cred_before = cred_snapshot();
$cred_result = Bhranti_Agent::start( $cred_request );
cred_check( is_wp_error( $cred_result ) && 'bhranti_credentials' === $cred_result->get_error_code(), 'Canonical sanitized content remains checked when an earlier raw JSON representation differs.' );
cred_check( $cred_before === cred_snapshot(), 'Raw-versus-sanitized credential rejection has no persistence or provider side effects.' );
foreach ( array( 'system_prompt', 'guardrails', 'agent_md', 'skills' ) as $cred_field ) {
	cred_reset();
	$GLOBALS['cred_configuration'][ $cred_field ] = 'skills' === $cred_field ? array( array( 'id' => 'fixture', 'name' => 'Fixture skill', 'skill_md' => $cred_secrets['password'] ) ) : $cred_secrets['password'];
	cred_reject_start( 'What can you help me do?', array(), 'Credential in captured ' . $cred_field );
}
cred_reset();
$GLOBALS['cred_messages'] = array( array( 'role' => 'user', 'content' => $cred_secrets['password'] ) );
cred_reject_start( 'How many themes are installed?', array(), 'Credential in legacy conversation history' );

$cred_parse = new ReflectionMethod( Bhranti_Agent::class, 'parse' );
foreach ( $cred_secrets as $cred_name => $cred_secret ) {
	foreach ( array( 'reply', 'question' ) as $cred_type ) {
		$cred_result = $cred_parse->invoke( null, wp_json_encode( array( 'type' => $cred_type, 'content' => $cred_secret, 'tool' => 'none', 'args' => '{}' ) ) );
		cred_check( is_wp_error( $cred_result ) && 'bhranti_credentials' === $cred_result->get_error_code(), 'Model ' . $cred_type . ' cannot echo fictional ' . $cred_name . ' credentials.' );
	}
	$cred_result = $cred_parse->invoke( null, wp_json_encode( array( 'type' => 'tool', 'content' => $cred_secret, 'tool' => 'bhranti_inspect', 'args' => '{"resource":"logs"}' ) ) );
	cred_check( is_wp_error( $cred_result ) && 'bhranti_credentials' === $cred_result->get_error_code(), 'Prepared public intent cannot contain fictional ' . $cred_name . ' credentials.' );
	$cred_result = $cred_parse->invoke( null, wp_json_encode( array( 'type' => 'tool', 'content' => 'Create the requested draft.', 'tool' => 'wp_request', 'args' => wp_json_encode( array( 'method' => 'POST', 'path' => '/wp/v2/pages', 'params' => array( 'content' => $cred_secret ) ) ) ) ) );
	cred_check( is_wp_error( $cred_result ) && 'bhranti_credentials' === $cred_result->get_error_code(), 'Model tool arguments cannot contain fictional ' . $cred_name . ' credentials.' );
}
$cred_result = $cred_parse->invoke( null, wp_json_encode( array( 'type' => 'reply', 'content' => 'Configure your connection in WordPress Settings → Connectors; keep API keys out of chat.', 'tool' => 'none', 'args' => '{}' ) ) );
cred_check( ! is_wp_error( $cred_result ) && 'reply' === $cred_result['type'], 'An ordinary safe answer about credential handling still parses.' );
$cred_result = $cred_parse->invoke( null, wp_json_encode( array( 'type' => 'tool', 'content' => 'Read your recent failed tasks.', 'tool' => 'bhranti_inspect', 'args' => '{"resource":"logs","status":"error"}' ) ) );
cred_check( ! is_wp_error( $cred_result ) && 'bhranti_inspect' === $cred_result['tool'], 'Ordinary log-inspection tool plans remain supported.' );

echo 'PASS ' . $GLOBALS['cred_checks'] . " offline credential boundary checks; no database, HTTP or real AI.\n";
