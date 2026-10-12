<?php
/** Offline reference-reader boundary test with native HTTP/HTML/REST code; no DB or network. */
define( 'ABSPATH', rtrim( getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Documents/WordPress/Plugins', '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/utf8.php';
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/functions.php';
require ABSPATH . WPINC . '/class-wp-error.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/kses.php';
require ABSPATH . WPINC . '/http.php';
require ABSPATH . WPINC . '/class-wp-http.php';
require ABSPATH . WPINC . '/rest-api.php';
foreach ( array( 'class-wp-html-attribute-token', 'class-wp-html-text-replacement', 'class-wp-html-span', 'class-wp-html-decoder', 'class-wp-html-tag-processor' ) as $class ) { require ABSPATH . WPINC . '/html-api/' . $class . '.php'; }
function __( $text, $domain = 'default' ) { return $text; }
function _n( $single, $plural, $count, $domain = 'default' ) { return 1 === $count ? $single : $plural; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_current_user_id() { return 7; }
function user_can( $id, $capability ) { return $GLOBALS['ref_deny'] !== $id; }
function get_post( $id ) { return (object) array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $GLOBALS['ref_owner'] ); }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['ref_messages']; }
function get_bloginfo( $key ) { return 'test'; }
function home_url( $path = '' ) { return 'https://bhranti.wp.local' . $path; }
final class Bhranti_Actions { public static function ensure_actor() { return 11; } }
final class Bhranti_Audit { public static function record( ...$args ) { $GLOBALS['ref_audits'][] = $args; return $GLOBALS['ref_log_fail'] ? false : count( $GLOBALS['ref_audits'] ); } }
require dirname( __DIR__ ) . '/includes/class-bhranti-context.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-references.php';
$GLOBALS['ref_owner'] = 7; $GLOBALS['ref_deny'] = 0; $GLOBALS['ref_log_fail'] = false; $GLOBALS['ref_audits'] = array(); $GLOBALS['ref_requests'] = array(); $checks = 0;
add_filter( 'pre_option_blog_charset', static function() { return 'UTF-8'; } );
$url = 'https://crafts.example.org/store/';
$GLOBALS['ref_messages'] = array( array( 'role' => 'user', 'content' => 'I like this design: ' . $url ) );
$GLOBALS['ref_response'] = array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'text/html; charset=UTF-8' ), 'body' => '<html><head><style>.shop{color:#b85e3b;font-family:serif}</style><script>private-script-marker</script></head><body><h1>Original crafts</h1><p>A warm collection.</p><input value="private-field-marker"><a href="https://crafts.example.org/shop?token=private-token-marker">Shop</a></body></html>' );
add_filter( 'pre_http_request', static function( $response, $args, $url ) { $GLOBALS['ref_requests'][] = array( $args, $url ); return $GLOBALS['ref_response']; }, 10, 3 );
function ref_check( $condition, $label ) { if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; }
function ref_read( $url ) { return Bhranti_References::execute( array( 'tool' => 'reference_page', 'args' => array( 'url' => $url ) ), 7, 1 ); }
$result = ref_read( $url );
ref_check( ! is_wp_error( $result ) && false === $result['data']['rendered_visual'], 'A supplied public URL returns structure, without pretending to render it.' );
ref_check( array( '#b85e3b' ) === $result['data']['color_hints'] && array( 'serif' ) === $result['data']['font_hints'], 'Bounded lexical color/font hints are retained as data.' );
ref_check( false === strpos( wp_json_encode( $result ), 'private-script-marker' ) && false === strpos( wp_json_encode( $result ), 'private-field-marker' ) && false === strpos( wp_json_encode( $result ), 'private-token-marker' ), 'Native context sanitization removes scripts, form values and link query secrets.' );
$args = $GLOBALS['ref_requests'][0][0];
ref_check( true === $args['reject_unsafe_urls'] && 15 === $args['timeout'] && 3 === $args['redirection'] && 524289 === $args['limit_response_size'] && array() === $args['cookies'], 'Actual WordPress safe HTTP receives bounded redirect/time/byte constraints and no cookies.' );
ref_check( false === strpos( $args['user-agent'], 'bhranti.wp.local' ), 'The native HTTP user agent does not disclose the local site URL.' );
ref_check( 7 === $GLOBALS['ref_audits'][0][4] && 11 === $GLOBALS['ref_audits'][0][5] && false === strpos( wp_json_encode( $GLOBALS['ref_audits'] ), $url ), 'Reference audit preserves both identities without storing page paths or content.' );
foreach ( array( 'http://crafts.example.org/store/', 'https://localhost/', 'https://bhranti.wp.local/', 'https://127.0.0.1/', 'https://169.254.169.254/', 'https://[::1]/', 'https://user:pass@crafts.example.org/store/', 'https://crafts.example.org:8443/store/', 'https://crafts.example.org/store/?token=private', 'https://other.example.org/' ) as $blocked ) {
    $before = count( $GLOBALS['ref_requests'] ); ref_check( is_wp_error( ref_read( $blocked ) ) && $before === count( $GLOBALS['ref_requests'] ), 'Unsafe or non-human-supplied URL is blocked before HTTP.' );
}
$GLOBALS['ref_messages'] = array( array( 'role' => 'assistant', 'content' => $url ) );
ref_check( is_wp_error( ref_read( $url ) ), 'Assistant/site content does not authorize reference fetching.' );
$GLOBALS['ref_messages'] = array_merge( array( array( 'role' => 'user', 'content' => $url ) ), array_fill( 0, 12, array( 'role' => 'assistant', 'content' => 'Later messages.' ) ) );
ref_check( is_wp_error( ref_read( $url ) ), 'Historical references beyond recent conversation are not silently reused.' );
$GLOBALS['ref_messages'] = array( array( 'role' => 'user', 'content' => $url ) );
foreach ( array( 7, 11 ) as $id ) { $GLOBALS['ref_deny'] = $id; ref_check( is_wp_error( ref_read( $url ) ), 'Either identity denial stops reference reads.' ); }
$GLOBALS['ref_deny'] = 0; $GLOBALS['ref_owner'] = 99; ref_check( is_wp_error( ref_read( $url ) ), 'Private conversation ownership is enforced.' ); $GLOBALS['ref_owner'] = 7;
$GLOBALS['ref_log_fail'] = true; $before = count( $GLOBALS['ref_requests'] ); ref_check( is_wp_error( ref_read( $url ) ) && $before === count( $GLOBALS['ref_requests'] ), 'Audit persistence failure prevents outbound work.' ); $GLOBALS['ref_log_fail'] = false;
$good = $GLOBALS['ref_response'];
foreach ( array( new WP_Error( 'fixture-network', 'No actual network' ), array( 'response' => array( 'code' => 403 ) ), array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'application/json' ), 'body' => '{}' ), array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'text/html' ), 'body' => str_repeat( 'x', 524290 ) ) ) as $invalid ) { $GLOBALS['ref_response'] = $invalid; ref_check( is_wp_error( ref_read( $url ) ), 'Invalid remote responses produce a safe image-reference fallback.' ); }
$GLOBALS['ref_response'] = $good; $GLOBALS['ref_response']['body'] = '<p>Useful opening</p>' . str_repeat( 'x', 524289 - strlen( '<p>Useful opening</p>' ) );
$bounded = ref_read( $url ); ref_check( ! is_wp_error( $bounded ) && true === $bounded['data']['truncated'] && strlen( $bounded['data']['page']['text'] ) <= 8000, 'Large modern pages retain useful bounded context and disclose truncation.' );
echo 'PASS ' . $checks . " reference checks with native HTTP/REST/HTML; no DB, network or AI.\n";
