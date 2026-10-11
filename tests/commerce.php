<?php
/**
 * Genuine WooCommerce REST/product-store regression on the explicitly selected site.
 *
 * This does not install, activate, bootstrap or initialize WooCommerce. The pinned
 * official source lives outside the site; only fresh owned fixtures are persisted.
 * Run only during the coordinated fixture window, with the integration guards.
 *
 * @package Bhranti
 */

define( 'BHRANTI_RENDER_QA_SUPPORT_ONLY', true );
require __DIR__ . '/rendered-messages.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( 1 !== get_current_blog_id() || 11 !== (int) get_option( 'bhranti_actor_id' ) || ! class_exists( 'Bhranti_Commerce' ) || realpath( BHRANTI_FILE ) !== realpath( WP_PLUGIN_DIR . '/bhranti/bhranti.php' ) ) {
	throw new RuntimeException( 'Deploy the reviewed commerce adapter on the exact site before creating fixtures.' );
}
if ( function_exists( 'WC' ) || defined( 'WC_PLUGIN_FILE' ) || class_exists( 'WooCommerce', false ) || is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
	throw new RuntimeException( 'This isolated-source suite requires WooCommerce to remain uninstalled and inactive.' );
}

$co_source = '/private/tmp/bhranti-woocommerce-full-source/woocommerce';
$co_zip    = '/private/tmp/bhranti-woocommerce-source.zip';
if ( '5317434cb381b5fdc7c1dd6ee258324f08c390976aaf8d1199f2a0e9a3ab8586' !== hash_file( 'sha256', $co_zip ) || ! str_contains( file_get_contents( $co_source . '/woocommerce.php' ), 'Version: 11.2.1' ) ) {
	throw new RuntimeException( 'Unexpected official WooCommerce source; no fixture created.' );
}

$co_checks = 0;
$co_http = 0;
$co_user = 0;
$co_other = 0;
$co_session = 0;
$co_products = array();
$co_categories = array();
$co_taxonomy_terms = array();
$co_table_owned = false;
$co_failure = null;
$co_clean = true;
$co_original_user = get_current_user_id();
$co_marker = 'bhranti-commerce-qa-' . str_replace( '-', '', wp_generate_uuid4() );
$co_checkpoint_path = '/private/tmp/' . $co_marker . '.json';
$co_log_dir = '/private/tmp/' . $co_marker . '-logs/';
$co_tax_cache_found = false;
$co_tax_cache_saved = false;
$co_tax_cache_original = null;
if ( file_exists( $co_log_dir ) || wp_using_ext_object_cache() || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_tax_rate_classes' ) ) ) || $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", $wpdb->esc_like( 'woocommerce_' ) . '%' ) ) ) {
	throw new RuntimeException( 'Process-local empty native tax configuration requires no existing Woo options/table/external cache or owned log path.' );
}
$co_prior_posts = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) );
$co_prior_terms = array_map( 'intval', $wpdb->get_col( "SELECT term_id FROM {$wpdb->terms}" ) );
$co_prior_users = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->users}" ) );
$co_option_originals = array();
$co_option_observed = array();
$co_restoring = false;
$co_table = $wpdb->prefix . 'wc_product_meta_lookup';
if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $co_table ) || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $co_table ) ) ) ) {
	throw new RuntimeException( 'Native product lookup table already exists; do not use or replace it.' );
}
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_product_attributes_lookup' ) ) ) ) {
	throw new RuntimeException( 'Existing attribute lookup could schedule real store jobs; no fixtures created.' );
}
if ( $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') LIMIT 1" ) || $wpdb->get_var( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('product_cat','product_type','product_visibility') LIMIT 1" ) ) {
	throw new RuntimeException( 'Existing commerce content requires a separately reviewed fixture plan.' );
}

/** Fail with a safe label instead of dumping native/private payloads. */
function co_check( $ok, $label ) {
	if ( ! $ok ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	++$GLOBALS['co_checks'];
}

/** Exact persisted option bytes and autoload, including absence. */
function co_raw_option( $name ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name=%s", $name ), ARRAY_A );
}

/** Fingerprint ordinary assets, all users/options and existing private row bytes. */
function co_baseline() {
	global $wpdb;
	$queries = array(
		'posts' => "SELECT * FROM {$wpdb->posts} ORDER BY ID",
		'meta' => "SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id",
		'terms' => "SELECT * FROM {$wpdb->terms} ORDER BY term_id",
		'taxonomy' => "SELECT * FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id",
		'term_meta' => "SELECT * FROM {$wpdb->termmeta} ORDER BY meta_id",
		'relationships' => "SELECT * FROM {$wpdb->term_relationships} ORDER BY object_id,term_taxonomy_id",
		'comments' => "SELECT * FROM {$wpdb->comments} ORDER BY comment_ID",
		'comment_meta' => "SELECT * FROM {$wpdb->commentmeta} ORDER BY meta_id",
		'users' => "SELECT * FROM {$wpdb->users} ORDER BY ID",
		'user_meta' => "SELECT * FROM {$wpdb->usermeta} ORDER BY umeta_id",
		'options' => "SELECT option_name,option_value,autoload FROM {$wpdb->options} ORDER BY option_name",
	);
	$state = array();
	foreach ( $queries as $name => $query ) {
		$state[ $name ] = hash( 'sha256', serialize( $wpdb->get_results( $query, ARRAY_A ) ) );
	}
	$uploads = wp_get_upload_dir()['basedir'];
	$files = array();
	if ( is_dir( $uploads ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $uploads, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() ) {
				$files[ $file->getPathname() ] = hash_file( 'sha256', $file->getPathname() );
			}
		}
	}
	ksort( $files );
	$state['uploads'] = hash( 'sha256', serialize( $files ) );
	$GLOBALS['co_latest_upload_files'] = $files;
	return $state;
}

