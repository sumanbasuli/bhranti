<?php
/**
 * Offline setup boundary checks using installed native Woo controllers and REST schemas.
 * No wp-load, database, HTTP, AI, order creation or native mutation callbacks.
 */
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
    setup_check( true === $setting->invoke( null, $key, $value, array( 'options' => array( $value => $value ) ), 1, 11 ), 'Native enum value accepted: ' . $key );
    setup_check( is_wp_error( $setting->invoke( null, $key, 'not-native', array( 'options' => array( $value => $value ) ), 1, 11 ) ), 'Non-native enum rejected: ' . $key );
}
setup_check( true === $setting->invoke( null, 'woocommerce_specific_allowed_countries', array( 'IN' ), array( 'options' => WC()->countries->get_countries() ), 1, 11 ), 'Country list preserves native arrays' );
setup_check( is_wp_error( $setting->invoke( null, 'woocommerce_specific_allowed_countries', array( 'ZZ' ), array( 'options' => WC()->countries->get_countries() ), 1, 11 ) ), 'Unknown country rejected' );
setup_check( is_wp_error( $setting->invoke( null, 'woocommerce_specific_allowed_countries', '', array( 'options' => WC()->countries->get_countries() ), 1, 11 ) ), 'Public country list requires an array so native normalization cannot become an uncertain write' );
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
final class Bhranti_Settings { public static function allows( $permission ) { return $permission === ( $GLOBALS['setup_denied_policy'] ?? '' ) ? new WP_Error( 'bhranti_action_disabled', 'Disabled.' ) : true; } }
$visibility_review = array( 'kind' => 'visibility', 'method' => 'POST', 'requester_id' => 1, 'actor_id' => 11 );
foreach ( array( '00', '-1', 'bad-id', true ) as $bad_cart ) {
    $GLOBALS['setup_options']['woocommerce_cart_page_id'] = $bad_cart;
    setup_check( is_wp_error( Bhranti_Commerce_Setup::policy( $visibility_review ) ), 'Truthy invalid Cart assignment cannot reach native implicit post insertion' );
}
$GLOBALS['setup_options']['woocommerce_cart_page_id'] = 77;
$GLOBALS['setup_cart'] = (object) array( 'post_type' => 'page', 'post_status' => 'draft' );
setup_check( is_wp_error( Bhranti_Commerce_Setup::policy( $visibility_review ) ), 'Native visibility hook may not implicitly publish a draft Cart' );
$GLOBALS['setup_cart']->post_status = 'publish';
setup_check( true === Bhranti_Commerce_Setup::policy( $visibility_review ), 'Published Cart permits reviewed visibility after dual capability checks' );
foreach ( array( 1, 11 ) as $identity ) {
    foreach ( array( 'edit_post', 'publish_pages' ) as $capability ) {
        $GLOBALS['setup_denied_cap'] = array( $identity, $capability );
        setup_check( is_wp_error( Bhranti_Commerce_Setup::policy( $visibility_review ) ), 'Native Cart side-effect independently requires both identities and capabilities' );
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
echo 'PASS: ' . $checks . " offline native setup boundary checks. No bootstrap, database, network or AI.\n";
