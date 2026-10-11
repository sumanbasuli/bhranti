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
    equal(page.w.document.getElementById('bhranti-composer'), input, 'Deferred rendering resumes after compositionend without replacing the native textarea');
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

const clone = (value) => JSON.parse(JSON.stringify(value));
function tool(id, operation = 'read', extra = {}) {
  return { id, type: 'action', run_id: runId, success: true, operation, summary: 'Native ' + operation + ' result ' + id + '.', created: '2026-10-09T00:00:02Z', target: { ...historical.target }, ...extra };
}
function chooseDisclosure(page, node, open) {
  check(node && node.tagName === 'DETAILS', 'The chosen action disclosure is a native details element');
  node.open = open;
  node.dispatchEvent(new page.w.Event('toggle'));
}
function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

async function testStablePollingAndComposer() {
  const current = session('running', { phase: 'model', step: 7 });
  current.events = [tool('one'), tool('two', 'write', { created: '2026-10-09T00:00:03Z' })];
  const page = fixture({ session: current });
  try {
    page.api.state.draft = 'Undo-sensitive unsent draft 猫'; page.api.rememberDraft(); page.api.render(); await page.advance(20);
    const doc = page.w.document, root = doc.getElementById('bhranti-app'), log = doc.getElementById('bhranti-stream');
    const header = doc.getElementById('bhranti-header'), textarea = doc.getElementById('bhranti-composer'), form = doc.getElementById('bhranti-composer-form');
    const group = doc.querySelector('.bhranti-tool-group'), groupSummary = group.querySelector('summary');
    chooseDisclosure(page, group, false);
    textarea.focus(); textarea.setSelectionRange(2, 9);
    log.scrollTop = 137; log.dispatchEvent(new page.w.Event('scroll'));
    const removedLogs = [];
    const observer = new page.w.MutationObserver((records) => records.forEach((record) => [...record.removedNodes].forEach((node) => { if (node === log) removedLogs.push(node); })));
    observer.observe(root, { childList: true });
    page.api.state.session = clone(page.api.state.session); page.api.state.workspace = clone(page.api.state.workspace); page.api.render(); await page.advance(20);
    equal(doc.getElementById('bhranti-header'), header, 'An identical cloned poll preserves the header DOM node');
    equal(doc.getElementById('bhranti-stream'), log, 'An identical cloned poll preserves the connected conversation log');
    equal(doc.getElementById('bhranti-composer-form'), form, 'An identical cloned poll does not rebuild the composer form');
    equal(doc.getElementById('bhranti-composer'), textarea, 'An identical cloned poll retains native textarea and undo ownership');
    equal(doc.querySelector('.bhranti-tool-group summary'), groupSummary, 'An identical cloned poll preserves the native disclosure summary node');
    equal(doc.querySelector('.bhranti-tool-group').open, false, 'An identical cloned poll preserves a manually collapsed group');
    equal(doc.activeElement, textarea, 'Identical polling retains the focused textarea');
    equal(textarea.selectionStart, 2, 'Identical polling preserves the draft caret start'); equal(textarea.selectionEnd, 9, 'Identical polling preserves the draft caret end');
    equal(log.scrollTop, 137, 'Identical polling preserves the reading position');
    page.api.state.session.events.push(tool('three', 'image', { created: '2026-10-09T00:00:04Z', target: { ...historical.target, type: 'attachment', title: 'Fresh generated image' } }));
    page.api.render(); await page.advance(20);
    equal(doc.getElementById('bhranti-stream'), log, 'A changed action poll reuses the same log rather than replacing its live region');
    equal(removedLogs.length, 0, 'Changing action history never disconnects the existing conversation log');
    equal(doc.getElementById('bhranti-composer'), textarea, 'A changed action poll retains the native textarea');
    equal(textarea.value, 'Undo-sensitive unsent draft 猫', 'A changed action poll preserves exact unsent text');
    equal(doc.querySelector('.bhranti-tool-group').open, false, 'Adding an action preserves the group disclosure choice');
    equal(log.scrollTop, 137, 'An additional action does not drag a reader to the bottom');
    const send = doc.getElementById('bhranti-send');
    page.api.state.session.run.status = 'complete'; page.api.render();
    check(doc.getElementById('bhranti-send') !== send, 'A material status change creates the current Send control');
    textarea.value = ''; textarea.dispatchEvent(new page.w.Event('input', { bubbles: true }));
    equal(doc.getElementById('bhranti-send').disabled, true, 'The reused textarea listener updates the current Send control for an empty draft');
    textarea.value = 'Fresh local text'; textarea.dispatchEvent(new page.w.Event('input', { bubbles: true }));
    equal(doc.getElementById('bhranti-send').disabled, false, 'The reused textarea listener enables the current idle Send control');
    Object.defineProperty(textarea, 'scrollHeight', { configurable: true, value: 480 }); page.api.sizeComposer(textarea);
    equal(textarea.style.height, '150px', 'Long drafts stop growing at the bounded composer height');
    Object.defineProperty(textarea, 'scrollHeight', { configurable: true, value: 15 }); page.api.sizeComposer(textarea);
    equal(textarea.style.height, '48px', 'Short drafts retain the minimum usable composer height');
    equal(page.requests.length, 0, 'Disclosure choices and visual polling make no server request'); observer.disconnect();
  } finally { page.close(); }
}

