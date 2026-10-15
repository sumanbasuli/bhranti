<?php
/**
 * Exact owned render-job/evidence protocol in the disposable native WP fixture.
 * Synthetic observations test validation; actual browser behavior is tested separately.
 * BHRANTI_RECOVERY_NATIVE_WP_ROOT=/private/tmp/bhranti-recovery-native-qa-20261015 php tests/render-check-native.php
 *
 * @package Bhranti
 */
function rc_report() {
    $profiles = array();
    foreach ( Bhranti_Render::viewports() as $viewport ) {
        $profiles[] = $viewport + array( 'violations'=>array(), 'incomplete'=>array(), 'passes'=>31, 'inapplicable'=>29, 'layout'=>array('document_width'=>$viewport['width'],'viewport_width'=>$viewport['width'],'overflow'=>array(),'overflow_count'=>0), 'images'=>array('checked'=>0,'broken'=>array(),'broken_count'=>0), 'anchors'=>array('checked'=>1,'missing'=>array(),'missing_count'=>0,'duplicates'=>array(),'duplicate_count'=>0), 'headings'=>array(array('level'=>1,'text'=>'Native fixture')), 'limitations'=>array('automated_only','no_interaction','focus_not_tested') );
    }
    return array('status'=>'complete','engine'=>'axe-core','version'=>Bhranti_Render::ENGINE_VERSION,'profiles'=>$profiles);
}
function rc_submit( $tuple, $job, $report, $extra = array() ) {
    return lr_rest('POST','/sessions/'.$tuple[0].'/render-result',array_merge(array('run_id'=>$tuple[1],'prepared_step'=>$job['step'],'job_id'=>$job['id'],'report'=>$report),$extra));
}
/** Exercise the actual native frontend gate without an HTTP request or page rendering. */
function rc_frame_bootstrap( $tuple, $page, $job ) {
    global $wp_query, $wp_scripts, $wp_filter;
    $saved_query=$wp_query; $saved_scripts=$wp_scripts ?? null; $saved_get=$_GET; $saved_user=get_current_user_id();
    $stored=lr_state($tuple); $property=new ReflectionProperty(Bhranti_Render::class,'frame_job'); $property->setAccessible(true);
    $hooks=array(); foreach(array('wp_enqueue_scripts','redirect_canonical','show_admin_bar') as $name){$hooks[$name]=isset($wp_filter[$name])?clone $wp_filter[$name]:null;}
    try {
        $wp_query=new WP_Query(array('page_id'=>$page,'post_type'=>'page','post_status'=>'draft'));
        lr_check(is_singular('page') && get_queried_object_id()===$page,'The fixture uses the actual native singular draft query.');
        foreach(array('ordinary','job','owner','phase','step','source','environment','expired','valid') as $kind){
            $property->setValue(null,null); $wp_scripts=new WP_Scripts();
            foreach($hooks as $name=>$hook){if(null===$hook)unset($wp_filter[$name]);else $wp_filter[$name]=clone $hook;}
            $_GET=array('bhranti_render'=>$tuple[0],'bhranti_render_job'=>$job['id']); $candidate=$stored; wp_set_current_user($saved_user);
            if('ordinary'===$kind)$_GET=array();
            if('job'===$kind)$_GET['bhranti_render_job']=wp_generate_uuid4();
            if('owner'===$kind)wp_set_current_user($GLOBALS['lr_actor']);
            if('phase'===$kind)$candidate['phase']='prepared';
            if('step'===$kind)$candidate['step']++;
            if('source'===$kind)$candidate['render_job']['revision']=str_repeat('0',64);
            if('environment'===$kind)$candidate['render_job']['environment_revision']=str_repeat('0',64);
            if('expired'===$kind)$candidate['render_job']['expires']=time()-1;
            update_post_meta($tuple[0],'bhranti_run',wp_slash($candidate));
            Bhranti_Render::frame(); Bhranti_Render::enqueue_frame();
            ob_start(); Bhranti_Render::marker(); $marker=ob_get_clean();
            if('valid'!==$kind){lr_check(!Bhranti_Render::is_frame() && !wp_script_is('bhranti-render','enqueued') && ''===$marker,'A noncurrent/unowned ordinary native preview receives no worker, bootstrap or marker: '.$kind);continue;}
            lr_check(Bhranti_Render::is_frame() && wp_script_is('bhranti-render','enqueued') && '<meta name="bhranti-render-check" content="'.$job['marker'].'">'===$marker,'Only the exact owned active browser job enqueues measurement and retains its exact marker.');
            $script=$wp_scripts->registered['bhranti-render']; $inline=array_values(array_filter($script->extra['before'],'is_string'));
            lr_check(1===count($inline) && 0===strpos($inline[0],'window.bhrantiRenderFrame = '),'The native worker has one explicitly scoped JSON bootstrap.');
            $config=json_decode(substr($inline[0],strlen('window.bhrantiRenderFrame = '),-1),true);
            lr_check(array('job','renderCheck')===array_keys($config) && array('axeUrl','version')===array_keys($config['renderCheck']),'Bootstrap contains only the public job and fixed local engine metadata.');
            lr_check(array('id','run_id','step','path','post_id','url','revision','environment_revision','marker','expires','viewports','engine','version')===array_keys($config['job']),'Private conversation, task, requester, provider and REST-authentication state never enter the preview.');
            lr_check($job['id']===$config['job']['id'] && $job['marker']===$config['job']['marker'] && plugins_url('assets/vendor/axe-core/axe.js',BHRANTI_FILE)===$config['renderCheck']['axeUrl'] && Bhranti_Render::ENGINE_VERSION===$config['renderCheck']['version'],'Worker binding and engine URL come from the current stored native job and installed asset.');
            lr_check(plugins_url('assets/render-check.js',BHRANTI_FILE)===$script->src && BHRANTI_VERSION===$script->ver && array('wp-i18n')===$script->deps,'Native script loading uses only the installed versioned worker and WordPress translations.');
        }
    } finally {
        $property->setValue(null,null); update_post_meta($tuple[0],'bhranti_run',wp_slash($stored));
        $wp_query=$saved_query; $wp_scripts=$saved_scripts; $_GET=$saved_get; wp_set_current_user($saved_user);
        foreach($hooks as $name=>$hook){if(null===$hook)unset($wp_filter[$name]);else $wp_filter[$name]=$hook;}
    }
}
function bhranti_native_recovery_scenario() {
    $tuple=lr_start('render-check');
    $source='<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Native fixture</h1><!-- /wp:heading -->';
    $run=lr_tool($tuple,'wp_request',array('method'=>'POST','path'=>'/wp/v2/pages','params'=>array('title'=>$GLOBALS['lr_marker'].' rendered','status'=>'draft','content'=>$source)));
    $write=lr_receipt($run,'write');$page=$write['target']['id'];$GLOBALS['lr_pages'][]=$page;
    $command=array('resource'=>'rendered','path'=>'/wp/v2/pages/'.$page);
    $prepared=lr_model($tuple,lr_plan('bhranti_inspect',$command));$run=lr_state($tuple);if(!isset($run['render_job'])){fwrite(STDERR,wp_json_encode(array('receipt'=>end($run['receipts']),'events'=>array_slice(get_post_meta($tuple[0],'bhranti_events',true),-1)))."\n");}lr_check(!is_wp_error($prepared) && isset($run['render_job']),'A native planning turn prepares the actual owned browser job.');$job=$run['render_job'];
    lr_check('browser'===$run['phase'] && 'running'===$run['status'] && count($run['receipts'])===1,'Preparing a browser observation creates no fabricated successful read.');
    lr_check(!wp_next_scheduled('bhranti_run_tick',$tuple),'Browser wait clears native scheduling instead of looping AI calls.');
    $calls=$GLOBALS['lr_text_calls'];$step=$run['step'];lr_tick($tuple);
    lr_check($GLOBALS['lr_text_calls']===$calls && lr_state($tuple)['step']===$step,'Cron leaves the exact browser job and model step intact.');
    $start=array('run_id'=>$tuple[1],'prepared_step'=>$step,'job_id'=>$job['id']);
    $reply=lr_rest('POST','/sessions/'.$tuple[0].'/render-start',$start);
    lr_check(200===$reply->get_status() && $reply->get_data()['run']['render_job']['id']===$job['id'],'The current owned browser job is stable across claims.');
    rc_frame_bootstrap($tuple,$page,$job);
    $report=rc_report();
    foreach(array('extra','version','profiles','counts','overflow','html') as $kind){
        $bad=$report;
        if('extra'===$kind)$bad['passed']=true;
        if('version'===$kind)$bad['version']='unreviewed';
        if('profiles'===$kind)array_pop($bad['profiles']);
        if('counts'===$kind)$bad['profiles'][0]['images']['broken_count']=-1;
        if('overflow'===$kind)$bad['profiles'][0]['layout']['overflow']=array('html');
        if('html'===$kind)$bad['profiles'][0]['html']='<input value="secret">';
        $reply=rc_submit($tuple,$job,$bad);
        lr_check(400===$reply->get_status() && 'browser'===lr_state($tuple)['phase'],'Invalid report cannot settle an owned job: '.$kind);
    }
    $wrong=$job;$wrong['id']=wp_generate_uuid4();
    lr_check(409===rc_submit($tuple,$wrong,$report)->get_status(),'A different job cannot submit a report.');
    wp_set_current_user($GLOBALS['lr_actor']);
    $denied=rc_submit($tuple,$job,$report)->get_status();lr_check($denied>=400 && $denied<500,'The managed actor cannot impersonate the human browser reporter.');
    wp_set_current_user($GLOBALS['lr_owner']);
    $expired=lr_state($tuple);$expired['render_job']['expires']=time()-1;update_post_meta($tuple[0],'bhranti_run',wp_slash($expired));
    $reply=lr_rest('POST','/sessions/'.$tuple[0].'/render-start',$start);
    lr_check(200===$reply->get_status(),'A reopened expired job renews through the native owned endpoint.');
    $renewed=lr_state($tuple)['render_job'];
    lr_check($renewed['id']!==$job['id'] && $renewed['marker']!==$job['marker'] && $renewed['step']===$step && $GLOBALS['lr_text_calls']===$calls,'Renewal changes only job identity, not model step or completed work.');
    lr_check(409===rc_submit($tuple,$job,$report)->get_status(),'A late old-job report cannot settle the renewed job.');
    $job=$renewed;
    $result=Bhranti_Render::result($job,$report,$GLOBALS['lr_owner'],$tuple[0]);
    lr_check(!is_wp_error($result) && $result['render_check']['passed'] && Bhranti_Render::valid_receipt($result['render_check']),'Complete consistent synthetic observations produce only the explicitly scoped automated evidence.');
    foreach(array('violation','incomplete','reflow','image','anchor','limit','empty') as $kind){
        $bad=$report;
        if('violation'===$kind)$bad['profiles'][0]['violations'][]=array('id'=>'image-alt','impact'=>'serious','description'=>'Images need an alternative.','nodes'=>array());
        if('incomplete'===$kind)$bad['profiles'][0]['incomplete'][]=array('id'=>'color-contrast','impact'=>'serious','description'=>'Review contrast.','nodes'=>array());
        if('reflow'===$kind)$bad['profiles'][2]['layout']['document_width']=900;
        if('image'===$kind)$bad['profiles'][0]['images']=array('checked'=>1,'broken'=>array('img'),'broken_count'=>1);
        if('anchor'===$kind)$bad['profiles'][0]['anchors']['missing_count']=1;
        if('limit'===$kind)$bad['profiles'][0]['limitations'][]='findings_limit';
        if('empty'===$kind)$bad['profiles'][0]['headings']=array();
        $finding=Bhranti_Render::result($job,$bad,$GLOBALS['lr_owner'],$tuple[0]);
        lr_check(!is_wp_error($finding) && !$finding['render_check']['passed'],'A real finding/limit cannot become passing evidence: '.$kind);
    }
    $reply=rc_submit($tuple,$job,$report);lr_check(200===$reply->get_status(),'The exact report settles through the native run endpoint.');
    $settled=lr_state($tuple);$receipt=end($settled['receipts']);
    lr_check(''===$settled['phase'] && !isset($settled['render_job']) && count($settled['receipts'])===2 && $receipt['render_check']['passed'] && !$receipt['coverage'],'Rendered evidence is one settled read receipt without raw native coverage.');
    $reply=rc_submit($tuple,$job,$report);lr_check(200===$reply->get_status() && lr_state($tuple)['receipts']===$settled['receipts'],'Lost report acknowledgments reconcile without repeating or fabricating receipts.');
    $notes=array('revision'=>'initial','objective'=>'Verify only the implemented browser observations.','decisions'=>array('Synthetic report fixture, never a real browser audit or full WCAG certification.'),'outcomes'=>array(array('id'=>'draft','title'=>'Save the owned draft','status'=>'completed','evidence'=>array($write['id']))),'checks'=>array(array('id'=>'render','title'=>'Automated desktop/mobile rendered checks','status'=>'rendered_check','evidence'=>array($receipt['id']))));
    lr_tool($tuple,'bhranti_task',array('method'=>'POST','params'=>$notes));
    lr_check(!Bhranti_Task::remaining($tuple[0]),'Fresh exact browser evidence closes the scoped automated check.');
    $events=get_post_meta($tuple[0],'bhranti_events',true);$encoded=wp_json_encode($events);
    lr_check(false!==strpos($encoded,'render_report') && false===strpos($encoded,$job['marker']) && false===strpos($encoded,'bhranti_render_job'),'Private events retain the report without preview tokens or job URLs.');
    wp_update_post(array('ID'=>$page,'post_content'=>$source.'<!-- wp:paragraph --><p>Changed.</p><!-- /wp:paragraph -->'));
    lr_check(!Bhranti_Render::valid_evidence($receipt,$GLOBALS['lr_owner'],$tuple[0]) && Bhranti_Task::remaining($tuple[0]),'Changed saved source invalidates the prior passing observation.');
    lr_check(0===$GLOBALS['lr_http'] && 0===$GLOBALS['lr_mail'] && 0===$GLOBALS['lr_image_calls'],'Protocol tests use no external AI, browser, image, HTTP or mail.');
}
// Match the isolated native HTTPS origin for permalink/admin URL generation.
$_SERVER['HTTPS']='on';
require __DIR__.'/large-task-recovery.php';
