<?php
/**
 * Atomic option-lock checks in one disposable local WordPress clone.
 *
 * BHRANTI_LOCK_NATIVE_WP_ROOT=/private/tmp/bhranti-production-security-qa-20261018 php tests/locking.php
 * The test touches one new, random lock option in that clone only.
 *
 * @package Bhranti
 */

$lock_root = realpath( getenv( 'BHRANTI_LOCK_NATIVE_WP_ROOT' ) ?: '' );
if ( 'cli' !== PHP_SAPI || '/private/tmp/bhranti-production-security-qa-20261018' !== $lock_root || ! is_file( $lock_root . '/wp-content/database/.ht.sqlite' ) ) {
	fwrite( STDERR, "Use only the disposable private WordPress lock fixture.\n" );
	exit( 1 );
}
define( 'ABSPATH', $lock_root . '/' );
define( 'WP_USE_THEMES', false );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
require $lock_root . '/wp-load.php';
if ( 'https://bhranti.wp.local' !== get_option( 'home' ) || array( 'bhranti/bhranti.php' ) !== get_option( 'active_plugins' ) || 'bhranti_standards_qa' !== DB_NAME || ! DISABLE_WP_CRON || realpath( FQDB ) !== $lock_root . '/wp-content/database/.ht.sqlite' ) {
	throw new RuntimeException( 'Disposable WordPress identity check failed.' );
}
$lock_source = dirname( __DIR__ ) . '/includes/class-bhranti-lock.php';
if ( ! class_exists( 'Bhranti_Lock', false ) || hash_file( 'sha256', ( new ReflectionClass( 'Bhranti_Lock' ) )->getFileName() ) !== hash_file( 'sha256', $lock_source ) ) {
	throw new RuntimeException( 'Stage the current Bhranti lock helper into the disposable clone before testing.' );
}

$lock_checks = 0;
$lock_name   = 'bhranti_lock_' . random_int( 100000000, 999999999 );
$notoptions  = wp_cache_get( 'notoptions', 'options' );

/** Assert a security property with a precise failure label. */
function lock_check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
	++$GLOBALS['lock_checks'];
}

/** Read the real stored row, independent of WordPress's option cache. */
function lock_row( $name ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
}

/** Seed one owned test row without WordPress add_option()'s upsert. */
function lock_seed( $name, $value ) {
	global $wpdb;
	$inserted = $wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => maybe_serialize( $value ), 'autoload' => 'off' ), array( '%s', '%s', '%s' ) );
	lock_check( 1 === $inserted, 'Fixture row inserted.' );
}

/** Clear the three option-cache locations after test-only raw mutations. */
function lock_cache_clear( $name ) {
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
}

/** Wait for process barrier files without relying on scheduler timing. */
function lock_wait_files( $paths ) {
	$deadline = microtime( true ) + 20;
	do {
		$ready = true;
		foreach ( $paths as $path ) {
			if ( ! is_file( $path ) ) {
				$ready = false;
				break;
			}
		}
		if ( $ready ) {
			return;
		}
		usleep( 10000 );
	} while ( microtime( true ) < $deadline );
	throw new RuntimeException( 'Timed out waiting for isolated lock-test process barrier.' );
}

if ( '--worker' === ( $argv[1] ?? '' ) ) {
	$worker_name = $argv[2] ?? '';
	$worker_dir  = realpath( $argv[3] ?? '' );
	$worker_slot = (int) ( $argv[4] ?? 0 );
	if ( ! preg_match( '/\Abhranti_lock_[1-9][0-9]*\z/D', $worker_name ) || ! is_string( $worker_dir ) || 0 !== strpos( $worker_dir, $lock_root . '/wp-content/qa-temp/lock-race-' ) || ! in_array( $worker_slot, array( 1, 2 ), true ) ) {
		throw new RuntimeException( 'Invalid disposable lock-test worker arguments.' );
	}
	file_put_contents( $worker_dir . '/ready-' . $worker_slot, '1', LOCK_EX );
	lock_wait_files( array( $worker_dir . '/start' ) );
	$worker_token = Bhranti_Lock::acquire( $worker_name, 600 );
	file_put_contents( $worker_dir . '/result-' . $worker_slot, wp_json_encode( array( 'won' => is_array( $worker_token ), 'token' => $worker_token ) ), LOCK_EX );
	if ( is_array( $worker_token ) ) {
		lock_wait_files( array( $worker_dir . '/release' ) );
		$released = Bhranti_Lock::release( $worker_name, $worker_token );
		file_put_contents( $worker_dir . '/released-' . $worker_slot, $released ? '1' : '0', LOCK_EX );
	}
	exit( 0 );
}