async function testToolDisclosuresAndGrouping() {
  const current = session('running', { phase: 'model' });
  current.events = [tool('read'), tool('write', 'write', { created: '2026-10-09T00:00:03Z', rollback_available: true, action_id: 'snapshot-write', summary: 'Moved this page to Trash.' }),
    tool('failed', 'write', { type: 'error', success: false, created: '2026-10-09T00:00:04Z', summary: 'The local action was refused.' }),
    tool('image', 'image', { created: '2026-10-09T00:00:05Z', target: { id: 100, type: 'attachment', title: 'Cat image', url: '/wp-admin/post.php?post=100&action=edit' } })];
  const page = fixture({ session: current });
  try {
    const doc = page.w.document;
    equal(doc.querySelectorAll('.bhranti-tool-group').length, 1, 'Only two consecutive successful actions form a group');
    const group = doc.querySelector('.bhranti-tool-group');
    equal(group.querySelector('summary').textContent, '2 completed actions', 'A group reports its actual number of completed actions');
    equal(group.open, true, 'The current active run starts its success group open');
    equal(group.querySelectorAll('.bhranti-tool-event').length, 2, 'The group contains only its two successful actions');
    const events = [...doc.querySelectorAll('.bhranti-tool-event')];
    equal(events.length, 4, 'Every success and failure remains independently available');
    equal(events[0].querySelector('.bhranti-tool-label').textContent, 'Read page', 'A successful native page read is labelled as a read');
    equal(events[1].querySelector('.bhranti-tool-label').textContent, 'Moved this page to Trash.', 'A write uses the actual native summary rather than incorrectly calling Trash an update');
    equal(events[3].querySelector('.bhranti-tool-label').textContent, 'Generated image', 'Image generation is truthfully labelled');
    for (const event of events) {
      const summary = event.querySelector('summary');
      equal(summary.id, event.id + '-summary', 'Each disclosure has a stable keyboard-focusable summary identity');
      check(event.querySelector('.bhranti-tool-target').textContent, 'The compact action header retains its concrete target');
      check(event.querySelector('.bhranti-tool-body p').textContent, 'Expanded details retain the full native action summary');
      check(event.querySelector('.bhranti-event-date').textContent, 'Expanded details retain the recorded action time');
      check(event.querySelector('.bhranti-target-link'), 'Expanded details retain a safe native target link');
    }
    equal(events[0].querySelector('[role="img"]').getAttribute('aria-label'), 'Completed', 'Successful action status has an explicit accessible label');
    equal(events[2].querySelector('[role="img"]').getAttribute('aria-label'), 'Failed', 'Failed action status has an explicit accessible label');
    check(events[2].open && !events[2].closest('.bhranti-tool-group'), 'A failed action is visible and never hidden inside a success group');
    check(!events[3].closest('.bhranti-tool-group'), 'A single success following a failure remains an individual action');
    const rollbackId = events[1].querySelector('button').id;
    check(rollbackId && events[1].querySelector('button').disabled, 'A rollback control has a durable identity and is disabled while the run is active');
    chooseDisclosure(page, group, false); chooseDisclosure(page, events[0], true);
    const summaryId = events[0].querySelector('summary').id; doc.getElementById(summaryId).focus();
    page.api.state.draft = 'A visible change without another action'; page.api.render(); await page.advance(20);
    equal(doc.querySelector('.bhranti-tool-group').open, false, 'Changed UI state preserves the manually closed success group');
    equal(doc.getElementById(events[0].id).open, true, 'Changed UI state preserves an opened individual action');
    equal(doc.activeElement.id, summaryId, 'A changed render restores native action-summary focus');
    equal(doc.getElementById(rollbackId).id, rollbackId, 'Rollback identity remains stable across changed renders');
    const chat17 = clone(page.api.state.session), sameEvents = clone(page.api.state.session.events);
    page.api.state.session = { ...session('complete'), id: 18, events: sameEvents }; page.api.render();
    equal(doc.querySelector('.bhranti-tool-group').open, false, 'A historical run defaults to a closed group');
    equal(doc.querySelector('.bhranti-tool-event').open, false, 'A different chat does not inherit another chat’s action disclosure choice');
    page.api.state.session = chat17; page.api.render();
    equal(doc.querySelector('.bhranti-tool-group').open, false, 'Returning to the original chat restores its group choice');
    equal(doc.querySelector('.bhranti-tool-event').open, true, 'Returning to the original chat restores its action choice');
    page.api.state.session.run = { ...page.api.state.session.run, status: 'complete' }; page.api.render();
    equal(doc.getElementById(rollbackId).disabled, false, 'The same rollback control becomes usable only after the run ends');
    const interrupted = session('complete'); interrupted.events = [tool('first', 'read', { created: '2026-10-09T00:00:02Z' }), tool('second', 'read', { created: '2026-10-09T00:00:04Z' })];
    interrupted.messages.push({ id: 'between', role: 'assistant', content: 'An intervening reply.', created: '2026-10-09T00:00:03Z' });
    page.api.state.session = interrupted; page.api.render();
    equal(doc.querySelectorAll('.bhranti-tool-group').length, 0, 'An intervening human-visible reply prevents unrelated action grouping');
    equal(page.requests.length, 0, 'Action inspection and grouping do not send, replay or roll back requests');
  } finally { page.close(); }
}

