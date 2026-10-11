<?php
/**
 * Bounded, untrusted rendered-page and explicitly captured screenshot context.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\MessagePart;

defined( 'ABSPATH' ) || exit;

/** Prepare captured context as native AI message data, never executable HTML. */
final class Bhranti_Context {

	/**
	 * Validate screenshot bytes before constructing any native file DTO.
	 *
	 * @param mixed $input Captured {data, mime_type} object.
	 * @return array|WP_Error
	 */
	public static function screenshot( $input ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'data', 'mime_type' ) ) || ! is_string( $input['data'] ?? null ) || ! in_array( $input['mime_type'] ?? '', array( 'image/jpeg', 'image/png' ), true ) ) {
			return self::error( __( 'Attach one captured PNG or JPEG screenshot as inline image data.', 'bhranti' ) );
		}
		$data  = $input['data'];
		$limit = 2097152;
		if ( '' === $data || strlen( $data ) > 4 * (int) ceil( $limit / 3 ) || ! preg_match( '/\A[A-Za-z0-9+\/]+={0,2}\z/', $data ) ) {
			return self::error( __( 'The captured screenshot exceeds the 2 MiB limit or contains invalid image data.', 'bhranti' ) );
		}
		// Native File DTOs accept base64, but decoded bytes are bounded and checked first.
		$bytes = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- User-chosen screenshots are decoded only for bounded raster validation, never code execution.
		$info  = is_string( $bytes ) && strlen( $bytes ) <= $limit ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Malformed screenshot bytes must return a validation error without PHP warnings.
		if ( ! is_array( $info ) || ( $info['mime'] ?? '' ) !== $input['mime_type'] || $info[0] < 1 || $info[1] < 1 || $info[0] > 2560 || $info[1] > 2560 ) {
			return self::error( __( 'The screenshot must be a valid PNG or JPEG no larger than 2560 pixels on either side.', 'bhranti' ) );
		}
		return array(
			'data'      => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Canonical native screenshot data, never executable code.
			'mime_type' => $input['mime_type'],
			'width'     => $info[0],
			'height'    => $info[1],
		);
	}

	/**
	 * Convert validated screenshot context into the native SDK's image message part.
	 *
	 * @param mixed $input Raw or previously validated screenshot.
	 * @return MessagePart|WP_Error
	 */
	public static function screenshot_part( $input ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'data', 'mime_type', 'width', 'height' ) ) ) {
			return self::error( __( 'The captured screenshot is not a supported image object.', 'bhranti' ) );
		}
		$validated = self::screenshot( array_intersect_key( $input, array_flip( array( 'data', 'mime_type' ) ) ) );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		try {
			return new MessagePart( new File( $validated['data'], $validated['mime_type'] ) );
		} catch ( Throwable $error ) {
			return self::error( __( 'WordPress could not prepare the captured screenshot for its native AI connector.', 'bhranti' ) );
		}
	}

	/**
	 * Retain bounded visible text and inert HTML structure without script/form data.
	 *
	 * The browser must collect only the visible page area and exclude Bhranti UI,
	 * hidden controls and password inputs. This method never executes or renders it.
	 *
	 * @param mixed $input {html, text, viewport} page context.
	 * @return array|WP_Error
	 */
	public static function page( $input ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'html', 'text', 'viewport' ) ) ) {
			return self::error( __( 'Page context must contain only rendered HTML and visible text.', 'bhranti' ) );
		}
		$result = array( 'truncated' => false );
		if ( isset( $input['viewport'] ) ) {
			$viewport = $input['viewport'];
			if ( ! is_array( $viewport ) || array_diff( array_keys( $viewport ), array( 'width', 'height' ) ) || ! is_int( $viewport['width'] ?? null ) || ! is_int( $viewport['height'] ?? null ) || $viewport['width'] < 1 || $viewport['height'] < 1 || $viewport['width'] > 16384 || $viewport['height'] > 16384 ) {
				return self::error( __( 'The captured page viewport must contain bounded dimensions.', 'bhranti' ) );
			}
			$result['viewport'] = $viewport;
		}
		foreach ( array(
			'html' => 12000,
			'text' => 8000,
		) as $key => $limit ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			if ( ! is_string( $input[ $key ] ) || strlen( $input[ $key ] ) > 512000 || wp_check_invalid_utf8( $input[ $key ] ) !== $input[ $key ] ) {
				return self::error( __( 'Rendered page context must be bounded UTF-8 text.', 'bhranti' ) );
			}
			$result['truncated'] = $result['truncated'] || mb_strlen( $input[ $key ] ) > $limit;
			$value               = mb_substr( $input[ $key ], 0, $limit );
			if ( 'html' === $key ) {
				// Remove entire non-visible payloads, including an unterminated truncated tag.
				$value = preg_replace( '#<(script|style|noscript|template|svg)\b[^>]*>[\s\S]*?(?:</\1\s*>|\z)#i', '', $value );
				$value = preg_replace( '/<!--[\s\S]*?(?:-->|\z)/', '', $value );
				$value = wp_kses_post( $value );
				$tags  = new WP_HTML_Tag_Processor( $value );
				while ( $tags->next_tag() ) {
					foreach ( $tags->get_attribute_names_with_prefix( 'data-' ) ?? array() as $attribute ) {
						$tags->remove_attribute( $attribute );
					}
					foreach ( array( 'style', 'srcset' ) as $attribute ) {
						$tags->remove_attribute( $attribute );
					}
					foreach ( array( 'href', 'src', 'poster' ) as $attribute ) {
						$url = $tags->get_attribute( $attribute );
						if ( ! is_string( $url ) ) {
							continue;
						}
						$parts = wp_parse_url( $url );
						if ( ! is_array( $parts ) || ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) ) {
							$tags->remove_attribute( $attribute );
							continue;
						}
						$safe_url  = isset( $parts['scheme'], $parts['host'] ) ? $parts['scheme'] . '://' : ( isset( $parts['host'] ) ? '//' : '' );
						$safe_url .= ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '' );
						$tags->set_attribute( $attribute, $safe_url );
					}
				}
				$value = $tags->get_updated_html();
			} else {
				$value = sanitize_textarea_field( $value );
			}
			$result[ $key ] = $value;
		}
		return $result;
	}

	/**
	 * Return one safe validation diagnostic without echoing captured content.
	 *
	 * @param string $message Safe public explanation.
	 * @return WP_Error
	 */
	private static function error( $message ) {
		return new WP_Error( 'bhranti_context_invalid', $message, array( 'status' => 400 ) );
	}
}
