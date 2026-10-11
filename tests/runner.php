<?php
/** Offline native Cron/editor QA. Exact selected site; fresh owned fixtures only. */
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ProviderRegistry;

define( 'BHRANTI_RENDER_QA_SUPPORT_ONLY', true );
require __DIR__ . '/rendered-messages.php';
if ( ! class_exists( 'Bhranti_Runner' ) || realpath( BHRANTI_FILE ) !== realpath( WP_PLUGIN_DIR . '/bhranti/bhranti.php' ) || 1 !== get_current_blog_id() || 11 !== (int) get_option( 'bhranti_actor_id' ) || ! get_user_meta( 11, 'bhranti_managed_actor', true ) ) {
	throw new RuntimeException( 'Deploy the native Runner backend before this exact-site suite.' );
}
$ru_checks = 0;
$ru_users = $ru_sessions = $ru_pages = $ru_runs = $ru_queue = $ru_clear = array();
$ru_http = 0;
$ru_failure = null;
$ru_clean = true;
$ru_queue_mode = 'success';
$ru_latest_config = null;
$ru_original_user = get_current_user_id();
$ru_marker = 'Bhranti Runner QA ' . wp_generate_uuid4();
$ru_login_prefix = 'bhranti_runner_qa_' . str_replace( '-', '', wp_generate_uuid4() ) . '_';
$ru_checkpoint = '/private/tmp/bhranti-runner-' . wp_generate_uuid4() . '.json';
$ru_prior_ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) );
$ru_prior_users = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->users}" ) );