async function testActionPreviewDisclosure() {
  const action = { tool: 'wp_request', method: 'GET', path: '/wp/v2/pages/99', summary: 'Read the saved fixture page.', target: { ...historical.target }, write: false, details: ['context'] };
  const page = fixture({ session: session('running', { phase: 'prepared', step: 8, next_action: action }) });
  try {
    const doc = page.w.document, detail = doc.querySelector('.bhranti-action-meta');
    check(detail && !detail.open, 'Prepared action technical details start compact');
    chooseDisclosure(page, detail, true); detail.querySelector('summary').focus(); const summaryId = detail.querySelector('summary').id;
    page.api.state.session.run.phase = 'action'; page.api.render(); await page.advance(20);
    equal(doc.querySelector('.bhranti-action-meta').id, detail.id, 'Prepared and executing phases share the exact action disclosure identity');
    equal(doc.querySelector('.bhranti-action-meta').open, true, 'Polling from prepared to executing retains the chosen action details');
    equal(doc.activeElement.id, summaryId, 'Polling retains the action-detail summary focus');
    for (const change of [{ step: 9 }, { id: 'new-run' }, { next_action: { ...action, path: '/wp/v2/pages/100' } }]) {
      page.api.state.session.run = { ...session('running', { phase: 'prepared', step: 8, next_action: action }).run, ...change }; page.api.render();
      check(doc.querySelector('.bhranti-action-meta').id !== detail.id, 'A different run, step or canonical path has a separate disclosure identity');
      equal(doc.querySelector('.bhranti-action-meta').open, false, 'A different prepared action does not inherit an earlier action disclosure choice');
    }
    const ai = doc.getElementById('bhranti-disclosure'); chooseDisclosure(page, ai, true);
    page.api.state.draft = 'A noncritical display change'; page.api.render();
    equal(doc.getElementById('bhranti-disclosure').open, true, 'The AI-data disclosure preserves its explicit open choice');
    equal(page.requests.length, 0, 'Preview and AI disclosure inspection dispatch no request');
  } finally { page.close(); }
}

async function testNativeDisclosureToggleRace() {
  const initial = session('running', { phase: 'model' });
  initial.events = [tool('native-toggle-one'), tool('native-toggle-two', 'write', { created: '2026-10-09T00:00:03Z' })];
  const page = fixture({ session: initial });
  try {
    const doc = page.w.document, event = doc.querySelector('.bhranti-tool-event'), summary = event.querySelector('summary');
    summary.focus(); summary.click();
    equal(event.open, true, 'Native summary activation immediately opens its action details');
    page.api.state.session.events.push(tool('native-toggle-three', 'image', { created: '2026-10-09T00:00:04Z' }));
    page.api.render();
    equal(doc.getElementById(event.id).open, true, 'A changed poll before the asynchronous native toggle event preserves the actual open choice');
    equal(doc.activeElement.id, summary.id, 'A changed poll during a native toggle preserves the focused summary');
    const group = doc.querySelector('.bhranti-tool-group'), groupSummary = group.querySelector('summary');
    groupSummary.focus(); groupSummary.click();
    equal(group.open, false, 'Native group summary activation immediately collapses the group');
    page.api.state.session.run.status = 'complete'; page.api.render();
    equal(doc.getElementById(group.id).open, false, 'Task completion cannot reopen a just-collapsed native group before toggle delivery');
    equal(doc.activeElement.id, groupSummary.id, 'Task completion retains the focused group summary');
    equal(page.requests.length, 0, 'A native disclosure race never sends or repeats a tool request');
  } finally { page.close(); }
}

async function testTruthfulTaskDock() {
  const page = fixture({ session: session('running', { phase: 'model', step: 8 }) });
  try {
    const doc = page.w.document;
    const verify = (status, label, dock) => {
      page.api.render(); const node = doc.getElementById('bhranti-run-status');
      check(node && node.textContent.includes(label), 'Actual task state ' + status + ' displays ' + label);
      equal(!!node.closest('.bhranti-task-dock'), dock, 'Only active task state ' + status + ' occupies the live dock');
      equal(!!node.closest('.bhranti-stream'), !dock, 'Terminal status stays in history and active status stays outside the log');
    };
    verify('planning', 'Planning next step…', true);
    equal(doc.querySelector('.bhranti-step').textContent, 'Step 8', 'The live task shows its actual model step');
    check(!doc.getElementById('bhranti-run-status').textContent.match(/\d+%|\bETA\b/), 'The dock does not fabricate percentages or an estimated completion time');
    for (const action of [
      { tool: 'wp_request', method: 'GET', path: '/wp/v2/pages/99', write: false, summary: 'Read the saved fixture page.' },
      { tool: 'generate_image', method: 'POST', write: true, summary: 'Generate one hand-drawn cat image.' },
      { tool: 'wp_request', method: 'DELETE', write: true, summary: 'Move the fixture page to Trash.' },
    ]) {
      page.api.state.session.run.phase = 'prepared'; page.api.state.session.run.next_action = action; verify(action.tool + ':' + action.method, action.summary, true);
      check(!doc.getElementById('bhranti-run-status').textContent.includes('Applying changes'), 'Known read/image/Trash actions never receive an invented generic write label');
    }
    page.api.state.session.run.phase = 'action'; delete page.api.state.session.run.next_action; verify('unknown-action', 'Running a WordPress action…', true);
    check(!doc.querySelector('.bhranti-elapsed'), 'A server-side running action without a local request does not invent elapsed time');
    page.api.state.session.run.retry_at = page.w.Date.now() / 1000 + 15; verify('retry', 'Retrying connection in 15s', true);
    page.api.state.pausedForEditor = true; verify('dirty-pause', 'Save or discard', true);
    page.api.state.stopRequested = true; verify('stop-requested', 'Stopping after the current step…', true);
    equal(doc.getElementById('bhranti-stop').disabled, true, 'Stop is disabled only once its request is already pending');
    page.api.state.stopRequested = false; page.api.state.pausedForEditor = false; delete page.api.state.session.run.retry_at;
    page.api.state.session.run.status = 'awaiting_confirmation'; verify('approval', 'Waiting for your approval', true);
    equal(doc.querySelectorAll('#bhranti-stop').length, 0, 'An approval wait does not imply that an action is currently executing');
    for (const [status, label] of [['complete', 'Completed'], ['failed', 'Task stopped'], ['cancelled', 'Stopped']]) { page.api.state.session.run.status = status; verify(status, label, false); }
    page.api.state.session.run.can_resume = true; page.api.state.session.run.status = 'failed'; verify('resumable-failure', 'Connection paused', false);
    equal(page.requests.length, 0, 'Truthful task labels are pure displays of persisted state');
  } finally { page.close(); }
}

