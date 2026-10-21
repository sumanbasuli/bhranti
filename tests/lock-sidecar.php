<?php
/**
 * Isolated SQLite sidecar creation, permission and inode regression checks.
 *
 * No WordPress bootstrap, database, network, site data or AI requests are used.
 *
 * @package Bhranti
 */

$sidecar_ignored_umask = in_array( '--ignored-umask', $argv, true );
$sidecar_denied_chmod  = in_array( '--denied-chmod', $argv, true );
if ( $sidecar_ignored_umask && ! function_exists( 'umask' ) ) {
	function umask( $mask = null ) { return 0; }
}
if ( $sidecar_denied_chmod && ! function_exists( 'chmod' ) ) {
	function chmod( $path, $mode ) { return false; }
}
define( 'ABSPATH', sys_get_temp_dir() . '/' );
require dirname( __DIR__ ) . '/includes/class-bhranti-lock.php';

$sidecar_open   = new ReflectionMethod( 'Bhranti_Lock', 'open_sqlite_guard' );
$sidecar_secure = new ReflectionMethod( 'Bhranti_Lock', 'secure_created_sqlite_guard' );
if ( PHP_VERSION_ID < 80100 ) {
	$sidecar_open->setAccessible( true );
	$sidecar_secure->setAccessible( true );
}
$sidecar_checks = 0;
$sidecar_root   = sys_get_temp_dir() . '/bhranti-lock-sidecar-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $sidecar_root, 0700 ) ) {
	throw new RuntimeException( 'Could not create the isolated sidecar fixture.' );
}

/** Assert an observable sidecar security or compatibility property. */
function sidecar_check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
	++$GLOBALS['sidecar_checks'];
}

/** Read fresh permission bits instead of PHP's path-stat cache. */
function sidecar_mode( $path ) {
	clearstatcache( true, $path );
	return fileperms( $path ) & 0777;
}

/** Remove one real disposable inode precisely between the opener's stat and open. */
final class Bhranti_Sidecar_Race_Stream {
	public $context;
	public static $path;
	public static $mode;
	private $handle;
	public function url_stat( $path, $flags ) {
		clearstatcache( true, self::$path );
		return @lstat( self::$path );
	}
	public function stream_open( $path, $mode, $options, &$opened_path ) {
		self::$mode = $mode;
		unlink( self::$path );
		$this->handle = @fopen( self::$path, $mode );
		return is_resource( $this->handle );
	}
	public function stream_stat() {
		return fstat( $this->handle );
	}
	public function stream_close() {
		if ( is_resource( $this->handle ) ) { fclose( $this->handle ); }
	}
}

