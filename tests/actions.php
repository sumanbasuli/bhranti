<?php
/**
 * Real WordPress action and rollback checks using uniquely marked temporary data.
 *
 * Run only after deployment:
 * BHRANTI_TEST_WP_ROOT=/path/to/wordpress BHRANTI_TEST_ALLOW_MUTATION=1 php tests/actions.php
 *
 * @package Bhranti
 */

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ProviderRegistry;

define( 'BHRANTI_QA_SUPPORT_ONLY', true );
require __DIR__ . '/integration.php';
if ( ! class_exists( 'Bhranti_Actions' ) || ! class_exists( 'Bhranti_Audit' ) || realpath( BHRANTI_FILE ) !== realpath( WP_PLUGIN_DIR . '/bhranti/bhranti.php' ) ) {
	fwrite( STDERR, "Deploy and activate the action-capable Bhranti plugin on https://bhranti.wp.local/ before running these checks.\n" );
	exit( 1 );
}

$action_checks  = 0;
$action_users   = array();
$action_posts   = array();
$action_terms   = array();
$action_comments = array();
$action_sessions = array();
$action_audits  = array();
$action_http    = array();
$action_failure = null;
$resource_ids = array();
$action_original_user = get_current_user_id();
$action_marker = 'bhranti-action-qa-' . str_replace( '-', '', wp_generate_uuid4() );
$action_original_content = null;
$action_content_preserved = false;
$action_fixture_options = array();
$action_registry_property = new ReflectionProperty( AiClient::class, 'defaultRegistry' );
$action_registry_property->setAccessible( true );
$action_original_registry = AiClient::defaultRegistry();
$action_original_dispatcher = AiClient::getEventDispatcher();
$action_original_cache = AiClient::getCache();
$action_registry_property->setValue( null, new ProviderRegistry() );
AiClient::setEventDispatcher( null );
AiClient::setCache( null );

/** Fail on observable behavior, printing the failing operation. */
function bhranti_action_check( $condition, $label ) {
	global $action_checks;
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	++$action_checks;
}

/** Exercise the actual Bhranti REST permission callbacks and schemas. */
function bhranti_action_rest( $method, $path, $params = array() ) {
	$request = new WP_REST_Request( $method, '/bhranti/v1' . $path );
	$request->set_body_params( $params );
	return rest_do_request( $request );
}

/** Build one reviewed WordPress REST command. */
function bhranti_action_command( $method, $path, $params = array() ) {
	return array( 'tool' => 'wp_request', 'args' => array( 'method' => $method, 'path' => $path, 'params' => $params ) );
}

/** Capture identifiers for cleanup before checking the result. */
function bhranti_action_execute( $command, $owner, $session, $confirmed = false, $expected_fingerprint = '' ) {
	$result = Bhranti_Actions::execute( $command, $owner, $session, $confirmed, $expected_fingerprint );
	if ( ! is_wp_error( $result ) && ! empty( $result['action_id'] ) ) {
		$GLOBALS['action_audits'][] = $result['action_id'];
	}
	if ( ! is_wp_error( $result ) && ! empty( $result['data']['id'] ) && 'POST' === ( $command['args']['method'] ?? '' ) ) {
		$path = $command['args']['path'] ?? '';
		$id = (int) $result['data']['id'];
		if ( in_array( $path, array( '/wp/v2/posts', '/wp/v2/pages' ), true ) ) {
			$GLOBALS['action_posts'][] = $id;
		} elseif ( '/wp/v2/comments' === $path ) {
			$GLOBALS['action_comments'][] = $id;
		} elseif ( '/wp/v2/categories' === $path ) {
			$GLOBALS['action_terms'][] = array( 'id' => $id, 'taxonomy' => 'category' );
		} elseif ( '/wp/v2/users' === $path ) {
			$GLOBALS['action_users'][] = $id;
		}
	}
	return $result;
}

/** Give failures a readable label without dumping arbitrary provider payloads. */
function bhranti_action_success( $result, $label ) {
	bhranti_action_check( ! is_wp_error( $result ) && is_array( $result ), $label . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );
	return $result;
}

/** Fingerprint ordinary content while excluding the plugin's private chat/audit storage. */
function bhranti_action_content_state() {
	global $wpdb;
	return hash( 'sha256', wp_json_encode( array(
		'posts' => $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_type NOT IN ('bhranti_session','bhranti_event') ORDER BY ID", ARRAY_A ),
		'meta' => $wpdb->get_results( "SELECT pm.* FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type NOT IN ('bhranti_session','bhranti_event') ORDER BY pm.meta_id", ARRAY_A ),
		'terms' => $wpdb->get_results( "SELECT tr.* FROM {$wpdb->term_relationships} tr JOIN {$wpdb->posts} p ON p.ID=tr.object_id WHERE p.post_type NOT IN ('bhranti_session','bhranti_event') ORDER BY tr.object_id,tr.term_taxonomy_id", ARRAY_A ),
		'comments' => $wpdb->get_results( "SELECT * FROM {$wpdb->comments} ORDER BY comment_ID", ARRAY_A ),
		'comment_meta' => $wpdb->get_results( "SELECT * FROM {$wpdb->commentmeta} ORDER BY meta_id", ARRAY_A ),
		'term_values' => $wpdb->get_results( "SELECT * FROM {$wpdb->terms} ORDER BY term_id", ARRAY_A ),
		'taxonomy_values' => $wpdb->get_results( "SELECT term_taxonomy_id,term_id,taxonomy,description,parent FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id", ARRAY_A ),
	) ) );
}

/** Read publication state from storage so REST date shims cannot hide a rollback change. */
function bhranti_action_native_post_state( $id ) {
	global $wpdb;
	$state = $wpdb->get_row( $wpdb->prepare( "SELECT post_title,post_excerpt,post_content_filtered,post_name,post_date,post_date_gmt,post_status,post_content,post_author FROM {$wpdb->posts} WHERE ID=%d", $id ), ARRAY_A );
	wp_cache_delete( $id, 'post_meta' );
	foreach ( array( '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status', '_wp_desired_post_slug' ) as $key ) {
		$state['trash_metadata'][ $key ] = get_post_meta( $id, $key );
	}
	return $state;
}

/** Save existing site settings before assigning them only to disposable resources. */
function bhranti_action_seed_fixture_options( $values ) {
	global $wpdb, $action_fixture_options;
	foreach ( $values as $name => $value ) {
		if ( ! array_key_exists( $name, $action_fixture_options ) ) {
			$storage = $wpdb->get_row( $wpdb->prepare( "SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name=%s", $name ), ARRAY_A );
			$action_fixture_options[ $name ] = array( 'exists' => is_array( $storage ), 'value' => get_option( $name ), 'storage' => $storage );
		}
		update_option( $name, $value );
	}
}

/** Restore exact original setting values before deleting fixture posts. */
function bhranti_action_restore_fixture_options() {
	global $action_fixture_options;
	foreach ( $action_fixture_options as $name => $original ) {
		if ( $original['exists'] ) {
			update_option( $name, $original['value'] );
		} else {
			delete_option( $name );
		}
	}
}

/** Core casts option values in memory; compare exact persisted value/autoload instead. */
function bhranti_action_fixture_option_preserved( $name ) {
	global $wpdb, $action_fixture_options;
	$storage = $wpdb->get_row( $wpdb->prepare( "SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name=%s", $name ), ARRAY_A );
	return $action_fixture_options[ $name ]['storage'] === $storage;
}

/** Compare the settings core resets when a selected front/posts page is trashed. */
function bhranti_action_front_options() {
	return array( 'show_on_front' => (string) get_option( 'show_on_front' ), 'page_on_front' => (int) get_option( 'page_on_front' ), 'page_for_posts' => (int) get_option( 'page_for_posts' ) );
}

/** Keep semantic REST publication fields without changeable modified timestamps. */
function bhranti_action_publication_response( $data ) {
	return array_intersect_key( $data, array_flip( array( 'slug', 'date', 'date_gmt', 'status', 'content' ) ) );
}

/** Start a run and require that enqueueing it has no model side effect. */
function bhranti_action_start_run( $session, $content, $context = array() ) {
	$calls = count( Bhranti_QA_Model::$calls );
	$response = bhranti_action_rest( 'POST', '/sessions/' . $session . '/messages', array( 'content' => $content, 'context' => $context ) );
	bhranti_action_check( 200 === $response->get_status() && 'running' === $response->get_data()['run']['status'], 'Agent request starts as a persisted running request' );
	bhranti_action_check( $calls === count( Bhranti_QA_Model::$calls ), 'Starting a run does not call its model' );
	return $response->get_data()['run']['id'];
}

