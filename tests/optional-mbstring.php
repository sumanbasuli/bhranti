<?php
/**
 * Offline regression for hosts without optional mbstring-only functions.
 *
 * Native formatting, HTML, UTF-8 and response parsing; no wp-load.php, database,
 * HTTP, provider, uploads or site writes. Run with the two formerly required
 * functions disabled even when the local PHP build includes mbstring:
 * BHRANTI_TEST_WP_ROOT=/path/to/wordpress php -d disable_functions=mb_strcut,mb_strtolower tests/optional-mbstring.php
 */

define( 'ABSPATH', rtrim( getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Documents/WordPress/Plugins', '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/compat-utf8.php';
require ABSPATH . WPINC . '/utf8.php';
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/class-wp-error.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/kses.php';
require ABSPATH . WPINC . '/http.php';
foreach ( array( 'class-wp-html-attribute-token', 'class-wp-html-text-replacement', 'class-wp-html-span', 'class-wp-html-decoder', 'class-wp-html-tag-processor' ) as $class ) {
	require ABSPATH . WPINC . '/html-api/' . $class . '.php';
}

function __( $text, $domain = 'default' ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function is_utf8_charset( $value = null ) { return true; }
function get_bloginfo( $key ) { return 'UTF-8'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_allowed_protocols() { return array( 'http', 'https', 'mailto' ); }
function wp_get_upload_dir() { return array( 'baseurl' => 'https://example.invalid/uploads' ); }

require dirname( __DIR__ ) . '/includes/class-bhranti-context.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-chat.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-references.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-agent.php';

$checks = 0;
function om_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$GLOBALS['checks'];
}
function om_response( $text ) {
	$method = new ReflectionMethod( Bhranti_References::class, 'response' );
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}
	return $method->invoke( null, array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'text/html; charset=UTF-8' ), 'body' => $text ), 'https://example.org/design/' );
}

om_check( ! function_exists( 'mb_strcut' ) && ! function_exists( 'mb_strtolower' ), 'Run this regression with mb_strcut and mb_strtolower disabled.' );

// Every partial two-, three- and four-byte UTF-8 suffix must be discarded, while
// a complete character ending exactly at the byte limit must remain intact.
foreach ( array( 'é', '€', '😀' ) as $character ) {
	$length = strlen( $character );
	$prefix = str_repeat( 'x', 240000 - $length );
	$rendered = Bhranti_Chat::render( $prefix . $character . 'tail' );
	om_check( $prefix . $character === trim( wp_strip_all_tags( $rendered ) ), 'Chat preserves a complete multibyte character at the byte limit.' );
	for ( $partial = 1; $partial < $length; ++$partial ) {
		$prefix = str_repeat( 'x', 240000 - $partial );
		$rendered = Bhranti_Chat::render( $prefix . $character . 'tail' );
		om_check( $prefix === trim( wp_strip_all_tags( $rendered ) ) && wp_is_valid_utf8( $rendered ), 'Chat retains its valid prefix when the byte boundary splits a multibyte character.' );
	}
	foreach ( array( 512000, 524288 ) as $limit ) {
		$prefix = str_repeat( 'x', $limit - $length );
		$result = om_response( $prefix . $character . 'z' );
		om_check( ! is_wp_error( $result ) && wp_is_valid_utf8( $result['data']['page']['html'] ) && true === $result['data']['truncated'], 'Reference context accepts a complete character at either byte boundary.' );
		for ( $partial = 1; $partial < $length; ++$partial ) {
			// Native HTTP can end its one-byte lookahead inside the same character.
			$result = om_response( substr( str_repeat( 'x', $limit - $partial ) . $character, 0, 524289 ) );
			om_check( ! is_wp_error( $result ) && wp_is_valid_utf8( $result['data']['page']['html'] ) && strlen( $result['data']['page']['html'] ) <= 12000, 'Reference trimming avoids an invalid UTF-8 fragment at either byte boundary.' );
		}
	}
}
om_check( is_wp_error( om_response( "<p>Invalid \xFF input</p>" ) ), 'Reference validation still rejects invalid interior UTF-8.' );
om_check( is_wp_error( om_response( "Invalid trailing \xE2\x80" ) ), 'A short invalid response is rejected rather than treated as an HTTP boundary cut.' );
om_check( is_wp_error( om_response( str_repeat( 'x', 524290 ) ) ), 'References still reject an over-limit HTTP response.' );
om_check( '<p>Normal <strong>chat</strong>.</p>' === trim( Bhranti_Chat::render( 'Normal <strong>chat</strong>.' ) ), 'Ordinary chat formatting remains intact.' );

// Compare through actual recovery receipt lookup, with a different prompt/hash
// so only a Unicode-aware title match can reuse the existing generated image.
$completed = new ReflectionMethod( Bhranti_Agent::class, 'completed_command' );
if ( PHP_VERSION_ID < 80100 ) {
	$completed->setAccessible( true );
}
$receipt = array( 'id' => 'image-receipt', 'success' => true, 'run_id' => 'fixture-run', 'operation' => 'image', 'tool' => 'generate_image', 'command_hash' => str_repeat( '0', 64 ), 'target' => array( 'title' => 'Étoile Ω [cat].* / +' ) );
$run = array( 'id' => 'fixture-run', 'recovery_stage' => 1, 'receipts' => array( $receipt ) );
$command = array( 'tool' => 'generate_image', 'args' => array( 'title' => "  étoile   ω [CAT].* / +  ", 'prompt' => 'Rephrased image prompt' ) );
om_check( $receipt === $completed->invoke( null, $run, $command ), 'Recovery matches Unicode case and whitespace without mbstring, including literal regex punctuation.' );
$command['args']['title'] = 'étoile ω catXXXX / +';
om_check( null === $completed->invoke( null, $run, $command ), 'Title punctuation is literal and cannot match a different image through regex syntax.' );
$command['args']['title'] = '';
om_check( null === $completed->invoke( null, $run, $command ), 'An empty title cannot reuse an unrelated image.' );
$command['args']['title'] = 'Étoile Ω [cat].* / +';
$run['receipts'][0]['success'] = false;
om_check( null === $completed->invoke( null, $run, $command ), 'A failed image remains ineligible for replay suppression.' );
$run['receipts'][0] = $receipt;
$run['receipts'][0]['run_id'] = 'another-run';
om_check( null === $completed->invoke( null, $run, $command ), 'Another run cannot supply a completed image receipt.' );

echo 'PASS ' . $checks . " optional-mbstring checks; native text/HTML paths, no database, network, AI or site writes.\n";
