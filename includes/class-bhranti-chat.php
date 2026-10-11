<?php
/**
 * Inert formatting for assistant replies, including native upload images.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/** Render static replies without block callbacks, shortcodes or remote images. */
final class Bhranti_Chat {

	/**
	 * Format untrusted assistant prose or static WordPress block HTML.
	 *
	 * This does not execute blocks, shortcodes, PHP, scripts or embeds. Native
	 * upload URLs come from WordPress without creating any upload directories.
	 *
	 * @param mixed $reply Assistant reply text.
	 * @return string Safe static HTML for the chat message.
	 */
	public static function render( $reply ) {
		if ( ! is_string( $reply ) || '' === $reply ) {
			return '';
		}
		$reply = wp_check_invalid_utf8( mb_strcut( $reply, 0, 240000 ), true );
		// Remove native block serialization only; never invoke dynamic renderers.
		$reply = preg_replace( '/<!--\s*\/?wp:[\s\S]*?-->/', '', $reply );
		// Literal code remains readable, including angle brackets and PHP examples.
		$reply = preg_replace_callback(
			'#<(pre|code)\b[^>]*>([\s\S]*?)</\1\s*>#i',
			static function ( $token ) {
				$content = $token[2];
				if ( 'pre' === strtolower( $token[1] ) && preg_match( '#\A\s*<code\b[^>]*>([\s\S]*?)</code>\s*\z#i', $content, $code ) ) {
					return '<pre><code>' . esc_html( $code[1] ) . '</code></pre>';
				}
				$tag = strtolower( $token[1] );
				return '<' . $tag . '>' . esc_html( $content ) . '</' . $tag . '>';
			},
			$reply
		);
		$reply = preg_replace( '#<(script|style|iframe|object|svg|math|template|noscript|form|textarea|select|button)\b[^>]*>[\s\S]*?(?:</\1\s*>|\z)#i', '', $reply );
		$reply = preg_replace( '#</?(?:script|style|iframe|object|embed|svg|math|template|noscript|form|textarea|select|button|input|link|meta|base)\b[^>]*>#i', '', $reply );
		// No assistant-supplied selectors can trigger the surrounding admin UI's CSS.
		$plain = array_fill_keys( array( 'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'small', 'sup', 'sub', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'div', 'span', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'caption' ), array() );
		$html  = array_merge(
			$plain,
			array(
				'a'   => array(
					'href'   => true,
					'title'  => true,
					'target' => true,
				),
				'img' => array(
					'src'    => true,
					'alt'    => true,
					'title'  => true,
					'width'  => true,
					'height' => true,
				),
				'th'  => array(
					'scope'   => true,
					'colspan' => true,
					'rowspan' => true,
				),
				'td'  => array(
					'colspan' => true,
					'rowspan' => true,
				),
			)
		);
		// Preserve unknown literal angle-bracket prose; KSES handles allowed tags.
		$names   = implode( '|', array_keys( $html ) );
		$reply   = preg_replace_callback( '#<(?!\!--|/?(?:' . $names . ')(?:\s|/?>))[^<>]*>#i', static fn( $token ) => esc_html( $token[0] ), $reply );
		$reply   = preg_replace( '#<(?!\!--|/?(?:' . $names . ')(?:\s|/?>))#i', '&lt;', $reply );
		$anchors = new WP_HTML_Tag_Processor( $reply );
		while ( $anchors->next_tag( 'A' ) ) {
			$href  = $anchors->get_attribute( 'href' );
			$parts = is_string( $href ) ? wp_parse_url( $href ) : false;
			if ( ! is_array( $parts ) || preg_match( '/[\\\\\x00-\x20]/', $href ) || ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https', 'mailto' ), true ) ) ) {
				$anchors->remove_attribute( 'href' );
			}
		}
		$reply   = $anchors->get_updated_html();
		$reply   = wp_kses( $reply, $html, array( 'http', 'https', 'mailto' ) );
		$uploads = wp_get_upload_dir();
		// KSES has normalized attribute delimiters before native tag inspection.
		$reply = preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( $token ) use ( $uploads ) {
				$image = new WP_HTML_Tag_Processor( $token[0] );
				if ( ! $image->next_tag( 'IMG' ) ) {
					return '';
				}
				$url = self::image_url( $image->get_attribute( 'src' ), $uploads['baseurl'] ?? '' );
				if ( '' === $url ) {
					return '';
				}
				$image->set_attribute( 'src', $url );
				$image->set_attribute( 'loading', 'lazy' );
				$image->set_attribute( 'decoding', 'async' );
				return $image->get_updated_html();
			},
			$reply
		);
		$links = new WP_HTML_Tag_Processor( $reply );
		while ( $links->next_tag( 'A' ) ) {
			$links->set_attribute( 'rel', 'noopener noreferrer' );
			if ( '_blank' !== $links->get_attribute( 'target' ) ) {
				$links->remove_attribute( 'target' );
			}
		}
		return wpautop( $links->get_updated_html() );
	}

	/**
	 * Accept raster images only beneath the configured native uploads URL.
	 *
	 * No network requests or attachment lookup queries run during rendering.
	 * Query strings, fragments and credentials are never carried to an image.
	 *
	 * @param mixed  $url Image URL from already sanitized HTML.
	 * @param string $base Native WordPress upload base URL, including CDN filters.
	 * @return string Canonical permitted URL, or an empty string.
	 */
	private static function image_url( $url, $base ) {
		if ( ! is_string( $url ) || ! is_string( $base ) || '' === $base || strlen( $url ) > 2048 || preg_match( '/[\\\\\x00-\x20]|%(?:2e|2f|5c)/i', $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		$root  = wp_parse_url( $base );
		if ( ! is_array( $parts ) || ! is_array( $root ) || empty( $root['host'] ) || ! in_array( strtolower( $root['scheme'] ?? '' ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) ) {
			return '';
		}
		if ( isset( $parts['host'] ) && ( strtolower( $parts['host'] ) !== strtolower( $root['host'] ) || ( $parts['port'] ?? null ) !== ( $root['port'] ?? null ) ) ) {
			return '';
		}
		$path   = $parts['path'] ?? '';
		$prefix = rtrim( $root['path'] ?? '', '/' ) . '/';
		if ( ! str_starts_with( $path, $prefix ) || preg_match( '#(?:\A|/)\.\.?(/|\z)#', $path ) || ! preg_match( '/\.(?:png|jpe?g|gif|webp|avif|bmp)\z/i', $path ) ) {
			return '';
		}
		return $root['scheme'] . '://' . $root['host'] . ( isset( $root['port'] ) ? ':' . $root['port'] : '' ) . $path;
	}
}
