<?php
/**
 * Native setup/Undo integration on the already active local WooCommerce install.
 *
 * Run only after parent review/deployment with BHRANTI_TEST_ALLOW_MUTATION=1,
 * BHRANTI_TEST_SETUP_LIVE=1 and the exact BHRANTI_TEST_WP_ROOT. No AI is invoked.
 * Uses admin 1 and managed actor 11; all new content is uniquely marked. A private
 * checkpoint is retained on any cleanup or preservation failure. No SQL writes.
 *
 * @package Bhranti
 */

if ( '1' !== getenv( 'BHRANTI_TEST_SETUP_LIVE' ) ) {
	fwrite( STDERR, "Set BHRANTI_TEST_SETUP_LIVE=1 only after reviewing the fixture plan.\n" );
	exit( 1 );
}
define( 'BHRANTI_QA_SUPPORT_ONLY', true );
require __DIR__ . '/integration.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( 1 !== get_current_blog_id() || ! is_plugin_active( 'woocommerce/woocommerce.php' ) || ! defined( 'WC_VERSION' ) || '11.2.1' !== WC_VERSION || ! class_exists( 'Bhranti_Commerce_Setup' ) || realpath( BHRANTI_FILE ) !== realpath( WP_PLUGIN_DIR . '/bhranti/bhranti.php' ) || 11 !== (int) get_option( 'bhranti_actor_id' ) || 'admin' !== get_userdata( 1 )->user_login || 'bhranti' !== get_userdata( 11 )->user_login || wp_using_ext_object_cache() ) {
	throw new RuntimeException( 'Exact deployed local site, native Woo 11.2.1 and existing identities required; no fixtures created.' );
}

$sl = array( 'checks' => 0, 'http' => 0, 'mail' => 0, 'session' => 0, 'page' => 0, 'zone' => 0, 'posts' => array(), 'actions' => array(), 'options' => array(), 'observed' => array(), 'native' => array(), 'cart_binding' => null, 'phase' => 'setup', 'clean' => true );
$sl['marker'] = 'bhranti-setup-qa-' . str_replace( '-', '', wp_generate_uuid4() );
$sl['checkpoint'] = '/private/tmp/' . $sl['marker'] . '.json';
$sl['original_user'] = get_current_user_id();
$sl['failure'] = null;
wp_set_current_user( 1 );
rest_get_server();
if ( true !== Bhranti_Settings::allows( 'manage_site_settings' ) || ! user_can( 1, 'manage_woocommerce' ) ) {
	throw new RuntimeException( 'Existing administrator and enabled store-settings guardrail required; no fixtures created.' );
}