/** Prepare a deletion through the actual model and confirmation endpoint flow. */
function bhranti_action_propose_delete( $session, $path ) {
	Bhranti_QA_Model::$responses = array( wp_json_encode( array( 'type' => 'tool', 'tool' => 'wp_request', 'args' => array( 'method' => 'DELETE', 'path' => $path, 'params' => array() ) ) ) );
	$run_id = bhranti_action_start_run( $session, 'Delete this temporary QA item: ' . $path );
	$step = bhranti_action_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $run_id ) );
	bhranti_action_check( 200 === $step->get_status() && 'awaiting_confirmation' === $step->get_data()['run']['status'] && ! empty( $step->get_data()['confirmation']['id'] ), 'Destructive model command pauses for human confirmation' );
	bhranti_action_check( ! isset( $step->get_data()['confirmation']['command'] ) && ! isset( $step->get_data()['confirmation']['fingerprint'] ), 'Confirmation response excludes canonical commands and fingerprints' );
	return $step->get_data();
}

/** Simulate a plugin replacing a core controller, without mutating site data. */
final class Bhranti_QA_Unsafe_Controller extends WP_REST_Posts_Controller {
	public function get_items( $request ) {
		$GLOBALS['bhranti_qa_unsafe_controller_called'] = true;
		return new WP_REST_Response( array( 'private_plugin_data' => 'must-not-reach-model' ) );
	}
}

$action_http_guard = static function ( $response, $args, $url ) use ( &$action_http ) {
	$action_http[] = $url;
	return new WP_Error( 'bhranti_qa_http_denied', 'Integration tests make no external HTTP requests.' );
};
$action_runner_veto = bhranti_qa_runner_veto( static function () { return $GLOBALS['action_sessions'] ?? array(); } );
add_filter( 'pre_http_request', $action_http_guard, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );

