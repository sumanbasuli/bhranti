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
require dirname( __DIR__ ) . '/includes/class-bhranti-credentials.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-media.php';
wp_cache_init();
function __( $text, $domain = 'default' ) { return $text; }
function _x( $text, $context, $domain = 'default' ) { return $text; }
function user_can( $user, $capability ) { return ! in_array( $user . ':' . $capability, $GLOBALS['media_denied_caps'] ?? array(), true ); }
function get_current_user_id() { return $GLOBALS['media_current_user'] ?? 1; }
add_filter( 'pre_option', static function ( $value, $name, $default ) { return array( 'upload_path' => '', 'upload_url_path' => '', 'siteurl' => 'https://example.test', 'bhranti_actor_id' => 11 )[ $name ] ?? ( false === $default ? null : $default ); }, 10, 3 );
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
	public static $ratios = array();
	public static $images = true;
	public static $discovery_failure = false;
	public static $metadata_calls = 0;
	public static $empty_metadata = false;
	protected static function createProviderMetadata(): ProviderMetadata { return new ProviderMetadata( 'media-format-offline', 'Offline media format test', ProviderTypeEnum::server(), null, null ); }
	protected static function createModel( ModelMetadata $metadata, ProviderMetadata $provider ): ModelInterface { return new Media_Format_Model( $metadata, $provider ); }
	protected static function createProviderAvailability(): ProviderAvailabilityInterface { return new class implements ProviderAvailabilityInterface { public function isConfigured(): bool { return true; } }; }
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new class implements ModelMetadataDirectoryInterface {
			public function listModelMetadata(): array {
				++Media_Format_Provider::$metadata_calls;
				if ( Media_Format_Provider::$discovery_failure ) { throw new \WordPress\AiClient\Providers\Http\Exception\NetworkException( 'Synthetic offline metadata interruption.' ); }
				return Media_Format_Provider::$empty_metadata ? array() : array( $this->getModelMetadata( 'offline-image' ) );
			}
			public function hasModelMetadata( string $id ): bool { return 'offline-image' === $id; }
			public function getModelMetadata( string $id ): ModelMetadata {
				$options = array( new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ), new SupportedOption( OptionEnum::outputModalities(), array( array( Media_Format_Provider::$images ? ModalityEnum::image() : ModalityEnum::text() ) ) ) );
				if ( Media_Format_Provider::$images && Media_Format_Provider::$formats ) { $options[] = new SupportedOption( OptionEnum::outputMimeType(), Media_Format_Provider::$formats ); $options[] = new SupportedOption( OptionEnum::outputFileType(), array( FileTypeEnum::inline() ) ); }
				if ( Media_Format_Provider::$images && Media_Format_Provider::$ratios ) { $options[] = new SupportedOption( OptionEnum::outputMediaAspectRatio(), Media_Format_Provider::$ratios ); }
				return new ModelMetadata( $id, 'Offline image', array( Media_Format_Provider::$images ? CapabilityEnum::imageGeneration() : CapabilityEnum::textGeneration() ), $options );
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
Media_Format_Provider::$images = false;
$count = count( Media_Format_Model::$calls );
$unsupported = $prompt->invoke( null, array( 'prompt' => 'Offline text-only connector.' ), Bhranti_Media::capabilities( 1, 11 ) );
media_format_check( is_wp_error( $unsupported ) && 'bhranti_image_support' === $unsupported->get_error_code() && 422 === $unsupported->get_error_data()['status'] && $count === count( Media_Format_Model::$calls ), 'A native text-only model is confirmed unavailable without generating or fabricating an image' );
Media_Format_Provider::$images = true;
Media_Format_Provider::$discovery_failure = true;
$unknown = $prompt->invoke( null, array( 'prompt' => 'Offline capability lookup failure.' ), Bhranti_Media::capabilities( 1, 11 ) );
media_format_check( is_wp_error( $unknown ) && 'bhranti_image_discovery' === $unknown->get_error_code() && 502 === $unknown->get_error_data()['status'] && $count === count( Media_Format_Model::$calls ), 'An actual native metadata exception is unknown capability, never a permanent unsupported result or an image request' );
Media_Format_Provider::$discovery_failure = false;
$recovered = $prompt->invoke( null, array( 'prompt' => 'Offline fresh capability recovery.' ), Bhranti_Media::capabilities( 1, 11 ) );
media_format_check( $recovered instanceof WP_AI_Client_Prompt_Builder && $count === count( Media_Format_Model::$calls ), 'A fresh successful native capability check recovers without cached unsupported state or automatic generation' );
foreach ( array( array( 'image/png' ), array() ) as $advertised_formats ) {
	Media_Format_Provider::$formats = $advertised_formats;
	$count = count( Media_Format_Model::$calls );
	$builder = $prompt->invoke( null, array( 'prompt' => 'Offline legacy format test.' ), Bhranti_Media::capabilities( 1, 11 ) );
	media_format_check( $builder instanceof WP_AI_Client_Prompt_Builder && $count === count( Media_Format_Model::$calls ), 'PNG-only and older metadata remain discoverable without image requests' );
	$result = $builder->generate_image_result();
	$config = end( Media_Format_Model::$calls );
	media_format_check( $result instanceof GenerativeAiResult && $count + 1 === count( Media_Format_Model::$calls ) && ( $advertised_formats ? 'image/png' === $config['outputMimeType'] : ! isset( $config['outputMimeType'] ) ), 'Actual native builder uses the discovered PNG format or leaves older provider options unchanged' );
}

// The real native SDK matches ratio option values; only the local model generates.
Media_Format_Provider::$formats = array( 'image/png', 'image/jpeg', 'image/webp' );
$ratio_result = new ReflectionMethod( Bhranti_Media::class, 'ratio_result' );
$ratios = new ReflectionMethod( Bhranti_Media::class, 'ratio_candidates' );
foreach ( array(
	array( '16:9', array( '16:9', '3:2', '2:3', '1:1' ), '16:9' ),
	array( '2:3', array( '16:9', '3:2', '2:3', '1:1' ), '2:3' ),
	array( '1:1', array( '16:9', '3:2', '2:3', '1:1' ), '1:1' ),
	array( '5:3', array( '4:3', '3:2' ), '3:2' ),
	array( '2:5', array( '3:4', '9:16' ), '9:16' ),
	array( '32:18', array( '16:9' ), '16:9' ),
	array( '16:9', array( '2:3', '1:1' ), null ),
	array( '2:3', array( '16:9', '1:1' ), null ),
	array( '1:1', array( '16:9' ), null ),
	array( '16:9', array(), null ),
) as $case ) {
	Media_Format_Provider::$ratios = $case[1];
	$args = array( 'prompt' => 'Original offline composition fixture.', 'aspect_ratio' => $case[0] );
	$count = count( Media_Format_Model::$calls ); $format = null; $ratio = null;
	$builder = $prompt->invokeArgs( null, array( $args, Bhranti_Media::capabilities( 1, 11 ), &$format, &$ratio ) );
	media_format_check( $builder instanceof WP_AI_Client_Prompt_Builder && $count === count( Media_Format_Model::$calls ) && 'image/webp' === $format && $case[2] === $ratio, 'Actual native metadata selects the requested/same-orientation/default ratio before generation.' );
	$result = $builder->generate_image_result(); $config = end( Media_Format_Model::$calls );
	media_format_check( $result instanceof GenerativeAiResult && $count + 1 === count( Media_Format_Model::$calls ) && ( $config['outputMediaAspectRatio'] ?? null ) === $case[2] && 'image/webp' === $config['outputMimeType'] && 'inline' === $config['outputFileType'], 'One real SDK call receives exactly the negotiated ratio, MIME and inline configuration.' );
	$selection = $ratio_result->invoke( null, $case[0], $ratio );
	media_format_check( $selection['requested_aspect_ratio'] === $case[0] && $selection['selected_aspect_ratio'] === $case[2] && $selection['aspect_ratio_fallback'] === ( $case[0] !== $case[2] ), 'Requested/native/default ratio selection is transparent and does not claim the output dimensions match.' );
	$actual = $validate->invoke( null, $result->toImageFile(), 11, 1, $args );
	media_format_check( is_array( $actual ) && 320 === $actual['width'] && 240 === $actual['height'] && $png === $actual['bytes'] && $count + 1 === count( Media_Format_Model::$calls ), 'Actual validated dimensions and original bytes remain unchanged even when a provider returns a different shape; no regeneration or crop.' );
}
foreach ( array( '16:9', '2:3', '1:1' ) as $requested ) {
	$candidates = $ratios->invoke( null, $requested );
	media_format_check( $requested === $candidates[0] && null === end( $candidates ) && count( $candidates ) <= 6, 'Ratio negotiation is bounded and exact first/native default last.' );
	list( $width, $height ) = array_map( 'intval', explode( ':', $requested ) );
	foreach ( array_filter( $candidates ) as $candidate ) {
		list( $candidate_width, $candidate_height ) = array_map( 'intval', explode( ':', $candidate ) );
		media_format_check( ( $width <=> $height ) === ( $candidate_width <=> $candidate_height ), 'Every explicit fallback preserves landscape/portrait/square orientation.' );
	}
}
Media_Format_Provider::$ratios = array( '16:9' );
Media_Format_Provider::$formats = array();
$format = null; $ratio = null; $count = count( Media_Format_Model::$calls );
$builder = $prompt->invokeArgs( null, array( array( 'prompt' => 'Offline legacy MIME fixture.', 'aspect_ratio' => '16:9' ), Bhranti_Media::capabilities( 1, 11 ), &$format, &$ratio ) );
media_format_check( $builder instanceof WP_AI_Client_Prompt_Builder && null === $format && '16:9' === $ratio && $count === count( Media_Format_Model::$calls ), 'A formatless native provider can still honor an advertised ratio.' );
$builder->generate_image_result(); $config = end( Media_Format_Model::$calls );
media_format_check( $count + 1 === count( Media_Format_Model::$calls ) && '16:9' === $config['outputMediaAspectRatio'] && ! isset( $config['outputMimeType'], $config['outputFileType'] ), 'Legacy MIME behavior and an exact supported ratio coexist in one actual SDK call.' );
Media_Format_Provider::$formats = array( 'image/png', 'image/jpeg', 'image/webp' );
$metadata_before = Media_Format_Provider::$metadata_calls;
foreach ( array( '', '0:1', '1:0', '01:1', '1:01', '1000:1', '1:1000', '-1:2', '16/9', '1.5:1', '16:9 ', "16:9\n", null, array( '16:9' ), 169 ) as $invalid_ratio ) {
	media_format_check( is_wp_error( $preflight->invoke( null, array( 'aspect_ratio' => $invalid_ratio ), Bhranti_Media::capabilities( 1, 11 ) ) ) && $metadata_before === Media_Format_Provider::$metadata_calls, 'Invalid ratios are rejected before any native metadata or generation.' );
}
Media_Format_Provider::$images = false;
$count = count( Media_Format_Model::$calls );
$unsupported = $prompt->invoke( null, array( 'prompt' => 'Offline no images.', 'aspect_ratio' => '16:9' ), Bhranti_Media::capabilities( 1, 11 ) );
media_format_check( is_wp_error( $unsupported ) && 'bhranti_image_support' === $unsupported->get_error_code() && $count === count( Media_Format_Model::$calls ), 'A genuinely non-image native model is unavailable only after ratio-free support also fails, without generating.' );
Media_Format_Provider::$images = true; Media_Format_Provider::$discovery_failure = true;
$unknown = $prompt->invoke( null, array( 'prompt' => 'Offline uncertain ratio metadata.', 'aspect_ratio' => '16:9' ), Bhranti_Media::capabilities( 1, 11 ) );
media_format_check( is_wp_error( $unknown ) && 'bhranti_image_discovery' === $unknown->get_error_code() && $count === count( Media_Format_Model::$calls ), 'Ratio metadata failure remains unknown and does not trigger fallback generation.' );
Media_Format_Provider::$discovery_failure = false; Media_Format_Provider::$ratios = array();
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

// Explicit capability inspection uses native metadata only, through live policy
// and both identities. The isolated provider cannot make an HTTP request.
require dirname( __DIR__ ) . '/includes/class-bhranti-workspace.php';
function clean_user_cache( $id ) {}
function clean_post_cache( $id ) {}
function get_post( $id ) { return 91 === (int) $id ? (object) array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => 1 ) : null; }
function get_user_meta( $id, $key, $single = false ) { return 11 === (int) $id && 'bhranti_managed_actor' === $key ? 1 : ''; }
$GLOBALS['blog_id'] = 1;
final class Bhranti_Settings {
    public static $media_allowed = true;
    public static $manage = true;
    public static function can_manage() { return self::$manage; }
    public static function allows( $name ) { return self::$media_allowed ? true : new WP_Error( 'bhranti_action_disabled', 'Media is disabled.' ); }
}
Media_Format_Provider::$formats=array('image/png','image/jpeg','image/webp');
$count=count(Media_Format_Model::$calls);$observations=count(Bhranti_Memory::$observations);
$inspection=Bhranti_Media::inspect_images(1,11,91);
media_format_check(!is_wp_error($inspection) && 'available'===$inspection['data']['status'] && 'native_supported'===$inspection['data']['reason'] && array('image/webp')===$inspection['data']['generation_formats'], 'Explicit native inspection finds one actually supported MIME type without claiming all formats');
media_format_check($count===count(Media_Format_Model::$calls) && $observations===count(Bhranti_Memory::$observations) && 1===get_current_user_id() && true===$inspection['metadata_only'] && false===$inspection['rollback_available'], 'Inspection never generates, saves a capability memory, changes identity or implies an undoable site action');
media_format_check(array('image/png','image/webp','image/jpeg')===$inspection['data']['upload_formats'] && $limit===$inspection['data']['max_generated_image_bytes'], 'Inspection exposes only native human/actor MIME intersection and bounded generation storage limit');
media_format_check(false===strpos(wp_json_encode($inspection),'media-format-offline') && false===strpos(wp_json_encode($inspection),'offline-image') && false===strpos(wp_json_encode($inspection),PHP_SAPI), 'Public inspection contains no provider/model identifiers or runtime details');
Media_Format_Provider::$ratios = array( '3:2', '2:3', '1:1' );
$inspection = Bhranti_Media::inspect_images( 1, 11, 91, '16:9' );
media_format_check( 'available' === $inspection['data']['status'] && '16:9' === $inspection['data']['requested_aspect_ratio'] && '3:2' === $inspection['data']['selected_aspect_ratio'] && $inspection['data']['aspect_ratio_fallback'] && $count === count( Media_Format_Model::$calls ), 'Metadata-only helper reports a supported same-orientation ratio fallback without generating.' );
Media_Format_Provider::$ratios = array();
$inspection = Bhranti_Media::inspect_images( 1, 11, 91, '2:3' );
media_format_check( 'available' === $inspection['data']['status'] && '2:3' === $inspection['data']['requested_aspect_ratio'] && null === $inspection['data']['selected_aspect_ratio'] && $inspection['data']['aspect_ratio_fallback'] && $count === count( Media_Format_Model::$calls ), 'A legacy provider lacking ratio metadata remains image-capable and clearly selects native default.' );
$metadata_before = Media_Format_Provider::$metadata_calls;
media_format_check( is_wp_error( Bhranti_Media::inspect_images( 1, 11, 91, '0:2' ) ) && $metadata_before === Media_Format_Provider::$metadata_calls, 'Explicit ratio inspection rejects zero dimensions before native metadata.' );
Media_Format_Provider::$images=false;
$inspection=Bhranti_Media::inspect_images(1,11,91);
media_format_check('unavailable'===$inspection['data']['status'] && 'native_unavailable'===$inspection['data']['reason'] && array()===$inspection['data']['generation_formats'] && $count===count(Media_Format_Model::$calls), 'A confirmed text-only native connector is reported before any generation attempt');
Media_Format_Provider::$images=true;Media_Format_Provider::$discovery_failure=true;
$inspection=Bhranti_Media::inspect_images(1,11,91,'16:9');
media_format_check('unknown'===$inspection['data']['status'] && 'discovery_unavailable'===$inspection['data']['reason'] && '16:9'===$inspection['data']['requested_aspect_ratio'] && null===$inspection['data']['selected_aspect_ratio'] && false===$inspection['data']['aspect_ratio_fallback'] && $count===count(Media_Format_Model::$calls), 'Native ratio metadata failure stays unknown, records no selected/default fallback and cannot trigger generation');
Media_Format_Provider::$discovery_failure=false;Media_Format_Provider::$empty_metadata=true;
$inspection=Bhranti_Media::inspect_images(1,11,91);
media_format_check('unknown'===$inspection['data']['status'] && 'discovery_unavailable'===$inspection['data']['reason'] && $count===count(Media_Format_Model::$calls), 'An empty native model directory is unknown rather than evidence of a text-only connector');
Media_Format_Provider::$empty_metadata=false;Media_Format_Provider::$formats=array();
$inspection=Bhranti_Media::inspect_images(1,11,91);
media_format_check('available'===$inspection['data']['status'] && array()===$inspection['data']['generation_formats'] && $count===count(Media_Format_Model::$calls), 'Legacy formatless image metadata remains supported without inventing MIME support');
add_filter('wp_ai_client_prevent_prompt',$blocked);
$inspection=Bhranti_Media::inspect_images(1,11,91);
media_format_check('unknown'===$inspection['data']['status'] && 'discovery_unavailable'===$inspection['data']['reason'] && $count===count(Media_Format_Model::$calls), 'Native prevention blocks every probe without an unsupported-provider claim or generation');
remove_filter('wp_ai_client_prevent_prompt',$blocked);
$metadata_calls=Media_Format_Provider::$metadata_calls;$limit=0;
$inspection=Bhranti_Media::inspect_images(1,11,91);
media_format_check('site_media_limits'===$inspection['data']['reason'] && 'unavailable'===$inspection['data']['status'] && $metadata_calls===Media_Format_Provider::$metadata_calls, 'A site upload limit stops before native metadata and is not blamed on connector image support');
$limit=1048576;Bhranti_Settings::$media_allowed=false;
media_format_check(is_wp_error(Bhranti_Media::inspect_images(1,11,91)) && $metadata_calls===Media_Format_Provider::$metadata_calls,'Disabled live media policy prevents capability discovery');
Bhranti_Settings::$media_allowed=true;
foreach(array('1:upload_files','11:upload_files','1:manage_options') as $denial){$GLOBALS['media_denied_caps']=array($denial);media_format_check(is_wp_error(Bhranti_Media::inspect_images(1,11,91)) && $metadata_calls===Media_Format_Provider::$metadata_calls,'Both current account permissions are enforced before metadata discovery');}
$GLOBALS['media_denied_caps']=array();
media_format_check(is_wp_error(Bhranti_Media::inspect_images(1,12,91)) && is_wp_error(Bhranti_Media::inspect_images(1,11,92)) && $metadata_calls===Media_Format_Provider::$metadata_calls,'A different actor or unowned conversation cannot inspect connector capability');
$GLOBALS['media_current_user']=2;
media_format_check(is_wp_error(Bhranti_Media::inspect_images(1,11,91)) && $metadata_calls===Media_Format_Provider::$metadata_calls,'A different current user cannot borrow the owner connector identity');
$GLOBALS['media_current_user']=1;
media_format_check($count===count(Media_Format_Model::$calls) && $observations===count(Bhranti_Memory::$observations) && $before===glob(sys_get_temp_dir().'/bhranti-image-*'),'Every explicit inspection branch leaves model generation, capability memory and raster files untouched');
echo 'PASS: ' . $checks . " native offline media-format checks; no WordPress bootstrap, database, network or AI.\n";
