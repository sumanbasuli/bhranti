<?php
/**
 * Exact-package native chat/action regression in a fresh offline Playground.
 *
 * Run through tests/review-e2e.mjs, never against an existing WordPress site.
 * The deterministic provider implements the real native SDK and connector registry.
 *
 * @package Bhranti
 */

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
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
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

if ( ! defined( 'BHRANTI_REVIEW_E2E_TEST' ) || true !== BHRANTI_REVIEW_E2E_TEST || ! is_dir( '/internal' ) || ! is_file( '/wordpress/wp-load.php' ) ) {
	throw new RuntimeException( 'Use only the fresh offline Playground review harness.' );
}
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( '/wordpress/' !== ABSPATH || ! WP_DEBUG || ! DISABLE_WP_CRON || ! WP_HTTP_BLOCK_EXTERNAL || 'Bhranti isolated review fixture' !== get_option( 'blogname' ) || ! is_plugin_active( 'bhranti/bhranti.php' ) ) {
	throw new RuntimeException( 'The exact isolated, offline, debug-enabled plugin fixture is required.' );
}

/** The provider is process-local and has no API credentials or transport. */
final class Bhranti_Review_Provider extends AbstractProvider {
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata( 'bhranti-offline-review', 'Offline native review fixture', ProviderTypeEnum::server() );
	}
	protected static function createModel( ModelMetadata $metadata, ProviderMetadata $provider ): ModelInterface {
		return new Bhranti_Review_Model( $metadata, $provider );
	}
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new class implements ProviderAvailabilityInterface {
			public function isConfigured(): bool { return true; }
		};
	}
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new class implements ModelMetadataDirectoryInterface {
			public function listModelMetadata(): array { return array( $this->getModelMetadata( 'offline-native' ) ); }
			public function hasModelMetadata( string $id ): bool { return 'offline-native' === $id; }
			public function getModelMetadata( string $id ): ModelMetadata {
				return new ModelMetadata( $id, 'Deterministic native review', array( CapabilityEnum::textGeneration(), CapabilityEnum::imageGeneration(), CapabilityEnum::chatHistory() ), array( new SupportedOption( OptionEnum::systemInstruction() ), new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ), new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ), array( ModalityEnum::image() ) ) ), new SupportedOption( OptionEnum::outputMimeType(), array( 'application/json', 'text/plain', 'image/png' ) ), new SupportedOption( OptionEnum::outputFileType(), array( FileTypeEnum::inline() ) ), new SupportedOption( OptionEnum::outputSchema() ) ) );
			}
		};
	}
}

/** Each planning call consumes exactly one predetermined native SDK result. */
final class Bhranti_Review_Model extends AbstractApiBasedModel implements TextGenerationModelInterface, ImageGenerationModelInterface {
	public static $replies = array();
	public static $calls = 0;
	public static $image_calls = 0;
	public function generateTextResult( array $messages ): GenerativeAiResult {
		++self::$calls;
		if ( ! self::$replies ) { throw new RuntimeException( 'Unscripted native model call in offline review.' ); }
		$reply = array_shift( self::$replies );
		if ( $reply instanceof Throwable ) { throw $reply; }
		return new GenerativeAiResult( 'offline-review', array( new Candidate( new ModelMessage( array( new MessagePart( $reply ) ) ), FinishReasonEnum::stop() ) ), new TokenUsage( 0, 0, 0 ), $this->providerMetadata(), $this->metadata() );
	}
	public function generateImageResult( array $messages ): GenerativeAiResult {
		++self::$image_calls;
		$image = imagecreatetruecolor( 3, 2 );
		ob_start();
		imagepng( $image );
		$bytes = ob_get_clean();
		return new GenerativeAiResult( 'offline-review-image', array( new Candidate( new ModelMessage( array( new MessagePart( new File( base64_encode( $bytes ), 'image/png' ) ) ) ), FinishReasonEnum::stop() ) ), new TokenUsage( 0, 0, 0 ), $this->providerMetadata(), $this->metadata() );
	}

}

