<?php
/**
 * Fail-closed lock errors and adapter propagation without a site bootstrap.
 *
 * Uses WordPress's actual hooks and WP_Error, and real isolated SQLite/sidecar
 * files. No database queries, HTTP, AI, existing sites or credentials are used.
 * Only unavailable acquire/unlock branches inject disabled-function replacements;
 * the occupied-lock branch uses a real second native OS file handle.
 *
 * BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/lock-errors.php
 *
 * @package Bhranti
 */

$lock_error_mode = $argv[2] ?? '';
if ( '--child' !== ( $argv[1] ?? '' ) ) {
	$lock_error_total = 0;
	foreach ( array( 'unsupported', 'missing-database', 'symlink', 'directory', 'public-sidecar', 'flock-unavailable', 'flock-missing', 'unlock-unavailable', 'reacquire-contention', 'contention', 'network-missing-database' ) as $lock_error_case ) {
		$lock_error_command = array( PHP_BINARY );
		if ( in_array( $lock_error_case, array( 'flock-unavailable', 'flock-missing', 'unlock-unavailable', 'reacquire-contention' ), true ) ) {
			$lock_error_command[] = '-d';
			$lock_error_command[] = 'disable_functions=flock';
		}
		$lock_error_command = array_merge( $lock_error_command, array( __FILE__, '--child', $lock_error_case ) );
		$lock_error_process = proc_open( $lock_error_command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $lock_error_pipes );
		if ( ! is_resource( $lock_error_process ) ) {
			throw new RuntimeException( 'Could not start an isolated lock-error test.' );
		}
		$lock_error_output = stream_get_contents( $lock_error_pipes[1] );
		$lock_error_stderr = stream_get_contents( $lock_error_pipes[2] );
		fclose( $lock_error_pipes[1] );
		fclose( $lock_error_pipes[2] );
		$lock_error_exit = proc_close( $lock_error_process );
		$lock_error_result = json_decode( $lock_error_output, true );
		if ( 0 !== $lock_error_exit || '' !== $lock_error_stderr || ! is_array( $lock_error_result ) || $lock_error_case !== ( $lock_error_result['case'] ?? '' ) ) {
			throw new RuntimeException( 'Lock-error case failed: ' . $lock_error_case . "\n" . $lock_error_output . $lock_error_stderr );
		}
		$lock_error_total += $lock_error_result['checks'];
	}
	echo 'PASS ' . $lock_error_total . " lock-error and contention checks in 11 isolated processes.\n";
	return;
}

$lock_error_wp = rtrim( getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: dirname( __DIR__, 4 ), '/' );
if ( ! is_file( $lock_error_wp . '/wp-includes/class-wp-error.php' ) ) {
	throw new RuntimeException( 'Set BHRANTI_TEST_WP_ROOT to a local WordPress source tree.' );
}
define( 'ABSPATH', $lock_error_wp . '/' );
define( 'DAY_IN_SECONDS', 86400 );
require ABSPATH . 'wp-includes/plugin.php';
require ABSPATH . 'wp-includes/class-wp-error.php';
/** Minimal type check; the actual WP_Error and hook implementation are loaded above. */
function is_wp_error( $value ) { return $value instanceof WP_Error; }

/** Translation is irrelevant to assertions; preserve the plugin message. */
function __( $text, $domain = 'default' ) { return $text; }
/** Only the workspace lock name needs an authenticated fixture identity. */
function get_current_user_id() { return 7; }
/** Exercise the real network acquisition branch only in its own process. */
function is_multisite() { return 'network-missing-database' === $GLOBALS['lock_error_mode']; }
/** The isolated network owns its first site's lifecycle lock. */
function get_main_site_id() { return 1; }
/** Track native blog restoration without loading any real site. */
function switch_to_blog( $site ) { $GLOBALS['lock_error_blogs'][] = $site; }
/** Record the inverse of the isolated native blog switch. */
function restore_current_blog() { array_pop( $GLOBALS['lock_error_blogs'] ); }

