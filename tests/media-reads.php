<?php
/**
 * Native attachment REST reads in a disposable private WordPress runtime.
 *
 * BHRANTI_MEDIA_TEST_WP_ROOT=/private/tmp/bhranti-media-reads-qa-20261014 php tests/media-reads.php
 *
 * @package Bhranti
 */

$bmr_root = realpath( getenv( 'BHRANTI_MEDIA_TEST_WP_ROOT' ) ?: '' );
if ( '/private/tmp/bhranti-media-reads-qa-20261014' !== $bmr_root ) {
	fwrite( STDERR, "Use only the dedicated private media-read QA runtime.\n" );
	exit( 1 );
}
define( 'WP_USE_THEMES', false );
require $bmr_root . '/wp-load.php';
if ( 'https://bhranti-checker.invalid' !== get_option( 'home' ) || get_option( 'active_plugins' ) || ! DISABLE_WP_CRON ) {
	fwrite( STDERR, "The isolated offline runtime configuration is required.\n" );
	exit( 1 );
}
require dirname( __DIR__ ) . '/bhranti.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
Bhranti_Workspace::register_storage();
Bhranti_Audit::register_storage();

$bmr_checks = 0;
$bmr_http   = 0;
$bmr_owner  = 0;
$bmr_actor  = 0;
$bmr_post   = 0;
$bmr_session = 0;
$bmr_file    = '';
$bmr_marker  = 'bhranti-media-read-' . str_replace( '-', '', wp_generate_uuid4() );
$bmr_original_user = get_current_user_id();
$bmr_role_before   = get_option( 'wp_user_roles' );
$bmr_options_before = array();
foreach ( array( 'bhranti_actor_id', 'bhranti_role_owned', 'bhranti_actor_setup_lock' ) as $bmr_key ) {
	$bmr_options_before[ $bmr_key ] = get_option( $bmr_key, null );
}
$bmr_http_guard = static function () {
	++$GLOBALS['bmr_http'];
	return new WP_Error( 'bhranti_media_qa_offline', 'Outbound requests are disabled.' );
};
add_filter( 'pre_http_request', $bmr_http_guard );
$bmr_failure = null;

/** Assert behavior without printing media, credentials or arbitrary payloads. */
function bmr_check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
	++$GLOBALS['bmr_checks'];
}

/** Read through the actual owned action pipeline. */
function bmr_read( $path, $params = array() ) {
	return Bhranti_Actions::execute(
		array( 'tool' => 'wp_request', 'args' => array( 'method' => 'GET', 'path' => $path, 'params' => $params ) ),
		$GLOBALS['bmr_owner'],
		$GLOBALS['bmr_session']
	);
}

/** Return exact core REST data for comparison, without bypassing permissions. */
function bmr_native( $path, $params = array() ) {
	$request = new WP_REST_Request( 'GET', $path );
	$request->set_query_params( $params );
	$response = rest_do_request( $request );
	bmr_check( 200 === $response->get_status(), 'Native core attachment read succeeds.' );
	return $response->get_data();
}

/** Make a simple original PNG fixture without an external image dependency. */
function bmr_png() {
	$chunk = static function ( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	};
	$rows  = str_repeat( "\0" . str_repeat( "\x24\x80\x90\xff", 640 ), 480 );
	return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', 640, 480, 8, 6, 0, 0, 0 ) ) . $chunk( 'IDAT', gzcompress( $rows ) ) . $chunk( 'IEND', '' );
}

