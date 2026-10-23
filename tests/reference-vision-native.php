<?php
/**
 * Real installed WordPress prompt wrapper/SDK vision checks, entirely offline.
 * Only an isolated native local metadata provider and deterministic DTO result.
 * No site bootstrap, database, connector settings, HTTP or remote generation.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/reference-vision-native.php
 */
use WordPress\AiClient\AiClient;
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
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

define( 'ABSPATH', rtrim( getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Documents/WordPress/Plugins', '/' ) . '/' );
define( 'WPINC', 'wp-includes' ); define( 'WP_DEBUG', false ); define( 'DAY_IN_SECONDS', 86400 );
foreach ( array( 'class-wp-error', 'plugin', 'cache', 'load', 'functions', 'utf8', 'compat', 'formatting', 'pluggable' ) as $file ) { require ABSPATH . WPINC . '/' . $file . '.php'; }
require ABSPATH . WPINC . '/php-ai-client/autoload.php';
require ABSPATH . WPINC . '/ai-client.php';
require ABSPATH . WPINC . '/ai-client/class-wp-ai-client-prompt-builder.php';
wp_cache_init();
function __( $text, $domain = 'default' ) { return $text; }
function get_current_user_id() { return 7; }
function get_translations_for_domain( $domain ) { return new class { public function translate( $text ) { return $text; } }; }
add_filter( 'pre_option', static function ( $value, $name, $default ) { return false === $default ? null : $default; }, 10, 3 );
$wpdb = new class { public function __call( $name, $args ) { throw new RuntimeException( 'Database access is forbidden: ' . $name ); } };
final class Bhranti_Workspace {
    public static $messages = array();
    public static function session_data( $post ) { return array( 'messages' => self::$messages ); }
    public static function site_context() { return array( 'name' => 'Offline native reference test' ); }
    public static function ai_request( $callback ) { return $callback(); }
}
final class Bhranti_Settings { public static function all() { return array( 'permissions' => array() ); } }
final class Bhranti_Actions { public static function catalog() { return array(); } }
final class Bhranti_Plugins { public static function catalog() { return array(); } }
final class Bhranti_Media { public static function catalog() { return array(); } }
final class Bhranti_Audit { public static $events = array(); public static function record( ...$event ) { self::$events[] = $event; return count( self::$events ); } }
foreach ( array( 'credentials', 'skills', 'knowledge', 'context', 'agent', 'diagnostics' ) as $class ) { require dirname( __DIR__ ) . '/includes/class-bhranti-' . $class . '.php'; }

final class Reference_Vision_Provider extends AbstractProvider {
    public static $vision = true; public static $json = true; public static $empty = false; public static $failure = false; public static $metadata_calls = 0;
    protected static function createProviderMetadata(): ProviderMetadata { return new ProviderMetadata( 'reference-vision-offline', 'Offline reference capability test', ProviderTypeEnum::server(), null, null ); }
    protected static function createModel( ModelMetadata $metadata, ProviderMetadata $provider ): ModelInterface { return new Reference_Vision_Model( $metadata, $provider ); }
    protected static function createProviderAvailability(): ProviderAvailabilityInterface { return new class implements ProviderAvailabilityInterface { public function isConfigured(): bool { return true; } }; }
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface { return new class implements ModelMetadataDirectoryInterface {
        public function listModelMetadata(): array { ++Reference_Vision_Provider::$metadata_calls; if ( Reference_Vision_Provider::$failure ) { throw new \WordPress\AiClient\Providers\Http\Exception\NetworkException( 'private-offline-metadata-interruption' ); } return Reference_Vision_Provider::$empty ? array() : array( $this->getModelMetadata( 'offline-text' ) ); }
        public function hasModelMetadata( string $id ): bool { return 'offline-text' === $id; }
        public function getModelMetadata( string $id ): ModelMetadata {
            $inputs = array( array( ModalityEnum::text() ) ); if ( Reference_Vision_Provider::$vision ) { $inputs[] = array( ModalityEnum::text(), ModalityEnum::image() ); }
            $options = array( new SupportedOption( OptionEnum::systemInstruction() ), new SupportedOption( OptionEnum::inputModalities(), $inputs ), new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ), new SupportedOption( OptionEnum::outputMimeType(), Reference_Vision_Provider::$json ? array( 'text/plain', 'application/json' ) : array( 'text/plain' ) ) );
            if ( Reference_Vision_Provider::$json ) { $options[] = new SupportedOption( OptionEnum::outputSchema() ); }
            return new ModelMetadata( $id, 'Offline native text', array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ), $options );
        }
    }; }
}
final class Reference_Vision_Model extends AbstractApiBasedModel implements TextGenerationModelInterface {
    public static $calls = array();
    public function generateTextResult( array $messages ): GenerativeAiResult {
        self::$calls[] = array( 'messages' => $messages, 'config' => $this->getConfig()->toArray(), 'options' => $this->getRequestOptions()->toArray() );
        return new GenerativeAiResult( 'offline-reference-result', array( new Candidate( new ModelMessage( array( new MessagePart( '{"type":"reply","content":"Native reference result.","tool":"none","args":"{}"}' ) ) ), FinishReasonEnum::stop() ) ), new TokenUsage( 0, 0, 0 ), $this->providerMetadata(), $this->metadata() );
    }
}
AiClient::defaultRegistry()->setHttpTransporter( new class implements \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface {
    public function send( \WordPress\AiClient\Providers\Http\DTO\Request $request, ?\WordPress\AiClient\Providers\Http\DTO\RequestOptions $options = null ): \WordPress\AiClient\Providers\Http\DTO\Response { throw new RuntimeException( 'Network is forbidden in reference vision tests.' ); }
} );
AiClient::defaultRegistry()->registerProvider( Reference_Vision_Provider::class );
$vn_checks = 0; $vn_builders = array();
function vn_check( $condition, $label ) { ++$GLOBALS['vn_checks']; if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); } }
// Read-only test inspection of native private state proves exact preflight config;
// production code only calls the installed wrapper's public fluent methods.
$capture = static function ( $prevent, $wrapper ) {
    $builder = ( new ReflectionProperty( WP_AI_Client_Prompt_Builder::class, 'builder' ) )->getValue( $wrapper );
    $messages = ( new ReflectionProperty( $builder, 'messages' ) )->getValue( $builder );
    $config = ( new ReflectionProperty( $builder, 'modelConfig' ) )->getValue( $builder )->toArray();
    $options = ( new ReflectionProperty( $builder, 'requestOptions' ) )->getValue( $builder )->toArray();
    $GLOBALS['vn_builders'][] = array( 'messages' => $messages, 'config' => $config, 'options' => $options );
    return $prevent;
};
add_filter( 'wp_ai_client_prevent_prompt', $capture, 10, 2 );
$raster = imagecreatetruecolor( 3, 2 ); ob_start(); imagepng( $raster ); $png = base64_encode( ob_get_clean() ); unset( $raster );
$image = Bhranti_Context::screenshot( array( 'data' => $png, 'mime_type' => 'image/png' ) );
$reference_id = wp_generate_uuid4();
Bhranti_Workspace::$messages = array( array( 'id' => $reference_id, 'role' => 'user', 'content' => 'The original human visual reference.' ), array( 'id' => wp_generate_uuid4(), 'role' => 'assistant', 'content' => 'Which audience?' ), array( 'id' => wp_generate_uuid4(), 'role' => 'user', 'content' => 'A later answer without reattaching.' ) );
$post = (object) array( 'ID' => 91, 'post_author' => 7 );
$run = array( 'id' => wp_generate_uuid4(), 'requester_id' => 7, 'actor_id' => 7, 'context' => array( 'screen' => 'post' ), 'history' => array(), 'configuration' => array( 'name' => 'Offline configured agent', 'system_prompt' => 'private-configured-prompt-marker', 'max_steps' => 128 ), 'reference_image' => array( 'message_id' => $reference_id, 'image' => $image ) );
$ask = new ReflectionMethod( Bhranti_Agent::class, 'ask' );
function vn_files( $messages ) { $files = array(); foreach ( $messages as $message ) { foreach ( $message->getParts() as $part ) { if ( $part->getType()->isFile() ) { $files[] = array( $message, $part->getFile() ); } } } return $files; }

