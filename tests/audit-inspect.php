<?php
/**
 * Native, private activity-inspection regressions without HTTP or AI requests.
 *
 * BHRANTI_INSPECT_TEST_WP_ROOT=/private/tmp/bhranti-inspect-qa-20261014 php tests/audit-inspect.php
 * The isolated WordPress fixture is required. Only marked fixtures and read audits write.
 *
 * @package Bhranti
 */

$bai_root = realpath( getenv( 'BHRANTI_INSPECT_TEST_WP_ROOT' ) ?: '' );
if ( '/private/tmp/bhranti-inspect-qa-20261014' !== $bai_root ) {
	fwrite( STDERR, "Use only the independent private inspector QA runtime.\n" );
	exit( 1 );
}
define( 'WP_USE_THEMES', false );
require $bai_root . '/wp-load.php';
if ( 'https://bhranti-checker.invalid' !== get_option( 'home' ) || 'https://bhranti-checker.invalid' !== get_option( 'siteurl' ) || get_option( 'active_plugins' ) || ! DISABLE_WP_CRON || realpath( DB_DIR ) !== realpath( $bai_root . '/wp-content/database' ) || '7.1.3' !== $wp_version ) {
	fwrite( STDERR, "The isolated native WordPress 7.1.3 runtime is required.\n" );
	exit( 1 );
}
require dirname( __DIR__ ) . '/bhranti.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
Bhranti_Workspace::register_storage();
Bhranti_Audit::register_storage();

$bai_checks        = 0;
$bai_http          = 0;
$bai_mail          = 0;
$bai_owner         = 0;
$bai_other         = 0;
$bai_actor         = 0;
$bai_session       = 0;
$bai_other_session = 0;
$bai_post          = 0;
$bai_failure       = null;
$bai_marker        = 'bhranti-audit-inspect-' . str_replace( '-', '', wp_generate_uuid4() );
$bai_original_user = get_current_user_id();
$bai_options       = array();
foreach ( array( 'wp_user_roles', 'bhranti_actor_id', 'bhranti_role_owned', 'bhranti_actor_setup_lock' ) as $bai_key ) {
	$bai_options[ $bai_key ] = get_option( $bai_key, null );
}
$bai_tables = array();
foreach ( array( 'posts', 'postmeta', 'users', 'usermeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta' ) as $bai_table ) {
	$bai_tables[ $bai_table ] = hash( 'sha256', wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . $wpdb->$bai_table . ' ORDER BY 1,2', ARRAY_A ) ) );
}
$bai_http_guard = static function () {
	++$GLOBALS['bai_http'];
	return new WP_Error( 'bhranti_audit_qa_offline', 'Outbound requests are disabled.' );
};
$bai_mail_guard = static function () {
	++$GLOBALS['bai_mail'];
	return false;
};
add_filter( 'pre_http_request', $bai_http_guard );
add_filter( 'pre_wp_mail', $bai_mail_guard );

/** Assert behavior without printing private fixture text. */
function bai_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$GLOBALS['bai_checks'];
}

/** Execute through the actual reviewed action/schema pipeline. */
function bai_inspect( $args, $session_id = 0 ) {
	return Bhranti_Actions::execute( array( 'tool' => 'bhranti_inspect', 'args' => $args ), $GLOBALS['bai_owner'], $session_id ?: $GLOBALS['bai_session'] );
}

