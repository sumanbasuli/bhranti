'use strict';
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname,'../assets/admin.js'),'utf8');
const helper = source.match(/\tfunction paintPreparedAction\(\) \{[\s\S]+?(?=\n\tfunction abortRender)/)[0];
let checks = 0;
(async()=>{
 for (const hidden of [false,true]) {
  let timers = new Map(), frames = new Map(), next=0, delay;
  const context={window:{setTimeout:(fn,ms)=>{delay=ms;timers.set(++next,fn);return next;},clearTimeout:id=>timers.delete(id),requestAnimationFrame:fn=>{frames.set(++next,fn);return next;},cancelAnimationFrame:id=>frames.delete(id)}};
  vm.createContext(context);vm.runInContext(helper+'\nthis.paint=paintPreparedAction;',context);
  let settled=false;const promise=context.paint().then(()=>{settled=true;});
  assert.equal(delay,250);checks++;assert.equal(settled,false);checks++;
  if(hidden){[...timers.values()][0]();}else{const first=[...frames.entries()][0];frames.delete(first[0]);first[1]();const second=[...frames.entries()][0];frames.delete(second[0]);second[1]();}
  await promise;assert.equal(settled,true);checks++;assert.equal(timers.size,0);checks++;assert.equal(frames.size,0);checks++;
 }
 console.log('PASS '+checks+' prepared-action paint checks; hidden frames cannot stall execution, no browser/network/AI.');
})().catch(error=>{console.error(error);process.exitCode=1;});
