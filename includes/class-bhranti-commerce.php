<?php
/**
 * Reviewed native WooCommerce catalog and public store-setting operations.
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-bhranti-commerce-setup.php';

/** Keep commerce work inside native controllers without financial transactions. */
final class Bhranti_Commerce {
	/** Return the narrow commerce tool, including useful dependency discovery. */
	public static function catalog() {
		return array(
			'commerce_request' => array(
				'description' => 'Manage a WooCommerce store through reviewed native APIs. First GET /wc/v3 for availability and safe setting paths. If WooCommerce is missing, use existing plugin_request to install directory slug woocommerce inactive, then activate woocommerce/woocommerce when the user requested a store. Never claim the store is ready until successful native results. GET /wc/v3/products and /wc/v3/products/categories searches existing catalog items. POST /wc/v3/products creates a simple product, default draft; example params {"name":"Handmade bowl","type":"simple","status":"draft","regular_price":"25.00","description":"<p>Confirmed product details.</p>"}. Product fields: name,slug,status,type=simple,description,short_description,regular_price,sale_price,sku,featured,catalog_visibility,virtual,manage_stock,stock_quantity,stock_status,backorders,sold_individually,weight,dimensions,categories:[{id:1}],images:[{id:123}],menu_order,reviews_allowed. Images must be existing native Media Library IDs; no remote uploads. POST product item updates it. DELETE product item only trashes after the human Approve button; never send force. POST categories supports name,slug,parent,description,display,menu_order; category deletion is unavailable. Store settings: GET the exact allowed path returned by discovery; POST it with only {"value":"..."}. Large country/state settings may return options plus option_groups. Both contain the original selectable keys; each grouped label is label_prefix followed by its options label. Send the complete option key unchanged. Reads and edits are bounded, audited and checked against native schemas and current permissions. Product Undo restores supported saved state; hook/email effects are not universally reversible. Ask for missing real products/prices/currency/shipping/payment decisions; never invent business facts or configure payment credentials, orders or customers. Store setup: GET/POST /wc/v3/shipping/zones (name,order); GET/POST /wc/v3/shipping/zones/{id}/locations with params {locations:[{type:country,code:IN}]} replacing the complete location list; GET/POST /wc/v3/shipping/zones/{id}/methods with method_id flat_rate/free_shipping/local_pickup,enabled,order,settings; GET/POST a specific /methods/{instance_id} without method_id. Read before updates. Supported method settings: title,tax_status,cost for flat_rate/local_pickup; title,requires,min_amount,ignore_discounts for free_shipping. Costs must be numeric strings, never expressions. GET/POST /wc/v3/payment_gateways/cod configures native cash on delivery only: enabled,title,description,settings {instructions,enable_for_virtual}. Cash on delivery covers all configured delivery methods unless native discovery supplies specific supported method options. Shipping zones govern delivery regions. Never create an order. GET /wc-admin/options reads shop visibility; POST accepts only woocommerce_coming_soon and woocommerce_store_pages_only as yes/no. Change visibility only when the user requests launch, and verify customer-facing setup first. Publish the assigned Cart page through the reviewed content tool before visibility; native visibility invalidation re-publishes that already published page and requires content/publishing permissions. No public shipping deletion; Undo can remove an unchanged, dependency-free item created by this chat.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'method' => array(
							'type' => 'string',
							'enum' => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
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
	 * Describe an actual prepared commerce command without executing it.
	 *
	 * @param array $command Pending command.
	 * @param int   $requester_id Human administrator.
	 * @param int   $actor_id Managed actor.
	 * @return array
	 */
	public static function progress( $command, $requester_id, $actor_id ) {
		$setup_path = $command['args']['path'] ?? '';
		if ( is_string( $setup_path ) && ( 0 === strpos( $setup_path, '/wc/v3/shipping/' ) || '/wc/v3/payment_gateways/cod' === $setup_path || '/wc-admin/options' === $setup_path ) ) {
			$read   = array(
				'tool' => 'commerce_request',
				'args' => array(
					'method' => 'GET',
					'path'   => $setup_path,
				),
			);
			$review = self::review( $read, $requester_id, $actor_id );
			if ( ! is_wp_error( $review ) ) {
				return array(
					'summary' => 'GET' === ( $command['args']['method'] ?? 'GET' ) ? __( 'Reading store setup', 'bhranti' ) : __( 'Updating store setup', 'bhranti' ),
					'details' => array(),
					'target'  => self::target( $review ),
				);
			}
		}
		$method = $command['args']['method'] ?? 'GET';
		$path   = $command['args']['path'] ?? '';
		$data   = array(
			'summary' => __( 'Checking store setup', 'bhranti' ),
			'details' => array(),
		);
		if ( ! is_string( $path ) || ! preg_match( '#\A/wc/v3/(products(?:/categories)?|settings/(?:general|products|advanced))(?:/([a-z_0-9]+))?\z#', $path, $match ) ) {
			return $data;
		}
		$data['summary'] = 'GET' === $method ? __( 'Reading store information', 'bhranti' ) : ( 'DELETE' === $method ? __( 'Requesting approval to trash a product', 'bhranti' ) : __( 'Updating store catalog or settings', 'bhranti' ) );
		$params          = $command['args']['params'] ?? array();
		$data['details'] = is_array( $params ) ? array_values( array_intersect( array_keys( $params ), array( 'name', 'description', 'short_description', 'regular_price', 'sale_price', 'sku', 'categories', 'images', 'status', 'manage_stock', 'stock_quantity', 'stock_status', 'value' ) ) ) : array();
		if ( ! empty( $match[2] ) ) {
			$read   = array(
				'tool' => 'commerce_request',
				'args' => array(
					'method' => 'GET',
					'path'   => $path,
				),
			);
			$review = self::review( $read, $requester_id, $actor_id );
			if ( ! is_wp_error( $review ) ) {
				$data['target'] = self::target( $review );
			}
		}
		return $data;
	}

	/**
	 * Execute one native catalog request or return an owned deletion proposal.
	 *
	 * @param array  $command Tool and arguments.
	 * @param int    $requester_id Human administrator.
	 * @param int    $session_id Private conversation.
	 * @param bool   $confirmed Human confirmation, never a model argument.
	 * @param string $expected_fingerprint State bound to the human approval.
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
		if ( ! user_can( $requester_id, 'manage_options' ) || ! user_can( $actor_id, 'manage_options' ) ) {
			return self::error( 'bhranti_commerce_permission', __( 'Both your account and Bhranti need permission to manage this workspace.', 'bhranti' ), 403 );
		}
		if ( self::discovery_command( $command ) ) {
			$logged = self::audit(
				'action_started',
				__( 'Checking WooCommerce store capabilities.', 'bhranti' ),
				$session_id,
				$requester_id,
				$actor_id,
				array(
					'method' => 'GET',
					'path'   => '/wc/v3',
				)
			);
			if ( is_wp_error( $logged ) ) {
				return $logged;
			}
			$data   = self::availability();
			$logged = self::audit(
				'action_completed',
				__( 'WooCommerce store capabilities retrieved.', 'bhranti' ),
				$session_id,
				$requester_id,
				$actor_id,
				array(
					'method' => 'GET',
					'path'   => '/wc/v3',
				)
			);
			return is_wp_error( $logged ) ? $logged : self::result( $data, array(), '', false, __( 'Checked store setup.', 'bhranti' ) );
		}
		$review = self::review( $command, $requester_id, $actor_id );
		if ( is_wp_error( $review ) ) {
			self::audit( 'action_blocked', __( 'A commerce request was blocked by Bhranti guardrails.', 'bhranti' ), $session_id, $requester_id, $actor_id, array( 'code' => $review->get_error_code() ) );
			return $review;
		}
		$details = array(
			'method' => $review['method'],
			'path'   => $review['path'],
		);
		if ( 'GET' === $review['method'] ) {
			$logged = self::audit( 'action_started', __( 'Reading WooCommerce catalog information.', 'bhranti' ), $session_id, $requester_id, $actor_id, $details );
			if ( is_wp_error( $logged ) ) {
				return $logged;
			}
			$data   = self::dispatch( $review, $actor_id );
			$logged = self::audit( is_wp_error( $data ) ? 'action_failed' : 'action_completed', is_wp_error( $data ) ? __( 'WooCommerce could not complete the read.', 'bhranti' ) : __( 'WooCommerce catalog information retrieved.', 'bhranti' ), $session_id, $requester_id, $actor_id, $details );
			return is_wp_error( $data ) ? $data : ( is_wp_error( $logged ) ? $logged : self::result( self::project( $data, $review['kind'] ), self::target( $review ), '', false, __( 'WooCommerce information retrieved.', 'bhranti' ) ) );
		}
		$lock = self::lock();
		if ( is_wp_error( $lock ) ) {
			return self::refused( $lock, $session_id, $requester_id, $actor_id, $details );
		}
		$dispatched = false;
		try {
			$before = self::state( $review );
			if ( is_wp_error( $before ) ) {
				return self::refused( $before, $session_id, $requester_id, $actor_id, $details );
			}
			$allowed = self::policy( $review, $before );
			if ( is_wp_error( $allowed ) ) {
				return self::refused( $allowed, $session_id, $requester_id, $actor_id, $details );
			}
			if ( 'setting' === $review['kind'] && $before['option']['exists'] ) {
				$prior      = $before['option']['value'];
				$setting    = $review['controller']->get_setting( $review['request']['group_id'], $review['id'] );
				$prior      = self::setting_input( $prior, $review['id'] );
				$valid      = self::setting_value( $review['id'], $prior, $setting, $requester_id, $actor_id );
				$validator  = 'validate_setting_' . $setting['type'] . '_field';
				$validator  = is_callable( array( $review['controller'], $validator ) ) ? $validator : 'validate_setting_text_field';
				$restorable = true === $valid ? $review['controller']->$validator( $prior, $setting ) : false;
				if ( true !== $valid || is_wp_error( $restorable ) || $restorable !== $prior ) {
					return self::refused( self::error( 'bhranti_commerce_preservation', __( 'The existing store setting cannot be safely restored. Use WooCommerce Settings for this change.', 'bhranti' ), 403 ), $session_id, $requester_id, $actor_id, $details );
				}
			}
			if ( Bhranti_Commerce_Setup::handles( $review['kind'] ) && ! $review['create'] ) {
				$restore_params = Bhranti_Commerce_Setup::restore_params( $review, array( 'before' => $before ) );
				$restorable     = Bhranti_Commerce_Setup::prepare( 'POST', $review['path'], $restore_params, true );
				if ( is_wp_error( $restorable ) ) {
					return self::refused( self::error( 'bhranti_commerce_preservation', __( 'The current store setup cannot be safely restored. Review this change in WooCommerce Settings.', 'bhranti' ), 403 ), $session_id, $requester_id, $actor_id, $details );
				}
			}
			$fingerprint = self::fingerprint( $before );
			if ( $expected_fingerprint && ! hash_equals( $expected_fingerprint, $fingerprint ) ) {
				return self::refused( self::error( 'bhranti_rollback_conflict', __( 'This product changed after approval was requested. Review it again.', 'bhranti' ), 409 ), $session_id, $requester_id, $actor_id, $details );
			}
			if ( 'DELETE' === $review['method'] && ! $confirmed ) {
				$logged = self::audit( 'action_blocked', __( 'Moving a product to Trash requires human approval.', 'bhranti' ), $session_id, $requester_id, $actor_id, $details );
				if ( is_wp_error( $logged ) ) {
					return $logged;
				}
				return array(
					'confirmation_required' => true,
					'command'               => $review['command'],
					'fingerprint'           => $fingerprint,
					'target'                => self::target( $review ),
					'summary'               => __( 'Move this product to Trash? Supported saved state can be restored with Undo.', 'bhranti' ),
				);
			}
			$id       = 'shop_' . wp_generate_uuid4();
			$snapshot = array(
				'id'           => $id,
				'kind'         => 'commerce',
				'state'        => 'prepared',
				'requester_id' => (int) $requester_id,
				'actor_id'     => $actor_id,
				'session_id'   => (int) $session_id,
				'expires'      => time() + 7 * DAY_IN_SECONDS,
				'command'      => $review['command'],
				'before'       => $before,
			);
			$saved    = self::snapshot( $session_id, $snapshot );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$details['action_id'] = $id;
			$logged               = self::audit( 'action_started', __( 'Applying a reviewed WooCommerce change.', 'bhranti' ), $session_id, $requester_id, $actor_id, $details );
			if ( is_wp_error( $logged ) ) {
				return $logged;
			}
			$fresh_review = self::review( $review['command'], $requester_id, $actor_id );
			$fresh        = self::state( $review );
			$allowed      = is_wp_error( $fresh_review ) || is_wp_error( $fresh ) ? false : self::policy( $fresh_review, $fresh );
			if ( is_wp_error( $fresh_review ) || is_wp_error( $fresh ) || true !== $allowed || ! hash_equals( $fingerprint, self::fingerprint( $fresh ) ) || get_option( 'bhranti_commerce_lock' ) !== $lock || is_wp_error( self::owner( $requester_id, $session_id ) ) ) {
				return self::refused( self::error( 'bhranti_rollback_conflict', __( 'The product, setting or permissions changed before execution. Read the target again.', 'bhranti' ), 409 ), $session_id, $requester_id, $actor_id, $details );
			}
			$dispatched = true;
			$data       = self::dispatch( $fresh_review, $actor_id );
			if ( is_wp_error( $data ) ) {
				$snapshot['state'] = 'failed';
				self::snapshot( $session_id, $snapshot );
				self::audit( 'action_failed', __( 'WooCommerce did not finalize the change. Inspect the target before retrying.', 'bhranti' ), $session_id, $requester_id, $actor_id, $details + array( 'code' => $data->get_error_code() ) );
				return self::uncertain( $data );
			}
			if ( $review['create'] ) {
				$review['id']           = absint( $data['id'] ?? 0 );
				$review['path']        .= '/' . $review['id'];
				$snapshot['created_id'] = $review['id'];
				if ( 'shipping_zone' === $review['kind'] ) {
					$review['zone_id'] = $review['id'];
				}
			}
			$after = self::state( $review );
			if ( is_wp_error( $after ) || ( 'setting' === $review['kind'] && ! self::same_setting( $after['option']['value'], $review['params']['value'] ) ) || ( Bhranti_Commerce_Setup::handles( $review['kind'] ) && ! is_wp_error( $after ) && ! Bhranti_Commerce_Setup::verified( $review, $after ) ) || ( 'visibility' === $review['kind'] && ! is_wp_error( $after ) && $after['cart'] !== $before['cart'] ) ) {
				$snapshot['state'] = 'failed';
				self::snapshot( $session_id, $snapshot );
				self::audit( 'action_failed', __( 'WooCommerce did not verify the saved change. Inspect the target before retrying.', 'bhranti' ), $session_id, $requester_id, $actor_id, $details + array( 'code' => 'bhranti_commerce_storage' ) );
				return self::uncertain( self::error( 'bhranti_commerce_storage', __( 'WooCommerce did not verify the saved change. Inspect the target before retrying.', 'bhranti' ), 500 ) );
			}
			$snapshot['state']             = 'completed';
			$snapshot['after_fingerprint'] = self::fingerprint( $after );
			$saved                         = self::snapshot( $session_id, $snapshot );
			if ( is_wp_error( $saved ) ) {
				return self::uncertain( $saved );
			}
			$logged  = self::audit( 'action_completed', __( 'WooCommerce catalog or store setting updated.', 'bhranti' ), $session_id, $requester_id, $actor_id, $details + array( 'target' => self::target( $review ) ) );
			$summary = 'DELETE' === $review['method'] ? __( 'Product moved to Trash.', 'bhranti' ) : ( 'product' === $review['kind'] ? __( 'Product saved.', 'bhranti' ) : ( 'category' === $review['kind'] ? __( 'Product category saved.', 'bhranti' ) : __( 'Store setting saved.', 'bhranti' ) ) );
			return is_wp_error( $logged ) ? self::uncertain( $logged ) : self::result( self::project( $data, $review['kind'] ), self::target( $review ), $id, true, $summary );
		} catch ( Throwable $error ) {
			// A native hook can throw after saving. Never treat that boundary as safe to replay.
			$code = 'bhranti_commerce_interrupted';
			try {
				self::audit( 'action_failed', __( 'The store action was interrupted. Inspect the target before retrying.', 'bhranti' ), $session_id, $requester_id, $actor_id, $details + array( 'code' => 'bhranti_commerce_interrupted' ) );
			} catch ( Throwable $audit_error ) {
				// The original audit may already be durable; do not expose either exception payload.
				$code = 'bhranti_audit_failed';
			}
			$error = self::error( $code, __( 'The store action was interrupted. Check the item in WordPress before trying again.', 'bhranti' ), 500 );
			return $dispatched ? self::uncertain( $error ) : $error;
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * Undo a verified owned change once, without overwriting later store activity.
	 *
	 * @param string $action_id Snapshot identifier.
	 * @param int    $requester_id Human administrator.
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
		if ( ! is_array( $record ) || 'commerce' !== ( $record['kind'] ?? '' ) || 'completed' !== ( $record['state'] ?? '' ) || (int) ( $record['requester_id'] ?? 0 ) !== (int) $requester_id || (int) ( $record['session_id'] ?? 0 ) !== (int) $session_id ) {
			return self::error( 'bhranti_rollback_missing', __( 'This rollback is unavailable or has already been used.', 'bhranti' ), 404 );
		}
		if ( (int) $record['expires'] < time() ) {
			return self::error( 'bhranti_rollback_expired', __( 'The seven-day rollback window has expired.', 'bhranti' ), 410 );
		}
		$actor_id = Bhranti_Actions::ensure_actor();
		if ( is_wp_error( $actor_id ) ) {
			return $actor_id;
		}
		$command = $record['command'];
		if ( ! empty( $record['created_id'] ) ) {
			$command['args']['path'] .= '/' . absint( $record['created_id'] );
		}
		$command['args']['method'] = 'GET';
		$command['args']['params'] = array();
		$review                    = self::review( $command, $requester_id, $actor_id, true );
		if ( is_wp_error( $review ) ) {
			return $review;
		}
		$details = array(
			'method'    => 'POST',
			'path'      => $review['path'],
			'action_id' => $action_id,
		);
		$lock    = self::lock();
		if ( is_wp_error( $lock ) ) {
			return self::refused( $lock, $session_id, $requester_id, $actor_id, $details );
		}
		try {
			$current = self::state( $review );
			if ( is_wp_error( $current ) || ! hash_equals( $record['after_fingerprint'], self::fingerprint( $current ) ) ) {
				return self::refused( self::error( 'bhranti_rollback_conflict', __( 'This store item changed after Bhranti saved it. Undo cannot overwrite later changes.', 'bhranti' ), 409 ), $session_id, $requester_id, $actor_id, $details );
			}
			$restore_command                   = $command;
			$restore_command['args']['method'] = ! empty( $record['created_id'] ) ? 'DELETE' : 'POST';
			$restore_command['args']['params'] = 'setting' === $review['kind'] ? array( 'value' => self::setting_input( $record['before']['option']['exists'] ? $record['before']['option']['value'] : $current['setting']['value'], $review['id'] ) ) : array();
			if ( Bhranti_Commerce_Setup::handles( $review['kind'] ) ) {
				$restore_command['args']['params'] = Bhranti_Commerce_Setup::restore_params( $review, $record );
				$allowed                           = Bhranti_Commerce_Setup::can_remove( $review, $record, $current );
				if ( is_wp_error( $allowed ) ) {
					return self::refused( $allowed, $session_id, $requester_id, $actor_id, $details );
				}
			}
			if ( 'product' === $review['kind'] && empty( $record['created_id'] ) ) {
				$restore_command['args']['params'] = array(
					'status'     => $record['before']['props']['status'],
					'categories' => array_map(
						static function ( $id ) {
							return array( 'id' => (int) $id ); },
						$record['before']['props']['category_ids']
					),
				);
			}
			$restore_review = self::review( $restore_command, $requester_id, $actor_id, true );
			if ( is_wp_error( $restore_review ) ) {
				return self::refused( $restore_review, $session_id, $requester_id, $actor_id, $details );
			}
			$details['method'] = $restore_review['method'];
			$allowed           = self::policy( $restore_review, $current );
			if ( is_wp_error( $allowed ) ) {
				return self::refused( $allowed, $session_id, $requester_id, $actor_id, $details );
			}
			// WooCommerce registers these native product capabilities in WC_Install::create_roles().
			// phpcs:ignore WordPress.WP.Capabilities.Unknown
			if ( 'product' === $review['kind'] && empty( $record['created_id'] ) && ( ! user_can( $requester_id, 'assign_product_terms' ) || ( in_array( $record['before']['props']['status'], array( 'publish', 'private' ), true ) && ! user_can( $requester_id, 'publish_products' ) ) ) ) {
				return self::refused( self::error( 'bhranti_commerce_permission', __( 'Your account needs permission to restore the product’s original categories and publication state.', 'bhranti' ), 403 ), $session_id, $requester_id, $actor_id, $details );
			}
			if ( ! empty( $record['created_id'] ) && 'category' === $review['kind'] && ( $current['term']['count'] || get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'parent'     => $review['id'],
					'hide_empty' => false,
					'number'     => 1,
				)
			) || get_objects_in_term( $review['id'], 'product_cat' ) ) ) {
				return self::refused( self::error( 'bhranti_rollback_conflict', __( 'This category now has children or products. Undo cannot remove it.', 'bhranti' ), 409 ), $session_id, $requester_id, $actor_id, $details );
			}
			$details = array(
				'method'    => 'POST',
				'path'      => $review['path'],
				'action_id' => $action_id,
			);
			$logged  = self::audit( 'rollback_started', __( 'Restoring supported WooCommerce saved state.', 'bhranti' ), $session_id, $requester_id, $actor_id, $details );
			if ( is_wp_error( $logged ) ) {
				return $logged;
			}
			$record['state'] = 'rolling_back';
			$saved           = self::snapshot( $session_id, $record );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$fresh        = self::state( $review );
			$fresh_review = self::review( $restore_command, $requester_id, $actor_id, true );
			$allowed      = is_wp_error( $fresh_review ) || is_wp_error( $fresh ) ? false : self::policy( $fresh_review, $fresh );
			if ( is_wp_error( $fresh ) || is_wp_error( $fresh_review ) || true !== $allowed || ! hash_equals( $record['after_fingerprint'], self::fingerprint( $fresh ) ) || get_option( 'bhranti_commerce_lock' ) !== $lock || is_wp_error( self::owner( $requester_id, $session_id ) ) ) {
				$record['state'] = 'completed';
				self::snapshot( $session_id, $record );
				return self::refused( self::error( 'bhranti_rollback_conflict', __( 'The store item changed during Undo preparation.', 'bhranti' ), 409 ), $session_id, $requester_id, $actor_id, $details );
			}
			if ( Bhranti_Commerce_Setup::handles( $review['kind'] ) ) {
				$removable = Bhranti_Commerce_Setup::can_remove( $fresh_review, $record, $fresh );
				if ( is_wp_error( $removable ) ) {
					$record['state'] = 'completed';
					self::snapshot( $session_id, $record );
					return self::refused( $removable, $session_id, $requester_id, $actor_id, $details );
				}
			}
			$data = self::restore( $fresh_review, $record, $actor_id );
			if ( is_wp_error( $data ) ) {
				$record['state'] = 'failed';
				self::snapshot( $session_id, $record );
				self::audit( 'rollback_failed', __( 'WooCommerce Undo was not finalized. Inspect the item before retrying.', 'bhranti' ), $session_id, $requester_id, $actor_id, $details );
				return $data;
			}
			$record['state'] = 'rolled_back';
			$saved           = self::snapshot( $session_id, $record );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$logged = self::audit( 'rollback_completed', __( 'Supported WooCommerce saved state restored.', 'bhranti' ), $session_id, $requester_id, $actor_id, $details );
			return is_wp_error( $logged ) ? $logged : self::result( self::project( $data, $review['kind'] ), self::target( $review ), $action_id, false, __( 'Store change undone.', 'bhranti' ) );
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * Review strict paths, real schemas and the unmodified human permissions first.
	 *
	 * @param array $command Parsed tool command.
	 * @param int   $requester_id Human administrator.
	 * @param int   $actor_id Managed actor.
	 * @param bool  $recovery Internal Undo, never supplied by the model.
	 * @return array|WP_Error
	 */
	private static function review( $command, $requester_id, $actor_id, $recovery = false ) {
		if ( ! self::availability()['available'] ) {
			return self::error( 'bhranti_commerce_missing', __( 'WooCommerce is not active. For a requested store, install and activate WooCommerce with the reviewed plugin tool, then check commerce availability again.', 'bhranti' ), 400 );
		}
		if ( ! is_array( $command ) || array_diff( array_keys( $command ), array( 'tool', 'args' ) ) || 'commerce_request' !== ( $command['tool'] ?? '' ) || ! is_array( $command['args'] ?? null ) || array_diff( array_keys( $command['args'] ), array( 'method', 'path', 'params' ) ) ) {
			return self::error( 'bhranti_commerce_invalid', __( 'Use a commerce tool and its exact argument object.', 'bhranti' ) );
		}
		$args   = $command['args'];
		$method = $args['method'] ?? '';
		$method = in_array( $method, array( 'PUT', 'PATCH' ), true ) ? 'POST' : $method;
		$path   = $args['path'] ?? '';
		$params = $args['params'] ?? array();
		if ( ! in_array( $method, array( 'GET', 'POST', 'DELETE' ), true ) || ! is_string( $path ) || ! is_array( $params ) || strlen( wp_json_encode( $params ) ) > 60000 ) {
			return self::error( 'bhranti_commerce_invalid', __( 'Use a bounded commerce request with its exact method, path and fields.', 'bhranti' ) );
		}
		$setup = Bhranti_Commerce_Setup::prepare( $method, $path, $params, $recovery );
		if ( is_wp_error( $setup ) ) {
			return $setup;
		}
		if ( is_array( $setup ) ) {
			$review     = $setup + array(
				'requester_id' => (int) $requester_id,
				'actor_id'     => (int) $actor_id,
				'recovery'     => $recovery,
				'command'      => array(
					'tool' => 'commerce_request',
					'args' => array(
						'method' => $method,
						'path'   => $path,
						'params' => $params,
					),
				),
			);
			$permission = self::permission( $review, $requester_id );
			if ( ! is_wp_error( $permission ) ) {
				$permission = self::acting(
					$review,
					$actor_id,
					static function () use ( $review, $actor_id ) {
						return self::permission( $review, $actor_id );
					}
				);
			}
			if ( is_wp_error( $permission ) ) {
				return $permission;
			}
			$allowed = self::policy( $review );
			return is_wp_error( $allowed ) ? $allowed : $review;
		}
		if ( ! preg_match( '#\A/wc/v3/(products(?:/categories)?)(?:/([1-9][0-9]*))?\z#', $path, $match ) ) {
			if ( ! is_string( $path ) || ! preg_match( '#\A/wc/v3/settings/(general|products|advanced)/(woocommerce_[a-z_]+)\z#', $path, $match ) || ! isset( self::settings()[ $match[2] ] ) || self::settings()[ $match[2] ] !== $match[1] || ! in_array( $method, array( 'GET', 'POST' ), true ) || ! is_array( $params ) ) {
				return self::error( 'bhranti_commerce_blocked', __( 'Choose a supported catalog, store-setting, shipping or cash-on-delivery action.', 'bhranti' ), 403 );
			}
			$kind = 'setting';
			$id   = $match[2];
		} else {
			$kind = 'products/categories' === $match[1] ? 'category' : 'product';
			$id   = isset( $match[2] ) ? (int) $match[2] : 0;
		}
		$create = 'POST' === $method && ! $id;
		if ( 'DELETE' === $method && ( ! $id || ( 'category' === $kind && ! $recovery ) ) ) {
			return self::error( 'bhranti_commerce_blocked', __( 'Only reversible product Trash is available; category deletion is unsupported.', 'bhranti' ), 403 );
		}
		$read_fields    = $id ? array( 'context' ) : array( 'context', 'search', 'page', 'per_page', 'status', 'category', 'sku', 'parent', 'hide_empty', 'include', 'orderby', 'order' );
		$product_fields = array( 'name', 'slug', 'status', 'type', 'description', 'short_description', 'regular_price', 'sale_price', 'sku', 'featured', 'catalog_visibility', 'virtual', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'sold_individually', 'weight', 'dimensions', 'categories', 'images', 'menu_order', 'reviews_allowed' );
		$allowed_fields = 'GET' === $method ? $read_fields : ( 'DELETE' === $method ? array() : ( 'setting' === $kind ? array( 'value' ) : ( 'category' === $kind ? array( 'name', 'slug', 'parent', 'description', 'display', 'menu_order' ) : $product_fields ) ) );
		if ( array_diff( array_keys( $params ), $allowed_fields ) || ( 'GET' !== $method && self::unsafe( $params ) ) || ( isset( $params['type'] ) && 'simple' !== $params['type'] ) || ( isset( $params['status'] ) && ! in_array( $params['status'], array( 'draft', 'pending', 'private', 'publish' ), true ) ) ) {
			return self::error( 'bhranti_commerce_invalid', __( 'Use only reviewed WooCommerce catalog fields and saved native image IDs.', 'bhranti' ) );
		}
		if ( 'GET' === $method && ! $id ) {
			if ( isset( $params['per_page'] ) && ( ! is_int( $params['per_page'] ) || $params['per_page'] < 1 || $params['per_page'] > 20 ) ) {
				return self::error( 'bhranti_commerce_invalid', __( 'Read at most 20 catalog items per request.', 'bhranti' ) );
			}
			$params['per_page'] = min( 20, max( 1, (int) ( $params['per_page'] ?? 10 ) ) );
		}
		if ( 'GET' === $method && 'setting' !== $kind ) {
			$params['context'] = 'edit';
		}
		if ( $create && 'product' === $kind ) {
			$params['type']   = 'simple';
			$params['status'] = $params['status'] ?? 'draft';
		}
		if ( isset( $params['categories'] ) ) {
			// WooCommerce registers this capability in WC_Install::create_roles().
			// phpcs:ignore WordPress.WP.Capabilities.Unknown
			if ( ! is_array( $params['categories'] ) || count( $params['categories'] ) > 20 || ! user_can( $requester_id, 'assign_product_terms' ) ) {
				return self::error( 'bhranti_commerce_permission', __( 'You must be allowed to assign product categories.', 'bhranti' ), 403 );
			}
			foreach ( $params['categories'] as $term ) {
				if ( ! is_array( $term ) || array_keys( $term ) !== array( 'id' ) || ! is_int( $term['id'] ) || ! term_exists( $term['id'], 'product_cat' ) ) {
					return self::error( 'bhranti_commerce_invalid', __( 'Product categories require actual native category IDs.', 'bhranti' ) );
				}
			}
		}
		if ( isset( $params['images'] ) ) {
			$media = Bhranti_Settings::allows( 'manage_media' );
			if ( is_wp_error( $media ) || ! is_array( $params['images'] ) || count( $params['images'] ) > 20 || ! user_can( $requester_id, 'upload_files' ) || ! user_can( $actor_id, 'upload_files' ) ) {
				return self::error( 'bhranti_commerce_permission', __( 'Native image assignment requires Media permissions.', 'bhranti' ), 403 );
			}
			foreach ( $params['images'] as $image ) {
				if ( ! is_array( $image ) || array_keys( $image ) !== array( 'id' ) || ! is_int( $image['id'] ) || ! wp_attachment_is_image( $image['id'] ) || ! user_can( $requester_id, 'read_post', $image['id'] ) || ! user_can( $actor_id, 'read_post', $image['id'] ) ) {
					return self::error( 'bhranti_commerce_invalid', __( 'Use existing readable native Media Library image IDs; remote image URLs are not accepted.', 'bhranti' ) );
				}
			}
		}
		$matched = self::handler( $method, $path, $kind );
		if ( is_wp_error( $matched ) ) {
			return $matched;
		}
		$request = new WP_REST_Request( $method, $path );
		$request->set_attributes( $matched['endpoint'] );
		$request->set_url_params( $matched['url_params'] );
		$defaults = array();
		foreach ( $matched['endpoint']['args'] ?? array() as $name => $schema ) {
			// Match native REST routing: null defaults are omitted, not sanitized as values.
			if ( isset( $schema['default'] ) ) {
				$defaults[ $name ] = $schema['default']; }
		}
		$request->set_default_params( $defaults );
		$canonical_params = $params;
		if ( 'DELETE' === $method ) {
			$params['force'] = 'category' === $kind && $recovery;
		}
		foreach ( $params as $name => $value ) {
			if ( ! isset( $matched['endpoint']['args'][ $name ] ) || ( null === $value && ! $recovery ) ) {
				return self::error( 'bhranti_commerce_invalid', __( 'A commerce field is outside the actual native controller schema.', 'bhranti' ) );
			}
			$request->set_param( $name, $value );
		}
		$valid = $request->has_valid_params();
		if ( ! is_wp_error( $valid ) ) {
			$valid = $request->sanitize_params(); }
		if ( is_wp_error( $valid ) ) {
			return self::error( 'bhranti_commerce_invalid', __( 'WooCommerce rejected a field type or value. Read the actual target before editing.', 'bhranti' ) );
		}
		$review     = array(
			'command'      => array(
				'tool' => 'commerce_request',
				'args' => array(
					'method' => $method,
					'path'   => $path,
					'params' => $canonical_params,
				),
			),
			'method'       => $method,
			'path'         => $path,
			'params'       => $params,
			'kind'         => $kind,
			'id'           => $id,
			'create'       => $create,
			'request'      => $request,
			'endpoint'     => $matched['endpoint'],
			'controller'   => $matched['controller'],
			'requester_id' => (int) $requester_id,
			'actor_id'     => (int) $actor_id,
			'recovery'     => $recovery,
		);
		$permission = self::permission( $review, $requester_id );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$permission = self::acting(
			$review,
			$actor_id,
			static function () use ( $review, $actor_id ) {
				return self::permission( $review, $actor_id );
			}
		);
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		if ( 'GET' !== $method ) {
			$allowed = self::policy( $review );
			if ( is_wp_error( $allowed ) ) {
				return $allowed; }
		}
		if ( 'setting' === $kind ) {
			$setting = $review['controller']->get_setting( $matched['url_params']['group_id'], $id );
			if ( is_wp_error( $setting ) || ( $setting['option_key'] ?? null ) !== $id ) {
				return self::error( 'bhranti_commerce_blocked', __( 'This store setting does not map to its reviewed native option.', 'bhranti' ), 403 );
			}
			if ( 'POST' === $method ) {
				$valid = self::setting_value( $id, $params['value'] ?? null, $setting, $requester_id, $actor_id );
				if ( is_wp_error( $valid ) ) {
					return $valid; }
				$validator = 'validate_setting_' . $setting['type'] . '_field';
				$validator = is_callable( array( $review['controller'], $validator ) ) ? $validator : 'validate_setting_text_field';
				$value     = $review['controller']->$validator( $params['value'], $setting );
				if ( is_wp_error( $value ) || $value !== $params['value'] ) {
					return self::error( 'bhranti_commerce_invalid', __( 'Choose a value available in the native WooCommerce setting.', 'bhranti' ) );
				}
			}
		}
		return $review;
	}

	/**
	 * Match only the actual installed Woo controllers and their native callbacks.
	 *
	 * @param string $method Reviewed HTTP method.
	 * @param string $path Exact route.
	 * @param string $kind Reviewed resource kind.
	 * @return array|WP_Error
	 */
	private static function handler( $method, $path, $kind ) {
		$classes = array(
			'product'  => 'WC_REST_Products_Controller',
			'category' => 'WC_REST_Product_Categories_Controller',
			'setting'  => 'WC_REST_Setting_Options_Controller',
		);
		$class   = $classes[ $kind ];
		$source  = realpath( WC_ABSPATH . 'includes/rest-api/Controllers/Version3/class-wc-rest-' . ( 'product' === $kind ? 'products' : ( 'category' === $kind ? 'product-categories' : 'setting-options' ) ) . '-controller.php' );
		if ( ! $source || ( new ReflectionClass( $class ) )->getFileName() !== $source ) {
			return self::error( 'bhranti_commerce_blocked', __( 'The native WooCommerce controller could not be verified.', 'bhranti' ), 403 );
		}
		foreach ( rest_get_server()->get_routes() as $route => $endpoints ) {
			if ( ! preg_match( '@^' . $route . '$@i', $path, $matches ) ) {
				continue; }
			foreach ( $endpoints as $endpoint ) {
				if ( empty( $endpoint['methods'][ $method ] ) ) {
					continue; }
				$permission      = $endpoint['permission_callback'] ?? null;
				$callback        = $endpoint['callback'] ?? null;
				$item            = 'setting' === $kind || (bool) preg_match( '#/[1-9][0-9]*\z#', $path );
				$callback_name   = 'GET' === $method ? ( $item ? 'get_item' : 'get_items' ) : ( 'DELETE' === $method ? 'delete_item' : ( $item ? 'update_item' : 'create_item' ) );
				$permission_name = 'setting' === $kind ? ( 'POST' === $method ? 'update_items_permissions_check' : 'get_items_permissions_check' ) : $callback_name . '_permissions_check';
				if ( ! is_array( $permission ) || 2 !== count( $permission ) || ! is_object( $permission[0] ) || get_class( $permission[0] ) !== $class || $permission[1] !== $permission_name ) {
					return self::error( 'bhranti_commerce_blocked', __( 'This route is not the reviewed native WooCommerce endpoint.', 'bhranti' ), 403 );
				}
				$controller = $permission[0];
				if ( $callback instanceof Closure && 'GET' === $method && 'product' === $kind ) {
					$reflection   = new ReflectionFunction( $callback );
					$captured     = $reflection->getStaticVariables()['callback'] ?? null;
					$native_cache = realpath( WC_ABSPATH . 'src/Internal/Traits/RestApiCache.php' );
					if ( $reflection->getFileName() !== $native_cache || $reflection->getClosureThis() !== $controller || ! is_array( $captured ) || $captured[0] !== $controller || $captured[1] !== $callback_name ) {
						return self::error( 'bhranti_commerce_blocked', __( 'The native product read handler could not be verified.', 'bhranti' ), 403 );
					}
				} elseif ( ! is_array( $callback ) || 2 !== count( $callback ) || $callback[0] !== $controller || $callback[1] !== $callback_name ) {
					return self::error( 'bhranti_commerce_blocked', __( 'This route is not the reviewed native WooCommerce handler.', 'bhranti' ), 403 );
				}
				$url_params = array_filter( $matches, 'is_string', ARRAY_FILTER_USE_KEY );
				return array(
					'endpoint'   => $endpoint,
					'url_params' => $url_params,
					'controller' => $controller,
				);
			}
		}
		return self::error( 'bhranti_commerce_missing', __( 'This WooCommerce capability is not available. Check that WooCommerce is active and current.', 'bhranti' ), 404 );
	}

	/**
	 * Run the unchanged native permission callback with the requested identity.
	 *
	 * @param array $review Reviewed request.
	 * @param int   $user_id Human or managed actor.
	 * @return true|WP_Error
	 */
	private static function permission( $review, $user_id ) {
		$original = get_current_user_id();
		try {
			wp_set_current_user( $user_id );
			$allowed = call_user_func( $review['endpoint']['permission_callback'], $review['request'] );
			return true === $allowed ? true : self::error( 'bhranti_commerce_permission', __( 'Both your account and Bhranti need permission for this store action.', 'bhranti' ), 403 );
		} finally {
			wp_set_current_user( $original );
		}
	}

	/**
	 * Grant only native Woo capabilities during this one human-permitted operation.
	 *
	 * @param array    $review Reviewed request.
	 * @param int      $actor_id Managed actor.
	 * @param callable $callback Native permission, dispatch or restoration.
	 * @return mixed
	 */
	private static function acting( $review, $actor_id, $callback ) {
		if ( 'setting' === $review['kind'] || Bhranti_Commerce_Setup::handles( $review['kind'] ) ) {
			$capabilities = array( 'manage_woocommerce' );
		} elseif ( 'category' === $review['kind'] ) {
			$capabilities = 'GET' === $review['method'] ? array( 'manage_product_terms' ) : ( 'DELETE' === $review['method'] ? array( 'delete_product_terms', 'manage_product_terms' ) : array( 'edit_product_terms', 'manage_product_terms' ) );
		} else {
			$capabilities = array( 'read_private_products' );
			if ( 'DELETE' === $review['method'] ) {
				$capabilities = array_merge( $capabilities, array( 'delete_products', 'delete_others_products', 'delete_published_products', 'delete_private_products' ) );
			} elseif ( 'POST' === $review['method'] ) {
				$capabilities = array_merge( $capabilities, array( 'edit_products', 'edit_others_products', 'edit_published_products', 'edit_private_products', 'publish_products' ) );
				if ( isset( $review['params']['categories'] ) || $review['recovery'] ) {
					$capabilities[] = 'assign_product_terms'; }
			}
		}
		$grant    = static function ( $allcaps, $requested, $args, $user ) use ( $actor_id, $capabilities, $review ) {
			if ( (int) $actor_id === $user->ID ) {
				foreach ( $capabilities as $capability ) {
					$allcaps[ $capability ] = user_can( $review['requester_id'], $capability ); }
			}
			return $allcaps;
		};
		$original = get_current_user_id();
		add_filter( 'user_has_cap', $grant, PHP_INT_MAX, 4 );
		try {
			wp_set_current_user( $actor_id );
			return $callback();
		} finally {
			remove_filter( 'user_has_cap', $grant, PHP_INT_MAX );
			wp_set_current_user( $original );
		}
	}

	/**
	 * Dispatch only the reviewed native endpoint through WordPress REST routing.
	 *
	 * @param array $review Reviewed request.
	 * @param int   $actor_id Managed actor.
	 * @return array|WP_Error
	 */
	private static function dispatch( $review, $actor_id ) {
		return self::acting(
			$review,
			$actor_id,
			static function () use ( $review ) {
				$response = Bhranti_Commerce_Setup::request( $review );
				if ( $response->is_error() ) {
					return self::error( 'bhranti_commerce_native', __( 'WooCommerce could not complete this action. Read the target and check its required fields before trying again.', 'bhranti' ), $response->get_status() );
				}
				return $response->get_data();
			}
		);
	}

	/**
	 * Capture native persisted state, independent of REST caches or rendered fields.
	 *
	 * @param array $review Reviewed request.
	 * @return array|WP_Error
	 */
	private static function state( $review ) {
		if ( Bhranti_Commerce_Setup::handles( $review['kind'] ) ) {
			return Bhranti_Commerce_Setup::state( $review );
		}
		if ( $review['create'] && ! $review['id'] ) {
			return array(); }
		$id = $review['id'];
		if ( 'setting' === $review['kind'] ) {
			wp_cache_delete( $id, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$sentinel = new stdClass();
			$value    = get_option( $id, $sentinel );
			$setting  = $review['controller']->get_setting( $review['request']['group_id'], $id );
			return is_wp_error( $setting ) ? self::error( 'bhranti_commerce_missing', __( 'This store setting is unavailable.', 'bhranti' ), 404 ) : array(
				'option'  => array(
					'exists'   => $sentinel !== $value,
					'value'    => $sentinel === $value ? null : $value,
					'autoload' => array_key_exists( $id, wp_load_alloptions( true ) ),
				),
				'setting' => array_intersect_key( $setting, array_flip( array( 'id', 'value', 'default', 'type' ) ) ),
			);
		}
		if ( 'category' === $review['kind'] ) {
			clean_term_cache( $id, 'product_cat' );
			wp_cache_delete( $id, 'term_meta' );
			$term = get_term( $id, 'product_cat', ARRAY_A );
			if ( ! $term || is_wp_error( $term ) ) {
				return self::error( 'bhranti_commerce_missing', __( 'This product category no longer exists.', 'bhranti' ), 404 ); }
			return array(
				'term'     => $term,
				'meta'     => get_term_meta( $id ),
				'objects'  => get_objects_in_term( $id, 'product_cat' ),
				'children' => get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'parent'     => $id,
						'hide_empty' => false,
						'fields'     => 'ids',
					)
				),
			);
		}
		clean_post_cache( $id );
		wp_cache_delete( $id, 'post_meta' );
		$post = get_post( $id, ARRAY_A );
		if ( ! $post || 'product' !== $post['post_type'] ) {
			return self::error( 'bhranti_commerce_missing', __( 'This product no longer exists.', 'bhranti' ), 404 ); }
		$product = new WC_Product_Simple( $id );
		$props   = array();
		foreach ( array( 'name', 'slug', 'status', 'description', 'short_description', 'featured', 'catalog_visibility', 'sku', 'regular_price', 'sale_price', 'virtual', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'sold_individually', 'weight', 'length', 'width', 'height', 'category_ids', 'image_id', 'gallery_image_ids', 'menu_order', 'reviews_allowed', 'low_stock_amount' ) as $name ) {
			$props[ $name ] = $product->{ 'get_' . $name }( 'edit' );
		}
		foreach ( array( 'date_on_sale_from', 'date_on_sale_to' ) as $name ) {
			$date           = $product->{ 'get_' . $name }( 'edit' );
			$props[ $name ] = $date ? $date->getTimestamp() : null;
		}
		$meta = get_post_meta( $id );
		unset( $meta['_edit_lock'], $meta['_edit_last'] );
		$terms = array();
		foreach ( get_object_taxonomies( 'product' ) as $taxonomy ) {
			$terms[ $taxonomy ] = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
		}
		return array(
			'post'  => $post,
			'meta'  => $meta,
			'terms' => $terms,
			'props' => $props,
		);
	}

	/**
	 * Restore with native Woo CRUD and WordPress APIs, never generated code.
	 *
	 * @param array $review Reviewed restoration and both permissions.
	 * @param array $record Durable prior state.
	 * @param int   $actor_id Managed actor.
	 * @return array|WP_Error
	 */
	private static function restore( $review, $record, $actor_id ) {
		return self::acting(
			$review,
			$actor_id,
			static function () use ( $review, $record ) {
				if ( Bhranti_Commerce_Setup::handles( $review['kind'] ) ) {
					return Bhranti_Commerce_Setup::restore( $review, $record );
				}
				$id     = $review['id'];
				$before = $record['before'];
				if ( 'setting' === $review['kind'] ) {
					if ( ! $before['option']['exists'] ) {
						// An absent native option has no prior value to sanitize or recreate.
						delete_option( $id );
					} else {
						$response = rest_do_request( $review['request'] );
						if ( $response->is_error() ) {
							return self::error( 'bhranti_commerce_restore', __( 'WooCommerce could not restore this setting.', 'bhranti' ), 500 );
						}
						// Preserve native sanitization filters; restore autoload only after checking their result.
						if ( ! self::same_setting( get_option( $id ), $review['params']['value'] ) ) {
							return self::error( 'bhranti_commerce_restore', __( 'WooCommerce changed the restored value. Inspect Settings before trying again.', 'bhranti' ), 500 );
						}
						// WooCommerce historically stores an empty string for an unselected country list.
						if ( '' === $before['option']['value'] && array() === self::setting_input( $before['option']['value'], $id ) ) {
							update_option( $id, '' );
						}
						if ( array_key_exists( $id, wp_load_alloptions( true ) ) !== $before['option']['autoload'] ) {
							wp_set_option_autoload_values( array( $id => $before['option']['autoload'] ) );
						}
					}
					$state = self::state( $review );
					if ( is_wp_error( $state ) || $state['option'] !== $before['option'] ) {
						return self::error( 'bhranti_commerce_restore', __( 'The restored store setting could not be verified.', 'bhranti' ), 500 );
					}
					return self::project( $state['setting'], 'setting' );
				}
				if ( 'category' === $review['kind'] ) {
					if ( ! empty( $record['created_id'] ) ) {
						$deleted = wp_delete_term( $id, 'product_cat' );
						return true === $deleted ? array(
							'id'      => $id,
							'deleted' => true,
						) : self::error( 'bhranti_commerce_restore', __( 'The empty created category could not be removed.', 'bhranti' ), 500 );
					}
					$restored = wp_update_term( $id, 'product_cat', array_intersect_key( $before['term'], array_flip( array( 'name', 'slug', 'parent', 'description' ) ) ) );
					if ( is_wp_error( $restored ) ) {
						return self::error( 'bhranti_commerce_restore', __( 'The product category could not be restored.', 'bhranti' ), 500 ); }
					foreach ( array( 'display_type', 'order' ) as $key ) {
						delete_term_meta( $id, $key );
						foreach ( $before['meta'][ $key ] ?? array() as $value ) {
							add_term_meta( $id, $key, $value ); }
					}
					$fresh  = self::state( $review );
					$fields = array_flip( array( 'name', 'slug', 'parent', 'description' ) );
					if ( is_wp_error( $fresh ) || array_intersect_key( $fresh['term'], $fields ) !== array_intersect_key( $before['term'], $fields ) ) {
						return self::error( 'bhranti_commerce_restore', __( 'The restored category details could not be verified. Inspect the category before trying again.', 'bhranti' ), 500 );
					}
					foreach ( array( 'display_type', 'order' ) as $key ) {
						if ( ( $fresh['meta'][ $key ] ?? array() ) !== ( $before['meta'][ $key ] ?? array() ) ) {
							return self::error( 'bhranti_commerce_restore', __( 'The restored category display settings could not be verified. Inspect the category before trying again.', 'bhranti' ), 500 );
						}
					}
				} elseif ( ! empty( $record['created_id'] ) ) {
					$response = rest_do_request( $review['request'] );
					if ( $response->is_error() || 'trash' !== get_post_status( $id ) ) {
						return self::error( 'bhranti_commerce_restore', __( 'The created product could not be moved to Trash.', 'bhranti' ), 500 ); }
				} else {
					if ( 'trash' === get_post_status( $id ) && ! wp_untrash_post( $id ) ) {
						return self::error( 'bhranti_commerce_restore', __( 'The product could not be restored from Trash.', 'bhranti' ), 500 ); }
					$product  = new WC_Product_Simple( $id );
					$restored = $product->set_props( $before['props'] );
					if ( is_wp_error( $restored ) || ! $product->save() ) {
						return self::error( 'bhranti_commerce_restore', __( 'The product’s saved details could not be restored.', 'bhranti' ), 500 ); }
					$core = wp_update_post(
						wp_slash(
							array(
								'ID'            => $id,
								'post_name'     => $before['post']['post_name'],
								'post_date'     => $before['post']['post_date'],
								'post_date_gmt' => $before['post']['post_date_gmt'],
								'edit_date'     => true,
							)
						),
						true
					);
					if ( is_wp_error( $core ) ) {
						return self::error( 'bhranti_commerce_restore', __( 'The product’s original saved state could not be restored.', 'bhranti' ), 500 ); }
					foreach ( array( '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_wp_old_slug' ) as $key ) {
						delete_post_meta( $id, $key );
						foreach ( $before['meta'][ $key ] ?? array() as $value ) {
							add_post_meta( $id, $key, wp_slash( $value ) ); }
					}
					$fresh = self::state( $review );
					if ( is_wp_error( $fresh ) || $fresh['props'] !== $before['props'] ) {
						return self::error( 'bhranti_commerce_restore', __( 'The restored product details could not be verified. Inspect the product before trying again.', 'bhranti' ), 500 ); }
				}
				$get = new WP_REST_Request( 'GET', $review['path'] );
				$get->set_query_params( array( 'context' => 'edit' ) );
				$response = rest_do_request( $get );
				return $response->is_error() ? self::error( 'bhranti_commerce_restore', __( 'The restored item could not be read. Inspect it in WordPress.', 'bhranti' ), 500 ) : $response->get_data();
			}
		);
	}

	/**
	 * Require the authenticated human's private conversation.
	 *
	 * @param int $requester_id Human administrator.
	 * @param int $session_id Conversation ID.
	 * @return true|WP_Error
	 */
	private static function owner( $requester_id, $session_id ) {
		clean_post_cache( $session_id );
		$post = get_post( $session_id );
		if ( get_current_user_id() !== (int) $requester_id || ! Bhranti_Settings::can_manage() || ! $post || 'bhranti_session' !== $post->post_type || 'private' !== $post->post_status || (int) $post->post_author !== (int) $requester_id ) {
			return self::error( 'bhranti_action_owner', __( 'This workspace action is not available to your account.', 'bhranti' ), 403 );
		}
		return true;
	}

	/**
	 * Recognize only the minimal capability discovery command.
	 *
	 * @param array $command Parsed command.
	 * @return bool
	 */
	private static function discovery_command( $command ) {
		return is_array( $command ) && ! array_diff( array_keys( $command ), array( 'tool', 'args' ) ) && 'commerce_request' === ( $command['tool'] ?? '' ) && is_array( $command['args'] ?? null ) && ! array_diff( array_keys( $command['args'] ), array( 'method', 'path', 'params' ) ) && 'GET' === ( $command['args']['method'] ?? '' ) && '/wc/v3' === ( $command['args']['path'] ?? '' ) && array() === ( $command['args']['params'] ?? array() );
	}

	/**
	 * Return installed availability without starting WooCommerce or installing it.
	 *
	 * @return array
	 */
	private static function availability() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins   = get_plugins();
		$file      = 'woocommerce/woocommerce.php';
		$available = defined( 'WC_ABSPATH' ) && class_exists( 'WC_REST_Products_Controller' ) && class_exists( 'WC_REST_Product_Categories_Controller' ) && class_exists( 'WC_REST_Setting_Options_Controller' ) && class_exists( 'WC_Product_Simple' ) && post_type_exists( 'product' ) && taxonomy_exists( 'product_cat' );
		$settings  = array();
		foreach ( self::settings() as $id => $group ) {
			$settings[] = array(
				'id'    => $id,
				'group' => $group,
				'path'  => '/wc/v3/settings/' . $group . '/' . $id,
			);
		}
		return array(
			'available'    => $available,
			'version'      => defined( 'WC_VERSION' ) ? WC_VERSION : ( $plugins[ $file ]['Version'] ?? '' ),
			'dependency'   => array(
				'slug'      => 'woocommerce',
				'plugin'    => 'woocommerce/woocommerce',
				'installed' => isset( $plugins[ $file ] ),
				'active'    => is_plugin_active( $file ),
			),
			'settings'     => $settings,
			'setup_routes' => Bhranti_Commerce_Setup::routes(),
			'next_step'    => $available ? 'Read the existing catalog and exact setting paths before making the requested changes.' : 'For a requested store, install directory slug woocommerce inactive with plugin_request, then activate woocommerce/woocommerce and check availability again.',
		);
	}

	/** Return only public catalog, page and inventory option identifiers. */
	private static function settings() {
		return array(
			'woocommerce_store_address'              => 'general',
			'woocommerce_store_address_2'            => 'general',
			'woocommerce_store_city'                 => 'general',
			'woocommerce_store_postcode'             => 'general',
			'woocommerce_default_country'            => 'general',
			'woocommerce_allowed_countries'          => 'general',
			'woocommerce_all_except_countries'       => 'general',
			'woocommerce_specific_allowed_countries' => 'general',
			'woocommerce_ship_to_countries'          => 'general',
			'woocommerce_specific_ship_to_countries' => 'general',
			'woocommerce_weight_unit'                => 'products',
			'woocommerce_dimension_unit'             => 'products',
			'woocommerce_currency'                   => 'general',
			'woocommerce_currency_pos'               => 'general',
			'woocommerce_price_thousand_sep'         => 'general',
			'woocommerce_price_decimal_sep'          => 'general',
			'woocommerce_price_num_decimals'         => 'general',
			'woocommerce_shop_page_id'               => 'products',
			'woocommerce_cart_redirect_after_add'    => 'products',
			'woocommerce_enable_ajax_add_to_cart'    => 'products',
			'woocommerce_manage_stock'               => 'products',
			'woocommerce_hold_stock_minutes'         => 'products',
			'woocommerce_notify_low_stock_amount'    => 'products',
			'woocommerce_notify_no_stock_amount'     => 'products',
			'woocommerce_hide_out_of_stock_items'    => 'products',
			'woocommerce_cart_page_id'               => 'advanced',
			'woocommerce_checkout_page_id'           => 'advanced',
			'woocommerce_myaccount_page_id'          => 'advanced',
		);
	}

	/**
	 * Normalize scalar option storage while retaining native country arrays.
	 *
	 * @param mixed $value Value to validate or normalize.
	 * @param mixed $id Exact native item or option ID.
	 */
	private static function setting_input( $value, $id = '' ) {
		if ( '' === $value && in_array( $id, array( 'woocommerce_all_except_countries', 'woocommerce_specific_allowed_countries', 'woocommerce_specific_ship_to_countries' ), true ) ) {
			return array();
		}
		return is_scalar( $value ) ? (string) $value : $value;
	}

	/**
	 * Compare native persisted settings without lossy array-to-string conversion.
	 *
	 * @param mixed $left First saved value.
	 * @param mixed $right Second saved value.
	 */
	private static function same_setting( $left, $right ) {
		return self::setting_input( $left ) === self::setting_input( $right );
	}

	/**
	 * Restrict selected setting values beyond the native generic value schema.
	 *
	 * @param string $id Exact option ID.
	 * @param mixed  $value Proposed scalar value.
	 * @param array  $setting Actual native setting definition.
	 * @param int    $requester_id Human administrator.
	 * @param int    $actor_id Managed actor.
	 * @return true|WP_Error
	 */
	private static function setting_value( $id, $value, $setting, $requester_id, $actor_id ) {
		$country_lists = array( 'woocommerce_all_except_countries', 'woocommerce_specific_allowed_countries', 'woocommerce_specific_ship_to_countries' );
		if ( in_array( $id, $country_lists, true ) ) {
			$valid = is_array( $value ) && count( $value ) <= 250 && ( ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
			if ( $valid ) {
				foreach ( $value as $country ) {
					if ( ! is_string( $country ) || ! isset( $setting['options'][ $country ] ) ) {
						$valid = false;
						break;
					}
				}
			}
			return $valid ? true : self::error( 'bhranti_commerce_invalid', __( 'Choose country codes available in the native store setting.', 'bhranti' ) );
		}
		if ( in_array( $id, array( 'woocommerce_store_address', 'woocommerce_store_address_2', 'woocommerce_store_city', 'woocommerce_store_postcode' ), true ) ) {
			return is_string( $value ) && mb_strlen( $value ) <= 250 && sanitize_text_field( $value ) === $value ? true : self::error( 'bhranti_commerce_invalid', __( 'Use the confirmed plain-text business address.', 'bhranti' ) );
		}
		if ( in_array( $id, array( 'woocommerce_default_country', 'woocommerce_allowed_countries', 'woocommerce_ship_to_countries', 'woocommerce_weight_unit', 'woocommerce_dimension_unit' ), true ) ) {
			return is_string( $value ) && array_key_exists( $value, $setting['options'] ?? array() ) ? true : self::error( 'bhranti_commerce_invalid', __( 'Choose a value returned by the native store setting.', 'bhranti' ) );
		}
		$valid = is_string( $value ) && mb_strlen( $value ) <= 100 && ! self::unsafe( $value );
		if ( $valid && in_array( $id, array( 'woocommerce_currency', 'woocommerce_currency_pos' ), true ) ) {
			$valid = isset( $setting['options'][ $value ] );
		} elseif ( $valid && in_array( $id, array( 'woocommerce_cart_redirect_after_add', 'woocommerce_enable_ajax_add_to_cart', 'woocommerce_manage_stock', 'woocommerce_hide_out_of_stock_items' ), true ) ) {
			$valid = in_array( $value, array( 'yes', 'no' ), true );
		} elseif ( $valid && false !== strpos( $id, '_page_id' ) ) {
			$valid = (bool) preg_match( '/\A(?:|0|[1-9][0-9]{0,9})\z/', $value );
			if ( $valid && '' !== $value && '0' !== $value ) {
				$page  = get_post( (int) $value );
				$valid = $page && 'page' === $page->post_type && 'trash' !== $page->post_status && user_can( $requester_id, 'read_post', $page->ID ) && user_can( $actor_id, 'read_post', $page->ID );
			}
		} elseif ( $valid && in_array( $id, array( 'woocommerce_price_thousand_sep', 'woocommerce_price_decimal_sep' ), true ) ) {
			$valid = in_array( $value, array( '', '.', ',', ' ', "\xc2\xa0" ), true );
		} elseif ( $valid && 'woocommerce_hold_stock_minutes' === $id && '' === $value ) {
			$valid = true;
		} elseif ( $valid ) {
			$valid = (bool) preg_match( '/\A(?:0|[1-9][0-9]{0,5})\z/', $value ) && ( 'woocommerce_price_num_decimals' !== $id || (int) $value <= 6 );
		}
		return $valid ? true : self::error( 'bhranti_commerce_invalid', __( 'Use an available currency, a saved readable page, or a valid catalog or inventory value.', 'bhranti' ) );
	}

	/**
	 * Recheck live switches and preserve existing native content before every write.
	 *
	 * @param array $review Reviewed operation.
	 * @param array $before Fresh native state.
	 * @return true|WP_Error
	 */
	private static function policy( $review, $before = array() ) {
		if ( 'GET' === $review['method'] ) {
			return true;
		}
		$allowed = $review['recovery'] ? true : Bhranti_Settings::allows( ( 'setting' === $review['kind'] || Bhranti_Commerce_Setup::handles( $review['kind'] ) ) ? 'manage_site_settings' : ( 'DELETE' === $review['method'] ? 'trash_content' : 'edit_content' ) );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( Bhranti_Commerce_Setup::handles( $review['kind'] ) ) {
			$allowed = Bhranti_Commerce_Setup::policy( $review );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}
		if ( 'product' === $review['kind'] ) {
			if ( $before ) {
				$type = wp_get_object_terms( $review['id'], 'product_type', array( 'fields' => 'slugs' ) );
				if ( is_wp_error( $type ) || ( array() !== $type && array( 'simple' ) !== $type ) || 'yes' === get_post_meta( $review['id'], '_downloadable', true ) ) {
					return self::error( 'bhranti_commerce_blocked', __( 'This tool edits simple products without downloadable files. Read other product types without changing them.', 'bhranti' ), 403 );
				}
			}
			$status       = $review['params']['status'] ?? '';
			$prior_status = $before['post']['post_status'] ?? '';
			if ( $before && ! in_array( $prior_status, array( 'draft', 'pending', 'private', 'publish', 'trash' ), true ) ) {
				return self::error( 'bhranti_commerce_preservation', __( 'This product’s publication state needs the native product editor.', 'bhranti' ), 403 );
			}
			$published = in_array( $status, array( 'publish', 'private' ), true ) || in_array( $prior_status, array( 'publish', 'private' ), true );
			if ( $published && 'DELETE' !== $review['method'] ) {
				$allowed       = $review['recovery'] ? true : Bhranti_Settings::allows( 'publish_content' );
				$actor_allowed = self::acting(
					$review,
					$review['actor_id'],
					static function () use ( $review ) {
						// WooCommerce registers this capability in WC_Install::create_roles().
						// phpcs:ignore WordPress.WP.Capabilities.Unknown
						return user_can( $review['actor_id'], 'publish_products' );
					}
				);
				// WooCommerce registers this capability in WC_Install::create_roles().
				// phpcs:ignore WordPress.WP.Capabilities.Unknown
				if ( is_wp_error( $allowed ) || ! $actor_allowed || ! user_can( $review['requester_id'], 'publish_products' ) ) {
					return self::error( 'bhranti_commerce_permission', __( 'Publishing or editing a published product requires publishing permission.', 'bhranti' ), 403 );
				}
			}
			if ( 'DELETE' === $review['method'] && ( ! defined( 'EMPTY_TRASH_DAYS' ) || ! EMPTY_TRASH_DAYS || 'trash' === ( $before['post']['post_status'] ?? '' ) ) ) {
				return self::error( 'bhranti_commerce_blocked', __( 'This product cannot be safely moved to Trash.', 'bhranti' ), 403 );
			}
			foreach ( array(
				'post_title'            => 'title_save_pre',
				'post_content'          => 'post',
				'post_excerpt'          => 'post',
				'post_content_filtered' => 'post',
			) as $field => $context ) {
				$value = $before['post'][ $field ] ?? '';
				if ( wp_kses( $value, $context ) !== $value ) {
					return self::error( 'bhranti_commerce_preservation', __( 'This product contains markup that this account cannot preserve. Use the native product editor for this change.', 'bhranti' ), 403 );
				}
			}
		} elseif ( 'category' === $review['kind'] && $before && wp_kses_post( $before['term']['description'] ) !== $before['term']['description'] ) {
			return self::error( 'bhranti_commerce_preservation', __( 'This category contains markup that this account cannot preserve. Use the native category editor for this change.', 'bhranti' ), 403 );
		}
		return true;
	}

	/**
	 * Save a durable owned snapshot before touching a store item.
	 *
	 * @param int   $session_id Private conversation.
	 * @param array $record Native rollback state.
	 * @return true|WP_Error
	 */
	private static function snapshot( $session_id, $record ) {
		$owner = self::owner( $record['requester_id'], $session_id );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		wp_cache_delete( $session_id, 'post_meta' );
		$records                  = get_post_meta( $session_id, 'bhranti_actions', true );
		$records                  = is_array( $records ) ? $records : array();
		$records[ $record['id'] ] = $record;
		if ( strlen( wp_json_encode( $records ) ) > 5 * MB_IN_BYTES ) {
			return self::error( 'bhranti_commerce_storage', __( 'This conversation has reached its recovery storage limit. Start a new chat.', 'bhranti' ), 500 );
		}
		update_post_meta( $session_id, 'bhranti_actions', wp_slash( $records ) );
		wp_cache_delete( $session_id, 'post_meta' );
		return get_post_meta( $session_id, 'bhranti_actions', true ) === $records ? true : self::error( 'bhranti_commerce_storage', __( 'The recovery record could not be saved. No new store action can start.', 'bhranti' ), 500 );
	}

	/**
	 * Fingerprint complete native saved state, including terms, images and inventory.
	 *
	 * @param array $state Native state.
	 * @return string
	 */
	private static function fingerprint( $state ) {
		return hash( 'sha256', wp_json_encode( $state ) );
	}

	/** Acquire a short site-wide native option lock for store writes. */
	private static function lock() {
		$value    = array(
			'id'      => wp_generate_uuid4(),
			'expires' => time() + 60,
		);
		$existing = get_option( 'bhranti_commerce_lock' );
		if ( is_array( $existing ) && (int) ( $existing['expires'] ?? PHP_INT_MAX ) < time() && get_option( 'bhranti_commerce_lock' ) === $existing ) {
			delete_option( 'bhranti_commerce_lock' );
		}
		return add_option( 'bhranti_commerce_lock', $value, '', false ) ? $value : self::error( 'bhranti_commerce_busy', __( 'Another store change is running. Wait for it to finish.', 'bhranti' ), 409 );
	}

	/**
	 * Release only this operation's lock.
	 *
	 * @param array $lock Owned token.
	 */
	private static function unlock( $lock ) {
		if ( get_option( 'bhranti_commerce_lock' ) === $lock ) {
			delete_option( 'bhranti_commerce_lock' );
		}
	}

	/**
	 * Project catalog data without arbitrary metadata or commercial/private records.
	 *
	 * @param mixed  $data Actual REST result.
	 * @param string $kind Reviewed resource.
	 * @return array
	 */
	private static function project( $data, $kind ) {
		if ( Bhranti_Commerce_Setup::handles( $kind ) ) {
			return Bhranti_Commerce_Setup::project( $data, $kind );
		}
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( array() === $data || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			return array_map(
				static function ( $item ) use ( $kind ) {
					return self::project( $item, $kind );
				},
				$data
			);
		}
		$fields = 'setting' === $kind ? array( 'id', 'label', 'description', 'value', 'default', 'type', 'options' ) : ( 'category' === $kind ? array( 'id', 'name', 'slug', 'parent', 'description', 'display', 'menu_order', 'count', 'deleted' ) : array( 'id', 'name', 'slug', 'permalink', 'status', 'type', 'description', 'short_description', 'sku', 'regular_price', 'sale_price', 'price', 'featured', 'catalog_visibility', 'virtual', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'sold_individually', 'weight', 'dimensions', 'categories', 'images', 'menu_order', 'reviews_allowed' ) );
		$public = array_intersect_key( $data, array_flip( $fields ) );
		if ( 'setting' === $kind && 'woocommerce_default_country' === ( $public['id'] ?? '' ) && isset( $public['options'] ) && is_array( $public['options'] ) && strlen( wp_json_encode( $public['options'] ) ) > 60000 ) {
			$public = self::compact_country_options( $public );
		}
		foreach ( array( 'images', 'categories' ) as $field ) {
			if ( isset( $public[ $field ] ) && is_array( $public[ $field ] ) ) {
				$public[ $field ] = array_map(
					static function ( $item ) use ( $field ) {
						return is_array( $item ) ? array_intersect_key( $item, array_flip( 'images' === $field ? array( 'id', 'src', 'name', 'alt' ) : array( 'id', 'name', 'slug' ) ) ) : array();
					},
					$public[ $field ]
				);
			}
		}
		return $public;
	}

	/**
	 * Share repeated country labels without losing any native option key or label.
	 *
	 * @param array $setting Public native setting with an oversized options map.
	 * @return array Lossless presentation; native validation and snapshots stay flat.
	 */
	private static function compact_country_options( $setting ) {
		$compact = $setting;
		$groups  = array();
		foreach ( $setting['options'] as $code => $label ) {
			if ( ! is_string( $code ) || ! preg_match( '/\A([A-Z]{2}):.+\z/u', $code, $match ) || ! is_string( $label ) || 1 !== preg_match( '//u', $label ) ) {
				continue;
			}
			$separator = strpos( $label, ' - ' );
			if ( false === $separator || 0 === $separator ) {
				continue;
			}
			// The ASCII separator is a complete UTF-8 boundary in a validated label.
			$prefix  = substr( $label, 0, $separator + 3 );
			$country = $match[1];
			if ( isset( $groups[ $country ] ) && $groups[ $country ]['label_prefix'] !== $prefix ) {
				continue;
			}
			$groups[ $country ]['label_prefix']     = $prefix;
			$groups[ $country ]['options'][ $code ] = substr( $label, $separator + 3 );
			unset( $compact['options'][ $code ] );
		}
		if ( ! $groups ) {
			return $setting;
		}
		$compact['option_groups'] = $groups;
		return strlen( wp_json_encode( $compact ) ) < strlen( wp_json_encode( $setting ) ) ? $compact : $setting;
	}

	/**
	 * Return a safe native destination for an existing catalog entity.
	 *
	 * @param array $review Reviewed target.
	 * @return array
	 */
	private static function target( $review ) {
		if ( Bhranti_Commerce_Setup::handles( $review['kind'] ) ) {
			return Bhranti_Commerce_Setup::target( $review );
		}
		$id = $review['id'];
		if ( 'setting' === $review['kind'] ) {
			return array(
				'id'    => 0,
				'type'  => 'woocommerce_settings',
				'title' => __( 'WooCommerce Settings', 'bhranti' ),
				'url'   => admin_url( 'admin.php?page=wc-settings&tab=' . self::settings()[ $id ] ),
			);
		}
		if ( ! $id ) {
			return array();
		}
		if ( 'category' === $review['kind'] ) {
			$term = get_term( $id, 'product_cat' );
			return array(
				'id'    => (int) $id,
				'type'  => 'product_cat',
				'title' => $term && ! is_wp_error( $term ) ? mb_substr( wp_strip_all_tags( $term->name ), 0, 150 ) : __( 'Product categories', 'bhranti' ),
				'url'   => admin_url( $term && ! is_wp_error( $term ) ? 'term.php?taxonomy=product_cat&tag_ID=' . $id . '&post_type=product' : 'edit-tags.php?taxonomy=product_cat&post_type=product' ),
			);
		}
		$post = get_post( $id );
		return $post ? array(
			'id'    => (int) $id,
			'type'  => 'product',
			'title' => mb_substr( wp_strip_all_tags( $post->post_title ), 0, 150 ),
			'url'   => admin_url( 'trash' === $post->post_status ? 'edit.php?post_type=product&post_status=trash' : 'post.php?post=' . $id . '&action=edit' ),
		) : array();
	}

	/**
	 * Standardize a verified tool result for the existing harness.
	 *
	 * @param array  $data Safe native data.
	 * @param array  $target Safe target.
	 * @param string $action_id Recovery ID.
	 * @param bool   $rollback_available Recovery availability.
	 * @param string $summary Public concise status.
	 * @return array
	 */
	private static function result( $data, $target, $action_id, $rollback_available, $summary ) {
		return array(
			'data'               => $data,
			'target'             => $target,
			'action_id'          => $action_id,
			'rollback_available' => $rollback_available,
			'summary'            => $summary,
		);
	}

	/**
	 * Persist only safe operation references, never catalog content or settings data.
	 *
	 * @param string $type Audit event.
	 * @param string $summary Fixed description.
	 * @param int    $session_id Private conversation.
	 * @param int    $requester_id Human administrator.
	 * @param int    $actor_id Managed actor.
	 * @param array  $details Safe references.
	 * @return int|WP_Error
	 */
	private static function audit( $type, $summary, $session_id, $requester_id, $actor_id, $details ) {
		if ( '/wc-admin/options' === ( $details['path'] ?? '' ) && 'GET' !== ( $details['method'] ?? 'GET' ) ) {
			$cart_id = absint( get_option( 'woocommerce_cart_page_id', 0 ) );
			if ( $cart_id ) {
				$details['target'] = array(
					'id'   => $cart_id,
					'type' => 'page',
				);
			}
		}
		return Bhranti_Audit::record( $type, $summary, $session_id, $details, $requester_id, $actor_id );
	}

	/**
	 * Record an owned post-review refusal without exposing parameters or content.
	 *
	 * @param WP_Error $error Original safe preflight error.
	 * @param int      $session_id Private conversation.
	 * @param int      $requester_id Human administrator.
	 * @param int      $actor_id Managed actor.
	 * @param array    $details Reviewed operation references.
	 * @return WP_Error
	 */
	private static function refused( $error, $session_id, $requester_id, $actor_id, $details ) {
		$logged = self::audit( 'action_blocked', __( 'The store action was stopped before changing the item.', 'bhranti' ), $session_id, $requester_id, $actor_id, $details + array( 'code' => $error->get_error_code() ) );
		return is_wp_error( $logged ) ? $logged : $error;
	}

	/**
	 * Reject executable-looking write inputs rather than modifying source files.
	 *
	 * @param mixed $value Proposed input.
	 * @return bool
	 */
	private static function unsafe( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::unsafe( $item ) ) {
					return true; }
			}
			return false;
		}
		return is_string( $value ) && ( false !== strpos( $value, '<?' ) || preg_match( '/<\s*script\b|javascript\s*:/i', $value ) );
	}

	/**
	 * Mark a possibly mutated result so the harness stops instead of replanning it.
	 *
	 * @param WP_Error $error Fixed safe error after the native write boundary.
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
	 * Return fixed actionable errors without exposing native exception payloads.
	 *
	 * @param string $code Safe diagnostic code.
	 * @param string $message Translated public copy.
	 * @param int    $status HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
