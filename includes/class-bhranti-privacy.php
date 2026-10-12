<?php
/**
 * WordPress personal-data tools for Bhranti's private workspace.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Export and erase only the requested user's data on the current site.
 */
final class Bhranti_Privacy {

	/**
	 * Connect Bhranti to the standard WordPress privacy tools.
	 */
	public static function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function exporters( $exporters ) {
		$exporters['bhranti'] = array(
			'exporter_friendly_name' => __( 'Bhranti workspace', 'bhranti' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function erasers( $erasers ) {
		$erasers['bhranti'] = array(
			'eraser_friendly_name' => __( 'Bhranti workspace', 'bhranti' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export conversations in batches, with activity on page one.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @param string $email_address Account email address.
	 * @param int    $page          Export page.
	 * @return array Export data and completion status.
	 */
	public static function export( $email_address, $page = 1 ) {
		global $wpdb;

		$user = get_user_by( 'email', trim( $email_address ) );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$page     = max( 1, (int) $page );
		$site_id  = get_current_blog_id();
		$data     = array();
		$sessions = get_posts(
			array(
				'post_type'      => 'bhranti_session',
				'post_status'    => array_keys( get_post_stati() ),
				'author'         => $user->ID,
				'posts_per_page' => 20,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		foreach ( $sessions as $session ) {
			$messages   = get_post_meta( $session->ID, 'bhranti_messages', true );
			$transcript = array();
			foreach ( is_array( $messages ) ? $messages : array() as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}
				$transcript[] = sprintf( '[%s] %s: %s', $message['created'] ?? '', $message['role'] ?? '', $message['content'] ?? '' );
			}
			$data[] = array(
				'group_id'          => 'bhranti-conversations',
				'group_label'       => __( 'Bhranti conversations', 'bhranti' ),
				'group_description' => __( 'Private conversations saved in Bhranti on this site.', 'bhranti' ),
				'item_id'           => "bhranti-session-{$site_id}-{$session->ID}",
				'data'              => array(
					array(
						'name'  => __( 'Conversation title', 'bhranti' ),
						'value' => esc_html( $session->post_title ),
					),
					array(
						'name'  => __( 'Created (UTC)', 'bhranti' ),
						'value' => esc_html( $session->post_date_gmt ),
					),
					array(
						'name'  => __( 'Updated (UTC)', 'bhranti' ),
						'value' => esc_html( $session->post_modified_gmt ),
					),
					array(
						'name'  => __( 'Request context and action results', 'bhranti' ),
						'value' => esc_html(
							wp_json_encode(
								array(
									'run'              => get_post_meta( $session->ID, 'bhranti_run', true ),
									'events'           => get_post_meta( $session->ID, 'bhranti_events', true ),
									'rollback_records' => get_post_meta( $session->ID, 'bhranti_actions', true ),
									'plugin_records'   => get_post_meta( $session->ID, 'bhranti_plugin_actions', true ),
								)
							)
						),
					),
					array(
						'name'  => __( 'Messages', 'bhranti' ),
						'value' => nl2br( esc_html( implode( "\n\n", $transcript ) ) ),
					),
				),
			);
		}

		$logs = get_posts(
			array(
				'post_type'      => 'bhranti_event',
				'post_status'    => array_keys( get_post_stati() ),
				'author'         => $user->ID,
				'posts_per_page' => 20,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $logs as $log ) {
			$data[] = array(
				'group_id'          => 'bhranti-audit',
				'group_label'       => __( 'Bhranti action logs', 'bhranti' ),
				'group_description' => __( 'Site operations requested by this account, including the acting WordPress user.', 'bhranti' ),
				'item_id'           => "bhranti-audit-{$site_id}-{$log->ID}",
				'data'              => array(
					array(
						'name'  => __( 'Activity record', 'bhranti' ),
						'value' => esc_html( wp_json_encode( get_post_meta( $log->ID, 'bhranti_event_data', true ) ) ),
					),
				),
			);
		}

		$media = self::generated_media( $user->ID, $page );
		foreach ( $media as $attachment ) {
			$record = get_post_meta( $attachment->ID, 'bhranti_generated_media', true );
			if ( ! is_array( $record ) || (int) ( $record['requester_id'] ?? 0 ) !== (int) $user->ID ) {
				continue;
			}
			$data[] = array(
				'group_id'    => 'bhranti-media',
				'group_label' => __( 'Bhranti generated media', 'bhranti' ),
				'item_id'     => "bhranti-media-{$site_id}-{$attachment->ID}",
				'data'        => array(
					array(
						'name'  => __( 'Media generation record', 'bhranti' ),
						'value' => esc_html( wp_json_encode( $record ) ),
					),
					array(
						'name'  => __( 'Attachment ID', 'bhranti' ),
						'value' => (string) $attachment->ID,
					),
				),
			);
		}

		if ( 1 === $page ) {
			$memory = Bhranti_Memory::export_user( $user->ID );
			if ( is_wp_error( $memory ) || $memory['preferences'] || $memory['capabilities'] ) {
				$data[] = array(
					'group_id'    => 'bhranti-memory',
					'group_label' => __( 'Bhranti private memory', 'bhranti' ),
					'item_id'     => "bhranti-memory-{$site_id}-{$user->ID}",
					'data'        => array(
						array(
							'name'  => __( 'Saved preferences and verified observations', 'bhranti' ),
							'value' => is_wp_error( $memory ) ? esc_html__( 'Saved private memory could not be read. It can be erased through this privacy tool or reset in Bhranti Settings.', 'bhranti' ) : esc_html( wp_json_encode( $memory ) ),
						),
					),
				);
			}
			$editors = get_user_meta( $user->ID, 'bhranti_editor_state_' . $site_id, true );
			if ( $editors ) {
				$data[] = array(
					'group_id'    => 'bhranti-editors',
					'group_label' => __( 'Bhranti editor protection', 'bhranti' ),
					'item_id'     => "bhranti-editors-{$site_id}-{$user->ID}",
					'data'        => array(
						array(
							'name'  => __( 'Tab protection markers', 'bhranti' ),
							'value' => esc_html( wp_json_encode( $editors ) ),
						),
					),
				);
			}
			// Read the exact site key; get_user_option() can fall back to a global key.
			$prefix   = $wpdb->get_blog_prefix();
			$activity = get_user_meta( $user->ID, $prefix . 'bhranti_activity', true );
			foreach ( is_array( $activity ) ? $activity : array() as $index => $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}
				$data[] = array(
					'group_id'          => 'bhranti-activity',
					'group_label'       => __( 'Bhranti activity', 'bhranti' ),
					'group_description' => __( 'Recent workspace activity recorded on this site.', 'bhranti' ),
					'item_id'           => "bhranti-activity-{$site_id}-{$user->ID}-{$index}",
					'data'              => array(
						array(
							'name'  => __( 'Event', 'bhranti' ),
							'value' => esc_html( $event['type'] ?? '' ),
						),
						array(
							'name'  => __( 'Description', 'bhranti' ),
							'value' => esc_html( $event['message'] ?? '' ),
						),
						array(
							'name'  => __( 'Date (UTC)', 'bhranti' ),
							'value' => esc_html( $event['created'] ?? '' ),
						),
						array(
							'name'  => __( 'Conversation ID', 'bhranti' ),
							'value' => (string) absint( $event['session_id'] ?? 0 ),
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => count( $sessions ) < 20 && count( $logs ) < 20 && count( $media ) < 20,
		);
	}

	/**
	 * Permanently erase the requested user's local workspace data.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @param string $email_address Account email address.
	 * @param int    $unused_page   Erasure page; deletion always starts at the first remaining batch.
	 * @return array WordPress erasure result.
	 */
	public static function erase( $email_address, $unused_page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Deletion always starts with the first remaining batch.
		global $wpdb;

		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
		$user   = get_user_by( 'email', trim( $email_address ) );
		if ( ! $user ) {
			return $result;
		}

		$memory_removed = Bhranti_Memory::erase_user( $user->ID );
		if ( is_wp_error( $memory_removed ) ) {
			$result['items_retained'] = true;
			$result['messages'][]     = __( 'Private memory could not be erased. Resolve the storage problem and retry.', 'bhranti' );
		} elseif ( $memory_removed ) {
			$result['items_removed'] = true;
		}

		// Always take the first remaining batch: deletion changes pagination offsets.
		$sessions         = get_posts(
			array(
				'post_type'      => 'bhranti_session',
				'post_status'    => array_keys( get_post_stati() ),
				'author'         => $user->ID,
				'posts_per_page' => 20,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$removed_sessions = false;
		foreach ( $sessions as $session ) {
			if ( wp_delete_post( $session->ID, true ) && ! get_post( $session->ID ) ) {
				$result['items_removed'] = true;
				$removed_sessions        = true;
			} else {
				$result['items_retained'] = true;
				/* translators: %d: Conversation ID. */
				$result['messages'][] = sprintf( __( 'Bhranti conversation %d could not be erased. Retry after resolving the storage problem.', 'bhranti' ), $session->ID );
			}
		}

		$logs         = get_posts(
			array(
				'post_type'      => 'bhranti_event',
				'post_status'    => array_keys( get_post_stati() ),
				'author'         => $user->ID,
				'posts_per_page' => 20,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$removed_logs = false;
		foreach ( $logs as $log ) {
			wp_delete_post( $log->ID, true );
			if ( ! get_post( $log->ID ) ) {
				$result['items_removed'] = true;
				$removed_logs            = true;
			} else {
				$result['items_retained'] = true;
				$result['messages'][]     = __( 'A Bhranti action log could not be erased. Resolve the storage problem and try again.', 'bhranti' );
			}
		}

		$media         = self::generated_media( $user->ID );
		$removed_media = false;
		foreach ( $media as $attachment ) {
			$record = get_post_meta( $attachment->ID, 'bhranti_generated_media', true );
			if ( is_array( $record ) && (int) ( $record['requester_id'] ?? 0 ) === (int) $user->ID && delete_post_meta( $attachment->ID, 'bhranti_generated_media', $record ) ) {
				$result['items_removed'] = true;
				$removed_media           = true;
			} else {
				$result['items_retained'] = true;
				$result['messages'][]     = __( 'A Bhranti media generation record could not be erased. The image itself is preserved.', 'bhranti' );
			}
		}

		$prefix = $wpdb->get_blog_prefix();
		// Remove the initial prototype's retired preference too.
		foreach ( array( 'bhranti_activity', 'bhranti_ai_enabled' ) as $option ) {
			if ( ! metadata_exists( 'user', $user->ID, $prefix . $option ) ) {
				continue;
			}
			if ( delete_user_option( $user->ID, $option, false ) ) {
				$result['items_removed'] = true;
			} else {
				$result['items_retained'] = true;
				$result['messages'][]     = __( 'Bhranti workspace data could not be erased. Retry after resolving the storage problem.', 'bhranti' );
			}
		}
		$editor_key = 'bhranti_editor_state_' . get_current_blog_id();
		if ( metadata_exists( 'user', $user->ID, $editor_key ) ) {
			if ( delete_user_meta( $user->ID, $editor_key ) ) {
				$result['items_removed'] = true;
			} else {
				$result['items_retained'] = true;
				$result['messages'][]     = __( 'Bhranti editor protection markers could not be erased. Resolve the storage problem and retry.', 'bhranti' );
			}
		}
		delete_option( 'bhranti_lock_' . $user->ID );
		$result['done'] = ( count( $sessions ) < 20 && count( $logs ) < 20 && count( $media ) < 20 ) || ( ! $removed_sessions && ! $removed_logs && ! $removed_media && $result['items_retained'] );

		return $result;
	}

	/**
	 * Page through the requested human's media markers without removing site content.
	 *
	 * @param int $user_id Requester.
	 * @param int $page    Export page.
	 * @return WP_Post[]
	 */
	private static function generated_media( $user_id, $page = 1 ) {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => array_keys( get_post_stati() ),
				'posts_per_page' => 20,
				'paged'          => max( 1, (int) $page ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Native privacy batches match only this plugin's generated-media marker.
					array(
						'key'     => 'bhranti_generated_media',
						'value'   => '"requester_id";i:' . (int) $user_id . ';',
						'compare' => 'LIKE',
					),
				),
			)
		);
	}
}
