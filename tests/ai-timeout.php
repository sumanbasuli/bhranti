<?php
/**
 * Isolated native WordPress timeout hook regression.
 *
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/ai-timeout.php
 * Only native hook classes are loaded; no site bootstrap, HTTP, AI or DB.
 * A test namespace spies on handle options while evaluating the trusted local
 * Workspace class body. Production never evaluates generated code.
 */
namespace BhrantiTimeoutQA {
	/** Spy only on the transport handle's options; native hooks remain real. */
	function curl_setopt( $handle, $option, $value ) {
		$handle->options[ $option ] = $value;
		$handle->calls[] = array( $option, $value );
		if ( CURLOPT_TIMEOUT === $option ) {
			$handle->options[ CURLOPT_TIMEOUT_MS ] = $value * 1000;
		}
		return true;
	}
}

namespace {
	define( 'ABSPATH', rtrim( getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Documents/WordPress/Plugins', '/\\' ) . '/' );
	require ABSPATH . 'wp-includes/plugin.php';
	require ABSPATH . 'wp-includes/class-wp-error.php';
	require ABSPATH . 'wp-includes/Requests/src/Autoload.php';
	WpOrg\Requests\Autoload::register();
	require ABSPATH . 'wp-includes/class-wp-http-requests-hooks.php';
	// Execute the actual staged class body in a test namespace to spy on cURL
	// options without making a network request or replacing any native hook API.
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-bhranti-workspace.php' );
	eval( 'namespace BhrantiTimeoutQA;' . substr( $source, 5 ) );
	$checks = 0;
	function timeout_check( $condition, $message ) {
		global $checks;
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
		++$checks;
	}
	function timeout_handle() {
		return (object) array(
			'options' => array(
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_HTTPHEADER => array( 'Authorization: fixture-only', 'Content-Type: application/json' ),
			),
			'calls' => array(),
		);
	}
	function timeout_dispatch( $handle, $request ) {
		$hooks = new WP_HTTP_Requests_Hooks( 'https://native-provider.example.invalid/responses', $request );
		$hooks->dispatch( 'curl.before_send', array( &$handle ) );
	}
	function timeout_scope_count() {
		global $wp_filter;
		return count( $wp_filter['http_api_curl']->callbacks[ PHP_INT_MAX ] ?? array() );
	}
	$studio = static function ( $handle ) {
		BhrantiTimeoutQA\curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 30 );
		BhrantiTimeoutQA\curl_setopt( $handle, CURLOPT_TIMEOUT, 30 );
	};
	add_action( 'http_api_curl', $studio, 1, 1 );
	foreach ( array( 120.0, 180.0, 60, '120', 0.0011 ) as $duration ) {
		$handle = timeout_handle();
		$preserved = $handle->options;
		$request = array( 'timeout' => $duration, 'sslverify' => true, 'redirection' => 0, 'headers' => array( 'Authorization' => 'fixture-only' ) );
		$returned = BhrantiTimeoutQA\Bhranti_Workspace::ai_request(
			static function () use ( $handle, $request ) {
				timeout_check( 1 === timeout_scope_count(), 'Scoped hook registered only during AI call' );
				timeout_dispatch( $handle, $request );
				return $request;
			}
		);
		timeout_check( (int) ceil( (float) $duration * 1000 ) === $handle->options[ CURLOPT_TIMEOUT_MS ], 'Native duration restored after Studio thirty-second override' );
		timeout_check( 30 === $handle->options[ CURLOPT_CONNECTTIMEOUT ], 'Studio connection timeout preserved' );
		timeout_check( $preserved === array_intersect_key( $handle->options, $preserved ), 'TLS, headers and redirect options unchanged' );
		timeout_check( $returned === $request && 0 === timeout_scope_count(), 'Native return and argument values unchanged; scoped hook removed' );
	}
	foreach ( array( null, false, true, '', 'invalid', 0, -1, 181, INF, NAN, array( 120 ) ) as $duration ) {
		$handle = timeout_handle();
		BhrantiTimeoutQA\Bhranti_Workspace::ai_request( static function () use ( $handle, $duration ) { timeout_dispatch( $handle, array( 'timeout' => $duration ) ); } );
		timeout_check( 30000 === $handle->options[ CURLOPT_TIMEOUT_MS ] && 2 === count( $handle->calls ), 'Invalid or unbounded timeout not altered' );
	}
	$error = new WP_Error( 'fixture_native_failure', 'Fixture only' );
	timeout_check( $error === BhrantiTimeoutQA\Bhranti_Workspace::ai_request( static fn() => $error ) && 0 === timeout_scope_count(), 'WP_Error returns unchanged with hook cleaned' );
	$expected = new RuntimeException( 'Fixture native exception' );
	try {
		BhrantiTimeoutQA\Bhranti_Workspace::ai_request( static function () use ( $expected ) { throw $expected; } );
		throw new RuntimeException( 'Expected native exception missing' );
	} catch ( RuntimeException $caught ) {
		timeout_check( $caught === $expected && 0 === timeout_scope_count(), 'Throwable identity preserved with hook cleaned' );
	}
	BhrantiTimeoutQA\Bhranti_Workspace::ai_request(
		static function () {
			timeout_check( 1 === timeout_scope_count(), 'Outer scope active' );
			BhrantiTimeoutQA\Bhranti_Workspace::ai_request( static function () {
				timeout_check( 2 === timeout_scope_count(), 'Nested scope uses independent closure' );
				$handle = timeout_handle();
				timeout_dispatch( $handle, array( 'timeout' => 180.0 ) );
				timeout_check( 180000 === $handle->options[ CURLOPT_TIMEOUT_MS ], 'Nested native duration restored' );
			} );
			timeout_check( 1 === timeout_scope_count(), 'Nested cleanup preserves outer scope' );
		}
	);
	timeout_check( 0 === timeout_scope_count() && 1 === has_action( 'http_api_curl', $studio ), 'All own scopes removed; existing Studio hook retained' );
	$after = timeout_handle();
	timeout_dispatch( $after, array( 'timeout' => 120.0 ) );
	timeout_check( 30000 === $after->options[ CURLOPT_TIMEOUT_MS ], 'Non-AI request after scope is unchanged' );
	remove_action( 'http_api_curl', $studio, 1 );
	echo 'PASS ' . $checks . " isolated native timeout hook checks\n";
}
