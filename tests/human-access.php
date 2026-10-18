<?php
/**
 * Human-only workspace regression in an isolated, offline WordPress fixture.
 *
 * BHRANTI_HUMAN_ACCESS_WP_ROOT=/private/tmp/bhranti-production-security-qa-20261018 php tests/human-access.php
 *
 * @package Bhranti
 */

$root = realpath( getenv( 'BHRANTI_HUMAN_ACCESS_WP_ROOT' ) ?: '' );
if ( ! $root || 0 !== strpos( $root, '/private/tmp/' ) || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "An isolated /private/tmp WordPress fixture is required.\n" );
	exit( 1 );
}
define( 'WP_USE_THEMES', false );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
require $root . '/wp-load.php';
if ( realpath( ABSPATH ) !== $root || ! defined( 'DB_DIR' ) || realpath( DB_DIR ) !== realpath( $root . '/wp-content/database' ) || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! defined( 'BHRANTI_FILE' ) || realpath( BHRANTI_FILE ) !== realpath( $root . '/wp-content/plugins/bhranti/bhranti.php' ) ) {
	fwrite( STDERR, "The offline plugin fixture was not loaded.\n" );
	exit( 1 );
}
foreach ( array( 'class-bhranti-workspace.php', 'class-bhranti-panel.php', 'class-bhranti-log-screen.php' ) as $file ) {
	if ( hash_file( 'sha256', dirname( __DIR__ ) . '/includes/' . $file ) !== hash_file( 'sha256', $root . '/wp-content/plugins/bhranti/includes/' . $file ) ) {
		fwrite( STDERR, "Copy the current human access classes into the disposable fixture first.\n" );
		exit( 1 );
	}
}
require_once ABSPATH . 'wp-admin/includes/user.php';

$checks        = 0;
$requests      = array();
$original_user = get_current_user_id();
$human_id      = 0;
$failure       = null;
$marker        = 'bhranti-human-qa-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 14 );
$http_guard    = static function ( $response, $args, $url ) use ( &$requests ) {
	$requests[] = $url;
	return new WP_Error( 'bhranti_human_qa_no_http' );
};
$check = static function ( $condition, $message ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
};
$rest = static function ( $method, $path ) {
	return rest_do_request( new WP_REST_Request( $method, '/bhranti/v1' . $path ) );
};
add_filter( 'pre_http_request', $http_guard, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );

try {
	$actor_id = (int) get_option( 'bhranti_actor_id' );
	$actor    = get_userdata( $actor_id );
	$check( $actor && in_array( 'bhranti_agent', $actor->roles, true ) && (int) get_user_meta( $actor_id, 'bhranti_managed_actor', true ) === get_current_blog_id(), 'The disposable fixture has a real managed actor.' );
	$human_id = wp_insert_user(
		array(
			'user_login' => $marker,
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => $marker . '@example.invalid',
			'role'       => 'administrator',
		)
	);
	$check( is_int( $human_id ) && $human_id > 0, 'Disposable human administrator was created.' );

	wp_set_current_user( $actor_id );
	$check( current_user_can( 'manage_options' ) && ! Bhranti_Workspace::can_access(), 'Managed actor cannot enter the human workspace despite its site capability.' );
	$check( 403 === $rest( 'GET', '/workspace' )->get_status(), 'Managed actor cannot read the workspace route.' );
	$before = get_posts( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'author' => $actor_id, 'posts_per_page' => -1, 'fields' => 'ids' ) );
	$check( 403 === $rest( 'POST', '/sessions' )->get_status(), 'Managed actor cannot create a conversation.' );
	$check( $before === get_posts( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'author' => $actor_id, 'posts_per_page' => -1, 'fields' => 'ids' ) ), 'Denied actor request saved no conversation.' );
	ob_start();
	Bhranti_Workspace::render();
	$check( '' === ob_get_clean(), 'Managed actor receives no settings screen.' );
	ob_start();
	Bhranti_Log_Screen::render();
	$check( '' === ob_get_clean(), 'Managed actor receives no site-wide log screen.' );
	$bar = new class {
		public $nodes = array();
		public function add_node( $node ) {
			$this->nodes[] = $node;
		}
	};
	Bhranti_Panel::admin_bar( $bar );
	$check( array() === $bar->nodes, 'Managed actor receives no Bhranti panel entry.' );
	$deny = static function () {
		return static function () {
			throw new RuntimeException( 'expected-denial' );
		};
	};
	add_filter( 'wp_die_handler', $deny );
	try {
		Bhranti_Log_Screen::download();
		throw new RuntimeException( 'Managed actor log download was not denied.' );
	} catch ( RuntimeException $error ) {
		$check( 'expected-denial' === $error->getMessage(), 'Managed actor cannot download site-wide logs.' );
	} finally {
		remove_filter( 'wp_die_handler', $deny );
	}

	wp_set_current_user( $human_id );
	$check( Bhranti_Workspace::can_access(), 'Human administrator retains workspace access.' );
	$check( 200 === $rest( 'GET', '/workspace' )->get_status(), 'Human administrator can read the workspace route.' );
	$bar->nodes = array();
	Bhranti_Panel::admin_bar( $bar );
	$check( 1 === count( $bar->nodes ) && 'bhranti' === $bar->nodes[0]['id'], 'Human administrator keeps the panel entry.' );
	ob_start();
	Bhranti_Log_Screen::render();
	$check( false !== strpos( ob_get_clean(), 'Bhranti Logs' ), 'Human administrator keeps the log screen.' );
	$check( array() === $requests, 'No external HTTP or AI request ran.' );
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	wp_set_current_user( $original_user );
	if ( $human_id && get_userdata( $human_id ) ) {
		wp_delete_user( $human_id );
	}
	remove_filter( 'pre_http_request', $http_guard, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
}

if ( $failure || ( $human_id && get_userdata( $human_id ) ) ) {
	fwrite( STDERR, ( $failure ? $failure->getMessage() : 'Disposable administrator cleanup failed.' ) . "\n" );
	exit( 1 );
}
echo 'PASS: ' . $checks . " human-only workspace checks; fixture removed.\n";
