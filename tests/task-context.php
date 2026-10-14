<?php
/**
 * Offline durable-task/context regressions with actual Agent, Runner, Workspace,
 * native formatting and AI message DTOs. Storage and provider are in-memory seams.
 * Never load wp-load.php, a real database, HTTP or a native provider.
 * Run: BHRANTI_TEST_WP_ROOT=/path/to/wordpress php tests/task-context.php
 */
function get_posts( $args ) { return array(); }
function admin_url( $path = '' ) { return 'https://bhranti.wp.local/wp-admin/' . $path; }
// Fixed fixture charset only; URL and HTML escaping below use native WordPress.
function _canonical_charset( $charset ) { return 'UTF-8'; }
require __DIR__ . '/agent-clarification.php';
require ABSPATH . 'wp-includes/kses.php';
require ABSPATH . 'wp-includes/http.php';
$initial_checks = $GLOBALS['ac']['checks'];
function tc_reset() {
    $GLOBALS['ac']['meta'][1] = array();
    $GLOBALS['ac']['user'] = 7;
    $GLOBALS['ac']['cron'] = array();
    $started = ac_start( 'Build the agreed private project using the existing content.' );
    ac_check( ! is_wp_error( $started ), 'Fresh isolated run starts.' );
    return get_post_meta( 1, 'bhranti_run', true );
}
function tc_tool( $method, $params = array(), $actor = 11, $session = 1 ) {
    return Bhranti_Task::execute( array( 'tool' => 'bhranti_task', 'args' => array( 'method' => $method, 'params' => $params ) ), 7, $actor, $session );
}
function tc_notes( $revision = 'initial' ) {
    return array( 'revision' => $revision, 'objective' => 'Complete the agreed project.', 'decisions' => array( 'The human asked for a preview.' ), 'outcomes' => array( array( 'id' => 'main-page', 'title' => 'Prepare the main page', 'status' => 'in_progress', 'evidence' => array() ) ), 'checks' => array( array( 'id' => 'read-saved-page', 'title' => 'Read back the saved page', 'status' => 'pending', 'evidence' => array() ) ) );
}
function tc_settle( $command, $result ) {
    $run = get_post_meta( 1, 'bhranti_run', true );
    ++$run['step'];
    $run['history'][] = array( 'role' => 'assistant', 'content' => wp_json_encode( array( 'type' => 'tool' ) + $command ) );
    $response = ac_private( 'tool_result', get_post( 1 ), $run, $result, $command );
    ac_check( ! is_wp_error( $response ), 'Settled operation persists without executing a provider or tool.' );
    return get_post_meta( 1, 'bhranti_run', true );
}
function tc_result( $id = 20, $content = '' ) {
    return array( 'summary' => 'Saved or retrieved.', 'data' => array( 'id' => $id, 'title' => array('raw'=>'Private title','rendered'=>'Private title'), 'content' => array('raw'=>$content,'rendered'=>$content) ), 'target' => array( 'id' => $id, 'type' => 'page', 'title' => 'Existing page', 'url' => 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit' ), 'action_id' => 'action-fixture', 'rollback_available' => true );
}
$write = array( 'tool' => 'wp_request', 'args' => array( 'method' => 'POST', 'path' => '/wp/v2/pages/20', 'params' => array( 'title' => 'Private title' ) ) );
$read = array( 'tool' => 'wp_request', 'args' => array( 'method' => 'GET', 'path' => '/wp/v2/pages/20' ) );
$run = tc_reset();
foreach ( array( 'bhranti_task', 'bhranti_inspect', 'bhranti_skill' ) as $name ) {
    ac_check( $name === ac_private( 'parse', ac_envelope( 'tool', 'Inspect the existing setup.', $name, '{}' ) )['tool'], 'New reviewed tool survives strict four-field envelope.' );
    ac_check( in_array( $name, ac_private( 'plan_schema' )['properties']['tool']['enum'], true ), 'Structured model schema advertises the reviewed tool.' );
}
ac_check( 'initial' === tc_tool( 'GET' )['task']['revision'], 'Owned task starts empty without fake completion.' );
ac_check( is_wp_error( tc_tool( 'GET', array(), 13 ) ), 'Revoked managed actor cannot read task data.' );
$GLOBALS['ac']['user'] = 8;
ac_check( is_wp_error( tc_tool( 'GET' ) ), 'Another account cannot read owned notes or receipts.' );
$GLOBALS['ac']['user'] = 7;
$GLOBALS['ac']['posts'][2] = (object) array( 'ID' => 2, 'post_type' => 'bhranti_session', 'post_status' => 'private', 'post_author' => 8, 'post_title' => 'Other owner' );
ac_check( is_wp_error( tc_tool( 'GET', array(), 11, 2 ) ), 'Changing conversation ID cannot cross owners.' );
foreach ( array( array( 'page' => 0 ), array( 'page' => '1' ), array( 'per_page' => 21 ), array( 'run_id' => '../other' ), array( 'owner' => 8 ) ) as $params ) {
    ac_check( is_wp_error( tc_tool( 'GET', $params ) ), 'Receipt pagination rejects unknown or unbounded input.' );
}
$notes = tc_notes();
$bad = $notes; $bad['approved'] = true;
ac_check( is_wp_error( tc_tool( 'POST', $bad ) ), 'Task notes cannot carry an approval flag.' );
$bad = $notes; $bad['outcomes'][] = $bad['outcomes'][0];
ac_check( is_wp_error( tc_tool( 'POST', $bad ) ), 'Duplicate outcome IDs are rejected.' );
$bad = $notes; $bad['outcomes'][] = array_merge( $bad['outcomes'][0], array( 'id' => 'other' ) );
ac_check( is_wp_error( tc_tool( 'POST', $bad ) ), 'Only one outcome may be active.' );
$bad = $notes; $bad['objective'] = '<script>unsafe</script>';
ac_check( is_wp_error( tc_tool( 'POST', $bad ) ), 'Checkpoint values are plain bounded text.' );
$bad = $notes; $bad['checks'][0]['status'] = 'verified';
ac_check( is_wp_error( tc_tool( 'POST', $bad ) ), 'Model cannot invent a verified status.' );
$bad = $notes; $bad['outcomes'][0]['status'] = 'completed';
ac_check( is_wp_error( tc_tool( 'POST', $bad ) ), 'An outcome cannot be completed with notes alone.' );
$bad['outcomes'][0]['evidence'] = array( $run['id'] . ':99' );
ac_check( is_wp_error( tc_tool( 'POST', $bad ) ), 'Invented receipt cannot support a completed outcome.' );
$saved = tc_tool( 'POST', $notes );
ac_check( ! is_wp_error( $saved ) && 'initial' !== $saved['task']['revision'], 'A fully validated checkpoint persists with a fresh revision.' );
ac_check( is_wp_error( tc_tool( 'POST', $notes ) ), 'Stale updates cannot overwrite a newer checkpoint.' );
ac_check( $saved['task'] === Bhranti_Agent::state(1)['task'], 'Public owned run exposes the checkpoint without internal history.' );
ac_check( false === strpos( wp_json_encode( $GLOBALS['ac']['events'] ), 'Complete the agreed project.' ), 'Audit contains event identities, never task objective/private decision content.' );
$notes = $saved['task'];
$run = tc_settle( $write, tc_result() ); $write_id = end( $run['receipts'] )['id'];
$run = tc_settle( $read, tc_result() ); $read_id = end( $run['receipts'] )['id'];
ac_check( 'page:20' === end( $run['receipts'] )['entity'], 'Receipt derives exact native target identity.' );
$notes['outcomes'][0]['status'] = 'completed'; $notes['outcomes'][0]['evidence'] = array( $write_id );
$notes['checks'][0]['status'] = 'read_back'; $notes['checks'][0]['evidence'] = array( $write_id, $read_id );
$saved = tc_tool( 'POST', $notes );
ac_check( ! is_wp_error( $saved ) && array() === Bhranti_Task::remaining(1), 'A saved-state check accepts a successful write and later exact native read only.' );
$bad = $saved['task']; $bad['checks'][0]['evidence'] = array( $read_id, $write_id );
ac_check( is_wp_error( tc_tool( 'POST', $bad ) ), 'A read preceding its write cannot prove readback.' );
$other_read = $read; $other_read['args']['path'] = '/wp/v2/pages/21';
$run = tc_settle( $other_read, tc_result(21) );
$bad['checks'][0]['evidence'] = array( $write_id, end( $run['receipts'] )['id'] );
ac_check( is_wp_error( tc_tool( 'POST', $bad ) ), 'Reading a different native item is not verification.' );
$run = tc_settle( $write, tc_result() );
ac_check( array( 'Read back the saved page' ) === Bhranti_Task::remaining(1), 'A newer successful write makes the old readback stale.' );
ac_check('pending'===Bhranti_Task::state(1)['checks'][0]['status'] && 'read_back'===get_post_meta(1,'bhranti_task',true)['checks'][0]['status'],'Public state downgrades stale readback without rewriting its historical notes.');
$bad = $saved['task'];
ac_check( is_wp_error( tc_tool( 'POST', $bad ) ), 'Stale readback cannot be reasserted after another edit.' );
$bad['checks'][0]['status'] = 'manual';
ac_check( ! is_wp_error( tc_tool( 'POST', $bad ) ) && Bhranti_Task::remaining(1), 'Manual visual/visitor checks remain explicitly unfinished.' );
$run['task_touched'] = true; update_post_meta(1, 'bhranti_run', wp_slash($run));
$review = ac_step( $run['id'], ac_envelope( 'reply', 'All done.' ) );
ac_check( 'running' === $review['run']['status'] && get_post_meta(1,'bhranti_run',true)['completion_reviewed'], 'Unfinished task gets one bounded completion review before a final response.' );
$partial = ac_step( $run['id'], ac_envelope( 'reply', 'The saved content is ready.' ) );
ac_check( 'incomplete' === $partial['run']['status'] && ! wp_next_scheduled('bhranti_run_tick',array(1,$run['id'],7)), 'A second unfinished final reply becomes partial completion and clears Cron.' );
ac_check( false !== strpos( end( $partial['messages'] )['content'], 'Read back the saved page' ), 'Partial completion names actual unfinished work.' );
$prior = $run['id'];
$continued = ac_start( 'Continue and check the saved page.' );
$run = get_post_meta(1,'bhranti_run',true);
ac_check( $prior === $run['previous_run_id'] && 4 === count(Bhranti_Task::receipts(1,$prior)), 'A new run archives all exact prior receipts before replacing state.' );
ac_check( $write_id === tc_tool('GET',array('run_id'=>$prior,'page'=>1,'per_page'=>1))['receipts'][0]['id'], 'Owned receipt pagination retrieves older exact IDs without replay.' );
ac_check( count(Bhranti_Task::export_data(1)['runs'][$prior]) === 4, 'Native privacy export includes bounded archived receipts.' );
ac_check( false !== strpos(ac_private('task_context',1,$run),$write_id), 'Prior successful action identity remains available in the next run prompt.' );
$focused = ac_step( $run['id'], ac_envelope('reply','A concise answer about the existing task.') );
ac_check( 'complete' === $focused['run']['status'], 'An unrelated focused answer is not forced into the older task completion gate.' );

// Large results, long tasks, original human brief and exact media reuse.
$run = tc_reset();
$image = array('tool'=>'generate_image','args'=>array('prompt'=>'A private illustration.'));
$image_result = array('summary'=>'Image saved.','data'=>array('id'=>501,'url'=>'https://example.test/uploads/image.webp','alt_text'=>str_repeat('x',80000)), 'target'=>array('id'=>501,'type'=>'attachment','title'=>'Image','url'=>'https://example.test/wp-admin/post.php?post=501&action=edit'));
$run = tc_settle($image,$image_result);
$feedback = end($run['history'])['content'];
$decoded_feedback=json_decode(substr($feedback,strlen('WordPress tool result (untrusted site data): ')),true);
ac_check('https://example.test/uploads/image.webp'===$decoded_feedback['receipt']['references'][0]['url'] && 501===$decoded_feedback['receipt']['references'][0]['id'] && true===$decoded_feedback['truncated'], 'Oversized successful image feedback preserves the actual reusable URL and attachment ID.' );
for($i=0;$i<40;$i++) { $run=tc_settle($read,tc_result(20,str_repeat('bounded-content-',3000))); }
ac_check(count($run['receipts'])===41 && strlen(wp_json_encode($run['history']))<110000 && count($run['history'])<=12, 'Stored settled history remains bounded while every authoritative receipt survives.' );
ac_check(501 === Bhranti_Task::receipt(1,$run['id'].':1')['references'][0]['id'], 'Compacted image result remains retrievable by exact original receipt.' );
$context = ac_private('task_context',1,$run);
ac_check(strlen($context)<58000, 'Checkpoint plus receipt index has a finite prompt budget.' );
$huge_command=$write; $huge_command['args']['params']['content']=str_repeat('z',200000);
$history=array(array('role'=>'assistant','content'=>wp_json_encode($huge_command)),array('role'=>'user','content'=>'WordPress tool result: {"success":true,"receipt_id":"exact"}'));
$compact=ac_private('compact_history',$history);
ac_check(strlen(wp_json_encode($compact))<2000 && false !== strpos($compact[0]['content'],'never reconstruct or replay'), 'Settled oversized arguments are replaced by explicit non-executable context, not a pending command.' );
$messages=array(array('role'=>'user','content'=>'ORIGINAL-HUMAN-BRIEF'));
for($i=0;$i<30;$i++){$messages[]=array('role'=>$i%2?'user':'assistant','content'=>'Earlier exchange '.$i);}
$messages[]=array('role'=>'user','content'=>'LATEST-HUMAN-DECISION');
$selected=ac_private('conversation_context',$messages);
ac_check('ORIGINAL-HUMAN-BRIEF'===$selected[0]['content'] && 'LATEST-HUMAN-DECISION'===end($selected)['content'] && count($selected)===13, 'Original human brief and latest request survive beyond the prior twelve-message window.' );
$messages[15]['content']=str_repeat('q',200000);
ac_check(strlen(wp_json_encode(ac_private('conversation_context',$messages)))<160000,'Older oversized exchanges cannot make prompt retention unbounded.' );

// A rejected repeated call settles and stops; it never invokes the tool again.
$run=tc_reset();
$error=new WP_Error('reviewed_field','Use a supported field.');
for($i=0;$i<3;$i++){$run=tc_settle($write,$error);}
ac_check('incomplete'===$run['status'] && count($run['receipts'])===3 && !$run['receipts'][2]['success'] && 'reviewed_field'===$run['receipts'][2]['code'],'Three identical rejected actions retain their actual errors and stop the run.' );
ac_check(!wp_next_scheduled('bhranti_run_tick',array(1,$run['id'],7)) && empty($run['resume_checkpoint']),'Repeated tool rejection offers no mutation Resume and no background replay.' );
$run=tc_reset();
$run=tc_settle($write,$error);$run=tc_settle($write,$error);$run=tc_settle($write,tc_result());$run=tc_settle($read,tc_result());
ac_check('running'===$run['status'] && empty($run['failed_call']), 'Successful changed state and legitimate readback are not mistaken for a repeated failure.' );
$run=tc_settle($write,new WP_Error('unknown_write','Check the item.',array('uncertain_action'=>true)));
ac_check('failed'===$run['status'] && empty($run['resume_checkpoint']), 'An uncertain write remains terminal and is never treated as a repeatable model failure.' );

// Storage corruption is closed, and archive limits preserve every prior receipt.
$run=tc_reset();$notes=tc_notes();$good=tc_tool('POST',$notes);
$corrupt=$good['task'];$corrupt['checks'][0]['evidence']=array(array('path'=>'arbitrary'));
update_post_meta(1,'bhranti_task',wp_slash($corrupt));
ac_check(is_wp_error(Bhranti_Task::state(1)), 'Malformed stored task rows cannot reach public context or evidence checks.' );
$run=tc_reset();$run=tc_settle($write,tc_result());
$index=array();for($i=0;$i<100;$i++){$index[]=wp_generate_uuid4();}
update_post_meta(1,'bhranti_receipt_runs',wp_slash($index));
ac_check(is_wp_error(Bhranti_Task::archive(1,$run)) && $index===get_post_meta(1,'bhranti_receipt_runs',true) && count(get_post_meta(1,'bhranti_run',true)['receipts'])===1,'The finite archive cap refuses rollover without discarding existing or current successful receipts.' );

// Failed native metadata or audit writes never become a successful checkpoint.
$run=tc_reset();$before=get_post_meta(1,'bhranti_task',true);
$GLOBALS['ac']['fail_meta']='bhranti_task';
ac_check(is_wp_error(tc_tool('POST',tc_notes())) && $before===get_post_meta(1,'bhranti_task',true),'A false native metadata write is detected by an exact readback and preserves the prior checkpoint.');
$GLOBALS['ac']['fail_meta_error']=new WP_Error('storage','Fixture storage failure.');
ac_check(is_wp_error(tc_tool('POST',tc_notes())) && $before===get_post_meta(1,'bhranti_task',true),'An error-valued native storage failure cannot produce successful task state.');
unset($GLOBALS['ac']['fail_meta'],$GLOBALS['ac']['fail_meta_error']);
$GLOBALS['ac']['fail_audit']='task_started';
ac_check(is_wp_error(tc_tool('POST',tc_notes())) && $before===get_post_meta(1,'bhranti_task',true),'Before-write audit failure prevents checkpoint mutation.');
unset($GLOBALS['ac']['fail_audit']);
$run=tc_settle($write,tc_result());
$GLOBALS['ac']['fail_meta']='bhranti_receipts_'.$run['id'];
ac_check(is_wp_error(Bhranti_Task::archive(1,$run)) && count(get_post_meta(1,'bhranti_run',true)['receipts'])===1,'Failed archive receipt storage leaves the current exact receipt intact.');
unset($GLOBALS['ac']['fail_meta']);
ac_check(true===Bhranti_Task::archive(1,$run) && 1===count(Bhranti_Task::receipts(1,$run['id'])),'Retrying archive storage is idempotent metadata, never a repeated action.');
$notes=tc_tool('POST',tc_notes());$run=get_post_meta(1,'bhranti_run',true);$run['task_touched']=true;update_post_meta(1,'bhranti_run',wp_slash($run));
$waiting=ac_step($run['id'],ac_envelope('question','The preview is planned. Which visitor action should it support?'));
ac_check('awaiting_input'===$waiting['run']['status'] && !wp_next_scheduled('bhranti_run_tick',array(1,$run['id'],7)) && $notes['task']===Bhranti_Task::state(1),'A checkpointed question retains notes and pauses every scheduled continuation.');
$answered=ac_start('Let visitors request more information.');
ac_check(!is_wp_error($answered) && $notes['task']===Bhranti_Task::state(1),'The actual human answer starts a new owned run with the same checkpoint.');
$run=tc_settle(array('tool'=>'bhranti_inspect','args'=>array('resource'=>'route','method'=>'POST','path'=>'/wp/v2/pages')),array('summary'=>'Schema retrieved.','metadata_only'=>true));
$native_events=get_post_meta(1,'bhranti_events',true);$last=end($native_events);
ac_check('metadata'===end($run['receipts'])['operation'] && true===$last['metadata_only'] && 'read'===$last['operation'],'Inspecting a POST schema stays metadata/read and never looks like a site mutation.');
$run=tc_reset();$receipts=array();
for($i=1;$i<=1000;$i++){$run['step']=$i;$receipts[]=ac_private('action_receipt',$run,$read,tc_result());}
$run['receipts']=$receipts;update_post_meta(1,'bhranti_run',wp_slash($run));
$last_page=tc_tool('GET',array('page'=>50,'per_page'=>20));
ac_check(1000===$last_page['total'] && 20===count($last_page['receipts']) && 1000===end($last_page['receipts'])['step'],'The full permitted 1000-step receipt set remains exact and paginated.');
ac_check(true===Bhranti_Task::archive(1,$run),'A complete permitted receipt set can be retained across a new run.');

$run=tc_reset();$run=tc_settle($read,new WP_Error('oversized_native_error',str_repeat('private-error-',6000)));
$encoded_error=end($run['history'])['content'];$error_feedback=json_decode(substr($encoded_error,strlen('WordPress tool result (untrusted site data): ')),true);
ac_check(false===$error_feedback['success'] && 'oversized_native_error'===$error_feedback['receipt']['code'] && true===$error_feedback['truncated'],'Compacting an oversized error preserves failure and exact code, never turns it into success.');
$bad_run=$run;$bad_run['receipts'][0]['raw_content']='must not become retained evidence';
update_post_meta(1,'bhranti_run',wp_slash($bad_run));
ac_check(array()===Bhranti_Task::receipts(1,$run['id']) && is_wp_error(Bhranti_Task::archive(1,$bad_run)),'Malformed persisted receipts cannot become model evidence or archived raw content.');
$settings=array('tool'=>'wp_request','args'=>array('method'=>'POST','path'=>'/wp/v2/settings','params'=>array('title'=>'Fixture')));
$run=tc_reset();$setting_result=array('summary'=>'Site settings updated.','data'=>array('title'=>'Fixture'),'target'=>array('id'=>0,'type'=>'settings','url'=>'https://example.test/wp-admin/options-general.php'));
$run=tc_settle($settings,$setting_result);$write_ref=end($run['receipts'])['id'];$settings['args']['method']='GET';$run=tc_settle($settings,$setting_result);$read_ref=end($run['receipts'])['id'];
$notes=tc_notes();$notes['checks'][0]['status']='read_back';$notes['checks'][0]['evidence']=array($write_ref,$read_ref);
ac_check(!is_wp_error(tc_tool('POST',$notes)),'The exact native singleton settings route can support saved-state readback without inventing a post ID.');

// Aggregate archive bytes are bounded because native metadata caches whole posts.
$run=tc_reset();$bulky=array();
for($i=1;$i<=1000;$i++){$run['step']=$i;$record=ac_private('action_receipt',$run,$read,tc_result());$record['target']['url']='https://example.test/'.str_repeat('a',1800);$bulky[]=$record;}
$run['receipts']=$bulky;update_post_meta(1,'bhranti_run',wp_slash($run));
ac_check(true===Bhranti_Task::archive(1,$run),'One valid large run can be archived within the conversation byte budget.');
$prior_id=$run['id'];$run['id']=wp_generate_uuid4();foreach($run['receipts'] as &$receipt){$receipt['run_id']=$run['id'];$receipt['id']=$run['id'].':'.$receipt['step'];}unset($receipt);
update_post_meta(1,'bhranti_run',wp_slash($run));$retained_index=get_post_meta(1,'bhranti_receipt_runs',true);
ac_check(is_wp_error(Bhranti_Task::archive(1,$run)) && $retained_index===get_post_meta(1,'bhranti_receipt_runs',true) && 1000===count(Bhranti_Task::receipts(1,$prior_id)) && 1000===count(get_post_meta(1,'bhranti_run',true)['receipts']),'Aggregate archive byte cap refuses rollover before changing the index and retains both completed runs.');

// Readback must retrieve the fields actually written, with raw native content.
$run=tc_reset();$write_content=$write;$write_content['args']['params']['content']='Saved body.';
$run=tc_settle($write_content,tc_result());$written=end($run['receipts'])['id'];
$id_only=tc_result();$id_only['data']=array('id'=>20);$run=tc_settle($read,$id_only);
$notes=tc_notes();$notes['checks'][0]['status']='read_back';$notes['checks'][0]['evidence']=array($written,end($run['receipts'])['id']);
ac_check(is_wp_error(tc_tool('POST',$notes)),'An id-only GET cannot satisfy a check for written title and content.');
$view_only=tc_result();$view_only['data']['title']=array('rendered'=>'Private title');$view_only['data']['content']=array('rendered'=>'Saved body.');$run=tc_settle($read,$view_only);
$notes['checks'][0]['evidence'][1]=end($run['receipts'])['id'];
ac_check(is_wp_error(tc_tool('POST',$notes)),'Rendered-only title/content do not prove their saved raw fields were retrieved.');
$run=tc_settle($read,tc_result(20,'Saved body.'));$notes['checks'][0]['evidence'][1]=end($run['receipts'])['id'];
ac_check(!is_wp_error(tc_tool('POST',$notes)),'A later exact edit-context title/content read covers the actual written fields.');
$style_write=$write;$style_write['args']['params']=array('styles'=>array('color'=>array('background'=>'#fff'),'typography'=>array('fontSize'=>'18px')));
$run=tc_reset();$style_result=tc_result();$style_result['data']['styles']=$style_write['args']['params']['styles'];
$run=tc_settle($style_write,$style_result);$written=end($run['receipts'])['id'];unset($style_result['data']['styles']['typography']);$run=tc_settle($read,$style_result);
$notes=tc_notes();$notes['checks'][0]['status']='read_back';$notes['checks'][0]['evidence']=array($written,end($run['receipts'])['id']);
ac_check(is_wp_error(tc_tool('POST',$notes)),'A partial styles read cannot cover a different nested style field written by the action.');
$style_result['data']['styles']=$style_write['args']['params']['styles'];$run=tc_settle($read,$style_result);$notes['checks'][0]['evidence'][1]=end($run['receipts'])['id'];
ac_check(!is_wp_error(tc_tool('POST',$notes)),'All written nested style fields can be covered without retaining their values in receipts.');

$run=tc_reset();$definition=array('title'=>'Contact','fields'=>array(array('name'=>'email','type'=>'email','label'=>'Email','required'=>true)),'confirmation'=>'Request received.');
$form_write=array('tool'=>'form_request','args'=>array('method'=>'POST','path'=>'/contact-form-7/v1/contact-forms/15','params'=>$definition));
$form_result=array('summary'=>'Native form saved.','data'=>array('id'=>15,'title'=>'Contact','fields'=>array(array('name'=>'email','type'=>'email','required'=>true)),'definition'=>$definition),'target'=>array('id'=>15,'type'=>'wpcf7_contact_form'));
$run=tc_settle($form_write,$form_result);$written=end($run['receipts'])['id'];$form_read=$form_write;$form_read['args']['method']='GET';unset($form_read['args']['params']);$run=tc_settle($form_read,$form_result);
$notes=tc_notes();$notes['checks'][0]['status']='read_back';$notes['checks'][0]['evidence']=array($written,end($run['receipts'])['id']);
ac_check(!is_wp_error(tc_tool('POST',$notes)),'The native managed-form definition projection covers reviewed form fields without exposing submissions.');
// Saved-source inspection is a real read, but its selected outline is not raw-field evidence.
$run = tc_reset();
$run = tc_settle( $write_content, tc_result() );
$written = end( $run['receipts'] )['id'];
$inspect_command = array( 'tool' => 'bhranti_inspect', 'args' => array( 'resource' => 'content', 'path' => '/wp/v2/pages/20' ) );
$outline_result = tc_result();
$outline_result['metadata_only'] = false;
$outline_result['data'] = array( 'revision' => str_repeat( 'a', 64 ), 'block_count' => 2, 'outline' => array( array( 'name' => 'core/paragraph' ) ), 'selected' => array( 'content' => '<p>A selected paragraph only.</p>' ) );
$run = tc_settle( $inspect_command, $outline_result );
$outline_receipt = end( $run['receipts'] );
$events = get_post_meta( 1, 'bhranti_events', true );
ac_check( 'read' === $outline_receipt['operation'] && empty( end( $events )['metadata_only'] ) && 'read' === end( $events )['operation'], 'A successful saved-content inspection is a visible native read, not metadata or a write.' );
ac_check( false === $outline_receipt['coverage'] && array() === $outline_receipt['fields'], 'A selected block outline carries no full raw-field coverage claim.' );
$notes = tc_notes(); $notes['checks'][0]['status'] = 'read_back'; $notes['checks'][0]['evidence'] = array( $written, $outline_receipt['id'] );
ac_check( is_wp_error( tc_tool( 'POST', $notes ) ), 'A bounded content inspection cannot satisfy a full saved-content readback check.' );
foreach ( array( 'route', 'images' ) as $resource ) {
    $command = array( 'tool' => 'bhranti_inspect', 'args' => array( 'resource' => $resource ) );
    $run = tc_settle( $command, array( 'summary' => 'Metadata retrieved.', 'metadata_only' => true ) );
    ac_check( 'metadata' === end( $run['receipts'] )['operation'], 'Route and image-capability inspection stay metadata-only.' );
}

// Partial completion retains truthful saved-item links independently of model claims.
$run = tc_reset();
$saved_result = tc_result();
$saved_result['target']['url'] = admin_url( 'post.php?post=20&action=edit' );
$saved_result['target']['title'] = 'A "Draft" & <b>Title</b><script>unwanted()</script>';
$run = tc_settle( $write, $saved_result );
$notes = tc_tool( 'POST', tc_notes() );
$run['task_touched'] = true; update_post_meta( 1, 'bhranti_run', wp_slash( $run ) );
ac_step( $run['id'], ac_envelope( 'reply', 'All done. Fully accessible and visually verified.' ) );
$partial = ac_step( $run['id'], ac_envelope( 'reply', 'All done. Fully accessible and visually verified.' ) );
$partial_text = end( $partial['messages'] )['content'];
ac_check( 'incomplete' === $partial['run']['status'] && false !== strpos( $partial_text, 'Saved items:' ) && false !== strpos( $partial_text, 'post=20&#038;action=edit' ) && false !== strpos( $partial_text, 'Prepare the main page' ), 'The real partial-completion branch preserves the saved draft editor link alongside authoritative unfinished outcomes.' );
ac_check( false === strpos( $partial_text, 'Fully accessible' ) && false === strpos( $partial_text, 'All done.' ) && false === strpos( $partial_text, '<script' ) && false === strpos( $partial_text, 'unwanted()' ) && false !== strpos( $partial_text, '&amp;' ) && false !== strpos( $partial_text, '&quot;Draft&quot;' ), 'Saved titles use native HTML escaping and the final message does not repeat false model completion claims.' );
$base_receipt = $run['receipts'][0];
$only_reads = $run; $only_reads['receipts'] = array();
foreach ( array( 'read', 'metadata' ) as $operation ) { $receipt = $base_receipt; $receipt['operation'] = $operation; $only_reads['receipts'][] = $receipt; }
$receipt = $base_receipt; $receipt['success'] = false; $receipt['code'] = 'uncertain_action'; $only_reads['receipts'][] = $receipt;
$receipt = $base_receipt; $receipt['run_id'] = wp_generate_uuid4(); $only_reads['receipts'][] = $receipt;
$receipt = $base_receipt; $receipt['method'] = 'DELETE'; $only_reads['receipts'][] = $receipt;
ac_check( '' === ac_private( 'saved_items', $only_reads ), 'Reads, metadata, uncertain/failed results, another run and deletions cannot produce saved-item success links.' );
foreach ( array( 'javascript:alert(1)', 'https://other.example/wp-admin/post.php?post=20', 'https://user:secret@bhranti.wp.local/wp-admin/post.php', 'https://bhranti.wp.local/not-admin/post.php', "https://bhranti.wp.local/wp-admin/post.php\n?post=20", 'https://bhranti.wp.local:8443/wp-admin/post.php' ) as $unsafe_url ) {
    $invalid = $run; $receipt = $base_receipt; $receipt['target']['url'] = $unsafe_url; $invalid['receipts'] = array( $receipt );
    ac_check( '' === ac_private( 'saved_items', $invalid ), 'Invalid or non-native admin target URLs are omitted before chat rendering.' );
}
$bounded = $run; $bounded['receipts'] = array();
for ( $i = 30; $i <= 34; ++$i ) {
    $receipt = $base_receipt; $receipt['target']['url'] = admin_url( 'post.php?post=' . $i . '&action=edit' ); $receipt['target']['title'] = 'Saved target ' . $i;
    $receipt['operation'] = 34 === $i ? 'image' : 'write'; $bounded['receipts'][] = $receipt;
}
$bounded['receipts'][] = end( $bounded['receipts'] );
$links = ac_private( 'saved_items', $bounded );
ac_check( 3 === substr_count( $links, '<a href=' ) && false !== strpos( $links, 'Saved target 34' ) && false !== strpos( $links, 'Saved target 32' ) && false === strpos( $links, 'Saved target 31' ), 'Saved links select at most three unique recent successful writes/images without duplicate entries.' );

// Later successful Trash invalidates an earlier saved link, including the core status alias.
foreach ( array( 'DELETE', 'POST', 'PATCH', 'PUT' ) as $method ) {
    $run = tc_reset();
    $run = tc_settle( $write, $saved_result );
    $delete = $write; $delete['args']['method'] = $method;
    $delete['args']['params'] = 'DELETE' === $method ? array() : array( 'status' => 'trash' );
    $trashed_result = $saved_result; $trashed_result['target']['url'] = admin_url( 'edit.php?post_status=trash&post_type=page' );
    $run = tc_settle( $delete, $trashed_result );
    ac_check( 'DELETE' === end( $run['receipts'] )['method'] && '' === ac_private( 'saved_items', $run ), 'A successful native Trash, including POST/PUT/PATCH aliases, suppresses its old saved editor link.' );
}
$run = tc_reset(); $run = tc_settle( $write, $saved_result );
$run = tc_settle( $delete, new WP_Error( 'denied_delete', 'Deletion was not approved.' ) );
ac_check( false !== strpos( ac_private( 'saved_items', $run ), 'post=20' ), 'A rejected deletion does not hide an earlier successfully saved item.' );
$run = tc_reset(); $run = tc_settle( $write, $saved_result );
$run = tc_settle( $delete, $trashed_result );
$restore = $write; $restore['args']['params'] = array( 'status' => 'draft' );
$run = tc_settle( $restore, $saved_result );
ac_check( false !== strpos( ac_private( 'saved_items', $run ), 'post=20' ), 'A later successful restoration may expose its current saved editor link.' );

// Loaded specialist methods survive ordinary history compaction within this run only.
$run = tc_reset();
$skill_command = array( 'tool' => 'bhranti_skill', 'args' => array( 'name' => 'wordpress-support' ) );
$skill_document = Bhranti_Skills::read( 'wordpress-support' );
$skill_result = array( 'summary' => 'Specialist WordPress guidance loaded.', 'data' => $skill_document, 'metadata_only' => true, 'rollback_available' => false );
$run = tc_settle( $skill_command, $skill_result );
ac_check( array( 'wordpress-support' => $skill_document['revision'] ) === ( $run['loaded_skills'] ?? null ), 'A successful native skill selection retains only its fixed name and verified revision.' );
for ( $i = 0; $i < 7; ++$i ) { $run = tc_settle( $read, tc_result() ); }
ac_check( false === strpos( wp_json_encode( $run['history'] ), 'wordpress-support' ), 'The original specialist tool exchange has actually left the bounded six-group history.' );
$GLOBALS['ac']['reply'] = ac_envelope( 'tool', 'Read the current page.', 'wp_request', wp_json_encode( $read['args'] ) );
$reply = ac_private( 'ask', get_post( 1 ), $run );
ac_check( ! is_wp_error( $reply ) && 'wp_request' === ac_private( 'parse', $reply )['tool'] && false !== strpos( $GLOBALS['ac']['instruction'], $skill_document['content'] ), 'The next native prompt still contains the complete verified method without another skill call.' );
$other_document = Bhranti_Skills::read( 'commerce-workflow' );
ac_check( false === strpos( $GLOBALS['ac']['instruction'], $other_document['content'] ), 'An unrelated specialist is not loaded automatically.' );
$run = tc_settle( $skill_command, $skill_result );
ac_check( 1 === count( $run['loaded_skills'] ), 'A repeated successful lookup does not duplicate the retained selection.' );
$baseline = Bhranti_Skills::read( 'site-design' );
$baseline_command = $skill_command; $baseline_command['args']['name'] = 'site-design';
$baseline_result = $skill_result; $baseline_result['data'] = $baseline;
$run = tc_settle( $baseline_command, $baseline_result );
ac_private( 'ask', get_post( 1 ), $run );
ac_check( 1 === count( $run['loaded_skills'] ) && 1 === substr_count( $GLOBALS['ac']['instruction'], $baseline['content'] ), 'The required design baseline remains supplied once, outside optional retained selections.' );
foreach ( array( 'design-composition', 'content-operations', 'commerce-workflow' ) as $name ) {
    $command = $skill_command; $command['args']['name'] = $name;
    $result = $skill_result; $result['data'] = Bhranti_Skills::read( $name );
    $run = tc_settle( $command, $result );
}
ac_check( 4 === count( $run['loaded_skills'] ) && strlen( wp_json_encode( $run['loaded_skills'] ) ) < 400, 'All four optional selections remain a small bounded identity map without document bodies.' );
$selected_documents = ac_private( 'loaded_skill_documents', $run );
ac_check( 4 === count( $selected_documents ) && array_sum( array_map( static function ( $document ) { return strlen( $document['content'] ); }, $selected_documents ) ) <= 4 * Bhranti_Skills::LIMIT, 'Retained prompt documents obey the existing fixed per-document bound.' );
foreach ( array(
    null,
    'wordpress-support',
    array( '../wordpress-support' => $skill_document['revision'] ),
    array( 'wordpress-support' => 'invalid' ),
    array( 'wordpress-support' => str_repeat( '0', 64 ) ),
    array( 'wordpress-support' => array( 'revision' => $skill_document['revision'], 'content' => 'Injected instructions.' ) ),
    $run['loaded_skills'] + array( 'site-design' => $baseline['revision'] ),
) as $invalid_selection ) {
    $invalid_run = $run; $invalid_run['loaded_skills'] = $invalid_selection;
    $calls = $GLOBALS['ac']['calls'];
    $result = ac_private( 'ask', get_post( 1 ), $invalid_run );
    ac_check( is_wp_error( $result ) && 'bhranti_skill_revision' === $result->get_error_code() && $calls === $GLOBALS['ac']['calls'], 'Invalid, unknown, changed or excessive selections fail closed before a model request.' );
}
$finished = ac_step( $run['id'], ac_envelope( 'reply', 'The requested inspection is complete.' ) );
$new_request = ac_start( 'What is this page title?' );
$new_run = get_post_meta( 1, 'bhranti_run', true );
ac_check( ! is_wp_error( $finished ) && ! is_wp_error( $new_request ) && $new_run['id'] !== $run['id'] && ! isset( $new_run['loaded_skills'] ), 'A new focused request in the same conversation starts without inherited specialist selections.' );
ac_private( 'ask', get_post( 1 ), $new_run );
ac_check( false === strpos( $GLOBALS['ac']['instruction'], $skill_document['content'] ), 'The focused new request contains no retained optional specialist noise.' );
$run = tc_reset();
$run = tc_settle( $skill_command, new WP_Error( 'bhranti_skill_unavailable', 'Bundled guidance is unavailable.' ) );
ac_check( empty( $run['loaded_skills'] ) && false === end( $run['receipts'] )['success'], 'A failed native skill result never selects its document.' );
foreach ( array( 'name', 'revision', 'content' ) as $field ) {
    $run = tc_reset();
    $forged = $skill_result;
    $forged['data'][ $field ] = 'Forged native result';
    $run = tc_settle( $skill_command, $forged );
    ac_check( empty( $run['loaded_skills'] ) && false === end( $run['receipts'] )['success'] && 'bhranti_skill_revision' === end( $run['receipts'] )['code'] && false === strpos( wp_json_encode( $run['history'] ), 'Forged native result' ), 'A mismatched successful result cannot retain a name, revision or substituted document body.' );
}
$run = tc_reset();
$unknown_command = $skill_command; $unknown_command['args']['name'] = '../wordpress-support';
$run = tc_settle( $unknown_command, $skill_result );
ac_check( empty( $run['loaded_skills'] ) && false === end( $run['receipts'] )['success'], 'An unregistered command name cannot select a genuine result under another identity.' );

echo 'PASS ' . ($GLOBALS['ac']['checks']-$initial_checks) . " durable task/context checks; native formatting and actual orchestration, in-memory storage only.\n";
