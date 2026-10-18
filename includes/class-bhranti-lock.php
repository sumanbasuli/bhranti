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
	 * Acquire an exact lock name, replacing only an expired value.
	 *
	 * @param string $name One of Bhranti's private lock option names.
	 * @param int    $ttl  Lifetime in seconds.
	 * @return array|false Unique token, or false when the lock is occupied or unavailable.
	 */
	public static function acquire( $name, $ttl ) {
		if ( ! self::valid_name( $name ) || ! self::valid_ttl( $ttl ) ) {
			return false;
		}
		$token = array(
			'token'   => wp_generate_uuid4(),
			'expires' => time() + $ttl,
		);
		if ( self::insert( $name, $token ) ) {
			return $token;
		}

		$previous = self::raw_value( $name );
		if ( is_wp_error( $previous ) || null === $previous || ! self::expired( maybe_unserialize( $previous ), $name, $ttl ) || ! self::delete_exact( $name, $previous ) ) {
			return false;
		}
		return self::insert( $name, $token ) ? $token : false;
	}

	/**
	 * Check a token against fresh database state, without trusting option caches.
	 *
	 * @param string $name  Lock option name.
	 * @param array  $token Token returned by acquire().
	 * @return bool Whether this unexpired token still owns the lock.
	 */
	public static function owns( $name, $token ) {
		if ( ! self::valid_name( $name ) || ! self::valid_token( $token ) || $token['expires'] <= time() ) {
			return false;
		}
		$current = self::raw_value( $name );
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
		return self::valid_name( $name ) && self::valid_token( $token ) && self::delete_exact( $name, maybe_serialize( $token ) );
	}

	/** Restrict direct option-table writes to the six owned lock families. */
	private static function valid_name( $name ) {
		return is_string( $name ) && ( in_array( $name, array( 'bhranti_actor_setup_lock', 'bhranti_plugins_lock', 'bhranti_forms_lock', 'bhranti_commerce_lock', 'bhranti_settings_lock' ), true ) || (bool) preg_match( '/\Abhranti_lock_[1-9][0-9]*\z/D', $name ) );
	}

	/** Keep internal leases finite, even if a future caller passes unchecked data. */
	private static function valid_ttl( $ttl ) {
		return is_int( $ttl ) && $ttl > 0 && $ttl <= DAY_IN_SECONDS;
	}

	/** Require our canonical token shape before ownership checks or release. */
	private static function valid_token( $token ) {
		return is_array( $token ) && array( 'token', 'expires' ) === array_keys( $token ) && is_string( $token['token'] ) && (bool) preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D', $token['token'] ) && is_int( $token['expires'] );
	}

	/** Read the persisted value directly; a stale notoptions cache cannot hide a lock. */
	private static function raw_value( $name ) {
		global $wpdb;

		$previous_suppression = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock correctness requires a fresh database read.
			$row      = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
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

	/** Plain INSERT must fail on a competing owner, never update that owner's row. */
	private static function insert( $name, $token ) {
		global $wpdb;

		$value    = maybe_serialize( $token );
		$autoload = wp_determine_option_autoload_value( $name, $token, $value, false );
		// A duplicate key is ordinary contention, not a database error to display.
		$previous_suppression = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- add_option() uses an upsert in current WordPress and is unsafe for a mutex.
			$inserted = $wpdb->insert(
				$wpdb->options,
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

	/** Delete only the value observed earlier, so a successor cannot be released. */
	private static function delete_exact( $name, $value ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- delete_option() deletes by name without checking the owner.
		$deleted = $wpdb->delete(
			$wpdb->options,
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

	/** Remove every WordPress option-cache location after a committed row change. */
	private static function clear_cache( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