if ( in_array( $lock_error_mode, array( 'flock-unavailable', 'unlock-unavailable', 'reacquire-contention' ), true ) ) {
	if ( function_exists( 'flock' ) ) {
		throw new RuntimeException( 'The unavailable-flock case requires its isolated disabled-function configuration.' );
	}
	/** Model an unsupported advisory-lock syscall, without claiming contention. */
	function flock( $stream, $operation, &$would_block = null ) {
		$would_block = 0;
		if ( 'reacquire-contention' === $GLOBALS['lock_error_mode'] ) {
			$path = stream_get_meta_data( $stream )['uri'];
			if ( ! empty( $GLOBALS['lock_error_occupied'][ $path ] ) ) { $would_block = 1; return false; }
			if ( LOCK_UN === $operation ) { return true; }
			if ( ! empty( $GLOBALS['lock_error_first'][ $path ] ) ) { $GLOBALS['lock_error_occupied'][ $path ] = true; $would_block = 1; return false; }
			$GLOBALS['lock_error_first'][ $path ] = true;
			return true;
		}
		return 'unlock-unavailable' === $GLOBALS['lock_error_mode'] && ( LOCK_EX | LOCK_NB ) === $operation;
	}
}

$lock_error_root = sys_get_temp_dir() . '/bhranti-lock-errors-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $lock_error_root, 0700 ) ) {
	throw new RuntimeException( 'Could not create the isolated lock-error fixture.' );
}
if ( 'unsupported' !== $lock_error_mode ) {
	define( 'DB_ENGINE', 'sqlite' );
	define( 'DB_PATH', $lock_error_root . '/isolated.sqlite' );
	if ( ! in_array( $lock_error_mode, array( 'missing-database', 'network-missing-database' ), true ) ) {
		$lock_error_database = new PDO( 'sqlite:' . DB_PATH );
		$lock_error_database->exec( 'CREATE TABLE fixture (id INTEGER PRIMARY KEY)' );
		$lock_error_database = null;
	}
}
$GLOBALS['wpdb'] = (object) array( 'options' => 'wp_options', 'is_mysql' => false );
$GLOBALS['lock_error_blogs'] = array();
$GLOBALS['lock_error_checks'] = 0;
$GLOBALS['lock_error_callbacks'] = 0;
$lock_error_handles = array();

foreach ( array( 'lock', 'workspace', 'settings', 'memory', 'plugins', 'forms', 'commerce' ) as $lock_error_class ) {
	require dirname( __DIR__ ) . '/includes/class-bhranti-' . $lock_error_class . '.php';
}

/** Assert an externally observable safety or diagnosis guarantee. */
function lock_error_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $GLOBALS['lock_error_mode'] . ': ' . $message );
	}
	++$GLOBALS['lock_error_checks'];
}
/** Reach private acquisition boundaries without invoking unrelated operations. */
function lock_error_invoke( $class, $method, $arguments = array() ) {
	$reflection = new ReflectionMethod( $class, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$reflection->setAccessible( true );
	}
	return $reflection->invokeArgs( null, $arguments );
}
/** A callback must never run if its serialization guard was not acquired. */
function lock_error_callback() { ++$GLOBALS['lock_error_callbacks']; return 'unsafe callback'; }
/** Remove only the freshly created fixture, preserving symlink targets. */
function lock_error_cleanup( $path ) {
	if ( is_link( $path ) || is_file( $path ) ) {
		unlink( $path );
		return;
	}
	if ( is_dir( $path ) ) {
		foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $item ) {
			lock_error_cleanup( $path . '/' . $item );
		}
		rmdir( $path );
	}
}

