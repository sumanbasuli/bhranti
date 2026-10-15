<?php
/**
 * Real WordPress inspection in an independent, private, disposable native runtime.
 *
 * BHRANTI_INSPECT_TEST_WP_ROOT=/private/tmp/bhranti-inspect-qa-20261014 php tests/inspect.php
 * No live site, content actions, HTTP, AI, dynamic rendering or transactions.
 * Only uniquely owned fixture setup/cleanup and normal inspection audit records write.
 *
 * @package Bhranti
 */

$bi_root = realpath( getenv( 'BHRANTI_INSPECT_TEST_WP_ROOT' ) ?: '' );
if ( '/private/tmp/bhranti-inspect-qa-20261014' !== $bi_root ) {
	fwrite( STDERR, "Use only the independent private inspector QA runtime.\n" );
	exit( 1 );
}
define( 'WP_USE_THEMES', false );
require $bi_root . '/wp-load.php';
if ( 'https://bhranti-checker.invalid' !== get_option( 'home' ) || 'https://bhranti-checker.invalid' !== get_option( 'siteurl' ) || get_option( 'active_plugins' ) || ! DISABLE_WP_CRON || realpath( DB_DIR ) !== realpath( $bi_root . '/wp-content/database' ) || '7.1.3' !== $wp_version ) {
	fwrite( STDERR, "The isolated native WordPress 7.1.3 runtime is required.\n" );
	exit( 1 );
}
require dirname( __DIR__ ) . '/bhranti.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
Bhranti_Workspace::register_storage();
Bhranti_Audit::register_storage();

$bi_checks = 0;
$bi_http = 0;
$bi_mail = 0;
$bi_renders = 0;
$bi_additional = 0;
$bi_schema_callbacks = 0;
$bi_dispatched = 0;
$bi_owner = 0;
$bi_actor = 0;
$bi_session = 0;
$bi_fixture_ids = array();
$bi_created_theme_term = 0;
$bi_marker = 'bhranti-inspect-' . str_replace( '-', '', wp_generate_uuid4() );
$bi_failure = null;
$bi_original_user = get_current_user_id();
$bi_existing_tables = array();
foreach ( array( 'posts', 'postmeta', 'users', 'usermeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta' ) as $bi_table ) {
	$bi_existing_tables[ $bi_table ] = hash( 'sha256', wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . $wpdb->$bi_table . ' ORDER BY 1,2', ARRAY_A ) ) );
}
$bi_existing_options = array();
foreach ( $wpdb->get_results( 'SELECT option_name,option_value,autoload FROM ' . $wpdb->options, ARRAY_A ) as $bi_option ) {
	$bi_existing_options[ $bi_option['option_name'] ] = array( $bi_option['option_value'], $bi_option['autoload'] );
}
$bi_options = array();
foreach ( array( 'wp_user_roles', 'bhranti_actor_id', 'bhranti_role_owned', 'bhranti_actor_setup_lock' ) as $bi_key ) {
	$bi_options[ $bi_key ] = get_option( $bi_key, null );
}
$bi_http_guard = static function () {
	++$GLOBALS['bi_http'];
	return new WP_Error( 'bhranti_inspect_qa_offline', 'Outbound requests are disabled.' );
};
add_filter( 'pre_http_request', $bi_http_guard );
$bi_mail_guard = static function () {
	++$GLOBALS['bi_mail'];
	return false;
};
add_filter( 'pre_wp_mail', $bi_mail_guard );
$bi_request_guard = static function ( $response, $handler, $request ) {
	if ( 'GET' !== $request->get_method() ) {
		throw new RuntimeException( 'Inspector must never dispatch a native write.' );
	}
	++$GLOBALS['bi_dispatched'];
	if ( preg_match( '#^/wp/v2/(?:posts/[1-9][0-9]*|templates/.+)$#', $request->get_route() ) && 'id,type,link,title.raw,content.raw' !== $request->get_param( '_fields' ) ) {
		throw new RuntimeException( 'Raw field projection must precede actual native dispatch.' );
	}
	if ( false !== strpos( $request->get_route(), '/revisions' ) ) {
		$fields = array( 'id', 'parent', 'date', 'date_gmt', 'modified', 'modified_gmt' );
		if ( preg_match( '#/revisions/[1-9][0-9]*$#', $request->get_route() ) ) { $fields = array_merge( $fields, array( 'title.raw', 'content.raw', 'excerpt.raw' ) ); }
		if ( 'edit' !== $request->get_param( 'context' ) || $fields !== $request->get_param( '_fields' ) ) { throw new RuntimeException( 'Revision projection must exclude rendering/private callbacks before dispatch.' ); }
	}
	return $response;
};
add_filter( 'rest_request_before_callbacks', $bi_request_guard, 10, 3 );

/** Assert one concrete native behavior without printing saved source or secrets. */
function bi_check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$GLOBALS['bi_checks'];
}

/** Require a specific closed error. */
function bi_error( $result, $codes, $message ) {
	$codes = (array) $codes;
	bi_check( is_wp_error( $result ) && in_array( $result->get_error_code(), $codes, true ), $message . ( is_wp_error( $result ) ? ' [' . $result->get_error_code() . ']' : ' [unexpected success]' ) );
}

/** Call only the actual authenticated inspector pipeline. */
function bi_inspect( $args ) {
	return Bhranti_Inspect::execute( array( 'tool' => 'bhranti_inspect', 'args' => $args ), $GLOBALS['bi_owner'], $GLOBALS['bi_session'] );
}

/** Create only marked fixture content, never an action through the inspector. */
function bi_fixture( $content, $type = 'post' ) {
	$id = wp_insert_post( wp_slash( array( 'post_type' => $type, 'post_title' => $GLOBALS['bi_marker'], 'post_content' => $content, 'post_status' => 'draft', 'post_author' => $GLOBALS['bi_owner'] ) ), true );
	bi_check( is_int( $id ) && $id > 0, 'Unique isolated saved content fixture exists.' );
	$GLOBALS['bi_fixture_ids'][] = $id;
	return $id;
}

/** Use the exact installed native endpoint arguments for evidence. */
function bi_endpoint( $path, $method ) {
	foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
		if ( ! preg_match( '@^' . $route . '$@i', $path ) ) { continue; }
		foreach ( $handlers as $handler ) {
			if ( ! empty( $handler['methods'][ $method ] ) ) { return $handler; }
		}
	}
	throw new RuntimeException( 'Required native endpoint was not registered.' );
}

/** Read parser bounds without changing or rendering the saved fixture. */
function bi_source( $content, $args = array() ) {
	$id = bi_fixture( $content );
	return bi_inspect( array( 'resource' => 'content', 'path' => '/wp/v2/posts/' . $id ) + $args );
}

/** Read earlier native wording through the ordinary reviewed action pipeline. */
function bi_revision( $path, $params = array(), $method = 'GET' ) {
	return Bhranti_Actions::execute( array( 'tool' => 'wp_request', 'args' => array( 'method' => $method, 'path' => $path, 'params' => $params ) ), $GLOBALS['bi_owner'], $GLOBALS['bi_session'] );
}

