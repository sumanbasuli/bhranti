<?php
/** Focused raw-history/rendered-response regression; exact site and offline only. */
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ProviderRegistry;
define('BHRANTI_QA_SUPPORT_ONLY',true); require __DIR__.'/integration.php';
$rm_checks=0; $rm_user=0; $rm_session=0; $rm_http=0; $rm_failure=null; $rm_clean=true; $rm_original_user=get_current_user_id();
$rm_marker='bhranti-render-qa-'.str_replace('-','',wp_generate_uuid4()); $rm_checkpoint='/private/tmp/'.$rm_marker.'.json';
function rm_check($condition,$label){if(!$condition){throw new RuntimeException('FAIL: '.$label);}++$GLOBALS['rm_checks'];}
function rm_rest($method,$path,$body=array()){$r=new WP_REST_Request($method,'/bhranti/v1'.$path);$r->set_body_params($body);return rest_do_request($r);}
function rm_raw($messages){return array_map(static function($m){unset($m['rendered']);return $m;},$messages);}
function rm_baseline(){global $wpdb;return array(
 'posts'=>$wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_type NOT IN ('bhranti_session','bhranti_event') ORDER BY ID",ARRAY_A),
 'meta'=>$wpdb->get_results("SELECT m.* FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON m.post_id=p.ID WHERE p.post_type NOT IN ('bhranti_session','bhranti_event') ORDER BY meta_id",ARRAY_A),
 'comments'=>$wpdb->get_results("SELECT * FROM {$wpdb->comments} ORDER BY comment_ID",ARRAY_A),
 'relationships'=>$wpdb->get_results("SELECT * FROM {$wpdb->term_relationships} ORDER BY object_id,term_taxonomy_id",ARRAY_A),
 'options'=>$wpdb->get_results("SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN ('bhranti_settings','bhranti_actor_id','bhranti_role_owned','wp_user_roles','active_plugins','stylesheet','template') ORDER BY option_name",ARRAY_A),
 'actor'=>$wpdb->get_row("SELECT * FROM {$wpdb->users} WHERE ID=11",ARRAY_A),
 'actor_meta'=>$wpdb->get_results("SELECT * FROM {$wpdb->usermeta} WHERE user_id=11 ORDER BY umeta_id",ARRAY_A),
);}
function rm_checkpoint(){global $rm_checkpoint,$rm_marker,$rm_user,$rm_session,$rm_before; $data=wp_json_encode(array('site'=>home_url('/'),'marker'=>$rm_marker,'user'=>$rm_user,'session'=>$rm_session,'baseline_hashes'=>array_map(static fn($v)=>hash('sha256',wp_json_encode($v)),$rm_before)));$pending=$rm_checkpoint.'.pending';$h=fopen($pending,'x');if(!$h||!chmod($pending,0600)){throw new RuntimeException('Cannot create private checkpoint.');}try{if(strlen($data)!==fwrite($h,$data)||!fflush($h)){throw new RuntimeException('Cannot persist checkpoint.');}}finally{fclose($h);}if(!rename($pending,$rm_checkpoint)){throw new RuntimeException('Cannot replace checkpoint.');}}
if ( defined('BHRANTI_RENDER_QA_SUPPORT_ONLY') && BHRANTI_RENDER_QA_SUPPORT_ONLY ) { return; }
$rm_before=rm_baseline();$rm_prior_ids=array_map('intval',$wpdb->get_col("SELECT ID FROM {$wpdb->posts}"));rm_checkpoint();
$rm_property=new ReflectionProperty(AiClient::class,'defaultRegistry');$rm_property->setAccessible(true);$rm_registry=AiClient::defaultRegistry();$rm_dispatcher=AiClient::getEventDispatcher();$rm_cache=AiClient::getCache();$rm_connector=false;
$rm_http_guard=static function($response,$args,$url){++$GLOBALS['rm_http'];return new WP_Error('bhranti_render_qa_http_denied','No outbound request allowed.');};$rm_runner_veto = bhranti_qa_runner_veto( static function () { return array( (int) ( $GLOBALS['rm_session'] ?? 0 ) ); } );
add_filter('pre_http_request',$rm_http_guard,PHP_INT_MAX,3);add_filter('pre_wp_mail','__return_true',PHP_INT_MAX);
try{
 $rm_property->setValue(null,new ProviderRegistry());AiClient::setEventDispatcher(null);AiClient::setCache(null);AiClient::defaultRegistry()->registerProvider(Bhranti_QA_Provider::class);
 rm_check(null===WP_Connector_Registry::get_instance()->get_registered('bhranti-qa'),'Local fake native connector name unregistered');WP_Connector_Registry::get_instance()->register('bhranti-qa',array('name'=>'Bhranti offline QA','type'=>'ai_provider','authentication'=>array('method'=>'none')));$rm_connector=true;
 $login='bhranti_render_qa_'.str_replace('-','',wp_generate_uuid4());$rm_user=wp_insert_user(array('user_login'=>$login,'user_pass'=>wp_generate_password(),'user_email'=>$login.'@example.test','role'=>'administrator'));rm_check(is_int($rm_user)&&$rm_user>0,'Fresh temporary requester created');rm_checkpoint();wp_set_current_user($rm_user);
 $session=rm_rest('POST','/sessions');rm_check(200===$session->get_status(),'Fresh private chat created');$rm_session=(int)$session->get_data()['id'];rm_checkpoint();
 $first_request='Please respond with literal quoted markup and a small table.';
 $first_reply='<p>Cat "quotes" \\ and <b>literal markup</b>.</p><table><tr><th>Coat</th></tr><tr><td>Tabby</td></tr></table>';
 Bhranti_QA_Model::$responses=array(wp_json_encode(array('type'=>'reply','content'=>$first_reply)));
 $start=rm_rest('POST','/sessions/'.$rm_session.'/messages',array('content'=>$first_request));rm_check(200===$start->get_status()&&!Bhranti_QA_Model::$calls,'First request saved without model call');
 $done=rm_rest('POST','/sessions/'.$rm_session.'/step',array('run_id'=>$start->get_data()['run']['id']));rm_check(200===$done->get_status()&&'complete'===$done->get_data()['run']['status'],'First native reply completed');$messages=$done->get_data()['messages'];
 rm_check($first_request===$messages[0]['content']&&$first_reply===$messages[1]['content'],'Public content retains exact original raw quotes/backslash/HTML');
 rm_check(isset($messages[1]['rendered'])&&!isset($messages[0]['rendered'])&&str_contains($messages[1]['rendered'],'<table'),'Assistant-only response includes safe derived rich table rendering');
 $raw=rm_raw($messages);rm_check($raw===get_post_meta($rm_session,'bhranti_messages',true),'Stored first exchange excludes derived rendering and preserves raw fields');
 $second_request='Continue this existing chat without rewriting the previous raw reply.';$start2=rm_rest('POST','/sessions/'.$rm_session.'/messages',array('content'=>$second_request));rm_check(200===$start2->get_status(),'Next request in same chat accepted');$stored=get_post_meta($rm_session,'bhranti_messages',true);
 rm_check($raw===array_slice($stored,0,2)&&!isset($stored[1]['rendered'])&&$second_request===$stored[2]['content'],'Next request preserves exact older raw history and never persists derived HTML');
 Bhranti_QA_Model::$responses=array(wp_json_encode(array('type'=>'reply','content'=>'Second plain native reply')));$done2=rm_rest('POST','/sessions/'.$rm_session.'/step',array('run_id'=>$start2->get_data()['run']['id']));rm_check('complete'===$done2->get_data()['run']['status'],'Second reply completed through real native SDK');
 $call=Bhranti_QA_Model::$calls[1];$prompt=$call['prompt'];rm_check(3===count($prompt)&&$first_reply===$prompt[1]->getParts()[0]->getText()&&$second_request===$prompt[2]->getParts()[0]->getText(),'Later native model receives exact original raw assistant text rather than derived rendering');
 $final=$done2->get_data()['messages'];rm_check(rm_raw($final)===get_post_meta($rm_session,'bhranti_messages',true)&&isset($final[1]['rendered'],$final[3]['rendered']),'Second save keeps all raw history clean while rendering responses independently');
 rm_check(''===get_post_field('post_content',$rm_session),'Private message storage remains outside executable WordPress post content');rm_check(0===$rm_http,'Focused native rendering workflow remains fully offline');
}catch(Throwable $e){$rm_failure=$e;}
finally{
 if($rm_user){$user=get_user_by('id',$rm_user);$rows=$wpdb->get_results($wpdb->prepare("SELECT ID,post_type,post_status FROM {$wpdb->posts} WHERE post_author=%d",$rm_user),ARRAY_A);$safe=$user&&str_starts_with($user->user_login,'bhranti_render_qa_')&&str_ends_with($user->user_email,'@example.test');foreach($rows as $row){$safe=$safe&&!in_array((int)$row['ID'],$rm_prior_ids,true)&&in_array($row['post_type'],array('bhranti_session','bhranti_event'),true)&&'private'===$row['post_status'];}if($safe){foreach($rows as $row){wp_delete_post((int)$row['ID'],true);}require_once ABSPATH.'wp-admin/includes/user.php';$rm_clean=wp_delete_user($rm_user)&&!get_user_by('id',$rm_user);}else{$rm_clean=false;}}
 wp_set_current_user($rm_original_user);if($rm_connector){WP_Connector_Registry::get_instance()->unregister('bhranti-qa');}$rm_property->setValue(null,$rm_registry);AiClient::setEventDispatcher($rm_dispatcher);AiClient::setCache($rm_cache);remove_filter('pre_http_request',$rm_http_guard,PHP_INT_MAX);remove_filter('pre_wp_mail','__return_true',PHP_INT_MAX);remove_filter( 'pre_schedule_event', $rm_runner_veto, PHP_INT_MAX ); restore_error_handler();
 $rm_after=rm_baseline();$preserved=$rm_before===$rm_after;if($preserved&&$rm_clean){unlink($rm_checkpoint);}echo wp_json_encode(array('checks'=>$rm_checks,'ordinary_content_options_actor_preserved'=>$preserved,'fixtures_removed'=>$rm_clean,'http_calls'=>$rm_http)),"\n";
}
if($rm_failure||!$preserved||!$rm_clean){fwrite(STDERR,$rm_failure?$rm_failure->getMessage()."\n":"Fixture cleanup or state preservation failed.\n");exit(1);}echo 'PASS '.$rm_checks." focused native raw/rendered message checks\n";
