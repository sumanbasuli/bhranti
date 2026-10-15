<?php
/**
 * Public descriptions of prepared actions, without executing or authorizing them.
 *
 * @package Bhranti
 */

defined( 'ABSPATH' ) || exit;

/** Derive progress from the actual pending command rather than simulated work. */
final class Bhranti_Progress {
	/**
	 * Describe a proposed tool using bounded labels and native item links.
	 *
	 * @param array  $command Parsed tool command. Execution still validates its complete schema.
	 * @param int    $requester_id Requesting administrator.
	 * @param int    $actor_id Managed user.
	 * @param string $intent Optional public description of the intended outcome.
	 * @return array
	 */
	public static function describe( $command, $requester_id, $actor_id, $intent = '' ) {
		$tool   = $command['tool'];
		$args   = $command['args'];
		$method = is_string( $args['method'] ?? null ) ? strtoupper( $args['method'] ) : 'GET';
		$path   = is_string( $args['path'] ?? null ) ? mb_substr( sanitize_text_field( $args['path'] ), 0, 240 ) : '';
		$data   = array(
			'tool'       => $tool,
			'method'     => $method,
			'path'       => $path,
			'summary'    => __( 'Preparing a WordPress action', 'bhranti' ),
			'details'    => array(),
			'write'      => 'generate_image' === $tool || ! in_array( $method, array( 'GET', 'HEAD' ), true ),
			'navigation' => false,
		);
		$labels = array(
			'bhranti_task'    => __( 'Organizing the work plan', 'bhranti' ),
			'bhranti_skill'   => __( 'Loading specialist WordPress guidance', 'bhranti' ),
			'bhranti_inspect' => __( 'Inspecting native WordPress information', 'bhranti' ),
			'site_search'     => __( 'Searching site content', 'bhranti' ),
			'plugin_search'   => __( 'Searching the WordPress plugin directory', 'bhranti' ),
			'generate_image'  => __( 'Generating an image through WordPress AI', 'bhranti' ),
			'open_item'       => __( 'Reading the requested WordPress item', 'bhranti' ),
			'reference_page'  => __( 'Inspecting the supplied design reference', 'bhranti' ),
		);
		if ( isset( $labels[ $tool ] ) ) {
			$data['summary'] = $labels[ $tool ];
		}
		if ( in_array( $tool, array( 'bhranti_task', 'bhranti_skill', 'bhranti_inspect' ), true ) ) {
			$data['write']       = false;
			$data['site_action'] = 'bhranti_inspect' === $tool && in_array( $args['resource'] ?? '', array( 'content', 'rendered' ), true );
			$data['method']      = 'GET';
		}
		if ( 'bhranti_inspect' === $tool && 'rendered' === ( $args['resource'] ?? '' ) ) {
			$data['summary'] = __( 'Checking actual desktop and mobile rendering', 'bhranti' );
			$data['details'] = array( __( 'Automated accessibility, layout, images and anchors', 'bhranti' ) );
		}
		if ( 'bhranti_task_limit' === $tool ) {
			$data['path']    = '/bhranti/v1/task-limit';
			$data['summary'] = 'GET' === $method ? __( 'Reading Bhranti Task step limit', 'bhranti' ) : __( 'Updating Bhranti Task step limit', 'bhranti' );
			$data['details'] = 'POST' === $method ? array( 'max_steps' ) : array();
			if ( user_can( $requester_id, 'manage_options' ) && user_can( $actor_id, 'manage_options' ) ) {
				$data['target'] = Bhranti_Settings::task_target();
			}
		}
		if ( 'commerce_request' === $tool ) {
			$data = array_replace( $data, Bhranti_Commerce::progress( $command, $requester_id, $actor_id ) );
		}
		if ( 'form_request' === $tool ) {
			$form = Bhranti_Forms::progress( $command, $requester_id, $actor_id );
			if ( is_array( $form ) ) {
				$data = array_replace( $data, $form );
			}
		}
		if ( 'plugin_request' === $tool ) {
			$data['summary'] = 'GET' === $method ? __( 'Reading installed plugin information', 'bhranti' ) : ( 'DELETE' === $method ? __( 'Requesting approval to uninstall a plugin', 'bhranti' ) : __( 'Installing or changing a WordPress plugin', 'bhranti' ) );
		}
		if ( 'wp_request' === $tool || 'open_item' === $tool || ( 'bhranti_inspect' === $tool && 'content' === ( $args['resource'] ?? '' ) ) ) {
			if ( preg_match( '#\A/wp/v2/(posts|pages|media|comments|themes|templates|template-parts|menu-items|menus|settings|categories|tags|types|taxonomies|search)(?:/([^/?\#]+))?\z#', $path, $match ) ) {
				$resources = array(
					'posts'          => __( 'posts', 'bhranti' ),
					'pages'          => __( 'pages', 'bhranti' ),
					'media'          => __( 'media', 'bhranti' ),
					'comments'       => __( 'comments', 'bhranti' ),
					'themes'         => __( 'themes', 'bhranti' ),
					'templates'      => __( 'templates', 'bhranti' ),
					'template-parts' => __( 'template parts', 'bhranti' ),
					'menu-items'     => __( 'navigation items', 'bhranti' ),
					'menus'          => __( 'menus', 'bhranti' ),
					'settings'       => __( 'site settings', 'bhranti' ),
					'categories'     => __( 'categories', 'bhranti' ),
					'tags'           => __( 'tags', 'bhranti' ),
					'types'          => __( 'content types', 'bhranti' ),
					'taxonomies'     => __( 'taxonomies', 'bhranti' ),
					'search'         => __( 'search results', 'bhranti' ),
				);
				/* translators: %s: WordPress resource being acted on, such as pages or media. */
				$verb            = 'GET' === $method ? __( 'Reading %s', 'bhranti' ) : ( 'DELETE' === $method ? __( 'Requesting approval to trash %s', 'bhranti' ) : ( empty( $match[2] ) ? __( 'Creating %s', 'bhranti' ) : __( 'Updating %s', 'bhranti' ) ) );
				$data['summary'] = sprintf( $verb, $resources[ $match[1] ] );
				if ( in_array( $match[1], array( 'posts', 'pages', 'media' ), true ) && ! empty( $match[2] ) && ctype_digit( $match[2] ) ) {
					$id   = (int) $match[2];
					$post = get_post( $id );
					if ( $post && user_can( $requester_id, 'read_post', $id ) && user_can( $actor_id, 'read_post', $id ) ) {
						$data['target']     = array(
							'id'    => $id,
							'type'  => $post->post_type,
							'title' => mb_substr( wp_strip_all_tags( $post->post_title ), 0, 150 ),
							'url'   => get_edit_post_link( $id, 'raw' ),
						);
						$data['navigation'] = $data['write'] && 'DELETE' !== $method && 'attachment' !== $post->post_type;
					}
				}
			}
		}
		if ( ! in_array( $tool, array( 'commerce_request', 'form_request' ), true ) && isset( $args['params'] ) && is_array( $args['params'] ) ) {
			$data['details'] = array_values( array_intersect( array_keys( $args['params'] ), array( 'title', 'content', 'excerpt', 'status', 'featured_media', 'alt_text', 'caption', 'description', 'slug', 'parent', 'menu_order', 'categories', 'tags', 'template', 'styles', 'settings' ) ) );
		}
		if ( is_string( $intent ) && '' !== trim( $intent ) ) {
			$data['intent'] = mb_substr( sanitize_text_field( $intent ), 0, 240 );
		}
		return $data;
	}
}
