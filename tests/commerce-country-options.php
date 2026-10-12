<?php
/**
 * Lossless oversized-country projection against installed native Woo choices.
 * No WordPress bootstrap, database, HTTP, AI or native write callbacks.
 */
define( 'ABSPATH', rtrim( getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Documents/WordPress/Plugins', '/' ) . '/' );
define( 'WC_ABSPATH', ABSPATH . 'wp-content/plugins/woocommerce/' );
define( 'WPINC', 'wp-includes' );
require ABSPATH . 'wp-includes/functions.php';
require ABSPATH . 'wp-includes/rest-api/endpoints/class-wp-rest-controller.php';
function __( $text, $domain = 'default' ) { return $text; }
function _x( $text, $context, $domain = 'default' ) { return $text; }
function WC() {
	return new class {
		public $countries;
		public function __construct() {
			$this->countries = new class {
				public function get_countries() { return include WC_ABSPATH . 'i18n/countries.php'; }
				public function get_states( $country ) { $states = include WC_ABSPATH . 'i18n/states.php'; return $states[ $country ] ?? array(); }
			};
		}
	};
}
foreach ( array( 'Version3/class-wc-rest-controller.php', 'Version2/class-wc-rest-setting-options-v2-controller.php', 'Version3/class-wc-rest-setting-options-controller.php' ) as $file ) {
	require WC_ABSPATH . 'includes/rest-api/Controllers/' . $file;
}
require dirname( __DIR__ ) . '/includes/class-bhranti-commerce.php';
$checks = 0;
function country_check( $valid, $label ) { if ( ! $valid ) { throw new RuntimeException( $label ); } ++$GLOBALS['checks']; }
function country_restore( $setting ) {
	$options = $setting['options'];
	foreach ( $setting['option_groups'] ?? array() as $group ) {
		foreach ( $group['options'] as $key => $label ) {
			country_check( ! array_key_exists( $key, $options ), 'Every native option appears exactly once' );
			$options[ $key ] = $group['label_prefix'] . $label;
		}
	}
	ksort( $options );
	return $options;
}
$native = new ReflectionMethod( 'WC_REST_Setting_Options_Controller', 'get_countries_and_states' );
$options = $native->invoke( new WC_REST_Setting_Options_Controller() );
$original = $options; ksort( $original );
$setting = array( 'id' => 'woocommerce_default_country', 'label' => 'Country / State', 'description' => 'Store location.', 'value' => 'IN:MH', 'default' => 'GB', 'type' => 'select', 'options' => $options );
$project = new ReflectionMethod( 'Bhranti_Commerce', 'project' );
$compact = new ReflectionMethod( 'Bhranti_Commerce', 'compact_country_options' );
$result = new ReflectionMethod( 'Bhranti_Commerce', 'result' );
$projected = $project->invoke( null, $setting, 'setting' );
country_check( count( $options ) >= 2221 && strlen( wp_json_encode( $options ) ) > 60000, 'Installed native choices reproduce the actual oversized result' );
country_check( isset( $projected['option_groups'] ) && $original === country_restore( $projected ), 'Every native country/state code and exact label roundtrips losslessly' );
country_check( array_diff_key( $setting, array( 'options' => true ) ) === array_diff_key( $projected, array( 'options' => true, 'option_groups' => true ) ), 'Current/default values and all other public metadata remain exact' );
$wrapped_bytes = 0;
foreach ( array( false, true ) as $write ) {
	$tool_result = $result->invoke( null, $projected, array( 'id' => 0, 'type' => 'woocommerce_settings', 'title' => 'WooCommerce Settings', 'url' => 'https://bhranti.wp.local/wp-admin/admin.php?page=wc-settings&tab=general' ), $write ? 'shop_00000000-0000-0000-0000-000000000000' : '', $write, $write ? 'Store setting saved.' : 'WooCommerce information retrieved.' );
	$bytes = strlen( wp_json_encode( array( 'success' => true, 'result' => $tool_result ) ) );
	$wrapped_bytes = max( $wrapped_bytes, $bytes );
	country_check( $bytes < 60000, 'Complete native read/write result including harness wrapper fits the actual budget' );
}
$small = $setting; $small['options'] = array( 'AA:X' => 'Example - Region' );
country_check( $small === $project->invoke( null, $small, 'setting' ), 'Small native setting presentation stays unchanged' );
$other = $setting; $other['id'] = 'woocommerce_specific_allowed_countries';
country_check( $other === $project->invoke( null, $other, 'setting' ), 'Other option schemas are never grouped' );
foreach ( array( null, false, 'not-an-options-array' ) as $malformed ) {
	$bad = $setting; $bad['options'] = $malformed;
	country_check( $bad === $project->invoke( null, $bad, 'setting' ), 'Malformed options types stay unchanged without warnings' );
}
$special = $setting;
$special['options'] = array(
	'ZZ:A' => 'État très long avec caractères - Région à côté',
	'ZZ:B' => 'État très long avec caractères - 北海道',
	'ZZ:C' => 'Different country label - Another region',
	'YY:X' => array( 'arbitrary' => 'native value' ),
	'YY:Y' => null,
	'no-state-key' => 'Arbitrary label - preserved',
	7 => 'Numeric key',
	'XY:X' => 'No separator',
);
// Repeat only valid independent choices so grouping actually saves bytes.
for ( $i = 0; $i < 20; ++$i ) { $special['options'][ 'ZZ:extra' . $i ] = 'État très long avec caractères - Région ' . $i; }
$special_projection = $compact->invoke( null, $special );
$special_original = $special['options']; ksort( $special_original );
country_check( isset( $special_projection['option_groups']['ZZ'] ) && $special_original === country_restore( $special_projection ), 'UTF-8 labels, mismatched prefixes and arbitrary native types survive exact reconstruction' );
country_check( 'État très long avec caractères - ' === $special_projection['option_groups']['ZZ']['label_prefix'], 'UTF-8 prefix is cut only at a complete ASCII delimiter boundary' );
country_check( $special['options']['ZZ:C'] === $special_projection['options']['ZZ:C'] && $special['options']['YY:X'] === $special_projection['options']['YY:X'], 'Conflicting prefixes and malformed label types remain in the original map' );
$invalid = $special; $invalid['options']['ZZ:bad'] = "Country\xff - Broken";
$invalid['options']["ZZ:\xff"] = 'État très long avec caractères - Region';
$invalid_projection = $compact->invoke( null, $invalid );
$invalid_original = $invalid['options']; ksort( $invalid_original );
country_check( $invalid['options']['ZZ:bad'] === $invalid_projection['options']['ZZ:bad'] && isset( $invalid_projection['options']["ZZ:\xff"] ) && $invalid_original === country_restore( $invalid_projection ), 'Invalid UTF-8 stays ungrouped without splitting or discarding native choices' );
echo 'PASS: ' . $checks . ' offline country projection checks; native choices=' . count( $options ) . '; original options bytes=' . strlen( wp_json_encode( $options ) ) . '; full result bytes=' . $wrapped_bytes . ". No bootstrap, database, network or AI.\n";
