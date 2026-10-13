/**
 * Isolated sidebar recovery checks. Requires jsdom on Node's module search path.
 * Run: node tests/admin-recovery.cjs
 * No WordPress bootstrap, HTTP requests, provider calls or database writes.
 */
const assert=require('node:assert/strict');const fs=require('node:fs');const {JSDOM}=require('jsdom');
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/admin.js'),'utf8').replace('syncTrigger(); if (settings.autoOpen) openPanel();','window.review={state,continueRun,resumeTask,reconnectChat,request,render,actionInFlight}; syncTrigger(); if (settings.autoOpen) openPanel();');
const history=[{role:'user',content:'Keep working on the existing task'},{role:'assistant',content:'Created the first artifact'}],events=[{id:'past',type:'action',action_id:'artifact',summary:'Draft created',target:{id:99,type:'page',title:'Draft',url:'/wp-admin/post.php?post=99&action=edit'}}];
const session=(status='running',extras={})=>({id:17,agent_id:'bhranti',messages:history,events,run:{id:'same-run',status,step:3,phase:'',...extras}});
function fixture(loaded,handler,control=false){const dom=new JSDOM('<div id="wp-admin-bar-bhranti"><a href="#">Bhranti</a></div><main id="wpbody-content"><button>Native page action</button></main><div id="bhranti-app" hidden></div>',{url:'https://bhranti.wp.local/wp-admin/index.php?bhranti_session=17',runScripts:'outside-only',pretendToBeVisual:true});const w=dom.window;let clock=1000000;const requests=[],waits=[],deadlines=new Map();let timerSequence=0;const nativeTimeout=w.setTimeout.bind(w),nativeClear=w.clearTimeout.bind(w);w.clearTimeout=id=>{if(!deadlines.delete(id))nativeClear(id);};w.Date.now=()=>clock;w.setTimeout=(fn,delay,...args)=>{if(delay>=20000){const id='deadline-'+(++timerSequence);deadlines.set(id,{fn,at:clock+delay,delay});return id;}if(delay>0&&delay<=1500){waits.push(fn);return 100000+waits.length}return nativeTimeout(fn,delay,...args)};if(control){const frame=w.document.querySelector('#wpbody-content'),root=w.document.querySelector('#bhranti-app');frame.getBoundingClientRect=()=>({left:160,top:32,right:1280,bottom:900});root.getBoundingClientRect=()=>({left:930,top:32,right:1280,bottom:900});}w.bhrantiSettings={autoOpen:true,isAdmin:true,connectorsUrl:'https://bhranti.wp.local/wp-admin/options-connectors.php',context:{screen:'dashboard'}};w.sessionStorage.setItem('bhranti-admin-open:https://bhranti.wp.local/wp-admin/options-connectors.php:0:draft:17','Unsent follow-up');w.wp={i18n:{__:(s)=>s},apiFetch:async req=>{requests.push({...req,at:clock});if(req.path.endsWith('/workspace'))return {sessions:[{id:17,title:'Task'}],agents:[{id:'bhranti',name:'Bhranti'}],ai:{available:true}};return handler(req,loaded,clock,requests)}};w.eval(source);return {dom,w,requests,advance(ms){clock+=ms;for(const[id,entry]of[...deadlines])if(entry.at<=clock){deadlines.delete(id);entry.fn();}const next=waits.shift();if(next)next()},waits,deadlines}}
const flush=()=>new Promise(r=>setTimeout(r,5));async function until(fn){for(let i=0;i<100;i++){if(fn())return;await flush()}throw new Error('Timed out')};const count=(p,s)=>p.requests.filter(r=>r.path.endsWith(s)).length;const idle=p=>!p.w.review.state.loading&&!p.w.review.state.busy&&!p.w.review.state.stepping;const button=(p,t)=>[...p.w.document.querySelectorAll('button')].find(b=>b.textContent===t);
(async()=>{
 let reads=0;const due=1005;const retry=fixture(session('running',{retry_at:due,request_retries:1}),(req,loaded,clock)=>{if(req.method==='GET')return ++reads===1?loaded:session('running',{retry_at:0,request_retries:1});assert.ok(clock>=due*1000,'No request may retry before the server deadline');assert.ok(reads>=2,'Read server state after due before planning');assert.equal(req.data.run_id,'same-run');return session('complete')});await until(()=>retry.waits.length>0);assert.match(retry.w.document.querySelector('.bhranti-run-status').textContent,/Retrying connection in 5s/);assert.equal(count(retry,'/step'),0);retry.advance(2000);await until(()=>retry.waits.length>0);assert.match(retry.w.document.querySelector('.bhranti-run-status').textContent,/Retrying connection in 3s/);assert.equal(count(retry,'/step'),0);retry.advance(4000);await until(()=>idle(retry));assert.equal(count(retry,'/step'),1);assert.deepEqual(Array.from(retry.w.review.state.session.events,e=>e.id),['past']);assert.equal(retry.w.review.state.draft,'Unsent follow-up');retry.dom.window.close();
 const stopped=fixture(session('running',{retry_at:1060,request_retries:2}),(req,loaded)=>req.method==='GET'?loaded:session('cancelled'));await until(()=>stopped.waits.length>0);button(stopped,'Stop').click();stopped.advance(0);await until(()=>idle(stopped));assert.equal(count(stopped,'/cancel'),1);assert.equal(count(stopped,'/step'),0);assert.equal(count(stopped,'/execute'),0);stopped.dom.window.close();
 let resumeCalls=0;const recovery=fixture(session('failed',{can_resume:true,request_retries:3}),(req,loaded)=>{if(req.method==='GET')return loaded;if(req.path.endsWith('/resume')){resumeCalls++;assert.deepEqual(Object.keys(req.data),['run_id']);assert.equal(req.data.run_id,'same-run');return session('running',{request_retries:0})}if(req.path.endsWith('/step'))return session('complete');throw new Error('Unexpected action')});await until(()=>idle(recovery));assert.ok(button(recovery,'Resume task'));button(recovery,'Resume task').click();recovery.w.review.resumeTask();await until(()=>resumeCalls===1&&idle(recovery));assert.equal(count(recovery,'/resume'),1);assert.equal(count(recovery,'/messages'),0);assert.equal(count(recovery,'/sessions'),0);assert.deepEqual(Array.from(recovery.w.review.state.session.messages,m=>m.content),history.map(m=>m.content));assert.equal(recovery.w.review.state.draft,'Unsent follow-up');recovery.dom.window.close();
 const unsafe=fixture(session('failed',{can_resume:false}),(req,loaded)=>loaded);await until(()=>idle(unsafe));assert.equal(button(unsafe,'Resume task'),undefined);unsafe.dom.window.close();
 let fail=true;const failed=fixture(session('failed',{can_resume:true}),(req,loaded)=>{if(req.method==='GET')return loaded;if(req.path.endsWith('/resume'))throw new Error('Recovery request failed');throw new Error('Must not replay actions')});await until(()=>idle(failed));button(failed,'Resume task').click();await until(()=>failed.w.review.state.error&&idle(failed));assert.equal(count(failed,'/resume'),1);assert.equal(count(failed,'/step'),0);assert.match(failed.w.document.body.textContent,/Recovery request failed/);failed.dom.window.close();
 // A dropped POST can remain pending forever even after Cron reaches a terminal checkpoint.
 for(const terminal of ['failed','complete','awaiting_input']){
  let reads=0,resolveLate;const held=new Promise(resolve=>{resolveLate=resolve});
  const page=fixture(session('running',{step:10}),(req,loaded)=>{if(req.method==='GET')return ++reads===1?loaded:session(terminal,{step:13,can_resume:terminal==='failed'});if(req.path.endsWith('/step'))return held;throw new Error('Unknown POST must never replay.');});
  try{
   await until(()=>count(page,'/step')===1);const post=page.requests.find(req=>req.path.endsWith('/step'));
   assert.ok(post.signal,'Every mutating response has an abort signal');
   page.advance(239999);await flush();assert.equal(idle(page),false,'Allow native image generation up to 180 seconds plus save overhead');assert.equal(reads,1);
   page.advance(1);await until(()=>idle(page));
   assert.equal(post.signal.aborted,true);assert.equal(page.w.review.state.session.run.status,terminal);assert.equal(page.w.review.state.session.run.step,13);
   assert.equal(count(page,'/step'),1);assert.equal(count(page,'/execute'),0);assert.equal(count(page,'/resume'),0);assert.equal(reads,2,'One authoritative owned read reconciles the ambiguous response');
   assert.equal(page.w.review.state.progress,null);assert.equal(page.w.document.getElementById('bhranti-new-chat').disabled,false);assert.equal(page.w.review.state.draft,'Unsent follow-up');
   assert.equal([...page.w.document.querySelectorAll('button')].filter(node=>node.textContent==='Resume task').length,terminal==='failed'?1:0);
   assert.equal(page.deadlines.size,0,'Resolved requests leave no response deadlines behind');
   resolveLate(session('running',{step:10}));await flush();assert.equal(page.w.review.state.session.run.status,terminal,'Late pre-timeout response cannot overwrite the authoritative terminal checkpoint');
  }finally{resolveLate(session('complete'));page.dom.window.close();}
 }
 // A lost execute response never authorizes replay or handover of a still-unknown write.
 for(const observed of ['action','prepared','unavailable','other-run']){
  const action={tool:'wp_request',method:'POST',path:'/wp/v2/pages/99',summary:'Updating the page.',write:true,target:{id:99,type:'page',title:'Draft',url:'/wp-admin/post.php?post=99&action=edit'}};
  let reads=0,reconnected=false,resolveLate;const held=new Promise(resolve=>{resolveLate=resolve});
  const page=fixture(session('running',{step:10,phase:'prepared',next_action:action}),(req,loaded)=>{
   if(req.method==='GET'){reads++;if(reads===1)return loaded;if(reconnected)return session('complete',{step:11});if(observed==='unavailable')throw Object.assign(new Error('Status unavailable'),{data:{status:403}});if(observed==='other-run')return session('failed',{id:'different-run',step:13,can_resume:true});return session('running',{step:10,phase:observed,next_action:action});}
   if(req.path.endsWith('/execute'))return held;throw new Error('An unconfirmed action must not be replayed or cancelled.');
  },true);
  try{
   await until(()=>count(page,'/execute')===1);assert.ok(page.w.document.querySelector('.bhranti-site-control'));
   page.advance(240000);await until(()=>idle(page));
   assert.ok(page.w.review.state.error);assert.ok(page.w.document.querySelector('.bhranti-site-control'),'Unknown action retains the existing overlay until authoritative handover');
   assert.equal(page.w.review.state.session.run.id,'same-run','A mismatched run response cannot overwrite the owning checkpoint');
   assert.equal(count(page,'/execute'),1);assert.equal(count(page,'/step'),0);assert.equal(count(page,'/cancel'),0);assert.equal(page.w.review.state.progress,null);
   if(observed!=='action'){await page.w.review.continueRun();assert.equal(count(page,'/execute'),1,'An unconfirmed prepared operation cannot replay');assert.equal(count(page,'/cancel'),0);}
   reconnected=true;await page.w.review.reconnectChat();await until(()=>idle(page));
   assert.equal(page.w.review.state.session.run.status,'complete');assert.equal(page.w.review.state.stopRequested,false);assert.equal(page.w.document.querySelector('.bhranti-site-control'),null,'Only verified handover releases site control');
   assert.equal(count(page,'/execute'),1);assert.equal(count(page,'/step'),0);assert.equal(count(page,'/cancel'),0);
   resolveLate(session('running',{step:10,phase:'prepared',next_action:action}));await flush();assert.equal(page.w.review.state.session.run.status,'complete');
  }finally{resolveLate(session('complete'));page.dom.window.close();}
 }
 // A prepared action that never reached PHP remains cancellable by the owning human.
 for(const timing of ['before-timeout','after-timeout','busy-cancel']){
  let reads=0,resolveLate;const held=new Promise(resolve=>{resolveLate=resolve});
  const action={tool:'wp_request',method:'POST',path:'/wp/v2/pages/99',summary:'Updating the page.',write:true,target:events[0].target};
  const page=fixture(session('running',{step:10,phase:'prepared',next_action:action}),(req,loaded)=>{
   if(req.method==='GET'){reads++;return loaded;}
   if(req.path.endsWith('/execute'))return held;
   if(req.path.endsWith('/cancel')){assert.equal(req.data.run_id,'same-run');if(timing==='busy-cancel')throw Object.assign(new Error('Native execute owns the lock.'),{code:'bhranti_busy',data:{status:409}});return session('cancelled',{step:10});}
   throw new Error('Cancellation cannot repeat the prepared action.');
  },true);
  try{
   await until(()=>count(page,'/execute')===1);
   if(timing!=='after-timeout'){button(page,'Stop').click();await flush();assert.ok(page.w.document.querySelector('.bhranti-site-control'),'Explicit Stop preserves control until native cancellation acknowledgement');}
   page.advance(240000);await until(()=>idle(page));
   if(timing==='after-timeout'){assert.equal(count(page,'/cancel'),0);button(page,'Stop').click();await until(()=>idle(page));}
   assert.equal(count(page,'/cancel'),1,'Dropped-before-server execute permits one native serialized cancellation');assert.equal(count(page,'/execute'),1);assert.equal(count(page,'/step'),0);
   if(timing==='busy-cancel'){assert.equal(page.w.review.state.session.run.status,'running');assert.ok(page.w.document.querySelector('.bhranti-site-control'));await flush();assert.equal(count(page,'/cancel'),1,'Busy cancellation cannot turn into a retry loop');}
   else{assert.equal(page.w.review.state.session.run.status,'cancelled');assert.equal(page.w.document.querySelector('.bhranti-site-control'),null);assert.equal(page.w.review.state.stopRequested,false);}
  }finally{resolveLate(session('complete'));page.dom.window.close();}
 }
 // A Stop belongs only to its original run, even when Reconnect discovers a newer task.
 {
  let reads=0,reconnected=false,resolveLate;const held=new Promise(resolve=>{resolveLate=resolve});
  const action={tool:'wp_request',method:'POST',path:'/wp/v2/pages/99',summary:'Updating the page.',write:true,target:events[0].target};
  const page=fixture(session('running',{step:10,phase:'prepared',next_action:action}),(req,loaded)=>{
   if(req.method==='GET'){reads++;if(reads===1)return loaded;if(!reconnected)return session('running',{id:'new-run',phase:'action',step:12});return session(reads===3?'running':'complete',{id:'new-run',phase:reads===3?'action':'',step:12});}
   if(req.path.endsWith('/execute'))return held;throw new Error('A stale Stop must never cancel the newer run.');
  },true);
  try{
   await until(()=>count(page,'/execute')===1);button(page,'Stop').click();page.advance(240000);await until(()=>idle(page));assert.equal(page.w.review.state.session.run.id,'same-run');assert.equal(count(page,'/cancel'),0);
   reconnected=true;await page.w.review.reconnectChat();await flush();assert.equal(page.w.review.state.session.run.id,'new-run');assert.equal(page.w.review.state.stopRequested,false,'Accepting another run discards stale Stop ownership');assert.equal(count(page,'/cancel'),0);
   page.advance(1500);await until(()=>idle(page));assert.equal(page.w.review.state.session.run.status,'complete');assert.equal(count(page,'/execute'),1);assert.equal(count(page,'/cancel'),0);assert.equal(count(page,'/step'),0);
  }finally{resolveLate(session('complete'));page.dom.window.close();}
 }
 // Shared transport bounds do not retry create/message/confirmation/resume requests.
 const noReplay=fixture(session('complete'),(req,loaded)=>req.method==='GET'?loaded:new Promise(()=>{}));
 try{
  await until(()=>idle(noReplay));
  for(const route of ['/sessions','/sessions/17/messages','/sessions/17/confirm','/sessions/17/resume']){
   const before=noReplay.requests.length;let error;const operation=noReplay.w.review.request(route,'POST',{}).catch(value=>{error=value;});await flush();noReplay.advance(240000);await operation;
   assert.equal(error.code,'bhranti_response_timeout');assert.equal(noReplay.requests.length,before+1);assert.equal(noReplay.deadlines.size,0);
  }
 }finally{noReplay.dom.window.close();}
 console.log('PASS: server retry deadline countdown, no premature step/execute, fresh read after due, responsive Stop, explicit safe-failure Resume once, same history/artifacts/draft retained, unsafe failure hidden, failed resume never replays actions; 240-second ambiguous POST reconciliation, terminal recovery, stale late-response refusal, uncertain action control and same-run binding');
})().catch(e=>{console.error(e);process.exitCode=1});