try {
	$action_original_content = bhranti_action_content_state();
	foreach ( array( 'administrator', 'administrator', 'subscriber' ) as $role ) {
		$login = 'bhranti_action_qa_' . str_replace( '-', '', wp_generate_uuid4() );
		$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role, 'user_email' => $login . '@example.test' ) );
		bhranti_action_check( is_int( $id ) && $id > 0, 'Temporary ' . $role . ' created' );
		$action_users[] = $id;
	}
	list( $owner, $other, $subscriber ) = $action_users;
	wp_set_current_user( $owner );
	$actor = Bhranti_Actions::ensure_actor();
	bhranti_action_check( is_int( $actor ) && $actor > 0 && $actor !== $owner, 'Dedicated action actor resolves independently of requester' );
	$actor_user = get_userdata( $actor );
	bhranti_action_check( $actor_user && ! $actor_user->has_cap( 'edit_users' ) && ! $actor_user->has_cap( 'install_plugins' ) && ! $actor_user->has_cap( 'switch_themes' ) && ! $actor_user->has_cap( 'unfiltered_html' ), 'Action actor has no user-management, software-installation or executable markup privileges' );
	bhranti_action_check( get_current_blog_id() === (int) get_user_meta( $actor, 'bhranti_managed_actor', true ), 'Managed actor is bound to this WordPress site' );
	$actor_login = apply_filters( 'authenticate', $actor_user, $actor_user->user_login, 'unused-test-password' );
	bhranti_action_check( is_wp_error( $actor_login ) && 'bhranti_actor_login' === $actor_login->get_error_code(), 'Managed actor cannot authenticate as an interactive user' );
	bhranti_action_check( false === apply_filters( 'determine_current_user', $actor ) && false === apply_filters( 'wp_is_application_passwords_available_for_user', true, $actor_user ) && false === apply_filters( 'allow_password_reset', true, $actor ), 'Managed actor cannot use cookies, application passwords or password resets' );
	$session_response = bhranti_action_rest( 'POST', '/sessions' );
	bhranti_action_check( 200 === $session_response->get_status(), 'Temporary private session created' );
	$session = $session_response->get_data()['id'];
	$action_sessions[] = $session;
	bhranti_action_check( is_array( Bhranti_Actions::catalog() ) && Bhranti_Actions::catalog(), 'Reviewed tool catalog is available' );
	register_post_type( 'bhranti_qa_type', array( 'public' => false, 'show_in_rest' => true, 'rest_base' => 'bhranti-qa-type', 'rest_controller_class' => 'Bhranti_QA_Unsafe_Controller' ) );
	get_post_type_object( 'bhranti_qa_type' )->get_rest_controller()->register_routes();
	$GLOBALS['bhranti_qa_unsafe_controller_called'] = false;
	bhranti_action_check( is_wp_error( bhranti_action_execute( bhranti_action_command( 'GET', '/wp/v2/bhranti-qa-type' ), $owner, $session ) ) && ! $GLOBALS['bhranti_qa_unsafe_controller_called'], 'A plugin subclass cannot turn an allowed route into arbitrary callback execution' );
	$safe_settings = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'GET', '/wp/v2/settings' ), $owner, $session ), 'Reviewed site settings can be read' );
	bhranti_action_check( isset( $safe_settings['data']['title'] ) && ! array_intersect( array( 'email', 'url', 'users_can_register', 'default_role', 'admin_email', 'siteurl', 'home' ), array_keys( $safe_settings['data'] ) ), 'Settings projection excludes account, security and site-routing fields' );
	$public_user = bhranti_action_execute( bhranti_action_command( 'GET', '/wp/v2/users/' . $owner, array( 'context' => 'edit' ) ), $owner, $session );
	bhranti_action_check( is_wp_error( $public_user ) || ( ! array_diff( array_keys( $public_user['data'] ), array( 'id', 'name', 'slug', 'url', 'description' ) ) && ! isset( $public_user['data']['email'], $public_user['data']['roles'], $public_user['data']['capabilities'] ) ), 'User reads reject privileged context or return only the reviewed public projection' );

	$blocked = array(
		bhranti_action_command( 'POST', '/wp/v2/settings', array( 'email' => 'bhranti-qa@example.test' ) ),
		bhranti_action_command( 'POST', '/wp/v2/settings', array( 'url' => 'https://example.test' ) ),
		bhranti_action_command( 'POST', '/wp/v2/settings', array( 'users_can_register' => true ) ),
		bhranti_action_command( 'POST', '/wp/v2/settings', array( 'default_role' => 'administrator' ) ),
		bhranti_action_command( 'POST', '/wp/v2/users', array( 'username' => $action_marker, 'password' => 'never-create-this' ) ),
		bhranti_action_command( 'GET', '/wp/v2/users/' . $owner . '/application-passwords' ),
		bhranti_action_command( 'POST', '/wp/v2/plugins', array( 'slug' => 'never-install-this' ) ),
		bhranti_action_command( 'POST', '/wp/v2/themes', array( 'stylesheet' => 'never-switch-this' ) ),
		bhranti_action_command( 'GET', '/wp/v2/plugins' ),
		bhranti_action_command( 'POST', '/wp/v2/categories', array( 'name' => $action_marker ) ),
		bhranti_action_command( 'POST', '/wp/v2/posts', array( 'title' => $action_marker, 'content' => '<?php echo "never-execute"; ?>' ) ),
		bhranti_action_command( 'POST', '/wp/v2/posts', array( 'title' => $action_marker, 'content' => '<script>alert("never-execute")</script>' ) ),
		bhranti_action_command( 'GET', 'https://example.test/wp/v2/posts' ),
		bhranti_action_command( 'GET', '/wp/v2/posts?context=edit' ),
		bhranti_action_command( 'GET', '/wp/v2/posts/../../users' ),
		bhranti_action_command( 'GET', '/wp/v2/%70osts' ),
		array(),
		array( 'tool' => 'php_eval', 'args' => array( 'code' => 'never execute' ) ),
		array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => '/wp/v2/posts', 'params' => str_repeat( 'x', 100000 ) ) ),
	);
	foreach ( $blocked as $index => $command ) {
		bhranti_action_check( is_wp_error( bhranti_action_execute( $command, $owner, $session ) ), 'Unsafe command ' . $index . ' blocked' );
		bhranti_action_check( $owner === get_current_user_id(), 'Actor context restored after blocked command ' . $index );
	}

	$denied = bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/posts', array( 'title' => $action_marker . '-subscriber', 'status' => 'draft' ) ), $subscriber, $session );
	bhranti_action_check( is_wp_error( $denied ), 'Requester without content capabilities cannot use actor privileges' );
	$deny_requester = static function ( $caps, $required, $args, $user ) use ( $owner ) {
		if ( $owner === (int) $user->ID ) {
			$caps['edit_posts'] = false;
		}
		return $caps;
	};
	add_filter( 'user_has_cap', $deny_requester, PHP_INT_MAX, 4 );
	try {
		$denied = bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/posts', array( 'title' => $action_marker . '-requester-denied', 'status' => 'draft' ) ), $owner, $session );
		bhranti_action_check( is_wp_error( $denied ), 'Requester capability revocation cannot be bypassed by actor permissions' );
	} finally {
		remove_filter( 'user_has_cap', $deny_requester, PHP_INT_MAX );
	}
	$deny_actor = static function ( $caps, $required, $args, $user ) use ( $actor ) {
		if ( $actor === (int) $user->ID ) {
			$caps['edit_posts'] = false;
		}
		return $caps;
	};
	add_filter( 'user_has_cap', $deny_actor, PHP_INT_MAX, 4 );
	try {
		$denied = bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/posts', array( 'title' => $action_marker . '-actor-denied', 'status' => 'draft' ) ), $owner, $session );
		bhranti_action_check( is_wp_error( $denied ), 'Requester cannot bypass an actor capability restriction' );
	} finally {
		remove_filter( 'user_has_cap', $deny_actor, PHP_INT_MAX );
	}

	foreach ( array( 'posts', 'pages' ) as $base ) {
		$result = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/' . $base, array( 'title' => $action_marker . '-' . $base, 'content' => 'Initial QA content.', 'status' => 'draft' ) ), $owner, $session ), 'Real REST creates a ' . $base . ' draft' );
		$id = (int) $result['data']['id'];
		$action_posts[] = $id;
		$resource_ids[ $base ] = $id;
		bhranti_action_check( $id > 0 && $actor === (int) get_post( $id )->post_author, 'Created ' . $base . ' content is authored by Bhranti' );
		bhranti_action_check( ! empty( $result['rollback_available'] ) && ! empty( $result['action_id'] ), 'Creation has a persisted rollback action' );
		$snapshots = get_post_meta( $session, 'bhranti_actions', true );
		$snapshot = $snapshots[ $result['action_id'] ] ?? array();
		bhranti_action_check( $owner === (int) ( $snapshot['requester_id'] ?? 0 ) && $session === (int) ( $snapshot['session_id'] ?? 0 ) && $actor === (int) ( $snapshot['actor_id'] ?? 0 ), 'Rollback snapshot binds requester, session and actor' );
		$path = '/wp/v2/' . $base . '/' . $id;
		$updated = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', $path, array( 'content' => 'Updated QA content.' ) ), $owner, $session ), 'Real REST updates ' . $base );
		bhranti_action_check( 'Updated QA content.' === get_post( $id )->post_content, 'Updated content persists' );
		$rolled_back = Bhranti_Actions::rollback( $updated['action_id'], $owner, $session );
		bhranti_action_check( ! is_wp_error( $rolled_back ) && 'Initial QA content.' === get_post( $id )->post_content, 'Rollback restores the previous ' . $base . ' content' );
		bhranti_action_check( is_wp_error( Bhranti_Actions::rollback( $updated['action_id'], $owner, $session ) ), 'Rollback cannot be replayed' );

		$conflicting = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', $path, array( 'content' => 'Agent content before conflict.' ) ), $owner, $session ), 'Create rollback conflict fixture' );
		wp_update_post( array( 'ID' => $id, 'post_content' => 'A later human edit must survive.' ) );
		$conflict = Bhranti_Actions::rollback( $conflicting['action_id'], $owner, $session );
		bhranti_action_check( is_wp_error( $conflict ) && 'bhranti_rollback_conflict' === $conflict->get_error_code() && 'A later human edit must survive.' === get_post( $id )->post_content, 'Rollback refuses to overwrite a later editor change' );
		bhranti_action_check( is_wp_error( Bhranti_Actions::rollback( $conflicting['action_id'], $other, $session ) ), 'Other administrator cannot roll back owner action' );
		$force = bhranti_action_execute( bhranti_action_command( 'DELETE', $path, array( 'force' => true ) ), $owner, $session );
		bhranti_action_check( is_wp_error( $force ) && null !== get_post( $id ), 'Permanent deletion is blocked' );
		$before_trash = bhranti_action_native_post_state( $id );
		bhranti_action_check( '' === $before_trash['post_name'] && '0000-00-00 00:00:00' === $before_trash['post_date_gmt'], 'Native ' . $base . ' draft fixture has a blank slug and immediate publication date' );
		$rest_before_trash = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'GET', $path, array( 'context' => 'edit' ) ), $owner, $session ), 'Read canonical ' . $base . ' REST publication fields before Trash' );
		$proposal = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'DELETE', $path ), $owner, $session ), 'Trash returns a confirmation proposal' );
		bhranti_action_check( ! empty( $proposal['confirmation_required'] ) && 'draft' === get_post_status( $id ) && ! empty( $proposal['fingerprint'] ), 'Proposal has a fingerprint and makes no deletion' );
		$status_proposal = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', $path, array( 'status' => 'trash' ) ), $owner, $session ), 'Trash status follows the same confirmation gate' );
		bhranti_action_check( ! empty( $status_proposal['confirmation_required'] ) && 'draft' === get_post_status( $id ), 'Changing status cannot bypass destructive confirmation' );
		$trashed = bhranti_action_success( bhranti_action_execute( $proposal['command'], $owner, $session, true, $proposal['fingerprint'] ), 'Confirmed action trashes only the temporary content' );
		bhranti_action_check( 'trash' === get_post_status( $id ), 'Confirmed action uses native Trash' );
		$trash_restored = Bhranti_Actions::rollback( $trashed['action_id'], $owner, $session );
		bhranti_action_check( ! is_wp_error( $trash_restored ) && 'draft' === get_post_status( $id ), 'Trash rollback restores original status' );
		bhranti_action_check( $before_trash === bhranti_action_native_post_state( $id ), 'Trash rollback exactly restores native ' . $base . ' slug, dates, content, author and core Trash metadata' );
		bhranti_action_check( bhranti_action_publication_response( $rest_before_trash['data'] ) === bhranti_action_publication_response( $trash_restored['data'] ), 'Rollback returns refreshed ' . $base . ' REST publication fields after auxiliary restoration' );
		bhranti_action_check( $owner === get_current_user_id(), 'Actor context restored after mutation and rollback' );
	}
	foreach ( array(
		'explicit-slug-draft' => array( 'base' => 'posts', 'params' => array( 'status' => 'draft', 'slug' => $action_marker . '-explicit-draft' ) ),
		'published-dated-page' => array( 'base' => 'pages', 'params' => array( 'status' => 'publish', 'slug' => $action_marker . '-published-dated', 'date' => '2025-06-15T10:30:00' ) ),
	) as $scenario => $fixture ) {
		$params = array( 'title' => $action_marker . '-' . $scenario, 'content' => 'Temporary publication-state regression fixture.' ) + $fixture['params'];
		if ( 'draft' === $fixture['params']['status'] ) {
			// Core 7.1's create_item explicit-slug branch uses two undefined properties;
			// the equivalent native update constructs this fixture without core warnings.
			unset( $params['slug'] );
		}
		$created = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/' . $fixture['base'], $params ), $owner, $session ), 'Native REST creates ' . $scenario . ' fixture' );
		$id = (int) $created['data']['id'];
		$path = '/wp/v2/' . $fixture['base'] . '/' . $id;
		if ( 'draft' === $fixture['params']['status'] ) {
			bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'PATCH', $path, array( 'slug' => $fixture['params']['slug'] ) ), $owner, $session ), 'Native REST assigns explicit slug to immediate draft fixture' );
		}
		$before_trash = bhranti_action_native_post_state( $id );
		$rest_before_trash = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'GET', $path, array( 'context' => 'edit' ) ), $owner, $session ), 'Read canonical REST publication fields for ' . $scenario );
		bhranti_action_check( $fixture['params']['slug'] === $before_trash['post_name'] && $fixture['params']['status'] === $before_trash['post_status'], 'Native publication-state fixture has the intended explicit slug and status' );
		bhranti_action_check( 'draft' === $before_trash['post_status'] ? '0000-00-00 00:00:00' === $before_trash['post_date_gmt'] : ( '2025-06-15 10:30:00' === $before_trash['post_date'] && '0000-00-00 00:00:00' !== $before_trash['post_date_gmt'] ), 'Native publication-state fixture has the intended immediate or explicit publication timing' );
		$proposal = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'DELETE', $path ), $owner, $session ), 'Native ' . $scenario . ' Trash proposes confirmation' );
		bhranti_action_check( $before_trash === bhranti_action_native_post_state( $id ), 'Unapproved ' . $scenario . ' deletion changes no native state' );
		$trashed = bhranti_action_success( bhranti_action_execute( $proposal['command'], $owner, $session, true, $proposal['fingerprint'] ), 'Confirmed ' . $scenario . ' moves to Trash' );
		bhranti_action_check( 'trash' === get_post_status( $id ), 'Confirmed publication-state fixture uses native Trash' );
		$trash_restored = bhranti_action_success( Bhranti_Actions::rollback( $trashed['action_id'], $owner, $session ), 'Native ' . $scenario . ' Trash rollback succeeds' );
		bhranti_action_check( $before_trash === bhranti_action_native_post_state( $id ), 'Trash rollback exactly restores ' . $scenario . ' native slug, date/GMT, status, content, author and core metadata' );
		bhranti_action_check( bhranti_action_publication_response( $rest_before_trash['data'] ) === bhranti_action_publication_response( $trash_restored['data'] ), 'Rollback returns refreshed REST publication fields for ' . $scenario );
	}
	$sticky_original = get_option( 'sticky_posts', array() );
	bhranti_action_seed_fixture_options( array( 'sticky_posts' => $sticky_original ) );
	$sticky_created = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/posts', array( 'title' => $action_marker . '-sticky', 'content' => 'Disposable pinned draft.', 'status' => 'draft', 'sticky' => true ) ), $owner, $session ), 'Native REST creates a pinned draft fixture' );
	$sticky_id = (int) $sticky_created['data']['id'];
	$sticky_path = '/wp/v2/posts/' . $sticky_id;
	$sticky_before = bhranti_action_native_post_state( $sticky_id );
	$sticky_ids_before = get_option( 'sticky_posts' );
	bhranti_action_check( is_sticky( $sticky_id ), 'Native pinned fixture is sticky before Trash' );
	$proposal = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'DELETE', $sticky_path ), $owner, $session ), 'Pinned draft proposes Trash confirmation' );
	$sticky_trashed = bhranti_action_success( bhranti_action_execute( $proposal['command'], $owner, $session, true, $proposal['fingerprint'] ), 'Confirmed pinned draft uses native Trash' );
	bhranti_action_check( ! is_sticky( $sticky_id ), 'Native Trash removes only the fixture pin' );
	$later_pin = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/posts', array( 'title' => $action_marker . '-later-pin', 'status' => 'draft', 'sticky' => true ) ), $owner, $session ), 'A later user pin has its own disposable draft' );
	$later_pin_id = (int) $later_pin['data']['id'];
	bhranti_action_success( Bhranti_Actions::rollback( $sticky_trashed['action_id'], $owner, $session ), 'Pinned draft Trash rollback succeeds' );
	bhranti_action_check( $sticky_before === bhranti_action_native_post_state( $sticky_id ) && is_sticky( $sticky_id ) && is_sticky( $later_pin_id ) && ! array_diff( $sticky_ids_before, get_option( 'sticky_posts' ) ), 'Trash rollback restores original pin/native state and preserves unrelated later pins' );
	try {
		$front_fixture_ids = array();
		foreach ( array( 'front', 'blog', 'alternate' ) as $kind ) {
			$front_created = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/pages', array( 'title' => $action_marker . '-' . $kind . '-setting', 'content' => 'Disposable selected-page fixture.', 'status' => 'publish' ) ), $owner, $session ), 'Fresh ' . $kind . ' page fixture created' );
			$front_fixture_ids[ $kind ] = (int) $front_created['data']['id'];
		}
		bhranti_action_seed_fixture_options( array( 'show_on_front' => 'page', 'page_on_front' => $front_fixture_ids['front'], 'page_for_posts' => $front_fixture_ids['blog'] ) );
		foreach ( array( 'front', 'blog' ) as $kind ) {
			$id = $front_fixture_ids[ $kind ];
			$path = '/wp/v2/pages/' . $id;
			$options_before = bhranti_action_front_options();
			$native_before = bhranti_action_native_post_state( $id );
			$proposal = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'DELETE', $path ), $owner, $session ), 'Selected ' . $kind . ' page proposes Trash confirmation' );
			$selected_trashed = bhranti_action_success( bhranti_action_execute( $proposal['command'], $owner, $session, true, $proposal['fingerprint'] ), 'Selected ' . $kind . ' page confirmed Trash succeeds' );
			bhranti_action_check( 0 === (int) get_option( 'front' === $kind ? 'page_on_front' : 'page_for_posts' ), 'Native Trash clears the selected ' . $kind . ' page setting' );
			bhranti_action_success( Bhranti_Actions::rollback( $selected_trashed['action_id'], $owner, $session ), 'Selected ' . $kind . ' page Trash rolls back' );
			bhranti_action_check( $options_before === bhranti_action_front_options() && $native_before === bhranti_action_native_post_state( $id ), 'Trash rollback restores original ' . $kind . ' selection and native post state' );
		}
		$front_path = '/wp/v2/pages/' . $front_fixture_ids['front'];
		$proposal = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'DELETE', $front_path ), $owner, $session ), 'Front-page conflict fixture proposes Trash' );
		$front_trashed = bhranti_action_success( bhranti_action_execute( $proposal['command'], $owner, $session, true, $proposal['fingerprint'] ), 'Front-page conflict fixture moves to Trash' );
		bhranti_action_seed_fixture_options( array( 'show_on_front' => 'page', 'page_on_front' => $front_fixture_ids['alternate'] ) );
		$later_front_options = bhranti_action_front_options();
		$front_conflict = Bhranti_Actions::rollback( $front_trashed['action_id'], $owner, $session );
		bhranti_action_check( is_wp_error( $front_conflict ) && 'bhranti_rollback_conflict' === $front_conflict->get_error_code() && $later_front_options === bhranti_action_front_options() && 'trash' === get_post_status( $front_fixture_ids['front'] ), 'Rollback does not overwrite a later user front-page selection' );
	} finally {
		bhranti_action_restore_fixture_options();
	}
	foreach ( $action_fixture_options as $name => $original ) {
		bhranti_action_check( bhranti_action_fixture_option_preserved( $name ), 'Fixture cleanup restores original stored value/autoload of site option ' . $name );
	}
	foreach ( array(
		'iframe-content' => array( 'content' => '<iframe src="https://example.test/owned-qa-frame"></iframe>', 'excerpt' => '<script>window.bhrantiQaNeverRuns=true;</script>' ),
		'script-content' => array( 'content' => '<script>window.bhrantiQaNeverRuns=true;</script>', 'excerpt' => '<iframe src="https://example.test/owned-qa-frame"></iframe>' ),
		'unsafe-title' => array( 'content' => 'Safe existing content.', 'excerpt' => 'Safe existing excerpt.', 'title_suffix' => '<script>window.bhrantiQaNeverRuns=true;</script>' ),
		'filtered-content' => array( 'content' => 'Safe existing content.', 'excerpt' => 'Safe existing excerpt.', 'filtered' => '<iframe src="https://example.test/owned-qa-frame"></iframe>' ),
	) as $kind => $unsafe ) {
		$unsafe_title = $action_marker . '-preserve-' . $kind . ( $unsafe['title_suffix'] ?? '' );
		$unsafe_filtered = $unsafe['filtered'] ?? '';
		$id = wp_insert_post( wp_slash( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => $owner, 'post_title' => $unsafe_title, 'post_content' => $unsafe['content'], 'post_excerpt' => $unsafe['excerpt'], 'post_content_filtered' => $unsafe_filtered ) ), true );
		if ( is_int( $id ) && $id > 0 ) {
			$action_posts[] = $id;
		}
		bhranti_action_check( is_int( $id ) && $id > 0 && $unsafe['content'] === get_post( $id )->post_content && $unsafe['excerpt'] === get_post( $id )->post_excerpt && $unsafe_title === get_post( $id )->post_title && $unsafe_filtered === get_post( $id )->post_content_filtered, 'Fresh administrator-authored ' . $kind . ' fixture retains its original raw fields' );
		$path = '/wp/v2/posts/' . $id;
		$native_before = bhranti_action_native_post_state( $id );
		$read = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'GET', $path, array( 'context' => 'edit' ) ), $owner, $session ), 'Existing ' . $kind . ' remains readable through reviewed core API' );
		bhranti_action_check( $unsafe['content'] === $read['data']['content']['raw'] && $unsafe['excerpt'] === $read['data']['excerpt']['raw'] && $unsafe_title === $read['data']['title']['raw'] && $native_before === bhranti_action_native_post_state( $id ), 'Read returns literal core REST fields and leaves protected native fields unchanged' );
		foreach ( array( array( 'status' => 'private' ), array( 'title' => $action_marker . '-must-not-rewrite' ) ) as $params ) {
			$blocked = bhranti_action_execute( bhranti_action_command( 'PATCH', $path, $params ), $owner, $session );
			bhranti_action_check( is_wp_error( $blocked ) && 'bhranti_action_preservation' === $blocked->get_error_code(), 'Actor write refuses native KSES damage to existing ' . $kind );
			bhranti_action_check( $native_before === bhranti_action_native_post_state( $id ), 'Refused write preserves complete native ' . $kind . ' publication/content state' );
		}
		$blocked = bhranti_action_execute( bhranti_action_command( 'DELETE', $path ), $owner, $session );
		bhranti_action_check( is_wp_error( $blocked ) && 'bhranti_action_preservation' === $blocked->get_error_code(), 'Trash refuses native KSES damage to existing ' . $kind );
		bhranti_action_check( $native_before === bhranti_action_native_post_state( $id ), 'Refused Trash preserves raw content/excerpt, native publication and metadata' );
		$preservation_logged = false;
		foreach ( Bhranti_Audit::events( $owner, 1, $session ) as $event ) {
			if ( 'action_blocked' === $event['type'] && 'bhranti_action_preservation' === ( $event['details']['code'] ?? '' ) && $path === ( $event['details']['path'] ?? '' ) ) {
				$preservation_logged = true;
			}
		}
		bhranti_action_check( $preservation_logged, 'Native audit records the blocked preservation refusal for ' . $kind );
	}
	$preserve_created = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/posts', array( 'title' => $action_marker . '-rollback-preservation', 'content' => 'Safe original content.', 'status' => 'draft' ) ), $owner, $session ), 'Fresh rollback preservation fixture created' );
	$preserve_id = (int) $preserve_created['data']['id'];
	$preserve_updated = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'PATCH', '/wp/v2/posts/' . $preserve_id, array( 'content' => 'Safe agent update.' ) ), $owner, $session ), 'Safe rollback preservation fixture action recorded' );
	$unsafe_later = '<iframe src="https://example.test/owned-qa-frame"></iframe>';
	wp_update_post( wp_slash( array( 'ID' => $preserve_id, 'post_content' => $unsafe_later, 'post_excerpt' => '<script>window.bhrantiQaNeverRuns=true;</script>' ) ) );
	bhranti_action_check( $unsafe_later === get_post( $preserve_id )->post_content, 'Later administrator edit retains protected markup on disposable rollback target' );
	$preserve_native = bhranti_action_native_post_state( $preserve_id );
	$preserve_rollback = Bhranti_Actions::rollback( $preserve_updated['action_id'], $owner, $session );
	bhranti_action_check( is_wp_error( $preserve_rollback ) && 'bhranti_action_preservation' === $preserve_rollback->get_error_code(), 'Rollback preservation guard refuses a damaging actor write before any mutation' );
	bhranti_action_check( $preserve_native === bhranti_action_native_post_state( $preserve_id ), 'Refused rollback leaves native publication/content/author/metadata state unchanged' );

	$post_id = $resource_ids['posts'];
	$revoke_actor = static function ( $caps, $required, $args, $user ) use ( $actor ) {
		return $actor === (int) $user->ID ? array_fill_keys( array_keys( $caps ), false ) : $caps;
	};
	add_filter( 'user_has_cap', $revoke_actor, PHP_INT_MAX, 4 );
	try {
		$before = get_post( $post_id )->post_content;
		$patch_denied = bhranti_action_execute( bhranti_action_command( 'PATCH', '/wp/v2/posts/' . $post_id, array( 'content' => 'This patch must be denied.' ) ), $owner, $session );
		bhranti_action_check( is_wp_error( $patch_denied ) && $before === get_post( $post_id )->post_content, 'PATCH cannot bypass a Bhranti actor whose capabilities are revoked' );
	} finally {
		remove_filter( 'user_has_cap', $revoke_actor, PHP_INT_MAX );
	}
	$search = bhranti_action_success( bhranti_action_execute( array( 'tool' => 'site_search', 'args' => array( 'search' => $action_marker, 'type' => 'post', 'per_page' => 5 ) ), $owner, $session ), 'Native search executes through the bounded tool' );
	bhranti_action_check( count( $search['data'] ) <= 5, 'Search respects result bounds' );
	$open = bhranti_action_success( bhranti_action_execute( array( 'tool' => 'open_item', 'args' => array( 'path' => '/wp/v2/posts/' . $post_id ) ), $owner, $session ), 'Existing WordPress item resolves for navigation' );
	bhranti_action_check( ! empty( $open['target'] ), 'Open tool returns a WordPress navigation target' );
	$bounded = bhranti_action_execute( array( 'tool' => 'site_search', 'args' => array( 'search' => $action_marker, 'per_page' => 100000 ) ), $owner, $session );
	bhranti_action_check( is_wp_error( $bounded ) || count( $bounded['data'] ) <= 20, 'Oversized search is rejected or safely bounded' );
	$race_action = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'PATCH', '/wp/v2/posts/' . $post_id, array( 'content' => 'Before rollback preparation race.' ) ), $owner, $session ), 'Create rollback preparation race fixture' );
	$rollback_race_happened = false;
	$rollback_race = static function ( $meta_id, $event_id, $key, $value ) use ( $post_id, &$rollback_race_happened ) {
		if ( 'bhranti_event_data' === $key && is_array( $value ) && 'rollback_started' === ( $value['type'] ?? '' ) && '/wp/v2/posts/' . $post_id === ( $value['details']['path'] ?? '' ) ) {
			$rollback_race_happened = true;
			// Direct row update models another HTTP request: this request's caches stay stale.
			$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_content' => 'QA manual edit during rollback preparation.' ), array( 'ID' => $post_id ) );
		}
	};
	add_action( 'added_post_meta', $rollback_race, PHP_INT_MAX, 4 );
	try {
		$race_result = Bhranti_Actions::rollback( $race_action['action_id'], $owner, $session );
		bhranti_action_check( $rollback_race_happened && is_wp_error( $race_result ) && 'bhranti_rollback_conflict' === $race_result->get_error_code() && 'QA manual edit during rollback preparation.' === $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT post_content FROM {$GLOBALS['wpdb']->posts} WHERE ID=%d", $post_id ) ), 'Rollback rechecks state after durable audit preparation and preserves a concurrent human edit' );
	} finally {
		remove_action( 'added_post_meta', $rollback_race, PHP_INT_MAX );
	}
	$race_page_id = $resource_ids['pages'];
	$race_page_path = '/wp/v2/pages/' . $race_page_id;
	$race_proposal = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'DELETE', $race_page_path ), $owner, $session ), 'Prepare confirmed deletion race fixture' );
	$delete_race_happened = false;
	$delete_race = static function ( $meta_id, $event_id, $key, $value ) use ( $race_page_id, $race_page_path, &$delete_race_happened ) {
		if ( 'bhranti_event_data' === $key && is_array( $value ) && 'action_started' === ( $value['type'] ?? '' ) && 'DELETE' === ( $value['details']['method'] ?? '' ) && $race_page_path === ( $value['details']['path'] ?? '' ) ) {
			$delete_race_happened = true;
			$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_content' => 'QA manual edit during deletion preparation.' ), array( 'ID' => $race_page_id ) );
		}
	};
	add_action( 'added_post_meta', $delete_race, PHP_INT_MAX, 4 );
	try {
		$race_result = bhranti_action_execute( $race_proposal['command'], $owner, $session, true, $race_proposal['fingerprint'] );
		bhranti_action_check( $delete_race_happened && is_wp_error( $race_result ) && 'bhranti_rollback_conflict' === $race_result->get_error_code() && 'draft' === get_post_status( $race_page_id ) && 'QA manual edit during deletion preparation.' === $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT post_content FROM {$GLOBALS['wpdb']->posts} WHERE ID=%d", $race_page_id ) ), 'Confirmed deletion rechecks state after durable audit preparation and preserves a concurrent human edit' );
	} finally {
		remove_action( 'added_post_meta', $delete_race, PHP_INT_MAX );
	}

	$term = wp_insert_term( $action_marker, 'category', array( 'slug' => $action_marker ) );
	bhranti_action_check( ! is_wp_error( $term ), 'Uniquely marked temporary category seeded' );
	$term_id = (int) $term['term_id'];
	$action_terms[] = array( 'id' => $term_id, 'taxonomy' => 'category' );
	$term_result = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/categories/' . $term_id, array( 'description' => 'Updated QA description.' ) ), $owner, $session ), 'Native REST updates a category' );
	bhranti_action_check( 'Updated QA description.' === get_term( $term_id, 'category' )->description, 'Category update persists' );
	bhranti_action_check( ! is_wp_error( Bhranti_Actions::rollback( $term_result['action_id'], $owner, $session ) ) && '' === get_term( $term_id, 'category' )->description, 'Category rollback restores prior values' );
	bhranti_action_check( is_wp_error( bhranti_action_execute( bhranti_action_command( 'DELETE', '/wp/v2/categories/' . $term_id, array( 'force' => true ) ), $owner, $session ) ) && ! is_wp_error( get_term( $term_id, 'category' ) ), 'Irreversible category deletion blocked' );
	$meta_conflict_action = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/posts/' . $post_id, array( 'content' => 'Before a metadata conflict.' ) ), $owner, $session ), 'Create metadata conflict fixture' );
	update_post_meta( $post_id, 'bhranti_qa_marker', $action_marker );
	$meta_conflict = Bhranti_Actions::rollback( $meta_conflict_action['action_id'], $owner, $session );
	bhranti_action_check( is_wp_error( $meta_conflict ) && 'bhranti_rollback_conflict' === $meta_conflict->get_error_code() && $action_marker === get_post_meta( $post_id, 'bhranti_qa_marker', true ), 'Rollback fingerprint detects concurrent post metadata edits' );
	$term_conflict_action = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/posts/' . $post_id, array( 'content' => 'Before a taxonomy conflict.' ) ), $owner, $session ), 'Create taxonomy conflict fixture' );
	wp_set_post_terms( $post_id, array( $term_id ), 'category' );
	$term_conflict = Bhranti_Actions::rollback( $term_conflict_action['action_id'], $owner, $session );
	bhranti_action_check( is_wp_error( $term_conflict ) && 'bhranti_rollback_conflict' === $term_conflict->get_error_code() && in_array( $term_id, wp_get_post_terms( $post_id, 'category', array( 'fields' => 'ids' ) ), true ), 'Rollback fingerprint detects concurrent taxonomy edits' );
	$expired_action = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/posts/' . $post_id, array( 'content' => 'Before rollback expiry.' ) ), $owner, $session ), 'Create rollback expiry fixture' );
	$snapshots = get_post_meta( $session, 'bhranti_actions', true );
	$snapshots[ $expired_action['action_id'] ]['expires'] = time() - 1;
	update_post_meta( $session, 'bhranti_actions', wp_slash( $snapshots ) );
	$expired_rollback = Bhranti_Actions::rollback( $expired_action['action_id'], $owner, $session );
	bhranti_action_check( is_wp_error( $expired_rollback ) && 'bhranti_rollback_expired' === $expired_rollback->get_error_code() && 'Before rollback expiry.' === get_post( $post_id )->post_content, 'Expired rollback cannot change content' );

	// Comment writes target only this suite's own temporary private post.
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'private', 'comment_status' => 'open' ) );
	$comment = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/comments', array( 'post' => $post_id, 'content' => $action_marker . '-comment' ) ), $owner, $session ), 'Native REST creates a comment on temporary content' );
	$comment_id = (int) $comment['data']['id'];
	$action_comments[] = $comment_id;
	bhranti_action_check( $actor === (int) get_comment( $comment_id )->user_id, 'Comment author identifies Bhranti actor' );
	$comment_update = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/comments/' . $comment_id, array( 'content' => $action_marker . '-comment-updated' ) ), $owner, $session ), 'Native REST updates a comment' );
	bhranti_action_check( ! is_wp_error( Bhranti_Actions::rollback( $comment_update['action_id'], $owner, $session ) ) && $action_marker . '-comment' === get_comment( $comment_id )->comment_content, 'Comment rollback restores prior content' );

	$audit_id = Bhranti_Audit::record( 'qa_identity', 'Temporary identity verification.', $session, array( 'method' => 'POST', 'path' => '/wp/v2/posts', 'password' => 'qa-secret-should-not-be-stored', 'params' => array( 'content' => 'qa-prompt-should-not-be-stored' ) ), $owner, $actor );
	bhranti_action_check( is_int( $audit_id ) && $audit_id > 0, 'Audit metadata persists' );
	$audit = get_post_meta( $audit_id, 'bhranti_event_data', true );
	bhranti_action_check( $owner === (int) $audit['requester_id'] && $actor === (int) $audit['actor_id'] && $session === (int) $audit['session_id'], 'Audit records both requester and Bhranti actor' );
	bhranti_action_check( $session === (int) get_post( $audit_id )->post_parent && $session === (int) get_post_meta( $audit_id, 'bhranti_session_id', true ), 'Audit session binding is persisted for indexed pagination' );
	bhranti_action_check( false === strpos( wp_json_encode( $audit ), 'qa-secret-should-not-be-stored' ) && false === strpos( wp_json_encode( $audit ), 'qa-prompt-should-not-be-stored' ), 'Audit excludes credential and prompt payload fields' );
	$deny_audit = static function ( $check, $id, $key ) {
		return 'bhranti_event_data' === $key ? false : $check;
	};
	add_filter( 'update_post_metadata', $deny_audit, PHP_INT_MAX, 3 );
	try {
		$denied = bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/posts', array( 'title' => $action_marker . '-audit-denied', 'status' => 'draft' ) ), $owner, $session );
		bhranti_action_check( is_wp_error( $denied ), 'An unavailable durable audit stops content mutation' );
		bhranti_action_check( array() === get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => $action_marker . '-audit-denied' ) ), 'Failed audit leaves no created content' );
	} finally {
		remove_filter( 'update_post_metadata', $deny_audit, PHP_INT_MAX );
	}
	$deny_snapshot = static function ( $check, $id, $key ) {
		return 'bhranti_actions' === $key ? false : $check;
	};
	add_filter( 'update_post_metadata', $deny_snapshot, PHP_INT_MAX, 3 );
	try {
		$denied = bhranti_action_execute( bhranti_action_command( 'POST', '/wp/v2/posts', array( 'title' => $action_marker . '-snapshot-denied', 'status' => 'draft' ) ), $owner, $session );
		bhranti_action_check( is_wp_error( $denied ) && 'bhranti_action_storage' === $denied->get_error_code(), 'An unavailable durable rollback snapshot stops content mutation' );
		bhranti_action_check( array() === get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => $action_marker . '-snapshot-denied' ) ), 'Failed rollback storage leaves no created content' );
	} finally {
		remove_filter( 'update_post_metadata', $deny_snapshot, PHP_INT_MAX );
	}

	AiClient::defaultRegistry()->registerProvider( Bhranti_QA_Provider::class );
	WP_Connector_Registry::get_instance()->register( 'bhranti-qa', array( 'name' => 'Bhranti local QA', 'type' => 'ai_provider', 'authentication' => array( 'method' => 'none' ) ) );
	$loop_title = $action_marker . '-agent-loop';
	Bhranti_QA_Model::$responses = array(
		wp_json_encode( array( 'type' => 'tool', 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => '/wp/v2/posts', 'params' => array( 'title' => $loop_title, 'content' => 'Temporary loop-created draft.', 'status' => 'draft' ) ) ) ),
		wp_json_encode( array( 'type' => 'reply', 'content' => 'The temporary draft was created.' ) ),
	);
	$run_id = bhranti_action_start_run( $session, 'Create only the uniquely marked temporary draft.', array( 'url' => 'https://bhranti.wp.local/wp-admin/edit.php?nonce=qa-secret-token', 'title' => 'Untrusted context', 'post_id' => $post_id ) );
	$stored_run = get_post_meta( $session, 'bhranti_run', true );
	bhranti_action_check( 'https://bhranti.wp.local/wp-admin/edit.php' === $stored_run['context']['url'], 'Page context removes nonce and token query parameters' );
	wp_set_current_user( $other );
	bhranti_action_check( 404 === bhranti_action_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $run_id ) )->get_status(), 'Another administrator cannot advance the run' );
	wp_set_current_user( $owner );
	bhranti_action_check( 409 === bhranti_action_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => wp_generate_uuid4() ) )->get_status(), 'A stale or substituted run identifier is rejected' );
	$before_calls = count( Bhranti_QA_Model::$calls );
	$first_step = bhranti_action_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $run_id ) );
	$loop_posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'draft', 'title' => $loop_title ) );
	foreach ( $loop_posts as $loop_post ) {
		$action_posts[] = $loop_post->ID;
	}
	bhranti_action_check( 200 === $first_step->get_status() && 'running' === $first_step->get_data()['run']['status'] && $before_calls + 1 === count( Bhranti_QA_Model::$calls ) && 1 === count( $loop_posts ), 'One step makes one model call and executes one real WordPress action' );
	bhranti_action_check( $actor === (int) $loop_posts[0]->post_author && '' === $first_step->get_data()['run']['phase'] && ! isset( $first_step->get_data()['run']['history'] ) && ! isset( $first_step->get_data()['run']['pending_plan'] ) && ! isset( $first_step->get_data()['run']['configuration'] ), 'Agent content identifies Bhranti and exposes only its settled public phase without private run data' );
	$first_step_data = $first_step->get_data();
	$loop_event = end( $first_step_data['events'] );
	bhranti_action_check( ! empty( $loop_event['action_id'] ) && ! empty( $loop_event['rollback_available'] ), 'Action timeline exposes the concrete rollback identifier' );
	$last_step = bhranti_action_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $run_id ) );
	bhranti_action_check( 200 === $last_step->get_status() && 'complete' === $last_step->get_data()['run']['status'] && $before_calls + 2 === count( Bhranti_QA_Model::$calls ), 'Next step returns a final reply without another content action' );
	$before_replay = count( Bhranti_QA_Model::$calls );
	bhranti_action_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $run_id ) );
	bhranti_action_check( $before_replay === count( Bhranti_QA_Model::$calls ), 'A completed step cannot invoke the model again' );
	$rolled_back = bhranti_action_rest( 'POST', '/sessions/' . $session . '/rollback', array( 'action_id' => $loop_event['action_id'] ) );
	bhranti_action_check( 200 === $rolled_back->get_status() && 'trash' === get_post_status( $loop_posts[0]->ID ), 'Authenticated rollback endpoint reverses only the loop-created draft' );
	$rollback_session_response = bhranti_action_rest( 'POST', '/sessions' );
	bhranti_action_check( 200 === $rollback_session_response->get_status(), 'Independent temporary rollback session created' );
	$rollback_session = $rollback_session_response->get_data()['id'];
	$action_sessions[] = $rollback_session;
	$bound_action = bhranti_action_success( bhranti_action_execute( bhranti_action_command( 'PATCH', '/wp/v2/posts/' . $post_id, array( 'content' => 'Session-bound rollback fixture.' ) ), $owner, $session ), 'Create session-bound rollback fixture' );
	$wrong_session_rollback = bhranti_action_rest( 'POST', '/sessions/' . $rollback_session . '/rollback', array( 'action_id' => $bound_action['action_id'] ) );
	bhranti_action_check( $wrong_session_rollback->get_status() >= 400 && 'Session-bound rollback fixture.' === get_post( $post_id )->post_content, 'Rollback identifier cannot cross its session boundary' );
	bhranti_action_check( ! is_wp_error( Bhranti_Actions::rollback( $bound_action['action_id'], $owner, $session ) ), 'Originating session can restore its own action' );

	$unsaved_title = $action_marker . '-unsaved-context';
	Bhranti_QA_Model::$responses = array( wp_json_encode( array( 'type' => 'tool', 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => '/wp/v2/posts', 'params' => array( 'title' => $unsaved_title, 'status' => 'draft' ) ) ) ) );
	$unsaved_run = bhranti_action_start_run( $session, 'This test context has an unsaved editor change.', array( 'unsaved_changes' => true, 'post_id' => $post_id, 'selected_block' => array( 'name' => 'core/paragraph', 'content' => str_repeat( 'x', 20000 ) ) ) );
	$unsaved_step = bhranti_action_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $unsaved_run ) );
	$unsaved_posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => $unsaved_title ) );
	foreach ( $unsaved_posts as $post ) {
		$action_posts[] = $post->ID;
	}
	bhranti_action_check( 200 === $unsaved_step->get_status() && array() === $unsaved_posts, 'Unsaved editor context blocks model-requested writes' );
	bhranti_action_rest( 'POST', '/sessions/' . $session . '/cancel', array( 'run_id' => $unsaved_run ) );

	$page_id = $resource_ids['pages'];
	$page_path = '/wp/v2/pages/' . $page_id;
	$page_before_cancel = bhranti_action_native_post_state( $page_id );
	$actions_before_cancel = get_post_meta( $session, 'bhranti_actions', true );
	$confirmation = bhranti_action_propose_delete( $session, $page_path );
	wp_set_current_user( $other );
	bhranti_action_check( 404 === bhranti_action_rest( 'POST', '/sessions/' . $session . '/confirm', array( 'confirmation_id' => $confirmation['confirmation']['id'], 'approved' => true ) )->get_status() && 'draft' === get_post_status( $page_id ), 'Other administrator cannot approve a deletion' );
	wp_set_current_user( $owner );
	bhranti_action_check( 409 === bhranti_action_rest( 'POST', '/sessions/' . $session . '/confirm', array( 'confirmation_id' => wp_generate_uuid4(), 'approved' => true ) )->get_status(), 'Tampered confirmation identifier is rejected' );
	$cancelled = bhranti_action_rest( 'POST', '/sessions/' . $session . '/confirm', array( 'confirmation_id' => $confirmation['confirmation']['id'], 'approved' => false ) );
	bhranti_action_check( 200 === $cancelled->get_status() && 'cancelled' === $cancelled->get_data()['run']['status'] && 'draft' === get_post_status( $page_id ), 'Rejecting confirmation leaves its target intact' );
	bhranti_action_check( $page_before_cancel === bhranti_action_native_post_state( $page_id ) && $actions_before_cancel === get_post_meta( $session, 'bhranti_actions', true ), 'Rejecting deletion changes no native publication state and creates no mutation snapshot' );

	$confirmation = bhranti_action_propose_delete( $session, $page_path );
	$expiry_run = get_post_meta( $session, 'bhranti_run', true );
	$expiry_run['confirmation']['expires'] = time() - 1;
	update_post_meta( $session, 'bhranti_run', wp_slash( $expiry_run ) );
	$expired = bhranti_action_rest( 'POST', '/sessions/' . $session . '/confirm', array( 'confirmation_id' => $confirmation['confirmation']['id'], 'approved' => true ) );
	bhranti_action_check( 200 === $expired->get_status() && 'cancelled' === $expired->get_data()['run']['status'] && 'draft' === get_post_status( $page_id ), 'Expired confirmation cannot delete content' );

	$confirmation = bhranti_action_propose_delete( $session, $page_path );
	wp_update_post( array( 'ID' => $page_id, 'post_content' => 'Changed after the approval proposal.' ) );
	$changed = bhranti_action_rest( 'POST', '/sessions/' . $session . '/confirm', array( 'confirmation_id' => $confirmation['confirmation']['id'], 'approved' => true ) );
	bhranti_action_check( 200 === $changed->get_status() && 'cancelled' === $changed->get_data()['run']['status'] && 'Changed after the approval proposal.' === get_post( $page_id )->post_content && 'draft' === get_post_status( $page_id ), 'Fingerprint binding stops deletion after a human edit' );

	$confirmation = bhranti_action_propose_delete( $session, $page_path );
	$other_session_response = bhranti_action_rest( 'POST', '/sessions' );
	bhranti_action_check( 200 === $other_session_response->get_status(), 'Second temporary session created' );
	$other_session = $other_session_response->get_data()['id'];
	$action_sessions[] = $other_session;
	bhranti_action_check( 409 === bhranti_action_rest( 'POST', '/sessions/' . $other_session . '/confirm', array( 'confirmation_id' => $confirmation['confirmation']['id'], 'approved' => true ) )->get_status(), 'Confirmation is bound to its originating session' );
	$approved = bhranti_action_rest( 'POST', '/sessions/' . $session . '/confirm', array( 'confirmation_id' => $confirmation['confirmation']['id'], 'approved' => true, 'command' => bhranti_action_command( 'DELETE', '/wp/v2/posts/' . $post_id, array( 'force' => true ) ), 'fingerprint' => 'client-tamper' ) );
	bhranti_action_check( 200 === $approved->get_status() && 'trash' === get_post_status( $page_id ) && 'private' === get_post_status( $post_id ), 'Approval ignores client-supplied command and uses the stored canonical target' );
	bhranti_action_check( 409 === bhranti_action_rest( 'POST', '/sessions/' . $session . '/confirm', array( 'confirmation_id' => $confirmation['confirmation']['id'], 'approved' => true ) )->get_status(), 'Confirmation is consumed exactly once' );
	$approved_data = $approved->get_data();
	$approved_event = end( $approved_data['events'] );
	bhranti_action_check( 409 === bhranti_action_rest( 'POST', '/sessions/' . $session . '/rollback', array( 'action_id' => $approved_event['action_id'] ) )->get_status(), 'Rollback cannot race an active run' );
	bhranti_action_rest( 'POST', '/sessions/' . $session . '/cancel', array( 'run_id' => $approved->get_data()['run']['id'] ) );
	bhranti_action_check( 200 === bhranti_action_rest( 'POST', '/sessions/' . $session . '/rollback', array( 'action_id' => $approved_event['action_id'] ) )->get_status() && 'draft' === get_post_status( $page_id ), 'Approved trash can be restored after the run is stopped' );

	Bhranti_QA_Model::$responses = array_fill( 0, 2, '{"type":"tool","tool":"php_eval","args":{"code":"never-execute"}}' );
	$unsafe_run = bhranti_action_start_run( $session, 'Return an unsupported action for validation.' );
	$unsafe_content_before = bhranti_action_content_state();
	$unsafe_retry = bhranti_action_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $unsafe_run ) );
	bhranti_action_check( 200 === $unsafe_retry->get_status() && 'running' === $unsafe_retry->get_data()['run']['status'] && $unsafe_content_before === bhranti_action_content_state(), 'Unsupported generated actions remain unexecuted while one bounded formatting correction is requested' );
	$unsafe = bhranti_action_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $unsafe_run ) );
	bhranti_action_check( 200 === $unsafe->get_status() && 'failed' === $unsafe->get_data()['run']['status'] && $unsafe_content_before === bhranti_action_content_state(), 'Repeated malformed or unsupported model action fails the run safely' );
	$interrupted_run = bhranti_action_start_run( $session, 'Simulate a request interrupted after its state was saved.' );
	$interrupted_state = get_post_meta( $session, 'bhranti_run', true );
	$interrupted_state['phase'] = 'action';
	update_post_meta( $session, 'bhranti_run', wp_slash( $interrupted_state ) );
	$before_interruption = count( Bhranti_QA_Model::$calls );
	$interrupted = bhranti_action_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $interrupted_run ) );
	bhranti_action_check( 200 === $interrupted->get_status() && 'failed' === $interrupted->get_data()['run']['status'] && $before_interruption === count( Bhranti_QA_Model::$calls ), 'Interrupted action phase is terminal and cannot be replayed automatically' );

	Bhranti_QA_Model::$responses = array_fill( 0, 13, wp_json_encode( array( 'type' => 'tool', 'tool' => 'site_search', 'args' => array( 'search' => $action_marker, 'per_page' => 1 ) ) ) );
	$bounded_run = bhranti_action_start_run( $session, 'Exercise the bounded search loop using only QA markers.' );
	$bounded_state = get_post_meta( $session, 'bhranti_run', true );
	$bounded_state['configuration']['max_steps'] = 12;
	update_post_meta( $session, 'bhranti_run', wp_slash( $bounded_state ) );
	$before_bound = count( Bhranti_QA_Model::$calls );
	for ( $index = 0; $index < 13; ++$index ) {
		$bounded_step = bhranti_action_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $bounded_run ) );
		bhranti_action_check( 200 === $bounded_step->get_status(), 'Bounded loop step ' . $index . ' completes through REST' );
	}
	bhranti_action_check( 'failed' === $bounded_step->get_data()['run']['status'] && 12 === $bounded_step->get_data()['run']['step'] && $before_bound + 12 === count( Bhranti_QA_Model::$calls ), 'A run respects its captured twelve-step fixture limit (status=' . $bounded_step->get_data()['run']['status'] . ', step=' . $bounded_step->get_data()['run']['step'] . ', calls=' . ( count( Bhranti_QA_Model::$calls ) - $before_bound ) . ')' );
	$privacy_login = 'bhranti_action_qa_' . str_replace( '-', '', wp_generate_uuid4() );
	$privacy_email = $privacy_login . '@example.test';
	$privacy_user = wp_insert_user( array( 'user_login' => $privacy_login, 'user_pass' => wp_generate_password(), 'role' => 'administrator', 'user_email' => $privacy_email ) );
	bhranti_action_check( is_int( $privacy_user ) && $privacy_user > 0, 'Isolated privacy-test administrator created' );
	$action_users[] = $privacy_user;
	wp_set_current_user( $privacy_user );
	$privacy_session_response = bhranti_action_rest( 'POST', '/sessions' );
	bhranti_action_check( 200 === $privacy_session_response->get_status(), 'Isolated privacy conversation created' );
	$privacy_session = $privacy_session_response->get_data()['id'];
	$action_sessions[] = $privacy_session;
	$privacy_snapshot_id = wp_generate_uuid4();
	update_post_meta( $privacy_session, 'bhranti_messages', array( array( 'role' => 'user', 'content' => $action_marker . '-privacy-own-transcript', 'created' => gmdate( 'c' ) ) ) );
	update_post_meta( $privacy_session, 'bhranti_run', array( 'id' => wp_generate_uuid4(), 'status' => 'complete', 'step' => 1, 'requester_id' => $privacy_user, 'actor_id' => $actor, 'context' => array( 'title' => 'Temporary privacy context' ) ) );
	update_post_meta( $privacy_session, 'bhranti_events', array( array( 'summary' => 'Temporary privacy event', 'action_id' => $privacy_snapshot_id ) ) );
	update_post_meta( $privacy_session, 'bhranti_actions', array( $privacy_snapshot_id => array( 'requester_id' => $privacy_user, 'actor_id' => $actor, 'restore' => array( 'content' => 'Temporary privacy rollback content' ) ) ) );
	// The native session-created log plus these twenty logs cross one privacy page.
	for ( $index = 0; $index < 20; ++$index ) {
		$privacy_audit = Bhranti_Audit::record( 'privacy_qa', 'Temporary privacy audit ' . $index, $privacy_session, array(), $privacy_user, $actor );
		bhranti_action_check( is_int( $privacy_audit ) && $privacy_audit > 0, 'Temporary privacy log ' . $index . ' persists' );
	}
	wp_set_current_user( $other );
	$retained_session_response = bhranti_action_rest( 'POST', '/sessions' );
	bhranti_action_check( 200 === $retained_session_response->get_status(), 'Other user privacy conversation created' );
	$retained_session = $retained_session_response->get_data()['id'];
	$action_sessions[] = $retained_session;
	update_post_meta( $retained_session, 'bhranti_messages', array( array( 'role' => 'user', 'content' => $action_marker . '-privacy-other-secret', 'created' => gmdate( 'c' ) ) ) );
	$retained_audit = Bhranti_Audit::record( 'privacy_other_qa', 'Other user private audit.', $retained_session, array(), $other, $actor );
	bhranti_action_check( is_int( $retained_audit ) && $retained_audit > 0, 'Other user privacy audit created' );
	$privacy_first = Bhranti_Privacy::export( $privacy_email, 1 );
	$privacy_second = Bhranti_Privacy::export( $privacy_email, 2 );
	$privacy_encoded = html_entity_decode( wp_json_encode( array( $privacy_first, $privacy_second ) ), ENT_QUOTES, 'UTF-8' );
	bhranti_action_check( false === $privacy_first['done'] && true === $privacy_second['done'] && 21 === count( $privacy_first['data'] ) && 1 === count( $privacy_second['data'] ), 'Privacy export paginates mixed one-session and twenty-one-log data correctly' );
	$privacy_state = json_decode( html_entity_decode( $privacy_first['data'][0]['data'][3]['value'], ENT_QUOTES, 'UTF-8' ), true );
	bhranti_action_check( $privacy_user === (int) $privacy_state['run']['requester_id'] && $actor === (int) $privacy_state['run']['actor_id'] && isset( $privacy_state['events'][0], $privacy_state['rollback_records'][ $privacy_snapshot_id ] ), 'Privacy export includes owned request context, operation events, rollback records and both identities' );
	bhranti_action_check( false !== strpos( $privacy_encoded, $action_marker . '-privacy-own-transcript' ) && false === strpos( $privacy_encoded, $action_marker . '-privacy-other-secret' ), 'Privacy export includes own transcript and excludes another user conversation' );
	$privacy_erased_first = Bhranti_Privacy::erase( $privacy_email, 1 );
	$privacy_erased_second = Bhranti_Privacy::erase( $privacy_email, 2 );
	bhranti_action_check( true === $privacy_erased_first['items_removed'] && false === $privacy_erased_first['done'] && true === $privacy_erased_second['items_removed'] && true === $privacy_erased_second['done'], 'Privacy erasure drains the first remaining batch without pagination skips' );
	bhranti_action_check( ! get_post( $privacy_session ) && get_post( $retained_session ) && get_post( $retained_audit ) && get_post( $session ) && get_post( $post_id ), 'Privacy erasure removes only requested disposable logs and chats while preserving other users and ordinary content' );
	bhranti_action_check( array() === Bhranti_Privacy::export( $privacy_email, 1 )['data'], 'Erased user retains no exported Bhranti data' );
	wp_set_current_user( $owner );
	bhranti_action_check( array() === $action_http, 'Action tests make no external HTTP requests' );
} catch ( Throwable $error ) {
	$action_failure = $error;
} finally {
	// Only resources tracked after creation by this suite are removed.
	bhranti_action_restore_fixture_options();
	foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'posts_per_page' => -1, 's' => $action_marker ) ) as $post ) {
		if ( isset( $actor ) && (int) $post->post_author === $actor && 0 === strpos( $post->post_title, $action_marker ) ) {
			$action_posts[] = $post->ID;
		}
	}
	foreach ( array_unique( $action_comments ) as $id ) {
		wp_delete_comment( $id, true );
	}
	foreach ( $action_terms as $term ) {
		wp_delete_term( $term['id'], $term['taxonomy'] );
	}
	foreach ( array_unique( array_merge( $action_posts, $action_sessions ) ) as $id ) {
		if ( get_post( $id ) ) {
			wp_delete_post( $id, true );
		}
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array_unique( $action_users ) as $id ) {
		foreach ( get_posts( array( 'post_type' => 'bhranti_event', 'post_status' => 'private', 'author' => $id, 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $event_id ) {
			wp_delete_post( $event_id, true );
		}
		delete_option( 'bhranti_lock_' . $id );
		wp_delete_user( $id );
	}
	$action_content_preserved = null !== $action_original_content && $action_original_content === bhranti_action_content_state();
	foreach ( array_keys( $action_fixture_options ) as $name ) {
		if ( ! bhranti_action_fixture_option_preserved( $name ) && ! $action_failure ) {
			$action_failure = new RuntimeException( 'FAIL: Final cleanup changed the stored value/autoload of site option ' . $name );
		}
	}
	if ( ! $action_content_preserved && ! $action_failure ) {
		$action_failure = new RuntimeException( 'FAIL: All pre-existing ordinary site content must be unchanged after scoped test cleanup.' );
	} elseif ( ! $action_failure ) {
		++$action_checks;
	}
	wp_set_current_user( $action_original_user );
	remove_filter( 'pre_http_request', $action_http_guard, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
	unregister_post_type( 'bhranti_qa_type' );
	$action_registry_property->setValue( null, $action_original_registry );
	AiClient::setEventDispatcher( $action_original_dispatcher );
	AiClient::setCache( $action_original_cache );
	remove_filter( 'pre_schedule_event', $action_runner_veto, PHP_INT_MAX ); restore_error_handler();
}
if ( $action_failure ) {
	fwrite( STDERR, $action_failure->getMessage() . "\nCompleted checks: " . $action_checks . '; ordinary content preserved after cleanup: ' . ( $action_content_preserved ? 'yes' : 'no' ) . "\n" );
	exit( 1 );
}
echo 'PASS: ' . $action_checks . " WordPress action checks; only temporary resources targeted and cleaned up.\n";
