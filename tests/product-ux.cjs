/** Isolated product UI checks: real admin.js, JSDOM, deterministic API/timer mocks. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
let JSDOM;
try { ({ JSDOM } = require('jsdom')); }
catch { ({ JSDOM } = require(process.env.BHRANTI_TEST_JSDOM_PATH || '/Users/txsadhu/node_modules/jsdom')); }

const scriptPath = path.join(process.env.BHRANTI_TEST_PLUGIN_ROOT || path.dirname(__dirname), 'assets/admin.js');
const source = fs.readFileSync(scriptPath, 'utf8');
const exposedNames = [...source.matchAll(/^\t(?:async )?function (\w+)\(/gm)].map((match) => match[1]);
const marker = 'syncTrigger(); if (settings.autoOpen) openPanel();';
assert.equal(source.split(marker).length, 2, 'Instrument only the known final initializer');
const instrumented = source
  .replace(/window\.location\.assign\(([^;]+)\);/g, 'window.review.navigation.push({ kind: "assign", url: $1 });')
  .replace(/window\.location\.reload\([^;]*\);/g, 'window.review.navigation.push({ kind: "reload", url: window.location.href });')
  .replace(marker, 'window.review = { state, ' + exposedNames.join(', ') + ', navigation: [] }; ' + marker);
const runId = '90000000-1000-4000-8000-100000000017';
const historical = { id: 'old-action', type: 'action', action_id: 'old-snapshot', summary: 'Earlier draft updated.', target: { id: 99, type: 'page', title: 'Fixture page', url: '/wp-admin/post.php?post=99&action=edit' }, created: '2026-10-09T00:00:01Z' };
const message = { role: 'user', content: 'The local test task.', created: '2026-10-09T00:00:00Z' };
function session(status = 'complete', extra = {}) {
  return { id: 17, agent_id: 'bhranti', messages: [message], events: [historical], run: { id: runId, status, phase: '', step: 3, background: true, editor: { dirty: false, active: false, write_blocked: false }, ...extra } };
}
function fixture(options = {}) {
  const dom = new JSDOM('<!doctype html><div id="wp-admin-bar-bhranti"><a href="#">Bhranti</a></div><div id="wpbody-content"><form id="native-form"><input id="native-field" value="Unsaved native text"></form></div><div id="bhranti-app" hidden></div>', { url: options.url || 'https://bhranti.wp.local/wp-admin/edit.php?post_type=page', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  let now = 1791500000000, sequence = 0;
  const timers = new Map(), requests = [], notices = [], subscriptions = [];
  let localDirty = false, remoteEditor = { dirty: false, active: false, write_blocked: false, stale_dirty: false };
  const timer = (callback, delay = 0, interval = false) => { const id = ++sequence; timers.set(id, { callback, delay, at: now + delay, interval }); return id; };
  w.Date.now = () => now;
  w.setTimeout = (callback, delay) => timer(callback, delay);
  w.clearTimeout = (id) => timers.delete(id);
  w.setInterval = (callback, delay) => timer(callback, delay, true);
  w.clearInterval = (id) => timers.delete(id);
  w.requestAnimationFrame = (callback) => timer(() => callback(now), 16);
  w.cancelAnimationFrame = w.clearTimeout;
  w.fetch = () => { throw new Error('The isolated UI suite must not make an HTTP request.'); };
  w.confirm = () => true;
  w.open = (url) => { w.review.navigation.push({ kind: 'open', url }); return null; };
  Object.defineProperty(w.HTMLElement.prototype, 'scrollHeight', { configurable: true, get() { return this.classList.contains('bhranti-stream') ? 1000 : 0; } });
  Object.defineProperty(w.HTMLElement.prototype, 'clientHeight', { configurable: true, get() { return this.classList.contains('bhranti-stream') ? 200 : 0; } });
  w.bhrantiSettings = { autoOpen: false, isAdmin: options.isAdmin !== false, userId: 1, backgroundEnabled: !!options.background, connectorsUrl: 'https://bhranti.wp.local/wp-admin/options-connectors.php', settingsUrl: '/wp-admin/admin.php?page=bhranti', context: { screen: options.editor ? 'page' : 'edit-page', ...(options.context || {}) } };
  const workspace = { sessions: [{ id: 17, title: 'First chat' }, { id: 18, title: 'Second chat' }], agents: [{ id: 'bhranti', name: 'Bhranti' }], user: { name: 'Fixture requester' }, ai: { available: true } };
  let handler = options.handler || (async (request) => {
    if (request.path.endsWith('/workspace')) return workspace;
    if (request.path.endsWith('/editor-state')) return remoteEditor;
    if (request.method === 'GET' && /\/sessions\/(17|18)$/.test(request.path)) return { ...session(), id: Number(request.path.split('/').pop()) };
    throw new Error('Unexpected fixture request: ' + request.method + ' ' + request.path);
  });
  w.wp = {
    i18n: { __: (text) => text, sprintf: (text, ...values) => { let index = 0; return text.replace(/%[sd]/g, () => values[index++]); } },
    data: {
      subscribe: (callback) => subscriptions.push(callback),
      select: (store) => store === 'core/editor' && options.editor ? { getCurrentPostId: () => 99, getCurrentPostType: () => 'page', isEditedPostDirty: () => localDirty, getEditedPostAttribute: (name) => name === 'title' ? 'Fixture page' : '' } : undefined,
      dispatch: () => ({ createNotice: (type, text, details) => notices.push({ type, text, ...details }), removeNotice: (id) => { const index = notices.findIndex((notice) => notice.id === id); if (index >= 0) notices.splice(index, 1); } }),
    },
    notices: { store: 'core/notices' },
    apiFetch: async (request) => { requests.push({ ...request, at: now }); return handler(request); },
  };
  w.eval(instrumented);
  const api = w.review;
  api.state.initialized = true; api.state.workspace = workspace; api.state.session = options.session || session(); api.state.open = true;
  w.document.body.classList.add('bhranti-panel-open'); w.document.getElementById('bhranti-app').hidden = false;
  api.markExistingEvents(); api.render();
  const output = {
    dom, w, api, requests, notices, timers,
    setHandler(next) { handler = next; },
    setDirty(dirty) { localDirty = dirty; subscriptions.forEach((callback) => callback()); },
    setRemote(next) { remoteEditor = next; api.state.editor = next; },
    async advance(milliseconds = 0) {
      now += milliseconds; let calls = 0;
      while (true) {
        const due = [...timers].filter(([, entry]) => entry.at <= now).sort((a, b) => a[1].at - b[1].at)[0];
        if (!due) break;
        if (++calls > 100) throw new Error('Unexpected unbounded fixture timer loop.');
        const [id, entry] = due; timers.delete(id); if (entry.interval) timers.set(id, { ...entry, at: now + entry.delay });
        entry.callback(); await flush();
      }
      await flush();
    },
    close() { w.dispatchEvent(new w.PageTransitionEvent('pagehide')); dom.window.close(); },
  };
  return output;
}
const flush = async () => { await Promise.resolve(); await new Promise((resolve) => setImmediate(resolve)); };
async function until(test, page, advance = 50, attempts = 100) { for (let index = 0; index < attempts; index++) { await flush(); if (test()) return; await page.advance(advance); } throw new Error('The isolated UI behavior did not settle.'); }
const count = (page, suffix, method) => page.requests.filter((request) => request.path.endsWith(suffix) && (!method || request.method === method)).length;
const idle = (page) => !page.api.state.loading && !page.api.state.busy && !page.api.state.stepping;
const controls = (page, label) => [...page.w.document.querySelectorAll('button,a')].filter((node) => node.textContent === label || node.getAttribute('aria-label') === label);
let checks = 0;
function check(condition, label) { assert.ok(condition, label); checks++; }
function equal(actual, expected, label) { assert.equal(actual, expected, label); checks++; }

async function testDraftFocusAndScroll() {
  const page = fixture();
  try {
    page.api.state.draft = 'First unsent draft <literal> \\'; page.api.rememberDraft(); page.api.render(); await page.advance(20);
    let stream = page.w.document.querySelector('.bhranti-stream'); stream.scrollTop = 125; stream.dispatchEvent(new page.w.Event('scroll'));
    const input = page.w.document.getElementById('bhranti-composer'); input.focus(); input.setSelectionRange(3, 8); page.api.render(); await page.advance(20);
    equal(page.w.document.querySelector('.bhranti-stream').scrollTop, 125, 'Noncritical rerender preserves a reader above the bottom');
    equal(page.w.document.activeElement.id, 'bhranti-composer', 'Rerender preserves composer focus');
    equal(page.w.document.activeElement.selectionStart, 3, 'Rerender preserves composer selection start');
    equal(page.w.document.activeElement.selectionEnd, 8, 'Rerender preserves composer selection end');
    equal(page.w.document.activeElement.value, 'First unsent draft <literal> \\', 'Rerender preserves literal unsent text');
    await page.api.openSession(18); await until(() => idle(page), page);
    page.api.state.draft = 'Second independent draft'; page.api.rememberDraft(); page.api.render(); await page.advance(20);
    page.w.document.querySelector('.bhranti-stream').scrollTop = 260; page.w.document.querySelector('.bhranti-stream').dispatchEvent(new page.w.Event('scroll'));
    await page.api.openSession(17); await until(() => idle(page), page); await page.advance(20);
    equal(page.api.state.draft, 'First unsent draft <literal> \\', 'Switching chat restores only that chat draft');
    equal(page.w.document.querySelector('.bhranti-stream').scrollTop, 125, 'Returning to a chat restores its own reading position');
    const native = page.w.document.getElementById('native-field'); native.focus(); page.api.render(); await page.advance(20);
    equal(page.w.document.activeElement, native, 'Passive UI refresh never steals native WordPress focus');
    equal(native.value, 'Unsaved native text', 'Passive refresh preserves native typed content');
    equal(page.requests.filter((request) => request.method !== 'GET').length, 0, 'Chat selection and draft switching issue no mutations');
  } finally { page.close(); }
}

async function testCompositionPreservation() {
  const page = fixture();
  try {
    let input = page.w.document.getElementById('bhranti-composer'); input.focus();
    input.dispatchEvent(new page.w.CompositionEvent('compositionstart', { bubbles: true, data: '猫' }));
    input.value = 'A composed draft 猫'; input.dispatchEvent(new page.w.Event('input', { bubbles: true }));
    page.api.state.session.messages.push({ role: 'assistant', content: 'A background result during composition.' });
    page.api.render(); await page.advance(20);
    equal(page.w.document.getElementById('bhranti-composer'), input, 'Background rendering keeps the active IME textarea mounted');
    equal(input.value, 'A composed draft 猫', 'Background updates retain the exact composing draft');
    equal(page.w.document.activeElement, input, 'Background updates preserve focus during composition');
    check(!page.w.document.body.textContent.includes('A background result during composition.'), 'The background UI update is deferred until composition finishes');
    input.dispatchEvent(new page.w.KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true, isComposing: true, bubbles: true }));
    input.dispatchEvent(new page.w.KeyboardEvent('keyup', { key: 'Enter', isComposing: true, bubbles: true }));
    await page.advance(20);
    equal(page.w.document.getElementById('bhranti-composer'), input, 'A composing keyup does not release the deferred render');
    equal(page.requests.length, 0, 'IME Enter cannot accidentally submit a message or trigger a request');
    input.dispatchEvent(new page.w.CompositionEvent('compositionend', { bubbles: true, data: '猫' })); await page.advance();
    check(page.w.document.getElementById('bhranti-composer') !== input, 'Deferred rendering resumes after compositionend');
    check(page.w.document.body.textContent.includes('A background result during composition.'), 'The deferred result is rendered after compositionend');
    equal(page.w.document.getElementById('bhranti-composer').value, 'A composed draft 猫', 'Completed composition retains the per-chat draft through the deferred render');
    input = page.w.document.getElementById('bhranti-composer');
    input.dispatchEvent(new page.w.CompositionEvent('compositionstart', { bubbles: true }));
    input.value = 'Another composed draft 日本語'; input.dispatchEvent(new page.w.Event('input', { bubbles: true }));
    page.api.state.session.messages.push({ role: 'assistant', content: 'The result after window blur.' }); page.api.render();
    page.w.dispatchEvent(new page.w.Event('blur')); await page.advance();
    check(page.w.document.body.textContent.includes('The result after window blur.'), 'Window blur releases an abandoned IME render hold');
    equal(page.w.document.getElementById('bhranti-composer').value, 'Another composed draft 日本語', 'Blur preserves the unsubmitted composition text');
    page.api.state.session.messages.push({ role: 'assistant', content: 'A later ordinary update.' }); page.api.render();
    check(page.w.document.body.textContent.includes('A later ordinary update.'), 'Blur cannot freeze subsequent normal UI rendering');
    equal(page.requests.length, 0, 'Composition, completion and blur leave the task and server untouched');
  } finally { page.close(); }
}

async function testTransportBoundaries() {
  let getReads = 0;
  const page = fixture({ handler: async (request) => {
    if (request.method === 'GET') { if (++getReads < 3) throw Object.assign(new Error('A temporary local read outage.'), { data: { status: 503 } }); return session(); }
    throw Object.assign(new Error('The write response was lost.'), { data: { status: 503 } });
  } });
  try {
    const before = JSON.stringify(page.api.state.session); let result, failed;
    const operation = page.api.request('/sessions/17').then((value) => { result = value; }, (error) => { failed = error; });
    await until(() => result || failed, page, 250); await operation;
    check(!failed && result.id === 17, 'A safe read recovers after two transient failures');
    equal(getReads, 3, 'Safe GET retries are bounded to three attempts');
    const reads = page.requests.filter((request) => request.method === 'GET');
    check(reads[1].at - reads[0].at >= 1500 && reads[2].at - reads[1].at >= 3000, 'Read retries use bounded backoff instead of a request loop');
    equal(JSON.stringify(page.api.state.session), before, 'A reconnecting read cannot mutate the durable task checkpoint');
    equal(page.api.state.error, '', 'A recovered read does not leave a blocking task error');
    equal(page.api.state.connectionError, '', 'Recovered connection status clears after a successful safe read');
    equal(page.api.state.reconnecting, 0, 'Recovered reads release their connecting indicator');
    equal(page.notices.length, 0, 'Transient read recovery does not spam WordPress notices');
    let writeError; await page.api.request('/sessions/17/execute', 'POST', { run_id: runId, prepared_step: 3 }).catch((error) => { writeError = error; });
    check(writeError, 'An ambiguous write error remains available to the caller');
    equal(count(page, '/execute', 'POST'), 1, 'An ambiguous POST is never replayed by automatic transport recovery');
    equal(count(page, '/step', 'POST'), 0, 'Transport recovery cannot invent a new planning request');
    const calls = page.requests.length;
    page.setHandler(async () => { throw Object.assign(new Error('This private chat no longer exists.'), { code: 'bhranti_session_missing', data: { status: 404 } }); });
    await page.api.request('/sessions/18').catch(() => {});
    equal(page.requests.length, calls + 1, 'A permanent missing/forbidden read is not automatically retried');
  } finally { page.close(); }
}

async function testPollingOutage() {
  let reads = 0;
  const completed = session('complete'); completed.messages.push({ role: 'assistant', content: 'The server completed the existing task.', created: '2026-10-09T00:00:09Z' });
  const page = fixture({ session: session('running', { phase: 'model' }), handler: async (request) => {
    if (request.method !== 'GET') throw new Error('Polling recovery must not dispatch a mutation.');
    if (++reads === 1) throw Object.assign(new Error('Short local polling outage.'), { data: { status: 503 } });
    return reads === 2 ? session('running', { phase: 'action', step: 4 }) : completed;
  } });
  try {
    page.api.state.draft = 'Unsent follow-up during the task'; page.api.rememberDraft();
    const native = page.w.document.getElementById('native-field'); native.focus();
    const running = page.api.continueRun();
    await flush();
    const composer = page.w.document.getElementById('bhranti-composer');
    equal(composer.disabled, false, 'A pending task leaves the composer editable for an unsent follow-up');
    equal(page.w.document.getElementById('bhranti-send').disabled, true, 'An active task prevents sending a second request');
    composer.value = 'A follow-up typed while polling reconnects'; composer.dispatchEvent(new page.w.Event('input', { bubbles: true }));
    await until(() => page.api.state.session.run.status === 'complete' && idle(page), page, 250); await running;
    equal(page.api.state.error, '', 'A polling outage followed by current server state does not falsely fail the task');
    equal(page.api.state.session.run.status, 'complete', 'Polling reflects actual completed server state after an intervening running state');
    equal(page.api.state.draft, 'A follow-up typed while polling reconnects', 'Polling reconnection preserves the unsent per-chat draft');
    equal(page.w.document.activeElement, native, 'Background status recovery preserves native WordPress focus');
    equal(page.requests.filter((request) => request.method !== 'GET').length, 0, 'Polling recovery uses GET only and never replays model or action requests');
    equal(page.notices.length, 0, 'A resolved polling outage does not become a duplicate error card/notice');
    check(page.w.document.body.textContent.includes('The server completed the existing task.'), 'The recovered assistant result remains visible');
  } finally { page.close(); }
}

async function testNoncriticalAndExpiredChat() {
  const page = fixture({ handler: async (request) => {
    if (request.path.endsWith('/workspace')) throw Object.assign(new Error('Workspace read unavailable.'), { data: { status: 503 } });
    throw Object.assign(new Error('The selected conversation was erased.'), { code: 'bhranti_session_missing', data: { status: 404 } });
  } });
  try {
    page.api.state.draft = 'Keep this draft if a historical chat expired'; page.api.rememberDraft();
    for (let attempt = 0; attempt < 2; attempt++) { let done = false; const operation = page.api.refreshWorkspaceQuietly().finally(() => { done = true; }); await until(() => done, page, 250); await operation; }
    page.api.render(); await page.advance(20);
    equal(page.api.state.error, '', 'Repeated noncritical chat-list errors do not block the current chat');
    equal(page.notices.length, 0, 'Repeated noncritical failures coalesce without duplicate generic notices');
    equal(page.w.document.getElementById('bhranti-composer').disabled, false, 'Noncritical connection status leaves the idle composer usable');
    await page.api.openSession(18); await until(() => idle(page), page);
    equal(page.api.state.session, null, 'Expired chat selection recovers to a new chat instead of a stuck error');
    equal(page.api.state.error, '', 'An expired chat does not leave a blocking retry loop');
    check(controls(page, 'New chat').some((node) => !node.disabled), 'A usable New chat action remains available after erasure');
    equal(count(page, '/sessions/18', 'GET'), 1, 'Expired chat is queried once rather than repeatedly retried');
    equal(page.requests.filter((request) => request.method !== 'GET').length, 0, 'Expired selection never creates a replacement chat silently');
  } finally { page.close(); }
}

async function testInitialOutageCard() {
  let offline = true;
  const page = fixture({ handler: async (request) => {
    if (offline) throw Object.assign(new Error('The initial local read is unavailable.'), { data: { status: 503 } });
    if (request.path.endsWith('/workspace')) return { sessions: [], agents: [{ id: 'bhranti', name: 'Bhranti' }], ai: { available: true } };
    throw new Error('Initial reconnect must only load read-only workspace state.');
  } });
  try {
    page.api.state.initialized = false; page.api.state.session = null; page.api.state.draft = 'Preserve literal new-chat draft during recovery';
    let done = false; const loading = page.api.openPanel().finally(() => { done = true; }); await until(() => done, page, 250); await loading;
    equal(count(page, '/workspace', 'GET'), 3, 'Initial loading outage exhausts only the bounded safe read attempts');
    equal(controls(page, 'Reconnect').length, 1, 'Initial outage exposes one concrete Reconnect action');
    equal(controls(page, 'Try again').length, 0, 'Initial outage avoids an additional generic Retry action');
    check(page.api.state.error, 'Initial outage retains a clear recoverable connection explanation');
    equal(page.w.document.body.textContent.split(page.api.state.error).length - 1, 1, 'The same connection explanation appears once rather than in duplicate cards/status');
    offline = false; await page.api.reconnectChat(); await until(() => idle(page), page);
    equal(page.api.state.error, '', 'An explicit successful read reconnect clears the initial loading error');
    equal(page.api.state.draft, 'Preserve literal new-chat draft during recovery', 'Initial reconnect preserves the exact unsent new-chat draft');
    equal(page.requests.filter((request) => request.method !== 'GET').length, 0, 'Initial reconnect never submits a chat or invokes AI');
  } finally { page.close(); }
}

async function testResolvedErrorsAndControls() {
  const recovered = session('complete');
  recovered.messages = [message,
    { id: 'foreign-error', run_id: 'earlier-run', role: 'error', content: 'An earlier unresolved fixture failure.', created: '2026-10-09T00:00:02Z' },
    { id: 'resolved-error', run_id: runId, role: 'error', resolved: true, content: 'The connection failed before the saved task resumed.', created: '2026-10-09T00:00:03Z' },
    { id: 'done', role: 'assistant', content: 'The same saved task finished.', created: '2026-10-09T00:00:04Z' },
  ];
  const page = fixture({ session: recovered });
  try {
    const collapsed = [...page.w.document.querySelectorAll('details')].find((node) => node.textContent.includes('The connection failed before the saved task resumed.'));
    check(collapsed && !collapsed.open, 'Explicitly resolved same-run transport error is retained in closed details');
    check(page.w.document.body.textContent.includes('The same saved task finished.'), 'Recovered task completion stays visible beside collapsed diagnostic history');
    const unresolved = [...page.w.document.querySelectorAll('.bhranti-message.is-error')].find((node) => node.textContent.includes('An earlier unresolved fixture failure.'));
    check(unresolved && !unresolved.closest('details'), 'An unrelated unresolved error is not collapsed by another run completing');
    page.api.state.session = session('failed', { can_resume: true }); page.api.state.session.messages = [{ id: 'current-error', role: 'error', run_id: runId, content: 'The native connection failed; completed work is saved.' }]; page.api.state.error = ''; page.api.render();
    equal(controls(page, 'Resume task').length, 1, 'One safely resumable failure exposes one primary Resume task control');
    equal(controls(page, 'Try again').length, 0, 'A stored resumable failure does not add a second generic Retry control');
  } finally { page.close(); }
}

async function testActionTargetLinks() {
  const editor = fixture({ editor: true, url: 'https://bhranti.wp.local/wp-admin/post.php?post=99&action=edit' });
  try {
    const link = editor.w.document.querySelector('.bhranti-tool-event .bhranti-target-link');
    check(link, 'Historical action retains its existing concrete target link');
    equal(new URL(link.href).searchParams.get('post'), '99', 'Action target link points at the affected native editor');
    equal(new URL(link.href).searchParams.get('bhranti_session'), '17', 'Action target link preserves its conversation context');
    editor.setDirty(true); const before = editor.requests.length;
    const click = new editor.w.MouseEvent('click', { bubbles: true, cancelable: true }); link.dispatchEvent(click);
    equal(click.defaultPrevented, true, 'Existing action link refuses navigation away from a dirty native editor');
    equal(editor.api.navigation.length, 0, 'Dirty action target navigation cannot change the current editor');
    equal(editor.requests.length, before, 'Dirty target link neither saves the editor nor dispatches a request');
    equal(editor.api.state.notices.length, 1, 'The existing dirty-editor warning remains available');
    check(!editor.api.targetLink({ ...historical.target, url: 'https://external.invalid/result' }), 'Action links continue rejecting external targets');
    check(!editor.api.targetLink({ ...historical.target, url: '/wp-admin/post.php?post=99&action=delete' }), 'Action links continue rejecting mutation URLs');
  } finally { editor.close(); }
}

function checkNoSavedChangesCard(page) {
  equal(page.w.document.querySelectorAll('.bhranti-result-notice').length, 0, 'Completed conversation has no persistent saved-changes card');
  equal(page.w.document.querySelectorAll('#bhranti-view-result').length, 0, 'Completed conversation has no removed saved-result button');
  for (const label of ['Changes saved', 'View saved result', 'Reload editor']) {
    check(!page.w.document.querySelector('.bhranti-stream').textContent.includes(label), 'Conversation omits the removed ' + label + ' block');
  }
}

async function testSubmissionAcknowledgement() {
  for (const accepted of [false, true]) {
    const text = 'One exact new fixture submission ' + accepted;
    let reads = 0;
    const acknowledged = session('running', { phase: 'model' }); acknowledged.messages.push({ role: 'user', content: text, created: '2026-10-09T00:00:03Z' });
    const completed = { ...acknowledged, run: { ...acknowledged.run, status: 'complete', phase: '' } };
    const page = fixture({ handler: async (request) => {
      if (request.path.endsWith('/workspace')) return { sessions: [{ id: 17, title: 'Fixture' }], agents: [{ id: 'bhranti', name: 'Bhranti' }], ai: { available: true } };
      if (request.path.endsWith('/messages') && request.method === 'POST') throw Object.assign(new Error('The submission response was lost.'), { data: { status: 503 } });
      if (request.method === 'GET') return accepted ? ++reads === 1 ? acknowledged : completed : session();
      throw new Error('A lost submission response must never replay a mutation.');
    } });
    try {
      page.api.state.draft = text; page.api.rememberDraft();
      await page.api.sendMessage(); await until(() => idle(page), page, 100);
      equal(count(page, '/messages', 'POST'), 1, 'A lost submission response is sent once even when later reads ' + (accepted ? 'prove acceptance' : 'lack acceptance'));
      equal(count(page, '/step', 'POST'), 0, 'Submission reconciliation cannot fabricate a new planning call');
      equal(count(page, '/execute', 'POST'), 0, 'Submission reconciliation cannot fabricate a prepared action');
      if (accepted) {
        equal(page.api.state.error, '', 'Only a fresh persisted user message proves an accepted submission');
        equal(page.api.state.draft, '', 'Acknowledged submission is not restored as a duplicate draft');
        equal(page.api.state.session.run.status, 'complete', 'An accepted task reconciles to actual server completion');
      } else {
        equal(page.api.state.draft, text, 'Without persisted acceptance proof the exact unsent text remains recoverable');
        check(page.api.state.error, 'Unproven submission does not silently clear its connection warning');
        equal(controls(page, 'Reconnect').length, 1, 'Unproven submission presents one explicit safe read recovery action');
        equal(controls(page, 'Try again').length, 0, 'Submission recovery avoids an ambiguous generic Retry mutation');
        await page.api.reconnectChat(); await until(() => idle(page), page);
        equal(page.api.state.draft, text, 'Reconnect preserves an unaccepted draft rather than resending it');
        equal(count(page, '/messages', 'POST'), 1, 'Explicit Reconnect is read-only until a later human submission');
      }
    } finally { page.close(); }
  }
}

async function testUncertainActionRecovery() {
  const target = { id: 99, type: 'page', title: 'Fixture page', url: '/wp-admin/post.php?post=99&action=edit' };
  const prepared = session('running', { phase: 'prepared', step: 4, next_action: { tool: 'wp_request', method: 'POST', path: '/wp/v2/pages/99', summary: 'One exact update.', write: true, target } });
  const uncertain = session('running', { phase: 'action', step: 4, interrupted: false });
  const complete = session('complete', { step: 5 }); complete.events.push({ ...historical, id: 'saved-action', run_id: runId, success: true, operation: 'write' });
  let reads = 0;
  const page = fixture({ session: prepared, handler: async (request) => {
    if (request.path.endsWith('/execute') && request.method === 'POST') throw Object.assign(new Error('The action response was lost.'), { data: { status: 503 } });
    if (request.method === 'GET') return ++reads === 1 ? uncertain : complete;
    throw new Error('An uncertain prepared write must not be replayed.');
  } });
  try {
    const executing = page.api.continueRun(); await until(() => idle(page), page); await executing;
    equal(count(page, '/execute', 'POST'), 1, 'Lost prepared-action response performs exactly one execute request');
    equal(page.api.state.session.run.phase, 'action', 'Recovery reads and retains the server uncertain-action checkpoint');
    equal(controls(page, 'Reconnect').length, 1, 'Uncertain response exposes one safe read recovery control');
    equal(count(page, '/step', 'POST'), 0, 'An uncertain action cannot trigger a fresh model request');
    await page.api.reconnectChat(); await until(() => idle(page), page);
    equal(count(page, '/execute', 'POST'), 1, 'Explicit read recovery never repeats the completed prepared action');
    equal(page.api.state.session.run.status, 'complete', 'Reconnect displays the actual persisted action completion');
    equal(page.api.state.error, '', 'Safe completion proof removes the transient client recovery error');
  } finally { page.close(); }
}

async function completedWriteFixture(options = {}) {
  const target = { id: options.targetId || 99, type: options.type || 'page', title: 'Fixture page', url: '/wp-admin/post.php?post=' + (options.targetId || 99) + '&action=edit' };
  const action = { ...historical, id: 'new-write', action_id: 'new-snapshot', run_id: runId, success: options.success === undefined ? true : options.success, operation: options.operation || 'write', target, created: '2026-10-09T00:00:03Z' };
  const loaded = session('running', { phase: 'prepared', step: 4, next_action: { tool: 'wp_request', method: 'POST', path: '/wp/v2/pages/' + target.id, summary: 'Update the fixture page.', write: true, navigation: true, target } });
  const written = session('running', { phase: '', step: 4 }); written.events.push(action);
  const finished = session(options.terminal || 'complete', { phase: '', step: 5 }); finished.events.push(action); finished.messages.push({ role: 'assistant', content: 'The exact fixture update finished.', created: '2026-10-09T00:00:05Z' });
  const page = fixture({ ...options, session: loaded, handler: async (request) => {
    if (request.path.endsWith('/editor-state')) return page.api.editorSummary();
    if (request.method === 'GET') return finished;
    if (request.path.endsWith('/execute')) { equal(page.api.navigation.length, 0, 'Preparation does not navigate or refresh an active view'); return written; }
    if (request.path.endsWith('/step')) { equal(page.api.navigation.length, 0, 'A completed write is not refreshed until the entire task completes'); return finished; }
    throw new Error('Unexpected product result mutation.');
  } });
  // The initializer uses no editor heartbeat unless background is enabled.
  await page.advance(20);
  if (options.formDirty) {
    const native = page.w.document.getElementById('native-field'); native.value = 'Changed unsaved setting'; native.dispatchEvent(new page.w.Event('input', { bubbles: true }));
  }
  if (options.remoteDirty) page.setRemote({ dirty: true, active: false, write_blocked: true, stale_dirty: true });
  const operation = page.api.continueRun();
  await until(() => idle(page), page, 50); await operation;
  return { page, action, finished };
}

async function testCompletedTargetRefresh() {
  const list = await completedWriteFixture({ context: { screen: 'edit-page', post_type: 'page' } });
  try {
    equal(count(list.page, '/execute', 'POST'), 1, 'One prepared content write is executed');
    equal(count(list.page, '/step', 'POST'), 1, 'The task completes through one next planning request');
    equal(list.page.api.navigation.length, 1, 'A completed matching admin list updates exactly once');
    checkNoSavedChangesCard(list.page);
    const links = list.page.w.document.querySelectorAll('.bhranti-tool-event .bhranti-target-link');
    equal(links.length, 2, 'The prior and completed action retain their target links without a duplicate saved-changes card');
    check([...links].every((link) => new URL(link.href).searchParams.get('post') === '99'), 'Retained action links point at the native affected item');
    const navigation = list.page.api.navigation[0]; equal(new URL(navigation.url).pathname, '/wp-admin/edit.php', 'Completed list refresh preserves the visible admin list instead of opening an editor');
    list.page.api.render(); list.page.api.revealNewTarget(new list.page.w.Set(), runId); await list.page.advance(20);
    equal(list.page.api.navigation.length, 1, 'Renders and historical event replay cannot refresh the same completed target again');
  } finally { list.page.close(); }
  const frontend = await completedWriteFixture({ isAdmin: false, url: 'https://bhranti.wp.local/cats/', context: { screen: '', post_id: 99, post_type: 'page' } });
  try { equal(frontend.page.api.navigation.length, 1, 'Completed write to the current frontend post refreshes that view once'); equal(new URL(frontend.page.api.navigation[0].url).pathname, '/cats/', 'Frontend refresh stays on the current permalink'); } finally { frontend.page.close(); }
  const dashboard = await completedWriteFixture({ url: 'https://bhranti.wp.local/wp-admin/index.php', context: { screen: 'dashboard' } });
  try { equal(dashboard.page.api.navigation.length, 1, 'An ordinary clean dashboard may reveal a newly completed saved content target'); equal(new URL(dashboard.page.api.navigation[0].url).searchParams.get('post'), '99', 'Dashboard reveal opens the concrete affected native editor only after completion'); } finally { dashboard.page.close(); }
  for (const options of [
    { url: 'https://bhranti.wp.local/wp-admin/edit.php', context: { screen: 'edit-post', post_type: 'post' } },
    { isAdmin: false, url: 'https://bhranti.wp.local/cats/', context: { screen: '', post_id: 100, post_type: 'page' } },
    { editor: true, url: 'https://bhranti.wp.local/wp-admin/post.php?post=99&action=edit', context: { screen: 'page', post_id: 99, post_type: 'page' } },
    { operation: 'read', context: { screen: 'edit-page', post_type: 'page' } },
    { operation: 'image', type: 'attachment', context: { screen: 'edit-page', post_type: 'page' } },
    { terminal: 'failed', context: { screen: 'edit-page', post_type: 'page' } },
    { success: false, context: { screen: 'edit-page', post_type: 'page' } },
    { success: null, context: { screen: 'edit-page', post_type: 'page' } },
    { remoteDirty: true, context: { screen: 'edit-page', post_type: 'page' } },
    { formDirty: true, url: 'https://bhranti.wp.local/wp-admin/index.php', context: { screen: 'dashboard' } },
    { formDirty: true, url: 'https://bhranti.wp.local/wp-admin/options-general.php', context: { screen: 'options-general' } },
  ]) { const result = await completedWriteFixture(options); try { equal(result.page.api.navigation.length, 0, 'Unrelated view, dirty form/cross-tab, active editor, unsuccessful or incomplete task never auto-refreshes'); checkNoSavedChangesCard(result.page); if (options.formDirty) equal(result.page.w.document.getElementById('native-field').value, 'Changed unsaved setting', 'Completion preserves unsaved native form values'); } finally { result.page.close(); } }
  const historic = fixture({ session: list.finished, context: { screen: 'edit-page', post_type: 'page' } });
  try { historic.api.revealNewTarget(new historic.w.Set(), runId); historic.api.render(); await historic.advance(20); equal(historic.api.navigation.length, 0, 'Opening a historical completed chat never refreshes the current WordPress view'); checkNoSavedChangesCard(historic); } finally { historic.close(); }
  equal(typeof historic.api.viewResult, 'undefined', 'The removed saved-result helper is absent from the runtime');
  equal(typeof historic.api.resultNotice, 'undefined', 'The removed saved-changes-card builder is absent from the runtime');
}

async function main() {
  await testTransportBoundaries();
  await testPollingOutage();
  await testNoncriticalAndExpiredChat();
  await testInitialOutageCard();
  await testDraftFocusAndScroll();
  await testCompositionPreservation();
  await testResolvedErrorsAndControls();
  await testActionTargetLinks();
  await testSubmissionAcknowledgement();
  await testUncertainActionRecovery();
  await testCompletedTargetRefresh();
  console.log('PASS ' + checks + ' isolated product UX checks; no HTTP, AI, WordPress load or DB access');
}
main().catch((error) => { console.error(error); process.exitCode = 1; });
