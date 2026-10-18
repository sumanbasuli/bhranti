<?php
/**
 * Bounded Contact Form 7 definitions, using its native saved-form API.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/** Keep form design separate from submissions, mail transport and executable code. */
final class Bhranti_Forms {

	/** Register presentation hooks without changing native form definitions. */
	public static function register() {
		add_filter( 'wpcf7_form_class_attr', array( __CLASS__, 'form_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	/**
	 * Mark only forms created through the reviewed adapter for scoped styling.
	 *
	 * @param string $classes Native form classes.
	 * @return string
	 */
	public static function form_class( $classes ) {
		if ( ! is_string( $classes ) || ! self::native_class( 'WPCF7_ContactForm', 'contact-form-7/includes/contact-form.php' ) ) {
			return $classes;
		}
		$form = WPCF7_ContactForm::get_current();
		if ( ! $form || ! $form->id() ) {
			return $classes;
		}
		$managed = get_post_meta( $form->id(), '_bhranti_form_definition', true );
		if ( is_array( $managed ) && (int) ( $managed['requester_id'] ?? 0 ) > 0 && ! in_array( 'bhranti-managed-form', preg_split( '/\s+/', $classes ), true ) ) {
			$classes .= ' bhranti-managed-form';
		}
		return $classes;
	}

	/** Enqueue after native assets, respecting sites that disable CF7's base CSS. */
	public static function enqueue() {
		if ( is_admin() || ! self::availability()['available'] ) {
			return;
		}
		$dependencies = wp_style_is( 'contact-form-7', 'enqueued' ) ? array( 'contact-form-7' ) : array();
		wp_enqueue_style( 'bhranti-forms', plugins_url( 'assets/forms.css', BHRANTI_FILE ), $dependencies, BHRANTI_VERSION );
	}

	/** Describe the reviewed native form operations to any connected model. */
	public static function catalog() {
		return array(
			'form_request' => array(
				'description' => 'Reuse an existing working form before creating one. GET /contact-form-7/v1 discovers Contact Form 7 and Flamingo availability. If needed, use plugin_request for official directory slug contact-form-7; dashboard storage also needs slug flamingo. Each plugin needs its own installation card. That card may include activation with status active when both current switches and native capabilities allow it; one approval covers the listed installation and activation. Otherwise install inactive, and request new approval for later activation or deactivation. GET /contact-form-7/v1/contact-forms lists saved forms (optional search,page,per_page up to 20). GET /contact-form-7/v1/contact-forms/{integer ID} reads a bounded definition and native shortcode without submissions or raw mail templates. POST collection creates; POST item replaces a definition previously managed by this human through Bhranti, only while its native configuration is unchanged. Send the complete params {title,fields:[{type:"name"|"email"|"text"|"select",name:"your-name",label:"Your name",required:true,options:["Choice"]}],confirmation,notifications:"dashboard"|"email",recipient:"business email for email mode"}. Omit options except for select; omit recipient for dashboard. Use 1–12 fields, 1–20 select choices. Creating a form or changing its notification mode/recipient pauses for an exact human approval card showing the destination. Unchanged notification destinations on managed updates do not need another card. Dashboard-only mode requires active official Flamingo; it uses fixed native skip_mail:on, never a fabricated storage claim. No arbitrary HTML, mail templates, additional settings, uploads, submissions, autoresponders, credentials or deletion. Embed the returned shortcode in a core/shortcode block with wp_request. Verify the public form after embedding; saving a definition is not proof of delivery. Existing forms with other integrations are reusable but not rewritten. Undo restores a managed update only when its notification destination stayed the same; creating a form has no automatic deletion Undo. Page embedding has its separate content Undo. Native hooks, emails and submissions are not reversed.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'method' => array(
							'type' => 'string',
							'enum' => array( 'GET', 'POST' ),
						),
						'path'   => array( 'type' => 'string' ),
						'params' => array( 'type' => 'object' ),
					),
					'required'             => array( 'method', 'path' ),
					'additionalProperties' => false,
				),
			),
		);
	}

	/**
	 * Describe an action without reading private form properties or saving anything.
	 *
	 * @param array $command Parsed command.
	 * @param int   $requester_id Human account.
	 * @param int   $actor_id Managed actor.
	 * @return array|WP_Error
	 */
	public static function progress( $command, $requester_id, $actor_id ) {
		$review = self::review( $command, $requester_id, $actor_id );
		if ( is_wp_error( $review ) ) {
			return $review;
		}
		return array(
			'kind'    => 'forms',
			'write'   => 'POST' === $review['method'],
			'method'  => $review['method'],
			'path'    => $review['path'],
			'summary' => 'POST' === $review['method'] ? __( 'Setting up the contact form', 'bhranti' ) : __( 'Checking available contact forms', 'bhranti' ),
			'target'  => self::target( $review['id'] ),
		);
	}

