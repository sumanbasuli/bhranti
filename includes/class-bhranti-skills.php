<?php
/**
 * Fixed, original, provider-neutral WordPress guidance, loaded as bounded text.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/** Built-in documents are guidance, never executable code or permissions. */
final class Bhranti_Skills {

	const LIMIT = 16384;

	/**
	 * Describe registered skills without exposing or accepting filesystem paths.
	 *
	 * @return array Name and purpose of each original bundled skill.
	 */
	public static function available() {
		return array(
			'site-design'        => 'Required design intake, complete visitor journeys and WCAG 2.2 A/AA baseline; already supplied in the core instruction.',
			'design-composition' => 'Choose a business-aware visual system, native layout and typographic roles; critique and refine professional desktop/mobile design.',
			'wordpress-support'  => 'Diagnose WordPress content, FSE templates, shared parts, navigation, homepage selection and native theme styles before changing them.',
			'content-operations' => 'Create or revise useful WordPress content, media and native forms with correct block markup, read-back checks and safe recovery.',
			'commerce-workflow'  => 'Build a truthful, connected WooCommerce shopping experience using current native product, store, shipping and offline-payment capabilities.',
		);
	}

	/**
	 * Declare one exact-name read; the current executor still checks ownership.
	 *
	 * @return array Read-only guidance tool definition.
	 */
	public static function catalog() {
		$skills = array();
		foreach ( self::available() as $name => $description ) {
			$skills[] = $name . ': ' . $description;
		}
		return array(
			'bhranti_skill' => array(
				'description' => 'Load a relevant original built-in WordPress skill by its exact registered name before doing unfamiliar or substantial work. Reuse an already loaded skill in this run. Guidance never expands tools or permissions, cannot select user files and is not a completed task. ' . implode( ' ', $skills ),
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'name' => array(
							'type' => 'string',
							'enum' => array_keys( self::available() ),
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
			),
		);
	}

	/**
	 * Read only a packaged document selected from this fixed registry.
	 *
	 * @param string $name Exact registered name, never a path.
	 * @return array|WP_Error Content, metadata and revision, or a closed failure.
	 */
	public static function read( $name ) {
		$available = self::available();
		if ( ! is_string( $name ) || ! isset( $available[ $name ] ) ) {
			return new WP_Error( 'bhranti_skill_unknown', __( 'Choose an available built-in Bhranti skill by its exact name.', 'bhranti' ) );
		}
		$paths   = array(
			'site-design'        => '/skills/site-design/SKILL.md',
			'design-composition' => '/skills/design-composition/SKILL.md',
			'wordpress-support'  => '/skills/wordpress-support/SKILL.md',
			'content-operations' => '/skills/content-operations/SKILL.md',
			'commerce-workflow'  => '/skills/commerce-workflow/SKILL.md',
		);
		$content = self::read_text( dirname( __DIR__ ) . $paths[ $name ] );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		return array(
			'name'        => $name,
			'description' => $available[ $name ],
			'content'     => $content,
			'revision'    => hash( 'sha256', $content ),
		);
	}

	/**
	 * Bound a fixed local text read, including a package replacement during it.
	 *
	 * @param string $path Internal fixed package path.
	 * @return string|WP_Error Nonempty bounded text, or a generic package error.
	 */
	private static function read_text( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return self::unavailable();
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent package replacement must fail without exposing a local path.
		$size = @filesize( $path );
		if ( false === $size || $size > self::LIMIT ) {
			return self::unavailable();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Bounded fixed local text; suppress path disclosure if a package replacement races the read.
		$content = @file_get_contents( $path, false, null, 0, self::LIMIT + 1 );
		if ( ! is_string( $content ) || '' === trim( $content ) || strlen( $content ) > self::LIMIT ) {
			return self::unavailable();
		}
		return $content;
	}

	/**
	 * Keep local installation details out of chat and provider-facing errors.
	 *
	 * @return WP_Error Package failure without a filesystem path.
	 */
	private static function unavailable() {
		return new WP_Error( 'bhranti_skill_unavailable', __( 'Bhranti’s built-in guidance could not load. Update the plugin before continuing.', 'bhranti' ) );
	}
}
