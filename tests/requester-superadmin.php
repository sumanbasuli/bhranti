<?php
/**
 * Offline multisite-superadmin bypass regression using native WordPress code.
 *
 * Native WP_User::has_cap, map_meta_cap, is_super_admin, hook dispatch and KSES
 * initialization run unchanged. Only the in-memory account and network lookup
 * are seams; no WordPress bootstrap, database, HTTP, AI or role writes occur.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/requester-superadmin.php
 *
 * @package Bhranti
 */

define( 'ABSPATH', rtrim( getenv( 'BHRANTI_TEST_WP_ROOT' ) ?: '/Users/txsadhu/Documents/WordPress/Plugins', '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'ALLOW_UNFILTERED_UPLOADS', true );
require ABSPATH . WPINC . '/class-wp-error.php';
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/class-wp-user.php';
require ABSPATH . WPINC . '/capabilities.php';
require ABSPATH . WPINC . '/kses.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-identity.php';
function __( $text, $domain = 'default' ) { return $text; }
function is_multisite() { return true; }
function wp_get_current_user() { return $GLOBALS['identity_superadmin']; }
function get_userdata( $id ) { return 7 === (int) $id ? wp_get_current_user() : false; }

$super                  = ( new ReflectionClass( 'WP_User' ) )->newInstanceWithoutConstructor();
$super->ID              = 7;
$super->data            = (object) array( 'user_login' => 'offline-superadmin' );
$super->allcaps         = array();
$GLOBALS['identity_superadmin'] = $super;
$GLOBALS['super_admins'] = array( 'offline-superadmin' );
$checks                 = 0;
$check                  = static function ( $condition, $message ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
};
$check( is_super_admin( 7 ) && current_user_can( 'unfiltered_html' ) && current_user_can( 'unfiltered_upload' ), 'Actual native multisite-superadmin bypass is active before the tool.' );
kses_init();
$check( false === has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'Native superadministrator would normally bypass post KSES.' );
Bhranti_Identity::scope( static function () use ( $check ) {
	$check( ! current_user_can( 'unfiltered_html' ) && ! current_user_can( 'unfiltered_upload' ), 'The native superadministrator shortcut honors explicit scoped denial.' );
	$check( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'Native KSES initialization enables post filtering inside the tool.' );
	$check( current_user_can( 'edit_posts' ), 'Unrelated native superadministrator permissions remain available.' );
} );
$check( current_user_can( 'unfiltered_html' ) && current_user_can( 'unfiltered_upload' ) && false === has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'Finally restores native superadministrator permissions and KSES behavior.' );
try {
	Bhranti_Identity::scope( static function () { throw new RuntimeException( 'expected-superadmin-exception' ); } );
} catch ( RuntimeException $error ) {
	$check( 'expected-superadmin-exception' === $error->getMessage(), 'The expected callback exception propagates.' );
}
$check( current_user_can( 'unfiltered_html' ) && array() === $super->allcaps && false === has_filter( 'map_meta_cap', array( 'Bhranti_Identity', 'authoring_caps' ) ), 'Exception cleanup leaves no persisted grants or scoped filter.' );
echo 'PASS ' . $checks . " native multisite-superadmin authoring checks; no DB, HTTP or AI.\n";