async function testLiveRequestElapsed() {
  for (const outcome of ['complete', 'error', 'pagehide', 'stop']) {
    const held = deferred(); let operation;
    const page = fixture({ session: session('running', { step: 7 }), handler: async (request) => {
      if (request.path.endsWith('/step') && request.method === 'POST') return held.promise;
      if (request.method === 'GET') return session('failed', { step: 7 });
      throw new Error('Only the actual held planning request is allowed.');
    } });
    try {
      page.api.state.draft = 'Draft while a native request is pending'; page.api.render();
      const textarea = page.w.document.getElementById('bhranti-composer'); textarea.focus(); textarea.setSelectionRange(4, 11);
      const log = page.w.document.getElementById('bhranti-stream'); log.scrollTop = 100; log.dispatchEvent(new page.w.Event('scroll'));
      operation = page.api.continueRun(); await until(() => count(page, '/step', 'POST') === 1, page);
      const sent = page.requests.find((request) => request.path.endsWith('/step'));
      equal(JSON.stringify(sent.data), JSON.stringify({ run_id: runId, defer_tool: true }), 'The real pending request sends one exact prepared-protocol planning payload');
      check(page.w.document.querySelector('.bhranti-task-dock .bhranti-elapsed'), 'Only an actual unresolved POST starts the live elapsed display');
      const started = page.api.state.progress.started;
      await page.advance(2500);
      equal(page.w.document.querySelector('.bhranti-elapsed').textContent, '2s', 'Actual pending request time advances from its real start');
      check(page.w.document.querySelector('.bhranti-elapsed').getAttribute('aria-label').includes('2 seconds'), 'Elapsed request duration has an accessible precise label');
      page.api.state.session = clone(page.api.state.session); page.api.render(); await page.advance(1500);
      equal(page.api.state.progress.started, started, 'A cloned status poll cannot reset the current request clock');
      equal(page.w.document.querySelector('.bhranti-elapsed').textContent, '4s', 'A continuing request is measured rather than restarted by renders');
      equal(page.w.document.getElementById('bhranti-composer'), textarea, 'Actual pending request UI retains the composing textarea');
      equal(textarea.value, 'Draft while a native request is pending', 'Pending elapsed updates do not alter the unsent draft');
      equal(log.scrollTop, 100, 'Pending task status stays outside the log and preserves the reader position');
      equal(page.w.document.getElementById('bhranti-stop').getAttribute('id'), 'bhranti-stop', 'The live Stop control has a stable identity during a request');
      if (outcome === 'stop') {
        page.w.document.getElementById('bhranti-stop').focus(); page.w.document.getElementById('bhranti-stop').click(); await page.advance();
        equal(page.api.state.stopRequested, true, 'The live Stop control records the human request while the current action is pending');
        equal(page.w.document.getElementById('bhranti-stop').disabled, true, 'The persistent task dock disables the already requested Stop control');
        check(page.w.document.getElementById('bhranti-run-status').textContent.includes('Stopping after the current step…'), 'Stop truthfully waits for the current native request to finish');
        equal(page.requests.filter((request) => request.method !== 'GET').length, 1, 'Stop cannot replay or cancel an unresolved native request inline');
      }
      if (outcome === 'pagehide') {
        page.w.dispatchEvent(new page.w.PageTransitionEvent('pagehide'));
        equal([...page.timers.values()].filter((entry) => entry.interval).length, 0, 'Unloading the page cancels the local elapsed timer');
        equal(page.api.state.session.run.status, 'running', 'Unloading cannot fabricate task completion'); held.resolve(session('complete'));
      } else if (outcome === 'error') held.reject(Object.assign(new Error('The local planning response was lost.'), { data: { status: 503 } }));
      else held.resolve(session('complete', { step: 7 }));
      await until(() => idle(page), page); await operation;
      equal(page.api.state.progress, null, 'Resolved, rejected or unloaded requests release their elapsed state');
      equal([...page.timers.values()].filter((entry) => entry.interval).length, 0, 'The actual elapsed interval is cleaned in every request outcome');
      equal(count(page, '/step', 'POST'), 1, 'The elapsed lifecycle never replays the planning POST');
      equal(count(page, '/execute', 'POST'), 0, 'The elapsed lifecycle cannot invent a prepared action');
      equal(page.api.state.session.run.status, outcome === 'error' ? 'failed' : 'complete', 'The final display follows the returned server checkpoint');
      equal(count(page, '/cancel', 'POST'), 0, 'An already terminal returned checkpoint requires no additional cancel mutation');
      check(!page.w.document.querySelector('.bhranti-elapsed'), 'No elapsed badge remains after the request ends');
    } finally { held.resolve(session('complete')); if (operation) await operation; page.close(); }
  }
}