function sl_check( $value, $label ) {
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$GLOBALS['sl']['checks'];
}
function sl_raw( $name ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name=%s", $name ), ARRAY_A );
}
function sl_baseline() {
	global $wpdb;
	$queries = array(
		'posts' => "SELECT * FROM {$wpdb->posts} ORDER BY ID", 'postmeta' => "SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id",
		'users' => "SELECT * FROM {$wpdb->users} ORDER BY ID", 'usermeta' => "SELECT * FROM {$wpdb->usermeta} ORDER BY umeta_id",
		'terms' => "SELECT * FROM {$wpdb->terms} ORDER BY term_id", 'termmeta' => "SELECT * FROM {$wpdb->termmeta} ORDER BY meta_id",
		'taxonomy' => "SELECT * FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id", 'relationships' => "SELECT * FROM {$wpdb->term_relationships} ORDER BY object_id,term_taxonomy_id",
		'comments' => "SELECT * FROM {$wpdb->comments} ORDER BY comment_ID", 'commentmeta' => "SELECT * FROM {$wpdb->commentmeta} ORDER BY meta_id",
		'options' => "SELECT option_name,option_value,autoload FROM {$wpdb->options} ORDER BY option_name",
		'zones' => "SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zones ORDER BY zone_id",
		'locations' => "SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zone_locations ORDER BY location_id",
		'methods' => "SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zone_methods ORDER BY instance_id",
	);
	$result = array();
	foreach ( $queries as $key => $query ) { $result[ $key ] = hash( 'sha256', serialize( $wpdb->get_results( $query, ARRAY_A ) ) ); }
	return $result;
}
function sl_checkpoint() {
	$state = $GLOBALS['sl'];
	unset( $state['failure'], $state['native'] );
	$state['site'] = home_url( '/' );
	$bytes = wp_json_encode( $state );
	$path = $state['checkpoint'] . '.pending';
	$stream = fopen( $path, 'x' );
	if ( ! $stream || ! chmod( $path, 0600 ) ) { throw new RuntimeException( 'Private fixture checkpoint unavailable.' ); }
	try {
		if ( strlen( $bytes ) !== fwrite( $stream, $bytes ) || ! fflush( $stream ) ) { throw new RuntimeException( 'Fixture checkpoint incomplete.' ); }
	} finally { fclose( $stream ); }
	if ( ! rename( $path, $state['checkpoint'] ) ) { throw new RuntimeException( 'Fixture checkpoint could not be committed.' ); }
}
function sl_guard_option( $name ) {
	global $sl;
	if ( ! array_key_exists( $name, $sl['options'] ) ) {
		// The native instance key must belong to the newly created marked zone.
		if ( preg_match( '/\Awoocommerce_(flat_rate|free_shipping|local_pickup)_([1-9][0-9]*)_settings\z/', $name, $parts ) && $sl['zone'] ) {
			$method = WC_Data_Store::load( 'shipping-zone' )->get_method( (int) $parts[2] );
			if ( $method && (int) $method->zone_id === $sl['zone'] && $method->method_id === $parts[1] && null === sl_raw( $name ) ) {
				$sl['options'][ $name ] = null;
				$sl['observed'][ $name ] = null;
				sl_checkpoint();
				return;
			}
		}
		throw new RuntimeException( 'Unreviewed option mutation prevented: ' . sanitize_key( $name ) );
	}
}
function sl_observe_option( $name ) {
	sl_guard_option( $name );
	$GLOBALS['sl']['observed'][ $name ] = sl_raw( $name );
	sl_checkpoint();
}
function sl_native( $method, $path, $params = array() ) {
	$request = new WP_REST_Request( $method, $path );
	if ( 'GET' === $method ) { $request->set_query_params( $params ); }
	else { $request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( $params ) ); }
	return rest_do_request( $request );
}
function sl_action( $method, $path, $params = array() ) {
	$result = Bhranti_Actions::execute( array( 'tool' => 'commerce_request', 'args' => array( 'method' => $method, 'path' => $path, 'params' => $params ) ), 1, $GLOBALS['sl']['session'] );
	if ( is_array( $result ) && ! empty( $result['rollback_available'] ) ) {
		$GLOBALS['sl']['actions'][] = $result['action_id'];
		sl_checkpoint();
	}
	return $result;
}
function sl_success( $result, $label ) {
	sl_check( is_array( $result ) && ! is_wp_error( $result ), $label . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );
	return $result;
}
function sl_error( $result, $code, $label ) {
	sl_check( is_wp_error( $result ) && $code === $result->get_error_code(), $label . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );
}
function sl_undo( $id, $label = 'Native owned Undo succeeds' ) {
	$result = Bhranti_Actions::rollback( $id, 1, $GLOBALS['sl']['session'] );
	sl_success( $result, $label );
	$GLOBALS['sl']['actions'] = array_values( array_diff( $GLOBALS['sl']['actions'], array( $id ) ) );
	sl_checkpoint();
	return $result;
}
function sl_restore_fixture_cart() {
	global $sl;
	if ( ! $sl['cart_binding'] ) { return; }
	$binding = $sl['cart_binding'];
	$current = sl_raw( 'woocommerce_cart_page_id' );
	if ( $current !== $binding['before'] ) {
		sl_check( $current === $binding['expected'] && $current === $sl['observed']['woocommerce_cart_page_id'], 'Owned Cart binding still matches before exact native fixture restoration' );
		update_option( 'woocommerce_cart_page_id', maybe_unserialize( $binding['before']['option_value'] ) );
	}
	sl_check( sl_raw( 'woocommerce_cart_page_id' ) === $binding['before'], 'Original Cart assignment bytes and autoload restored without publishing original content' );
	$sl['cart_binding'] = null;
	sl_checkpoint();
}
function sl_roundtrip( $path, $params, $option ) {
	$before = sl_raw( $option );
	sl_success( sl_action( 'GET', $path ), 'Native setup read: ' . $option );
	$edit = sl_success( sl_action( 'POST', $path, $params ), 'Native setup write: ' . $option );
	sl_check( $before !== sl_raw( $option ), 'Saved native option really changed: ' . $option );
	sl_success( sl_action( 'GET', $path ), 'Native read after write: ' . $option );
	sl_undo( $edit['action_id'] );
	sl_check( $before === sl_raw( $option ), 'Exact original option bytes/autoload restored: ' . $option );
	sl_error( Bhranti_Actions::rollback( $edit['action_id'], 1, $GLOBALS['sl']['session'] ), 'bhranti_rollback_missing', 'Undo is single-use' );
}
function sl_policy( $enabled ) {
	$response = sl_native( 'GET', '/bhranti/v1/settings' );
	sl_check( 200 === $response->get_status(), 'Read live guardrail revision' );
	$data = $response->get_data();
	$data['config']['permissions']['manage_site_settings'] = $enabled;
	$response = sl_native( 'POST', '/bhranti/v1/settings', array( 'config' => $data['config'], 'revision' => $data['revision'] ) );
	sl_check( 200 === $response->get_status(), 'Update temporary guardrail using native Settings API' );
}

