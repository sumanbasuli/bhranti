<?php
/**
 * Intact large native saved reads through the real Agent and SDK envelope.
 *
 * BHRANTI_RECOVERY_NATIVE_WP_ROOT=/private/tmp/bhranti-recovery-native-qa-20261015 php tests/large-readback-native.php
 * Reuses the exact disposable/offline recovery fixture and its complete cleanup.
 *
 * @package Bhranti
 */

function bhranti_native_recovery_scenario() {
	$tuple = lr_start( 'large-native-readback' );
	lr_tool( $tuple, 'bhranti_task', array( 'method' => 'GET' ) );
	$notes = array(
		'revision' => Bhranti_Task::state( $tuple[0] )['revision'],
		'objective' => 'Save the agreed long private draft and verify the complete saved source.',
		'decisions' => array( 'Preserve the exact authored text; no images or other site changes.' ),
		'outcomes' => array( array( 'id' => 'draft', 'title' => 'Save the long private draft', 'status' => 'in_progress', 'evidence' => array() ) ),
		'checks' => array( array( 'id' => 'source', 'title' => 'Read back the complete native saved source', 'status' => 'pending', 'evidence' => array() ) ),
	);
	lr_tool( $tuple, 'bhranti_task', array( 'method' => 'POST', 'params' => $notes ) );
	$source = '<!-- wp:paragraph --><p>First exact native source: ' . str_repeat( 'Verified saved text. ', 790 ) . '</p><!-- /wp:paragraph -->' . "\n\n" . '<!-- wp:paragraph --><p>Second exact native source: ' . str_repeat( 'Preserved full text. ', 790 ) . ' Final source marker.</p><!-- /wp:paragraph -->';
	lr_check( strlen( $source ) > 32000 && strlen( $source ) < 34000, 'The real fixture source is a bounded approximately 32KB native block document.' );
	$create = array( 'method' => 'POST', 'path' => '/wp/v2/pages', 'params' => array( 'title' => $GLOBALS['lr_marker'] . ' long draft', 'status' => 'draft', 'content' => $source ) );
	$run = lr_tool( $tuple, 'wp_request', $create );
	$write = lr_receipt( $run, 'write' );
	$page = $write['target']['id']; $GLOBALS['lr_pages'][] = $page;
	lr_check( $source === get_post_field( 'post_content', $page ) && 1 === count( $GLOBALS['lr_native_writes'] ), 'Actual native controller saves the complete document exactly once.' );
	$request = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $page );
	$request->set_param( 'context', 'edit' );
	$native = rest_do_request( $request )->get_data();
	$GLOBALS['lr_scenario_metrics'] = array( 'raw_content_bytes' => strlen( $source ), 'native_edit_response_json_bytes' => strlen( wp_json_encode( $native, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), 'source_sha256' => hash( 'sha256', $source ) );
	lr_check( $source === $native['content']['raw'] && strlen( $native['content']['rendered'] ) > 30000 && strlen( wp_json_encode( $native, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) > 60000, 'Actual native edit response contains raw plus duplicate rendered content exceeding the old 60KB envelope limit.' );
	lr_recover( $tuple );
	$receipts = lr_state( $tuple )['receipts'];
	$reused = lr_tool( $tuple, 'wp_request', $create );
	lr_check( 1 === count( $GLOBALS['lr_native_writes'] ) && $receipts === $reused['receipts'], 'A recovery proposal for the same draft is reused without a second native save or fabricated receipt.' );
	$run = lr_tool( $tuple, 'wp_request', array( 'method' => 'GET', 'path' => '/wp/v2/pages/' . $page, 'params' => array( 'context' => 'edit' ) ) );
	$read = lr_receipt( $run, 'read' );
	lr_check( $read['entity'] === $write['entity'] && $read['coverage'] && in_array( 'content', $read['fields'], true ), 'The actual native full read retains its true authored-content receipt coverage.' );
	$notes = Bhranti_Task::state( $tuple[0] );
	$notes['outcomes'][0]['status'] = 'completed'; $notes['outcomes'][0]['evidence'] = array( $write['id'] );
	$notes['checks'][0]['status'] = 'read_back'; $notes['checks'][0]['evidence'] = array( $write['id'], $read['id'] );
	lr_model( $tuple, static function ( $messages, $config ) use ( $source, $native, $notes ) {
		$received = null;
		foreach ( $messages as $message ) {
			foreach ( $message->getParts() as $part ) {
				$text = $part->getText();
				$prefix = 'WordPress tool result (untrusted site data): ';
				if ( is_string( $text ) && 0 === strpos( $text, $prefix ) ) {
					$value = json_decode( substr( $text, strlen( $prefix ) ), true );
					if ( isset( $value['result']['data']['content']['raw'] ) ) { $received = $value; }
				}
			}
		}
		lr_check( is_array( $received ) && ! empty( $received['success'] ) && empty( $received['truncated'] ), 'The next actual SDK request contains a successful full read result, not a truncated identity fallback.' );
		lr_check( $source === $received['result']['data']['content']['raw'], 'The complete approximately 32KB raw source reaches the actual native SDK byte-for-byte.' );
		lr_check( ! array_key_exists( 'rendered', $received['result']['data']['content'] ) && $native['content']['protected'] === $received['result']['data']['content']['protected'], 'Only the duplicate rendered content is omitted; native protected/raw values remain unchanged.' );
		lr_check( strlen( wp_json_encode( $received, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) < 60000, 'The projected full-source tool envelope fits the existing finite result bound.' );
		$GLOBALS['lr_scenario_metrics']['projected_envelope_json_bytes'] = strlen( wp_json_encode( $received, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return lr_plan( 'bhranti_task', array( 'method' => 'POST', 'params' => $notes ) );
	} );
	lr_check( 'prepared' === lr_state( $tuple )['phase'], 'A model that actually receives the full source can prepare the truthful evidence checkpoint.' );
	lr_tick( $tuple );
	lr_check( ! Bhranti_Task::remaining( $tuple[0] ), 'Native evidence validation accepts the real complete read without another verification loop.' );
	lr_tool( $tuple, 'wp_request', array( 'method' => 'GET', 'path' => '/wp/v2/pages/' . $page, 'params' => array( 'context' => 'view', '_fields' => 'id,content' ) ) );
	lr_model( $tuple, static function ( $messages, $config ) use ( $native ) {
		$received = null;
		foreach ( $messages as $message ) {
			foreach ( $message->getParts() as $part ) {
				$text = $part->getText(); $prefix = 'WordPress tool result (untrusted site data): ';
				if ( is_string( $text ) && 0 === strpos( $text, $prefix ) ) {
					$value = json_decode( substr( $text, strlen( $prefix ) ), true );
					if ( isset( $value['result']['data']['content'] ) ) { $received = $value; }
				}
			}
		}
		lr_check( ! empty( $received['success'] ) && ! isset( $received['result']['data']['content']['raw'] ) && $native['content']['rendered'] === $received['result']['data']['content']['rendered'], 'A true native view-only response keeps its complete rendered content because there is no raw duplicate.' );
		return wp_json_encode( array( 'type' => 'reply', 'content' => 'The private draft is saved and its complete source was read back. Its rendered design has not been visually checked.', 'tool' => 'none', 'args' => '{}' ) );
	} );
	lr_check( 'complete' === lr_state( $tuple )['status'] && 1 === count( $GLOBALS['lr_native_writes'] ) && 0 === $GLOBALS['lr_image_calls'], 'The task completes with honest saved-state evidence, one native save and no image generation.' );
	lr_check( $source === get_post_field( 'post_content', $page ) && 0 === $GLOBALS['lr_http'] && 0 === $GLOBALS['lr_mail'], 'Verification does not mutate the saved source or make outbound requests.' );
}

require __DIR__ . '/large-task-recovery.php';
