<?php
/**
 * Native image generation checks using disposable data on the selected site.
 *
 * Run after deployment with BHRANTI_TEST_WP_ROOT and BHRANTI_TEST_ALLOW_MUTATION=1.
 *
 * @package Bhranti
 */

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

define( 'BHRANTI_QA_SUPPORT_ONLY', true );
require __DIR__ . '/integration.php';
if ( ! class_exists( 'Bhranti_Media' ) || realpath( BHRANTI_FILE ) !== realpath( WP_PLUGIN_DIR . '/bhranti/bhranti.php' ) ) {
	fwrite( STDERR, "Deploy the media-capable Bhranti plugin on https://bhranti.wp.local/ before running these checks.\n" );
	exit( 1 );
}

/** An offline image provider uses the actual native SDK and File DTO. */
final class Bhranti_QA_Image_Provider extends AbstractProvider {
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata( 'bhranti-media-qa', 'Bhranti offline image QA', ProviderTypeEnum::server(), null, null );
	}
	protected static function createModel( ModelMetadata $metadata, ProviderMetadata $provider ): ModelInterface {
		return new Bhranti_QA_Image_Model( $metadata, $provider );
	}
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new class implements ProviderAvailabilityInterface {
			public function isConfigured(): bool {
				return true;
			}
		};
	}
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new class implements ModelMetadataDirectoryInterface {
			public function listModelMetadata(): array {
				return array( $this->getModelMetadata( 'local-image' ) );
			}
			public function hasModelMetadata( string $id ): bool {
				return 'local-image' === $id;
			}
			public function getModelMetadata( string $id ): ModelMetadata {
				return new ModelMetadata(
					$id,
					'Local image',
					array( CapabilityEnum::imageGeneration() ),
					array(
						new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
						new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::image() ) ) ),
					)
				);
			}
		};
	}
}

/** Record real model calls, including the human identity and prior durable audit. */
final class Bhranti_QA_Image_Model extends AbstractApiBasedModel implements ImageGenerationModelInterface {
	public static $calls = array();
	public static $image = null;
	public static $fail = false;
	public static $before_reply = null;
	public static $session = 0;
	public function generateImageResult( array $prompt ): GenerativeAiResult {
		self::$calls[] = array(
			'prompt' => $prompt,
			'config' => $this->getConfig()->toArray(),
			'user'   => get_current_user_id(),
			'events' => array_column( bhranti_media_events( self::$session ), 'type' ),
		);
		if ( is_callable( self::$before_reply ) ) {
			$callback = self::$before_reply;
			self::$before_reply = null;
			$callback();
		}
		if ( self::$fail ) {
			throw new RuntimeException( 'bhranti-media-provider-private-secret' );
		}
		return new GenerativeAiResult(
			'local-image-result',
			array( new Candidate( new ModelMessage( array( new MessagePart( self::$image ) ) ), FinishReasonEnum::stop() ) ),
			new TokenUsage( 2, 3, 5 ),
			$this->providerMetadata(),
			$this->metadata()
		);
	}
}

$media_checks = 0;
$media_users = array();
$media_sessions = array();
$media_attachments = array();
$media_options = array();
$media_uploads = array();
$media_http = array();
$media_connectors = array();
$media_failure = null;
$media_preserved = false;
$media_original_user = get_current_user_id();
$media_marker = 'bhranti-media-qa-' . str_replace( '-', '', wp_generate_uuid4() );
$media_deny_cap = array();
$media_upload_mode = '';
$media_audit_fail = '';
$media_bad_metadata = false;
$media_sentinel = sys_get_temp_dir() . '/' . $media_marker . '.txt';
$media_checkpoint = sys_get_temp_dir() . '/' . $media_marker . '.checkpoint.json';
$media_registry_property = new ReflectionProperty( AiClient::class, 'defaultRegistry' );
$media_registry_property->setAccessible( true );
$media_original_registry = AiClient::defaultRegistry();
$media_original_dispatcher = AiClient::getEventDispatcher();
$media_original_cache = AiClient::getCache();

/** Assert observable behavior without printing image data or prompts. */
function bhranti_media_check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	++$GLOBALS['media_checks'];
}

/** Dispatch through native REST routing and permission/schema validation. */
function bhranti_media_rest( $method, $path, $body = array(), $json = false ) {
	$request = new WP_REST_Request( $method, '/bhranti/v1' . $path );
	if ( $json ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	} else {
		$request->set_body_params( $body );
	}
	return rest_do_request( $request );
}

/** Use the same reviewed envelope as the agent. */
function bhranti_media_command( $args ) {
	return array( 'tool' => 'generate_image', 'args' => $args );
}

/** Preserve raw serialization, absence and autoload mode. */
function bhranti_media_raw_option( $name ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name=%s", $name ), ARRAY_A );
}

/** Restore exact option bytes after temporary policy changes. */
function bhranti_media_restore_option( $name, $raw ) {
	global $wpdb;
	if ( null === $raw ) {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );
	} elseif ( null === bhranti_media_raw_option( $name ) ) {
		$wpdb->insert( $wpdb->options, array_merge( array( 'option_name' => $name ), $raw ), array( '%s', '%s', '%s' ) );
	} else {
		$wpdb->update( $wpdb->options, $raw, array( 'option_name' => $name ), array( '%s', '%s' ), array( '%s' ) );
	}
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
}