/** Persist before mutation and after observing each fresh ID/option. */
function co_checkpoint() {
	global $co_checkpoint_path, $co_marker, $co_user, $co_other, $co_session, $co_products, $co_categories, $co_taxonomy_terms, $co_prior_posts, $co_prior_terms, $co_prior_users, $co_before, $co_option_originals, $co_option_observed, $co_table, $co_table_owned, $co_log_dir, $co_upload_files;
	$encode = static function ( $rows ) {
		foreach ( $rows as &$row ) {
			if ( is_array( $row ) ) {
				$row['option_value_base64'] = base64_encode( $row['option_value'] );
				unset( $row['option_value'] );
			}
		}
		return $rows;
	};
	$data = wp_json_encode( array(
		'site' => home_url( '/' ), 'marker' => $co_marker, 'users' => array( $co_user, $co_other ), 'session' => $co_session,
		'products' => $co_products, 'categories' => $co_categories, 'native_type_terms' => $co_taxonomy_terms, 'original_post_ids' => $co_prior_posts,
		'original_term_ids' => $co_prior_terms, 'original_user_ids' => $co_prior_users, 'baseline_hashes' => $co_before,
		'raw_option_originals' => $encode( $co_option_originals ), 'raw_option_last_observed' => $encode( $co_option_observed ),
		'lookup_table' => $co_table, 'lookup_table_owned' => $co_table_owned, 'source_version' => '11.2.1',
		'owned_log_directory' => $co_log_dir, 'baseline_upload_files' => $co_upload_files,
	) );
	$pending = $co_checkpoint_path . '.pending';
	$handle = fopen( $pending, 'x' );
	if ( ! $handle || ! chmod( $pending, 0600 ) ) {
		throw new RuntimeException( 'Cannot create private recovery checkpoint.' );
	}
	try {
		if ( strlen( $data ) !== fwrite( $handle, $data ) || ! fflush( $handle ) ) {
			throw new RuntimeException( 'Incomplete recovery checkpoint.' );
		}
	} finally {
		fclose( $handle );
	}
	if ( ! rename( $pending, $co_checkpoint_path ) ) {
		throw new RuntimeException( 'Cannot commit recovery checkpoint.' );
	}
}

/** Observe only reviewed native Woo cache/options and the one test policy option. */
function co_observe_option( $name ) {
	global $co_option_originals, $co_option_observed, $co_restoring;
	if ( $co_restoring ) {
		return;
	}
	if ( ! isset( $co_option_originals[ $name ] ) && ! array_key_exists( $name, $co_option_originals ) ) {
		throw new RuntimeException( 'Uncheckpointed option mutation: ' . sanitize_key( $name ) );
	}
	$co_option_observed[ $name ] = co_raw_option( $name );
	co_checkpoint();
}

/** Refuse unreviewed option writes before persistence, while recovery is still exact. */
function co_guard_option( $name ) {
	if ( ! $GLOBALS['co_restoring'] && ! array_key_exists( $name, $GLOBALS['co_option_originals'] ) ) {
		throw new RuntimeException( 'Unreviewed option mutation blocked before persistence: ' . sanitize_key( $name ) );
	}
}

/** Direct native REST dispatch retains genuine schema and permission callbacks. */
function co_native( $method, $path, $params = array() ) {
	$request = new WP_REST_Request( $method, $path );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_body_params( $params );
	}
	return rest_do_request( $request );
}

/** Execute the reviewed adapter, with real requester/session ownership. */
function co_action( $method, $path, $params = array(), $confirmed = false, $fingerprint = '' ) {
	return Bhranti_Actions::execute( array( 'tool' => 'commerce_request', 'args' => array( 'method' => $method, 'path' => $path, 'params' => $params ) ), $GLOBALS['co_user'], $GLOBALS['co_session'], $confirmed, $fingerprint );
}

function co_success( $result, $label ) {
	co_check( is_array( $result ) && ! is_wp_error( $result ), $label . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );
	return $result;
}

function co_denied( $result, $label ) {
	co_check( is_wp_error( $result ), $label );
}

function co_error( $result, $code, $label ) {
	co_check( is_wp_error( $result ) && $code === $result->get_error_code(), $label . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );
}

/** Core applies rest_endpoints before normalizing raw native method strings. */
function co_endpoint_get( $endpoint ) {
	$methods = $endpoint['methods'] ?? '';
	return is_array( $methods ) ? ! empty( $methods['GET'] ) : str_contains( $methods, 'GET' );
}

function co_category_state( $id ) {
	$term = get_term( $id, 'product_cat', ARRAY_A );
	return array( 'term' => array_intersect_key( $term, array_flip( array( 'name', 'slug', 'parent', 'description' ) ) ), 'display' => get_term_meta( $id, 'display_type' ), 'order' => get_term_meta( $id, 'order' ) );
}

function co_undo( $action_id ) {
	return Bhranti_Actions::rollback( $action_id, $GLOBALS['co_user'], $GLOBALS['co_session'] );
}

/** Change the actual current policy through the native revision-bound Settings API. */
function co_policy( $key, $allowed ) {
	$settings = co_native( 'GET', '/bhranti/v1/settings' );
	co_check( 200 === $settings->get_status(), 'Read current revision-bound Settings for temporary policy fixture' );
	$data = $settings->get_data();
	$data['config']['permissions'][ $key ] = $allowed;
	$request = new WP_REST_Request( 'POST', '/bhranti/v1/settings' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'config' => $data['config'], 'revision' => $data['revision'] ) ) );
	$saved = rest_do_request( $request );
	co_check( 200 === $saved->get_status(), 'Acknowledge the current policy fixture revision (' . ( $saved->get_data()['code'] ?? $saved->get_status() ) . ')' );
}

