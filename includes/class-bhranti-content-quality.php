<?php
/**
 * Check mechanically known authored-content issues without rendering blocks.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/** Authored checks supplement, and never certify, rendered WCAG 2.2 AA review. */
final class Bhranti_Content_Quality {

	/**
	 * Reject newly introduced issues while preserving unchanged legacy content.
	 *
	 * @param string $content Proposed saved content.
	 * @param string $before Existing saved content, when available.
	 * @return true|WP_Error
	 */
	public static function validate( $content, $before = '' ) {
		$before = is_string( $before ) ? $before : '';
		if ( is_string( $content ) && $content === $before ) {
			return true;
		}
		if ( ! is_string( $content ) || strlen( $content ) > 1048576 || strlen( $before ) > 1048576 ) {
			return self::error( array( 'content_review_limit' ) );
		}
		$proposed = self::issues( $content );
		$existing = self::issues( $before );
		$codes    = array();
		foreach ( $proposed as $identity => $issue ) {
			if ( 'content_review_limit' === $issue['code'] || $issue['count'] > ( $existing[ $identity ]['count'] ?? 0 ) ) {
				$codes[] = $issue['code'];
			}
		}
		return $codes ? self::error( $codes ) : true;
	}

	/**
	 * Scan inert HTML and known native text-block attributes, with finite work.
	 *
	 * @param string $content Authored markup.
	 * @return array Internal hashed issue identities and counts.
	 */
	private static function issues( $content ) {
		$issues    = array();
		$ids       = array();
		$processor = new WP_HTML_Tag_Processor( $content );
		$pair      = null;
		$name      = null;
		$tokens    = 0;
		while ( $processor->next_token() ) {
			if ( ++$tokens > 20000 ) {
				self::add_issue( $issues, 'content_review_limit', array() );
				break;
			}
			if ( '#text' === $processor->get_token_type() ) {
				$text = self::text( $processor->get_modifiable_text() );
				if ( '' !== $text && $name ) {
					$name['named'] = true;
				}
				if ( '' !== $text && $pair && self::contrast( $pair ) < $pair['minimum'] ) {
					self::add_issue( $issues, 'text_contrast_low', array( $pair, $text ) );
				}
				$pair = null;
				continue;
			}
			if ( '#tag' !== $processor->get_token_type() ) {
				continue;
			}
			$tag  = $processor->get_tag();
			$pair = null;
			if ( $processor->is_tag_closer() ) {
				if ( $name && $tag === $name['tag'] ) {
					if ( ! $name['named'] && ! $name['unknown'] ) {
						self::add_issue( $issues, 'A' === $tag ? 'link_name_missing' : 'button_name_missing', $name['identity'] );
					}
					$name = null;
				}
				continue;
			}
			$id = $processor->get_attribute( 'id' );
			if ( is_string( $id ) && '' !== $id ) {
				$key = hash( 'sha256', $id );
				if ( isset( $ids[ $key ] ) ) {
					self::add_issue( $issues, 'duplicate_id', array( $key ) );
				}
				$ids[ $key ] = true;
			}
			if ( 'IMG' === $tag && null === $processor->get_attribute( 'alt' ) ) {
				self::add_issue( $issues, 'image_alt_missing', array( $processor->get_attribute( 'src' ), $processor->get_attribute( 'srcset' ) ) );
			}
			if ( $name ) {
				if ( 'IMG' === $tag ) {
					$name['named'] = $name['named'] || '' !== self::text( $processor->get_attribute( 'alt' ) ) || self::has_name( $processor );
				} elseif ( 'BR' !== $tag ) {
					// A nested/custom accessibility tree needs rendered review, not guesses.
					$name['unknown'] = true;
				}
			}
			if ( 'BUTTON' === $tag || ( 'A' === $tag && null !== $processor->get_attribute( 'href' ) ) ) {
				$name = array(
					'tag'      => $tag,
					'named'    => self::has_name( $processor ),
					'unknown'  => null !== $processor->get_attribute( 'hidden' ) || 'true' === $processor->get_attribute( 'aria-hidden' ),
					'identity' => array( $tag, $processor->get_attribute( 'href' ), $processor->get_attribute( 'id' ), $processor->get_attribute( 'type' ) ),
				);
			}
			if ( null === $processor->get_attribute( 'hidden' ) && null === $processor->get_attribute( 'disabled' ) ) {
				$pair = self::inline_pair( $processor->get_attribute( 'style' ) );
			}
		}
		$blocks = parse_blocks( $content );
		for ( $index = 0; isset( $blocks[ $index ] ); ++$index ) {
			if ( $index >= 2000 ) {
				self::add_issue( $issues, 'content_review_limit', array() );
				break;
			}
			$block = $blocks[ $index ];
			foreach ( $block['innerBlocks'] ?? array() as $child ) {
				$blocks[] = $child;
			}
			if ( ! empty( $block['innerBlocks'] ) || ! in_array( $block['blockName'], array( 'core/paragraph', 'core/heading', 'core/button', 'core/preformatted', 'core/verse' ), true ) ) {
				continue;
			}
			$style = $block['attrs']['style'] ?? array();
			if ( ! is_array( $style ) || ! is_array( $style['color'] ?? null ) || ! empty( $style['color']['gradient'] ) || ! empty( $block['attrs']['textColor'] ) || ! empty( $block['attrs']['backgroundColor'] ) ) {
				continue;
			}
			$pair = self::pair( $style['color']['text'] ?? null, $style['color']['background'] ?? null, $style['typography']['fontSize'] ?? null, $style['typography']['fontWeight'] ?? null );
			if ( ! $pair || self::contrast( $pair ) >= $pair['minimum'] ) {
				continue;
			}
			$reader = new WP_HTML_Tag_Processor( $block['innerHTML'] );
			$text   = '';
			$known  = true;
			while ( $reader->next_token() ) {
				if ( '#text' === $reader->get_token_type() ) {
					$text .= $reader->get_modifiable_text();
				} elseif ( '#tag' === $reader->get_token_type() && ! $reader->is_tag_closer() ) {
					// Inline overrides, images, scripts and dynamic naming are not block text.
					if ( null !== $reader->get_attribute( 'style' ) || null !== $reader->get_attribute( 'hidden' ) || in_array( $reader->get_tag(), array( 'IMG', 'SCRIPT', 'STYLE', 'TEMPLATE', 'SVG' ), true ) ) {
						$known = false;
						break;
					}
				}
			}
			if ( $known && '' !== self::text( $text ) ) {
				self::add_issue( $issues, 'text_contrast_low', array( $pair, self::text( $text ) ) );
			}
		}
		return $issues;
	}