/** Keep private recovery evidence before mutations, even if PHP cannot run finally. */
function bhranti_media_checkpoint() {
	$pending = $GLOBALS['media_checkpoint'] . '.pending';
	$handle = fopen( $pending, 'x' );
	bhranti_media_check( false !== $handle, 'Private recovery checkpoint is exclusively created' );
	try {
		bhranti_media_check( chmod( $pending, 0600 ), 'Recovery checkpoint permissions are private' );
		$state = wp_json_encode( array(
			'site'           => home_url( '/' ),
			'marker'         => $GLOBALS['media_marker'],
			'options'        => $GLOBALS['media_options'],
			'users'          => $GLOBALS['media_users'],
			'sessions'       => $GLOBALS['media_sessions'],
			'attachments'    => $GLOBALS['media_attachments'],
			'uploads'        => $GLOBALS['media_uploads'],
			'content_before' => $GLOBALS['media_before_content'],
			'files_before'   => $GLOBALS['media_before_files'],
			'identity_before' => $GLOBALS['media_before_identity'],
	) );
		bhranti_media_check( is_string( $state ) && strlen( $state ) === fwrite( $handle, $state ) && fflush( $handle ), 'Recovery checkpoint is fully persisted before proceeding' );
	} finally {
		fclose( $handle );
	}
	bhranti_media_check( rename( $pending, $GLOBALS['media_checkpoint'] ), 'Recovery checkpoint atomically replaces its prior fixture state' );
}

/** Toggle only media policy through the human Settings REST contract. */
function bhranti_media_policy( $allowed ) {
	$stored = bhranti_media_rest( 'GET', '/settings' )->get_data();
	$stored['config']['permissions']['manage_media'] = $allowed;
	$response = bhranti_media_rest( 'POST', '/settings', $stored, true );
	bhranti_media_check( 200 === $response->get_status(), 'Temporary media policy saves through native Settings REST' );
}

/** Read all durable events in this owned fixture conversation. */
function bhranti_media_events( $session ) {
	$events = array();
	foreach ( get_posts( array( 'post_type' => 'bhranti_event', 'post_status' => 'private', 'post_parent' => $session, 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $post ) {
		$events[] = get_post_meta( $post->ID, 'bhranti_event_data', true );
	}
	return $events;
}

/** Fingerprint ordinary site content, including all attachment metadata. */
function bhranti_media_content() {
	global $wpdb;
	return hash( 'sha256', wp_json_encode( array(
		$wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_type NOT IN ('bhranti_event','bhranti_session') ORDER BY ID", ARRAY_A ),
		$wpdb->get_results( "SELECT m.* FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON m.post_id=p.ID WHERE p.post_type NOT IN ('bhranti_event','bhranti_session') ORDER BY meta_id", ARRAY_A ),
		$wpdb->get_results( "SELECT * FROM {$wpdb->comments} ORDER BY comment_ID", ARRAY_A ),
		$wpdb->get_results( "SELECT * FROM {$wpdb->commentmeta} ORDER BY meta_id", ARRAY_A ),
		$wpdb->get_results( "SELECT * FROM {$wpdb->term_relationships} ORDER BY object_id,term_taxonomy_id", ARRAY_A ),
	) ) );
}

/** Fingerprint existing file bytes and links without following linked directories. */
function bhranti_media_files() {
	$upload = wp_upload_dir( null, false );
	$root = trailingslashit( $upload['basedir'] );
	$files = array();
	if ( is_dir( $root ) ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) {
			$path = $entry->getPathname();
			$files[ substr( $path, strlen( $root ) ) ] = $entry->isLink() ? 'link:' . readlink( $path ) : hash_file( 'sha256', $path );
		}
	}
	ksort( $files );
	return $files;
}

/** Keep the selected site's existing managed account and conversation exact. */
function bhranti_media_preserved_identity() {
	global $wpdb;
	return hash( 'sha256', wp_json_encode( array(
		$wpdb->get_row( "SELECT * FROM {$wpdb->users} WHERE ID=11", ARRAY_A ),
		$wpdb->get_results( "SELECT * FROM {$wpdb->usermeta} WHERE user_id=11 ORDER BY umeta_id", ARRAY_A ),
		$wpdb->get_row( "SELECT * FROM {$wpdb->posts} WHERE ID=742", ARRAY_A ),
		$wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} WHERE post_id=742 ORDER BY meta_id", ARRAY_A ),
	) ) );
}

/** Reject before writes and count whether native image generation occurred. */
function bhranti_media_reject( $command, $owner, $session, $label, $code, $calls = 0 ) {
	$before_content = bhranti_media_content();
	$before_files = bhranti_media_files();
	$before_calls = count( Bhranti_QA_Image_Model::$calls );
	$before_user = get_current_user_id();
	Bhranti_QA_Image_Model::$session = $session;
	$result = Bhranti_Actions::execute( $command, $owner, $session );
	bhranti_media_check( is_wp_error( $result ) && $code === $result->get_error_code(), $label . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );
	bhranti_media_check( $before_calls + $calls === count( Bhranti_QA_Image_Model::$calls ), $label . ' has the expected native generation count' );
	bhranti_media_check( $before_content === bhranti_media_content() && $before_files === bhranti_media_files(), $label . ' saves no attachment or upload' );
	bhranti_media_check( $before_user === get_current_user_id(), $label . ' restores the authenticated identity' );
	bhranti_media_check( false === strpos( wp_json_encode( array( $result->get_error_messages(), $result->get_error_data() ) ), 'provider-private-secret' ), $label . ' hides provider payloads' );
	return $result;
}

