<?php
/** Current server action policy checks against disposable site-local fixtures. @package Bhranti */

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ProviderRegistry;

define( 'BHRANTI_QA_SUPPORT_ONLY', true );
require __DIR__ . '/integration.php';
if ( ! method_exists( 'Bhranti_Settings', 'allows' ) || realpath( BHRANTI_FILE ) !== realpath( WP_PLUGIN_DIR . '/bhranti/bhranti.php' ) ) { fwrite( STDERR, "Deploy the permission-aware plugin on https://bhranti.wp.local/ first.\n" ); exit( 1 ); }

$permission_checks = 0;
$permission_users = array();
$permission_posts = array();
$permission_sessions = array();
$permission_comments = array();
$permission_terms = array();
$permission_options = array();
$permission_http = array();
$permission_failure = null;
$permission_preserved = false;
$permission_original_user = get_current_user_id();
$permission_marker = 'bhranti-permission-qa-' . str_replace( '-', '', wp_generate_uuid4() );

function bhranti_permission_check( $condition, $label ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$GLOBALS['permission_checks'];
}
function bhranti_permission_rest( $method, $path, $params = array(), $json = false ) {
	$request = new WP_REST_Request( $method, '/bhranti/v1' . $path );
	if ( $json ) { $request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( $params ) ); } else { $request->set_body_params( $params ); }
	return rest_do_request( $request );
}
function bhranti_permission_command( $method, $path, $params = array() ) { return array( 'tool' => 'wp_request', 'args' => array( 'method' => $method, 'path' => $path, 'params' => $params ) ); }
function bhranti_permission_success( $result, $label ) {
	bhranti_permission_check( is_array( $result ) && ! is_wp_error( $result ), $label . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );
	return $result;
}
function bhranti_permission_raw_option( $name ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name=%s", $name ), ARRAY_A );
}
function bhranti_permission_restore_option( $name, $raw ) {
	global $wpdb;
	if ( null === $raw ) { $wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) ); }
	elseif ( null === bhranti_permission_raw_option( $name ) ) { $wpdb->insert( $wpdb->options, array_merge( array( 'option_name' => $name ), $raw ), array( '%s', '%s', '%s' ) ); }
	else { $wpdb->update( $wpdb->options, $raw, array( 'option_name' => $name ), array( '%s', '%s' ), array( '%s' ) ); }
	wp_cache_delete( $name, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
}
function bhranti_permission_config( $permissions ) {
	$stored = bhranti_permission_rest( 'GET', '/settings' )->get_data();
	$stored['config']['permissions'] = $permissions;
	$response = bhranti_permission_rest( 'POST', '/settings', $stored, true );
	bhranti_permission_check( 200 === $response->get_status(), 'Authorized current action policy saves' );
	return $response->get_data();
}
function bhranti_permission_native_state() {
	global $wpdb;
	return hash( 'sha256', wp_json_encode( array(
		$wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_type NOT IN ('bhranti_session','bhranti_event') ORDER BY ID", ARRAY_A ),
		$wpdb->get_results( "SELECT m.* FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON m.post_id=p.ID WHERE p.post_type NOT IN ('bhranti_session','bhranti_event') ORDER BY meta_id", ARRAY_A ),
		$wpdb->get_results( "SELECT * FROM {$wpdb->comments} ORDER BY comment_ID", ARRAY_A ),
		$wpdb->get_results( "SELECT * FROM {$wpdb->commentmeta} ORDER BY meta_id", ARRAY_A ),
		$wpdb->get_results( "SELECT * FROM {$wpdb->terms} ORDER BY term_id", ARRAY_A ),
		$wpdb->get_results( "SELECT term_taxonomy_id,term_id,taxonomy,description,parent FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id", ARRAY_A ),
		$wpdb->get_results( "SELECT * FROM {$wpdb->term_relationships} ORDER BY object_id,term_taxonomy_id", ARRAY_A ),
	) ) );
}
function bhranti_permission_post( $type, $author, $suffix, $extra = array() ) {
	$id = wp_insert_post( wp_slash( array_merge( array( 'post_type' => $type, 'post_author' => $author, 'post_status' => 'draft', 'post_title' => $GLOBALS['permission_marker'] . '-' . $suffix, 'post_content' => 'Disposable permission fixture.' ), $extra ) ), true );
	bhranti_permission_check( is_int( $id ) && $id > 0, 'Fresh ' . $type . ' fixture created' );
	$GLOBALS['permission_posts'][] = $id;
	return $id;
}
function bhranti_permission_denied( $result, $label ) {
	bhranti_permission_check( is_wp_error( $result ) && 'bhranti_action_disabled' === $result->get_error_code(), $label . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );
}

