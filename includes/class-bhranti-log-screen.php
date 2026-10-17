<?php
/**
 * Searchable, readable administration activity and standard NDJSON download.
 *
 * @package Bhranti
 */

defined( 'ABSPATH' ) || exit;

/** Keep presentation and downloads separate from durable event capture. */
final class Bhranti_Log_Screen {

	/** Register only the authenticated download and Logs stylesheet. */
	public static function register() {
		add_action( 'admin_post_bhranti_export_logs', array( __CLASS__, 'download' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Scope styles to this plugin page.
	 *
	 * @param string $hook Current administration screen.
	 */
	public static function enqueue( $hook ) {
		if ( 'bhranti_page_bhranti-logs' === $hook && current_user_can( 'manage_options' ) ) {
			wp_enqueue_style( 'bhranti-logs', plugins_url( 'assets/logs.css', BHRANTI_FILE ), array(), BHRANTI_VERSION );
		}
	}

	/**
	 * Parse only supported, bounded filters from a form or test.
	 *
	 * @param array $source Untrusted form input.
	 * @return array|WP_Error
	 */
	public static function filters( $source ) {
		$filters = array(
			'page'     => 1,
			'per_page' => 50,
		);
		foreach ( array( 'search', 'status', 'category', 'from', 'to', 'run_id', 'requester_id', 'actor_id', 'session_id', 'per_page', 'log_page' ) as $key ) {
			if ( ! isset( $source[ $key ] ) || '' === $source[ $key ] ) {
				continue;
			}
			if ( ! is_string( $source[ $key ] ) && ! is_int( $source[ $key ] ) ) {
				return new WP_Error( 'bhranti_log_filter', __( 'Check the activity filters and try again.', 'bhranti' ) );
			}
			$value = sanitize_text_field( (string) $source[ $key ] );
			if ( in_array( $key, array( 'requester_id', 'actor_id', 'session_id', 'per_page', 'log_page' ), true ) ) {
				if ( ! preg_match( '/^\d{1,9}$/D', $value ) ) {
					return new WP_Error( 'bhranti_log_filter', __( 'User, conversation and page numbers must be whole numbers.', 'bhranti' ) );
				}
				$value = (int) $value;
			}
			if ( 'search' === $key && 200 < mb_strlen( $value ) ) {
				return new WP_Error( 'bhranti_log_filter', __( 'Use a search of 200 characters or fewer.', 'bhranti' ) );
			}
			if ( 'run_id' === $key && 64 < strlen( $value ) ) {
				return new WP_Error( 'bhranti_log_filter', __( 'Check the task ID and try again.', 'bhranti' ) );
			}
			$filters[ 'log_page' === $key ? 'page' : $key ] = $value;
		}
		if ( ! in_array( $filters['per_page'], array( 20, 50, 100 ), true ) ) {
			return new WP_Error( 'bhranti_log_filter', __( 'Choose 20, 50 or 100 events per page.', 'bhranti' ) );
		}
		$filters['page'] = max( 1, $filters['page'] );
		return $filters;
	}

	/**
	 * Create a stable local URL without copying arbitrary query arguments.
	 *
	 * @param array $filters Validated filters.
	 * @param int   $page Page number.
	 * @param bool  $raw Show raw activity records.
	 * @return string
	 */
	private static function url( $filters = array(), $page = 1, $raw = false ) {
		unset( $filters['page'], $filters['max_id'] );
		if ( $raw ) {
			$filters['view'] = 'raw';
		}
		return add_query_arg( array_merge( array( 'page' => 'bhranti-logs' ), $filters, array( 'log_page' => $page ) ), admin_url( 'admin.php' ) );
	}

	/** Render a native administration activity view. */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search and pagination, with no saved preference or mutation.
		$filters = self::filters( wp_unslash( $_GET ) );
		$result  = is_wp_error( $filters ) ? $filters : Bhranti_Audit::query( $filters );
		$error   = is_wp_error( $result ) ? $result : null;
		if ( is_wp_error( $filters ) ) {
			$filters = array(
				'page'     => 1,
				'per_page' => 50,
			);
		}
		if ( $error ) {
			$result = array(
				'events'   => array(),
				'total'    => 0,
				'pages'    => 0,
				'page'     => $filters['page'],
				'per_page' => $filters['per_page'],
			);
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presentation choice.
		$raw      = isset( $_GET['view'] ) && 'raw' === $_GET['view'];
		$advanced = array_intersect_key( array_filter( $filters ), array_flip( array( 'from', 'to', 'requester_id', 'actor_id', 'session_id', 'run_id' ) ) );
		if ( $raw ) {
			unset( $advanced['session_id'], $advanced['run_id'] );
		}
		?>
		<div class="wrap bhranti-logs">
			<div class="bhranti-logs-heading">
				<div><h1><?php esc_html_e( 'Bhranti Logs', 'bhranti' ); ?></h1><p><?php esc_html_e( 'Follow what happened on your site, who requested it and how each step finished.', 'bhranti' ); ?></p></div>
				<?php if ( ! $error ) : ?>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="get">
						<input type="hidden" name="action" value="bhranti_export_logs">
						<?php wp_nonce_field( 'bhranti_export_logs', '_wpnonce', false ); ?>
						<?php foreach ( $filters as $key => $value ) : ?>
							<?php
							if ( 'page' !== $key ) :
								?>
								<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>"><?php endif; ?>
						<?php endforeach; ?>
						<button type="submit" class="button"><?php esc_html_e( 'Download logs (.ndjson)', 'bhranti' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
			<?php
			if ( $error ) :
				?>
				<div class="notice notice-error"><p><?php echo esc_html( $error->get_error_message() ); ?> <a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Reset filters', 'bhranti' ); ?></a></p></div><?php endif; ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="bhranti-log-filters">
				<input type="hidden" name="page" value="bhranti-logs">
				<?php
				if ( $raw ) :
					?>
					<input type="hidden" name="view" value="raw"><?php endif; ?>
				<div class="bhranti-log-filter-row">
					<label class="bhranti-log-search"><?php esc_html_e( 'Search activity', 'bhranti' ); ?><input type="search" name="search" value="<?php echo esc_attr( $filters['search'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Action, user, item ID or error code', 'bhranti' ); ?>" maxlength="200"></label>
					<label><?php esc_html_e( 'Outcome', 'bhranti' ); ?><select name="status" aria-label="<?php esc_attr_e( 'Outcome', 'bhranti' ); ?>"><option value=""><?php esc_html_e( 'All outcomes', 'bhranti' ); ?></option><?php self::options( Bhranti_Audit::status_options(), $filters['status'] ?? '' ); ?></select></label>
					<label><?php esc_html_e( 'Activity', 'bhranti' ); ?><select name="category" aria-label="<?php esc_attr_e( 'Activity', 'bhranti' ); ?>"><option value=""><?php esc_html_e( 'All activity', 'bhranti' ); ?></option><?php self::options( Bhranti_Audit::category_options(), $filters['category'] ?? '' ); ?></select></label>
					<label><?php esc_html_e( 'Events per page', 'bhranti' ); ?><select name="per_page" aria-label="<?php esc_attr_e( 'Events per page', 'bhranti' ); ?>">
					<?php
					self::options(
						array(
							20  => '20',
							50  => '50',
							100 => '100',
						),
						$filters['per_page']
					);
					?>
					</select></label>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'bhranti' ); ?></button>
					<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Reset', 'bhranti' ); ?></a>
				</div>
				<details class="bhranti-log-more" <?php echo $advanced ? 'open' : ''; ?>>
					<summary><?php esc_html_e( 'More filters', 'bhranti' ); ?></summary>
					<div class="bhranti-log-filter-row">
						<label><?php esc_html_e( 'From date', 'bhranti' ); ?><input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ?? '' ); ?>"></label>
						<label><?php esc_html_e( 'To date', 'bhranti' ); ?><input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ?? '' ); ?>"></label>
						<label><?php esc_html_e( 'Requested by (user ID)', 'bhranti' ); ?><input type="number" min="1" name="requester_id" value="<?php echo esc_attr( $filters['requester_id'] ?? '' ); ?>"></label>
						<label><?php esc_html_e( 'Acting user ID', 'bhranti' ); ?><input type="number" min="1" name="actor_id" value="<?php echo esc_attr( $filters['actor_id'] ?? '' ); ?>"></label>
						<label><?php esc_html_e( 'Conversation ID', 'bhranti' ); ?><input type="number" min="1" name="session_id" value="<?php echo esc_attr( $filters['session_id'] ?? '' ); ?>"></label>
						<label><?php esc_html_e( 'Task ID', 'bhranti' ); ?><input type="text" name="run_id" maxlength="64" value="<?php echo esc_attr( $filters['run_id'] ?? '' ); ?>"></label>
					</div>
					<p class="description"><?php esc_html_e( 'Use the user IDs shown on an event. Dates use your site’s timezone.', 'bhranti' ); ?></p>
				</details>
			</form>
			<div class="bhranti-log-note"><p><?php esc_html_e( 'Need help understanding an error? Open Bhranti and ask it to explain your recent logs. It can read activity without changing the site.', 'bhranti' ); ?></p><p class="description"><?php esc_html_e( 'Each row is a recorded event. “Started” records the start of a step; a later event records its result. Downloads include all matching events, with UTC timestamps. Prompts and credentials are excluded.', 'bhranti' ); ?></p></div>
			<?php if ( ! empty( $filters['session_id'] ) || ! empty( $filters['run_id'] ) ) : ?>
				<div class="bhranti-log-scope">
					<h2>
					<?php
					if ( ! empty( $filters['run_id'] ) ) {
						esc_html_e( 'Task activity', 'bhranti' );
					} else {
						/* translators: %d: conversation ID. */ echo esc_html( sprintf( __( 'Conversation #%d activity', 'bhranti' ), $filters['session_id'] ) ); }
					?>
					</h2>
					<?php
					if ( ! empty( $filters['run_id'] ) ) :
						?>
						<p><code><?php echo esc_html( $filters['run_id'] ); ?></code></p><?php endif; ?>
					<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'All site activity', 'bhranti' ); ?></a>
				</div>
			<?php endif; ?>
			<nav class="bhranti-log-views" aria-label="<?php esc_attr_e( 'Log view', 'bhranti' ); ?>">
				<a href="<?php echo esc_url( self::url( $filters, 1 ) ); ?>"
				<?php
				if ( ! $raw ) {
					echo 'aria-current="page"'; }
				?>
				><?php esc_html_e( 'Summary', 'bhranti' ); ?></a>
				<a href="<?php echo esc_url( self::url( $filters, 1, true ) ); ?>"
				<?php
				if ( $raw ) {
					echo 'aria-current="page"'; }
				?>
				><?php esc_html_e( 'Raw logs', 'bhranti' ); ?></a>
			</nav>
			<?php self::pagination( $result, $filters, 'top', $raw ); ?>
			<?php if ( $raw && $result['events'] ) : ?>
				<?php
				echo '<pre class="bhranti-log-raw" tabindex="0" role="region" aria-label="' . esc_attr__( 'Raw activity records, newest first', 'bhranti' ) . '"><code>';
				foreach ( $result['events'] as $event ) {
					echo esc_html( self::raw_record( $event ) . "\n\n" );
				}
				echo '</code></pre>';
				?>
			<?php else : ?>
			<table class="widefat bhranti-log-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'Bhranti activity, newest events first', 'bhranti' ); ?></caption>
				<thead><tr><th scope="col" class="bhranti-log-time"><?php esc_html_e( 'When', 'bhranti' ); ?></th><th scope="col" class="bhranti-log-outcome"><?php esc_html_e( 'Outcome', 'bhranti' ); ?></th><th scope="col"><?php esc_html_e( 'Activity and details', 'bhranti' ); ?></th><th scope="col" class="bhranti-log-people"><?php esc_html_e( 'People', 'bhranti' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $result['events'] as $event ) : ?>
					<?php self::row( $event ); ?>
				<?php endforeach; ?>
				<?php if ( ! $result['events'] ) : ?>
					<tr><td colspan="4" class="bhranti-log-empty"><strong><?php esc_html_e( 'No matching activity', 'bhranti' ); ?></strong><p><?php esc_html_e( 'Try another search or reset the filters. New activity appears here as you work with Bhranti.', 'bhranti' ); ?></p><a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Show all activity', 'bhranti' ); ?></a></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			<?php endif; ?>
			<?php self::pagination( $result, $filters, 'bottom', $raw ); ?>
		</div>
		<?php
	}