	/**
	 * Execute one owned, audited operation. No endpoint submits or deletes a form.
	 *
	 * @param array  $command Parsed command.
	 * @param int    $requester_id Human account.
	 * @param int    $session_id Private conversation.
	 * @param bool   $confirmed Exact human approval from the confirmation endpoint.
	 * @param string $expected_fingerprint Approved native state and command.
	 * @return array|WP_Error
	 */
	public static function execute( $command, $requester_id, $session_id, $confirmed = false, $expected_fingerprint = '' ) {
		$owner = self::owner( $requester_id, $session_id );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$actor_id = Bhranti_Actions::ensure_actor();
		if ( is_wp_error( $actor_id ) ) {
			return $actor_id;
		}
		$review = self::review( $command, $requester_id, $actor_id );
		if ( is_wp_error( $review ) ) {
			return self::refused( $review, $session_id, $requester_id, $actor_id );
		}
		$details = array(
			'method' => $review['method'],
			'path'   => $review['path'],
		);
		if ( 'GET' === $review['method'] ) {
			$logged = self::audit( 'action_started', $session_id, $requester_id, $actor_id, $details );
			if ( is_wp_error( $logged ) ) {
				return $logged;
			}
			$previous_user = get_current_user_id();
			try {
				wp_set_current_user( $actor_id );
				$data = self::read( $review, $requester_id, $actor_id );
			} finally {
				wp_set_current_user( $previous_user );
			}
			if ( is_wp_error( $data ) ) {
				return self::refused( $data, $session_id, $requester_id, $actor_id, $details );
			}
			$logged = self::audit( 'action_completed', $session_id, $requester_id, $actor_id, $details );
			return is_wp_error( $logged ) ? $logged : self::result( $data, $review['id'], '', false );
		}
		$lock = self::lock();
		if ( is_wp_error( $lock ) ) {
			return self::refused( $lock, $session_id, $requester_id, $actor_id, $details );
		}
		$dispatched = false;
		try {
			$before = self::state( $review['id'], $actor_id );
			$valid  = self::writable( $before, $review['params'], $requester_id );
			if ( is_wp_error( $valid ) ) {
				return self::refused( $valid, $session_id, $requester_id, $actor_id, $details );
			}
			$canonical           = array(
				'tool' => 'form_request',
				'args' => array(
					'method' => $review['method'],
					'path'   => $review['path'],
					'params' => $review['params'],
				),
			);
			$destination_changed = self::notification_changed( $before, $review['params'] );
			if ( $destination_changed ) {
				$approval_fingerprint = self::approval_fingerprint( $before, $canonical );
				if ( $expected_fingerprint && ! hash_equals( $expected_fingerprint, $approval_fingerprint ) ) {
					return self::refused( self::conflict(), $session_id, $requester_id, $actor_id, $details );
				}
				if ( ! $confirmed ) {
					$logged = self::audit(
						'action_confirmation_required',
						$session_id,
						$requester_id,
						$actor_id,
						$details + array(
							'target_id'   => $review['id'],
							'target_type' => 'wpcf7_contact_form',
						)
					);
					if ( is_wp_error( $logged ) ) {
						return $logged;
					}
					$target          = self::target( $review['id'] );
					$target['title'] = $review['params']['title'];
					return array(
						'confirmation_required' => true,
						'action'                => $review['id'] ? 'update' : 'create',
						'command'               => $canonical,
						'summary'               => self::approval_summary( $review['id'], $review['params'] ),
						'audit_summary'         => __( 'Contact form notification choice awaits approval.', 'bhranti' ),
						'target'                => $target,
						'fingerprint'           => $approval_fingerprint,
					);
				}
				if ( ! $expected_fingerprint || ! Bhranti_Agent::consume_form_approval( $canonical, $requester_id, $session_id, $approval_fingerprint ) ) {
					return self::refused( self::error( 'bhranti_forms_confirmation', __( 'This form notification change requires its current approval button.', 'bhranti' ), 403 ), $session_id, $requester_id, $actor_id, $details );
				}
			} elseif ( $confirmed || $expected_fingerprint ) {
				// Revalidation of a pending card must never become an ordinary write.
				return self::refused( self::conflict(), $session_id, $requester_id, $actor_id, $details );
			}
			$record = array(
				'id'           => 'form_' . wp_generate_uuid4(),
				'kind'         => 'forms',
				'state'        => 'prepared',
				'requester_id' => (int) $requester_id,
				'actor_id'     => (int) $actor_id,
				'session_id'   => (int) $session_id,
				'expires'      => time() + 7 * DAY_IN_SECONDS,
				'object_id'    => $review['id'],
				'before'       => $before,
			);
			$saved  = self::snapshot( $session_id, $record );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$details['action_id'] = $record['id'];
			$logged               = self::audit( 'action_started', $session_id, $requester_id, $actor_id, $details );
			if ( is_wp_error( $logged ) ) {
				return $logged;
			}
			$fresh = self::review( $command, $requester_id, $actor_id );
			$state = self::state( $review['id'], $actor_id );
			if ( is_wp_error( $fresh ) || is_wp_error( self::owner( $requester_id, $session_id ) ) || get_option( 'bhranti_forms_lock' ) !== $lock || self::fingerprint( $before ) !== self::fingerprint( $state ) ) {
				return self::refused( self::conflict(), $session_id, $requester_id, $actor_id, $details );
			}
			$valid = self::writable( $state, $fresh['params'], $requester_id );
			if ( is_wp_error( $valid ) ) {
				return self::refused( $valid, $session_id, $requester_id, $actor_id, $details );
			}
			if ( $destination_changed && ! hash_equals( $approval_fingerprint, self::approval_fingerprint( $state, $canonical ) ) ) {
				return self::refused( self::conflict(), $session_id, $requester_id, $actor_id, $details );
			}
			$dispatched = true;
			$id         = self::save( $review['id'], $fresh['params'], $requester_id, $actor_id );
			if ( is_wp_error( $id ) ) {
				$record['state'] = 'failed';
				self::snapshot( $session_id, $record );
				self::audit( 'action_failed', $session_id, $requester_id, $actor_id, $details );
				return self::uncertain( $id );
			}
			$after = self::state( $id, $actor_id );
			if ( is_wp_error( $after ) || ! self::managed( $after, $requester_id ) ) {
				return self::uncertain( self::save_error() );
			}
			$record['object_id']         = $id;
			$record['state']             = 'completed';
			$record['after_fingerprint'] = self::fingerprint( $after );
			$saved                       = self::snapshot( $session_id, $record );
			if ( is_wp_error( $saved ) ) {
				return self::uncertain( $saved );
			}
			$logged = self::audit(
				'action_completed',
				$session_id,
				$requester_id,
				$actor_id,
				$details + array(
					'target_id'   => $id,
					'target_type' => 'wpcf7_contact_form',
				)
			);
			$data   = self::project( $id, $requester_id, $actor_id );
			if ( is_wp_error( $data ) ) {
				return self::uncertain( $data );
			}
			return is_wp_error( $logged ) ? self::uncertain( $logged ) : self::result( $data, $id, $record['id'], ! empty( $before ) && ! $destination_changed );
		} catch ( Throwable $error ) {
			$error = self::error( 'bhranti_forms_interrupted', __( 'The form operation was interrupted. Inspect the saved form before trying again.', 'bhranti' ), 500 );
			self::audit( 'action_failed', $session_id, $requester_id, $actor_id, $details );
			return $dispatched ? self::uncertain( $error ) : $error;
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * Restore a managed update once, preserving later native edits and submissions.
	 *
	 * @param string $action_id Saved operation ID.
	 * @param int    $requester_id Human account.
	 * @param int    $session_id Private conversation.
	 * @return array|WP_Error
	 */
	public static function rollback( $action_id, $requester_id, $session_id ) {
		$owner = self::owner( $requester_id, $session_id );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		wp_cache_delete( $session_id, 'post_meta' );
		$records = get_post_meta( $session_id, 'bhranti_actions', true );
		$record  = is_array( $records ) ? ( $records[ $action_id ] ?? null ) : null;
		if ( ! is_array( $record ) || 'forms' !== ( $record['kind'] ?? '' ) || 'completed' !== ( $record['state'] ?? '' ) || (int) ( $record['session_id'] ?? 0 ) !== (int) $session_id || (int) ( $record['requester_id'] ?? 0 ) !== (int) $requester_id || empty( $record['before'] ) ) {
			return self::error( 'bhranti_rollback_missing', __( 'This form update cannot be undone or its Undo was already used. New forms are not automatically deleted.', 'bhranti' ), 404 );
		}
		if ( $record['expires'] < time() ) {
			return self::error( 'bhranti_rollback_expired', __( 'The seven-day rollback window has expired.', 'bhranti' ), 410 );
		}
		$actor_id = Bhranti_Actions::ensure_actor();
		if ( is_wp_error( $actor_id ) ) {
			return $actor_id;
		}
		$definition = $record['before']['managed']['definition'];
		$command    = array(
			'tool' => 'form_request',
			'args' => array(
				'method' => 'POST',
				'path'   => '/contact-form-7/v1/contact-forms/' . $record['object_id'],
				'params' => $definition,
			),
		);
		$review     = self::review( $command, $requester_id, $actor_id );
		if ( is_wp_error( $review ) ) {
			return self::refused( $review, $session_id, $requester_id, $actor_id );
		}
		$details = array(
			'method'    => 'POST',
			'path'      => $review['path'],
			'action_id' => $action_id,
		);
		$lock    = self::lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$dispatched = false;
		try {
			$current = self::state( $record['object_id'], $actor_id );
			if ( is_wp_error( $current ) || ! hash_equals( $record['after_fingerprint'], self::fingerprint( $current ) ) || ! self::managed( $current, $requester_id ) ) {
				return self::refused( self::conflict(), $session_id, $requester_id, $actor_id, $details );
			}
			if ( self::notification_changed( $current, $definition ) ) {
				return self::refused( self::error( 'bhranti_forms_rollback_destination', __( 'Undo cannot change this form’s notification destination. Review the destination in the native form editor.', 'bhranti' ), 403 ), $session_id, $requester_id, $actor_id, $details );
			}
			if ( 'dashboard' === $definition['notifications'] && ! self::availability()['dashboard_storage'] ) {
				return self::refused( self::storage_missing(), $session_id, $requester_id, $actor_id, $details );
			}
			$logged = self::audit( 'rollback_started', $session_id, $requester_id, $actor_id, $details );
			if ( is_wp_error( $logged ) ) {
				return $logged;
			}
			$record['state'] = 'rolling_back';
			$saved           = self::snapshot( $session_id, $record );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$fresh = self::review( $command, $requester_id, $actor_id );
			if ( is_wp_error( $fresh ) || is_wp_error( self::owner( $requester_id, $session_id ) ) || ( 'dashboard' === $definition['notifications'] && ! self::availability()['dashboard_storage'] ) || get_option( 'bhranti_forms_lock' ) !== $lock || ! hash_equals( $record['after_fingerprint'], self::fingerprint( self::state( $record['object_id'], $actor_id ) ) ) ) {
				$record['state'] = 'completed';
				self::snapshot( $session_id, $record );
				return self::refused( self::conflict(), $session_id, $requester_id, $actor_id, $details );
			}
			$dispatched = true;
			$id         = self::save( $record['object_id'], $definition, $requester_id, $actor_id, $record['before'] );
			if ( is_wp_error( $id ) ) {
				self::audit( 'rollback_failed', $session_id, $requester_id, $actor_id, $details );
				return self::uncertain( $id );
			}
			$record['state'] = 'rolled_back';
			$saved           = self::snapshot( $session_id, $record );
			if ( is_wp_error( $saved ) ) {
				return self::uncertain( $saved );
			}
			$logged = self::audit( 'rollback_completed', $session_id, $requester_id, $actor_id, $details );
			$data   = self::project( $id, $requester_id, $actor_id );
			if ( is_wp_error( $data ) ) {
				return self::uncertain( $data );
			}
			return is_wp_error( $logged ) ? self::uncertain( $logged ) : self::result( $data, $id, $action_id, false );
		} catch ( Throwable $error ) {
			$error = self::error( 'bhranti_forms_interrupted', __( 'Form Undo was interrupted. Inspect the form before trying again.', 'bhranti' ), 500 );
			return $dispatched ? self::uncertain( $error ) : $error;
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * Validate only the adapter schema; never forward arbitrary native REST fields.
	 *
	 * @param array $command Tool envelope.
	 * @param int   $requester_id Human account.
	 * @param int   $actor_id Managed actor.
	 * @return array|WP_Error
	 */
	private static function review( $command, $requester_id, $actor_id ) {
		if ( ! is_array( $command ) || array_diff( array_keys( $command ), array( 'tool', 'args' ) ) || 'form_request' !== ( $command['tool'] ?? '' ) || ! is_array( $command['args'] ?? null ) || array_diff( array_keys( $command['args'] ), array( 'method', 'path', 'params' ) ) ) {
			return self::invalid();
		}
		$args   = $command['args'];
		$method = $args['method'] ?? '';
		$path   = $args['path'] ?? '';
		$params = $args['params'] ?? array();
		if ( ! in_array( $method, array( 'GET', 'POST' ), true ) || ! is_string( $path ) || ! preg_match( '#^/contact-form-7/v1(?:/contact-forms(?:/([1-9][0-9]{0,9}))?)?$#D', $path, $match ) || ! is_array( $params ) || strlen( wp_json_encode( $params ) ) > 18000 ) {
			return self::invalid();
		}
		$id        = isset( $match[1] ) ? (int) $match[1] : 0;
		$discovery = '/contact-form-7/v1' === $path;
		if ( ! user_can( $requester_id, 'manage_options' ) || ! user_can( $actor_id, 'manage_options' ) ) {
			return self::permission();
		}
		if ( $discovery ) {
			return 'GET' === $method && array() === $params ? array(
				'method'    => $method,
				'path'      => $path,
				'params'    => $params,
				'id'        => 0,
				'discovery' => true,
			) : self::invalid();
		}
		if ( ! self::availability()['available'] ) {
			return self::error( 'bhranti_forms_missing', __( 'Contact Form 7 is not active. Install directory slug contact-form-7 and activate contact-form-7/wp-contact-form-7 with the native plugin tool, then check forms again.', 'bhranti' ) );
		}
		$cap = $id ? 'wpcf7_edit_contact_form' : ( 'GET' === $method ? 'wpcf7_read_contact_forms' : 'wpcf7_edit_contact_forms' );
		// Match CF7's REST controller: even an individual definition read needs edit permission.
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Only the three native CF7 capabilities above.
		if ( ! user_can( $requester_id, $cap, $id ) || ! user_can( $actor_id, $cap, $id ) ) {
			return self::permission();
		}
		if ( 'POST' === $method ) {
			$allowed = Bhranti_Settings::allows( 'edit_content' );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
			$params = self::definition( $params );
			if ( is_wp_error( $params ) ) {
				return $params;
			}
		} elseif ( ( $id && $params ) || array_diff( array_keys( $params ), array( 'search', 'page', 'per_page' ) ) || ( isset( $params['search'] ) && ! self::text( $params['search'], 100 ) ) || ( isset( $params['page'] ) && ( ! is_int( $params['page'] ) || $params['page'] < 1 || $params['page'] > 500 ) ) || ( isset( $params['per_page'] ) && ( ! is_int( $params['per_page'] ) || $params['per_page'] < 1 || $params['per_page'] > 20 ) ) ) {
			return self::invalid();
		}
		return array(
			'method'    => $method,
			'path'      => $path,
			'params'    => $params,
			'id'        => $id,
			'discovery' => false,
		);
	}

	/**
	 * Accept bounded plain data, rejecting CF7 tags/options and HTML injection.
	 *
	 * @param array $params Complete definition.
	 * @return array|WP_Error
	 */
	private static function definition( $params ) {
		if ( ! is_array( $params ) || array_diff( array_keys( $params ), array( 'title', 'fields', 'confirmation', 'notifications', 'recipient' ) ) || ! self::text( $params['title'] ?? null, 160 ) || ! self::text( $params['confirmation'] ?? null, 400 ) || ! in_array( $params['notifications'] ?? '', array( 'dashboard', 'email' ), true ) || ! is_array( $params['fields'] ?? null ) || count( $params['fields'] ) < 1 || count( $params['fields'] ) > 12 || array_keys( $params['fields'] ) !== range( 0, count( $params['fields'] ) - 1 ) ) {
			return self::invalid();
		}
		$names = array();
		foreach ( $params['fields'] as $field ) {
			if ( ! is_array( $field ) || array_diff( array_keys( $field ), array( 'type', 'name', 'label', 'required', 'options' ) ) || ! in_array( $field['type'] ?? '', array( 'name', 'email', 'text', 'select' ), true ) || ! is_string( $field['name'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9-]{0,39}$/D', $field['name'] ) || in_array( $field['name'], array( 'name', 'action', 'id', 'author', 'post', 'page', 'submit', 'context' ), true ) || isset( $names[ $field['name'] ] ) || ! self::text( $field['label'] ?? null, 120 ) || ! is_bool( $field['required'] ?? null ) ) {
				return self::invalid();
			}
			$names[ $field['name'] ] = true;
			if ( 'select' === $field['type'] ) {
				$options = $field['options'] ?? null;
				if ( ! is_array( $options ) || count( $options ) < 1 || count( $options ) > 20 || array_keys( $options ) !== range( 0, count( $options ) - 1 ) || count( array_unique( $options, SORT_REGULAR ) ) !== count( $options ) ) {
					return self::invalid();
				}
				foreach ( $options as $option ) {
					if ( ! self::text( $option, 100 ) || preg_match( '/["\\\\|]/', $option ) ) {
						return self::invalid();
					}
				}
			} elseif ( isset( $field['options'] ) ) {
				return self::invalid();
			}
		}
		if ( 'email' === $params['notifications'] ) {
			if ( ! is_string( $params['recipient'] ?? null ) || strlen( $params['recipient'] ) > 254 || ! is_email( $params['recipient'] ) || preg_match( '/[\r\n\[\]]/', $params['recipient'] ) ) {
				return self::invalid();
			}
		} elseif ( array_key_exists( 'recipient', $params ) ) {
			return self::invalid();
		}
		return $params;
	}

	/**
	 * Preserve native integrations before considering notification approval.
	 *
	 * @param array|WP_Error $before Fresh native state.
	 * @param array          $definition Proposed definition.
	 * @param int            $requester_id Human account.
	 * @return true|WP_Error
	 */
	private static function writable( $before, $definition, $requester_id ) {
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		if ( $before && ! self::managed( $before, $requester_id ) ) {
			return self::error( 'bhranti_forms_preservation', __( 'Reuse this existing form without replacing its configuration. Only unchanged forms set up by you through Bhranti can be edited here.', 'bhranti' ), 409 );
		}
		if ( 'dashboard' === $definition['notifications'] && ! self::availability()['dashboard_storage'] ) {
			return self::storage_missing();
		}
		return true;
	}

	/**
	 * Whether this write would establish a new notification destination.
	 *
	 * @param array $before Current saved state.
	 * @param array $definition Proposed definition.
	 * @return bool
	 */
	private static function notification_changed( $before, $definition ) {
		$old = $before['managed']['definition'] ?? array();
		return ( $old['notifications'] ?? '' ) !== $definition['notifications'] || ( $old['recipient'] ?? '' ) !== ( $definition['recipient'] ?? '' );
	}

	/**
	 * Bind the approved command to the current native form state.
	 *
	 * @param array $before Current saved state.
	 * @param array $command Canonical proposed command.
	 * @return string
	 */
	private static function approval_fingerprint( $before, $command ) {
		return self::fingerprint(
			array(
				'state'   => $before,
				'command' => $command,
			)
		);
	}

	/**
	 * Name the exact notification effect in the private approval card.
	 *
	 * @param int   $id Saved form ID, or zero for creation.
	 * @param array $definition Proposed definition.
	 * @return string
	 */
	private static function approval_summary( $id, $definition ) {
		if ( 'dashboard' === $definition['notifications'] ) {
			return $id ? __( 'Change this form to dashboard-only storage? Flamingo will store new submissions and email notifications will be disabled. Previous submissions and email effects cannot be undone.', 'bhranti' ) : __( 'Create this form with dashboard-only storage? Flamingo will store new submissions and email notifications will be disabled. New forms have no automatic deletion Undo.', 'bhranti' );
		}
		if ( $id ) {
			/* translators: %s: email address receiving form submissions. */
			return sprintf( __( 'Change this form to email new submissions to %s? Previous submissions and email effects cannot be undone.', 'bhranti' ), $definition['recipient'] );
		}
		/* translators: %s: email address receiving form submissions. */
		return sprintf( __( 'Create this form and email new submissions to %s? New forms have no automatic deletion Undo.', 'bhranti' ), $definition['recipient'] );
	}

	/**
	 * Build only native CF7 tags; native rendering supplies the form and nonce.
	 *
	 * @param array $definition Reviewed definition.
	 * @param array $defaults Native template properties.
	 * @return array
	 */
	private static function properties( $definition, $defaults ) {
		$markup = array();
		$body   = array();
		$name   = '';
		$email  = '';
		foreach ( $definition['fields'] as $field ) {
			$type = 'name' === $field['type'] ? 'text' : $field['type'];
			$tag  = '[' . $type . ( $field['required'] ? '*' : '' ) . ' ' . $field['name'];
			if ( 'select' === $type ) {
				$tag .= ' include_blank';
				foreach ( $field['options'] as $option ) {
					$tag .= ' "' . $option . '"';
				}
			} else {
				$tag .= ' maxlength:500';
				if ( 'name' === $field['type'] || 'email' === $field['type'] ) {
					$tag .= ' autocomplete:' . $field['type'];
				}
			}
			$markup[] = '<label> ' . esc_html( $field['label'] ) . "\n" . $tag . '] </label>';
			$body[]   = $field['label'] . ': [' . $field['name'] . ']';
			if ( ! $name && 'name' === $field['type'] ) {
				$name = $field['name'];
			}
			if ( ! $email && 'email' === $field['type'] ) {
				$email = $field['name'];
			}
		}
		$markup[]                 = '[submit]';
		$mail                     = array(
			'subject'            => '[_site_title] ' . $definition['title'],
			'sender'             => $defaults['mail']['sender'],
			'body'               => implode( "\n", $body ),
			'recipient'          => 'email' === $definition['notifications'] ? $definition['recipient'] : '',
			'additional_headers' => '',
			'attachments'        => '',
			'use_html'           => false,
			'exclude_blank'      => false,
		);
		$messages                 = $defaults['messages'];
		$messages['mail_sent_ok'] = $definition['confirmation'];
		$settings                 = 'dashboard' === $definition['notifications'] ? "skip_mail: on\n" : '';
		// Fixed native Flamingo mappings contain only validated field identifiers.
		$settings .= $name ? 'flamingo_name: "[' . $name . ']"' . "\n" : '';
		$settings .= $email ? 'flamingo_email: "[' . $email . ']"' . "\n" : '';
		$settings .= 'flamingo_subject: "' . str_replace( '"', '', $definition['title'] ) . '"';
		return array(
			'form'                => implode( "\n\n", $markup ),
			'mail'                => $mail,
			'mail_2'              => array_merge(
				$mail,
				array(
					'active'    => false,
					'recipient' => '',
				)
			),
			'messages'            => $messages,
			'additional_settings' => $settings,
		);
	}

	/**
	 * Save through the actual CF7 class while acting as Bhranti, then verify storage.
	 *
	 * @param int   $id Existing form or zero.
	 * @param array $definition Reviewed definition.
	 * @param int   $requester_id Human owner.
	 * @param int   $actor_id Managed actor.
	 * @param array $restore Exact previous managed native properties, only during Undo.
	 * @return int|WP_Error
	 */
	private static function save( $id, $definition, $requester_id, $actor_id, $restore = array() ) {
		$previous_user = get_current_user_id();
		try {
			wp_set_current_user( $actor_id );
			$form = $id ? WPCF7_ContactForm::get_instance( $id ) : WPCF7_ContactForm::get_template();
			if ( ! $form ) {
				return self::invalid();
			}
			$properties = $restore ? $restore['properties'] : self::properties( $definition, $form->get_properties() );
			$form->set_title( $definition['title'] );
			$form->set_properties( $properties );
			$saved = $form->save();
			if ( ! $saved || ( $id && (int) $saved !== $id ) ) {
				return self::save_error();
			}
			$id    = (int) $saved;
			$after = self::state( $id, $actor_id );
			if ( is_wp_error( $after ) || $after['properties'] !== $properties || $after['title'] !== $definition['title'] || 'publish' !== $after['status'] ) {
				return self::save_error();
			}
			$managed = array(
				'requester_id' => (int) $requester_id,
				'definition'   => $definition,
				'fingerprint'  => self::native_fingerprint( $after ),
			);
			update_post_meta( $id, '_bhranti_form_definition', wp_slash( $managed ) );
			wp_cache_delete( $id, 'post_meta' );
			return get_post_meta( $id, '_bhranti_form_definition', true ) === $managed ? $id : self::save_error();
		} finally {
			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * Read bounded definitions only, never entries, raw mail messages or credentials.
	 *
	 * @param array $review Validated request.
	 * @param int   $requester_id Human account.
	 * @param int   $actor_id Managed actor.
	 * @return array|WP_Error
	 */
	private static function read( $review, $requester_id, $actor_id ) {
		if ( $review['discovery'] ) {
			return self::availability();
		}
		if ( $review['id'] ) {
			return self::project( $review['id'], $requester_id, $actor_id );
		}
		$params = $review['params'];
		$ids    = get_posts(
			array(
				'post_type'      => 'wpcf7_contact_form',
				'post_status'    => 'publish',
				'posts_per_page' => $params['per_page'] ?? 20,
				'paged'          => $params['page'] ?? 1,
				's'              => $params['search'] ?? '',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		$data   = array();
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Native CF7 mapped read capability.
			if ( user_can( $requester_id, 'wpcf7_read_contact_form', $id ) && user_can( $actor_id, 'wpcf7_read_contact_form', $id ) ) {
				$item = self::project( $id, $requester_id, $actor_id, false );
				if ( ! is_wp_error( $item ) ) {
					$data[] = $item;
				}
			}
		}
		return $data;
	}

	/**
	 * Project a saved definition without exposing native mail templates.
	 *
	 * @param int  $id Native ID.
	 * @param int  $requester_id Human account.
	 * @param int  $actor_id Managed actor.
	 * @param bool $detail Include the bounded field schema.
	 * @return array|WP_Error
	 */
	private static function project( $id, $requester_id, $actor_id, $detail = true ) {
		$previous_user = get_current_user_id();
		try {
			wp_set_current_user( $actor_id );
			$state = self::state( $id, $actor_id );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$form      = WPCF7_ContactForm::get_instance( $id );
			$shortcode = $form->shortcode();
			// Preserve CF7's native hash shortcode, but never forward another tag or target from a filter.
			if ( ! is_string( $shortcode ) || strlen( $shortcode ) > 600 || ! preg_match( '/^\[contact-form-7 id="([a-zA-Z0-9]+)" title="[^"<>\r\n\[\]]*"\]$/D', $shortcode, $match ) || $match[1] !== $form->hash() ) {
				$shortcode = '[contact-form-7 id="' . (int) $id . '"]';
			}
			$data = array(
				'id'                => (int) $id,
				'title'             => mb_substr( sanitize_text_field( $state['title'] ), 0, 160 ),
				'shortcode'         => $shortcode,
				'editable'          => self::managed( $state, $requester_id ),
				'dashboard_storage' => self::availability()['dashboard_storage'] && ! $form->is_true( 'do_not_store' ),
			);
			if ( $detail ) {
				$fields = array();
				foreach ( array_slice( $form->scan_form_tags(), 0, 20 ) as $tag ) {
					if ( ! empty( $tag->name ) ) {
						$fields[] = array(
							'name'     => mb_substr( sanitize_key( $tag->name ), 0, 80 ),
							'type'     => mb_substr( sanitize_key( $tag->basetype ), 0, 30 ),
							'required' => $tag->is_required(),
						);
					}
				}
				$data['fields'] = $fields;
				if ( $data['editable'] ) {
					$data['definition'] = $state['managed']['definition'];
				}
			}
			return $data;
		} finally {
			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * Snapshot native form configuration, excluding unrelated metadata/submissions.
	 *
	 * @param int $id Native form ID or zero before creation.
	 * @param int $actor_id Managed actor.
	 * @return array|WP_Error
	 */
	private static function state( $id, $actor_id ) {
		$previous_user = get_current_user_id();
		try {
			wp_set_current_user( $actor_id );
			if ( ! $id ) {
				return array();
			}
			clean_post_cache( $id );
			$post = get_post( $id );
			if ( ! $post || 'wpcf7_contact_form' !== $post->post_type || 'publish' !== $post->post_status ) {
				return self::error( 'bhranti_forms_missing_item', __( 'This saved contact form is not available.', 'bhranti' ), 404 );
			}
			$form       = WPCF7_ContactForm::get_instance( $id );
			$properties = $form ? $form->get_properties() : array();
			if ( count( $properties ) !== 5 || array_diff( array_keys( $properties ), array( 'form', 'mail', 'mail_2', 'messages', 'additional_settings' ) ) || strlen( wp_json_encode( $properties ) ) > 60000 ) {
				return self::error( 'bhranti_forms_preservation', __( 'This form uses additional configuration. Reuse it through its native form editor.', 'bhranti' ), 409 );
			}
			return array(
				'title'        => $post->post_title,
				'status'       => $post->post_status,
				'author'       => (int) $post->post_author,
				'content_hash' => hash( 'sha256', $post->post_content ),
				'locale'       => $form->locale(),
				'properties'   => $properties,
				'managed'      => get_post_meta( $id, '_bhranti_form_definition', true ),
			);
		} finally {
			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * Check the saved adapter fingerprint before trusting its bounded definition.
	 *
	 * @param array $state Saved state.
	 * @param int   $requester_id Human account.
	 * @return bool
	 */
	private static function managed( $state, $requester_id ) {
		$managed = $state['managed'] ?? null;
		return is_array( $managed ) && (int) ( $managed['requester_id'] ?? 0 ) === (int) $requester_id && is_string( $managed['fingerprint'] ?? null ) && ! is_wp_error( self::definition( $managed['definition'] ?? null ) ) && hash_equals( $managed['fingerprint'], self::native_fingerprint( $state ) );
	}

	/**
	 * Inspect active native class provenance; never bootstrap an inactive plugin.
	 *
	 * @return array
	 */
	private static function availability() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$available = is_plugin_active( 'contact-form-7/wp-contact-form-7.php' ) && post_type_exists( 'wpcf7_contact_form' ) && self::native_class( 'WPCF7_ContactForm', 'contact-form-7/includes/contact-form.php' );
		$storage   = $available && is_plugin_active( 'flamingo/flamingo.php' ) && self::native_class( 'Flamingo_Contact', 'flamingo/includes/class-contact.php' ) && self::native_class( 'Flamingo_Inbound_Message', 'flamingo/includes/class-inbound-message.php' ) && post_type_exists( 'flamingo_inbound' ) && taxonomy_exists( 'flamingo_inbound_channel' ) && function_exists( 'wpcf7_flamingo_submit' ) && false !== has_action( 'wpcf7_submit', 'wpcf7_flamingo_submit' );
		if ( $storage ) {
			$callback = new ReflectionFunction( 'wpcf7_flamingo_submit' );
			$storage  = realpath( $callback->getFileName() ) === realpath( WP_PLUGIN_DIR . '/contact-form-7/modules/flamingo.php' );
		}
		return array(
			'available'           => $available,
			'plugin_slug'         => 'contact-form-7',
			'plugin'              => 'contact-form-7/wp-contact-form-7',
			'dashboard_storage'   => $storage,
			'storage_plugin_slug' => 'flamingo',
			'storage_plugin'      => 'flamingo/flamingo',
			'collection'          => '/contact-form-7/v1/contact-forms',
			'field_types'         => array( 'name', 'email', 'text', 'select' ),
			'deletion'            => false,
			'submission_access'   => false,
		);
	}

	/**
	 * Require the exact native implementation rather than a same-named replacement.
	 *
	 * @param string $class_name Expected class.
	 * @param string $relative Canonical plugin-relative source.
	 * @return bool
	 */
	private static function native_class( $class_name, $relative ) {
		if ( ! class_exists( $class_name, false ) ) {
			return false;
		}
		$reflection = new ReflectionClass( $class_name );
		$expected   = realpath( WP_PLUGIN_DIR . '/' . $relative );
		return $expected && realpath( $reflection->getFileName() ) === $expected;
	}

	/**
	 * Require the authenticated human's private conversation.
	 *
	 * @param int $requester_id Human account.
	 * @param int $session_id Private conversation.
	 * @return true|WP_Error
	 */
	private static function owner( $requester_id, $session_id ) {
		clean_post_cache( $session_id );
		$post = get_post( $session_id );
		return get_current_user_id() === (int) $requester_id && Bhranti_Settings::can_manage() && $post && 'bhranti_session' === $post->post_type && 'private' === $post->post_status && (int) $post->post_author === (int) $requester_id ? true : self::error( 'bhranti_action_owner', __( 'This workspace action is not available to your account.', 'bhranti' ), 403 );
	}

	/**
	 * Persist and read back the private recovery record before changing a form.
	 *
	 * @param int   $session_id Owned conversation.
	 * @param array $record Operation record.
	 * @return true|WP_Error
	 */
	private static function snapshot( $session_id, $record ) {
		wp_cache_delete( $session_id, 'post_meta' );
		$records                  = get_post_meta( $session_id, 'bhranti_actions', true );
		$records                  = is_array( $records ) ? $records : array();
		$records[ $record['id'] ] = $record;
		if ( strlen( wp_json_encode( $records ) ) > 5000000 ) {
			return self::save_error();
		}
		update_post_meta( $session_id, 'bhranti_actions', wp_slash( $records ) );
		wp_cache_delete( $session_id, 'post_meta' );
		return get_post_meta( $session_id, 'bhranti_actions', true ) === $records ? true : self::save_error();
	}

	/** Acquire a short site-local lock to serialize form configuration writes. */
	private static function lock() {
		$token = array(
			'id'      => wp_generate_uuid4(),
			'expires' => time() + 120,
		);
		$old   = get_option( 'bhranti_forms_lock' );
		if ( is_array( $old ) && (int) ( $old['expires'] ?? PHP_INT_MAX ) < time() && get_option( 'bhranti_forms_lock' ) === $old ) {
			delete_option( 'bhranti_forms_lock' );
		}
		return add_option( 'bhranti_forms_lock', $token, '', false ) ? $token : self::error( 'bhranti_forms_busy', __( 'Another form change is running. Wait for it to finish.', 'bhranti' ), 409 );
	}

	/**
	 * Release only the owned lock.
	 *
	 * @param array $token Owned lock.
	 */
	private static function unlock( $token ) {
		if ( get_option( 'bhranti_forms_lock' ) === $token ) {
			delete_option( 'bhranti_forms_lock' );
		}
	}

	/**
	 * Provide a native editor target without arbitrary URLs.
	 *
	 * @param int $id Form ID or zero.
	 * @return array
	 */
	private static function target( $id ) {
		return array(
			'id'    => (int) $id,
			'type'  => 'wpcf7_contact_form',
			'title' => __( 'Contact form', 'bhranti' ),
			'url'   => add_query_arg(
				$id ? array(
					'page'   => 'wpcf7',
					'post'   => $id,
					'action' => 'edit',
				) : array( 'page' => 'wpcf7' ),
				admin_url( 'admin.php' )
			),
		);
	}

	/**
	 * Return only the bounded public result and supported Undo state.
	 *
	 * @param array  $data Public definition.
	 * @param int    $id Form ID.
	 * @param string $action_id Durable operation ID.
	 * @param bool   $rollback_available Whether an update can be restored.
	 * @return array
	 */
	private static function result( $data, $id, $action_id, $rollback_available ) {
		return array(
			'data'               => $data,
			'target'             => self::target( $id ),
			'action_id'          => $action_id,
			'rollback_available' => $rollback_available,
			'summary'            => $action_id ? __( 'Contact form configuration saved. Verify the form on its page before considering setup complete.', 'bhranti' ) : __( 'Native contact form information retrieved.', 'bhranti' ),
		);
	}

	/**
	 * Record identifiers only, never definitions, destinations, evidence or entries.
	 *
	 * @param string $event Event kind.
	 * @param int    $session_id Owned conversation.
	 * @param int    $requester_id Human account.
	 * @param int    $actor_id Managed actor.
	 * @param array  $details Safe operation references.
	 * @return int|WP_Error
	 */
	private static function audit( $event, $session_id, $requester_id, $actor_id, $details ) {
		$result = Bhranti_Audit::record( $event, __( 'Reviewed contact form operation.', 'bhranti' ), $session_id, $details, $requester_id, $actor_id );
		return $result ? $result : self::save_error();
	}

	/**
	 * Record a refused operation without exposing unreviewed command values.
	 *
	 * @param WP_Error $error Safe error.
	 * @param int      $session_id Owned conversation.
	 * @param int      $requester_id Human account.
	 * @param int      $actor_id Managed actor.
	 * @param array    $details Safe references.
	 * @return WP_Error
	 */
	private static function refused( $error, $session_id, $requester_id, $actor_id, $details = array() ) {
		$logged = self::audit( 'action_blocked', $session_id, $requester_id, $actor_id, $details + array( 'code' => $error->get_error_code() ) );
		return is_wp_error( $logged ) ? $logged : $error;
	}

	/**
	 * Permit plain bounded display text, never tags, special mail tags or newlines.
	 *
	 * @param mixed $value Proposed text.
	 * @param int   $limit Character bound.
	 * @return bool
	 */
	private static function text( $value, $limit ) {
		return is_string( $value ) && '' !== trim( $value ) && mb_strlen( $value ) <= $limit && sanitize_text_field( $value ) === $value && ! preg_match( '/[<>\[\]\x00-\x1f\x7f]/', $value );
	}

	/**
	 * Fingerprint the whole reviewed state.
	 *
	 * @param mixed $state Saved state.
	 * @return string
	 */
	private static function fingerprint( $state ) {
		return hash( 'sha256', wp_json_encode( $state ) );
	}

	/**
	 * Keep the ownership marker outside its own native fingerprint.
	 *
	 * @param array $state Saved state.
	 * @return string
	 */
	private static function native_fingerprint( $state ) {
		unset( $state['managed'] );
		return self::fingerprint( $state );
	}

	/** Return a fixed schema error. */
	private static function invalid() {
		return self::error( 'bhranti_forms_invalid', __( 'Use the reviewed form fields and plain labels, with an explicit notification choice.', 'bhranti' ) );
	}

	/** Return a fixed native permission refusal. */
	private static function permission() {
		return self::error( 'bhranti_forms_permission', __( 'Both your account and Bhranti need native permission to manage this form.', 'bhranti' ), 403 );
	}

	/** Return a truthful dependency instruction without claiming local delivery. */
	private static function storage_missing() {
		return self::error( 'bhranti_forms_storage_missing', __( 'Dashboard-only requests need active Flamingo. Install directory slug flamingo and activate flamingo/flamingo with the native plugin tool, then check storage again.', 'bhranti' ), 409 );
	}

	/** Return a conflict without overwriting another editor's changes. */
	private static function conflict() {
		return self::error( 'bhranti_rollback_conflict', __( 'The form, ownership or permissions changed. Read it again; later changes cannot be overwritten by Undo.', 'bhranti' ), 409 );
	}

	/** Return a bounded persistence failure without native payloads. */
	private static function save_error() {
		return self::error( 'bhranti_forms_storage', __( 'WordPress could not verify the form or its recovery record. Inspect the form before retrying.', 'bhranti' ), 500 );
	}

	/**
	 * Stop the harness after a possibly dispatched write; never replay it blindly.
	 *
	 * @param WP_Error $error Safe failure.
	 * @return WP_Error
	 */
	private static function uncertain( $error ) {
		$data                     = $error->get_error_data();
		$data                     = is_array( $data ) ? $data : array( 'status' => 500 );
		$data['uncertain_action'] = true;
		$error->add_data( $data );
		return $error;
	}

	/**
	 * Return fixed copy without leaking form content, destinations or native errors.
	 *
	 * @param string $code Error code.
	 * @param string $message Public copy.
	 * @param int    $status HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
