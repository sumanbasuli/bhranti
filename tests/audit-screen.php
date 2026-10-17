<?php
/** Isolated native activity screen/export checks; never loads a live site. */
$als_root = realpath( getenv( 'BHRANTI_AUDIT_SCREEN_WP_ROOT' ) ?: '' );
if ( '/private/tmp/bhranti-inspect-qa-20261014' !== $als_root ) { throw new RuntimeException( 'Use the independent inspector QA runtime.' ); }
define( 'WP_USE_THEMES', false );
require $als_root . '/wp-load.php';
if ( 'https://bhranti-checker.invalid' !== get_option( 'home' ) || get_option( 'active_plugins' ) || ! DISABLE_WP_CRON ) { throw new RuntimeException( 'Offline disposable runtime required.' ); }
require dirname( __DIR__ ) . '/bhranti.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
Bhranti_Workspace::register_storage(); Bhranti_Audit::register_storage();
$als_ids = array(); $als_owner = 0; $als_session = 0; $als_checks = 0; $als_before_user = get_current_user_id(); $als_before_get = $_GET; $als_before_request = $_REQUEST; $als_before_uri = $_SERVER['REQUEST_URI'] ?? null;
$als_marker = 'bhranti-log-screen-' . wp_generate_uuid4(); $als_http = 0;
add_filter( 'pre_http_request', static function () { ++$GLOBALS['als_http']; return new WP_Error( 'offline', 'Offline fixture.' ); } );
add_filter( 'pre_wp_mail', '__return_false' );
function als_check( $condition, $label ) { if ( ! $condition ) { throw new RuntimeException( $label ); } ++$GLOBALS['als_checks']; }
function als_event( $message = 'Reading a saved page.' ) {
	$id = Bhranti_Audit::record( 'action_completed', $message, $GLOBALS['als_session'], array( 'method' => 'GET', 'path' => '/wp/v2/pages/98765', 'run_id' => $GLOBALS['als_marker'] ), $GLOBALS['als_owner'], $GLOBALS['als_owner'] );
	if ( is_int( $id ) ) { $GLOBALS['als_ids'][] = $id; }
	als_check( is_int( $id ), 'Fixture event saved.' ); return $id;
}
$als_die = static function () { return static function ( $message, $title = '', $args = array() ) { throw new RuntimeException( 'expected-denial' ); }; };
try {
	$als_owner = wp_insert_user( array( 'user_login' => $als_marker, 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
	als_check( is_int( $als_owner ), 'Fresh administrator fixture.' ); wp_set_current_user( $als_owner );
	$als_session = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $als_owner, 'post_title' => $als_marker ), true );
	als_check( is_int( $als_session ), 'Fresh conversation fixture.' );
	for ( $index = 0; $index < 125; ++$index ) { als_event(); }
	$als_filters = array( 'session_id' => $als_session );
	$als_lines = array(); $als_added = false;
	$als_count = Bhranti_Log_Screen::stream( $als_filters, static function ( $line ) use ( &$als_lines, &$als_added ) {
		$als_lines[] = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
		if ( ! $als_added ) { $als_added = true; als_event( 'A new event during export.' ); }
	} );
	als_check( 125 === $als_count && 125 === count( $als_lines ), 'Export crosses pages and excludes concurrent new events.' );
	als_check( 125 === count( array_unique( array_column( $als_lines, 'event_id' ) ) ) || 125 === count( array_unique( array_column( $als_lines, 'id' ) ) ), 'Export does not duplicate records.' );
	als_check( is_wp_error( Bhranti_Log_Screen::filters( array( 'search' => array( 'bad' ) ) ) ), 'Array input rejected.' );
	als_check( is_wp_error( Bhranti_Log_Screen::filters( array( 'per_page' => '99999' ) ) ), 'Unbounded page size rejected.' );
	als_check( is_wp_error( Bhranti_Log_Screen::filters( array( 'log_page' => '-3' ) ) ), 'Negative page rejected.' );
	als_check( is_wp_error( Bhranti_Log_Screen::filters( array( 'search' => str_repeat( 'x', 201 ) ) ) ), 'Search bound enforced.' );
	als_check( 2 === Bhranti_Log_Screen::filters( array( 'log_page' => '2' ) )['page'], 'Native screen page parameter accepted.' );
	$_GET = array( 'session_id' => (string) $als_session, 'log_page' => '2', 'per_page' => '50' );
	$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=bhranti-logs&session_id=' . $als_session . '&log_page=2&per_page=50';
	ob_start(); Bhranti_Log_Screen::render(); $als_html = ob_get_clean();
	als_check( false !== strpos( $als_html, '51–100 of 126 events' ), 'Pagination count covers all matching events.' );
	als_check( false !== strpos( $als_html, 'aria-current="page"' ) && false !== strpos( $als_html, 'log_page=3' ), 'Numbered navigation keeps current and next pages.' );
	preg_match_all( '/<nav aria-label="Activity pages[^"]*">(.*?)<\/nav>/s', $als_html, $als_navs );
	preg_match_all( '/href="([^"]*)"/', implode( '', $als_navs[1] ), $als_page_urls );
	foreach ( $als_page_urls[1] as $als_page_url ) {
		$als_parts = wp_parse_url( html_entity_decode( $als_page_url, ENT_QUOTES, 'UTF-8' ) );
		parse_str( $als_parts['query'] ?? '', $als_page_query );
		als_check( empty( $als_parts['fragment'] ) && isset( $als_page_query['log_page'] ) && (int) $als_page_query['session_id'] === $als_session, 'Pagination keeps real query arguments without escaped fragments.' );
	}
	als_check( false !== strpos( $als_html, 'bhranti_export_logs' ) && false !== strpos( $als_html, '_wpnonce' ), 'Download form includes authenticated action and nonce.' );
	als_check( false !== strpos( $als_html, 'Conversation activity' ) && false !== strpos( $als_html, 'Task activity' ), 'Trace links available for owned task.' );
	als_check( false !== strpos( $als_html, 'Raw log</summary>' ) && false !== strpos( $als_html, '&quot;event_id&quot;' ), 'Summary has escaped raw evidence for each event.' );
	preg_match_all( '/href="([^"]+)"[^>]*>(Conversation activity|Task activity)<\/a>/', $als_html, $als_context_urls, PREG_SET_ORDER );
	als_check( count( $als_context_urls ) > 0, 'Related activity links are present.' );
	foreach ( $als_context_urls as $als_context_link ) {
		$als_context_parts = wp_parse_url( html_entity_decode( $als_context_link[1], ENT_QUOTES, 'UTF-8' ) );
		parse_str( $als_context_parts['query'] ?? '', $als_context_query );
		als_check( empty( $als_context_parts['fragment'] ) && 'raw' === ( $als_context_query['view'] ?? '' ) && '1' === ( $als_context_query['log_page'] ?? '' ) && ! isset( $als_context_query['search'] ) && ! isset( $als_context_query['status'] ), 'Related activity opens raw records from page one without unrelated filters.' );
	}
	$_GET = array( 'session_id' => (string) $als_session, 'view' => 'raw', 'log_page' => '2', 'per_page' => '50' );
	ob_start(); Bhranti_Log_Screen::render(); $als_raw_html = ob_get_clean();
	als_check( false !== strpos( $als_raw_html, 'Conversation #' . $als_session . ' activity' ) && false !== strpos( $als_raw_html, '51–100 of 126 events' ), 'Raw conversation has a clear scope and correct pagination.' );
	als_check( false !== strpos( $als_raw_html, 'aria-label="Raw activity records, newest first"' ) && false !== strpos( $als_raw_html, '&quot;format&quot;: &quot;bhranti.activity&quot;' ) && false === strpos( $als_raw_html, '<table' ), 'Raw view renders original escaped export records instead of summary rows.' );
	als_check( false !== strpos( $als_raw_html, 'name="view" value="raw"' ) && false !== strpos( $als_raw_html, 'view=raw' ), 'Filtering and navigation retain raw presentation.' );
	als_check( false === strpos( $als_raw_html, 'class="bhranti-log-more" open' ), 'Context alone does not push raw records below an expanded advanced form.' );
	$_GET = array( 'run_id' => $als_marker, 'view' => 'raw' );
	ob_start(); Bhranti_Log_Screen::render(); $als_task_html = ob_get_clean();
	als_check( preg_match( '/<h2>\s*Task activity\s*<\/h2>/', $als_task_html ) && false !== strpos( $als_task_html, '1–50 of 126 events' ), 'Task raw view identifies its exact scope and includes matching events.' );

	$_GET = array( 'session_id' => (string) $als_session, 'search' => 'No matching phrase at all' );
	ob_start(); Bhranti_Log_Screen::render(); $als_empty = ob_get_clean();
	als_check( false !== strpos( $als_empty, 'No matching activity' ) && false !== strpos( $als_empty, 'Reset' ), 'Empty search gives an actionable state.' );
	$_GET = array( 'from' => '2026-99-99' );
	ob_start(); Bhranti_Log_Screen::render(); $als_invalid = ob_get_clean();
	als_check( false !== strpos( $als_invalid, 'notice-error' ), 'Invalid date shown as a filter error.' );
	wp_set_current_user( 0 ); ob_start(); Bhranti_Log_Screen::render(); $als_denied = ob_get_clean();
	als_check( '' === $als_denied, 'Unauthorized screen reveals no activity.' );
	add_filter( 'wp_die_handler', $als_die );
	try { Bhranti_Log_Screen::download(); throw new RuntimeException( 'Download did not deny anonymous access.' ); } catch ( RuntimeException $error ) { als_check( 'expected-denial' === $error->getMessage(), 'Download requires administrator.' ); }
	wp_set_current_user( $als_owner ); $_GET = array(); $_REQUEST = array( '_wpnonce' => 'invalid' );
	try { Bhranti_Log_Screen::download(); throw new RuntimeException( 'Download did not deny invalid nonce.' ); } catch ( RuntimeException $error ) { als_check( 'expected-denial' === $error->getMessage(), 'Download requires a valid nonce.' ); }
	remove_filter( 'wp_die_handler', $als_die );
	als_check( 0 === $als_http, 'No HTTP or AI requests.' );
} finally {
	remove_filter( 'wp_die_handler', $als_die );
	wp_set_current_user( $als_before_user ); $_GET = $als_before_get; $_REQUEST = $als_before_request; if ( null === $als_before_uri ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $als_before_uri; }
	foreach ( $als_ids as $als_id ) { wp_delete_post( $als_id, true ); }
	if ( $als_session ) { wp_delete_post( $als_session, true ); }
	if ( $als_owner ) { wp_delete_user( $als_owner ); }
}
echo wp_json_encode( array( 'checks' => $als_checks, 'network_requests' => $als_http, 'fixtures_removed' => count( $als_ids ), 'status' => 'passed' ) ) . "\n";
