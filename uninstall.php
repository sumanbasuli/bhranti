<?php
/**
 * Remove Bhranti's own stored data when WordPress uninstalls the plugin.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-bhranti-lock.php';
require_once __DIR__ . '/includes/class-bhranti-memory.php';

/**
 * Delete workspace records and site-specific user options on one site.
 */
$bhranti_remove_site_data = static function () {
	global $wpdb;

	$bhranti_memory_removed = Bhranti_Memory::uninstall_site();
	if ( is_wp_error( $bhranti_memory_removed ) ) {
		return $bhranti_memory_removed;
	}
	foreach ( array( 'bhranti_run_tick', 'bhranti_memory_erase_user' ) as $bhranti_hook ) {
		$bhranti_unscheduled = wp_unschedule_hook( $bhranti_hook, true );
		if ( false === $bhranti_unscheduled || is_wp_error( $bhranti_unscheduled ) ) {
			return new WP_Error( 'bhranti_uninstall_cron', __( 'Bhranti could not remove its scheduled tasks.', 'bhranti' ) );
		}
	}
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
	// A deletion veto or database failure must not leave private chats and logs behind.
	$bhranti_remaining = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must verify persisted rows, independent of caches.
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the posts table name; both values are prepared.
			'bhranti_session',
			'bhranti_event'
		)
	);
	if ( null === $bhranti_remaining || 0 !== (int) $bhranti_remaining ) {
		return new WP_Error( 'bhranti_uninstall_private_data', __( 'Bhranti could not erase all private conversations and activity.', 'bhranti' ) );
	}
	// Preserve the generated attachments while removing the plugin's requester markers.
	delete_post_meta_by_key( 'bhranti_generated_media' );
	// Keep native contact forms and submissions, removing only Bhranti's ownership marker.
	delete_post_meta_by_key( '_bhranti_form_definition' );
	$bhranti_postmeta_remaining = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must verify persisted rows, independent of caches.
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the postmeta table name; both keys are prepared.
			'bhranti_generated_media',
			'_bhranti_form_definition'
		)
	);
	if ( null === $bhranti_postmeta_remaining || 0 !== (int) $bhranti_postmeta_remaining ) {
		return new WP_Error( 'bhranti_uninstall_postmeta', __( 'Bhranti could not erase its content markers.', 'bhranti' ) );
	}

	// Erase this site's exact plugin keys, including former site members.
	foreach ( array(
		$wpdb->get_blog_prefix() . 'bhranti_activity',
		$wpdb->get_blog_prefix() . 'bhranti_ai_enabled',
		'bhranti_editor_state_' . get_current_blog_id(),
	) as $bhranti_user_key ) {
		delete_metadata( 'user', 0, $bhranti_user_key, '', true );
	}
	$bhranti_usermeta_remaining = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must verify persisted rows, independent of caches.
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the usermeta table name; each key is prepared.
			$wpdb->get_blog_prefix() . 'bhranti_activity',
			$wpdb->get_blog_prefix() . 'bhranti_ai_enabled',
			'bhranti_editor_state_' . get_current_blog_id()
		)
	);
	if ( null === $bhranti_usermeta_remaining || 0 !== (int) $bhranti_usermeta_remaining ) {
		return new WP_Error( 'bhranti_uninstall_usermeta', __( 'Bhranti could not erase its user activity state.', 'bhranti' ) );
	}
	$bhranti_option_pattern    = $wpdb->esc_like( 'bhranti_' ) . '%';
	$bhranti_options_remaining = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must verify persisted rows, independent of caches.
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT IN (%s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the options table name; the prefix is prepared.
			$bhranti_option_pattern,
			'bhranti_memory_state',
			'bhranti_memory_network_state',
			'bhranti_memory_network_lock'
		)
	);
	if ( null === $bhranti_options_remaining ) {
		return new WP_Error( 'bhranti_uninstall_options', __( 'Bhranti could not erase its private settings.', 'bhranti' ) );
	}
	while ( 0 < (int) $bhranti_options_remaining ) {
		// Also remove legacy options and locks left by accounts deleted before uninstall.
		$bhranti_option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the plugin-owned namespace before removing it through WordPress APIs.
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT IN (%s, %s, %s) ORDER BY option_name ASC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the options table name; the prefix is prepared.
				$bhranti_option_pattern,
				'bhranti_memory_state',
				'bhranti_memory_network_state',
				'bhranti_memory_network_lock'
			)
		);
		if ( $wpdb->last_error || ! $bhranti_option_names ) {
			return new WP_Error( 'bhranti_uninstall_options', __( 'Bhranti could not erase its private settings.', 'bhranti' ) );
		}
		foreach ( $bhranti_option_names as $bhranti_option_name ) {
			delete_option( $bhranti_option_name );
		}
		$bhranti_new_option_count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm that persisted rows decreased after deletion.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT IN (%s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the options table name; the prefix is prepared.
				$bhranti_option_pattern,
				'bhranti_memory_state',
				'bhranti_memory_network_state',
				'bhranti_memory_network_lock'
			)
		);
		if ( null === $bhranti_new_option_count || (int) $bhranti_new_option_count >= (int) $bhranti_options_remaining ) {
			return new WP_Error( 'bhranti_uninstall_options', __( 'Bhranti could not erase its private settings.', 'bhranti' ) );
		}
		$bhranti_options_remaining = $bhranti_new_option_count;
	}
	return true;
};

if ( is_multisite() ) {
	$bhranti_network_removed = Bhranti_Memory::uninstall_network(
		static function () use ( $bhranti_remove_site_data ) {
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
						$bhranti_site_removed = $bhranti_remove_site_data();
					} finally {
						restore_current_blog();
					}
					if ( is_wp_error( $bhranti_site_removed ) ) {
						return $bhranti_site_removed;
					}
				}
				$bhranti_offset    += 100;
				$bhranti_site_count = count( $bhranti_site_ids );
			} while ( 100 === $bhranti_site_count );
			return true;
		}
	);
	if ( is_wp_error( $bhranti_network_removed ) ) {
		wp_die(
			esc_html__( 'Bhranti could not finish removing its private data. Check site storage and database permissions, then try deleting the plugin again.', 'bhranti' ),
			esc_html__( 'Bhranti uninstall stopped', 'bhranti' ),
			array( 'response' => 500 )
		);
		exit;
	}
} else {
	$bhranti_site_removed = $bhranti_remove_site_data();
	if ( is_wp_error( $bhranti_site_removed ) ) {
		wp_die(
			esc_html__( 'Bhranti could not finish removing its private data. Check site storage and database permissions, then try deleting the plugin again.', 'bhranti' ),
			esc_html__( 'Bhranti uninstall stopped', 'bhranti' ),
			array( 'response' => 500 )
		);
		exit;
	}
}

unset( $bhranti_remove_site_data, $bhranti_network_removed, $bhranti_site_removed );