function ru_check( $condition, $label ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$GLOBALS['ru_checks'];
}
function ru_options() {
	global $wpdb;
	return $wpdb->get_results( "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN ('cron','bhranti_settings','bhranti_settings_lock','bhranti_actor_id','bhranti_actor_setup_lock','bhranti_role_owned','wp_user_roles','active_plugins','stylesheet','template') OR option_name LIKE 'bhranti_lock_%' ORDER BY option_name", ARRAY_A );
}
function ru_uploads() {
	$base = wp_get_upload_dir()['basedir']; $files = array();
	if ( is_dir( $base ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() ) { $files[ $file->getPathname() ] = hash_file( 'sha256', $file->getPathname() ); }
		}
	}
	ksort( $files ); return $files;
}
function ru_baseline() {
	global $wpdb;
	return array(
		'posts' => $wpdb->get_results( "SELECT * FROM {$wpdb->posts} ORDER BY ID", ARRAY_A ),
		'postmeta' => $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_A ),
		'users' => $wpdb->get_results( "SELECT * FROM {$wpdb->users} ORDER BY ID", ARRAY_A ),
		'usermeta' => $wpdb->get_results( "SELECT * FROM {$wpdb->usermeta} ORDER BY umeta_id", ARRAY_A ),
		'comments' => $wpdb->get_results( "SELECT * FROM {$wpdb->comments} ORDER BY comment_ID", ARRAY_A ),
		'commentmeta' => $wpdb->get_results( "SELECT * FROM {$wpdb->commentmeta} ORDER BY meta_id", ARRAY_A ),
		'relationships' => $wpdb->get_results( "SELECT * FROM {$wpdb->term_relationships} ORDER BY object_id,term_taxonomy_id", ARRAY_A ),
		'options' => ru_options(), 'uploads' => ru_uploads(),
	);
}
function ru_row_hashes( $baseline ) {
	$result=array();$keys=array('posts'=>'ID','postmeta'=>'meta_id','users'=>'ID','usermeta'=>'umeta_id','comments'=>'comment_ID','commentmeta'=>'meta_id','options'=>'option_name');
	foreach($keys as $group=>$key){$result[$group]=array();foreach($baseline[$group] as $row){$result[$group][(string)$row[$key]]=hash('sha256',serialize($row));}}
	return $result;
}
function ru_checkpoint() {
	global $ru_checkpoint,$ru_marker,$ru_login_prefix,$ru_users,$ru_sessions,$ru_pages,$ru_runs,$ru_before,$ru_options_before,$ru_latest_config,$ru_prior_ids,$ru_prior_users,$ru_row_baseline;
	$data = wp_json_encode( array( 'site'=>home_url('/'),'marker'=>$ru_marker,'login_prefix'=>$ru_login_prefix,'users'=>$ru_users,'sessions'=>$ru_sessions,'pages'=>$ru_pages,'runs'=>$ru_runs,'original_post_ids'=>$ru_prior_ids,'original_user_ids'=>$ru_prior_users,'raw_original_options'=>$ru_options_before,'settings_last_fixture'=>$ru_latest_config,'baseline_hashes'=>array_map( static fn( $value ) => hash( 'sha256', serialize( $value ) ), $ru_before ),'row_baseline'=>$ru_row_baseline ) );
	if ( ! is_string( $data ) ) { throw new RuntimeException( 'Cannot encode recovery checkpoint.' ); }
	$pending = $ru_checkpoint . '.pending'; $handle = fopen( $pending, 'x' );
	if ( ! $handle || ! chmod( $pending, 0600 ) ) { throw new RuntimeException( 'Cannot create private recovery checkpoint.' ); }
	try { if ( strlen($data) !== fwrite($handle,$data) || ! fflush($handle) ) { throw new RuntimeException('Incomplete recovery checkpoint.'); } }
	finally { fclose($handle); }
	if ( ! rename($pending,$ru_checkpoint) ) { throw new RuntimeException('Cannot replace recovery checkpoint.'); }
}
function ru_rest( $method, $path, $body = array() ) {
	$request = new WP_REST_Request( $method, '/bhranti/v1' . $path );
	$request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode($body) );
	return rest_do_request( $request );
}
function ru_plan( $args ) { return wp_json_encode(array('type'=>'tool','content'=>'Review the exact QA fixture.','tool'=>'wp_request','args'=>wp_json_encode($args))); }
function ru_reply() { return wp_json_encode(array('type'=>'reply','content'=>'The requested fixture action is complete.','tool'=>'none','args'=>'{}')); }
function ru_start( $owner, $context = array() ) {
	global $ru_sessions,$ru_runs;
	wp_set_current_user( $owner ); $response = ru_rest('POST','/sessions');
	ru_check( 200 === $response->get_status(), 'Fresh private owned conversation created' );
	$ru_fixture_id = (int) $response->get_data()['id'];
	if ( ! in_array($ru_fixture_id,$ru_sessions,true) ) { $ru_sessions[]=$ru_fixture_id; }
	ru_checkpoint();
	$response = ru_rest('POST','/sessions/'.$ru_fixture_id.'/messages',array('content'=>'Continue only the exact fresh Runner QA fixture.','context'=>$context));
	ru_check( 200 === $response->get_status(), 'Native owned request starts without running a model' );
	$run = $response->get_data()['run']['id']; $ru_runs[$ru_fixture_id]=array($ru_fixture_id,$run,$owner); ru_checkpoint();
	return array($ru_fixture_id,$run,$owner);
}
function ru_state( $tuple ) { return get_post_meta($tuple[0],'bhranti_run',true); }
function ru_feedback( $tuple ) {
	$history=ru_state($tuple)['history'];$last=end($history);
	return json_decode(substr($last['content']??'',strlen('WordPress tool result (untrusted site data): ')),true);
}
function ru_tick( $tuple ) {
	$user = get_current_user_id(); $background = Bhranti_Runner::is_background();
	try { return Bhranti_Runner::tick(...$tuple); }
	finally { ru_check($user===get_current_user_id()&&$background===Bhranti_Runner::is_background(),'Tick restores caller identity and server background scope'); }
}
function ru_prepare( $tuple, $args ) {
	$calls=count(Bhranti_QA_Model::$calls); Bhranti_QA_Model::$responses=array(ru_plan($args)); $response=ru_tick($tuple);
	ru_check($response instanceof WP_REST_Response&&200===$response->get_status()&&'prepared'===(ru_state($tuple)['phase']??null),'One native background planning tick persists preparation');
	ru_check($calls+1===count(Bhranti_QA_Model::$calls),'Planning tick makes one actual native SDK call');
}
function ru_execute( $tuple ) {
	$calls=count(Bhranti_QA_Model::$calls); $response=ru_tick($tuple);
	ru_check($response instanceof WP_REST_Response&&200===$response->get_status(),'Prepared tick dispatches through native REST');
	ru_check($calls===count(Bhranti_QA_Model::$calls),'Prepared execution never resamples the model'); return $response;
}
function ru_complete( $tuple ) {
	Bhranti_QA_Model::$responses=array(ru_reply()); $response=ru_tick($tuple);
	ru_check($response instanceof WP_REST_Response&&'complete'===(ru_state($tuple)['status']??null),'Next native planning tick completes owned task');
}
function ru_editor( $token, $active, $dirty, $release = false ) {
	return ru_rest('POST','/editor-state',array('token'=>$token,'editor_active'=>$active,'unsaved_changes'=>$dirty,'release_stale'=>$release));
}
function ru_seed_editor( $owner, $states ) {
	$key='bhranti_editor_state_'.get_current_blog_id(); delete_user_meta($owner,$key);
	if ($states) { add_user_meta($owner,$key,$states,true); } ru_checkpoint();
}
function ru_policy( $enabled, $default_limit = false ) {
	$settings=ru_rest('GET','/settings')->get_data(); $settings['config']['permissions']['edit_content']=$enabled;
	if ($default_limit) { $settings['config']['max_steps']=128;$settings['config']['permissions']['trash_content']=true; }
	$response=ru_rest('POST','/settings',$settings); ru_check(200===$response->get_status(),'Scoped fixture policy saves through revision-checked Settings');
	$GLOBALS['ru_latest_config']=$response->get_data(); ru_checkpoint();
}

