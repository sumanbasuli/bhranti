<?php
/**
 * Native store setup operations shared by the audited commerce transaction.
 *
 * @package Bhranti
 */

defined( 'ABSPATH' ) || exit;

/** Keep shipping, offline COD and shop visibility inside exact native APIs. */
final class Bhranti_Commerce_Setup {
	/**
	 * Resource kinds handled here.
	 *
	 * @param string $kind Reviewed resource kind.
	 */
	public static function handles( $kind ) {
		return in_array( $kind, array( 'shipping_zone', 'shipping_locations', 'shipping_method', 'cod', 'visibility' ), true );
	}

	/** Discover exact setup routes without disclosing gateway credentials. */
	public static function routes() {
		return array( '/wc/v3/shipping/zones', '/wc/v3/shipping/zones/{id}', '/wc/v3/shipping/zones/{id}/locations', '/wc/v3/shipping/zones/{id}/methods', '/wc/v3/shipping/zones/{id}/methods/{instance_id}', '/wc/v3/payment_gateways/cod', '/wc-admin/options' );
	}

	/**
	 * Account for the native visibility hook that re-publishes the assigned Cart.
	 *
	 * @param array $review Reviewed command and identities.
	 * @return true|WP_Error
	 */
	public static function policy( $review ) {
		if ( 'visibility' !== $review['kind'] || 'GET' === $review['method'] ) {
			return true;
		}
		$assignment = get_option( 'woocommerce_cart_page_id', 0 );
		if ( ! $assignment ) {
			return true;
		}
		if ( ( ! is_int( $assignment ) && ! is_string( $assignment ) ) || ! preg_match( '/\A[1-9][0-9]*\z/', (string) $assignment ) ) {
			return self::error( 'bhranti_commerce_preservation', __( 'Check the assigned Cart page before changing store visibility.', 'bhranti' ), 409 );
		}
		$cart_id = (int) $assignment;
		$cart    = get_post( $cart_id );
		if ( ! $cart || 'page' !== $cart->post_type || 'publish' !== $cart->post_status ) {
			return self::error( 'bhranti_commerce_preservation', __( 'Publish the assigned Cart page before changing store visibility.', 'bhranti' ), 409 );
		}
		foreach ( array( $review['requester_id'], $review['actor_id'] ) as $user_id ) {
			if ( ! user_can( $user_id, 'edit_post', $cart_id ) || ! user_can( $user_id, 'publish_pages' ) ) {
				return self::error( 'bhranti_commerce_permission', __( 'Your account needs permission to edit and publish the assigned Cart page.', 'bhranti' ), 403 );
			}
		}
		foreach ( array( 'edit_content', 'publish_content' ) as $permission ) {
			$allowed = Bhranti_Settings::allows( $permission );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}
		return true;
	}

