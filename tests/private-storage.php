<?php
/**
 * Private CPT capability regression in an isolated, offline WordPress fixture.
 *
 * BHRANTI_PRIVATE_STORAGE_WP_ROOT=/private/tmp/bhranti-plugin-approval-native-grrmqzej php tests/private-storage.php
 *
 * @package Bhranti
 */

$root = realpath( getenv( 'BHRANTI_PRIVATE_STORAGE_WP_ROOT' ) ?: '' );
if ( ! $root || 0 !== strpos( $root, '/private/tmp/' ) || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "An isolated /private/tmp WordPress fixture is required.\n" );
	exit( 1 );
}
define( 'WP_USE_THEMES', false );
require $root . '/wp-load.php';
if ( realpath( ABSPATH ) !== $root || ! defined( 'DB_DIR' ) || realpath( DB_DIR ) !== realpath( $root . '/wp-content/database' ) || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! defined( 'BHRANTI_FILE' ) || realpath( BHRANTI_FILE ) !== realpath( $root . '/wp-content/plugins/bhranti/bhranti.php' ) ) {
	fwrite( STDERR, "The offline plugin fixture was not loaded.\n" );
	exit( 1 );
}
foreach ( array( 'class-bhranti-workspace.php', 'class-bhranti-audit.php' ) as $file ) {
	if ( hash_file( 'sha256', dirname( __DIR__ ) . '/includes/' . $file ) !== hash_file( 'sha256', $root . '/wp-content/plugins/bhranti/includes/' . $file ) ) {
		fwrite( STDERR, "Copy the current private storage classes into the disposable fixture first.\n" );
		exit( 1 );
	}
}
require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-includes/class-IXR.php';
require_once ABSPATH . 'wp-includes/class-wp-xmlrpc-server.php';

class Bhranti_Private_XMLRPC_Probe extends wp_xmlrpc_server {
	public $probe_user_id;
	public function login( $username, $password ) {
		wp_set_current_user( $this->probe_user_id );
		return get_userdata( $this->probe_user_id );
	}
}

function bhranti_private_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	$GLOBALS['bhranti_private_checks']++;
}

$GLOBALS['bhranti_private_checks'] = 0;
$created_users = array();
$created_posts = array();
$original_user = get_current_user_id();
$marker = 'bhrantiqa' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
$failure = null;
$http = array();
$http_guard = static function ( $response, $args, $url ) use ( &$http ) {
	$http[] = $url;
	return new WP_Error( 'bhranti_private_no_http' );
};
add_filter( 'pre_http_request', $http_guard, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );

try {
	foreach ( array( 'administrator', 'administrator', 'editor' ) as $role ) {
		$login = $marker . '-' . $role . '-' . count( $created_users );
		$id = wp_insert_user( array(
			'user_login' => $login,
			'user_pass' => wp_generate_password( 32, true, true ),
			'user_email' => $login . '@example.invalid',
			'role' => $role,
		) );
		bhranti_private_check( is_int( $id ) && $id > 0, 'Disposable ' . $role . ' account created.' );
		$created_users[] = $id;
	}
	list( $owner, $other_admin, $editor ) = $created_users;
	foreach ( array( 'bhranti_session', 'bhranti_event' ) as $type ) {
		$post_id = wp_insert_post( array(
			'post_type' => $type,
			'post_status' => 'private',
			'post_author' => $owner,
			'post_title' => $marker,
		), true );
		bhranti_private_check( is_int( $post_id ) && $post_id > 0, 'Disposable ' . $type . ' record created.' );
		$created_posts[] = $post_id;
		$meta_key = 'bhranti_session' === $type ? 'bhranti_messages' : 'bhranti_event_data';
		update_post_meta( $post_id, $meta_key, array( 'content' => $marker ) );
		foreach ( array( $editor, $other_admin, $owner ) as $user_id ) {
			wp_set_current_user( $user_id );
			$probe = new Bhranti_Private_XMLRPC_Probe();
			$probe->probe_user_id = $user_id;
			bhranti_private_check( ! current_user_can( 'edit_post', $post_id ) && ! current_user_can( 'read_post', $post_id ) && ! current_user_can( 'delete_post', $post_id ) && ! current_user_can( 'edit_post_meta', $post_id, $meta_key ), 'Generic post and meta capabilities deny Bhranti storage.' );
			$single = $probe->wp_getPost( array( 1, 'probe', 'unused', $post_id ) );
			bhranti_private_check( $single instanceof IXR_Error && 401 === $single->code, 'XML-RPC single-record read denied.' );
			$list = $probe->wp_getPosts( array( 1, 'probe', 'unused', array( 'post_type' => $type, 'post_status' => 'private' ) ) );
			bhranti_private_check( $list instanceof IXR_Error && 401 === $list->code, 'XML-RPC private-record enumeration denied.' );
			$legacy_blogger = $probe->blogger_getPost( array( 1, $post_id, 'probe', 'unused' ) );
			bhranti_private_check( $legacy_blogger instanceof IXR_Error && 401 === $legacy_blogger->code, 'Legacy Blogger XML-RPC read denied.' );
			$legacy_metaweblog = $probe->mw_getPost( array( $post_id, 'probe', 'unused' ) );
			bhranti_private_check( $legacy_metaweblog instanceof IXR_Error && 401 === $legacy_metaweblog->code, 'Legacy MetaWeblog XML-RPC read denied.' );
			$types = $probe->wp_getPostTypes( array( 1, 'probe', 'unused', array() ) );
			bhranti_private_check( is_array( $types ) && ! isset( $types[ $type ] ), 'XML-RPC post-type catalog hides Bhranti storage.' );
			$edit = $probe->wp_editPost( array( 1, 'probe', 'unused', $post_id, array( 'post_title' => 'forged' ) ) );
			bhranti_private_check( $edit instanceof IXR_Error && $marker === get_post_field( 'post_title', $post_id ), 'XML-RPC record mutation denied.' );
			$delete = $probe->wp_deletePost( array( 1, 'probe', 'unused', $post_id ) );
			bhranti_private_check( $delete instanceof IXR_Error && get_post( $post_id ), 'XML-RPC record deletion denied.' );
		}
	}
	wp_set_current_user( $owner );
	$session = Bhranti_Workspace::owned_session( $created_posts[0] );
	bhranti_private_check( ! is_wp_error( $session ) && (int) $session->post_author === $owner, 'Owning administrator still reaches Bhranti session.' );
	wp_set_current_user( $other_admin );
	bhranti_private_check( is_wp_error( Bhranti_Workspace::owned_session( $created_posts[0] ) ), 'Other administrator cannot reach the session through Bhranti.' );
	bhranti_private_check( array() === $http, 'No external HTTP requests ran.' );
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	wp_set_current_user( $owner ?? $original_user );
	foreach ( $created_posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	foreach ( $created_users as $user_id ) {
		wp_delete_user( $user_id );
	}
	wp_set_current_user( $original_user );
	remove_filter( 'pre_http_request', $http_guard, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
}

if ( $failure ) {
	fwrite( STDERR, $failure->getMessage() . "\n" );
	exit( 1 );
}
foreach ( $created_posts as $post_id ) {
	if ( get_post( $post_id ) ) {
		fwrite( STDERR, "Disposable record cleanup failed.\n" );
		exit( 1 );
	}
}
foreach ( $created_users as $user_id ) {
	if ( get_userdata( $user_id ) ) {
		fwrite( STDERR, "Disposable user cleanup failed.\n" );
		exit( 1 );
	}
}
echo 'PASS: ' . $GLOBALS['bhranti_private_checks'] . " private-storage capability checks; fixtures removed.\n";