/** Start two independent PHP/WordPress processes against one fresh lock row. */
function lock_two_process_race( $name, $root ) {
	global $wpdb;
	$directory = $root . '/wp-content/qa-temp/lock-race-' . wp_generate_uuid4();
	if ( ! mkdir( $directory, 0700 ) ) {
		throw new RuntimeException( 'Could not create the disposable lock-test barrier.' );
	}
	$processes = array();
	try {
		foreach ( array( 1, 2 ) as $slot ) {
			$command = array( PHP_BINARY, __FILE__, '--worker', $name, $directory, (string) $slot );
			$pipes   = array();
			$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, array_merge( getenv(), array( 'BHRANTI_LOCK_NATIVE_WP_ROOT' => $root ) ) );
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( 'Could not start an independent lock-test worker.' );
			}
			fclose( $pipes[0] );
			$processes[] = array( $process, $pipes[1], $pipes[2] );
		}
		lock_wait_files( array( $directory . '/ready-1', $directory . '/ready-2' ) );
		file_put_contents( $directory . '/start', '1', LOCK_EX );
		lock_wait_files( array( $directory . '/result-1', $directory . '/result-2' ) );
		$first  = json_decode( file_get_contents( $directory . '/result-1' ), true );
		$second = json_decode( file_get_contents( $directory . '/result-2' ), true );
		lock_check( is_array( $first ) && is_array( $second ) && (int) $first['won'] + (int) $second['won'] === 1, 'Exactly one independent process acquires the same lock.' );
		$winner = $first['won'] ? 1 : 2;
		$token  = 1 === $winner ? $first['token'] : $second['token'];
		lock_check( is_array( $token ) && maybe_serialize( $token ) === lock_row( $name ) && false === Bhranti_Lock::acquire( $name, 600 ), 'The losing process and a later attempt cannot overwrite the winner.' );
		file_put_contents( $directory . '/release', '1', LOCK_EX );
		lock_wait_files( array( $directory . '/released-' . $winner ) );
		lock_check( '1' === file_get_contents( $directory . '/released-' . $winner ) && null === lock_row( $name ), 'Only the winning process releases its owned row.' );
	} finally {
		// Unblock either worker if an assertion fails, then remove only this run's data.
		file_put_contents( $directory . '/start', '1', LOCK_EX );
		file_put_contents( $directory . '/release', '1', LOCK_EX );
		foreach ( $processes as $item ) {
			$state = proc_get_status( $item[0] );
			if ( $state['running'] ) {
				proc_terminate( $item[0] );
			}
			fclose( $item[1] );
			fclose( $item[2] );
			proc_close( $item[0] );
		}
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );
		lock_cache_clear( $name );
		foreach ( glob( $directory . '/*' ) as $path ) {
			unlink( $path );
		}
		rmdir( $directory );
	}
}