$GLOBALS['bre_checks'] = 0;
$GLOBALS['bre_sessions'] = array();
$GLOBALS['bre_http'] = array();
$GLOBALS['bre_mail'] = 0;
$GLOBALS['bre_native_writes'] = array();
$GLOBALS['bre_owner'] = 1;

function bre_check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$GLOBALS['bre_checks'];
}
function bre_rest( $method, $path, $params = array() ) {
	$request = new WP_REST_Request( $method, '/bhranti/v1' . $path );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $params ) );
	return rest_do_request( $request );
}
function bre_session() {
	$response = bre_rest( 'POST', '/sessions' );
	bre_check( 200 === $response->get_status(), 'Native conversation creation succeeds.' );
	$id = (int) $response->get_data()['id'];
	$GLOBALS['bre_sessions'][] = $id;
	return $id;
}
function bre_start( $session, $message ) {
	$response = bre_rest( 'POST', '/sessions/' . $session . '/messages', array( 'content' => $message ) );
	bre_check( 200 === $response->get_status() && 'running' === ( $response->get_data()['run']['status'] ?? '' ), 'A normal human message starts an owned native run: ' . wp_json_encode( $response->get_data() ) );
	$tuple = array( $session, $response->get_data()['run']['id'], $GLOBALS['bre_owner'] );
	bre_check( (bool) wp_next_scheduled( 'bhranti_run_tick', $tuple ), 'An exact owner-bound native Cron event persists.' );
	return $tuple;
}
function bre_private( $tuple ) {
	return get_post_meta( $tuple[0], 'bhranti_run', true );
}
function bre_plan( $tool, $args ) {
	return wp_json_encode( array( 'type' => 'tool', 'content' => 'Perform the requested WordPress change.', 'tool' => $tool, 'args' => wp_json_encode( $args ) ) );
}
function bre_prepare( $tuple, $tool, $args ) {
	Bhranti_Review_Model::$replies[] = bre_plan( $tool, $args );
	$before = Bhranti_Review_Model::$calls;
	$response = bre_rest( 'POST', '/sessions/' . $tuple[0] . '/step', array( 'run_id' => $tuple[1], 'defer_tool' => true ) );
	bre_check( 200 === $response->get_status() && $before + 1 === Bhranti_Review_Model::$calls && ! Bhranti_Review_Model::$replies, 'Exactly one native planning response is consumed.' );
	return $response->get_data();
}
function bre_execute( $tuple, $params = array() ) {
	$before = Bhranti_Review_Model::$calls;
	$response = bre_rest( 'POST', '/sessions/' . $tuple[0] . '/execute', array_merge( array( 'run_id' => $tuple[1], 'prepared_step' => (int) bre_private( $tuple )['step'] ), $params ) );
	bre_check( $before === Bhranti_Review_Model::$calls, 'Prepared execution never regenerates a model plan.' );
	return $response;
}
function bre_finish( $tuple ) {
	Bhranti_Review_Model::$replies[] = wp_json_encode( array( 'type' => 'reply', 'content' => 'The requested change is saved.', 'tool' => 'none', 'args' => '{}' ) );
	$response = bre_rest( 'POST', '/sessions/' . $tuple[0] . '/step', array( 'run_id' => $tuple[1] ) );
	bre_check( 200 === $response->get_status() && 'complete' === ( $response->get_data()['run']['status'] ?? '' ), 'Native chat finishes and exposes its saved history.' );
	bre_check( ! wp_next_scheduled( 'bhranti_run_tick', $tuple ), 'Completion removes the exact native Cron event.' );
	return $response->get_data();
}
function bre_receipt( $tuple, $operation ) {
	$receipts = array_filter( bre_private( $tuple )['receipts'] ?? array(), static function ( $item ) use ( $operation ) { return $item['success'] && $operation === $item['operation']; } );
	bre_check( (bool) $receipts, 'Successful operation has a persisted native receipt: ' . $operation );
	return end( $receipts );
}