	/**
	 * Print native select options.
	 *
	 * @param array      $options Label map.
	 * @param string|int $current Selected value.
	 */
	private static function options( $options, $current ) {
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) $current, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
	}

	/**
	 * Render a human summary, identities and expandable evidence.
	 *
	 * @param array $event Event.
	 */
	private static function row( $event ) {
		$view = Bhranti_Audit::describe( $event );
		$time = strtotime( $event['created'] ?? '' );
		?>
		<tr>
			<td data-label="<?php esc_attr_e( 'When', 'bhranti' ); ?>" class="bhranti-log-time"><time datetime="<?php echo esc_attr( $event['created'] ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ), $time ) ); ?><span><?php echo esc_html( wp_date( get_option( 'time_format' ) . ' T', $time ) ); ?></span></time><small><?php echo esc_html( '#' . $event['event_id'] ); ?></small></td>
			<td data-label="<?php esc_attr_e( 'Outcome', 'bhranti' ); ?>"><span class="bhranti-log-badge bhranti-log-<?php echo esc_attr( $view['status'] ); ?>"><?php echo esc_html( $view['status_label'] ); ?></span><small class="bhranti-log-category"><?php echo esc_html( $view['category_label'] ); ?></small></td>
			<td data-label="<?php esc_attr_e( 'Activity', 'bhranti' ); ?>" class="bhranti-log-activity">
				<strong class="bhranti-log-title"><?php echo esc_html( $view['title'] ); ?></strong>
				<p><?php echo esc_html( $view['explanation'] ); ?></p>
				<?php
				if ( $view['target_label'] ) :
					?>
					<p class="bhranti-log-target">
					<?php
					if ( $view['target_url'] ) :
						?>
					<a href="<?php echo esc_url( $view['target_url'] ); ?>"><?php echo esc_html( $view['target_label'] ); ?></a>
						<?php
else :
	?>
	<?php echo esc_html( $view['target_label'] ); ?><?php endif; ?></p><?php endif; ?>
				<?php
				if ( $view['next_step'] ) :
					?>
					<p class="bhranti-log-next"><?php echo esc_html( $view['next_step'] ); ?></p><?php endif; ?>
				<details><summary><?php esc_html_e( 'Event details', 'bhranti' ); ?></summary><dl>
					<dt><?php esc_html_e( 'Event ID', 'bhranti' ); ?></dt><dd><code><?php echo esc_html( $event['id'] ); ?></code></dd>
					<dt><?php esc_html_e( 'Recorded at (UTC)', 'bhranti' ); ?></dt><dd><code><?php echo esc_html( $event['created'] ); ?></code></dd>
					<dt><?php esc_html_e( 'Conversation ID', 'bhranti' ); ?></dt><dd><code><?php echo esc_html( $event['session_id'] ? $event['session_id'] : '—' ); ?></code></dd>
					<?php
					foreach ( $view['metadata'] as $label => $value ) :
						?>
						<dt><?php echo esc_html( $label ); ?></dt><dd><code><?php echo esc_html( is_scalar( $value ) ? $value : wp_json_encode( $value ) ); ?></code></dd><?php endforeach; ?>
				</dl></details>
				<details class="bhranti-log-raw-event"><summary><?php esc_html_e( 'Raw log', 'bhranti' ); ?></summary><pre class="bhranti-log-raw"><?php echo esc_html( self::raw_record( $event ) ); ?></pre></details>
				<div class="bhranti-log-links">
				<?php if ( ! empty( $event['session_id'] ) ) : ?>
					<a href="<?php echo esc_url( self::url( array( 'session_id' => $event['session_id'] ), 1, true ) ); ?>"><?php esc_html_e( 'Conversation activity', 'bhranti' ); ?></a>
					<?php
					if ( get_current_user_id() === (int) $event['requester_id'] && 'bhranti_session' === get_post_type( $event['session_id'] ) && (int) get_post_field( 'post_author', $event['session_id'] ) === get_current_user_id() ) :
						?>
						<a href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page'            => 'bhranti',
									'bhranti'         => 1,
									'bhranti_session' => $event['session_id'],
								),
								admin_url( 'admin.php' )
							)
						);
						?>
						"><?php esc_html_e( 'View chat', 'bhranti' ); ?></a><?php endif; ?>
				<?php endif; ?>
				<?php
				if ( ! empty( $event['details']['run_id'] ) ) :
					?>
					<a href="<?php echo esc_url( self::url( array( 'run_id' => $event['details']['run_id'] ), 1, true ) ); ?>"><?php esc_html_e( 'Task activity', 'bhranti' ); ?></a><?php endif; ?>
				</div>
			</td>
			<td data-label="<?php esc_attr_e( 'People', 'bhranti' ); ?>" class="bhranti-log-people"><span><?php esc_html_e( 'Requested by', 'bhranti' ); ?></span><strong><?php echo esc_html( ( $event['requester'] ? $event['requester'] : __( 'Unknown user', 'bhranti' ) ) . ' (#' . $event['requester_id'] . ')' ); ?></strong><span><?php esc_html_e( 'Acting user', 'bhranti' ); ?></span><strong><?php echo esc_html( ( $event['actor'] ? $event['actor'] : __( 'Unknown user', 'bhranti' ) ) . ' (#' . $event['actor_id'] . ')' ); ?></strong></td>
		</tr>
		<?php
	}

	/**
	 * Render counts and usable numbered pagination at both ends.
	 *
	 * @param array  $result Query page.
	 * @param array  $filters Validated filters.
	 * @param string $position Distinguish navigation landmarks.
	 * @param bool   $raw Keep the selected presentation.
	 */
	private static function pagination( $result, $filters, $position, $raw ) {
		$total = (int) $result['total'];
		$first = $total && $result['events'] ? ( $result['page'] - 1 ) * $result['per_page'] + 1 : 0;
		$last  = $first ? $first + count( $result['events'] ) - 1 : 0;
		?>
		<div class="bhranti-log-pagination">
			<p><?php /* translators: 1: first visible event, 2: last visible event, 3: all matching events. */ echo esc_html( sprintf( __( '%1$s–%2$s of %3$s events', 'bhranti' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $total ) ) ); ?></p>
			<?php if ( 1 < $result['pages'] ) : ?>
				<nav aria-label="<?php echo esc_attr( 'top' === $position ? __( 'Activity pages, top', 'bhranti' ) : __( 'Activity pages, bottom', 'bhranti' ) ); ?>">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'         => str_replace( '999999999', '%#%', self::url( $filters, 999999999, $raw ) ),
							'format'       => '',
							'current'      => $result['page'],
							'total'        => $result['pages'],
							'mid_size'     => 1,
							'prev_text'    => __( 'Previous', 'bhranti' ),
							'next_text'    => __( 'Next', 'bhranti' ),
							'aria_current' => 'page',
						)
					)
				);
				?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Format the same bounded, redacted record used in the download.
	 *
	 * @param array $event Saved activity event.
	 * @return string Readable JSON.
	 */
	private static function raw_record( $event ) {
		return wp_json_encode( json_decode( Bhranti_Audit::export_event( $event ), true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
	}

	/**
	 * Write the complete matching snapshot in bounded batches, not a PHP array.
	 *
	 * @param array    $filters Validated filters.
	 * @param callable $write Receives one complete NDJSON record at a time.
	 * @return int|WP_Error Number of records written.
	 */
	public static function stream( $filters, $write ) {
		$filters['page']     = 1;
		$filters['per_page'] = 100;
		$result              = Bhranti_Audit::query( $filters );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$filters['max_id'] = $result['latest_id'];
		$count             = 0;
		while ( $result['events'] ) {
			foreach ( $result['events'] as $event ) {
				$write( Bhranti_Audit::export_event( $event ) );
				++$count;
			}
			if ( $result['page'] >= $result['pages'] ) {
				break;
			}
			++$filters['page'];
			$result = Bhranti_Audit::query( $filters );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return $count;
	}

	/** Send an authenticated local download without writing a public file. */
	public static function download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download these logs.', 'bhranti' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bhranti_export_logs' );
		$filters = self::filters( wp_unslash( $_GET ) );
		if ( ! is_wp_error( $filters ) ) {
			$filters['page'] = 1;
		}
		$result = is_wp_error( $filters ) ? $filters : Bhranti_Audit::query( $filters );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
		}
		$filters['max_id'] = $result['latest_id'];
		nocache_headers();
		header( 'Content-Type: application/x-ndjson; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="bhranti-logs-' . gmdate( 'Ymd-His' ) . '.ndjson"' );
		header( 'X-Bhranti-Log-Count: ' . (int) $result['total'] );
		self::stream(
			$filters,
			static function ( $line ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A standard NDJSON attachment; export_event encodes the allowlisted record, never HTML.
				echo $line;
			}
		);
		exit;
	}
}
