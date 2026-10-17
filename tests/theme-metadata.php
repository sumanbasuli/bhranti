<?php
/**
 * Offline theme/global-style metadata regression using native REST controllers
 * and the installed WordPress resolver. Theme objects, queries, identities and
 * post persistence are memory seams. No wp-load.php, DB, HTTP or AI is used.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/theme-metadata.php
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

$GLOBALS['tm_checks'] = 0;
$GLOBALS['rf_user'] = 7;
$GLOBALS['rf_deny'] = 0;
$GLOBALS['tm_deny_style'] = 0;
$GLOBALS['rf_options'] = array( 'bhranti_actor_id' => 11, 'bhranti_role_owned' => true );
$GLOBALS['tm_permissions'] = array();
$GLOBALS['tm_posts'] = array();
$GLOBALS['tm_records'] = array();
$GLOBALS['tm_queries'] = array();
$GLOBALS['tm_inserts'] = 0;
$GLOBALS['tm_dispatched'] = 0;
$GLOBALS['tm_additional'] = 0;
$GLOBALS['tm_audits'] = array();
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
function current_user_can( $cap, ...$args ) {
    $id = get_current_user_id();
    $GLOBALS['tm_permissions'][] = array( $id, $cap );
    if ( 'read_post' === $cap ) { return $GLOBALS['tm_deny_style'] !== $id; }
    if ( $GLOBALS['rf_deny'] === $id ) { return false; }
    if ( 'manage_network_themes' === $cap ) { return false; }
    if ( 'switch_themes' === $cap && 11 === $id ) {
        $caps = apply_filters( 'user_has_cap', array(), array( $cap ), array( $cap ), (object) array( 'ID' => $id ) );
        return ! empty( $caps[ $cap ] );
    }
    return true;
}
function user_can( $id, $cap ) { return 'manage_options' === $cap || $GLOBALS['rf_deny'] !== $id; }
function get_object_taxonomies( $type, $output = 'names' ) { return array(); }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }
function get_option( $key, $default = false ) { return $GLOBALS['rf_options'][ $key ] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = true ) { if ( isset( $GLOBALS['rf_options'][ $key ] ) ) { return false; } $GLOBALS['rf_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['rf_options'][ $key ] ); return true; }
function get_userdata( $id ) { return (object) array( 'ID' => $id, 'roles' => array( 'bhranti_agent' ), 'caps' => array( 'bhranti_agent' => true ), 'user_email' => 'fixture@example.invalid' ); }
function get_user_meta( $id, $key, $single = false ) { return 11 === $id && 'bhranti_managed_actor' === $key ? 1 : ''; }
function get_role( $name ) { return (object) array( 'capabilities' => array_fill_keys( array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'edit_private_pages', 'publish_pages', 'read_private_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages', 'manage_categories', 'moderate_comments', 'upload_files', 'edit_theme_options', 'manage_options', 'list_users' ), true ) ); }
function get_post( $id ) { return 1 === (int) $id ? (object) array( 'ID' => 1, 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => 7 ) : ( $GLOBALS['tm_posts'][ $id ] ?? null ); }
function get_edit_post_link( $id, $context = 'display' ) { return 'https://bhranti.wp.local/wp-admin/post.php?post=' . $id . '&action=edit'; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function esc_url_raw( $value ) { return $value; }
function _doing_it_wrong( $function, $message, $version ) { throw new RuntimeException( $function . ': ' . $message ); }
function wp_remote_request() { throw new RuntimeException( 'HTTP forbidden in offline test.' ); }
function wp_ai_client_prompt() { throw new RuntimeException( 'AI forbidden in offline test.' ); }

function wp_cache_delete( ...$args ) { return true; }
function clean_user_cache( $id ) {}
function get_registered_theme_features() { return array(); }
function get_post_types( $args = array(), $output = 'names' ) { return array(); }
function is_user_logged_in() { return true; }
function is_multisite() { return false; }
function get_home_url( ...$args ) { return 'https://bhranti.wp.local/'; }
function is_ssl() { return false; }
function is_admin() { return false; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
function wp_get_theme( $stylesheet = null ) { return $GLOBALS['tm_themes'][ $stylesheet ?: 'active-theme' ] ?? tm_theme( 'missing', false ); }
function wp_get_themes() { return $GLOBALS['tm_themes']; }
function get_posts( $args ) {
    $query = new WP_Query();
    if ( isset( $GLOBALS['tm_query_override'] ) ) { $GLOBALS['tm_queries'][] = $args; return $GLOBALS['tm_query_override']; }
    return array_map( static fn( $post ) => $post->ID, $query->query( $args ) );
}
function wp_get_object_terms( $id, $taxonomy, $args ) { return array_keys( $GLOBALS['tm_records'], $id, true ); }
function wptexturize( $value ) { return $value; }
function get_post_type_object( $type ) { return 'wp_global_styles' === $type ? $GLOBALS['tm_type'] : null; }
function wp_insert_post( $post, $error = false ) {
    ++$GLOBALS['tm_inserts'];
    $id = 8000 + $GLOBALS['tm_inserts'];
    $GLOBALS['tm_posts'][ $id ] = new WP_Post( (object) ( array( 'ID' => $id ) + $post ) );
    $GLOBALS['tm_records'][ $post['tax_input']['wp_theme'][0] ] = $id;
    return $id;
}

require ABSPATH . WPINC . '/class-wp-post.php';
require ABSPATH . WPINC . '/class-wp-theme.php';
require ABSPATH . WPINC . '/class-wp-theme-json.php';
require ABSPATH . WPINC . '/class-wp-theme-json-resolver.php';
require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-controller.php';
require ABSPATH . WPINC . '/rest-api/fields/class-wp-rest-meta-fields.php';
require ABSPATH . WPINC . '/rest-api/fields/class-wp-rest-post-meta-fields.php';
require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-posts-controller.php';
require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-global-styles-controller.php';
require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-themes-controller.php';

/** Native theme instances get memory-only headers to avoid filesystem/cache writes. */
function tm_theme( $name, $exists = true ) {
    $reflection = new ReflectionClass( WP_Theme::class );
    $theme = $reflection->newInstanceWithoutConstructor();
    $headers = array( 'Name' => $name, 'Version' => '1.0', 'RequiresWP' => '6.0', 'RequiresPHP' => '8.0', 'TextDomain' => '' );
    foreach ( array( 'stylesheet' => $name, 'template' => $name, 'headers' => $headers, 'headers_sanitized' => $headers, 'textdomain_loaded' => false, 'block_theme' => true, 'errors' => $exists ? null : new WP_Error( 'theme_not_found' ) ) as $property => $value ) {
        $reflection->getProperty( $property )->setValue( $theme, $value );
    }
    return $theme;
}
/** Database seam: the real resolver constructs and bounds this query. */
class WP_Query {
    public function query( $args ) {
        $GLOBALS['tm_queries'][] = $args;
        $id = $GLOBALS['tm_records'][ $args['tax_query'][0]['terms'] ] ?? 0;
        return $id ? array( $GLOBALS['tm_posts'][ $id ] ) : array();
    }
}
class TM_Post_Type {
    public $name = 'wp_global_styles';
    public $rest_base = 'global-styles';
    public $rest_namespace = 'wp/v2';
    public $custom_controller = null;
    public function get_rest_controller() { return $this->custom_controller ?: new WP_REST_Global_Styles_Controller(); }
}
final class Bhranti_Settings { public static function task_catalog() { return array(); } }
final class Bhranti_Commerce { public static function catalog() { return array(); } }
final class Bhranti_Audit { public static function record( ...$args ) { $GLOBALS['tm_audits'][] = $args; return count( $GLOBALS['tm_audits'] ); } }
require dirname( __DIR__ ) . '/includes/class-bhranti-credentials.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-references.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-site.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-actions.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-skills.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-knowledge.php';
$GLOBALS['tm_type'] = new TM_Post_Type();
$GLOBALS['tm_themes'] = array( 'active-theme' => tm_theme( 'active-theme' ), 'other-theme' => tm_theme( 'other-theme' ) );
$GLOBALS['wp_rest_server'] = new WP_REST_Server();
$controller = new WP_REST_Themes_Controller();
do_action( 'rest_api_init' );
$controller->register_routes();
register_rest_field( 'theme', 'private_extra', array( 'get_callback' => static function() { ++$GLOBALS['tm_additional']; return 'private-marker'; }, 'schema' => array( 'type' => 'string' ) ) );
add_filter( 'rest_request_before_callbacks', static function( $response, $handler, $request ) {
    if ( 0 === strpos( $request->get_route(), '/wp/v2/themes' ) ) { ++$GLOBALS['tm_dispatched']; }
    return $response;
}, 10, 3 );

