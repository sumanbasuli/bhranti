<?php
/**
 * Native account-memory erasure recovery on an explicitly disposable site.
 *
 * Set BHRANTI_MEMORY_NATIVE_WP_ROOT and create .bhranti-memory-erasure-qa there
 * containing disposable-memory-erasure. No original site or network is allowed.
 * BHRANTI_MEMORY_WP_CLI supplies the local wp-cli.phar for native Cron dispatch.
 *
 * @package Bhranti
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions,WordPress.PHP.DiscouragedPHPFunctions,WordPress.WP.AlternativeFunctions -- Guarded, non-shipped CLI tests require local fixture markers, separate native worker processes and diagnostic capture.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	set_error_handler(
		static function ( $severity, $message, $file ) {
			return E_DEPRECATED === $severity && ( 0 === strpos( $file, 'phar://' ) || false !== strpos( $file, 'sqlite-database-integration' ) );
		}
	);
	return;
}
set_exception_handler(
	static function ( $error ) {
		fwrite( STDERR, $error->getMessage() . "\n" . $error->getTraceAsString() . "\n" );
		exit( 1 );
	}
);
$memory_root = realpath( (string) getenv( 'BHRANTI_MEMORY_NATIVE_WP_ROOT' ) );
if ( 'cli' !== PHP_SAPI || ! $memory_root || ! is_file( $memory_root . '/.bhranti-memory-erasure-qa' ) || 0 !== strpos( $memory_root, '/private/tmp/bhranti-' ) || 'disposable-memory-erasure' !== trim( (string) file_get_contents( $memory_root . '/.bhranti-memory-erasure-qa' ) ) ) {
	throw new RuntimeException( 'Use only an explicitly marked disposable native WordPress fixture.' );
}
$memory_diagnostics = array();
set_error_handler(
	static function ( $severity, $message, $file, $line ) use ( &$memory_diagnostics ) {
		if ( ! ( error_reporting() & $severity ) ) {
			return false;
		}
		if ( E_DEPRECATED === $severity && false !== strpos( $file, 'sqlite-database-integration' ) ) {
			return true;
		}
		$memory_diagnostics[] = array(
			'severity' => $severity,
			'message'  => $message,
			'file'     => $file,
			'line'     => $line,
		);
		return false;
	}
);
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'memory-network.example.invalid';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require $memory_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! WP_HTTP_BLOCK_EXTERNAL || ! class_exists( 'Bhranti_Memory' ) || hash_file( 'sha256', ( new ReflectionClass( 'Bhranti_Memory' ) )->getFileName() ) !== hash_file( 'sha256', dirname( __DIR__ ) . '/includes/class-bhranti-memory.php' ) ) {
	throw new RuntimeException( 'Stage this exact source in the offline disposable fixture first.' );
}
$memory_checks  = 0;
$memory_created = array();
$memory_sites   = array();
$memory_prefix  = 'memory_erasure_' . bin2hex( random_bytes( 4 ) );
$memory_started = microtime( true );
$memory_admin   = get_current_user_id();
wp_set_current_user( 1 );

/**
 * Assert an observable privacy or scheduler property.
 *
 * @param mixed  $condition Observable assertion value.
 * @param string $label Failure description.
 * @throws RuntimeException If the assertion fails.
 */
function me_check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label ) );
	}
	++$GLOBALS['memory_checks'];
}

/**
 * Read only an exact private fixture option without a stale option cache.
 *
 * @param string $name Fixed local option name.
 * @return string|null
 */
function me_raw( $name ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
}

/**
 * Retrieve the inert local pending-generation fixture data.
 *
 * @param int $user_id Test subject.
 * @return array|null
 */
function me_pending( $user_id ) {
	$raw = me_raw( 'bhranti_memory_erasure_' . $user_id );
	return is_string( $raw ) ? json_decode( $raw, true ) : null;
}

/** Create one unique native administrator used by these checks only. */
function me_user() {
	$name = $GLOBALS['memory_prefix'] . '_' . count( $GLOBALS['memory_created'] );
	$id   = wp_insert_user(
		array(
			'user_login' => $name,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => $name . '@example.invalid',
			'role'       => 'administrator',
		)
	);
	me_check( ! is_wp_error( $id ) && $id > 1, 'Unique native fixture administrator created.' );
	$GLOBALS['memory_created'][] = $id;
	return $id;
}