global $wpdb;
if ( null !== lock_row( $lock_name ) ) {
	throw new RuntimeException( 'Random fixture lock name unexpectedly exists.' );
}
try {
	lock_check( false === Bhranti_Lock::acquire( 'active_plugins', 60 ) && ! Bhranti_Lock::active( 'active_plugins', 60 ), 'Non-lock option names cannot be touched.' );
	lock_check( false === Bhranti_Lock::acquire( $lock_name, 0 ), 'Zero-duration locks are rejected.' );
	$first = Bhranti_Lock::acquire( $lock_name, 600 );
	lock_check( is_array( $first ) && isset( $first['token'], $first['expires'] ) && $first['expires'] > time(), 'A unique, expiring token is acquired.' );
	lock_check( maybe_serialize( $first ) === lock_row( $lock_name ) && $first === get_option( $lock_name ), 'The new token is stored and WordPress cache sees it.' );
	lock_check( Bhranti_Lock::owns( $lock_name, $first ) && Bhranti_Lock::active( $lock_name, 600 ), 'Fresh database ownership and activity are visible.' );
	lock_check( false === Bhranti_Lock::acquire( $lock_name, 600 ) && maybe_serialize( $first ) === lock_row( $lock_name ), 'A duplicate acquisition cannot replace the current owner.' );

	// The old core add_option() could overwrite this row after a stale cache miss.
	wp_cache_set( 'notoptions', array( $lock_name => true ), 'options' );
	lock_check( false === Bhranti_Lock::acquire( $lock_name, 600 ) && maybe_serialize( $first ) === lock_row( $lock_name ), 'A poisoned notoptions cache cannot overwrite the owner.' );
	$other = array( 'token' => wp_generate_uuid4(), 'expires' => time() + 600 );
	wp_cache_set( $lock_name, maybe_serialize( $other ), 'options' );
	lock_check( Bhranti_Lock::owns( $lock_name, $first ) && ! Bhranti_Lock::owns( $lock_name, $other ), 'Poisoned option cache cannot forge database ownership.' );
	lock_check( ! Bhranti_Lock::release( $lock_name, $other ) && maybe_serialize( $first ) === lock_row( $lock_name ), 'A different token cannot release the owner.' );
	lock_check( Bhranti_Lock::release( $lock_name, $first ) && null === lock_row( $lock_name ) && ! Bhranti_Lock::active( $lock_name, 600 ), 'Release deletes only the owned row and invalidates cache.' );

	$stale = array( 'token' => wp_generate_uuid4(), 'expires' => time() - 1 );
	lock_seed( $lock_name, $stale );
	$successor = Bhranti_Lock::acquire( $lock_name, 600 );
	lock_check( is_array( $successor ) && maybe_serialize( $successor ) === lock_row( $lock_name ), 'An expired token can be replaced atomically.' );
	lock_check( ! Bhranti_Lock::release( $lock_name, $stale ) && Bhranti_Lock::owns( $lock_name, $successor ), 'A stale owner cannot delete its successor.' );
	lock_check( Bhranti_Lock::release( $lock_name, $successor ), 'Successor releases its own row.' );

	lock_seed( $lock_name, time() );
	lock_check( Bhranti_Lock::active( $lock_name, 600 ) && false === Bhranti_Lock::acquire( $lock_name, 600 ), 'A recent legacy workspace timestamp remains protected.' );
	$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - 601 ) ), array( 'option_name' => $lock_name ), array( '%s' ), array( '%s' ) );
	lock_cache_clear( $lock_name );
	lock_check( ! Bhranti_Lock::active( $lock_name, 600 ), 'An expired legacy workspace timestamp is inactive.' );
	$replacement = Bhranti_Lock::acquire( $lock_name, 600 );
	lock_check( is_array( $replacement ) && Bhranti_Lock::owns( $lock_name, $replacement ), 'A stale legacy timestamp migrates to a unique token.' );
	lock_check( Bhranti_Lock::release( $lock_name, $replacement ), 'Migrated workspace token can be released.' );

	lock_seed( $lock_name, 'unknown-lock-format' );
	lock_check( Bhranti_Lock::active( $lock_name, 600 ) && false === Bhranti_Lock::acquire( $lock_name, 600 ), 'Unknown stored lock formats fail closed.' );
	$wpdb->update( $wpdb->options, array( 'option_value' => '' ), array( 'option_name' => $lock_name ), array( '%s' ), array( '%s' ) );
	lock_cache_clear( $lock_name );
	lock_check( Bhranti_Lock::active( $lock_name, 600 ) && false === Bhranti_Lock::acquire( $lock_name, 600 ), 'Even an empty malformed lock row fails closed.' );
} finally {
	// This name was absent before the test; never delete a pre-existing site option.
	$wpdb->delete( $wpdb->options, array( 'option_name' => $lock_name ), array( '%s' ) );
	lock_cache_clear( $lock_name );
	if ( is_array( $notoptions ) ) {
		wp_cache_set( 'notoptions', $notoptions, 'options' );
	}
}
// Simulate a database read failure without changing any real table or option.
__( 'Bhranti could not verify an operation lock.', 'bhranti' );
$real_options_table = $wpdb->options;
$wpdb->options      = $wpdb->prefix . 'bhranti_missing_lock_table_' . random_int( 100000000, 999999999 );
try {
	$read_failure = Bhranti_Lock::active( $lock_name, 600 );
	lock_check( is_wp_error( $read_failure ) && 'bhranti_lock_database' === $read_failure->get_error_code(), 'A database read error is distinct from an absent lock and defers background work.' );
	lock_check( false === Bhranti_Lock::acquire( $lock_name, 600 ), 'A database error cannot grant a new lock.' );
	lock_check( ! Bhranti_Lock::owns( $lock_name, array( 'token' => wp_generate_uuid4(), 'expires' => time() + 600 ) ), 'A database error cannot prove ownership.' );
} finally {
	$wpdb->options = $real_options_table;
}
// Both workers bootstrap WordPress independently and begin only after both are ready.
$race_name = 'bhranti_lock_' . random_int( 100000000, 999999999 );
lock_check( null === lock_row( $race_name ), 'The independent-process lock name did not already exist.' );
lock_two_process_race( $race_name, $lock_root );
echo "Passed {$lock_checks} isolated WordPress lock checks.\n";