try {
	$bai_owner = wp_insert_user( array( 'user_login' => $bai_marker, 'user_pass' => wp_generate_password( 40 ), 'user_email' => $bai_marker . '@example.invalid', 'role' => 'administrator' ) );
	$bai_other = wp_insert_user( array( 'user_login' => $bai_marker . '-other', 'user_pass' => wp_generate_password( 40 ), 'user_email' => $bai_marker . '-other@example.invalid', 'role' => 'administrator' ) );
	bai_check( is_int( $bai_owner ) && is_int( $bai_other ), 'Two independent human fixtures exist.' );
	wp_set_current_user( $bai_owner );
	$bai_actor = Bhranti_Actions::ensure_actor();
	bai_check( is_int( $bai_actor ) && $bai_actor !== $bai_owner, 'A real managed account is available.' );
	$bai_session = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $bai_owner, 'post_title' => $bai_marker, 'post_content' => 'PRIVATE-CHAT-CONTENT-' . $bai_marker ), true );
	$bai_other_session = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $bai_other, 'post_title' => 'PRIVATE-CHAT-TITLE-' . $bai_marker, 'post_content' => 'OTHER-PRIVATE-CHAT-' . $bai_marker ), true );
	$bai_post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => $bai_owner, 'post_title' => $bai_marker, 'post_content' => 'UNCHANGED-POST-' . $bai_marker ), true );
	bai_check( is_int( $bai_session ) && is_int( $bai_other_session ) && is_int( $bai_post ), 'Owned conversations and draft fixture exist.' );
	$bai_run = wp_generate_uuid4();
	$bai_failed = Bhranti_Audit::record( 'action_failed', 'Native content action failed.', $bai_session, array( 'tool' => 'wp_request', 'method' => 'POST', 'path' => '/wp/v2/posts/' . $bai_post, 'target_id' => $bai_post, 'target_type' => 'post', 'code' => 'bhranti_action_conflict', 'run_id' => $bai_run ), $bai_owner, $bai_actor );
	$bai_recovered = Bhranti_Audit::record( 'action_completed', 'Native content action completed.', $bai_session, array( 'tool' => 'wp_request', 'method' => 'POST', 'path' => '/wp/v2/posts/' . $bai_post, 'target_id' => $bai_post, 'target_type' => 'post', 'run_id' => $bai_run ), $bai_owner, $bai_actor );
	$bai_other_event = Bhranti_Audit::record( 'action_failed', 'Native plugin action failed.', $bai_other_session, array( 'tool' => 'plugin_request', 'method' => 'POST', 'path' => '/wp/v2/plugins', 'code' => 'bhranti_plugin_permission' ), $bai_other, $bai_actor );
	bai_check( is_int( $bai_failed ) && is_int( $bai_recovered ) && is_int( $bai_other_event ), 'Actual audit records contain two identities and operation metadata.' );
	$bai_tainted = get_post_meta( $bai_failed, 'bhranti_event_data', true );
	$bai_tainted['prompt'] = 'PRIVATE-PROMPT-' . $bai_marker;
	$bai_tainted['snapshot'] = array( 'content' => 'PRIVATE-SNAPSHOT-' . $bai_marker );
	$bai_tainted['details']['params'] = array( 'content' => 'PRIVATE-PARAMS-' . $bai_marker );
	update_post_meta( $bai_failed, 'bhranti_event_data', $bai_tainted );
	$bai_native_before = array();
	foreach ( array( $bai_session, $bai_other_session, $bai_post ) as $bai_id ) {
		$bai_native_before[ $bai_id ] = array( get_post( $bai_id, ARRAY_A ), get_post_meta( $bai_id ) );
	}
	$bai_events_before = Bhranti_Audit::query( array( 'session_id' => $bai_session ) );
	$bai_default = bai_inspect( array( 'resource' => 'logs' ) );
	bai_check( ! is_wp_error( $bai_default ) && 'conversation' === $bai_default['data']['scope'], 'Log inspection requires no native path and defaults to the current chat.' );
	bai_check( 2 === $bai_default['data']['total'] && 2 === count( $bai_default['data']['events'] ), 'The read snapshot excludes its own audit entries and another chat.' );
	bai_check( array( $bai_recovered, $bai_failed ) === array_column( $bai_default['data']['events'], 'event_id' ), 'Newest successful recovery and older failure remain in the actual time order.' );
	bai_check( $bai_owner === $bai_default['data']['events'][0]['requester_id'] && $bai_actor === $bai_default['data']['events'][0]['actor_id'], 'Readable results preserve both requester and acting identity.' );
	bai_check( 'success' === $bai_default['data']['events'][0]['activity']['status'] && 'error' === $bai_default['data']['events'][1]['activity']['status'], 'Readable activity explains successful and failed outcomes.' );
	bai_check( ! empty( $bai_default['data']['events'][1]['activity']['explanation'] ) && ! empty( $bai_default['data']['events'][1]['activity']['next_step'] ), 'A failure has a plain-language explanation and next step.' );
	$bai_events_after = Bhranti_Audit::query( array( 'session_id' => $bai_session ) );
	bai_check( $bai_events_before['total'] + 2 === $bai_events_after['total'], 'Exactly one read-start and one read-result record audit the query without recursion.' );
	$bai_filtered = bai_inspect( array( 'resource' => 'logs', 'scope' => 'site', 'run_id' => $bai_run, 'status' => 'error', 'category' => 'content', 'requester_id' => $bai_owner, 'actor_id' => $bai_actor, 'search' => 'failed', 'from' => gmdate( 'Y-m-d' ), 'to' => gmdate( 'Y-m-d' ), 'page' => 1, 'per_page' => 1 ) );
	bai_check( ! is_wp_error( $bai_filtered ) && 1 === $bai_filtered['data']['total'] && $bai_failed === $bai_filtered['data']['events'][0]['event_id'], 'All documented filters combine through the real audit query.' );
	$bai_page = bai_inspect( array( 'resource' => 'logs', 'run_id' => $bai_run, 'page' => 2, 'per_page' => 1 ) );
	bai_check( ! is_wp_error( $bai_page ) && 2 === $bai_page['data']['total'] && 2 === $bai_page['data']['pages'] && $bai_failed === $bai_page['data']['events'][0]['event_id'], 'Bounded pagination retrieves the older matching event rather than repeating page one.' );
	$bai_site = bai_inspect( array( 'resource' => 'logs', 'scope' => 'site', 'session_id' => $bai_other_session ) );
	bai_check( ! is_wp_error( $bai_site ) && 1 === $bai_site['data']['total'] && $bai_other === $bai_site['data']['events'][0]['requester_id'], 'An administrator can inspect site activity metadata from another account.' );
	$bai_payload = wp_json_encode( array( $bai_default, $bai_filtered, $bai_site ) );
	foreach ( array( 'PRIVATE-CHAT-CONTENT-', 'PRIVATE-CHAT-TITLE-', 'OTHER-PRIVATE-CHAT-', 'PRIVATE-PROMPT-', 'PRIVATE-SNAPSHOT-', 'PRIVATE-PARAMS-' ) as $bai_private ) {
		bai_check( false === strpos( $bai_payload, $bai_private ), 'Private conversation, prompt, action argument and snapshot payloads are never exposed.' );
	}
	bai_check( false !== strpos( $bai_payload, 'Untrusted historical activity metadata' ) && false === strpos( $bai_payload, 'bhranti_session=' ), 'Inspection marks observations as untrusted and provides no private chat links.' );
	bai_check( is_wp_error( bai_inspect( array( 'resource' => 'logs', 'scope' => 'site' ), $bai_other_session ) ), 'Site-wide audit access never permits using another account’s private conversation.' );
	foreach ( array(
		array( 'resource' => 'logs', 'session_id' => $bai_other_session ),
		array( 'resource' => 'logs', 'scope' => 'all' ),
		array( 'resource' => 'logs', 'status' => 'complete' ),
		array( 'resource' => 'logs', 'category' => 'secret' ),
		array( 'resource' => 'logs', 'path' => '/wp/v2/posts/' . $bai_post ),
		array( 'resource' => 'logs', 'method' => 'POST' ),
		array( 'resource' => 'logs', 'block_path' => '0' ),
		array( 'resource' => 'logs', 'aspect_ratio' => '16:9' ),
		array( 'resource' => 'logs', 'params' => array() ),
		array( 'resource' => 'logs', 'max_id' => $bai_failed ),
		array( 'resource' => 'logs', 'page' => 0 ),
		array( 'resource' => 'logs', 'per_page' => 21 ),
		array( 'resource' => 'logs', 'page' => '1' ),
		array( 'resource' => 'logs', 'requester_id' => 0 ),
		array( 'resource' => 'logs', 'actor_id' => '1' ),
		array( 'resource' => 'logs', 'search' => str_repeat( 'x', 101 ) ),
		array( 'resource' => 'logs', 'run_id' => '../other' ),
		array( 'resource' => 'logs', 'from' => '2026-02-30' ),
		array( 'resource' => 'logs', 'from' => '2026-10-17', 'to' => '2026-10-01' ),
		array( 'resource' => 'route', 'path' => '/wp/v2/pages', 'scope' => 'site' ),
		array( 'resource' => 'content', 'path' => '/wp/v2/posts/' . $bai_post, 'status' => 'error' ),
		array( 'resource' => 'content', 'path' => '/wp/v2/bhranti_session/' . $bai_other_session ),
	) as $bai_bad ) {
		bai_check( is_wp_error( bai_inspect( $bai_bad ) ), 'Unbounded, malformed, cross-resource and write-style log arguments fail closed: ' . wp_json_encode( $bai_bad ) );
	}
	$bai_cap_guard = static function ( $allcaps, $caps, $args, $user ) use ( $bai_actor ) {
		if ( $bai_actor === $user->ID ) {
			$allcaps['manage_options'] = false;
		}
		return $allcaps;
	};
	add_filter( 'user_has_cap', $bai_cap_guard, 10, 4 );
	try {
		bai_check( is_wp_error( bai_inspect( array( 'resource' => 'logs', 'scope' => 'site' ) ) ), 'A revoked acting account cannot read activity metadata.' );
	} finally {
		remove_filter( 'user_has_cap', $bai_cap_guard, 10 );
	}
	$bai_cap_guard = static function ( $allcaps, $caps, $args, $user ) use ( $bai_owner ) {
		if ( $bai_owner === $user->ID ) {
			$allcaps['manage_options'] = false;
		}
		return $allcaps;
	};
	add_filter( 'user_has_cap', $bai_cap_guard, 10, 4 );
	try {
		bai_check( is_wp_error( bai_inspect( array( 'resource' => 'logs' ) ) ), 'Managed-account authority never broadens a revoked human account.' );
	} finally {
		remove_filter( 'user_has_cap', $bai_cap_guard, 10 );
	}
	wp_set_current_user( $bai_other );
	bai_check( is_wp_error( bai_inspect( array( 'resource' => 'logs', 'scope' => 'site' ) ) ), 'Current native account must match the requesting human.' );
	wp_set_current_user( $bai_owner );
	$bai_native_schema = bai_inspect( array( 'resource' => 'route', 'path' => '/wp/v2/pages', 'method' => 'GET' ) );
	bai_check( ! is_wp_error( $bai_native_schema ) && isset( $bai_native_schema['data']['parameters'] ), 'Normal native inspection still works after adding log filters.' );
	foreach ( $bai_native_before as $bai_id => $bai_before ) {
		bai_check( $bai_before === array( get_post( $bai_id, ARRAY_A ), get_post_meta( $bai_id ) ), 'Diagnostic queries preserve every native content and private conversation record.' );
	}
} catch ( Throwable $bai_error ) {
	$bai_failure = $bai_error->getMessage();
} finally {
	wp_set_current_user( $bai_original_user );
	foreach ( array( $bai_owner, $bai_other ) as $bai_user_id ) {
		if ( ! $bai_user_id ) {
			continue;
		}
		foreach ( get_posts( array( 'post_type' => 'bhranti_event', 'post_status' => 'private', 'author' => $bai_user_id, 'numberposts' => -1 ) ) as $bai_event ) {
			wp_delete_post( $bai_event->ID, true );
		}
	}
	foreach ( array( $bai_session, $bai_other_session, $bai_post ) as $bai_id ) {
		$bai_item = $bai_id ? get_post( $bai_id ) : null;
		if ( $bai_item && in_array( (int) $bai_item->post_author, array( $bai_owner, $bai_other ), true ) ) {
			wp_delete_post( $bai_id, true );
		}
	}
	foreach ( array( $bai_owner, $bai_other ) as $bai_user_id ) {
		if ( $bai_user_id && get_userdata( $bai_user_id ) && 0 === strpos( get_userdata( $bai_user_id )->user_login, $bai_marker ) ) {
			wp_delete_user( $bai_user_id );
		}
	}
	if ( is_int( $bai_actor ) && ! $bai_options['bhranti_actor_id'] && get_user_meta( $bai_actor, 'bhranti_managed_actor', true ) ) {
		wp_delete_user( $bai_actor );
	}
	foreach ( $bai_options as $bai_key => $bai_value ) {
		null === $bai_value ? delete_option( $bai_key ) : update_option( $bai_key, $bai_value );
	}
	remove_filter( 'pre_http_request', $bai_http_guard );
	remove_filter( 'pre_wp_mail', $bai_mail_guard );
}
foreach ( $bai_tables as $bai_table => $bai_hash ) {
	if ( $bai_hash !== hash( 'sha256', wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . $wpdb->$bai_table . ' ORDER BY 1,2', ARRAY_A ) ) ) ) {
		$bai_failure = 'Fixture cleanup changed existing native ' . $bai_table . ' records.';
	} else {
		++$bai_checks;
	}
}
if ( $bai_http || $bai_mail ) {
	$bai_failure = 'Audit inspection attempted HTTP or mail.';
}
if ( $bai_failure ) {
	fwrite( STDERR, 'FAIL: ' . $bai_failure . "\n" );
	exit( 1 );
}
echo 'PASS: ' . $bai_checks . " native audit inspection checks; fixtures cleaned, no content actions, network or AI.\n";