try {
	foreach ( array( array( 'Asia/Kolkata', 0, 'Asia/Kolkata' ), array( '', 0, '+00:00' ), array( '', 5.5, '+05:30' ), array( '', -3.5, '-03:30' ) ) as $bi_timezone_case ) {
		$bi_timezone_name = static function () use ( $bi_timezone_case ) { return $bi_timezone_case[0]; };
		$bi_timezone_offset = static function () use ( $bi_timezone_case ) { return $bi_timezone_case[1]; };
		add_filter( 'pre_option_timezone_string', $bi_timezone_name );
		add_filter( 'pre_option_gmt_offset', $bi_timezone_offset );
		try { bi_check( $bi_timezone_case[2] === ( Bhranti_Workspace::site_context()['timezone'] ?? null ), 'Site context exposes the native resolved named, UTC or fractional fixed-offset timezone without changing settings.' ); }
		finally { remove_filter( 'pre_option_timezone_string', $bi_timezone_name ); remove_filter( 'pre_option_gmt_offset', $bi_timezone_offset ); }
	}
	$bi_owner = wp_insert_user( array( 'user_login' => $bi_marker, 'user_pass' => wp_generate_password( 40 ), 'user_email' => $bi_marker . '@example.invalid', 'role' => 'administrator' ) );
	bi_check( is_int( $bi_owner ) && $bi_owner > 1, 'Independent human fixture created.' );
	wp_set_current_user( $bi_owner );
	$bi_session = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $bi_owner, 'post_title' => $bi_marker ), true );
	bi_check( is_int( $bi_session ), 'Independent private owned conversation created.' );
	$bi_actor = Bhranti_Actions::ensure_actor();
	bi_check( is_int( $bi_actor ) && $bi_actor !== $bi_owner, 'Actual managed native actor created.' );
	register_block_type( 'bhranti-inspect-fixture/dynamic', array( 'api_version' => 3, 'render_callback' => static function () { ++$GLOBALS['bi_renders']; throw new RuntimeException( 'Dynamic rendering is forbidden during inspector tests.' ); } ) );
	register_rest_field( 'post', 'bhranti_private_fixture', array( 'get_callback' => static function () { ++$GLOBALS['bi_additional']; throw new RuntimeException( 'Unrequested additional-field callback must not run.' ); }, 'schema' => array( 'type' => 'string', 'context' => array( 'view', 'edit' ) ) ) );
	$bi_native_post_endpoint = bi_endpoint( '/wp/v2/posts', 'POST' );
	bi_check( 'WP_REST_Posts_Controller' === get_class( $bi_native_post_endpoint['callback'][0] ) && 0 === strpos( ( new ReflectionClass( $bi_native_post_endpoint['callback'][0] ) )->getFileName(), realpath( ABSPATH . WPINC ) ), 'Core-controller evidence comes from the actual installed WordPress package.' );

	// A plain follow-up Undo must recover real earlier wording without a new write primitive.
	$bi_original_intro = '<!-- wp:paragraph --><p>The exact original introduction.</p><!-- /wp:paragraph -->';
	$bi_later_section = '<!-- wp:paragraph --><p>A later unrelated section stays.</p><!-- /wp:paragraph -->';
	$bi_history_parent = bi_fixture( $bi_original_intro, 'post' );
	$bi_history_revision = wp_save_post_revision( $bi_history_parent );
	bi_check( is_int( $bi_history_revision ) && $bi_history_revision > 0, 'Native fixture retains a real saved original revision.' );
	$bi_friendly_intro = '<!-- wp:paragraph --><p>A friendly new introduction.</p><!-- /wp:paragraph -->';
	wp_update_post( wp_slash( array( 'ID' => $bi_history_parent, 'post_content' => $bi_friendly_intro . $bi_later_section ) ) );
	$bi_current_history = get_post_field( 'post_content', $bi_history_parent, 'raw' );
	$bi_history_page = bi_fixture( '<!-- wp:paragraph --><p>Page original.</p><!-- /wp:paragraph -->', 'page' );
	$bi_page_revision = wp_save_post_revision( $bi_history_page );
	$bi_revision_path = '/wp/v2/posts/' . $bi_history_parent . '/revisions';
	$bi_revision_schema = bi_inspect( array( 'resource' => 'route', 'path' => $bi_revision_path ) );
	bi_check( is_array( $bi_revision_schema ) && isset( $bi_revision_schema['data']['parameters']['per_page'] ) && ! isset( $bi_revision_schema['data']['parameters']['parent'] ), 'The previously blocked native revision schema is discoverable with URL-bound parent.' );
	$bi_revision_queries = array();
	$bi_revision_query_observer = static function ( $args ) use ( &$bi_revision_queries ) { $bi_revision_queries[] = $args; return $args; };
	add_filter( 'rest_revision_query', $bi_revision_query_observer );
	register_rest_field( 'post-revision', 'bhranti_private_revision_fixture', array( 'get_callback' => static function () { ++$GLOBALS['bi_additional']; throw new RuntimeException( 'Revision additional fields must not run.' ); }, 'schema' => array( 'type' => 'string', 'context' => array( 'edit' ) ) ) );
	$bi_history_list = bi_revision( $bi_revision_path );
	bi_check( is_array( $bi_history_list ) && count( $bi_history_list['data'] ) <= 20 && in_array( $bi_history_revision, array_column( $bi_history_list['data'], 'id' ), true ) && ! isset( $bi_history_list['data'][0]['content'] ), 'Bounded native collection lists real saved revision metadata without content dumps.' );
	bi_check( 20 === $bi_revision_queries[0]['posts_per_page'] && 1 === count( bi_revision( $bi_revision_path, array( 'per_page' => 1 ) )['data'] ), 'Native revisions never use the core unbounded default and honor smaller requested pages.' );
	$bi_original_read = bi_revision( $bi_revision_path . '/' . $bi_history_revision, array( '_fields' => 'id,parent,title.raw,content.raw,author,meta' ) );
	bi_check( is_array( $bi_original_read ) && $bi_original_intro === $bi_original_read['data']['content']['raw'] && $bi_history_parent === $bi_original_read['data']['parent'], 'The model can retrieve exact earlier raw wording without asking the human to paste it.' );
	bi_check( 'post-revision' === $bi_original_read['target']['type'] && $bi_history_revision === $bi_original_read['target']['id'] && ! isset( $bi_original_read['data']['author'], $bi_original_read['data']['meta'], $bi_original_read['data']['content']['rendered'] ), 'Historical source has distinct revision identity and excludes author, metadata and rendered fields.' );
	bi_check( '' === $bi_original_read['action_id'] && ! $bi_original_read['rollback_available'] && 0 === $bi_renders && 0 === $bi_additional, 'Revision lookup adds no write/rollback authority or render/additional callbacks.' );
	$bi_restored_section = Bhranti_Content_Patch::apply( $bi_current_history, array( 'revision' => hash( 'sha256', $bi_current_history ), 'operation' => 'replace', 'block_path' => '0', 'content' => $bi_original_read['data']['content']['raw'] ) );
	bi_check( $bi_original_intro . $bi_later_section === $bi_restored_section && $bi_current_history === get_post_field( 'post_content', $bi_history_parent, 'raw' ), 'Existing conflict-safe patch can restore only the earlier section and retain later work without writing during inspection tests.' );
	$bi_stale_patch = Bhranti_Content_Patch::apply( $bi_current_history, array( 'revision' => hash( 'sha256', $bi_original_intro ), 'operation' => 'replace', 'block_path' => '0', 'content' => $bi_original_intro ) );
	bi_check( is_wp_error( $bi_stale_patch ), 'Earlier source never grants a stale full-page restore or bypasses current revision conflicts.' );
	$bi_page_read = bi_revision( '/wp/v2/pages/' . $bi_history_page . '/revisions/' . $bi_page_revision );
	bi_check( is_array( $bi_page_read ) && 'page-revision' === $bi_page_read['target']['type'], 'The same native integration supports pages without broadening other post types.' );
	foreach ( array( array( $bi_revision_path, array( 'parent' => $bi_history_page ), 'GET' ), array( $bi_revision_path, array( 'per_page' => 21 ), 'GET' ), array( $bi_revision_path . '/' . $bi_page_revision, array(), 'GET' ), array( $bi_revision_path . '/' . $bi_history_revision, array(), 'POST' ), array( $bi_revision_path . '/' . $bi_history_revision, array( 'force' => true ), 'DELETE' ), array( '/wp/v2/posts/' . $bi_history_parent . '/autosaves', array(), 'GET' ) ) as $bi_revision_bad ) {
		bi_check( is_wp_error( bi_revision( $bi_revision_bad[0], $bi_revision_bad[1], $bi_revision_bad[2] ) ), 'Parent overrides, cross-parent reads, oversized pages, autosave routes and revision writes are refused.' );
	}
	$bi_autosave = _wp_put_post_revision( array_merge( get_post( $bi_history_parent, ARRAY_A ), array( 'post_content' => 'Private unsaved autosave fixture.' ) ), true );
	bi_check( is_int( $bi_autosave ) && wp_is_post_autosave( $bi_autosave ), 'A real separate native autosave fixture exists.' );
	$bi_history_list = bi_revision( $bi_revision_path );
	$bi_before_autosave_read = $bi_dispatched;
	bi_check( is_array( $bi_history_list ) && ! in_array( $bi_autosave, array_column( $bi_history_list['data'], 'id' ), true ) && is_wp_error( bi_revision( $bi_revision_path . '/' . $bi_autosave ) ) && $bi_dispatched === $bi_before_autosave_read, 'Autosaves are omitted from native revision collections and exact autosave IDs fail before native dispatch.' );
	$bi_foreign_autosave = _wp_put_post_revision( array_merge( get_post( $bi_history_page, ARRAY_A ), array( 'post_content' => 'Private foreign unsaved autosave fixture.' ) ), true );
	bi_check( is_int( $bi_foreign_autosave ) && wp_is_post_autosave( $bi_foreign_autosave ), 'A real foreign-parent autosave fixture exists.' );
	foreach ( array( $bi_page_revision, $bi_foreign_autosave, $bi_history_parent, PHP_INT_MAX ) as $bi_wrong_history_id ) {
		bi_error( bi_revision( $bi_revision_path . '/' . $bi_wrong_history_id ), 'bhranti_revision_parent', 'Foreign saved revisions, foreign autosaves, non-revisions and missing IDs share a generic parent mismatch without leaking autosave identity.' );
	}
	bi_check( $bi_dispatched === $bi_before_autosave_read, 'Foreign or invalid revision identities fail before native dispatch.' );
	foreach ( array( $bi_owner, $bi_actor ) as $bi_denied_user ) {
		foreach ( array( 'read_post', 'edit_post' ) as $bi_denied_meta_cap ) {
			$bi_revision_deny = static function ( $caps, $cap, $user_id, $args ) use ( $bi_denied_user, $bi_denied_meta_cap, $bi_history_parent ) { return $user_id === $bi_denied_user && $cap === $bi_denied_meta_cap && (int) ( $args[0] ?? 0 ) === $bi_history_parent ? array( 'do_not_allow' ) : $caps; };
			add_filter( 'map_meta_cap', $bi_revision_deny, 100, 4 );
			try { bi_error( bi_revision( $bi_revision_path . '/' . $bi_history_revision ), 'bhranti_action_permission', 'Either account losing parent read or edit permission blocks history.' ); }
			finally { remove_filter( 'map_meta_cap', $bi_revision_deny, 100 ); }
			bi_check( $bi_owner === get_current_user_id(), 'Rejected historical permission checks restore the human identity.' );
		}
	}
	$bi_revision_native_route = '/wp/v2/posts/(?P<parent>[\d]+)/revisions';
	foreach ( array( 'instance', 'schema', 'permission', 'callback', 'callback_item', 'moved' ) as $bi_revision_spoof ) {
		$bi_revision_filter = static function ( $routes ) use ( $bi_revision_native_route, $bi_revision_spoof ) {
			if ( 'moved' === $bi_revision_spoof ) { $routes['/wp/v2/private-history/(?P<parent>[\d]+)/revisions'] = $routes[ $bi_revision_native_route ]; return $routes; }
			if ( 'callback_item' === $bi_revision_spoof ) { foreach ( $routes[ $bi_revision_native_route . '/(?P<id>[\d]+)' ] as &$item_handler ) { if ( 'get_item' === ( $item_handler['callback'][1] ?? '' ) ) { $item_handler['callback'][1] = 'get_items'; $item_handler['permission_callback'][1] = 'get_items_permissions_check'; } } return $routes; }
			if ( 'schema' === $bi_revision_spoof ) { $routes[ $bi_revision_native_route ]['schema'] = '__return_empty_array'; }
			foreach ( $routes[ $bi_revision_native_route ] as &$handler ) {
				if ( 'get_items' !== ( $handler['callback'][1] ?? '' ) ) { continue; }
				if ( 'instance' === $bi_revision_spoof ) { $copy = new WP_REST_Revisions_Controller( 'post' ); $handler['callback'][0] = $copy; $handler['permission_callback'][0] = $copy; }
				if ( 'permission' === $bi_revision_spoof ) { $handler['permission_callback'] = '__return_true'; }
				if ( 'callback' === $bi_revision_spoof ) { $handler['callback'][1] = 'get_item'; $handler['permission_callback'][1] = 'get_item_permissions_check'; }
			}
			return $routes;
		};
		add_filter( 'rest_endpoints', $bi_revision_filter, PHP_INT_MAX );
		try { bi_error( bi_revision( 'moved' === $bi_revision_spoof ? '/wp/v2/private-history/' . $bi_history_parent . '/revisions' : $bi_revision_path . ( 'callback_item' === $bi_revision_spoof ? '/' . $bi_history_revision : '' ) ), 'bhranti_action_blocked', 'Replacement instances/schema/permissions/callback shapes and moved revision routes cannot impersonate reviewed native history.' ); }
		finally { remove_filter( 'rest_endpoints', $bi_revision_filter, PHP_INT_MAX ); }
	}
	$bi_foreign_revision = static function ( $args ) use ( $bi_history_page ) { $args['post_parent'] = $bi_history_page; return $args; };
	add_filter( 'rest_revision_query', $bi_foreign_revision );
	try { bi_error( bi_revision( $bi_revision_path ), 'bhranti_revision_parent', 'A third-party query filter cannot return another parent’s history.' ); }
	finally { remove_filter( 'rest_revision_query', $bi_foreign_revision ); }
	remove_filter( 'rest_revision_query', $bi_revision_query_observer );
	$bi_large_history = bi_fixture( str_repeat( 'x', 1048577 ) );
	$bi_large_revision = wp_save_post_revision( $bi_large_history );
	bi_error( bi_revision( '/wp/v2/posts/' . $bi_large_history . '/revisions/' . $bi_large_revision ), 'bhranti_revision_limit', 'Oversized original source is rejected rather than returned or truncated.' );
	bi_check( $bi_current_history === get_post_field( 'post_content', $bi_history_parent, 'raw' ) && ! get_post_meta( $bi_session, 'bhranti_actions', true ), 'All history attempts preserve current source and create no action snapshots.' );

	$bi_content = '<!-- wp:group {"layout":{"type":"constrained"},"anchor":"useful-context"} --><div class="wp-block-group" id="useful-context"><!-- wp:heading --><h2 class="wp-block-heading">Useful heading</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Useful body text.</p><!-- /wp:paragraph --><!-- wp:template-part {"slug":"header","theme":"twentytwentyfive"} /--></div><!-- /wp:group --><!-- wp:paragraph --><p>Outside the group.</p><!-- /wp:paragraph --><!-- wp:bhranti-inspect-fixture/dynamic /--><!-- wp:paragraph --><p><img src="https://assets.invalid/fixture.png"></p><!-- /wp:paragraph -->';
	$bi_post_id = bi_fixture( $bi_content );
	$bi_path = '/wp/v2/posts/' . $bi_post_id;
	$bi_storage_before = hash( 'sha256', wp_json_encode( array( get_post( $bi_post_id )->to_array(), get_post_meta( $bi_post_id ) ) ) );
	$bi_source_data = Bhranti_Actions::inspect_source( $bi_path, $bi_owner, $bi_session );
	bi_check( is_array( $bi_source_data ) && $bi_content === $bi_source_data['data']['content']['raw'], 'Actual native raw source preserves the saved document.' );
	bi_check( array( 'raw' ) === array_keys( $bi_source_data['data']['content'] ) && array( 'raw' ) === array_keys( $bi_source_data['data']['title'] ) && ! isset( $bi_source_data['data']['meta'], $bi_source_data['data']['password'], $bi_source_data['data']['bhranti_private_fixture'] ), 'Before-dispatch native projection omits rendered, private and unrequested fields.' );
	bi_check( 0 === $bi_renders && 0 === $bi_additional, 'Dynamic blocks and private additional-field callbacks have not rendered.' );
	$bi_outline = bi_inspect( array( 'resource' => 'content', 'path' => $bi_path, 'per_page' => 3 ) );
	bi_check( is_array( $bi_outline ), 'Actual saved-content inspection succeeds.' );
	$bi_data = $bi_outline['data'];
	bi_check( hash( 'sha256', $bi_content ) === $bi_data['revision'] && 7 === $bi_data['block_count'], 'Revision hashes exact full source, and native traversal counts nested blocks.' );
	bi_check( array( '0', '0/0', '0/1' ) === array_column( $bi_data['outline'], 'path' ) && $bi_data['has_more'], 'First page uses stable native parsed block paths in preorder.' );
	bi_check( array( 'anchor' => 'useful-context' ) === $bi_data['outline'][0]['references'] && 'Useful heading' === $bi_data['outline'][1]['text'], 'Outline exposes bounded useful text and finite scalar references.' );
	$bi_next = bi_inspect( array( 'resource' => 'content', 'path' => $bi_path, 'page' => 2, 'per_page' => 3, 'block_path' => '0/2' ) );
	bi_check( array( '0/2', '1', '2' ) === array_column( $bi_next['data']['outline'], 'path' ) && $bi_next['data']['has_more'], 'Second page continues across child and root blocks without losing order.' );
	$bi_native_selected = serialize_block( parse_blocks( $bi_content )[0]['innerBlocks'][2] );
	bi_check( $bi_native_selected === $bi_next['data']['selected']['markup'] && hash( 'sha256', $bi_native_selected ) === $bi_next['data']['selected']['revision'], 'Exact nested selection returns native saved markup and its own revision.' );
	bi_check( array( 'slug' => 'header', 'theme' => 'twentytwentyfive' ) === $bi_next['data']['outline'][0]['references'], 'Native shared-template reference fields remain useful.' );
	bi_check( 'bhranti_content_quality' === $bi_data['authored_checks']['code'] && in_array( 'image_alt_missing', $bi_data['authored_checks']['details']['issues'], true ) && false !== strpos( $bi_data['verification'], 'Not rendered' ) && false !== strpos( $bi_data['verification'], 'full accessibility' ), 'Known authored defects are reported without claiming rendered or full WCAG validation.' );
	$bi_last = bi_inspect( array( 'resource' => 'content', 'path' => $bi_path, 'page' => 3, 'per_page' => 3 ) );
	bi_check( array( '3' ) === array_column( $bi_last['data']['outline'], 'path' ) && ! $bi_last['data']['has_more'], 'Final page correctly ends the finite outline.' );
	$bi_empty = bi_inspect( array( 'resource' => 'content', 'path' => $bi_path, 'page' => 250, 'per_page' => 20 ) );
	bi_check( array() === $bi_empty['data']['outline'] && ! $bi_empty['data']['has_more'], 'Valid out-of-range pages remain bounded empty reads.' );
	bi_error( bi_inspect( array( 'resource' => 'content', 'path' => $bi_path, 'block_path' => '0/99' ) ), 'bhranti_inspect_missing', 'Unknown exact block selection fails clearly.' );
	foreach ( array(
		array( 'resource' => 'content', 'path' => '/wp/v2/posts' ),
		array( 'resource' => 'content', 'path' => $bi_path, 'method' => 'POST' ),
		array( 'resource' => 'content', 'path' => $bi_path, 'field' => 'content' ),
		array( 'resource' => 'content', 'path' => $bi_path, 'block_path' => '../0' ),
		array( 'resource' => 'content', 'path' => $bi_path, 'block_path' => '/0' ),
		array( 'resource' => 'content', 'path' => $bi_path, 'page' => 0 ),
		array( 'resource' => 'content', 'path' => $bi_path, 'page' => 251 ),
		array( 'resource' => 'content', 'path' => $bi_path, 'per_page' => 21 ),
		array( 'resource' => 'content', 'path' => $bi_path, 'per_page' => 0 ),
		array( 'resource' => 'content', 'path' => $bi_path, 'unknown' => true ),
		array( 'resource' => 'route', 'path' => '/wp/v2/posts', 'page' => 1 ),
	) as $bi_invalid ) { bi_error( bi_inspect( $bi_invalid ), 'bhranti_inspect_invalid', 'Invalid/non-content/paging arguments cannot select an arbitrary source.' ); }
	foreach ( array( '/wp/v2/users/' . $bi_owner, '/bhranti/v1/settings', '/wp/v2/posts/1?context=edit', '/wp/v2/posts/%31', '/wp/v2/../../secrets', '/private/server/file' ) as $bi_invalid_path ) {
		bi_error( bi_inspect( array( 'resource' => 'content', 'path' => $bi_invalid_path ) ), array( 'bhranti_inspect_invalid', 'bhranti_action_blocked' ), 'Non-document/private/encoded/external routes are outside saved-content inspection.' );
	}
	$bi_reference_doc = '<!-- wp:group ' . wp_json_encode( array( 'id' => 123, 'ref' => true, 'slug' => str_repeat( 's', 250 ), 'theme' => array( 'hidden' => 'fixture-private' ), 'anchor' => 'useful', 'custom' => 'fixture-private' ) ) . ' --><div class="wp-block-group"></div><!-- /wp:group -->';
	$bi_reference_outline = bi_source( $bi_reference_doc );
	$bi_references = $bi_reference_outline['data']['outline'][0]['references'];
	bi_check( 123 === $bi_references['id'] && 200 === mb_strlen( $bi_references['slug'] ) && ! isset( $bi_references['ref'], $bi_references['theme'], $bi_references['custom'] ) && false === strpos( wp_json_encode( $bi_references ), 'fixture-private' ), 'Outline reference projection is finite and excludes nested/boolean/arbitrary attributes.' );

	// A real database template uses the native string ID and controller, not a fake DTO.
	$bi_theme = get_stylesheet();
	$bi_theme_term_before = term_exists( $bi_theme, 'wp_theme' );
	$bi_template_id = bi_fixture( '<!-- wp:paragraph --><p>Native template fixture.</p><!-- /wp:paragraph --><!-- wp:bhranti-inspect-fixture/dynamic /-->', 'wp_template' );
	wp_update_post( array( 'ID' => $bi_template_id, 'post_status' => 'publish', 'post_name' => $bi_marker ) );
	$bi_term_ids = wp_set_object_terms( $bi_template_id, $bi_theme, 'wp_theme' );
	bi_check( ! is_wp_error( $bi_term_ids ), 'Native template fixture is attached to its actual theme taxonomy.' );
	if ( ! $bi_theme_term_before ) { $bi_term_record = get_term_by( 'slug', $bi_theme, 'wp_theme' ); $bi_created_theme_term = $bi_term_record ? $bi_term_record->term_id : 0; }
	$bi_template_path = '/wp/v2/templates/' . $bi_theme . '//' . $bi_marker;
	$bi_template = bi_inspect( array( 'resource' => 'content', 'path' => $bi_template_path ) );
	bi_check( is_array( $bi_template ) && hash( 'sha256', get_post( $bi_template_id )->post_content ) === $bi_template['data']['revision'] && 2 === $bi_template['data']['block_count'], 'Actual FSE string-ID content can be inspected without rendering its dynamic block.' );

	$bi_schema = bi_inspect( array( 'resource' => 'route', 'path' => '/wp/v2/posts', 'method' => 'POST' ) );
	bi_check( is_array( $bi_schema ) && ! array_diff( array( 'title', 'content', 'status' ), array_keys( $bi_schema['data']['parameters'] ) ), 'Real native post creation fields are discoverable.' );
	bi_check( ! array_intersect( array( 'author', 'password', 'meta', 'force', 'id', 'bhranti_private_fixture' ), array_keys( $bi_schema['data']['parameters'] ) ), 'Unreviewed identities, passwords, metadata and custom fields are not advertised for writes.' );
	$bi_content_schema = $bi_schema['data']['parameters']['content'];
	bi_check( $bi_native_post_endpoint['args']['content']['type'] === $bi_content_schema['type'], 'Schema preserves the actual native declared content type rather than a guessed type.' );
	bi_check( true === $bi_content_schema['properties']['rendered']['readonly'] && $bi_native_post_endpoint['args']['content']['properties']['raw']['context'] === $bi_content_schema['properties']['raw']['context'], 'Native readonly and context metadata distinguish response fields from writable raw input.' );
	bi_check( false !== strpos( $bi_content_schema['input_note'], 'plain string' ) && false !== strpos( $bi_content_schema['input_note'], 'raw text' ) && false !== strpos( $bi_schema['data']['verification'], 'omitted' ), 'Bounded discovery explains actual native write formats and its partial-schema limit.' );
	$bi_prepare_native = new ReflectionMethod( WP_REST_Posts_Controller::class, 'prepare_item_for_database' );
	foreach ( array( 'Useful text', array( 'raw' => 'Useful text' ) ) as $bi_native_value ) {
		$bi_native_request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$bi_native_request->set_attributes( $bi_native_post_endpoint );
		$bi_native_request->set_body_params( array( 'content' => $bi_native_value ) );
		bi_check( ! is_wp_error( $bi_native_request->has_valid_params() ) && ! is_wp_error( $bi_native_request->sanitize_params() ), 'Actual native request accepts the string/raw-object content format without a write.' );
		$bi_prepared = $bi_prepare_native->invoke( $bi_native_post_endpoint['callback'][0], $bi_native_request );
		bi_check( is_object( $bi_prepared ) && ! is_wp_error( $bi_prepared ) && 'Useful text' === $bi_prepared->post_content, 'Actual core database preparation accepts both content formats without saving them.' );
	}
	bi_check( is_wp_error( rest_validate_value_from_schema( array( 'raw' => array( 'invalid' ) ), $bi_native_post_endpoint['args']['content'] ) ), 'Actual native schema rejects invalid nested raw content.' );
	$bi_one = bi_inspect( array( 'resource' => 'route', 'path' => '/wp/v2/posts', 'method' => 'POST', 'field' => 'content' ) );
	bi_check( array( 'content' ) === array_keys( $bi_one['data']['parameters'] ), 'Exact field selection avoids unrelated schema data.' );
	foreach ( array( 'password', 'meta', 'author', 'content.raw', 'nonexistent' ) as $bi_unavailable_field ) {
		bi_error( bi_inspect( array( 'resource' => 'route', 'path' => '/wp/v2/posts', 'method' => 'POST', 'field' => $bi_unavailable_field ) ), 'bhranti_action_blocked', 'Unknown or hidden write fields are not returned.' );
	}
	$bi_types = bi_inspect( array( 'resource' => 'route', 'path' => '/wp/v2/types/post' ) );
	bi_check( is_array( $bi_types ) && isset( $bi_types['data']['parameters']['context'] ), 'Exact native public post-type schema exception remains usable.' );
	$bi_search = bi_inspect( array( 'resource' => 'route', 'path' => '/wp/v2/search' ) );
	bi_check( is_array( $bi_search ) && 'string' === $bi_search['data']['parameters']['search']['type'], 'Actual native Search discovery retains its singular get_items_permission_check pairing.' );
	foreach ( array( '/wp/v2/templates', '/wp/v2/template-parts' ) as $bi_template_collection ) {
		$bi_native_template_endpoint = bi_endpoint( $bi_template_collection, 'GET' );
		$bi_template_schema = bi_inspect( array( 'resource' => 'route', 'path' => $bi_template_collection, 'method' => 'GET' ) );
		bi_check( is_array( $bi_template_schema ) && array_keys( $bi_native_template_endpoint['args'] ) === array_keys( $bi_template_schema['data']['parameters'] ), 'Template collection discovery exposes the actual installed native arguments.' );
		bi_check( ! array_diff( array( 'context', 'wp_id', 'area', 'post_type' ), array_keys( $bi_template_schema['data']['parameters'] ) ) && ! array_intersect( array( 'page', 'per_page' ), array_keys( $bi_template_schema['data']['parameters'] ) ), 'Native template collections retain supported filters without invented post-list pagination.' );
		foreach ( array( 'page', 'per_page' ) as $bi_pagination_field ) {
			bi_error( bi_inspect( array( 'resource' => 'route', 'path' => $bi_template_collection, 'method' => 'GET', 'field' => $bi_pagination_field ) ), 'bhranti_action_blocked', 'Unsupported template pagination cannot be advertised as a native argument.' );
		}
	}
	$bi_public_schema_spoof = static function ( $routes ) {
		$routes['/wp/v2/types/(?P<type>[\\w-]+)']['schema'] = static function () { ++$GLOBALS['bi_schema_callbacks']; throw new RuntimeException( 'Substituted public schema callback must not execute.' ); };
		return $routes;
	};
	add_filter( 'rest_endpoints', $bi_public_schema_spoof );
	try { bi_error( bi_inspect( array( 'resource' => 'route', 'path' => '/wp/v2/types/post' ) ), 'bhranti_action_blocked', 'A substituted schema callback cannot use the exact public post-type permission exception.' ); }
	finally { remove_filter( 'rest_endpoints', $bi_public_schema_spoof ); }
	bi_check( is_array( bi_inspect( array( 'resource' => 'route', 'path' => '/wp/v2/types/post' ) ) ) && 0 === $bi_schema_callbacks, 'Restoring the native public schema preserves discovery without invoking the substitute.' );
	foreach ( array( array( '/wp/v2/users', 'POST' ), array( '/wp/v2/media', 'POST' ), array( '/wp/v2/categories', 'POST' ), array( '/wp/v2/settings', 'DELETE' ), array( '/bhranti/v1/settings', 'GET' ), array( '/wp/v2/posts?bad=1', 'GET' ) ) as $bi_blocked_route ) {
		bi_error( bi_inspect( array( 'resource' => 'route', 'path' => $bi_blocked_route[0], 'method' => $bi_blocked_route[1] ) ), 'bhranti_action_blocked', 'Unsupported creation/deletion and non-reviewed routes remain outside schema discovery.' );
	}

	// Schema callbacks remain inert even on actual native controller arguments.
	$bi_callback = static function () { ++$GLOBALS['bi_schema_callbacks']; throw new RuntimeException( 'Schema callbacks must not execute during discovery.' ); };
	$bi_argument_filter = static function ( $routes ) use ( $bi_callback ) {
		foreach ( $routes['/wp/v2/posts'] as &$endpoint ) {
			if ( 'create_item' !== ( $endpoint['callback'][1] ?? '' ) ) { continue; }
			$endpoint['args']['content']['validate_callback'] = $bi_callback;
			$endpoint['args']['content']['sanitize_callback'] = $bi_callback;
			$endpoint['args']['content']['callback'] = $bi_callback;
			$endpoint['args']['content']['properties']['raw']['validate_callback'] = $bi_callback;
		}
		return $routes;
	};
	add_filter( 'rest_endpoints', $bi_argument_filter );
	try {
		$bi_inert = bi_inspect( array( 'resource' => 'route', 'path' => '/wp/v2/posts', 'method' => 'POST', 'field' => 'content' ) );
		bi_check( is_array( $bi_inert ) && 0 === $bi_schema_callbacks && false === strpos( wp_json_encode( $bi_inert['data']['parameters'] ), 'callback' ), 'Native argument projection strips executable callbacks at every schema level.' );
	} finally { remove_filter( 'rest_endpoints', $bi_argument_filter ); }

	// A real controller object does not make a substituted permission method native.
	$bi_permission_spoof = static function ( $routes ) {
		foreach ( $routes['/wp/v2/posts/(?P<id>[\\d]+)'] as &$endpoint ) {
			if ( 'get_item' === ( $endpoint['callback'][1] ?? '' ) ) { $endpoint['permission_callback'] = array( $endpoint['callback'][0], 'get_item' ); }
		}
		return $routes;
	};
	add_filter( 'rest_endpoints', $bi_permission_spoof );
	try {
		bi_error( bi_inspect( array( 'resource' => 'route', 'path' => $bi_path ) ), 'bhranti_action_blocked', 'A mismatched permission method on the actual native controller fails closed.' );
		bi_error( bi_inspect( array( 'resource' => 'content', 'path' => $bi_path ) ), 'bhranti_action_blocked', 'Permission-method spoof cannot cause rendering before source projection.' );
		bi_check( 0 === $bi_renders, 'Mismatched native permission method invokes no dynamic block callback.' );
	} finally { remove_filter( 'rest_endpoints', $bi_permission_spoof ); }

	register_rest_route( 'wp/v2', '/bhranti-inspect-private/(?P<id>[\\d]+)', array( 'methods' => 'GET', 'callback' => $bi_callback, 'permission_callback' => '__return_true' ) );
	bi_error( bi_inspect( array( 'resource' => 'route', 'path' => '/wp/v2/bhranti-inspect-private/1' ) ), 'bhranti_action_blocked', 'Unknown/private callbacks cannot impersonate core routes.' );
	bi_error( bi_inspect( array( 'resource' => 'content', 'path' => '/wp/v2/bhranti-inspect-private/1' ) ), 'bhranti_action_blocked', 'Unknown/private controller content is never dispatched.' );
	bi_check( 0 === $bi_schema_callbacks, 'Unreviewed controller callbacks never execute.' );

	// The actual actor and requesting human must retain native item permissions after discovery.
	foreach ( array( $bi_owner, $bi_actor ) as $bi_denied_user ) {
		$bi_deny = static function ( $caps, $requested, $args, $user ) use ( $bi_denied_user ) {
			if ( $user->ID === $bi_denied_user ) { foreach ( array( 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'read_private_posts' ) as $key ) { $caps[ $key ] = false; } }
			return $caps;
		};
		add_filter( 'user_has_cap', $bi_deny, 100, 4 );
		try {
			bi_error( bi_inspect( array( 'resource' => 'content', 'path' => $bi_path ) ), 'bhranti_action_permission', 'Revocation on either native account blocks source inspection after successful metadata discovery.' );
			bi_check( $bi_owner === get_current_user_id(), 'Native permission denial restores the requesting user.' );
		} finally { remove_filter( 'user_has_cap', $bi_deny, 100 ); }
	}
	$bi_deny_manage = static function ( $caps, $requested, $args, $user ) { if ( $user->ID === $GLOBALS['bi_owner'] ) { $caps['manage_options'] = false; } return $caps; };
	add_filter( 'user_has_cap', $bi_deny_manage, 100, 4 );
	try { bi_error( bi_inspect( array( 'resource' => 'route', 'path' => '/wp/v2/posts' ) ), 'bhranti_inspect_permission', 'Revoked administrator access blocks metadata as well as content.' ); }
	finally { remove_filter( 'user_has_cap', $bi_deny_manage, 100 ); }
	$bi_wrong_session = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $bi_actor, 'post_title' => $bi_marker ), true );
	$bi_fixture_ids[] = $bi_wrong_session;
	bi_error( Bhranti_Inspect::execute( array( 'tool' => 'bhranti_inspect', 'args' => array( 'resource' => 'route', 'path' => '/wp/v2/posts' ) ), $bi_owner, $bi_wrong_session ), 'bhranti_inspect_permission', 'A private conversation belonging to another user cannot inspect.' );

	// Check only the actual empty native registry; no test provider or connector is registered.
	if ( function_exists( 'wp_ai_client_prompt' ) && class_exists( 'WordPress\\AiClient\\AiClient' ) && ! WordPress\AiClient\AiClient::defaultRegistry()->getRegisteredProviderIds() ) {
		$bi_images = bi_inspect( array( 'resource' => 'images' ) );
		bi_check( is_array( $bi_images ) && 'unknown' === $bi_images['data']['status'] && 'discovery_unavailable' === $bi_images['data']['reason'] && $bi_images['metadata_only'] && ! $bi_images['rollback_available'] && 0 === $bi_http, 'Bare owned image inspection keeps an empty native connector unknown, without generation or HTTP.' );
		bi_check( null === $bi_images['data']['requested_aspect_ratio'] && null === $bi_images['data']['selected_aspect_ratio'], 'Omitting the ratio reaches native Media without inventing an output shape.' );
		$bi_ratio_schema = Bhranti_Inspect::catalog()['bhranti_inspect']['parameters'];
		foreach ( array( '16:9', '2:3', '999:999' ) as $bi_ratio ) {
			$bi_image_args = array( 'resource' => 'images', 'aspect_ratio' => $bi_ratio );
			bi_check( ! is_wp_error( rest_validate_value_from_schema( $bi_image_args, $bi_ratio_schema ) ), 'The native tool schema accepts positive bounded landscape, portrait and maximum-length ratios.' );
			$bi_shaped_images = bi_inspect( $bi_image_args );
			bi_check( is_array( $bi_shaped_images ) && $bi_ratio === $bi_shaped_images['data']['requested_aspect_ratio'] && null === $bi_shaped_images['data']['selected_aspect_ratio'] && 'unknown' === $bi_shaped_images['data']['status'] && $bi_shaped_images['metadata_only'], 'The actual Inspector passes the exact optional ratio to native Media without claiming support or generating.' );
		}
	} else {
		bi_check( false, 'Aspect-ratio inspection requires the isolated native client with an empty provider registry.' );
	}
	foreach ( array( '16x9', '0:9', '16:0', '-1:2', '01:2', '1.5:2', '1000:1', '1:1000', '1:1:1', ' 1:1', '1:1 ', 7, null, array() ) as $bi_bad_ratio ) {
		bi_error( bi_inspect( array( 'resource' => 'images', 'aspect_ratio' => $bi_bad_ratio ) ), 'bhranti_inspect_invalid', 'Invalid ratio type, shape or bounds are rejected by the native Inspector schema.' );
	}
	$bi_reads_before_ratio_denials = $bi_dispatched;
	foreach ( array( 'route', 'content' ) as $bi_resource ) {
		bi_error( bi_inspect( array( 'resource' => $bi_resource, 'path' => $bi_path, 'aspect_ratio' => '16:9' ) ), 'bhranti_inspect_invalid', 'A valid image ratio cannot be added to route or saved-content inspection.' );
	}
	foreach ( array( array( 'method' => 'GET' ), array( 'field' => 'content' ), array( 'page' => 1 ), array( 'width' => 1600 ), array( 'format' => 'png' ), array( 'prompt' => 'Unrequested generation' ) ) as $bi_extra_image_args ) {
		bi_error( bi_inspect( array( 'resource' => 'images', 'aspect_ratio' => '16:9' ) + $bi_extra_image_args ), 'bhranti_inspect_invalid', 'Image capability inspection denies unrelated native route, pixel, format or generation arguments.' );
	}
	bi_check( $bi_reads_before_ratio_denials === $bi_dispatched && 0 === $bi_http && 0 === $bi_mail, 'Ratio-related denials dispatch no native content endpoint, HTTP or mail.' );
	bi_error( bi_inspect( array( 'resource' => 'images', 'path' => $bi_path ) ), 'bhranti_inspect_invalid', 'Image metadata inspection rejects an extra source path.' );
	$bi_media_config = Bhranti_Settings::all();
	$bi_media_config['permissions']['manage_media'] = false;
	$bi_disabled_media = static function () use ( $bi_media_config ) { return array( 'config' => $bi_media_config, 'revision' => wp_generate_uuid4() ); };
	add_filter( 'pre_option_bhranti_settings', $bi_disabled_media );
	try { bi_error( bi_inspect( array( 'resource' => 'images' ) ), 'bhranti_action_disabled', 'Bare image inspection reuses the current native media policy through the proper owned wrapper.' ); }
	finally { remove_filter( 'pre_option_bhranti_settings', $bi_disabled_media ); }

	// Bound native schema output while retaining useful exact-field discovery.
	$bi_large_schema = static function ( $routes ) {
		foreach ( $routes['/wp/v2/posts'] as &$endpoint ) {
			if ( 'get_items' !== ( $endpoint['callback'][1] ?? '' ) ) { continue; }
			for ( $index = 0; $index < 45; ++$index ) { $endpoint['args']['fixture_' . $index] = array( 'type' => 'string', 'description' => str_repeat( 'x', 1200 ) ); }
		}
		return $routes;
	};
	add_filter( 'rest_endpoints', $bi_large_schema );
	try {
		bi_error( bi_inspect( array( 'resource' => 'route', 'path' => '/wp/v2/posts' ) ), 'bhranti_inspect_limit', 'Oversized schema response requires exact field selection.' );
		$bi_bounded_schema = bi_inspect( array( 'resource' => 'route', 'path' => '/wp/v2/posts', 'field' => 'fixture_0' ) );
		bi_check( is_array( $bi_bounded_schema ) && 1000 === mb_strlen( $bi_bounded_schema['data']['parameters']['fixture_0']['description'] ), 'Exact native parameter projection bounds descriptions.' );
	} finally { remove_filter( 'rest_endpoints', $bi_large_schema ); }
	$bi_schema_reader = new ReflectionMethod( Bhranti_Actions::class, 'schema_data' );
	$bi_properties = array(); for ( $index = 0; $index < 26; ++$index ) { $bi_properties['p' . $index] = array( 'type' => 'string', 'sanitize_callback' => $bi_callback ); }
	$bi_partial = $bi_schema_reader->invoke( null, array( 'type' => 'object', 'properties' => $bi_properties, 'enum' => range( 1, 51 ), 'validate_callback' => $bi_callback ) );
	bi_check( 25 === count( $bi_partial['properties'] ) && $bi_partial['truncated'] && ! isset( $bi_partial['enum'], $bi_partial['validate_callback'] ) && false === strpos( wp_json_encode( $bi_partial ), 'callback' ), 'Recursive schema bounds mark omitted properties/enums and drop callbacks.' );
	$bi_deep_schema = array( 'type' => 'string' ); for ( $index = 0; $index < 8; ++$index ) { $bi_deep_schema = array( 'type' => 'array', 'items' => $bi_deep_schema ); }
	$bi_partial = $bi_schema_reader->invoke( null, $bi_deep_schema );
	bi_check( false !== strpos( wp_json_encode( $bi_partial ), 'truncated' ) && 0 === $bi_schema_callbacks, 'Deep schema structures stop at the finite limit without callbacks.' );

	$bi_large = bi_source( str_repeat( 'x', 1048576 ) );
	bi_check( is_array( $bi_large ) && 1 === $bi_large['data']['block_count'] && strlen( $bi_large['data']['outline'][0]['text'] ) <= 240, 'Exactly one MiB of native saved source stays bounded in its outline.' );
	bi_error( bi_source( str_repeat( 'x', 1048577 ) ), 'bhranti_inspect_content', 'Source above the byte bound is rejected before traversal.' );
	$bi_blocks = str_repeat( '<!-- wp:bhranti-inspect-fixture/dynamic /-->', 5000 );
	$bi_many = bi_source( $bi_blocks, array( 'page' => 250, 'per_page' => 20 ) );
	bi_check( is_array( $bi_many ) && 5000 === $bi_many['data']['block_count'] && 20 === count( $bi_many['data']['outline'] ) && '4980' === $bi_many['data']['outline'][0]['path'] && ! $bi_many['data']['has_more'], 'Exactly 5,000 parsed blocks support the last bounded page without rendering.' );
	bi_error( bi_source( $bi_blocks . '<!-- wp:paragraph --><p>Extra</p><!-- /wp:paragraph -->' ), 'bhranti_inspect_limit', 'A 5,001st block exceeds the traversal limit.' );
	$bi_nested = str_repeat( '<!-- wp:group --><div class="wp-block-group">', 64 ) . '<!-- wp:paragraph --><p>Deep text.</p><!-- /wp:paragraph -->' . str_repeat( '</div><!-- /wp:group -->', 64 );
	bi_check( is_array( bi_source( $bi_nested ) ), 'Native parsed nesting depth 64 remains inspectable.' );
	bi_error( bi_source( '<!-- wp:group --><div class="wp-block-group">' . $bi_nested . '</div><!-- /wp:group -->' ), 'bhranti_inspect_limit', 'Native nesting deeper than 64 fails closed.' );
	$bi_prefix = '<!-- wp:paragraph --><p>'; $bi_suffix = '</p><!-- /wp:paragraph -->';
	$bi_selection_base = strlen( serialize_block( parse_blocks( $bi_prefix . $bi_suffix )[0] ) );
	$bi_selection = $bi_prefix . str_repeat( 'x', 24000 - $bi_selection_base ) . $bi_suffix;
	$bi_selected = bi_source( $bi_selection, array( 'block_path' => '0' ) );
	bi_check( is_array( $bi_selected ) && 24000 === strlen( $bi_selected['data']['selected']['markup'] ), 'Exactly 24,000 bytes of selected native block markup are returned without truncation.' );
	bi_error( bi_source( $bi_prefix . str_repeat( 'x', 24001 - $bi_selection_base ) . $bi_suffix, array( 'block_path' => '0' ) ), 'bhranti_inspect_limit', 'Oversized block selection asks for smaller children instead of dumping source.' );

	bi_check( $bi_storage_before === hash( 'sha256', wp_json_encode( array( get_post( $bi_post_id )->to_array(), get_post_meta( $bi_post_id ) ) ) ), 'Every inspection preserves the actual native saved content and metadata.' );
	bi_check( 0 === $bi_renders && 0 === $bi_additional && 0 === $bi_schema_callbacks && 0 === $bi_http && $bi_dispatched > 0, 'Real native reads occurred without dynamic/additional callbacks, network or provider requests.' );
	$bi_events = Bhranti_Audit::events( $bi_owner, 1, $bi_session );
	bi_check( count( $bi_events ) > 0 && false === strpos( wp_json_encode( $bi_events ), 'Useful body text' ) && false === strpos( wp_json_encode( $bi_events ), 'fixture.png' ), 'Inspection audits contain operation identity without raw content.' );
} catch ( Throwable $error ) {
	$bi_failure = $error->getMessage();
} finally {
	remove_filter( 'rest_request_before_callbacks', $bi_request_guard, 10 );
	if ( isset( $bi_revision_query_observer ) ) { remove_filter( 'rest_revision_query', $bi_revision_query_observer ); }
	unset( $GLOBALS['wp_rest_additional_fields']['post']['bhranti_private_fixture'] );
	unset( $GLOBALS['wp_rest_additional_fields']['post-revision']['bhranti_private_revision_fixture'] );
	if ( WP_Block_Type_Registry::get_instance()->is_registered( 'bhranti-inspect-fixture/dynamic' ) ) { unregister_block_type( 'bhranti-inspect-fixture/dynamic' ); }
	wp_set_current_user( $bi_original_user );
	foreach ( $bi_fixture_ids as $bi_id ) {
		$bi_item = get_post( $bi_id );
		if ( $bi_item && $bi_marker === $bi_item->post_title && in_array( (int) $bi_item->post_author, array( $bi_owner, $bi_actor ), true ) ) { wp_delete_post( $bi_id, true ); }
	}
	if ( $bi_created_theme_term && ! get_objects_in_term( $bi_created_theme_term, 'wp_theme' ) ) { wp_delete_term( $bi_created_theme_term, 'wp_theme' ); }
	foreach ( get_posts( array( 'post_type' => 'bhranti_event', 'post_status' => 'private', 'author' => $bi_owner, 'numberposts' => -1 ) ) as $bi_event ) {
		if ( (int) $bi_event->post_parent === $bi_session ) { wp_delete_post( $bi_event->ID, true ); }
	}
	$bi_session_post = $bi_session ? get_post( $bi_session ) : null;
	if ( $bi_session_post && $bi_marker === $bi_session_post->post_title && (int) $bi_session_post->post_author === $bi_owner ) { wp_delete_post( $bi_session, true ); }
	if ( $bi_owner && get_userdata( $bi_owner ) && $bi_marker === get_userdata( $bi_owner )->user_login ) { wp_delete_user( $bi_owner ); }
	if ( is_int( $bi_actor ) && ! $bi_options['bhranti_actor_id'] && get_user_meta( $bi_actor, 'bhranti_managed_actor', true ) ) { wp_delete_user( $bi_actor ); }
	foreach ( $bi_options as $bi_key => $bi_value ) { null === $bi_value ? delete_option( $bi_key ) : update_option( $bi_key, $bi_value ); }
	// Native template discovery may cache theme patterns; remove only cache keys absent before this isolated test.
	foreach ( $wpdb->get_col( 'SELECT option_name FROM ' . $wpdb->options ) as $bi_option_name ) {
		if ( ! isset( $bi_existing_options[ $bi_option_name ] ) && preg_match( '/^_site_transient_(?:timeout_)?wp_theme_files_patterns-[a-f0-9]+$/', $bi_option_name ) ) { delete_option( $bi_option_name ); }
	}
	remove_filter( 'pre_http_request', $bi_http_guard );
	remove_filter( 'pre_wp_mail', $bi_mail_guard );
}
foreach ( $bi_fixture_ids as $bi_id ) { if ( get_post( $bi_id ) ) { $bi_failure = 'An isolated content fixture was not removed.'; } }
if ( $bi_session && get_post( $bi_session ) ) { $bi_failure = 'The isolated conversation fixture was not removed.'; }
if ( $bi_owner && get_userdata( $bi_owner ) ) { $bi_failure = 'The isolated requesting user was not removed.'; }
foreach ( $bi_existing_tables as $bi_table => $bi_hash ) {
	if ( $bi_hash !== hash( 'sha256', wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . $wpdb->$bi_table . ' ORDER BY 1,2', ARRAY_A ) ) ) ) { $bi_failure = 'Fixture cleanup changed existing native ' . $bi_table . ' data.'; }
	else { ++$bi_checks; }
}
$bi_options_after = array();
foreach ( $wpdb->get_results( 'SELECT option_name,option_value,autoload FROM ' . $wpdb->options, ARRAY_A ) as $bi_option ) {
	$bi_options_after[ $bi_option['option_name'] ] = array( $bi_option['option_value'], $bi_option['autoload'] );
}
ksort( $bi_existing_options ); ksort( $bi_options_after );
if ( $bi_existing_options !== $bi_options_after ) { $bi_failure = 'Fixture cleanup changed existing options or left unowned new settings.'; }
else { ++$bi_checks; }
if ( $bi_http || $bi_mail ) { $bi_failure = 'Native inspector QA attempted HTTP or mail during setup, inspection or cleanup.'; }
if ( $bi_failure ) { fwrite( STDERR, 'FAIL: ' . $bi_failure . "\n" ); exit( 1 ); }
echo 'PASS: ' . $bi_checks . " native inspector checks; unique fixtures cleaned, no content actions/rendering/network/AI.\n";