/** Public/native product edit state without time-dependent response decoration. */
function co_product_state( $id ) {
	$product = new WC_Product_Simple( $id );
	$props = array( 'name', 'description', 'short_description', 'status', 'slug', 'sku', 'regular_price', 'sale_price', 'price', 'virtual', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount', 'weight', 'length', 'width', 'height', 'category_ids', 'image_id', 'gallery_image_ids' );
	$state = array();
	foreach ( $props as $prop ) {
		$state[ $prop ] = $product->{'get_' . $prop}( 'edit' );
	}
	return $state;
}

/** File-only autoload glue: all controller/model/service implementations are official. */
function co_load_native() {
	global $co_source, $wpdb;
	define( 'WC_PLUGIN_FILE', $co_source . '/woocommerce.php' );
	define( 'WC_ABSPATH', $co_source . '/' );
	define( 'WC_VERSION', '11.2.1' );
	define( 'WC_PLUGIN_BASENAME', 'woocommerce/woocommerce.php' );
	define( 'WC_DELIMITER', '|' );
	define( 'WC_ROUNDING_PRECISION', 6 );
	define( 'WC_DISCOUNT_ROUNDING_MODE', 2 );
	define( 'WC_TAX_ROUNDING_MODE', 2 );
	define( 'WC_LOG_DIR', '/private/tmp/' . $GLOBALS['co_marker'] . '-logs/' );
	define( 'WC_LOG_DIR_CUSTOM', true );
	$classmap = require $co_source . '/vendor/composer/autoload_classmap.php';
	$psr4 = require $co_source . '/vendor/composer/autoload_psr4.php';
	$loader = static function ( $class ) use ( $classmap, $psr4, $co_source ) {
		if ( isset( $classmap[ $class ] ) ) {
			require_once $classmap[ $class ];
			return;
		}
		foreach ( $psr4 as $prefix => $paths ) {
			if ( str_starts_with( $class, $prefix ) ) {
				foreach ( $paths as $path ) {
					$file = $path . '/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
					if ( is_readable( $file ) ) {
						require_once $file;
						return;
					}
				}
			}
		}
		if ( str_starts_with( $class, 'WC_' ) || str_starts_with( $class, 'Abstract_WC_' ) ) {
			$file = strtolower( str_replace( '_', '-', $class ) );
			foreach ( array( '', 'abstracts/', 'legacy/', 'data-stores/', 'interfaces/', 'admin/', 'admin/settings/', 'tracks/', 'log-handlers/' ) as $dir ) {
				foreach ( array( 'class-', 'abstract-', '' ) as $kind ) {
					$path = $co_source . '/includes/' . $dir . $kind . $file . '.php';
					if ( is_readable( $path ) && ! in_array( $class, array( 'WC_Install', 'WC_Post_Types' ), true ) ) {
						require_once $path;
						return;
					}
				}
			}
		}
	};
	spl_autoload_register( $loader );
	$GLOBALS['co_loader'] = $loader;
	require_once $co_source . '/includes/class-woocommerce.php';
	// Skip the plugin lifecycle; provide only actual native request dependencies.
	$GLOBALS['co_wc'] = ( new ReflectionClass( 'WooCommerce' ) )->newInstanceWithoutConstructor();
	$GLOBALS['wc_container'] = new Automattic\WooCommerce\Container();
	require_once $co_source . '/includes/wc-core-functions.php';
	$GLOBALS['co_wc']->countries = new WC_Countries();
	$GLOBALS['co_wc']->product_factory = new WC_Product_Factory();
	$GLOBALS['co_wc']->query = new WC_Query();
	$wpdb->wc_product_meta_lookup = $GLOBALS['co_table'];
	// Register only process-local core objects, with the official native capabilities.
	register_post_type( 'product', array( 'public' => true, 'supports' => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'comments' ), 'capability_type' => 'product', 'map_meta_cap' => true ) );
	foreach ( array( 'product_type', 'product_visibility', 'product_cat' ) as $taxonomy ) {
		register_taxonomy( $taxonomy, 'product', array( 'hierarchical' => 'product_cat' === $taxonomy, 'public' => 'product_cat' === $taxonomy, 'rewrite' => false, 'capabilities' => array( 'manage_terms' => 'manage_product_terms', 'edit_terms' => 'edit_product_terms', 'delete_terms' => 'delete_product_terms', 'assign_terms' => 'assign_product_terms' ) ) );
	}
	// wc_get_product checks these lifecycle counters; dispatch no global init hooks.
	foreach ( array( 'woocommerce_init', 'woocommerce_after_register_taxonomy', 'woocommerce_after_register_post_type' ) as $action ) {
		$GLOBALS['wp_actions'][ $action ] = 1;
	}
	$GLOBALS['co_controllers'] = array( new WC_REST_Products_Controller(), new WC_REST_Product_Categories_Controller(), new WC_REST_Settings_Controller(), new WC_REST_Setting_Options_Controller() );
	foreach ( $GLOBALS['co_controllers'] as $controller ) {
		$controller->register_routes();
	}
	foreach ( array( 'general', 'products', 'advanced' ) as $group ) {
		$page = include $co_source . '/includes/admin/settings/class-wc-settings-' . $group . '.php';
		$GLOBALS['co_settings_registrars'][] = new WC_Register_WP_Admin_Settings( $page, 'page' );
	}
}

/** Remove only callbacks introduced by the out-of-site Woo source, including shutdown. */
function co_remove_native_hooks() {
	global $co_source;
	foreach ( $GLOBALS['wp_filter'] as $tag => $hook ) {
		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				try {
					$reflection = is_array( $function ) ? new ReflectionMethod( $function[0], $function[1] ) : ( is_string( $function ) || $function instanceof Closure ? new ReflectionFunction( $function ) : new ReflectionMethod( $function, '__invoke' ) );
					$file = $reflection->getFileName();
					if ( $file && str_starts_with( $file, $co_source . '/' ) ) {
						remove_filter( $tag, $function, $priority );
					}
				} catch ( ReflectionException $ignored ) {
					// Preserve callbacks that belong to the site's existing plugins.
				}
			}
		}
	}
}

// These return genuine native objects; neither calls the plugin singleton/constructor.
if ( ! function_exists( 'WC' ) ) {
	function WC() { return $GLOBALS['co_wc']; } // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
}
if ( ! function_exists( 'wc_get_container' ) ) {
	function wc_get_container() { return $GLOBALS['wc_container']; }
}

