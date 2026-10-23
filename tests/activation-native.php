<?php
/**
 * Clean activation, encrypted database memory and settings guards in disposable Playground.
 *
 * Requires an explicit BHRANTI_ACTIVATION_TEST constant and the Playground VFS.
 * Uses only fixture data; no network, AI, real site or provider connection.
 * See docs/development.md for the command.
 *
 * @package Bhranti
 */

if ( ! defined( 'BHRANTI_ACTIVATION_TEST' ) || true !== BHRANTI_ACTIVATION_TEST || ! is_dir( '/internal' ) || ! is_file( '/wordpress/wp-load.php' ) ) {
	fwrite( STDERR, "Run this test only in a disposable Playground with BHRANTI_ACTIVATION_TEST=true.\n" );
	exit( 1 );
}

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( '/wordpress/' !== ABSPATH || ! WP_DEBUG || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || is_multisite() || is_plugin_active( 'bhranti/bhranti.php' ) ) {
	throw new RuntimeException( 'A clean, offline, debug-enabled single-site Playground is required.' );
}

wp_set_current_user( 1 );
$checks      = 0;
$requests    = array();
$diagnostics = array();
$users       = get_users( array( 'fields' => 'ID' ) );

function bhranti_activation_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$GLOBALS['checks'];
}

add_filter( 'pre_http_request', static function ( $response, $args, $url ) use ( &$requests ) {
	$requests[] = $url;
	return new WP_Error( 'bhranti_activation_offline' );
}, PHP_INT_MAX, 3 );
add_filter( 'wp_die_handler', static function () {
	return static function ( $message ) {
		throw new RuntimeException( wp_strip_all_tags( $message ) );
	};
} );
set_error_handler( static function ( $severity, $message, $file, $line ) use ( &$diagnostics ) {
	if ( error_reporting() & $severity ) {
		$diagnostics[] = compact( 'severity', 'message', 'file', 'line' );
	}
	return false;
} );

try {
	$level = ob_get_level();
	ob_start();
	$result = activate_plugin( 'bhranti/bhranti.php' );
	$output = ob_get_clean();
	bhranti_activation_check( ! is_wp_error( $result ) && is_plugin_active( 'bhranti/bhranti.php' ), 'Native clean activation succeeds.' );
	bhranti_activation_check( '' === $output && empty( $diagnostics ), 'Debug activation emits no output, warnings or notices.' );
	bhranti_activation_check( empty( $requests ), 'Activation makes no HTTP requests.' );
	bhranti_activation_check( get_users( array( 'fields' => 'ID' ) ) === $users, 'Activation does not create a managed user before first use.' );
	$state = get_option( 'bhranti_memory_state', null );
	bhranti_activation_check( 'active' === $state, 'Activation establishes native database memory state.' );
	$memory = Bhranti_Memory::get();
	bhranti_activation_check( true === $memory['available'] && array() === $memory['preferences'], 'Fresh encrypted database memory is available without content-directory storage.' );
	bhranti_activation_check( ! file_exists( WP_CONTENT_DIR . '/bhranti-memory' ) && ! file_exists( WP_CONTENT_DIR . '/.bhranti-memory-site-' . get_current_blog_id() . '.php' ), 'Activation creates no memory payload, gate or directory under wp-content.' );
	deactivate_plugins( 'bhranti/bhranti.php' );
	ob_start();
	$result = activate_plugin( 'bhranti/bhranti.php' );
	$output = ob_get_clean();
	bhranti_activation_check( ! is_wp_error( $result ) && is_plugin_active( 'bhranti/bhranti.php' ) && '' === $output, 'Reactivation succeeds through native database state.' );
	bhranti_activation_check( empty( $diagnostics ) && empty( $requests ), 'All lifecycle checks remain free of diagnostics and network requests.' );

	// Exercise new and existing SQLite guard files through the native settings handler.
	$initial = Bhranti_Settings::get();
	$config  = $initial['config'];
	$config['system_prompt'] = 'Write clearly.';
	$save = new WP_REST_Request( 'POST', '/bhranti/v1/settings' );
	$save->set_header( 'Content-Type', 'application/json' );
	$save->set_body( wp_json_encode( array( 'config' => $config, 'revision' => $initial['revision'] ) ) );
	$first = Bhranti_Settings::save( $save );
	bhranti_activation_check( ! is_wp_error( $first ) && 'Write clearly.' === $first['config']['system_prompt'], 'Native settings save creates a safe SQLite guard even when umask is ignored.' );
	$config['system_prompt'] = 'Keep changes focused.';
	$save->set_body( wp_json_encode( array( 'config' => $config, 'revision' => $first['revision'] ) ) );
	$second = Bhranti_Settings::save( $save );
	bhranti_activation_check( ! is_wp_error( $second ) && 'Keep changes focused.' === $second['config']['system_prompt'] && $first['revision'] !== $second['revision'], 'A second native save reuses the private guard and advances its revision.' );
	$save->set_body( wp_json_encode( array( 'config' => $config, 'revision' => $initial['revision'] ) ) );
	$stale = Bhranti_Settings::save( $save );
	bhranti_activation_check( is_wp_error( $stale ) && 'bhranti_settings_conflict' === $stale->get_error_code() && Bhranti_Settings::get() === $second, 'A stale settings save is rejected without replacing the saved configuration.' );
	bhranti_activation_check( empty( $diagnostics ) && empty( $requests ) && get_users( array( 'fields' => 'ID' ) ) === $users, 'Settings checks emit no diagnostics or HTTP requests and create no managed user.' );
} finally {
	restore_error_handler();
}

echo 'PASS ' . $checks . ' native activation checks; PHP ' . PHP_VERSION . ', WordPress ' . get_bloginfo( 'version' ) . ".\n";
