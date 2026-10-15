<?php
/**
 * Bounded native schema and saved-content discovery for expert WordPress tasks.
 *
 * @package Bhranti
 */

defined( 'ABSPATH' ) || exit;

/** Inspection supplies evidence, never authority or rendered-page verification. */
final class Bhranti_Inspect {

	/** Describe finite native inspection operations. */
	public static function catalog() {
		return array(
			'bhranti_inspect' => array(
				'description' => 'Inspect supported WordPress parameters, saved block content or native image-generation availability without changing the site. resource route: path and method describe the reviewed native endpoint parameters; optional field selects one exact parameter. Metadata is not permission: execute still rechecks current guardrails and both accounts. resource content: exact item path returns a paginated block outline, full content data.revision, mechanically known authored issues, and optional exact block_path saved markup. For wp_request.content_patch use data.revision, never selected.revision; paths include whitespace/freeform entries. resource images: supply resource and optional aspect_ratio such as 16:9 or 2:3; check native metadata and site limits without generating. Choose imagery for its role and inspect existing native Media Library assets before creating new ones. If generation is available, use it where appropriate to the agreed design; unavailable support alone triggers a needed-upload question. An unsupported requested ratio may use a supported native ratio or default and does not mean image generation is unavailable. Unknown means discovery failed, not permanent lack of support. resource rendered: supply only resource and an exact saved /wp/v2/posts/ID or /wp/v2/pages/ID path. An open Bhranti browser automatically loads the authenticated native preview at desktop 1280 and mobile 390/320 CSS pixels and runs local axe WCAG A/AA and finite DOM layout, image and fragment checks. The job waits safely if the browser closes. Read returned findings, fix only authorized targets and check again. This performs actual rendered checks, not a screenshot, keyboard interaction, form submission, anonymous visitor test, design-quality judgment or full WCAG certification. A block outline or authored check alone verifies none of those rendered behaviors. No arbitrary routes, source files or callbacks. Image capability checks may use the native connector metadata service.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'resource'     => array(
							'type' => 'string',
							'enum' => array( 'route', 'content', 'images', 'rendered' ),
						),
						'path'         => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 240,
						),
						'method'       => array(
							'type' => 'string',
							'enum' => array( 'GET', 'POST', 'DELETE' ),
						),
						'field'        => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 100,
						),
						'block_path'   => array(
							'type'      => 'string',
							'maxLength' => 190,
							'pattern'   => '^[0-9]+(?:/[0-9]+)*$',
						),
						'page'         => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 250,
						),
						'per_page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 20,
						),
						'aspect_ratio' => array(
							'type'      => 'string',
							'maxLength' => 7,
							'pattern'   => '^[1-9][0-9]{0,2}:[1-9][0-9]{0,2}$',
						),
					),
					'required'             => array( 'resource' ),
					'additionalProperties' => false,
				),
			),
		);
	}

	/**
	 * Inspect within the authenticated owner's existing reviewed native authority.
	 *
	 * @param array $command Exact tool envelope.
	 * @param int   $requester_id Human administrator.
	 * @param int   $session_id Owned private conversation.
	 * @return array|WP_Error
	 */
	public static function execute( $command, $requester_id, $session_id ) {
		$post = get_post( $session_id );
		if ( get_current_user_id() !== (int) $requester_id || ! user_can( $requester_id, 'manage_options' ) || ! $post || 'bhranti_session' !== $post->post_type || 'private' !== $post->post_status || (int) $post->post_author !== (int) $requester_id ) {
			return self::error( 'bhranti_inspect_permission', __( 'This inspection is not available to your account.', 'bhranti' ), 403 );
		}
		$actor_id = Bhranti_Actions::ensure_actor();
		if ( is_wp_error( $actor_id ) ) {
			return $actor_id;
		}
		if ( ! user_can( $actor_id, 'manage_options' ) ) {
			return self::error( 'bhranti_inspect_permission', __( 'Bhranti cannot inspect this item with its current permissions.', 'bhranti' ), 403 );
		}
		if ( ! is_array( $command ) || array_diff( array_keys( $command ), array( 'tool', 'args' ) ) || 'bhranti_inspect' !== ( $command['tool'] ?? '' ) || is_wp_error( rest_validate_value_from_schema( $command['args'] ?? null, self::catalog()['bhranti_inspect']['parameters'], 'args' ) ) ) {
			return self::error( 'bhranti_inspect_invalid', __( 'Use one exact native inspection request.', 'bhranti' ) );
		}
		$args = $command['args'];
		if ( 'rendered' === $args['resource'] ) {
			return self::error( 'bhranti_render_browser', __( 'Rendered inspection needs its owned browser job in the current conversation.', 'bhranti' ), 409 );
		}
		if ( ( 'images' === $args['resource'] && array_diff( array_keys( $args ), array( 'resource', 'aspect_ratio' ) ) ) || ( 'images' !== $args['resource'] && ! isset( $args['path'] ) ) ) {
			return self::error( 'bhranti_inspect_invalid', __( 'Choose an exact native item or an image capability check without additional fields.', 'bhranti' ) );
		}
		if ( 'route' === $args['resource'] && array_intersect( array_keys( $args ), array( 'block_path', 'page', 'per_page', 'aspect_ratio' ) ) ) {
			return self::error( 'bhranti_inspect_invalid', __( 'Route inspection does not accept content paging or block paths.', 'bhranti' ) );
		}
		if ( 'content' === $args['resource'] && ( isset( $args['field'] ) || isset( $args['aspect_ratio'] ) || ( isset( $args['method'] ) && 'GET' !== $args['method'] ) ) ) {
			return self::error( 'bhranti_inspect_invalid', __( 'Saved-content inspection is read-only.', 'bhranti' ) );
		}
		$details = array(
			'tool'   => 'bhranti_inspect',
			'method' => 'GET',
			'path'   => $args['path'] ?? 'native-image-capabilities',
		);
		$logged  = Bhranti_Audit::record( 'action_started', __( 'Inspecting native WordPress information.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
		if ( is_wp_error( $logged ) || false === $logged ) {
			return self::error( 'bhranti_inspect_storage', __( 'This inspection could not be logged.', 'bhranti' ), 500 );
		}
		if ( 'images' === $args['resource'] ) {
			$result = Bhranti_Media::inspect_images( $requester_id, $actor_id, $session_id, $args['aspect_ratio'] ?? null );
		} else {
			$result = 'route' === $args['resource'] ? Bhranti_Actions::route_schema( $args['path'], $args['method'] ?? 'GET', $args['field'] ?? '' ) : self::content( $args, $requester_id, $session_id );
		}
		$logged = Bhranti_Audit::record( is_wp_error( $result ) ? 'action_failed' : 'action_completed', __( 'Native WordPress inspection finished.', 'bhranti' ), $session_id, $details + ( is_wp_error( $result ) ? array( 'code' => $result->get_error_code() ) : array() ), $requester_id, $actor_id );
		if ( is_wp_error( $logged ) || false === $logged ) {
			return self::error( 'bhranti_inspect_storage', __( 'This inspection result could not be logged.', 'bhranti' ), 500 );
		}
		return $result;
	}

	/**
	 * Reuse the existing reviewed read before examining inert saved block data.
	 *
	 * @param array $args Inspection parameters.
	 * @param int   $requester_id Human.
	 * @param int   $session_id Conversation.
	 * @return array|WP_Error
	 */
	private static function content( $args, $requester_id, $session_id ) {
		if ( ! preg_match( '#\A/wp/v2/(?:[a-z0-9_-]+)/(?:[1-9][0-9]*|[a-z0-9_-]+//[a-z0-9_-]+)\z#', $args['path'] ) ) {
			return self::error( 'bhranti_inspect_invalid', __( 'Choose an exact saved content item, not a collection.', 'bhranti' ) );
		}
		$result = Bhranti_Actions::inspect_source( $args['path'], $requester_id, $session_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$content = $result['data']['content']['raw'] ?? null;
		if ( ! is_string( $content ) || strlen( $content ) > 1048576 ) {
			return self::error( 'bhranti_inspect_content', __( 'This item has no readable block document within the inspection limit.', 'bhranti' ) );
		}
		return self::outline( $content, $args, $result['target'] ?? array() );
	}

	/**
	 * Summarize up to 5,000 native parsed blocks without invoking render callbacks.
	 *
	 * @param string $content Exact saved source.
	 * @param array  $args Validated paging and selection.
	 * @param array  $target Native reviewed target.
	 * @return array|WP_Error
	 */
	private static function outline( $content, $args, $target ) {
		$blocks   = parse_blocks( $content );
		$page     = $args['page'] ?? 1;
		$per_page = $args['per_page'] ?? 20;
		$offset   = ( $page - 1 ) * $per_page;
		$outline  = array();
		$stack    = array();
		foreach ( array_reverse( $blocks, true ) as $index => $block ) {
			$stack[] = array( $block, (string) $index, 0 );
		}
		$total    = 0;
		$selected = null;
		while ( $stack ) {
			list( $block, $path, $depth ) = array_pop( $stack );
			if ( ++$total > 5000 || $depth > 64 ) {
				return self::error( 'bhranti_inspect_limit', __( 'This block document exceeds the bounded inspection limit.', 'bhranti' ) );
			}
			if ( $total > $offset && count( $outline ) < $per_page ) {
				$outline[] = array(
					'path'       => $path,
					'name'       => is_string( $block['blockName'] ) ? mb_substr( $block['blockName'], 0, 100 ) : null,
					'children'   => count( $block['innerBlocks'] ),
					'text'       => mb_substr( sanitize_text_field( wp_strip_all_tags( $block['innerHTML'], true ) ), 0, 240 ),
					'references' => self::references( $block['attrs'] ),
				);
			}
			if ( isset( $args['block_path'] ) && $path === $args['block_path'] ) {
				$markup = serialize_block( $block );
				if ( strlen( $markup ) > 24000 ) {
					return self::error( 'bhranti_inspect_limit', __( 'That section is too large; inspect its child blocks instead.', 'bhranti' ) );
				}
				$selected = array(
					'path'     => $path,
					'name'     => $block['blockName'],
					'markup'   => $markup,
					'revision' => hash( 'sha256', $markup ),
				);
			}
			foreach ( array_reverse( $block['innerBlocks'], true ) as $index => $child ) {
				$stack[] = array( $child, $path . '/' . $index, $depth + 1 );
			}
		}
		if ( isset( $args['block_path'] ) && null === $selected ) {
			return self::error( 'bhranti_inspect_missing', __( 'That block path does not exist in the saved document.', 'bhranti' ), 404 );
		}
		$quality = Bhranti_Content_Quality::validate( $content );
		return array(
			'summary' => __( 'Saved block structure inspected without rendering.', 'bhranti' ),
			'target'  => $target,
			'data'    => array(
				'revision'        => hash( 'sha256', $content ),
				'revision_scope'  => 'Exact full saved raw content; use this revision for content_patch, not the selected block hash.',
				'block_count'     => $total,
				'page'            => $page,
				'per_page'        => $per_page,
				'has_more'        => $total > $offset + $per_page,
				'outline'         => $outline,
				'selected'        => $selected,
				'authored_checks' => is_wp_error( $quality ) ? array(
					'code'    => $quality->get_error_code(),
					'details' => $quality->get_error_data(),
				) : array( 'known_issues_found' => false ),
				'verification'    => 'Saved source and mechanically known authored checks only. Not rendered visual, visitor-flow, native JS block validity or full accessibility verification.',
			),
		);
	}

	/**
	 * Retain only finite scalar block references, never arbitrary nested attributes.
	 *
	 * @param array $attributes Parsed native block attributes.
	 * @return array
	 */
	private static function references( $attributes ) {
		$result = array();
		foreach ( array( 'ref', 'id', 'slug', 'theme', 'anchor' ) as $name ) {
			$value = $attributes[ $name ] ?? null;
			if ( is_int( $value ) ) {
				$result[ $name ] = $value;
			} elseif ( is_string( $value ) ) {
				$result[ $name ] = mb_substr( sanitize_text_field( $value ), 0, 200 );
			}
		}
		return $result;
	}

	/**
	 * Return a bounded public inspection error.
	 *
	 * @param string $code Error code.
	 * @param string $message Public message.
	 * @param int    $status REST status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
