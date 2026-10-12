<?php
/**
 * Native WordPress raster preparation without bootstrapping a site or provider.
 * Only uniquely owned local temporary image files are created and removed.
 */
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\AiClient;
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
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

define( 'ABSPATH', rtrim( getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Documents/WordPress/Plugins', '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_DEBUG', false );
define( 'WP_MAX_MEMORY_LIMIT', '256M' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );
define( 'GB_IN_BYTES', 1073741824 );
define( 'WP_TEMP_DIR', sys_get_temp_dir() . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/bhranti-offline-not-an-upload-target' );
define( 'WP_CONTENT_URL', 'https://example.test/never-used' );
require ABSPATH . WPINC . '/class-wp-error.php';
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/cache.php';
require ABSPATH . WPINC . '/load.php';
require ABSPATH . WPINC . '/functions.php';
require ABSPATH . WPINC . '/utf8.php';
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/shortcodes.php';
require ABSPATH . WPINC . '/pluggable.php';
require ABSPATH . WPINC . '/media.php';
require ABSPATH . WPINC . '/php-ai-client/autoload.php';
require ABSPATH . WPINC . '/ai-client.php';
require ABSPATH . WPINC . '/ai-client/class-wp-ai-client-prompt-builder.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-media.php';
wp_cache_init();
function __( $text, $domain = 'default' ) { return $text; }
function _x( $text, $context, $domain = 'default' ) { return $text; }
function user_can( $user, $capability ) { return true; }
function get_current_user_id() { return 1; }
add_filter( 'pre_option', static function ( $value, $name, $default ) { return array( 'upload_path' => '', 'upload_url_path' => '', 'siteurl' => 'https://example.test' )[ $name ] ?? ( false === $default ? null : $default ); }, 10, 3 );
$wpdb = new class { public function __call( $name, $args ) { throw new RuntimeException( 'Database access is forbidden: ' . $name ); } };
$checks = 0;
function media_format_check( $valid, $label ) { if ( ! $valid ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
$validate = new ReflectionMethod( 'Bhranti_Media', 'validate_image' );
$preflight = new ReflectionMethod( 'Bhranti_Media', 'preflight' );
$before = glob( sys_get_temp_dir() . '/bhranti-image-*' );
$limit = 1048576;
add_filter( 'upload_size_limit', static function () { return $GLOBALS['limit']; } );
$allowed = array( 'image/png', 'image/jpeg', 'image/webp' );
add_filter( 'upload_mimes', static function ( $types, $user ) { return array_intersect( $types, $GLOBALS['allowed'] ); }, 10, 2 );
media_format_check( extension_loaded( 'gd' ), 'Native GD image editor is available' );
$caps = Bhranti_Media::capabilities( 1, 11 );
media_format_check( 3 === count( $caps['formats'] ) && PHP_SAPI === $caps['runtime'] && $limit === $caps['max_upload_bytes'], 'Native current MIME intersection, editor and runtime limits are discovered' );
$allowed = array( 'image/png' );
$caps = Bhranti_Media::capabilities( 1, 11 );
media_format_check( is_wp_error( $preflight->invoke( null, array( 'format' => 'webp' ), $caps ) ), 'An unsupported requested format fails before provider discovery' );
$allowed = array();
media_format_check( is_wp_error( $preflight->invoke( null, array(), Bhranti_Media::capabilities( 1, 11 ) ) ), 'No native allowed format fails preflight' );
$allowed = array( 'image/png', 'image/jpeg', 'image/webp' );
$limit = 0;
media_format_check( is_wp_error( $preflight->invoke( null, array(), Bhranti_Media::capabilities( 1, 11 ) ) ), 'A zero native upload limit fails before generation' );
$limit = 1048576;
$raster = imagecreatetruecolor( 320, 240 );
// Deterministic varied pixels exercise actual encoding and resizing, not fixtures with fake headers.
for ( $y = 0; $y < 240; ++$y ) { for ( $x = 0; $x < 320; ++$x ) { imagesetpixel( $raster, $x, $y, ( ( $x * 197 + $y * 71 ) % 256 << 16 ) | ( ( $x * 19 + $y * 149 ) % 256 << 8 ) | ( $x * 83 + $y * 37 ) % 256 ); } }
ob_start(); imagepng( $raster ); $png = ob_get_clean(); unset( $raster );
$file = new File( base64_encode( $png ), 'image/png' );
$unchanged = $validate->invoke( null, $file, 11, 1 );
media_format_check( is_array( $unchanged ) && $unchanged['bytes'] === $png, 'An already valid native raster is preserved byte for byte' );
foreach ( array( 'jpeg' => 'image/jpeg', 'webp' => 'image/webp' ) as $format => $mime ) {
	$converted = $validate->invoke( null, $file, 11, 1, array( 'format' => $format ) );
	media_format_check( is_array( $converted ), 'Native conversion succeeds for ' . $format . ( is_wp_error( $converted ) ? ': ' . $converted->get_error_message() : '' ) );
	media_format_check( $mime === $converted['mime'] && $mime === getimagesizefromstring( $converted['bytes'] )['mime'] && 320 === $converted['width'] && 240 === $converted['height'], 'Actual native output matches requested format and original dimensions' );
}
$limit = 4000;
$resized = $validate->invoke( null, $file, 11, 1, array( 'format' => 'webp' ) );
media_format_check( is_array( $resized ) && strlen( $resized['bytes'] ) <= $limit && $resized['width'] < 320 && $resized['height'] < 240, 'One source is locally resized below the current native limit' );
$allowed = array( 'image/jpeg' );
$limit = 1048576;
$converted = $validate->invoke( null, $file, 11, 1 );
media_format_check( is_array( $converted ) && 'image/jpeg' === $converted['mime'], 'Unexpected provider PNG can be converted to the only allowed native JPEG output' );
$allowed = array( 'image/png', 'image/jpeg', 'image/webp' );
$limit = 1;
$failure = $validate->invoke( null, $file, 11, 1 );
media_format_check( is_wp_error( $failure ) && 'limit' === $failure->get_error_data()['media_reason'], 'Impossible upload size fails after bounded local attempts without regeneration' );
$limit = 1048576;
$prompt = new ReflectionMethod( 'Bhranti_Media', 'image_prompt' );
// An isolated native registry contains only this local provider. No real connector is registered.
final class Media_Format_Provider extends AbstractProvider {
	public static $formats = array( 'image/png', 'image/jpeg', 'image/webp' );
	protected static function createProviderMetadata(): ProviderMetadata { return new ProviderMetadata( 'media-format-offline', 'Offline media format test', ProviderTypeEnum::server(), null, null ); }
	protected static function createModel( ModelMetadata $metadata, ProviderMetadata $provider ): ModelInterface { return new Media_Format_Model( $metadata, $provider ); }
	protected static function createProviderAvailability(): ProviderAvailabilityInterface { return new class implements ProviderAvailabilityInterface { public function isConfigured(): bool { return true; } }; }
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new class implements ModelMetadataDirectoryInterface {
			public function listModelMetadata(): array { return array( $this->getModelMetadata( 'offline-image' ) ); }
			public function hasModelMetadata( string $id ): bool { return 'offline-image' === $id; }
			public function getModelMetadata( string $id ): ModelMetadata {
				$options = array( new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ), new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::image() ) ) ) );
				if ( Media_Format_Provider::$formats ) { $options[] = new SupportedOption( OptionEnum::outputMimeType(), Media_Format_Provider::$formats ); $options[] = new SupportedOption( OptionEnum::outputFileType(), array( FileTypeEnum::inline() ) ); }
				return new ModelMetadata( $id, 'Offline image', array( CapabilityEnum::imageGeneration() ), $options );
			}
		};
	}
}
final class Media_Format_Model extends AbstractApiBasedModel implements ImageGenerationModelInterface {
	public static $calls = array();
	public function generateImageResult( array $messages ): GenerativeAiResult {
		self::$calls[] = $this->getConfig()->toArray();
		return new GenerativeAiResult( 'offline-result', array( new Candidate( new ModelMessage( array( new MessagePart( $GLOBALS['file'] ) ) ), FinishReasonEnum::stop() ) ), new TokenUsage( 0, 0, 0 ), $this->providerMetadata(), $this->metadata() );
	}
}
AiClient::defaultRegistry()->setHttpTransporter( new class implements \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface {
	public function send( \WordPress\AiClient\Providers\Http\DTO\Request $request, ?\WordPress\AiClient\Providers\Http\DTO\RequestOptions $options = null ): \WordPress\AiClient\Providers\Http\DTO\Response { throw new RuntimeException( 'Network is forbidden in native format tests.' ); }
} );
AiClient::defaultRegistry()->registerProvider( Media_Format_Provider::class );
foreach ( array( array( null, 'image/webp' ), array( 'png', 'image/png' ), array( 'jpeg', 'image/jpeg' ) ) as $case ) {
	$args = array( 'prompt' => 'Offline generated raster test.' );
	if ( $case[0] ) { $args['format'] = $case[0]; }
	$count = count( Media_Format_Model::$calls );
	$builder = $prompt->invoke( null, $args, Bhranti_Media::capabilities( 1, 11 ) );
	media_format_check( $builder instanceof WP_AI_Client_Prompt_Builder && $count === count( Media_Format_Model::$calls ), 'Native format discovery never generates an image' );
	$result = $builder->generate_image_result();
	$config = end( Media_Format_Model::$calls );
	media_format_check( $result instanceof GenerativeAiResult && $count + 1 === count( Media_Format_Model::$calls ) && $case[1] === $config['outputMimeType'] && 'inline' === $config['outputFileType'], 'Native public builder passes the supported output format and inline type to exactly one local model call' );
	media_format_check( 'media-format-offline' === $result->getProviderMetadata()->getId() && 'offline-image' === $result->getModelMetadata()->getId() && $file === $result->toImageFile(), 'Native result provides verified provider/model provenance and image bytes' );
}
$blocked = static function () { return true; };
add_filter( 'wp_ai_client_prevent_prompt', $blocked );
$count = count( Media_Format_Model::$calls );
$unsupported = $prompt->invoke( null, array( 'prompt' => 'Offline rejected request.' ), Bhranti_Media::capabilities( 1, 11 ) );
media_format_check( is_wp_error( $unsupported ) && $count === count( Media_Format_Model::$calls ), 'Native connector prevention remains authoritative during format selection' );
remove_filter( 'wp_ai_client_prevent_prompt', $blocked );
foreach ( array( array( 'image/png' ), array() ) as $advertised_formats ) {
	Media_Format_Provider::$formats = $advertised_formats;
	$count = count( Media_Format_Model::$calls );
	$builder = $prompt->invoke( null, array( 'prompt' => 'Offline legacy format test.' ), Bhranti_Media::capabilities( 1, 11 ) );
	media_format_check( $builder instanceof WP_AI_Client_Prompt_Builder && $count === count( Media_Format_Model::$calls ), 'PNG-only and older metadata remain discoverable without image requests' );
	$result = $builder->generate_image_result();
	$config = end( Media_Format_Model::$calls );
	media_format_check( $result instanceof GenerativeAiResult && $count + 1 === count( Media_Format_Model::$calls ) && ( $advertised_formats ? 'image/png' === $config['outputMimeType'] : ! isset( $config['outputMimeType'] ) ), 'Actual native builder uses the discovered PNG format or leaves older provider options unchanged' );
}
$failure = $validate->invoke( null, new File( base64_encode( $png ), 'image/jpeg' ), 11, 1 );
media_format_check( is_wp_error( $failure ) && 'mime' === $failure->get_error_data()['media_reason'], 'Generated MIME spoofing is rejected before conversion' );
$failure = $validate->invoke( null, new File( 'https://example.test/image.png', 'image/png' ), 11, 1 );
media_format_check( is_wp_error( $failure ) && 'bhranti_media_inline' === $failure->get_error_code(), 'Remote provider images are never downloaded' );
$forced = static function ( $map ) { $map['image/jpeg'] = 'image/png'; return $map; };
add_filter( 'image_editor_output_format', $forced );
$failure = $validate->invoke( null, $file, 11, 1, array( 'format' => 'jpeg' ) );
media_format_check( is_wp_error( $failure ) && 'metadata' === $failure->get_error_data()['media_reason'], 'A native filter changing requested output format fails verification and removes only its temporary raster' );
remove_filter( 'image_editor_output_format', $forced );
media_format_check( $before === glob( sys_get_temp_dir() . '/bhranti-image-*' ), 'Native temporary source and conversion files are completely removed' );
final class Bhranti_Memory {
	public static $observations = array();
	public static $fail = false;
	public static function observe_media( ...$args ) { if ( self::$fail ) { throw new RuntimeException( 'Offline memory storage unavailable.' ); } self::$observations[] = $args; return true; }
}
$observe = new ReflectionMethod( 'Bhranti_Media', 'observe' );
$facts = array( 'mime_type' => 'image/png', 'bytes' => strlen( $png ), 'width' => 320, 'height' => 240 );
$observe->invoke( null, 1, Bhranti_Media::capabilities( 1, 11 ), 'success', 'media-format-offline', 'offline-image', $facts );
$remembered = end( Bhranti_Memory::$observations );
media_format_check( array( 'allowed_mime_types', 'editor_mime_types', 'max_upload_bytes', 'runtime' ) === array_keys( $remembered[1] ) && $remembered[5] === $facts && 'media-format-offline' === $remembered[3] && 'offline-image' === $remembered[4], 'Memory receives only bounded current capabilities and verified native provenance/image metadata' );
Bhranti_Memory::$fail = true;
media_format_check( null === $observe->invoke( null, 1, Bhranti_Media::capabilities( 1, 11 ), 'checked' ), 'Advisory memory failure cannot throw or replay a completed native image action' );
echo 'PASS: ' . $checks . " native offline media-format checks; no WordPress bootstrap, database, network or AI.\n";