foreach ( array( 'bhranti_settings_lock', 'bhranti_actor_setup_lock', 'bhranti_commerce_lock' ) as $name ) { if ( null !== sl_raw( $name ) ) { throw new RuntimeException( 'A live operation holds a lock; no fixtures created.' ); } }
$sl['baseline'] = sl_baseline();
$sl['fresh_site'] = sl_raw( 'fresh_site' );
$sl['prior_posts'] = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) );
$known = array( 'bhranti_settings', 'bhranti_settings_lock', 'bhranti_actor_setup_lock', 'bhranti_commerce_lock', 'woocommerce_store_address', 'woocommerce_store_address_2', 'woocommerce_store_city', 'woocommerce_store_postcode', 'woocommerce_default_country', 'woocommerce_allowed_countries', 'woocommerce_specific_allowed_countries', 'woocommerce_all_except_countries', 'woocommerce_ship_to_countries', 'woocommerce_specific_ship_to_countries', 'woocommerce_weight_unit', 'woocommerce_dimension_unit', 'woocommerce_cod_settings', 'woocommerce_coming_soon', 'woocommerce_store_pages_only', 'woocommerce_cart_page_id', '_transient_shipping-transient-version', '_transient_timeout_shipping-transient-version', '_transient_woocommerce_admin_payment_gateway_suggestions_specs', '_transient_timeout_woocommerce_admin_payment_gateway_suggestions_specs' );
foreach ( $known as $name ) { $sl['options'][ $name ] = sl_raw( $name ); $sl['observed'][ $name ] = $sl['options'][ $name ]; }
sl_checkpoint();
$http_guard = static function () { ++$GLOBALS['sl']['http']; return new WP_Error( 'bhranti_setup_qa_offline', 'No network requests in setup fixtures.' ); };
$mail_guard = static function () { ++$GLOBALS['sl']['mail']; return true; };
$option_update = static function ( $value, $name ) {
	if ( 'fresh_site' === $name ) {
		// Core calls this even when already '0'. Permit only that exact no-op while
		// publishing our durably tracked fixture; no first-publish option mutation.
		$original = $GLOBALS['sl']['fresh_site'];
		$page = get_post( $GLOBALS['sl']['page'] );
		sl_check( '0' === $value && $original === sl_raw( $name ) && '0' === ( $original['option_value'] ?? null ) && doing_action( 'publish_page' ) && $page && 1 === (int) $page->post_author && $GLOBALS['sl']['marker'] . '-cart' === $page->post_title, 'Native fresh-site bookkeeping is a byte-preserving no-op for the owned Cart' );
		return $value;
	}
	sl_guard_option( $name );
	return $value;
};
$post_guard = static function ( $data, $postarr ) {
	global $sl;
	$id = (int) ( $postarr['ID'] ?? 0 );
	if ( $id && ! in_array( $id, $sl['posts'], true ) ) { throw new RuntimeException( 'Native hook attempted to change pre-existing content.' ); }
	if ( ! $id && 'revision' === $data['post_type'] && (int) $data['post_parent'] === $sl['page'] && $sl['page'] ) { return $data; }
	if ( ! $id && ! in_array( $data['post_type'], array( 'bhranti_event', 'bhranti_session', 'page' ), true ) ) { throw new RuntimeException( 'Unexpected native content creation.' ); }
	if ( ! $id && 'bhranti_event' !== $data['post_type'] && ! str_starts_with( wp_unslash( $data['post_title'] ), $sl['marker'] ) ) { throw new RuntimeException( 'Unmarked fixture creation.' ); }
	return $data;
};
$post_observer = static function ( $id, $post, $update ) {
	global $sl;
	if ( ! $update ) {
		sl_check( ! in_array( (int) $id, $sl['prior_posts'], true ), 'New fixture or audit ID is not pre-existing' );
		$sl['posts'][] = (int) $id;
		if ( 'bhranti_session' === $post->post_type ) { $sl['session'] = (int) $id; }
		if ( 'page' === $post->post_type ) { $sl['page'] = (int) $id; }
		sl_checkpoint();
	}
};
$zone_observer = static function ( $zone ) {
	global $sl;
	if ( str_starts_with( $zone->get_zone_name( 'edit' ), $sl['marker'] ) ) { $sl['zone'] = (int) $zone->get_id(); sl_checkpoint(); }
};
$native_observer = static function ( $response, $handler, $request ) {
	if ( str_starts_with( $request->get_route(), '/wc/' ) || '/wc-admin/options' === $request->get_route() ) {
		$GLOBALS['sl']['native'][] = array( 'method' => $request->get_method(), 'path' => $request->get_route(), 'actor' => get_current_user_id(), 'phase' => $GLOBALS['sl']['phase'] );
	}
	return $response;
};
$queue_guard = bhranti_qa_runner_veto( static function () { return array( $GLOBALS['sl']['session'] ); } );
add_filter( 'pre_http_request', $http_guard, PHP_INT_MAX );
add_filter( 'pre_wp_mail', $mail_guard, PHP_INT_MAX );
add_filter( 'pre_update_option', $option_update, PHP_INT_MAX, 2 );
add_action( 'add_option', 'sl_guard_option', PHP_INT_MAX );
add_action( 'delete_option', 'sl_guard_option', PHP_INT_MAX );
add_action( 'added_option', 'sl_observe_option', PHP_INT_MAX );
add_action( 'updated_option', 'sl_observe_option', PHP_INT_MAX );
add_action( 'deleted_option', 'sl_observe_option', PHP_INT_MAX );
add_filter( 'wp_insert_post_data', $post_guard, PHP_INT_MAX, 2 );
add_action( 'wp_insert_post', $post_observer, -9999, 3 );
add_action( 'woocommerce_after_shipping_zone_object_save', $zone_observer, -9999 );
add_filter( 'rest_request_before_callbacks', $native_observer, PHP_INT_MAX, 3 );

