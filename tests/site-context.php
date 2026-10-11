<?php
/** Focused exact-site native theme and captured-context QA; no network or real AI. */
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ProviderRegistry;

define( 'BHRANTI_QA_SUPPORT_ONLY', true );
require __DIR__ . '/integration.php';
if ( ! class_exists( 'Bhranti_Site' ) || ! class_exists( 'Bhranti_Context' ) || 11 !== (int) get_option( 'bhranti_actor_id' ) ) { throw new RuntimeException( 'Deploy the reviewed Site/Context backend before running this suite.' ); }

/** Real native SDK metadata with text+image input, deterministic in-process responses. */
final class Bhranti_Context_QA_Provider extends AbstractProvider {
 protected static function createProviderMetadata(): ProviderMetadata { return new ProviderMetadata( 'bhranti-context-qa', 'Bhranti context local QA', ProviderTypeEnum::server(), null, null ); }
 protected static function createModel( ModelMetadata $metadata, ProviderMetadata $provider ): ModelInterface { return new Bhranti_QA_Model( $metadata, $provider ); }
 protected static function createProviderAvailability(): ProviderAvailabilityInterface { return new class implements ProviderAvailabilityInterface { public function isConfigured(): bool { return true; } }; }
 protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface { return new class implements ModelMetadataDirectoryInterface {
  public function listModelMetadata(): array { return array( $this->getModelMetadata( 'local-vision' ) ); }
  public function hasModelMetadata( string $id ): bool { return 'local-vision' === $id; }
  public function getModelMetadata( string $id ): ModelMetadata { return new ModelMetadata( $id, 'Local captured-context model', array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ), array(
   new SupportedOption( OptionEnum::systemInstruction() ), new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ), new SupportedOption( OptionEnum::outputSchema() ),
   new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ), array( ModalityEnum::text(), ModalityEnum::image() ) ) ),
   new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
  ) ); }
 }; }
}
$sc_checks=0; $sc_users=array(); $sc_sessions=array(); $sc_http=array(); $sc_failure=null; $sc_preserved=false; $sc_connector_registered=false; $sc_fixture_clean=true; $sc_deny_human=false;
$sc_marker='bhranti-context-qa-'.str_replace('-','',wp_generate_uuid4()); $sc_checkpoint='/private/tmp/'.$sc_marker.'.checkpoint.json';
$sc_original_user=get_current_user_id(); $sc_registry_property=new ReflectionProperty(AiClient::class,'defaultRegistry'); $sc_registry_property->setAccessible(true);
$sc_original_registry=AiClient::defaultRegistry(); $sc_original_dispatcher=AiClient::getEventDispatcher(); $sc_original_cache=AiClient::getCache();
function sc_check($condition,$label) { if (!$condition) { throw new RuntimeException('FAIL: '.$label); } ++$GLOBALS['sc_checks']; }
function sc_rest($method,$path,$body=array()) { $r=new WP_REST_Request($method,'/bhranti/v1'.$path); $r->set_body_params($body); return rest_do_request($r); }
function sc_command($method,$path,$params=array()) { return array('tool'=>'wp_request','args'=>array('method'=>$method,'path'=>$path,'params'=>$params)); }
function sc_rows() {
 global $wpdb; $queries=array(
  'posts'=>array('ID',"SELECT * FROM {$wpdb->posts} ORDER BY ID"), 'postmeta'=>array('meta_id',"SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id"),
  'comments'=>array('comment_ID',"SELECT * FROM {$wpdb->comments} ORDER BY comment_ID"), 'commentmeta'=>array('meta_id',"SELECT * FROM {$wpdb->commentmeta} ORDER BY meta_id"),
  'terms'=>array('term_id',"SELECT * FROM {$wpdb->terms} ORDER BY term_id"), 'term_taxonomy'=>array('term_taxonomy_id',"SELECT * FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id"),
  'relationships'=>array(null,"SELECT * FROM {$wpdb->term_relationships} ORDER BY object_id,term_taxonomy_id"),
  'options'=>array('option_name',"SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN ('bhranti_settings','bhranti_actor_id','bhranti_role_owned','bhranti_actor_setup_lock','wp_user_roles','active_plugins','show_on_front','page_on_front','page_for_posts','stylesheet','template','blogname','blogdescription') OR option_name LIKE 'bhranti_lock_%' ORDER BY option_name"),
  'actor'=>array('ID',"SELECT * FROM {$wpdb->users} WHERE ID=11"), 'actor_meta'=>array('umeta_id',"SELECT * FROM {$wpdb->usermeta} WHERE user_id=11 ORDER BY umeta_id"),
 ); $state=array(); foreach($queries as $table=>$def) { $state[$table]=array(); foreach($wpdb->get_results($def[1],ARRAY_A) as $row) { $key=$def[0]?(string)$row[$def[0]]:$row['object_id'].':'.$row['term_taxonomy_id']; $state[$table][$key]=hash('sha256',wp_json_encode($row)); } } return $state;
}
function sc_checkpoint() {
 global $sc_checkpoint,$sc_marker,$sc_users,$sc_sessions,$sc_baseline;
 $pending=$sc_checkpoint.'.pending'; $h=fopen($pending,'x'); if (!$h) { throw new RuntimeException('Cannot create private checkpoint.'); }
 try { if (!chmod($pending,0600)) {throw new RuntimeException('Cannot protect checkpoint.');} $data=wp_json_encode(array('site'=>home_url('/'),'marker'=>$sc_marker,'users'=>$sc_users,'sessions'=>$sc_sessions,'row_baseline'=>$sc_baseline)); if (strlen($data)!==fwrite($h,$data)||!fflush($h)) {throw new RuntimeException('Cannot persist checkpoint.');} } finally {fclose($h);}
 if (!rename($pending,$sc_checkpoint)) {throw new RuntimeException('Cannot replace checkpoint.');}
}
function sc_events($session) { $events=array(); foreach(get_posts(array('post_type'=>'bhranti_event','post_status'=>'private','post_parent'=>$session,'posts_per_page'=>-1)) as $event) {$events[]=get_post_meta($event->ID,'bhranti_event_data',true);} return $events; }
function sc_session_snapshot($session) {global $wpdb; return array($wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID=%d",$session),ARRAY_A),$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id",$session),ARRAY_A),sc_events($session));}
function sc_png($width,$height) { $image=imagecreatetruecolor($width,$height); ob_start(); imagepng($image); $bytes=ob_get_clean(); imagedestroy($image); return base64_encode($bytes); }
function sc_start($session,$content,$context=array()) { $r=sc_rest('POST','/sessions/'.$session.'/messages',array('content'=>$content,'context'=>$context)); sc_check(200===$r->get_status(),'Context request starts through native REST'); return $r->get_data()['run']['id']; }
function sc_step($session,$run) { $before=count(Bhranti_QA_Model::$calls); $r=sc_rest('POST','/sessions/'.$session.'/step',array('run_id'=>$run)); sc_check(200===$r->get_status(),'Context step passes native REST boundary'); sc_check(count(Bhranti_QA_Model::$calls)===$before+1,'Exactly one native model call per step'); return $r->get_data(); }
function sc_capture_assert($call,$content,$png) {
 $files=array(); foreach($call['prompt'] as $message_index=>$message) { foreach($message->getParts() as $part) { if($part->getType()->isFile()) { $files[]=array($message_index,$message,$part); } } }
 sc_check(1===count($files),'Screenshot occurs exactly once across all native message history'); $attached=$files[0]; $file=$attached[2]->getFile();
 sc_check($attached[1]->getRole()->isUser(),'Screenshot is attached to a native user message'); sc_check($file->isInline()&&!$file->isRemote()&&'image/png'===$file->getMimeType()&&$png===$file->getBase64Data(),'Native File preserves exact chosen screenshot bytes/type');
 $parts=$attached[1]->getParts(); sc_check($parts[0]->getType()->isText()&&$content===$parts[0]->getText(),'Screenshot attaches to the latest actual human request, not previous history/tool/retry feedback');
 sc_check(!str_contains($call['config']['systemInstruction'],$png)&&str_contains($call['config']['systemInstruction'],'UNTRUSTED DATA'),'System instruction omits screenshot base64 and labels page context untrusted');
 sc_check('application/json'===$call['config']['outputMimeType'],'Screenshot planning preserves native JSON mode');
}
$sc_http_guard=static function($response,$args,$url) { $GLOBALS['sc_http'][]=$url; return new WP_Error('bhranti_context_qa_http_denied','No outbound requests in this focused suite.'); };
$sc_cap_guard=static function($allcaps,$required,$args,$user) { if($GLOBALS['sc_deny_human']&&isset($GLOBALS['sc_owner'])&&(int)$user->ID===$GLOBALS['sc_owner']&&'switch_themes'===($args[0]??'')) {$allcaps['switch_themes']=false;} return $allcaps; };
$sc_runner_veto = bhranti_qa_runner_veto( static function () { return $GLOBALS['sc_sessions'] ?? array(); } );
add_filter('pre_http_request',$sc_http_guard,PHP_INT_MAX,3); add_filter('pre_wp_mail','__return_true',PHP_INT_MAX); add_filter('user_has_cap',$sc_cap_guard,PHP_INT_MAX,4);
$sc_baseline=sc_rows(); sc_checkpoint();
try {
 $sc_registry_property->setValue(null,new ProviderRegistry()); AiClient::setEventDispatcher(null); AiClient::setCache(null); AiClient::defaultRegistry()->registerProvider(Bhranti_Context_QA_Provider::class);
 sc_check(null===WP_Connector_Registry::get_instance()->get_registered('bhranti-context-qa'),'Disposable native vision connector name is unregistered'); WP_Connector_Registry::get_instance()->register('bhranti-context-qa',array('name'=>'Bhranti context local QA','type'=>'ai_provider','authentication'=>array('method'=>'none'))); $sc_connector_registered=true;
 foreach(array('administrator','administrator') as $role) { $login='bhranti_context_qa_'.str_replace('-','',wp_generate_uuid4()); $id=wp_insert_user(array('user_login'=>$login,'user_pass'=>wp_generate_password(),'role'=>$role,'user_email'=>$login.'@example.test')); sc_check(is_int($id)&&$id>0,'Fresh temporary human test identity'); $sc_users[]=$id; sc_checkpoint(); }
 list($sc_owner,$sc_other)=$sc_users; wp_set_current_user($sc_owner);
 foreach(array(0,1) as $i) { $r=sc_rest('POST','/sessions'); sc_check(200===$r->get_status(),'Fresh owned private fixture conversation'); $sc_sessions[]=(int)$r->get_data()['id']; sc_checkpoint(); }
 list($session,$invalid_session)=$sc_sessions;
 $actor_before=get_user_by('id',11)->allcaps; sc_check(!user_can(11,'switch_themes'),'Managed actor has no permanent switch_themes capability');
 $themes=Bhranti_Actions::execute(sc_command('GET','/wp/v2/themes'),$sc_owner,$session);
 sc_check(!is_wp_error($themes)&&is_array($themes['data']),'Reviewed Actions obtains native installed-theme collection');
 $native_themes=array_keys(wp_get_themes()); $returned_themes=array_column($themes['data'],'stylesheet'); sort($native_themes); sort($returned_themes); sc_check($native_themes===$returned_themes,'Theme collection exactly matches native installed themes');
 $allowed=array('stylesheet','template','name','version','status','is_block_theme','requires_wp','requires_php'); foreach($themes['data'] as $theme) {sc_check(!array_diff(array_keys($theme),$allowed),'Theme response excludes arbitrary private/theme-author/plugin fields'); sc_check(isset($theme['name']['raw'])&&!str_contains($theme['name']['raw'],'<'),'Theme display name is plain public metadata');}
 sc_check($sc_owner===get_current_user_id()&&!user_can(11,'switch_themes')&&$actor_before===get_user_by('id',11)->allcaps,'Native theme collection restores human identity and exact actor permanent caps');
 $stylesheet=get_stylesheet(); $one=Bhranti_Actions::execute(sc_command('GET','/wp/v2/themes/'.$stylesheet),$sc_owner,$session); sc_check(!is_wp_error($one)&&$stylesheet===$one['data']['stylesheet'],'Exact native individual theme read works');
 $sc_deny_human=true; $denied=Bhranti_Actions::execute(sc_command('GET','/wp/v2/themes'),$sc_owner,$session); sc_check(is_wp_error($denied)&&'bhranti_action_permission'===$denied->get_error_code(),'Actor temporary read grant never bypasses denied human core theme permission'); $sc_deny_human=false;
 foreach(array('POST','PUT','PATCH','DELETE') as $method) { $blocked=Bhranti_Actions::execute(sc_command($method,'/wp/v2/themes/'.$stylesheet,array('status'=>'active')),$sc_owner,$session); sc_check(is_wp_error($blocked),'Native theme writes remain blocked'); }
 $request=new WP_REST_Request('GET','/wp/v2/themes'); $sc_deny_human=true;
 $scope=Bhranti_Site::actor_read($request,11,static function() use($sc_owner) {sc_check(11===get_current_user_id()&&current_user_can('switch_themes'),'Exact theme callback receives actor-only read cap'); sc_check(!user_can($sc_owner,'switch_themes')&&!current_user_can('install_themes')&&!current_user_can('delete_themes'),'Other humans and unrelated dangerous caps are never granted'); return 'scoped-native-result';});
 sc_check('scoped-native-result'===$scope&&$sc_owner===get_current_user_id()&&!user_can(11,'switch_themes'),'Successful helper returns result and removes cap filter'); $sc_deny_human=false;
 try {Bhranti_Site::actor_read($request,11,static function(){throw new RuntimeException('Expected local scope exception');});} catch(RuntimeException $expected) {sc_check('Expected local scope exception'===$expected->getMessage(),'Scope propagates expected local callback exception');}
 sc_check($sc_owner===get_current_user_id()&&!user_can(11,'switch_themes'),'Thrown callback still removes scoped cap and restores caller');
 $callback_called=false; foreach(array(new WP_REST_Request('POST','/wp/v2/themes'),new WP_REST_Request('GET','/wp/v2/posts'),new WP_REST_Request('GET','/wp/v2/themes/../private'),new WP_REST_Request('GET','/wp/v2/themes?context=edit')) as $bad) { $r=Bhranti_Site::actor_read($bad,11,static function() use(&$callback_called){$callback_called=true;return true;}); sc_check(is_wp_error($r)&&!$callback_called,'Non-theme/non-GET/malformed routes cannot enter scoped callback'); }
 sc_check(is_wp_error(Bhranti_Site::actor_read($request,$sc_other,static fn()=>true)),'Unmanaged actor cannot enter theme read scope');
 $throw_rest=static function($response,$server,$r){if('/wp/v2/themes'===$r->get_route()){throw new RuntimeException('Expected native dispatch fixture exception');}return $response;};
 add_filter('rest_pre_dispatch',$throw_rest,PHP_INT_MAX,3); try {$dispatch_failed=Bhranti_Actions::execute(sc_command('GET','/wp/v2/themes'),$sc_owner,$session);} finally {remove_filter('rest_pre_dispatch',$throw_rest,PHP_INT_MAX);}
 sc_check(is_wp_error($dispatch_failed)&&'bhranti_action_failed'===$dispatch_failed->get_error_code()&&$sc_owner===get_current_user_id()&&!user_can(11,'switch_themes'),'Actual native REST dispatch exception unwinds actor read scope');
 $png=sc_png(2,2); $screenshot=array('data'=>$png,'mime_type'=>'image/png'); $valid=Bhranti_Context::screenshot($screenshot); sc_check(!is_wp_error($valid)&&2===$valid['width']&&2===$valid['height'],'Captured native PNG geometry validates');
 $part=Bhranti_Context::screenshot_part($valid); sc_check($part instanceof WordPress\AiClient\Messages\DTO\MessagePart&&$png===$part->getFile()->getBase64Data(),'Native SDK screenshot File/MessagePart exact bytes');
 $edge=Bhranti_Context::screenshot(array('data'=>sc_png(2560,1),'mime_type'=>'image/png')); sc_check(!is_wp_error($edge)&&2560===$edge['width'],'Screenshot accepts exact 2560-pixel native boundary');
 $bad_screens=array(array('data'=>'https://private.example/image.png','mime_type'=>'image/png'),array('data'=>$png,'mime_type'=>'image/jpeg'),array('data'=>'','mime_type'=>'image/png'),array('data'=>'invalid$base64','mime_type'=>'image/png'),array('data'=>base64_encode('<svg><script>unsafe</script></svg>'),'mime_type'=>'image/png'),array('data'=>$png,'mime_type'=>'image/webp'),array('data'=>$png,'mime_type'=>'image/png','extra'=>true),array('data'=>sc_png(2561,1),'mime_type'=>'image/png'),array('data'=>base64_encode(base64_decode($png).str_repeat('A',2097152)),'mime_type'=>'image/png'));
 foreach($bad_screens as $case_index=>$bad) { $snapshot=sc_session_snapshot($invalid_session); $calls=count(Bhranti_QA_Model::$calls); $r=sc_rest('POST','/sessions/'.$invalid_session.'/messages',array('content'=>'Reject invalid captured screenshot','context'=>array('screenshot'=>$bad))); sc_check(400===$r->get_status()&&'bhranti_context_invalid'===$r->get_data()['code'],'Invalid screenshot rejected at actual native start endpoint (case '.($case_index+1).', status '.$r->get_status().', code '.($r->get_data()['code']??'none').')'); sc_check($snapshot===sc_session_snapshot($invalid_session)&&$calls===count(Bhranti_QA_Model::$calls),'Invalid screenshot creates no messages/run/audit or provider call'); }
 $html='<script>private-script-sentinel</script><style>private-style-sentinel</style><template>private-template-sentinel</template><div data-token="private-token" style="color:red"><a href="https://user:pass@example.test/cats?nonce=private-query#frag" onclick="private-handler">Cats</a><input type="password" value="private-control" /><img src="/cat.png?nonce=private-query" srcset="private-srcset" /></div>';
 $page=Bhranti_Context::page(array('html'=>$html,'text'=>"Cats\nनमस्ते",'viewport'=>array('width'=>1440,'height'=>900)));
 sc_check(!is_wp_error($page)&&!str_contains($page['html'],'private-')&&!str_contains($page['html'],'user:pass')&&!str_contains($page['html'],'onclick'),'Captured HTML removes script/style/template/control/data/event/URL credential payloads'); sc_check(str_contains($page['html'],'https://example.test/cats')&&str_contains($page['html'],'/cat.png')&&"Cats\nनमस्ते"===$page['text'],'Rendered context retains inert structure and Unicode visible text');
 $bounds=Bhranti_Context::page(array('html'=>str_repeat('猫',12001),'text'=>str_repeat('猫',8001))); sc_check($bounds['truncated']&&12000===mb_strlen($bounds['html'])&&8000===mb_strlen($bounds['text']),'Rendered HTML/text limits count Unicode characters');
 foreach(array(array('text'=>"bad\xff"),array('text'=>str_repeat('A',512001)),array('viewport'=>array('width'=>0,'height'=>900)),array('viewport'=>array('width'=>1440,'height'=>16385)),array('viewport'=>array('width'=>'1440','height'=>900)),array('html'=>'ok','extra'=>true)) as $bad) { $snapshot=sc_session_snapshot($invalid_session); $r=sc_rest('POST','/sessions/'.$invalid_session.'/messages',array('content'=>'Reject invalid rendered page','context'=>array('rendered_page'=>$bad))); sc_check(400===$r->get_status()&&$snapshot===sc_session_snapshot($invalid_session),'Invalid rendered page fails before messages/run/audit mutation'); }
 $seed=sc_start($session,'Earlier human request'); Bhranti_QA_Model::$responses=array(wp_json_encode(array('type'=>'reply','content'=>'Earlier model reply'))); sc_check('complete'===sc_step($session,$seed)['run']['status'],'Earlier conversation history seeded through native SDK');
 $request_text='Inspect my chosen screenshot and rendered cats page without changing site content'; $context=array('screenshot'=>$screenshot,'rendered_page'=>array('html'=>$html,'text'=>'Cats page visible text','viewport'=>array('width'=>1440,'height'=>900)));
 $run=sc_start($session,$request_text,$context); $state=get_post_meta($session,'bhranti_run',true); sc_check($state['context']['screenshot']['data']===$png&&!str_contains($state['context']['rendered_page']['html'],'private-'),'Private run stores only validated screenshot and sanitized page context');
 Bhranti_QA_Model::$responses=array('{"type":"unexpected-envelope"}',wp_json_encode(array('type'=>'tool','tool'=>'wp_request','args'=>array('method'=>'GET','path'=>'/wp/v2/themes'))),wp_json_encode(array('type'=>'reply','content'=>'Chosen screenshot inspected; installed themes read.')));
 foreach(array('running','running','complete') as $expected_status) { $data=sc_step($session,$run); sc_check($expected_status===$data['run']['status'],'Screenshot survives one normal formatting retry/tool step/final response'); $call=Bhranti_QA_Model::$calls[count(Bhranti_QA_Model::$calls)-1]; sc_capture_assert($call,$request_text,$png); sc_check(!str_contains($call['config']['systemInstruction'],'private-')&&str_contains($call['config']['systemInstruction'],'Cats page visible text'),'Native system context contains sanitized rendered page without removed HTML payload'); }
 $native_retry_text='Retry screenshot inspection after a transient native failure'; $run=sc_start($session,$native_retry_text,array('screenshot'=>$screenshot)); Bhranti_QA_Model::$responses=array(new RuntimeException('local-private-provider-error'),wp_json_encode(array('type'=>'reply','content'=>'Native retry complete')));
 foreach(array('running','complete') as $expected_status) {$data=sc_step($session,$run); sc_check($expected_status===$data['run']['status'],'Chosen screenshot survives shared bounded native planning retry'); sc_capture_assert(Bhranti_QA_Model::$calls[count(Bhranti_QA_Model::$calls)-1],$native_retry_text,$png);}
 foreach($sc_sessions as $id) { $audit=wp_json_encode(sc_events($id)); sc_check(!str_contains($audit,$png)&&!str_contains($audit,'private-script')&&!str_contains($audit,'local-private-provider-error'),'Durable audits contain no screenshot/base64/HTML/private provider text'); }
 sc_check(!$sc_http,'Theme/context workflow made zero HTTP or real provider calls'); sc_check(!user_can(11,'switch_themes')&&$actor_before===get_user_by('id',11)->allcaps,'All workflow steps leave managed actor role/capabilities unchanged');
} catch(Throwable $error) {$sc_failure=$error;}
finally {
 $sc_deny_human=false; remove_filter('user_has_cap',$sc_cap_guard,PHP_INT_MAX); wp_set_current_user($sc_original_user);
 foreach($sc_users as $user_id) { $user=get_user_by('id',$user_id); if(!$user||!str_starts_with($user->user_login,'bhranti_context_qa_')||!str_ends_with($user->user_email,'@example.test')) { $sc_fixture_clean=false; $sc_failure=new RuntimeException('Temporary identity cleanup guard failed.'); continue; }
  $owned_ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_author=%d",$user_id)); $safe=true; foreach($owned_ids as $id) {$post=get_post($id); if(isset($sc_baseline['posts'][(string)$id])||!in_array($post->post_type,array('bhranti_session','bhranti_event'),true)||'private'!==$post->post_status) {$safe=false;}}
  if(!$safe) {$sc_fixture_clean=false; $sc_failure=new RuntimeException('Unexpected test-owned content; user and rows preserved.'); continue;}
  foreach($owned_ids as $id) {wp_delete_post($id,true);}
  require_once ABSPATH.'wp-admin/includes/user.php'; $deleted=wp_delete_user($user_id); if(!$deleted||get_user_by('id',$user_id)) {$sc_fixture_clean=false; $sc_failure=new RuntimeException('Temporary identity deletion failed; recovery checkpoint retained.');}
 }
 $after=sc_rows(); $sc_preserved=$sc_baseline===$after; $diff=array(); foreach(array_keys($sc_baseline) as $table) {foreach(array_unique(array_merge(array_keys($sc_baseline[$table]),array_keys($after[$table]))) as $key) {if(($sc_baseline[$table][$key]??null)!==($after[$table][$key]??null)) {$diff[]=array('table'=>$table,'id'=>$key);}}}
 if($sc_connector_registered) {WP_Connector_Registry::get_instance()->unregister('bhranti-context-qa');}
 $sc_registry_property->setValue(null,$sc_original_registry); AiClient::setEventDispatcher($sc_original_dispatcher); AiClient::setCache($sc_original_cache); remove_filter('pre_http_request',$sc_http_guard,PHP_INT_MAX); remove_filter('pre_wp_mail','__return_true',PHP_INT_MAX); remove_filter( 'pre_schedule_event', $sc_runner_veto, PHP_INT_MAX ); restore_error_handler();
 if($sc_preserved&&$sc_fixture_clean) {unlink($sc_checkpoint);} echo wp_json_encode(array('checks'=>$sc_checks,'original_state_preserved'=>$sc_preserved,'fixtures_removed'=>$sc_fixture_clean,'state_changes'=>$diff,'http_calls'=>count($sc_http))),"\n";
}
if($sc_failure||!$sc_preserved) {fwrite(STDERR,$sc_failure?$sc_failure->getMessage()."\n":"Original state changed; inspect retained checkpoint.\n");exit(1);} echo 'PASS '.$sc_checks." native Site/Context checks\n";