function tm_check( $condition, $label ) { if ( ! $condition ) { throw new RuntimeException( $label ); } ++$GLOBALS['tm_checks']; }
function tm_run( $params = array(), $path = '/wp/v2/themes', $method = 'GET' ) {
    return Bhranti_Actions::execute( array( 'tool' => 'wp_request', 'args' => array( 'method' => $method, 'path' => $path, 'params' => $params ) ), 7, 1 );
}

$empty = tm_run( array( 'status' => array( 'active' ) ) );
if ( is_wp_error( $empty ) ) { throw new RuntimeException( $empty->get_error_code() . ': ' . $empty->get_error_message() ); }
tm_check( 1 === count( $empty['data'] ) && 'active-theme' === $empty['data'][0]['stylesheet'], 'Native active-theme filter is preserved.' );
tm_check( array_key_exists( 'global_styles_id', $empty['data'][0] ) && null === $empty['data'][0]['global_styles_id'], 'Fresh theme returns explicit null, not a fabricated or newly created styles ID.' );
tm_check( array( 'global_styles_id' => null ) === tm_run( array( '_fields' => 'global_styles_id' ), '/wp/v2/themes/active-theme' )['data'], 'Sparse projection retains an explicitly absent global-style ID.' );
tm_check( 0 === $GLOBALS['tm_inserts'] && array() === $GLOBALS['tm_posts'], 'Native theme metadata read never initializes a styles post.' );
tm_check( 0 === $GLOBALS['tm_additional'] && false === strpos( wp_json_encode( $empty ), 'private-marker' ), 'Native field allowlist omits unreviewed additional callbacks/data.' );
tm_check( 7 === get_current_user_id(), 'Requester identity is restored after native dispatch.' );
wp_set_current_user( 11 );
tm_check( ! current_user_can( 'switch_themes' ), 'Temporary theme-read capability does not survive dispatch.' );
wp_set_current_user( 7 );
tm_check( false === $empty['rollback_available'] && '' === $empty['action_id'], 'Discovery remains a read, with no mutation/rollback identifier.' );

