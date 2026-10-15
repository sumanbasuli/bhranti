<?php
/**
 * Bounded edits to saved native block content, without rendering blocks.
 *
 * @package Bhranti
 */

defined( 'ABSPATH' ) || exit;

/** Content patches expand into ordinary reviewed native content writes. */
final class Bhranti_Content_Patch {

	/** Describe the optional harness argument, never a WordPress route parameter. */
	public static function schema() {
		return array(
			'type'                 => 'object',
			'description'          => 'Small saved-block edit on an existing post, page or template. Use POST to its exact item path and omit params. revision is the full content SHA256 from bhranti_inspect resource content data.revision, never selected.revision. append without block_path adds root blocks; append with block_path adds inside a simple core/group only. replace requires block_path and exactly one complete block. Inspect the new revision after a conflict. This is a harness argument, not a native REST field.',
			'properties'           => array(
				'revision'   => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
				'operation'  => array(
					'type' => 'string',
					'enum' => array( 'append', 'replace' ),
				),
				'block_path' => array(
					'type'      => 'string',
					'maxLength' => 190,
					'pattern'   => '^(?:0|[1-9][0-9]*)(?:/(?:0|[1-9][0-9]*))*$',
				),
				'content'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 8192,
					'description' => 'At most 8192 UTF-8 bytes of complete registered block markup. No freeform text, empty replacement or attribute-only operations. Expanded content still passes native schema, code, content-quality and KSES checks. Native JS save validity and rendered accessibility still need verification.',
				),
			),
			'required'             => array( 'revision', 'operation', 'content' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Validate the harness envelope before allowing an otherwise empty update.
	 *
	 * @param array  $args Native tool arguments plus the optional patch.
	 * @param string $controller_class Matched exact native controller.
	 * @param string $type Native schema title.
	 * @param string $callback Matched native operation.
	 * @return true|WP_Error
	 */
	public static function validate( $args, $controller_class, $type, $callback ) {
		$patch = $args['content_patch'] ?? null;
		$valid = rest_validate_value_from_schema( $patch, self::schema(), 'content_patch' );
		if ( is_wp_error( $valid ) || ! is_array( $patch ) || strlen( $patch['content'] ) > 8192 || '' === trim( $patch['content'] ) || ( 'replace' === $patch['operation'] && ! isset( $patch['block_path'] ) ) ) {
			return self::error( 'bhranti_content_patch_invalid', __( 'Use a content revision and a complete block fragment of at most 8192 bytes. Replacing a block also requires its exact block path.', 'bhranti' ) );
		}
		$path  = $args['path'] ?? '';
		$posts = 'WP_REST_Posts_Controller' === $controller_class && in_array( $type, array( 'post', 'page' ), true ) && preg_match( '#\A/wp/v2/' . ( 'post' === $type ? 'posts' : 'pages' ) . '/[1-9][0-9]*\z#', $path );
		$theme = 'WP_REST_Templates_Controller' === $controller_class && 'wp_template' === $type && preg_match( '#\A/wp/v2/templates/[a-z0-9_-]+//[a-z0-9_-]+\z#', $path );
		if ( 'POST' !== ( $args['method'] ?? '' ) || 'update_item' !== $callback || ( ! $posts && ! $theme ) || ! empty( $args['params'] ) ) {
			return self::error( 'bhranti_content_patch_invalid', __( 'A content patch updates only one existing post, page or template, using POST without other native fields.', 'bhranti' ) );
		}
		$fragment = self::document( $patch['content'], true );
		if ( is_wp_error( $fragment ) ) {
			return $fragment;
		}
		if ( 'replace' === $patch['operation'] && 1 !== count(
			array_filter(
				$fragment['blocks'],
				static function ( $block ) {
					return null !== $block['blockName']; }
			)
		) ) {
			return self::error( 'bhranti_content_patch_invalid', __( 'Replace one block with exactly one complete block.', 'bhranti' ) );
		}
		return true;
	}

	/**
	 * Expand against the exact saved source after permissions and policy checks.
	 *
	 * @param string $content Existing raw saved content.
	 * @param array  $patch Validated bounded patch.
	 * @return string|WP_Error Full native content value, with unchanged bytes preserved.
	 */
	public static function apply( $content, $patch ) {
		$valid = rest_validate_value_from_schema( $patch, self::schema(), 'content_patch' );
		if ( is_wp_error( $valid ) || ! is_array( $patch ) || strlen( $patch['content'] ) > 8192 || '' === trim( $patch['content'] ) || ( 'replace' === $patch['operation'] && ! isset( $patch['block_path'] ) ) ) {
			return self::malformed();
		}
		if ( ! is_string( $content ) || strlen( $content ) > 1048576 || ! hash_equals( hash( 'sha256', $content ), $patch['revision'] ) ) {
			return self::error( 'bhranti_content_patch_conflict', __( 'The saved content changed. Inspect its current full revision before preparing another section.', 'bhranti' ), 409 );
		}
		$fragment = self::document( $patch['content'], true );
		if ( is_wp_error( $fragment ) || ( 'replace' === $patch['operation'] && 1 !== count(
			array_filter(
				$fragment['blocks'],
				static function ( $block ) {
					return null !== $block['blockName']; }
			)
		) ) ) {
			return self::malformed();
		}
		$document = self::document( $content );
		if ( is_wp_error( $document ) ) {
			return $document;
		}
		if ( ! isset( $patch['block_path'] ) ) {
			$result = $content . ( '' === $content ? '' : "\n\n" ) . $patch['content'];
		} else {
			$node = $document['nodes'][ $patch['block_path'] ] ?? null;
			if ( ! $node || null === $node['name'] ) {
				return self::error( 'bhranti_content_patch_invalid', __( 'Select an existing named block from the current content outline.', 'bhranti' ) );
			}
			if ( 'replace' === $patch['operation'] ) {
				$result = substr( $content, 0, $node['start'] ) . $patch['content'] . substr( $content, $node['end'] );
			} else {
				$offset = self::group_insertion( $content, $node, $document['nodes'] );
				if ( is_wp_error( $offset ) ) {
					return $offset;
				}
				$result = substr( $content, 0, $offset ) . "\n" . $patch['content'] . "\n" . substr( $content, $offset );
			}
		}
		if ( strlen( $result ) > 1048576 ) {
			return self::error( 'bhranti_content_patch_invalid', __( 'This saved document exceeds the content inspection limit.', 'bhranti' ) );
		}
		$verified = self::document( $result );
		return is_wp_error( $verified ) ? $verified : $result;
	}

	/**
	 * Match native parser paths to exact source spans; never render a block.
	 *
	 * @param string $content Saved source.
	 * @param bool   $fragment Require complete registered authored blocks.
	 * @return array|WP_Error
	 */
	private static function document( $content, $fragment = false ) {
		$parser           = new WP_Block_Parser();
		$parser->document = $content;
		$parser->offset   = 0;
		$nodes            = array();
		$stack            = array();
		$roots            = 0;
		$cursor           = 0;
		while ( true ) {
			list( $kind, $name, $attrs, $start, $length ) = $parser->next_token();
			$gap_end                                      = 'no-more-tokens' === $kind ? strlen( $content ) : $start;
			if ( preg_match( '/<!--\s*\/?wp:/', substr( $content, $parser->offset, $gap_end - $parser->offset ) ) ) {
				return self::malformed();
			}
			if ( 'no-more-tokens' === $kind ) {
				if ( $stack ) {
					return self::malformed();
				}
				if ( $cursor < strlen( $content ) ) {
					$nodes[ (string) $roots ] = array(
						'name'  => null,
						'start' => $cursor,
						'end'   => strlen( $content ),
					);
				}
				break;
			}
			if ( 'block-closer' !== $kind && ( count( $nodes ) >= 5000 || count( $stack ) >= 64 ) ) {
				return self::error( 'bhranti_content_patch_invalid', __( 'This block document exceeds the bounded editing limit.', 'bhranti' ) );
			}
			$token = substr( $content, $start, $length );
			if ( 'block-closer' === $kind ) {
				$path = end( $stack );
				if ( false === $path || $nodes[ $path ]['name'] !== $name || ! preg_match( '/\A<!--\s+\/wp:[a-z][a-z0-9_\/-]*\s+-->\z/s', $token ) ) {
					return self::malformed();
				}
				array_pop( $stack );
				$nodes[ $path ]['close'] = $start;
				$nodes[ $path ]['end']   = $start + $length;
				if ( ! $stack ) {
					$cursor = $start + $length;
				}
			} else {
				if ( ! is_array( $attrs ) || preg_match( '/\A<!--\s+\/wp:/', $token ) ) {
					return self::malformed();
				}
				if ( ! $stack ) {
					if ( $start > $cursor ) {
						$nodes[ (string) $roots++ ] = array(
							'name'  => null,
							'start' => $cursor,
							'end'   => $start,
						);
					}
					$path = (string) $roots++;
				} else {
					$parent                         = end( $stack );
					$path                           = $parent . '/' . count( $nodes[ $parent ]['children'] );
					$nodes[ $parent ]['children'][] = $path;
				}
				$nodes[ $path ] = array(
					'name'     => $name,
					'start'    => $start,
					'open'     => $start + $length,
					'close'    => $start + $length,
					'end'      => $start + $length,
					'children' => array(),
				);
				if ( 'block-opener' === $kind ) {
					$stack[] = $path;
				} elseif ( ! $stack ) {
					$cursor = $start + $length;
				}
			}
			$parser->offset = $start + $length;
		}
		$blocks = parse_blocks( $content );
		$queue  = array( array( $blocks, '' ) );
		$seen   = 0;
		$named  = 0;
		while ( $queue ) {
			list( $siblings, $prefix ) = array_pop( $queue );
			foreach ( $siblings as $index => $block ) {
				$path = $prefix . $index;
				$node = $nodes[ $path ] ?? null;
				if ( ! $node || ! array_key_exists( 'blockName', $block ) || $node['name'] !== $block['blockName'] || ! is_array( $block['innerBlocks'] ?? null ) || ! is_array( $block['innerContent'] ?? null ) ) {
					return self::malformed();
				}
				++$seen;
				if ( null === $node['name'] ) {
					if ( $fragment && '' !== trim( $block['innerHTML'] ) ) {
						return self::malformed();
					}
					continue;
				}
				++$named;
				$nulls = 0;
				$html  = '';
				foreach ( $block['innerContent'] as $chunk ) {
					if ( null === $chunk ) {
						++$nulls;
					} elseif ( is_string( $chunk ) ) {
						$html .= $chunk;
					} else {
						return self::malformed();
					}
				}
				if ( count( $block['innerBlocks'] ) !== $nulls || count( $node['children'] ) !== $nulls || $html !== $block['innerHTML'] || ( $fragment && ! WP_Block_Type_Registry::get_instance()->is_registered( $node['name'] ) ) ) {
					return self::malformed();
				}
				$queue[] = array( $block['innerBlocks'], $path . '/' );
			}
		}
		if ( count( $nodes ) !== $seen || 5000 < $seen || ( $fragment && ! $named ) ) {
			return self::malformed();
		}
		return array(
			'blocks' => $blocks,
			'nodes'  => $nodes,
		);
	}

	/**
	 * Insert inside a simple Group wrapper, preserving every existing null slot.
	 *
	 * @param string $content Existing document.
	 * @param array  $node Native Group span.
	 * @param array  $nodes All native spans.
	 * @return int|WP_Error Byte offset before the Group wrapper closes.
	 */
	private static function group_insertion( $content, $node, $nodes ) {
		if ( 'core/group' !== $node['name'] || $node['open'] === $node['end'] ) {
			return self::error( 'bhranti_content_patch_invalid', __( 'Nested append requires an existing simple core/group. Replace a complete block for other structures.', 'bhranti' ) );
		}
		$cursor = $node['open'];
		$chunks = array();
		foreach ( $node['children'] as $path ) {
			$chunks[] = substr( $content, $cursor, $nodes[ $path ]['start'] - $cursor );
			$cursor   = $nodes[ $path ]['end'];
		}
		$chunks[] = substr( $content, $cursor, $node['close'] - $cursor );
		if ( ! preg_match( '/\A\s*<(div|main|section|article|aside|header|footer)(?:\s[^<>]*)?>/i', $chunks[0], $opening ) ) {
			return self::malformed();
		}
		$tag  = $opening[1];
		$last = count( $chunks ) - 1;
		if ( ! preg_match( '/<\/' . $tag . '>\s*\z/i', $chunks[ $last ], $closing, PREG_OFFSET_CAPTURE ) ) {
			return self::malformed();
		}
		$chunks[ $last ] = substr( $chunks[ $last ], 0, $closing[0][1] );
		$chunks[0]       = substr( $chunks[0], strlen( $opening[0] ) );
		foreach ( $chunks as $chunk ) {
			if ( '' !== trim( $chunk ) ) {
				return self::malformed();
			}
		}
		return $cursor + $closing[0][1];
	}

	/** Return a safe grammar error, never source content. */
	private static function malformed() {
		return self::error( 'bhranti_content_patch_invalid', __( 'The block fragment or selected structure is incomplete or unsuitable for a bounded block edit. Use complete native blocks and inspect the saved structure.', 'bhranti' ) );
	}

	/**
	 * Create a bounded public error.
	 *
	 * @param string $code Error code.
	 * @param string $message Public description.
	 * @param int    $status REST status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
