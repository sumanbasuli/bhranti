<?php
/**
 * Chosen-reference continuation through the real Agent's start/question/answer loop.
 * Native WordPress formatting and AI File/MessagePart DTOs, in-memory storage.
 * No wp-load.php, database, HTTP, filesystem images or provider requests.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/reference-continuation.php
 */
require __DIR__ . '/agent-clarification.php';
require dirname( __DIR__ ) . '/includes/class-bhranti-context.php';
function get_posts( $args ) { return array(); }

$rc_checks = 0;
function rc_check( $condition, $label ) {
    ++$GLOBALS['rc_checks'];
    if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
}
function rc_session( $id ) {
    $GLOBALS['ac']['posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => 7, 'post_title' => 'Reference fixture' );
    $GLOBALS['ac']['meta'][ $id ] = array();
}
function rc_png( $width = 2, $height = 3 ) {
    $image = imagecreatetruecolor( $width, $height );
    ob_start(); imagepng( $image ); $bytes = ob_get_clean(); unset( $image );
    return array( 'data' => base64_encode( $bytes ), 'mime_type' => 'image/png' );
}
function rc_start( $id, $content, $context = array() ) {
    return Bhranti_Agent::start( array( 'id' => $id, 'content' => $content, 'context' => $context ) );
}
function rc_step( $id, $run, $type = 'question' ) {
    $GLOBALS['ac']['reply'] = ac_envelope( $type, 'question' === $type ? 'Which audience and visitor action should the design serve?' : 'The requested task is complete.' );
    $calls = $GLOBALS['ac']['calls'];
    $data = Bhranti_Agent::step( array( 'id' => $id, 'run_id' => $run, 'defer_tool' => true ) );
    rc_check( ! is_wp_error( $data ) && $calls + 1 === $GLOBALS['ac']['calls'], 'One deterministic native-message request passes the actual Agent step.' );
    return $data;
}
function rc_files() {
    $files = array();
    foreach ( $GLOBALS['ac']['history'] as $message ) {
        foreach ( $message->getParts() as $part ) {
            if ( $part->getType()->isFile() ) { $files[] = array( $message, $part->getFile() ); }
        }
    }
    return $files;
}
function rc_capture( $original_text, $image ) {
    $files = rc_files();
    rc_check( 1 === count( $files ), 'The chosen image occurs exactly once per native request.' );
    list( $message, $file ) = $files[0];
    rc_check( $message->getRole()->isUser() && $original_text === $message->getParts()[0]->getText(), 'Native image remains bound to its original human text, never an answer or tool result.' );
    rc_check( $file->isInline() && ! $file->isRemote() && $image['data'] === $file->getBase64Data() && $image['mime_type'] === $file->getMimeType(), 'Actual native SDK File retains exact validated chosen bytes and MIME.' );
    rc_check( ! str_contains( $GLOBALS['ac']['instruction'], $image['data'] ) && ! str_contains( $GLOBALS['ac']['instruction'], 'message_id' ) && ! str_contains( $GLOBALS['ac']['instruction'], '"screenshot"' ), 'System/current-page instruction contains neither image bytes nor binding metadata.' );
}
function rc_waiting( $id, $image, $text = 'Use this supplied design reference for my project.' ) {
    rc_session( $id );
    $start = rc_start( $id, $text, array( 'screenshot' => $image ) );
    rc_check( ! is_wp_error( $start ), 'Chosen reference starts the owned conversation.' );
    rc_step( $id, $start['run']['id'] );
    return get_post_meta( $id, 'bhranti_run', true );
}

$image = rc_png(); $replacement = rc_png( 3, 2 ); $original = 'Use this supplied reference for our local handmade-products site.';
rc_session( 101 );
$start = rc_start( 101, $original, array( 'screenshot' => $image, 'screen' => 'post', 'post_id' => 20, 'title' => 'Current editor, not this attached design' ) );
$private = get_post_meta( 101, 'bhranti_run', true ); $original_id = $start['messages'][0]['id'];
rc_check( $original_id === $private['reference_image']['message_id'] && $image['data'] === $private['reference_image']['image']['data'], 'Private reference is anchored to the UUID of the saved initial human message.' );
rc_check( ! isset( $private['context']['screenshot'], $start['run']['reference_image'] ) && ! str_contains( wp_json_encode( $start ), $image['data'] ), 'Active/public start data omits image bytes; current-page context is distinct from the reference.' );
$waiting = rc_step( 101, $start['run']['id'] ); rc_capture( $original, $image );
rc_check( array( 'message_id' => $original_id, 'mime_type' => 'image/png', 'width' => 2, 'height' => 3 ) === $waiting['run']['reference_image'], 'Only waiting state exposes the removable composer summary with original identity.' );
rc_check( ! str_contains( wp_json_encode( $waiting ), $image['data'] ) && ! str_contains( wp_json_encode( $GLOBALS['ac']['events'] ), $image['data'] ), 'Public sessions and durable audit events do not contain retained bytes.' );
$answer = rc_start( 101, 'Local shoppers should send an enquiry. Preserve the catalogue.', array( 'screen' => 'dashboard', 'post_id' => 99, 'title' => 'A different current page' ) );
$answered = get_post_meta( 101, 'bhranti_run', true );
rc_check( $private['reference_image'] === $answered['reference_image'] && 99 === $answered['context']['post_id'] && ! isset( $answer['run']['reference_image'] ), 'Answer inherits original reference only, while fresh current-page context remains independent.' );
$waiting_again = rc_step( 101, $answer['run']['id'] ); rc_capture( $original, $image );
rc_check( $original_id === $waiting_again['run']['reference_image']['message_id'] && $answer['messages'][2]['id'] !== $original_id, 'A further clarification still exposes the first chosen image UUID, not the answer UUID.' );
$final_answer = rc_start( 101, 'Use Home, Collection, About and Contact; staff reply afterward.' );
rc_step( 101, $final_answer['run']['id'], 'reply' ); rc_capture( $original, $image );
rc_check( ! isset( get_post_meta( 101, 'bhranti_run', true )['reference_image'] ) && ! isset( Bhranti_Agent::state( 101 )['run']['reference_image'] ), 'Completion clears retained bytes and the public presence summary.' );
$new_task = rc_start( 101, 'Now tell me the task limit.' ); rc_step( 101, $new_task['run']['id'], 'reply' );
rc_check( array() === rc_files(), 'A subsequent completed-conversation task never silently receives the old image.' );

// Replacement and explicit discard are intentional choices, including recovery from bad old state.
$old = rc_waiting( 102, $image );
$new = rc_start( 102, 'Use this new reference instead.', array( 'screenshot' => $replacement ) );
$new_private = get_post_meta( 102, 'bhranti_run', true );
rc_check( $old['reference_image']['message_id'] !== $new_private['reference_image']['message_id'] && $new['messages'][2]['id'] === $new_private['reference_image']['message_id'], 'A new chosen image replaces the old image and binds to the new saved human message.' );
rc_step( 102, $new['run']['id'] ); rc_capture( 'Use this new reference instead.', $replacement );
$discard = rc_start( 102, 'Continue without the reference.', array( 'discard_reference_image' => true ) );
rc_check( ! isset( get_post_meta( 102, 'bhranti_run', true )['reference_image'], get_post_meta( 102, 'bhranti_run', true )['context']['discard_reference_image'] ), 'Explicit discard removes the reference and is not included as page context.' );
rc_step( 102, $discard['run']['id'] ); rc_check( array() === rc_files(), 'Discarded image is absent from the next native request.' );
$both = rc_start( 102, 'Use only the newly supplied image.', array( 'discard_reference_image' => true, 'screenshot' => $image ) );
rc_step( 102, $both['run']['id'] ); rc_capture( 'Use only the newly supplied image.', $image );
$unchanged = get_post_meta( 102, 'bhranti_run', true );
$keep = rc_start( 102, 'Keep using it.', array( 'discard_reference_image' => false ) );
rc_check( $unchanged['reference_image'] === get_post_meta( 102, 'bhranti_run', true )['reference_image'], 'Exact false preserves a valid same-question reference.' );
rc_step( 102, $keep['run']['id'] );
foreach ( array( null, 0, 1, 'true', array() ) as $bad_choice ) {
    $before = $GLOBALS['ac'];
    $bad = rc_start( 102, 'Reject this flag.', array( 'discard_reference_image' => $bad_choice ) );
    rc_check( is_wp_error( $bad ) && 'bhranti_context_invalid' === $bad->get_error_code(), 'Only an exact boolean may remove a reference.' );
    $after = $GLOBALS['ac'];
    // Acquiring/releasing the request lock consumes a process-local random UUID,
    // but never persists run state, messages, audit, scheduling or provider calls.
    unset( $before['uuid'], $after['uuid'] );
    rc_check( $before === $after, 'Invalid discard input has no storage, audit, scheduling or provider side effect.' );
}

// Owned-session identity is mandatory; cancellation and unrelated sessions never inherit.
$before = $GLOBALS['ac']; $GLOBALS['ac']['user'] = 8;
$foreign = rc_start( 102, 'An unrelated user must not use this image.' );
rc_check( is_wp_error( $foreign ) && 'bhranti_session_missing' === $foreign->get_error_code(), 'Other users cannot inherit or expose the conversation reference.' );
$GLOBALS['ac']['user'] = 7;
$after = $GLOBALS['ac']; unset( $before['uuid'], $after['uuid'] );
rc_check( $before === $after, 'Rejected foreign Send cannot change the owned reference or messages.' );
rc_session( 103 ); $separate = rc_start( 103, 'A separate task with no image.' ); rc_step( 103, $separate['run']['id'], 'reply' );
rc_check( array() === rc_files(), 'An unrelated conversation never receives another conversation image.' );
rc_session( 104 ); $cancelled_start = rc_start( 104, 'Start then stop this referenced task.', array( 'screenshot' => $image ) );
$cancelled = Bhranti_Agent::cancel( array( 'id' => 104, 'run_id' => $cancelled_start['run']['id'] ) );
rc_check( 'cancelled' === $cancelled['run']['status'] && ! isset( get_post_meta( 104, 'bhranti_run', true )['reference_image'] ), 'Stopping an active task clears its private retained reference.' );
$after_stop = rc_start( 104, 'An independent request after Stop.' ); rc_step( 104, $after_stop['run']['id'], 'reply' );
rc_check( array() === rc_files(), 'A task started after Stop does not reuse the old image.' );

// Revalidation checks real raster bounds, the original human identity and unknown state.
$valid = rc_waiting( 105, $image ); $messages = get_post_meta( 105, 'bhranti_messages', true );
$corruptions = array();
$bad = $valid; $bad['requester_id'] = 8; $corruptions[] = array( $bad, $messages );
$bad = $valid; $bad['reference_image'] = null; $corruptions[] = array( $bad, $messages );
$bad = $valid; $bad['reference_image']['unrecognized'] = true; $corruptions[] = array( $bad, $messages );
$bad = $valid; $bad['reference_image']['message_id'] = 'not-a-human-message'; $corruptions[] = array( $bad, $messages );
$bad = $valid; $bad['reference_image']['message_id'] = wp_generate_uuid4(); $corruptions[] = array( $bad, $messages );
$bad = $valid; $bad['reference_image']['message_id'] = $messages[1]['id']; $corruptions[] = array( $bad, $messages );
$duplicated = $messages; $duplicated[] = $messages[0]; $corruptions[] = array( $valid, $duplicated );
foreach ( array(
    array( 'width' => 3 ), array( 'height' => '3' ), array( 'width' => 0 ), array( 'unknown' => 'value' ),
    array( 'data' => 'private-invalid-bytes' ), array( 'mime_type' => 'image/jpeg' ),
    array( 'data' => str_repeat( 'A', 2796205 ) ),
    array( 'data' => rc_png( 2561, 1 )['data'], 'width' => 2561, 'height' => 1 ),
) as $changes ) { $bad = $valid; $bad['reference_image']['image'] = array_replace( $bad['reference_image']['image'], $changes ); $corruptions[] = array( $bad, $messages ); }
foreach ( $corruptions as $case => list( $bad_run, $bad_messages ) ) {
    $GLOBALS['ac']['meta'][105]['bhranti_run'] = $bad_run; $GLOBALS['ac']['meta'][105]['bhranti_messages'] = $bad_messages;
    $public = Bhranti_Agent::state( 105 );
    rc_check( ! isset( $public['run']['reference_image'] ), 'Invalid private reference exposes no composer summary (case ' . $case . ').' );
    $before = $GLOBALS['ac'];
    $bad = rc_start( 105, 'This answer must not reuse bad stored reference data.' );
    rc_check( is_wp_error( $bad ) && 'bhranti_reference_unavailable' === $bad->get_error_code(), 'Bad private reference fails safely before a new Send (case ' . $case . ').' );
    // UUID allocation is not a persistent change and occurs before reference validation.
    $after = $GLOBALS['ac']; unset( $before['uuid'], $after['uuid'] );
    rc_check( $before === $after, 'Bad stored reference causes no message, run, audit, Cron or native call change.' );
    $calls = $GLOBALS['ac']['calls'];
    $bad = ac_private( 'ask', get_post( 105 ), $bad_run );
    rc_check( is_wp_error( $bad ) && $calls === $GLOBALS['ac']['calls'], 'Bad stored reference cannot reach the native model request.' );
}
$recovered = rc_start( 105, 'Replace the unverifiable old image.', array( 'screenshot' => $replacement ) );
rc_step( 105, $recovered['run']['id'] ); rc_capture( 'Replace the unverifiable old image.', $replacement );
$broken = get_post_meta( 105, 'bhranti_run', true ); $broken['reference_image'] = array(); $GLOBALS['ac']['meta'][105]['bhranti_run'] = $broken;
$recovered = rc_start( 105, 'Discard the unverifiable old image.', array( 'discard_reference_image' => true ) );
rc_step( 105, $recovered['run']['id'], 'reply' ); rc_check( array() === rc_files(), 'Explicit discard safely recovers from unverifiable prior image state.' );

// Keep a middle human reference after first+recent conversation compaction.
rc_session( 106 );
$initial = Bhranti_Workspace::message( 'user', 'Earlier unrelated conversation brief.' );
$reference_message = Bhranti_Workspace::message( 'user', 'This middle request owns the chosen visual reference.' );
$middle_messages = array( $initial, Bhranti_Workspace::message( 'assistant', 'Earlier answer.' ), $reference_message );
for ( $i = 0; $i < 14; ++$i ) { $middle_messages[] = Bhranti_Workspace::message( 'assistant', 'Clarification ' . $i ); $middle_messages[] = Bhranti_Workspace::message( 'user', 'Confirmed decision ' . $i ); }
$middle_messages[] = Bhranti_Workspace::message( 'assistant', 'One remaining decision?' );
$middle_run = $valid; $middle_run['id'] = wp_generate_uuid4(); $middle_run['reference_image']['message_id'] = $reference_message['id'];
$GLOBALS['ac']['meta'][106] = array( 'bhranti_run' => $middle_run, 'bhranti_messages' => $middle_messages );
$compacted = rc_start( 106, 'The final confirmed business decision.' );
rc_step( 106, $compacted['run']['id'] ); rc_capture( $reference_message['content'], $image );
$human_texts = array_map( static function( $message ) { return $message->getParts()[0]->getText(); }, $GLOBALS['ac']['history'] );
rc_check( in_array( $initial['content'], $human_texts, true ) && in_array( 'The final confirmed business decision.', $human_texts, true ) && count( array_filter( $human_texts, static function( $text ) use ( $reference_message ) { return $text === $reference_message['content']; } ) ) === 1, 'Ordinary compaction preserves first brief, original reference and latest human answer exactly once.' );
$history_run = get_post_meta( 106, 'bhranti_run', true );
$history_run['history'][] = array( 'id' => $reference_message['id'], 'role' => 'user', 'content' => 'Tool-history data cannot borrow a human image binding.' );
$GLOBALS['ac']['reply'] = ac_envelope( 'reply', 'Inspect the same original reference.' );
ac_private( 'ask', get_post( 106 ), $history_run ); rc_capture( $reference_message['content'], $image );
$large = array( Bhranti_Workspace::message( 'user', str_repeat( '漢', 12000 ) ), Bhranti_Workspace::message( 'assistant', 'Answer.' ), Bhranti_Workspace::message( 'user', str_repeat( '猫', 12000 ) ) );
for ( $i = 0; $i < 14; ++$i ) { $large[] = Bhranti_Workspace::message( 'assistant', str_repeat( 'x', 12000 ) ); }
$large[] = Bhranti_Workspace::message( 'user', str_repeat( '犬', 12000 ) );
$bounded = ac_private( 'conversation_context', $large, $large[2]['id'] );
rc_check( strlen( wp_json_encode( $bounded, JSON_UNESCAPED_UNICODE ) ) <= 160000, 'Native UTF-8 conversation byte bound holds even for three maximum Unicode human texts.' );
rc_check( in_array( $large[2]['id'], array_column( $bounded, 'id' ), true ) && in_array( end( $large )['id'], array_column( $bounded, 'id' ), true ), 'Byte-budget pressure preserves the image owner and latest answer without moving the image.' );
foreach ( array( '😀', "\x00" ) as $unit ) {
    $edge = $large; $edge[0]['content'] = str_repeat( $unit, 12000 ); $edge[2]['content'] = str_repeat( $unit, 12000 ); $edge[count( $edge ) - 1]['content'] = str_repeat( $unit, 12000 );
    $bounded_edge = ac_private( 'conversation_context', $edge, $edge[2]['id'] );
    rc_check( strlen( wp_json_encode( $bounded_edge, JSON_UNESCAPED_UNICODE ) ) <= 160000, 'UTF-8 budget holds for supplementary Unicode and maximum JSON control-character escaping.' );
    rc_check( in_array( $edge[2]['id'], array_column( $bounded_edge, 'id' ), true ) && in_array( end( $edge )['id'], array_column( $bounded_edge, 'id' ), true ) && $edge[2]['content'] === $bounded_edge[array_search( $edge[2]['id'], array_column( $bounded_edge, 'id' ), true )]['content'], 'Supplementary/control characters preserve original reference text and latest answer intact.' );
}

// Compatible old active runs stay usable, but unbound bytes do not migrate into new answers.
rc_session( 107 ); $legacy = rc_start( 107, 'Existing active legacy image request.', array( 'screenshot' => $image ) );
$legacy_run = get_post_meta( 107, 'bhranti_run', true ); $legacy_run['context']['screenshot'] = $legacy_run['reference_image']['image']; unset( $legacy_run['reference_image'] );
$GLOBALS['ac']['meta'][107]['bhranti_run'] = $legacy_run;
rc_step( 107, $legacy['run']['id'] ); rc_capture( 'Existing active legacy image request.', $image );
$legacy_answer = rc_start( 107, 'Answer without a verified bound legacy reference.' );
rc_step( 107, $legacy_answer['run']['id'], 'reply' ); rc_check( array() === rc_files(), 'Unbound legacy image never silently inherits into a new question-answer run.' );

// Exact native configuration support is checked before a chosen image is shared for generation.
rc_session( 108 ); $vision_text = 'Use this reference once native image understanding is available.';
$vision_start = rc_start( 108, $vision_text, array( 'screenshot' => $image ) );
$GLOBALS['ac']['vision_support'] = array( 'image' => false, 'text' => true ); $GLOBALS['ac']['support_checks'] = array();
$calls = $GLOBALS['ac']['calls'];
$vision_waiting = Bhranti_Agent::step( array( 'id' => 108, 'run_id' => $vision_start['run']['id'] ) );
rc_check( 'awaiting_input' === $vision_waiting['run']['status'] && $calls === $GLOBALS['ac']['calls'] && 2 === count( $GLOBALS['ac']['support_checks'] ), 'Known native image=false plus equivalent text=true asks for input without a model-generation request.' );
$probes = $GLOBALS['ac']['support_checks'];
rc_check( 1 === $probes[0]['files'] && 0 === $probes[1]['files'] && $probes[0]['instruction'] === $probes[1]['instruction'] && $probes[0]['schema'] === $probes[1]['schema'], 'Vision comparison changes only image message parts, preserving exact system and strict JSON configuration.' );
$image_probe = $probes[0]['history']; $text_probe = $probes[1]['history'];
rc_check( array_map( static function( $message ) { return array( $message->getRole()->value, $message->getParts()[0]->getText() ); }, $image_probe ) === array_map( static function( $message ) { return array( $message->getRole()->value, $message->getParts()[0]->getText() ); }, $text_probe ), 'Text-only capability probe preserves original message roles, order and verbatim textual context.' );
$GLOBALS['ac']['history'] = $image_probe; rc_capture( $vision_text, $image );
rc_check( $vision_start['messages'][0]['id'] === $vision_waiting['run']['reference_image']['message_id'] && str_contains( end( $vision_waiting['messages'] )['content'], 'Remove the reference and describe' ), 'Trusted native capability question retains the reference chip and offers an explicit human choice.' );
$retry_answer = rc_start( 108, 'Keep the image for now; I have not changed the native connection.' );
$again = Bhranti_Agent::step( array( 'id' => 108, 'run_id' => $retry_answer['run']['id'] ) );
rc_check( 'awaiting_input' === $again['run']['status'] && $calls === $GLOBALS['ac']['calls'] && $vision_waiting['run']['reference_image'] === $again['run']['reference_image'], 'An answer without removal never silently drops an unsupported chosen image or pretends it was inspected.' );
$text_answer = rc_start( 108, 'Use the described layout: strong title and product photography.', array( 'discard_reference_image' => true ) );
rc_step( 108, $text_answer['run']['id'], 'reply' ); rc_check( array() === rc_files(), 'An explicit removed-reference answer uses the ordinary native text path.' );

// Unknown metadata never becomes an unsupported-image fact and never generates blindly.
foreach ( array( null, 1, 'true', new stdClass(), new WP_Error( 'private-capability-error', 'private-metadata-marker' ), new RuntimeException( 'private-metadata-marker' ) ) as $case => $unknown ) {
    rc_session( 109 ); $start_unknown = rc_start( 109, 'Do not guess this unknown native vision capability.', array( 'screenshot' => $image ) );
    $GLOBALS['ac']['vision_support'] = array( 'image' => $unknown, 'text' => true ); $GLOBALS['ac']['support_checks'] = array();
    $calls = $GLOBALS['ac']['calls']; $run_unknown = get_post_meta( 109, 'bhranti_run', true );
    $error = ac_private( 'ask', get_post( 109 ), $run_unknown );
    rc_check( is_wp_error( $error ) && $calls === $GLOBALS['ac']['calls'] && 1 === count( $GLOBALS['ac']['support_checks'] ), 'Unknown/nonboolean/exception native vision capability stops before generation or a text-only guess (case ' . $case . ').' );
    rc_check( ! str_contains( $error->get_error_message(), 'cannot read this reference' ) && ! str_contains( wp_json_encode( $GLOBALS['ac']['events'] ), 'private-metadata-marker' ) && $run_unknown['reference_image'] === get_post_meta( 109, 'bhranti_run', true )['reference_image'], 'Unknown capability neither claims unsupported nor exposes private native diagnostics or removes the image.' );
}
foreach ( array( false, null, new stdClass() ) as $unknown_text ) {
    $GLOBALS['ac']['vision_support'] = array( 'image' => false, 'text' => $unknown_text ); $GLOBALS['ac']['support_checks'] = array();
    $calls = $GLOBALS['ac']['calls'];
    $error = ac_private( 'ask', get_post( 109 ), get_post_meta( 109, 'bhranti_run', true ) );
    rc_check( is_wp_error( $error ) && $calls === $GLOBALS['ac']['calls'] && 2 === count( $GLOBALS['ac']['support_checks'] ), 'Image=false without strict equivalent text=true remains a general native failure, never a known vision limitation.' );
}
$GLOBALS['ac']['vision_support'] = array( 'image' => new stdClass() ); $GLOBALS['ac']['support_checks'] = array();
$unknown_id = get_post_meta( 109, 'bhranti_run', true )['id']; $calls = $GLOBALS['ac']['calls'];
$first_unknown = Bhranti_Agent::step( array( 'id' => 109, 'run_id' => $unknown_id ) );
$last_unknown = Bhranti_Agent::step( array( 'id' => 109, 'run_id' => $unknown_id ) );
rc_check( 'running' === $first_unknown['run']['status'] && 'failed' === $last_unknown['run']['status'] && $calls === $GLOBALS['ac']['calls'] && 2 === count( $GLOBALS['ac']['support_checks'] ), 'Unknown metadata follows only the existing bounded failure retry, with no blind native generation or unsupported question.' );
rc_check( isset( get_post_meta( 109, 'bhranti_run', true )['reference_image'] ) && ! isset( $last_unknown['run']['reference_image'] ), 'Bounded unknown failure preserves private reference for this run without exposing an awaiting-input claim.' );
unset( $GLOBALS['ac']['vision_support'] );

echo 'PASS ' . $rc_checks . " reference-continuation checks with actual native File/MessagePart DTOs and Agent/Workspace/Task/Runner; no DB, HTTP or AI.\n";
