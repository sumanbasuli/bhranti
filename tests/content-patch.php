<?php
/**
 * Real native block patches and reviewed writes in a disposable offline site.
 *
 * BHRANTI_PATCH_TEST_WP_ROOT=/private/tmp/bhranti-content-patch-qa-20261015 php tests/content-patch.php
 * No live site, provider, HTTP, mail, scheduled execution or source writes.
 *
 * @package Bhranti
 */

$cp_root = realpath( getenv( 'BHRANTI_PATCH_TEST_WP_ROOT' ) ?: '' );
if ( '/private/tmp/bhranti-content-patch-qa-20261015' !== $cp_root ) {
	fwrite( STDERR, "Use only the independent private content-patch QA runtime.\n" );
	exit( 1 );
}
define( 'WP_USE_THEMES', false );
require $cp_root . '/wp-load.php';
if ( 'https://bhranti-checker.invalid' !== get_option( 'home' ) || 'https://bhranti-checker.invalid' !== get_option( 'siteurl' ) || get_option( 'active_plugins' ) || ! DISABLE_WP_CRON || realpath( DB_DIR ) !== realpath( $cp_root . '/wp-content/database' ) || '7.1.3' !== $wp_version ) {
	fwrite( STDERR, "The isolated offline native WordPress 7.1.3 runtime is required.\n" );
	exit( 1 );
}
require_once dirname( __DIR__ ) . '/includes/class-bhranti-content-patch.php';
require dirname( __DIR__ ) . '/bhranti.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
Bhranti_Workspace::register_storage();
Bhranti_Audit::register_storage();

$cp_checks = 0;
$cp_http = 0;
$cp_mail = 0;
$cp_owner = 0;
$cp_actor = 0;
$cp_session = 0;
$cp_ids = array();
$cp_term = 0;
$cp_failure = null;
$cp_marker = 'bhranti-content-patch-' . str_replace( '-', '', wp_generate_uuid4() );
$cp_original_user = get_current_user_id();
$cp_tables = array();
foreach ( array( 'posts', 'postmeta', 'users', 'usermeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta' ) as $cp_table ) {
	$cp_tables[ $cp_table ] = hash( 'sha256', wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . $wpdb->$cp_table . ' ORDER BY 1,2', ARRAY_A ) ) );
}
$cp_options = array();
foreach ( $wpdb->get_results( 'SELECT option_name,option_value,autoload FROM ' . $wpdb->options, ARRAY_A ) as $cp_option ) {
	$cp_options[ $cp_option['option_name'] ] = array( $cp_option['option_value'], $cp_option['autoload'] );
}
$cp_http_guard = static function () { ++$GLOBALS['cp_http']; return new WP_Error( 'bhranti_patch_offline', 'Outbound requests are disabled.' ); };
$cp_mail_guard = static function () { ++$GLOBALS['cp_mail']; return false; };
$cp_schedule_guard = static function () { throw new RuntimeException( 'Scheduling is forbidden in content-patch QA.' ); };
$cp_request_guard = static function ( $response, $handler, $request ) {
	if ( 'POST' === $request->get_method() ) { cp_check( ! array_key_exists( 'content_patch', $request->get_params() ), 'Harness patch is never forwarded to the native controller.' ); }
	return $response;
};
add_filter( 'pre_http_request', $cp_http_guard );
add_filter( 'pre_wp_mail', $cp_mail_guard );
add_filter( 'pre_schedule_event', $cp_schedule_guard );
add_filter( 'rest_request_before_callbacks', $cp_request_guard, 10, 3 );

