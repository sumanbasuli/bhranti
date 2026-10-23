<?php
/**
 * Authenticated human execution and bounded content-authoring permissions.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keep native user permissions without granting the agent unfiltered authoring.
 */
final class Bhranti_Identity {

	/**
	 * Nested tool scopes share one temporary native authoring guard.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Validate the actual signed-in human, including owner-bound Cron contexts.
	 *
	 * Cron establishes its independently verified requester before invoking this
	 * method. A tool can never select or impersonate a different account here.
	 *
	 * @param int $requester_id Expected owner; zero means the current user.
	 * @return int|WP_Error Human user ID or a permission error.
	 */
	public static function requester( $requester_id = 0 ) {
		$current = get_current_user_id();
		$user    = $current ? wp_get_current_user() : false;
		if ( $user ) {
			// Refresh this site's saved grants without changing the current identity.
			clean_user_cache( $current );
			$user->for_site( get_current_blog_id() );
		}
		if ( ! $current || ( $requester_id && $current !== (int) $requester_id ) || ! $user || ! user_can( $current, 'manage_options' ) || get_user_meta( $current, 'bhranti_managed_actor', true ) || in_array( 'bhranti_agent', (array) $user->roles, true ) ) {
			return new WP_Error( 'bhranti_requester_permission', __( 'This action requires its signed-in administrator and current WordPress permissions.', 'bhranti' ), array( 'status' => 403 ) );
		}
		return $current;
	}

	/**
	 * Apply native KSES even when the submitting administrator can bypass it.
	 *
	 * The authenticated account remains unchanged. The map_meta_cap denial also
	 * applies to multisite super administrators. Reinitializing core KSES filters
	 * handles WordPress's same-user shortcut and restores normal permissions in
	 * finally, including when a callback throws. No user roles or caps are saved.
	 *
	 * @param callable $callback Reviewed operation.
	 * @return mixed Operation result.
	 */
	public static function scope( $callback ) {
		$outer = 0 === self::$depth;
		++self::$depth;
		try {
			if ( $outer ) {
				add_filter( 'map_meta_cap', array( __CLASS__, 'authoring_caps' ), PHP_INT_MAX, 2 );
				kses_init();
			}
			return $callback();
		} finally {
			--self::$depth;
			if ( $outer ) {
				remove_filter( 'map_meta_cap', array( __CLASS__, 'authoring_caps' ), PHP_INT_MAX );
				kses_init();
			}
		}
	}

	/**
	 * Deny native unfiltered content and upload bypasses during reviewed tools.
	 *
	 * @param string[] $caps Native primitive capability requirements.
	 * @param string   $cap Requested capability.
	 * @return string[] Native requirements, with explicit bypass denial.
	 */
	public static function authoring_caps( $caps, $cap ) {
		return in_array( $cap, array( 'unfiltered_html', 'unfiltered_upload' ), true ) ? array( 'do_not_allow' ) : $caps;
	}
}
