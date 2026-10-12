<?php
/**
 * Offline native block metadata read regression.
 * Reuses the memory identity/storage seams and native REST server from sparse
 * read tests, then registers the actual native block controller and registries.
 * No WordPress bootstrap, DB, HTTP, AI, script evaluation or content rendering.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/block-metadata.php
 */
require __DIR__ . '/read-fields.php';
require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-controller.php';
require ABSPATH . WPINC . '/class-wp-block-type.php';
require ABSPATH . WPINC . '/class-wp-block-type-registry.php';
require ABSPATH . WPINC . '/class-wp-block-styles-registry.php';
require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-block-types-controller.php';
$bm_start = $GLOBALS['rf_checks'];
$GLOBALS['bm_rendered'] = 0;
$GLOBALS['bm_variations'] = 0;
$GLOBALS['bm_additional'] = 0;
$GLOBALS['bm_dispatched'] = 0;
function _x( $text, $context, $domain = 'default' ) { return $text; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $value ) ); }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function get_post_types( $args = array(), $output = 'names' ) { return array(); }
function is_user_logged_in() { return true; }
function is_multisite() { return false; }
function get_home_url( ...$args ) { return 'https://bhranti.wp.local/'; }
function is_ssl() { return false; }
function is_admin() { return false; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
function wp_array_slice_assoc( $array, $keys ) { return array_intersect_key( $array, array_flip( $keys ) ); }

$registry = WP_Block_Type_Registry::get_instance();
$block = $registry->register( 'fixture/product-grid', array(
    'api_version' => 3,
    'title' => 'Products grid',
    'description' => 'Native metadata fixture.',
    'category' => 'widgets',
    'attributes' => array( 'columns' => array( 'type' => 'number', 'default' => 3 ), 'query' => array( 'type' => 'object', 'default' => array( 'perPage' => 6 ) ) ),
    'supports' => array( 'align' => array( 'wide', 'full' ), 'color' => array( 'background' => true ), 'html' => false ),
    'uses_context' => array( 'postId' ),
    'provides_context' => array( 'fixture/query' => 'query' ),
    'editor_script_handles' => array( 'private-editor-script-marker' ),
    'view_script_handles' => array( 'https://assets.example.invalid/private-view-script-marker.js' ),
    'style_handles' => array( 'private-style-marker' ),
    'render_callback' => static function() { ++$GLOBALS['bm_rendered']; return '<p>Never render this fixture.</p>'; },
    'variation_callback' => static function() { ++$GLOBALS['bm_variations']; return array( array( 'name' => 'private-variation-marker', 'attributes' => array( 'columns' => 2 ) ) ); },
) );
WP_Block_Styles_Registry::get_instance()->register( 'fixture/product-grid', array(
    'name' => 'bordered', 'label' => 'Bordered', 'is_default' => true,
    'inline_style' => '.grid { background: url(https://assets.example.invalid/private-style-url.png); }',
    'style_handle' => 'private-registered-style-marker',
) );
$registry->register( 'other/static', array( 'title' => 'Static fixture', 'category' => 'text', 'attributes' => array() ) );
register_rest_field( 'block-type', 'private_additional', array( 'get_callback' => static function() { ++$GLOBALS['bm_additional']; return 'private-additional-marker'; }, 'schema' => array( 'type' => 'string', 'context' => array( 'view', 'edit' ) ) ) );
$controller = new WP_REST_Block_Types_Controller();
do_action( 'rest_api_init' );
$controller->register_routes();
add_filter( 'rest_request_before_callbacks', static function( $response, $handler, $request ) {
    if ( 0 === strpos( $request->get_route(), '/wp/v2/block-types' ) ) { ++$GLOBALS['bm_dispatched']; }
    return $response;
}, 10, 3 );

$item = rf_run( array( 'context' => 'edit' ), 'GET', '/wp/v2/block-types/fixture/product-grid' );
if ( is_wp_error( $item ) ) { throw new RuntimeException( $item->get_error_code() . ': ' . $item->get_error_message() ); }
rf_check( $block->get_attributes() === $item['data']['attributes'], 'Actual native attributes, including global attributes/defaults, remain useful.' );
rf_check( $block->supports === $item['data']['supports'] && true === $item['data']['is_dynamic'] && 3 === $item['data']['api_version'], 'Exact block supports and dynamic/API information are returned.' );
rf_check( array( 'postId' ) === $item['data']['uses_context'] && array( 'fixture/query' => 'query' ) === $item['data']['provides_context'], 'Native block context wiring survives metadata projection.' );
rf_check( false === $item['rollback_available'] && '' === $item['action_id'] && 7 === get_current_user_id(), 'Metadata reads create no write/rollback and restore the requester.' );
rf_check( 0 === $GLOBALS['bm_rendered'] && 0 === $GLOBALS['bm_variations'] && 0 === $GLOBALS['bm_additional'], 'Metadata dispatch does not run render, lazy variation or unreviewed additional-field callbacks.' );
rf_check( false === strpos( wp_json_encode( $item ), 'private-' ) && ! isset( $item['data']['_links'], $item['data']['render_callback'], $item['data']['script_handles'], $item['data']['view_script_handles'] ), 'Script assets, callbacks, variations and render links are not exposed.' );
rf_check( array( array( 'name' => 'bordered', 'label' => 'Bordered', 'is_default' => true ) ) === $item['data']['styles'], 'Native registered styles retain display metadata without inline CSS or asset handles.' );
$projection = rf_run( array( '_fields' => 'name,attributes.columns,supports.align,view_script_handles,_links' ), 'GET', '/wp/v2/block-types/fixture/product-grid' );
rf_check( array( 'attributes' => array( 'columns' => $block->get_attributes()['columns'] ), 'name' => 'fixture/product-grid', 'supports' => array( 'align' => array( 'wide', 'full' ) ) ) === $projection['data'], 'Model field selection can narrow approved metadata but cannot recover excluded asset fields.' );

$collection = rf_run( array(), 'GET', '/wp/v2/block-types' );
rf_check( 2 === count( $collection['data'] ) && array( 'fixture/product-grid', 'other/static' ) === array_column( $collection['data'], 'name' ), 'Native registry collection discovers installed names.' );
foreach ( $collection['data'] as $record ) { rf_check( ! array_diff( array_keys( $record ), array( 'name', 'title', 'category', 'is_dynamic' ) ), 'Collections contain only compact name/title/category/dynamic metadata.' ); }
$namespace = rf_run( array(), 'GET', '/wp/v2/block-types/other' );
rf_check( 1 === count( $namespace['data'] ) && 'other/static' === $namespace['data'][0]['name'] && false === $namespace['data'][0]['is_dynamic'], 'Native namespace filtering and static-block metadata remain correct.' );
rf_check( array() === rf_run( array(), 'GET', '/wp/v2/block-types/missing' )['data'], 'An empty native namespace remains an empty collection.' );
for ( $index = 0; $index < 205; ++$index ) { $registry->register( 'bulk/item-' . $index, array( 'title' => 'Fixture ' . $index, 'category' => 'text', 'attributes' => array( 'private_large' => array( 'type' => 'string', 'default' => str_repeat( 'x', 1024 ) ) ) ) ); }
$bounded = rf_run( array(), 'GET', '/wp/v2/block-types' );
rf_check( 200 === count( $bounded['data'] ) && strlen( wp_json_encode( $bounded['data'] ) ) < 30000, 'Collection output is capped at 200 compact rows despite a larger native registry.' );
rf_check( 'bulk/item-204' === rf_run( array(), 'GET', '/wp/v2/block-types/bulk/item-204' )['data']['name'], 'Exact block reads remain available beyond the discovery cap.' );
for ( $index = 0; $index < 25; ++$index ) { WP_Block_Styles_Registry::get_instance()->register( 'fixture/product-grid', array( 'name' => 'style-' . $index, 'label' => 'Style ' . $index, 'inline_style' => 'private-css-marker' ) ); }
$styles = rf_run( array(), 'GET', '/wp/v2/block-types/fixture/product-grid' )['data']['styles'];
rf_check( 20 === count( $styles ) && false === strpos( wp_json_encode( $styles ), 'private-' ), 'Style discovery is bounded even when the native registry supplies more choices.' );
rf_check( 0 === $GLOBALS['bm_rendered'] && 0 === $GLOBALS['bm_variations'] && 0 === $GLOBALS['bm_additional'], 'Repeated collection and item reads still never execute omitted callbacks.' );

foreach ( array( 7, 11 ) as $denied ) {
    $GLOBALS['rf_deny'] = $denied; $before = $GLOBALS['bm_dispatched'];
    $result = (new ReflectionMethod( Bhranti_Actions::class, 'review' ))->invoke( null, rf_command( array(), 'GET', '/wp/v2/block-types/fixture/product-grid' ), 7, 11 );
    rf_check( is_wp_error( $result ) && 'bhranti_action_permission' === $result->get_error_code() && $before === $GLOBALS['bm_dispatched'], 'Either human or actor native read refusal prevents dispatch.' );
    rf_check( 7 === get_current_user_id(), 'Permission denial restores requesting identity.' );
}
$GLOBALS['rf_deny'] = 0;
foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
    $before = $GLOBALS['bm_dispatched']; $result = rf_run( array( 'title' => 'Must not change' ), $method, '/wp/v2/block-types/fixture/product-grid' );
    rf_check( is_wp_error( $result ) && 'bhranti_action_blocked' === $result->get_error_code() && $before === $GLOBALS['bm_dispatched'], 'Block metadata never exposes a write route: ' . $method );
}
$unknown = rf_run( array(), 'GET', '/wp/v2/block-types/fixture/not-registered' );
rf_check( is_wp_error( $unknown ), 'Unknown native blocks return an error instead of invented metadata.' );
rf_check( 'bhranti_block_metadata_unavailable' === $unknown->get_error_code() && 404 === $unknown->get_error_data()['status'], 'Missing native block metadata is distinguished from permission denial.' );
rf_check( 7 === get_current_user_id(), 'Missing metadata restores the human identity.' );
foreach ( array( 7, 11 ) as $denied ) {
    $GLOBALS['rf_deny'] = $denied; $before = $GLOBALS['bm_dispatched'];
    $result = (new ReflectionMethod( Bhranti_Actions::class, 'review' ))->invoke( null, rf_command( array(), 'GET', '/wp/v2/block-types/fixture/not-registered' ), 7, 11 );
    rf_check( is_wp_error( $result ) && 'bhranti_action_permission' === $result->get_error_code() && $before === $GLOBALS['bm_dispatched'], 'A missing block still checks both native human/actor permissions before reporting availability.' );
}
$GLOBALS['rf_deny'] = 0;
$render = rf_run( array(), 'GET', '/wp/v2/block-renderer/fixture/product-grid' );
rf_check( is_wp_error( $render ) && 0 === $GLOBALS['bm_rendered'], 'Block metadata access does not authorize dynamic block rendering.' );
$events = array_slice( $GLOBALS['rf_audits'], -2 );
rf_check( ! empty( $events ) && false === strpos( wp_json_encode( $GLOBALS['rf_audits'] ), 'private-view-script-marker' ), 'Audit records contain action metadata, never full block script payloads.' );
// Positive control: native callbacks really exist; only Bhranti's field choice omits them.
$native = new WP_REST_Request( 'GET', '/wp/v2/block-types/fixture/product-grid' );
$native->set_url_params( array( 'namespace' => 'fixture', 'name' => 'product-grid' ) );
$native->set_query_params( array( '_fields' => 'variations,private_additional' ) );
$controller->get_item( $native );
rf_check( 1 === $GLOBALS['bm_variations'] && 1 === $GLOBALS['bm_additional'] && 0 === $GLOBALS['bm_rendered'], 'Positive control executes omitted native metadata callbacks, confirming Bhranti checks are meaningful.' );
echo 'PASS ' . ( $GLOBALS['rf_checks'] - $bm_start ) . " block metadata checks with native controller/registries; no DB, HTTP or AI.\n";