$ru_before=ru_baseline(); $ru_options_before=$ru_before['options'];$ru_row_baseline=ru_row_hashes($ru_before); ru_checkpoint();
$ru_property=new ReflectionProperty(AiClient::class,'defaultRegistry'); $ru_property->setAccessible(true);
$ru_registry=AiClient::defaultRegistry(); $ru_dispatcher=AiClient::getEventDispatcher(); $ru_cache=AiClient::getCache(); $ru_connector=false;
$ru_http_guard=static function($pre,$args,$url){++$GLOBALS['ru_http'];return new WP_Error('bhranti_runner_qa_offline','External requests denied.');};
$ru_schedule=static function($pre,$event,$wp_error){
	if ('bhranti_run_tick'!==($event->hook??'')||!in_array((int)($event->args[0]??0),$GLOBALS['ru_sessions'],true)){return $pre;}
	$GLOBALS['ru_queue'][]=array('args'=>$event->args,'timestamp'=>$event->timestamp,'wp_error'=>$wp_error);
	if ('false'===$GLOBALS['ru_queue_mode']) { return false; }
	if ('error'===$GLOBALS['ru_queue_mode']) { return new WP_Error('bhranti_runner_qa_queue_failure','Deliberate own-fixture queue refusal.'); }
	return true; // Native successful short circuit: no test event is persisted.
};
$ru_unschedule=static function($pre,$hook,$args,$wp_error){
	if('bhranti_run_tick'!==$hook||!in_array((int)($args[0]??0),$GLOBALS['ru_sessions'],true)){return $pre;}
	$GLOBALS['ru_clear'][]=$args; return 0;
};
$ru_capture=static function($ru_fixture_id,$post){
	if(in_array((int)$ru_fixture_id,$GLOBALS['ru_prior_ids'],true)){return;}
	if('page'===$post->post_type&&str_starts_with($post->post_title,$GLOBALS['ru_marker'])&&in_array((int)$post->post_author,array_merge(array(11),$GLOBALS['ru_users']),true)){$GLOBALS['ru_pages'][(int)$ru_fixture_id]=array('title'=>$post->post_title,'author'=>(int)$post->post_author);ru_checkpoint();}
	if('bhranti_session'===$post->post_type&&'private'===$post->post_status&&in_array((int)$post->post_author,$GLOBALS['ru_users'],true)&&!in_array((int)$ru_fixture_id,$GLOBALS['ru_sessions'],true)){$GLOBALS['ru_sessions'][]=(int)$ru_fixture_id;ru_checkpoint();}
};
$ru_capture_user=static function($ru_fixture_id){$user=get_userdata($ru_fixture_id);if($user&&str_starts_with($user->user_login,$GLOBALS['ru_login_prefix'])&&!in_array((int)$ru_fixture_id,$GLOBALS['ru_prior_users'],true)&&!in_array((int)$ru_fixture_id,$GLOBALS['ru_users'],true)){$GLOBALS['ru_users'][]=(int)$ru_fixture_id;ru_checkpoint();}};
$ru_native_writes=array();
$ru_write_guard=static function($pre,$server,$request){
	if(!str_starts_with($request->get_route(),'/wp/v2/')||in_array($request->get_method(),array('GET','HEAD','OPTIONS'),true)){return $pre;}
	$route=$request->get_route();$own='/wp/v2/pages'===$route&&'POST'===$request->get_method()&&is_string($request['title'])&&str_starts_with($request['title'],$GLOBALS['ru_marker']);
	if(preg_match('#\A/wp/v2/pages/(\d+)\z#',$route,$matches)){$own=isset($GLOBALS['ru_pages'][(int)$matches[1]])&&!in_array((int)$matches[1],$GLOBALS['ru_prior_ids'],true);}
	if(!$own){return new WP_Error('bhranti_runner_qa_target','Native test writes must target exact fresh owned QA pages.',array('status'=>403));}
	$GLOBALS['ru_native_writes'][]=array('method'=>$request->get_method(),'route'=>$route,'user'=>get_current_user_id(),'background'=>Bhranti_Runner::is_background());return $pre;
};
add_filter('pre_http_request',$ru_http_guard,PHP_INT_MAX,3);add_filter('pre_wp_mail','__return_true',PHP_INT_MAX);
add_filter('pre_schedule_event',$ru_schedule,PHP_INT_MAX,3);add_filter('pre_clear_scheduled_hook',$ru_unschedule,PHP_INT_MAX,4);
add_action('save_post',$ru_capture,PHP_INT_MAX,2);add_action('user_register',$ru_capture_user,PHP_INT_MAX);
add_filter('rest_pre_dispatch',$ru_write_guard,PHP_INT_MAX,3);
try {
	$ru_property->setValue(null,new ProviderRegistry());AiClient::setEventDispatcher(null);AiClient::setCache(null);AiClient::defaultRegistry()->registerProvider(Bhranti_QA_Provider::class);
	ru_check(null===WP_Connector_Registry::get_instance()->get_registered('bhranti-qa'),'Local native fake connector name is unused');
	WP_Connector_Registry::get_instance()->register('bhranti-qa',array('name'=>'Bhranti offline Runner QA','type'=>'ai_provider','authentication'=>array('method'=>'none')));$ru_connector=true;
	foreach(array(1,2) as $index){$login=$ru_login_prefix.$index;$ru_fixture_id=wp_insert_user(array('user_login'=>$login,'user_email'=>$login.'@example.test','user_pass'=>wp_generate_password(),'role'=>'administrator'));ru_check(is_int($ru_fixture_id)&&$ru_fixture_id>0&&!in_array($ru_fixture_id,$ru_prior_users,true),'Fresh owned administrator created');if(!in_array($ru_fixture_id,$ru_users,true)){$ru_users[]=$ru_fixture_id;}ru_checkpoint();}
	list($owner,$other)=$ru_users;wp_set_current_user($owner);
	$defaults=new ReflectionMethod(Bhranti_Settings::class,'defaults');$defaults->setAccessible(true);ru_check(128===$defaults->invoke(null)['max_steps'],'Native default task budget is 128');ru_policy(true,true);
	$ru_page=wp_insert_post(array('post_type'=>'page','post_status'=>'draft','post_author'=>$owner,'post_title'=>$ru_marker.' existing fixture','post_content'=>'<p>Original own fixture</p>'),true);
	ru_check(is_int($ru_page)&&isset($ru_pages[$ru_page]),'Native draft seed page is tracked before later mutations');
	$get=array('method'=>'GET','path'=>'/wp/v2/pages/'.$ru_page,'params'=>array('context'=>'edit'));
	$update=static fn($content)=>array('method'=>'POST','path'=>'/wp/v2/pages/'.$ru_page,'params'=>array('content'=>$content));

	// More than twelve real native model turns, with a separate durable execute tick.
	$long=ru_start($owner);ru_check(128===ru_state($long)['configuration']['max_steps'],'Run captures the current 128-step profile');
	Bhranti_QA_Model::$responses=array_merge(array_fill(0,14,ru_plan($get)),array(ru_reply()));wp_set_current_user($other);
	foreach(range(1,14) as $step){$calls=count(Bhranti_QA_Model::$calls);$response=ru_tick($long);ru_check($response instanceof WP_REST_Response&&'prepared'===ru_state($long)['phase']&&$step===ru_state($long)['step'],'Background read planning advances one durable step');ru_check($calls+1===count(Bhranti_QA_Model::$calls),'Each read plan has exactly one native SDK call');ru_execute($long);ru_check('running'===ru_state($long)['status']&&''===ru_state($long)['phase'],'Separate native execute clears preparation while retaining task');$feedback=ru_feedback($long);ru_check(true===($feedback['success']??false)&&$ru_page===($feedback['result']['data']['id']??null),'Every deferred read receives actual successful native fixture data');}
	$response=ru_tick($long);ru_check($response instanceof WP_REST_Response&&'complete'===ru_state($long)['status']&&15===ru_state($long)['step'],'Fourteen reads plus final reply exceed the former twelve-step limit');
	$calls=count(Bhranti_QA_Model::$calls);ru_check(false===ru_tick($long)&&$calls===count(Bhranti_QA_Model::$calls),'Completed duplicate tick never calls the provider');

	// Stored preparation is consumed once even if the old execute request is replayed.
	$write=ru_start($owner);$writes=count($ru_native_writes);$create=array('method'=>'POST','path'=>'/wp/v2/pages','params'=>array('title'=>$ru_marker.' one native create','status'=>'draft','content'=>'<p>Exactly once</p>'));
	ru_prepare($write,$create);ru_check($writes===count($ru_native_writes),'Planning performs no native content write');wp_set_current_user($other);ru_execute($write);
	ru_check($writes+1===count($ru_native_writes)&&11===end($ru_native_writes)['user']&&true===end($ru_native_writes)['background'],'Exactly one native write runs as actor 11 inside server background scope');
	$created_ids=array();foreach($ru_pages as $ru_fixture_id=>$record){if($ru_marker.' one native create'===$record['title']){$created_ids[]=(int)$ru_fixture_id;}}ru_check(1===count($created_ids)&&'draft'===get_post_status($created_ids[0])&&11===(int)get_post_field('post_author',$created_ids[0])&&'<p>Exactly once</p>'===get_post_field('post_content',$created_ids[0]),'Exactly one real native draft exists with canonical content and actor author');
	wp_set_current_user($owner);$again=ru_rest('POST','/sessions/'.$write[0].'/execute',array('run_id'=>$write[1],'prepared_step'=>1,'unsaved_changes'=>false,'background'=>true));
	ru_check(200===$again->get_status()&&$writes+1===count($ru_native_writes)&&!Bhranti_Runner::is_background(),'Duplicate execute cannot replay write or set server-only background flag');ru_complete($write);

	// A watchdog refusal stops before native model or action dispatch.
	foreach(array('false'=>'pre_schedule_event_false','error'=>'bhranti_runner_qa_queue_failure') as $mode=>$code){$tuple=ru_start($owner);$calls=count(Bhranti_QA_Model::$calls);$writes=count($ru_native_writes);$ru_queue_mode=$mode;try{$failure=ru_tick($tuple);}finally{$ru_queue_mode='success';}ru_check(is_wp_error($failure)&&$code===$failure->get_error_code(),'Native failed queue returns its genuine safe error');ru_check($calls===count(Bhranti_QA_Model::$calls)&&$writes===count($ru_native_writes)&&''===ru_state($tuple)['phase'],'Watchdog failure prevents any provider or tool dispatch');}

	// Ownership, identifiers, author/status changes and current capability are fresh checks.
	$identity=ru_start($owner);$calls=count(Bhranti_QA_Model::$calls);$writes=count($ru_native_writes);wp_set_current_user($other);
	foreach(array(array($identity[0],$identity[1],$other),array($identity[0],wp_generate_uuid4(),$owner),array($ru_page,$identity[1],$owner)) as $tuple){$error=ru_tick($tuple);ru_check(is_wp_error($error)&&'bhranti_runner_owner'===$error->get_error_code(),'Wrong human, run or conversation is rejected before dispatch');}
	foreach(array(array('0'.$identity[0],$identity[1],$owner),array($identity[0],'A'.substr($identity[1],1),$owner),array($identity[0],$identity[1],$owner+0.5)) as $tuple){$error=ru_tick($tuple);ru_check(is_wp_error($error)&&'bhranti_runner_invalid'===$error->get_error_code(),'Noncanonical background identifiers are refused');}
	foreach(array(array('post_author'=>$other),array('post_status'=>'draft')) as $change){wp_update_post(array_merge(array('ID'=>$identity[0]),$change));$error=ru_tick($identity);ru_check(is_wp_error($error)&&'bhranti_runner_owner'===$error->get_error_code(),'Changed native conversation ownership or privacy stops continuation');wp_update_post(array('ID'=>$identity[0],'post_author'=>$owner,'post_status'=>'private'));}
	$cap=static function($caps,$required,$args,$user)use($owner){if($owner===$user->ID){$caps['manage_options']=false;}return $caps;};add_filter('user_has_cap',$cap,PHP_INT_MAX,4);try{$error=ru_tick($identity);}finally{remove_filter('user_has_cap',$cap,PHP_INT_MAX);}
	ru_check(is_wp_error($error)&&'bhranti_runner_permission'===$error->get_error_code(),'Revoked current administrator permission stops background work');
	ru_check($calls===count(Bhranti_QA_Model::$calls)&&$writes===count($ru_native_writes),'Identity and capability refusals perform no AI or core write');

	// A live ten-minute lock waits; an expired uncertain phase fails without replay.
	foreach(array('model','action') as $phase){$tuple=ru_start($owner);$state=ru_state($tuple);$state['phase']=$phase;update_post_meta($tuple[0],'bhranti_run',wp_slash($state));add_option('bhranti_lock_'.$owner,time()-590,'',false);ru_checkpoint();$calls=count(Bhranti_QA_Model::$calls);$writes=count($ru_native_writes);$queue=count($ru_queue);wp_set_current_user($other);ru_check(true===ru_tick($tuple)&&$phase===ru_state($tuple)['phase'],'Unfinished phase with active 600-second lock waits');ru_check($calls===count(Bhranti_QA_Model::$calls)&&$writes===count($ru_native_writes)&&$queue+1===count($ru_queue)&&end($ru_queue)['timestamp']>=time()+8,'Lock wait queues bounded watchdog without model or native write');update_option('bhranti_lock_'.$owner,time()-601,false);ru_checkpoint();$response=ru_tick($tuple);ru_check($response instanceof WP_REST_Response&&'failed'===ru_state($tuple)['status']&&!get_option('bhranti_lock_'.$owner),'Expired uncertain phase stops safely and releases only own lock');ru_check($calls===count(Bhranti_QA_Model::$calls)&&$writes===count($ru_native_writes)&&false===ru_tick($tuple),'Uncertain stopped task never replays model or write');}

	// Native deletion is only a proposal; server ticks never press the human button.
	$deletion=ru_start($owner);ru_prepare($deletion,array('method'=>'DELETE','path'=>'/wp/v2/pages/'.$ru_page,'params'=>array()));$writes=count($ru_native_writes);ru_execute($deletion);
	ru_check('awaiting_confirmation'===ru_state($deletion)['status']&&'draft'===get_post_status($ru_page)&&$writes===count($ru_native_writes),'Background deletion awaits explicit human confirmation without native DELETE');$calls=count(Bhranti_QA_Model::$calls);ru_check(false===ru_tick($deletion)&&$calls===count(Bhranti_QA_Model::$calls)&&'draft'===get_post_status($ru_page),'Awaiting confirmation cannot be auto-approved by later ticks');

	// Editor endpoint carries only bounded same-user state, never raw editor content.
	$token=wp_generate_uuid4();$key='bhranti_editor_state_'.get_current_blog_id();wp_set_current_user(0);ru_check(401===ru_editor($token,true,false)->get_status(),'Anonymous editor state is denied');
	wp_set_current_user($owner);$initial=get_user_meta($owner,$key,false);
	foreach(array(array('token'=>wp_generate_uuid4(),'editor_active'=>true,'unsaved_changes'=>false,'content'=>'private editor text'),array('token'=>wp_generate_uuid4(),'editor_active'=>true,'unsaved_changes'=>false,'requester_id'=>$other),array('token'=>'bad','editor_active'=>true,'unsaved_changes'=>false),array('token'=>wp_generate_uuid4(),'editor_active'=>true,'unsaved_changes'=>true,'release_stale'=>true)) as $body){$response=ru_rest('POST','/editor-state',$body);ru_check(400===$response->get_status()&&$initial===get_user_meta($owner,$key,false),'Malformed, extra-content and cross-identity heartbeat fields cannot persist');}
	$not_json=new WP_REST_Request('POST','/bhranti/v1/editor-state');$not_json->set_body_params(array('token'=>$token,'editor_active'=>true,'unsaved_changes'=>false));$response=Bhranti_Runner::editor_state($not_json);ru_check(is_wp_error($response)&&'bhranti_editor_invalid'===$response->get_error_code(),'Editor callback requires a real native JSON body');
	foreach(array('true',1,null) as $bad_bool){$bad_json=new WP_REST_Request('POST','/bhranti/v1/editor-state');$bad_json->set_header('Content-Type','application/json');$bad_json->set_body(wp_json_encode(array('token'=>$token,'editor_active'=>$bad_bool,'unsaved_changes'=>false)));$response=Bhranti_Runner::editor_state($bad_json);ru_check(is_wp_error($response)&&'bhranti_editor_invalid'===$response->get_error_code()&&$initial===get_user_meta($owner,$key,false),'Editor callback refuses nonboolean native JSON values without storage');}
	$response=ru_editor($token,true,false);$public=$response->get_data();ru_check(200===$response->get_status()&&array('write_blocked','active','dirty','stale_dirty')===array_keys($public)&&!str_contains(wp_json_encode($public),$token),'Public heartbeat returns only four safe aggregate booleans');
	$stored=get_user_meta($owner,$key,true);ru_check(array('active','dirty','expires')===array_keys($stored[$token])&&is_int($stored[$token]['expires'])&&$stored[$token]['expires']<=time()+120,'Stored own tab marker contains only booleans and bounded expiration');
	ru_check(Bhranti_Runner::editor_blocked($owner,true)&&!Bhranti_Runner::editor_blocked($owner,false)&&!Bhranti_Runner::editor_dirty($owner),'Clean active editor protects background writes and permits reads');
	$reader=ru_start($owner);ru_prepare($reader,$get);ru_execute($reader);ru_complete($reader);
	$paused=ru_start($owner);ru_prepare($paused,$update('<p>After clean editor closes</p>'));$before_content=get_post_field('post_content',$ru_page);$writes=count($ru_native_writes);wp_set_current_user($other);ru_check(true===ru_tick($paused)&&'prepared'===ru_state($paused)['phase']&&$before_content===get_post_field('post_content',$ru_page)&&$writes===count($ru_native_writes),'Active clean editor pauses actual prepared background write');wp_set_current_user($owner);ru_check(200===ru_editor($token,false,false)->get_status(),'Same tab closes its clean editor');ru_execute($paused);ru_check('<p>After clean editor closes</p>'===get_post_field('post_content',$ru_page),'Closing clean editor releases exact prepared write');ru_complete($paused);

	$stale=wp_generate_uuid4();ru_seed_editor($owner,array($stale=>array('active'=>true,'dirty'=>true,'expires'=>time()-1)));$status=Bhranti_Runner::editor_status($owner);ru_check($status['dirty']&&$status['stale_dirty']&&$status['write_blocked']&&!$status['active'],'Expired dirty tab remains sticky while active state expires');
	$sticky=ru_start($owner);ru_prepare($sticky,$update('<p>After explicit stale release</p>'));$before_content=get_post_field('post_content',$ru_page);wp_set_current_user($other);ru_editor(wp_generate_uuid4(),false,false,true);ru_check(Bhranti_Runner::editor_dirty($owner)&&true===ru_tick($sticky)&&$before_content===get_post_field('post_content',$ru_page),'Another administrator cannot release owner stale work or execute its paused write');wp_set_current_user($owner);$released=ru_editor(wp_generate_uuid4(),false,false,true);ru_check(200===$released->get_status()&&!$released->get_data()['write_blocked']&&!get_user_meta($owner,$key,false),'Explicit same-user clean stale release removes only own expired markers');ru_execute($sticky);ru_check('<p>After explicit stale release</p>'===get_post_field('post_content',$ru_page),'Own stale release permits the saved canonical background action');ru_complete($sticky);
	ru_seed_editor($owner,array(wp_generate_uuid4()=>array('active'=>true,'dirty'=>false,'expires'=>time()-1)));ru_check(!Bhranti_Runner::editor_blocked($owner,true),'Expired clean editor is harmless without explicit stale release');ru_editor(wp_generate_uuid4(),false,false);ru_check(!get_user_meta($owner,$key,false),'Next own heartbeat prunes expired clean marker');
	$twenty=array();foreach(range(1,20) as $unused){$twenty[wp_generate_uuid4()]=array('active'=>true,'dirty'=>false,'expires'=>time()+120);}ru_seed_editor($owner,$twenty);ru_check(409===ru_editor(wp_generate_uuid4(),true,false)->get_status()&&$twenty===get_user_meta($owner,$key,true),'Twenty-tab bound refuses additional marker without changing prior state');
	add_user_meta($owner,$key,$twenty,false);ru_check(is_wp_error(Bhranti_Runner::editor_status($owner))&&Bhranti_Runner::editor_blocked($owner,true)&&!Bhranti_Runner::editor_blocked($owner,false),'Ambiguous duplicate metadata fails closed for writes while reads remain permitted');ru_seed_editor($owner,array());
	wp_set_current_user(11);$actor_request=new WP_REST_Request('POST','/bhranti/v1/editor-state');$actor_request->set_header('Content-Type','application/json');$actor_request->set_body(wp_json_encode(array('token'=>wp_generate_uuid4(),'editor_active'=>true,'unsaved_changes'=>false)));$actor_result=Bhranti_Runner::editor_state($actor_request);ru_check(is_wp_error($actor_result)&&'bhranti_editor_permission'===$actor_result->get_error_code(),'Managed actor cannot own human editor state');wp_set_current_user($owner);

	// Recheck server-side editor state after Runner's early check, at native execute.
	$race=ru_start($owner);ru_prepare($race,$update('<p>Editor race must pause</p>'));$before_content=get_post_field('post_content',$ru_page);$writes=count($ru_native_writes);$racing_token=wp_generate_uuid4();
	$late_editor=static function($pre,$server,$request)use($race,$owner,$racing_token){if('/bhranti/v1/sessions/'.$race[0].'/execute'===$request->get_route()){ru_seed_editor($owner,array($racing_token=>array('active'=>true,'dirty'=>false,'expires'=>time()+120)));}return $pre;};
	add_filter('rest_pre_dispatch',$late_editor,PHP_INT_MAX,3);try{$wait=ru_tick($race);}finally{remove_filter('rest_pre_dispatch',$late_editor,PHP_INT_MAX);}
	ru_check(true===$wait&&'prepared'===ru_state($race)['phase']&&$before_content===get_post_field('post_content',$ru_page)&&$writes===count($ru_native_writes),'Final server background guard catches an editor heartbeat arriving after initial check');ru_seed_editor($owner,array());ru_execute($race);ru_complete($race);

	// Execute-time policy is authoritative even after a previously allowed plan.
	$policy=ru_start($owner);ru_prepare($policy,$update('<p>Policy must block this</p>'));$before_content=get_post_field('post_content',$ru_page);$writes=count($ru_native_writes);ru_policy(false);ru_execute($policy);$history=ru_state($policy)['history'];ru_check(str_contains(end($history)['content'],'bhranti_action_disabled')&&$before_content===get_post_field('post_content',$ru_page)&&$writes===count($ru_native_writes),'Current policy change between prepare and execute blocks native write');ru_policy(true);ru_complete($policy);

	// The server dispatch scope and original identity survive an uncaught REST exception.
	$exception=ru_start($owner);$calls=count(Bhranti_QA_Model::$calls);wp_set_current_user($other);$throw=static function($pre,$server,$request)use($exception){if('/bhranti/v1/sessions/'.$exception[0].'/step'===$request->get_route()){throw new RuntimeException('Own Runner QA dispatch exception.');}return $pre;};add_filter('rest_pre_dispatch',$throw,PHP_INT_MAX,3);$caught=false;try{ru_tick($exception);}catch(RuntimeException $error){$caught='Own Runner QA dispatch exception.'===$error->getMessage();}finally{remove_filter('rest_pre_dispatch',$throw,PHP_INT_MAX);}ru_check($caught&&$other===get_current_user_id()&&!Bhranti_Runner::is_background()&&$calls===count(Bhranti_QA_Model::$calls),'Uncaught native dispatch exception restores human and server flag before propagating');
	ru_check(count($ru_queue)>0&&count($ru_clear)>0,'Native queue and exact clear hooks were exercised');
	foreach($ru_queue as $queued){$args=$queued['args'];ru_check(3===count($args)&&is_int($args[0])&&is_string($args[1])&&36===strlen($args[1])&&is_int($args[2])&&in_array($args[0],$ru_sessions,true)&&in_array($args[2],$ru_users,true)&&true===$queued['wp_error'],'Each queue contains exact owned [session, UUID, requester] native tuple');}
	foreach($ru_clear as $args){ru_check(3===count($args)&&in_array($args[0],$ru_sessions,true)&&is_string($args[1])&&is_int($args[2]),'Clear interception is scoped to exact fresh session tuples');}
	ru_check(0===$ru_http,'All Runner/provider/REST work remained offline');
} catch (Throwable $error) { $ru_failure=$error; }
finally {
	$ru_queue_mode='success';remove_action('save_post',$ru_capture,PHP_INT_MAX);remove_action('user_register',$ru_capture_user,PHP_INT_MAX);wp_set_current_user($ru_original_user);
	foreach($ru_pages as $ru_fixture_id=>$identity){$post=get_post($ru_fixture_id);if(!$post){continue;}$children=$wpdb->get_results($wpdb->prepare("SELECT ID,post_type FROM {$wpdb->posts} WHERE post_parent=%d",$ru_fixture_id),ARRAY_A);$safe=!in_array((int)$ru_fixture_id,$ru_prior_ids,true)&&'page'===$post->post_type&&$identity['author']===(int)$post->post_author&&$identity['title']===$post->post_title&&str_starts_with($post->post_title,$ru_marker)&&in_array($post->post_status,array('draft','trash'),true);foreach($children as $child){$safe=$safe&&!in_array((int)$child['ID'],$ru_prior_ids,true)&&'revision'===$child['post_type'];}if($safe){if(!wp_delete_post($ru_fixture_id,true)||get_post($ru_fixture_id)){$ru_clean=false;}}else{$ru_clean=false;}}
	foreach($ru_users as $ru_fixture_id){$user=get_user_by('id',$ru_fixture_id);$rows=$wpdb->get_results($wpdb->prepare("SELECT ID,post_type,post_status,post_parent FROM {$wpdb->posts} WHERE post_author=%d",$ru_fixture_id),ARRAY_A);$safe=!in_array((int)$ru_fixture_id,$ru_prior_users,true)&&$user&&str_starts_with($user->user_login,$ru_login_prefix)&&str_ends_with($user->user_email,'@example.test');foreach($rows as $row){$safe=$safe&&!in_array((int)$row['ID'],$ru_prior_ids,true)&&in_array($row['post_type'],array('bhranti_session','bhranti_event'),true)&&'private'===$row['post_status'];}if(!$safe){$ru_clean=false;continue;}foreach($rows as $row){if(!wp_delete_post((int)$row['ID'],true)||get_post((int)$row['ID'])){$ru_clean=false;}}delete_option('bhranti_lock_'.$ru_fixture_id);require_once ABSPATH.'wp-admin/includes/user.php';if(!wp_delete_user($ru_fixture_id)||get_user_by('id',$ru_fixture_id)){$ru_clean=false;}}
	if($ru_latest_config){$ru_current_settings_raw=$wpdb->get_row("SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name='bhranti_settings'",ARRAY_A);if($ru_current_settings_raw&&maybe_unserialize($ru_current_settings_raw['option_value'])===$ru_latest_config){$raw=null;foreach($ru_options_before as $option){if('bhranti_settings'===$option['option_name']){$raw=$option;break;}}if(null===$raw){$wpdb->delete($wpdb->options,array('option_name'=>'bhranti_settings'),array('%s'));}else{$wpdb->update($wpdb->options,array('option_value'=>$raw['option_value'],'autoload'=>$raw['autoload']),array('option_name'=>'bhranti_settings'),array('%s','%s'),array('%s'));}foreach(array('bhranti_settings','alloptions','notoptions') as $cache_key){wp_cache_delete($cache_key,'options');}}else{$ru_clean=false;$ru_failure=new RuntimeException('Settings changed outside fixtures; current human configuration preserved.');}}
	if($ru_connector){WP_Connector_Registry::get_instance()->unregister('bhranti-qa');}$ru_property->setValue(null,$ru_registry);AiClient::setEventDispatcher($ru_dispatcher);AiClient::setCache($ru_cache);
	remove_filter('rest_pre_dispatch',$ru_write_guard,PHP_INT_MAX);remove_filter('pre_schedule_event',$ru_schedule,PHP_INT_MAX);remove_filter('pre_clear_scheduled_hook',$ru_unschedule,PHP_INT_MAX);remove_filter('pre_http_request',$ru_http_guard,PHP_INT_MAX);remove_filter('pre_wp_mail','__return_true',PHP_INT_MAX);restore_error_handler();
	$ru_after=ru_baseline();$ru_preserved=$ru_before===$ru_after;$ru_changed=array();foreach($ru_before as $key=>$value){if($value!==$ru_after[$key]){$ru_changed[]=$key;}}$ru_after_rows=ru_row_hashes($ru_after);$ru_changed_rows=array();foreach($ru_row_baseline as $group=>$hashes){foreach(array_unique(array_merge(array_keys($hashes),array_keys($ru_after_rows[$group]))) as $key){if(($hashes[$key]??null)!==($ru_after_rows[$group][$key]??null)){$ru_changed_rows[]=array('group'=>$group,'id'=>$key);}}}
	if($ru_clean&&$ru_preserved){unlink($ru_checkpoint);}echo wp_json_encode(array('checks'=>$ru_checks,'all_original_content_users_actor_cron_options_uploads_preserved'=>$ru_preserved,'fixtures_removed'=>$ru_clean,'http_calls'=>$ru_http,'changed_baseline_groups'=>$ru_changed,'changed_rows'=>$ru_changed_rows)) ,"\n";
}
if($ru_failure||!$ru_clean||!$ru_preserved){fwrite(STDERR,$ru_failure?$ru_failure->getMessage()."\n":"Cleanup or preservation failed; private checkpoint retained.\n");exit(1);}
echo 'PASS '.$ru_checks." offline native Runner/editor checks\n";
