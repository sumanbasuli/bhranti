<?php
/**
 * Remove Bhranti's own stored data when WordPress uninstalls the plugin.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

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
	$bhranti_unscheduled = wp_unschedule_hook( 'bhranti_run_tick', true );
	if ( false === $bhranti_unscheduled || is_wp_error( $bhranti_unscheduled ) ) {
		return new WP_Error( 'bhranti_uninstall_cron', __( 'Bhranti could not remove its scheduled tasks.', 'bhranti' ) );
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

	delete_option( 'bhranti_actor_id' );
	delete_option( 'bhranti_actor_setup_lock' );
	delete_option( 'bhranti_settings' );
	delete_option( 'bhranti_settings_lock' );
	delete_option( 'bhranti_plugins_lock' );
	delete_option( 'bhranti_commerce_lock' );
	delete_option( 'bhranti_forms_lock' );
	$bhranti_role_marker = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Role ownership must come from persisted state before any role changes.
		$wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the options table name; the option name is prepared.
			'bhranti_role_owned'
		)
	);
	if ( $wpdb->last_error ) {
		return new WP_Error( 'bhranti_uninstall_role', __( 'Bhranti could not verify its managed role.', 'bhranti' ) );
	}
	$bhranti_owned_role = null !== $bhranti_role_marker && (bool) maybe_unserialize( $bhranti_role_marker );
	if ( $bhranti_owned_role ) {
		$bhranti_default_role = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the persisted registration default, not a potentially stale option cache.
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the options table name; the option name is prepared.
				'default_role'
			)
		);
		if ( $wpdb->last_error ) {
			return new WP_Error( 'bhranti_uninstall_role', __( 'Bhranti could not remove its managed role.', 'bhranti' ) );
		}
		if ( 'bhranti_agent' === $bhranti_default_role ) {
			if ( ! get_role( 'subscriber' ) ) {
				return new WP_Error( 'bhranti_uninstall_role', __( 'Bhranti could not remove its managed role.', 'bhranti' ) );
			}
			wp_cache_delete( 'default_role', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			update_option( 'default_role', 'subscriber' );
			$bhranti_default_role = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify the new registration default persisted before removing the owned role.
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the options table name; the option name is prepared.
					'default_role'
				)
			);
			if ( 'subscriber' !== $bhranti_default_role ) {
				return new WP_Error( 'bhranti_uninstall_role', __( 'Bhranti could not remove its managed role.', 'bhranti' ) );
			}
		}
		remove_role( 'bhranti_agent' );
		$bhranti_stored_roles = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WP_Roles updates its in-memory role before the database; verify persisted removal.
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the options table name; the option name is prepared.
				$wpdb->get_blog_prefix() . 'user_roles'
			)
		);
		$bhranti_stored_roles = maybe_unserialize( $bhranti_stored_roles );
		if ( ! is_array( $bhranti_stored_roles ) || isset( $bhranti_stored_roles['bhranti_agent'] ) ) {
			return new WP_Error( 'bhranti_uninstall_role', __( 'Bhranti could not remove its managed role.', 'bhranti' ) );
		}
		$bhranti_default_role = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The removed role must not remain the persisted registration default.
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the options table name; the option name is prepared.
				'default_role'
			)
		);
		if ( $wpdb->last_error || 'bhranti_agent' === $bhranti_default_role ) {
			return new WP_Error( 'bhranti_uninstall_role', __( 'Bhranti could not remove its managed role.', 'bhranti' ) );
		}
	}
	$bhranti_processed_managed = array();
	$bhranti_page              = 1;
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
			$bhranti_user          = get_userdata( $bhranti_user_id );
			$bhranti_managed       = $bhranti_user && get_current_blog_id() === (int) get_user_meta( $bhranti_user_id, 'bhranti_managed_actor', true );
			$bhranti_role_assigned = $bhranti_owned_role && $bhranti_user && array_key_exists( 'bhranti_agent', (array) $bhranti_user->caps );
			if ( $bhranti_managed ) {
				if ( ! get_role( 'subscriber' ) ) {
					return new WP_Error( 'bhranti_uninstall_actor', __( 'Bhranti could not secure its managed account.', 'bhranti' ) );
				}
				// Keep authored content, but secure a managed account even if its actor-ID option was lost.
				$bhranti_user->set_role( 'subscriber' );
				foreach ( array_keys( (array) $bhranti_user->caps ) as $bhranti_capability ) {
					if ( 'subscriber' !== $bhranti_capability ) {
						$bhranti_user->remove_cap( $bhranti_capability );
					}
				}
				$bhranti_new_password = wp_generate_password( 64, true, true );
				wp_set_password( $bhranti_new_password, $bhranti_user_id );
				if ( class_exists( 'WP_Application_Passwords' ) ) {
					$bhranti_app_passwords_removed = WP_Application_Passwords::delete_all_application_passwords( $bhranti_user_id );
					if ( is_wp_error( $bhranti_app_passwords_removed ) ) {
						return new WP_Error( 'bhranti_uninstall_actor', __( 'Bhranti could not secure its managed account.', 'bhranti' ) );
					}
				}
			} elseif ( $bhranti_role_assigned ) {
				// A former role holder must not regain its old permissions after reinstallation.
				$bhranti_user->remove_cap( 'bhranti_agent' );
				if ( ! $bhranti_user->roles ) {
					if ( ! get_role( 'subscriber' ) ) {
						return new WP_Error( 'bhranti_uninstall_actor', __( 'Bhranti could not secure its managed account.', 'bhranti' ) );
					}
					$bhranti_user->add_role( 'subscriber' );
				}
			}
			if ( $bhranti_managed || $bhranti_role_assigned ) {
				$bhranti_capability_rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify site-scoped capabilities in storage after demotion.
					$wpdb->prepare(
						"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the usermeta table name; values are prepared.
						$bhranti_user_id,
						$wpdb->get_blog_prefix() . 'capabilities'
					)
				);
				$bhranti_saved_caps      = 1 === count( $bhranti_capability_rows ) ? maybe_unserialize( $bhranti_capability_rows[0] ) : null;
				if ( $wpdb->last_error || ! is_array( $bhranti_saved_caps ) || ( $bhranti_role_assigned && array_key_exists( 'bhranti_agent', $bhranti_saved_caps ) ) || ( $bhranti_managed && array( 'subscriber' => true ) !== $bhranti_saved_caps ) ) {
					return new WP_Error( 'bhranti_uninstall_actor', __( 'Bhranti could not secure its managed account.', 'bhranti' ) );
				}
				if ( $bhranti_managed ) {
					$bhranti_stored_password = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm the rotated credential persisted.
						$wpdb->prepare(
							"SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the users table name; ID is prepared.
							$bhranti_user_id
						)
					);
					clean_user_cache( $bhranti_user_id );
					if ( null === $bhranti_stored_password || ! wp_check_password( $bhranti_new_password, $bhranti_stored_password, $bhranti_user_id ) || user_can( $bhranti_user_id, 'manage_options' ) || ( class_exists( 'WP_Application_Passwords' ) && WP_Application_Passwords::get_user_application_passwords( $bhranti_user_id ) ) ) {
						return new WP_Error( 'bhranti_uninstall_actor', __( 'Bhranti could not secure its managed account.', 'bhranti' ) );
					}
					if ( class_exists( 'WP_Application_Passwords' ) ) {
						$bhranti_stored_app_passwords = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm no credential remains in persistent user meta.
							$wpdb->prepare(
								"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the usermeta table name; values are prepared.
								$bhranti_user_id,
								WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS
							)
						);
						if ( $wpdb->last_error ) {
							return new WP_Error( 'bhranti_uninstall_actor', __( 'Bhranti could not secure its managed account.', 'bhranti' ) );
						}
						foreach ( $bhranti_stored_app_passwords as $bhranti_app_passwords ) {
							if ( array() !== maybe_unserialize( $bhranti_app_passwords ) ) {
								return new WP_Error( 'bhranti_uninstall_actor', __( 'Bhranti could not secure its managed account.', 'bhranti' ) );
							}
						}
					}
					$bhranti_processed_managed[ (int) $bhranti_user_id ] = true;
				}
			}
			delete_user_option( $bhranti_user_id, 'bhranti_activity', false );
			delete_user_meta( $bhranti_user_id, 'bhranti_editor_state_' . get_current_blog_id() );
			// Remove the initial prototype's retired preference too.
			delete_user_option( $bhranti_user_id, 'bhranti_ai_enabled', false );
			delete_option( 'bhranti_lock_' . $bhranti_user_id );
		}
		if ( $wpdb->last_error ) {
			return new WP_Error( 'bhranti_uninstall_actor', __( 'Bhranti could not verify its managed accounts.', 'bhranti' ) );
		}
		++$bhranti_page;
		$bhranti_user_count = count( $bhranti_user_ids );
	} while ( 100 === $bhranti_user_count );
	$bhranti_marked_users = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify that every site-owned actor was visited and secured.
		$wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the usermeta table name; marker values are prepared.
			'bhranti_managed_actor',
			(string) get_current_blog_id()
		)
	);
	if ( $wpdb->last_error ) {
		return new WP_Error( 'bhranti_uninstall_actor', __( 'Bhranti could not verify its managed accounts.', 'bhranti' ) );
	}
	foreach ( $bhranti_marked_users as $bhranti_marked_user_id ) {
		if ( ! isset( $bhranti_processed_managed[ (int) $bhranti_marked_user_id ] ) ) {
			return new WP_Error( 'bhranti_uninstall_actor', __( 'Bhranti could not secure every managed account.', 'bhranti' ) );
		}
	}
	if ( $bhranti_owned_role ) {
		$bhranti_role_assignments = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Detect any persisted role assignment missed by the user sweep.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the usermeta table name; both values are prepared.
				$wpdb->get_blog_prefix() . 'capabilities',
				'%' . $wpdb->esc_like( 's:13:"bhranti_agent";' ) . '%'
			)
		);
		if ( null === $bhranti_role_assignments || 0 !== (int) $bhranti_role_assignments ) {
			return new WP_Error( 'bhranti_uninstall_actor', __( 'Bhranti could not remove every managed role assignment.', 'bhranti' ) );
		}
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
	delete_option( 'bhranti_role_owned' );
	$bhranti_option_pattern    = $wpdb->esc_like( 'bhranti_' ) . '%';
	$bhranti_options_remaining = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must verify persisted rows, independent of caches.
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the options table name; the prefix is prepared.
			$bhranti_option_pattern
		)
	);
	if ( null === $bhranti_options_remaining ) {
		return new WP_Error( 'bhranti_uninstall_options', __( 'Bhranti could not erase its private settings.', 'bhranti' ) );
	}
	while ( 0 < (int) $bhranti_options_remaining ) {
		// Also remove legacy options and locks left by accounts deleted before uninstall.
		$bhranti_option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the plugin-owned namespace before removing it through WordPress APIs.
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the options table name; the prefix is prepared.
				$bhranti_option_pattern
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
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core owns the options table name; the prefix is prepared.
				$bhranti_option_pattern
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