try {
	$default_args = array(
		'qa_false'  => array( 'type' => 'boolean', 'default' => false ),
		'qa_zero'   => array( 'type' => 'integer', 'default' => 0 ),
		'qa_empty'  => array( 'type' => 'string', 'default' => '' ),
		'qa_null'   => array( 'type' => array( 'string', 'null' ), 'default' => null ),
		'qa_absent' => array( 'type' => 'string' ),
	);
	rest_get_server();
	register_rest_route(
		'bhranti-media-qa-v1',
		'/defaults',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => static function ( $request ) {
				return $request->get_default_params();
			},
			'args'                => $default_args,
		)
	);
	$native_defaults = rest_do_request( new WP_REST_Request( 'GET', '/bhranti-media-qa-v1/defaults' ) );
	bmr_check( 200 === $native_defaults->get_status(), 'Native REST defaults dispatch succeeds.' );
	$expected_defaults = array( 'qa_false' => false, 'qa_zero' => 0, 'qa_empty' => '' );
	bmr_check( $expected_defaults === $native_defaults->get_data(), 'Native REST preserves false, zero and empty string while skipping null and absent defaults.' );
	$defaults_method = new ReflectionMethod( 'Bhranti_Actions', 'defaults' );
	$defaults_method->setAccessible( true );
	bmr_check( $native_defaults->get_data() === $defaults_method->invoke( null, array( 'args' => $default_args ) ), 'Action defaults match the native nullable-default contract.' );
	$bmr_owner = wp_insert_user( array( 'user_login' => $bmr_marker, 'user_pass' => wp_generate_password( 40 ), 'user_email' => $bmr_marker . '@example.invalid', 'role' => 'administrator' ) );
	bmr_check( is_int( $bmr_owner ) && $bmr_owner > 1, 'Fresh isolated human fixture created.' );
	wp_set_current_user( $bmr_owner );
	$bmr_session = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $bmr_owner, 'post_title' => $bmr_marker ), true );
	bmr_check( is_int( $bmr_session ), 'Fresh private owned conversation created.' );
	$bmr_actor = Bhranti_Actions::ensure_actor();
	bmr_check( is_int( $bmr_actor ), 'Native managed actor available in isolated QA.' );
	$upload = wp_upload_bits( $bmr_marker . '.png', null, bmr_png() );
	bmr_check( empty( $upload['error'] ), 'Original bounded PNG fixture uploaded locally.' );
	$bmr_file = $upload['file'];
	$bmr_post = wp_insert_attachment( array( 'post_title' => $bmr_marker, 'post_author' => $bmr_owner, 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $bmr_file, 0, true );
	bmr_check( is_int( $bmr_post ), 'Native attachment fixture inserted.' );
	$metadata = wp_generate_attachment_metadata( $bmr_post, $bmr_file );
	wp_update_attachment_metadata( $bmr_post, $metadata );
	$before = hash( 'sha256', wp_json_encode( array( get_post( $bmr_post ), get_post_meta( $bmr_post ) ) ) );
	$path   = '/wp/v2/media/' . $bmr_post;
	$native = bmr_native( $path );
	$result = bmr_read( $path );
	bmr_check( is_array( $result ), 'Actual Bhranti item read succeeds.' );
	$data = $result['data'];
	foreach ( array( 'id', 'source_url', 'media_type', 'mime_type' ) as $key ) {
		bmr_check( $native[ $key ] === $data[ $key ], 'Public native attachment reference preserved: ' . $key );
	}
	foreach ( array( 'width', 'height' ) as $key ) {
		bmr_check( 640 === $native['media_details']['width'] && 480 === $native['media_details']['height'] && $native['media_details'][ $key ] === $data['media_details'][ $key ], 'Actual native image dimensions preserved.' );
	}
	bmr_check( ! isset( $data['media_details']['file'] ) && ! isset( $data['media_details']['image_meta'] ) && ! isset( $data['meta'] ), 'File paths, EXIF and arbitrary metadata excluded.' );
	bmr_check( '' === $result['action_id'] && ! $result['rollback_available'], 'Reads create no write or rollback record.' );
	foreach ( $data['media_details']['sizes'] as $name => $size ) {
		bmr_check( $native['media_details']['sizes'][ $name ]['source_url'] === $size['source_url'], 'Native size URL preserved.' );
		bmr_check( ! isset( $size['file'] ) && ! array_diff( array_keys( $size ), array( 'source_url', 'mime_type', 'width', 'height' ) ), 'Each size contains only public fields.' );
	}
	$list = bmr_read( '/wp/v2/media', array( 'include' => array( $bmr_post ) ) );
	bmr_check( is_array( $list ) && 1 === count( $list['data'] ) && $list['data'][0]['source_url'] === $native['source_url'], 'Native collection exposes the same usable upload reference' . ( is_wp_error( $list ) ? ' (' . $list->get_error_code() . ')' : ' (count ' . count( $list['data'] ?? array() ) . ')' ) . '.' );
	$fields = bmr_read( $path, array( '_fields' => 'id,source_url,media_details.width,media_details.sizes.thumbnail.source_url' ) );
	bmr_check( is_array( $fields ) && array( 'id', 'source_url', 'media_details' ) === array_keys( $fields['data'] ), 'Existing native _fields projection runs after safe media projection.' );
	bmr_check( $fields['data']['source_url'] === $native['source_url'] && array( 'width', 'sizes' ) === array_keys( $fields['data']['media_details'] ), 'Dotted native field selection retains requested public data.' );
	$list_fields = bmr_read( '/wp/v2/media', array( 'include' => array( $bmr_post ), '_fields' => 'id,source_url,media_details.width' ) );
	bmr_check( is_array( $list_fields ) && array( 'id', 'source_url', 'media_details' ) === array_keys( $list_fields['data'][0] ) && array( 'width' => 640 ) === $list_fields['data'][0]['media_details'], 'Native collection _fields retains only requested usable public media data.' );
	$filter = static function ( $response, $post ) use ( $bmr_post, $native ) {
		if ( $post->ID !== $bmr_post ) {
			return $response;
		}
		$data = $response->get_data();
		$data['source_url'] = 'https://user:private@media.example.invalid/image.png?signature=private#private';
		$data['media_details']['file'] = '/private/server/location';
		$data['media_details']['image_meta'] = array( 'private' => 'private' );
		$data['media_details']['sizes'] = array();
		for ( $index = 0; $index < 25; ++$index ) {
			$data['media_details']['sizes'][ 'qa_' . $index ] = array( 'file' => '/private/path', 'source_url' => $native['source_url'] . '?secret=private#private', 'width' => 100, 'height' => 80, 'mime_type' => 'image/png', 'image_meta' => array( 'private' => 'private' ) );
		}
		$response->set_data( $data );
		return $response;
	};
	add_filter( 'rest_prepare_attachment', $filter, 10, 2 );
	$bounded = bmr_read( $path );
	remove_filter( 'rest_prepare_attachment', $filter, 10 );
	bmr_check( 'https://media.example.invalid/image.png' === $bounded['data']['source_url'], 'Native media URL credentials, query and fragment removed.' );
	bmr_check( 20 === count( $bounded['data']['media_details']['sizes'] ), 'Size collections are bounded to twenty.' );
	bmr_check( false === strpos( wp_json_encode( $bounded['data']['media_details'] ), 'private' ), 'Nested private metadata and query tokens are excluded.' );
	foreach ( array( 'file:///private/test.png', 'javascript:alert(1)', str_repeat( 'x', 2049 ) ) as $bad_url ) {
		$bad_filter = static function ( $response, $post ) use ( $bmr_post, $bad_url ) {
			if ( $post->ID === $bmr_post ) {
				$data = $response->get_data();
				$data['source_url'] = $bad_url;
				$data['media_type'] = array();
				$data['mime_type'] = "image/png\nprivate";
				$data['media_details']['width'] = 32769;
				$data['media_details']['height'] = '480';
				$response->set_data( $data );
			}
			return $response;
		};
		add_filter( 'rest_prepare_attachment', $bad_filter, 10, 2 );
		$bad = bmr_read( $path );
		remove_filter( 'rest_prepare_attachment', $bad_filter, 10 );
		bmr_check( is_array( $bad ) && ! array_intersect( array( 'source_url', 'mime_type', 'media_type' ), array_keys( $bad['data'] ) ) && ! isset( $bad['data']['media_details']['width'] ) && ! isset( $bad['data']['media_details']['height'] ), 'Malformed media fields are omitted without a failed normal read.' );
	}
	$too_many = bmr_read( '/wp/v2/media', array( 'per_page' => 21 ) );
	bmr_check( is_wp_error( $too_many ), 'Existing twenty-item read limit remains authoritative.' );
	foreach ( array( $bmr_owner, $bmr_actor ) as $denied_user ) {
		$deny = static function ( $caps, $requested, $args, $user ) use ( $denied_user ) {
			if ( $user->ID === $denied_user ) {
				foreach ( array( 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'upload_files' ) as $key ) {
					$caps[ $key ] = false;
				}
			}
			return $caps;
		};
		add_filter( 'user_has_cap', $deny, 100, 4 );
		$denied = bmr_read( $path, array( 'context' => 'edit', '_fields' => 'id,source_url' ) );
		remove_filter( 'user_has_cap', $deny, 100 );
		bmr_check( is_wp_error( $denied ) && 'bhranti_action_permission' === $denied->get_error_code(), 'Human and actor native media permissions remain mandatory.' );
	}
	bmr_check( $before === hash( 'sha256', wp_json_encode( array( get_post( $bmr_post ), get_post_meta( $bmr_post ) ) ) ), 'All reads preserve native attachment storage.' );
	bmr_check( 0 === $bmr_http, 'No network or AI requests occurred.' );
} catch ( Throwable $error ) {
	$bmr_failure = $error->getMessage();
} finally {
	wp_set_current_user( $bmr_original_user );
	if ( $bmr_post && get_post( $bmr_post ) && get_post( $bmr_post )->post_title === $bmr_marker ) {
		wp_delete_attachment( $bmr_post, true );
	}
	if ( $bmr_file && is_file( $bmr_file ) && false !== strpos( basename( $bmr_file ), $bmr_marker ) ) {
		wp_delete_file( $bmr_file );
	}
	foreach ( get_posts( array( 'post_type' => 'bhranti_event', 'post_status' => 'private', 'author' => $bmr_owner, 'numberposts' => -1 ) ) as $event ) {
		wp_delete_post( $event->ID, true );
	}
	if ( $bmr_session && get_post( $bmr_session ) && (int) get_post( $bmr_session )->post_author === $bmr_owner ) {
		wp_delete_post( $bmr_session, true );
	}
	if ( $bmr_owner && get_userdata( $bmr_owner ) && get_userdata( $bmr_owner )->user_login === $bmr_marker ) {
		wp_delete_user( $bmr_owner );
	}
	if ( $bmr_actor && ! $bmr_options_before['bhranti_actor_id'] && get_user_meta( $bmr_actor, 'bhranti_managed_actor', true ) ) {
		wp_delete_user( $bmr_actor );
	}
	update_option( 'wp_user_roles', $bmr_role_before );
	foreach ( $bmr_options_before as $key => $value ) {
		if ( null === $value ) {
			delete_option( $key );
		} else {
			update_option( $key, $value );
		}
	}
	remove_filter( 'pre_http_request', $bmr_http_guard );
}
if ( $bmr_session && get_post( $bmr_session ) ) {
	$bmr_failure = 'The isolated conversation fixture was not removed.';
}
if ( $bmr_post && get_post( $bmr_post ) ) {
	$bmr_failure = 'The isolated attachment fixture was not removed.';
}
if ( $bmr_failure ) {
	fwrite( STDERR, 'FAIL: ' . $bmr_failure . "\n" );
	exit( 1 );
}
echo 'PASS: ' . $bmr_checks . " native media-read checks; isolated fixtures cleaned, no network or AI.\n";
