<?php
/**
 * Native KSES preservation regression on uniquely marked disposable posts.
 *
 * BHRANTI_PRESERVATION_TEST_WP_ROOT=/private/tmp/bhranti-working-demo-... \
 * BHRANTI_PRESERVATION_ALLOW_MUTATION=1 php tests/content-preservation-native.php
 *
 * Skips the installed Bhranti copy in this process and loads only the current
 * Actions class. No deployment, chats, AI, provider settings or existing posts.
 */

$root = realpath( getenv( 'BHRANTI_PRESERVATION_TEST_WP_ROOT' ) ?: '' );
if ( 'cli' !== PHP_SAPI || '1' !== getenv( 'BHRANTI_PRESERVATION_ALLOW_MUTATION' ) || ! $root || 0 !== strpos( $root, '/private/tmp/bhranti-working-demo-' ) || ! is_file( $root . '/.bhranti-working-demo.json' ) ) {
	fwrite( STDERR, "An explicitly authorized, disposable native demonstration fixture is required.\n" );
	exit( 1 );
}

$http = array();
$GLOBALS['wp_filter']['option_active_plugins'][PHP_INT_MAX][] = array(
	'function' => static function ( $plugins ) { return array_values( array_diff( $plugins, array( 'bhranti/bhranti.php' ) ) ); }, 'accepted_args' => 1,
);
$GLOBALS['wp_filter']['pre_http_request'][PHP_INT_MAX][] = array(
	'function' => static function ( $pre, $args, $url ) use ( &$http ) { $http[] = true; return new WP_Error( 'preservation_test_offline' ); }, 'accepted_args' => 3,
);
$GLOBALS['wp_filter']['pre_wp_mail'][PHP_INT_MAX][] = array( 'function' => static function () { return true; }, 'accepted_args' => 1 );
define( 'WP_USE_THEMES', false );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
register_shutdown_function( static function () {
	$error = error_get_last();
	if ( $error && E_ERROR === $error['type'] ) { fwrite( STDERR, 'Fixture fatal at ' . $error['file'] . ':' . $error['line'] . "\n" ); }
} );
require $root . '/wp-load.php';
if ( realpath( ABSPATH ) !== $root || 'http://127.0.0.1:9418' !== home_url() || ! DISABLE_WP_CRON || class_exists( 'Bhranti_Actions', false ) ) {
	throw new RuntimeException( 'The isolated source-class test guard failed.' );
}
require dirname( __DIR__ ) . '/includes/class-bhranti-actions.php';

$checks = 0;
$post_id = 0;
$acting = false;
$requests = 0;
$marker = 'bhranti-preservation-' . wp_generate_uuid4();
$original_user = get_current_user_id();
$guard = new ReflectionMethod( Bhranti_Actions::class, 'preserves_existing_content' );
$spacing = new ReflectionMethod( Bhranti_Actions::class, 'void_tag_spacing' );
if ( PHP_VERSION_ID < 80100 ) { $guard->setAccessible( true ); $spacing->setAccessible( true ); }
add_filter( 'user_has_cap', static function ( $allcaps, $caps, $args, $user ) use ( &$acting ) {
	if ( $acting && 1 === (int) $user->ID ) { $allcaps['unfiltered_html'] = false; }
	return $allcaps;
}, PHP_INT_MAX, 4 );

function cp_check( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); throw new RuntimeException( $message ); }
	++$GLOBALS['checks'];
}
function cp_seed( $fields = array() ) {
	global $acting, $post_id, $marker;
	$acting = false; wp_set_current_user( 1 ); kses_init();
	$fields = array_merge( array( 'post_title' => $marker, 'post_content' => '<p>Existing safe text.</p>', 'post_excerpt' => 'Existing excerpt.', 'post_content_filtered' => '', 'post_status' => 'draft', 'post_type' => 'post' ), $fields );
	if ( $post_id ) { $fields['ID'] = $post_id; }
	$result = wp_insert_post( wp_slash( $fields ), true );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() ); }
	$post_id = $result;
	update_post_meta( $post_id, '_bhranti_preservation_fixture', $marker );
	$acting = true; kses_init();
}
function cp_edit( $title ) {
	global $guard, $post_id, $requests;
	$allowed = $guard->invoke( null, array( 'class' => 'WP_REST_Posts_Controller' ), array( 'id' => $post_id ), 1 );
	if ( is_wp_error( $allowed ) ) { return $allowed; }
	$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
	$request->set_body_params( array( 'title' => $title ) );
	++$requests;
	return rest_do_request( $request );
}
function cp_state() {
	clean_post_cache( $GLOBALS['post_id'] );
	return get_post( $GLOBALS['post_id'] )->to_array();
}

