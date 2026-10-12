<?php
/**
 * Offline sparse-read regression using native REST validation and field projection.
 *
 * No wp-load.php, database, HTTP, AI or live-site mutations. Routes, identities,
 * actor setup and audit storage are in-memory seams; REST parsing, dispatch,
 * schema validation and response filtering are the installed WordPress code.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/read-fields.php
 */
$core_root = getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Documents/WordPress/Plugins';
define( 'ABSPATH', rtrim( $core_root, '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_DEBUG', true );
require ABSPATH . WPINC . '/class-wp-error.php';
require ABSPATH . WPINC . '/class-wp-http-response.php';
require ABSPATH . WPINC . '/class-wp-list-util.php';
require ABSPATH . WPINC . '/rest-api/class-wp-rest-response.php';
require ABSPATH . WPINC . '/rest-api/class-wp-rest-request.php';
require ABSPATH . WPINC . '/rest-api/class-wp-rest-server.php';
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/rest-api.php';

$GLOBALS['rf_checks'] = 0;
$GLOBALS['rf_user'] = 7;
$GLOBALS['rf_deny'] = 0;
$GLOBALS['rf_permissions'] = array();
$GLOBALS['rf_dispatches'] = 0;
$GLOBALS['rf_audits'] = array();
$GLOBALS['rf_options'] = array( 'bhranti_actor_id' => 11, 'bhranti_role_owned' => true );
$GLOBALS['rf_record'] = array( 'id' => 9033, 'title' => array( 'raw' => 'Handicrafts shop', 'rendered' => 'Handicrafts shop' ), 'status' => 'draft', 'type' => 'page', 'content' => array( 'raw' => 'Existing saved blocks', 'rendered' => '<p>Existing saved blocks</p>' ), 'meta' => array( 'private' => 'never expose' ), 'password' => 'never expose', 'provider_secret' => 'never expose' );
function __( $text, $domain = 'default' ) { return $text; }
function _n( $single, $plural, $count, $domain = 'default' ) { return 1 === $count ? $single : $plural; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( $number, $decimals ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
function wp_parse_list( $value ) { return is_array( $value ) ? $value : preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY ); }
function wp_is_numeric_array( $value ) { return is_array( $value ) && array_keys( $value ) === array_keys( array_values( $value ) ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function wp_list_filter( $list, $args = array(), $operator = 'AND' ) { return (new WP_List_Util( $list ))->filter( $args, $operator ); }
function absint( $value ) { return abs( (int) $value ); }
function get_current_user_id() { return $GLOBALS['rf_user']; }
function get_current_blog_id() { return 1; }
function wp_set_current_user( $id ) { $GLOBALS['rf_user'] = $id; }
function current_user_can( $cap ) { return $GLOBALS['rf_deny'] !== get_current_user_id(); }
function user_can( $id, $cap ) { return $GLOBALS['rf_deny'] !== $id; }
function get_object_taxonomies( $type, $output = 'names' ) { return array(); }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }
function get_option( $key, $default = false ) { return $GLOBALS['rf_options'][ $key ] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = true ) { if ( isset( $GLOBALS['rf_options'][ $key ] ) ) { return false; } $GLOBALS['rf_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['rf_options'][ $key ] ); return true; }
function get_userdata( $id ) { return (object) array( 'ID' => $id, 'roles' => array( 'bhranti_agent' ), 'caps' => array( 'bhranti_agent' => true ), 'user_email' => 'fixture@example.invalid' ); }
function get_user_meta( $id, $key, $single = false ) { return 11 === $id && 'bhranti_managed_actor' === $key ? 1 : ''; }
function get_role( $name ) { return (object) array( 'capabilities' => array_fill_keys( array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'edit_private_pages', 'publish_pages', 'read_private_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages', 'manage_categories', 'moderate_comments', 'upload_files', 'edit_theme_options', 'manage_options', 'list_users' ), true ) ); }
function get_post( $id ) { return (object) array( 'ID' => 1, 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => 7 ); }
function get_edit_post_link( $id, $context = 'display' ) { return 'https://bhranti.wp.local/wp-admin/post.php?post=' . $id . '&action=edit'; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function esc_url_raw( $value ) { return $value; }
function _doing_it_wrong( $function, $message, $version ) { throw new RuntimeException( $function . ': ' . $message ); }
function wp_remote_request() { throw new RuntimeException( 'HTTP forbidden in offline test.' ); }
function wp_ai_client_prompt() { throw new RuntimeException( 'AI forbidden in offline test.' ); }

final class Bhranti_Settings { public static function task_catalog() { return array(); } }
final class Bhranti_Commerce { public static function catalog() { return array(); } }
final class Bhranti_Site { public static function is_theme_request( $request ) { return false; } }
final class Bhranti_Audit { public static function record( ...$args ) { $GLOBALS['rf_audits'][] = $args; return count( $GLOBALS['rf_audits'] ); } }
require dirname( __DIR__ ) . '/includes/class-bhranti-references.php';
/** Controller seam: production names allow the actual Actions reviewer to inspect it. */
class WP_REST_Posts_Controller {
    public function get_item_schema() { return array( 'title' => 'page', 'properties' => array_fill_keys( array( 'title', 'status', 'content' ), array() ) ); }
    public function permission( $request ) { $GLOBALS['rf_permissions'][] = array( get_current_user_id(), $request->get_params() ); return current_user_can( 'edit_pages' ); }
    public function get_items( $request ) { ++$GLOBALS['rf_dispatches']; return new WP_REST_Response( array( $GLOBALS['rf_record'] ) ); }
    public function get_item( $request ) { ++$GLOBALS['rf_dispatches']; return new WP_REST_Response( $GLOBALS['rf_record'] ); }
    public function update_item( $request ) { throw new RuntimeException( 'A write reached dispatch.' ); }
    public function delete_item( $request ) { throw new RuntimeException( 'A delete reached dispatch.' ); }
}
require dirname( __DIR__ ) . '/includes/class-bhranti-actions.php';
$GLOBALS['wp_rest_server'] = new WP_REST_Server();
$controller = new WP_REST_Posts_Controller();
$read_args = array( 'context' => array( 'type' => 'string', 'enum' => array( 'edit', 'view' ), 'default' => 'view' ), 'include' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ), 'status' => array( 'type' => 'string' ), 'per_page' => array( 'type' => 'integer' ) );
foreach ( array( '/wp/v2/pages' => 'get_items', '/wp/v2/pages/(?P<id>[\d]+)' => 'get_item' ) as $path => $callback ) {
    $endpoints = array( array( 'methods' => 'GET', 'callback' => array( $controller, $callback ), 'permission_callback' => array( $controller, 'permission' ), 'args' => $read_args ) );
    if ( 'get_item' === $callback ) {
        foreach ( array( 'POST' => 'update_item', 'DELETE' => 'delete_item' ) as $method => $write_callback ) { $endpoints[] = array( 'methods' => $method, 'callback' => array( $controller, $write_callback ), 'permission_callback' => array( $controller, 'permission' ), 'args' => array( 'title' => array( 'type' => 'string' ) ) ); }
    }
    rest_get_server()->register_route( 'wp/v2', $path, $endpoints );
}
function rf_check( $condition, $label ) { if ( ! $condition ) { throw new RuntimeException( $label ); } ++$GLOBALS['rf_checks']; }
function rf_command( $params, $method = 'GET', $path = '/wp/v2/pages' ) { return array( 'tool' => 'wp_request', 'args' => array( 'method' => $method, 'path' => $path, 'params' => $params ) ); }
function rf_run( $params, $method = 'GET', $path = '/wp/v2/pages' ) { return Bhranti_Actions::execute( rf_command( $params, $method, $path ), 7, 1 ); }
function rf_review( $params, $method = 'GET' ) { return (new ReflectionMethod( Bhranti_Actions::class, 'review' ))->invoke( null, rf_command( $params, $method, '/wp/v2/pages/9033' ), 7, 11 ); }

// Exact request observed in the real store flow must be accepted unchanged.
$params = array( 'context' => 'edit', 'include' => array( 9033, 9034, 9035, 9036, 9037 ), 'status' => 'any', 'per_page' => 20, '_fields' => 'id,title,status' );
$result = rf_run( $params );
if ( is_wp_error( $result ) ) { echo $result->get_error_code() . ': ' . $result->get_error_message() . "\n"; }
rf_check( is_array( $result ), 'Observed sparse collection GET must succeed.' );
rf_check( array( 'id' => 9033, 'title' => $GLOBALS['rf_record']['title'], 'status' => 'draft' ) === $result['data'][0], 'Only requested reviewed fields returned.' );
rf_check( '' === $result['action_id'] && false === $result['rollback_available'], 'GET has no mutation or rollback identifier.' );
rf_check( array( 7, 11, 11 ) === array_column( $GLOBALS['rf_permissions'], 0 ), 'Human and actor checks plus native actor dispatch remain intact.' );
foreach ( $GLOBALS['rf_permissions'] as $permission ) { rf_check( ! isset( $permission[1]['_fields'] ) && 'edit' === $permission[1]['context'], 'Sparse fields cannot change native read permission inputs.' ); }
rf_check( 7 === get_current_user_id(), 'Dispatch restores requesting identity.' );
$full = rf_run( array( 'context' => 'edit' ), 'GET', '/wp/v2/pages/9033' );
foreach ( array( 'title.raw', array( 'title.raw' ), 'content.raw,title.raw', 'title,title.raw', 'id,meta,password,provider_secret,unknown' ) as $fields ) {
    $result = rf_run( array( 'context' => 'edit', '_fields' => $fields ), 'GET', '/wp/v2/pages/9033' );
    rf_check( is_array( $result ), 'Sparse item GET succeeds.' );
    $request = new WP_REST_Request( 'GET' ); $request->set_query_params( array( '_fields' => $fields ) );
    $expected = rest_filter_response_fields( new WP_REST_Response( $full['data'] ), rest_get_server(), $request )->get_data();
    rf_check( $expected === $result['data'], 'Nested projection matches native WordPress over already reviewed data.' );
    rf_check( $full['target'] === $result['target'] && 9033 === $result['target']['id'], 'Sparse item data preserves complete navigation and audit target.' );
    rf_check( ! array_intersect_key( $result['data'], array_flip( array( 'meta', 'password', 'provider_secret' ) ) ), 'Field projection cannot expose discarded sensitive fields.' );
}
foreach ( array( null, true, 7, array(), '', ' ', array( array( 'id' ) ), array( 'nested' => 'id' ), 'title..raw', str_repeat( 'a', 129 ), array_fill( 0, 51, 'id' ) ) as $invalid ) {
    $before = $GLOBALS['rf_dispatches']; $result = rf_run( array( '_fields' => $invalid ) );
    rf_check( is_wp_error( $result ) && 'bhranti_action_invalid' === $result->get_error_code(), 'Malformed or oversized field list fails validation.' );
    rf_check( $before === $GLOBALS['rf_dispatches'], 'Invalid field list never dispatches.' );
}
foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) { $result = rf_review( array( '_fields' => 'id', 'title' => 'No write' ), $method ); rf_check( is_wp_error( $result ) && 'bhranti_action_blocked' === $result->get_error_code(), 'Writes cannot use _fields: ' . $method ); }
foreach ( array( array( '_embed' => true, '_fields' => 'id' ), array( 'meta' => array(), '_fields' => 'id' ), array( 'unknown' => 'x', '_fields' => 'id' ), array( 'per_page' => 21, '_fields' => 'id' ) ) as $invalid ) { $before = $GLOBALS['rf_dispatches']; $result = rf_run( $invalid ); rf_check( is_wp_error( $result ) && $before === $GLOBALS['rf_dispatches'], 'Other field and collection restrictions remain authoritative.' ); }
foreach ( array( 7, 11 ) as $denied ) { $GLOBALS['rf_deny'] = $denied; $before = $GLOBALS['rf_dispatches']; $result = rf_review( array( 'context' => 'edit', '_fields' => 'id' ) ); rf_check( is_wp_error( $result ) && 'bhranti_action_permission' === $result->get_error_code() && $before === $GLOBALS['rf_dispatches'], 'Sparse GET cannot bypass either identity permission.' ); rf_check( 7 === get_current_user_id(), 'Permission refusal restores identity.' ); }
$GLOBALS['rf_deny'] = 0;
rf_check( $full['data'] === rf_run( array( 'context' => 'edit' ), 'GET', '/wp/v2/pages/9033' )['data'], 'Ordinary full reads remain unchanged.' );
$recovery_read = (new ReflectionMethod( Bhranti_Actions::class, 'read_item' ))->invoke( null, '/wp/v2/pages/9033', 7, 11 );
rf_check( $GLOBALS['rf_record'] === $recovery_read, 'Internal before-write and recovery reads retain complete native records.' );
rf_check( ! isset( $GLOBALS['rf_options']['bhranti_actor_setup_lock'] ), 'Fixture actor lock released.' );
echo 'PASS ' . $GLOBALS['rf_checks'] . " offline native REST sparse-read checks; no DB, HTTP or AI.\n";