	/**
	 * Record Cart semantics, excluding editor bookkeeping and cache-hook timestamps.
	 *
	 * @return array|WP_Error
	 */
	private static function cart_state() {
		$assignment = self::option( 'woocommerce_cart_page_id' );
		$id         = absint( $assignment['value'] ?? 0 );
		if ( ! $id ) {
			return array( 'assignment' => $assignment );
		}
		clean_post_cache( $id );
		$post = get_post( $id, ARRAY_A );
		if ( ! $post || 'page' !== $post['post_type'] ) {
			return self::error( 'bhranti_commerce_preservation', __( 'The assigned Cart page needs to be checked before changing store visibility.', 'bhranti' ), 409 );
		}
		unset( $post['post_modified'], $post['post_modified_gmt'] );
		$meta = get_post_meta( $id );
		unset( $meta['_edit_lock'], $meta['_edit_last'] );
		$terms = array();
		foreach ( get_object_taxonomies( 'page' ) as $taxonomy ) {
			$ids = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $ids ) ) {
				return self::error( 'bhranti_commerce_preservation', __( 'The assigned Cart page could not be checked.', 'bhranti' ), 409 );
			}
			sort( $ids );
			$terms[ $taxonomy ] = $ids;
		}
		return self::canonical(
			array(
				'assignment' => $assignment,
				'post'       => $post,
				'meta'       => $meta,
				'terms'      => $terms,
			)
		);
	}

	/**
	 * Build a strictly validated native request. Null means this is not a setup route.
	 *
	 * @param string $method Normalized method.
	 * @param string $path Exact route.
	 * @param array  $params Model fields.
	 * @param bool   $recovery Private owned Undo.
	 * @return array|WP_Error|null
	 */
	public static function prepare( $method, $path, $params, $recovery ) {
		$kind   = '';
		$id     = 0;
		$zone   = 0;
		$create = false;
		if ( preg_match( '#\A/wc/v3/shipping/zones(?:/(0|[1-9][0-9]*)(?:/(locations|methods)(?:/([1-9][0-9]*))?)?)?\z#', $path, $match ) ) {
			$zone   = isset( $match[1] ) ? (int) $match[1] : 0;
			$kind   = empty( $match[2] ) ? 'shipping_zone' : ( 'locations' === $match[2] ? 'shipping_locations' : 'shipping_method' );
			$id     = 'shipping_method' === $kind ? (int) ( $match[3] ?? 0 ) : $zone;
			$create = 'POST' === $method && ( ( 'shipping_zone' === $kind && ! isset( $match[1] ) ) || ( 'shipping_method' === $kind && ! isset( $match[3] ) ) );
			if ( ( 'shipping_locations' === $kind && ( ! empty( $match[3] ) || ( 'GET' !== $method && 0 === $zone ) ) ) || ( 'POST' === $method && 'shipping_zone' === $kind && isset( $match[1] ) && 0 === $zone ) || ( 'DELETE' === $method && ( ! $recovery || ! $id || ! in_array( $kind, array( 'shipping_zone', 'shipping_method' ), true ) ) ) ) {
				return self::error();
			}
		} elseif ( '/wc/v3/payment_gateways/cod' === $path ) {
			$kind = 'cod';
			$id   = 'cod';
		} elseif ( '/wc-admin/options' === $path ) {
			$kind = 'visibility';
			$id   = 'visibility';
		} else {
			return null;
		}
		if ( ! in_array( $method, array( 'GET', 'POST', 'DELETE' ), true ) || ( 'DELETE' === $method && ! in_array( $kind, array( 'shipping_zone', 'shipping_method' ), true ) ) ) {
			return self::error();
		}
		$valid = self::parameters( $kind, $method, $params, $create );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$config               = array(
			'shipping_zone'      => array( 'WC_REST_Shipping_Zones_Controller', 'includes/rest-api/Controllers/Version3/class-wc-rest-shipping-zones-controller.php' ),
			'shipping_locations' => array( 'WC_REST_Shipping_Zone_Locations_Controller', 'includes/rest-api/Controllers/Version3/class-wc-rest-shipping-zone-locations-controller.php' ),
			'shipping_method'    => array( 'WC_REST_Shipping_Zone_Methods_Controller', 'includes/rest-api/Controllers/Version3/class-wc-rest-shipping-zone-methods-controller.php' ),
			'cod'                => array( 'WC_REST_Payment_Gateways_Controller', 'includes/rest-api/Controllers/Version3/class-wc-rest-payment-gateways-controller.php' ),
			'visibility'         => array( 'Automattic\WooCommerce\Admin\API\Options', 'src/Admin/API/Options.php' ),
		);
		list( $class, $file ) = $config[ $kind ];
		if ( ! class_exists( $class ) || realpath( WC_ABSPATH . $file ) !== ( new ReflectionClass( $class ) )->getFileName() ) {
			return self::error( 'bhranti_commerce_missing', __( 'The native store setup API is not available.', 'bhranti' ), 404 );
		}
		$item     = 'shipping_method' === $kind ? (bool) $id : ( 'shipping_zone' === $kind ? isset( $match[1] ) : true );
		$callback = 'GET' === $method ? ( $item ? 'get_item' : 'get_items' ) : ( 'DELETE' === $method ? 'delete_item' : ( $create ? 'create_item' : 'update_item' ) );
		if ( 'shipping_locations' === $kind ) {
			$callback = 'GET' === $method ? 'get_items' : 'update_items';
		} elseif ( 'visibility' === $kind ) {
			$callback = 'GET' === $method ? 'get_options' : 'update_options';
		}
		$permission = 'GET' === $method ? 'get_items_permissions_check' : ( 'DELETE' === $method ? 'delete_items_permissions_check' : ( $create ? 'create_item_permissions_check' : 'update_items_permissions_check' ) );
		if ( 'visibility' === $kind ) {
			$permission = 'GET' === $method ? 'get_item_permissions_check' : 'update_item_permissions_check';
		} elseif ( 'cod' === $kind && 'GET' === $method ) {
			$permission = 'get_item_permissions_check';
		}
		$matched = null;
		foreach ( rest_get_server()->get_routes() as $route => $endpoints ) {
			if ( ! preg_match( '@^' . $route . '$@', $path, $matches ) ) {
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				if ( empty( $endpoint['methods'][ $method ] ) ) {
					continue;
				}
				$handler = $endpoint['callback'] ?? null;
				if ( ! is_array( $handler ) || count( $handler ) !== 2 || ! is_object( $handler[0] ) || get_class( $handler[0] ) !== $class || $handler[1] !== $callback || ( $endpoint['permission_callback'] ?? null ) !== array( $handler[0], $permission ) ) {
					return self::error();
				}
				$matched = array(
					'endpoint'   => $endpoint,
					'controller' => $handler[0],
					'url_params' => array_filter( $matches, 'is_string', ARRAY_FILTER_USE_KEY ),
				);
				break 2;
			}
		}
		if ( ! $matched ) {
			return self::error( 'bhranti_commerce_missing', __( 'This native store setup route is unavailable.', 'bhranti' ), 404 );
		}
		$request = new WP_REST_Request( $method, $path );
		$request->set_attributes( $matched['endpoint'] );
		$request->set_url_params( $matched['url_params'] );
		$defaults = array();
		foreach ( $matched['endpoint']['args'] ?? array() as $name => $schema ) {
			if ( isset( $schema['default'] ) ) {
				$defaults[ $name ] = $schema['default'];
			}
		}
		$request->set_default_params( $defaults );
		if ( 'GET' === $method ) {
			$request->set_query_params( 'visibility' === $kind ? array( 'options' => 'woocommerce_coming_soon,woocommerce_store_pages_only' ) : array() );
		} else {
			$body = 'DELETE' === $method && $recovery ? array( 'force' => true ) : ( 'shipping_locations' === $kind ? $params['locations'] : $params );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		$valid = $request->has_valid_params();
		if ( ! is_wp_error( $valid ) ) {
			$valid = $request->sanitize_params();
		}
		if ( is_wp_error( $valid ) ) {
			return self::error( 'bhranti_commerce_invalid', __( 'Check the store setup fields and use values returned by the native settings.', 'bhranti' ), 400 );
		}
		$review = array(
			'kind'       => $kind,
			'id'         => $id,
			'zone_id'    => $zone,
			'create'     => $create,
			'method'     => $method,
			'path'       => $path,
			'params'     => $params,
			'request'    => $request,
			'endpoint'   => $matched['endpoint'],
			'controller' => $matched['controller'],
		);
		if ( 'cod' === $kind ) {
			$gateway = $matched['controller']->get_gateway( $request );
			if ( ! self::native_class( $gateway, 'WC_Gateway_COD', 'includes/gateways/cod/class-wc-gateway-cod.php' ) ) {
				return self::error();
			}
			// Woo only exposes method options in a native settings context. Never invent them.
			$gateway->init_form_fields();
			if ( isset( $params['settings']['enable_for_methods'] ) ) {
				$options    = $gateway->form_fields['enable_for_methods']['options'] ?? array();
				$options    = is_callable( $options ) ? call_user_func( $options ) : $options;
				$valid_keys = array();
				foreach ( $options as $key => $option ) {
					if ( is_array( $option ) ) {
						$valid_keys = array_merge( $valid_keys, array_keys( $option ) );
					} else {
						$valid_keys[] = $key;
					}
				}
				if ( array_diff( $params['settings']['enable_for_methods'], $valid_keys ) ) {
					return self::error();
				}
			}
		} elseif ( 'shipping_method' === $kind && ( $id || $create ) ) {
			if ( false === WC_Shipping_Zones::get_zone_by( 'zone_id', $zone ) ) {
				return self::error( 'bhranti_commerce_missing', __( 'Read an existing shipping zone before adding methods.', 'bhranti' ), 404 );
			}
			$methods = WC_Shipping::instance()->get_shipping_method_class_names();
			$object  = $id ? self::method( $zone, $id ) : null;
			$slug    = $id && ! is_wp_error( $object ) ? $object->id : ( $params['method_id'] ?? '' );
			$classes = self::method_classes();
			if ( ! isset( $classes[ $slug ] ) || ! isset( $methods[ $slug ] ) || ( is_object( $methods[ $slug ] ) ? get_class( $methods[ $slug ] ) : $methods[ $slug ] ) !== $classes[ $slug ] || ( $id && is_wp_error( $object ) ) ) {
				return self::error();
			}
			$object = $id ? $object : new $classes[ $slug ]();
			if ( ! self::native_class( $object, $classes[ $slug ], 'includes/shipping/' . str_replace( '_', '-', $slug ) . '/class-wc-shipping-' . str_replace( '_', '-', $slug ) . '.php' ) ) {
				return self::error();
			}
			$review['method_id'] = $slug;
			$valid               = self::method_settings( $slug, $params['settings'] ?? array(), $object );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		return $review;
	}

	/**
	 * Validate the small public setup schema before any native dispatch.
	 *
	 * @param string $kind Reviewed resource kind.
	 * @param mixed  $method Native method or HTTP verb.
	 * @param array  $params Reviewed request fields.
	 * @param bool   $create Whether the operation creates an item.
	 */
	private static function parameters( $kind, $method, $params, $create ) {
		if ( 'GET' === $method || 'DELETE' === $method ) {
			return array() === $params ? true : self::error();
		}
		if ( ! $params || self::has_null( $params ) || strlen( wp_json_encode( $params ) ) > 12000 ) {
			return self::error();
		}
		$fields = array(
			'shipping_zone'      => array( 'name', 'order' ),
			'shipping_locations' => array( 'locations' ),
			'shipping_method'    => array( 'method_id', 'enabled', 'order', 'settings' ),
			'cod'                => array( 'enabled', 'title', 'description', 'settings' ),
			'visibility'         => array( 'woocommerce_coming_soon', 'woocommerce_store_pages_only' ),
		);
		if ( array_diff( array_keys( $params ), $fields[ $kind ] ) || ( isset( $params['enabled'] ) && ! is_bool( $params['enabled'] ) ) || ( isset( $params['order'] ) && ( ! is_int( $params['order'] ) || $params['order'] < 0 || $params['order'] > 1000 ) ) ) {
			return self::error();
		}
		foreach ( array( 'name', 'title', 'description' ) as $field ) {
			if ( isset( $params[ $field ] ) && ! self::text( $params[ $field ], 'description' === $field ? 2000 : 200 ) ) {
				return self::error();
			}
		}
		if ( 'shipping_zone' === $kind && $create && empty( $params['name'] ) ) {
			return self::error();
		}
		if ( 'shipping_method' === $kind && ( ( $create && ( ! is_string( $params['method_id'] ?? null ) || ! isset( self::method_classes()[ $params['method_id'] ] ) ) ) || ( ! $create && isset( $params['method_id'] ) ) || ( isset( $params['settings'] ) && ! is_array( $params['settings'] ) ) ) ) {
			return self::error();
		}
		if ( 'shipping_locations' === $kind ) {
			$locations = $params['locations'] ?? null;
			if ( ! is_array( $locations ) || count( $locations ) > 50 || ( $locations && array_keys( $locations ) !== range( 0, count( $locations ) - 1 ) ) ) {
				return self::error();
			}
			foreach ( $locations as $location ) {
				if ( ! is_array( $location ) || count( $location ) !== 2 || array_diff( array_keys( $location ), array( 'type', 'code' ) ) || ! is_string( $location['code'] ?? null ) ) {
					return self::error();
				}
				$code = $location['code'];
				$type = $location['type'] ?? '';
				if ( 'continent' === $type ) {
					$valid = isset( WC()->countries->get_continents()[ $code ] );
				} elseif ( 'country' === $type ) {
					$valid = isset( WC()->countries->get_countries()[ $code ] );
				} elseif ( 'state' === $type && preg_match( '/\A([A-Z]{2}):([A-Z0-9-]{1,10})\z/', $code, $parts ) ) {
					$valid = isset( WC()->countries->get_states( $parts[1] )[ $parts[2] ] );
				} else {
					$valid = 'postcode' === $type && (bool) preg_match( '/\A[A-Za-z0-9* .-]{1,32}\z/', $code );
				}
				if ( ! $valid ) {
					return self::error();
				}
			}
		}
		if ( 'cod' === $kind && isset( $params['settings'] ) ) {
			$settings = $params['settings'];
			if ( ! is_array( $settings ) || array_diff( array_keys( $settings ), array( 'instructions', 'enable_for_methods', 'enable_for_virtual' ) ) || ( isset( $settings['instructions'] ) && ! self::text( $settings['instructions'], 2000 ) ) || ( isset( $settings['enable_for_virtual'] ) && ! in_array( $settings['enable_for_virtual'], array( 'yes', 'no' ), true ) ) ) {
				return self::error();
			}
			if ( isset( $settings['enable_for_methods'] ) ) {
				if ( ! is_array( $settings['enable_for_methods'] ) || count( $settings['enable_for_methods'] ) > 50 ) {
					return self::error();
				}
				foreach ( $settings['enable_for_methods'] as $value ) {
					if ( ! is_string( $value ) || ! preg_match( '/\A(flat_rate|free_shipping|local_pickup)(?::([1-9][0-9]*))?\z/', $value ) ) {
						return self::error();
					}
				}
			}
		}
		if ( 'visibility' === $kind ) {
			foreach ( $params as $value ) {
				if ( ! in_array( $value, array( 'yes', 'no' ), true ) ) {
					return self::error();
				}
			}
		}
		return true;
	}

	/** Native supported shipping classes. */
	private static function method_classes() {
		return array(
			'flat_rate'     => 'WC_Shipping_Flat_Rate',
			'free_shipping' => 'WC_Shipping_Free_Shipping',
			'local_pickup'  => 'WC_Shipping_Local_Pickup',
		);
	}

	/**
	 * Verify an exact native class rather than a plugin replacement.
	 *
	 * @param object $instance Native instance.
	 * @param string $class_name Expected native class.
	 * @param string $file Native source relative to WooCommerce.
	 */
	private static function native_class( $instance, $class_name, $file ) {
		return is_object( $instance ) && get_class( $instance ) === $class_name && realpath( WC_ABSPATH . $file ) === ( new ReflectionClass( $class_name ) )->getFileName();
	}

	/**
	 * Only small plain customer-facing text is accepted in setup settings.
	 *
	 * @param mixed $value Value to validate or normalize.
	 * @param int   $length Maximum text length.
	 */
	private static function text( $value, $length ) {
		return is_string( $value ) && mb_strlen( $value ) <= $length && sanitize_textarea_field( $value ) === $value;
	}

	/**
	 * Restrict shipping costs to numbers, never expressions, placeholders or arbitrary settings.
	 *
	 * @param string $slug Native shipping method identifier.
	 * @param array  $settings Requested settings.
	 * @param mixed  $method Native method or HTTP verb.
	 */
	private static function method_settings( $slug, $settings, $method ) {
		$fields = 'free_shipping' === $slug ? array( 'title', 'requires', 'min_amount', 'ignore_discounts' ) : array( 'title', 'tax_status', 'cost' );
		if ( ! is_array( $settings ) || array_diff( array_keys( $settings ), $fields ) ) {
			return self::error();
		}
		$native = $method->get_instance_form_fields();
		foreach ( $settings as $key => $value ) {
			if ( ! isset( $native[ $key ] ) || ! is_string( $value ) || ( 'title' === $key && ! self::text( $value, 200 ) ) || ( in_array( $key, array( 'cost', 'min_amount' ), true ) && ! preg_match( '/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,6})?\z/', $value ) ) || ( in_array( $key, array( 'tax_status', 'requires' ), true ) && ! array_key_exists( $value, $native[ $key ]['options'] ?? array() ) ) || ( 'ignore_discounts' === $key && ! in_array( $value, array( 'yes', 'no' ), true ) ) ) {
				return self::error();
			}
		}
		return true;
	}

	/**
	 * Read a specific method from native zone CRUD, never from a supplied option name.
	 *
	 * @param int   $zone_id Native zone ID.
	 * @param mixed $id Exact native item or option ID.
	 */
	private static function method( $zone_id, $id ) {
		$zone = WC_Shipping_Zones::get_zone_by( 'zone_id', $zone_id );
		if ( false === $zone ) {
			return self::error( 'bhranti_commerce_missing', __( 'This shipping zone no longer exists.', 'bhranti' ), 404 );
		}
		foreach ( $zone->get_shipping_methods() as $method ) {
			if ( (int) $method->instance_id === (int) $id ) {
				return $method;
			}
		}
		return self::error( 'bhranti_commerce_missing', __( 'This shipping method no longer exists.', 'bhranti' ), 404 );
	}

	/**
	 * Native option state including absence and autoload, kept only in private Undo.
	 *
	 * @param string $name Exact native option name.
	 */
	private static function option( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$absent = new stdClass();
		$value  = get_option( $name, $absent );
		return array(
			'exists'   => $value !== $absent,
			'value'    => $value === $absent ? null : $value,
			'autoload' => array_key_exists( $name, wp_load_alloptions( true ) ),
		);
	}

	/**
	 * Capture persisted native state for execution and conflict-safe Undo.
	 *
	 * @param array $review Validated operation and identities.
	 */
	public static function state( $review ) {
		$kind = $review['kind'];
		if ( 'shipping_zone' === $kind && $review['create'] && ! $review['id'] ) {
			return array();
		}
		if ( 'visibility' === $kind ) {
			$cart = self::cart_state();
			if ( is_wp_error( $cart ) ) {
				return $cart;
			}
			return array(
				'woocommerce_coming_soon'      => self::option( 'woocommerce_coming_soon' ),
				'woocommerce_store_pages_only' => self::option( 'woocommerce_store_pages_only' ),
				'cart'                         => $cart,
			);
		}
		if ( 'cod' === $kind ) {
			return array( 'option' => self::option( 'woocommerce_cod_settings' ) );
		}
		$zone = WC_Shipping_Zones::get_zone_by( 'zone_id', $review['zone_id'] );
		if ( false === $zone ) {
			return self::error( 'bhranti_commerce_missing', __( 'This shipping zone no longer exists.', 'bhranti' ), 404 );
		}
		$zone_data = array(
			'id'        => $zone->get_id(),
			'name'      => $zone->get_zone_name( 'edit' ),
			'order'     => $zone->get_zone_order( 'edit' ),
			'locations' => array_map(
				static function ( $value ) {
					return (array) $value;
				},
				$zone->get_zone_locations( 'edit' )
			),
		);
		$raw       = WC_Data_Store::load( 'shipping-zone' )->get_methods( $review['zone_id'], false );
		$methods   = array_map(
			static function ( $value ) {
				return (array) $value;
			},
			$raw
		);
		if ( 'shipping_method' !== $kind || ( $review['create'] && ! $review['id'] ) ) {
			return array(
				'zone'    => $zone_data,
				'methods' => $methods,
			);
		}
		foreach ( $methods as $method ) {
			if ( (int) $method['instance_id'] === (int) $review['id'] ) {
				return array(
					'zone'   => $zone_data,
					'method' => $method,
					'option' => self::option( 'woocommerce_' . $method['method_id'] . '_' . $review['id'] . '_settings' ),
				);
			}
		}
		return self::error( 'bhranti_commerce_missing', __( 'This shipping method no longer exists.', 'bhranti' ), 404 );
	}

	/**
	 * Build a restoration command entirely from the owned recorded state.
	 *
	 * @param array $review Validated operation and identities.
	 * @param array $record Owned durable recovery record.
	 */
	public static function restore_params( $review, $record ) {
		$before = $record['before'];
		if ( ! empty( $record['created_id'] ) ) {
			return array();
		}
		switch ( $review['kind'] ) {
			case 'shipping_zone':
				return array(
					'name'  => $before['zone']['name'],
					'order' => (int) $before['zone']['order'],
				);
			case 'shipping_locations':
				return array( 'locations' => $before['zone']['locations'] );
			case 'shipping_method':
				return array(
					'enabled' => (bool) $before['method']['is_enabled'],
					'order'   => (int) $before['method']['method_order'],
				);
			case 'cod':
				return array( 'enabled' => 'yes' === ( $before['option']['value']['enabled'] ?? 'no' ) );
			case 'visibility':
				return array_map(
					static function ( $option ) {
						return $option['exists'] ? $option['value'] : 'no';
					},
					array_intersect_key( $before, array_flip( array( 'woocommerce_coming_soon', 'woocommerce_store_pages_only' ) ) )
				);
		}
		return array();
	}

	/**
	 * Refuse creation Undo when the created resource acquired dependent state.
	 *
	 * @param array $review Validated operation and identities.
	 * @param array $record Owned durable recovery record.
	 * @param array $current Current native saved state.
	 */
	public static function can_remove( $review, $record, $current ) {
		if ( empty( $record['created_id'] ) ) {
			return true;
		}
		if ( 'shipping_zone' === $review['kind'] ) {
			return empty( $current['zone']['locations'] ) && empty( $current['methods'] ) ? true : self::error( 'bhranti_rollback_conflict', __( 'This shipping zone now has locations or methods. Undo those changes first.', 'bhranti' ), 409 );
		}
		if ( 'shipping_method' === $review['kind'] ) {
			$cod = get_option( 'woocommerce_cod_settings', array() );
			if ( in_array( $current['method']['method_id'] . ':' . $review['id'], $cod['enable_for_methods'] ?? array(), true ) ) {
				return self::error( 'bhranti_rollback_conflict', __( 'Cash on delivery now depends on this shipping method. Update that setting first.', 'bhranti' ), 409 );
			}
		}
		return true;
	}

	/**
	 * Restore native options only after native controllers checked the exact operation.
	 *
	 * @param array $review Validated operation and identities.
	 * @param array $record Owned durable recovery record.
	 */
	public static function restore( $review, $record ) {
		$response = self::request( $review );
		if ( $response->is_error() ) {
			return self::error( 'bhranti_commerce_restore', __( 'The native store setup could not be restored.', 'bhranti' ), 500 );
		}
		if ( ! empty( $record['created_id'] ) ) {
			$remaining = 'shipping_zone' === $review['kind'] ? WC_Shipping_Zones::get_zone_by( 'zone_id', $review['id'] ) : self::method( $review['zone_id'], $review['id'] );
			if ( ( 'shipping_zone' === $review['kind'] && false !== $remaining ) || ( 'shipping_method' === $review['kind'] && ! is_wp_error( $remaining ) ) ) {
				return self::error( 'bhranti_commerce_restore', __( 'Removal of the created setup item could not be verified.', 'bhranti' ), 500 );
			}
			return array(
				'id'      => $review['id'],
				'deleted' => true,
			);
		}
		$before  = $record['before'];
		$options = array();
		if ( 'visibility' === $review['kind'] ) {
			$options = array_intersect_key( $before, array_flip( array( 'woocommerce_coming_soon', 'woocommerce_store_pages_only' ) ) );
		} elseif ( 'cod' === $review['kind'] ) {
			$options['woocommerce_cod_settings'] = $before['option'];
		} elseif ( 'shipping_method' === $review['kind'] ) {
			$options[ 'woocommerce_' . $before['method']['method_id'] . '_' . $review['id'] . '_settings' ] = $before['option'];
		}
		foreach ( $options as $name => $option ) {
			if ( ! $option['exists'] ) {
				delete_option( $name );
			} else {
				$value = $option['value'];
				if ( 'cod' === $review['kind'] || 'shipping_method' === $review['kind'] ) {
					$object = 'cod' === $review['kind'] ? $review['controller']->get_gateway( $review['request'] ) : self::method( $review['zone_id'], $review['id'] );
					$hook   = 'cod' === $review['kind'] ? 'woocommerce_gateway_cod_settings_values' : 'woocommerce_shipping_' . $before['method']['method_id'] . '_instance_settings_values';
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Reapply the exact native WooCommerce settings filter before restoring owned state.
					if ( is_wp_error( $object ) || apply_filters( $hook, $value, $object ) !== $value ) {
						return self::error( 'bhranti_commerce_restore', __( 'A native settings filter changed the restoration. Inspect the setting before retrying.', 'bhranti' ), 500 );
					}
				}
				update_option( $name, $value );
				if ( array_key_exists( $name, wp_load_alloptions( true ) ) !== $option['autoload'] ) {
					wp_set_option_autoload_values( array( $name => $option['autoload'] ) );
				}
			}
		}
		$after = self::state( $review );
		if ( is_wp_error( $after ) || $after !== $before ) {
			return self::error( 'bhranti_commerce_restore', __( 'The restored store setup could not be verified.', 'bhranti' ), 500 );
		}
		return self::project( $response->get_data(), $review['kind'] );
	}

	/**
	 * Verify requested values in persisted state, never trust a success response alone.
	 *
	 * @param array $review Validated operation and identities.
	 * @param array $state Native persisted state.
	 */
	public static function verified( $review, $state ) {
		foreach ( $review['params'] as $key => $value ) {
			if ( 'visibility' === $review['kind'] ) {
				$actual = $state[ $key ]['value'] ?? null;
			} elseif ( 'shipping_zone' === $review['kind'] ) {
				$actual = $state['zone'][ $key ] ?? null;
			} elseif ( 'shipping_locations' === $review['kind'] ) {
				$actual = $state['zone']['locations'];
				$value  = array_map(
					static function ( $location ) {
						if ( 'postcode' === $location['type'] ) {
							$location['code'] = trim( strtoupper( $location['code'] ) );
						}
						return $location;
					},
					$value
				);
			} elseif ( 'cod' === $review['kind'] ) {
				$actual = 'settings' === $key ? array_intersect_key( $state['option']['value'] ?? array(), $value ) : ( $state['option']['value'][ $key ] ?? null );
				if ( 'enabled' === $key ) {
					$value = $value ? 'yes' : 'no';
				}
			} else {
				$actual = 'settings' === $key ? array_intersect_key( $state['option']['value'] ?? array(), $value ) : ( $state['method'][ array(
					'enabled'   => 'is_enabled',
					'order'     => 'method_order',
					'method_id' => 'method_id',
				)[ $key ] ] ?? null );
				if ( 'enabled' === $key || 'order' === $key ) {
					$actual = 'enabled' === $key ? (bool) $actual : (int) $actual;
				}
			}
			if ( self::canonical( $actual ) !== self::canonical( $value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Project only customer-facing setup fields; never arbitrary payment settings.
	 *
	 * @param mixed  $data Native response data.
	 * @param string $kind Reviewed resource kind.
	 */
	public static function project( $data, $kind ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( array() === $data || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			return array_map(
				static function ( $item ) use ( $kind ) {
					return self::project( $item, $kind );
				},
				array_slice( $data, 0, 50 )
			);
		}
		$fields = array(
			'shipping_zone'      => array( 'id', 'name', 'order', 'deleted' ),
			'shipping_locations' => array( 'code', 'type' ),
			'shipping_method'    => array( 'id', 'method_id', 'title', 'order', 'enabled', 'settings', 'deleted' ),
			'cod'                => array( 'id', 'enabled', 'title', 'description', 'settings' ),
			'visibility'         => array( 'woocommerce_coming_soon', 'woocommerce_store_pages_only' ),
		);
		$data   = array_intersect_key( $data, array_flip( $fields[ $kind ] ) );
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$keys             = 'cod' === $kind ? array( 'instructions', 'enable_for_methods', 'enable_for_virtual' ) : array( 'title', 'tax_status', 'cost', 'requires', 'min_amount', 'ignore_discounts' );
			$data['settings'] = array_intersect_key( $data['settings'], array_flip( $keys ) );
			foreach ( $data['settings'] as &$setting ) {
				$setting = is_array( $setting ) ? array_intersect_key( $setting, array_flip( array( 'id', 'label', 'type', 'value', 'default', 'options' ) ) ) : array();
			}
			unset( $setting );
		}
		return $data;
	}

	/**
	 * Native Settings destination retained in the chat action timeline.
	 *
	 * @param array $review Validated operation and identities.
	 */
	public static function target( $review ) {
		$tab = 'cod' === $review['kind'] ? 'checkout' : ( 'visibility' === $review['kind'] ? 'site-visibility' : 'shipping' );
		return array(
			'id'    => 0,
			'type'  => 'woocommerce_settings',
			'title' => __( 'WooCommerce Settings', 'bhranti' ),
			'url'   => admin_url( 'admin.php?page=wc-settings&tab=' . $tab ),
		);
	}

	/**
	 * Dispatch with native integer formatting for Woo's unformatted boolean update.
	 *
	 * Woo's shipping controller passes false without a format; wpdb otherwise sends
	 * an empty string, which SQLite rejects for its native integer is_enabled field.
	 * Keep REST validation, permissions and callbacks intact and restore the format.
	 *
	 * @param array $review Validated operation.
	 * @return WP_REST_Response Native REST response.
	 */
	public static function request( $review ) {
		if ( 'shipping_method' !== $review['kind'] || 'GET' === $review['method'] ) {
			return rest_do_request( $review['request'] );
		}
		global $wpdb;
		$had_format                      = array_key_exists( 'is_enabled', $wpdb->field_types );
		$format                          = $wpdb->field_types['is_enabled'] ?? null;
		$wpdb->field_types['is_enabled'] = '%d';
		try {
			return rest_do_request( $review['request'] );
		} finally {
			if ( $had_format ) {
				$wpdb->field_types['is_enabled'] = $format;
			} else {
				unset( $wpdb->field_types['is_enabled'] );
			}
		}
	}

	/**
	 * Canonicalize object key order without changing list order or scalar types.
	 *
	 * @param mixed $value Value to validate or normalize.
	 */
	private static function canonical( $value ) {
		if ( is_array( $value ) ) {
			if ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				ksort( $value );
			}
			$value = array_map( array( self::class, 'canonical' ), $value );
		}
		return $value;
	}

	/**
	 * Reject nulls at the public boundary; native defaults remain native.
	 *
	 * @param array $values Fields to inspect.
	 */
	private static function has_null( $values ) {
		foreach ( $values as $value ) {
			if ( null === $value || ( is_array( $value ) && self::has_null( $value ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Safe setup errors never echo native field values or credentials.
	 *
	 * @param string $code Safe error identifier.
	 * @param string $message Public error text.
	 * @param int    $status HTTP error status.
	 */
	private static function error( $code = 'bhranti_commerce_blocked', $message = '', $status = 403 ) {
		return new WP_Error( $code, $message ? $message : __( 'Use the reviewed native shipping, cash-on-delivery or shop visibility fields.', 'bhranti' ), array( 'status' => $status ) );
	}
}
