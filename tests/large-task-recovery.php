<?php
/**
 * Native REST/Runner recovery in one disposable, offline WordPress clone.
 *
 * BHRANTI_RECOVERY_NATIVE_WP_ROOT=/private/tmp/bhranti-recovery-native-qa-20261015 php tests/large-task-recovery.php
 * Only this private clone is bootstrapped. No remote AI, HTTP or mail is allowed.
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
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

$lr_root = realpath( getenv( 'BHRANTI_RECOVERY_NATIVE_WP_ROOT' ) ?: '' );
if ( 'cli' !== PHP_SAPI || '/private/tmp/bhranti-recovery-native-qa-20261015' !== $lr_root || ! is_file( $lr_root . '/.bhranti-recovery-fixture.json' ) || realpath( $lr_root . '/wp-content/database/.ht.sqlite' ) !== $lr_root . '/wp-content/database/.ht.sqlite' ) {
	fwrite( STDERR, "Use only the explicitly prepared private recovery QA clone.\n" );
	exit( 1 );
}
define( 'ABSPATH', $lr_root . '/' );
define( 'WP_USE_THEMES', false );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
require $lr_root . '/wp-load.php';
if ( 'https://bhranti-checker.invalid' !== get_option( 'home' ) || get_option( 'active_plugins' ) || ! DISABLE_WP_CRON || realpath( FQDB ) !== $lr_root . '/wp-content/database/.ht.sqlite' ) {
	throw new RuntimeException( 'Isolated WordPress identity/configuration guard failed.' );
}
require dirname( __DIR__ ) . '/bhranti.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
Bhranti_Workspace::register_storage();
Bhranti_Audit::register_storage();

$lr_checks = $lr_http = $lr_mail = $lr_text_calls = $lr_image_calls = 0;
$lr_owner = $lr_actor = 0;
$lr_sessions = $lr_runs = $lr_pages = $lr_images = $lr_queue = $lr_prompts = $lr_native_writes = array();
$lr_responses = array();
$lr_marker = 'bhranti-recovery-' . str_replace( '-', '', wp_generate_uuid4() );
$lr_original_user = get_current_user_id();
$lr_initial_users = array_map( 'intval', get_users( array( 'fields' => 'ID' ) ) );
$lr_initial_posts = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) );
$lr_initial_options = array();
foreach ( array( 'wp_user_roles', 'bhranti_actor_id', 'bhranti_role_owned', 'bhranti_actor_setup_lock', 'bhranti_settings', 'cron' ) as $lr_key ) {
	$lr_initial_options[ $lr_key ] = get_option( $lr_key, null );
}
$lr_baseline = array();
foreach ( $lr_initial_posts as $lr_id ) {
	$lr_baseline[ $lr_id ] = hash( 'sha256', wp_json_encode( array( get_post( $lr_id ), get_post_meta( $lr_id ) ) ) );
}
$lr_registry_property = new ReflectionProperty( AiClient::class, 'defaultRegistry' );
$lr_registry_property->setAccessible( true );
$lr_original_registry = AiClient::defaultRegistry();

function lr_check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
	++$GLOBALS['lr_checks'];
}
function lr_rest( $method, $path, $body = array() ) {
	$request = new WP_REST_Request( $method, '/bhranti/v1' . $path );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	return rest_do_request( $request );
}
function lr_state( $tuple ) {
	return get_post_meta( $tuple[0], 'bhranti_run', true );
}
function lr_plan( $tool, $args ) {
	return wp_json_encode( array( 'type' => 'tool', 'content' => 'Continue the exact isolated recovery fixture.', 'tool' => $tool, 'args' => wp_json_encode( $args ) ) );
}
function lr_start( $suffix ) {
	wp_set_current_user( $GLOBALS['lr_owner'] );
	$created = lr_rest( 'POST', '/sessions' );
	lr_check( 200 === $created->get_status(), 'Actual native REST creates the owned private conversation.' );
	$id = $created->get_data()['id'];
	$GLOBALS['lr_sessions'][] = $id;
	$started = lr_rest( 'POST', '/sessions/' . $id . '/messages', array( 'content' => $GLOBALS['lr_marker'] . ' ' . $suffix . ': prepare only the agreed private draft in small verified native steps.', 'context' => array() ) );
	lr_check( 200 === $started->get_status(), 'Actual native REST accepts the fixture run without generation.' );
	$tuple = array( $id, $started->get_data()['run']['id'], $GLOBALS['lr_owner'] );
	$GLOBALS['lr_runs'][] = $tuple;
	lr_check( (bool) wp_next_scheduled( 'bhranti_run_tick', $tuple ), 'WordPress really persists the exact owned run Cron event.' );
	return $tuple;
}
function lr_tick( $tuple ) {
	$before = get_current_user_id();
	$result = Bhranti_Runner::tick( $tuple[0], $tuple[1], $tuple[2] );
	lr_check( $before === get_current_user_id() && ! Bhranti_Runner::is_background(), 'Native tick restores caller identity and background scope.' );
	return $result;
}
function lr_model( $tuple, $reply ) {
	$GLOBALS['lr_responses'][] = $reply;
	$before = $GLOBALS['lr_text_calls'];
	$result = lr_tick( $tuple );
	lr_check( $before + 1 === $GLOBALS['lr_text_calls'] && ! $GLOBALS['lr_responses'], 'One actual native SDK generation consumes one local response.' );
	return $result;
}
function lr_tool( $tuple, $tool, $args ) {
	lr_model( $tuple, lr_plan( $tool, $args ) );
	if ( '' === ( lr_state( $tuple )['phase'] ?? '' ) && ! empty( lr_state( $tuple )['duplicate_proposals'] ) ) {
		return lr_state( $tuple );
	}
	lr_check( 'prepared' === lr_state( $tuple )['phase'], 'Model plan persists as a prepared canonical action.' );
	$before = $GLOBALS['lr_text_calls'];
	lr_tick( $tuple );
	lr_check( $before === $GLOBALS['lr_text_calls'], 'Prepared execution never samples another model response.' );
	return lr_state( $tuple );
}
function lr_due( $tuple ) {
	$run = lr_state( $tuple );
	lr_check( 'running' === $run['status'] && isset( $run['retry_at'] ), 'Only a real persisted model-retry deadline is advanced by the test clock.' );
	$run['retry_at'] = time() - 1;
	update_post_meta( $tuple[0], 'bhranti_run', wp_slash( $run ) );
}
function lr_cancel( $tuple ) {
	wp_set_current_user( $GLOBALS['lr_owner'] );
	$response = lr_rest( 'POST', '/sessions/' . $tuple[0] . '/cancel', array( 'run_id' => $tuple[1] ) );
	lr_check( 200 === $response->get_status() && 'cancelled' === lr_state( $tuple )['status'], 'Explicit native Stop cancels the exact owned run.' );
}
function lr_receipt( $run, $operation ) {
	$receipts = array_values( array_filter( $run['receipts'] ?? array(), static function ( $receipt ) use ( $operation ) { return $receipt['success'] && $receipt['operation'] === $operation; } ) );
	lr_check( (bool) $receipts, 'Successful native operation has an authoritative receipt.' );
	return end( $receipts );
}
function lr_recover( $tuple ) {
	lr_model( $tuple, new WordPress\AiClient\Providers\Http\Exception\NetworkException( 'Synthetic private recovery timeout.' ) );
	lr_check( 1 === lr_state( $tuple )['recovery_stage'], 'A known failed model request persists the smaller strategy.' );
	lr_due( $tuple );
}
function lr_patch( $page, $text ) {
	return array( 'method' => 'POST', 'path' => '/wp/v2/pages/' . $page, 'content_patch' => array( 'revision' => hash( 'sha256', get_post_field( 'post_content', $page ) ), 'operation' => 'append', 'content' => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->' ) );
}
function lr_prepare( $tuple, $args ) {
	lr_model( $tuple, lr_plan( 'wp_request', $args ) );
	lr_check( 'prepared' === lr_state( $tuple )['phase'], 'Native model plan prepares the exact reviewed write.' );
}
function lr_settings( $key, $value ) {
	$settings = lr_rest( 'GET', '/settings' )->get_data();
	$settings['config']['permissions'][ $key ] = $value;
	$saved = lr_rest( 'POST', '/settings', array( 'config' => $settings['config'], 'revision' => $settings['revision'] ) );
	lr_check( 200 === $saved->get_status(), 'Native revision-checked settings save changes only the fixture guardrail.' );
}

final class Recovery_QA_Provider extends AbstractProvider {
	protected static function createProviderMetadata(): ProviderMetadata { return new ProviderMetadata( 'bhranti-recovery-offline', 'Private offline recovery fixture', ProviderTypeEnum::server() ); }
	protected static function createModel( ModelMetadata $metadata, ProviderMetadata $provider ): ModelInterface { return new Recovery_QA_Model( $metadata, $provider ); }
	protected static function createProviderAvailability(): ProviderAvailabilityInterface { return new class implements ProviderAvailabilityInterface { public function isConfigured(): bool { return true; } }; }
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new class implements ModelMetadataDirectoryInterface {
			public function listModelMetadata(): array { return array( $this->getModelMetadata( 'offline-recovery' ) ); }
			public function hasModelMetadata( string $id ): bool { return 'offline-recovery' === $id; }
			public function getModelMetadata( string $id ): ModelMetadata {
				return new ModelMetadata( $id, 'Offline recovery model', array( CapabilityEnum::textGeneration(), CapabilityEnum::imageGeneration(), CapabilityEnum::chatHistory() ), array( new SupportedOption( OptionEnum::systemInstruction() ), new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ), array( ModalityEnum::text(), ModalityEnum::image() ) ) ), new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ), array( ModalityEnum::image() ) ) ), new SupportedOption( OptionEnum::outputMimeType(), array( 'application/json', 'text/plain', 'image/png' ) ), new SupportedOption( OptionEnum::outputSchema() ), new SupportedOption( OptionEnum::outputFileType(), array( FileTypeEnum::inline() ) ) ) );
			}
		};
	}
}
final class Recovery_QA_Model extends AbstractApiBasedModel implements TextGenerationModelInterface, ImageGenerationModelInterface {
	public function generateTextResult( array $messages ): GenerativeAiResult {
		++$GLOBALS['lr_text_calls'];
		$GLOBALS['lr_prompts'][] = array( 'messages' => $messages, 'config' => $this->getConfig()->toArray() );
		if ( ! $GLOBALS['lr_responses'] ) { throw new RuntimeException( 'Unexpected fixture model request.' ); }
		$value = array_shift( $GLOBALS['lr_responses'] );
		if ( $value instanceof Throwable ) { throw $value; }
		if ( is_callable( $value ) ) { $value = $value( $messages, $this->getConfig()->toArray() ); }
		return $this->result( new MessagePart( $value ) );
	}
	public function generateImageResult( array $messages ): GenerativeAiResult {
		++$GLOBALS['lr_image_calls'];
		$image = imagecreatetruecolor( 3, 2 );
		ob_start(); imagepng( $image ); $bytes = ob_get_clean(); unset( $image );
		return $this->result( new MessagePart( new File( base64_encode( $bytes ), 'image/png' ) ) );
	}
	private function result( $part ): GenerativeAiResult {
		return new GenerativeAiResult( 'offline-recovery-result', array( new Candidate( new ModelMessage( array( $part ) ), FinishReasonEnum::stop() ) ), new TokenUsage( 0, 0, 0 ), $this->providerMetadata(), $this->metadata() );
	}
}

$lr_http_guard = static function () { ++$GLOBALS['lr_http']; return new WP_Error( 'recovery_qa_no_http', 'HTTP disabled in private QA.' ); };
$lr_mail_guard = static function () { ++$GLOBALS['lr_mail']; return true; };
$lr_schedule = static function ( $pre, $event ) {
	if ( 'bhranti_run_tick' === ( $event->hook ?? '' ) && in_array( (int) ( $event->args[0] ?? 0 ), $GLOBALS['lr_sessions'], true ) && (int) ( $event->args[2] ?? 0 ) === $GLOBALS['lr_owner'] ) {
		$GLOBALS['lr_queue'][] = array( 'args' => $event->args, 'timestamp' => $event->timestamp );
		return $pre;
	}
	return new WP_Error( 'recovery_qa_owned_cron_only', 'Only the exact private fixture run may be scheduled.' );
};
$lr_write_observer = static function ( $response, $handler, $request ) {
	if ( in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) && preg_match( '#^/wp/v2/pages(?:/[0-9]+)?$#', $request->get_route() ) && ! is_wp_error( $response ) && ! rest_ensure_response( $response )->is_error() ) {
		$GLOBALS['lr_native_writes'][] = array( 'route' => $request->get_route(), 'method' => $request->get_method() );
	}
	return $response;
};
add_filter( 'pre_http_request', $lr_http_guard, PHP_INT_MAX );
add_filter( 'pre_wp_mail', $lr_mail_guard, PHP_INT_MAX );
add_filter( 'pre_schedule_event', $lr_schedule, PHP_INT_MAX, 2 );
add_filter( 'rest_request_after_callbacks', $lr_write_observer, PHP_INT_MAX, 3 );
$lr_failure = null;
$lr_clean = true;
try {
	$lr_owner = wp_insert_user( array( 'user_login' => $lr_marker, 'user_pass' => wp_generate_password( 40 ), 'user_email' => $lr_marker . '@example.invalid', 'role' => 'administrator' ) );
	lr_check( is_int( $lr_owner ), 'Fresh isolated administrator fixture exists.' );
	wp_set_current_user( $lr_owner );
	$lr_actor = Bhranti_Actions::ensure_actor();
	lr_check( is_int( $lr_actor ) && ! in_array( $lr_actor, $lr_initial_users, true ), 'Managed actor is newly owned by this private fixture.' );
	$lr_registry_property->setValue( null, new ProviderRegistry() );
	AiClient::defaultRegistry()->registerProvider( Recovery_QA_Provider::class );
	AiClient::defaultRegistry()->setHttpTransporter( new class implements WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface {
		public function send( WordPress\AiClient\Providers\Http\DTO\Request $request, ?WordPress\AiClient\Providers\Http\DTO\RequestOptions $options = null ): WordPress\AiClient\Providers\Http\DTO\Response { ++$GLOBALS['lr_http']; throw new RuntimeException( 'Transport forbidden in private native QA.' ); }
	} );
	WP_Connector_Registry::get_instance()->register( 'bhranti-recovery-offline', array( 'name' => 'Private offline fixture', 'type' => 'ai_provider', 'authentication' => array( 'method' => 'none' ) ) );
	if ( function_exists( 'bhranti_native_recovery_scenario' ) ) {
		bhranti_native_recovery_scenario();
	} else {
	$main = lr_start( 'main' );
	lr_tool( $main, 'bhranti_task', array( 'method' => 'GET' ) );
	$notes = array( 'revision' => Bhranti_Task::state( $main[0] )['revision'], 'objective' => 'Save the agreed small draft and one fictional illustration.', 'decisions' => array( 'Preserve the original paragraph and append two native sections.' ), 'outcomes' => array( array( 'id' => 'draft', 'title' => 'Save the complete draft', 'status' => 'in_progress', 'evidence' => array() ), array( 'id' => 'image', 'title' => 'Save the agreed illustration', 'status' => 'pending', 'evidence' => array() ) ), 'checks' => array( array( 'id' => 'readback', 'title' => 'Read back the saved draft', 'status' => 'pending', 'evidence' => array() ) ) );
	lr_tool( $main, 'bhranti_task', array( 'method' => 'POST', 'params' => $notes ) );
	$notes = Bhranti_Task::state( $main[0] );
	$image_args = array( 'prompt' => 'A fictional shared power circuit illustration.', 'title' => 'Recovery circuit illustration', 'alt_text' => 'A fictional shared circuit illustration.', 'format' => 'png' );
	$run = lr_tool( $main, 'generate_image', $image_args );
	$image_receipt = lr_receipt( $run, 'image' );
	$image_id = $image_receipt['target']['id']; $lr_images[] = $image_id;
	lr_check( 1 === $lr_image_calls && 'attachment' === get_post_type( $image_id ) && is_file( get_attached_file( $image_id ) ), 'One native image DTO creates one real isolated attachment/file.' );
	$initial = '<!-- wp:paragraph --><p>Original agreed draft.</p><!-- /wp:paragraph -->';
	$create = array( 'method' => 'POST', 'path' => '/wp/v2/pages', 'params' => array( 'title' => $lr_marker . ' draft', 'status' => 'draft', 'content' => $initial ) );
	$run = lr_tool( $main, 'wp_request', $create );
	$page_receipt = lr_receipt( $run, 'write' );
	$page_id = $page_receipt['target']['id']; $lr_pages[] = $page_id;
	lr_check( 'draft' === get_post_status( $page_id ) && 1 === count( $lr_native_writes ), 'One native draft write precedes model recovery.' );
	$before_receipts = $run['receipts']; $before_history = $run['history'];
	$network = new WordPress\AiClient\Providers\Http\Exception\NetworkException( 'Synthetic private recovery timeout.' );
	lr_model( $main, $network );
	$recovering = lr_state( $main );
	lr_check( 'running' === $recovering['status'] && 1 === $recovering['recovery_stage'] && $before_history === $recovering['history'] && $before_receipts === $recovering['receipts'], 'First native failed model request persists stage one without losing settled work.' );
	lr_check( $notes === Bhranti_Task::state( $main[0] ), 'Native durable objective and confirmed decisions survive model recovery unchanged.' );
	$calls = $lr_text_calls;
	lr_tick( $main );
	lr_check( $calls === $lr_text_calls && end( $lr_queue )['timestamp'] >= $recovering['retry_at'] && wp_next_scheduled( 'bhranti_run_tick', $main ) >= $recovering['retry_at'], 'Browser-closed Runner respects the saved retry deadline with an actual native Cron event and no generation.' );
	lr_due( $main );
	$repeat_image = $image_args; $repeat_image['prompt'] = 'Reworded prompt for the same already completed illustration.';
	$run = lr_tool( $main, 'generate_image', $repeat_image );
	lr_check( 1 === $lr_image_calls && $before_receipts === $run['receipts'], 'Recovery reuses the exact successful image receipt despite a renamed prompt.' );
	lr_check( false !== strpos( end( $run['history'] )['content'], $image_receipt['id'] ), 'Skipped image feedback names the original successful receipt, not a fabricated result.' );
	$prompt = end( $lr_prompts );
	$prompt_messages = wp_json_encode( array_map( static function ( $message ) { return $message->toArray(); }, $prompt['messages'] ) );
	lr_check( false !== strpos( $prompt['config']['systemInstruction'], 'below 4000 UTF-8 bytes' ) && false !== strpos( $prompt_messages, 'saved_resources' ) && false !== strpos( $prompt_messages, $image_receipt['id'] ), 'Native SDK receives the smaller durable strategy and actual saved-resource references.' );
	$run = lr_tool( $main, 'wp_request', $create );
	lr_check( 1 === count( $lr_native_writes ) && $before_receipts === $run['receipts'], 'Recovery never recreates the already saved native draft.' );
	$fragments = array( '<!-- wp:heading --><h2 class="wp-block-heading">First smaller section</h2><!-- /wp:heading -->', '<!-- wp:paragraph --><p>Second smaller section preserves the first.</p><!-- /wp:paragraph -->' );
	foreach ( $fragments as $fragment ) {
		$patch = array( 'method' => 'POST', 'path' => '/wp/v2/pages/' . $page_id, 'content_patch' => array( 'revision' => hash( 'sha256', get_post_field( 'post_content', $page_id ) ), 'operation' => 'append', 'content' => $fragment ) );
		$run = lr_tool( $main, 'wp_request', $patch );
		lr_check( false !== strpos( get_post_field( 'post_content', $page_id ), $fragment ), 'Actual native content patch appends one bounded fragment.' );
		lr_check( 1 === $run['recovery_stage'], 'Successful smaller writes retain the durable recovery strategy.' );
	}
	foreach ( array( 'design-composition', 'content-operations' ) as $skill ) { lr_tool( $main, 'bhranti_skill', array( 'name' => $skill ) ); }
	lr_tool( $main, 'bhranti_inspect', array( 'resource' => 'content', 'path' => '/wp/v2/pages/' . $page_id ) );
	lr_tool( $main, 'wp_request', array( 'method' => 'GET', 'path' => '/wp/v2/media/' . $image_id, 'params' => array( 'context' => 'edit', '_fields' => 'id,source_url,alt_text,media_details' ) ) );
	$run = lr_tool( $main, 'wp_request', array( 'method' => 'GET', 'path' => '/wp/v2/pages/' . $page_id, 'params' => array( 'context' => 'edit', '_fields' => array( 'id', 'title.raw', 'content.raw', 'status' ) ) ) );
	$readback = lr_receipt( $run, 'read' );
	lr_check( $readback['entity'] === 'page:' . $page_id && 3 === count( $lr_native_writes ) && 1 === $lr_image_calls, 'Native saved readback follows only one create and two section writes; image is not regenerated.' );
	$final_write = lr_receipt( $run, 'write' );
	$notes['outcomes'][0]['status'] = 'completed'; $notes['outcomes'][0]['evidence'] = array( $final_write['id'] );
	$notes['outcomes'][1]['status'] = 'completed'; $notes['outcomes'][1]['evidence'] = array( $image_receipt['id'] );
	$notes['checks'][0]['status'] = 'read_back'; $notes['checks'][0]['evidence'] = array( $final_write['id'], $readback['id'] );
	lr_tool( $main, 'bhranti_task', array( 'method' => 'POST', 'params' => $notes ) );
	lr_check( ! Bhranti_Task::remaining( $main[0] ), 'Native task validation accepts only actual successful image/write and later raw-read evidence.' );
	$settled = lr_state( $main );
	$history_commands = array_filter( array_map( static function ( $message ) { return 'assistant' === $message['role'] ? json_decode( $message['content'], true ) : null; }, $settled['history'] ) );
	lr_check( ! array_filter( $history_commands, static function ( $command ) { return 'generate_image' === ( $command['tool'] ?? '' ) || '/wp/v2/pages' === ( $command['args']['path'] ?? '' ); } ), 'Real six-group compaction has removed the early image generation and page-creation payloads.' );
	$prompt = end( $lr_prompts );
	$checkpoint = '';
	foreach ( $prompt['messages'] as $message ) {
		foreach ( $message->getParts() as $part ) { if ( null !== $part->getText() && 0 === strpos( $part->getText(), 'Task checkpoint (' ) ) { $checkpoint = $part->getText(); } }
	}
	$checkpoint_json = json_decode( substr( $checkpoint, strpos( $checkpoint, ': ' ) + 2 ), true );
	$resource_entities = array_column( $checkpoint_json['saved_resources'] ?? array(), 'entity' );
	lr_check( in_array( 'attachment:' . $image_id, $resource_entities, true ) && in_array( 'page:' . $page_id, $resource_entities, true ) && $notes['objective'] === $checkpoint_json['task']['objective'], 'Actual native SDK receives saved image/page identities and the agreed objective after original payload compaction.' );
	lr_check( 2 === count( $settled['loaded_skills'] ) && 1 === $settled['recovery_stage'] && false !== strpos( $prompt['config']['systemInstruction'], 'below 4000 UTF-8 bytes' ), 'Compaction preserves verified specialist selection and the smaller recovery strategy.' );
	$lr_responses[] = wp_json_encode( array( 'type' => 'reply', 'content' => 'The exact isolated draft is saved and read back.', 'tool' => 'none', 'args' => '{}' ) );
	$calls = $lr_text_calls; $caller = get_current_user_id();
	do_action_ref_array( 'bhranti_run_tick', $main );
	lr_check( $calls + 1 === $lr_text_calls && ! $lr_responses && $caller === get_current_user_id() && ! Bhranti_Runner::is_background(), 'Actual registered WordPress Cron callback generates once and restores caller identity.' );
	lr_check( 'complete' === lr_state( $main )['status'], 'Cron-only native continuation completes without human Resume or replacement request.' );
	lr_check( ! wp_next_scheduled( 'bhranti_run_tick', $main ), 'Terminal completion clears the actual owned Cron event.' );
	$actual_content = get_post_field( 'post_content', $page_id );
	$expected_blocks = array_merge( parse_blocks( $initial ), parse_blocks( $fragments[0] ), parse_blocks( $fragments[1] ) );
	$actual_blocks = array_values( array_filter( parse_blocks( $actual_content ), static function ( $block ) { return null !== $block['blockName']; } ) );
	lr_check( $expected_blocks === $actual_blocks, 'Both smaller fragments preserve the exact native block content and order.' );

	// Exactly one automatic continuation; no synthetic Resume or replacement run.
	$bounded = lr_start( 'bounded-outage' );
	$bounded_initial = lr_state( $bounded );
	$before_calls = $lr_text_calls;
	foreach ( array( 5, 15, 30, 60, 5, 15, 30, null ) as $index => $delay ) {
		$before = time();
		lr_model( $bounded, new WordPress\AiClient\Providers\Http\Exception\NetworkException( 'Synthetic private outage.' ) );
		$saved = lr_state( $bounded );
		lr_check( $bounded[1] === $saved['id'] && $bounded_initial['history'] === $saved['history'] && $bounded_initial['configuration'] === $saved['configuration'] && $index + 1 === $saved['step'], 'Automatic recovery retains run identity, history, captured configuration and exact step count.' );
		$prompt = end( $lr_prompts );
		$budget = 0 === $index ? 8000 : ( $index <= 3 ? 4000 : 2000 );
		lr_check( false !== strpos( $prompt['config']['systemInstruction'], 'below ' . $budget . ' UTF-8 bytes' ), 'Actual native SDK request receives the persisted 8000/4000/2000-byte strategy.' );
		if ( null !== $delay ) {
			lr_check( 'running' === $saved['status'] && $saved['retry_at'] >= $before + $delay && $saved['retry_at'] <= time() + $delay && ( $index < 3 ? 1 : 2 ) === $saved['recovery_stage'], 'Native scheduled model retries honor each bounded delay and the single stage-two transition.' );
			$calls = $lr_text_calls;
			$early = lr_rest( 'POST', '/sessions/' . $bounded[0] . '/step', array( 'run_id' => $bounded[1], 'defer_tool' => true ) );
			lr_tick( $bounded );
			lr_check( 200 === $early->get_status() && $calls === $lr_text_calls && $saved === lr_state( $bounded ), 'Foreground REST and background ticks both respect the same future deadline without generation.' );
			lr_due( $bounded );
		}
	}
	lr_check( $before_calls + 8 === $lr_text_calls && 'failed' === $saved['status'] && 1 === $saved['auto_recoveries'] && 'model_request' === $saved['resume_checkpoint']['kind'], 'Eight consecutive model failures stop at a proven model-only checkpoint.' );
	$calls = $lr_text_calls;
	$public = lr_rest( 'GET', '/sessions/' . $bounded[0] )->get_data();
	lr_check( $public['run']['can_resume'] && false === lr_tick( $bounded ) && $calls === $lr_text_calls, 'Only explicit human Resume can renew a terminal known model failure.' );

	// Native wrapper classification must not convert authorization or usage refusal into retries.
	foreach ( array( 401, 403, 429 ) as $status ) {
		$denied = lr_start( 'denied-' . $status );
		lr_model( $denied, new WordPress\AiClient\Providers\Http\Exception\ClientException( 'Synthetic private refusal.', $status ) );
		$saved = lr_state( $denied );
		lr_check( 'failed' === $saved['status'] && empty( $saved['recovery_stage'] ) && empty( $saved['retry_at'] ) && false === lr_tick( $denied ), 'Native auth/permission/usage refusal stays terminal and never enters recovery.' );
	}
	$malformed = lr_start( 'malformed' );
	lr_model( $malformed, '{not-json' );
	lr_check( 1 === lr_state( $malformed )['recovery_stage'] && 1 === lr_state( $malformed )['plan_retries'] && 'running' === lr_state( $malformed )['status'], 'One malformed model plan selects smaller steps without executing an action.' );
	lr_model( $malformed, '{still-not-json' );
	lr_check( 'failed' === lr_state( $malformed )['status'] && empty( lr_state( $malformed )['auto_recoveries'] ), 'Malformed output retains its separate one-plan retry bound.' );

	// A live editor always wins over a recovered background proposal; Stop remains immediate.
	$dirty = lr_start( 'dirty-editor' ); lr_recover( $dirty );
	lr_prepare( $dirty, lr_patch( $page_id, 'Must not appear from dirty editor.' ) );
	$editor_token = wp_generate_uuid4();
	$heartbeat = lr_rest( 'POST', '/editor-state', array( 'token' => $editor_token, 'editor_active' => true, 'unsaved_changes' => true ) );
	$write_count = count( $lr_native_writes ); $calls = $lr_text_calls;
	lr_tick( $dirty );
	$attempt = lr_rest( 'POST', '/sessions/' . $dirty[0] . '/execute', array( 'run_id' => $dirty[1], 'prepared_step' => lr_state( $dirty )['step'], 'unsaved_changes' => false ) );
	lr_check( 200 === $heartbeat->get_status() && 409 === $attempt->get_status() && 'prepared' === lr_state( $dirty )['phase'] && $write_count === count( $lr_native_writes ) && $calls === $lr_text_calls, 'Actual editor heartbeat blocks both background and foreground recovered writes without a false clean client bypass.' );
	lr_cancel( $dirty );
	lr_check( false === lr_tick( $dirty ) && $write_count === count( $lr_native_writes ), 'Stopped prepared write cannot run from a delayed native tick.' );
	lr_rest( 'POST', '/editor-state', array( 'token' => $editor_token, 'editor_active' => false, 'unsaved_changes' => false ) );
	$stopped = lr_start( 'stop-backoff' ); lr_recover( $stopped ); lr_cancel( $stopped );
	$calls = $lr_text_calls;
	lr_check( false === lr_tick( $stopped ) && $calls === $lr_text_calls, 'Stop during model backoff prevents every later model retry.' );

	// Permissions and action switches are current, never captured recovery authority.
	$revoked = lr_start( 'requester-revoked' ); lr_recover( $revoked );
	$deny_owner = static function ( $caps, $required, $args, $user ) { if ( $user->ID === $GLOBALS['lr_owner'] ) { $caps['manage_options'] = false; } return $caps; };
	add_filter( 'user_has_cap', $deny_owner, PHP_INT_MAX, 4 );
	$calls = $lr_text_calls;
	try { $blocked = lr_tick( $revoked ); } finally { remove_filter( 'user_has_cap', $deny_owner, PHP_INT_MAX ); }
	lr_check( is_wp_error( $blocked ) && 403 === $blocked->get_error_data()['status'] && $calls === $lr_text_calls, 'Native background recovery rechecks the requesting administrator before generation.' );
	lr_cancel( $revoked );
	$actor_denied = lr_start( 'actor-revoked' ); lr_recover( $actor_denied );
	lr_prepare( $actor_denied, lr_patch( $page_id, 'Must not appear after actor permission loss.' ) );
	$deny_actor = static function ( $caps, $required, $args, $user ) { if ( $user->ID === $GLOBALS['lr_actor'] ) { $caps['edit_pages'] = false; $caps['edit_others_pages'] = false; } return $caps; };
	add_filter( 'user_has_cap', $deny_actor, PHP_INT_MAX, 4 );
	try { lr_tick( $actor_denied ); } finally { remove_filter( 'user_has_cap', $deny_actor, PHP_INT_MAX ); }
	lr_check( $write_count === count( $lr_native_writes ) && false === end( lr_state( $actor_denied )['receipts'] )['success'], 'Native recovered execution rechecks the managed actor and records a denied action without saving.' );
	lr_cancel( $actor_denied );
	$policy = lr_start( 'policy-disabled' ); lr_recover( $policy );
	lr_prepare( $policy, lr_patch( $page_id, 'Must not appear after guardrail disabled.' ) );
	lr_settings( 'edit_content', false );
	lr_tick( $policy );
	lr_check( $write_count === count( $lr_native_writes ) && false === end( lr_state( $policy )['receipts'] )['success'], 'Actual native guardrail settings override a recovered prepared write.' );
	lr_settings( 'edit_content', true ); lr_cancel( $policy );

	$approval = lr_start( 'human-deletion' ); lr_recover( $approval );
	$awaiting = lr_tool( $approval, 'wp_request', array( 'method' => 'DELETE', 'path' => '/wp/v2/pages/' . $page_id ) );
	$calls = $lr_text_calls;
	lr_check( 'awaiting_confirmation' === $awaiting['status'] && 'draft' === get_post_status( $page_id ) && false === lr_tick( $approval ) && $calls === $lr_text_calls, 'Recovered deletion still awaits the native human confirmation button; Cron cannot approve.' );
	$denied = lr_rest( 'POST', '/sessions/' . $approval[0] . '/confirm', array( 'confirmation_id' => $awaiting['confirmation']['id'], 'approved' => false ) );
	lr_check( 200 === $denied->get_status() && 'cancelled' === lr_state( $approval )['status'] && 'draft' === get_post_status( $page_id ), 'Actual human Deny preserves the native draft.' );

	// Repeated proposals cannot burn the task on duplicate images or creations.
	$duplicates = lr_start( 'duplicate-bound' );
	$unique_create = $create; $unique_create['params']['title'] .= '-duplicate-bound';
	$run = lr_tool( $duplicates, 'wp_request', $unique_create );
	$created_receipt = lr_receipt( $run, 'write' ); $lr_pages[] = $created_receipt['target']['id'];
	lr_recover( $duplicates );
	$receipts = lr_state( $duplicates )['receipts']; $before_writes = count( $lr_native_writes );
	for ( $duplicate = 0; $duplicate < 3; ++$duplicate ) { lr_model( $duplicates, lr_plan( 'wp_request', $unique_create ) ); }
	lr_check( 'incomplete' === lr_state( $duplicates )['status'] && 3 === lr_state( $duplicates )['duplicate_proposals'] && $receipts === lr_state( $duplicates )['receipts'] && $before_writes === count( $lr_native_writes ), 'Three duplicate proposals end incomplete with original receipts and zero repeated native writes.' );

	// Native saves whose acknowledgement/final rollback storage fails are never replayable.
	foreach ( array( 'callback-loss', 'snapshot-loss' ) as $kind ) {
		$uncertain = lr_start( $kind ); lr_recover( $uncertain );
		$text = 'A native saved section with ' . $kind . ' acknowledgement.';
		lr_prepare( $uncertain, lr_patch( $page_id, $text ) );
		$before_writes = count( $lr_native_writes ); $calls = $lr_text_calls;
		$lost_callback = static function ( $response, $handler, $request ) use ( $page_id ) {
			if ( 'POST' === $request->get_method() && '/wp/v2/pages/' . $page_id === $request->get_route() && ! is_wp_error( $response ) && ! rest_ensure_response( $response )->is_error() ) {
				throw new RuntimeException( 'Synthetic private post-save callback acknowledgement loss.' );
			}
			return $response;
		};
		$lost_snapshot = static function ( $pre, $object_id, $key, $value ) use ( $uncertain ) {
			if ( $uncertain[0] === $object_id && 'bhranti_actions' === $key && is_array( $value ) ) {
				foreach ( $value as $snapshot ) { if ( 'completed' === ( $snapshot['state'] ?? '' ) ) { return false; } }
			}
			return $pre;
		};
		if ( 'callback-loss' === $kind ) { add_filter( 'rest_request_after_callbacks', $lost_callback, PHP_INT_MAX, 3 ); } else { add_filter( 'update_post_metadata', $lost_snapshot, PHP_INT_MAX, 4 ); }
		try { lr_tick( $uncertain ); } finally {
			remove_filter( 'rest_request_after_callbacks', $lost_callback, PHP_INT_MAX );
			remove_filter( 'update_post_metadata', $lost_snapshot, PHP_INT_MAX );
		}
		$saved = lr_state( $uncertain );
		$public = lr_rest( 'GET', '/sessions/' . $uncertain[0] )->get_data();
		lr_check( false !== strpos( get_post_field( 'post_content', $page_id ), $text ) && $before_writes + 1 === count( $lr_native_writes ), 'The exact native fixture really saved once before acknowledgement/final-storage loss.' );
		lr_check( 'failed' === $saved['status'] && empty( $saved['resume_checkpoint'] ) && ! $public['run']['can_resume'] && false === end( $saved['receipts'] )['success'], 'Uncertain native save stops immediately without a success receipt or safe model Resume.' );
		$late = lr_rest( 'POST', '/sessions/' . $uncertain[0] . '/step', array( 'run_id' => $uncertain[1], 'defer_tool' => true ) );
		lr_check( 200 === $late->get_status() && false === lr_tick( $uncertain ) && $calls === $lr_text_calls && $before_writes + 1 === count( $lr_native_writes ), 'Neither browser nor Cron can automatically repeat or replan an uncertain saved action.' );
	}

	// The captured task limit remains binding across the automatic continuation.
	$settings = lr_rest( 'GET', '/settings' )->get_data();
	$limit_before = $settings['config']['max_steps']; $settings['config']['max_steps'] = 12;
	lr_check( 200 === lr_rest( 'POST', '/settings', array( 'config' => $settings['config'], 'revision' => $settings['revision'] ) )->get_status(), 'Native settings capture a twelve-step fixture task limit.' );
	$limited = lr_start( 'step-limited' );
	$settings = lr_rest( 'GET', '/settings' )->get_data(); $settings['config']['max_steps'] = $limit_before;
	lr_check( 200 === lr_rest( 'POST', '/settings', array( 'config' => $settings['config'], 'revision' => $settings['revision'] ) )->get_status(), 'Later native settings restore future-task limits without renewing the active task.' );
	for ( $read = 0; $read < 4; ++$read ) { lr_tool( $limited, 'wp_request', array( 'method' => 'GET', 'path' => '/wp/v2/pages/' . $page_id, 'params' => array( 'context' => 'edit', '_fields' => 'id,status' ) ) ); }
	for ( $attempt = 0; $attempt < 8; ++$attempt ) {
		lr_model( $limited, new WordPress\AiClient\Providers\Http\Exception\NetworkException( 'Synthetic private step-limit outage.' ) );
		if ( 'running' === lr_state( $limited )['status'] ) { lr_due( $limited ); }
	}
	$saved = lr_state( $limited ); $public = lr_rest( 'GET', '/sessions/' . $limited[0] )->get_data();
	lr_check( 'failed' === $saved['status'] && 12 === $saved['step'] && 12 === $saved['configuration']['max_steps'] && ! $public['run']['can_resume'] && false === lr_tick( $limited ), 'Automatic recovery cannot exceed or renew the captured native task step limit.' );
	lr_check( 0 === $lr_http && 0 === $lr_mail, 'Every native fixture path remains offline without mail.' );
	}
} catch ( Throwable $error ) {
	$lr_failure = $error;
} finally {
	foreach ( $lr_runs as $tuple ) { Bhranti_Runner::clear( $tuple[0], $tuple[1], $tuple[2] ); }
	foreach ( $lr_images as $id ) { if ( ! wp_delete_attachment( $id, true ) || get_post( $id ) ) { $lr_clean = false; } }
	foreach ( $lr_pages as $id ) { if ( ! wp_delete_post( $id, true ) || get_post( $id ) ) { $lr_clean = false; } }
	foreach ( array( $lr_owner, $lr_actor ) as $id ) {
		if ( ! $id || in_array( $id, $lr_initial_users, true ) ) { continue; }
		Bhranti_Memory::erase_user( $id );
		$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_author = %d", $id ) );
		foreach ( $posts as $post ) {
			if ( in_array( (int) $post->ID, $lr_initial_posts, true ) ) { $lr_clean = false; continue; }
			if ( 'attachment' === $post->post_type ) { wp_delete_attachment( $post->ID, true ); } else { wp_delete_post( $post->ID, true ); }
		}
		if ( get_posts( array( 'post_type' => 'any', 'post_status' => array_keys( get_post_stati() ), 'author' => $id, 'numberposts' => 1 ) ) || ! wp_delete_user( $id ) ) { $lr_clean = false; }
	}
	foreach ( $lr_initial_options as $key => $value ) { if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value ); } }
	WP_Connector_Registry::get_instance()->unregister( 'bhranti-recovery-offline' );
	$lr_registry_property->setValue( null, $lr_original_registry );
	remove_filter( 'pre_http_request', $lr_http_guard, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', $lr_mail_guard, PHP_INT_MAX );
	remove_filter( 'pre_schedule_event', $lr_schedule, PHP_INT_MAX );
	remove_filter( 'rest_request_after_callbacks', $lr_write_observer, PHP_INT_MAX );
	wp_set_current_user( $lr_original_user );
	foreach ( $lr_baseline as $id => $hash ) { if ( $hash !== hash( 'sha256', wp_json_encode( array( get_post( $id ), get_post_meta( $id ) ) ) ) ) { $lr_clean = false; } }
	if ( $lr_initial_users !== array_map( 'intval', get_users( array( 'fields' => 'ID' ) ) ) ) { $lr_clean = false; }
	$remaining_posts = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) );
	sort( $remaining_posts ); sort( $lr_initial_posts );
	if ( $remaining_posts !== $lr_initial_posts ) { $lr_clean = false; }
	foreach ( $lr_initial_options as $key => $value ) { if ( get_option( $key, null ) !== $value ) { $lr_clean = false; } }
}
$lr_summary = array( 'checks' => $lr_checks, 'http' => $lr_http, 'mail' => $lr_mail, 'native_model_fixture_calls' => $lr_text_calls, 'native_image_fixture_calls' => $lr_image_calls, 'native_page_writes' => count( $lr_native_writes ), 'cleanup_and_baseline_preserved' => $lr_clean );
if ( isset( $GLOBALS['lr_scenario_metrics'] ) ) { $lr_summary['scenario'] = $GLOBALS['lr_scenario_metrics']; }
echo wp_json_encode( $lr_summary ) . "\n";
if ( $lr_failure || ! $lr_clean ) {
	fwrite( STDERR, ( $lr_failure ? $lr_failure->getMessage() : 'Private fixture cleanup failed.' ) . "\n" );
	exit( 1 );
}
echo 'PASS ' . $lr_checks . " isolated native large-task recovery checks; no HTTP or remote AI.\n";
