<?php
/**
 * Offline native SDK recovery checks. Fresh exact-site fixtures only.
 *
 * Run only after deployment with BHRANTI_TEST_WP_ROOT and
 * BHRANTI_TEST_ALLOW_MUTATION=1. No external AI/HTTP or persisted fixture Cron.
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
use WordPress\AiClient\Providers\Http\Exception\NetworkException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;
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

define( 'BHRANTI_RENDER_QA_SUPPORT_ONLY', true );
require __DIR__ . '/rendered-messages.php';
if ( ! method_exists( 'Bhranti_Agent', 'resume' ) || realpath( BHRANTI_FILE ) !== realpath( WP_PLUGIN_DIR . '/bhranti/bhranti.php' ) || 1 !== get_current_blog_id() || 11 !== (int) get_option( 'bhranti_actor_id' ) || ! get_user_meta( 11, 'bhranti_managed_actor', true ) ) {
	throw new RuntimeException( 'Deploy the reviewed recovery backend on the exact selected site before this suite.' );
}

/** Local raster generation travels through the real SDK and native Media APIs. */
final class Bhranti_Recovery_Image_Provider extends AbstractProvider {
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata( 'bhranti-recovery-image-qa', 'Offline recovery image', ProviderTypeEnum::server(), null, null );
	}
	protected static function createModel( ModelMetadata $metadata, ProviderMetadata $provider ): ModelInterface {
		return new Bhranti_Recovery_Image_Model( $metadata, $provider );
	}
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new class implements ProviderAvailabilityInterface {
			public function isConfigured(): bool { return true; }
		};
	}
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new class implements ModelMetadataDirectoryInterface {
			public function listModelMetadata(): array { return array( $this->getModelMetadata( 'recovery-raster' ) ); }
			public function hasModelMetadata( string $id ): bool { return 'recovery-raster' === $id; }
			public function getModelMetadata( string $id ): ModelMetadata {
				return new ModelMetadata( $id, 'Recovery raster', array( CapabilityEnum::imageGeneration() ), array(
					new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
					new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::image() ) ) ),
				) );
			}
		};
	}
}

/** The same real fake text model also accepts the chosen native screenshot. */
final class Bhranti_Recovery_Text_Provider extends AbstractProvider {
	protected static function createProviderMetadata(): ProviderMetadata { return new ProviderMetadata( 'bhranti-recovery-text-qa', 'Offline recovery text', ProviderTypeEnum::server(), null, null ); }
	protected static function createModel( ModelMetadata $metadata, ProviderMetadata $provider ): ModelInterface { return new Bhranti_QA_Model( $metadata, $provider ); }
	protected static function createProviderAvailability(): ProviderAvailabilityInterface { return new class implements ProviderAvailabilityInterface { public function isConfigured(): bool { return true; } }; }
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new class implements ModelMetadataDirectoryInterface {
			public function listModelMetadata(): array { return array( $this->getModelMetadata( 'recovery-text' ) ); }
			public function hasModelMetadata( string $id ): bool { return 'recovery-text' === $id; }
			public function getModelMetadata( string $id ): ModelMetadata { return new ModelMetadata( $id, 'Recovery text', array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ), array(
				new SupportedOption( OptionEnum::systemInstruction() ), new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ), new SupportedOption( OptionEnum::outputSchema() ),
				new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ), array( ModalityEnum::text(), ModalityEnum::image() ) ) ), new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
			) ); }
		};
	}
}

final class Bhranti_Recovery_Image_Model extends AbstractApiBasedModel implements ImageGenerationModelInterface {
	public static $calls = array();
	public function generateImageResult( array $prompt ): GenerativeAiResult {
		self::$calls[] = array( 'requester_id' => get_current_user_id(), 'request_options' => $this->getRequestOptions()?->toArray() );
		// A two-pixel native PNG keeps resource usage and generated files bounded.
		$resource = imagecreatetruecolor( 2, 2 );
		ob_start(); imagepng( $resource ); $bytes = ob_get_clean();
		return new GenerativeAiResult( 'recovery-local-image', array( new Candidate( new ModelMessage( array( new MessagePart( new File( base64_encode( $bytes ), 'image/png' ) ) ) ), FinishReasonEnum::stop() ) ), new TokenUsage( 1, 1, 2 ), $this->providerMetadata(), $this->metadata() );
	}
}

$rc_checks = 0;
$rc_users = $rc_sessions = $rc_pages = $rc_attachments = $rc_queue = $rc_writes = array();
$rc_http = 0;
$rc_failure = null;
$rc_clean = true;
$rc_original_user = get_current_user_id();
$rc_marker = 'Bhranti recovery QA ' . wp_generate_uuid4();
$rc_login_prefix = 'bhranti_recovery_qa_' . str_replace( '-', '', wp_generate_uuid4() ) . '_';
$rc_checkpoint = '/private/tmp/bhranti-recovery-' . wp_generate_uuid4() . '.json';
$rc_prior_ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) );
$rc_prior_users = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->users}" ) );
$rc_before = rm_baseline();
$rc_option = $wpdb->get_row( "SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name='bhranti_settings'", ARRAY_A );
$rc_latest_config = null;
$rc_connectors = array();
$rc_queue_mode = true;
$rc_native_queue = array();
$rc_cron_before = $wpdb->get_row( "SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name='cron'", ARRAY_A );

