<?php
/**
 * Requester-only native WooCommerce integration in a disposable offline fixture.
 *
 * BHRANTI_ADAPTER_QA_ROOT=/private/tmp/bhranti-forms-native-... php tests/commerce-requester-native.php
 * Uses a new, marked SQLite WordPress installation with official Woo 11.2.1
 * already initialized. Never use a customer site. No HTTP, mail or AI is sent.
 *
 * @package Bhranti
 */
$qa_root = realpath( getenv( 'BHRANTI_ADAPTER_QA_ROOT' ) ?: '' );
if ( ! $qa_root || 0 !== strpos( $qa_root, '/private/tmp/bhranti-forms-native-' ) || ! is_file( $qa_root . '/wp-content/database/.ht.sqlite' ) ) { throw new RuntimeException( 'Disposable native adapter fixture required.' ); }
$GLOBALS['cqa_http'] = 0;
$GLOBALS['cqa_mail'] = 0;
$GLOBALS['cqa_requests'] = array();
$GLOBALS['cqa_mail_calls'] = array();
$GLOBALS['wp_filter']['pre_http_request'][PHP_INT_MAX][] = array( 'function' => static function ( $pre, $args, $url ) { ++$GLOBALS['cqa_http']; $GLOBALS['cqa_requests'][] = parse_url( $url, PHP_URL_HOST ) . parse_url( $url, PHP_URL_PATH ); return new WP_Error( 'cqa_offline', 'External HTTP blocked.' ); }, 'accepted_args' => 3 );
$GLOBALS['wp_filter']['pre_wp_mail'][PHP_INT_MAX][] = array( 'function' => static function () { ++$GLOBALS['cqa_mail']; $GLOBALS['cqa_mail_calls'][] = array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 24 ), 'class' ); return true; }, 'accepted_args' => 2 );
$_SERVER['HTTP_HOST'] = 'bhranti-adapter.invalid';
$_SERVER['REQUEST_URI'] = '/adapter-test/';
$_SERVER['REQUEST_METHOD'] = 'GET';
define( 'REST_REQUEST', true );
require $qa_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( realpath( ABSPATH ) !== $qa_root || 'Bhranti isolated native adapter fixture' !== get_option( 'blogname' ) || ! DISABLE_WP_CRON || ! WP_HTTP_BLOCK_EXTERNAL || ! WP_DEBUG || ! defined( 'WC_VERSION' ) || '11.2.1' !== WC_VERSION ) { throw new RuntimeException( 'Wrong disposable native fixture.' ); }
foreach ( array( 'Bhranti_Identity' => 'identity', 'Bhranti_Actions' => 'actions', 'Bhranti_Commerce' => 'commerce', 'Bhranti_Commerce_Setup' => 'commerce-setup' ) as $class => $file ) {
 if ( hash_file( 'sha256', ( new ReflectionClass( $class ) )->getFileName() ) !== hash_file( 'sha256', dirname( __DIR__ ) . '/includes/class-bhranti-' . $file . '.php' ) ) { throw new RuntimeException( 'Current candidate runtime required.' ); }
}
$GLOBALS['cqa_checks'] = 0;
$GLOBALS['cqa_user'] = 0;
$GLOBALS['cqa_session'] = 0;
function cqa_check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['cqa_checks']; }
function cqa_result( $result, $label ) { cqa_check( is_array( $result ) && ! is_wp_error( $result ), $label . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) ); return $result; }
function cqa_action( $method, $path, $params = array() ) { return Bhranti_Actions::execute( array( 'tool' => 'commerce_request', 'args' => array( 'method' => $method, 'path' => $path, 'params' => $params ) ), $GLOBALS['cqa_user'], $GLOBALS['cqa_session'] ); }
function cqa_undo( $action, $label ) { $result = cqa_result( Bhranti_Actions::rollback( $action['action_id'], $GLOBALS['cqa_user'], $GLOBALS['cqa_session'] ), $label ); cqa_check( get_current_user_id() === $GLOBALS['cqa_user'], 'Undo keeps human executor' ); return $result; }
function cqa_decide( $proposal, $approved ) {
 cqa_check( ! empty( $proposal['confirmation_required'] ), 'exact confirmation proposal' );
 $run = array( 'id' => wp_generate_uuid4(), 'status' => 'awaiting_confirmation', 'phase' => '', 'step' => 1, 'requester_id' => $GLOBALS['cqa_user'], 'actor_id' => $GLOBALS['cqa_user'], 'context' => array(), 'history' => array(), 'receipts' => array(), 'configuration' => array( 'max_steps' => 12 ), 'confirmation' => $proposal + array( 'id' => wp_generate_uuid4(), 'expires' => time() + 600 ) );
 update_post_meta( $GLOBALS['cqa_session'], 'bhranti_run', wp_slash( $run ) );
 $request = new WP_REST_Request( 'POST', '/bhranti/v1/sessions/' . $GLOBALS['cqa_session'] . '/confirm' );
 $request->set_body_params( array( 'confirmation_id' => $run['confirmation']['id'], 'approved' => $approved ) );
 $response = rest_do_request( $request );
 cqa_check( 200 === $response->get_status(), 'owned native confirmation endpoint' );
 return $response->get_data();
}
$marker = 'Bhranti requester commerce QA ' . wp_generate_uuid4();
$baseline_posts = get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1 ) );
$baseline_users = get_users( array( 'fields' => 'ID' ) );
$baseline_roles = get_option( 'wp_user_roles' );
$baseline_settings = get_option( 'bhranti_settings', false );
$baseline_country = get_option( 'woocommerce_default_country', false );
$baseline_cod = get_option( 'woocommerce_cod_settings', false );
$http_before = $GLOBALS['cqa_http'];
$mail_before = $GLOBALS['cqa_mail'];
$category = 0;
$zone = 0;
try {
 $GLOBALS['cqa_user'] = wp_insert_user( array( 'user_login' => 'commerce-qa-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 40 ), 'user_email' => 'commerce-qa@example.invalid', 'role' => 'administrator' ) );
 cqa_check( ! is_wp_error( $GLOBALS['cqa_user'] ), 'fresh requesting human fixture' );
 wp_set_current_user( $GLOBALS['cqa_user'] );
 $grants = get_user_meta( $GLOBALS['cqa_user'], $GLOBALS['wpdb']->prefix . 'capabilities', true );
 cqa_check( current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_products' ), 'native Woo administrator capabilities exist without Bhranti grants' );
 $GLOBALS['cqa_session'] = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $GLOBALS['cqa_user'] ) );
 rest_get_server();
 $discovery = cqa_result( cqa_action( 'GET', '/wc/v3' ), 'native Woo discovery' );
 cqa_check( $discovery['data']['available'], 'official native Woo and endpoints discovered' );
 $created_category = cqa_result( cqa_action( 'POST', '/wc/v3/products/categories', array( 'name' => $marker, 'description' => 'Synthetic native adapter category.' ) ), 'native category creation' );
 $category = $created_category['data']['id'];
 $observed = array();
 $watch = static function ( $id ) use ( &$observed ) { $observed[] = array( get_current_user_id(), current_user_can( 'unfiltered_html' ), current_user_can( 'unfiltered_upload' ) ); };
 add_action( 'woocommerce_new_product', $watch );
 add_action( 'woocommerce_update_product', $watch );
 $product = cqa_result( cqa_action( 'POST', '/wc/v3/products', array( 'name' => $marker, 'type' => 'simple', 'regular_price' => '29.50', 'description' => '<p>Handmade fixture for native testing.</p>', 'manage_stock' => true, 'stock_quantity' => 5, 'categories' => array( array( 'id' => $category ) ) ) ), 'native draft product creation' );
 $id = $product['data']['id'];
 $native = wc_get_product( $id );
 cqa_check( 'draft' === $native->get_status() && '29.50' === $native->get_regular_price() && 5 === $native->get_stock_quantity(), 'native Woo saved price, stock and default draft' );
 cqa_check( (int) get_post( $id )->post_author === $GLOBALS['cqa_user'], 'native product authorship uses requesting human' );
 cqa_check( array( $category ) === $native->get_category_ids(), 'native product category saved' );
 $read = cqa_result( cqa_action( 'GET', '/wc/v3/products/' . $id ), 'native product read' );
 cqa_check( $read['data']['id'] === $id, 'native read returns exact product' );
 $updated = cqa_result( cqa_action( 'POST', '/wc/v3/products/' . $id, array( 'regular_price' => '35.00', 'stock_quantity' => 7 ) ), 'native product price and stock update' );
 $native = wc_get_product( $id );
 cqa_check( '35.00' === $native->get_regular_price() && 7 === $native->get_stock_quantity(), 'native product fields changed' );
 cqa_undo( $updated, 'native product Undo' );
 $native = wc_get_product( $id );
 cqa_check( '29.50' === $native->get_regular_price() && 5 === $native->get_stock_quantity(), 'Undo restores actual native price and stock' );
 cqa_check( is_wp_error( Bhranti_Actions::rollback( $updated['action_id'], $GLOBALS['cqa_user'], $GLOBALS['cqa_session'] ) ), 'product Undo cannot replay' );
 cqa_check( ! empty( $observed ) && array( array( $GLOBALS['cqa_user'], false, false ) ) === array_values( array_unique( $observed, SORT_REGULAR ) ), 'every native product write keeps human identity with HTML/upload bypass denied' );
 remove_action( 'woocommerce_new_product', $watch );
 remove_action( 'woocommerce_update_product', $watch );
 cqa_check( current_user_can( 'unfiltered_html' ), 'normal human HTML permission restored after tool execution' );
 $deny = static function ( $caps, $requested, $args, $user ) { if ( $user->ID === $GLOBALS['cqa_user'] ) { $caps['edit_products'] = false; } return $caps; };
 add_filter( 'user_has_cap', $deny, PHP_INT_MAX, 4 );
 cqa_check( is_wp_error( cqa_action( 'POST', '/wc/v3/products/' . $id, array( 'regular_price' => '99.00' ) ) ), 'native denied product capability blocks write' );
 remove_filter( 'user_has_cap', $deny, PHP_INT_MAX );
 cqa_check( '29.50' === wc_get_product( $id )->get_regular_price(), 'denied capability leaves price unchanged' );
 $settings = Bhranti_Settings::get();
 $settings['config']['permissions']['edit_content'] = false;
 update_option( 'bhranti_settings', $settings );
 cqa_check( is_wp_error( cqa_action( 'POST', '/wc/v3/products/' . $id, array( 'name' => $marker . ' blocked' ) ) ), 'live edit switch blocks commerce writes' );
 false === $baseline_settings ? delete_option( 'bhranti_settings' ) : update_option( 'bhranti_settings', $baseline_settings );
 $change = cqa_result( cqa_action( 'POST', '/wc/v3/products/' . $id, array( 'regular_price' => '31.00' ) ), 'fresh reversible product edit' );
 $native = wc_get_product( $id ); $native->set_regular_price( '32.00' ); $native->save();
 cqa_check( is_wp_error( Bhranti_Actions::rollback( $change['action_id'], $GLOBALS['cqa_user'], $GLOBALS['cqa_session'] ) ) && '32.00' === wc_get_product( $id )->get_regular_price(), 'Undo refuses later native product edit' );
 $trash = cqa_result( cqa_action( 'DELETE', '/wc/v3/products/' . $id ), 'product Trash proposal' );
 cqa_check( 'draft' === get_post_status( $id ), 'Trash makes no change before approval' );
 $cancel = cqa_decide( $trash, false );
 cqa_check( 'cancelled' === $cancel['run']['status'] && 'draft' === get_post_status( $id ), 'Cancel preserves draft product' );
 $trash = cqa_result( cqa_action( 'DELETE', '/wc/v3/products/' . $id ), 'fresh product Trash proposal' );
 cqa_decide( $trash, true );
 cqa_check( 'trash' === get_post_status( $id ), 'owned approval uses native Trash' );
 $run = get_post_meta( $GLOBALS['cqa_session'], 'bhranti_run', true );
 $hist = end( $run['history'] );
 $result = json_decode( substr( $hist['content'], strlen( 'WordPress tool result (untrusted site data): ' ) ), true );
 cqa_undo( $result['result'], 'native Trash Undo' );
 cqa_check( 'draft' === get_post_status( $id ), 'Trash Undo restores draft' );
 foreach ( array( array( 'GET', '/wc/v3/orders', array() ), array( 'POST', '/wc/v3/payment_gateways/stripe', array( 'enabled' => true ) ), array( 'POST', '/wc/v3/products', array( 'name' => $marker, 'images' => array( array( 'src' => 'https://example.invalid/remote.png' ) ) ) ) ) as $blocked ) {
  cqa_check( is_wp_error( cqa_action( ...$blocked ) ), 'unreviewed order, gateway or remote-image route rejected' );
 }
 $country = cqa_result( cqa_action( 'POST', '/wc/v3/settings/general/woocommerce_default_country', array( 'value' => 'IN:MH' ) ), 'native country setting update' );
 cqa_check( 'IN:MH' === get_option( 'woocommerce_default_country' ), 'native country setting persisted' );
 cqa_undo( $country, 'native store setting Undo' );
 cqa_check( $baseline_country === get_option( 'woocommerce_default_country', false ), 'store setting Undo restores native country' );
 $created_zone = cqa_result( cqa_action( 'POST', '/wc/v3/shipping/zones', array( 'name' => $marker, 'order' => 12 ) ), 'native shipping zone create' );
 $zone = $created_zone['data']['id'];
 cqa_check( WC_Shipping_Zones::get_zone( $zone )->get_zone_name() === $marker, 'native shipping zone saved' );
 $location = cqa_result( cqa_action( 'POST', '/wc/v3/shipping/zones/' . $zone . '/locations', array( 'locations' => array( array( 'code' => 'IN', 'type' => 'country' ) ) ) ), 'native shipping location save' );
 $shipping = cqa_result( cqa_action( 'POST', '/wc/v3/shipping/zones/' . $zone . '/methods', array( 'method_id' => 'flat_rate' ) ), 'native flat-rate shipping create' );
 $instance = $shipping['data']['id'];
 $rate = cqa_result( cqa_action( 'POST', '/wc/v3/shipping/zones/' . $zone . '/methods/' . $instance, array( 'settings' => array( 'cost' => '5.00', 'title' => 'Fixture delivery', 'tax_status' => 'none' ) ) ), 'native shipping rate update' );
 cqa_check( '5.00' === get_option( 'woocommerce_flat_rate_' . $instance . '_settings' )['cost'], 'native shipping cost persisted' );
 cqa_undo( $rate, 'native shipping rate Undo' );
 cqa_undo( $shipping, 'native shipping method creation Undo' );
 cqa_undo( $location, 'native shipping location Undo' );
 cqa_undo( $created_zone, 'native shipping zone creation Undo' );
 $zone = 0;
 $cod = cqa_result( cqa_action( 'POST', '/wc/v3/payment_gateways/cod', array( 'enabled' => true, 'settings' => array( 'instructions' => 'Pay on delivery at the fixture.' ) ) ), 'native offline COD update' );
 cqa_check( 'yes' === get_option( 'woocommerce_cod_settings' )['enabled'], 'native offline COD enabled' );
 cqa_undo( $cod, 'native offline COD Undo' );
 cqa_check( $baseline_cod === get_option( 'woocommerce_cod_settings', false ), 'offline COD Undo restores original native option' );
 $events = get_posts( array( 'post_type' => 'bhranti_event', 'post_status' => 'private', 'post_parent' => $GLOBALS['cqa_session'], 'posts_per_page' => -1, 'fields' => 'ids' ) );
 foreach ( $events as $event_id ) {
  $event = get_post_meta( $event_id, 'bhranti_event_data', true );
  cqa_check( $GLOBALS['cqa_user'] === (int) $event['requester_id'] && $GLOBALS['cqa_user'] === (int) $event['actor_id'], 'durable audit records the actual human requester/executor' );
 }
 cqa_check( ! empty( $events ), 'native operations retain durable audit events' );
 cqa_check( $grants === get_user_meta( $GLOBALS['cqa_user'], $GLOBALS['wpdb']->prefix . 'capabilities', true ) && $baseline_roles === get_option( 'wp_user_roles' ), 'Bhranti never grants or persists extra capabilities or roles' );
 cqa_check( false === get_option( 'bhranti_actor_id', false ) && ! get_role( 'bhranti_agent' ), 'commerce never provisions a separate actor account or role' );
 cqa_check( $http_before === $GLOBALS['cqa_http'], 'native commerce actions attempt no HTTP' );
 cqa_check( $mail_before + 1 === $GLOBALS['cqa_mail'] && 1 === count( $GLOBALS['cqa_mail_calls'] ) && in_array( 'WC_Email_Admin_Payment_Gateway_Enabled', $GLOBALS['cqa_mail_calls'][0], true ), 'native COD security notification is intercepted before mail delivery; no other mail is attempted' );
} finally {
 if ( $GLOBALS['cqa_user'] && ! is_wp_error( $GLOBALS['cqa_user'] ) ) { wp_set_current_user( $GLOBALS['cqa_user'] ); }
 if ( $zone ) { WC_Shipping_Zones::get_zone( $zone )->delete(); }
 if ( $category ) { wp_delete_term( $category, 'product_cat' ); }
 false === $baseline_settings ? delete_option( 'bhranti_settings' ) : update_option( 'bhranti_settings', $baseline_settings );
 $posts = get_posts( array( 'post_type' => array( 'product', 'bhranti_session', 'bhranti_event' ), 'post_status' => array( 'private', 'draft', 'publish', 'trash' ), 'posts_per_page' => -1, 'fields' => 'ids' ) );
 foreach ( array_diff( $posts, $baseline_posts ) as $post_id ) { wp_delete_post( $post_id, true ); }
 if ( $GLOBALS['cqa_user'] && ! is_wp_error( $GLOBALS['cqa_user'] ) ) { wp_delete_user( $GLOBALS['cqa_user'] ); }
 cqa_check( $baseline_users === get_users( array( 'fields' => 'ID' ) ), 'temporary human user cleaned up' );
}
echo 'PASS: ' . $GLOBALS['cqa_checks'] . " requester-only native Woo checks; no external HTTP, delivered mail or AI; one native COD security notification safely intercepted.\n";