try {
	$image = '<!-- wp:image --><figure class="wp-block-image"><img src="http://127.0.0.1:9418/example.jpg" alt="A plain sample" class="wp-image-7"/></figure><!-- /wp:image -->';
	cp_seed( array( 'post_content' => $image ) );
	cp_check( $image !== wp_kses( $image, 'post' ), 'The native KSES fixture reproduces self-closing image spacing.' );
	$first = cp_edit( 'First focused title edit' );
	cp_check( ! is_wp_error( $first ) && 200 === $first->get_status() && 'First focused title edit' === get_post( $post_id )->post_title, 'The actual preservation guard allows a native title edit on the existing image.' );
	cp_check( wp_kses( $image, 'post' ) === get_post( $post_id )->post_content && 'draft' === get_post( $post_id )->post_status, 'Native WordPress preserves the image with only its harmless spacing change.' );
	$second = cp_edit( 'Second focused title edit' );
	cp_check( ! is_wp_error( $second ) && 200 === $second->get_status() && 'Second focused title edit' === get_post( $post_id )->post_title, 'A repeated native title edit remains allowed.' );

	foreach ( array(
		'post_content' => array( '<script>window.neverExecute=1</script>', '<img src="/example.jpg" alt="Sample" onerror="neverExecute()"/>', '<p style="width:expression(neverExecute())">Unsafe style</p>', '<a href="javascript:neverExecute()">Unsafe link</a>', '<img src="/example.jpg" alt="Sample" unknown-attribute="must-stay"/>' ),
		'post_excerpt' => array( '<script>neverExecute()</script>', '<img src="/example.jpg" alt="Sample" onerror="neverExecute()"/>' ),
		'post_title' => array( '<script>neverExecute()</script>', '<img src="/example.jpg" alt="Sample"/>' ),
		'post_content_filtered' => array( '<script>neverExecute()</script>' ),
	) as $field => $cases ) {
		foreach ( $cases as $case => $unsafe ) {
			cp_seed( array( $field => $unsafe ) );
			$before = cp_state(); $count = $requests;
			$result = cp_edit( 'This rejected title must not be saved' );
			cp_check( is_wp_error( $result ) && 'bhranti_action_preservation' === $result->get_error_code(), 'Meaningful native KSES changes remain rejected in ' . $field . ' case ' . $case . '.' );
			cp_check( $requests === $count && $before === cp_state() && 'draft' === get_post( $post_id )->post_status, 'Rejected edits preserve every saved draft field and never reach native mutation.' );
		}
	}

	foreach ( array( ' ', "\t", "\r\n", "\f" ) as $space ) {
		$quoted = '<img alt="A > B and literal /> text" src="/example.jpg"';
		cp_check( $spacing->invoke( null, $quoted . '/>' ) === $spacing->invoke( null, $quoted . $space . '/>' ), 'Only the closing void-tag whitespace is ignored, including quoted greater-than signs.' );
	}
	foreach ( array( array( '<div/>', '<div />' ), array( '<img-custom/>', '<img-custom />' ), array( '<img alt="literal/>"/>', '<img alt="literal />"/>' ), array( 'Plain/>text', 'Plain />text' ), array( '<img class="a"/>', '<img class="b" />' ) ) as $pair ) {
		cp_check( $spacing->invoke( null, $pair[0] ) !== $spacing->invoke( null, $pair[1] ), 'Non-void tags, attribute bytes and ordinary text stay significant.' );
	}
	cp_check( empty( $http ), 'The regression makes no HTTP or AI requests.' );
} finally {
	$acting = false; wp_set_current_user( 1 ); kses_init();
	if ( $post_id && $marker === get_post_meta( $post_id, '_bhranti_preservation_fixture', true ) ) { wp_delete_post( $post_id, true ); }
	wp_set_current_user( $original_user ); kses_init();
}
cp_check( ! get_post( $post_id ), 'Only the marked temporary draft and its native revisions are removed.' );
echo 'PASS ' . $checks . " native preservation checks; no AI, provider changes, chats or existing-post edits.\n";