$reply = $ask->invoke( null, $post, $run ); $call = Reference_Vision_Model::$calls[0];
vn_check( is_string( $reply ) && 1 === count( Reference_Vision_Model::$calls ) && 2 === count( $vn_builders ), 'True exact native vision+JSON metadata permits exactly one local DTO generation; support checks do not generate.' );
vn_check( $vn_builders[0]['config'] === $call['config'] && $vn_builders[0]['options'] === $call['options'] && 'application/json' === $call['config']['outputMimeType'] && 120.0 === $call['options']['timeout'], 'Exact same native system/schema/output/request options are checked then used for generation.' );
vn_check( str_contains( $call['config']['systemInstruction'], 'private-configured-prompt-marker' ) && $call['config']['outputSchema']['properties']['type']['enum'] === array( 'reply', 'question', 'tool' ), 'Configured prompt and existing strict four-field schema remain in the native checked request.' );
$files = vn_files( $call['messages'] );
vn_check( 1 === count( $files ) && $png === $files[0][1]->getBase64Data() && 'The original human visual reference.' === $files[0][0]->getParts()[0]->getText() && ! str_contains( $call['config']['systemInstruction'], $png ), 'Native File occurs once on the original human message, not the clarification answer or system instruction.' );
Reference_Vision_Provider::$vision = false; $vn_builders = array(); $calls = count( Reference_Vision_Model::$calls );
$reply = $ask->invoke( null, $post, $run ); $question = json_decode( $reply, true );
vn_check( 'question' === $question['type'] && 'none' === $question['tool'] && '{}' === $question['args'] && $calls === count( Reference_Vision_Model::$calls ) && 2 === count( $vn_builders ), 'Actual native text-only metadata returns a trusted input question without generating or dropping the image.' );
vn_check( $vn_builders[0]['config'] === $vn_builders[1]['config'] && $vn_builders[0]['options'] === $vn_builders[1]['options'] && 1 === count( vn_files( $vn_builders[0]['messages'] ) ) && array() === vn_files( $vn_builders[1]['messages'] ), 'Actual equivalent native comparison removes only the File part, retaining exact complete configuration.' );
foreach ( array( 'json', 'empty', 'failure' ) as $mode ) {
    Reference_Vision_Provider::$json = 'json' !== $mode; Reference_Vision_Provider::$empty = 'empty' === $mode; Reference_Vision_Provider::$failure = 'failure' === $mode; $vn_builders = array();
    $error = $ask->invoke( null, $post, $run );
    vn_check( is_wp_error( $error ) && $calls === count( Reference_Vision_Model::$calls ) && ! str_contains( $error->get_error_message(), 'cannot read this reference' ), 'Actual native ' . $mode . ' failure remains unknown/general capability without generation or an unsupported-image claim.' );
    vn_check( ! str_contains( wp_json_encode( Bhranti_Audit::$events ), 'private-offline-metadata-interruption' ), 'Native metadata diagnostic payload never enters durable audit data.' );
}
Reference_Vision_Provider::$json = true; Reference_Vision_Provider::$empty = false; Reference_Vision_Provider::$failure = false;
$prevent = static function () { return true; }; add_filter( 'wp_ai_client_prevent_prompt', $prevent, PHP_INT_MAX ); $vn_builders = array();
$error = $ask->invoke( null, $post, $run );
vn_check( is_wp_error( $error ) && $calls === count( Reference_Vision_Model::$calls ) && 2 === count( $vn_builders ), 'Native prompt prevention blocks both exact probes without model generation or a false image-support claim.' );
remove_filter( 'wp_ai_client_prevent_prompt', $prevent, PHP_INT_MAX );
Reference_Vision_Provider::$vision = true; $vn_builders = array();
$reply = $ask->invoke( null, $post, $run );
vn_check( is_string( $reply ) && $calls + 1 === count( Reference_Vision_Model::$calls ), 'Fresh native metadata recovery reuses the retained original image and generates exactly once.' );
vn_check( 1 === count( vn_files( end( Reference_Vision_Model::$calls )['messages'] ) ) && $run['reference_image']['image']['data'] === $png, 'Recovery keeps original image bytes without modifying connector/model preferences or source state.' );
remove_filter( 'wp_ai_client_prevent_prompt', $capture, 10 );
echo 'PASS ' . $vn_checks . " installed native vision-wrapper/SDK checks; no WordPress bootstrap, database, HTTP or remote AI.\n";