try {
	$lock_error_names = array( 'bhranti_lock_7', 'bhranti_plugins_lock', 'bhranti_forms_lock', 'bhranti_commerce_lock', 'bhranti_settings_lock', 'bhranti_memory_lock', 'bhranti_memory_network_lock' );
	if ( in_array( $lock_error_mode, array( 'symlink', 'directory', 'public-sidecar', 'contention' ), true ) ) {
		foreach ( $lock_error_names as $lock_error_name ) {
			$lock_error_path = $lock_error_root . '/.ht-bhranti-lock-' . substr( hash( 'sha256', basename( DB_PATH ) . "\nwp_options\n" . $lock_error_name ), 0, 40 );
			if ( 'symlink' === $lock_error_mode ) {
				file_put_contents( $lock_error_root . '/target', 'unchanged unrelated target' );
				symlink( $lock_error_root . '/target', $lock_error_path );
			} elseif ( 'directory' === $lock_error_mode ) {
				mkdir( $lock_error_path, 0700 );
			} elseif ( 'public-sidecar' === $lock_error_mode ) {
				file_put_contents( $lock_error_path, 'existing unsafe sidecar' );
				chmod( $lock_error_path, 0644 );
			} else {
				$lock_error_handle = fopen( $lock_error_path, 'x+' );
				chmod( $lock_error_path, 0600 );
				lock_error_check( flock( $lock_error_handle, LOCK_EX | LOCK_NB ), 'The native fixture owns the real advisory lock.' );
				$lock_error_handles[] = $lock_error_handle;
			}
		}
	}
	$lock_error_streams = count( get_resources( 'stream' ) );
	$lock_error_contended = in_array( $lock_error_mode, array( 'contention', 'reacquire-contention' ), true );
	foreach ( $lock_error_names as $lock_error_name ) {
		if ( in_array( $lock_error_mode, array( 'unlock-unavailable', 'reacquire-contention' ), true ) ) {
			$claim = lock_error_invoke( 'Bhranti_Lock', 'claim_guard', array( $lock_error_name ) );
			lock_error_check( $lock_error_contended ? false === $claim : is_wp_error( $claim ), 'A broken release or lost reacquisition is rejected before any lock-option write.' );
		}
		$lock_error_value = Bhranti_Lock::acquire( $lock_error_name, 600 );
		if ( $lock_error_contended ) {
			lock_error_check( false === $lock_error_value, 'Real lock contention remains false, not a technical error.' );
			lock_error_check( true === Bhranti_Lock::active( $lock_error_name, 600 ), 'An actual occupied guard remains visibly active.' );
		} else {
			lock_error_check( is_wp_error( $lock_error_value ) && 'bhranti_lock_guard' === $lock_error_value->get_error_code(), 'A failed guard is reported as an operational error.' );
			lock_error_check( 503 === ( $lock_error_value->get_error_data()['status'] ?? 0 ), 'An unavailable guard is HTTP 503, not another user’s busy request.' );
			lock_error_check( is_wp_error( Bhranti_Lock::active( $lock_error_name, 600 ) ), 'An unverifiable guard is never reported as inactive.' );
			lock_error_check( false === strpos( $lock_error_value->get_error_message(), $lock_error_root ), 'No private filesystem path leaks through the returned message.' );
		}
	}
	$lock_error_results = array(
		'workspace' => Bhranti_Workspace::mutate( 'lock_error_callback' ),
		'plugins'   => lock_error_invoke( 'Bhranti_Plugins', 'acquire_lock' ),
		'forms'     => lock_error_invoke( 'Bhranti_Forms', 'lock' ),
		'commerce'  => lock_error_invoke( 'Bhranti_Commerce', 'lock' ),
		'settings'  => lock_error_invoke( 'Bhranti_Settings', 'persist', array( array(), 'unreached', array(), 'lock_error_callback', 'lock_error_callback' ) ),
		'memory'    => lock_error_invoke( 'Bhranti_Memory', 'locked', array( 'lock_error_callback' ) ),
	);
	foreach ( $lock_error_results as $lock_error_adapter => $lock_error_result ) {
		lock_error_check( is_wp_error( $lock_error_result ), $lock_error_adapter . ' never treats a failed acquisition as an owned token.' );
		lock_error_check( ( $lock_error_contended ? 409 : 503 ) === ( $lock_error_result->get_error_data()['status'] ?? 0 ), $lock_error_adapter . ' keeps contention and technical failure distinct.' );
		if ( ! $lock_error_contended ) {
			lock_error_check( 'bhranti_lock_guard' === $lock_error_result->get_error_code(), $lock_error_adapter . ' preserves the precise guard error.' );
		}
	}
	lock_error_check( count( get_resources( 'stream' ) ) === $lock_error_streams, 'Failed acquisition and activity probes leave no open stream handles.' );
	lock_error_check( 0 === $GLOBALS['lock_error_callbacks'], 'Failed guards never enter protected operations.' );
	lock_error_check( array() === $GLOBALS['lock_error_blogs'], 'Network failure restores the caller’s original site context.' );
	if ( 'symlink' === $lock_error_mode ) {
		lock_error_check( 'unchanged unrelated target' === file_get_contents( $lock_error_root . '/target' ), 'Rejected symlinks do not modify their targets.' );
	}
	if ( 'public-sidecar' === $lock_error_mode ) {
		lock_error_check( 'existing unsafe sidecar' === file_get_contents( $lock_error_path ) && 0644 === ( fileperms( $lock_error_path ) & 0777 ), 'Existing insecure sidecars are neither repaired nor truncated.' );
	}
	echo json_encode( array( 'case' => $lock_error_mode, 'checks' => $GLOBALS['lock_error_checks'] ) ) . PHP_EOL;
} finally {
	foreach ( $lock_error_handles as $lock_error_handle ) {
		flock( $lock_error_handle, LOCK_UN );
		fclose( $lock_error_handle );
	}
	lock_error_cleanup( $lock_error_root );
}