/**
 * Save an explicitly requested preference through the real owned memory tool.
 *
 * @param int    $id Test subject.
 * @param string $text Explicit human preference.
 * @return string
 */
function me_save( $id, $text = 'Use clear and helpful answers' ) {
	wp_set_current_user( $id );
	$session = wp_insert_post(
		array(
			'post_type'   => 'bhranti_session',
			'post_status' => 'private',
			'post_author' => $id,
			'post_title'  => $GLOBALS['memory_prefix'],
		)
	);
	$message = 'Please remember: ' . $text;
	update_post_meta(
		$session,
		'bhranti_messages',
		array(
			array(
				'role'    => 'user',
				'content' => $message,
			),
		)
	);
	$view = Bhranti_Memory::get();
	me_check( ! is_wp_error( $view ), 'Fixture user can review memory: ' . ( is_wp_error( $view ) ? $view->get_error_code() : '' ) . ' user=' . $id . ' blog=' . get_current_blog_id() . ' exists=' . ( get_userdata( $id ) ? 'yes' : 'no' ) . ' manage=' . ( user_can( $id, 'manage_options' ) ? 'yes' : 'no' ) );
	$result = Bhranti_Memory::execute(
		array(
			'tool' => 'bhranti_memory',
			'args' => array(
				'method'   => 'POST',
				'category' => 'tone',
				'text'     => $text,
				'evidence' => $message,
				'revision' => $view['revision'],
			),
		),
		$id,
		$session
	);
	me_check( ! is_wp_error( $result ), 'Native owned preference saved.' );
	wp_set_current_user( 1 );
	return me_raw( 'bhranti_memory_user_' . $id );
}

/**
 * Force a fresh view of this site's native scheduler option after another process.
 *
 * @param int   $id Test subject.
 * @param array $pending Exact pending generation.
 * @return object|false
 */
