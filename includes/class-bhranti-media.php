<?php
/**
 * Native AI image generation and reviewed WordPress attachment creation.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

defined( 'ABSPATH' ) || exit;

/** Save only bounded inline images from the native WordPress connector. */
final class Bhranti_Media {

	/**
	 * Describe image generation when the current media policy permits it.
	 *
	 * @return array
	 */
	public static function catalog() {
		if ( true !== Bhranti_Settings::allows( 'manage_media' ) ) {
			return array();
		}
		return array(
			'generate_image' => array(
				'description' => 'Generate one image with the configured native WordPress image provider and save it to the Media Library. Use the returned attachment id and url in a reviewed post/page update. Supply a descriptive prompt, optional plain-text title and alt_text, and optional format png/jpeg/webp. Current WordPress upload and image-editor support is checked before generation. A bounded raster may be converted or resized locally to meet the current upload limit, without another AI request. Supports only inline PNG, JPEG or WebP images, not remote URLs or uploads. Image generation may be unavailable with the current connector. If the native check returns bhranti_image_support, do not repeat the unsupported request: reuse suitable user-approved Media Library images or ask one concise question for the needed images, suggesting Media → Add New when they must be placed on the site. Chat reference attachments provide visual context and are not automatically saved as usable Media Library assets. Continue independent authorized work before waiting if it does not depend on the missing images. A discovery or provider failure does not prove permanent lack of image support. Never fabricate photos, attachment IDs or URLs. A saved image remains in Media Library after page-content rollback; there is no automatic media deletion or undo. Never repeat a completed image generation just because a later page edit fails.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'prompt'   => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 2000,
						),
						'title'    => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'alt_text' => array(
							'type'      => 'string',
							'maxLength' => 500,
						),
						'format'   => array(
							'type' => 'string',
							'enum' => array( 'png', 'jpeg', 'webp' ),
						),
					),
					'required'             => array( 'prompt' ),
					'additionalProperties' => false,
				),
			),
		);
	}

	/**
	 * Generate as the human connector identity and save as the managed actor.
	 *
	 * @param array $command Reviewed tool envelope.
	 * @param int   $requester_id Human requester.
	 * @param int   $session_id Owned private conversation.
	 * @return array|WP_Error
	 */
	public static function execute( $command, $requester_id, $session_id ) {
		$owner = self::owner( $requester_id, $session_id );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$allowed = Bhranti_Settings::allows( 'manage_media' );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$catalog = self::catalog();
		if ( ! is_array( $command ) || array_diff( array_keys( $command ), array( 'tool', 'args' ) ) || 'generate_image' !== ( $command['tool'] ?? '' ) || ! is_array( $command['args'] ?? null ) || ! isset( $catalog['generate_image'] ) || is_wp_error( rest_validate_value_from_schema( $command['args'], $catalog['generate_image']['parameters'], 'args' ) ) ) {
			return self::error( 'bhranti_media_invalid', __( 'Use one descriptive image prompt and optional plain-text title and alt text.', 'bhranti' ), 400 );
		}
		$args = $command['args'];
		foreach ( $args as $value ) {
			if ( ! is_string( $value ) || wp_check_invalid_utf8( $value ) !== $value ) {
				return self::error( 'bhranti_media_invalid', __( 'Image prompt and labels must be valid UTF-8 text.', 'bhranti' ), 400 );
			}
		}
		if ( '' === trim( $args['prompt'] ) || mb_strlen( $args['prompt'] ) > 2000 || mb_strlen( $args['title'] ?? '' ) > 200 || mb_strlen( $args['alt_text'] ?? '' ) > 500 ) {
			return self::error( 'bhranti_media_invalid', __( 'The image prompt or labels exceed their permitted length.', 'bhranti' ), 400 );
		}
		$actor_id = Bhranti_Actions::ensure_actor();
		if ( is_wp_error( $actor_id ) ) {
			return $actor_id;
		}
		$permission = self::permission( $requester_id, $actor_id, $session_id );
		if ( is_wp_error( $permission ) ) {
			self::audit( 'media_blocked', __( 'Native image generation was blocked.', 'bhranti' ), $session_id, array( 'code' => $permission->get_error_code() ), $requester_id, $actor_id );
			return $permission;
		}
		$action_id    = 'media_' . wp_generate_uuid4();
		$details      = array(
			'action_id'   => $action_id,
			'target_type' => 'attachment',
		);
		$capabilities = self::capabilities( $requester_id, $actor_id );
		self::observe( $requester_id, $capabilities );
		$preflight = self::preflight( $args, $capabilities );
		if ( is_wp_error( $preflight ) ) {
			$details['code'] = $preflight->get_error_code();
			self::audit( 'media_blocked', __( 'The site cannot accept the requested image format or size.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
			return $preflight;
		}
		$logged = self::audit( 'media_started', __( 'Native WordPress AI image generation started.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
		if ( is_wp_error( $logged ) ) {
			return $logged;
		}
		try {
			$image = Bhranti_Workspace::ai_request(
				static function () use ( $args, $capabilities ) {
					$builder = self::image_prompt( $args, $capabilities );
					return is_wp_error( $builder ) ? $builder : $builder->generate_image_result();
				}
			);
		} catch ( Throwable $error ) {
			$image = self::error( 'bhranti_image_exception', __( 'Native image generation failed.', 'bhranti' ), 502 );
		}
		if ( is_wp_error( $image ) ) {
			if ( in_array( $image->get_error_code(), array( 'bhranti_image_support', 'bhranti_image_discovery' ), true ) ) {
				$details['code'] = $image->get_error_code();
				self::audit( 'bhranti_image_support' === $details['code'] ? 'media_blocked' : 'media_failed', $image->get_error_message(), $session_id, $details, $requester_id, $actor_id );
				return $image;
			}
			$codes           = array( 'prompt_network_error', 'prompt_client_error', 'prompt_upstream_server_error', 'prompt_token_limit_reached', 'prompt_invalid_argument', 'prompt_builder_error', 'prompt_prevented', 'bhranti_image_exception' );
			$details['code'] = in_array( $image->get_error_code(), $codes, true ) ? $image->get_error_code() : 'bhranti_image_failed';
			$details        += Bhranti_Diagnostics::classify( $image );
			self::audit( 'media_failed', __( 'The native WordPress image provider could not complete generation.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
			return self::error( 'bhranti_image_failed', __( 'The native WordPress image provider could not generate an image. Check the image connector and logs. No image was saved.', 'bhranti' ), 502 );
		}
		$provider_id = '';
		$model_id    = '';
		try {
			if ( $image instanceof GenerativeAiResult ) {
				$provider_id = $image->getProviderMetadata()->getId();
				$model_id    = $image->getModelMetadata()->getId();
				$image       = $image->toImageFile();
			}
			$validated = self::validate_image( $image, $actor_id, $requester_id, $args );
		} catch ( Throwable $error ) {
			$validated = self::error( 'bhranti_media_image', __( 'The provider returned invalid image data. No image was saved.', 'bhranti' ), 422 );
		}
		if ( is_wp_error( $validated ) ) {
			$details['code'] = $validated->get_error_code();
			$failure         = $validated->get_error_data();
			if ( is_array( $failure ) && isset( $failure['media_reason'] ) ) {
				$details['reason'] = $failure['media_reason'];
				self::observe( $requester_id, self::capabilities( $requester_id, $actor_id ), $failure['media_reason'], $provider_id, $model_id );
			}
			self::audit( 'media_failed', __( 'The generated image did not pass media validation.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
			return $validated;
		}
		$logged = self::audit( 'media_saving', __( 'Saving a validated generated image to the native Media Library.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
		if ( is_wp_error( $logged ) ) {
			return $logged;
		}
		$permission = self::permission( $requester_id, $actor_id, $session_id );
		if ( is_wp_error( $permission ) ) {
			$details['code'] = $permission->get_error_code();
			self::audit( 'media_blocked', __( 'Media permission changed before the generated image was saved.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
			return $permission;
		}
		$previous_user = get_current_user_id();
		try {
			wp_set_current_user( $actor_id );
			$result = self::save_image( $validated, $args, $action_id, $requester_id, $actor_id, $session_id );
		} catch ( Throwable $error ) {
			$result = self::error( 'bhranti_media_storage', __( 'The image save was interrupted. Review Media Library and the logs before retrying.', 'bhranti' ), 500 );
		} finally {
			wp_set_current_user( $previous_user );
		}
		if ( is_wp_error( $result ) ) {
			$details['code'] = $result->get_error_code();
			self::audit( 'media_failed', __( 'The generated image could not be fully saved. Review Media Library before retrying.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
			return $result;
		}
		self::observe(
			$requester_id,
			self::capabilities( $requester_id, $actor_id ),
			'success',
			$provider_id,
			$model_id,
			array(
				'mime_type' => $validated['mime'],
				'bytes'     => strlen( $validated['bytes'] ),
				'width'     => $validated['width'],
				'height'    => $validated['height'],
			)
		);
		return $result;
	}

	/**
	 * Read current native media limits; earlier runs never override this runtime.
	 *
	 * @param int $requester_id Human requester.
	 * @param int $actor_id Managed uploader.
	 * @return array Nonsecret, freshly verified site capabilities.
	 */
	public static function capabilities( $requester_id, $actor_id ) {
		$formats = array();
		$allowed = array();
		$editors = array();
		$human   = get_allowed_mime_types( $requester_id );
		$actor   = get_allowed_mime_types( $actor_id );
		foreach ( array(
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/jpeg' => 'jpeg',
		) as $mime => $format ) {
			if ( in_array( $mime, $human, true ) && in_array( $mime, $actor, true ) ) {
				$allowed[] = $mime;
			}
			if ( wp_image_editor_supports(
				array(
					'mime_type' => $mime,
					'methods'   => array( 'load', 'save', 'resize' ),
				)
			) ) {
				$editors[] = $mime;
			}
			if ( in_array( $mime, $allowed, true ) && in_array( $mime, $editors, true ) ) {
				$formats[ $mime ] = $format;
			}
		}
		return array(
			'formats'            => $formats,
			'allowed_mime_types' => $allowed,
			'editor_mime_types'  => $editors,
			'max_upload_bytes'   => max( 0, min( 33554432, (int) wp_max_upload_size() ) ),
			'runtime'            => PHP_SAPI,
		);
	}

	/**
	 * Remember bounded native observations without changing the media outcome.
	 *
	 * @param int    $requester_id Human requester.
	 * @param array  $capabilities Fresh runtime capabilities.
	 * @param string $outcome Verified finite outcome.
	 * @param string $provider_id Native metadata identifier, when known.
	 * @param string $model_id Native metadata identifier, when known.
	 * @param array  $image Verified successful output facts, never raw bytes.
	 */
	private static function observe( $requester_id, $capabilities, $outcome = 'checked', $provider_id = '', $model_id = '', $image = array() ) {
		if ( ! class_exists( 'Bhranti_Memory' ) ) {
			return;
		}
		unset( $capabilities['formats'] );
		try {
			Bhranti_Memory::observe_media( $requester_id, $capabilities, $outcome, $provider_id, $model_id, $image );
		} catch ( Throwable $error ) {
			// Memory is advisory; a storage failure must never replay a completed image action.
			return;
		}
	}

	/**
	 * Reject impossible site/format requests before native AI discovery or generation.
	 *
	 * @param array $args Reviewed image arguments.
	 * @param array $capabilities Fresh native media capabilities.
	 * @return true|WP_Error
	 */
	private static function preflight( $args, $capabilities ) {
		if ( ! $capabilities['formats'] || $capabilities['max_upload_bytes'] < 1 ) {
			return self::error( 'bhranti_media_format', __( 'WordPress cannot currently process an allowed image format within its upload limit. No image generation was requested.', 'bhranti' ), 422 );
		}
		if ( isset( $args['format'] ) && ! in_array( $args['format'], $capabilities['formats'], true ) ) {
			return self::error( 'bhranti_media_format', __( 'The requested image format is not supported by this site. Choose a currently supported format. No image generation was requested.', 'bhranti' ), 422 );
		}
		return true;
	}

	/**
	 * Ask the native client for a supported output type, without changing preferences.
	 *
	 * @param array $args Reviewed image arguments.
	 * @param array $capabilities Fresh native media capabilities.
	 * @return WP_AI_Client_Prompt_Builder|WP_Error
	 */
	private static function image_prompt( $args, $capabilities ) {
		$formats = $capabilities['max_upload_bytes'] <= 4194304 ? array( 'image/webp', 'image/jpeg', 'image/png' ) : array( 'image/png', 'image/webp', 'image/jpeg' );
		foreach ( $formats as $mime ) {
			if ( ! isset( $capabilities['formats'][ $mime ] ) || ( isset( $args['format'] ) && $args['format'] !== $capabilities['formats'][ $mime ] ) ) {
				continue;
			}
			$builder = wp_ai_client_prompt( $args['prompt'] )->as_output_mime_type( $mime )->using_request_options( RequestOptions::fromArray( array( RequestOptions::KEY_TIMEOUT => 180.0 ) ) );
			$support = $builder->is_supported_for_image_generation();
			if ( ! is_bool( $support ) ) {
				return self::discovery_error();
			}
			if ( ! $support ) {
				continue;
			}
			$inline = wp_ai_client_prompt( $args['prompt'] )->as_output_mime_type( $mime )->as_output_file_type( FileTypeEnum::inline() )->using_request_options( RequestOptions::fromArray( array( RequestOptions::KEY_TIMEOUT => 180.0 ) ) );
			return true === $inline->is_supported_for_image_generation() ? $inline : $builder;
		}
		// Older providers may generate images without advertising an output-format option.
		// Their one result must still pass the same native raster preparation and limits.
		$builder = wp_ai_client_prompt( $args['prompt'] )->using_request_options( RequestOptions::fromArray( array( RequestOptions::KEY_TIMEOUT => 180.0 ) ) );
		$support = $builder->is_supported_for_image_generation();
		if ( ! is_bool( $support ) ) {
			return self::discovery_error();
		}
		if ( ! $support ) {
			return self::error( 'bhranti_image_support', __( 'Image generation is not available for this request. Choose suitable images from your Media Library or upload them through Media → Add New, then tell Bhranti which to use. No image was generated.', 'bhranti' ), 422 );
		}
		$inline = wp_ai_client_prompt( $args['prompt'] )->as_output_file_type( FileTypeEnum::inline() )->using_request_options( RequestOptions::fromArray( array( RequestOptions::KEY_TIMEOUT => 180.0 ) ) );
		return true === $inline->is_supported_for_image_generation() ? $inline : $builder;
	}

	/** Keep a failed native capability lookup distinct from confirmed unavailability. */
	private static function discovery_error() {
		// Native support checks can return the builder after a caught metadata exception.
		return self::error( 'bhranti_image_discovery', __( 'WordPress could not check image generation right now. You can use suitable Media Library images or upload your own instead. No image generation was requested.', 'bhranti' ), 502 );
	}

	/**
	 * Validate bytes without downloading a provider-supplied URL.
	 *
	 * @param mixed $image Native image DTO.
	 * @param int   $actor_id Media author.
	 * @param int   $requester_id Human requester.
	 * @param array $args Reviewed output preference.
	 * @return array|WP_Error
	 */
	private static function validate_image( $image, $actor_id, $requester_id = 0, $args = array() ) {
		if ( ! $image instanceof File || ! $image->isInline() || $image->isRemote() ) {
			return self::error( 'bhranti_media_inline', __( 'This image provider must return inline image bytes. Remote images are not downloaded. No image was saved.', 'bhranti' ), 422 );
		}
		$types = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
		);
		$mime  = $image->getMimeType();
		$limit = 33554432;
		$data  = $image->getBase64Data();
		if ( ! isset( $types[ $mime ] ) || ! is_string( $data ) || '' === $data || strlen( $data ) > 4 * (int) ceil( $limit / 3 ) || ! preg_match( '/\A[A-Za-z0-9+\/]+={0,2}\z/', $data ) ) {
			$reason = ! isset( $types[ $mime ] ) ? 'mime' : ( is_string( $data ) && strlen( $data ) > 4 * (int) ceil( $limit / 3 ) ? 'limit' : 'metadata' );
			return self::image_error( $reason, __( 'The generated image type, data or size does not meet the safe image-processing limits. No image was saved. Do not regenerate automatically.', 'bhranti' ) );
		}
		// Decode the native image DTO only as bounded raster bytes, never executable content.
		$bytes = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Native AI image DTOs contain base64 raster data; the bytes are size/MIME/dimension checked before upload.
		$info  = is_string( $bytes ) && strlen( $bytes ) <= $limit ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Malformed provider raster data must return a validation error without emitting PHP warnings.
		if ( ! is_array( $info ) || ( $info['mime'] ?? '' ) !== $mime ) {
			return self::image_error( 'mime', __( 'The generated image bytes do not match their declared format. No image was saved. Do not regenerate automatically.', 'bhranti' ) );
		}
		if ( $info[0] < 1 || $info[1] < 1 || $info[0] > 8192 || $info[1] > 8192 || $info[0] * $info[1] > 40000000 ) {
			return self::image_error( 'dimensions', __( 'The generated image exceeds the safe pixel dimensions. No image was saved. Do not regenerate automatically.', 'bhranti' ) );
		}
		$validated    = array(
			'bytes'  => $bytes,
			'mime'   => $mime,
			'ext'    => $types[ $mime ],
			'width'  => $info[0],
			'height' => $info[1],
		);
		$capabilities = self::capabilities( $requester_id ? $requester_id : get_current_user_id(), $actor_id );
		$preflight    = self::preflight( $args, $capabilities );
		if ( is_wp_error( $preflight ) ) {
			return self::error( 'bhranti_media_format', __( 'WordPress image permissions or upload limits changed during generation. No image was saved. Do not regenerate automatically.', 'bhranti' ), 422 );
		}
		if ( isset( $capabilities['formats'][ $mime ] ) && strlen( $bytes ) <= $capabilities['max_upload_bytes'] && ( ! isset( $args['format'] ) || $args['format'] === $capabilities['formats'][ $mime ] ) ) {
			return $validated;
		}
		return self::convert_image( $validated, $args, $capabilities );
	}

	/**
	 * Convert one bounded result locally; never spend another provider request.
	 *
	 * @param array $image Verified source bytes and dimensions.
	 * @param array $args Reviewed output preference.
	 * @param array $capabilities Fresh native media limits.
	 * @return array|WP_Error
	 */
	private static function convert_image( $image, $args, $capabilities ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$types = array(
			'image/webp' => 'webp',
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
		);
		$mime  = '';
		foreach ( $types as $candidate => $extension ) {
			if ( isset( $capabilities['formats'][ $candidate ] ) && ( ! isset( $args['format'] ) || $args['format'] === $capabilities['formats'][ $candidate ] ) ) {
				$mime = $candidate;
				break;
			}
		}
		if ( ! $mime ) {
			return self::image_error( 'mime', __( 'WordPress has no permitted output format for this image. No image was saved. Do not regenerate automatically.', 'bhranti' ) );
		}
		$directory = trailingslashit( get_temp_dir() ) . 'bhranti-image-' . wp_generate_uuid4();
		if ( file_exists( $directory ) || is_link( $directory ) || ! mkdir( $directory, 0700 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Reserve a fresh private temporary directory atomically; WordPress image editors otherwise inherit public temporary-file permissions.
			return self::error( 'bhranti_media_storage', __( 'WordPress could not reserve temporary image storage. No image was saved.', 'bhranti' ), 500 );
		}
		$source = wp_tempnam( 'image', trailingslashit( $directory ) );
		if ( ! $source || dirname( $source ) !== $directory || ! is_file( $source ) || is_link( $source ) ) {
			rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Only this operation's newly created empty directory.
			return self::error( 'bhranti_media_storage', __( 'WordPress could not reserve a temporary image file. No image was saved.', 'bhranti' ), 500 );
		}
		$destination = $source . '.' . $types[ $mime ];
		$owned       = array( $source );
		try {
			if ( file_exists( $destination ) || is_link( $destination ) ) {
				return self::error( 'bhranti_media_storage', __( 'The temporary image destination already exists. No file was overwritten.', 'bhranti' ), 409 );
			}
			$owned[] = $destination;
			if ( file_put_contents( $source, $image['bytes'] ) !== strlen( $image['bytes'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only the bounded generated raster to WordPress's exclusively reserved temporary file.
				return self::error( 'bhranti_media_storage', __( 'WordPress could not prepare the temporary image. No image was saved.', 'bhranti' ), 500 );
			}
			$editor = wp_get_image_editor(
				$source,
				array(
					'mime_type'        => $image['mime'],
					'output_mime_type' => $mime,
				)
			);
			if ( is_wp_error( $editor ) || is_wp_error( $editor->set_quality( 82 ) ) ) {
				return self::image_error( 'support', __( 'WordPress could not convert the generated image locally. No image was saved. Do not regenerate automatically.', 'bhranti' ) );
			}
			for ( $attempt = 0; $attempt < 6; ++$attempt ) {
				$saved = $editor->save( $destination, $mime );
				if ( is_array( $saved ) && is_string( $saved['path'] ?? null ) && dirname( $saved['path'] ) === $directory && preg_match( '/\A' . preg_quote( wp_basename( $source ), '/' ) . '\.(?:png|jpg|jpeg|webp|avif|gif)\z/', wp_basename( $saved['path'] ) ) ) {
					$owned[] = $saved['path'];
				}
				clearstatcache( true, $destination );
				if ( is_wp_error( $saved ) || ( $saved['path'] ?? '' ) !== $destination || ( $saved['mime-type'] ?? '' ) !== $mime || ! is_file( $destination ) || is_link( $destination ) || filesize( $destination ) > 33554432 ) {
					return self::image_error( 'metadata', __( 'The locally converted image could not be verified. No image was saved. Do not regenerate automatically.', 'bhranti' ) );
				}
				if ( filesize( $destination ) <= $capabilities['max_upload_bytes'] ) {
					$bytes = file_get_contents( $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only this operation's bounded native image-editor output.
					$info  = is_string( $bytes ) ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Malformed native output fails validation without PHP warnings.
					if ( is_array( $info ) && ( $info['mime'] ?? '' ) === $mime && $info[0] >= 1 && $info[1] >= 1 && $info[0] <= $image['width'] && $info[1] <= $image['height'] && strlen( $bytes ) <= $capabilities['max_upload_bytes'] ) {
						return array(
							'bytes'  => $bytes,
							'mime'   => $mime,
							'ext'    => $types[ $mime ],
							'width'  => $info[0],
							'height' => $info[1],
						);
					}
					return self::image_error( 'metadata', __( 'The converted image data could not be verified. No image was saved. Do not regenerate automatically.', 'bhranti' ) );
				}
				$size = $editor->get_size();
				if ( $attempt >= 5 || max( $size['width'], $size['height'] ) < 2 || is_wp_error( $editor->resize( max( 1, (int) floor( $size['width'] * 0.7 ) ), max( 1, (int) floor( $size['height'] * 0.7 ) ), false ) ) ) {
					break;
				}
			}
			return self::image_error( 'limit', __( 'The generated image could not fit the current WordPress upload limit after local conversion. No image was saved. Do not regenerate automatically.', 'bhranti' ) );
		} finally {
			foreach ( array_unique( $owned ) as $file ) {
				if ( is_file( $file ) && ! is_link( $file ) ) {
					wp_delete_file( $file );
				}
			}
			rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Only this operation's newly created directory after its owned temporary files are removed.
		}
	}

	/**
	 * Keep a finite verified validation reason, without retaining provider data.
	 *
	 * @param string $reason Bounded media outcome.
	 * @param string $message Safe public message.
	 * @return WP_Error
	 */
	private static function image_error( $reason, $message ) {
		return new WP_Error(
			'bhranti_media_image',
			$message,
			array(
				'status'       => 422,
				'media_reason' => $reason,
			)
		);
	}

	/**
	 * Create a fresh UUID-named attachment through native WordPress media APIs.
	 *
	 * @param array  $image Validated bytes and dimensions.
	 * @param array  $args Display labels.
	 * @param string $action_id Audited operation.
	 * @param int    $requester_id Human requester.
	 * @param int    $actor_id Attachment author.
	 * @param int    $session_id Conversation.
	 * @return array|WP_Error
	 */
	private static function save_image( $image, $args, $action_id, $requester_id, $actor_id, $session_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$capabilities = self::capabilities( $requester_id, $actor_id );
		if ( ! isset( $capabilities['formats'][ $image['mime'] ] ) || strlen( $image['bytes'] ) > $capabilities['max_upload_bytes'] ) {
			return self::image_error( 'limit', __( 'WordPress image permissions or upload limits changed before saving. No image was saved. Do not regenerate automatically.', 'bhranti' ) );
		}
		$name      = 'bhranti-' . substr( $action_id, 6 ) . '.' . $image['ext'];
		$directory = wp_upload_dir();
		if ( ! empty( $directory['error'] ) || empty( $directory['path'] ) ) {
			return self::error( 'bhranti_media_storage', __( 'The WordPress upload directory is unavailable.', 'bhranti' ), 500 );
		}
		$expected = trailingslashit( $directory['path'] ) . $name;
		if ( file_exists( $expected ) || is_link( $expected ) ) {
			return self::error( 'bhranti_media_storage', __( 'A generated image filename already exists. No image was overwritten.', 'bhranti' ), 409 );
		}
		$file      = '';
		$id        = 0;
		$metadata  = array();
		$completed = false;
		try {
			$upload = wp_upload_bits( $name, null, $image['bytes'] );
			if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || ! is_string( $upload['file'] ?? null ) ) {
				return self::error( 'bhranti_media_storage', __( 'WordPress could not save the generated image file.', 'bhranti' ), 500 );
			}
			$file = $upload['file'];
			if ( ! self::owned_file( $file, $expected ) ) {
				return self::error( 'bhranti_media_storage', __( 'The native upload returned an unexpected file location.', 'bhranti' ), 500 );
			}
			$checked = wp_check_filetype_and_ext( $file, $name, get_allowed_mime_types( $actor_id ) );
			if ( ( $checked['type'] ?? '' ) !== $image['mime'] || ( $checked['ext'] ?? '' ) !== $image['ext'] || ! is_file( $file ) || is_link( $file ) || filesize( $file ) !== strlen( $image['bytes'] ) || ! hash_equals( hash( 'sha256', $image['bytes'] ), hash_file( 'sha256', $file ) ) ) {
				return self::error( 'bhranti_media_storage', __( 'The native upload result could not be verified. Review Media Library before retrying.', 'bhranti' ), 500 );
			}
			$title  = sanitize_text_field( $args['title'] ?? '' );
			$title  = '' !== $title ? $title : __( 'Generated image', 'bhranti' );
			$alt    = sanitize_text_field( $args['alt_text'] ?? '' );
			$record = array(
				'action_id'    => $action_id,
				'requester_id' => (int) $requester_id,
				'session_id'   => (int) $session_id,
			);
			$id     = wp_insert_attachment(
				wp_slash(
					array(
						'post_title'     => $title,
						'post_mime_type' => $image['mime'],
						'post_status'    => 'inherit',
						'post_author'    => $actor_id,
						'post_content'   => '',
						'meta_input'     => array(
							'_wp_attachment_image_alt' => $alt,
							'bhranti_generated_media'  => $record,
						),
					)
				),
				$file,
				0,
				true
			);
			if ( is_wp_error( $id ) || ! $id ) {
				return self::error( 'bhranti_media_storage', __( 'WordPress could not create the generated image attachment.', 'bhranti' ), 500 );
			}
			$metadata = wp_generate_attachment_metadata( $id, $file );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( $id, $metadata );
			}
			clean_post_cache( $id );
			$post = get_post( $id );
			$url  = wp_get_attachment_url( $id );
			if ( ! $post || 'attachment' !== $post->post_type || (int) $post->post_author !== (int) $actor_id || $image['mime'] !== $post->post_mime_type || get_post_meta( $id, 'bhranti_generated_media', true ) !== $record || get_post_meta( $id, '_wp_attachment_image_alt', true ) !== $alt || ! is_array( $metadata ) || empty( $metadata['width'] ) || empty( $metadata['height'] ) || wp_get_attachment_metadata( $id ) !== $metadata || ! $url || ! self::owned_file( get_attached_file( $id ), $expected ) ) {
				return self::error( 'bhranti_media_storage', __( 'The generated attachment could not be verified. Review Media Library before retrying.', 'bhranti' ), 500 );
			}
			$summary = __( 'Generated image saved to Media Library. Page rollback leaves this image in the library.', 'bhranti' );
			$logged  = self::audit(
				'media_completed',
				$summary,
				$session_id,
				array(
					'action_id'   => $action_id,
					'target_id'   => $id,
					'target_type' => 'attachment',
				),
				$requester_id,
				$actor_id
			);
			if ( is_wp_error( $logged ) ) {
				return $logged;
			}
			$completed = true;
			return array(
				'summary'            => $summary,
				'data'               => array(
					'id'        => (int) $id,
					'url'       => $url,
					'mime_type' => $post->post_mime_type,
					'width'     => $metadata['width'],
					'height'    => $metadata['height'],
					'title'     => $post->post_title,
					'alt_text'  => $alt,
				),
				'target'             => array(
					'id'    => (int) $id,
					'type'  => 'attachment',
					'title' => $post->post_title,
					'url'   => admin_url( 'post.php?post=' . $id . '&action=edit' ),
				),
				'action_id'          => $action_id,
				'rollback_available' => false,
			);
		} finally {
			if ( ! $completed ) {
				// Remove only this request's fresh UUID-named attachment, never existing media.
				$post = is_int( $id ) && $id ? get_post( $id ) : null;
				if ( $post && 'attachment' === $post->post_type && (int) $post->post_author === (int) $actor_id && self::owned_file( get_attached_file( $id ), $expected ) ) {
					wp_delete_attachment( $id, true );
				}
				if ( ! $post || ! get_post( $id ) ) {
					$files = array( $file, $expected );
					foreach ( is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array() as $size ) {
						if ( is_string( $size['file'] ?? null ) ) {
							$files[] = trailingslashit( dirname( $expected ) ) . $size['file'];
						}
					}
					if ( is_string( $metadata['file'] ?? null ) ) {
						$files[] = trailingslashit( dirname( $expected ) ) . wp_basename( $metadata['file'] );
					}
					foreach ( array_unique( $files ) as $created_file ) {
						if ( self::owned_file( $created_file, $expected ) && is_file( $created_file ) ) {
							wp_delete_file( $created_file );
						}
					}
				}
			}
		}
	}

	/**
	 * Restrict verification and cleanup to this request's upload directory and UUID.
	 *
	 * @param string $file Candidate native upload or derived image.
	 * @param string $expected Reserved original filename.
	 * @return bool
	 */
	private static function owned_file( $file, $expected ) {
		if ( ! is_string( $file ) || '' === $file || is_link( $file ) || wp_normalize_path( dirname( $file ) ) !== wp_normalize_path( dirname( $expected ) ) ) {
			return false;
		}
		$stem = pathinfo( $expected, PATHINFO_FILENAME );
		$ext  = pathinfo( $expected, PATHINFO_EXTENSION );
		return 1 === preg_match( '/\A' . preg_quote( $stem, '/' ) . '(?:-[a-z0-9]+)*\.' . preg_quote( $ext, '/' ) . '\z/', wp_basename( $file ) );
	}

	/**
	 * Check session ownership under the authenticated human account.
	 *
	 * @param int $requester_id Human requester.
	 * @param int $session_id Conversation.
	 * @return true|WP_Error
	 */
	private static function owner( $requester_id, $session_id ) {
		clean_post_cache( $session_id );
		$post = get_post( $session_id );
		if ( get_current_user_id() !== (int) $requester_id || ! Bhranti_Settings::can_manage() || ! $post || 'bhranti_session' !== $post->post_type || 'private' !== $post->post_status || (int) $post->post_author !== (int) $requester_id ) {
			return self::error( 'bhranti_media_owner', __( 'This image request is not available to your account.', 'bhranti' ), 403 );
		}
		return true;
	}

	/**
	 * Recheck current policy, identity and native upload capability before saving.
	 *
	 * @param int $requester_id Human requester.
	 * @param int $actor_id Managed actor.
	 * @param int $session_id Conversation.
	 * @return true|WP_Error
	 */
	private static function permission( $requester_id, $actor_id, $session_id ) {
		clean_user_cache( $requester_id );
		clean_user_cache( $actor_id );
		wp_cache_delete( 'bhranti_actor_id', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$owner = self::owner( $requester_id, $session_id );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$allowed = Bhranti_Settings::allows( 'manage_media' );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( (int) get_option( 'bhranti_actor_id' ) !== (int) $actor_id || (int) get_user_meta( $actor_id, 'bhranti_managed_actor', true ) !== get_current_blog_id() || ! user_can( $requester_id, 'manage_options' ) || ! user_can( $requester_id, 'upload_files' ) || ! user_can( $actor_id, 'upload_files' ) ) {
			return self::error( 'bhranti_media_permission', __( 'Both your account and Bhranti must have native WordPress upload permission.', 'bhranti' ), 403 );
		}
		return true;
	}

	/**
	 * Persist safe operation metadata without image prompts, bytes or provider payloads.
	 *
	 * @param string $type Event code.
	 * @param string $summary Public summary.
	 * @param int    $session_id Conversation.
	 * @param array  $details Safe action metadata.
	 * @param int    $requester_id Human requester.
	 * @param int    $actor_id Executing identity.
	 * @return int|WP_Error
	 */
	private static function audit( $type, $summary, $session_id, $details, $requester_id, $actor_id ) {
		return Bhranti_Audit::record( $type, $summary, $session_id, $details, $requester_id, $actor_id );
	}

	/**
	 * Return a safe public error.
	 *
	 * @param string $code Stable diagnostic.
	 * @param string $message Public message.
	 * @param int    $status HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