	/**
	 * Check only complete, simple authored CSS declarations, never theme CSS.
	 *
	 * @param mixed $style Native style attribute.
	 * @return array|null Known opaque pair and contrast threshold.
	 */
	private static function inline_pair( $style ) {
		if ( ! is_string( $style ) || preg_match( '/[\x22\x27(){}\\\\]|\/\*/', $style ) ) {
			return null;
		}
		$values     = array();
		$priorities = array();
		foreach ( explode( ';', $style ) as $declaration ) {
			$parts = explode( ':', $declaration, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$property = strtolower( trim( $parts[0] ) );
			$value    = strtolower( trim( $parts[1] ) );
			$priority = (bool) preg_match( '/!\s*important\s*$/', $value );
			$value    = trim( preg_replace( '/!\s*important\s*$/', '', $value ) );
			if ( 'background' === $property ) {
				$property = 'background-color';
			}
			if ( ! empty( $priorities[ $property ] ) && ! $priority ) {
				continue;
			}
			$values[ $property ]     = $value;
			$priorities[ $property ] = $priority;
		}
		if ( isset( $values['all'] ) || isset( $values['font'] ) || isset( $values['background-clip'] ) || ( isset( $values['opacity'] ) && ( ! is_numeric( $values['opacity'] ) || (float) $values['opacity'] < 1 ) ) ) {
			return null;
		}
		foreach ( array( 'background-image', 'filter', 'text-shadow' ) as $property ) {
			if ( isset( $values[ $property ] ) && 'none' !== $values[ $property ] ) {
				return null;
			}
		}
		if ( 'none' === ( $values['display'] ?? '' ) || 'hidden' === ( $values['visibility'] ?? '' ) ) {
			return null;
		}
		return self::pair( $values['color'] ?? null, $values['background-color'] ?? null, $values['font-size'] ?? null, $values['font-weight'] ?? null );
	}

	/**
	 * Resolve opaque colors, using only the size-independent floor without a size.
	 *
	 * Theme/preset sizes need rendered review for ratios between 3 and 4.5.
	 * Explicit relative expressions are deferred because this is not a CSS engine.
	 *
	 * @param mixed $foreground Text color.
	 * @param mixed $background Background color.
	 * @param mixed $size Absolute font size, when specified.
	 * @param mixed $weight Explicit font weight, when specified.
	 * @return array|null
	 */
	private static function pair( $foreground, $background, $size = null, $weight = null ) {
		$foreground = self::hex( $foreground );
		$background = self::hex( $background );
		if ( ! $foreground || ! $background ) {
			return null;
		}
		$minimum = 3;
		if ( null !== $size ) {
			if ( ! is_string( $size ) || ! preg_match( '/^([0-9]+(?:\.[0-9]+)?)(px|pt)$/i', trim( $size ), $match ) ) {
				return null;
			}
			$points  = (float) $match[1] * ( 'px' === strtolower( $match[2] ) ? 0.75 : 1 );
			$bold    = 'bold' === $weight || ( is_numeric( $weight ) && (float) $weight >= 700 );
			$minimum = 4.5;
			if ( $points >= 18 || ( $bold && $points >= 14 ) ) {
				$minimum = 3;
			}
		}
		return array(
			'foreground' => $foreground,
			'background' => $background,
			'minimum'    => $minimum,
		);
	}

	/**
	 * Normalize three/six-digit hex and fully opaque four/eight-digit hex.
	 *
	 * @param mixed $value Authored color.
	 * @return string|null
	 */
	private static function hex( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^#([a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/i', trim( $value ), $match ) ) {
			return null;
		}
		$value = strtolower( $match[1] );
		if ( 3 === strlen( $value ) || 4 === strlen( $value ) ) {
			$value = preg_replace( '/(.)/', '$1$1', $value );
		}
		if ( 8 === strlen( $value ) && 'ff' !== substr( $value, 6 ) ) {
			return null;
		}
		return substr( $value, 0, 6 );
	}

