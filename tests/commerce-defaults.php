<?php
/**
 * Native WooCommerce category-schema regression, without WordPress/Woo bootstrap.
 *
 * Uses the real installed category controller, REST request and schema validation.
 * Identity/routing are in-memory seams; no database, network, AI or action dispatch.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/commerce-defaults.php
 */
$core_root = getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Documents/WordPress/Plugins';
define( 'ABSPATH', rtrim( $core_root, '/' ) . '/' );
define( 'WC_ABSPATH', ABSPATH . 'wp-content/plugins/woocommerce/' );
require_once ABSPATH . 'wp-includes/class-wp-error.php';
require_once ABSPATH . 'wp-includes/class-wp-http-response.php';
require_once ABSPATH . 'wp-includes/rest-api/class-wp-rest-response.php';
require_once ABSPATH . 'wp-includes/rest-api/class-wp-rest-request.php';
require_once ABSPATH . 'wp-includes/rest-api/endpoints/class-wp-rest-controller.php';
require_once ABSPATH . 'wp-includes/rest-api.php';
require_once ABSPATH . 'wp-includes/formatting.php';
function __( $text, $domain = 'default' ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_plugins() { return array( 'woocommerce/woocommerce.php' => array( 'Version' => '11.2.1' ) ); }
function is_plugin_active( $plugin ) { return 'woocommerce/woocommerce.php' === $plugin; }
function post_type_exists( $type ) { return 'product' === $type; }
function taxonomy_exists( $type ) { return 'product_cat' === $type; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function do_action( $hook, ...$args ) {}
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_id_list( $values ) { return array_map( 'absint', (array) $values ); }
function get_current_user_id() { return 1; }
function wp_set_current_user( $id ) {}
function wc_rest_check_product_term_permissions( $taxonomy, $context, $id = 0 ) { return false; }
function is_user_logged_in() { return true; }
// Availability placeholders only: the tested native category controller is never replaced.
class WC_REST_Products_Controller {}
class WC_REST_Setting_Options_Controller {}
class WC_Product_Simple {}
foreach ( array(
	'Version3/class-wc-rest-controller.php',
	'Version3/class-wc-rest-terms-controller.php',
	'Version1/class-wc-rest-product-categories-v1-controller.php',
	'Version2/class-wc-rest-product-categories-v2-controller.php',
	'Version3/class-wc-rest-product-categories-controller.php',
) as $relative ) {
	require_once WC_ABSPATH . 'includes/rest-api/Controllers/' . $relative;
}
require_once dirname( __DIR__ ) . '/includes/class-bhranti-commerce.php';
$controller = new WC_REST_Product_Categories_Controller();
$schema     = $controller->get_collection_params();
if ( ! array_key_exists( 'default', $schema['product'] ) || null !== $schema['product']['default'] || 'integer' !== $schema['product']['type'] ) {
	throw new RuntimeException( 'The real native schema no longer contains the regression case.' );
}
$endpoint = array(
	'methods' => array( 'GET' => true ),
	'callback' => array( $controller, 'get_items' ),
	'permission_callback' => array( $controller, 'get_items_permissions_check' ),
	'args' => $schema,
);
$GLOBALS['wp_rest_server'] = new class( $endpoint ) {
	public $endpoint;
	public function __construct( $endpoint ) { $this->endpoint = $endpoint; }
	public function get_routes() { return array( '/wc/v3/products/categories' => array( $this->endpoint ) ); }
};
$review = new ReflectionMethod( 'Bhranti_Commerce', 'review' );
$checks = 0;
foreach ( array( array(), array( 'per_page' => 20 ), array( 'hide_empty' => false, 'order' => 'asc' ) ) as $params ) {
	$result = $review->invoke( null, array( 'tool' => 'commerce_request', 'args' => array( 'method' => 'GET', 'path' => '/wc/v3/products/categories', 'params' => $params ) ), 1, 11 );
	// Native schema validation must reach the deliberately denied native permission callback.
	if ( ! is_wp_error( $result ) || 'bhranti_commerce_permission' !== $result->get_error_code() ) {
		throw new RuntimeException( 'Valid category collection failed before native permissions: ' . ( is_wp_error( $result ) ? $result->get_error_code() : 'unexpected result' ) );
	}
	++$checks;
}
foreach ( array( array( 'parent' => 'not-an-id' ), array( 'per_page' => 21 ), array( 'product' => 1 ) ) as $params ) {
	$result = $review->invoke( null, array( 'tool' => 'commerce_request', 'args' => array( 'method' => 'GET', 'path' => '/wc/v3/products/categories', 'params' => $params ) ), 1, 11 );
	if ( ! is_wp_error( $result ) || 'bhranti_commerce_invalid' !== $result->get_error_code() ) {
		throw new RuntimeException( 'Invalid or unreviewed category fields must remain rejected.' );
	}
	++$checks;
}
// Confirm the original behavior actually reproduces the native null sanitization failure.
$bad = new WP_REST_Request( 'GET', '/wc/v3/products/categories' );
$bad->set_attributes( $endpoint );
$bad->set_default_params( array( 'product' => null ) );
if ( ! is_wp_error( $bad->sanitize_params() ) ) {
	throw new RuntimeException( 'Regression control no longer reproduces the native failure.' );
}
++$checks;
echo 'PASS: ' . $checks . " native category-default checks; no bootstrap, database, network or AI.\n";