async function testExactApprovalControls() {
  for (const decision of [true, false, 'lost']) {
    const pending = session('awaiting_confirmation', { step: 9 });
    pending.confirmation = { id: 'confirmation-9', summary: 'Move only the identified fixture page to Trash.', target: { ...historical.target } };
    const held = deferred(); const page = fixture({ session: pending, handler: async (request) => {
      if (request.path.endsWith('/confirm') && request.method === 'POST') return held.promise;
      if (request.method === 'GET') return clone(pending);
      throw new Error('Approval controls must not dispatch a second action or planning request.');
    } });
    try {
      const doc = page.w.document;
      check(doc.querySelector('.bhranti-confirmation').textContent.includes(pending.confirmation.summary), 'The approval card retains the concrete destructive action summary');
      check(doc.querySelector('.bhranti-confirmation .bhranti-target-link'), 'Approval exposes the exact affected target');
      check(doc.querySelector('.bhranti-task-dock').textContent.includes('Waiting for your approval'), 'The persistent task dock truthfully indicates approval wait');
      doc.getElementById(decision === false ? 'bhranti-cancel' : 'bhranti-confirm').click();
      await until(() => count(page, '/confirm', 'POST') === 1, page);
      const request = page.requests.find((entry) => entry.path.endsWith('/confirm'));
      equal(JSON.stringify(request.data), JSON.stringify({ confirmation_id: 'confirmation-9', approved: decision !== false }), 'Approve/Deny sends only the exact canonical confirmation decision');
      equal(doc.getElementById('bhranti-confirm').disabled, true, 'Approve cannot be submitted again during the same pending decision');
      equal(doc.getElementById('bhranti-cancel').disabled, true, 'Deny cannot race a pending approval');
      doc.getElementById('bhranti-confirm').click(); doc.getElementById('bhranti-cancel').click(); await flush();
      equal(count(page, '/confirm', 'POST'), 1, 'Repeated UI activation cannot duplicate an approval POST');
      await page.advance(2200); check(doc.querySelector('.bhranti-elapsed'), 'Approval displays elapsed time only while the actual decision request is pending');
      if (decision === 'lost') held.reject(Object.assign(new Error('The approval response was lost.'), { data: { status: 503 } }));
      else held.resolve(session(decision ? 'complete' : 'cancelled', { step: 9 }));
      await until(() => idle(page), page);
      equal(count(page, '/confirm', 'POST'), 1, 'A lost or completed confirmation is never automatically replayed');
      equal(page.requests.filter((entry) => entry.method !== 'GET' && !entry.path.endsWith('/confirm')).length, 0, 'Approval presentation cannot invoke execute, planning, submission, rollback or Resume');
      equal(page.api.state.session.run.status, decision === 'lost' ? 'awaiting_confirmation' : decision ? 'complete' : 'cancelled', 'Approval outcome is read from the durable returned checkpoint');
      if (decision === 'lost') { equal(count(page, '/sessions/17', 'GET'), 1, 'An ambiguous approval response is reconciled through one read'); check(doc.querySelector('.bhranti-confirmation'), 'An unproven lost decision remains visible for the real server state'); }
      equal(page.api.state.progress, null, 'Approval releases its local request clock once the decision settles');
    } finally { held.resolve(session('cancelled')); await flush(); page.close(); }
  }
}