$http_guard = static function ( $response, $args, $url ) { $GLOBALS['bre_http'][] = $url; return new WP_Error( 'bhranti_review_no_http', 'The disposable native review fixture has no external network.' ); };
$mail_guard = static function () { ++$GLOBALS['bre_mail']; return true; };
$write_observer = static function ( $response, $handler, $request ) {
	if ( 'GET' !== $request->get_method() && 0 === strpos( $request->get_route(), '/wp/v2/' ) && ! is_wp_error( $response ) && ! rest_ensure_response( $response )->is_error() ) {
		$GLOBALS['bre_native_writes'][] = array( 'path' => $request->get_route(), 'user' => get_current_user_id() );
	}
	return $response;
};
add_filter( 'pre_http_request', $http_guard, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', $mail_guard, PHP_INT_MAX );
add_filter( 'rest_request_after_callbacks', $write_observer, PHP_INT_MAX, 3 );
$registry = new ReflectionProperty( AiClient::class, 'defaultRegistry' );
$registry->setAccessible( true );
$registry->setValue( null, new ProviderRegistry() );
AiClient::setCache( null );
AiClient::setEventDispatcher( null );
AiClient::defaultRegistry()->registerProvider( Bhranti_Review_Provider::class );
WP_Connector_Registry::get_instance()->register( 'bhranti-offline-review', array( 'name' => 'Offline review fixture', 'type' => 'ai_provider', 'authentication' => array( 'method' => 'none' ) ) );

wp_set_current_user( 1 );
$owner = 1;
$users_before = get_users( array( 'fields' => 'ID' ) );
$initial_posts = $GLOBALS['wpdb']->get_results( "SELECT ID,post_content,post_status,post_author FROM {$GLOBALS['wpdb']->posts} WHERE post_type NOT IN ('bhranti_session','bhranti_event') ORDER BY ID", ARRAY_A );
$session = bre_session();
$tuple = bre_start( $session, 'Hello, can you help me make a small edit?' );
$data = bre_finish( $tuple );
bre_check( 2 === count( $data['messages'] ) && $users_before === get_users( array( 'fields' => 'ID' ) ), 'Normal native chat works without creating an account.' );
$secret_session = bre_session();
$secret = 'QAZReviewFakePassword47!';
$calls = Bhranti_Review_Model::$calls;
$rejected = bre_rest( 'POST', '/sessions/' . $secret_session . '/messages', array( 'content' => 'My password: ' . $secret ) );
bre_check( 'bhranti_credentials' === ( $rejected->get_data()['code'] ?? '' ) && ! get_post_meta( $secret_session, 'bhranti_messages', true ) && $calls === Bhranti_Review_Model::$calls, 'Fake credentials are rejected before storage and provider use.' );

$tuple = bre_start( $session, 'Create a draft page called A useful little update.' );
$content = '<!-- wp:paragraph --><p>A small, useful update for our readers.</p><!-- /wp:paragraph -->';
$prepared = bre_prepare( $tuple, 'wp_request', array( 'method' => 'POST', 'path' => '/wp/v2/pages', 'params' => array( 'title' => 'A useful little update', 'content' => $content, 'status' => 'draft' ) ) );
bre_check( 'prepared' === ( $prepared['run']['phase'] ?? '' ) && ! get_page_by_path( 'a-useful-little-update' ), 'Draft plan is visible before any native write.' );
$dirty = bre_execute( $tuple, array( 'unsaved_changes' => true ) );
bre_check( 409 === $dirty->get_status() && 'prepared' === bre_private( $tuple )['phase'], 'Unsaved editor state blocks and preserves the prepared action.' );
$done = bre_execute( $tuple );
bre_check( 200 === $done->get_status(), 'The exact clean prepared action dispatches.' );
$receipt = bre_receipt( $tuple, 'write' );
$bre_page_id = (int) $receipt['target']['id'];
bre_check( $bre_page_id > 0 && $owner === (int) get_post( $bre_page_id )->post_author && $content === get_post_field( 'post_content', $bre_page_id ) && 'draft' === get_post_status( $bre_page_id ), 'Native draft saves valid content and human authorship.' );
$count = count( $GLOBALS['bre_native_writes'] );
bre_execute( $tuple );
bre_check( $count === count( $GLOBALS['bre_native_writes'] ), 'A repeated execute cannot repeat the saved write.' );
bre_finish( $tuple );

$tuple = bre_start( $session, 'Change the page title to A clearer little update.' );
bre_prepare( $tuple, 'wp_request', array( 'method' => 'POST', 'path' => '/wp/v2/pages/' . $bre_page_id, 'params' => array( 'title' => 'A clearer little update' ) ) );
bre_execute( $tuple );
$edit = bre_receipt( $tuple, 'write' );
bre_check( 'A clearer little update' === get_post_field( 'post_title', $bre_page_id ) && $content === get_post_field( 'post_content', $bre_page_id ), 'A small title change preserves existing blocks.' );
bre_finish( $tuple );
$rollback = bre_rest( 'POST', '/sessions/' . $session . '/rollback', array( 'action_id' => $edit['action_id'] ) );
bre_check( 200 === $rollback->get_status() && 'A useful little update' === get_post_field( 'post_title', $bre_page_id ), 'Native chat Undo restores the exact prior title.' );

$tuple = bre_start( $session, 'Move that draft page to the trash.' );
$pending = bre_prepare( $tuple, 'wp_request', array( 'method' => 'DELETE', 'path' => '/wp/v2/pages/' . $bre_page_id ) );
if ( 'prepared' === ( $pending['run']['phase'] ?? '' ) ) { $pending = bre_execute( $tuple )->get_data(); }
bre_check( 'awaiting_confirmation' === ( $pending['run']['status'] ?? '' ) && 'draft' === get_post_status( $bre_page_id ), 'Trash waits for exact human confirmation.' );
$denied = bre_rest( 'POST', '/sessions/' . $session . '/confirm', array( 'confirmation_id' => $pending['confirmation']['id'], 'approved' => false ) );
bre_check( 'cancelled' === ( $denied->get_data()['run']['status'] ?? '' ) && 'draft' === get_post_status( $bre_page_id ), 'Cancel leaves content unchanged and stops the run.' );

$tuple = bre_start( $session, 'Change the draft title to Finished in the background.' );
bre_prepare( $tuple, 'wp_request', array( 'method' => 'POST', 'path' => '/wp/v2/pages/' . $bre_page_id, 'params' => array( 'title' => 'Finished in the background' ) ) );
wp_set_current_user( 0 );
$ticked = Bhranti_Runner::tick( $tuple[0], $tuple[1], $tuple[2] );
bre_check( ! is_wp_error( $ticked ) && 0 === get_current_user_id() && ! Bhranti_Runner::is_background() && 'Finished in the background' === get_post_field( 'post_title', $bre_page_id ), 'A real Cron tick continues the owned prepared task and restores anonymous caller identity.' );
Bhranti_Review_Model::$replies[] = wp_json_encode( array( 'type' => 'reply', 'content' => 'Saved the requested draft title.', 'tool' => 'none', 'args' => '{}' ) );
do_action_ref_array( 'bhranti_run_tick', $tuple );
bre_check( 0 === get_current_user_id() && 'complete' === bre_private( $tuple )['status'] && ! wp_next_scheduled( 'bhranti_run_tick', $tuple ), 'Registered native scheduled callback finishes without a browser or user credential.' );
wp_set_current_user( $owner );

$tuple = bre_start( $session, 'Change the title to This must never be saved.' );
bre_prepare( $tuple, 'wp_request', array( 'method' => 'POST', 'path' => '/wp/v2/pages/' . $bre_page_id, 'params' => array( 'title' => 'This must never be saved' ) ) );
$user = new WP_User( $owner );
$user->add_cap( 'manage_options', false );
wp_set_current_user( 0 );
$denied = Bhranti_Runner::tick( $tuple[0], $tuple[1], $tuple[2] );
bre_check( is_wp_error( $denied ) && 'bhranti_runner_permission' === $denied->get_error_code() && 0 === get_current_user_id() && 'Finished in the background' === get_post_field( 'post_title', $bre_page_id ), 'Revoked human permission stops scheduled execution without fallback identity.' );
$user->remove_cap( 'manage_options' );
wp_set_current_user( $owner );
$stop = bre_rest( 'POST', '/sessions/' . $session . '/cancel', array( 'run_id' => $tuple[1] ) );
bre_check( 200 === $stop->get_status() && 'cancelled' === bre_private( $tuple )['status'], 'Stop releases the exact pending run after permission restoration.' );


$plugin_file = 'bhranti-review-inert/bhranti-review-inert.php';
$plugin_dir = WP_PLUGIN_DIR . '/bhranti-review-inert';
bre_check( ! file_exists( $plugin_dir ) && mkdir( $plugin_dir ), 'A uniquely absent inert fixture plugin directory is created only in the disposable VFS.' );
file_put_contents( WP_PLUGIN_DIR . '/' . $plugin_file, "<?php\n/**\n * Plugin Name: Bhranti offline review fixture\n * Version: 1.0\n */\ndefined('ABSPATH') || exit;\n" );
wp_clean_plugins_cache();
$plugin_args = array( 'method' => 'POST', 'path' => '/wp/v2/plugins/bhranti-review-inert/bhranti-review-inert', 'params' => array( 'status' => 'active' ) );
$tuple = bre_start( $session, 'Activate the offline review fixture plugin.' );
$pending = bre_prepare( $tuple, 'plugin_request', $plugin_args );
if ( 'prepared' === ( $pending['run']['phase'] ?? '' ) ) { $pending = bre_execute( $tuple )->get_data(); }
bre_check( 'awaiting_confirmation' === ( $pending['run']['status'] ?? '' ) && ! is_plugin_active( $plugin_file ), 'A native plugin proposal never activates silently.' );
$cancelled = bre_rest( 'POST', '/sessions/' . $session . '/confirm', array( 'confirmation_id' => $pending['confirmation']['id'], 'approved' => false ) );
bre_check( 'cancelled' === ( $cancelled->get_data()['run']['status'] ?? '' ) && ! is_plugin_active( $plugin_file ), 'Plugin Cancel stops the run without activation.' );
$tuple = bre_start( $session, 'Please activate that offline review fixture plugin.' );
$pending = bre_prepare( $tuple, 'plugin_request', $plugin_args );
if ( 'prepared' === ( $pending['run']['phase'] ?? '' ) ) { $pending = bre_execute( $tuple )->get_data(); }
$approved = bre_rest( 'POST', '/sessions/' . $session . '/confirm', array( 'confirmation_id' => $pending['confirmation']['id'], 'approved' => true ) );
bre_check( 200 === $approved->get_status() && is_plugin_active( $plugin_file ), 'The exact human approval dispatches native plugin activation.' );
$plugin_records = get_post_meta( $session, 'bhranti_plugin_actions', true );
$plugin_action_id = array_key_last( $plugin_records );
$replayed = bre_rest( 'POST', '/sessions/' . $session . '/confirm', array( 'confirmation_id' => $pending['confirmation']['id'], 'approved' => true ) );
bre_check( 409 === $replayed->get_status() && is_plugin_active( $plugin_file ), 'A consumed plugin approval cannot be replayed.' );
bre_finish( $tuple );
$plugin_undo = bre_rest( 'POST', '/sessions/' . $session . '/rollback', array( 'action_id' => $plugin_action_id ) );
bre_check( 200 === $plugin_undo->get_status() && ! is_plugin_active( $plugin_file ), 'Explicit native plugin Undo restores only its activation state.' );

$tuple = bre_start( $session, 'Generate one small offline raster illustration for this test.' );
bre_prepare( $tuple, 'generate_image', array( 'prompt' => 'A small offline raster fixture.', 'title' => 'Offline raster fixture', 'alt_text' => 'A small offline raster fixture.', 'format' => 'png' ) );
bre_execute( $tuple );
$image = bre_receipt( $tuple, 'image' );
$image_id = (int) $image['target']['id'];
bre_check( 1 === Bhranti_Review_Model::$image_calls && 'attachment' === get_post_type( $image_id ) && is_file( get_attached_file( $image_id ) ) && $owner === (int) get_post( $image_id )->post_author && 'image/png' === get_post_mime_type( $image_id ), 'One native image DTO becomes one supported real Media Library attachment authored by the human.' );
$recovery_args = array( 'method' => 'POST', 'path' => '/wp/v2/pages/' . $bre_page_id, 'params' => array( 'title' => 'Illustrated small update' ) );
bre_prepare( $tuple, 'wp_request', $recovery_args );
bre_execute( $tuple );
$settled_receipts = bre_private( $tuple )['receipts'];
$settled_writes = count( $GLOBALS['bre_native_writes'] );
Bhranti_Review_Model::$replies[] = new WordPress\AiClient\Providers\Http\Exception\NetworkException( 'A known offline review timeout.' );
$retry = bre_rest( 'POST', '/sessions/' . $session . '/step', array( 'run_id' => $tuple[1] ) );
$recovering = bre_private( $tuple );
bre_check( 200 === $retry->get_status() && 'running' === $recovering['status'] && 1 === $recovering['recovery_stage'] && $settled_receipts === $recovering['receipts'], 'A known native planning failure preserves successful writes and image receipts for automatic recovery.' );
// Advance only this disposable run's persisted retry clock; no production retry policy is changed.
$recovering['retry_at'] = time() - 1;
update_post_meta( $session, 'bhranti_run', wp_slash( $recovering ) );
bre_prepare( $tuple, 'generate_image', array( 'prompt' => 'A small offline raster fixture.', 'title' => 'Offline raster fixture', 'alt_text' => 'A small offline raster fixture.', 'format' => 'png' ) );
bre_check( 1 === Bhranti_Review_Model::$image_calls && $settled_receipts === bre_private( $tuple )['receipts'], 'Recovering the same illustration reuses its native receipt without generating another image.' );
bre_prepare( $tuple, 'wp_request', $recovery_args );
bre_check( $settled_writes === count( $GLOBALS['bre_native_writes'] ) && $settled_receipts === bre_private( $tuple )['receipts'] && 'Illustrated small update' === get_post_field( 'post_title', $bre_page_id ), 'Recovered proposals do not repeat a completed native write.' );
bre_finish( $tuple );

$memory = bre_rest( 'GET', '/memory' )->get_data();
bre_check( ! empty( $memory['available'] ) && is_string( $memory['revision'] ?? null ), 'Human Settings reaches encrypted memory through native REST.' );
$tuple = bre_start( $session, 'Please remember: I prefer concise and friendly writing.' );
bre_prepare( $tuple, 'bhranti_memory', array( 'method' => 'POST', 'category' => 'tone', 'text' => 'I prefer concise and friendly writing', 'evidence' => 'Please remember: I prefer concise and friendly writing.', 'revision' => $memory['revision'] ) );
bre_execute( $tuple );
bre_finish( $tuple );
$memory = bre_rest( 'GET', '/memory' )->get_data();
bre_check( 1 === count( $memory['preferences'] ?? array() ) && 'I prefer concise and friendly writing' === $memory['preferences'][0]['text'], 'A real chat tool saves only the requested owned preference.' );
$export = Bhranti_Privacy::export( get_userdata( $owner )->user_email );
bre_check( false !== strpos( wp_json_encode( $export ), 'I prefer concise and friendly writing' ), 'Native privacy export includes owned saved memory.' );
$audit = Bhranti_Audit::query( array( 'session_id' => $session, 'per_page' => 100 ), $owner );
bre_check( ! empty( $audit['events'] ), 'Real chat actions produce durable paginated activity.' );
$run_event = null;
foreach ( $audit['events'] as $event ) {
	if ( ! empty( $event['details']['run_id'] ) ) {
		$run_event = $event;
		break;
	}
}
bre_check( is_array( $run_event ), 'Conversation activity includes an exact task ID.' );
$task_events = Bhranti_Audit::query( array( 'run_id' => $run_event['details']['run_id'], 'per_page' => 100 ), $owner );
bre_check( ! is_wp_error( $task_events ) && ! empty( $task_events['events'] ), 'Task activity filter returns its native saved events.' );
foreach ( $task_events['events'] as $event ) {
	bre_check( $run_event['details']['run_id'] === ( $event['details']['run_id'] ?? '' ) && $session === (int) $event['session_id'], 'Task activity contains only exact linked events.' );
}
$raw_event = json_decode( Bhranti_Audit::export_event( $run_event ), true );
bre_check( is_array( $raw_event ) && 'bhranti.activity' === $raw_event['format'] && $owner === (int) $raw_event['requester']['id'] && $owner === (int) $raw_event['actor']['id'] && $session === (int) $raw_event['session_id'] && $run_event['details']['run_id'] === $raw_event['run_id'] && false === strpos( wp_json_encode( $raw_event ), $secret ), 'Raw NDJSON keeps human identity and exact task linkage without a rejected credential.' );
foreach ( $audit['events'] as $event ) {
	if ( in_array( $event['type'], array( 'action_started', 'action_completed' ), true ) ) {
		bre_check( $owner === (int) $event['requester_id'] && $owner === (int) $event['actor_id'], 'Native action audit identifies the real human executor.' );
	}
}
foreach ( $GLOBALS['bre_native_writes'] as $write ) {
	bre_check( $owner === $write['user'], 'Every observed core mutation executes as its submitting human.' );
}
$before_erase = get_post_field( 'post_content', $bre_page_id );
do {
	$erased = Bhranti_Privacy::erase( get_userdata( $owner )->user_email );
	bre_check( empty( $erased['items_retained'] ), 'Native privacy erasure reports no retained private fixtures.' );
} while ( ! $erased['done'] );
bre_check( ! get_post( $session ) && ! get_post( $secret_session ) && array() === Bhranti_Memory::export_user( $owner )['preferences'] && $before_erase === get_post_field( 'post_content', $bre_page_id ), 'Native erasure removes chats and memory while preserving authored content.' );
bre_check( array() === $GLOBALS['bre_http'] && 0 === $GLOBALS['bre_mail'] && $users_before === get_users( array( 'fields' => 'ID' ) ), 'The complete chat/action flow makes no external HTTP, email or managed account.' );
foreach ( $initial_posts as $post ) {
	bre_check( $post === $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( "SELECT ID,post_content,post_status,post_author FROM {$GLOBALS['wpdb']->posts} WHERE ID=%d", $post['ID'] ), ARRAY_A ), 'Unrelated initial content remains byte-for-byte unchanged.' );
}
remove_filter( 'rest_request_after_callbacks', $write_observer, PHP_INT_MAX );
remove_filter( 'pre_http_request', $http_guard, PHP_INT_MAX );
remove_filter( 'pre_wp_mail', $mail_guard, PHP_INT_MAX );
echo wp_json_encode( array( 'status' => 'complete', 'checks' => $GLOBALS['bre_checks'], 'model_calls' => Bhranti_Review_Model::$calls, 'image_calls' => Bhranti_Review_Model::$image_calls, 'native_writes' => count( $GLOBALS['bre_native_writes'] ), 'external_http' => count( $GLOBALS['bre_http'] ), 'fixture_page' => $bre_page_id, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION ) );