try {
	$session = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => 1, 'post_title' => $sl['marker'] ), true );
	sl_check( is_int( $session ) && $session === $sl['session'], 'Fresh marked private session owned by existing administrator' );
	$sl['phase'] = 'adapter';
	$created = sl_success( sl_action( 'POST', '/wc/v3/shipping/zones', array( 'name' => $sl['marker'], 'order' => 1000 ) ), 'Native shipping zone creation' );
	$sl['zone'] = (int) $created['data']['id'];
	sl_checkpoint();
	$zone_path = '/wc/v3/shipping/zones/' . $sl['zone'];
	$locations = sl_success( sl_action( 'POST', $zone_path . '/locations', array( 'locations' => array( array( 'type' => 'country', 'code' => 'IN' ) ) ) ), 'Native JSON-array shipping location save' );
	sl_check( 'IN' === sl_success( sl_action( 'GET', $zone_path . '/locations' ), 'Native location readback' )['data'][0]['code'], 'Country location actually persisted' );
	sl_error( Bhranti_Actions::rollback( $created['action_id'], 1, $sl['session'] ), 'bhranti_rollback_conflict', 'Zone creation Undo cannot remove later location changes' );
	foreach ( array( 'flat_rate', 'free_shipping', 'local_pickup' ) as $slug ) {
		$settings = 'free_shipping' === $slug ? array( 'title' => 'Fixture delivery', 'requires' => 'min_amount', 'min_amount' => '100.00', 'ignore_discounts' => 'no' ) : array( 'title' => 'Fixture delivery', 'tax_status' => 'none', 'cost' => '25.00' );
		$method = sl_success( sl_action( 'POST', $zone_path . '/methods', array( 'method_id' => $slug, 'enabled' => true, 'settings' => $settings ) ), 'Actual native shipping method creation: ' . $slug );
		$method_path = $zone_path . '/methods/' . $method['data']['id'];
		$before = sl_success( sl_action( 'GET', $method_path ), 'Read created method' )['data'];
		$edit = sl_success( sl_action( 'POST', $method_path, array( 'enabled' => false, 'order' => 3, 'settings' => array( 'title' => 'Edited fixture delivery' ) ) ), 'Edit native method settings' );
		sl_check( false === sl_success( sl_action( 'GET', $method_path ), 'Read changed method' )['data']['enabled'], 'Method disabled in actual saved data' );
		sl_undo( $edit['action_id'] );
		sl_check( $before === sl_success( sl_action( 'GET', $method_path ), 'Read restored method' )['data'], 'Method Undo restores native fields and options' );
		sl_undo( $method['action_id'], 'Only owned unchanged method is removed by native creation Undo' );
		sl_check( ! WC_Data_Store::load( 'shipping-zone' )->get_method( (int) $method['data']['id'] ), 'Native method row removed' );
	}
	sl_undo( $locations['action_id'] );
	foreach ( array( 1, 11 ) as $denied_user ) {
		$deny = static function ( $caps, $cap, $user ) use ( $denied_user ) { return $user === $denied_user && 'manage_woocommerce' === $cap ? array( 'do_not_allow' ) : $caps; };
		add_filter( 'map_meta_cap', $deny, PHP_INT_MAX, 3 );
		try { sl_error( sl_action( 'POST', $zone_path, array( 'name' => $sl['marker'] . '-denied' ) ), 'bhranti_commerce_permission', 'Native human/actor permission independently denied' ); }
		finally { remove_filter( 'map_meta_cap', $deny, PHP_INT_MAX ); }
	}
	sl_policy( false );
	try {
		sl_error( sl_action( 'POST', $zone_path, array( 'name' => $sl['marker'] . '-blocked' ) ), 'bhranti_action_disabled', 'Live store-settings guardrail stops mutation' );
		sl_success( sl_action( 'GET', $zone_path ), 'Disabled write guardrail preserves native reads' );
	} finally { sl_policy( true ); }
	$edit = sl_success( sl_action( 'POST', $zone_path, array( 'name' => $sl['marker'] . '-edited' ) ), 'Native zone edit gets owned Undo' );
	$sl['phase'] = 'fixture';
	sl_check( 200 === sl_native( 'POST', $zone_path, array( 'name' => $sl['marker'] . '-concurrent' ) )->get_status(), 'Fixture-only native concurrent edit' );
	$sl['phase'] = 'adapter';
	sl_error( Bhranti_Actions::rollback( $edit['action_id'], 1, $sl['session'] ), 'bhranti_rollback_conflict', 'Undo refuses an actual later native edit' );
	$sl['phase'] = 'fixture';
	sl_check( $sl['marker'] . '-concurrent' === WC_Shipping_Zones::get_zone_by( 'zone_id', $sl['zone'] )->get_zone_name( 'edit' ), 'Owned conflict state still matches before fixture cleanup' );
	sl_check( 200 === sl_native( 'POST', $zone_path, array( 'name' => $sl['marker'] . '-edited' ) )->get_status(), 'Restore only owned fixture conflict' );
	$sl['phase'] = 'adapter';
	sl_undo( $edit['action_id'] );
	$uncertain_calls = 0;
	$fail_after_write = static function ( $response, $handler, $request ) use ( $zone_path, &$uncertain_calls ) {
		if ( $zone_path === $request->get_route() && 'POST' === $request->get_method() && $GLOBALS['sl']['marker'] . '-uncertain' === $request['name'] ) { ++$uncertain_calls; return new WP_Error( 'bhranti_setup_qa_after_save', 'Owned fixture response fails after native save.', array( 'status' => 500 ) ); }
		return $response;
	};
	add_filter( 'rest_request_after_callbacks', $fail_after_write, PHP_INT_MAX, 3 );
	try { $uncertain = sl_action( 'POST', $zone_path, array( 'name' => $sl['marker'] . '-uncertain' ) ); }
	finally { remove_filter( 'rest_request_after_callbacks', $fail_after_write, PHP_INT_MAX ); }
	sl_check( is_wp_error( $uncertain ) && true === ( $uncertain->get_error_data()['uncertain_action'] ?? false ) && 1 === $uncertain_calls, 'Real post-save error is uncertain and native write is not replayed' );
	sl_check( $sl['marker'] . '-uncertain' === sl_success( sl_action( 'GET', $zone_path ), 'Inspect actual saved uncertain result' )['data']['name'], 'Read verifies native write really happened once' );
	$sl['phase'] = 'fixture';
	sl_check( 200 === sl_native( 'POST', $zone_path, array( 'name' => $sl['marker'] ) )->get_status(), 'Guarded cleanup of only the marked uncertain zone state' );
	$sl['phase'] = 'adapter';
	sl_undo( $created['action_id'] );
	sl_check( false === WC_Shipping_Zones::get_zone_by( 'zone_id', $sl['zone'] ), 'Owned empty shipping zone removed by native Undo' );
	$sl['zone'] = 0;
	foreach ( array( 'woocommerce_store_city' => $sl['marker'], 'woocommerce_default_country' => 'IN:MH', 'woocommerce_specific_allowed_countries' => array( 'IN' ), 'woocommerce_specific_ship_to_countries' => array( 'IN' ), 'woocommerce_allowed_countries' => 'specific', 'woocommerce_ship_to_countries' => 'specific', 'woocommerce_weight_unit' => 'kg', 'woocommerce_dimension_unit' => 'cm' ) as $name => $value ) {
		$group = in_array( $name, array( 'woocommerce_weight_unit', 'woocommerce_dimension_unit' ), true ) ? 'products' : 'general';
		if ( get_option( $name ) === $value ) { $value = is_array( $value ) ? array( 'US' ) : ( 'kg' === $value ? 'lbs' : ( 'cm' === $value ? 'in' : ( 'specific' === $value ? 'all' : 'US:CA' ) ) ); }
		sl_roundtrip( '/wc/v3/settings/' . $group . '/' . $name, array( 'value' => $value ), $name );
	}
	$cod_before = get_option( 'woocommerce_cod_settings', array() );
	// Woo 11.2.1 sends an admin email when a gateway is enabled. Disable only this
	// synthetic COD roundtrip's native notification, leaving production hooks intact.
	$cod_notification_vetoes = 0;
	$cod_email_guard = static function ( $enabled, $gateway, $email ) use ( &$cod_notification_vetoes ) {
		if ( $gateway instanceof WC_Gateway_COD && 'cod' === $gateway->id && $email instanceof WC_Email_Admin_Payment_Gateway_Enabled ) {
			++$cod_notification_vetoes;
			return false;
		}
		return $enabled;
	};
	add_filter( 'woocommerce_email_enabled_admin_payment_gateway_enabled', $cod_email_guard, PHP_INT_MAX, 3 );
	try {
		sl_roundtrip( '/wc/v3/payment_gateways/cod', array( 'enabled' => 'yes' !== ( $cod_before['enabled'] ?? 'no' ), 'title' => 'Fixture pay on delivery', 'description' => 'Fixture offline payment description.', 'settings' => array( 'instructions' => 'Fixture cash on delivery.', 'enable_for_virtual' => 'no', 'enable_for_methods' => array() ) ), 'woocommerce_cod_settings' );
	} finally { remove_filter( 'woocommerce_email_enabled_admin_payment_gateway_enabled', $cod_email_guard, PHP_INT_MAX ); }
	sl_check( 1 === $cod_notification_vetoes && 0 === $sl['mail'], 'Native COD enabled notification occurs once and is vetoed before mail only for the fixture' );
	// Track the draft durably before publication hooks can run. Native page-setting
	// choices include only published pages, so publish before assigning our fixture.
	$sl['phase'] = 'fixture';
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_author' => 1, 'post_title' => $sl['marker'] . '-cart', 'post_content' => '<!-- wp:paragraph --><p>Temporary native setup fixture.</p><!-- /wp:paragraph -->' ), true );
	sl_check( is_int( $page ) && $page === $sl['page'], 'Fresh marked Cart fixture isolates native visibility invalidation' );
	sl_check( $page === wp_update_post( array( 'ID' => $page, 'post_status' => 'publish' ), true ), 'Publish the already checkpointed fixture before native page assignment' );
	$sl['phase'] = 'adapter';
	$cart = null;
	$original_cart = sl_raw( 'woocommerce_cart_page_id' );
	if ( $original_cart && 'draft' === get_post_status( (int) $original_cart['option_value'] ) ) {
		// Native REST cannot restore an existing draft selection. Prove the public
		// safeguard, then isolate only this fixture with an explicitly recorded bind.
		sl_error( sl_action( 'POST', '/wc/v3/settings/advanced/woocommerce_cart_page_id', array( 'value' => (string) $page ) ), 'bhranti_commerce_preservation', 'Existing draft Cart assignment stays protected from unrestorable replacement' );
		sl_check( $original_cart === sl_raw( 'woocommerce_cart_page_id' ) && 'draft' === get_post_status( (int) $original_cart['option_value'] ), 'Refusal leaves the original draft and assignment untouched' );
		$sl['cart_binding'] = array( 'before' => $original_cart, 'expected' => array( 'option_value' => (string) $page, 'autoload' => $original_cart['autoload'] ) );
		sl_checkpoint();
		sl_check( $original_cart === sl_raw( 'woocommerce_cart_page_id' ) && $original_cart === $sl['observed']['woocommerce_cart_page_id'], 'Original assignment matches the durable checkpoint before fixture-only bind' );
		$sl['phase'] = 'fixture';
		update_option( 'woocommerce_cart_page_id', (string) $page );
		sl_check( $sl['cart_binding']['expected'] === sl_raw( 'woocommerce_cart_page_id' ), 'Native fixture-only Cart binding has the exact expected bytes and autoload' );
		$sl['phase'] = 'adapter';
	} else {
		$cart = sl_success( sl_action( 'POST', '/wc/v3/settings/advanced/woocommerce_cart_page_id', array( 'value' => (string) $page ) ), 'Temporarily bind native Cart to the owned fixture' );
	}
	$sl['phase'] = 'fixture';
	sl_check( $page === wp_update_post( array( 'ID' => $page, 'post_status' => 'draft' ), true ), 'Only the assigned owned Cart becomes draft for the preservation test' );
	$sl['phase'] = 'adapter';
	$visibility_before = sl_raw( 'woocommerce_coming_soon' );
	sl_error( sl_action( 'POST', '/wc-admin/options', array( 'woocommerce_coming_soon' => 'yes' === get_option( 'woocommerce_coming_soon' ) ? 'no' : 'yes' ) ), 'bhranti_commerce_preservation', 'Visibility refuses native implicit publication of a draft Cart' );
	sl_check( 'draft' === get_post_status( $page ) && $visibility_before === sl_raw( 'woocommerce_coming_soon' ), 'Draft Cart refusal makes no option or content changes' );
	$sl['phase'] = 'fixture';
	sl_check( $page === wp_update_post( array( 'ID' => $page, 'post_status' => 'publish' ), true ), 'Publish only the fresh fixture before native visibility tests' );
	$sl['phase'] = 'adapter';
	foreach ( array( 1, 11 ) as $denied_user ) {
		$deny = static function ( $caps, $cap, $user ) use ( $denied_user ) { return $user === $denied_user && 'publish_pages' === $cap ? array( 'do_not_allow' ) : $caps; };
		add_filter( 'map_meta_cap', $deny, PHP_INT_MAX, 3 );
		try { sl_error( sl_action( 'POST', '/wc-admin/options', array( 'woocommerce_coming_soon' => 'yes' === get_option( 'woocommerce_coming_soon' ) ? 'no' : 'yes' ) ), 'bhranti_commerce_permission', 'Cart publishing capability required independently for human and actor' ); }
		finally { remove_filter( 'map_meta_cap', $deny, PHP_INT_MAX ); }
	}
	foreach ( array( 'woocommerce_coming_soon', 'woocommerce_store_pages_only' ) as $name ) {
		sl_roundtrip( '/wc-admin/options', array( $name => 'yes' === get_option( $name ) ? 'no' : 'yes' ), $name );
	}
	if ( $cart ) { sl_undo( $cart['action_id'] ); }
	else { sl_restore_fixture_cart(); }
	$events = array();
	foreach ( $sl['posts'] as $id ) {
		if ( 'bhranti_event' !== get_post_type( $id ) ) { continue; }
		$event = get_post_meta( $id, 'bhranti_event_data', true );
		if ( (int) ( $event['session_id'] ?? 0 ) !== $sl['session'] ) { continue; }
		sl_check( 1 === (int) $event['requester_id'] && 'admin' === $event['requester'] && 11 === (int) $event['actor_id'] && 'bhranti' === $event['actor'], 'Every commerce audit records actual requester and actor identities' );
		$events[] = $event['type'];
	}
	foreach ( array( 'action_started', 'action_completed', 'action_blocked', 'action_failed', 'rollback_started', 'rollback_completed' ) as $type ) { sl_check( in_array( $type, $events, true ), 'Durable native audit outcome exists: ' . $type ); }
	foreach ( $sl['native'] as $native ) { if ( 'adapter' === $native['phase'] ) { sl_check( 11 === $native['actor'], 'Native Woo callback executes as managed actor' ); } }
	sl_check( 1 === get_current_user_id() && 0 === $sl['http'] && 0 === $sl['mail'] && array() === Bhranti_QA_Model::$calls, 'Identity restored with no HTTP, mail or AI calls' );
} catch ( Throwable $error ) { $sl['failure'] = $error; }
finally {
	$sl['phase'] = 'cleanup';
	wp_set_current_user( 1 );
	// Undo only known completed actions in reverse order; never replay uncertain writes.
	foreach ( array_reverse( $sl['actions'] ) as $id ) {
		try { sl_undo( $id, 'Cleanup restores only a completed owned action' ); }
		catch ( Throwable $error ) { $sl['clean'] = false; }
	}
	try { sl_restore_fixture_cart(); }
	catch ( Throwable $error ) { $sl['clean'] = false; }
	// Uncertain/conflicted resources deliberately stay in the private checkpoint for review.
	if ( $sl['zone'] && false !== WC_Shipping_Zones::get_zone_by( 'zone_id', $sl['zone'] ) ) { $sl['clean'] = false; }
	foreach ( array_reverse( $sl['posts'] ) as $id ) {
		$post = get_post( $id );
		if ( ! $post ) { continue; }
		$safe = ! in_array( $id, $sl['prior_posts'], true ) && ( 1 === (int) $post->post_author || ( 'revision' === $post->post_type && 11 === (int) $post->post_author && (int) $post->post_parent === $sl['page'] ) );
		if ( 'bhranti_event' === $post->post_type ) {
			$event = get_post_meta( $id, 'bhranti_event_data', true );
			$safe = $safe && ( (int) ( $event['session_id'] ?? -1 ) === $sl['session'] || ( 0 === (int) ( $event['session_id'] ?? -1 ) && in_array( $event['type'] ?? '', array( 'settings_started', 'settings_completed', 'settings_failed' ), true ) ) );
		} elseif ( 'revision' === $post->post_type ) { $safe = $safe && (int) $post->post_parent === $sl['page']; }
		else { $safe = $safe && in_array( $post->post_type, array( 'page', 'bhranti_session' ), true ) && str_starts_with( $post->post_title, $sl['marker'] ); }
		if ( $sl['cart_binding'] && ( $id === $sl['page'] || ( 'revision' === $post->post_type && (int) $post->post_parent === $sl['page'] ) ) ) { $safe = false; }
		if ( $safe && ( 'bhranti_session' !== $post->post_type || $sl['clean'] ) ) { $sl['clean'] = (bool) wp_delete_post( $id, true ) && $sl['clean']; }
		else { $sl['clean'] = false; }
	}
	// Only temporary policy/cache options need explicit recovery; store data uses owned Undo.
	foreach ( $sl['options'] as $name => $before ) {
		$current = sl_raw( $name );
		if ( $current === $before ) { continue; }
		$recoverable = 'bhranti_settings' === $name || str_starts_with( $name, '_transient_' );
		if ( ! $recoverable || $current !== $sl['observed'][ $name ] ) { $sl['clean'] = false; continue; }
		if ( null === $before ) { delete_option( $name ); }
		else {
			$value = maybe_unserialize( $before['option_value'] );
			update_option( $name, $value );
			$autoload = in_array( $before['autoload'], wp_autoload_values_to_autoload(), true );
			if ( array_key_exists( $name, wp_load_alloptions( true ) ) !== $autoload ) { wp_set_option_autoload_values( array( $name => $autoload ) ); }
		}
		if ( sl_raw( $name ) !== $before ) { $sl['clean'] = false; }
	}
	wp_set_current_user( $sl['original_user'] );
	$sl['after'] = sl_baseline();
	$sl['preserved'] = $sl['after'] === $sl['baseline'];
	sl_checkpoint();
	if ( $sl['clean'] && $sl['preserved'] ) { unlink( $sl['checkpoint'] ); }
	echo wp_json_encode( array( 'checks' => $sl['checks'], 'fixtures_removed' => $sl['clean'], 'original_rows_options_roles_plugins_preserved' => $sl['preserved'], 'changed_baseline_sections' => array_keys( array_diff_assoc( $sl['after'], $sl['baseline'] ) ), 'http_calls' => $sl['http'], 'mail_calls' => $sl['mail'], 'checkpoint_retained' => ! $sl['clean'] || ! $sl['preserved'] ) ), "\n";
	if ( ! $sl['clean'] || ! $sl['preserved'] ) { echo 'Private recovery checkpoint: ' . $sl['checkpoint'] . "\n"; }
	// Keep HTTP/mail guards through native shutdown. The process ends immediately below.
	restore_error_handler();
}
if ( $sl['failure'] || ! $sl['clean'] || ! $sl['preserved'] ) {
	fwrite( STDERR, $sl['failure'] ? $sl['failure']->getMessage() . "\n" : "Cleanup or preservation proof failed.\n" );
	exit( 1 );
}
echo 'PASS ' . $sl['checks'] . " live native WooCommerce setup checks.\n";
