<?php
/**
 * Native AI image generation and reviewed WordPress attachment creation.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

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
				'description' => 'Generate one image with the configured native WordPress image provider and save it to the Media Library. Use the returned attachment id and url in a reviewed post/page update. Supply a descriptive prompt, optional plain-text title and alt_text. Supports only inline PNG, JPEG or WebP images, not remote URLs or uploads. Image generation may be unavailable with the current connector. A saved image remains in Media Library after page-content rollback; there is no automatic media deletion or undo. Never repeat a completed image generation just because a later page edit fails.',
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
		$action_id = 'media_' . wp_generate_uuid4();
		$details   = array(
			'action_id'   => $action_id,
			'target_type' => 'attachment',
		);
		$logged    = self::audit( 'media_started', __( 'Native WordPress AI image generation started.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
		if ( is_wp_error( $logged ) ) {
			return $logged;
		}
		try {
			$image = Bhranti_Workspace::ai_request(
				static function () use ( $args ) {
					return wp_ai_client_prompt( $args['prompt'] )->using_request_options( RequestOptions::fromArray( array( RequestOptions::KEY_TIMEOUT => 180.0 ) ) )->generate_image();
				}
			);
		} catch ( Throwable $error ) {
			$image = self::error( 'bhranti_image_exception', __( 'Native image generation failed.', 'bhranti' ), 502 );
		}
		if ( is_wp_error( $image ) ) {
			$codes           = array( 'prompt_network_error', 'prompt_client_error', 'prompt_upstream_server_error', 'prompt_token_limit_reached', 'prompt_invalid_argument', 'prompt_builder_error', 'prompt_prevented', 'bhranti_image_exception' );
			$details['code'] = in_array( $image->get_error_code(), $codes, true ) ? $image->get_error_code() : 'bhranti_image_failed';
			$details        += Bhranti_Diagnostics::classify( $image );
			self::audit( 'media_failed', __( 'The native WordPress image provider could not complete generation.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
			return self::error( 'bhranti_image_failed', __( 'The native WordPress image provider could not generate an image. Check the image connector and logs. No image was saved.', 'bhranti' ), 502 );
		}
		try {
			$validated = self::validate_image( $image, $actor_id );
		} catch ( Throwable $error ) {
			$validated = self::error( 'bhranti_media_image', __( 'The provider returned invalid image data. No image was saved.', 'bhranti' ), 422 );
		}
		if ( is_wp_error( $validated ) ) {
			$details['code'] = $validated->get_error_code();
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
		return $result;
	}

	/**
	 * Validate bytes without downloading a provider-supplied URL.
	 *
	 * @param mixed $image Native image DTO.
	 * @param int   $actor_id Media author.
	 * @return array|WP_Error
	 */
	private static function validate_image( $image, $actor_id ) {
		if ( ! $image instanceof File || ! $image->isInline() || $image->isRemote() ) {
			return self::error( 'bhranti_media_inline', __( 'This image provider must return inline image bytes. Remote images are not downloaded. No image was saved.', 'bhranti' ), 422 );
		}
		$types = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
		);
		$mime  = $image->getMimeType();
		$limit = min( 33554432, wp_max_upload_size() );
		$data  = $image->getBase64Data();
		if ( ! isset( $types[ $mime ] ) || ! in_array( $mime, get_allowed_mime_types( $actor_id ), true ) || ! is_string( $data ) || '' === $data || $limit < 1 || strlen( $data ) > 4 * (int) ceil( $limit / 3 ) || ! preg_match( '/\A[A-Za-z0-9+\/]+={0,2}\z/', $data ) ) {
			return self::error( 'bhranti_media_image', __( 'The generated image type or size is not permitted by WordPress. No image was saved.', 'bhranti' ), 422 );
		}
		// Decode the native image DTO only as bounded raster bytes, never executable content.
		$bytes = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Native AI image DTOs contain base64 raster data; the bytes are size/MIME/dimension checked before upload.
		$info  = is_string( $bytes ) && strlen( $bytes ) <= $limit ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Malformed provider raster data must return a validation error without emitting PHP warnings.
		if ( ! is_array( $info ) || ( $info['mime'] ?? '' ) !== $mime || $info[0] < 1 || $info[1] < 1 || $info[0] > 8192 || $info[1] > 8192 || $info[0] * $info[1] > 40000000 ) {
			return self::error( 'bhranti_media_image', __( 'The provider returned invalid or oversized image bytes. No image was saved.', 'bhranti' ), 422 );
		}
		return array(
			'bytes'  => $bytes,
			'mime'   => $mime,
			'ext'    => $types[ $mime ],
			'width'  => $info[0],
			'height' => $info[1],
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
