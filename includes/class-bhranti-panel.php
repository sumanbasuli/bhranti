<?php
/**
 * The site-wide WordPress admin-bar chat panel.
 *
 * @package Bhranti
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mount the same private Bhranti panel beside the current WordPress screen.
 */
final class Bhranti_Panel {

	/**
	 * Register the admin-bar entry, local assets, and footer mount.
	 */
	public static function register() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_footer', array( __CLASS__, 'mount' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'mount' ), 5 );
	}

	/**
	 * Restrict the panel to authenticated site administrators.
	 *
	 * @return bool
	 */
	private static function allowed() {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/**
	 * Add the native admin-bar launch control.
	 *
	 * @param WP_Admin_Bar $bar WordPress admin bar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! self::allowed() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'     => 'bhranti',
				'parent' => 'top-secondary',
				'title'  => '<span class="ab-icon" aria-hidden="true"><svg viewBox="0 0 16 16" fill="currentColor"><path d="M7.657 6.247c.11-.33.576-.33.686 0l.645 1.937a2.89 2.89 0 0 0 1.829 1.828l1.936.645c.33.11.33.576 0 .686l-1.937.645a2.89 2.89 0 0 0-1.828 1.829l-.645 1.936a.361.361 0 0 1-.686 0l-.645-1.937a2.89 2.89 0 0 0-1.828-1.828l-1.937-.645a.361.361 0 0 1 0-.686l1.937-.645a2.89 2.89 0 0 0 1.828-1.828zM3.794 1.148a.217.217 0 0 1 .412 0l.387 1.162c.173.518.579.924 1.097 1.097l1.162.387a.217.217 0 0 1 0 .412l-1.162.387A1.73 1.73 0 0 0 4.593 5.69l-.387 1.162a.217.217 0 0 1-.412 0L3.407 5.69A1.73 1.73 0 0 0 2.31 4.593l-1.162-.387a.217.217 0 0 1 0-.412l1.162-.387A1.73 1.73 0 0 0 3.407 2.31zM10.863.099a.145.145 0 0 1 .274 0l.258.774c.115.346.386.617.732.732l.774.258a.145.145 0 0 1 0 .274l-.774.258a1.16 1.16 0 0 0-.732.732l-.258.774a.145.145 0 0 1-.274 0l-.258-.774a1.16 1.16 0 0 0-.732-.732L9.1 2.137a.145.145 0 0 1 0-.274l.774-.258c.346-.115.617-.386.732-.732z"/></svg></span><span class="ab-label">' . esc_html__( 'Bhranti', 'bhranti' ) . '</span>',
				'href'   => admin_url( 'admin.php?page=bhranti' ),
				'meta'   => array( 'title' => __( 'Open Bhranti', 'bhranti' ) ),
			)
		);
	}

	/**
	 * Describe the current page without copying arbitrary query-string values.
	 *
	 * @return array
	 */
	private static function context() {
		global $post;
		$screen = is_admin() ? get_current_screen() : null;
		// Listing screens can leave the last row in global $post; it is not the page being edited.
		$post_id = $screen && 'post' === $screen->base && $post instanceof WP_Post ? $post->ID : ( ! is_admin() && is_singular() ? get_queried_object_id() : 0 );
		if ( $post_id && ! current_user_can( 'read_post', $post_id ) ) {
			$post_id = 0;
		}
		$home   = wp_parse_url( home_url( '/' ) );
		$origin = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		// The request path supplies context only; query values can contain nonces.
		$path    = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '/';
		$context = array(
			'url'       => esc_url_raw( $origin . ( is_string( $path ) && '/' === substr( $path, 0, 1 ) ? $path : '/' ) ),
			'screen'    => $screen ? $screen->id : 'front',
			'post_id'   => absint( $post_id ),
			'entity_id' => $post_id ? (string) $post_id : '',
			'post_type' => $post_id ? get_post_type( $post_id ) : ( $screen ? $screen->post_type : '' ),
			'title'     => $post_id ? wp_strip_all_tags( get_the_title( $post_id ) ) : '',
		);
		if ( $screen && ( 'site-editor' === $screen->id || 'site-editor' === $screen->base ) ) {
			// These editor identifiers are untrusted context hints, never action parameters.
			$hint_type = isset( $_GET['postType'] ) && is_string( $_GET['postType'] ) ? sanitize_key( wp_unslash( $_GET['postType'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$hint_id   = isset( $_GET['postId'] ) && is_string( $_GET['postId'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['postId'] ) ), 0, 200 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $hint_type && post_type_exists( $hint_type ) && '' !== $hint_id ) {
				$context['post_type'] = $hint_type;
				$context['entity_id'] = $hint_id;
			}
		}
		return $context;
	}

	/**
	 * Enqueue WordPress core client tools and local panel assets.
	 */
	public static function enqueue() {
		if ( ! self::allowed() ) {
			return;
		}
		// This query flag only opens the interface; it cannot run a site action.
		$open = isset( $_GET['bhranti'] ) && is_string( $_GET['bhranti'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['bhranti'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// The native editor can rewrite the URL before the panel has finished loading.
		$session_id = isset( $_GET['bhranti_session'] ) ? absint( $_GET['bhranti_session'] ) : ( isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_enqueue_style( 'bhranti-admin', plugins_url( 'assets/admin.css', BHRANTI_FILE ), array( 'buttons', 'dashicons' ), BHRANTI_VERSION );
		wp_enqueue_script( 'bhranti-admin', plugins_url( 'assets/admin.js', BHRANTI_FILE ), array( 'wp-api-fetch', 'wp-data', 'wp-notices', 'wp-i18n', 'wp-date' ), BHRANTI_VERSION, true );
		wp_set_script_translations( 'bhranti-admin', 'bhranti' );
		$screen = is_admin() ? get_current_screen() : null;
		if ( $screen && 'post' === $screen->base && in_array( $screen->post_type, array( 'post', 'page' ), true ) && $screen->is_block_editor() ) {
			wp_enqueue_script( 'bhranti-editor', plugins_url( 'assets/editor.js', BHRANTI_FILE ), array( 'bhranti-admin', 'wp-editor', 'wp-plugins', 'wp-element', 'wp-components', 'wp-compose' ), BHRANTI_VERSION, true );
			wp_set_script_translations( 'bhranti-editor', 'bhranti' );
		}
		wp_add_inline_script(
			'bhranti-admin',
			'window.bhrantiSettings = ' . wp_json_encode(
				array(
					'apiPath'           => '/bhranti/v1',
					'backgroundEnabled' => true,
					'connectorsUrl'     => admin_url( 'options-connectors.php' ),
					'logsUrl'           => admin_url( 'admin.php?page=bhranti-logs' ),
					'settingsUrl'       => admin_url( 'admin.php?page=bhranti' ),
					'dateFormat'        => get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
					'version'           => BHRANTI_VERSION,
					'context'           => self::context(),
					'autoOpen'          => $open,
					'initialSessionId'  => $session_id,
					'isAdmin'           => is_admin(),
					'userId'            => get_current_user_id(),
				)
			) . ';',
			'before'
		);
		wp_add_inline_script( 'bhranti-admin', 'wp.apiFetch.use( wp.apiFetch.createRootURLMiddleware( ' . wp_json_encode( esc_url_raw( rest_url() ) ) . ' ) ); wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( ' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . ' ) );', 'before' );
	}

	/**
	 * Mount once, before footer scripts execute, without altering page content.
	 */
	public static function mount() {
		static $mounted = false;
		if ( $mounted || ! self::allowed() ) {
			return;
		}
		$mounted = true;
		echo '<div id="bhranti-app" class="bhranti-app" hidden></div>';
	}
}