function me_event( $id, $pending ) {
	wp_cache_delete( 'cron', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	return wp_get_scheduled_event( 'bhranti_memory_erase_user', array( $id, $pending['generation'] ) );
}

/**
 * Run the registered worker in a separate native WordPress Cron command.
 *
 * @param array $arguments Exact native CLI arguments.
 */
function me_cli( $arguments ) {
	$cli = realpath( (string) getenv( 'BHRANTI_MEMORY_WP_CLI' ) );
	me_check( $cli && is_file( $cli ), 'Local native WP-CLI is supplied.' );
	$process = proc_open(
		array_merge( array( PHP_BINARY, '-d', 'error_reporting=24575', $cli ), $arguments, array( '--path=' . ABSPATH, '--url=' . home_url( '/' ), '--skip-themes', '--require=' . __FILE__ ) ),
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);
	me_check( is_resource( $process ), 'Native Cron worker starts in a separate PHP process.' );
	$output = stream_get_contents( $pipes[1] );
	$error  = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	me_check( 0 === proc_close( $process ) && '' === $error && false !== strpos( $output, 'Success:' ), 'Native Cron event dispatch succeeds: ' . $output . $error );
}

/** Dispatch the registered callback through native WordPress Cron. */
function me_cron() {
	me_cli( array( 'cron', 'event', 'run', 'bhranti_memory_erase_user' ) );
}

try {
	$live       = me_user();
	$live_bytes = me_save( $live );
	do_action( 'bhranti_memory_erase_user', $live, str_repeat( 'a', 32 ) );
	me_check( me_raw( 'bhranti_memory_user_' . $live ) === $live_bytes, 'A direct event without owned deletion intent cannot erase a live user.' );
	foreach ( array( 0, -1, '1e2', array( 1 ) ) as $invalid ) {
		me_check( is_wp_error( Bhranti_Memory::retry_erasure( $invalid, str_repeat( 'a', 32 ) ) ), 'Malformed subject cannot address private memory.' );
	}

	$deleted = me_user();
	$old     = me_save( $deleted );
	$lock    = Bhranti_Lock::acquire( 'bhranti_memory_lock', 600 );
	me_check( is_array( $lock ), 'Real native operation guard is held.' );
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		me_check( wpmu_delete_user( $deleted ), 'Native network account deletion succeeds while erasure is contended.' );
	} else {
		me_check( wp_delete_user( $deleted ), 'Native account deletion succeeds while erasure is contended.' );
	}
	$pending = me_pending( $deleted );
	me_check( ! get_userdata( $deleted ) && is_array( $pending ) && $pending['deleted'], 'Deleted account retains an exact durable cleanup intent.' );
	me_check( me_raw( 'bhranti_memory_user_' . $deleted ) === $old, 'Contended deletion leaves existing ciphertext for retry.' );
	$event = me_event( $deleted, $pending );
	me_check( $event && $event->timestamp >= time() && $event->timestamp <= time() + 60, 'First cleanup is scheduled with a bounded delay.' );
	me_cron();
	$retry = me_pending( $deleted );
	$event = me_event( $deleted, $retry );
	me_check( 1 === $retry['attempt'] && $event && $event->timestamp <= time() + 300, 'A still-held guard backs off without dropping the durable intent.' );
	me_check( me_raw( 'bhranti_memory_user_' . $deleted ) === $old, 'Failed retry cannot partially erase the record.' );
	me_check( Bhranti_Lock::release( 'bhranti_memory_lock', $lock ), 'Native operation guard releases.' );
	$lock = null;
	me_cron();
	me_check( null === me_pending( $deleted ) && ! me_event( $deleted, $retry ), 'Successful native Cron retry clears only its completed generation.' );
	me_check(
		array(
			'preferences'  => array(),
			'capabilities' => array(),
		) === Bhranti_Memory::export_user( $deleted ),
		'Deleted account has no remaining preferences.'
	);
	$erased = me_raw( 'bhranti_memory_user_' . $deleted );
	do_action( 'bhranti_memory_erase_user', $deleted, $retry['generation'] );
	me_check( me_raw( 'bhranti_memory_user_' . $deleted ) === $erased, 'Replayed completed generation is harmless.' );

	$failed = me_user();
	me_save( $failed );
	$lock   = Bhranti_Lock::acquire( 'bhranti_memory_lock', 600 );
	$reject = static function ( $pre, $event ) {
		return 'bhranti_memory_erase_user' === $event->hook ? new WP_Error( 'fixture_schedule_rejected', 'Expected scheduling failure.' ) : $pre;
	};
	add_filter( 'pre_schedule_event', $reject, 10, 2 );
	is_multisite() ? wpmu_delete_user( $failed ) : wp_delete_user( $failed );
	$pending = me_pending( $failed );
	me_check( is_array( $pending ) && ! me_event( $failed, $pending ), 'Scheduling rejection retains durable intent without a false event.' );
	remove_filter( 'pre_schedule_event', $reject, 10 );
	Bhranti_Lock::release( 'bhranti_memory_lock', $lock );
	$lock = null;
	Bhranti_Memory::resume_erasures();
	me_check( (bool) me_event( $failed, $pending ), 'Native administrator recovery requeues failed scheduling.' );
	Bhranti_Memory::resume_erasures();
	$matching = 0;
	foreach ( _get_cron_array() as $events ) {
		foreach ( $events['bhranti_memory_erase_user'] ?? array() as $candidate ) {
			$matching += array( $failed, $pending['generation'] ) === $candidate['args'] ? 1 : 0;
		}
	}
	me_check( 1 === $matching, 'Repeated administrator recovery keeps one callback per exact generation.' );
	deactivate_plugins( 'bhranti/bhranti.php', false, is_multisite() );
	me_check( ! me_event( $failed, $pending ) && me_pending( $failed ) === $pending, 'Deactivation clears native callbacks but preserves unfinished privacy intent.' );
	me_check( ! is_wp_error( activate_plugin( 'bhranti/bhranti.php', '', is_multisite() ) ), 'Native plugin reactivation succeeds.' );
	Bhranti_Memory::resume_erasures();
	me_check( (bool) me_event( $failed, $pending ), 'Explicit reactivation can resume retained cleanup.' );
	me_cron();
	me_check( null === me_pending( $failed ), 'Recovered scheduling eventually completes erasure.' );

	$intent          = array(
		'site'       => get_current_blog_id(),
		'user'       => $live,
		'deleted'    => true,
		'attempt'    => 0,
		'generation' => bin2hex( random_bytes( 16 ) ),
		'revision'   => hash( 'sha256', $live_bytes ),
	);
	$invalid_intents = array();
	foreach ( array(
		'site'       => get_current_blog_id() + 1,
		'user'       => $live + 1,
		'attempt'    => -1,
		'generation' => 'invalid',
		'revision'   => array( 'invalid' ),
	) as $field => $value ) {
		$bad               = $intent;
		$bad[ $field ]     = $value;
		$invalid_intents[] = wp_json_encode( $bad );
	}
	$invalid_intents[] = '{bad json';
	$invalid_intents[] = str_repeat( 'x', 300 );
	foreach ( $invalid_intents as $invalid_intent ) {
		update_option( 'bhranti_memory_erasure_' . $live, $invalid_intent, false );
		$invalid_result = Bhranti_Memory::retry_erasure( $live, $intent['generation'] );
		me_check( is_wp_error( $invalid_result ) && me_raw( 'bhranti_memory_user_' . $live ) === $live_bytes, 'Malformed or cross-owner intent never erases current memory.' );
	}
	delete_option( 'bhranti_memory_erasure_' . $live );

	$pending = array(
		'site'       => get_current_blog_id(),
		'user'       => $live,
		'deleted'    => true,
		'attempt'    => 0,
		'generation' => bin2hex( random_bytes( 16 ) ),
		'revision'   => hash( 'sha256', $live_bytes ),
	);
	update_option( 'bhranti_memory_erasure_' . $live, wp_json_encode( $pending ), false );
	me_check( true === Bhranti_Memory::retry_erasure( $live, $pending['generation'] ) && me_raw( 'bhranti_memory_user_' . $live ) === $live_bytes, 'Even an exact stored stale intent refuses a live or recreated account.' );
	$pending['generation'] = bin2hex( random_bytes( 16 ) );
	update_option( 'bhranti_memory_erasure_' . $live, wp_json_encode( $pending ), false );
	me_check( false === Bhranti_Memory::retry_erasure( $live, str_repeat( 'a', 32 ) ) && me_pending( $live ) === $pending, 'A stale generation cannot consume a newer intent.' );
	$newer               = $pending;
	$newer['generation'] = bin2hex( random_bytes( 16 ) );
	update_option( 'bhranti_memory_erasure_' . $live, wp_json_encode( $newer ), false );
	wp_schedule_single_event( time() + 60, 'bhranti_memory_erase_user', array( $live, $newer['generation'] ), true );
	$finish = new ReflectionMethod( Bhranti_Memory::class, 'finish_erasure' );
	me_check( true === $finish->invoke( null, $live, $pending ) && me_pending( $live ) === $newer && me_event( $live, $newer ), 'An old finisher preserves a newer removal-cycle intent and callback.' );
	Bhranti_Memory::retry_erasure( $live, $newer['generation'] );

	if ( is_multisite() ) {
		$site = wpmu_create_blog( 'memory-network.example.invalid', '/' . $memory_prefix . '/', 'Owned memory test site', 1 );
		me_check( ! is_wp_error( $site ) && $site > 1, 'Native separate multisite blog created.' );
		$memory_sites[] = $site;
		$super          = me_user();
		grant_super_admin( $super );
		add_user_to_blog( $site, $super, 'administrator' );
		switch_to_blog( $site );
		Bhranti_Memory::activate();
		me_save( $super, 'Use concise main instructions' );
		$lock = Bhranti_Lock::acquire( 'bhranti_memory_lock', 600 );
		wp_delete_user( $super );
		$pending = me_pending( $super );
		Bhranti_Lock::release( 'bhranti_memory_lock', $lock );
		$lock = null;
		me_check( ! is_user_member_of_blog( $super, $site ) && user_can( $super, 'manage_options' ), 'Removed network super administrator retains native WordPress access.' );
		$new_super_memory = me_save( $super, 'Use helpful answers after local removal' );
		Bhranti_Memory::retry_erasure( $super, $pending['generation'] );
		me_check( me_raw( 'bhranti_memory_user_' . $super ) === $new_super_memory, 'Stale local-removal retry preserves new acknowledged super-admin memory.' );
		restore_current_blog();
		revoke_super_admin( $super );

		switch_to_blog( $site );
		$known = me_user();
		grant_super_admin( $known );
		$known_bytes = me_save( $known, 'Use cautious answers during storage recovery' );
		$lock        = Bhranti_Lock::acquire( 'bhranti_memory_lock', 600 );
		wp_delete_user( $known );
		$pending = me_pending( $known );
		Bhranti_Lock::release( 'bhranti_memory_lock', $lock );
		$lock      = null;
		$fail_read = static function ( $query ) use ( $known ) {
			return false !== strpos( $query, ' AS payload,' ) && false !== strpos( $query, 'bhranti_memory_user_' . $known . "'" ) ? 'SELECT expected_missing_fingerprint_column' : $query;
		};
		add_filter( 'query', $fail_read );
		$failed_read = Bhranti_Memory::retry_erasure( $known, $pending['generation'] );
		remove_filter( 'query', $fail_read );
		me_check( is_wp_error( $failed_read ) && is_array( me_pending( $known ) ) && me_raw( 'bhranti_memory_user_' . $known ) === $known_bytes, 'Fingerprint read failure preserves known-generation intent and private data.' );
		me_check( me_event( $known, me_pending( $known ) ), 'Technical fingerprint failure retains a native retry.' );
		me_cron();
		me_check( null === me_pending( $known ) && ! Bhranti_Memory::export_user( $known )['preferences'], 'Known snapshot cleanup completes once native reads recover.' );
		revoke_super_admin( $known );

		$unknown = me_user();
		grant_super_admin( $unknown );
		$unknown_bytes = me_save( $unknown, 'Use careful words when storage is uncertain' );
		$lock          = Bhranti_Lock::acquire( 'bhranti_memory_lock', 600 );
		$fail_initial  = static function ( $query ) use ( $unknown ) {
			return false !== strpos( $query, ' AS payload,' ) && false !== strpos( $query, 'bhranti_memory_user_' . $unknown . "'" ) ? 'SELECT expected_missing_initial_fingerprint_column' : $query;
		};
		add_filter( 'query', $fail_initial );
		wp_delete_user( $unknown );
		remove_filter( 'query', $fail_initial );
		$pending = me_pending( $unknown );
		me_check( is_array( $pending ) && null === $pending['revision'], 'An uncaptured initial fingerprint retains a durable unknown intent.' );
		Bhranti_Lock::release( 'bhranti_memory_lock', $lock );
		$lock      = null;
		$uncertain = Bhranti_Memory::retry_erasure( $unknown, $pending['generation'] );
		me_check( is_wp_error( $uncertain ) && is_array( me_pending( $unknown ) ) && me_raw( 'bhranti_memory_user_' . $unknown ) === $unknown_bytes, 'Retained authorized account with unknown initial snapshot stays safely pending.' );
		me_check( true === Bhranti_Memory::erase_user( $unknown ), 'Explicit native privacy erasure can resolve unknown snapshot safely.' );
		$pending = me_pending( $unknown );
		me_check( true === Bhranti_Memory::retry_erasure( $unknown, $pending['generation'] ) && null === me_pending( $unknown ), 'Proven erased owner safely completes unknown intent.' );
		revoke_super_admin( $unknown );
		restore_current_blog();

		$member     = me_user();
		$main_bytes = me_save( $member, 'Use concise answers on the main site' );
		me_check( ! is_wp_error( add_user_to_blog( $site, $member, 'administrator' ) ), 'Native membership grants fixture administrator on second site.' );
		switch_to_blog( $site );
		Bhranti_Memory::activate();
		$second_bytes = me_save( $member, 'Use friendly answers on the second site' );
		me_check( $main_bytes !== $second_bytes, 'One user has distinct ciphertext on each native site.' );
		update_option( 'bhranti_memory_user_' . $member, $main_bytes, false );
		wp_set_current_user( $member );
		me_check( Bhranti_Memory::get()['message'] && ! Bhranti_Memory::get()['preferences'], 'Copied cross-site ciphertext cannot be decrypted.' );
		update_option( 'bhranti_memory_user_' . $member, $second_bytes, false );
		wp_set_current_user( 1 );
		$lock = Bhranti_Lock::acquire( 'bhranti_memory_lock', 600 );
		me_check( wp_delete_user( $member ), 'Native local membership deletion succeeds.' );
		$pending = me_pending( $member );
		me_check( get_userdata( $member ) && ! is_user_member_of_blog( $member, $site ) && ! $pending['deleted'], 'Local deletion intent is distinct from global account deletion.' );
		Bhranti_Lock::release( 'bhranti_memory_lock', $lock );
		$lock = null;
		restore_current_blog();
		me_check( me_raw( 'bhranti_memory_user_' . $member ) === $main_bytes, 'Local membership removal preserved other-site memory.' );
		switch_to_blog( $site );
		add_user_to_blog( $site, $member, 'administrator' );
		$new_preference = me_save( $member, 'Use a practical tone after rejoining' );
		Bhranti_Memory::retry_erasure( $member, $pending['generation'] );
		me_check( me_raw( 'bhranti_memory_user_' . $member ) === $new_preference && null === me_pending( $member ), 'Retry rechecks rejoined membership and preserves newly saved preferences.' );
		$lock = Bhranti_Lock::acquire( 'bhranti_memory_lock', 600 );
		wp_delete_user( $member );
		$pending = me_pending( $member );
		Bhranti_Lock::release( 'bhranti_memory_lock', $lock );
		$lock = null;
		update_option( 'bhranti_memory_user_' . $member, $second_bytes, false );
		me_check( ! user_can( $member, 'manage_options' ) && hash( 'sha256', $second_bytes ) !== $pending['revision'], 'A removed ordinary administrator can have a changed older in-flight revision.' );
		me_cron();
		me_check( ! is_wp_error( Bhranti_Memory::export_user( $member ) ) && ! Bhranti_Memory::export_user( $member )['preferences'], 'Native local-deletion Cron erases only its current-site memory.' );
		restore_current_blog();
		me_check( me_raw( 'bhranti_memory_user_' . $member ) === $main_bytes, 'Successful local cleanup still preserves main-site data.' );
		$lock = Bhranti_Lock::acquire( 'bhranti_memory_lock', 600 );
		wpmu_delete_user( $member );
		$pending = me_pending( $member );
		Bhranti_Lock::release( 'bhranti_memory_lock', $lock );
		$lock = null;
		me_cron();
		me_check( ! Bhranti_Memory::export_user( $member )['preferences'], 'Global account deletion eventually erases main-site memory.' );
		switch_to_blog( $site );
		me_check( ! Bhranti_Memory::export_user( $member )['preferences'], 'Global deletion includes a former membership site.' );
		restore_current_blog();
		$partial = me_user();
		add_user_to_blog( $site, $partial, 'administrator' );
		switch_to_blog( $site );
		$partial_bytes = me_save( $partial, 'Use thoughtful answers during recovery' );
		$interrupted   = Bhranti_Memory::uninstall_network(
			static function () use ( $partial, $partial_bytes ) {
				me_cli( array( 'user', 'delete', (string) $partial, '--yes' ) );
				me_check( is_array( me_pending( $partial ) ) && me_raw( 'bhranti_memory_user_' . $partial ) === $partial_bytes, 'Another process preserves deletion intent while a network uninstall holds its guard.' );
				return new WP_Error( 'expected_interrupted_network_uninstall', 'Expected fixture interruption.' );
			}
		);
		me_check( is_wp_error( $interrupted ), 'Fixture network uninstall interrupted before sweeping this site.' );
		Bhranti_Memory::resume_erasures();
		$pending = me_pending( $partial );
		me_check( me_event( $partial, $pending ), 'Interrupted network uninstall recovers the retained privacy intent.' );
		me_cron();
		me_check( ! Bhranti_Memory::export_user( $partial )['preferences'], 'Recovered network lifecycle finally erases the unswept owner.' );
		clean_user_cache( $partial );
		me_check( ! is_wp_error( add_user_to_blog( $site, $partial, 'administrator' ) ), 'Partial-local fixture user can rejoin.' );
		$partial_bytes = me_save( $partial, 'Use clear answers after local recovery' );
		$fail_delete   = static function ( $query ) use ( $partial ) {
			return 0 === strpos( $query, 'DELETE FROM' ) && false !== strpos( $query, 'bhranti_memory_user_' . $partial . "'" ) ? 'DELETE FROM expected_missing_memory_fixture_table' : $query;
		};
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail_delete );
		$partial_local = Bhranti_Memory::uninstall_site();
		remove_filter( 'query', $fail_delete );
		$wpdb->suppress_errors( $suppressed );
		me_check( is_wp_error( $partial_local ) && 'uninstalled' === get_option( 'bhranti_memory_state' ) && me_raw( 'bhranti_memory_user_' . $partial ) === $partial_bytes, 'Failed local uninstall retains unswept owner bytes behind its tombstone.' );
		wp_delete_user( $partial );
		$pending = me_pending( $partial );
		me_check( is_array( $pending ) && me_raw( 'bhranti_memory_user_' . $partial ) === $partial_bytes, 'Local tombstone alone never discards required owner-erasure intent.' );
		Bhranti_Memory::activate();
		Bhranti_Memory::resume_erasures();
		me_cron();
		me_check( null === me_pending( $partial ) && ! Bhranti_Memory::export_user( $partial )['preferences'], 'Explicit recovery from failed local uninstall completes pending erasure.' );

		me_check( true === Bhranti_Memory::uninstall_site(), 'Owned disposable second site completes native memory uninstall.' );
		Bhranti_Memory::deleted_user( $partial );
		me_check( null === me_pending( $partial ), 'Loaded deletion callback after completed local uninstall recreates no intent or event.' );
		me_check( true === Bhranti_Memory::activate(), 'Explicit activation restores the owned test site after cleanup.' );
		restore_current_blog();

		me_check( 1 === get_current_blog_id(), 'All network cleanup restores the original blog.' );
	}
	me_check( array() === $memory_diagnostics, 'No unexpected native PHP diagnostic: ' . wp_json_encode( $memory_diagnostics ) );
	echo wp_json_encode(
		array(
			'checks'      => $memory_checks,
			'multisite'   => is_multisite(),
			'seconds'     => round( microtime( true ) - $memory_started, 3 ),
			'diagnostics' => $memory_diagnostics,
		)
	) . "\n";
} finally {
	if ( isset( $lock ) && is_array( $lock ) ) {
		Bhranti_Lock::release( 'bhranti_memory_lock', $lock );
	}
	while ( is_multisite() && ms_is_switched() ) {
		restore_current_blog();
	}
	wp_set_current_user( 1 );
	foreach ( $memory_created as $memory_subject_id ) {
		$user = get_userdata( $memory_subject_id );
		if ( $user && 0 === strpos( $user->user_login, $memory_prefix ) ) {
			if ( is_multisite() ) {
				revoke_super_admin( $memory_subject_id );
				wpmu_delete_user( $memory_subject_id );
			} else {
				wp_delete_user( $memory_subject_id );
			}
		}
		$pending = me_pending( $memory_subject_id );
		if ( is_array( $pending ) ) {
			wp_clear_scheduled_hook( 'bhranti_memory_erase_user', array( $memory_subject_id, $pending['generation'] ), true );
		}
		delete_option( 'bhranti_memory_erasure_' . $memory_subject_id );
		delete_option( 'bhranti_memory_user_' . $memory_subject_id );
	}
	foreach ( get_posts(
		array(
			'post_type'   => 'bhranti_event',
			'post_status' => 'private',
			'author'      => 1,
			'numberposts' => 100,
			'orderby'     => 'ID',
			'order'       => 'DESC',
		)
	) as $event ) {
		$data = get_post_meta( $event->ID, 'bhranti_event_data', true );
		if ( 'memory_erasure_failed' === ( $data['type'] ?? '' ) && in_array( (int) ( $data['details']['target_id'] ?? 0 ), $memory_created, true ) ) {
			wp_delete_post( $event->ID, true );
		}
	}

	foreach ( $memory_sites as $site ) {
		wp_delete_site( $site );
	}
	wp_set_current_user( $memory_admin );
}
