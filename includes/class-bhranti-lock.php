<?php
/**
 * Atomic option-backed locks for Bhranti's site-local operations.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/** Serialize Bhranti writes without WordPress add_option()'s duplicate-key update. */
final class Bhranti_Lock {
	/**
	 * Process-lifetime guards held by this PHP request.
	 *
	 * @var array<string,array>
	 */
	private static $guards = array();

	/**
	 * Maps each issued token UUID to its guard key.
	 *
	 * @var array<string,string>
	 */
	private static $tokens = array();

	/**
	 * Acquire an exact lock name while holding a process-lifetime guard.
	 *
	 * @param string $name One of Bhranti's private lock option names.
	 * @param int    $ttl  Lifetime in seconds.
	 * @return array|false Unique token, or false when the lock is occupied or unavailable.
	 */
	public static function acquire( $name, $ttl ) {
		if ( ! self::valid_name( $name ) || ! self::valid_ttl( $ttl ) ) {
			return false;
		}
		$guard = self::claim_guard( $name );
		if ( ! is_array( $guard ) ) {
			return false;
		}
		self::$guards[ $guard['key'] ] = $guard;
		$owned                         = false;
		$token                         = array(
			'token'   => wp_generate_uuid4(),
			'expires' => time() + $ttl,
		);
		try {
			$inserted = self::insert( $name, $token, $guard['table'] );
			if ( ! $inserted ) {
				$previous = self::raw_value( $name, $guard['table'] );
				if ( is_wp_error( $previous ) || null === $previous || ! self::expired( maybe_unserialize( $previous ), $name, $ttl ) || ! self::guard_owned( $guard ) || ! self::delete_exact( $name, $previous, $guard['table'] ) ) {
					return false;
				}
				$inserted = self::insert( $name, $token, $guard['table'] );
			}
			if ( ! $inserted ) {
				return false;
			}
			if ( ! self::guard_owned( $guard ) ) {
				self::delete_exact( $name, maybe_serialize( $token ), $guard['table'] );
				return false;
			}
			self::$guards[ $guard['key'] ]['token'] = $token['token'];
			self::$tokens[ $token['token'] ]        = $guard['key'];
			$owned                                  = true;
			return $token;
		} finally {
			if ( ! $owned ) {
				self::release_guard( $guard );
				unset( self::$guards[ $guard['key'] ] );
			}
		}
	}

	/**
	 * Check a token against fresh database state, without trusting option caches.
	 *
	 * @param string $name  Lock option name.
	 * @param array  $token Token returned by acquire().
	 * @return bool Whether this request still owns the guard and exact option token.
	 */
	public static function owns( $name, $token ) {
		if ( ! self::valid_name( $name ) || ! self::valid_token( $token ) || ! isset( self::$tokens[ $token['token'] ] ) ) {
			return false;
		}
		$key   = self::$tokens[ $token['token'] ];
		$guard = self::$guards[ $key ] ?? null;
		if ( ! is_array( $guard ) || $guard['lock_name'] !== $name || ! self::guard_owned( $guard ) ) {
			return false;
		}
		$current = self::raw_value( $name, $guard['table'] );
		return ! is_wp_error( $current ) && is_string( $current ) && hash_equals( $current, maybe_serialize( $token ) );
	}

	/**
	 * Tell the background runner whether a lock is still active.
	 *
	 * Legacy per-user workspace locks contain an integer acquisition timestamp.
	 *
	 * @param string $name Lock option name.
	 * @param int    $ttl  Lifetime for a legacy timestamp.
	 * @return bool|WP_Error Whether an active lock exists, or a read error that must defer work.
	 */
	public static function active( $name, $ttl ) {
		if ( ! self::valid_name( $name ) || ! self::valid_ttl( $ttl ) ) {
			return false;
		}
		$active = self::guard_active( $name );
		if ( is_wp_error( $active ) || $active ) {
			return $active;
		}
		$raw = self::raw_value( $name );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		if ( null === $raw ) {
			return false;
		}
		return ! self::expired( maybe_unserialize( $raw ), $name, $ttl );
	}

	/**
	 * Release only the exact token still stored for this lock.
	 *
	 * @param string $name  Lock option name.
	 * @param array  $token Token returned by acquire().
	 * @return bool Whether this token was deleted.
	 */
	public static function release( $name, $token ) {
		if ( ! self::valid_name( $name ) || ! self::valid_token( $token ) || ! isset( self::$tokens[ $token['token'] ] ) ) {
			return false;
		}
		$key   = self::$tokens[ $token['token'] ];
		$guard = self::$guards[ $key ] ?? null;
		if ( ! is_array( $guard ) || $guard['lock_name'] !== $name ) {
			return false;
		}
		try {
			return self::guard_owned( $guard ) && self::delete_exact( $name, maybe_serialize( $token ), $guard['table'] );
		} finally {
			self::release_guard( $guard );
			unset( self::$guards[ $key ], self::$tokens[ $token['token'] ] );
		}
	}

