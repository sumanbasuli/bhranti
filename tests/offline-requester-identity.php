<?php
/**
 * Identity boundary for database-free orchestration and REST-schema fixtures.
 *
 * Account/capability lookup is intentionally in memory in those fixtures. The
 * real identity refresh, native KSES, scope cleanup and superadministrator
 * behavior are covered separately by requester-identity.php and
 * requester-superadmin.php. This seam grants no alternate execution identity.
 *
 * @package Bhranti
 */
final class Bhranti_Identity {
	public static function requester( $requester_id = 0 ) {
		$current = get_current_user_id();
		if ( ! $current || ( $requester_id && (int) $requester_id !== $current ) || ( function_exists( 'user_can' ) && ! user_can( $current, 'manage_options' ) ) || ( function_exists( 'get_user_meta' ) && get_user_meta( $current, 'bhranti_managed_actor', true ) ) ) {
			return new WP_Error( 'bhranti_requester_permission', 'The fixture requester has no native authority.', array( 'status' => 403 ) );
		}
		return $current;
	}
	public static function scope( $callback ) {
		return $callback();
	}
}
