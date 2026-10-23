<?php
/** Real native network lifecycle, identity and encrypted-memory isolation. @package Bhranti */
if ( ! defined( 'BHRANTI_REVIEW_E2E_TEST' ) || true !== BHRANTI_REVIEW_E2E_TEST || ! is_dir( '/internal' ) ) {
	throw new RuntimeException( 'Use the isolated native review harness.' );
}
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( '/wordpress/' !== ABSPATH || ! is_multisite() || ! WP_DEBUG || ! DISABLE_WP_CRON || ! WP_HTTP_BLOCK_EXTERNAL || is_plugin_active_for_network( 'bhranti/bhranti.php' ) ) {
	throw new RuntimeException( 'A fresh offline multisite fixture is required.' );
}
$checks = 0;
function brm_check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$GLOBALS['checks'];
}
wp_set_current_user( 1 );
$original_site = get_current_blog_id();
$requests = array();
$http_guard = static function ( $result, $args, $url ) use ( &$requests ) { $requests[] = $url; return new WP_Error( 'bhranti_network_offline' ); };
add_filter( 'pre_http_request', $http_guard, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
$network = get_network();
$second = wpmu_create_blog( $network->domain, '/second/', 'Second private review site', 1, array( 'public' => 0 ), $network->id );
brm_check( is_int( $second ) && $second > 1, 'Core creates a second real native network site.' );
$sites = array( $original_site, $second );
$result = activate_plugin( 'bhranti/bhranti.php', '', true );
brm_check( ! is_wp_error( $result ) && is_plugin_active_for_network( 'bhranti/bhranti.php' ), 'Native network-wide activation succeeds.' );
brm_check( $original_site === get_current_blog_id(), 'Network activation restores its original site.' );
brm_check( is_super_admin() && current_user_can( 'unfiltered_html' ), 'The native network administrator has the usual super-admin HTML capability.' );
Bhranti_Identity::scope( static function () {
	brm_check( ! current_user_can( 'unfiltered_html' ) && ! current_user_can( 'unfiltered_upload' ) && array( 'do_not_allow' ) === map_meta_cap( 'unfiltered_html', 1 ), 'A real multisite super administrator cannot bypass the scoped native authoring guard.' );
} );
brm_check( current_user_can( 'unfiltered_html' ) && false === has_filter( 'map_meta_cap', array( 'Bhranti_Identity', 'authoring_caps' ) ), 'Native super-admin permissions return after the reviewed operation.' );
$records = array();
foreach ( $sites as $index => $site_id ) {
	switch_to_blog( $site_id );
	try {
		brm_check( 'active' === get_option( 'bhranti_memory_state' ) && Bhranti_Memory::get()['available'], 'Each actual native site has an independent active memory lifecycle.' );
		$text = 0 === $index ? 'I prefer warm colors' : 'I prefer cool colors';
		$evidence = 'Please remember: ' . $text . '.';
		$session = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => 1, 'post_title' => 'Native network preference fixture' ), true );
		brm_check( is_int( $session ) && $session > 0, 'Native private conversation belongs to the actual network administrator.' );
		update_post_meta( $session, 'bhranti_messages', array( array( 'role' => 'user', 'content' => $evidence ) ) );
		$before = Bhranti_Memory::get();
		$saved = Bhranti_Memory::execute( array( 'tool' => 'bhranti_memory', 'args' => array( 'method' => 'POST', 'category' => 'design', 'text' => $text, 'evidence' => $evidence, 'revision' => $before['revision'] ) ), 1, $session );
		brm_check( ! is_wp_error( $saved ) && $text === Bhranti_Memory::get()['preferences'][0]['text'], 'Native site-scoped encrypted memory saves the exact explicit preference.' );
		$raw = get_option( 'bhranti_memory_user_1' );
		brm_check( is_string( $raw ) && 0 === strpos( $raw, Bhranti_Memory::FORMAT ) && false === strpos( $raw, $text ), 'Each site stores authenticated ciphertext rather than preference plaintext.' );
		add_option( 'bhranti_memory_user_keep', 'invalid numeric owner key', '', false );
		add_option( 'unrelated_bhranti_memory_user_keep', 'unrelated similarly named option', '', false );
		$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Preserved network fixture', 'post_content' => '<!-- wp:paragraph --><p>Preserved native content.</p><!-- /wp:paragraph -->', 'post_author' => 1 ), true );
		$records[ $site_id ] = array( 'raw' => $raw, 'text' => $text, 'page' => $page, 'content' => get_post_field( 'post_content', $page ) );
	} finally {
		restore_current_blog();
	}
}
brm_check( $records[ $original_site ]['raw'] !== $records[ $second ]['raw'] && $original_site === get_current_blog_id(), 'The same native user has distinct per-site ciphertext and the caller site is restored.' );
switch_to_blog( $second );
try {
	update_option( 'bhranti_memory_user_1', $records[ $original_site ]['raw'], false );
	$swapped = Bhranti_Memory::get();
	brm_check( empty( $swapped['preferences'] ) && ! empty( $swapped['message'] ), 'Ciphertext from a different native blog fails authenticated decoding.' );
	update_option( 'bhranti_memory_user_1', $records[ $second ]['raw'], false );
	$erased = Bhranti_Memory::erase_user( 1 );
	brm_check( ! is_wp_error( $erased ) && array() === Bhranti_Memory::export_user( 1 )['preferences'], 'Native privacy erasure removes only this selected site memory.' );
} finally { restore_current_blog(); }
brm_check( $records[ $original_site ]['text'] === Bhranti_Memory::get()['preferences'][0]['text'], 'Erasing another blog leaves the original site preference intact.' );
switch_to_blog( $second );
try {
	brm_check( true === Bhranti_Memory::uninstall_site() && 'invalid numeric owner key' === get_option( 'bhranti_memory_user_keep' ), 'Narrow memory cleanup never treats a nonnumeric option suffix as an owned memory record.' );
} finally { restore_current_blog(); }
brm_check( $records[ $original_site ]['text'] === Bhranti_Memory::get()['preferences'][0]['text'], 'Site-only uninstall leaves another native site memory untouched.' );
$former = wpmu_create_user( 'bhranti-former-site-member', wp_generate_password( 32, true, true ), 'former-site-member@example.invalid' );
brm_check( is_int( $former ) && $former > 1 && ! is_wp_error( add_user_to_blog( $second, $former, 'subscriber' ) ), 'An actual network user first joins the disposable second site.' );
switch_to_blog( $second );
try {
	update_user_option( $former, 'bhranti_activity', array( 'fixture' => 'private activity' ), false );
	update_user_option( $former, 'bhranti_ai_enabled', true, false );
	update_user_meta( $former, 'bhranti_editor_state_' . $second, array( 'fixture' => 'private editor state' ) );
	$former_keys = array( $GLOBALS['wpdb']->get_blog_prefix() . 'bhranti_activity', $GLOBALS['wpdb']->get_blog_prefix() . 'bhranti_ai_enabled', 'bhranti_editor_state_' . $second );
	update_user_meta( $former, 'review_unrelated_preference', 'preserve this unrelated preference' );
} finally { restore_current_blog(); }
remove_user_from_blog( $former, $second );
brm_check( ! is_user_member_of_blog( $former, $second ) && metadata_exists( 'user', $former, $former_keys[0] ), 'The former member is no longer enumerable as a site member but still has owned private activity.' );
deactivate_plugins( 'bhranti/bhranti.php', true, true );
uninstall_plugin( 'bhranti/bhranti.php' );
brm_check( $original_site === get_current_blog_id(), 'Native network uninstall restores its original blog.' );
foreach ( $sites as $site_id ) {
	switch_to_blog( $site_id );
	try {
		brm_check( null === get_option( 'bhranti_memory_user_1', null ) && 'uninstalled' === get_option( 'bhranti_memory_state' ), 'Network uninstall removes every site owner payload and blocks late writers.' );
		brm_check( 'unrelated similarly named option' === get_option( 'unrelated_bhranti_memory_user_keep' ), 'Uninstall preserves non-owned similarly named native options.' );
		$page = get_post( $records[ $site_id ]['page'] );
		brm_check( $page && 1 === (int) $page->post_author && $records[ $site_id ]['content'] === $page->post_content, 'Network uninstall preserves normal authored content and attribution.' );
		brm_check( empty( Bhranti_Memory::get()['available'] ), 'Already-loaded memory cannot recreate an uninstalled site payload.' );
	} finally { restore_current_blog(); }
}
foreach ( $former_keys as $key ) {
	brm_check( ! metadata_exists( 'user', $former, $key ), 'Network uninstall erases exact Bhranti metadata for former site members.' );
}
brm_check( get_userdata( $former ) && 'preserve this unrelated preference' === get_user_meta( $former, 'review_unrelated_preference', true ), 'Network cleanup preserves the native former-member account and unrelated metadata.' );
$result = activate_plugin( 'bhranti/bhranti.php', '', true );
brm_check( ! is_wp_error( $result ) && $original_site === get_current_blog_id() && Bhranti_Memory::get()['available'] && array() === Bhranti_Memory::get()['preferences'], 'Explicit native network reactivation restores empty available memory without old data.' );
brm_check( array() === $requests, 'All real multisite lifecycle and memory checks made no external HTTP requests.' );
echo wp_json_encode( array( 'status' => 'complete', 'checks' => $checks, 'sites' => count( $sites ), 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'external_http' => count( $requests ) ) );
