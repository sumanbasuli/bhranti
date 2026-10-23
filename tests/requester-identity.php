<?php
/**
 * Native requester identity and temporary KSES guard regression.
 *
 * BHRANTI_IDENTITY_WP_ROOT=/private/tmp/disposable-wordpress php tests/requester-identity.php
 * No AI or external HTTP requests; only uniquely marked temporary fixtures.
 *
 * @package Bhranti
 */

$playground = defined( 'BHRANTI_REVIEW_E2E_TEST' ) && true === BHRANTI_REVIEW_E2E_TEST && is_dir( '/internal' );
$root = realpath( getenv( 'BHRANTI_IDENTITY_WP_ROOT' ) ?: ( $playground ? '/wordpress' : '' ) );
if ( ! $root || ( ! $playground && 0 !== strpos( $root, '/private/tmp/' ) ) || ! is_file( $root . '/wp-load.php' ) ) {
	throw new RuntimeException( 'An isolated /private/tmp WordPress fixture is required.' );
}
if ( ! defined( 'WP_USE_THEMES' ) ) { define( 'WP_USE_THEMES', false ); }
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require_once $root . '/wp-load.php';
if ( realpath( ABSPATH ) !== $root || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! class_exists( 'Bhranti_Identity' ) || ! defined( 'BHRANTI_FILE' ) || ( ! $playground && hash_file( 'sha256', dirname( __DIR__ ) . '/includes/class-bhranti-identity.php' ) !== hash_file( 'sha256', dirname( BHRANTI_FILE ) . '/includes/class-bhranti-identity.php' ) ) ) {
	throw new RuntimeException( 'Load the current candidate in an offline disposable fixture first.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

$checks    = 0;
$users     = array();
$posts     = array();
$original  = get_current_user_id();
$baseline  = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$marker    = 'bhranti-identity-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
$http      = array();
$assertion = static function ( $condition, $message ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
};
$http_guard = static function ( $response, $args, $url ) use ( &$http ) {
	$http[] = $url;
	return new WP_Error( 'bhranti_identity_no_http' );
};
add_filter( 'pre_http_request', $http_guard, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
try {
	foreach ( array( 'administrator', 'editor' ) as $index => $role ) {
		$id = wp_insert_user( array( 'user_login' => $marker . '-' . $index, 'user_pass' => wp_generate_password( 32, true, true ), 'user_email' => $marker . '-' . $index . '@example.invalid', 'role' => $role ) );
		$assertion( is_int( $id ) && $id > 0, 'Create uniquely marked native users.' );
		$users[] = $id;
	}
	list( $human, $editor ) = $users;
	wp_set_current_user( $human );
	$before_users = get_users( array( 'fields' => 'ID' ) );
	$before_roles = get_option( $GLOBALS['wpdb']->get_blog_prefix() . 'user_roles' );
	$before_actor = get_option( 'bhranti_actor_id', null );
	$caps        = get_user_meta( $human, $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities', true );
	$assertion( $human === Bhranti_Actions::ensure_actor() && $human === Bhranti_Identity::requester( $human ), 'Tools use the signed-in human without a managed account.' );
	$assertion( $before_users === get_users( array( 'fields' => 'ID' ) ) && $before_roles === get_option( $GLOBALS['wpdb']->get_blog_prefix() . 'user_roles' ) && $before_actor === get_option( 'bhranti_actor_id', null ), 'Requester lookup creates no users, roles or actor option.' );
	$assertion( is_wp_error( Bhranti_Identity::requester( $editor ) ) && $human === get_current_user_id(), 'A supplied different requester never changes identity.' );
	$native_unfiltered = current_user_can( 'unfiltered_html' );
	$assertion( $native_unfiltered, 'The fixture administrator ordinarily has unfiltered HTML.' );
	Bhranti_Identity::scope(
		static function () use ( $assertion, $human, $marker, &$posts ) {
			$assertion( ! current_user_can( 'unfiltered_html' ) && ! current_user_can( 'unfiltered_upload' ), 'Scoped tools cannot bypass native content or MIME filtering.' );
			$assertion( array( 'do_not_allow' ) === map_meta_cap( 'unfiltered_html', $human ), 'Capability denial uses do_not_allow, covering super administrators too.' );
			$assertion( current_user_can( 'edit_posts' ) && current_user_can( 'upload_files' ) && $human === get_current_user_id(), 'Normal task capabilities and actual user identity remain intact.' );
			Bhranti_Identity::scope( static function () use ( $assertion ) {
				$assertion( ! current_user_can( 'unfiltered_html' ), 'Nested tool scopes retain the authoring guard.' );
			} );
			$assertion( ! current_user_can( 'unfiltered_html' ), 'Finishing a nested tool leaves the outer guard active.' );
			$id = wp_insert_post( wp_slash( array( 'post_status' => 'draft', 'post_title' => $marker, 'post_content' => '<!-- wp:paragraph --><p onclick="alert(1)">Allowed text<script>alert(1)</script></p><!-- /wp:paragraph -->' ) ), true );
			$assertion( is_int( $id ) && $id > 0, 'Native authoring saves a disposable draft.' );
			$posts[] = $id;
			$post    = get_post( $id );
			$assertion( (int) $post->post_author === $human && false === strpos( $post->post_content, '<script' ) && false === strpos( $post->post_content, 'onclick' ) && false !== strpos( $post->post_content, '<!-- wp:paragraph -->' ), 'Native KSES removes executable markup while preserving Gutenberg comments and human authorship.' );
		}
	);
	$assertion( $native_unfiltered === current_user_can( 'unfiltered_html' ), 'Normal human HTML permissions return after the tool.' );
	try {
		Bhranti_Identity::scope( static function () { throw new RuntimeException( 'expected-scope-exception' ); } );
	} catch ( RuntimeException $error ) {
		$assertion( 'expected-scope-exception' === $error->getMessage(), 'Expected operation exception was propagated.' );
	}
	$assertion( $native_unfiltered === current_user_can( 'unfiltered_html' ) && false === has_filter( 'map_meta_cap', array( 'Bhranti_Identity', 'authoring_caps' ) ) && $caps === get_user_meta( $human, $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities', true ), 'Finally restores normal filters and leaves saved human capabilities unchanged.' );
	$other = new WP_User( $human );
	$other->add_cap( 'manage_options', false );
	$assertion( is_wp_error( Bhranti_Identity::requester( $human ) ), 'Permission refresh rejects a capability revoked after current-user initialization.' );
	$other->remove_cap( 'manage_options' );
	$assertion( $human === Bhranti_Identity::requester( $human ), 'Restoring the native grant permits the requester again.' );
	wp_set_current_user( $editor );
	$assertion( is_wp_error( Bhranti_Actions::ensure_actor() ) && $editor === get_current_user_id(), 'An editor cannot borrow administrator permissions.' );
	wp_set_current_user( 0 );
	$assertion( is_wp_error( Bhranti_Actions::ensure_actor() ) && 0 === get_current_user_id(), 'Anonymous tools have no fallback account.' );
} finally {
	wp_set_current_user( $original );
	foreach ( $posts as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $users as $id ) {
		wp_delete_user( $id );
	}
}

// Exercise core WP_User's actual multisite-superadmin shortcut in this process
// only, after fixture cleanup. No network data or user role is created/changed.
if ( $baseline && ! defined( 'MULTISITE' ) ) {
	wp_set_current_user( $baseline[0]->ID );
	$GLOBALS['super_admins'] = array( $baseline[0]->user_login );
	define( 'MULTISITE', true );
	$assertion( is_super_admin() && current_user_can( 'unfiltered_html' ), 'Native capability checks recognize the temporary in-process super administrator.' );
	Bhranti_Identity::scope( static function () use ( $assertion ) {
		$assertion( ! current_user_can( 'unfiltered_html' ) && ! current_user_can( 'unfiltered_upload' ), 'Multisite super administrators cannot bypass scoped KSES or upload restrictions.' );
	} );
	$assertion( current_user_can( 'unfiltered_html' ), 'The temporary super administrator regains normal native permissions afterwards.' );
}
$assertion( array() === $http, 'Identity and sanitization checks made no HTTP requests.' );
echo wp_json_encode( array( 'status' => 'passed', 'checks' => $checks, 'http_requests' => count( $http ) ) ) . "\n";
