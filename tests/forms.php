<?php
/**
 * Native CF7/Flamingo integration on a disposable, isolated WordPress copy only.
 *
 * BHRANTI_FORMS_QA_ROOT=/private/tmp/bhranti-forms-native-... php tests/forms.php
 * Install official Contact Form 7 and Flamingo archives in that copy beforehand.
 * No real site, provider, outbound HTTP or email is used. Test entries are fake.
 *
 * @package Bhranti
 */

$forms_root = realpath( getenv( 'BHRANTI_FORMS_QA_ROOT' ) ?: '' );
if ( ! $forms_root || 0 !== strpos( $forms_root, '/private/tmp/bhranti-forms-native-' ) || ! is_file( $forms_root . '/wp-content/database/.ht.sqlite' ) ) {
	throw new RuntimeException( 'Use an explicitly named isolated native forms QA copy, never a user site.' );
}
$GLOBALS['forms_qa_http'] = 0;
$GLOBALS['forms_qa_mail'] = 0;
$GLOBALS['forms_qa_cf7'] = 'assets-absent' !== ( $argv[1] ?? '' );
$GLOBALS['forms_qa_flamingo'] = true;
$GLOBALS['wp_filter']['option_active_plugins'][10][] = array(
	'function' => static function () {
		return array_merge( array( 'bhranti/bhranti.php' ), $GLOBALS['forms_qa_cf7'] ? array( 'contact-form-7/wp-contact-form-7.php' ) : array(), $GLOBALS['forms_qa_flamingo'] ? array( 'flamingo/flamingo.php' ) : array() );
	},
	'accepted_args' => 1,
);
$GLOBALS['wp_filter']['pre_http_request'][PHP_INT_MAX][] = array(
	'function' => static function () { ++$GLOBALS['forms_qa_http']; return new WP_Error( 'forms_qa_http_blocked', 'QA blocks external HTTP.' ); },
	'accepted_args' => 3,
);
$GLOBALS['wp_filter']['pre_wp_mail'][PHP_INT_MAX][] = array(
	'function' => static function () { ++$GLOBALS['forms_qa_mail']; return true; },
	'accepted_args' => 2,
);
$_SERVER['HTTP_HOST'] = 'bhranti-forms.invalid';
$_SERVER['REQUEST_URI'] = '/forms-test/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Bhranti isolated native forms QA';
define( 'REST_REQUEST', true );
require $forms_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( ! class_exists( 'Bhranti_Forms' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-bhranti-forms.php';
}
foreach ( array( 'Bhranti_Forms' => 'class-bhranti-forms.php', 'Bhranti_Actions' => 'class-bhranti-actions.php', 'Bhranti_Content_Quality' => 'class-bhranti-content-quality.php' ) as $class => $file ) {
	if ( ! class_exists( $class ) || hash_file( 'sha256', ( new ReflectionClass( $class ) )->getFileName() ) !== hash_file( 'sha256', dirname( __DIR__ ) . '/includes/' . $file ) ) {
		throw new RuntimeException( 'Copy the current staged plugin into the isolated QA runtime before testing.' );
	}
}
if ( realpath( ABSPATH ) !== $forms_root || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
	throw new RuntimeException( 'Unexpected isolated runtime.' );
}
if ( ! $GLOBALS['forms_qa_cf7'] ) {
	do_action( 'wp_enqueue_scripts' );
	if ( class_exists( 'WPCF7_ContactForm', false ) || wp_style_is( 'bhranti-forms', 'registered' ) || wp_style_is( 'bhranti-forms', 'enqueued' ) || $GLOBALS['forms_qa_http'] || $GLOBALS['forms_qa_mail'] ) {
		throw new RuntimeException( 'Absent native plugin must not register or enqueue form CSS.' );
	}
	echo "PASS absent native form dependency: no stylesheet, HTTP or mail.\n";
	exit;
}
if ( '6.2.2' !== WPCF7_VERSION || '2.6.4' !== FLAMINGO_VERSION ) {
	throw new RuntimeException( 'Unexpected isolated runtime or official form plugin versions.' );
}

/** Fail without echoing full native payloads. */
function forms_check( $pass, $label ) {
	if ( ! $pass ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$GLOBALS['forms_checks'];
}

/** Invoke the real adapter through its normal public operation. */
function forms_request( $method, $path, $params = array() ) {
	return Bhranti_Forms::execute( array( 'tool' => 'form_request', 'args' => array( 'method' => $method, 'path' => $path, 'params' => $params ) ), $GLOBALS['forms_user'], $GLOBALS['forms_session'] );
}

/** Resolve a server-stored proposal through the actual owned confirmation route. */
function forms_decide( $proposal, $approved = true, $expires = null ) {
	forms_check( is_array( $proposal ) && ! empty( $proposal['confirmation_required'] ), 'form write offers an exact approval proposal' );
	$run = array(
		'id'            => wp_generate_uuid4(),
		'status'        => 'awaiting_confirmation',
		'phase'         => '',
		'step'          => 1,
		'requester_id'  => $GLOBALS['forms_user'],
		'actor_id'      => (int) $GLOBALS['forms_user'],
		'context'       => array(),
		'history'       => array(),
		'receipts'      => array(),
		'configuration' => array( 'max_steps' => 12 ),
		'confirmation'  => $proposal + array( 'id' => wp_generate_uuid4(), 'expires' => $expires ?? time() + 600 ),
	);
	update_post_meta( $GLOBALS['forms_session'], 'bhranti_run', wp_slash( $run ) );
	$request = new WP_REST_Request( 'POST', '/bhranti/v1/sessions/' . $GLOBALS['forms_session'] . '/confirm' );
	$request->set_body_params( array( 'confirmation_id' => $run['confirmation']['id'], 'approved' => $approved ) );
	$response = rest_do_request( $request );
	if ( 200 !== $response->get_status() ) {
		$data = $response->get_data();
		return new WP_Error( $data['code'] ?? 'forms_confirmation_failed', $data['message'] ?? 'Confirmation failed.' );
	}
	if ( ! $approved || ( $expires ?? PHP_INT_MAX ) <= time() ) {
		return $response->get_data();
	}
	$saved = get_post_meta( $GLOBALS['forms_session'], 'bhranti_run', true );
	$last  = end( $saved['history'] );
	$prefix = 'WordPress tool result (untrusted site data): ';
	if ( ! is_array( $last ) || 0 !== strpos( $last['content'] ?? '', $prefix ) ) {
		$data = $response->get_data();
		return 'cancelled' === ( $data['run']['status'] ?? '' ) ? $data : new WP_Error( 'forms_confirmation_result', 'The confirmation produced no tool result.' );
	}
	$data = json_decode( substr( $last['content'], strlen( $prefix ) ), true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'forms_confirmation_result', 'The confirmation result is invalid.' );
	}
	return $data['success'] ? $data['result'] : new WP_Error( $data['code'], $data['message'] );
}

/** A form creation or notification change needs a real approval decision. */
function forms_approved_request( $method, $path, $params ) {
	return forms_decide( forms_request( $method, $path, $params ) );
}

/** Observe every native lookup and save across a complete public operation. */
function forms_actor_operation( $operation, $label ) {
	$observed = array();
	$watch = static function () use ( &$observed ) { $observed[] = get_current_user_id(); };
	$human = get_current_user_id();
	$actor = (int) $GLOBALS['forms_user'];
	add_action( 'wpcf7_contact_form', $watch );
	add_action( 'wpcf7_after_save', $watch );
	try {
		$result = $operation();
	} finally {
		remove_action( 'wpcf7_contact_form', $watch );
		remove_action( 'wpcf7_after_save', $watch );
	}
	forms_check( count( $observed ) >= 3 && array( $actor ) === array_values( array_unique( $observed ) ), $label . ': every native lookup/save uses the requesting human' );
	forms_check( get_current_user_id() === $human, $label . ': restores the human after native work' );
	return $result;
}

// Each native submission needs a fresh PHP request because CF7 owns a singleton.
if ( isset( $argv[1] ) && in_array( $argv[1], array( 'invalid', 'valid' ), true ) ) {
	$form_id = (int) ( $argv[2] ?? 0 );
	$form = WPCF7_ContactForm::get_instance( $form_id );
	if ( ! $form || 0 !== strpos( $form->title(), 'Bhranti forms QA ' ) ) { throw new RuntimeException( 'Unknown fixture.' ); }
	wp_set_current_user( 0 );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array( '_wpcf7_unit_tag' => 'wpcf7-f' . $form_id . '-o1', '_wpcf7' => $form_id, 'your-name' => 'Native QA Visitor', 'your-email' => 'qa@example.invalid', 'request-topic' => 'General question', 'your-message' => 'Synthetic fixture only' );
	if ( 'invalid' === $argv[1] ) { $_POST['your-email'] = 'not-an-email'; unset( $_POST['your-name'] ); }
	$request = new WP_REST_Request( 'POST', '/contact-form-7/v1/contact-forms/' . $form_id . '/feedback' );
	$request->set_header( 'Content-Type', 'multipart/form-data; boundary=bhranti-native-qa' );
	$request->set_body_params( $_POST );
	$response = rest_do_request( $request );
	$data = $response->get_data();
	echo wp_json_encode( array( 'status' => $data['status'] ?? '', 'invalid_count' => count( $data['invalid_fields'] ?? array() ), 'message' => $data['message'] ?? '', 'mail' => $GLOBALS['forms_qa_mail'], 'http' => $GLOBALS['forms_qa_http'] ) );
	exit;
}

$GLOBALS['forms_checks'] = 0;
$GLOBALS['forms_user'] = 0;
$GLOBALS['forms_session'] = 0;
$forms_baseline = get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
$forms_marker = 'Bhranti forms QA ' . wp_generate_uuid4();
$forms_term_baseline = get_terms( array( 'taxonomy' => Flamingo_Inbound_Message::channel_taxonomy, 'hide_empty' => false, 'fields' => 'ids' ) );
$forms_definition = array(
	'title' => $forms_marker,
	'fields' => array(
		array( 'type' => 'name', 'name' => 'your-name', 'label' => 'Your name', 'required' => true ),
		array( 'type' => 'email', 'name' => 'your-email', 'label' => 'Your email', 'required' => true ),
		array( 'type' => 'select', 'name' => 'request-topic', 'label' => 'Request topic', 'required' => true, 'options' => array( 'General question', 'Appointment request' ) ),
		array( 'type' => 'text', 'name' => 'your-message', 'label' => 'Anything else', 'required' => false ),
	),
	'confirmation' => 'Your request was received. Staff will confirm the appointment.',
	'notifications' => 'dashboard',
);

try {
	forms_check( 10 === has_filter( 'wpcf7_form_class_attr', array( 'Bhranti_Forms', 'form_class' ) ) && 20 === has_action( 'wp_enqueue_scripts', array( 'Bhranti_Forms', 'enqueue' ) ), 'main plugin registers native class and later frontend asset hooks' );
	do_action( 'wp_enqueue_scripts' );
	$style = wp_styles()->registered['bhranti-forms'] ?? null;
	forms_check( $style && wp_style_is( 'bhranti-forms', 'enqueued' ) && array( 'contact-form-7' ) === $style->deps && plugins_url( 'assets/forms.css', BHRANTI_FILE ) === $style->src && BHRANTI_VERSION === $style->ver, 'managed CSS is registered with native URL/version and follows CF7 base assets' );
	wp_dequeue_style( 'contact-form-7' );
	wp_dequeue_style( 'bhranti-forms' );
	wp_deregister_style( 'bhranti-forms' );
	Bhranti_Forms::enqueue();
	forms_check( array() === wp_styles()->registered['bhranti-forms']->deps && ! wp_style_is( 'contact-form-7', 'enqueued' ), 'sites disabling native base CSS are not forced to load it' );
	$absent_output = array(); $absent_exit = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' assets-absent', $absent_output, $absent_exit );
	forms_check( 0 === $absent_exit && 0 === strpos( implode( "\n", $absent_output ), 'PASS absent native form dependency' ), 'fresh native request without CF7 loads no Bhranti form stylesheet' );
	$GLOBALS['forms_user'] = wp_insert_user( array( 'user_login' => 'bhranti-forms-qa-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 40 ), 'user_email' => 'forms-qa@example.invalid', 'role' => 'administrator' ) );
	forms_check( ! is_wp_error( $GLOBALS['forms_user'] ), 'isolated human fixture' );
	wp_set_current_user( $GLOBALS['forms_user'] );
	$GLOBALS['forms_session'] = wp_insert_post( array( 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => $GLOBALS['forms_user'] ) );
	forms_check( $GLOBALS['forms_session'] > 0, 'owned private conversation fixture' );
	update_post_meta( $GLOBALS['forms_session'], 'bhranti_messages', array( array( 'role' => 'user', 'content' => 'Build a contact form. Do not use old@example.invalid for notifications.' ) ) );
	$discovery = forms_request( 'GET', '/contact-form-7/v1' );
	forms_check( ! is_wp_error( $discovery ) && $discovery['data']['available'] && $discovery['data']['dashboard_storage'], 'official native APIs and Flamingo hook discovered' );
	$actor_id = (int) $GLOBALS['forms_user'];
	forms_check( user_can( $actor_id, 'unfiltered_html' ) && Bhranti_Identity::scope( static function () { return ! current_user_can( 'unfiltered_html' ); } ), 'normal human authoring bypass is denied only inside the bounded tool scope' );
	forms_check( false === get_option( 'bhranti_actor_id', false ) && ! get_role( 'bhranti_agent' ), 'forms discovery does not provision service identity or role' );
	// The public action entry point must veto new defects before native storage.
	$quality_pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	foreach ( array( '<img src="/qa.jpg">', '<h2 id="qa-repeat">Heading</h2><p id="qa-repeat">Text</p>', '<p style="color:#fff;background-color:#fff">Invisible text</p>' ) as $content ) {
		$result = Bhranti_Actions::execute( array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => '/wp/v2/pages', 'params' => array( 'title' => $forms_marker, 'status' => 'draft', 'content' => $content ) ) ), $GLOBALS['forms_user'], $GLOBALS['forms_session'] );
		forms_check( is_wp_error( $result ) && 'bhranti_content_quality' === $result->get_error_code(), 'Actions rejects new authored-content defect before saving' );
		forms_check( $quality_pages === get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ), 'rejected content creates no page' );
	}
	$good_content = '<!-- wp:paragraph --><p>Useful readable information.</p><!-- /wp:paragraph -->';
	$result = Bhranti_Actions::execute( array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => '/wp/v2/pages', 'params' => array( 'title' => $forms_marker, 'status' => 'draft', 'content' => $good_content ) ) ), $GLOBALS['forms_user'], $GLOBALS['forms_session'] );
	forms_check( ! is_wp_error( $result ) && get_post( $result['data']['id'] )->post_content === $good_content, 'valid native core content succeeds through Actions gate' );
	$quality_page_id = $result['data']['id'];
	$legacy = '<img src="/legacy-qa.jpg">';
	wp_update_post( array( 'ID' => $quality_page_id, 'post_content' => $legacy ) );
	$result = Bhranti_Actions::execute( array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => '/wp/v2/pages/' . $quality_page_id, 'params' => array( 'content' => $legacy . $good_content ) ) ), $GLOBALS['forms_user'], $GLOBALS['forms_session'] );
	forms_check( ! is_wp_error( $result ) && get_post( $quality_page_id )->post_content === $legacy . $good_content, 'unchanged legacy defect does not block unrelated native edit' );

	$invalid = array(
		array_replace( $forms_definition, array( 'additional_settings' => 'demo_mode:on' ) ),
		array_replace( $forms_definition, array( 'confirmation' => '<script>alert(1)</script>' ) ),
		array_replace( $forms_definition, array( 'confirmation' => 'Message [_site_admin_email]' ) ),
		array_replace( $forms_definition, array( 'recipient' => 'unexpected@example.invalid' ) ),
		array_replace( $forms_definition, array( 'notification_evidence' => 'form' ) ),
		array_replace( $forms_definition, array( 'fields' => array( array( 'type' => 'file', 'name' => 'upload', 'label' => 'Upload', 'required' => true ) ) ) ),
		array_replace( $forms_definition, array( 'fields' => array( array( 'type' => 'text', 'name' => 'your-name] [file x', 'label' => 'Name', 'required' => true ) ) ) ),
		array_replace( $forms_definition, array( 'fields' => array( array( 'type' => 'select', 'name' => 'choice', 'label' => 'Choice', 'required' => true, 'options' => array( 'Shown|sent elsewhere' ) ) ) ) ),
		array_replace( $forms_definition, array( 'fields' => array_fill( 0, 13, $forms_definition['fields'][0] ) ) ),
	);
	foreach ( $invalid as $definition ) {
		$result = forms_request( 'POST', '/contact-form-7/v1/contact-forms', $definition );
		forms_check( is_wp_error( $result ) && 'bhranti_forms_invalid' === $result->get_error_code(), 'reject unreviewed form definition' );
	}
	foreach ( array( '/contact-form-7/v1/contact-forms/1/feedback', '/contact-form-7/v1/contact-forms?search=x', '/contact-form-7/v1/contact-forms/01', '/wp/v2/users' ) as $path ) {
		forms_check( is_wp_error( forms_request( 'POST', $path, $forms_definition ) ), 'reject unreviewed route' );
	}
	forms_check( is_wp_error( forms_request( 'DELETE', '/contact-form-7/v1/contact-forms/1' ) ), 'no deletion endpoint' );
	$GLOBALS['forms_qa_flamingo'] = false;
	$result = forms_request( 'POST', '/contact-form-7/v1/contact-forms', $forms_definition );
	forms_check( is_wp_error( $result ) && 'bhranti_forms_storage_missing' === $result->get_error_code(), 'dashboard mode blocked without active Flamingo' );
	$GLOBALS['forms_qa_flamingo'] = true;
	$before_forms = get_posts( array( 'post_type' => 'wpcf7_contact_form', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	$proposal = forms_request( 'POST', '/contact-form-7/v1/contact-forms', $forms_definition );
	forms_check( ! is_wp_error( $proposal ) && ! empty( $proposal['confirmation_required'] ) && false !== strpos( $proposal['summary'], 'dashboard-only' ) && $before_forms === get_posts( array( 'post_type' => 'wpcf7_contact_form', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ), 'dashboard creation pauses without a native form write' );
	$unapproved = Bhranti_Forms::execute( $proposal['command'], $GLOBALS['forms_user'], $GLOBALS['forms_session'], true, $proposal['fingerprint'] );
	forms_check( is_wp_error( $unapproved ) && 'bhranti_forms_confirmation' === $unapproved->get_error_code(), 'a caller cannot forge form approval' );
	$cancelled = forms_decide( $proposal, false );
	forms_check( is_array( $cancelled ) && 'cancelled' === $cancelled['run']['status'] && $before_forms === get_posts( array( 'post_type' => 'wpcf7_contact_form', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ), 'denying the exact card leaves forms unchanged' );
	$proposal = forms_request( 'POST', '/contact-form-7/v1/contact-forms', $forms_definition );
	$expired = forms_decide( $proposal, true, time() - 1 );
	forms_check( is_array( $expired ) && 'cancelled' === $expired['run']['status'] && $before_forms === get_posts( array( 'post_type' => 'wpcf7_contact_form', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ), 'expired card cannot create a form' );
	$email_definition = array_replace( $forms_definition, array( 'notifications' => 'email', 'recipient' => 'old@example.invalid' ) );
	$proposal = forms_request( 'POST', '/contact-form-7/v1/contact-forms', $email_definition );
	forms_check( ! is_wp_error( $proposal ) && ! empty( $proposal['confirmation_required'] ) && false !== strpos( $proposal['summary'], 'old@example.invalid' ) && $before_forms === get_posts( array( 'post_type' => 'wpcf7_contact_form', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ), 'an address mentioned only in a warning still requires exact approval' );

	$deny_actor = static function ( $caps, $requested, $args, $user ) use ( $actor_id ) { if ( $user->ID === $actor_id ) { $caps['publish_pages'] = false; } return $caps; };
	add_filter( 'user_has_cap', $deny_actor, PHP_INT_MAX, 4 );
	$result = forms_request( 'POST', '/contact-form-7/v1/contact-forms', $forms_definition );
	forms_check( is_wp_error( $result ) && 'bhranti_forms_permission' === $result->get_error_code(), 'requesting human native capability required' );
	remove_filter( 'user_has_cap', $deny_actor, PHP_INT_MAX );
	$policy = get_option( 'bhranti_settings', false );
	// Use the actual settings storage envelope, then restore its exact original value.
	$settings = Bhranti_Settings::get();
	$settings_option = $settings;
	$settings_option['revision'] = wp_generate_uuid4();
	$settings_option['config']['permissions']['edit_content'] = false;
	update_option( 'bhranti_settings', $settings_option );
	$result = forms_request( 'POST', '/contact-form-7/v1/contact-forms', $forms_definition );
	forms_check( is_wp_error( $result ) && 'bhranti_action_disabled' === $result->get_error_code(), 'current action switch stops writes' );
	false === $policy ? delete_option( 'bhranti_settings' ) : update_option( 'bhranti_settings', $policy );

	$fail_snapshot = static function ( $check, $id, $key ) { return 'bhranti_actions' === $key ? false : $check; };
	add_filter( 'update_post_metadata', $fail_snapshot, PHP_INT_MAX, 3 );
	$result = forms_approved_request( 'POST', '/contact-form-7/v1/contact-forms', $forms_definition );
	forms_check( is_wp_error( $result ), 'durable snapshot required before native save' );
	remove_filter( 'update_post_metadata', $fail_snapshot, PHP_INT_MAX );
	$proposal = forms_request( 'POST', '/contact-form-7/v1/contact-forms', $forms_definition );
	$fail_audit = static function ( $empty, $post ) { return 'bhranti_event' === $post['post_type'] ? true : $empty; };
	add_filter( 'wp_insert_post_empty_content', $fail_audit, PHP_INT_MAX, 2 );
	$result = forms_decide( $proposal );
	forms_check( is_wp_error( $result ), 'durable audit required before native save' );
	remove_filter( 'wp_insert_post_empty_content', $fail_audit, PHP_INT_MAX );

	$created = forms_actor_operation( static function () use ( $forms_definition ) { return forms_approved_request( 'POST', '/contact-form-7/v1/contact-forms', $forms_definition ); }, 'Create' );
	forms_check( ! is_wp_error( $created ), 'native CF7 creation: ' . ( is_wp_error( $created ) ? $created->get_error_code() : 'saved' ) );
	$form_id = $created['data']['id'];
	forms_check( ! $created['rollback_available'] && (int) get_post( $form_id )->post_author === $actor_id, 'truthful creation Undo and native requesting-human attribution' );
	forms_check( get_current_user_id() === $GLOBALS['forms_user'], 'human identity restored after native save' );
	$native = WPCF7_ContactForm::get_instance( $form_id );
	forms_check( $created['data']['shortcode'] === $native->shortcode(), 'returns the native CF7 hash shortcode' );
	$read_actor = 0;
	$watch_read = static function () use ( &$read_actor ) { $read_actor = get_current_user_id(); };
	add_action( 'wpcf7_contact_form', $watch_read );
	forms_request( 'GET', '/contact-form-7/v1/contact-forms/' . $form_id );
	remove_action( 'wpcf7_contact_form', $watch_read );
	forms_check( $read_actor === $actor_id && get_current_user_id() === $GLOBALS['forms_user'], 'native form reads keep the requesting human' );
	forms_check( $native->is_true( 'skip_mail' ) && ! $native->prop( 'mail_2' )['active'], 'native dashboard mode and no autoresponder' );
	$config = new WPCF7_ConfigValidator( $native );
	$config->validate();
	forms_check( ! $config->collect_error_messages(), 'native configuration validator accepts generated definition' );
	$render_meta = get_post_meta( $form_id );
	$render_content = get_post( $form_id )->post_content;
	$html = do_shortcode( $created['data']['shortcode'] );
	forms_check( false !== strpos( $html, '<form ' ) && false !== strpos( $html, 'name="your-name"' ) && false !== strpos( $html, 'type="email"' ) && false !== strpos( $html, '<select ' ) && false !== strpos( $html, 'type="submit"' ), 'native shortcode renders actual fields and submit control' );
	forms_check( false !== strpos( $html, 'aria-required="true"' ), 'native required-field accessibility' );
	$rendered_form = new WP_HTML_Tag_Processor( $html );
	forms_check( $rendered_form->next_tag( 'FORM' ) && $rendered_form->has_class( 'bhranti-managed-form' ) && $rendered_form->has_class( 'wpcf7-form' ), 'native managed form receives only the additive presentation class' );
	$custom_html = do_shortcode( '[contact-form-7 id="' . $form_id . '" html_class="bhranti-managed-form qa-existing"]' );
	$custom_form = new WP_HTML_Tag_Processor( $custom_html );
	$custom_form->next_tag( 'FORM' );
	forms_check( $custom_form->has_class( 'qa-existing' ) && 1 === substr_count( $custom_form->get_attribute( 'class' ), 'bhranti-managed-form' ), 'native custom classes are preserved without duplicating the managed class' );
	forms_check( $render_meta === get_post_meta( $form_id ) && $render_content === get_post( $form_id )->post_content, 'rendering presentation leaves native settings, fingerprints and saved content unchanged' );
	$ordinary = WPCF7_ContactForm::get_template();
	$ordinary->set_title( $forms_marker . ' ordinary native form' );
	$ordinary->save();
	$ordinary_markup = do_shortcode( $ordinary->shortcode() );
	$ordinary_form = new WP_HTML_Tag_Processor( $ordinary_markup );
	forms_check( $ordinary_form->next_tag( 'FORM' ) && ! $ordinary_form->has_class( 'bhranti-managed-form' ) && $ordinary_form->has_class( 'wpcf7-form' ), 'unmanaged native forms retain their original classes' );
	update_post_meta( $ordinary->id(), '_bhranti_form_definition', array( 'requester_id' => 0 ) );
	$ordinary_form = new WP_HTML_Tag_Processor( do_shortcode( $ordinary->shortcode() ) );
	forms_check( $ordinary_form->next_tag( 'FORM' ) && ! $ordinary_form->has_class( 'bhranti-managed-form' ), 'a marker without an owning human never enables managed presentation' );

	$updated_definition = $forms_definition;
	$updated_definition['confirmation'] = 'Your request is waiting for staff confirmation.';
	$updated = forms_actor_operation( static function () use ( $form_id, $updated_definition ) { return forms_request( 'POST', '/contact-form-7/v1/contact-forms/' . $form_id, $updated_definition ); }, 'Update' );
	forms_check( ! is_wp_error( $updated ) && $updated['rollback_available'], 'same-destination managed update stays autonomous and offers Undo' );
	$undo = forms_actor_operation( static function () use ( $updated ) { return Bhranti_Forms::rollback( $updated['action_id'], $GLOBALS['forms_user'], $GLOBALS['forms_session'] ); }, 'Undo' );
	forms_check( ! is_wp_error( $undo ) && WPCF7_ContactForm::get_instance( $form_id )->message( 'mail_sent_ok', false ) === $forms_definition['confirmation'], 'native update Undo restores confirmation' );
	forms_check( is_wp_error( Bhranti_Forms::rollback( $updated['action_id'], $GLOBALS['forms_user'], $GLOBALS['forms_session'] ) ), 'Undo cannot replay' );
	$email_update = array_replace( $forms_definition, array( 'notifications' => 'email', 'recipient' => 'old@example.invalid' ) );
	$proposal = forms_request( 'POST', '/contact-form-7/v1/contact-forms/' . $form_id, $email_update );
	forms_check( ! is_wp_error( $proposal ) && ! empty( $proposal['confirmation_required'] ) && WPCF7_ContactForm::get_instance( $form_id )->is_true( 'skip_mail' ), 'dashboard-to-email change waits for approval' );
	$prior_marker  = get_post_meta( $form_id, '_bhranti_form_definition', true );
	$edited_marker = $prior_marker;
	$edited_marker['qa_state_change'] = true;
	update_post_meta( $form_id, '_bhranti_form_definition', $edited_marker );
	$stale = forms_decide( $proposal );
	forms_check( is_array( $stale ) && 'cancelled' === $stale['run']['status'] && WPCF7_ContactForm::get_instance( $form_id )->is_true( 'skip_mail' ), 'changed native state invalidates the pending destination card before dispatch' );
	update_post_meta( $form_id, '_bhranti_form_definition', $prior_marker );
	$proposal = forms_request( 'POST', '/contact-form-7/v1/contact-forms/' . $form_id, $email_update );
	$email_saved = forms_decide( $proposal );
	$native_email = WPCF7_ContactForm::get_instance( $form_id );
	forms_check( ! is_wp_error( $email_saved ) && 'old@example.invalid' === $native_email->prop( 'mail' )['recipient'] && ! $native_email->is_true( 'skip_mail' ), 'approved email route is saved natively' );
	$decisions = get_post_meta( $GLOBALS['forms_session'], 'bhranti_events', true );
	$decision  = null;
	foreach ( array_reverse( $decisions ) as $candidate ) {
		if ( 'confirmation' === ( $candidate['type'] ?? '' ) && 'approved' === ( $candidate['status'] ?? '' ) && (int) ( $candidate['target']['id'] ?? 0 ) === (int) $form_id ) {
			$decision = $candidate;
			break;
		}
	}
	forms_check( is_array( $decision ) && 'wpcf7_contact_form' === ( $decision['target']['type'] ?? '' ) && false === strpos( wp_json_encode( $decision ), 'old@example.invalid' ), 'approval decision audit omits the recipient shown on its private card' );
	$logs = get_posts( array( 'post_type' => 'bhranti_event', 'post_status' => 'private', 'post_parent' => $GLOBALS['forms_session'], 'posts_per_page' => -1, 'fields' => 'ids' ) );
	$private_log_found = false;
	foreach ( $logs as $log_id ) {
		$event = get_post_meta( $log_id, 'bhranti_event_data', true );
		if ( 'confirmation_approved' === ( $event['type'] ?? '' ) && (int) ( $event['details']['target_id'] ?? 0 ) === (int) $form_id ) {
			$private_log_found = true;
			forms_check( false === strpos( wp_json_encode( $event ), 'old@example.invalid' ), 'site activity log omits approved recipient' );
		}
	}
	forms_check( $private_log_found, 'form approval has a durable activity record' );
	forms_check( ! $email_saved['rollback_available'], 'notification-changing update does not offer Undo' );
	$email_undo = Bhranti_Forms::rollback( $email_saved['action_id'], $GLOBALS['forms_user'], $GLOBALS['forms_session'] );
	forms_check( is_wp_error( $email_undo ) && 'bhranti_forms_rollback_destination' === $email_undo->get_error_code() && 'old@example.invalid' === WPCF7_ContactForm::get_instance( $form_id )->prop( 'mail' )['recipient'], 'direct Undo cannot silently restore a different recipient' );
	$no_op_proposal = Bhranti_Forms::execute( $proposal['command'], $GLOBALS['forms_user'], $GLOBALS['forms_session'], false, $proposal['fingerprint'] );
	forms_check( is_wp_error( $no_op_proposal ) && 'bhranti_rollback_conflict' === $no_op_proposal->get_error_code(), 'stale card revalidation cannot become an autonomous same-destination edit' );
	$recipient_update = array_replace( $email_update, array( 'recipient' => 'new@example.invalid' ) );
	$proposal = forms_request( 'POST', '/contact-form-7/v1/contact-forms/' . $form_id, $recipient_update );
	forms_check( ! is_wp_error( $proposal ) && ! empty( $proposal['confirmation_required'] ) && false !== strpos( $proposal['summary'], 'new@example.invalid' ) && 'old@example.invalid' === WPCF7_ContactForm::get_instance( $form_id )->prop( 'mail' )['recipient'], 'same-mode recipient change awaits exact approval' );
	$recipient_saved = forms_decide( $proposal );
	forms_check( ! is_wp_error( $recipient_saved ) && ! $recipient_saved['rollback_available'] && 'new@example.invalid' === WPCF7_ContactForm::get_instance( $form_id )->prop( 'mail' )['recipient'], 'approved recipient change updates native mail without offering Undo' );
	$recipient_undo = Bhranti_Forms::rollback( $recipient_saved['action_id'], $GLOBALS['forms_user'], $GLOBALS['forms_session'] );
	forms_check( is_wp_error( $recipient_undo ) && 'bhranti_forms_rollback_destination' === $recipient_undo->get_error_code() && 'new@example.invalid' === WPCF7_ContactForm::get_instance( $form_id )->prop( 'mail' )['recipient'], 'direct Undo cannot silently restore the old recipient' );
	$proposal = forms_request( 'POST', '/contact-form-7/v1/contact-forms/' . $form_id, $forms_definition );
	forms_check( ! is_wp_error( $proposal ) && ! empty( $proposal['confirmation_required'] ) && false !== strpos( $proposal['summary'], 'dashboard-only' ), 'email-to-dashboard change waits for approval' );
	$dashboard_saved = forms_decide( $proposal );
	forms_check( ! is_wp_error( $dashboard_saved ) && WPCF7_ContactForm::get_instance( $form_id )->is_true( 'skip_mail' ), 'approved dashboard route restores native mail suppression' );
	forms_check( ! $dashboard_saved['rollback_available'], 'dashboard routing change does not offer Undo' );
	$dashboard_undo = Bhranti_Forms::rollback( $dashboard_saved['action_id'], $GLOBALS['forms_user'], $GLOBALS['forms_session'] );
	forms_check( is_wp_error( $dashboard_undo ) && 'bhranti_forms_rollback_destination' === $dashboard_undo->get_error_code() && WPCF7_ContactForm::get_instance( $form_id )->is_true( 'skip_mail' ), 'direct Undo cannot silently re-enable email' );
	$changed = forms_request( 'POST', '/contact-form-7/v1/contact-forms/' . $form_id, $updated_definition );
	$session_author = get_post( $GLOBALS['forms_session'] )->post_author;
	wp_update_post( array( 'ID' => $GLOBALS['forms_session'], 'post_author' => 1 ) );
	forms_check( is_wp_error( Bhranti_Forms::rollback( $changed['action_id'], $GLOBALS['forms_user'], $GLOBALS['forms_session'] ) ), 'foreign conversation cannot read or restore private snapshots' );
	wp_update_post( array( 'ID' => $GLOBALS['forms_session'], 'post_author' => $session_author ) );
	$native = WPCF7_ContactForm::get_instance( $form_id );
	$prior_title = $native->title();
	$native->set_title( $prior_title . ' manual edit' );
	$native->save();
	forms_check( is_wp_error( Bhranti_Forms::rollback( $changed['action_id'], $GLOBALS['forms_user'], $GLOBALS['forms_session'] ) ), 'Undo preserves later native edits' );
	forms_check( is_wp_error( forms_request( 'POST', '/contact-form-7/v1/contact-forms/' . $form_id, $updated_definition ) ), 'managed updates preserve later native edits' );
	$native->set_title( $prior_title );
	$native->save();
	$read = forms_request( 'GET', '/contact-form-7/v1/contact-forms/' . $form_id );
	forms_check( ! is_wp_error( $read ) && ! isset( $read['data']['mail'], $read['data']['properties'], $read['data']['entries'] ), 'reads omit raw mail templates and submissions' );
	forms_check( is_wp_error( forms_request( 'GET', '/contact-form-7/v1/contact-forms', array( 'per_page' => 21 ) ) ), 'bounded native collection' );
	$override_shortcode = static function () { return '[arbitrary-shortcode]'; };
	add_filter( 'wpcf7_contact_form_shortcode', $override_shortcode );
	$filtered = forms_request( 'GET', '/contact-form-7/v1/contact-forms/' . $form_id );
	remove_filter( 'wpcf7_contact_form_shortcode', $override_shortcode );
	forms_check( ! is_wp_error( $filtered ) && '[contact-form-7 id="' . $form_id . '"]' === $filtered['data']['shortcode'], 'a native shortcode filter cannot introduce another tag or target' );
	$throw_lookup = static function () { throw new RuntimeException( 'Synthetic native lookup interruption.' ); };
	add_action( 'wpcf7_contact_form', $throw_lookup );
	$interrupted = forms_request( 'POST', '/contact-form-7/v1/contact-forms/' . $form_id, $updated_definition );
	remove_action( 'wpcf7_contact_form', $throw_lookup );
	forms_check( is_wp_error( $interrupted ) && get_current_user_id() === $GLOBALS['forms_user'], 'native preflight exception always restores human identity' );

	foreach ( array( 'invalid', 'valid' ) as $case ) {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $case ) . ' ' . (int) $form_id;
		$output = array(); $exit = 0;
		exec( $command, $output, $exit );
		$data = json_decode( implode( "\n", $output ), true );
		forms_check( 0 === $exit && is_array( $data ), 'fresh native submission request' );
		forms_check( 0 === $data['mail'] && 0 === $data['http'], 'dashboard submission needs no email or external request' );
		if ( 'invalid' === $case ) {
			forms_check( 'validation_failed' === $data['status'] && $data['invalid_count'] >= 2, 'native required and email validation: ' . $data['status'] . '/' . $data['invalid_count'] );
		} else {
			forms_check( 'mail_sent' === $data['status'] && $data['message'] === $updated_definition['confirmation'], 'native successful response preserves staff-confirmation meaning' );
		}
	}
	$entries = get_posts( array( 'post_type' => Flamingo_Inbound_Message::post_type, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	forms_check( 1 === count( $entries ), 'only valid native submission is stored locally by Flamingo' );
	$entry = new Flamingo_Inbound_Message( $entries[0] );
	forms_check( $entry && 'Native QA Visitor' === $entry->fields['your-name'] && 'qa@example.invalid' === $entry->fields['your-email'], 'native local entry retains submitted fixture fields' );
	forms_check( 0 === $GLOBALS['forms_qa_mail'] && 0 === $GLOBALS['forms_qa_http'], 'no outbound requests or email across adapter operations' );
} finally {
	if ( $GLOBALS['forms_user'] ) { wp_set_current_user( $GLOBALS['forms_user'] ); }
	$posts = get_posts( array( 'post_type' => array( 'page', 'bhranti_session', 'bhranti_event', 'wpcf7_contact_form', 'flamingo_inbound', 'flamingo_contact' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	foreach ( array_diff( $posts, $forms_baseline ) as $id ) { wp_delete_post( $id, true ); }
	$terms = get_terms( array( 'taxonomy' => Flamingo_Inbound_Message::channel_taxonomy, 'hide_empty' => false, 'fields' => 'ids' ) );
	foreach ( array_diff( $terms, $forms_term_baseline ) as $id ) { wp_delete_term( $id, Flamingo_Inbound_Message::channel_taxonomy ); }
	if ( $GLOBALS['forms_user'] && ! is_wp_error( $GLOBALS['forms_user'] ) ) { wp_delete_user( $GLOBALS['forms_user'] ); }
}
echo 'PASS: ' . $GLOBALS['forms_checks'] . " native form checks; isolated QA only, no outbound mail, HTTP or AI.\n";