/** Count an assertion without printing source content or native data. */
function cp_check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$GLOBALS['cp_checks'];
}
/** Expect a closed public error. */
function cp_error( $result, $code, $message ) {
	cp_check( is_wp_error( $result ) && in_array( $result->get_error_code(), (array) $code, true ), $message . ( is_wp_error( $result ) ? ' [' . $result->get_error_code() . ']' : ' [unexpected success]' ) );
}
/** Exact harness patch, without extra native fields. */
function cp_command( $path, $content, $fragment, $operation = 'append', $block_path = null ) {
	$patch = array( 'revision' => hash( 'sha256', $content ), 'operation' => $operation, 'content' => $fragment );
	if ( null !== $block_path ) { $patch['block_path'] = $block_path; }
	return array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => $path, 'content_patch' => $patch ) );
}
/** Dispatch through the actual native permissions, policy, audit and snapshot pipeline. */
function cp_execute( $command ) {
	return Bhranti_Actions::execute( $command, $GLOBALS['cp_owner'], $GLOBALS['cp_session'] );
}
/** Create one uniquely owned native fixture for isolated setup only. */
function cp_fixture( $content, $type = 'page' ) {
	$id = wp_insert_post( wp_slash( array( 'post_type' => $type, 'post_title' => $GLOBALS['cp_marker'], 'post_status' => 'draft', 'post_author' => $GLOBALS['cp_owner'], 'post_content' => $content ) ), true );
	cp_check( is_int( $id ) && $id > 0, 'Unique native fixture exists.' );
	$GLOBALS['cp_ids'][] = $id;
	return $id;
}