/** Construct a tiny valid PNG fixture; large headers exercise dimension limits. */
function bhranti_media_png( $width = 2, $height = 2 ) {
	$chunk = static function ( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	};
	$header = pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 );
	return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', $header ) . $chunk( 'IDAT', gzcompress( "\0\xFF\0\0\0\xFF\0\0\0\0\xFF\xFF\xFF\xFF" ) ) . $chunk( 'IEND', '' );
}

$media_http_guard = static function ( $response, $args, $url ) {
	$GLOBALS['media_http'][] = $url;
	return new WP_Error( 'bhranti_media_qa_http_denied', 'Image QA is offline.' );
};
$media_cap_guard = static function ( $caps, $required, $args, $user ) {
	foreach ( $GLOBALS['media_deny_cap'][ $user->ID ] ?? array() as $cap ) {
		$caps[ $cap ] = false;
	}
	return $caps;
};
$media_upload_guard = static function ( $upload ) {
	if ( ! is_array( $upload ) || ! preg_match( '/\Abhranti-[a-f0-9-]{36}\.(?:png|jpg|webp)\z/', $upload['name'] ?? '' ) ) {
		return 'Image QA permits only newly generated UUID images.';
	}
	$directory = wp_upload_dir( null, false );
	$GLOBALS['media_uploads'][ $upload['name'] ] = trailingslashit( $directory['path'] ) . $upload['name'];
	bhranti_media_checkpoint();
	return 'upload_error' === $GLOBALS['media_upload_mode'] ? 'bhranti-media-provider-private-secret' : $upload;
};
$media_upload_result = static function ( $upload ) {
	if ( is_array( $upload ) && in_array( $upload['file'] ?? '', $GLOBALS['media_uploads'], true ) ) {
		if ( 'corrupt' === $GLOBALS['media_upload_mode'] ) {
			file_put_contents( $upload['file'], 'x', FILE_APPEND );
		} elseif ( 'unexpected_path' === $GLOBALS['media_upload_mode'] ) {
			$upload['file'] = $GLOBALS['media_sentinel'];
		}
	}
	return $upload;
};
$media_metadata_guard = static function ( $metadata, $id ) {
	$record = get_post_meta( $id, 'bhranti_generated_media', true );
	return $GLOBALS['media_bad_metadata'] && in_array( $record['session_id'] ?? 0, $GLOBALS['media_sessions'], true ) ? array() : $metadata;
};
$media_audit_guard = static function ( $empty, $post ) {
	$event = $post['meta_input']['bhranti_event_data'] ?? null;
	return is_array( $event ) && in_array( $event['session_id'] ?? 0, $GLOBALS['media_sessions'], true ) && $GLOBALS['media_audit_fail'] === ( $event['type'] ?? '' ) ? true : $empty;
};