try {
	if ( $sidecar_ignored_umask ) {
		$probe = fopen( $sidecar_root . '/creation-probe', 'x' );
		sidecar_check( is_resource( $probe ) && 0 !== ( fstat( $probe )['mode'] & 0077 ), 'The isolated runtime ignores the private creation mask.' );
		fclose( $probe );
	}
	$path   = $sidecar_root . '/new-guard';
	$mask   = umask();
	$handle = $sidecar_open->invoke( null, $path );
	if ( $sidecar_denied_chmod ) {
		sidecar_check( false === $handle && 0 !== ( sidecar_mode( $path ) & 0077 ) && 0 === filesize( $path ), 'A new guard fails closed when private permissions cannot be applied.' );
	} else {
		try {
			sidecar_check( is_resource( $handle ) && 0600 === sidecar_mode( $path ) && 0 === filesize( $path ), 'A new guard becomes an empty private file, including when umask is ignored.' );
			sidecar_check( $mask === umask(), 'The process creation mask is restored.' );
			sidecar_check( flock( $handle, LOCK_EX | LOCK_NB ), 'The returned new handle supports an exclusive advisory lock.' );
			$second = $sidecar_open->invoke( null, $path );
			try {
				sidecar_check( is_resource( $second ) && fstat( $handle )['ino'] === fstat( $second )['ino'], 'A later opener retains the stable sidecar inode.' );
				$would_block = 0;
				sidecar_check( ! flock( $second, LOCK_EX | LOCK_NB, $would_block ) && 1 === $would_block, 'A second handle cannot bypass the current process-lifetime guard.' );
				flock( $handle, LOCK_UN );
				sidecar_check( flock( $second, LOCK_EX | LOCK_NB ), 'The existing handle can acquire the same guard after release.' );
				flock( $second, LOCK_UN );
			} finally {
				if ( is_resource( $second ) ) { fclose( $second ); }
			}
		} finally {
			if ( is_resource( $handle ) ) { fclose( $handle ); }
		}

		$public = $sidecar_root . '/existing-public';
		file_put_contents( $public, 'unchanged existing sidecar' );
		chmod( $public, 0644 );
		$inode = fileinode( $public );
		sidecar_check( false === $sidecar_open->invoke( null, $public ) && 0644 === sidecar_mode( $public ) && $inode === fileinode( $public ) && 'unchanged existing sidecar' === file_get_contents( $public ), 'An existing insecure sidecar is rejected without permission, inode or content repair.' );

		$private = $sidecar_root . '/existing-private';
		file_put_contents( $private, 'preserved private sidecar' );
		chmod( $private, 0600 );
		$private_handle = $sidecar_open->invoke( null, $private );
		try {
			sidecar_check( is_resource( $private_handle ) && 0600 === sidecar_mode( $private ) && 'preserved private sidecar' === file_get_contents( $private ), 'An existing private sidecar opens without truncation or content changes.' );
		} finally {
			if ( is_resource( $private_handle ) ) { fclose( $private_handle ); }
		}

		Bhranti_Sidecar_Race_Stream::$path = $sidecar_root . '/removed-between-stat-and-open';
		file_put_contents( Bhranti_Sidecar_Race_Stream::$path, '' );
		chmod( Bhranti_Sidecar_Race_Stream::$path, 0600 );
		sidecar_check( stream_wrapper_register( 'bhrantisidecarrace', 'Bhranti_Sidecar_Race_Stream' ), 'The isolated removal-race stream is registered.' );
		try {
			$race_handle = $sidecar_open->invoke( null, 'bhrantisidecarrace://guard' );
			try {
				sidecar_check( false === $race_handle && 'r+' === Bhranti_Sidecar_Race_Stream::$mode && ! file_exists( Bhranti_Sidecar_Race_Stream::$path ), 'An existing sidecar removed before open fails closed without creating a replacement inode.' );
			} finally {
				if ( is_resource( $race_handle ) ) { fclose( $race_handle ); }
			}
		} finally {
			stream_wrapper_unregister( 'bhrantisidecarrace' );
		}

		$target = $sidecar_root . '/symlink-target';
		file_put_contents( $target, 'unrelated target' );
		chmod( $target, 0600 );
		symlink( $target, $sidecar_root . '/symlink-guard' );
		sidecar_check( false === $sidecar_open->invoke( null, $sidecar_root . '/symlink-guard' ) && 0600 === sidecar_mode( $target ) && 'unrelated target' === file_get_contents( $target ), 'A symlink is rejected without changing its target.' );
		mkdir( $sidecar_root . '/directory-guard', 0700 );
		sidecar_check( false === $sidecar_open->invoke( null, $sidecar_root . '/directory-guard' ), 'A directory cannot become a sidecar.' );

		$linked_path   = $sidecar_root . '/linked-guard';
		$linked_handle = fopen( $linked_path, 'x' );
		try {
			chmod( $linked_path, 0644 );
			link( $linked_path, $sidecar_root . '/linked-alias' );
			sidecar_check( false === $sidecar_secure->invoke( null, $linked_path, $linked_handle ) && 0644 === sidecar_mode( $linked_path ), 'New-inode normalization refuses a hard-linked inode without chmod.' );
		} finally {
			fclose( $linked_handle );
		}

		$replaced_path   = $sidecar_root . '/replaced-guard';
		$replaced_handle = fopen( $replaced_path, 'x' );
		try {
			chmod( $replaced_path, 0644 );
			rename( $replaced_path, $sidecar_root . '/original-inode' );
			file_put_contents( $replaced_path, 'replacement inode' );
			chmod( $replaced_path, 0644 );
			sidecar_check( false === $sidecar_secure->invoke( null, $replaced_path, $replaced_handle ) && 0644 === sidecar_mode( $replaced_path ) && 'replacement inode' === file_get_contents( $replaced_path ), 'New-inode normalization refuses a replaced path without chmod or content changes.' );
		} finally {
			fclose( $replaced_handle );
		}
	}

	if ( ! $sidecar_ignored_umask ) {
		$prior_mask = umask( 0022 );
		try {
			foreach ( array( array( 'umask', '--ignored-umask' ), array( 'umask,chmod', '--ignored-umask', '--denied-chmod' ) ) as $probe_arguments ) {
				$disabled  = array_shift( $probe_arguments );
				$command   = array_merge( array( PHP_BINARY, '-d', 'disable_functions=' . $disabled, __FILE__ ), $probe_arguments );
				$process   = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
				sidecar_check( is_resource( $process ), 'An independent ignored-umask regression probe starts.' );
				$output = stream_get_contents( $pipes[1] );
				$error  = stream_get_contents( $pipes[2] );
				fclose( $pipes[1] );
				fclose( $pipes[2] );
				sidecar_check( 0 === proc_close( $process ) && false !== strpos( $output, 'isolated SQLite sidecar checks' ) && '' === $error, 'The ignored-umask and denied-chmod probes preserve the sidecar checks. ' . $error );
			}
		} finally {
			umask( $prior_mask );
		}
	}
	printf( "Passed %d isolated SQLite sidecar checks.\n", $sidecar_checks );
} finally {
	foreach ( glob( $sidecar_root . '/*' ) as $fixture ) {
		if ( is_dir( $fixture ) && ! is_link( $fixture ) ) {
			rmdir( $fixture );
		} else {
			unlink( $fixture );
		}
	}
	rmdir( $sidecar_root );
}