async function testChatMenuJumpAndCopy() {
  const page = fixture();
  try {
    const doc = page.w.document;
    equal(doc.querySelector('.bhranti-brand').textContent, 'Bhranti', 'The header visibly identifies the Bhranti brand');
    check(doc.getElementById('bhranti-chats'), 'Chats is a direct header control');
    doc.getElementById('bhranti-chats').click(); await page.advance();
    equal(doc.getElementById('bhranti-chats').getAttribute('aria-expanded'), 'true', 'The Chats trigger reflects its open menu state');
    check(doc.getElementById('bhranti-chat-17') && doc.getElementById('bhranti-chat-18'), 'Chats use stable IDs derived from their real session IDs');
    equal(doc.getElementById('bhranti-chat-17').getAttribute('aria-current'), 'page', 'The selected chat is explicitly identified to assistive technology');
    let search = doc.getElementById('bhranti-chat-search'); search.focus(); search.value = 'SECOND'; search.dispatchEvent(new page.w.Event('input', { bubbles: true })); await page.advance();
    check(doc.getElementById('bhranti-chat-18') && !doc.getElementById('bhranti-chat-17'), 'Chat filtering is case-insensitive and selects the matching title');
    equal(page.requests.length, 0, 'Typing a chat filter performs no HTTP request');
    equal(doc.activeElement, search, 'Filtering retains the native search field and focus');
    const menu = doc.getElementById('bhranti-menu'); menu.scrollTop = 63;
    page.api.state.workspace = clone(page.api.state.workspace); page.api.render();
    equal(doc.getElementById('bhranti-chat-search').value, 'SECOND', 'A cloned workspace poll preserves the current chat query');
    equal(doc.getElementById('bhranti-menu').scrollTop, 63, 'A cloned workspace poll preserves menu reading position');
    page.api.state.workspace.sessions.push({ id: 19, title: 'Third independent fixture chat' }); page.api.render();
    equal(doc.getElementById('bhranti-chat-search'), search, 'A changed workspace poll retains the native search input');
    equal(doc.getElementById('bhranti-chat-search').value, 'SECOND', 'A changed workspace poll retains the exact query');
    equal(doc.getElementById('bhranti-menu').scrollTop, 63, 'A changed workspace poll retains menu reading position');
    equal(doc.activeElement, search, 'A changed workspace poll preserves the chat-search focus');
    doc.getElementById('bhranti-chat-search').dispatchEvent(new page.w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })); await page.advance();
    equal(page.api.state.menu, false, 'Escape closes Chats first'); equal(page.api.state.open, true, 'Escape from Chats preserves the conversation panel'); equal(doc.activeElement.id, 'bhranti-chats', 'Escape restores the direct Chats trigger focus');
    doc.getElementById('bhranti-chats').click(); await page.advance();
    equal(doc.getElementById('bhranti-chat-search').value, 'SECOND', 'Reopening Chats restores the filter');
    equal(doc.getElementById('bhranti-menu').scrollTop, 63, 'Reopening Chats restores its prior menu position');
    search = doc.getElementById('bhranti-chat-search'); search.value = 'no such fixture'; search.dispatchEvent(new page.w.Event('input', { bubbles: true })); await page.advance();
    check(doc.getElementById('bhranti-menu').textContent.includes('No matching chats.'), 'An unmatched query gives a clear empty search result');
    equal(doc.querySelectorAll('.bhranti-chat-item').length, 0, 'An unmatched query cannot leave hidden actionable chat results');
    search.value = ''; search.dispatchEvent(new page.w.Event('input', { bubbles: true })); await page.advance();
    const native = doc.getElementById('native-field'); native.focus(); native.click(); await page.advance();
    equal(page.api.state.menu, false, 'An outside native click closes the chat menu'); equal(doc.activeElement, native, 'Outside closing preserves the intended WordPress focus');
    page.api.state.draft = 'Per-chat menu draft'; page.api.rememberDraft(); page.api.render();
    const log = doc.getElementById('bhranti-stream'); log.scrollTop = 185; log.dispatchEvent(new page.w.Event('scroll'));
    equal(doc.getElementById('bhranti-jump-latest').hidden, false, 'Reading above the bottom exposes an explicit Jump to latest control');
    page.api.state.session.messages.push({ id: 'later', role: 'assistant', content: 'A newer fixture reply.', created: '2026-10-09T00:00:06Z' }); page.api.render(); await page.advance(20);
    equal(log.scrollTop, 185, 'A new reply does not silently reset a reader above the bottom');
    doc.getElementById('bhranti-jump-latest').focus(); doc.getElementById('bhranti-jump-latest').click(); await page.advance();
    equal(log.scrollTop, log.scrollHeight, 'Jump to latest explicitly moves to the current conversation bottom');
    equal(doc.getElementById('bhranti-jump-latest').hidden, true, 'Jump to latest hides after reaching the bottom');
    equal(doc.activeElement.id, 'bhranti-composer', 'Jump to latest returns focus to the draft without changing it'); equal(page.api.state.draft, 'Per-chat menu draft', 'Jump to latest retains the unsent draft');
    const copies = [];
    Object.defineProperty(page.w.navigator, 'clipboard', { configurable: true, value: { writeText: async (text) => { copies.push(text); } } });
    page.api.state.session.messages.push({ id: 'copy-fixture', role: 'assistant', content: 'Exact local reply <literal> 猫', created: '2026-10-09T00:00:07Z' }); page.api.render(); await page.advance();
    const response = [...doc.querySelectorAll('.bhranti-message.is-assistant')].find((node) => node.textContent.includes('Exact local reply'));
    const copy = response.querySelector('.bhranti-copy-button'); check(copy && copy.id === response.id + '-copy', 'Response copy has a durable per-message focus identity');
    const copyId = copy.id; copy.focus(); page.api.state.session = clone(page.api.state.session); page.api.render();
    equal(doc.getElementById(copyId), copy, 'An identical response poll retains the copy control node'); equal(doc.activeElement, copy, 'Polling retains copy-button keyboard focus');
    copy.click(); await flush(); await page.advance();
    equal(copies.length, 1, 'Copy performs exactly one local clipboard operation'); equal(copies[0], 'Exact local reply <literal> 猫', 'Copy uses the exact response text rather than rendered HTML');
    equal(page.requests.length, 0, 'Menu search, Jump to latest and local copying never call a provider or server');
  } finally { page.close(); }
}

async function testDirectChatNavigation() {
  const first = session('complete'); first.events = [tool('same-event')];
  const second = { ...clone(first), id: 18, title: 'Second independent chat' };
  const page = fixture({ session: first, handler: async (request) => {
    if (request.method === 'GET' && request.path.endsWith('/sessions/17')) return clone(first);
    if (request.method === 'GET' && request.path.endsWith('/sessions/18')) return clone(second);
    throw new Error('Chat navigation must only read the selected owned conversation.');
  } });
  try {
    const doc = page.w.document;
    page.api.state.draft = 'First chat exact draft'; page.api.rememberDraft(); page.api.render(); await page.advance(20);
    const stream = doc.getElementById('bhranti-stream'); stream.scrollTop = 120; stream.dispatchEvent(new page.w.Event('scroll'));
    chooseDisclosure(page, doc.querySelector('.bhranti-tool-event'), true);
    doc.getElementById('bhranti-chats').click(); await page.advance();
    doc.getElementById('bhranti-chat-18').click(); await until(() => idle(page), page); await page.advance(20);
    equal(page.api.state.session.id, 18, 'Direct chat selection opens the exact requested session');
    equal(count(page, '/sessions/18', 'GET'), 1, 'Direct chat selection issues one bounded selected-session read');
    equal(page.api.state.menu, false, 'Selecting a chat closes its menu');
    equal(page.api.state.draft, '', 'A newly selected chat does not inherit another chat’s draft');
    equal(doc.querySelector('.bhranti-tool-event').open, false, 'The same event ID in a different chat cannot inherit disclosure state');
    page.api.state.draft = 'Second chat exact draft'; page.api.rememberDraft(); page.api.render();
    const secondStream = doc.getElementById('bhranti-stream'); secondStream.scrollTop = 280; secondStream.dispatchEvent(new page.w.Event('scroll'));
    doc.getElementById('bhranti-chats').click(); await page.advance(); doc.getElementById('bhranti-chat-17').click(); await until(() => idle(page), page); await page.advance(20);
    equal(page.api.state.session.id, 17, 'Returning through Chats opens the original exact session');
    equal(page.api.state.draft, 'First chat exact draft', 'Direct chat navigation restores the original unsent draft');
    equal(doc.getElementById('bhranti-stream').scrollTop, 120, 'Direct chat navigation restores the original reading position');
    equal(doc.querySelector('.bhranti-tool-event').open, true, 'Direct chat navigation restores the original disclosure choice');
    page.api.state.session.run.status = 'running'; page.api.state.session.run.phase = 'model'; page.api.render();
    doc.getElementById('bhranti-chats').click(); await page.advance();
    check([...doc.querySelectorAll('.bhranti-chat-item')].every((node) => node.disabled), 'An active run prevents switching its actionable chat controls');
    const requests = page.requests.length; doc.getElementById('bhranti-chat-18').click(); await page.advance();
    equal(page.requests.length, requests, 'Activating a disabled chat during a task cannot send a read or mutation');
    equal(page.requests.filter((request) => request.method !== 'GET').length, 0, 'Direct chat navigation never submits, approves or replays a task');
    equal(page.api.navigation.length, 0, 'Chat navigation cannot navigate the native WordPress page');
  } finally { page.close(); }
}