try {
	$cp_owner = wp_insert_user( array( 'user_login' => $cp_marker, 'user_email' => $cp_marker . '@example.invalid', 'user_pass' => wp_generate_password( 40 ), 'role' => 'administrator' ) );
	cp_check( is_int( $cp_owner ) && $cp_owner > 1, 'Independent administrator exists.' );
	wp_set_current_user( $cp_owner );
	$cp_session = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $cp_owner, 'post_title' => $cp_marker ), true );
	cp_check( is_int( $cp_session ), 'Private owned fixture conversation exists.' );
	$cp_actor = Bhranti_Actions::ensure_actor();
	cp_check( is_int( $cp_actor ), 'Real managed native actor exists.' );
	$paragraph = '<!-- wp:paragraph --><p>Existing section.</p><!-- /wp:paragraph -->';
	$new = '<!-- wp:paragraph --><p>A complete next section.</p><!-- /wp:paragraph -->';
	$heading = '<!-- wp:heading --><h2 class="wp-block-heading">Section title</h2><!-- /wp:heading -->';
	$group = '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n<div class=\"wp-block-group\">\n" . $paragraph . "\n" . $heading . "\n</div>\n" . '<!-- /wp:group -->';
	$body = " \n" . $group . "\n\n" . $paragraph . "\n";
	$id = cp_fixture( $body );
	$path = '/wp/v2/pages/' . $id;
	$inspection = Bhranti_Inspect::execute( array( 'tool' => 'bhranti_inspect', 'args' => array( 'resource' => 'content', 'path' => $path, 'block_path' => '1/0' ) ), $cp_owner, $cp_session );
	cp_check( is_array( $inspection ) && $inspection['data']['revision'] === hash( 'sha256', $body ) && $inspection['data']['selected']['revision'] !== $inspection['data']['revision'], 'Inspector supplies exact full revision separately from selected-block revision.' );
	$command = cp_command( $path, $body, $new );
	$expected = $body . "\n\n" . $new;
	cp_check( Bhranti_Content_Patch::apply( $body, $command['args']['content_patch'] ) === $expected, 'Root append preserves every existing source byte including whitespace.' );
	$nested = cp_command( $path, $body, $new, 'append', '1' );
	$nested_expected = str_replace( "\n</div>\n<!-- /wp:group -->", "\n\n" . $new . "\n</div>\n<!-- /wp:group -->", $body );
	cp_check( Bhranti_Content_Patch::apply( $body, $nested['args']['content_patch'] ) === $nested_expected, 'Group append inserts inside its wrapper and preserves source/null-slot order.' );
	$replace = cp_command( $path, $body, $new, 'replace', '1/0' );
	$replacement_expected = substr_replace( $body, $new, strpos( $body, $paragraph ), strlen( $paragraph ) );
	cp_check( Bhranti_Content_Patch::apply( $body, $replace['args']['content_patch'] ) === $replacement_expected, 'Nested replacement changes only the exact selected source span.' );
	$empty_group = '<!-- wp:group --><section class="wp-block-group"></section><!-- /wp:group -->';
	$empty_patch = cp_command( $path, $empty_group, $new, 'append', '0' );
	cp_check( '<!-- wp:group --><section class="wp-block-group">' . "\n" . $new . "\n" . '</section><!-- /wp:group -->' === Bhranti_Content_Patch::apply( $empty_group, $empty_patch['args']['content_patch'] ), 'Empty semantic Group supports one safe native child insertion.' );
	$columns = '<!-- wp:columns --><div class="wp-block-columns"></div><!-- /wp:columns -->';
	cp_error( Bhranti_Content_Patch::apply( $columns, cp_command( $path, $columns, $new, 'append', '0' )['args']['content_patch'] ), 'bhranti_content_patch_invalid', 'Nested append refuses non-Group structures.' );
	foreach ( array( '', ' ', '<p>Freeform.</p>', '<!-- wp:paragraph --><p>Open only.</p>', '<!-- wp:paragraph --><p>Wrong.</p><!-- /wp:heading -->', '<!-- /wp:paragraph -->', '<!-- wp:paragraph {bad} --><p>Bad JSON.</p><!-- /wp:paragraph -->', '<!-- wp:paragraph --><p>Bad closer.</p><!-- /wp:paragraph {} -->', '<!-- /wp:paragraph /-->', '<!-- wp:unknown-plugin/unregistered /-->', str_repeat( '🐈', 2100 ) ) as $invalid ) {
		$bad = cp_command( $path, $body, $invalid );
		cp_error( cp_execute( $bad ), array( 'bhranti_content_patch_invalid', 'bhranti_action_invalid' ), 'Incomplete, unsupported or byte-oversized fragment fails closed.' );
		cp_check( $body === get_post( $id )->post_content && ! get_post_meta( $cp_session, 'bhranti_actions', true ), 'Rejected fragment writes neither content nor rollback snapshot.' );
	}
	foreach ( array( '<!-- wp:html --><div>Reviewed HTML.</div><!-- /wp:html -->', '<!-- wp:shortcode -->[bhranti_fixture_noop]<!-- /wp:shortcode -->' ) as $registered ) {
		$allowed = cp_command( $path, $body, $registered );
		cp_check( true === Bhranti_Content_Patch::validate( $allowed['args'], 'WP_REST_Posts_Controller', 'page', 'update_item' ), 'Registered native blocks retain the existing full-write content policy.' );
	}
	$bounded = '<!-- wp:paragraph --><p>' . str_repeat( 'x', 8192 - strlen( '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->' ) ) . '</p><!-- /wp:paragraph -->';
	cp_check( 8192 === strlen( $bounded ) && true === Bhranti_Content_Patch::validate( cp_command( $path, $body, $bounded )['args'], 'WP_REST_Posts_Controller', 'page', 'update_item' ), 'Exactly 8192 UTF-8 fragment bytes are accepted.' );
	cp_error( Bhranti_Content_Patch::validate( cp_command( $path, $body, $bounded . ' ' )['args'], 'WP_REST_Posts_Controller', 'page', 'update_item' ), 'bhranti_content_patch_invalid', 'One byte beyond the fragment bound is rejected.' );
	foreach ( array( array( 'operation' => 'delete' ), array( 'extra' => true ), array( 'revision' => str_repeat( '0', 63 ) ), array( 'block_path' => '00' ), array( 'block_path' => '1/99' ), array( 'block_path' => '0' ), array( 'operation' => 'replace' ) ) as $change ) {
		$bad = $command;
		$bad['args']['content_patch'] = array_merge( $bad['args']['content_patch'], $change );
		cp_error( cp_execute( $bad ), array( 'bhranti_content_patch_invalid', 'bhranti_action_invalid' ), 'Unknown keys, deletion, absent/invalid selection or revision shape cannot write.' );
	}
	$bad = $command; $bad['args']['content_patch']['revision'] = $inspection['data']['selected']['revision'];
	cp_error( cp_execute( $bad ), 'bhranti_content_patch_conflict', 'Selected-block hash cannot substitute for full-document revision.' );
	foreach ( array( 'GET', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
		$bad = $command; $bad['args']['method'] = $method;
		cp_error( cp_execute( $bad ), 'bhranti_content_patch_invalid', 'A patch is only a singular POST update.' );
	}
	$bad = $command; $bad['args']['params'] = array( 'title' => 'Unrelated change' );
	cp_error( cp_execute( $bad ), 'bhranti_content_patch_invalid', 'Native fields cannot be combined with a patch.' );
	cp_error( cp_execute( cp_command( $path, $body, $new . $new, 'replace', '1/0' ) ), 'bhranti_content_patch_invalid', 'Replace cannot turn one selected block into multiple siblings.' );
	foreach ( array( '/wp/v2/pages', '/wp/v2/navigation/1', '/wp/v2/settings', '/wp/v2/global-styles/1' ) as $other_path ) {
		$bad = $command; $bad['args']['path'] = $other_path;
		cp_error( cp_execute( $bad ), array( 'bhranti_content_patch_invalid', 'bhranti_action_blocked' ), 'Patch does not expand the reviewed route surface.' );
	}
	$bad = cp_command( $path, $body, '<!-- wp:paragraph --><p><script>alert(1)</script></p><!-- /wp:paragraph -->' );
	cp_error( cp_execute( $bad ), 'bhranti_action_blocked', 'Expanded full content is re-reviewed for executable source before saving.' );
	$bad = cp_command( $path, $body, '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.invalid/image.jpg"></figure><!-- /wp:image -->' );
	cp_error( cp_execute( $bad ), 'bhranti_content_quality', 'Expanded content still passes mandatory authored-quality validation.' );
	$config = Bhranti_Settings::all();
	$config['permissions']['edit_content'] = false;
	update_option( 'bhranti_settings', array( 'config' => $config, 'revision' => wp_generate_uuid4() ) );
	cp_error( cp_execute( $command ), 'bhranti_action_disabled', 'Live edit-content guardrail is checked before expansion.' );
	delete_option( 'bhranti_settings' );
	$deny_actor = static function ( $allcaps, $caps, $args, $user ) { if ( (int) $user->ID === $GLOBALS['cp_actor'] ) { $allcaps['edit_pages'] = false; $allcaps['edit_others_pages'] = false; } return $allcaps; };
	add_filter( 'user_has_cap', $deny_actor, 10, 4 );
	cp_error( cp_execute( $command ), 'bhranti_action_permission', 'Deferred empty body cannot bypass the native actor permission callback.' );
	remove_filter( 'user_has_cap', $deny_actor, 10 );
	cp_check( $body === get_post( $id )->post_content && ! get_post_meta( $cp_session, 'bhranti_actions', true ), 'Every blocked action leaves original content and snapshot storage intact.' );
	$race_id = cp_fixture( $paragraph );
	$race_changed = false;
	$race_guard = static function ( $response, $post, $request ) use ( $race_id, $paragraph, &$race_changed ) {
		if ( ! $race_changed && 'GET' === $request->get_method() && (int) $post->ID === $race_id ) {
			$race_changed = true;
			wp_update_post( wp_slash( array( 'ID' => $race_id, 'post_content' => $paragraph . "\nManually changed after the before-read." ) ) );
		}
		return $response;
	};
	add_filter( 'rest_prepare_page', $race_guard, 10, 3 );
	cp_error( cp_execute( cp_command( '/wp/v2/pages/' . $race_id, $paragraph, $new ) ), 'bhranti_content_patch_conflict', 'A real concurrent native edit after the before-read cannot become an accepted first-fingerprint baseline.' );
	remove_filter( 'rest_prepare_page', $race_guard, 10 );
	cp_check( $race_changed && $paragraph . "\nManually changed after the before-read." === get_post( $race_id )->post_content && ! get_post_meta( $cp_session, 'bhranti_actions', true ), 'Concurrent edit is preserved and no patch snapshot is created.' );
	$result = cp_execute( $command );
	cp_check( is_array( $result ) && ! empty( $result['action_id'] ) && true === $result['rollback_available'], 'Real native patch write completes with durable rollback.' );
	cp_check( false === $result['created'], 'A native item patch is recorded as an update, not creation intent.' );
	cp_check( $expected === get_post( $id )->post_content && hash( 'sha256', $expected ) === $result['content_revision'] && $result['data']['content_revision'] === $result['content_revision'], 'Native response revision hashes the actual saved expanded content.' );
	cp_check( strlen( wp_json_encode( $result ) ) < 1500 && ! isset( $result['data']['content'] ) && false === strpos( wp_json_encode( $result ), 'A complete next section' ), 'Successful patch result is compact and does not echo the expanded page.' );
	$snapshots = get_post_meta( $cp_session, 'bhranti_actions', true );
	$snapshot = $snapshots[ $result['action_id'] ];
	cp_check( ! isset( $snapshot['command']['args']['content_patch'] ) && array( 'content' ) === array_keys( $snapshot['command']['args']['params'] ) && $expected === $snapshot['command']['args']['params']['content'] && $body === $snapshot['restore']['content'], 'Native snapshot contains expanded actual content and exact old raw value, never a patch.' );
	cp_error( cp_execute( $command ), 'bhranti_content_patch_conflict', 'Replaying a completed append fails its old revision without duplicating content.' );
	cp_check( $expected === get_post( $id )->post_content && 1 === count( get_post_meta( $cp_session, 'bhranti_actions', true ) ), 'Conflict creates no new snapshot or write.' );
	$rollback = Bhranti_Actions::rollback( $result['action_id'], $cp_owner, $cp_session );
	cp_check( is_array( $rollback ) && $body === get_post( $id )->post_content, 'Existing native rollback restores byte-identical pre-patch content.' );
	$result = cp_execute( $nested );
	cp_check( is_array( $result ) && $nested_expected === get_post( $id )->post_content, 'Actual native Group append saves the same safe child structure.' );
	$edited = get_post( $id )->post_content;
	wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $edited . "\n" ) ) );
	cp_error( Bhranti_Actions::rollback( $result['action_id'], $cp_owner, $cp_session ), 'bhranti_rollback_conflict', 'Native rollback refuses a subsequent manual edit.' );
	$post_id = cp_fixture( $paragraph, 'post' );
	$result = cp_execute( cp_command( '/wp/v2/posts/' . $post_id, $paragraph, $new, 'replace', '0' ) );
	cp_check( is_array( $result ) && $new === get_post( $post_id )->post_content, 'Actual native posts support complete block replacement.' );
	$template_id = cp_fixture( $paragraph, 'wp_template' );
	wp_update_post( array( 'ID' => $template_id, 'post_status' => 'publish', 'post_name' => $cp_marker ) );
	$theme = get_stylesheet();
	$term_before = term_exists( $theme, 'wp_theme' );
	wp_set_object_terms( $template_id, $theme, 'wp_theme' );
	if ( ! $term_before ) { $term = get_term_by( 'slug', $theme, 'wp_theme' ); $cp_term = $term ? (int) $term->term_id : 0; }
	$result = cp_execute( cp_command( '/wp/v2/templates/' . $theme . '//' . $cp_marker, $paragraph, $new ) );
	cp_check( is_array( $result ) && $paragraph . "\n\n" . $new === get_post( $template_id )->post_content && isset( $result['content_revision'] ), 'Actual native FSE string-ID template update uses the same protected patch pipeline.' );
	$events = Bhranti_Audit::events( $cp_owner, 1, $cp_session );
	cp_check( count( $events ) > 0 && false === strpos( wp_json_encode( $events ), 'A complete next section' ), 'Native audit traces action identity without fragment or saved page bodies.' );
	cp_check( 0 === $cp_http && 0 === $cp_mail && $cp_owner === get_current_user_id(), 'Real native tests keep identity restored with no external requests or mail.' );
} catch ( Throwable $error ) {
	$cp_failure = $error->getMessage();
} finally {
	if ( isset( $deny_actor ) ) { remove_filter( 'user_has_cap', $deny_actor, 10 ); }
	if ( isset( $race_guard ) ) { remove_filter( 'rest_prepare_page', $race_guard, 10 ); }
	wp_set_current_user( $cp_original_user );
	foreach ( $cp_ids as $cp_id ) {
		$post = get_post( $cp_id );
		if ( $post && $cp_marker === $post->post_title && in_array( (int) $post->post_author, array( $cp_owner, $cp_actor ), true ) ) { wp_delete_post( $cp_id, true ); }
	}
	if ( $cp_term && ! get_objects_in_term( $cp_term, 'wp_theme' ) ) { wp_delete_term( $cp_term, 'wp_theme' ); }
	foreach ( get_posts( array( 'post_type' => 'bhranti_event', 'post_status' => 'private', 'author' => $cp_owner, 'numberposts' => -1 ) ) as $event ) {
		if ( (int) $event->post_parent === $cp_session ) { wp_delete_post( $event->ID, true ); }
	}
	if ( $cp_session && get_post( $cp_session ) && $cp_marker === get_post( $cp_session )->post_title ) { wp_delete_post( $cp_session, true ); }
	if ( $cp_owner && get_userdata( $cp_owner ) && $cp_marker === get_userdata( $cp_owner )->user_login ) { wp_delete_user( $cp_owner ); }
	if ( is_int( $cp_actor ) && ! isset( $cp_options['bhranti_actor_id'] ) && get_user_meta( $cp_actor, 'bhranti_managed_actor', true ) ) { wp_delete_user( $cp_actor ); }
	foreach ( $wpdb->get_col( 'SELECT option_name FROM ' . $wpdb->options ) as $name ) {
		if ( in_array( $name, array( 'bhranti_actor_id', 'bhranti_role_owned', 'bhranti_actor_setup_lock', 'bhranti_settings', 'wp_user_roles', 'nonce_key', 'nonce_salt' ), true ) || preg_match( '/^_site_transient_(?:timeout_)?wp_theme_files_patterns-[a-f0-9]+$/', $name ) ) {
			isset( $cp_options[ $name ] ) ? update_option( $name, maybe_unserialize( $cp_options[ $name ][0] ) ) : delete_option( $name );
		}
	}
	foreach ( array( 'wp_user_roles', 'bhranti_settings' ) as $name ) {
		if ( isset( $cp_options[ $name ] ) ) { update_option( $name, maybe_unserialize( $cp_options[ $name ][0] ) ); }
	}
	remove_filter( 'pre_http_request', $cp_http_guard );
	remove_filter( 'pre_wp_mail', $cp_mail_guard );
	remove_filter( 'pre_schedule_event', $cp_schedule_guard );
	remove_filter( 'rest_request_before_callbacks', $cp_request_guard, 10 );
}
foreach ( $cp_tables as $cp_table => $hash ) {
	if ( $hash !== hash( 'sha256', wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . $wpdb->$cp_table . ' ORDER BY 1,2', ARRAY_A ) ) ) ) { $cp_failure = $cp_failure ?: 'Fixture cleanup changed existing ' . $cp_table . ' records.'; }
	else { ++$cp_checks; }
}
$cp_options_after = array();
foreach ( $wpdb->get_results( 'SELECT option_name,option_value,autoload FROM ' . $wpdb->options, ARRAY_A ) as $cp_option ) {
	$cp_options_after[ $cp_option['option_name'] ] = array( $cp_option['option_value'], $cp_option['autoload'] );
}
ksort( $cp_options ); ksort( $cp_options_after );
if ( $cp_options !== $cp_options_after ) { $cp_failure = $cp_failure ?: 'Fixture cleanup changed original native options.'; }
else { ++$cp_checks; }
if ( $cp_http || $cp_mail ) { $cp_failure = 'Unexpected external request or mail in native QA.'; }
if ( $cp_failure ) { fwrite( STDERR, 'FAIL: ' . $cp_failure . "\n" ); exit( 1 ); }
echo 'PASS: ' . $cp_checks . " actual native content-patch checks; fixtures cleaned, no network, AI, mail or Cron.\n";