function rc_check( $condition, $label ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$GLOBALS['rc_checks'];
}
function rc_uploads() {
	$files = array(); $directory = wp_get_upload_dir()['basedir'];
	if ( is_dir( $directory ) ) { foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) ) as $file ) { if ( $file->isFile() ) { $files[ $file->getPathname() ] = hash_file( 'sha256', $file->getPathname() ); } } }
	ksort( $files ); return $files;
}
function rc_checkpoint() {
	global $rc_checkpoint, $rc_marker, $rc_login_prefix, $rc_users, $rc_sessions, $rc_pages, $rc_attachments, $rc_prior_ids, $rc_prior_users, $rc_before, $rc_option, $rc_latest_config, $rc_files_before;
	$text = wp_json_encode( array( 'site' => home_url( '/' ), 'marker' => $rc_marker, 'login_prefix' => $rc_login_prefix, 'users' => $rc_users, 'sessions' => $rc_sessions, 'pages' => $rc_pages, 'attachments' => $rc_attachments, 'original_post_ids' => $rc_prior_ids, 'original_user_ids' => $rc_prior_users, 'settings_raw' => $rc_option, 'settings_last_fixture' => $rc_latest_config, 'baseline_hashes' => array_map( static fn( $value ) => hash( 'sha256', serialize( $value ) ), $rc_before ), 'original_uploads' => $rc_files_before ) );
	$pending = $rc_checkpoint . '.pending'; $handle = fopen( $pending, 'x' );
	if ( ! $handle || ! chmod( $pending, 0600 ) ) { throw new RuntimeException( 'Cannot create private recovery checkpoint.' ); }
	try { if ( ! is_string( $text ) || strlen( $text ) !== fwrite( $handle, $text ) || ! fflush( $handle ) ) { throw new RuntimeException( 'Incomplete private recovery checkpoint.' ); } } finally { fclose( $handle ); }
	if ( ! rename( $pending, $rc_checkpoint ) ) { throw new RuntimeException( 'Cannot replace private recovery checkpoint.' ); }
}
function rc_rest( $method, $path, $params = array() ) {
	$request = new WP_REST_Request( $method, '/bhranti/v1' . $path );
	$request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( $params ) );
	return rest_do_request( $request );
}
function rc_state( $tuple ) { return get_post_meta( $tuple[0], 'bhranti_run', true ); }
function rc_plan( $tool, $args ) { return wp_json_encode( array( 'type' => 'tool', 'content' => 'Inspect only the fresh recovery fixture.', 'tool' => $tool, 'args' => wp_json_encode( $args ) ) ); }
function rc_reply() { return wp_json_encode( array( 'type' => 'reply', 'content' => 'The exact fixture is complete.', 'tool' => 'none', 'args' => '{}' ) ); }
function rc_screenshot( $call, $png ) {
	$files = array(); foreach ( $call['prompt'] as $message ) { foreach ( $message->getParts() as $part ) { if ( $part->getType()->isFile() ) { $files[] = array( $message, $part ); } } }
	rc_check( 1 === count( $files ) && $files[0][0]->getRole()->isUser() && $png === $files[0][1]->getFile()->getBase64Data(), 'Resume retains one exact screenshot on an actual user message' );
	rc_check( 'Continue only this disposable local recovery fixture.' === $files[0][0]->getParts()[0]->getText() && ! str_contains( $call['config']['systemInstruction'], $png ), 'Trailing terminal error cannot move screenshot to tool/retry feedback or system text' );
}
function rc_start( $owner, $context = array() ) {
	wp_set_current_user( $owner ); $response = rc_rest( 'POST', '/sessions' );
	rc_check( 200 === $response->get_status(), 'Fresh owned recovery conversation created' );
	$id = (int) $response->get_data()['id']; if ( ! in_array( $id, $GLOBALS['rc_sessions'], true ) ) { $GLOBALS['rc_sessions'][] = $id; } rc_checkpoint();
	$response = rc_rest( 'POST', '/sessions/' . $id . '/messages', array( 'content' => 'Continue only this disposable local recovery fixture.', 'context' => $context ) );
	rc_check( 200 === $response->get_status(), 'Fresh run saves without asking a provider' );
	return array( $id, $response->get_data()['run']['id'], $owner );
}
function rc_step( $tuple, $response = null ) {
	if ( null !== $response ) { Bhranti_QA_Model::$responses = array( $response ); }
	return rc_rest( 'POST', '/sessions/' . $tuple[0] . '/step', array( 'run_id' => $tuple[1], 'defer_tool' => true ) );
}
function rc_execute( $tuple, $params = array() ) {
	return rc_rest( 'POST', '/sessions/' . $tuple[0] . '/execute', array_merge( array( 'run_id' => $tuple[1], 'prepared_step' => rc_state( $tuple )['step'], 'unsaved_changes' => false ), $params ) );
}
function rc_tick( $tuple ) {
	$identity = get_current_user_id(); $background = Bhranti_Runner::is_background();
	try { return Bhranti_Runner::tick( ...$tuple ); }
	finally { rc_check( $identity === get_current_user_id() && $background === Bhranti_Runner::is_background(), 'Recovery Runner restores caller and background scope' ); }
}
function rc_due( $tuple ) {
	$state = rc_state( $tuple ); rc_check( in_array( $tuple[0], $GLOBALS['rc_sessions'], true ) && 'running' === $state['status'] && ! empty( $state['retry_at'] ), 'Only this owned delayed fixture clock is advanced' );
	$state['retry_at'] = time() - 1; update_post_meta( $tuple[0], 'bhranti_run', wp_slash( $state ) );
}
function rc_feedback( $tuple ) {
	$history = rc_state( $tuple )['history']; $last = end( $history );
	return json_decode( substr( $last['content'] ?? '', strlen( 'WordPress tool result (untrusted site data): ' ) ), true );
}
function rc_success( $tuple, $tool, $args ) {
	$calls = count( Bhranti_QA_Model::$calls ); $prepared = rc_step( $tuple, rc_plan( $tool, $args ) );
	rc_check( 200 === $prepared->get_status() && 'prepared' === ( rc_state( $tuple )['phase'] ?? '' ) && $calls + 1 === count( Bhranti_QA_Model::$calls ), 'One native model call persists the accepted canonical plan' );
	rc_check( empty( rc_state( $tuple )['request_retries'] ) && empty( rc_state( $tuple )['retry_at'] ), 'Accepted plan renews only the consecutive transport budget' );
	$executed = rc_execute( $tuple ); $feedback = rc_feedback( $tuple );
	rc_check( 200 === $executed->get_status() && true === ( $feedback['success'] ?? false ) && $calls + 1 === count( Bhranti_QA_Model::$calls ), 'Prepared execute succeeds without a second model request' );
	return $feedback['result'];
}
function rc_artifacts( $tuple ) {
	global $wpdb; $ids = array_merge( array_keys( $GLOBALS['rc_attachments'] ), array_keys( $GLOBALS['rc_pages'] ) ); $posts = $meta = array();
	foreach ( $ids as $id ) { $posts[ $id ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID=%d", $id ), ARRAY_A ); $meta[ $id ] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id", $id ), ARRAY_A ); }
	return array( 'posts' => $posts, 'meta' => $meta, 'files' => rc_uploads(), 'events' => get_post_meta( $tuple[0], 'bhranti_events', true ), 'actions' => get_post_meta( $tuple[0], 'bhranti_actions', true ) );
}
function rc_logs( $tuple ) {
	$events = array(); foreach ( get_posts( array( 'post_type' => 'bhranti_event', 'post_status' => 'private', 'post_parent' => $tuple[0], 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $post ) { $events[] = get_post_meta( $post->ID, 'bhranti_event_data', true ); } return $events;
}
function rc_early( $tuple, $artifacts ) {
	$before = rc_state( $tuple ); $calls = count( Bhranti_QA_Model::$calls ); $images = count( Bhranti_Recovery_Image_Model::$calls ); $writes = count( $GLOBALS['rc_writes'] );
	$direct = rc_step( $tuple ); rc_check( 200 === $direct->get_status() && $before === rc_state( $tuple ), 'Early foreground step keeps its exact durable checkpoint' );
	$queue_before = count( $GLOBALS['rc_queue'] ); rc_tick( $tuple );
	rc_check( $before === rc_state( $tuple ) && $calls === count( Bhranti_QA_Model::$calls ) && $images === count( Bhranti_Recovery_Image_Model::$calls ) && $writes === count( $GLOBALS['rc_writes'] ), 'Early Runner and foreground retries neither resample nor replay a tool' );
	$queued = array_slice( $GLOBALS['rc_queue'], $queue_before ); rc_check( $queued && min( array_column( $queued, 'timestamp' ) ) >= $before['retry_at'], 'Native queued retry cannot wake before the server deadline' );
	rc_check( $artifacts === rc_artifacts( $tuple ), 'Completed images, drafts, files, events and rollback snapshots survive early retries' );
}
function rc_failure( $tuple, $error, $attempt, $delay, $artifacts ) {
	$before = rc_state( $tuple ); $calls = count( Bhranti_QA_Model::$calls ); $started = time(); $response = rc_step( $tuple, $error ); $state = rc_state( $tuple );
	rc_check( 200 === $response->get_status() && 'running' === $state['status'] && '' === $state['phase'] && $before['step'] + 1 === $state['step'] && $calls + 1 === count( Bhranti_QA_Model::$calls ), 'One failed native request persists a safe delayed planning checkpoint' );
	rc_check( $attempt === $state['request_retries'] && $state['retry_at'] >= $started + $delay && $state['retry_at'] <= time() + $delay, 'Consecutive native transport retries use the reviewed bounded delay' );
	rc_check( $artifacts === rc_artifacts( $tuple ), 'A failed planning request cannot modify already successful artifacts' );
	rc_early( $tuple, $artifacts );
}
function rc_resume( $tuple ) { return rc_rest( 'POST', '/sessions/' . $tuple[0] . '/resume', array( 'run_id' => $tuple[1] ) ); }
function rc_denied_resume( $tuple, $label ) {
	$before = rc_state( $tuple ); $calls = count( Bhranti_QA_Model::$calls ); $images = count( Bhranti_Recovery_Image_Model::$calls ); $writes = count( $GLOBALS['rc_writes'] ); $response = rc_resume( $tuple );
	rc_check( $response->get_status() >= 400 && $before === rc_state( $tuple ) && $calls === count( Bhranti_QA_Model::$calls ) && $images === count( Bhranti_Recovery_Image_Model::$calls ) && $writes === count( $GLOBALS['rc_writes'] ), $label );
}
function rc_policy( $key, $enabled ) {
	$settings = rc_rest( 'GET', '/settings' )->get_data(); $settings['config']['permissions'][ $key ] = $enabled; $settings['config']['max_steps'] = 128;
	$response = rc_rest( 'POST', '/settings', $settings ); rc_check( 200 === $response->get_status(), 'Own revision-checked policy fixture saves' ); $GLOBALS['rc_latest_config'] = $response->get_data(); rc_checkpoint();
}
/** Seed only a fresh legacy fixture's durable, structured failure evidence. */
function rc_legacy( $owner, $changes = array(), $failure_details = array(), $identity = array(), $tail = array(), $between = false ) {
	$tuple = rc_start( $owner ); $state = rc_state( $tuple );
	$state['status'] = 'failed'; $state['phase'] = ''; $state['step'] = 1;
	$state = array_merge( $state, $changes ); update_post_meta( $tuple[0], 'bhranti_run', wp_slash( $state ) );
	// Actual terminal save_run clears this tuple; a manually seeded legacy fixture must match it.
	Bhranti_Runner::clear( ...$tuple );
	$details = array_merge( array( 'run_id' => $tuple[1], 'code' => 'prompt_network_error', 'reason' => 'network', 'status' => 503 ), $failure_details );
	$actor = $identity['actor_id'] ?? 11; $requester = $identity['requester_id'] ?? $owner;
	$id = Bhranti_Audit::record( 'ai_failed', 'A local legacy request failed before planning.', $tuple[0], $details, $requester, $actor ); rc_check( is_int( $id ) && $id > 0, 'Fresh legacy native failure evidence is durable' );
	if ( $between ) { rc_check( is_int( Bhranti_Audit::record( 'action_completed', 'An intervening fixture event.', $tuple[0], array( 'run_id' => $tuple[1] ), $owner, 11 ) ), 'Intervening legacy fixture evidence is durable' ); }
	rc_check( is_int( Bhranti_Audit::record( $tail['type'] ?? 'run_failed', 'The local fixture stopped.', $tuple[0], array( 'run_id' => $tuple[1], 'status' => $tail['status'] ?? 'failed' ), $owner, 11 ) ), 'Fresh legacy terminal evidence is durable' );
	return $tuple;
}

$rc_files_before = rc_uploads(); rc_checkpoint();
$rc_registry_property = new ReflectionProperty( AiClient::class, 'defaultRegistry' ); $rc_registry_property->setAccessible( true );
$rc_registry = AiClient::defaultRegistry(); $rc_dispatcher = AiClient::getEventDispatcher(); $rc_cache = AiClient::getCache();
$rc_http_guard = static function () { ++$GLOBALS['rc_http']; return new WP_Error( 'bhranti_recovery_qa_http_denied', 'No external request is allowed.' ); };
$rc_schedule = static function ( $pre, $event ) {
	if ( 'bhranti_run_tick' === ( $event->hook ?? '' ) && in_array( (int) ( $event->args[0] ?? 0 ), $GLOBALS['rc_sessions'], true ) ) { $GLOBALS['rc_queue'][] = array( 'args' => $event->args, 'timestamp' => $event->timestamp ); if ( true === $GLOBALS['rc_queue_mode'] ) { $GLOBALS['rc_native_queue'][ serialize( $event->args ) ] = clone $event; } return $GLOBALS['rc_queue_mode']; } return $pre;
};
$rc_next = static function ( $pre, $hook, $args ) { if ( 'bhranti_run_tick' === $hook && in_array( (int) ( $args[0] ?? 0 ), $GLOBALS['rc_sessions'], true ) ) { return $GLOBALS['rc_native_queue'][ serialize( $args ) ] ?? false; } return $pre; };
$rc_clear = static function ( $pre, $hook, $args ) { if ( 'bhranti_run_tick' === $hook && in_array( (int) ( $args[0] ?? 0 ), $GLOBALS['rc_sessions'], true ) ) { $key = serialize( $args ); $had = isset( $GLOBALS['rc_native_queue'][ $key ] ); unset( $GLOBALS['rc_native_queue'][ $key ] ); return $had ? 1 : 0; } return $pre; };
$rc_capture = static function ( $id, $post ) {
	if ( in_array( (int) $id, $GLOBALS['rc_prior_ids'], true ) || 11 !== (int) $post->post_author || ! str_starts_with( $post->post_title, $GLOBALS['rc_marker'] ) ) { return; }
	if ( 'attachment' === $post->post_type ) { $GLOBALS['rc_attachments'][ (int) $id ] = $post->post_title; }
	elseif ( 'page' === $post->post_type ) { $GLOBALS['rc_pages'][ (int) $id ] = $post->post_title; }
	rc_checkpoint();
};
$rc_attachment_capture = static function ( $id ) use ( $rc_capture ) { $rc_capture( $id, get_post( $id ) ); };
$rc_write_guard = static function ( $pre, $server, $request ) {
	$route = $request->get_route(); if ( ! str_starts_with( $route, '/wp/v2/' ) ) { return $pre; }
	if ( in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
		$allowed = '/wp/v2/types' === $route || '/wp/v2/types/page' === $route;
		if ( preg_match( '#\A/wp/v2/(media|pages)/(\d+)\z#', $route, $match ) ) { $allowed = isset( $GLOBALS[ 'media' === $match[1] ? 'rc_attachments' : 'rc_pages' ][ (int) $match[2] ] ); }
		return $allowed ? $pre : new WP_Error( 'bhranti_recovery_qa_read', 'Only schema and fresh owned fixture reads are allowed.', array( 'status' => 403 ) );
	}
	$allowed = '/wp/v2/pages' === $route && 'POST' === $request->get_method() && is_string( $request['title'] ) && str_starts_with( $request['title'], $GLOBALS['rc_marker'] );
	if ( preg_match( '#\A/wp/v2/pages/(\d+)\z#', $route, $match ) ) { $allowed = isset( $GLOBALS['rc_pages'][ (int) $match[1] ] ); }
	if ( ! $allowed ) { return new WP_Error( 'bhranti_recovery_qa_write', 'Only fresh own pages may be changed.', array( 'status' => 403 ) ); }
	$GLOBALS['rc_writes'][] = array( 'method' => $request->get_method(), 'route' => $route, 'actor_id' => get_current_user_id() ); return $pre;
};
add_filter( 'pre_http_request', $rc_http_guard, PHP_INT_MAX ); add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
add_filter( 'pre_schedule_event', $rc_schedule, PHP_INT_MAX, 2 ); add_filter( 'rest_pre_dispatch', $rc_write_guard, PHP_INT_MAX, 3 );
add_filter( 'pre_get_scheduled_event', $rc_next, PHP_INT_MAX, 3 ); add_filter( 'pre_clear_scheduled_hook', $rc_clear, PHP_INT_MAX, 3 );
add_action( 'save_post', $rc_capture, PHP_INT_MAX, 2 ); add_action( 'add_attachment', $rc_attachment_capture, PHP_INT_MAX );

try {
	$rc_registry_property->setValue( null, new ProviderRegistry() ); AiClient::setEventDispatcher( null ); AiClient::setCache( null );
	AiClient::defaultRegistry()->registerProvider( Bhranti_Recovery_Text_Provider::class ); AiClient::defaultRegistry()->registerProvider( Bhranti_Recovery_Image_Provider::class );
	foreach ( array( 'bhranti-recovery-text-qa', 'bhranti-recovery-image-qa' ) as $connector ) { rc_check( null === WP_Connector_Registry::get_instance()->get_registered( $connector ), 'Local fake connector identity is unused' ); WP_Connector_Registry::get_instance()->register( $connector, array( 'name' => 'Offline recovery QA', 'type' => 'ai_provider', 'authentication' => array( 'method' => 'none' ) ) ); $rc_connectors[] = $connector; }
	foreach ( array( 1, 2 ) as $index ) { $login = $rc_login_prefix . $index; $id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) ); rc_check( is_int( $id ) && $id > 0 && ! in_array( $id, $rc_prior_users, true ), 'Fresh disposable requesting administrator created' ); $rc_users[] = $id; rc_checkpoint(); }
	list( $rc_owner, $rc_other ) = $rc_users; wp_set_current_user( $rc_owner );
	rc_policy( 'manage_media', true ); rc_policy( 'edit_content', true ); rc_policy( 'trash_content', true );
	$screenshot_resource = imagecreatetruecolor( 2, 2 ); ob_start(); imagepng( $screenshot_resource ); $screenshot_png = base64_encode( ob_get_clean() );
	$primary = rc_start( $rc_owner, array( 'unsaved_changes' => false, 'screenshot' => array( 'data' => $screenshot_png, 'mime_type' => 'image/png' ) ) );
	$format = rc_step( $primary, '{"type":"invalid-envelope"}' ); rc_check( 'running' === $format->get_data()['run']['status'] && 1 === rc_state( $primary )['plan_retries'], 'One malformed-plan retry is consumed before image generation' );
	$images = array();
	foreach ( range( 1, 4 ) as $index ) { $result = rc_success( $primary, 'generate_image', array( 'prompt' => 'A tiny local cat fixture.', 'title' => $rc_marker . ' image ' . $index, 'alt_text' => 'Disposable cat' ) ); $id = $result['data']['id']; rc_check( isset( $rc_attachments[ $id ] ) && 11 === (int) get_post_field( 'post_author', $id ) && $rc_owner === get_post_meta( $id, 'bhranti_generated_media', true )['requester_id'], 'Each image is an actual fresh actor-authored native attachment' ); $images[] = $id; }
	foreach ( $images as $id ) { $result = rc_success( $primary, 'wp_request', array( 'method' => 'GET', 'path' => '/wp/v2/media/' . $id, 'params' => array() ) ); rc_check( $id === $result['data']['id'], 'Native read returns the exact saved attachment ID' ); }
	rc_check( 4 === count( Bhranti_Recovery_Image_Model::$calls ) && 1 === rc_state( $primary )['plan_retries'], 'Successful image/read plans keep the malformed budget and generate exactly four images' );
	$private_marker = 'native-private-recovery-' . wp_generate_uuid4(); $artifacts = rc_artifacts( $primary );
	rc_failure( $primary, new NetworkException( $private_marker ), 1, 5, $artifacts ); rc_due( $primary );
	rc_failure( $primary, new ServerException( $private_marker, 503 ), 2, 15, $artifacts ); rc_due( $primary );
	rc_failure( $primary, new NetworkException( $private_marker ), 3, 30, $artifacts ); rc_due( $primary );
	$content = '<!-- wp:paragraph --><p>' . str_repeat( 'Cats enjoy calm homes and thoughtful companionship. ', 75 ) . '</p><!-- /wp:paragraph --><!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>Tabby</td><td>Striped</td></tr></tbody></table></figure><!-- /wp:table -->';
	foreach ( $images as $id ) { $url = wp_get_attachment_url( $id ); $content .= '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="' . esc_url( $url ) . '" alt="Disposable cat" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->'; }
	$prepared = rc_step( $primary, rc_plan( 'wp_request', array( 'method' => 'POST', 'path' => '/wp/v2/pages', 'params' => array( 'title' => $rc_marker . ' draft', 'status' => 'draft', 'content' => $content, 'featured_media' => $images[0] ) ) ) );
	rc_check( 'prepared' === $prepared->get_data()['run']['phase'] && empty( rc_state( $primary )['request_retries'] ) && empty( rc_state( $primary )['retry_at'] ) && 1 === rc_state( $primary )['plan_retries'], 'A valid plan after three transport failures resets transport counters only' );
	$dirty = rc_execute( $primary, array( 'unsaved_changes' => true ) ); rc_check( 409 === $dirty->get_status() && 'prepared' === rc_state( $primary )['phase'] && ! $rc_pages, 'Recovery still pauses a write for newly unsaved editor changes' );
	$executed = rc_execute( $primary ); $feedback = rc_feedback( $primary ); $rc_page = $feedback['result']['data']['id'] ?? 0;
	rc_check( 200 === $executed->get_status() && true === ( $feedback['success'] ?? false ) && isset( $rc_pages[ $rc_page ] ) && 1 === count( $rc_pages ), 'Recovered canonical create saves exactly one owned draft' );
	$block_ids = array(); foreach ( parse_blocks( get_post_field( 'post_content', $rc_page ) ) as $block ) { if ( 'core/image' === $block['blockName'] ) { $block_ids[] = $block['attrs']['id']; } }
	rc_check( $images === $block_ids && $images[0] === get_post_thumbnail_id( $rc_page ) && str_word_count( wp_strip_all_tags( get_post_field( 'post_content', $rc_page ) ) ) >= 500, 'Recovered draft uses all four original images, featured image and requested long text' );
	$writes = count( $rc_writes ); rc_execute( $primary ); rc_check( $writes === count( $rc_writes ) && 4 === count( Bhranti_Recovery_Image_Model::$calls ), 'Replayed execute cannot recreate draft or regenerate images' );
	rc_success( $primary, 'wp_request', array( 'method' => 'GET', 'path' => '/wp/v2/pages/' . $rc_page, 'params' => array( 'context' => 'edit' ) ) );
	$artifacts = rc_artifacts( $primary );
	foreach ( array( 5, 15, 30 ) as $index => $delay ) { rc_failure( $primary, new NetworkException( $private_marker ), $index + 1, $delay, $artifacts ); rc_due( $primary ); }
	$exhausted = rc_step( $primary, new ServerException( $private_marker, 503 ) ); $checkpoint = rc_state( $primary );
	rc_check( 'failed' === $exhausted->get_data()['run']['status'] && ! empty( $exhausted->get_data()['run']['can_resume'] ) && $artifacts === rc_artifacts( $primary ), 'Fourth consecutive transport failure stops at an explicitly resumable checkpoint with artifacts intact' );
	$calls = count( Bhranti_QA_Model::$calls ); rc_tick( $primary ); rc_check( $calls === count( Bhranti_QA_Model::$calls ) && $checkpoint === rc_state( $primary ), 'Paused exhausted checkpoint cannot continue through Cron automatically' );
	wp_set_current_user( 0 ); rc_denied_resume( $primary, 'Anonymous Resume cannot use a private checkpoint' ); wp_set_current_user( $rc_other ); rc_denied_resume( $primary, 'Another administrator cannot Resume this owner checkpoint' ); wp_set_current_user( $rc_owner );
	$wrong = $primary; $wrong[1] = wp_generate_uuid4(); rc_denied_resume( $wrong, 'Wrong run UUID cannot Resume the checkpoint' );
	$deny_cap = static function ( $caps, $requested, $args, $user ) use ( $rc_owner ) { if ( $rc_owner === (int) $user->ID ) { $caps['manage_options'] = false; } return $caps; };
	add_filter( 'user_has_cap', $deny_cap, PHP_INT_MAX, 4 ); try { rc_denied_resume( $primary, 'Revoked live human capability refuses Resume without modifying checkpoint' ); $before_revoked = count( Bhranti_QA_Model::$calls ); $blocked_tick = rc_tick( $primary ); rc_check( false === $blocked_tick && $before_revoked === count( Bhranti_QA_Model::$calls ), 'Terminal paused Runner cannot resume itself after human permission revocation' ); } finally { remove_filter( 'user_has_cap', $deny_cap, PHP_INT_MAX ); }
	$messages = get_post_meta( $primary[0], 'bhranti_messages', true ); $resumed = rc_resume( $primary ); $state = rc_state( $primary );
	rc_check( 200 === $resumed->get_status() && 'running' === $state['status'] && empty( $state['request_retries'] ) && empty( $state['retry_at'] ) && $calls === count( Bhranti_QA_Model::$calls ), 'Explicit human Resume renews transport recovery without inline AI' );
	foreach ( array( 'id', 'requester_id', 'actor_id', 'step', 'configuration', 'context', 'plan_retries' ) as $key ) { rc_check( $checkpoint[ $key ] === $state[ $key ], 'Resume preserves frozen checkpoint field ' . $key ); }
	rc_check( $checkpoint['history'] === array_slice( $state['history'], 0, count( $checkpoint['history'] ) ) && count( $checkpoint['history'] ) + 1 === count( $state['history'] ) && 'user' === end( $state['history'] )['role'], 'Resume preserves all prior history and appends only fixed safe recovery guidance' );
	rc_check( $messages === get_post_meta( $primary[0], 'bhranti_messages', true ) && $artifacts === rc_artifacts( $primary ), 'Resume adds no user message and preserves successful files/actions' );
	$duplicate = rc_resume( $primary ); rc_check( 409 === $duplicate->get_status() && $state === rc_state( $primary ) && $calls === count( Bhranti_QA_Model::$calls ), 'Duplicate Resume cannot reset a running request or call AI' );
	rc_failure( $primary, new ServerException( $private_marker, 503 ), 1, 5, $artifacts ); rc_due( $primary );
	rc_screenshot( end( Bhranti_QA_Model::$calls ), $screenshot_png );
	$result = rc_success( $primary, 'wp_request', array( 'method' => 'GET', 'path' => '/wp/v2/pages/' . $rc_page, 'params' => array( 'context' => 'edit' ) ) ); rc_check( $rc_page === $result['data']['id'], 'Resume reuses the already created native draft' );
	$before_complete = rc_artifacts( $primary ); $done = rc_step( $primary, rc_reply() ); rc_check( 'complete' === $done->get_data()['run']['status'] && 4 === count( Bhranti_Recovery_Image_Model::$calls ) && 1 === count( $rc_pages ) && $before_complete === rc_artifacts( $primary ), 'Recovered completion never repeats successful images or writes' ); rc_denied_resume( $primary, 'Completed run cannot Resume' );
	rc_check( false === strpos( wp_json_encode( array( rc_state( $primary ), rc_logs( $primary ), $done->get_data() ) ), $private_marker ), 'Private native exception text stays out of checkpoints, replies and durable logs' );

	// A successful episode does not renew the malformed-plan allowance.
	$format_tuple = rc_start( $rc_owner ); rc_step( $format_tuple, '{"type":"bad"}' ); rc_success( $format_tuple, 'wp_request', array( 'method' => 'GET', 'path' => '/wp/v2/types', 'params' => array() ) );
	$again = rc_step( $format_tuple, '{"type":"bad-again"}' ); rc_check( 'failed' === $again->get_data()['run']['status'] && empty( $again->get_data()['run']['can_resume'] ), 'Malformed plans retain the one-per-run budget across successful reads' ); rc_denied_resume( $format_tuple, 'Malformed plan failure cannot use transport Resume' );

	foreach ( array( 'model', 'action' ) as $phase ) { $uncertain = rc_start( $rc_owner ); $state = rc_state( $uncertain ); $state['phase'] = $phase; update_post_meta( $uncertain[0], 'bhranti_run', wp_slash( $state ) ); $before = count( Bhranti_QA_Model::$calls ); $failed = rc_step( $uncertain ); rc_check( 'failed' === $failed->get_data()['run']['status'] && $before === count( Bhranti_QA_Model::$calls ) && empty( $failed->get_data()['run']['can_resume'] ), 'Interrupted uncertain ' . $phase . ' phase is never replayed or made resumable' ); rc_denied_resume( $uncertain, 'Uncertain ' . $phase . ' failure refuses Resume' ); }
	$limited = rc_start( $rc_owner ); $state = rc_state( $limited ); $state['step'] = $state['configuration']['max_steps']; update_post_meta( $limited[0], 'bhranti_run', wp_slash( $state ) ); $before = count( Bhranti_QA_Model::$calls ); $failed = rc_step( $limited ); rc_check( 'failed' === $failed->get_data()['run']['status'] && $before === count( Bhranti_QA_Model::$calls ) && empty( $failed->get_data()['run']['can_resume'] ), 'Configured step exhaustion stops before native AI and cannot become transport recovery' ); rc_denied_resume( $limited, 'Step limit refuses Resume' );
	$cancelled = rc_start( $rc_owner ); rc_rest( 'POST', '/sessions/' . $cancelled[0] . '/cancel', array( 'run_id' => $cancelled[1] ) ); rc_denied_resume( $cancelled, 'Cancelled run refuses Resume' );
	$approval = rc_start( $rc_owner ); rc_step( $approval, rc_plan( 'wp_request', array( 'method' => 'DELETE', 'path' => '/wp/v2/pages/' . $rc_page, 'params' => array() ) ) ); rc_execute( $approval );
	// DELETE returns a proposal rather than ordinary success feedback.
	rc_check( 'awaiting_confirmation' === rc_state( $approval )['status'] && 'draft' === get_post_status( $rc_page ), 'Deletion still requires the explicit human approval gate' ); rc_denied_resume( $approval, 'Approval wait cannot Resume or approve deletion' );
	$confirmation = rc_state( $approval )['confirmation']['id']; rc_rest( 'POST', '/sessions/' . $approval[0] . '/confirm', array( 'confirmation_id' => $confirmation, 'approved' => false ) );
	rc_check( 'draft' === get_post_status( $rc_page ), 'Deny leaves the recovered draft and images unchanged' );

	// Previous versions have no resume marker: only the exact final audit pair qualifies.
	$legacy = rc_legacy( $rc_owner ); $legacy_checkpoint = rc_state( $legacy ); $before = count( Bhranti_QA_Model::$calls );
	rc_check( Bhranti_Agent::state( $legacy[0] )['run']['can_resume'], 'A marker-free legacy network failure qualifies through its exact durable pair' );
	$legacy_resumed = rc_resume( $legacy ); rc_check( 200 === $legacy_resumed->get_status() && 'running' === rc_state( $legacy )['status'] && $before === count( Bhranti_QA_Model::$calls ) && $legacy_checkpoint['id'] === rc_state( $legacy )['id'] && $legacy_checkpoint['history'] === array_slice( rc_state( $legacy )['history'], 0, count( $legacy_checkpoint['history'] ) ), 'Legacy Resume preserves the exact task and performs no inline native request' );
	rc_check( 'complete' === rc_step( $legacy, rc_reply() )->get_data()['run']['status'], 'The next ordinary native step completes a valid legacy recovery' );
	$legacy_cases = array(
		array( 'wrong audit UUID', array(), array( 'run_id' => wp_generate_uuid4() ), array(), array(), false ),
		array( 'wrong acting identity', array(), array(), array( 'actor_id' => $rc_other ), array(), false ),
		array( 'wrong requesting identity', array(), array(), array( 'requester_id' => $rc_other ), array(), false ),
		array( 'authentication status', array(), array( 'status' => 401 ), array(), array(), false ),
		array( 'forbidden status', array(), array( 'status' => 403 ), array(), array(), false ),
		array( 'usage-limit status', array(), array( 'status' => 429 ), array(), array(), false ),
		array( 'nontransport reason', array(), array( 'reason' => 'structured_output' ), array(), array(), false ),
		array( 'unsupported native code', array(), array( 'code' => 'prompt_client_error' ), array(), array(), false ),
		array( 'terminal event mismatch', array(), array(), array(), array( 'type' => 'run_cancelled' ), false ),
		array( 'terminal status mismatch', array(), array(), array(), array( 'status' => 'complete' ), false ),
		array( 'intervening action evidence', array(), array(), array(), array(), true ),
		array( 'uncertain action phase', array( 'phase' => 'action' ), array(), array(), array(), false ),
		array( 'pending canonical tool', array( 'pending_plan' => array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => '/wp/v2/pages' ) ) ), array(), array(), array(), false ),
		array( 'pending confirmation', array( 'confirmation' => array( 'id' => wp_generate_uuid4() ) ), array(), array(), array(), false ),
		array( 'unsafe checkpoint kind', array( 'resume_checkpoint' => array( 'kind' => 'action', 'step' => 1 ) ), array(), array(), array(), false ),
		array( 'checkpoint step mismatch', array( 'resume_checkpoint' => array( 'kind' => 'model_request', 'step' => 2 ) ), array(), array(), array(), false ),
		array( 'exhausted legacy step limit', array( 'step' => 128 ), array(), array(), array(), false ),
	);
	foreach ( $legacy_cases as $case ) { $spoof = rc_legacy( $rc_owner, $case[1], $case[2], $case[3], $case[4], $case[5] ); rc_check( ! Bhranti_Agent::state( $spoof[0] )['run']['can_resume'], 'Legacy recovery refuses ' . $case[0] ); rc_denied_resume( $spoof, 'Exact server Resume rejects ' . $case[0] ); }

	$audit_tuple = rc_legacy( $rc_owner ); $audit_before = rc_state( $audit_tuple ); $calls = count( Bhranti_QA_Model::$calls );
	$audit_failure = static function ( $pre, $id, $key, $value ) use ( $audit_tuple ) { return 'bhranti_event_data' === $key && $audit_tuple[0] === (int) ( $value['session_id'] ?? 0 ) && 'ai_resumed' === ( $value['type'] ?? '' ) ? false : $pre; };
	add_filter( 'add_post_metadata', $audit_failure, PHP_INT_MAX, 4 ); try { $failed_audit = rc_resume( $audit_tuple ); } finally { remove_filter( 'add_post_metadata', $audit_failure, PHP_INT_MAX ); }
	rc_check( 500 === $failed_audit->get_status() && 'bhranti_audit_failed' === $failed_audit->get_data()['code'] && $audit_before === rc_state( $audit_tuple ) && $calls === count( Bhranti_QA_Model::$calls ) && Bhranti_Agent::state( $audit_tuple[0] )['run']['can_resume'], 'Missing mandatory Resume audit leaves checkpoint intact and eligible without contacting a model' );
	rc_check( 200 === rc_resume( $audit_tuple )->get_status() && 'complete' === rc_step( $audit_tuple, rc_reply() )->get_data()['run']['status'], 'A later authorized Resume succeeds after audit storage recovers' );
	$queue_tuple = rc_legacy( $rc_owner ); $calls = count( Bhranti_QA_Model::$calls ); $rc_queue_mode = false;
	try { $queue_failed = rc_resume( $queue_tuple ); } finally { $rc_queue_mode = true; }
	rc_check( 200 === $queue_failed->get_status() && 'running' === rc_state( $queue_tuple )['status'] && 'bhranti_runner_storage' === ( $queue_failed->get_data()['run']['background_error'] ?? '' ) && $calls === count( Bhranti_QA_Model::$calls ), 'Resume queue refusal exposes safe foreground continuation without hidden AI or duplicated action' );
	rc_check( 'complete' === rc_step( $queue_tuple, rc_reply() )->get_data()['run']['status'], 'Foreground continuation remains usable after the exact fixture queue fails' );
	$policy_tuple = rc_legacy( $rc_owner ); rc_resume( $policy_tuple ); $own_content = get_post_field( 'post_content', $rc_page );
	rc_step( $policy_tuple, rc_plan( 'wp_request', array( 'method' => 'POST', 'path' => '/wp/v2/pages/' . $rc_page, 'params' => array( 'content' => '<p>Current policy must authorize the resumed write.</p>' ) ) ) );
	rc_policy( 'edit_content', false ); $policy_blocked = rc_execute( $policy_tuple ); $policy_feedback = rc_feedback( $policy_tuple );
	rc_check( 200 === $policy_blocked->get_status() && false === ( $policy_feedback['success'] ?? true ) && 'bhranti_action_disabled' === ( $policy_feedback['code'] ?? '' ) && $own_content === get_post_field( 'post_content', $rc_page ), 'A resumed prepared action obeys a freshly disabled server policy and preserves content' );
	rc_policy( 'edit_content', true ); rc_step( $policy_tuple, rc_reply() );
	$editor_tuple = rc_legacy( $rc_owner ); rc_resume( $editor_tuple ); rc_step( $editor_tuple, rc_plan( 'wp_request', array( 'method' => 'POST', 'path' => '/wp/v2/pages/' . $rc_page, 'params' => array( 'content' => '<p>One reviewed clean resumed update.</p>' ) ) ) );
	$editor_token = wp_generate_uuid4(); $dirty_lease = rc_rest( 'POST', '/editor-state', array( 'token' => $editor_token, 'editor_active' => true, 'unsaved_changes' => true ) ); rc_check( 200 === $dirty_lease->get_status(), 'Only the fresh requester records its dirty editor lease' );
	$calls = count( Bhranti_QA_Model::$calls ); $writes = count( $rc_writes ); $prepared_state = rc_state( $editor_tuple ); rc_tick( $editor_tuple ); $dirty_foreground = rc_execute( $editor_tuple );
	rc_check( 409 === $dirty_foreground->get_status() && $prepared_state === rc_state( $editor_tuple ) && $calls === count( Bhranti_QA_Model::$calls ) && $writes === count( $rc_writes ) && $own_content === get_post_field( 'post_content', $rc_page ), 'Late sticky dirty state pauses resumed foreground and background writes without replay' );
	rc_rest( 'POST', '/editor-state', array( 'token' => $editor_token, 'editor_active' => true, 'unsaved_changes' => false ) ); rc_tick( $editor_tuple );
	rc_check( 'prepared' === rc_state( $editor_tuple )['phase'] && $writes === count( $rc_writes ), 'A clean active editor still pauses only resumed background writes' );
	$foreground = rc_execute( $editor_tuple ); rc_check( 200 === $foreground->get_status() && true === rc_feedback( $editor_tuple )['success'] && '<p>One reviewed clean resumed update.</p>' === get_post_field( 'post_content', $rc_page ) && $writes + 1 === count( $rc_writes ), 'Explicit clean foreground execution applies the resumed canonical update once' );
	rc_rest( 'POST', '/editor-state', array( 'token' => $editor_token, 'editor_active' => false, 'unsaved_changes' => false ) ); rc_step( $editor_tuple, rc_reply() );
	rc_check( 0 === $rc_http, 'Every recovery fixture uses local native SDK providers without external requests' );
} catch ( Throwable $error ) { $rc_failure = $error; }
finally {
	remove_action( 'save_post', $rc_capture, PHP_INT_MAX ); remove_action( 'add_attachment', $rc_attachment_capture, PHP_INT_MAX ); remove_filter( 'rest_pre_dispatch', $rc_write_guard, PHP_INT_MAX );
	foreach ( $rc_pages as $id => $title ) { $post = get_post( $id ); if ( ! $post ) { continue; } if ( in_array( $id, $rc_prior_ids, true ) || 11 !== (int) $post->post_author || 'page' !== $post->post_type || $title !== $post->post_title || ! in_array( $post->post_status, array( 'draft', 'trash' ), true ) ) { $rc_clean = false; continue; } wp_delete_post( $id, true ); }
	foreach ( $rc_attachments as $id => $title ) { $post = get_post( $id ); if ( ! $post ) { continue; } $marker = get_post_meta( $id, 'bhranti_generated_media', true ); $file = get_attached_file( $id ); if ( in_array( $id, $rc_prior_ids, true ) || 11 !== (int) $post->post_author || 'attachment' !== $post->post_type || $title !== $post->post_title || ! is_array( $marker ) || ! in_array( (int) ( $marker['requester_id'] ?? 0 ), $rc_users, true ) || ! in_array( (int) ( $marker['session_id'] ?? 0 ), $rc_sessions, true ) || ! str_starts_with( $marker['action_id'] ?? '', 'media_' ) || isset( $rc_files_before[ $file ] ) ) { $rc_clean = false; continue; } if ( ! wp_delete_attachment( $id, true ) || get_post( $id ) ) { $rc_clean = false; } }
	foreach ( $rc_users as $id ) { $user = get_user_by( 'id', $id ); $rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_type,post_status FROM {$wpdb->posts} WHERE post_author=%d", $id ), ARRAY_A ); $safe = $user && str_starts_with( $user->user_login, $rc_login_prefix ) && str_ends_with( $user->user_email, '@example.test' ) && ! in_array( $id, $rc_prior_users, true ); foreach ( $rows as $row ) { $safe = $safe && ! in_array( (int) $row['ID'], $rc_prior_ids, true ) && in_array( $row['post_type'], array( 'bhranti_session', 'bhranti_event' ), true ) && 'private' === $row['post_status']; } if ( ! $safe ) { $rc_clean = false; continue; } foreach ( $rows as $row ) { wp_delete_post( (int) $row['ID'], true ); } require_once ABSPATH . 'wp-admin/includes/user.php'; if ( ! wp_delete_user( $id ) || get_user_by( 'id', $id ) ) { $rc_clean = false; } }
	if ( $rc_latest_config ) { wp_cache_delete( 'bhranti_settings', 'options' ); wp_cache_delete( 'alloptions', 'options' ); if ( $rc_latest_config === get_option( 'bhranti_settings' ) ) { if ( null === $rc_option ) { $wpdb->delete( $wpdb->options, array( 'option_name' => 'bhranti_settings' ), array( '%s' ) ); } else { $wpdb->update( $wpdb->options, $rc_option, array( 'option_name' => 'bhranti_settings' ), array( '%s', '%s' ), array( '%s' ) ); } wp_cache_delete( 'bhranti_settings', 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); } else { $rc_clean = false; $rc_failure = new RuntimeException( 'Settings changed outside the fixture; current human value was preserved.' ); } }
	wp_set_current_user( $rc_original_user ); foreach ( $rc_connectors as $connector ) { WP_Connector_Registry::get_instance()->unregister( $connector ); } $rc_registry_property->setValue( null, $rc_registry ); AiClient::setEventDispatcher( $rc_dispatcher ); AiClient::setCache( $rc_cache ); remove_filter( 'pre_http_request', $rc_http_guard, PHP_INT_MAX ); remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX ); remove_filter( 'pre_schedule_event', $rc_schedule, PHP_INT_MAX ); remove_filter( 'pre_get_scheduled_event', $rc_next, PHP_INT_MAX ); remove_filter( 'pre_clear_scheduled_hook', $rc_clear, PHP_INT_MAX ); restore_error_handler();
	$rc_preserved = $rc_before === rm_baseline(); $rc_files_preserved = $rc_files_before === rc_uploads(); $rc_cron_preserved = $rc_cron_before === $wpdb->get_row( "SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name='cron'", ARRAY_A ); if ( $rc_clean && $rc_preserved && $rc_files_preserved && $rc_cron_preserved ) { unlink( $rc_checkpoint ); }
	echo wp_json_encode( array( 'checks' => $rc_checks, 'original_content_options_actor_preserved' => $rc_preserved, 'original_uploads_preserved' => $rc_files_preserved, 'original_cron_preserved' => $rc_cron_preserved, 'fixtures_removed' => $rc_clean, 'http_calls' => $rc_http ) ), "\n";
}
if ( $rc_failure || ! $rc_clean || ! $rc_preserved || ! $rc_files_preserved || ! $rc_cron_preserved ) { fwrite( STDERR, $rc_failure ? $rc_failure->getMessage() . "\n" : "Cleanup/preservation failed; private checkpoint retained.\n" ); exit( 1 ); }
echo 'PASS ' . $rc_checks . " offline native recovery checks\n";
