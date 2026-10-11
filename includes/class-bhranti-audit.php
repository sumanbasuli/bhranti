<?php
/**
 * Durable site-local activity records, kept separate from chat messages.
 *
 * @package Bhranti
 */

defined( 'ABSPATH' ) || exit;

/** Audit successful, failed, denied and cancelled operations with both identities. */
final class Bhranti_Audit {
	/** Register nonpublic log storage. */
	public static function register_storage() {
		register_post_type(
			'bhranti_event',
			array(
				'public'           => false,
				'show_ui'          => false,
				'show_in_rest'     => false,
				'can_export'       => false,
				'supports'         => array( 'author' ),
				'capability_type'  => 'post',
				'map_meta_cap'     => true,
				'delete_with_user' => true,
			)
		);
	}

	/**
	 * Save an audit event before an operation can run.
	 *
	 * @param string $type Event code.
	 * @param string $summary Human-readable description, without prompts or secrets.
	 * @param int    $session_id Conversation.
	 * @param array  $details Nonsecret operation metadata.
	 * @param int    $requester_id Requesting user; defaults to current user.
	 * @param int    $actor_id Executing user; defaults to requester.
	 * @return int|WP_Error
	 */
	public static function record( $type, $summary, $session_id = 0, $details = array(), $requester_id = 0, $actor_id = 0 ) {
		$requester_id = $requester_id ? $requester_id : get_current_user_id();
		$actor_id     = $actor_id ? $actor_id : $requester_id;
		$requester    = get_userdata( $requester_id );
		$actor        = get_userdata( $actor_id );
		$event        = array(
			'id'           => wp_generate_uuid4(),
			'type'         => sanitize_key( $type ),
			'message'      => sanitize_text_field( $summary ),
			'created'      => gmdate( 'c' ),
			'session_id'   => absint( $session_id ),
			'requester_id' => $requester_id,
			'requester'    => $requester ? $requester->user_login : '',
			'actor_id'     => $actor_id,
			'actor'        => $actor ? $actor->user_login : '',
			'details'      => array(),
		);
		if ( isset( $details['target'] ) && is_array( $details['target'] ) ) {
			$details['target_id']   = $details['target']['id'] ?? '';
			$details['target_type'] = $details['target']['type'] ?? '';
		}
		foreach ( array( 'method', 'path', 'action_id', 'target_id', 'target_type', 'status', 'code', 'reason', 'exception_class', 'run_id' ) as $key ) {
			if ( isset( $details[ $key ] ) && is_scalar( $details[ $key ] ) ) {
				$event['details'][ $key ] = mb_substr( sanitize_text_field( (string) $details[ $key ] ), 0, 300 );
			}
		}
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => 'bhranti_event',
					'post_status' => 'private',
					'post_author' => $requester_id,
					'post_parent' => absint( $session_id ),
					'meta_input'  => array(
						'bhranti_event_data' => $event,
						'bhranti_session_id' => absint( $session_id ),
					),
				)
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id || get_post_meta( $id, 'bhranti_event_data', true ) !== $event ) {
			if ( ! is_wp_error( $id ) && $id ) {
				wp_delete_post( $id, true );
			}
			return new WP_Error( 'bhranti_audit_failed', __( 'WordPress could not save the activity log. The operation was stopped.', 'bhranti' ), array( 'status' => 500 ) );
		}
		return $id;
	}

	/**
	 * Read a page of audit metadata. Capability/ownership checks belong to the caller.
	 *
	 * @param int $requester_id Filter to user; zero means site-wide.
	 * @param int $page Page number.
	 * @param int $session_id Optional session filter.
	 * @return array
	 */
	public static function events( $requester_id = 0, $page = 1, $session_id = 0 ) {
		$args = array(
			'post_type'      => 'bhranti_event',
			'post_status'    => 'private',
			'posts_per_page' => 50,
			'paged'          => max( 1, (int) $page ),
			'orderby'        => 'ID',
			'order'          => 'DESC',
		);
		if ( $requester_id ) {
			$args['author'] = $requester_id;
		}
		if ( $session_id ) {
			$args['post_parent'] = absint( $session_id );
		}

		$events = array();
		foreach ( get_posts( $args ) as $post ) {
			$event = get_post_meta( $post->ID, 'bhranti_event_data', true );
			if ( is_array( $event ) && ( ! $session_id || (int) $event['session_id'] === (int) $session_id ) ) {
				$events[] = $event;
			}
		}
		return $events;
	}

	/** Render separate native WordPress log screen with pagination. */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Page only selects a bounded read-only log slice.
		$page   = isset( $_GET['log_page'] ) ? max( 1, absint( $_GET['log_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$events = self::events( 0, $page );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bhranti Logs', 'bhranti' ); ?></h1>
			<p><?php esc_html_e( 'Site activity recorded by Bhranti. Each operation identifies its requester and acting WordPress user. Prompt text and credentials are excluded.', 'bhranti' ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Time', 'bhranti' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event', 'bhranti' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Requested by', 'bhranti' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Acting user', 'bhranti' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'bhranti' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Conversation', 'bhranti' ); ?></th>
				</tr></thead><tbody>
				<?php foreach ( $events as $event ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event['created'] ) ) ); ?></td>
						<td><?php echo esc_html( $event['type'] ); ?></td>
						<td><?php echo esc_html( $event['requester'] . ' (#' . $event['requester_id'] . ')' ); ?></td>
						<td><?php echo esc_html( $event['actor'] . ' (#' . $event['actor_id'] . ')' ); ?></td>
						<td><?php echo esc_html( $event['message'] ); ?><br><code><?php echo esc_html( implode( ' · ', $event['details'] ) ); ?></code></td>
						<td>
							<?php if ( $event['session_id'] && get_current_user_id() === (int) $event['requester_id'] && get_post( $event['session_id'] ) ) : ?>
								<a href="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'page'    => 'bhranti',
											'bhranti' => '1',
											'bhranti_session' => $event['session_id'],
										),
										admin_url( 'admin.php' )
									)
								);
								?>
											"><?php esc_html_e( 'View chat', 'bhranti' ); ?></a>
								<?php
							else :
								?>
								&mdash; <?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php
				if ( ! $events ) :
					?>
					<tr><td colspan="6"><?php esc_html_e( 'No activity on this page.', 'bhranti' ); ?></td></tr><?php endif; ?>
				</tbody>
			</table>
			<p>
				<?php
				if ( 1 < $page ) :
					?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'log_page', $page - 1 ) ); ?>"><?php esc_html_e( 'Newer events', 'bhranti' ); ?></a><?php endif; ?>
				<?php
				if ( 50 === count( $events ) ) :
					?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'log_page', $page + 1 ) ); ?>"><?php esc_html_e( 'Older events', 'bhranti' ); ?></a><?php endif; ?>
			</p>
		</div>
		<?php
	}
}