$media_runner_veto = bhranti_qa_runner_veto( static function () { return $GLOBALS['media_sessions'] ?? array(); } );
add_filter( 'pre_http_request', $media_http_guard, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
add_filter( 'user_has_cap', $media_cap_guard, PHP_INT_MAX, 4 );
add_filter( 'wp_upload_bits', $media_upload_guard, PHP_INT_MAX );
add_filter( 'wp_handle_upload', $media_upload_result, PHP_INT_MAX );
add_filter( 'wp_generate_attachment_metadata', $media_metadata_guard, PHP_INT_MAX, 2 );
add_filter( 'wp_insert_post_empty_content', $media_audit_guard, PHP_INT_MAX, 2 );
$media_before_content = bhranti_media_content();
$media_before_files = bhranti_media_files();
$media_before_identity = bhranti_media_preserved_identity();

try {
	foreach ( array( 'bhranti_settings', 'bhranti_settings_lock', 'bhranti_actor_id', 'bhranti_actor_setup_lock', 'bhranti_role_owned', $GLOBALS['wpdb']->get_blog_prefix() . 'user_roles' ) as $name ) {
		$media_options[ $name ] = bhranti_media_raw_option( $name );
	}
	bhranti_media_check( ! get_option( 'bhranti_settings_lock' ) && ! get_option( 'bhranti_actor_setup_lock' ), 'No existing Settings or actor setup transaction is interrupted' );
	$actor = (int) get_option( 'bhranti_actor_id' );
	bhranti_media_check( 11 === $actor && get_userdata( $actor ) && get_post( 742 ), 'Selected site actor 11 and conversation 742 exist and remain outside fixtures' );
	bhranti_media_checkpoint();
	foreach ( array( 'administrator', 'administrator', 'subscriber' ) as $role ) {
		$login = 'bhranti_media_qa_' . str_replace( '-', '', wp_generate_uuid4() );
		$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test', 'role' => $role ) );
		bhranti_media_check( is_int( $id ) && $id > 0, 'Disposable media ' . $role . ' is created' );
		$media_users[] = $id;
		bhranti_media_checkpoint();
	}
	list( $owner, $other, $subscriber ) = $media_users;
	wp_set_current_user( $owner );
	foreach ( array( $owner, $other ) as $session_owner ) {
		wp_set_current_user( $session_owner );
		$response = bhranti_media_rest( 'POST', '/sessions' );
		bhranti_media_check( 200 === $response->get_status(), 'Disposable private media conversation is created' );
		$media_sessions[] = $response->get_data()['id'];
		bhranti_media_checkpoint();
	}
	list( $session, $other_session ) = $media_sessions;
	wp_set_current_user( $owner );
	bhranti_media_policy( true );
	$media_registry_property->setValue( null, new ProviderRegistry() );
	AiClient::setEventDispatcher( null );
	AiClient::setCache( null );
	AiClient::defaultRegistry()->registerProvider( Bhranti_QA_Image_Provider::class );
	AiClient::defaultRegistry()->registerProvider( Bhranti_QA_Provider::class );
	foreach ( array( 'bhranti-media-qa', 'bhranti-qa' ) as $connector ) {
		bhranti_media_check( null === WP_Connector_Registry::get_instance()->get_registered( $connector ), 'Disposable connector name is not already registered' );
		WP_Connector_Registry::get_instance()->register( $connector, array( 'name' => 'Bhranti offline QA', 'type' => 'ai_provider', 'authentication' => array( 'method' => 'none' ) ) );
		$media_connectors[] = $connector;
	}
	$png = bhranti_media_png();
	Bhranti_QA_Image_Model::$image = new File( base64_encode( $png ), 'image/png' );
	Bhranti_QA_Image_Model::$session = $session;
	$args = array( 'prompt' => $media_marker . ' private image prompt', 'title' => 'A <b>small</b> cat \\ fixture', 'alt_text' => 'A <em>red</em> cat \\ fixture' );
	$command = bhranti_media_command( $args );
	$schema = Bhranti_Media::catalog()['generate_image']['parameters'];
	bhranti_media_check( false === $schema['additionalProperties'] && 2000 === $schema['properties']['prompt']['maxLength'] && 200 === $schema['properties']['title']['maxLength'] && 500 === $schema['properties']['alt_text']['maxLength'], 'Media tool exposes the bounded exact argument schema' );

	$invalid = array(
		array(),
		array( 'prompt' => '' ),
		array( 'prompt' => " \n\t " ),
		array( 'prompt' => 1 ),
		array( 'prompt' => array( 'text' => 'nested' ) ),
		array( 'prompt' => str_repeat( '猫', 2001 ) ),
		array( 'prompt' => "bad\xFFutf8" ),
		array( 'prompt' => 'test', 'title' => str_repeat( '猫', 201 ) ),
		array( 'prompt' => 'test', 'alt_text' => str_repeat( '猫', 501 ) ),
		array( 'prompt' => 'test', 'title' => false ),
		array( 'prompt' => 'test', 'alt_text' => "bad\xFFutf8" ),
		array( 'prompt' => 'test', 'url' => 'https://example.test/image.png' ),
		array( 'prompt' => 'test', 'author' => $other ),
		array( 'prompt' => 'test', 'filename' => '../escape.php' ),
		array( 'prompt' => 'test', 'provider' => 'unapproved' ),
	);
	foreach ( $invalid as $case ) {
		bhranti_media_reject( bhranti_media_command( $case ), $owner, $session, 'Invalid media arguments fail closed', 'bhranti_media_invalid' );
	}
	bhranti_media_reject( $command + array( 'confirmed' => true ), $owner, $session, 'Extra envelope keys cannot grant image authority', 'bhranti_media_invalid' );
	bhranti_media_reject( array( 'tool' => 'generate_image', 'args' => 'not-an-object' ), $owner, $session, 'Scalar media argument envelope is rejected', 'bhranti_media_invalid' );

	wp_set_current_user( 0 );
	bhranti_media_check( 401 === bhranti_media_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => wp_generate_uuid4() ) )->get_status(), 'Anonymous native REST image execution is rejected' );
	bhranti_media_reject( $command, $owner, $session, 'Anonymous direct image execution is rejected', 'bhranti_action_owner' );
	wp_set_current_user( $subscriber );
	bhranti_media_check( 403 === bhranti_media_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => wp_generate_uuid4() ) )->get_status(), 'Subscriber native REST image execution is rejected' );
	bhranti_media_reject( $command, $subscriber, $session, 'Subscriber cannot borrow human image authority', 'bhranti_action_owner' );
	wp_set_current_user( $other );
	bhranti_media_reject( $command, $owner, $session, 'Requester spoofing cannot cross authenticated identity', 'bhranti_action_owner' );
	bhranti_media_reject( $command, $other, $session, 'Another administrator cannot use an owned image conversation', 'bhranti_action_owner' );
	wp_set_current_user( $owner );
	bhranti_media_reject( $command, $owner, $other_session, 'Images cannot cross private conversation ownership', 'bhranti_action_owner' );

	bhranti_media_policy( false );
	bhranti_media_check( ! isset( Bhranti_Media::catalog()['generate_image'] ), 'Disabled current media policy hides image generation' );
	bhranti_media_reject( $command, $owner, $session, 'Disabled media policy prevents native generation', 'bhranti_action_disabled' );
	bhranti_media_policy( true );
	foreach ( array( $owner, $actor ) as $denied ) {
		$media_deny_cap = array( $denied => array( 'upload_files' ) );
		try {
			bhranti_media_reject( $command, $owner, $session, 'Both human and actor independently require upload_files', 'bhranti_media_permission' );
		} finally {
			$media_deny_cap = array();
		}
	}
	$media_audit_fail = 'media_started';
	try {
		bhranti_media_reject( $command, $owner, $session, 'Failed durable start audit prevents provider generation', 'bhranti_audit_failed' );
	} finally {
		$media_audit_fail = '';
	}

	Bhranti_QA_Image_Model::$before_reply = static function () {
		bhranti_media_policy( false );
	};
	try {
		bhranti_media_reject( $command, $owner, $session, 'A current policy revocation during generation prevents saving', 'bhranti_action_disabled', 1 );
	} finally {
		bhranti_media_policy( true );
	}
	foreach ( array( $owner, $actor ) as $denied ) {
		Bhranti_QA_Image_Model::$before_reply = static function () use ( $denied ) {
			$GLOBALS['media_deny_cap'] = array( $denied => array( 'upload_files' ) );
		};
		try {
			bhranti_media_reject( $command, $owner, $session, 'A human or actor upload revocation during generation prevents saving', 'bhranti_media_permission', 1 );
		} finally {
			$media_deny_cap = array();
		}
	}
	Bhranti_QA_Image_Model::$before_reply = static function () use ( $session, $other ) {
		wp_update_post( array( 'ID' => $session, 'post_author' => $other ) );
	};
	try {
		bhranti_media_reject( $command, $owner, $session, 'Conversation ownership is rechecked after image generation', 'bhranti_media_owner', 1 );
	} finally {
		wp_update_post( array( 'ID' => $session, 'post_author' => $owner ) );
	}
	Bhranti_QA_Image_Model::$fail = true;
	try {
		bhranti_media_reject( $command, $owner, $session, 'Native provider exceptions return a safe failure without an upload', 'bhranti_image_failed', 1 );
	} finally {
		Bhranti_QA_Image_Model::$fail = false;
	}

	$bad_images = array(
		array( new File( 'https://example.test/private-provider-image.png?secret=do-not-log', 'image/png' ), 'bhranti_media_inline' ),
		array( new File( base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"></svg>' ), 'image/svg+xml' ), 'bhranti_media_image' ),
		array( new File( base64_encode( $png ), 'image/jpeg' ), 'bhranti_media_image' ),
		array( new File( base64_encode( 'not an image' ), 'image/png' ), 'bhranti_media_image' ),
		array( new File( '', 'image/png' ), 'bhranti_media_image' ),
		array( new File( base64_encode( bhranti_media_png( 8193, 2 ) ), 'image/png' ), 'bhranti_media_image' ),
		array( new File( base64_encode( bhranti_media_png( 8192, 8192 ) ), 'image/png' ), 'bhranti_media_image' ),
		array( 'Provider prose cannot be saved as an image.', 'bhranti_image_failed' ),
	);
	foreach ( $bad_images as $case ) {
		Bhranti_QA_Image_Model::$image = $case[0];
		bhranti_media_reject( $command, $owner, $session, 'Unsupported remote/type/bytes/dimensions/provider output fails closed', $case[1], 1 );
	}
	Bhranti_QA_Image_Model::$image = new File( base64_encode( $png ), 'image/png' );
	$size_limit = static function () use ( $png ) {
		return strlen( $png ) - 1;
	};
	add_filter( 'upload_size_limit', $size_limit, PHP_INT_MAX );
	try {
		bhranti_media_reject( $command, $owner, $session, 'Native site upload size remains authoritative', 'bhranti_media_image', 1 );
	} finally {
		remove_filter( 'upload_size_limit', $size_limit, PHP_INT_MAX );
	}
	bhranti_media_check( false === file_exists( $media_sentinel ), 'Own unexpected-path sentinel is uniquely absent' );
	file_put_contents( $media_sentinel, $media_marker );
	foreach ( array( 'upload_error', 'corrupt', 'unexpected_path' ) as $mode ) {
		$media_upload_mode = $mode;
		try {
			bhranti_media_reject( $command, $owner, $session, 'Native upload error or unexpected file fails with cleanup', 'bhranti_media_storage', 1 );
			bhranti_media_check( $media_marker === file_get_contents( $media_sentinel ), 'Cleanup never removes an unexpected unrelated file' );
		} finally {
			$media_upload_mode = '';
		}
	}
	$media_bad_metadata = true;
	try {
		bhranti_media_reject( $command, $owner, $session, 'Failed attachment metadata verification removes only its fresh image', 'bhranti_media_storage', 1 );
	} finally {
		$media_bad_metadata = false;
	}
	$media_audit_fail = 'media_completed';
	try {
		bhranti_media_reject( $command, $owner, $session, 'Failed completion audit cannot claim a saved image or orphan its upload', 'bhranti_audit_failed', 1 );
	} finally {
		$media_audit_fail = '';
	}

	$before_calls = count( Bhranti_QA_Image_Model::$calls );
	$result = Bhranti_Actions::execute( $command, $owner, $session );
	bhranti_media_check( is_array( $result ) && ! is_wp_error( $result ), 'Native image tool saves an actual PNG attachment' );
	$id = $result['data']['id'];
	$media_attachments[] = $id;
	bhranti_media_checkpoint();
	bhranti_media_check( array( 'summary', 'data', 'target', 'action_id', 'rollback_available' ) === array_keys( $result ) && array( 'id', 'url', 'mime_type', 'width', 'height', 'title', 'alt_text' ) === array_keys( $result['data'] ), 'Successful generated image returns the exact public result projection' );
	bhranti_media_check( 42 === strlen( $result['action_id'] ) && 1 === preg_match( '/\Amedia_[a-f0-9-]{36}\z/', $result['action_id'] ) && false === $result['rollback_available'], 'Generated media has a namespaced action ID and no automatic rollback' );
	$post = get_post( $id );
	bhranti_media_check( 'attachment' === $post->post_type && 11 === (int) $post->post_author && '' === $post->post_content && 0 === (int) $post->post_parent, 'Fresh media belongs to actor 11 without changing or attaching existing content' );
	bhranti_media_check( 'image/png' === $result['data']['mime_type'] && 2 === (int) $result['data']['width'] && 2 === (int) $result['data']['height'] && $png === file_get_contents( get_attached_file( $id ) ), 'PNG bytes, MIME type and native attachment dimensions match the fixture' );
	bhranti_media_check( sanitize_text_field( $args['title'] ) === $post->post_title && sanitize_text_field( $args['alt_text'] ) === get_post_meta( $id, '_wp_attachment_image_alt', true ), 'Plain-text labels retain Unicode/slashes and strip executable markup' );
	bhranti_media_check( $result['data']['url'] === wp_get_attachment_url( $id ) && $result['target']['id'] === $id && 'attachment' === $result['target']['type'], 'Media result links to the verified new native attachment' );
	bhranti_media_check( array( 'action_id' => $result['action_id'], 'requester_id' => $owner, 'session_id' => $session ) === get_post_meta( $id, 'bhranti_generated_media', true ), 'Attachment marker identifies only this requester and owned conversation' );
	$call = Bhranti_QA_Image_Model::$calls[ $before_calls ];
	bhranti_media_check( $before_calls + 1 === count( Bhranti_QA_Image_Model::$calls ) && $owner === $call['user'] && $args['prompt'] === $call['prompt'][0]->getParts()[0]->getText() && in_array( 'media_started', $call['events'], true ), 'Native provider receives the exact prompt as the human after durable start audit' );
	bhranti_media_check( $owner === get_current_user_id() && ! isset( get_post_meta( $session, 'bhranti_actions', true )[ $result['action_id'] ] ), 'Image save restores the human and creates no false rollback snapshot' );
	$rollback = bhranti_media_rest( 'POST', '/sessions/' . $session . '/rollback', array( 'action_id' => $result['action_id'] ) );
	bhranti_media_check( 404 === $rollback->get_status() && 'bhranti_rollback_missing' === $rollback->get_data()['code'] && get_post( $id ) && is_file( get_attached_file( $id ) ), 'Native rollback route accepts the media ID but truthfully preserves the saved image' );

	$boundary = array( 'prompt' => str_repeat( '猫', 2000 ), 'title' => str_repeat( '猫', 200 ), 'alt_text' => str_repeat( '猫', 500 ) );
	$unicode = Bhranti_Actions::execute( bhranti_media_command( $boundary ), $owner, $session );
	bhranti_media_check( is_array( $unicode ) && ! is_wp_error( $unicode ), 'Unicode character limits accept their exact prompt/title/alt boundaries' );
	$media_attachments[] = $unicode['data']['id'];
	bhranti_media_checkpoint();
	bhranti_media_check( $boundary['title'] === $unicode['data']['title'] && $boundary['alt_text'] === $unicode['data']['alt_text'], 'Native attachment labels preserve exact valid Unicode' );

	Bhranti_QA_Model::$responses = array( wp_json_encode( array( 'type' => 'tool', 'tool' => 'generate_image', 'args' => array( 'prompt' => $media_marker . ' pipeline cat' ) ) ), wp_json_encode( array( 'type' => 'reply', 'content' => 'The local fixture image was saved.' ) ) );
	$started = bhranti_media_rest( 'POST', '/sessions/' . $session . '/messages', array( 'content' => 'Create one disposable cat image fixture.' ) );
	bhranti_media_check( 200 === $started->get_status() && 'running' === $started->get_data()['run']['status'], 'Real REST agent run starts for a generated image' );
	$run_id = $started->get_data()['run']['id'];
	$before_calls = count( Bhranti_QA_Image_Model::$calls );
	$step = bhranti_media_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $run_id ) );
	bhranti_media_check( 200 === $step->get_status() && 'running' === $step->get_data()['run']['status'] && $before_calls + 1 === count( Bhranti_QA_Image_Model::$calls ), 'Native REST parser dispatches generate_image exactly once' );
	$timeline = $step->get_data()['events'];
	$event = end( $timeline );
	bhranti_media_check( 'action' === $event['type'] && 'attachment' === $event['target']['type'] && false === $event['rollback_available'] && 42 === strlen( $event['action_id'] ), 'Visible tool timeline exposes the generated attachment and truthful rollback state' );
	$media_attachments[] = $event['target']['id'];
	bhranti_media_checkpoint();
	$finished = bhranti_media_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $run_id ) );
	bhranti_media_check( 200 === $finished->get_status() && 'complete' === $finished->get_data()['run']['status'] && $before_calls + 1 === count( Bhranti_QA_Image_Model::$calls ), 'Agent completion consumes the successful media result without regenerating it' );
	Bhranti_QA_Model::$responses = array( wp_json_encode( array( 'type' => 'tool', 'tool' => 'generate_image', 'args' => array( 'prompt' => 'Unsaved editor image must not run.' ) ) ) );
	$before_files = bhranti_media_files();
	$unsaved = bhranti_media_rest( 'POST', '/sessions/' . $session . '/messages', array( 'content' => 'Create an image while my editor has unsaved changes.', 'context' => array( 'unsaved_changes' => true ) ) );
	bhranti_media_check( 200 === $unsaved->get_status(), 'Unsaved editor fixture run starts' );
	$unsaved_step = bhranti_media_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $unsaved->get_data()['run']['id'] ) );
	bhranti_media_check( 200 === $unsaved_step->get_status() && 'cancelled' === $unsaved_step->get_data()['run']['status'] && $before_calls + 1 === count( Bhranti_QA_Image_Model::$calls ) && $before_files === bhranti_media_files(), 'Unsaved editor guard cancels generated media before the image provider or upload' );
	bhranti_media_check( false === get_option( 'bhranti_lock_' . $owner ) && false === get_option( 'bhranti_lock_' . $other ), 'Fixture mutation locks are released after native agent execution' );

	$events = bhranti_media_events( $session );
	$types = array_column( $events, 'type' );
	bhranti_media_check( in_array( 'media_started', $types, true ) && in_array( 'media_saving', $types, true ) && in_array( 'media_completed', $types, true ) && in_array( 'media_failed', $types, true ) && in_array( 'media_blocked', $types, true ), 'Durable audit distinguishes generated, saved, failed and permission-blocked media operations' );
	foreach ( $events as $audit ) {
		if ( 0 === strpos( $audit['type'] ?? '', 'media_' ) ) {
			bhranti_media_check( $owner === (int) $audit['requester_id'] && 11 === (int) $audit['actor_id'] && $session === (int) $audit['session_id'], 'Every media audit identifies the human, actor and owned conversation' );
			bhranti_media_check( ! array_diff( array_keys( $audit['details'] ), array( 'method', 'path', 'action_id', 'target_id', 'target_type', 'status', 'code', 'reason', 'exception_class', 'run_id' ) ), 'Media audit contains only the safe metadata allowlist' );
			if ( isset( $audit['details']['reason'] ) ) {
				bhranti_media_check( 'media_failed' === $audit['type'] && 'unknown' === $audit['details']['reason'] && in_array( $audit['details']['exception_class'] ?? '', array( 'runtime', 'unknown' ), true ), 'Native fixture diagnostics contain fixed classifications only, never exception text' );
			}
		}
	}
	$serialized = wp_json_encode( $events );
	foreach ( array( $media_marker . ' private image prompt', 'provider-private-secret', 'private-provider-image', base64_encode( $png ), 'base64Data' ) as $private ) {
		bhranti_media_check( false === strpos( $serialized, $private ), 'Audit excludes raw prompt, provider secrets, remote image URLs and inline bytes' );
	}
	$own_export = Bhranti_Privacy::export( get_userdata( $owner )->user_email );
	$other_export = Bhranti_Privacy::export( get_userdata( $other )->user_email );
	$own_media = array_values( array_filter( $own_export['data'], static function ( $item ) { return 'bhranti-media' === $item['group_id']; } ) );
	$other_media = array_values( array_filter( $other_export['data'], static function ( $item ) { return 'bhranti-media' === $item['group_id']; } ) );
	bhranti_media_check( count( $media_attachments ) === count( $own_media ) && array() === $other_media, 'Native privacy exporter includes generated attachment references only for their human requester' );
	foreach ( $own_media as $item ) {
		$record = json_decode( html_entity_decode( $item['data'][0]['value'], ENT_QUOTES, 'UTF-8' ), true );
		bhranti_media_check( $owner === $record['requester_id'] && $session === $record['session_id'] && in_array( (int) $item['data'][1]['value'], $media_attachments, true ), 'Privacy media reference exports the exact owned private marker and fresh attachment ID' );
	}
	Bhranti_Privacy::erase( get_userdata( $other )->user_email );
	bhranti_media_check( metadata_exists( 'post', $id, 'bhranti_generated_media' ), 'Another requester erasure preserves the human media marker' );
	$core_media = array();
	foreach ( $media_attachments as $attachment_id ) {
		$core_media[ $attachment_id ] = array(
			$GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( "SELECT * FROM {$GLOBALS['wpdb']->posts} WHERE ID=%d", $attachment_id ), ARRAY_A ),
			$GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( "SELECT * FROM {$GLOBALS['wpdb']->postmeta} WHERE post_id=%d AND meta_key<>'bhranti_generated_media' ORDER BY meta_id", $attachment_id ), ARRAY_A ),
		);
	}
	$before_erasure_files = bhranti_media_files();
	for ( $batch = 0; $batch < 10; ++$batch ) {
		$erased = Bhranti_Privacy::erase( get_userdata( $owner )->user_email );
		if ( $erased['done'] ) {
			break;
		}
	}
	bhranti_media_check( $erased['done'] && ! $erased['items_retained'] && $before_erasure_files === bhranti_media_files(), 'Requester privacy erasure removes private workspace associations while preserving every media file' );
	foreach ( $media_attachments as $attachment_id ) {
		$after_erasure = array(
			$GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( "SELECT * FROM {$GLOBALS['wpdb']->posts} WHERE ID=%d", $attachment_id ), ARRAY_A ),
			$GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( "SELECT * FROM {$GLOBALS['wpdb']->postmeta} WHERE post_id=%d AND meta_key<>'bhranti_generated_media' ORDER BY meta_id", $attachment_id ), ARRAY_A ),
		);
		bhranti_media_check( ! metadata_exists( 'post', $attachment_id, 'bhranti_generated_media' ) && $core_media[ $attachment_id ] === $after_erasure, 'Privacy erases only the generated-media marker and preserves ordinary attachment data' );
	}
	bhranti_media_check( array() === $media_http, 'All native media and text fixture generations remain offline' );
} catch ( Throwable $error ) {
	$media_failure = $error;
} finally {
	$media_deny_cap = array();
	$media_upload_mode = '';
	$media_audit_fail = '';
	$media_bad_metadata = false;
	Bhranti_QA_Image_Model::$before_reply = null;
	remove_filter( 'wp_insert_post_empty_content', $media_audit_guard, PHP_INT_MAX );
	remove_filter( 'wp_generate_attachment_metadata', $media_metadata_guard, PHP_INT_MAX );
	remove_filter( 'wp_handle_upload', $media_upload_result, PHP_INT_MAX );
	remove_filter( 'wp_upload_bits', $media_upload_guard, PHP_INT_MAX );
	remove_filter( 'user_has_cap', $media_cap_guard, PHP_INT_MAX );
	foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => 'bhranti_generated_media' ) ) as $post ) {
		$record = get_post_meta( $post->ID, 'bhranti_generated_media', true );
		if ( 11 === (int) $post->post_author && in_array( get_attached_file( $post->ID ), $media_uploads, true ) && in_array( $record['session_id'] ?? 0, $media_sessions, true ) && in_array( $record['requester_id'] ?? 0, $media_users, true ) ) {
			wp_delete_attachment( $post->ID, true );
		}
	}
	foreach ( $media_attachments as $id ) {
		$post = get_post( $id );
		if ( $post && 'attachment' === $post->post_type && 11 === (int) $post->post_author && in_array( get_attached_file( $id ), $media_uploads, true ) ) {
			wp_delete_attachment( $id, true );
		}
	}
	foreach ( $media_uploads as $name => $path ) {
		if ( ! is_link( $path ) && wp_basename( $path ) === $name && ! isset( $media_before_files[ ltrim( str_replace( trailingslashit( wp_upload_dir( null, false )['basedir'] ), '', $path ), '/' ) ] ) && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
	foreach ( $media_sessions as $id ) {
		if ( get_post( $id ) ) {
			wp_delete_post( $id, true );
		}
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $media_users as $id ) {
		foreach ( get_posts( array( 'post_type' => 'bhranti_event', 'post_status' => 'private', 'author' => $id, 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $event_id ) {
			wp_delete_post( $event_id, true );
		}
		delete_option( 'bhranti_lock_' . $id );
		wp_delete_user( $id );
	}
	foreach ( $media_options as $name => $raw ) {
		bhranti_media_restore_option( $name, $raw );
	}
	if ( is_file( $media_sentinel ) && ! is_link( $media_sentinel ) && $media_marker === file_get_contents( $media_sentinel ) ) {
		unlink( $media_sentinel );
	}
	foreach ( $media_connectors as $connector ) {
		WP_Connector_Registry::get_instance()->unregister( $connector );
	}
	$media_registry_property->setValue( null, $media_original_registry );
	AiClient::setEventDispatcher( $media_original_dispatcher );
	AiClient::setCache( $media_original_cache );
	wp_set_current_user( $media_original_user );
	remove_filter( 'pre_http_request', $media_http_guard, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
	$media_preserved = $media_before_content === bhranti_media_content() && $media_before_files === bhranti_media_files() && $media_before_identity === bhranti_media_preserved_identity();
	foreach ( $media_options as $name => $raw ) {
		$media_preserved = $media_preserved && $raw === bhranti_media_raw_option( $name );
	}
	if ( $media_preserved ) {
		foreach ( array( $media_checkpoint, $media_checkpoint . '.pending' ) as $path ) {
			if ( is_file( $path ) && ! is_link( $path ) ) {
				unlink( $path );
			}
		}
	}
	remove_filter( 'pre_schedule_event', $media_runner_veto, PHP_INT_MAX ); restore_error_handler();
}
if ( ! $media_preserved ) {
	fwrite( STDERR, "FAIL: Media QA cleanup did not preserve exact Settings/options, existing actor 11, conversation 742, content and upload bytes. Recovery checkpoint: " . $media_checkpoint . "\n" );
	exit( 1 );
}
if ( $media_failure ) {
	fwrite( STDERR, $media_failure->getMessage() . "\n" );
	exit( 1 );
}
echo 'PASS: ' . $media_checks . " native media checks; offline SDK image fixture; disposable data cleaned; actor 11, conversation 742, existing content/uploads and raw options preserved.\n";
