<?php
/**
 * Offline setup boundary checks using installed native Woo controllers and REST schemas.
 * No wp-load, database, HTTP, AI, order creation or native mutation callbacks.
 */
require_once __DIR__ . '/offline-requester-identity.php';
define( 'ABSPATH', rtrim( getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Documents/WordPress/Plugins', '/' ) . '/' );
define( 'WC_ABSPATH', ABSPATH . 'wp-content/plugins/woocommerce/' );
require ABSPATH . 'wp-includes/class-wp-error.php';
require ABSPATH . 'wp-includes/class-wp-http-response.php';
require ABSPATH . 'wp-includes/rest-api/class-wp-rest-response.php';
require ABSPATH . 'wp-includes/rest-api/class-wp-rest-request.php';
require ABSPATH . 'wp-includes/rest-api/class-wp-rest-server.php';
require ABSPATH . 'wp-includes/rest-api/endpoints/class-wp-rest-controller.php';
require ABSPATH . 'wp-includes/rest-api.php';
require ABSPATH . 'wp-includes/formatting.php';
function is_admin() { return false; }
function get_bloginfo( $show ) { return 'version' === $show ? '7.1.3' : ''; }
function __( $text, $domain = 'default' ) { return $text; }
function _x( $text, $context, $domain = 'default' ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_is_json_media_type( $media_type ) { return 'application/json' === $media_type; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function do_action( $hook, ...$args ) {}
function did_action( $hook ) { return 1; }
function _doing_it_wrong( ...$args ) { throw new RuntimeException( 'Unexpected native API misuse.' ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, $args ); }
function is_utf8_charset() { return true; }
function wp_is_valid_utf8( $value ) { return true; }
function wp_parse_id_list( $value ) { return array_map( 'absint', (array) $value ); }
function get_option( $key, $default = false ) { return $GLOBALS['setup_options'][ $key ] ?? $default; }
function wc_format_localized_price( $value ) { return (string) $value; }
function admin_url( $path = '' ) { return 'https://bhranti.wp.local/wp-admin/' . $path; }
function WC() {
    return new class {
        public $countries;
        public $payment_gateways;
        public function __construct() { $this->countries = new class {
            public function get_countries() { return include WC_ABSPATH . 'i18n/countries.php'; }
            public function get_continents() { return include WC_ABSPATH . 'i18n/continents.php'; }
            public function get_states( $country ) { $all = include WC_ABSPATH . 'i18n/states.php'; return $all[ $country ] ?? array(); }
        }; $this->payment_gateways = new class { public function payment_gateways() { return array( 'cod' => $GLOBALS['setup_gateway'] ); } }; }
        public function shipping() { return new class { public function get_shipping_classes() { return array(); } }; }
    };
}
foreach ( array( 'Version3/class-wc-rest-controller.php', 'Version3/class-wc-rest-data-controller.php', 'Version3/class-wc-rest-shipping-zones-controller-base.php', 'Version2/class-wc-rest-shipping-zones-v2-controller.php', 'Version2/class-wc-rest-shipping-zone-locations-v2-controller.php', 'Version2/class-wc-rest-shipping-zone-methods-v2-controller.php', 'Version3/class-wc-rest-shipping-zones-controller.php', 'Version3/class-wc-rest-shipping-zone-locations-controller.php', 'Version3/class-wc-rest-shipping-zone-methods-controller.php' ) as $file ) {
    require WC_ABSPATH . 'includes/rest-api/Controllers/' . $file;
}
require WC_ABSPATH . 'src/Admin/API/Options.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-commerce.php';
$GLOBALS['wp_rest_server'] = new WP_REST_Server();
$controllers = array( new WC_REST_Shipping_Zones_Controller(), new WC_REST_Shipping_Zone_Locations_Controller(), new WC_REST_Shipping_Zone_Methods_Controller(), new Automattic\WooCommerce\Admin\API\Options() );
foreach ( $controllers as $controller ) { $controller->register_routes(); }
$checks = 0;
function setup_check( $test, $label ) { if ( ! $test ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
function setup_prepare( $method, $path, $params = array(), $recovery = false ) { return Bhranti_Commerce_Setup::prepare( $method, $path, $params, $recovery ); }
foreach ( array( '/wc/v3/shipping/zones', '/wc/v3/shipping/zones/0', '/wc/v3/shipping/zones/1', '/wc/v3/shipping/zones/1/locations', '/wc/v3/shipping/zones/1/methods', '/wc-admin/options' ) as $path ) {
    $review = setup_prepare( 'GET', $path );
    setup_check( is_array( $review ) && $review['request'] instanceof WP_REST_Request, 'Actual native read route/schema: ' . $path );
    setup_check( ! is_wp_error( $review['request']->has_valid_params() ), 'Native read validates: ' . $path );
}
$zone = setup_prepare( 'POST', '/wc/v3/shipping/zones', array( 'name' => 'Confirmed delivery region', 'order' => 2 ) );
setup_check( is_array( $zone ) && $zone['create'] && 'shipping_zone' === $zone['kind'], 'Native zone creation schema has correct create identity' );
setup_check( array( 'name' => 'Confirmed delivery region', 'order' => 2 ) === $zone['request']->get_json_params(), 'Native object body retained for zones' );
$locations = array( array( 'type' => 'continent', 'code' => 'AS' ), array( 'type' => 'country', 'code' => 'IN' ), array( 'type' => 'state', 'code' => 'IN:MH' ), array( 'type' => 'postcode', 'code' => '400*' ) );
$review = setup_prepare( 'POST', '/wc/v3/shipping/zones/1/locations', array( 'locations' => $locations ) );
setup_check( is_array( $review ) && ! $review['create'], 'Native location update is not a create' );
setup_check( $locations === $review['request']->get_json_params(), 'Actual native location callback receives the JSON array, not an object envelope' );
setup_check( 'update_items' === $review['endpoint']['callback'][1], 'Native plural location update callback reviewed exactly' );
$visibility = setup_prepare( 'POST', '/wc-admin/options', array( 'woocommerce_coming_soon' => 'no' ) );
setup_check( is_array( $visibility ) && array( 'woocommerce_coming_soon' => 'no' ) === $visibility['request']->get_json_params(), 'Visibility body only contains exact approved native option' );
$visibility = setup_prepare( 'GET', '/wc-admin/options' );
setup_check( 'woocommerce_coming_soon,woocommerce_store_pages_only' === $visibility['request']['options'], 'Read projects only two visibility options, never private share key' );
foreach ( array(
    array( 'POST', '/wc/v3/shipping/zones', array() ),
    array( 'POST', '/wc/v3/shipping/zones', array( 'name' => 'X', 'unknown' => true ) ),
    array( 'POST', '/wc/v3/shipping/zones', array( 'name' => null ) ),
    array( 'POST', '/wc/v3/shipping/zones', array( 'name' => '<script>no</script>' ) ),
    array( 'POST', '/wc/v3/shipping/zones', array( 'name' => 'X', 'order' => -1 ) ),
    array( 'POST', '/wc/v3/shipping/zones/0', array( 'name' => 'X' ) ),
    array( 'POST', '/wc/v3/shipping/zones/0/locations', array( 'locations' => array() ) ),
    array( 'POST', '/wc/v3/shipping/zones/1/locations', array( 'locations' => array( array( 'type' => 'country', 'code' => 'ZZ' ) ) ) ),
    array( 'POST', '/wc/v3/shipping/zones/1/locations', array( 'locations' => array( array( 'type' => 'state', 'code' => 'IN:NOPE' ) ) ) ),
    array( 'POST', '/wc/v3/shipping/zones/1/locations', array( 'locations' => array( array( 'type' => 'country', 'code' => 'IN', 'force' => true ) ) ) ),
    array( 'POST', '/wc/v3/shipping/zones/1/methods', array( 'method_id' => 'third_party' ) ),
    array( 'POST', '/wc/v3/shipping/zones/1/methods', array( 'method_id' => array( 'flat_rate' ) ) ),
    array( 'POST', '/wc/v3/shipping/zones/1/methods/1', array( 'method_id' => 'flat_rate' ) ),
    array( 'POST', '/wc/v3/payment_gateways/cod', array( 'settings' => array( 'api_key' => 'blocked' ) ) ),
    array( 'POST', '/wc/v3/payment_gateways/cod', array( 'settings' => array( 'enable_for_methods' => array( 'stripe' ) ) ) ),
    array( 'POST', '/wc-admin/options', array( 'woocommerce_share_key' => 'blocked' ) ),
    array( 'POST', '/wc-admin/options', array( 'woocommerce_coming_soon' => true ) ),
    array( 'GET', '/wc-admin/options', array( 'options' => 'woocommerce_share_key' ) ),
    array( 'DELETE', '/wc/v3/shipping/zones/1', array() ),
    array( 'DELETE', '/wc/v3/shipping/zones/1/methods/2', array() ),
    array( 'DELETE', '/wc/v3/payment_gateways/cod', array() ),
) as $case ) { setup_check( is_wp_error( setup_prepare( ...$case ) ), 'Reject invalid setup request before native dispatch' ); }
setup_check( null === setup_prepare( 'GET', '/wc/v3/orders' ), 'Setup never handles order route' );
setup_check( null === setup_prepare( 'GET', '/wc/v3/payment_gateways/stripe' ), 'Setup never handles arbitrary online gateway' );
$deletion = setup_prepare( 'DELETE', '/wc/v3/shipping/zones/1', array(), true );
setup_check( is_array( $deletion ) && true === $deletion['request']['force'], 'Only owned recovery supplies native force deletion required by zones' );
$original = $GLOBALS['wp_rest_server'];
$routes = $original->get_routes();
foreach ( $routes['/wc/v3/shipping/zones'] as &$endpoint ) {
    if ( ! empty( $endpoint['methods']['GET'] ) ) { $endpoint['callback'][1] = 'create_item'; }
}
unset( $endpoint );
$GLOBALS['wp_rest_server'] = new class( $routes ) {
    private $routes;
    public function __construct( $routes ) { $this->routes = $routes; }
    public function get_routes() { return $this->routes; }
};
setup_check( is_wp_error( setup_prepare( 'GET', '/wc/v3/shipping/zones' ) ), 'Reject native object with wrong action callback instead of borrowing permissions' );
$GLOBALS['wp_rest_server'] = $original;
$setting = new ReflectionMethod( 'Bhranti_Commerce', 'setting_value' );
foreach ( array( 'woocommerce_default_country' => 'IN:MH', 'woocommerce_allowed_countries' => 'specific', 'woocommerce_ship_to_countries' => 'specific', 'woocommerce_weight_unit' => 'kg', 'woocommerce_dimension_unit' => 'cm' ) as $key => $value ) {
    setup_check( true === $setting->invoke( null, $key, $value, array( 'options' => array( $value => $value ) ), 1, 1 ), 'Native enum value accepted: ' . $key );
    setup_check( is_wp_error( $setting->invoke( null, $key, 'not-native', array( 'options' => array( $value => $value ) ), 1, 1 ) ), 'Non-native enum rejected: ' . $key );
}
setup_check( true === $setting->invoke( null, 'woocommerce_specific_allowed_countries', array( 'IN' ), array( 'options' => WC()->countries->get_countries() ), 1, 1 ), 'Country list preserves native arrays' );
setup_check( is_wp_error( $setting->invoke( null, 'woocommerce_specific_allowed_countries', array( 'ZZ' ), array( 'options' => WC()->countries->get_countries() ), 1, 1 ) ), 'Unknown country rejected' );
setup_check( is_wp_error( $setting->invoke( null, 'woocommerce_specific_allowed_countries', '', array( 'options' => WC()->countries->get_countries() ), 1, 1 ) ), 'Public country list requires an array so native normalization cannot become an uncertain write' );
$input = new ReflectionMethod( 'Bhranti_Commerce', 'setting_input' );
setup_check( array() === $input->invoke( null, '', 'woocommerce_specific_allowed_countries' ), 'Legacy empty country default normalized for native REST restoration' );
$zone_state = array( 'zone' => array( 'name' => 'Delivery', 'order' => 2, 'locations' => array() ), 'methods' => array() );
setup_check( true === Bhranti_Commerce_Setup::verified( array( 'kind' => 'shipping_zone', 'params' => array( 'name' => 'Delivery', 'order' => 2 ) ), $zone_state ), 'Verify real saved zone fields' );
setup_check( false === Bhranti_Commerce_Setup::verified( array( 'kind' => 'shipping_zone', 'params' => array( 'name' => 'Wrong' ) ), $zone_state ), 'Wrong native saved field is uncertain, not success' );
setup_check( true === Bhranti_Commerce_Setup::verified( array( 'kind' => 'shipping_locations', 'params' => array( 'locations' => array( array( 'type' => 'country', 'code' => 'IN' ) ) ) ), array( 'zone' => array( 'locations' => array( array( 'code' => 'IN', 'type' => 'country' ) ) ) ) ), 'Location key order does not produce false uncertain failure' );
setup_check( true === Bhranti_Commerce_Setup::can_remove( array( 'kind' => 'shipping_zone' ), array( 'created_id' => 7 ), $zone_state ), 'Own untouched empty shipping zone can be undone' );
$zone_state['methods'][] = array( 'instance_id' => 9 );
setup_check( is_wp_error( Bhranti_Commerce_Setup::can_remove( array( 'kind' => 'shipping_zone' ), array( 'created_id' => 7 ), $zone_state ) ), 'Zone Undo refuses dependent shipping method' );
$GLOBALS['setup_options']['woocommerce_cod_settings'] = array( 'enable_for_methods' => array( 'flat_rate:9' ) );
setup_check( is_wp_error( Bhranti_Commerce_Setup::can_remove( array( 'kind' => 'shipping_method', 'id' => 9 ), array( 'created_id' => 9 ), array( 'method' => array( 'method_id' => 'flat_rate' ) ) ) ), 'Method Undo refuses COD dependency' );
$projected = Bhranti_Commerce_Setup::project( array( 'id' => 'cod', 'secret' => 'hidden', 'settings' => array( 'api_key' => array( 'value' => 'hidden' ), 'instructions' => array( 'value' => 'Pay on delivery', 'secret' => 'hidden' ) ) ), 'cod' );
setup_check( ! str_contains( json_encode( $projected ), 'hidden' ), 'Only safe COD fields leave native adapter' );
require WC_ABSPATH . 'src/Enums/ProductTaxStatus.php';
require WC_ABSPATH . 'includes/abstracts/abstract-wc-settings-api.php';
require WC_ABSPATH . 'includes/abstracts/abstract-wc-shipping-method.php';
$method_settings = new ReflectionMethod( 'Bhranti_Commerce_Setup', 'method_settings' );
foreach ( array( 'flat_rate' => 'WC_Shipping_Flat_Rate', 'free_shipping' => 'WC_Shipping_Free_Shipping', 'local_pickup' => 'WC_Shipping_Local_Pickup' ) as $slug => $class ) {
    $file_slug = str_replace( '_', '-', $slug );
    require WC_ABSPATH . 'includes/shipping/' . $file_slug . '/class-wc-shipping-' . $file_slug . '.php';
    $native_method = (new ReflectionClass( $class ))->newInstanceWithoutConstructor();
    if ( 'flat_rate' === $slug ) {
        $native_method->instance_form_fields = (Closure::bind( function () { return include WC_ABSPATH . 'includes/shipping/flat-rate/includes/settings-flat-rate.php'; }, $native_method, $class ))();
    } else { $native_method->init_form_fields(); }
    $cost_field = 'free_shipping' === $slug ? 'min_amount' : 'cost';
    setup_check( true === $method_settings->invoke( null, $slug, array( $cost_field => '125.00', 'title' => 'Delivery' ), $native_method ), 'Numeric cost passes real native method form: ' . $slug );
    foreach ( array( '[qty]*5', '1e9', '-1', '1,500', '1.2.3', 125, null ) as $invalid_cost ) {
        setup_check( is_wp_error( $method_settings->invoke( null, $slug, array( $cost_field => $invalid_cost ), $native_method ) ), 'Expressions and non-numeric costs rejected: ' . $slug );
    }
    setup_check( is_wp_error( $method_settings->invoke( null, $slug, array( 'api_key' => 'no' ), $native_method ) ), 'Unknown shipping settings rejected: ' . $slug );
    $enum = 'free_shipping' === $slug ? 'requires' : 'tax_status';
    $valid_enum = 'free_shipping' === $slug ? 'min_amount' : 'none';
    setup_check( true === $method_settings->invoke( null, $slug, array( $enum => $valid_enum ), $native_method ), 'Real native enum accepted: ' . $slug );
    setup_check( is_wp_error( $method_settings->invoke( null, $slug, array( $enum => 'unknown' ), $native_method ) ), 'Unknown native enum rejected: ' . $slug );
}
setup_check( true === Bhranti_Commerce_Setup::verified( array( 'kind' => 'cod', 'params' => array( 'enabled' => true, 'settings' => array( 'instructions' => 'Pay on delivery' ) ) ), array( 'option' => array( 'value' => array( 'enabled' => 'yes', 'instructions' => 'Pay on delivery', 'unrelated' => 'preserved' ) ) ) ), 'COD saved fields verified without exposing unrelated settings' );
setup_check( false === Bhranti_Commerce_Setup::verified( array( 'kind' => 'cod', 'params' => array( 'settings' => array( 'enable_for_methods' => array( 'flat_rate:9' ) ) ) ), array( 'option' => array( 'value' => array( 'enable_for_methods' => array() ) ) ) ), 'Native silently dropped COD restriction cannot become success' );
setup_check( true === Bhranti_Commerce_Setup::verified( array( 'kind' => 'shipping_locations', 'params' => array( 'locations' => array( array( 'type' => 'postcode', 'code' => 'ab1*' ) ) ) ), array( 'zone' => array( 'locations' => array( array( 'code' => 'AB1*', 'type' => 'postcode' ) ) ) ) ), 'Native postcode normalization accepted without false uncertain write' );
require WC_ABSPATH . 'vendor/automattic/jetpack-constants/src/class-constants.php';
require WC_ABSPATH . 'includes/abstracts/abstract-wc-payment-gateway.php';
require WC_ABSPATH . 'includes/gateways/cod/class-wc-gateway-cod.php';
require WC_ABSPATH . 'includes/rest-api/Controllers/Version2/class-wc-rest-payment-gateways-v2-controller.php';
require WC_ABSPATH . 'includes/rest-api/Controllers/Version3/class-wc-rest-payment-gateways-controller.php';
$cod_controller = new WC_REST_Payment_Gateways_Controller();
$cod_controller->register_routes();
$GLOBALS['setup_gateway'] = (new ReflectionClass( 'WC_Gateway_COD' ))->newInstanceWithoutConstructor();
$cod = setup_prepare( 'POST', '/wc/v3/payment_gateways/cod', array( 'enabled' => true, 'title' => 'Pay on delivery', 'description' => 'Pay when your delivery arrives.', 'settings' => array( 'instructions' => 'Pay on delivery.', 'enable_for_virtual' => 'no', 'enable_for_methods' => array() ) ) );
setup_check( is_array( $cod ) && 'update_item' === $cod['endpoint']['callback'][1], 'Actual native COD object and schema accept the offline setup request' );
setup_check( array() === $GLOBALS['setup_gateway']->form_fields['enable_for_methods']['options'], 'Actual native COD exposes no method options outside its own outer settings route' );
setup_check( is_wp_error( setup_prepare( 'POST', '/wc/v3/payment_gateways/cod', array( 'settings' => array( 'enable_for_methods' => array( 'flat_rate:9' ) ) ) ) ), 'Unavailable COD method restrictions fail closed instead of native silent dropping' );
setup_check( is_array( setup_prepare( 'GET', '/wc/v3/payment_gateways/cod' ) ), 'Exact native COD read callback accepted' );
function get_post( $id ) { return $GLOBALS['setup_cart'] ?? null; }
function user_can( $id, $cap, ...$args ) { return array( $id, $cap ) !== ( $GLOBALS['setup_denied_cap'] ?? null ); }
final class Bhranti_Settings { public static function allows( $permission ) { return $permission === ( $GLOBALS['setup_denied_policy'] ?? '' ) ? new WP_Error( 'bhranti_action_disabled', 'Disabled.' ) : true; } public static function can_manage() { return true; } }
$visibility_review = array( 'kind' => 'visibility', 'method' => 'POST', 'requester_id' => 1, 'actor_id' => 1 );
foreach ( array( '00', '-1', 'bad-id', true ) as $bad_cart ) {
    $GLOBALS['setup_options']['woocommerce_cart_page_id'] = $bad_cart;
    setup_check( is_wp_error( Bhranti_Commerce_Setup::policy( $visibility_review ) ), 'Truthy invalid Cart assignment cannot reach native implicit post insertion' );
}
$GLOBALS['setup_options']['woocommerce_cart_page_id'] = 77;
$GLOBALS['setup_cart'] = (object) array( 'post_type' => 'page', 'post_status' => 'draft' );
setup_check( is_wp_error( Bhranti_Commerce_Setup::policy( $visibility_review ) ), 'Native visibility hook may not implicitly publish a draft Cart' );
$GLOBALS['setup_cart']->post_status = 'publish';
setup_check( true === Bhranti_Commerce_Setup::policy( $visibility_review ), 'Published Cart permits reviewed visibility after native human capability checks' );
foreach ( array( 1 ) as $identity ) {
    foreach ( array( 'edit_post', 'publish_pages' ) as $capability ) {
        $GLOBALS['setup_denied_cap'] = array( $identity, $capability );
        setup_check( is_wp_error( Bhranti_Commerce_Setup::policy( $visibility_review ) ), 'Native Cart side-effect requires the current human’s native capabilities' );
    }
}
unset( $GLOBALS['setup_denied_cap'] );
foreach ( array( 'edit_content', 'publish_content' ) as $permission ) {
    $GLOBALS['setup_denied_policy'] = $permission;
    setup_check( is_wp_error( Bhranti_Commerce_Setup::policy( $visibility_review ) ), 'Native Cart side-effect obeys live content and publishing guardrails' );
}
unset( $GLOBALS['setup_denied_policy'] );
$restore_visibility = Bhranti_Commerce_Setup::restore_params( $visibility_review, array( 'before' => array( 'woocommerce_coming_soon' => array( 'exists' => true, 'value' => 'yes' ), 'woocommerce_store_pages_only' => array( 'exists' => true, 'value' => 'no' ), 'cart' => array( 'post' => array( 'post_content' => 'Never an option write.' ) ) ) ) );
setup_check( array( 'woocommerce_coming_soon' => 'yes', 'woocommerce_store_pages_only' => 'no' ) === $restore_visibility, 'Cart conflict context cannot turn into an arbitrary option restoration' );
require ABSPATH . 'wp-includes/class-wpdb.php';
$GLOBALS['wpdb'] = (new ReflectionClass( 'wpdb' ))->newInstanceWithoutConstructor();
$GLOBALS['wpdb']->field_types = array( 'existing_field' => '%s' );
$format_method = new ReflectionMethod( 'wpdb', 'process_field_formats' );
$original_server = $GLOBALS['wp_rest_server'];
$GLOBALS['wp_rest_server'] = new class( $format_method ) {
    public $throw = false;
    private $format_method;
    public function __construct( $format_method ) { $this->format_method = $format_method; }
    public function dispatch( $request ) {
        $fields = $this->format_method->invoke( $GLOBALS['wpdb'], array( 'is_enabled' => false ), null );
        setup_check( '%d' === $fields['is_enabled']['format'] && false === $fields['is_enabled']['value'], 'Actual wpdb formats native false shipping flag as an integer' );
        if ( $this->throw ) { throw new RuntimeException( 'Own dispatch fixture.' ); }
        return new WP_REST_Response( array() );
    }
};
$format_review = array( 'kind' => 'shipping_method', 'method' => 'POST', 'request' => new WP_REST_Request( 'POST', '/wc/v3/shipping/zones/1/methods/1' ) );
Bhranti_Commerce_Setup::request( $format_review );
setup_check( array( 'existing_field' => '%s' ) === $GLOBALS['wpdb']->field_types, 'Temporary absent format removed after native request' );
$GLOBALS['wpdb']->field_types['is_enabled'] = '%s';
$GLOBALS['wp_rest_server']->throw = true;
try { Bhranti_Commerce_Setup::request( $format_review ); } catch ( RuntimeException $expected ) {}
setup_check( array( 'existing_field' => '%s', 'is_enabled' => '%s' ) === $GLOBALS['wpdb']->field_types, 'Original format restored even after native callback throws' );
$GLOBALS['wp_rest_server'] = $original_server;

// Exercise the complete consent proposal path without bootstrapping a site or
// invoking the native visibility mutation callback.
function get_current_user_id() { return $GLOBALS['setup_user_id'] ?? 1; }
function wp_set_current_user( $id ) { $GLOBALS['setup_user_id'] = $id; }
function clean_post_cache( $id ) {}
function wp_cache_delete( $key, $group = '' ) {}
function wp_load_alloptions( $force = false ) { return $GLOBALS['setup_options']; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) { if ( isset( $GLOBALS['setup_options'][ $key ] ) ) { return false; } $GLOBALS['setup_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['setup_options'][ $key ] ); return true; }
/** In-memory lock boundary; this fixture never opens a WordPress database. */
final class Bhranti_Lock {
    public static function acquire( $name, $ttl ) {
        if ( array_key_exists( $name, $GLOBALS['setup_options'] ) ) { return false; }
        $token = array( 'token' => wp_generate_uuid4(), 'expires' => time() + $ttl );
        return add_option( $name, $token, '', false ) ? $token : false;
    }
    public static function owns( $name, $token ) { return is_array( $token ) && $token === get_option( $name ) && ( $token['expires'] ?? 0 ) > time(); }
    public static function release( $name, $token ) { if ( ! self::owns( $name, $token ) ) { return false; } return delete_option( $name ); }
}
function add_filter( ...$args ) {}
function remove_filter( ...$args ) {}
function apply_filters_deprecated( $hook, $args, $version ) { return $args[0]; }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['setup_post_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['setup_post_meta'][ $id ][ $key ] = wp_unslash( $value ); return true; }
function get_plugins() { return array( 'woocommerce/woocommerce.php' => array( 'Version' => '11.2.1' ) ); }
function is_plugin_active( $plugin ) { return 'woocommerce/woocommerce.php' === $plugin; }
function post_type_exists( $type ) { return 'product' === $type; }
function taxonomy_exists( $type ) { return 'product_cat' === $type; }
if ( ! class_exists( 'WC_REST_Products_Controller' ) ) { class WC_REST_Products_Controller {} }
if ( ! class_exists( 'WC_REST_Product_Categories_Controller' ) ) { class WC_REST_Product_Categories_Controller {} }
if ( ! class_exists( 'WC_REST_Setting_Options_Controller' ) ) { class WC_REST_Setting_Options_Controller {} }
if ( ! class_exists( 'WC_Product_Simple' ) ) { class WC_Product_Simple {} }
class Setup_Homescreen { public static function is_admin_user() { return true; } }
class_alias( 'Setup_Homescreen', 'Automattic\\WooCommerce\\Internal\\Admin\\Homescreen' );
final class Bhranti_Actions { public static function ensure_actor() { return Bhranti_Identity::requester(); } }
final class Bhranti_Audit { public static function record( ...$args ) { return 1; } }
final class Bhranti_Agent { public static function consume_commerce_approval( ...$args ) { ++$GLOBALS['setup_grant_checks']; return false; } }
$GLOBALS['setup_grant_checks'] = 0;
$GLOBALS['setup_dispatches'] = 0;
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );
$GLOBALS['setup_cart'] = (object) array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => 1 );
$GLOBALS['setup_options']['woocommerce_cart_page_id'] = 0;
$GLOBALS['setup_options']['woocommerce_coming_soon'] = 'yes';
$GLOBALS['setup_options']['woocommerce_store_pages_only'] = 'yes';
$launch_command = array( 'tool' => 'commerce_request', 'args' => array( 'method' => 'POST', 'path' => '/wc-admin/options', 'params' => array( 'woocommerce_coming_soon' => 'no' ) ) );
$launch = Bhranti_Commerce::execute( $launch_command, 1, 77 );
setup_check( is_array( $launch ) && ! empty( $launch['confirmation_required'] ) && 'launch_store' === $launch['action'] && $launch_command === $launch['command'], 'A coming-soon to live proposal binds the exact command without writing' );
setup_check( 'yes' === get_option( 'woocommerce_coming_soon' ) && ! isset( $GLOBALS['setup_options']['bhranti_commerce_lock'] ) && 0 === $GLOBALS['setup_grant_checks'] && 0 === $GLOBALS['setup_dispatches'] && ! isset( $GLOBALS['setup_post_meta'][77] ), 'Unapproved store launch leaves native state untouched, stores no Undo, and releases the lock' );
$repeat = Bhranti_Commerce::execute( $launch_command, 1, 77, false, $launch['fingerprint'] );
setup_check( is_array( $repeat ) && $repeat['fingerprint'] === $launch['fingerprint'], 'Exact re-proposal remains a proposal with the same complete state fingerprint' );
$forged = Bhranti_Commerce::execute( $launch_command, 1, 77, true, $launch['fingerprint'] );
setup_check( is_wp_error( $forged ) && 'bhranti_commerce_confirmation' === $forged->get_error_code() && 1 === $GLOBALS['setup_grant_checks'] && 'yes' === get_option( 'woocommerce_coming_soon' ), 'A confirmed flag without the one-use human grant cannot launch' );
$GLOBALS['setup_options']['woocommerce_store_pages_only'] = 'no';
$stale = Bhranti_Commerce::execute( $launch_command, 1, 77, false, $launch['fingerprint'] );
setup_check( is_wp_error( $stale ) && 'bhranti_rollback_conflict' === $stale->get_error_code(), 'A changed visibility option invalidates the exact approval proposal' );
$scope_command = array( 'tool' => 'commerce_request', 'args' => array( 'method' => 'POST', 'path' => '/wc-admin/options', 'params' => array( 'woocommerce_store_pages_only' => 'yes' ) ) );
$scope = Bhranti_Commerce::execute( $scope_command, 1, 77 );
setup_check( is_array( $scope ) && ! empty( $scope['confirmation_required'] ) && 'reveal_site_pages' === $scope['action'] && 'no' === get_option( 'woocommerce_store_pages_only' ), 'Changing whole-site coming soon to store-only also waits for human approval' );
$GLOBALS['setup_options']['woocommerce_coming_soon'] = 'no';
$visibility_state = Bhranti_Commerce_Setup::state( array( 'kind' => 'visibility' ) );
$already_live = Bhranti_Commerce::execute( $launch_command, 1, 77, false, hash( 'sha256', wp_json_encode( $visibility_state ) ) );
setup_check( is_array( $already_live ) && ! empty( $already_live['confirmation_required'] ) && 'launch_store' === $already_live['action'] && 0 === $GLOBALS['setup_dispatches'], 'Writing public store visibility still requires approval when the store is already live' );
$already_scope = Bhranti_Commerce::execute( $scope_command, 1, 77 );
setup_check( is_array( $already_scope ) && ! empty( $already_scope['confirmation_required'] ) && 'reveal_site_pages' === $already_scope['action'] && 0 === $GLOBALS['setup_dispatches'], 'Writing store-only coming soon requires approval even while the whole site is live' );
$GLOBALS['setup_options']['woocommerce_store_pages_only'] = 'yes';
$scope_noop = Bhranti_Commerce::execute( $scope_command, 1, 77 );
setup_check( is_array( $scope_noop ) && ! empty( $scope_noop['confirmation_required'] ) && 0 === $GLOBALS['setup_dispatches'], 'A public site-page visibility no-op still requires approval' );
$combined_scope_command = array( 'tool' => 'commerce_request', 'args' => array( 'method' => 'POST', 'path' => '/wc-admin/options', 'params' => array( 'woocommerce_coming_soon' => 'yes', 'woocommerce_store_pages_only' => 'yes' ) ) );
$combined_scope = Bhranti_Commerce::execute( $combined_scope_command, 1, 77 );
setup_check( is_array( $combined_scope ) && ! empty( $combined_scope['confirmation_required'] ) && $combined_scope_command === $combined_scope['command'] && false !== strpos( $combined_scope['summary'], 'Turn on coming soon for the store.' ) && false !== strpos( $combined_scope['summary'], 'Limit coming soon to store pages' ) && 0 === $GLOBALS['setup_dispatches'], 'Combined site-page approval card discloses both visibility effects before any write' );
$combined_launch_command = array( 'tool' => 'commerce_request', 'args' => array( 'method' => 'POST', 'path' => '/wc-admin/options', 'params' => array( 'woocommerce_coming_soon' => 'no', 'woocommerce_store_pages_only' => 'no' ) ) );
$combined_launch = Bhranti_Commerce::execute( $combined_launch_command, 1, 77 );
setup_check( is_array( $combined_launch ) && ! empty( $combined_launch['confirmation_required'] ) && $combined_launch_command === $combined_launch['command'] && false !== strpos( $combined_launch['summary'], 'Turn off coming soon so the store can be public.' ) && false !== strpos( $combined_launch['summary'], 'Apply coming soon to the whole site' ) && 0 === $GLOBALS['setup_dispatches'], 'Combined store-launch approval card discloses both visibility effects before any write' );
$classification = new ReflectionMethod( 'Bhranti_Commerce', 'visibility_approval' );
foreach ( array(
    array( array( 'woocommerce_coming_soon' => 'yes' ), '' ),
    array( array( 'woocommerce_store_pages_only' => 'no' ), '' ),
    array( array( 'woocommerce_store_pages_only' => 'yes' ), 'reveal_site_pages' ),
    array( array( 'woocommerce_coming_soon' => 'no' ), 'launch_store' ),
    array( array( 'woocommerce_coming_soon' => 'no', 'woocommerce_store_pages_only' => 'yes' ), 'launch_store' ),
) as $case ) {
    setup_check( $case[1] === $classification->invoke( null, array( 'kind' => 'visibility', 'method' => 'POST', 'params' => $case[0] ) ), 'Approval follows the public visibility value being written, independent of prior state' );
}
$GLOBALS['wp_rest_server'] = new class( $original_server ) {
    private $server;
    public function __construct( $server ) { $this->server = $server; }
    public function get_routes() { return $this->server->get_routes(); }
    public function dispatch( $request ) {
        ++$GLOBALS['setup_dispatches'];
        if ( 'POST' !== $request->get_method() || '/wc-admin/options' !== $request->get_route() ) { throw new RuntimeException( 'Unexpected native dispatch.' ); }
        foreach ( $request->get_json_params() as $name => $value ) { $GLOBALS['setup_options'][ $name ] = $value; }
        return new WP_REST_Response( array() );
    }
};
$GLOBALS['setup_options']['woocommerce_coming_soon'] = 'no';
$GLOBALS['setup_options']['woocommerce_store_pages_only'] = 'no';
$restrictive_command = array( 'tool' => 'commerce_request', 'args' => array( 'method' => 'POST', 'path' => '/wc-admin/options', 'params' => array( 'woocommerce_coming_soon' => 'yes' ) ) );
$restrictive = Bhranti_Commerce::execute( $restrictive_command, 1, 77 );
setup_check( is_array( $restrictive ) && false === $restrictive['rollback_available'] && 'yes' === get_option( 'woocommerce_coming_soon' ) && 1 === $GLOBALS['setup_dispatches'], 'A restrictive visibility change saves, but cannot offer an Undo that would relaunch the site' );
$unsafe_undo = Bhranti_Commerce::rollback( $restrictive['action_id'], 1, 77 );
setup_check( is_wp_error( $unsafe_undo ) && 'bhranti_commerce_confirmation' === $unsafe_undo->get_error_code() && 'yes' === get_option( 'woocommerce_coming_soon' ) && 1 === $GLOBALS['setup_dispatches'], 'Direct rollback cannot broaden visibility without a fresh approved launch' );
$GLOBALS['setup_options']['woocommerce_store_pages_only'] = 'yes';
$restrict_scope_command = array( 'tool' => 'commerce_request', 'args' => array( 'method' => 'POST', 'path' => '/wc-admin/options', 'params' => array( 'woocommerce_store_pages_only' => 'no' ) ) );
$restrict_scope = Bhranti_Commerce::execute( $restrict_scope_command, 1, 77 );
setup_check( is_array( $restrict_scope ) && false === $restrict_scope['rollback_available'] && 'no' === get_option( 'woocommerce_store_pages_only' ) && 2 === $GLOBALS['setup_dispatches'], 'Restricting non-store pages cannot offer an Undo that writes the public site-page value' );
$unsafe_scope_undo = Bhranti_Commerce::rollback( $restrict_scope['action_id'], 1, 77 );
setup_check( is_wp_error( $unsafe_scope_undo ) && 'bhranti_commerce_confirmation' === $unsafe_scope_undo->get_error_code() && 2 === $GLOBALS['setup_dispatches'], 'Direct rollback cannot restore the public site-page value without approval' );
$GLOBALS['wp_rest_server'] = $original_server;

$acting = new ReflectionMethod( Bhranti_Commerce::class, 'acting' );
$before_dispatches = $GLOBALS['setup_dispatches'];
$wrong = $acting->invoke( null, array( 'requester_id' => 1 ), 11, static function () { throw new RuntimeException( 'An unrelated identity must not run a callback.' ); } );
setup_check( is_wp_error( $wrong ) && 'bhranti_commerce_permission' === $wrong->get_error_code() && $before_dispatches === $GLOBALS['setup_dispatches'] && 1 === get_current_user_id(), 'A different claimed executor cannot borrow native commerce permissions or change the current human.' );

echo 'PASS: ' . $checks . " offline native setup boundary checks. No bootstrap, database, network or AI.\n";
