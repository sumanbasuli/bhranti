<?php
/**
 * Offline authored-content checks using the actual native block and HTML APIs.
 * No wp-load.php, database, HTTP, AI, dynamic blocks or site mutations.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/content-quality.php
 */
$core_root = getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Studio/bhranti-2';
define( 'ABSPATH', rtrim( $core_root, '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
require ABSPATH . WPINC . '/class-wp-error.php';
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/class-wp-block-parser.php';
require ABSPATH . WPINC . '/blocks.php';
require ABSPATH . WPINC . '/class-wp-token-map.php';
require ABSPATH . WPINC . '/html-api/html5-named-character-references.php';
foreach ( array( 'attribute-token', 'span', 'doctype-info', 'text-replacement', 'decoder', 'tag-processor' ) as $class ) {
	require ABSPATH . WPINC . '/html-api/class-wp-html-' . $class . '.php';
}
function __( $text, $domain = 'default' ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function _doing_it_wrong( $function, $message, $version ) { throw new RuntimeException( 'Unexpected native API misuse: ' . $function ); }
function wp_remote_request() { throw new RuntimeException( 'HTTP forbidden.' ); }
function wp_ai_client_prompt() { throw new RuntimeException( 'AI forbidden.' ); }
add_filter( 'pre_render_block', static function() { throw new RuntimeException( 'Rendered block callbacks forbidden.' ); } );
require dirname( __DIR__ ) . '/includes/class-bhranti-content-quality.php';
$checks = 0;
function cq_check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$GLOBALS['checks'];
}
function cq_pass( $content, $before = '' ) {
	cq_check( true === Bhranti_Content_Quality::validate( $content, $before ), 'Expected accepted authored content.' );
}
function cq_error( $content, $codes, $before = '' ) {
	$result = Bhranti_Content_Quality::validate( $content, $before );
	cq_check( $result instanceof WP_Error, 'Expected a quality error.' );
	cq_check( 'bhranti_content_quality' === $result->get_error_code(), 'Fixed public error code.' );
	$data = $result->get_error_data();
	cq_check( 422 === $data['status'] && count( $data['issues'] ) <= 10, 'Bounded safe error data.' );
	$actual = $data['issues']; sort( $actual ); sort( $codes );
	cq_check( $actual === $codes, 'Expected specific predefined repair codes.' );
	cq_check( strlen( $result->get_error_message() ) < 1400, 'Bounded repair message.' );
	return $result;
}
function cq_block( $name, $attributes, $html ) {
	return '<!-- wp:' . $name . ' ' . json_encode( $attributes ) . ' -->' . $html . '<!-- /wp:' . $name . ' -->';
}
function cq_color( $foreground, $extra = 'font-size:16px;', $text = 'Readable text' ) {
	return '<p style="color:' . $foreground . ';background-color:#fff;' . $extra . '">' . $text . '</p>';
}

cq_pass( '<p>A useful café website.</p><img src="image.jpg" alt="The clinic reception">' );
cq_pass( '<img src="decoration.jpg" alt="">' );
cq_error( '<img src="image.jpg">', array( 'image_alt_missing' ) );
$legacy_image = '<img src="legacy.jpg">';
cq_pass( '<p>Unrelated new text.</p>' . $legacy_image, $legacy_image );
cq_error( '<img src="changed.jpg">', array( 'image_alt_missing' ), $legacy_image );
cq_error( $legacy_image . $legacy_image, array( 'image_alt_missing' ), $legacy_image );
cq_pass( $legacy_image . '<img src="new.jpg" alt="A welcome desk">', $legacy_image );

$duplicate = '<h2 id="service">Services</h2><p id="service">Details</p>';
cq_error( $duplicate, array( 'duplicate_id' ) );
cq_pass( $duplicate . '<p>Unrelated text.</p>', $duplicate );
cq_error( $duplicate . '<p id="service">Another section</p>', array( 'duplicate_id' ), $duplicate );
cq_pass( '<h2 id="services">Services</h2><p id="contact">Contact</p>' );
cq_error( '<p id="a&amp;b">A</p><p id="a&#38;b">B</p>', array( 'duplicate_id' ) );
cq_pass( '<p id="different">A</p><p id="Different">B</p>' );

cq_error( '<a href="/contact"></a>', array( 'link_name_missing' ) );
cq_error( '<button type="button"> &nbsp; </button>', array( 'button_name_missing' ) );
cq_error( '<a href="/contact"><img src="decoration.jpg" alt=""></a>', array( 'link_name_missing' ) );
cq_error( '<a href="/contact"><img src="photo.jpg"></a>', array( 'image_alt_missing', 'link_name_missing' ) );
cq_pass( '<a href="/contact"><img src="photo.jpg" alt="Contact the clinic"></a>' );
cq_pass( '<a href="/contact" aria-label="Contact"><img src="decoration.jpg" alt=""></a>' );
cq_pass( '<button aria-labelledby="external-label"></button>' );
cq_pass( '<button title="Close"></button>' );
cq_pass( '<button>预约</button><a href="/">0</a><a href="/"><img alt="0" src="zero.jpg"></a>' );
cq_pass( "<button>Legacy \xff label</button>" );
cq_pass( '<button><custom-icon></custom-icon></button>' );
cq_pass( '<button hidden></button><a aria-hidden="true" href="/"></a>' );
cq_pass( '<a href="/old"></a><p>New text</p>', '<a href="/old"></a>' );
cq_error( '<a href="/new"></a>', array( 'link_name_missing' ), '<a href="/old"></a>' );

cq_pass( cq_color( '#000' ) );
cq_pass( cq_color( '#767676' ) );
cq_error( cq_color( '#777777' ), array( 'text_contrast_low' ) );
cq_error( cq_color( '#949494' ), array( 'text_contrast_low' ) );
cq_pass( '<h2 style="color:#949494;background-color:#fff">Native theme-sized heading</h2>' );
cq_error( '<h2 style="color:#949494;background-color:#fff;font-size:16px">Explicit smaller heading</h2>', array( 'text_contrast_low' ) );
cq_pass( cq_color( '#949494', '' ) );
cq_error( cq_color( '#959595', '' ), array( 'text_contrast_low' ) );
cq_pass( cq_color( '#949494', 'font-size:24px;' ) );
cq_error( cq_color( '#959595', 'font-size:24px;' ), array( 'text_contrast_low' ) );
cq_pass( cq_color( '#949494', 'font-size:18pt;' ) );
cq_pass( cq_color( '#949494', 'font-size:14pt;font-weight:bold;' ) );
cq_error( cq_color( '#949494', 'font-size:13.99pt;font-weight:700;' ), array( 'text_contrast_low' ) );
cq_error( cq_color( '#949494', 'font-size:14pt;font-weight:400;' ), array( 'text_contrast_low' ) );
cq_pass( cq_color( '#777777', 'font-size:var(--theme-size);' ) );
cq_pass( '<p style="color:var(--text);background-color:#fff">Unknown</p>' );
cq_pass( '<p style="color:#fff">Unknown background</p>' );
cq_pass( '<p style="color:#fff;background-color:#fff;background-image:linear-gradient(black,white)">Unknown</p>' );
cq_pass( '<p style="color:#fff;background-color:#fff;opacity:0.5">Unknown compositing</p>' );
cq_pass( '<p style="color:#fff;background-color:#fff;text-shadow:0 0 2px black">Unknown halo</p>' );
cq_pass( '<p style="color:#000!important;color:#fff;background-color:#fff">Important</p>' );
cq_error( '<p style="color:#fff!important;color:#000;background-color:#fff">Important</p>', array( 'text_contrast_low' ) );
cq_pass( cq_color( '#ffffff00' ) );
cq_error( cq_color( '#ffff' ), array( 'text_contrast_low' ) );
cq_error( cq_color( '#ffffffff' ), array( 'text_contrast_low' ) );
cq_pass( '<div style="color:#fff;background-color:#fff"><p style="color:var(--theme-color)">Unknown child</p></div>' );
cq_pass( '<div style="color:#fff;background-color:#fff"><p style="color:#000;background-color:#fff">Known child</p></div>' );
cq_error( '<div style="color:var(--text)"><p style="color:#fff;background-color:#fff">Known child</p></div>', array( 'text_contrast_low' ) );
cq_pass( cq_color( '#777777' ) . '<p>Unrelated text</p>', cq_color( '#777777' ) );
cq_error( cq_color( '#888888' ), array( 'text_contrast_low' ), cq_color( '#777777' ) );

$styles = array( 'style' => array( 'color' => array( 'text' => '#777777', 'background' => '#ffffff' ), 'typography' => array( 'fontSize' => '16px' ) ) );
cq_error( cq_block( 'paragraph', $styles, '<p>Known core block</p>' ), array( 'text_contrast_low' ) );
cq_pass( cq_block( 'vendor/unknown', $styles, '<p>Unknown rendering</p>' ) );
cq_pass( cq_block( 'paragraph', array( 'style' => array( 'color' => 'malformed' ) ), '<p>Unknown metadata</p>' ) );
$unknown_size = array( 'style' => array( 'color' => array( 'text' => '#949494', 'background' => '#fff' ) ) );
cq_pass( cq_block( 'heading', $unknown_size, '<h2>Native theme-sized block</h2>' ) );
cq_pass( cq_block( 'paragraph', $unknown_size + array( 'fontSize' => 'large' ), '<p>Unknown theme preset</p>' ) );
cq_error( cq_block( 'heading', array( 'style' => array( 'color' => array( 'text' => '#949494', 'background' => '#fff' ), 'typography' => array( 'fontSize' => '16px' ) ) ), '<h2>Explicit smaller block</h2>' ), array( 'text_contrast_low' ) );
cq_pass( cq_block( 'paragraph', $styles, '<p style="color:#000;background-color:#fff">Explicit override</p>' ) );
$large = array( 'style' => array( 'color' => array( 'text' => '#949494', 'background' => '#fff' ), 'typography' => array( 'fontSize' => '24px' ) ) );
cq_pass( cq_block( 'heading', $large, '<h2>Known large block</h2>' ) );
$nested = cq_block( 'group', $styles, '<div>' . cq_block( 'paragraph', array(), '<p>Unknown inheritance</p>' ) . '</div>' );
cq_pass( $nested );

$secret = 'RAW_PRIVATE_CONTENT_918273';
$result = cq_error( '<img src="' . $secret . '">', array( 'image_alt_missing' ) );
cq_check( false === strpos( $result->get_error_message() . json_encode( $result->get_error_data() ), $secret ), 'Raw content never escapes in errors.' );
cq_check( false !== strpos( $result->get_error_message(), 'Images need an alt attribute' ), 'Provider receives an actionable fixed repair label.' );
cq_error( str_repeat( 'x', 1048577 ), array( 'content_review_limit' ) );
cq_error( str_repeat( '<br>', 20001 ), array( 'content_review_limit' ) );
cq_error( str_repeat( '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->', 2001 ), array( 'content_review_limit' ) );
cq_pass( '<p>Readable content</p>', false );
cq_error( array(), array( 'content_review_limit' ) );
require dirname( __DIR__ ) . '/includes/class-bhranti-commerce.php';
$catalog_quality = new ReflectionMethod( 'Bhranti_Commerce', 'content_quality' );
foreach ( array( 'product' => 'props', 'category' => 'term' ) as $kind => $storage ) {
	foreach ( 'category' === $kind ? array( 'description' ) : array( 'description', 'short_description' ) as $field ) {
		$review = array( 'kind' => $kind, 'params' => array( $field => '<img src="/new-catalog.jpg">' ) );
		$result = $catalog_quality->invoke( null, $review, array() );
		cq_check( $result instanceof WP_Error && 'bhranti_content_quality' === $result->get_error_code(), 'Catalog description cannot bypass authored-content checks.' );
		$before = array( $storage => array( $field => $review['params'][ $field ] ) );
		$review['params'][ $field ] .= '<p>New readable details.</p>';
		cq_check( true === $catalog_quality->invoke( null, $review, $before ), 'Native catalog state preserves unchanged legacy issues.' );
		$review['params'][ $field ] = '<img src="/changed-catalog.jpg">';
		cq_check( $catalog_quality->invoke( null, $review, $before ) instanceof WP_Error, 'Changed catalog image needs its own alt attribute.' );
		$review['params'][ $field ] = '<img src="/new-catalog.jpg" alt="Handmade bowl">';
		cq_check( true === $catalog_quality->invoke( null, $review, $before ), 'Valid catalog description passes shared native check.' );
	}
}
cq_check( true === $catalog_quality->invoke( null, array( 'kind' => 'setting', 'params' => array( 'value' => 'yes' ) ), array() ), 'Non-content store settings remain outside markup checks.' );
echo $checks . " authored-content checks passed with native parsers; no rendered compliance claim.\n";