function composerKey(page, init = {}) {
  const input = page.w.document.getElementById('bhranti-composer');
  const event = new page.w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true, ...init });
  input.dispatchEvent(event);
  return event;
}
function typeDraft(page, value) {
  const input = page.w.document.getElementById('bhranti-composer');
  input.value = value; input.dispatchEvent(new page.w.Event('input', { bubbles: true }));
  return input;
}
function keyboardFixture(held) {
  return fixture({ handler: async (request) => {
    if (request.path.endsWith('/workspace') && request.method === 'GET') return { sessions: [{ id: 17, title: 'Keyboard fixture' }], agents: [{ id: 'bhranti', name: 'Bhranti' }], ai: { available: true } };
    if (request.path.endsWith('/messages') && request.method === 'POST') {
      if (held) return held.promise;
      const finished = session('complete'); finished.messages.push({ id: 'keyboard-accepted', role: 'user', content: request.data.content, created: '2026-10-09T00:00:10Z' }); return finished;
    }
    throw new Error('Keyboard tests may submit only one synthetic message, with no planning or action request.');
  } });
}

async function testComposerKeyboardSubmit() {
  for (const modifiers of [{}, { ctrlKey: true }, { metaKey: true }]) {
    const page = keyboardFixture();
    try {
      const draft = 'Exact keyboard message 猫 ' + JSON.stringify(modifiers);
      const input = typeDraft(page, draft); input.focus(); input.setSelectionRange(2, 5);
      const hint = page.w.document.getElementById('bhranti-composer-hint');
      check(hint && hint.textContent.includes('Enter to send') && hint.textContent.includes('Shift+Enter'), 'The composer explains the actual send and native newline shortcuts');
      check(input.getAttribute('aria-describedby').split(/\s+/).includes(hint.id), 'The keyboard instructions are associated with the native textarea');
      const event = composerKey(page, modifiers);
      equal(event.defaultPrevented, true, 'Enter submission prevents an accidental textarea newline with plain/Ctrl/Cmd compatibility');
      await until(() => count(page, '/messages', 'POST') === 1 && idle(page), page);
      const request = page.requests.find((entry) => entry.path.endsWith('/messages'));
      equal(request.data.content, draft, 'Enter submits the exact existing Unicode draft');
      equal(request.data.agent_id, 'bhranti', 'Keyboard submission retains the selected native Bhranti agent');
      equal(count(page, '/messages', 'POST'), 1, 'A single Enter sends one message through the normal submission path');
      equal(page.api.state.draft, '', 'An acknowledged Enter submission clears only the submitted draft');
      equal(page.requests.filter((entry) => entry.method !== 'GET' && !entry.path.endsWith('/messages')).length, 0, 'Keyboard submission cannot bypass the normal path to planning, action or confirmation');
      equal(page.w.document.getElementById('bhranti-composer'), input, 'Keyboard acknowledgement retains the native textarea identity');
    } finally { page.close(); }
  }
}

async function testComposerKeyboardNewlines() {
  for (const modifiers of [{ shiftKey: true }, { shiftKey: true, ctrlKey: true }, { shiftKey: true, metaKey: true }]) {
    const page = keyboardFixture();
    try {
      const input = typeDraft(page, 'First line'); input.focus(); input.setSelectionRange(input.value.length, input.value.length);
      const event = composerKey(page, modifiers);
      equal(event.defaultPrevented, false, 'Shift+Enter preserves the browser’s native newline behavior, including modifier combinations');
      await flush(); equal(page.requests.length, 0, 'Shift+Enter makes no submission or read request');
      equal(page.api.state.draft, 'First line', 'The key handler does not synthesize or replace the native textarea text');
      // JSDOM does not implement keyboard default editing; deliver the native input
      // result separately to verify that a real newline remains in the draft.
      input.value += '\nSecond line 猫'; input.dispatchEvent(new page.w.Event('input', { bubbles: true }));
      page.api.rememberDraft(); page.api.state.workspace = clone(page.api.state.workspace); page.api.render(); await page.advance(20);
      equal(page.api.state.draft, 'First line\nSecond line 猫', 'A native newline input persists unchanged in the per-chat draft');
      equal(page.w.document.getElementById('bhranti-composer').value, 'First line\nSecond line 猫', 'Polling cannot remove or submit the native multiline draft');
      equal(page.requests.length, 0, 'Multiline typing and visual polling never invoke AI or submit a task');
    } finally { page.close(); }
  }
}

