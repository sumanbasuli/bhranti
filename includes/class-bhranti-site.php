<?php
/**
 * Permission-scoped native WordPress theme reads.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/** Allow native theme discovery without granting permanent theme-changing caps. */
final class Bhranti_Site {

	/**
	 * Run an exact native GET theme callback with an actor-only read capability.
	 *
	 * The caller must first validate the route schema and the human's unchanged
	 * native permission callback. This helper is internal; it registers no routes.
	 *
	 * @param WP_REST_Request $request Reviewed native GET request.
	 * @param int             $actor_id Managed Bhranti user.
	 * @param callable        $callback Actor permission callback or REST dispatch.
	 * @return mixed|WP_Error
	 */
	public static function actor_read( $request, $actor_id, $callback ) {
		if ( ! $request instanceof WP_REST_Request || 'GET' !== $request->get_method() || ! is_callable( $callback ) || ! self::is_theme_request( $request ) ) {
			return new WP_Error( 'bhranti_site_read_only', __( 'Only reviewed native theme reads can use this permission scope.', 'bhranti' ), array( 'status' => 403 ) );
		}
		wp_cache_delete( 'bhranti_actor_id', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		clean_user_cache( $actor_id );
		if ( (int) get_option( 'bhranti_actor_id' ) !== (int) $actor_id || (int) get_user_meta( $actor_id, 'bhranti_managed_actor', true ) !== get_current_blog_id() ) {
			return new WP_Error( 'bhranti_site_actor', __( 'The managed Bhranti identity could not be verified for this read.', 'bhranti' ), array( 'status' => 403 ) );
		}
		$grant         = static function ( $allcaps, $required, $args, $user ) use ( $actor_id ) {
			if ( (int) $user->ID === (int) $actor_id && 'switch_themes' === ( $args[0] ?? '' ) ) {
				$allcaps['switch_themes'] = true;
			}
			return $allcaps;
		};
		$previous_user = get_current_user_id();
		add_filter( 'user_has_cap', $grant, 10, 4 );
		try {
			wp_set_current_user( $actor_id );
			return call_user_func( $callback );
		} finally {
			remove_filter( 'user_has_cap', $grant, 10 );
			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * Recognize only actual core theme GET endpoints, never an arbitrary route.
	 *
	 * @param WP_REST_Request $request Native request.
	 * @return bool
	 */
	public static function is_theme_request( $request ) {
		if ( ! $request instanceof WP_REST_Request || 'GET' !== $request->get_method() ) {
			return false;
		}
		$path = $request->get_route();
		if ( ! is_string( $path ) || strlen( $path ) > 240 || str_contains( $path, '\\' ) || preg_match( '/[%?#\x00-\x20]|\.\./', $path ) || ! preg_match( '#\A/wp/v2/themes(?:/[^/:<>*?"|]+(?:/[^/:<>*?"|]+)?)?\z#', $path ) ) {
			return false;
		}
		foreach ( rest_get_server()->get_routes() as $route => $endpoints ) {
			if ( ! preg_match( '@^' . $route . '$@i', $path ) ) {
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				$callback   = $endpoint['callback'] ?? null;
				$permission = $endpoint['permission_callback'] ?? null;
				if ( ! empty( $endpoint['methods']['GET'] ) && is_array( $callback ) && is_object( $callback[0] ?? null ) && 'WP_REST_Themes_Controller' === get_class( $callback[0] ) && in_array( $callback[1] ?? '', array( 'get_items', 'get_item' ), true ) && is_array( $permission ) && ( $permission[0] ?? null ) === $callback[0] && in_array( $permission[1] ?? '', array( 'get_items_permissions_check', 'get_item_permissions_check' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Native theme fields that cannot initialize a user global-styles record.
	 *
	 * @return string[]
	 */
	public static function theme_fields() {
		return array( 'stylesheet', 'template', 'name', 'version', 'status', 'is_block_theme', 'requires_wp', 'requires_php' );
	}

	/**
	 * Project public installed-theme metadata from a native collection or item.
	 *
	 * @param mixed $data         Native REST response data.
	 * @param int   $requester_id Requesting user, after native theme permission checks.
	 * @param int   $actor_id     Managed actor, after native theme permission checks.
	 * @return array
	 */
	public static function theme_data( $data, $requester_id = 0, $actor_id = 0 ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( isset( $data[0] ) ) {
			return array_map(
				static function ( $item ) use ( $requester_id, $actor_id ) {
					return self::theme_data( $item, $requester_id, $actor_id );
				},
				$data
			);
		}
		$result = array_intersect_key( $data, array_flip( self::theme_fields() ) );
		foreach ( $result as $key => $value ) {
			if ( 'name' === $key && is_array( $value ) ) {
				$result[ $key ] = array();
				foreach ( array( 'raw', 'rendered' ) as $field ) {
					if ( is_string( $value[ $field ] ?? null ) ) {
						$result[ $key ][ $field ] = mb_substr( wp_strip_all_tags( $value[ $field ] ), 0, 500 );
					}
				}
			} elseif ( is_string( $value ) ) {
				$result[ $key ] = mb_substr( wp_strip_all_tags( $value ), 0, 500 );
			} elseif ( ! is_bool( $value ) ) {
				unset( $result[ $key ] );
			}
		}
		if ( isset( $result['stylesheet'] ) && $requester_id > 0 && $actor_id > 0 ) {
			$result['global_styles_id'] = self::global_styles_id( $result['stylesheet'], $requester_id, $actor_id );
		}
		return $result;
	}

	/**
	 * Discover an existing styles record without native theme-link initialization.
	 *
	 * @param string $stylesheet  Installed theme identifier.
	 * @param int    $requester_id Requesting user.
	 * @param int    $actor_id     Managed actor.
	 * @return int|null Existing ID readable by both identities, otherwise null.
	 */
	private static function global_styles_id( $stylesheet, $requester_id, $actor_id ) {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return null;
		}
		// Match Core's theme-owned published record without its internal, creating ID getter.
		$records = get_posts(
			array(
				'post_type'              => 'wp_global_styles',
				'post_status'            => array( 'publish' ),
				'posts_per_page'         => 1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Core identifies this single record through its wp_theme association.
				'tax_query'              => array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => $stylesheet,
					),
				),
			)
		);

		$id   = (int) ( $records[0] ?? 0 );
		$post = $id ? get_post( $id ) : null;
		$type = get_post_type_object( 'wp_global_styles' );
		if ( ! $post || 'wp_global_styles' !== $post->post_type || 'publish' !== $post->post_status || ! $type ) {
			return null;
		}
		$themes = wp_get_object_terms( $id, 'wp_theme', array( 'fields' => 'names' ) );
		if ( is_wp_error( $themes ) || ! in_array( $stylesheet, $themes, true ) ) {
			return null;
		}
		$controller = $type->get_rest_controller();
		if ( ! $controller || 'WP_REST_Global_Styles_Controller' !== get_class( $controller ) ) {
			return null;
		}
		$request = new WP_REST_Request( 'GET', '/wp/v2/global-styles/' . $id );
		$request->set_url_params( array( 'id' => $id ) );
		$request->set_query_params( array( 'context' => 'view' ) );
		$previous_user = get_current_user_id();
		try {
			foreach ( array( $requester_id, $actor_id ) as $user_id ) {
				wp_set_current_user( $user_id );
				$permission = $controller->get_item_permissions_check( $request );
				if ( is_wp_error( $permission ) || false === $permission || null === $permission ) {
					return null;
				}
			}
		} finally {
			wp_set_current_user( $previous_user );
		}
		return $id;
	}
}
