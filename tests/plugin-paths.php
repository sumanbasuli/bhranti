<?php
/**
 * Offline path regression fixture; no wp-load, database, HTTP, activation or AI.
 * Usage: php this.php PLUGIN_ROOT WORDPRESS_ROOT physical|alias
 * The production protection assertions run before the proposed helper is read.
 */
namespace WordPress\AiClient {
	final class AiClient {
		public static $registry;
		public static function defaultRegistry() {
			return self::$registry;
		}
	}
}

namespace {
	if ( PHP_SAPI !== 'cli' || 4 !== count( $argv ) || ! in_array( $argv[3], array( 'physical', 'alias' ), true ) ) {
		fwrite( STDERR, "Usage: php fixture.php PLUGIN_ROOT WORDPRESS_ROOT physical|alias\n" );
		exit( 2 );
	}
	$plugin_source = realpath( $argv[1] );
	$core_source   = realpath( $argv[2] );
	if ( ! $plugin_source || ! $core_source || ! is_file( $plugin_source . '/includes/class-bhranti-plugins.php' ) || ! is_file( $core_source . '/wp-includes/functions.php' ) ) {
		throw new \RuntimeException( 'The requested local source trees are unavailable.' );
	}
	$sandbox = sys_get_temp_dir() . '/bhranti-paths-' . bin2hex( random_bytes( 10 ) );
	if ( ! mkdir( $sandbox, 0700 ) ) {
		throw new \RuntimeException( 'Could not create the uniquely owned temporary fixture.' );
	}
	$sandbox = realpath( $sandbox );
	function path_fixture_remove( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( is_dir( $path ) ) {
			foreach ( scandir( $path ) as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					path_fixture_remove( $path . '/' . $name );
				}
			}
			rmdir( $path );
		}
	}
	register_shutdown_function( static function () use ( $sandbox ) { path_fixture_remove( $sandbox ); } );
	$physical = $sandbox . '/physical';
	mkdir( $physical, 0700 );
	mkdir( $physical . '/bhranti', 0700 );
	mkdir( $physical . '/provider-folder', 0700 );
	mkdir( $sandbox . '/outside', 0700 );
	$files = array(
		$physical . '/bhranti/bhranti.php'       => "<?php\n/**\n * Plugin Name: Inert own plugin fixture\n */\n",
		$physical . '/provider-folder/main.php' => "<?php\n/**\n * Plugin Name: Inert directory provider fixture\n */\nfinal class Bhranti_Path_Folder_Provider {}\n",
		$physical . '/root-provider.php'        => "<?php\n/**\n * Plugin Name: Inert root provider fixture\n */\nfinal class Bhranti_Path_Root_Provider {}\n",
		$sandbox . '/outside/main.php'          => "<?php\n/**\n * Plugin Name: Inert external provider fixture\n */\nfinal class Bhranti_Path_Outside_Provider {}\n",
	);
	foreach ( $files as $file => $contents ) {
		if ( strlen( $contents ) !== file_put_contents( $file, $contents ) ) {
			throw new \RuntimeException( 'Could not prepare an owned inert source file.' );
		}
	}
	foreach ( array(
		array( $physical, $sandbox . '/alias' ),
		array( $physical . '/root-provider.php', $physical . '/linked-file.php' ),
		array( $physical . '/provider-folder', $physical . '/linked-inside' ),
		array( $sandbox . '/outside', $physical . '/linked-outside' ),
	) as $link ) {
		if ( ! symlink( $link[0], $link[1] ) ) {
			throw new \RuntimeException( 'The local fixture requires temporary symlink support.' );
		}
	}
	define( 'ABSPATH', $core_source . '/' );
	define( 'WPINC', 'wp-includes' );
	define( 'WP_DEBUG', false );
	define( 'WP_CONTENT_DIR', $sandbox );
	define( 'WP_PLUGIN_DIR', 'alias' === $argv[3] ? $sandbox . '/alias' : $physical );
	define( 'WPMU_PLUGIN_DIR', $sandbox . '/unused-mu' );
	define( 'BHRANTI_FILE', WP_PLUGIN_DIR . '/bhranti/bhranti.php' );
	define( 'KB_IN_BYTES', 1024 );
	define( 'MB_IN_BYTES', 1048576 );
	require ABSPATH . WPINC . '/load.php';
	require ABSPATH . WPINC . '/plugin.php';
	require ABSPATH . WPINC . '/functions.php';
	require ABSPATH . WPINC . '/formatting.php';
	require ABSPATH . WPINC . '/class-wp-error.php';
	require ABSPATH . WPINC . '/pomo/translations.php';
	require ABSPATH . WPINC . '/l10n.php';
	require ABSPATH . WPINC . '/cache.php';
	wp_cache_init();
	$GLOBALS['wp_plugin_paths'] = array();
	$GLOBALS['l10n'] = array( 'default' => new \NOOP_Translations(), 'bhranti' => new \NOOP_Translations() );
	require $physical . '/provider-folder/main.php';
	require $physical . '/root-provider.php';
	require $sandbox . '/outside/main.php';
	final class Bhranti_Path_Registry {
		private $providers = array(
			'folder'  => 'Bhranti_Path_Folder_Provider',
			'root'    => 'Bhranti_Path_Root_Provider',
			'outside' => 'Bhranti_Path_Outside_Provider',
		);
		public function getRegisteredProviderIds() { return array_keys( $this->providers ); }
		public function getProviderClassName( $id ) { return $this->providers[ $id ]; }
	}
	\WordPress\AiClient\AiClient::$registry = new Bhranti_Path_Registry();
	require $plugin_source . '/includes/class-bhranti-plugins.php';
	$checks = 0;
	function path_fixture_equal( $actual, $expected, $label ) {
		global $checks;
		if ( $actual !== $expected ) {
			throw new \RuntimeException( $label . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
		++$checks;
	}
	function path_fixture_method( $name ) {
		$method = new \ReflectionMethod( 'Bhranti_Plugins', $name );
		if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
		return $method;
	}

	// First exercise existing production behavior; old source fails here, not on a missing helper.
	$protected = path_fixture_method( 'protected_plugin' );
	path_fixture_equal( $protected->invoke( null, 'unrelated/main' ), false, 'A valid registry does not accidentally fail closed for an unrelated plugin' );
	path_fixture_equal( $protected->invoke( null, 'provider-folder/main' ), true, 'The native reflected directory provider remains protected under ' . $argv[3] . ' root' );
	path_fixture_equal( $protected->invoke( null, 'root-provider' ), true, 'The native reflected single-file provider remains protected' );
	path_fixture_equal( $protected->invoke( null, 'provider-folder/secondary' ), true, 'The provider protects its whole containing plugin directory' );
	path_fixture_equal( $protected->invoke( null, 'provider-folder-extra/main' ), false, 'A sibling prefix is not the provider plugin' );
	path_fixture_equal( $protected->invoke( null, 'outside/main' ), false, 'A reflected implementation outside the plugin root does not protect an unrelated target' );
	path_fixture_equal( $protected->invoke( null, 'bhranti/bhranti' ), true, 'The running plugin retains its existing protection' );
	path_fixture_equal( $protected->invoke( null, 'ai/ai' ), true, 'The native AI dependency retains its existing protection' );

	// The production state method must still refuse individual file/directory links.
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$installed = get_plugins();
	$state = path_fixture_method( 'state' );
	foreach ( array( 'linked-file', 'linked-inside/main', 'linked-outside/main' ) as $plugin ) {
		path_fixture_equal( isset( $installed[ $plugin . '.php' ] ), true, 'Core discovers the inert linked fixture before the Bhranti rejection' );
		$result = $state->invoke( null, array( 'create' => false, 'plugin' => $plugin, 'command' => array( 'args' => array( 'method' => 'GET' ) ) ) );
		path_fixture_equal( $result instanceof \WP_Error ? $result->get_error_code() : null, 'bhranti_plugin_blocked', 'Existing source rejects the individual linked plugin ' . $plugin );
	}

	// These fixed outputs test the actual shared helper; no copied implementation or platform stubs.
	$relative = path_fixture_method( 'relative_plugin_path' );
	foreach ( array(
		array( '/srv/wp/plugins/vendor/main.php', '/srv/wp/plugins', 'vendor/main.php', 'POSIX' ),
		array( '/srv/wp/plugins/vendor/main.php', '/srv/wp/plugins/', 'vendor/main.php', 'POSIX trailing root slash' ),
		array( 'C:\\site\\plugins\\vendor\\main.php', 'C:\\site\\plugins', 'vendor/main.php', 'Windows backslashes' ),
		array( 'c:/site/plugins/vendor/main.php', 'C:\\site\\plugins\\', 'vendor/main.php', 'Windows mixed separators and drive letter' ),
		array( '\\\\server\\share\\plugins\\vendor\\main.php', '\\\\server\\share\\plugins', 'vendor/main.php', 'UNC' ),
		array( '/srv/wp/plugins-extra/vendor/main.php', '/srv/wp/plugins', false, 'Sibling prefix' ),
		array( 'C:\\other\\plugins\\vendor\\main.php', 'C:\\site\\plugins', false, 'Outside Windows root' ),
		array( '/srv/wp/plugins', '/srv/wp/plugins', false, 'Root itself' ),
		array( '', '/srv/wp/plugins', false, 'Empty path' ),
		array( '/srv/wp/plugins/vendor/main.php', '', false, 'Empty root' ),
		array( false, '/srv/wp/plugins', false, 'Failed file resolution' ),
		array( '/srv/wp/plugins/vendor/main.php', false, false, 'Failed root resolution' ),
	) as $case ) {
		path_fixture_equal( $relative->invoke( null, $case[0], $case[1] ), $case[2], $case[3] . ' relative path' );
	}
	path_fixture_equal( $relative->invoke( null, realpath( WP_PLUGIN_DIR . '/provider-folder/main.php' ), realpath( WP_PLUGIN_DIR ) ), 'provider-folder/main.php', 'The fixture root resolves to the same physical containment' );
	path_fixture_equal( $relative->invoke( null, realpath( WP_PLUGIN_DIR . '/linked-outside/main.php' ), realpath( WP_PLUGIN_DIR ) ), false, 'A resolved external symlink remains outside containment' );
	echo 'PASS ' . $checks . ' offline plugin-path checks (' . $argv[3] . "); no WordPress bootstrap, DB, HTTP, activation or AI\n";
}