$GLOBALS['tm_posts'][7001] = new WP_Post( (object) array( 'ID' => 7001, 'post_type' => 'wp_global_styles', 'post_status' => 'publish', 'post_content' => 'private-global-style-content' ) );
$GLOBALS['tm_records']['active-theme'] = 7001;
$GLOBALS['tm_permissions'] = array();
$item = tm_run( array(), '/wp/v2/themes/active-theme' );
tm_check( 7001 === $item['data']['global_styles_id'], 'Existing native global-style record is discoverable by exact theme read.' );
$style_permissions = array_values( array_filter( $GLOBALS['tm_permissions'], static fn( $entry ) => 'read_post' === $entry[1] ) );
tm_check( array( 7, 11 ) === array_column( $style_permissions, 0 ), 'Both identities pass the actual native global-style item read permission callback.' );
tm_check( false === strpos( wp_json_encode( $item ), 'private-global-style-content' ), 'Discovery exposes an ID, not private style content or full post data.' );
$projected = tm_run( array( '_fields' => 'stylesheet,global_styles_id,_links,private_extra' ), '/wp/v2/themes/active-theme' );
tm_check( array( 'stylesheet' => 'active-theme', 'global_styles_id' => 7001 ) === $projected['data'], 'Tool projection includes the new field while excluding links and unreviewed fields.' );
tm_check( array( 'name' => $item['data']['name'] ) === tm_run( array( '_fields' => 'name' ), '/wp/v2/themes/active-theme' )['data'], 'Tool projection can omit global-style metadata entirely.' );
$list = tm_run();
tm_check( 7001 === $list['data'][0]['global_styles_id'] && null === $list['data'][1]['global_styles_id'], 'Collection metadata keeps existing and absent theme records distinct.' );
foreach ( $GLOBALS['tm_queries'] as $query ) {
    tm_check( 1 === $query['posts_per_page'] && 'ids' === $query['fields'] && 'wp_global_styles' === $query['post_type'] && array( 'publish' ) === $query['post_status'] && 'wp_theme' === $query['tax_query'][0]['taxonomy'] && 'name' === $query['tax_query'][0]['field'] && true === $query['no_found_rows'], 'Public query mirrors Core’s bounded published, theme-scoped discovery and requests only IDs.' );
}

$GLOBALS['tm_posts'][7002] = new WP_Post( (object) array( 'ID' => 7002, 'post_type' => 'wp_global_styles', 'post_status' => 'publish' ) );
$GLOBALS['tm_records']['other-theme'] = 7002;
$GLOBALS['tm_query_override'] = array( 7002 );
tm_check( null === tm_run( array(), '/wp/v2/themes/active-theme' )['data']['global_styles_id'], 'A filtered query cannot substitute another theme’s styles record.' );
unset( $GLOBALS['tm_query_override'] );
$GLOBALS['tm_posts'][7001]->post_type = 'page';
tm_check( null === tm_run( array(), '/wp/v2/themes/active-theme' )['data']['global_styles_id'], 'A wrong post type cannot become a styles ID.' );
$GLOBALS['tm_posts'][7001]->post_type = 'wp_global_styles';
$GLOBALS['tm_posts'][7001]->post_status = 'draft';
tm_check( null === tm_run( array(), '/wp/v2/themes/active-theme' )['data']['global_styles_id'], 'Unpublished records are not substituted for Core’s active published styles.' );
$GLOBALS['tm_posts'][7001]->post_status = 'publish';