$permission_registry = new ReflectionProperty( AiClient::class, 'defaultRegistry' );
$permission_registry->setAccessible( true );
$permission_saved_registry = AiClient::defaultRegistry();
$permission_saved_dispatcher = AiClient::getEventDispatcher();
$permission_saved_cache = AiClient::getCache();
$permission_registry->setValue( null, new ProviderRegistry() );
AiClient::setEventDispatcher( null ); AiClient::setCache( null );
$permission_http_guard = static function ( $response, $args, $url ) { $GLOBALS['permission_http'][] = $url; return new WP_Error( 'bhranti_permission_qa_no_http' ); };
$permission_runner_veto = bhranti_qa_runner_veto( static function () { return $GLOBALS['permission_sessions'] ?? array(); } );
add_filter( 'pre_http_request', $permission_http_guard, PHP_INT_MAX, 3 ); add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
$permission_baseline = bhranti_permission_native_state();

try {
	foreach ( array( 'bhranti_settings', 'bhranti_settings_lock', 'blogdescription' ) as $name ) { $permission_options[ $name ] = bhranti_permission_raw_option( $name ); }
	bhranti_permission_check( ! get_option( 'bhranti_settings_lock' ), 'No existing Settings save is interrupted' );
	$login = 'bhranti_permission_qa_' . str_replace( '-', '', wp_generate_uuid4() );
	$owner = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test', 'role' => 'administrator' ) );
	bhranti_permission_check( is_int( $owner ) && $owner > 0, 'Disposable policy administrator created' ); $permission_users[] = $owner; wp_set_current_user( $owner );
	$session = bhranti_permission_rest( 'POST', '/sessions' )->get_data()['id']; $permission_sessions[] = $session;
	$all = array_fill_keys( array_keys( Bhranti_Settings::permission_defaults() ), true ); bhranti_permission_config( $all );
	$permission_page_id = bhranti_permission_post( 'page', $owner, 'content' );
	$publication = bhranti_permission_post( 'page', $owner, 'publication' );
	$trash = bhranti_permission_post( 'page', $owner, 'trash' );
	$media = bhranti_permission_post( 'attachment', $owner, 'media', array( 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg', 'post_content' => '', 'post_excerpt' => 'Original caption.', 'guid' => home_url( '/bhranti-disposable-metadata.jpg' ) ) );
	$styles = bhranti_permission_post( 'wp_global_styles', $owner, 'styles', array( 'post_status' => 'publish', 'post_content' => wp_json_encode( array( 'version' => 3, 'isGlobalStylesUserThemeJSON' => true, 'styles' => new stdClass(), 'settings' => new stdClass() ) ) ) );
	$menu = wp_insert_term( $permission_marker . '-menu', 'nav_menu' ); bhranti_permission_check( ! is_wp_error( $menu ), 'Unassigned temporary navigation menu created' ); $menu_id = $menu['term_id']; $permission_terms[] = array( $menu_id, 'nav_menu' );
	$comment = wp_insert_comment( array( 'comment_post_ID' => $permission_page_id, 'comment_content' => 'Original disposable comment.', 'comment_approved' => '0', 'user_id' => $owner, 'comment_author' => 'QA', 'comment_author_email' => 'qa@example.test' ) ); bhranti_permission_check( $comment > 0, 'Disposable unapproved comment created' ); $permission_comments[] = $comment;
	$cases = array(
		'edit_content' => bhranti_permission_command( 'PATCH', '/wp/v2/pages/' . $permission_page_id, array( 'title' => $permission_marker . '-updated' ) ),
		'publish_content' => bhranti_permission_command( 'PATCH', '/wp/v2/pages/' . $publication, array( 'status' => 'publish' ) ),
		'trash_content' => bhranti_permission_command( 'DELETE', '/wp/v2/pages/' . $trash ),
		'manage_comments' => bhranti_permission_command( 'PATCH', '/wp/v2/comments/' . $comment, array( 'content' => 'Updated disposable comment.' ) ),
		'manage_media' => bhranti_permission_command( 'PATCH', '/wp/v2/media/' . $media, array( 'caption' => 'Updated disposable caption.' ) ),
		'manage_navigation' => bhranti_permission_command( 'PATCH', '/wp/v2/menus/' . $menu_id, array( 'description' => 'Updated disposable menu.' ) ),
		'manage_design' => bhranti_permission_command( 'PATCH', '/wp/v2/global-styles/' . $styles, array( 'styles' => array( 'color' => array( 'background' => '#fedcba' ) ) ) ),
		'manage_site_settings' => bhranti_permission_command( 'POST', '/wp/v2/settings', array( 'description' => 'Disposable Bhranti policy fixture.' ) ),
	);
	foreach ( $cases as $key => $command ) {
		$disabled = $all; $disabled[ $key ] = false; bhranti_permission_config( $disabled );
		$before = bhranti_permission_native_state(); $snapshots = get_post_meta( $session, 'bhranti_actions', true ); $description = bhranti_permission_raw_option( 'blogdescription' );
		bhranti_permission_denied( Bhranti_Actions::execute( $command, $owner, $session, true ), 'Disabled ' . $key . ' blocks even a direct confirmed command' );
		bhranti_permission_check( $before === bhranti_permission_native_state() && $description === bhranti_permission_raw_option( 'blogdescription' ) && $snapshots === get_post_meta( $session, 'bhranti_actions', true ), 'Disabled ' . $key . ' changes no native resource, option or rollback record' );
		$read = Bhranti_Actions::execute( bhranti_permission_command( 'GET', '/wp/v2/types' ), $owner, $session ); bhranti_permission_success( $read, 'Disabling ' . $key . ' preserves bounded reviewed reads' );
		bhranti_permission_config( $all );
		if ( 'trash_content' === $key ) {
			$proposal = bhranti_permission_success( Bhranti_Actions::execute( $command, $owner, $session ), 'Enabled Trash still requires canonical human approval' );
			bhranti_permission_check( ! empty( $proposal['confirmation_required'] ) && 'draft' === get_post_status( $trash ), 'Enabled Trash is only a proposal before approval' );
			$result = Bhranti_Actions::execute( $proposal['command'], $owner, $session, true, $proposal['fingerprint'] );
		} else { $result = Bhranti_Actions::execute( $command, $owner, $session ); }
		$result = bhranti_permission_success( $result, 'Enabled ' . $key . ' dispatches its reviewed native action' );
		bhranti_permission_check( ! empty( $result['action_id'] ) && ! empty( $result['rollback_available'] ), 'Enabled ' . $key . ' records a usable rollback' );
		bhranti_permission_config( $disabled );
		bhranti_permission_success( Bhranti_Actions::rollback( $result['action_id'], $owner, $session ), 'Rollback remains available after disabling ' . $key );
	}
	bhranti_permission_config( array_fill_keys( array_keys( $all ), false ) );
	bhranti_permission_success( Bhranti_Actions::execute( array( 'tool' => 'site_search', 'args' => array( 'search' => $permission_marker, 'per_page' => 2 ) ), $owner, $session ), 'All write permissions off still permits bounded site search' );
	bhranti_permission_success( Bhranti_Actions::execute( array( 'tool' => 'open_item', 'args' => array( 'path' => '/wp/v2/pages/' . $permission_page_id ) ), $owner, $session ), 'All write permissions off still permits opening an existing item' );
	bhranti_permission_config( $all );
	$late_disabled = $all; $late_disabled['edit_content'] = false;
	$late_tripped = false;
	$late_policy_hook = static function ( $meta_id, $post_id, $key, $value ) use ( $permission_page_id, $late_disabled, &$late_tripped ) {
		if ( ! $late_tripped && 'bhranti_event_data' === $key && 'action_started' === ( $value['type'] ?? '' ) && 'POST' === ( $value['details']['method'] ?? '' ) && '/wp/v2/pages/' . $permission_page_id === ( $value['details']['path'] ?? '' ) ) { $late_tripped = true; bhranti_permission_config( $late_disabled ); }
	};
	$before = bhranti_permission_native_state(); $records_before = get_post_meta( $session, 'bhranti_actions', true );
	add_action( 'added_post_meta', $late_policy_hook, 10, 4 );
	try { bhranti_permission_denied( Bhranti_Actions::execute( $cases['edit_content'], $owner, $session ), 'Policy changed after preparation/audit stops the final native write' ); } finally { remove_action( 'added_post_meta', $late_policy_hook, 10 ); }
	$records_after = get_post_meta( $session, 'bhranti_actions', true ); $new_records = array_diff_key( $records_after, is_array( $records_before ) ? $records_before : array() ); $new_record = end( $new_records );
	bhranti_permission_check( $late_tripped && $before === bhranti_permission_native_state() && 1 === count( $new_records ) && 'failed' === $new_record['state'], 'Late policy refusal preserves the native target and records preparation as failed' );
	bhranti_permission_check( is_wp_error( Bhranti_Actions::rollback( $new_record['id'], $owner, $session ) ) && $before === bhranti_permission_native_state(), 'An action refused by late policy cannot be replayed as a rollback' );

	AiClient::defaultRegistry()->registerProvider( Bhranti_QA_Provider::class ); WP_Connector_Registry::get_instance()->register( 'bhranti-qa', array( 'name' => 'Bhranti local QA', 'type' => 'ai_provider', 'authentication' => array( 'method' => 'none' ) ) );
	foreach ( array( false, true ) as $disable_during_model ) {
		bhranti_permission_config( $all );
		$started = bhranti_permission_rest( 'POST', '/sessions/' . $session . '/messages', array( 'content' => 'Current server policy fixture.', 'context' => array( 'permissions' => $all, 'confirmed' => true ) ) );
		bhranti_permission_check( 200 === $started->get_status(), 'Policy race run starts while content edits are enabled' );
		$disabled = $all; $disabled['edit_content'] = false;
		if ( $disable_during_model ) { Bhranti_QA_Model::$before_reply = static function () use ( $disabled ) { bhranti_permission_config( $disabled ); }; } else { bhranti_permission_config( $disabled ); }
		$before = bhranti_permission_native_state();
		Bhranti_QA_Model::$responses = array( wp_json_encode( array_merge( array( 'type' => 'tool' ), $cases['edit_content'] ) ) );
		$stepped = bhranti_permission_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $started->get_data()['run']['id'] ) );
		$run = get_post_meta( $session, 'bhranti_run', true ); $history = end( $run['history'] );
		bhranti_permission_check( 200 === $stepped->get_status() && false !== strpos( $history['content'], 'bhranti_action_disabled' ) && $before === bhranti_permission_native_state(), ( $disable_during_model ? 'Policy changed during the native model response' : 'Policy changed between send and step' ) . ' overrides frozen prompt/client permission flags before the tool writes' );
		bhranti_permission_rest( 'POST', '/sessions/' . $session . '/cancel', array( 'run_id' => $started->get_data()['run']['id'] ) );
	}
	bhranti_permission_config( $all );
	Bhranti_QA_Model::$responses = array( wp_json_encode( array_merge( array( 'type' => 'tool' ), $cases['trash_content'] ) ) );
	$started = bhranti_permission_rest( 'POST', '/sessions/' . $session . '/messages', array( 'content' => 'Canonical confirmation policy fixture.' ) );
	$proposal = bhranti_permission_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $started->get_data()['run']['id'] ) );
	bhranti_permission_check( 'awaiting_confirmation' === $proposal->get_data()['run']['status'], 'Agent stores the canonical Trash proposal under enabled policy' );
	$disabled = $all; $disabled['trash_content'] = false; bhranti_permission_config( $disabled ); $before = bhranti_permission_native_state();
	$confirmed = bhranti_permission_rest( 'POST', '/sessions/' . $session . '/confirm', array( 'confirmation_id' => $proposal->get_data()['confirmation']['id'], 'approved' => true, 'permissions' => $all ) );
	bhranti_permission_check( 200 === $confirmed->get_status() && 'cancelled' === $confirmed->get_data()['run']['status'] && $before === bhranti_permission_native_state(), 'Stored approval re-checks current server Trash policy and cannot be bypassed with client flags' );
	bhranti_permission_check( array() === $permission_http, 'Policy checks and native fake-provider steps remain offline' );
} catch ( Throwable $error ) { $permission_failure = $error; }
finally {
	Bhranti_QA_Model::$before_reply = null;
	foreach ( $permission_options as $name => $raw ) { bhranti_permission_restore_option( $name, $raw ); }
	foreach ( $permission_comments as $id ) { wp_delete_comment( $id, true ); }
	foreach ( $permission_posts as $id ) { if ( get_post( $id ) ) { wp_delete_post( $id, true ); } }
	foreach ( $permission_terms as $term ) { wp_delete_term( $term[0], $term[1] ); }
	foreach ( $permission_sessions as $id ) { if ( get_post( $id ) ) { wp_delete_post( $id, true ); } }
	foreach ( $permission_users as $id ) {
		foreach ( get_posts( array( 'post_type' => array( 'bhranti_event', 'bhranti_session' ), 'post_status' => 'any', 'posts_per_page' => -1, 'author' => $id ) ) as $post ) { wp_delete_post( $post->ID, true ); }
		delete_option( 'bhranti_lock_' . $id ); if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; } wp_delete_user( $id );
	}
	$permission_preserved = $permission_baseline === bhranti_permission_native_state(); foreach ( $permission_options as $name => $raw ) { $permission_preserved = $permission_preserved && $raw === bhranti_permission_raw_option( $name ); }
	wp_set_current_user( $permission_original_user ); $permission_registry->setValue( null, $permission_saved_registry ); AiClient::setEventDispatcher( $permission_saved_dispatcher ); AiClient::setCache( $permission_saved_cache );
	remove_filter( 'pre_http_request', $permission_http_guard, PHP_INT_MAX ); remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX ); remove_filter( 'pre_schedule_event', $permission_runner_veto, PHP_INT_MAX ); restore_error_handler();
}
if ( $permission_failure || ! $permission_preserved ) { fwrite( STDERR, ( $permission_failure ? $permission_failure->getMessage() : 'FAIL: Original policy/content/options not preserved.' ) . "\nCompleted " . $permission_checks . ' checks; cleanup/baseline ' . ( $permission_preserved ? 'verified' : 'FAILED' ) . ".\n" ); exit( 1 ); }
echo 'PASS: ' . $permission_checks . " current server permission checks; native fixtures removed and original content/config/options preserved.\n";