	/**
	 * Choose a guard supported by this WordPress database adapter.
	 *
	 * @return string MySQL, SQLite, or an empty string for an unknown adapter.
	 */
	private static function backend() {
		global $wpdb;

		if ( defined( 'DB_ENGINE' ) && 'sqlite' === strtolower( (string) DB_ENGINE ) ) {
			return 'sqlite';
		}
		if ( true === $wpdb->is_mysql ) {
			return 'mysql';
		}
		if ( defined( 'DB_PATH' ) || defined( 'FQDB' ) ) {
			return 'sqlite';
		}
		return '';
	}

	/**
	 * Acquire a guard that remains held even when an option lease expires.
	 *
	 * @param string $name Private lock option name.
	 * @return array|false Guard details, or false on contention or unsupported storage.
	 */
	private static function claim_guard( $name ) {
		global $wpdb;

		$backend = self::backend();
		if ( 'mysql' === $backend ) {
			$resource = self::mysql_guard_name( $name );
			if ( isset( self::$guards[ $resource ] ) ) {
				return false;
			}
			$row = self::mysql_row( 'acquire', $resource );
			if ( is_wp_error( $row ) || ! isset( $row['acquired'], $row['connection_id'] ) || '1' !== (string) $row['acquired'] ) {
				return false;
			}
			return array(
				'key'           => $resource,
				'backend'       => 'mysql',
				'resource'      => $resource,
				'connection_id' => (string) $row['connection_id'],
				'lock_name'     => $name,
				'table'         => $wpdb->options,
			);
		}
		if ( 'sqlite' !== $backend ) {
			return false;
		}
		$path = self::sqlite_guard_file( $name );
		if ( is_wp_error( $path ) || isset( self::$guards[ $path ] ) ) {
			return false;
		}
		$handle = self::open_sqlite_guard( $path );
		if ( ! is_resource( $handle ) ) {
			return false;
		}
		$would_block = 0;
		if ( ! flock( $handle, LOCK_EX | LOCK_NB, $would_block ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the advisory-lock handle releases its OS lock.
			fclose( $handle );
			return false;
		}
		return array(
			'key'       => $path,
			'backend'   => 'sqlite',
			'resource'  => $handle,
			'path'      => $path,
			'lock_name' => $name,
			'table'     => $wpdb->options,
		);
	}

	/**
	 * Observe a live guard from the runner without acquiring a write lock.
	 *
	 * @param string $name Private lock option name.
	 * @return bool|WP_Error True when a request still holds the guard.
	 */
	private static function guard_active( $name ) {
		$backend = self::backend();
		if ( 'mysql' === $backend ) {
			$row = self::mysql_row( 'active', self::mysql_guard_name( $name ) );
			if ( is_wp_error( $row ) || ! array_key_exists( 'holder', $row ) ) {
				return self::guard_error();
			}
			return null !== $row['holder'];
		}
		if ( 'sqlite' !== $backend ) {
			return self::guard_error();
		}
		$path = self::sqlite_guard_file( $name );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( isset( self::$guards[ $path ] ) && self::guard_owned( self::$guards[ $path ] ) ) {
			return true;
		}
		$handle = self::open_sqlite_guard( $path );
		if ( ! is_resource( $handle ) ) {
			return self::guard_error();
		}
		$would_block = 0;
		$acquired    = flock( $handle, LOCK_EX | LOCK_NB, $would_block );
		if ( $acquired ) {
			flock( $handle, LOCK_UN );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the advisory-lock handle releases its OS lock.
		fclose( $handle );
		if ( ! $acquired && ! $would_block ) {
			return self::guard_error();
		}
		return ! $acquired;
	}

	/**
	 * Check that this PHP request still owns its process-lifetime guard.
	 *
	 * @param array $guard Guard returned by claim_guard().
	 * @return bool Whether the guard is still held by this request.
	 */
	private static function guard_owned( $guard ) {
		if ( 'mysql' === $guard['backend'] ) {
			$row = self::mysql_row( 'owned', $guard['resource'] );
			return ! is_wp_error( $row ) && isset( $row['holder'], $row['connection_id'] ) && $guard['connection_id'] === (string) $row['holder'] && $guard['connection_id'] === (string) $row['connection_id'];
		}
		if ( 'sqlite' !== $guard['backend'] || ! is_resource( $guard['resource'] ) ) {
			return false;
		}
		clearstatcache( true, $guard['path'] );
		$path_stat   = @lstat( $guard['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A removed sidecar means ownership cannot be verified.
		$handle_stat = fstat( $guard['resource'] );
		return self::regular_file( $path_stat ) && self::regular_file( $handle_stat ) && $path_stat['dev'] === $handle_stat['dev'] && $path_stat['ino'] === $handle_stat['ino'];
	}

	/**
	 * Release only this request's guard; an expired option cannot unlock it.
	 *
	 * @param array $guard Guard returned by claim_guard().
	 * @return void
	 */
	private static function release_guard( $guard ) {
		if ( 'mysql' === $guard['backend'] ) {
			if ( self::guard_owned( $guard ) ) {
				self::mysql_row( 'release', $guard['resource'] );
			}
			return;
		}
		if ( 'sqlite' === $guard['backend'] && is_resource( $guard['resource'] ) ) {
			flock( $guard['resource'], LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem cannot hold process-lifetime advisory locks.
			fclose( $guard['resource'] );
		}
	}

	/**
	 * Namespace MySQL's server-wide advisory lock by database and blog table.
	 *
	 * @param string $name Private lock option name.
	 * @return string A name shorter than MySQL's 64-character limit.
	 */
	private static function mysql_guard_name( $name ) {
		global $wpdb;

		return 'bhranti:' . substr( hash( 'sha256', DB_NAME . "\n" . $wpdb->options . "\n" . $name ), 0, 48 );
	}

	/**
	 * Query one fixed MySQL session function without dynamic SQL fragments.
	 *
	 * @param string $operation One of acquire, active, owned, or release.
	 * @param string $name Prepared lock name.
	 * @return array|WP_Error Query result or a guard error.
	 */
	private static function mysql_row( $operation, $name ) {
		global $wpdb;

		switch ( $operation ) {
			case 'acquire':
				$sql = $wpdb->prepare( 'SELECT GET_LOCK(%s, 0) AS acquired, CONNECTION_ID() AS connection_id', $name );
				break;
			case 'active':
				$sql = $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) AS holder', $name );
				break;
			case 'owned':
				$sql = $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) AS holder, CONNECTION_ID() AS connection_id', $name );
				break;
			case 'release':
				$sql = $wpdb->prepare( 'SELECT RELEASE_LOCK(%s) AS released', $name );
				break;
			default:
				return self::guard_error();
		}
		$previous_suppression = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is selected only from the fixed, prepared statements above.
			$row      = $wpdb->get_row( $sql, ARRAY_A );
			$db_error = $wpdb->last_error;
		} finally {
			$wpdb->suppress_errors( $previous_suppression );
		}
		return '' === $db_error && is_array( $row ) ? $row : self::guard_error();
	}

	/**
	 * Locate the official SQLite integration's actual database directory.
	 *
	 * @param string $name Private lock option name.
	 * @return string|WP_Error Stable sidecar path shared by all workers using this DB.
	 */
	private static function sqlite_guard_file( $name ) {
		global $wpdb;

		$db_path = defined( 'DB_PATH' ) ? DB_PATH : ( defined( 'FQDB' ) ? FQDB : null );
		if ( ! is_string( $db_path ) || '' === $db_path || ':memory:' === $db_path ) {
			return self::guard_error();
		}
		$real_path = realpath( $db_path );
		if ( false === $real_path || ! is_file( $real_path ) ) {
			return self::guard_error();
		}
		return dirname( $real_path ) . '/.ht-bhranti-lock-' . substr( hash( 'sha256', basename( $real_path ) . "\n" . $wpdb->options . "\n" . $name ), 0, 40 );
	}

	/**
	 * Open an empty private sidecar without following a symlink or truncating it.
	 *
	 * The sidecar is intentionally never unlinked: another process may already be
	 * waiting on its inode. It contains no content or credentials. The lifetime
	 * file count is bounded by the distinct site-wide and per-user lock names
	 * used on this site, rather than by the number of operations.
	 *
	 * @param string $path Canonical sidecar path.
	 * @return resource|false Safe file handle or false.
	 */
	private static function open_sqlite_guard( $path ) {
		clearstatcache( true, $path );
		$before = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing sidecar is expected before its first use.
		if ( false !== $before && ! self::regular_file( $before ) ) {
			return false;
		}
		$created = false;
		if ( false === $before ) {
			$previous_umask = umask( 0077 );
			try {
				$handle = @fopen( $path, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive creation identifies the empty inode this request may secure.
			} finally {
				umask( $previous_umask );
			}
			$created = is_resource( $handle );
			if ( ! $created ) {
				clearstatcache( true, $path );
				$before = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed exclusive create may mean another worker created the sidecar.
			}
		}
		if ( ! $created ) {
			if ( ! self::regular_file( $before ) || 0 !== ( $before['mode'] & 0077 ) ) {
				return false;
			}
			$handle = @fopen( $path, 'r+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Open only the existing inode; a removed sidecar must never be recreated here.
		}
		if ( false === $handle ) {
			return false;
		}
		if ( $created && ! self::secure_created_sqlite_guard( $path, $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A sidecar that could not be secured must not become an advisory lock.
			fclose( $handle );
			return false;
		}
		clearstatcache( true, $path );
		$after       = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A removed sidecar must fail closed.
		$handle_stat = fstat( $handle );
		if ( ! self::regular_file( $after ) || ! self::regular_file( $handle_stat ) || $after['dev'] !== $handle_stat['dev'] || $after['ino'] !== $handle_stat['ino'] || 0 !== ( $after['mode'] & 0077 ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the invalid advisory-lock handle is required here.
			fclose( $handle );
			return false;
		}
		return $handle;
	}

	/**
	 * Secure only the empty sidecar inode exclusively created by this request.
	 *
	 * Filesystems may ignore umask. Existing or replaced sidecars must pass the
	 * normal strict checks and are never repaired or unlinked here.
	 *
	 * @param string   $path New sidecar path.
	 * @param resource $handle Exclusively created sidecar handle.
	 * @return bool Whether the same private inode is safe to lock.
	 */
	private static function secure_created_sqlite_guard( $path, $handle ) {
		clearstatcache( true, $path );
		$path_stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing or replaced sidecars must fail closed.
		$open_stat = fstat( $handle );
		foreach ( array( $path_stat, $open_stat ) as $stat ) {
			if ( ! self::regular_file( $stat ) || 1 !== $stat['nlink'] || 0 !== $stat['size'] ) {
				return false;
			}
		}
		if ( $path_stat['dev'] !== $open_stat['dev'] || $path_stat['ino'] !== $open_stat['ino'] ) {
			return false;
		}
		if ( ( 0600 !== ( $path_stat['mode'] & 0777 ) || 0600 !== ( $open_stat['mode'] & 0777 ) ) && ! @chmod( $path, 0600 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Only the verified new empty inode may receive private permissions.
			return false;
		}
		clearstatcache( true, $path );
		$secured_path = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Verify the secured inode again before granting a handle.
		$secured_open = fstat( $handle );
		foreach ( array( $secured_path, $secured_open ) as $stat ) {
			if ( ! self::regular_file( $stat ) || 1 !== $stat['nlink'] || 0 !== $stat['size'] || 0600 !== ( $stat['mode'] & 0777 ) || $path_stat['dev'] !== $stat['dev'] || $path_stat['ino'] !== $stat['ino'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Reject symlinks, directories, devices and missing paths.
	 *
	 * @param array|false $stat Result of lstat() or fstat().
	 * @return bool Whether the handle refers to a regular file.
	 */
	private static function regular_file( $stat ) {
		return is_array( $stat ) && isset( $stat['mode'] ) && 0100000 === ( $stat['mode'] & 0170000 );
	}

	/**
	 * Report an unverified guard as an error, never as an inactive operation.
	 *
	 * @return WP_Error Safe error for the background runner to defer on.
	 */
	private static function guard_error() {
		return new WP_Error( 'bhranti_lock_guard', __( 'Bhranti could not verify an operation guard.', 'bhranti' ) );
	}

	/**
	 * Restrict direct option-table writes to the owned lock families.
	 *
	 * @param string $name Option name to inspect.
	 * @return bool Whether Bhranti owns this lock option family.
	 */
	private static function valid_name( $name ) {
		return is_string( $name ) && ( in_array(
			$name,
			array(
				'bhranti_memory_lock',
				'bhranti_memory_network_lock',
				'bhranti_plugins_lock',
				'bhranti_forms_lock',
				'bhranti_commerce_lock',
				'bhranti_settings_lock',
			),
			true
		) || (bool) preg_match( '/\Abhranti_lock_[1-9][0-9]*\z/D', $name ) );
	}

	/**
	 * Keep internal leases finite, even if a future caller passes unchecked data.
	 *
	 * @param int $ttl Requested lease lifetime.
	 * @return bool Whether the lifetime is within the supported range.
	 */
	private static function valid_ttl( $ttl ) {
		return is_int( $ttl ) && $ttl > 0 && $ttl <= DAY_IN_SECONDS;
	}

	/**
	 * Require our canonical token shape before ownership checks or release.
	 *
	 * @param mixed $token Proposed lock token.
	 * @return bool Whether the token has Bhranti's canonical shape.
	 */
	private static function valid_token( $token ) {
		return is_array( $token ) && array( 'token', 'expires' ) === array_keys( $token ) && is_string( $token['token'] ) && (bool) preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D', $token['token'] ) && is_int( $token['expires'] );
	}

	/**
	 * Read the persisted value directly; a stale notoptions cache cannot hide a lock.
	 *
	 * @param string $name  Lock option name.
	 * @param string $table Exact site options table captured when the guard was claimed.
	 * @return string|null|WP_Error Stored value, absent row, or a database error.
	 */
	private static function raw_value( $name, $table = '' ) {
		global $wpdb;

		$table = '' === $table ? $wpdb->options : $table;

		$previous_suppression = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock correctness requires a fresh database read.
			$row      = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table, $name ), ARRAY_A );
			$db_error = $wpdb->last_error;
		} finally {
			$wpdb->suppress_errors( $previous_suppression );
		}
		if ( '' !== $db_error || ( null !== $row && ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) ) ) {
			return new WP_Error( 'bhranti_lock_database', __( 'Bhranti could not verify an operation lock.', 'bhranti' ) );
		}
		return null === $row ? null : $row['option_value'];
	}

	/**
	 * An unknown value is occupied, not permission to remove it.
	 *
	 * @param mixed  $value Stored option value.
	 * @param string $name  Lock option name.
	 * @param int    $ttl   Legacy timestamp lifetime.
	 * @return bool Whether the exact stored value is definitely expired.
	 */
	private static function expired( $value, $name, $ttl ) {
		if ( is_array( $value ) && isset( $value['expires'] ) && is_numeric( $value['expires'] ) ) {
			return (int) $value['expires'] <= time();
		}
		if ( 0 === strpos( $name, 'bhranti_lock_' ) && ( is_int( $value ) || ( is_string( $value ) && preg_match( '/\A[0-9]{1,12}\z/D', $value ) ) ) ) {
			return (int) $value < time() - $ttl;
		}
		return false;
	}

	/**
	 * Plain INSERT must fail on a competing owner, never update that owner's row.
	 *
	 * @param string $name  Lock option name.
	 * @param array  $token Unique owner token.
	 * @param string $table Exact site options table captured at guard acquisition.
	 * @return bool Whether the row was inserted.
	 */
	private static function insert( $name, $token, $table ) {
		global $wpdb;

		$value    = maybe_serialize( $token );
		$autoload = wp_determine_option_autoload_value( $name, $token, $value, false );
		// A duplicate key is ordinary contention, not a database error to display.
		$previous_suppression = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- add_option() uses an upsert in current WordPress and is unsafe for a mutex.
			$inserted = $wpdb->insert(
				$table,
				array(
					'option_name'  => $name,
					'option_value' => $value,
					'autoload'     => $autoload,
				),
				array( '%s', '%s', '%s' )
			);
		} finally {
			$wpdb->suppress_errors( $previous_suppression );
		}
		if ( 1 !== $inserted ) {
			return false;
		}
		self::clear_cache( $name );
		return true;
	}

	/**
	 * Delete only the value observed earlier, so a successor cannot be released.
	 *
	 * @param string $name  Lock option name.
	 * @param string $value Exact stored value to compare before deletion.
	 * @param string $table Exact site options table captured at guard acquisition.
	 * @return bool Whether the row was deleted.
	 */
	private static function delete_exact( $name, $value, $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- delete_option() ignores the owner; clear_cache() invalidates all options cache locations after this exact-value delete.
		$deleted = $wpdb->delete(
			$table,
			array(
				'option_name'  => $name,
				'option_value' => $value,
			),
			array( '%s', '%s' )
		);
		if ( 1 !== $deleted ) {
			return false;
		}
		self::clear_cache( $name );
		return true;
	}

	/**
	 * Remove every WordPress option-cache location after a committed row change.
	 *
	 * @param string $name Lock option name.
	 * @return void
	 */
	private static function clear_cache( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