foreach ( array( 7, 11 ) as $denied ) {
    $GLOBALS['tm_deny_style'] = $denied;
    $result = tm_run( array(), '/wp/v2/themes/active-theme' );
    tm_check( is_array( $result ) && null === $result['data']['global_styles_id'], 'Denied global-style permission hides the ID without losing permitted theme metadata.' );
    tm_check( 7 === get_current_user_id(), 'Denied style lookup restores identity.' );
}
$GLOBALS['tm_deny_style'] = 0;
foreach ( array( 7, 11 ) as $denied ) {
    $GLOBALS['rf_deny'] = $denied;
    $queries = count( $GLOBALS['tm_queries'] ); $dispatches = $GLOBALS['tm_dispatched'];
    $result = tm_run( array(), '/wp/v2/themes/active-theme' );
    tm_check( is_wp_error( $result ) && 'bhranti_action_permission' === $result->get_error_code(), 'Native theme permission for either identity remains authoritative.' );
    tm_check( $queries === count( $GLOBALS['tm_queries'] ) && $dispatches === $GLOBALS['tm_dispatched'], 'Denied theme reads never dispatch or discover style IDs.' );
    tm_check( 7 === get_current_user_id(), 'Denied theme read restores identity.' );
}
$GLOBALS['rf_deny'] = 0;
$GLOBALS['tm_type']->custom_controller = new stdClass();
tm_check( null === tm_run( array(), '/wp/v2/themes/active-theme' )['data']['global_styles_id'], 'A replaced noncore styles controller cannot authorize ID disclosure.' );
$GLOBALS['tm_type']->custom_controller = null;
foreach ( array( array( '_embed' => true ), array( 'global_styles_id' => 7001 ), array( 'per_page' => 20 ) ) as $params ) {
    $result = tm_run( $params );
    tm_check( is_wp_error( $result ) && 'bhranti_action_blocked' === $result->get_error_code(), 'Theme argument allowlist is unchanged.' );
}
foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
    $result = tm_run( array(), '/wp/v2/themes/active-theme', $method );
    tm_check( is_wp_error( $result ) && 'bhranti_action_blocked' === $result->get_error_code(), 'Theme writes remain unavailable.' );
}
tm_check( is_wp_error( tm_run( array(), '/wp/v2/global-styles' ) ), 'No invented global-styles collection route is introduced.' );
tm_check( 0 === $GLOBALS['tm_inserts'] && 0 === $GLOBALS['tm_additional'], 'All Bhranti reads keep the native creation/additional-field counters at zero.' );
tm_check( false === strpos( wp_json_encode( $GLOBALS['tm_audits'] ), 'private-global-style-content' ), 'Audit contains no private style content.' );
tm_check( str_contains( Bhranti_Actions::catalog()['wp_request']['description'], '/wp/v2/global-styles/{global_styles_id}' ) && str_contains( Bhranti_Knowledge::instructions(), 'There is no GET /wp/v2/global-styles collection' ), 'Catalog and provider-neutral recipe name exact discovery/read routes.' );

// Positive control: the actual native controller/resolver create on _links when absent.
unset( $GLOBALS['tm_records']['active-theme'] );
$native = new WP_REST_Request( 'GET', '/wp/v2/themes/active-theme' );
$native->set_url_params( array( 'stylesheet' => 'active-theme' ) );
$native->set_query_params( array( '_fields' => 'stylesheet,_links' ) );
$controller->get_item( $native );
tm_check( 1 === $GLOBALS['tm_inserts'], 'Positive control proves native active-theme link generation takes the creation branch.' );
$native_query = end( $GLOBALS['tm_queries'] );
$public_query = $GLOBALS['tm_queries'][0];
foreach ( array( 'post_type', 'post_status', 'posts_per_page', 'orderby', 'no_found_rows', 'ignore_sticky_posts', 'update_post_meta_cache', 'update_post_term_cache', 'tax_query' ) as $key ) {
    tm_check( $native_query[ $key ] === $public_query[ $key ], 'Public discovery matches actual Core record identity/query bound: ' . $key );
}
tm_check( strtoupper( $native_query['order'] ) === $public_query['order'], 'Public lookup uses Core’s newest-record order.' );
echo 'PASS ' . $GLOBALS['tm_checks'] . " native theme/global-style metadata checks; no DB, HTTP or AI.\n";
