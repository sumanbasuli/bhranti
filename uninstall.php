<?php
/**
 * Remove Bhranti's own stored data when WordPress uninstalls the plugin.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete workspace records and site-specific user options on one site.
 */
$bhranti_remove_site_data = static function () {
	wp_unschedule_hook( 'bhranti_run_tick', true );
	$bhranti_retained_count = 0;
	do {
		$bhranti_sessions = get_posts(
			array(
				'post_type'      => array( 'bhranti_session', 'bhranti_event' ),
				'post_status'    => array_keys( get_post_stati() ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'offset'         => $bhranti_retained_count,
			)
		);
		foreach ( $bhranti_sessions as $bhranti_session_id ) {
			if ( ! wp_delete_post( $bhranti_session_id, true ) || get_post( $bhranti_session_id ) ) {
				// With ascending IDs, retained records precede the next unprocessed batch.
				++$bhranti_retained_count;
			}
		}
		$bhranti_session_count = count( $bhranti_sessions );
	} while ( 100 === $bhranti_session_count );
	// Preserve the generated attachments while removing the plugin's requester markers.
	delete_post_meta_by_key( 'bhranti_generated_media' );

	$bhranti_actor_id = (int) get_option( 'bhranti_actor_id' );
	$bhranti_actor    = get_userdata( $bhranti_actor_id );
	if ( $bhranti_actor && get_current_blog_id() === (int) get_user_meta( $bhranti_actor_id, 'bhranti_managed_actor', true ) ) {
		// Keep authored content and identity; never leave an elevated unattended account after uninstall.
		$bhranti_actor->set_role( 'subscriber' );
		wp_set_password( wp_generate_password( 64, true, true ), $bhranti_actor_id );
		if ( class_exists( 'WP_Application_Passwords' ) ) {
			WP_Application_Passwords::delete_all_application_passwords( $bhranti_actor_id );
		}
	}
	delete_option( 'bhranti_actor_id' );
	delete_option( 'bhranti_actor_setup_lock' );
	delete_option( 'bhranti_settings' );
	delete_option( 'bhranti_settings_lock' );
	delete_option( 'bhranti_plugins_lock' );
	delete_option( 'bhranti_commerce_lock' );
	if ( get_option( 'bhranti_role_owned' ) ) {
		remove_role( 'bhranti_agent' );
		delete_option( 'bhranti_role_owned' );
	}

	$bhranti_page = 1;
	do {
		// Include nonmembers: a former site administrator may still have local data.
		$bhranti_user_ids = get_users(
			array(
				'blog_id' => 0,
				'fields'  => 'ID',
				'number'  => 100,
				'paged'   => $bhranti_page,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		foreach ( $bhranti_user_ids as $bhranti_user_id ) {
			delete_user_option( $bhranti_user_id, 'bhranti_activity', false );
			delete_user_meta( $bhranti_user_id, 'bhranti_editor_state_' . get_current_blog_id() );
			// Remove the initial prototype's retired preference too.
			delete_user_option( $bhranti_user_id, 'bhranti_ai_enabled', false );
			delete_option( 'bhranti_lock_' . $bhranti_user_id );
		}
		++$bhranti_page;
		$bhranti_user_count = count( $bhranti_user_ids );
	} while ( 100 === $bhranti_user_count );
};

if ( is_multisite() ) {
	$bhranti_offset = 0;
	do {
		$bhranti_site_ids = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => 100,
				'offset'  => $bhranti_offset,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
		foreach ( $bhranti_site_ids as $bhranti_site_id ) {
			switch_to_blog( $bhranti_site_id );
			try {
				$bhranti_remove_site_data();
			} finally {
				restore_current_blog();
			}
		}
		$bhranti_offset    += 100;
		$bhranti_site_count = count( $bhranti_site_ids );
	} while ( 100 === $bhranti_site_count );
} else {
	$bhranti_remove_site_data();
}

unset( $bhranti_remove_site_data, $bhranti_site_ids, $bhranti_site_id, $bhranti_offset, $bhranti_site_count );