async function testComposerKeyboardImeAndRepeat() {
  for (const variant of [
    { isComposing: true }, { ctrlKey: true, isComposing: true }, { metaKey: true, isComposing: true },
    { keyCode: 229 }, { ctrlKey: true, keyCode: 229 }, { metaKey: true, keyCode: 229 },
    { repeat: true }, { ctrlKey: true, repeat: true }, { metaKey: true, repeat: true },
  ]) {
    const page = keyboardFixture();
    try {
      const input = typeDraft(page, 'Unsubmitted composing text 日本語'); input.focus();
      const event = composerKey(page, variant);
      equal(event.defaultPrevented, variant.repeat === true, 'IME remains native while repeated Enter suppresses duplicate-send newlines');
      await page.advance(); equal(count(page, '/messages', 'POST'), 0, 'IME or repeated Enter cannot send a task');
      equal(page.api.state.draft, 'Unsubmitted composing text 日本語', 'IME or repeated Enter preserves the exact unsubmitted draft');
      equal(page.api.state.busy, false, 'IME or repeated Enter cannot start a false busy state');
    } finally { page.close(); }
  }
  const composing = keyboardFixture();
  try {
    const input = typeDraft(composing, 'A live composition 猫'); input.focus();
    input.dispatchEvent(new composing.w.CompositionEvent('compositionstart', { bubbles: true }));
    for (const modifiers of [{}, { ctrlKey: true }, { metaKey: true }]) {
      const event = composerKey(composing, modifiers);
      equal(event.defaultPrevented, false, 'The live composition guard works even when the browser omits isComposing on Enter');
      equal(count(composing, '/messages', 'POST'), 0, 'A live composition Enter never reaches the message endpoint');
    }
    input.dispatchEvent(new composing.w.CompositionEvent('compositionend', { bubbles: true })); await composing.advance();
    equal(composing.api.state.draft, 'A live composition 猫', 'Composition completion itself cannot submit or lose the draft');
    const confirmationKey = composerKey(composing, { keyCode: 229, isComposing: false });
    equal(confirmationKey.defaultPrevented, false, 'A browser IME confirmation key remains native after compositionend');
    input.dispatchEvent(new composing.w.KeyboardEvent('keyup', { key: 'Enter', keyCode: 229, isComposing: false, bubbles: true })); await composing.advance();
    equal(count(composing, '/messages', 'POST'), 0, 'The compositionend/229/keyup sequence never submits an accidental message');
    equal(composing.api.state.draft, 'A live composition 猫', 'The final IME confirmation sequence preserves the exact composed draft');
    composerKey(composing); await until(() => count(composing, '/messages', 'POST') === 1 && idle(composing), composing);
    equal(count(composing, '/messages', 'POST'), 1, 'A new ordinary Enter after composition completion works normally');
  } finally { composing.close(); }

  const held = deferred(); const page = keyboardFixture(held);
  try {
    typeDraft(page, 'Only one held-key message'); composerKey(page);
    await until(() => count(page, '/messages', 'POST') === 1, page);
    for (let index = 0; index < 4; index++) equal(composerKey(page, { repeat: true }).defaultPrevented, true, 'A held submission key prevents native newline input while its message is pending');
    equal(count(page, '/messages', 'POST'), 1, 'Held Enter cannot duplicate a still-pending message submission');
    const finished = session('complete'); finished.messages.push({ role: 'user', content: 'Only one held-key message' }); held.resolve(finished);
    await until(() => idle(page), page);
    typeDraft(page, 'A new unsent follow-up while Enter remains held');
    for (let index = 0; index < 4; index++) composerKey(page, { repeat: true }); await page.advance();
    equal(count(page, '/messages', 'POST'), 1, 'Held-key repeats remain blocked after the previous message acknowledgement');
    equal(page.api.state.draft, 'A new unsent follow-up while Enter remains held', 'Held-key repeats cannot clear a newly typed follow-up draft');
  } finally { held.resolve(session('complete')); await flush(); page.close(); }
}

async function testComposerKeyboardSubmissionGuards() {
  for (const guard of ['blank', 'whitespace', 'busy', 'active']) {
    const page = keyboardFixture();
    try {
      const text = guard === 'blank' ? '' : guard === 'whitespace' ? ' \n\t ' : 'Draft protected by ' + guard;
      typeDraft(page, text);
      if (guard === 'busy') page.api.state.busy = true;
      if (guard === 'active') page.api.state.session.run = { ...page.api.state.session.run, status: 'running', phase: 'model' };
      page.api.render();
      for (const modifiers of [{}, { ctrlKey: true }, { metaKey: true }]) composerKey(page, modifiers);
      await page.advance();
      equal(page.requests.filter((entry) => entry.method !== 'GET').length, 0, 'Blank, busy or active tasks block every Enter submission variant');
      equal(page.api.state.draft, text, 'The keyboard shortcut preserves the draft when submission is guarded');
      equal(page.w.document.getElementById('bhranti-send').disabled, true, 'Keyboard guards agree with the normal Send control');
    } finally { page.close(); }
  }
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
  await testStablePollingAndComposer();
  await testToolDisclosuresAndGrouping();
  await testActionPreviewDisclosure();
  await testNativeDisclosureToggleRace();
  await testTruthfulTaskDock();
  await testLiveRequestElapsed();
  await testExactApprovalControls();
  await testChatMenuJumpAndCopy();
  await testDirectChatNavigation();
  await testComposerKeyboardSubmit();
  await testComposerKeyboardNewlines();
  await testComposerKeyboardImeAndRepeat();
  await testComposerKeyboardSubmissionGuards();
  console.log('PASS ' + checks + ' isolated product UX checks; no HTTP, AI, WordPress load or DB access');
}
const watchdog = setTimeout(() => { console.error('FAIL: unresolved isolated UI operation exceeded the 20-second test deadline'); process.exit(1); }, 20000);
main().catch((error) => { console.error(error); process.exitCode = 1; }).finally(() => clearTimeout(watchdog));