$co_before = co_baseline();
$co_upload_files = $co_latest_upload_files;
$co_known_options = array( 'user_count', 'product_cat_children', 'bhranti_settings', 'bhranti_settings_lock', 'bhranti_actor_setup_lock', 'bhranti_commerce_lock', 'woocommerce_currency', 'woocommerce_weight_unit', 'woocommerce_dimension_unit', 'woocommerce_manage_stock', 'woocommerce_default_country', 'woocommerce_shop_page_id', '_transient_product_transient_version', '_transient_product_query_transient_version', '_transient_timeout_product_transient_version', '_transient_timeout_product_query_transient_version' );
foreach ( array( 'wc_term_counts', 'wc_products_onsale', 'wc_featured_products', 'wc_outofstock_count', 'wc_low_stock_count' ) as $name ) {
	$co_known_options[] = '_transient_' . $name;
	$co_known_options[] = '_transient_timeout_' . $name;
}
foreach ( array( 'product-transient-version', 'product_query-transient-version' ) as $name ) {
	$co_known_options[] = '_transient_' . $name;
	$co_known_options[] = '_transient_timeout_' . $name;
}
foreach ( $co_known_options as $name ) {
	$co_option_originals[ $name ] = co_raw_option( $name );
	$co_option_observed[ $name ] = $co_option_originals[ $name ];
}
co_checkpoint();
$co_http_guard = static function () { ++$GLOBALS['co_http']; return new WP_Error( 'bhranti_commerce_qa_offline', 'No outbound requests allowed.' ); };
$co_native_diagnostic = array();
$co_native_error_observer = static function ( $response, $handler, $request ) {
	if ( is_wp_error( $response ) && '/wc/v3/products' === $request->get_route() && 'POST' === $request->get_method() && str_starts_with( (string) $request['name'], $GLOBALS['co_marker'] ) ) {
		$GLOBALS['co_native_diagnostic'] = array( 'code' => $response->get_error_code(), 'message' => mb_substr( sanitize_text_field( $response->get_error_message() ), 0, 500 ) );
	}
	return $response;
};
$co_option_added = static function ( $name ) { co_observe_option( $name ); };
$co_option_updated = static function ( $name ) { co_observe_option( $name ); };
$co_option_deleted = static function ( $name ) { co_observe_option( $name ); };
$co_option_pre_update = static function ( $value, $name ) { co_guard_option( $name ); return $value; };
$co_option_pre_add = static function ( $name ) { co_guard_option( $name ); };
$co_option_pre_delete = static function ( $name ) { co_guard_option( $name ); };
$co_user_observer = static function ( $id, $data ) {
	foreach ( array( 'co_user', 'co_other' ) as $index => $key ) {
		$login = $GLOBALS['co_marker'] . '-' . $index;
		if ( $login === ( $data['user_login'] ?? '' ) && $login . '@example.test' === ( $data['user_email'] ?? '' ) ) {
			co_check( ! in_array( (int) $id, $GLOBALS['co_prior_users'], true ), 'Observe fresh owned user before core count hooks can fail' );
			$GLOBALS[ $key ] = (int) $id;
			$lock_name = 'bhranti_lock_' . (int) $id;
			$GLOBALS['co_option_originals'][ $lock_name ] = co_raw_option( $lock_name );
			$GLOBALS['co_option_observed'][ $lock_name ] = $GLOBALS['co_option_originals'][ $lock_name ];
			co_checkpoint();
		}
	}
};
$co_post_observer = static function ( $id, $post, $update ) {
	if ( ! $update && 'product' === $post->post_type ) {
		co_check( ! in_array( (int) $id, $GLOBALS['co_prior_posts'], true ) && 11 === (int) $post->post_author && str_starts_with( $post->post_title, $GLOBALS['co_marker'] ), 'Observed newly created actor-owned product before response validation' );
		$GLOBALS['co_products'][] = (int) $id;
		foreach ( array( 'wc_product_children_', 'wc_var_prices_', 'wc_related_', 'wc_child_has_weight_', 'wc_child_has_dimensions_' ) as $prefix ) {
			foreach ( array( '_transient_', '_transient_timeout_' ) as $kind ) {
				$name = $kind . $prefix . $id;
				$GLOBALS['co_option_originals'][ $name ] = co_raw_option( $name );
				$GLOBALS['co_option_observed'][ $name ] = $GLOBALS['co_option_originals'][ $name ];
			}
		}
		co_checkpoint();
	}
};
$co_term_observer = static function ( $term_id, $tt_id, $taxonomy ) {
	if ( 'product_cat' === $taxonomy ) {
		$term = get_term( $term_id, $taxonomy );
		co_check( ! in_array( (int) $term_id, $GLOBALS['co_prior_terms'], true ) && str_starts_with( $term->name, $GLOBALS['co_marker'] ), 'Observe newly created UUID category before response validation' );
		$GLOBALS['co_categories'][] = (int) $term_id;
		co_checkpoint();
	} elseif ( 'product_type' === $taxonomy ) {
		$term = get_term( $term_id, $taxonomy );
		co_check( ! in_array( (int) $term_id, $GLOBALS['co_prior_terms'], true ) && 'simple' === $term->slug && ! empty( $GLOBALS['co_products'] ), 'Observe native simple-type term created only by an owned product' );
		$GLOBALS['co_taxonomy_terms'][ (int) $term_id ] = $taxonomy;
		co_checkpoint();
	}
};
$co_queue_guard = bhranti_qa_runner_veto( static function () { return array( $GLOBALS['co_session'] ); } );
add_filter( 'pre_http_request', $co_http_guard, PHP_INT_MAX );
add_filter( 'rest_request_after_callbacks', $co_native_error_observer, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
add_action( 'added_option', $co_option_added, PHP_INT_MAX, 1 );
add_action( 'updated_option', $co_option_updated, PHP_INT_MAX, 1 );
add_action( 'deleted_option', $co_option_deleted, PHP_INT_MAX, 1 );
add_filter( 'pre_update_option', $co_option_pre_update, PHP_INT_MAX, 2 );
add_action( 'add_option', $co_option_pre_add, PHP_INT_MAX, 1 );
add_action( 'delete_option', $co_option_pre_delete, PHP_INT_MAX, 1 );
add_action( 'user_register', $co_user_observer, -9999, 2 );
add_action( 'wp_insert_post', $co_post_observer, PHP_INT_MAX, 3 );
add_action( 'create_term', $co_term_observer, -9999, 3 );

try {
	foreach ( array( 'co_user', 'co_other' ) as $index => $key ) {
		$login = $co_marker . '-' . $index;
		$GLOBALS[ $key ] = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
		co_check( is_int( $GLOBALS[ $key ] ) && ! in_array( $GLOBALS[ $key ], $co_prior_users, true ), 'Create fresh owned requesting administrator' );
		co_checkpoint();
	}
	wp_set_current_user( $co_user );
	$co_session = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $co_user, 'post_title' => $co_marker ), true );
	co_check( is_int( $co_session ) && ! in_array( $co_session, $co_prior_posts, true ), 'Fresh private audit session' );
	co_checkpoint();
	foreach ( array( 'manage_woocommerce', 'edit_products', 'edit_others_products', 'edit_private_products', 'edit_published_products', 'publish_products', 'read_private_products', 'delete_products', 'delete_others_products', 'delete_private_products', 'delete_published_products', 'manage_product_terms', 'edit_product_terms', 'delete_product_terms', 'assign_product_terms' ) as $cap ) {
		( new WP_User( $co_user ) )->add_cap( $cap );
	}
	wp_set_current_user( 0 );
	wp_set_current_user( $co_user );
	$co_tax_cache_original = wp_cache_get( 'tax-rate-classes', 'taxes', false, $co_tax_cache_found );
	$co_tax_cache_saved = true;
	wp_cache_set( 'tax-rate-classes', array(), 'taxes' );
	co_load_native();
	co_check( ! class_exists( 'WC_Install', false ) && ! did_action( 'before_woocommerce_init' ), 'No Woo bootstrap/installer lifecycle executed' );
	foreach ( array( 'WC_REST_Products_Controller', 'WC_Product_Simple', 'WC_Product_Data_Store_CPT' ) as $class ) {
		co_check( str_starts_with( ( new ReflectionClass( $class ) )->getFileName(), $co_source . '/' ), 'Genuine official native implementation: ' . $class );
	}
	$schema_source = file_get_contents( $co_source . '/includes/class-wc-install.php' );
	co_check( 1 === preg_match( '/CREATE TABLE \{\$wpdb->prefix\}wc_product_meta_lookup \((.*?)\) \$collate;/s', $schema_source, $matches ), 'Lookup schema comes directly from pinned official installer source without executing it' );
	$sql = 'CREATE TABLE ' . $co_table . ' (' . $matches[1] . ') ' . $wpdb->get_charset_collate();
	co_check( false !== $wpdb->query( $sql ), 'Create only the absent owned native product lookup table' );
	$co_table_owned = true;
	co_checkpoint();
	co_check( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $co_table ), 'Owned lookup table begins empty' );
	foreach ( array( 'edit_content', 'publish_content', 'trash_content', 'manage_site_settings' ) as $permission ) {
		co_check( true === Bhranti_Settings::allows( $permission ), 'Required routine policy already allows ' . $permission );
	}
	$discovery = co_success( co_action( 'GET', '/wc/v3' ), 'Actual native controller availability discovery succeeds' );
	co_check( true === $discovery['data']['available'] && ! $discovery['rollback_available'] && '' === $discovery['action_id'], 'Discovery has truthful availability and no fictitious Undo' );
	co_check( $co_user === get_current_user_id(), 'Discovery restores the requesting identity' );
	$co_actor_caps = get_user_meta( 11, $wpdb->prefix . 'capabilities', true );
	$co_actor_roles = get_userdata( 11 )->roles;
	foreach ( array(
		array( 'GET', '/wc/v3/orders', array() ),
		array( 'GET', '/wc/v3/customers', array() ),
		array( 'POST', '/wc/v3/products/batch', array() ),
		array( 'GET', '/wc/v3/products?search=secret', array() ),
		array( 'GET', '/wc/v3/settings/general/woocommerce_api_keys', array() ),
		array( 'POST', '/wc/v3/products', array( 'name' => $co_marker, 'type' => 'variable' ) ),
		array( 'POST', '/wc/v3/products', array( 'name' => $co_marker, 'meta_data' => array( array( 'key' => 'secret', 'value' => 'no' ) ) ) ),
		array( 'POST', '/wc/v3/products', array( 'name' => $co_marker, 'images' => array( array( 'src' => 'https://example.test/no-network.png' ) ) ) ),
		array( 'POST', '/wc/v3/products', array( 'name' => $co_marker, 'description' => '<script>alert(1)</script>' ) ),
		array( 'POST', '/wc/v3/products', array( 'name' => $co_marker, 'stock_quantity' => array( 'bad' ) ) ),
	) as $case ) {
		co_denied( co_action( $case[0], $case[1], $case[2] ), 'Forbidden route/field/executable markup or invalid native type is refused before mutation' );
	}
	co_check( ! $co_products && ! $co_categories && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $co_table ), 'Invalid commands create no products, categories or lookup rows' );
	$category = co_success( co_action( 'POST', '/wc/v3/products/categories', array( 'name' => $co_marker . ' category', 'description' => 'Confirmed fixture category' ) ), 'Actual Woo category controller creates a fresh owned category' );
	$co_category_id = (int) $category['data']['id'];
	co_check( in_array( $co_category_id, $co_categories, true ) && $category['rollback_available'] && 'shop_' === substr( $category['action_id'], 0, 5 ), 'Native category ID is observed durably and has owned Undo' );
	co_check( str_contains( $category['target']['url'], '/term.php?taxonomy=product_cat&tag_ID=' . $co_category_id ), 'Category target points to the real native term editor' );
	co_check( $co_marker . ' category' === get_term( $co_category_id, 'product_cat' )->name, 'Actual term storage matches the successful category result' );
	$co_category_before = co_category_state( $co_category_id );
	$category_update = co_success( co_action( 'POST', '/wc/v3/products/categories/' . $co_category_id, array( 'name' => $co_marker . ' changed category', 'description' => 'Changed confirmed category', 'display' => 'products', 'menu_order' => 4 ) ), 'Actual native category update persists supported term and metadata fields' );
	co_check( $co_marker . ' changed category' === get_term( $co_category_id, 'product_cat' )->name && 'products' === get_term_meta( $co_category_id, 'display_type', true ) && 4 === (int) get_term_meta( $co_category_id, 'order', true ), 'Real category term and display/order metadata prove native update' );
	co_success( co_undo( $category_update['action_id'] ), 'Native category update Undo restores original supported saved state' );
	co_check( $co_category_before === co_category_state( $co_category_id ), 'Category Undo exactly restores native term fields and display/order metadata presence/values' );
	update_option( 'woocommerce_manage_stock', 'yes', false );
	$product = co_success( co_action( 'POST', '/wc/v3/products', array(
		'name' => $co_marker . ' bowl', 'description' => '<p>Confirmed fixture details.</p>', 'short_description' => 'Fixture summary',
		'regular_price' => '12.50', 'manage_stock' => true, 'stock_quantity' => 7, 'weight' => '1.5',
		'dimensions' => array( 'length' => '10', 'width' => '8', 'height' => '3' ), 'categories' => array( array( 'id' => $co_category_id ) ),
	) ), 'Actual Woo product controller and CPT data store create a real product' );
	$co_product_id = (int) $product['data']['id'];
	co_check( in_array( $co_product_id, $co_products, true ) && 'draft' === get_post_status( $co_product_id ) && 11 === (int) get_post_field( 'post_author', $co_product_id ), 'Product defaults to a native actor-owned draft' );
	co_check( $product['rollback_available'] && 41 === strlen( $product['action_id'] ), 'Successful product exposes one owned shop UUID Undo' );
	$co_product_before = co_product_state( $co_product_id );
	co_check( '12.50' === $co_product_before['regular_price'] && 7 === $co_product_before['stock_quantity'] && true === $co_product_before['manage_stock'] && array( $co_category_id ) === $co_product_before['category_ids'], 'Native model readback proves price, stock and category persistence' );
	$lookup = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $co_table . ' WHERE product_id=%d', $co_product_id ), ARRAY_A );
	co_check( is_array( $lookup ) && 12.5 === (float) $lookup['min_price'] && 7.0 === (float) $lookup['stock_quantity'], 'Genuine native product lookup row persists matching price/inventory' );
	$native = co_native( 'GET', '/wc/v3/products/' . $co_product_id, array( 'context' => 'edit' ) );
	$read = co_success( co_action( 'GET', '/wc/v3/products/' . $co_product_id ), 'Verified native closure-backed product GET succeeds through adapter' );
	co_check( 200 === $native->get_status() && $read['data']['id'] === $native->get_data()['id'] && $read['data']['regular_price'] === $native->get_data()['regular_price'] && $read['data']['description'] === $native->get_data()['description'], 'Adapter product values agree with the actual native REST response' );
	co_check( ! array_key_exists( 'meta_data', $read['data'] ) && ! array_key_exists( 'downloads', $read['data'] ) && ! $read['rollback_available'], 'Read projects safe native catalog fields and cannot invent Undo' );
	co_error( co_action( 'GET', '/wc/v3/products', array( 'per_page' => 100 ) ), 'bhranti_commerce_invalid', 'Oversized native collection requests are explicitly refused' );
	$list = co_success( co_action( 'GET', '/wc/v3/products', array( 'include' => array( $co_product_id ), 'per_page' => 20 ) ), 'Actual native product collection returns only the selected owned item' );
	co_check( 1 === count( $list['data'] ) && $co_product_id === $list['data'][0]['id'], 'Native bounded collection includes actual saved fixture' );
	// Current V3 preserves saved inventory even when the global preference is disabled.
	update_option( 'woocommerce_manage_stock', 'no', false );
	$rename = co_success( co_action( 'POST', '/wc/v3/products/' . $co_product_id, array( 'name' => $co_marker . ' renamed bowl' ) ), 'Real native name-only update succeeds with stock management globally disabled' );
	$renamed_state = co_product_state( $co_product_id );
	$expected_name = $co_product_before;
	$expected_name['name'] = $co_marker . ' renamed bowl';
	co_check( $expected_name === $renamed_state, 'Name-only native update preserves all existing prices, inventory, dimensions and categories' );
	co_success( co_undo( $rename['action_id'] ), 'Native product Undo restores the prior supported saved state' );
	co_check( $co_product_before === co_product_state( $co_product_id ), 'Native model/core Undo exactly restores all supported product fields' );
	co_error( co_undo( $rename['action_id'] ), 'bhranti_rollback_missing', 'Consumed commerce Undo cannot execute twice' );
	$update = co_success( co_action( 'PATCH', '/wc/v3/products/' . $co_product_id, array( 'regular_price' => '14.25', 'description' => '<p>Changed confirmed fixture details.</p>' ) ), 'Typed native PATCH is normalized to one product update' );
	$changed = co_product_state( $co_product_id );
	co_check( '14.25' === $changed['regular_price'] && '<p>Changed confirmed fixture details.</p>' === $changed['description'] && 7 === $changed['stock_quantity'], 'Actual product data proves changed fields and preserved unrelated inventory' );
	wp_set_current_user( $co_other );
	co_denied( Bhranti_Actions::rollback( $update['action_id'], $co_other, $co_session ), 'Another human cannot Undo the original requester’s private snapshot' );
	wp_set_current_user( $co_user );
	co_policy( 'edit_content', false );
	try {
		co_success( co_undo( $update['action_id'] ), 'Owned native Undo remains available while new content writes are disabled' );
	} finally {
		co_policy( 'edit_content', true );
	}
	co_check( $co_product_before === co_product_state( $co_product_id ), 'Product returns to original fixture state after supported edit Undo' );
	foreach ( array( $co_user, 11 ) as $denied_user ) {
		$deny = static function ( $caps, $cap, $user_id ) use ( $denied_user ) {
			return $user_id === $denied_user && in_array( $cap, array( 'edit_post', 'edit_product', 'edit_products' ), true ) ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, PHP_INT_MAX, 3 );
		try {
			co_error( co_action( 'POST', '/wc/v3/products/' . $co_product_id, array( 'name' => $co_marker . ' forbidden rename' ) ), 'bhranti_commerce_permission', 'Unchanged native permission requires human and actor independently' );
		} finally {
			remove_filter( 'map_meta_cap', $deny, PHP_INT_MAX );
		}
		co_check( $co_product_before === co_product_state( $co_product_id ) && $co_user === get_current_user_id(), 'Denied permission leaves product and requesting identity intact' );
	}
	co_policy( 'edit_content', false );
	try {
		co_error( co_action( 'POST', '/wc/v3/products/' . $co_product_id, array( 'name' => $co_marker . ' policy refused' ) ), 'bhranti_action_disabled', 'Current content policy blocks a native commerce write' );
		co_success( co_action( 'GET', '/wc/v3/products/' . $co_product_id ), 'Read remains available while content writes are disabled' );
	} finally {
		co_policy( 'edit_content', true );
	}
	co_check( $co_product_before === co_product_state( $co_product_id ), 'Policy refusal causes no product mutation' );
	$co_spoof_calls = 0;
	foreach ( array( 'callback', 'permission_callback' ) as $field ) {
		$filter = static function ( $routes ) use ( $field, &$co_spoof_calls ) {
			foreach ( $routes as $route => &$endpoints ) {
				if ( '/wc/v3/products/(?P<id>[\\d]+)' === $route ) {
					foreach ( $endpoints as &$endpoint ) {
						if ( co_endpoint_get( $endpoint ) ) {
							$endpoint[ $field ] = static function () use ( &$co_spoof_calls ) { ++$co_spoof_calls; return true; };
						}
					}
					unset( $endpoint );
				}
			}
			unset( $endpoints );
			return $routes;
		};
		add_filter( 'rest_endpoints', $filter, PHP_INT_MAX );
		try { co_error( co_action( 'GET', '/wc/v3/products/' . $co_product_id ), 'bhranti_commerce_blocked', 'Replaced native ' . $field . ' is blocked before execution' ); }
		finally { remove_filter( 'rest_endpoints', $filter, PHP_INT_MAX ); }
	}
	co_check( 0 === $co_spoof_calls, 'Neither spoof callback nor spoof permission executes' );
	$co_wrong_callback = static function ( $routes ) {
		foreach ( $routes as $route => &$endpoints ) {
			if ( '/wc/v3/products/(?P<id>[\\d]+)' !== $route ) { continue; }
			foreach ( $endpoints as &$endpoint ) {
				if ( co_endpoint_get( $endpoint ) ) {
					$endpoint['callback'] = array( $GLOBALS['co_controllers'][0], 'update_item' );
				}
			}
			unset( $endpoint );
		}
		unset( $endpoints );
		return $routes;
	};
	add_filter( 'rest_endpoints', $co_wrong_callback, PHP_INT_MAX );
	try {
		co_error( co_action( 'GET', '/wc/v3/products/' . $co_product_id ), 'bhranti_commerce_blocked', 'Genuine same-controller callback from the wrong method cannot replace a native GET' );
	} finally {
		remove_filter( 'rest_endpoints', $co_wrong_callback, PHP_INT_MAX );
	}
	co_check( $co_product_before === co_product_state( $co_product_id ), 'Wrong native method substitution leaves product unchanged' );
	co_error( co_action( 'DELETE', '/wc/v3/products/' . $co_product_id, array( 'force' => true ) ), 'bhranti_commerce_invalid', 'Model cannot force permanent product deletion' );
	co_error( co_action( 'DELETE', '/wc/v3/products/categories/' . $co_category_id ), 'bhranti_commerce_blocked', 'Category deletion cannot schedule native store-wide repair' );
	$proposal = co_action( 'DELETE', '/wc/v3/products/' . $co_product_id );
	co_check( is_array( $proposal ) && ! empty( $proposal['confirmation_required'] ) && 'draft' === get_post_status( $co_product_id ), 'Native product Trash waits for human confirmation without mutation' );
	$trash = co_success( co_action( 'DELETE', '/wc/v3/products/' . $co_product_id, array(), true, $proposal['fingerprint'] ), 'Explicit human-bound confirmation moves actual product to native Trash' );
	co_check( 'trash' === get_post_status( $co_product_id ) && $trash['rollback_available'], 'Native product remains restorable after Trash' );
	co_success( co_undo( $trash['action_id'] ), 'Trash Undo restores actual native product' );
	co_check( 'draft' === get_post_status( $co_product_id ) && $co_product_before === co_product_state( $co_product_id ), 'Trash Undo restores exact draft slug and supported saved properties' );
	co_error( co_undo( $category['action_id'] ), 'bhranti_rollback_conflict', 'Created category Undo refuses removal while an owned product still references it' );
	$currency_path = '/wc/v3/settings/general/woocommerce_currency';
	$currency = co_success( co_action( 'GET', $currency_path ), 'Actual registered general Settings controller reads native currency metadata' );
	$currency_before = co_raw_option( 'woocommerce_currency' );
	$currency_value = 'USD' === $currency['data']['value'] ? 'INR' : 'USD';
	$currency_edit = co_success( co_action( 'POST', $currency_path, array( 'value' => $currency_value ) ), 'Native Settings controller saves a reviewed real currency' );
	co_check( $currency_value === get_option( 'woocommerce_currency' ) && $currency_value === $currency_edit['data']['value'], 'Native currency option and controller response agree after save' );
	co_error( co_action( 'POST', $currency_path, array( 'value' => 'MADEUP' ) ), 'bhranti_commerce_invalid', 'Currency values outside the actual native choice list are refused' );
	co_success( co_undo( $currency_edit['action_id'] ), 'Owned native currency Undo succeeds' );
	co_check( $currency_before === co_raw_option( 'woocommerce_currency' ), 'Currency Undo preserves exact previous presence, raw value and autoload' );
	$created_undo = co_success( co_action( 'POST', '/wc/v3/products', array( 'name' => $co_marker . ' untouched creation Undo' ) ), 'Separate actual native product proves untouched creation Undo' );
	$created_undo_id = (int) $created_undo['data']['id'];
	co_success( co_undo( $created_undo['action_id'] ), 'Undo of untouched native product creation moves only the fresh product to Trash' );
	co_check( 'trash' === get_post_status( $created_undo_id ) && 'draft' === get_post_status( $co_product_id ), 'Created-product Undo uses native Trash and preserves the edited product' );
	$empty_category = co_success( co_action( 'POST', '/wc/v3/products/categories', array( 'name' => $co_marker . ' empty creation Undo' ) ), 'Separate real empty category proves fresh creation Undo' );
	co_success( co_undo( $empty_category['action_id'] ), 'Owned empty category creation Undo uses guarded native core deletion' );
	co_check( ! get_term( (int) $empty_category['data']['id'], 'product_cat' ), 'Only untouched empty native category is removed by creation Undo' );
	$parse = new ReflectionMethod( Bhranti_Agent::class, 'parse' );
	$schema_method = new ReflectionMethod( Bhranti_Agent::class, 'plan_schema' );
	$plan_args = array( 'method' => 'GET', 'path' => '/wc/v3', 'params' => array() );
	$native_plan = wp_json_encode( array( 'type' => 'tool', 'content' => 'Check requested store setup.', 'tool' => 'commerce_request', 'args' => wp_json_encode( $plan_args ) ) );
	co_check( array( 'type' => 'tool', 'tool' => 'commerce_request', 'args' => $plan_args ) === $parse->invoke( null, $native_plan ), 'Agent parses native strict commerce envelope as data' );
	co_check( in_array( 'commerce_request', $schema_method->invoke( null )['properties']['tool']['enum'], true ) && isset( Bhranti_Actions::catalog()['commerce_request'] ), 'Agent output schema and Actions catalog expose the genuine commerce tool' );
	$co_native_update_count = 0;
	$co_count_update = static function ( $id ) use ( $co_product_id, &$co_native_update_count ) { if ( $co_product_id === (int) $id ) { ++$co_native_update_count; } };
	$co_after_save_error = static function ( $response, $handler, $request ) use ( $co_product_id ) {
		if ( ! is_wp_error( $response ) && 'POST' === $request->get_method() && '/wc/v3/products/' . $co_product_id === $request->get_route() && '19.50' === $request['regular_price'] ) {
			return new WP_Error( 'bhranti_qa_after_native_save', 'Owned fixture response failed after real native save.', array( 'status' => 500 ) );
		}
		return $response;
	};
	add_action( 'woocommerce_update_product', $co_count_update, PHP_INT_MAX );
	add_filter( 'rest_request_after_callbacks', $co_after_save_error, PHP_INT_MAX, 3 );
	try {
		$uncertain = co_action( 'POST', '/wc/v3/products/' . $co_product_id, array( 'regular_price' => '19.50' ) );
	} finally {
		remove_filter( 'rest_request_after_callbacks', $co_after_save_error, PHP_INT_MAX );
		remove_action( 'woocommerce_update_product', $co_count_update, PHP_INT_MAX );
	}
	co_check( is_wp_error( $uncertain ) && true === ( $uncertain->get_error_data()['uncertain_action'] ?? false ), 'Actual native post-dispatch error is marked uncertain rather than safe to replay' );
	co_check( 1 === $co_native_update_count && '19.50' === co_product_state( $co_product_id )['regular_price'], 'Genuine native update saved exactly once before the injected response failure' );
	$after_failure_read = co_success( co_action( 'GET', '/wc/v3/products/' . $co_product_id ), 'Saved native state remains inspectable after post-dispatch uncertainty' );
	co_check( '19.50' === $after_failure_read['data']['regular_price'] && 1 === $co_native_update_count && ! $after_failure_read['rollback_available'], 'Readback verifies saved state without replay or fictitious read Undo' );
	co_check( $co_actor_caps === get_user_meta( 11, $wpdb->prefix . 'capabilities', true ) && $co_actor_roles === get_userdata( 11 )->roles, 'Transient native Woo permissions never change actor role/capability metadata' );
	co_check( $co_user === get_current_user_id() && 0 === $co_http && 0 === count( Bhranti_QA_Model::$calls ), 'All native operations restore identity and make no HTTP or AI calls' );
} catch ( Throwable $error ) {
	$co_failure = $error;
} finally {
	wp_set_current_user( $co_user ?: $co_original_user );
	// Cleanup uses guarded core APIs; do not run native order/user repair hooks.
	co_remove_native_hooks();
	if ( $co_tax_cache_saved ) {
		if ( $co_tax_cache_found ) { wp_cache_set( 'tax-rate-classes', $co_tax_cache_original, 'taxes' ); }
		else { wp_cache_delete( 'tax-rate-classes', 'taxes' ); }
	}
	if ( is_dir( $co_log_dir ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $co_log_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
			if ( $file->isLink() || ! str_starts_with( $file->getPathname(), $co_log_dir ) ) { $co_clean = false; continue; }
			$co_clean = ( $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ) ) && $co_clean;
		}
		$co_clean = rmdir( $co_log_dir ) && $co_clean;
	}
	foreach ( array_unique( $co_products ) as $id ) {
		$post = get_post( $id );
		$safe = $post && ! in_array( $id, $co_prior_posts, true ) && 'product' === $post->post_type && 11 === (int) $post->post_author && str_starts_with( $post->post_title, $co_marker );
		if ( $safe ) {
			$co_clean = (bool) wp_delete_post( $id, true ) && $co_clean;
			if ( $co_table_owned ) {
				$wpdb->delete( $co_table, array( 'product_id' => $id ), array( '%d' ) );
			}
		} elseif ( $post ) {
			$co_clean = false;
		}
	}
	foreach ( array_unique( $co_categories ) as $id ) {
		$term = get_term( $id, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) ) {
			$safe = ! in_array( $id, $co_prior_terms, true ) && str_starts_with( $term->name, $co_marker ) && ! (int) $term->count && ! $wpdb->get_var( $wpdb->prepare( "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id=%d LIMIT 1", $term->term_taxonomy_id ) );
			$co_clean = ( $safe && (bool) wp_delete_term( $id, 'product_cat' ) ) && $co_clean;
		}
	}
	foreach ( $co_taxonomy_terms as $id => $taxonomy ) {
		$term = get_term( $id, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			$safe = ! in_array( $id, $co_prior_terms, true ) && 'product_type' === $taxonomy && 'simple' === $term->slug && ! $wpdb->get_var( $wpdb->prepare( "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id=%d LIMIT 1", $term->term_taxonomy_id ) );
			$co_clean = ( $safe && (bool) wp_delete_term( $id, $taxonomy ) ) && $co_clean;
		}
	}
	if ( $co_table_owned ) {
		// Foreign rows or a concurrent real Woo activation preserve the table for review.
		$empty = 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $co_table );
		$foreign = (bool) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') LIMIT 1" );
		$active = in_array( 'woocommerce/woocommerce.php', (array) get_option( 'active_plugins' ), true );
		if ( $empty && ! $foreign && ! $active && $co_clean ) {
			$co_clean = false !== $wpdb->query( 'DROP TABLE ' . $co_table ) && $co_clean;
			$co_table_owned = false;
		} else {
			$co_clean = false;
		}
	}
	foreach ( array( $co_user, $co_other ) as $user_id ) {
		if ( ! $user_id ) { continue; }
		$user = get_user_by( 'id', $user_id );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_type,post_status FROM {$wpdb->posts} WHERE post_author=%d", $user_id ), ARRAY_A );
		$safe = $user && ! in_array( $user_id, $co_prior_users, true ) && str_starts_with( $user->user_login, $co_marker . '-' ) && $user->user_email === $user->user_login . '@example.test';
		foreach ( $rows as $row ) {
			$safe = $safe && ! in_array( (int) $row['ID'], $co_prior_posts, true ) && in_array( $row['post_type'], array( 'bhranti_session', 'bhranti_event' ), true ) && 'private' === $row['post_status'];
		}
		if ( $safe ) {
			foreach ( $rows as $row ) { wp_delete_post( (int) $row['ID'], true ); }
			require_once ABSPATH . 'wp-admin/includes/user.php';
			$co_clean = wp_delete_user( $user_id ) && $co_clean;
		} else {
			$co_clean = false;
		}
	}
	$co_restoring = true;
	foreach ( $co_option_originals as $name => $prior ) {
		if ( co_raw_option( $name ) !== $co_option_observed[ $name ] ) {
			$co_clean = false;
			continue;
		}
		if ( null === $prior ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );
		} elseif ( null === co_raw_option( $name ) ) {
			$wpdb->insert( $wpdb->options, array_merge( array( 'option_name' => $name ), $prior ), array( '%s', '%s', '%s' ) );
		} else {
			$wpdb->update( $wpdb->options, $prior, array( 'option_name' => $name ), array( '%s', '%s' ), array( '%s' ) );
		}
		wp_cache_delete( $name, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_set_current_user( $co_original_user );
	remove_filter( 'pre_http_request', $co_http_guard, PHP_INT_MAX );
	remove_filter( 'rest_request_after_callbacks', $co_native_error_observer, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
	remove_filter( 'pre_schedule_event', $co_queue_guard, PHP_INT_MAX );
	remove_action( 'added_option', $co_option_added, PHP_INT_MAX );
	remove_action( 'updated_option', $co_option_updated, PHP_INT_MAX );
	remove_action( 'deleted_option', $co_option_deleted, PHP_INT_MAX );
	remove_filter( 'pre_update_option', $co_option_pre_update, PHP_INT_MAX );
	remove_action( 'add_option', $co_option_pre_add, PHP_INT_MAX );
	remove_action( 'delete_option', $co_option_pre_delete, PHP_INT_MAX );
	remove_action( 'user_register', $co_user_observer, -9999 );
	remove_action( 'wp_insert_post', $co_post_observer, PHP_INT_MAX );
	remove_action( 'create_term', $co_term_observer, -9999 );
	co_remove_native_hooks();
	if ( isset( $GLOBALS['co_loader'] ) ) { spl_autoload_unregister( $GLOBALS['co_loader'] ); }
	$co_preserved = $co_before === co_baseline();
	if ( $co_clean && $co_preserved ) { unlink( $co_checkpoint_path ); }
	echo wp_json_encode( array( 'checks' => $co_checks, 'original_rows_options_actor_cron_uploads_preserved' => $co_preserved, 'fixtures_removed' => $co_clean, 'lookup_table_removed' => ! $co_table_owned, 'http_calls' => $co_http ) ), "\n";
	if ( $co_failure && $co_native_diagnostic ) { echo wp_json_encode( array( 'own_fixture_native_error' => $co_native_diagnostic ) ), "\n"; }
	restore_error_handler();
}
if ( $co_failure || ! $co_clean || ! $co_preserved ) {
	fwrite( STDERR, $co_failure ? $co_failure->getMessage() . "\n" : "Cleanup/state proof failed; private checkpoint retained.\n" );
	exit( 1 );
}
echo 'PASS ' . $co_checks . " genuine WooCommerce checks\n";