	/**
	 * Calculate the WCAG sRGB contrast ratio without rounding thresholds.
	 *
	 * @param array $pair Resolved opaque colors.
	 * @return float
	 */
	private static function contrast( $pair ) {
		$luminance = array();
		foreach ( array( $pair['foreground'], $pair['background'] ) as $color ) {
			$channels = array();
			for ( $index = 0; $index < 6; $index += 2 ) {
				$value      = hexdec( substr( $color, $index, 2 ) ) / 255;
				$channels[] = $value <= 0.04045 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
			}
			$luminance[] = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
		}
		return ( max( $luminance ) + 0.05 ) / ( min( $luminance ) + 0.05 );
	}

	/**
	 * Accept explicit accessible naming without guessing referenced external text.
	 *
	 * @param WP_HTML_Tag_Processor $processor Current tag.
	 * @return bool
	 */
	private static function has_name( $processor ) {
		foreach ( array( 'aria-label', 'aria-labelledby', 'title' ) as $attribute ) {
			if ( '' !== self::text( $processor->get_attribute( $attribute ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize decoded native text, retaining real multibyte labels.
	 *
	 * @param mixed $text Decoded text or attribute.
	 * @return string
	 */
	private static function text( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}
		$normalized = preg_replace( '/[\s\p{Z}\x{200B}\x{FEFF}]+/u', ' ', $text );
		return trim( is_string( $normalized ) ? $normalized : $text );
	}

	/**
	 * Keep raw issue identities internal and count repeated unchanged defects.
	 *
	 * @param array  $issues Issue map, by reference.
	 * @param string $code Predefined issue code.
	 * @param array  $identity Relevant authored identity, never returned or logged.
	 */
	private static function add_issue( &$issues, $code, $identity ) {
		$key            = $code . ':' . hash( 'sha256', wp_json_encode( $identity ) );
		$issues[ $key ] = $issues[ $key ] ?? array(
			'code'  => $code,
			'count' => 0,
		);
		++$issues[ $key ]['count'];
	}

	/**
	 * Return only bounded predefined repair labels, never authored markup.
	 *
	 * @param array $codes Predefined issue codes.
	 * @return WP_Error
	 */
	private static function error( $codes ) {
		$labels = array(
			'image_alt_missing'    => __( 'Images need an alt attribute; use empty alt only for decoration', 'bhranti' ),
			'duplicate_id'         => __( 'Explicit HTML IDs must be unique', 'bhranti' ),
			'link_name_missing'    => __( 'Links need an accessible name, including image-only links', 'bhranti' ),
			'button_name_missing'  => __( 'Buttons need an accessible name', 'bhranti' ),
			'text_contrast_low'    => __( 'Known text/background colors need at least 3:1 contrast; explicitly smaller text needs 4.5:1. Verify theme-sized text after rendering', 'bhranti' ),
			'content_review_limit' => __( 'The content exceeds the bounded authored-content review limit', 'bhranti' ),
		);
		$codes  = array_slice( array_values( array_unique( $codes ) ), 0, 10 );
		return new WP_Error(
			'bhranti_content_quality',
			/* translators: %s: A bounded list of predefined accessibility repair labels. */
			sprintf( __( 'Fix these authored-content accessibility checks before saving: %s.', 'bhranti' ), implode( '; ', array_intersect_key( $labels, array_flip( $codes ) ) ) ),
			array(
				'status' => 422,
				'issues' => $codes,
			)
		);
	}
}
