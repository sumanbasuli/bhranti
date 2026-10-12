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
  const nativeForm = '<form id="native-form"><input id="native-field" value="Unsaved native text"></form><button id="native-main-button">Native page action</button>';
  const nativeBar = '<div id="wpadminbar"><div id="wp-admin-bar-bhranti"><a href="#">Bhranti</a></div><form id="adminbarsearch"><input id="adminbar-search" aria-label="Search this site"></form></div><nav id="adminmenu"><a id="native-navigation" href="/wp-admin/index.php">Dashboard</a></nav>';
  const frameMarkup = options.siteFrame === 'frontend' ? '<header id="native-site-header">Native site header</header><main id="page" class="wp-site-blocks">' + nativeForm + '</main>' : '<div id="wpbody-content">' + (options.siteFrame === 'fse' ? '<div class="edit-site-layout" id="native-site-editor">' + nativeForm + '</div>' : nativeForm) + '</div>';
  const html = options.siteFrame ? '<!doctype html>' + nativeBar + frameMarkup + '<div id="bhranti-app" hidden></div>' : '<!doctype html><div id="wp-admin-bar-bhranti"><a href="#">Bhranti</a></div><div id="wpbody-content"><form id="native-form"><input id="native-field" value="Unsaved native text"></form></div><div id="bhranti-app" hidden></div>';
  const dom = new JSDOM(html, { url: options.url || 'https://bhranti.wp.local/wp-admin/edit.php?post_type=page', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  let now = 1791500000000, sequence = 0;
  const timers = new Map(), requests = [], notices = [], subscriptions = [];
  let localDirty = false, localSaving = false, localGesture = false, remoteEditor = { dirty: false, active: false, write_blocked: false, stale_dirty: false };
  const resizeObservers = [], rectangles = new Map();
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
  if (options.siteFrame) {
    Object.defineProperty(w, 'innerWidth', { configurable: true, value: 1280 }); Object.defineProperty(w, 'innerHeight', { configurable: true, value: 800 });
    const rectangle = (left, top, right, bottom) => ({ x: left, y: top, left, top, right, bottom, width: right - left, height: bottom - top, toJSON() { return {}; } });
    const nativeRects = w.HTMLElement.prototype.getClientRects;
    w.HTMLElement.prototype.getClientRects = function () { return this.id === 'bhranti-site-opener' && !this.hidden ? [rectangle(0, 0, 90, 32)] : nativeRects.call(this); };
    const frame = w.document.querySelector(options.siteFrame === 'frontend' ? 'main' : options.siteFrame === 'fse' ? '.edit-site-layout' : '#wpbody-content');
    rectangles.set(frame, rectangle(160, 24, 1280, 940)); rectangles.set(w.document.getElementById('bhranti-app'), rectangle(944, 32, 1280, 800)); rectangles.set(w.document.getElementById('wpadminbar'), rectangle(0, 0, 1280, 32));
    rectangles.forEach((rect, node) => { node.getBoundingClientRect = () => rectangles.get(node); node.getClientRects = () => [rectangles.get(node)]; });
    w.document.querySelector('#wp-admin-bar-bhranti > a').getClientRects = () => [rectangle(0, 0, 100, 32)];
    w.ResizeObserver = class { constructor(callback) { this.callback = callback; this.nodes = []; this.disconnected = false; resizeObservers.push(this); } observe(node) { this.nodes.push(node); } disconnect() { this.disconnected = true; } };
    if (options.settingsForm) {
      const mount = w.document.createElement('div'); mount.id = 'bhranti-settings-app'; mount.innerHTML = '<input id="settings-field" value="Original settings document">'; frame.appendChild(mount);
      if (options.settingsForm === 'clean') { mount.dataset.bhrantiDirty = 'false'; mount.dataset.bhrantiBusy = 'false'; }
    }
  }
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
      select: (store) => store === 'core/editor' && options.editor ? { getCurrentPostId: () => 99, getCurrentPostType: () => 'page', isEditedPostDirty: () => localDirty, getEditedPostAttribute: (name) => name === 'title' ? 'Fixture page' : '' } : options.siteFrame === 'fse' && store === 'core/edit-site' ? { getEditedPostId: () => 'fixture-theme//home', getEditedPostType: () => 'wp_template' } : options.siteFrame === 'fse' && store === 'core' ? { hasEditsForEntityRecord: () => localDirty, isSavingEntityRecord: () => localSaving, __experimentalGetDirtyEntityRecords: () => localDirty ? [{ kind: 'postType', name: 'wp_template', key: 'fixture-theme//home' }] : [] } : options.siteFrame === 'fse' && store === 'core/block-editor' ? { isDraggingBlocks: () => localGesture, isMultiSelecting: () => false } : undefined,
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
    dom, w, api, requests, notices, timers, rectangles, resizeObservers,
    setHandler(next) { handler = next; },
    setDirty(dirty) { localDirty = dirty; subscriptions.forEach((callback) => callback()); },
    setSaving(saving) { localSaving = saving; subscriptions.forEach((callback) => callback()); },
    setGesture(gesturing) { localGesture = gesturing; subscriptions.forEach((callback) => callback()); },
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

function referenceImageFixture(options = {}) {
  const page = fixture(options), operations = [], revoked = [];
  const image = { width: 3200, height: 1600, data: '/9j/2Q==', decodeError: false, ...(options.image || {}) };
  page.w.URL.createObjectURL = (blob) => { operations.push('decode', ['mime', blob.type]); return 'blob:local-reference-fixture'; };
  page.w.URL.revokeObjectURL = (url) => revoked.push(url);
  page.w.Image = class { constructor() { this.naturalWidth = image.width; this.naturalHeight = image.height; } set src(value) { this.url = value; Promise.resolve().then(() => image.decodeError ? this.onerror() : this.onload()); } };
  page.w.HTMLCanvasElement.prototype.getContext = function () { const canvas = this; operations.push('canvas'); return { set fillStyle(value) { operations.push(['background', value]); }, fillRect(...values) { operations.push(['fill', ...values]); }, drawImage(source, x, y, width, height) { operations.push(['draw', width, height, canvas.width, canvas.height]); } }; };
  page.w.HTMLCanvasElement.prototype.toDataURL = (type, quality) => { operations.push(['encode', type, quality]); return 'data:image/jpeg;base64,' + image.data; };
  return { ...page, image, operations, revoked };
}
function referenceFile(type = 'image/png', size, signature) {
  const bytes = signature || (type === 'image/jpeg' ? [255, 216, 255, 224, 0, 2, 255, 217] : type === 'image/webp' ? [82, 73, 70, 70, 4, 0, 0, 0, 87, 69, 66, 80] : [137, 80, 78, 71, 13, 10, 26, 10, 0, 0, 0, 0, 73, 69, 78, 68, 174, 66, 96, 130]);
  return { type, size: size === undefined ? bytes.length : size, name: 'private-local-reference.png', arrayBuffer: async () => Uint8Array.from(bytes).buffer };
}
async function testReferenceImageContext() {
  for (const type of ['image/png', 'image/jpeg', 'image/webp']) {
    const page = referenceImageFixture();
    try {
      page.api.state.draft = 'Use this as a visual reference'; page.api.rememberDraft();
      await page.api.attachReferenceImage(referenceFile(type));
      equal(page.api.state.screenshot.mime_type, 'image/jpeg', type + ': the browser emits only canonical raster JPEG');
      equal(page.api.state.screenshot.data, '/9j/2Q==', type + ': only re-encoded pixels become attached context');
      check(page.operations.some((entry) => Array.isArray(entry) && entry[0] === 'draw' && entry[1] === 1600 && entry[2] === 800), type + ': source pixels resize proportionally to the 1600px bound');
      check(page.operations.some((entry) => Array.isArray(entry) && entry[0] === 'background' && entry[1] === '#fff'), type + ': transparent references receive a predictable white JPEG background');
      equal(page.revoked.length, 1, type + ': the temporary local object URL is always released');
      equal(page.requests.length, 0, type + ': selecting and preparing a local reference makes no server or AI request');
      equal(page.w.document.getElementById('bhranti-image-file').accept, 'image/png,image/jpeg,image/webp', 'Native file picker offers only supported raster formats');
      check(controls(page, 'Attach reference image').length === 1 && controls(page, 'Attach tab screenshot').length === 1 && controls(page, 'Attach current page context').length === 1, 'Reference input preserves existing capture and page-context controls');
      check(page.w.document.body.textContent.includes('Shared with AI when you send.'), 'Preview clearly explains when the selected image is shared');
      equal(page.api.state.draft, 'Use this as a visual reference', 'Image selection preserves the current unsent message');
      const original = page.api.state.screenshot; page.api.state.attachPage = false;
      equal(page.api.attachedContext().screenshot, original, 'Reference images do not depend on attaching the current page');
      check(!('rendered_page' in page.api.attachedContext()), 'Opting out of page context remains respected with an image');
      await page.api.openSession(18); await until(() => idle(page), page);
      equal(page.api.state.screenshot, null, 'Another conversation does not inherit this unsent attachment');
      await page.api.openSession(17); await until(() => idle(page), page);
      equal(page.api.state.screenshot, original, 'Returning to the conversation restores its own attachment');
      equal(page.api.state.draft, 'Use this as a visual reference', 'Returning restores its matching unsent text');
      check(page.requests.every((request) => request.method === 'GET' && !request.data), 'Conversation switching never uploads the local attachment');
      await page.api.attachReferenceImage(undefined); equal(page.api.state.screenshot, original, 'Cancelling file selection preserves the previous image');
      controls(page, 'Remove image')[0].click();
      equal(page.api.state.screenshot, null, 'Remove image detaches the unsent reference');
      equal(page.api.state.screenshots.has(page.api.draftKey(17)), false, 'Removal also clears that chat’s attachment draft');
    } finally { page.close(); }
  }
  for (const declared of ['', 'image/png', 'image/webp']) {
    const page = referenceImageFixture();
    try {
      const file = referenceFile('image/jpeg'); file.type = declared; file.name = 'screenshot.png';
      await page.api.attachReferenceImage(file);
      equal(page.api.state.screenshot.mime_type, 'image/jpeg', 'Mislabelled or unlabelled JPEG is safely prepared regardless of filename');
      check(page.operations.some((entry) => Array.isArray(entry) && entry[0] === 'mime' && entry[1] === 'image/jpeg'), 'Native decoding receives the detected JPEG MIME rather than the picker label');
      equal(page.requests.length, 0, 'Correcting a local image label never uploads the image'); equal(page.notices.length, 0, 'A valid screenshot with a benign filename/MIME mismatch needs no error');
    } finally { page.close(); }
  }
  for (const [label, file, image] of [
    ['SVG', referenceFile('image/svg+xml'), {}],
    ['oversized input', referenceFile('image/png', 10485761), {}],
    ['empty input', referenceFile('image/jpeg', 0), {}],
    ['forged raster type', referenceFile('image/png', 1000, [60, 115, 118, 103]), {}],
    ['HTML with image name', referenceFile('text/html'), {}],
    ['trailing JPEG payload', referenceFile('image/jpeg', undefined, [255, 216, 255, 217, 60, 115, 118, 103]), {}],
    ['trailing PNG payload', referenceFile('image/png', undefined, [137, 80, 78, 71, 13, 10, 26, 10, 0, 0, 0, 0, 73, 69, 78, 68, 174, 66, 96, 130, 60]), {}],
    ['trailing WebP payload', referenceFile('image/webp', undefined, [82, 73, 70, 70, 4, 0, 0, 0, 87, 69, 66, 80, 60]), {}],
    ['undecodable raster', referenceFile(), { decodeError: true }],
    ['excessive width', referenceFile(), { width: 10001, height: 1 }],
    ['excessive pixel count', referenceFile(), { width: 5001, height: 5000 }],
    ['empty dimensions', referenceFile(), { width: 0, height: 0 }],
    ['encoded limit boundary', referenceFile(), { data: Buffer.alloc(2097153).toString('base64') }],
  ]) {
    const page = referenceImageFixture({ image });
    try {
      const original = { mime_type: 'image/jpeg', data: 'b2xk' }; page.api.state.screenshot = original;
      await page.api.attachReferenceImage(file);
      equal(page.api.state.screenshot, original, label + ': rejected input preserves the already chosen image');
      equal(page.api.state.capturing, false, label + ': rejection unlocks the composer and image controls');
      equal(page.notices.length, 1, label + ': invalid reference gives one actionable local notice');
      equal(page.requests.length, 0, label + ': rejection never makes a server request');
      if (['SVG', 'oversized input', 'empty input', 'forged raster type', 'HTML with image name', 'trailing JPEG payload', 'trailing PNG payload', 'trailing WebP payload'].includes(label)) equal(page.operations.length, 0, label + ': untrusted input is rejected before browser image decoding');
      if (['excessive width', 'excessive pixel count', 'empty dimensions'].includes(label)) check(!page.operations.includes('canvas'), label + ': the pixel guard rejects before allocating the output canvas');
      equal(page.revoked.length, page.operations.includes('decode') ? 1 : 0, label + ': any allocated local URL is released');
    } finally { page.close(); }
  }
  for (const flag of ['busy', 'loading', 'capturing', 'active']) {
    const page = referenceImageFixture();
    try {
      if (flag === 'active') page.api.state.session.run.status = 'running'; else page.api.state[flag] = true;
      page.api.render(); await page.api.attachReferenceImage(referenceFile());
      equal(controls(page, 'Attach reference image')[0].disabled, true, flag + ': file selection is unavailable while the composer cannot accept an attachment');
      equal(page.operations.length, 0, flag + ': direct selection cannot decode or attach during a conflicting operation');
    } finally { page.close(); }
  }
  for (const acknowledgement of ['success', 'lost-accepted', 'lost-unaccepted']) {
    let attempted;
    const page = referenceImageFixture({ handler: async (request) => {
      if (request.path.endsWith('/workspace')) return { sessions: [], agents: [], ai: { available: true } };
      const result = session('complete');
      if (request.path.endsWith('/messages')) { attempted = request; if (acknowledgement !== 'success') throw new Error('The native response was lost.'); result.messages.push({ role: 'user', content: request.data.content }); return result; }
      if (request.method === 'GET') { if (acknowledgement === 'lost-accepted') result.messages.push({ role: 'user', content: attempted.data.content }); return result; }
      throw new Error('Unexpected mutation in image submission fixture.');
    } });
    try {
      await page.api.attachReferenceImage(referenceFile()); const image = page.api.state.screenshot;
      page.api.state.draft = 'Follow this reference'; await page.api.sendMessage(); await until(() => idle(page), page);
      equal(count(page, '/messages', 'POST'), 1, acknowledgement + ': the chosen image is sent only once with the explicit message');
      equal(attempted.data.context.screenshot, image, acknowledgement + ': native context carries only the prepared attachment');
      equal(page.api.state.screenshot, acknowledgement === 'lost-unaccepted' ? image : null, acknowledgement + ': accepted messages clear the image and unproven submission preserves it');
      equal(page.api.state.screenshots.has(page.api.draftKey(17)), acknowledgement === 'lost-unaccepted', acknowledgement + ': draft attachment state matches proven submission');
    } finally { page.close(); }
  }
  const page = referenceImageFixture({ handler: async (request) => {
    if (request.path.endsWith('/workspace')) return { sessions: [], ai: { available: true } };
    if (request.path.endsWith('/sessions/17') && request.method === 'DELETE') return { deleted: true };
    throw new Error('Unexpected attachment ownership request.');
  } });
  try {
    await page.api.attachReferenceImage(referenceFile());
    const otherImage = { mime_type: 'image/jpeg', data: 'bmV3' }; page.api.state.screenshots.set(page.api.draftKey(null), otherImage);
    page.api.state.capturing = true; await page.api.deleteChat();
    equal(count(page, '/sessions/17', 'DELETE'), 0, 'A conversation cannot be deleted while its attachment is being prepared');
    page.api.state.capturing = false; await page.api.deleteChat();
    equal(count(page, '/sessions/17', 'DELETE'), 1, 'Explicit conversation deletion happens once after image preparation');
    equal(page.api.state.screenshots.has(page.api.draftKey(17)), false, 'Deleting a conversation removes its private pending attachment');
    equal(page.api.state.screenshot, otherImage, 'New chat restores only its own draft attachment after deletion');
  } finally { page.close(); }
}

async function testAwaitingInputAndIntent() {
  const page = fixture({ siteFrame: 'admin', session: session('running', { phase: 'prepared', next_action: { tool: 'commerce_request', method: 'GET', path: '/wc/v3', summary: 'Check store tools.', intent: 'I’ll check the existing catalog before creating your requested store.' } }) });
  try {
    equal(page.w.document.querySelector('.bhranti-action-intent').textContent, 'I’ll check the existing catalog before creating your requested store.', 'Prepared action displays its actual public intent separately from technical details');
    page.api.state.session.run.next_action.intent = '<img src=x onerror=alert(1)>' + 'x'.repeat(300); page.api.render();
    equal(page.w.document.querySelector('.bhranti-action-intent').textContent.length, 240, 'Public intent stays bounded in the sidebar');
    equal(page.w.document.querySelectorAll('.bhranti-action-intent img').length, 0, 'Intent is rendered as text and never executable HTML');
    page.api.state.session.run.next_action.intent = 'Check store tools.'; page.api.render();
    equal(page.w.document.querySelector('.bhranti-action-intent'), null, 'Duplicated canonical summary is not displayed twice');
    page.api.state.session.run.status = 'awaiting_input'; page.api.state.draft = 'Mitti & Thread, home decor in INR'; page.api.render();
    equal(page.w.document.querySelector('.bhranti-status-label').textContent, 'Waiting for your answer', 'A genuine question receives a waiting status rather than Completed');
    equal(page.w.document.getElementById('bhranti-send').disabled, false, 'The user can answer the question directly');
    equal(page.w.document.getElementById('bhranti-new-chat').disabled, false, 'Waiting for input does not trap the user in a conversation');
    equal(page.w.document.getElementById('bhranti-stop'), null, 'A paused question has no misleading Stop control');
    equal(page.w.document.querySelector('.bhranti-site-control'), null, 'Asking the user a question returns site control');
    equal(page.w.document.querySelector('.bhranti-action-preview'), null, 'A finished prepared-action preview does not linger while waiting for the answer');
    equal(page.requests.length, 0, 'Question presentation and intent never start a model request or native mutation');
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

async function testCommerceTargetLinks() {
  const settingsTarget = { type: 'woocommerce_settings', title: 'WooCommerce settings', url: '/wp-admin/admin.php?page=wc-settings&tab=general' };
  const categoryTarget = { type: 'product_cat', id: 7, title: 'Handmade bowls', url: '/wp-admin/term.php?taxonomy=product_cat&tag_ID=7&post_type=product' };
  const valid = [
    ...['general', 'products', 'advanced', 'shipping', 'checkout', 'site-visibility'].map((tab) => ({ ...settingsTarget, title: 'WooCommerce ' + tab, url: '/wp-admin/admin.php?tab=' + tab + '&page=wc-settings' })),
    categoryTarget,
    { ...categoryTarget, title: 'Absolute native product category', url: 'https://bhranti.wp.local/wp-admin/term.php?post_type=product&tag_ID=17&taxonomy=product_cat' },
  ];
  const invalid = [
    ...['payments', 'shipping-zones', 'checkout-settings', 'visibility', '', 'General'].map((tab) => ({ ...settingsTarget, url: '/wp-admin/admin.php?page=wc-settings&tab=' + tab })),
    { ...settingsTarget, url: '/wp-admin/admin.php?page=wc-settings' },
    { ...settingsTarget, url: '/wp-admin/admin.php?page=bhranti&tab=general' },
    { ...settingsTarget, url: settingsTarget.url + '&action=edit' },
    { ...settingsTarget, url: settingsTarget.url + '&_wpnonce=fixture' },
    { ...settingsTarget, url: settingsTarget.url + '&section=tax' },
    { ...settingsTarget, url: settingsTarget.url + '&page=wc-settings' },
    { ...settingsTarget, url: settingsTarget.url + '&tab=products' },
    { ...settingsTarget, url: settingsTarget.url + '&p%61ge=wc-settings' },
    { ...settingsTarget, type: 'product_cat' },
    { ...settingsTarget, type: 'page' },
    { ...settingsTarget, url: 'https://external.invalid' + settingsTarget.url },
    ...['0', '-1', '1.5', '07', 'no-id'].map((id) => ({ ...categoryTarget, url: '/wp-admin/term.php?taxonomy=product_cat&tag_ID=' + id + '&post_type=product' })),
    { ...categoryTarget, url: '/wp-admin/term.php?taxonomy=product_cat&post_type=product' },
    { ...categoryTarget, url: categoryTarget.url.replace('/term.php?', '/edit-tag.php?') },
    { ...categoryTarget, url: categoryTarget.url.replace('product_cat', 'category') },
    { ...categoryTarget, url: categoryTarget.url.replace('post_type=product', 'post_type=post') },
    { ...categoryTarget, url: categoryTarget.url + '&action=delete' },
    { ...categoryTarget, url: categoryTarget.url + '&action=edit' },
    { ...categoryTarget, url: categoryTarget.url + '&_wpnonce=fixture' },
    { ...categoryTarget, url: categoryTarget.url + '&taxonomy=product_cat' },
    { ...categoryTarget, url: categoryTarget.url + '&tag_ID=8' },
    { ...categoryTarget, url: categoryTarget.url + '&post_type=product' },
    { ...categoryTarget, url: categoryTarget.url + '&description=unexpected' },
    { ...categoryTarget, type: 'category' },
    { ...categoryTarget, type: 'woocommerce_settings' },
    { ...categoryTarget, url: 'https://external.invalid' + categoryTarget.url },
  ];
  const events = [...valid.map((target, index) => tool('commerce-valid-' + index, 'write', { target })), ...invalid.map((target, index) => tool('commerce-invalid-' + index, 'write', { target }))];
  const page = fixture({ session: { ...session('complete'), events } });
  try {
    for (const target of valid) {
      const safe = page.api.safeTarget(target), link = page.api.targetLink(target);
      check(safe && link, 'Reviewed WooCommerce settings/category target renders a native result link');
      equal(safe.origin, 'https://bhranti.wp.local', 'Commerce result stays on the exact WordPress origin');
      equal(safe.searchParams.get('bhranti_session'), '17', 'Commerce result preserves its conversation context');
      equal(link.textContent, target.title, 'Commerce result link retains the concrete affected target title');
    }
    for (const target of invalid) {
      equal(page.api.safeTarget(target), null, 'Unexpected WooCommerce target type, route, query or origin is refused');
      equal(page.api.targetLink(target), null, 'Refused Commerce targets cannot produce a clickable result link');
    }
    check(page.api.targetLink({ type: 'category', id: 9, title: 'Ordinary WordPress category', url: '/wp-admin/term.php?taxonomy=category&tag_ID=9&post_type=post' }), 'Strict product-category validation preserves other native WordPress term links');
    const rendered = [...page.w.document.querySelectorAll('.bhranti-tool-event .bhranti-target-link')];
    equal(rendered.length, valid.length, 'Completed Commerce history renders links only for reviewed exact native targets');
    for (const target of valid) check(rendered.some((link) => link.textContent === target.title), 'Each reviewed Commerce content result retains its native link in the action details');
    equal(page.api.navigation.length, 0, 'Rendering completed Commerce history never navigates or refreshes WordPress');
    equal(page.requests.length, 0, 'Commerce target validation never sends a GET or mutation');
  } finally { page.close(); }
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

async function testBackgroundPreparedStepRace() {
  const prepared = session('running', { phase: 'prepared', step: 4, next_action: { tool: 'wp_request', method: 'POST', path: '/wp/v2/pages/99', summary: 'Save the requested page.', write: true } });
  const stale = () => Object.assign(new Error('This request is no longer active.'), { code: 'bhranti_run_missing', data: { status: 409 } });
  for (const phase of ['prepared', 'action', '', 'complete', 'awaiting_confirmation']) {
    let reads = 0;
    const fresh = phase === 'complete' || phase === 'awaiting_confirmation' ? session(phase, { step: 7 }) : { ...prepared, run: { ...prepared.run, step: 7, phase } };
    const finished = session('complete', { step: 8 });
    finished.messages = [...finished.messages, { role: 'assistant', content: 'The store setup is saved.' }];
    const page = fixture({ session: prepared, handler: async (request) => {
      if (request.path.endsWith('/execute')) {
        if (request.data.prepared_step === 4) throw stale();
        equal(request.data.run_id, runId, 'Race recovery executes only the original owned run');
        equal(request.data.prepared_step, 7, 'Race recovery executes only the newly fetched prepared step');
        return finished;
      }
      if (request.path.endsWith('/step')) { equal(phase, '', 'A new planning request needs a freshly read idle checkpoint'); return finished; }
      if (request.method === 'GET') return ++reads === 1 ? fresh : finished;
      throw new Error('Unexpected mutation during checkpoint recovery.');
    } });
    try {
      page.api.state.draft = 'Keep my follow-up while the background task advances'; page.api.rememberDraft();
      const operation = page.api.continueRun(); await until(() => idle(page), page); await operation;
      equal(page.requests.filter((entry) => entry.path.endsWith('/execute') && entry.data.prepared_step === 4).length, 1, phase + ': the stale step is rejected once and never replayed');
      equal(count(page, '/sessions/17', 'GET'), phase === 'action' ? 2 : 1, phase + ': a known conflict is reconciled through the owned session read');
      equal(page.api.state.error, '', phase + ': a proven newer checkpoint avoids a false reconnect failure');
      equal(controls(page, 'Reconnect').length, 0, phase + ': the user is not asked to repair normal Cron progress');
      equal(page.api.state.draft, 'Keep my follow-up while the background task advances', phase + ': checkpoint recovery preserves the unsent draft');
      equal(count(page, '/execute', 'POST'), phase === 'prepared' ? 2 : 1, phase + ': only an authoritative new prepared checkpoint can execute another action');
      equal(count(page, '/step', 'POST'), phase === '' ? 1 : 0, phase + ': action polling and approvals never invent a planning request');
      equal(page.api.state.session.run.status, phase === 'awaiting_confirmation' ? 'awaiting_confirmation' : 'complete', phase + ': recovery retains the real final status');
    } finally { page.close(); }
  }
  for (const [label, fresh, error] of [
    ['unchanged checkpoint', prepared, stale()],
    ['regressed checkpoint', { ...prepared, run: { ...prepared.run, step: 3 } }, stale()],
    ['different run', { ...prepared, run: { ...prepared.run, id: '90000000-1000-4000-8000-100000000018', step: 7 } }, stale()],
    ['different conversation', { ...prepared, id: 18, run: { ...prepared.run, step: 7 } }, stale()],
    ['invalid checkpoint number', { ...prepared, run: { ...prepared.run, step: '7' } }, stale()],
    ['ambiguous write', { ...prepared, run: { ...prepared.run, step: 7 } }, Object.assign(new Error('The response was lost.'), { data: { status: 503 } })],
    ['unknown conflict', { ...prepared, run: { ...prepared.run, step: 7 } }, Object.assign(new Error('Another conflict.'), { code: 'unknown_conflict', data: { status: 409 } })],
    ['wrong conflict status', { ...prepared, run: { ...prepared.run, step: 7 } }, Object.assign(stale(), { data: { status: 403 } })],
  ]) {
    const page = fixture({ session: prepared, handler: async (request) => { if (request.method === 'GET') return fresh; throw error; } });
    try {
      const operation = page.api.continueRun(); await until(() => idle(page), page); await operation;
      equal(count(page, '/execute', 'POST'), 1, label + ': recovery cannot replay a write without proof');
      equal(count(page, '/step', 'POST'), 0, label + ': recovery cannot run a new model step');
      check(!!page.api.state.error, label + ': the unresolved state remains explicitly stopped');
    } finally { page.close(); }
  }
  const page = fixture({ session: session('running', { step: 4 }), handler: async (request) => { if (request.method === 'GET') return { ...prepared, run: { ...prepared.run, step: 7 } }; throw stale(); } });
  try {
    const operation = page.api.continueRun(); await until(() => idle(page), page); await operation;
    equal(count(page, '/step', 'POST'), 1, 'A stale planning request is not the known prepared-step rejection and never retries');
    equal(count(page, '/execute', 'POST'), 0, 'A rejected planner request cannot take over another checkpoint');
    check(!!page.api.state.error, 'Unexplained planner ownership conflict remains stopped');
  } finally { page.close(); }
}

async function testPendingHeartbeatRecovery() {
  const prepared = session('running', { phase: 'prepared', step: 4, next_action: { tool: 'wp_request', method: 'POST', path: '/wp/v2/pages/99', summary: 'Save the requested page.', write: true } });
  const clean = { dirty: false, active: false, write_blocked: false, stale_dirty: false };
  for (const outcome of ['clean', 'dirty', 'timeout']) {
    let rejectPending, reports = 0;
    const page = fixture({ background: true, session: prepared, handler: async (request) => {
      if (request.path.endsWith('/editor-state')) {
        reports++;
        if (reports === 1) return new Promise((resolve, reject) => { rejectPending = reject; });
        if (outcome === 'timeout') return new Promise((resolve, reject) => request.signal.addEventListener('abort', () => reject(Object.assign(new Error('The fresh safety report timed out.'), { name: 'AbortError' }))));
        return outcome === 'dirty' ? { ...clean, dirty: true, write_blocked: true, stale_dirty: true } : clean;
      }
      if (request.path.endsWith('/execute')) { equal(outcome, 'clean', 'Only a fresh clean safety report permits the original action'); return session('complete', { step: 4 }); }
      throw new Error('Unexpected request during pending heartbeat recovery.');
    } });
    try {
      const operation = page.api.continueRun(); await flush();
      equal(reports, 1, outcome + ': the write waits for the already pending safety report');
      equal(count(page, '/execute', 'POST'), 0, outcome + ': no action executes before the pending report is resolved');
      rejectPending(Object.assign(new Error('The earlier heartbeat timed out.'), { name: 'AbortError' }));
      await until(() => idle(page), page, 250); await operation;
      equal(reports, 2, outcome + ': one fresh safety report replaces the failed pending heartbeat');
      equal(count(page, '/execute', 'POST'), outcome === 'clean' ? 1 : 0, outcome + ': an unsafe or unknown state cannot execute or replay an action');
      equal(page.api.state.pausedForEditor, outcome !== 'clean', outcome + ': dirty or failed fresh checks retain the explicit editor pause');
      equal(!!page.api.state.editorError, outcome === 'timeout', outcome + ': only a successful fresh report clears the stale safety error');
      equal(page.requests.filter((entry) => entry.path.endsWith('/editor-state') && 'release_stale' in entry.data).length, 0, outcome + ': automatic checking never releases another tab’s sticky dirty marker');
      equal(count(page, '/step', 'POST'), 0, outcome + ': safety recovery never invents a planning request');
    } finally { page.close(); }
  }
  for (const [releaseStale, fresh] of [[false, false], [true, true]]) {
    let rejectPending;
    const page = fixture({ background: true, handler: async () => new Promise((resolve, reject) => { rejectPending = reject; }) });
    try {
      let caught = false;
      const report = page.api.reportEditor(releaseStale, fresh).catch(() => { caught = true; });
      rejectPending(new Error('The original safety report failed.')); await report;
      equal(count(page, '/editor-state', 'POST'), 1, 'Ordinary periodic reports and stale-marker release never automatically retry a rejected report');
      equal(caught, true, 'A non-fresh or explicit stale-marker release caller retains the report failure');
    } finally { page.close(); }
  }
  const page = fixture({ background: true });
  try {
    await flush(); page.api.state.pausedForEditor = true;
    await page.api.reportEditor();
    equal(page.api.state.pausedForEditor, true, 'A later clean periodic heartbeat never automatically resumes an unrelated dirty-editor pause');
    equal(count(page, '/execute', 'POST'), 0, 'Periodic heartbeat success cannot dispatch an action');
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

const siteOverlay = (page) => page.w.document.querySelector('.bhranti-site-control');
function siteTask(extra = {}) {
  return session('running', { phase: 'prepared', step: 4, next_action: { tool: 'bhranti_task_limit', method: 'POST', write: true, summary: 'Change the task step limit.', target: { type: 'bhranti_settings', title: 'Bhranti Settings', url: '/wp-admin/admin.php?page=bhranti' } }, ...extra });
}
function mainFrame(page) { return page.w.document.querySelector('.edit-site-layout') || page.w.document.querySelector('main.wp-site-blocks') || page.w.document.querySelector('#wpbody-content'); }
function assertSiteControlled(page, label) {
  const doc = page.w.document, overlay = siteOverlay(page), frame = mainFrame(page);
  check(overlay && overlay.isConnected, label + ' shows one connected site-control indicator');
  equal(doc.querySelectorAll('.bhranti-site-control').length, 1, label + ' never duplicates the control indicator');
  equal(overlay.textContent, 'Site is under Bhranti’s control', label + ' truthfully labels general WordPress control');
  equal(overlay.getAttribute('role'), 'status', label + ' provides nonmodal accessible status');
  equal(frame.getAttribute('aria-busy'), 'true', label + ' marks only the current main frame busy');
  check(frame.hasAttribute('inert'), label + ' protects the main frame from concurrent editing');
  check(!frame.contains(overlay), label + ' leaves its status outside the inert frame');
  check(!doc.getElementById('bhranti-app').closest('[inert]'), label + ' keeps chat and Stop interactive');
  if (page.w.bhrantiSettings.isAdmin) {
    check(doc.getElementById('wpadminbar').closest('[inert]'), label + ' blocks native admin-bar navigation');
    check(doc.getElementById('adminmenu').closest('[inert]'), label + ' blocks native WordPress navigation');
    check(doc.getElementById('bhranti-site-opener') && !doc.getElementById('bhranti-site-opener').closest('[inert]'), label + ' retains a dedicated reopening button outside native locks');
    check(doc.body.classList.contains('bhranti-site-controlled'), label + ' raises chat above the full admin overlay');
  } else {
    check(!doc.getElementById('wpadminbar').closest('[inert]'), label + ' preserves the frontend admin bar');
    check(!doc.getElementById('adminmenu').closest('[inert]'), label + ' preserves frontend navigation');
    equal(doc.getElementById('bhranti-site-opener'), null, label + ' does not add an admin-only opener to the frontend');
  }
  check(doc.getElementById('bhranti-stop') && !doc.getElementById('bhranti-stop').disabled, label + ' keeps the persistent live Stop control available');
}
function assertSiteReleased(page, label) {
  equal(siteOverlay(page), null, label + ' releases the site-control indicator');
  check(!mainFrame(page).hasAttribute('inert'), label + ' restores main-frame interaction');
  equal(mainFrame(page).getAttribute('aria-busy'), null, label + ' restores absent native busy state');
  for (const node of page.w.document.querySelectorAll('#wpadminbar, #adminmenu')) { check(!node.hasAttribute('inert'), label + ' releases native navigation inert'); equal(node.getAttribute('aria-busy'), null, label + ' restores native navigation busy state'); }
  equal(page.w.document.getElementById('bhranti-site-opener'), null, label + ' removes the temporary reopening control');
  check(!page.w.document.body.classList.contains('bhranti-site-controlled'), label + ' restores the original stacking layout');
}

async function testGeneralSiteControlLifecycle() {
  const page = fixture({ siteFrame: 'admin', session: siteTask() });
  try {
    const doc = page.w.document, frame = mainFrame(page), first = siteOverlay(page);
    assertSiteControlled(page, 'A proven prepared Settings tool');
    equal(page.api.editorState().editor_active, false, 'An ordinary admin page is not misreported as an open editor');
    equal(page.api.editorState().unsaved_changes, false, 'A clean ordinary admin page retains clean execution context');
    page.api.state.draft = 'Keep this unsent draft while the site is controlled'; page.api.rememberDraft(); page.api.render();
    const composer = doc.getElementById('bhranti-composer'); composer.focus(); composer.setSelectionRange(2, 8);
    page.api.state.session = clone(page.api.state.session); page.api.render();
    equal(siteOverlay(page), first, 'Cloned polling retains the same bound site-control node');
    equal(doc.getElementById('bhranti-composer'), composer, 'General control preserves the native composer identity');
    equal(doc.activeElement, composer, 'General control polling retains composer focus');
    equal(composer.selectionStart, 2, 'General control polling retains the draft caret');
    page.api.state.session.run.phase = 'model'; delete page.api.state.session.run.next_action;
    page.api.state.session.events.push(tool('settings-completed', 'write', { target: { type: 'bhranti_settings', title: 'Bhranti Settings', url: '/wp-admin/admin.php?page=bhranti' } })); page.api.render();
    assertSiteControlled(page, 'Planning after a same-run committed tool');
    equal(siteOverlay(page), first, 'A planning chain retains the same proven site ownership');
    for (const [label, change] of [
      ['completed task', () => { page.api.state.session.run.status = 'complete'; }],
      ['approval wait', () => { page.api.state.session.run.status = 'awaiting_confirmation'; }],
      ['failed task', () => { page.api.state.session.run.status = 'failed'; }],
      ['cancelled task', () => { page.api.state.session.run.status = 'cancelled'; }],
      ['interrupted task', () => { page.api.state.session.run.interrupted = true; }],
      ['human dirty pause', () => { page.api.state.pausedForEditor = true; }],
      ['planning response error', () => { page.api.state.error = 'Known local request error.'; }],
      ['editor safety error', () => { page.api.state.editorError = 'Known local safety error.'; }],
      ['requested Stop before dispatch', () => { page.api.state.stopRequested = true; }],
    ]) {
      page.api.state.session = siteTask(); page.api.state.error = ''; page.api.state.editorError = ''; page.api.state.pausedForEditor = false; page.api.state.stopRequested = false; page.api.render();
      check(siteOverlay(page), 'A clean prepared task reacquires control before ' + label); change(); page.api.render(); assertSiteReleased(page, label);
      equal(doc.getElementById('bhranti-composer').value, 'Keep this unsent draft while the site is controlled', 'Handover preserves the exact unsent draft');
    }
    page.api.state.session = siteTask(); page.api.state.error = ''; page.api.state.editorError = ''; page.api.state.pausedForEditor = false; page.api.state.stopRequested = false; page.api.render();
    page.api.closePanel(); assertSiteReleased(page, 'Closed queued/prepared task');
    page.api.state.open = true; page.api.state.session = siteTask(); page.api.render();
    page.api.state.session.run.phase = 'action'; page.api.render(); const executing = siteOverlay(page);
    page.api.state.error = 'An in-flight response is uncertain.'; page.api.state.editorError = 'An in-flight safety response is uncertain.'; page.api.state.stopRequested = true; page.api.render();
    equal(siteOverlay(page), executing, 'An already bound in-flight action retains protection through Stop and transport uncertainty');
    page.api.closePanel(); equal(siteOverlay(page), executing, 'Closing during an already bound action retains native-frame protection');
    check(page.api.triggerAvailable(doc.getElementById('bhranti-site-opener')), 'The dedicated Bhranti opener remains usable outside all held native frames');
    page.api.state.session.run.phase = 'model'; page.api.render(); assertSiteReleased(page, 'Server handover after a closed in-flight action');
    equal(frame.querySelector('#native-field').value, 'Unsaved native text', 'Control and handover never edit native form values');
    equal(page.requests.filter((request) => request.method !== 'GET').length, 0, 'Control lifecycle never dispatches a tool or replays a write');
  } finally { page.close(); }
}

async function testGeneralSiteControlProofAndRestoration() {
  const page = fixture({ siteFrame: 'admin', session: session('running', { phase: 'model' }) });
  try {
    const doc = page.w.document, frame = mainFrame(page);
    assertSiteReleased(page, 'An initial model question without a tool');
    for (const event of [tool('old-run', 'write', { run_id: 'another-run' }), tool('failed-tool', 'write', { success: false }), { ...historical }]) {
      page.api.state.session.events = [event]; page.api.render(); assertSiteReleased(page, 'Historical, failed or unproven tool event');
    }
    frame.setAttribute('inert', 'another-owner'); frame.setAttribute('aria-busy', 'false'); frame.dataset.nativeValue = 'preserved';
    page.api.state.session = siteTask(); page.api.render();
    check(siteOverlay(page), 'A proven task can show ownership on an already protected native frame');
    page.api.state.session.run.status = 'complete'; page.api.render();
    equal(frame.getAttribute('inert'), 'another-owner', 'Release restores the exact prior native inert attribute');
    equal(frame.getAttribute('aria-busy'), 'false', 'Release restores the exact prior native busy attribute');
    equal(frame.dataset.nativeValue, 'preserved', 'Release preserves unrelated native attributes');
    frame.removeAttribute('inert'); frame.removeAttribute('aria-busy');
    page.api.state.session = siteTask(); page.api.render();
    const countBefore = page.resizeObservers.length;
    frame.remove(); page.api.syncEditorControl(); equal(siteOverlay(page), null, 'Removing the native frame releases its detached ownership indicator');
    equal(frame.getAttribute('inert'), null, 'A detached native frame has its original interactive state restored');
    check(page.resizeObservers.slice(0, countBefore).every((observer) => observer.disconnected), 'Released frame observers are disconnected');
    doc.body.insertBefore(frame, doc.getElementById('bhranti-app')); frame.appendChild(doc.getElementById('bhranti-app')); page.api.syncEditorControl();
    equal(siteOverlay(page), null, 'A main frame containing Bhranti itself can never be made inert');
    doc.body.appendChild(doc.getElementById('bhranti-app')); page.api.syncEditorControl();
    check(siteOverlay(page), 'A safe outside-sidebar frame can reacquire ownership');
    page.w.dispatchEvent(new page.w.PageTransitionEvent('pagehide')); assertSiteReleased(page, 'Page unload');
    equal(page.requests.length, 0, 'Proof, remount and attribute restoration are local UI operations only');
  } finally { page.close(); }
  for (const url of ['https://bhranti.wp.local/wp-admin/post.php?post=99&action=edit', 'https://bhranti.wp.local/wp-admin/post-new.php']) {
    const post = fixture({ siteFrame: 'admin', editor: true, context: { screen: 'page', post_id: 99, post_type: 'page' }, url, session: siteTask() });
    try { equal(siteOverlay(post), null, 'Generic takeover never masks an unrelated native post/page editor'); equal(post.w.document.querySelector('#wpbody-content').hasAttribute('inert'), false, 'Native post/page exact-entity safety remains authoritative'); equal(post.requests.length, 0, 'Skipping unrelated post editor never changes or fetches its canvas'); } finally { post.close(); }
  }
}

async function testGeneralSiteControlDirtyAndDialogs() {
  const page = fixture({ siteFrame: 'admin', session: siteTask() });
  try {
    const doc = page.w.document, frame = mainFrame(page), field = doc.getElementById('native-field');
    assertSiteControlled(page, 'A clean ordinary admin form');
    field.value = 'Preserve the human’s exact native setting'; field.dispatchEvent(new page.w.Event('input', { bubbles: true }));
    assertSiteReleased(page, 'Unsaved main-form input');
    equal(page.api.editorState().editor_active, false, 'Dirty ordinary Settings still does not claim a native editor');
    equal(page.api.editorState().unsaved_changes, true, 'Dirty main forms are included in execution context even without a native editor');
    equal(page.api.runtimeContext().unsaved_changes, true, 'Attached context retains ordinary form dirty protection');
    const operation = page.api.continueRun(); await until(() => idle(page), page); await operation;
    equal(page.api.state.pausedForEditor, true, 'A dirty ordinary form pauses a prepared write before execution');
    equal(count(page, '/execute', 'POST'), 0, 'A dirty ordinary form never reaches the execute endpoint');
    equal(field.value, 'Preserve the human’s exact native setting', 'A dirty pause cannot modify or discard the native form text');
    equal(page.requests.filter((request) => request.method !== 'GET').length, 0, 'Dirty protection never submits a native save or replay');
    check(!frame.hasAttribute('inert'), 'Human editing remains available during a dirty pause');
  } finally { page.close(); }
  const focused = fixture({ siteFrame: 'admin', session: session('complete') });
  try {
    const doc = focused.w.document, field = doc.getElementById('native-field'); field.focus(); focused.api.state.session = siteTask(); focused.api.render();
    assertSiteReleased(focused, 'Focused native main-form typing'); equal(doc.activeElement, field, 'Deferred general control never steals native typing focus');
    const iframe = doc.createElement('iframe'); mainFrame(focused).appendChild(iframe); iframe.focus(); focused.api.render(); assertSiteReleased(focused, 'Active native frame interaction');
    doc.getElementById('bhranti-composer').focus(); focused.api.render(); assertSiteControlled(focused, 'Typing returned to Bhranti');
    const modal = doc.createElement('div'); modal.className = 'media-modal'; modal.getClientRects = () => [{ width: 100, height: 100 }]; doc.body.appendChild(modal); focused.api.render(); assertSiteReleased(focused, 'A visible native media dialog');
    modal.hidden = true; focused.api.render(); assertSiteControlled(focused, 'A closed native media dialog');
    equal(focused.requests.length, 0, 'Focus and dialog safety checks never dispatch a task');
  } finally { focused.close(); }
}

async function testClassicProductControlAndPriceProtection() {
  for (const [postType, url, eventName] of [
    ['product', 'https://bhranti.wp.local/wp-admin/post.php?post=99&action=edit', 'input'],
    ['product', 'https://bhranti.wp.local/wp-admin/post-new.php?post_type=product', 'change'],
    ['fixture_catalog_item', 'https://bhranti.wp.local/wp-admin/post.php?post=99&action=edit', 'change'],
  ]) {
    const page = fixture({ siteFrame: 'admin', url, context: { screen: postType, post_id: 99, post_type: postType }, session: siteTask() });
    try {
      const doc = page.w.document, price = doc.getElementById('native-field');
      price.id = '_regular_price'; price.name = '_regular_price'; price.value = '19.95';
      page.w.wp.autosave = { server: { postChanged: () => false } };
      assertSiteControlled(page, 'A clean Classic ' + postType + ' form');
      equal(page.api.editorState().unsaved_changes, false, 'Clean Classic autosave and fields do not falsely pause the task');
      price.value = '23.50'; price.dispatchEvent(new page.w.Event(eventName, { bubbles: true }));
      assertSiteReleased(page, 'A Classic ' + postType + ' price ' + eventName);
      equal(page.api.editorState().unsaved_changes, true, 'Classic metadata edits stay protected even when native content autosave reports clean');
      equal(page.api.runtimeContext().unsaved_changes, true, 'Classic product/CPT price edits reach the server execution guard');
      const operation = page.api.continueRun(); await until(() => idle(page), page); await operation;
      equal(page.api.state.pausedForEditor, true, 'Classic price edits pause the prepared action before dispatch');
      equal(count(page, '/execute', 'POST'), 0, 'No prepared write executes over unsaved Classic product/CPT metadata');
      equal(page.requests.filter((request) => request.method !== 'GET').length, 0, 'Classic dirty protection neither saves the product nor replays a POST');
      equal(price.value, '23.50', 'Pause preserves the exact human price');
      equal(page.api.navigation.length, 0, 'Classic product protection never refreshes or navigates the editor');
    } finally { page.close(); }
  }
  for (const postType of ['post', 'page']) {
    const page = fixture({ siteFrame: 'admin', url: 'https://bhranti.wp.local/wp-admin/post.php?post=99&action=edit', context: { screen: postType, post_id: 99, post_type: postType }, session: siteTask() });
    try { assertSiteReleased(page, 'Existing Classic ' + postType + ' exact-post policy'); equal(page.requests.length, 0, 'Generic product takeover does not introduce reads or writes into Classic post/page editors'); } finally { page.close(); }
  }
  const block = fixture({ siteFrame: 'admin', url: 'https://bhranti.wp.local/wp-admin/post.php?post=99&action=edit', context: { screen: 'page', post_id: 99, post_type: 'page' }, editor: true, session: session('complete') });
  try {
    const doc = block.w.document, native = doc.createElement('div'); native.id = 'editor'; native.className = 'block-editor__container'; mainFrame(block).appendChild(native);
    const field = doc.getElementById('native-field'); native.appendChild(doc.getElementById('native-form'));
    field.dispatchEvent(new block.w.Event('input', { bubbles: true }));
    equal(block.api.editorState().unsaved_changes, false, 'Native block editors continue to use their selectors rather than the Classic metadata latch');
    block.setDirty(true); equal(block.api.editorState().unsaved_changes, true, 'The native block-editor dirty selector remains authoritative');
    equal(block.requests.length, 0, 'Classic protection adds no native block-editor dispatch or request');
  } finally { block.close(); }
}

async function testSettingsStateControlAndHeartbeat() {
  for (const background of [false, true]) {
    const page = fixture({ siteFrame: 'admin', settingsForm: 'unknown', background, url: 'https://bhranti.wp.local/wp-admin/admin.php?page=bhranti', context: { screen: 'toplevel_page_bhranti' }, session: siteTask() });
    try {
      const doc = page.w.document, mount = doc.getElementById('bhranti-settings-app');
      assertSiteReleased(page, 'Unknown native React Settings state'); equal(page.api.editorState().unsaved_changes, true, 'Unknown/loading Settings fail closed before execution');
      const publish = (dirty, busy) => { mount.dataset.bhrantiDirty = String(dirty); mount.dataset.bhrantiBusy = String(busy); page.w.dispatchEvent(new page.w.CustomEvent('bhranti:settings-state')); };
      publish(false, false); await page.advance(); assertSiteControlled(page, 'Acknowledged clean native React Settings');
      const acknowledgedField = doc.getElementById('settings-field');
      acknowledgedField.dispatchEvent(new page.w.Event('input', { bubbles: true }));
      acknowledgedField.dispatchEvent(new page.w.Event('change', { bubbles: true })); await page.advance();
      equal(page.api.editorState().unsaved_changes, false, 'Bubbling Settings input cannot override the authoritative clean React mount state');
      assertSiteControlled(page, 'Known-clean React state after native bubbling input');
      publish(true, false); await page.advance(); assertSiteReleased(page, 'React configuration edit without a bubbling native input');
      equal(page.api.runtimeContext().unsaved_changes, true, 'React card changes protect execution context independently of bubbling DOM events');
      if (background) { const last = page.requests.filter((request) => request.path.endsWith('/editor-state')).at(-1); check(last && last.data.editor_active === false && last.data.unsaved_changes === true, 'Dirty ordinary Settings publishes the current tab lease without claiming an editor'); check(!('release_stale' in last.data), 'Dirty state never clears another tab’s sticky lease'); }
      const field = doc.getElementById('settings-field'); field.value = 'Exact unsaved Markdown 日本語'; field.dispatchEvent(new page.w.Event('input', { bubbles: true }));
      publish(false, false); await page.advance(); assertSiteControlled(page, 'Authoritative React clean acknowledgement or revert'); equal(page.api.editorState().unsaved_changes, false, 'Authoritative clean state clears only the mount’s prior dirty protection'); equal(field.value, 'Exact unsaved Markdown 日本語', 'Publishing state never rewrites native input values');
      publish(false, true); await page.advance(); assertSiteReleased(page, 'An in-flight native Settings save'); equal(page.api.editorState().unsaved_changes, true, 'Saving Settings is protected as uncertain local work');
      publish(false, false); await page.advance(); assertSiteControlled(page, 'Successful Settings save handover');
      if (background) { const clean = page.requests.filter((request) => request.path.endsWith('/editor-state')).at(-1); check(clean && clean.data.unsaved_changes === false && clean.data.token, 'Acknowledged Settings save publishes only a clean current-token heartbeat'); check(!('release_stale' in clean.data), 'Acknowledged save preserves unrelated dirty tabs'); }
      equal(count(page, '/execute', 'POST'), 0, 'Settings dirty/saving/clean notifications never execute a tool');
      equal(page.requests.filter((request) => request.method !== 'GET' && !request.path.endsWith('/editor-state')).length, 0, 'Settings state communication uses only its owned heartbeat and no native save/action');
    } finally { page.close(); }
  }
}

async function testGenericNativeNavigationAndGeometry() {
  const page = fixture({ siteFrame: 'admin', session: siteTask() });
  try {
    const doc = page.w.document, overlay = siteOverlay(page), frame = mainFrame(page);
    equal(overlay.style.top, '0px', 'General admin control includes the native admin bar'); equal(overlay.style.left, '0px', 'General admin control includes the whole left navigation');
    equal(overlay.style.width, '1280px', 'General admin control dims the whole viewport behind chat'); equal(overlay.style.height, '800px', 'General admin control reaches the viewport edges');
    equal(overlay.style.paddingRight, '360px', 'The ownership heading stays centered in the visible WordPress area');
    equal(doc.getElementById('bhranti-app').style.top, '32px', 'Making the native bar inert does not move the chat header');
    const search = doc.getElementById('adminbar-search'); search.value = 'A harmless site search'; search.dispatchEvent(new page.w.Event('input', { bubbles: true })); await page.advance();
    equal(page.api.editorState().unsaved_changes, false, 'Admin-bar search text is outside protected page forms and cannot mark the task dirty');
    equal(siteOverlay(page), overlay, 'A synthetic admin search event cannot remove proven site ownership');
    check(search.closest('[inert]'), 'Native admin-bar search is unavailable while the site is controlled');
    const undo = new page.w.KeyboardEvent('keydown', { key: 'z', ctrlKey: true, bubbles: true, cancelable: true }); search.dispatchEvent(undo);
    equal(undo.defaultPrevented, true, 'Native undo cannot change a now-controlled admin input');
    const original = page.rectangles.get(frame); page.rectangles.set(frame, { ...original, left: 200, right: 1100, width: 900 }); page.w.dispatchEvent(new page.w.Event('resize'));
    equal(overlay.style.left, '0px', 'Native content movement never opens a gap beside navigation'); equal(overlay.style.width, '1280px', 'Admin control continues to cover the whole viewport after content movement');
    Object.defineProperty(page.w, 'innerWidth', { configurable: true, value: 600 }); Object.defineProperty(page.w, 'innerHeight', { configurable: true, value: 700 });
    page.rectangles.set(doc.getElementById('bhranti-app'), { left: 250, right: 600, top: 46, bottom: 700, width: 350, height: 654 }); page.w.dispatchEvent(new page.w.Event('resize'));
    equal(overlay.style.width, '600px', 'Narrow viewport control remains edge to edge'); equal(overlay.style.height, '700px', 'Narrow viewport control follows the viewport height'); equal(overlay.style.paddingRight, '374px', 'Narrow layout centers the status outside the chat');
    Object.defineProperty(page.w, 'innerWidth', { configurable: true, value: 350 }); page.rectangles.set(doc.getElementById('bhranti-app'), { left: 0, right: 350, top: 46, bottom: 700, width: 350, height: 654 }); page.w.dispatchEvent(new page.w.Event('resize'));
    equal(overlay.style.width, '350px', 'A full-width mobile chat does not expand the overlay beyond the viewport'); equal(overlay.style.paddingRight, '326px', 'Mobile heading spacing cannot grow beyond the border-box width'); check(overlay.firstElementChild.hidden, 'The covered ownership heading does not wrap into a narrow vertical column on mobile');
    equal(page.api.navigation.length, 0, 'Control geometry and protected navigation never redirect WordPress'); equal(page.requests.length, 0, 'Local generic control never generates transport requests');
  } finally { page.close(); }
  const frontend = fixture({ siteFrame: 'frontend', isAdmin: false, url: 'https://bhranti.wp.local/fixture-page/', context: { screen: '' }, session: siteTask({ next_action: { tool: 'site_search', method: 'GET', summary: 'Inspect the requested site.' } }) });
  try { assertSiteControlled(frontend, 'A proven frontend task'); check(!frontend.w.document.getElementById('native-site-header').hasAttribute('inert'), 'Frontend takeover preserves its native header'); frontend.api.state.session.run.status = 'complete'; frontend.api.render(); assertSiteReleased(frontend, 'Frontend task completion'); equal(frontend.requests.length, 0, 'Frontend control does not load or mutate site data'); } finally { frontend.close(); }
}

async function testGeneralAdminShellRestoration() {
  const page = fixture({ siteFrame: 'admin', session: session('complete') });
  try {
    const doc = page.w.document, frame = mainFrame(page), root = doc.getElementById('bhranti-app');
    const shell = doc.createElement('div'); shell.id = 'wpwrap'; doc.body.appendChild(shell);
    const content = doc.createElement('div'); content.id = 'wpcontent'; content.appendChild(frame); shell.appendChild(content);
    const navigation = doc.createElement('div'); navigation.id = 'adminmenumain'; navigation.appendChild(doc.getElementById('adminmenu')); shell.appendChild(navigation);
    const footer = doc.createElement('div'); footer.id = 'wpfooter'; footer.innerHTML = '<button id="native-footer-action">Native footer action</button>'; shell.appendChild(footer); shell.appendChild(root);
    const bar = doc.getElementById('wpadminbar'); shell.appendChild(bar);
    content.setAttribute('aria-busy', 'false'); navigation.setAttribute('inert', 'native-owner'); footer.setAttribute('aria-busy', 'native-pending'); bar.setAttribute('aria-label', 'Native toolbar');
    const protectedNodes = [frame, content, navigation, doc.getElementById('adminmenu'), footer, bar];
    const original = protectedNodes.map((node) => ({ node, inert: node.getAttribute('inert'), busy: node.getAttribute('aria-busy') }));
    doc.getElementById('native-footer-action').focus(); page.api.state.session = siteTask(); page.api.render();
    for (const node of protectedNodes) { equal(node.getAttribute('inert'), '', 'The real native shell locks ' + node.id); equal(node.getAttribute('aria-busy'), 'true', 'The real native shell marks ' + node.id + ' busy'); }
    check(!shell.hasAttribute('inert'), 'The shared wpwrap ancestor is never made inert'); check(!root.closest('[inert]'), 'Chat stays interactive when mounted at the native admin_footer location');
    equal(doc.activeElement, siteOverlay(page), 'Acquisition moves native navigation/footer focus outside its new inert ancestor');
    page.api.state.session.run.status = 'awaiting_input'; page.api.render();
    for (const { node, inert, busy } of original) { equal(node.getAttribute('inert'), inert, 'Handover restores the exact original inert value for ' + node.id); equal(node.getAttribute('aria-busy'), busy, 'Handover restores the exact original busy value for ' + node.id); }
    equal(bar.getAttribute('aria-label'), 'Native toolbar', 'Handover preserves unrelated native attributes'); equal(siteOverlay(page), null, 'A question releases the whole admin shell');
    navigation.removeAttribute('inert');
    const search = doc.getElementById('adminbar-search'); search.focus(); page.api.state.session = siteTask(); page.api.render();
    equal(siteOverlay(page), null, 'Existing admin-bar typing defers acquisition of the larger native lock'); equal(doc.activeElement, search, 'A new prepared action never steals ongoing admin-bar typing');
    root.querySelector('#bhranti-composer').focus(); page.api.render(); check(siteOverlay(page), 'Returning focus to chat allows safe admin-shell control');
    page.w.dispatchEvent(new page.w.PageTransitionEvent('pagehide'));
    for (const node of protectedNodes) check(!node.hasAttribute('inert'), 'Page unload releases owned inert from ' + node.id);
    equal(page.requests.length, 0, 'Admin-shell acquisition and handover never request, save or replay a task'); equal(page.api.navigation.length, 0, 'Admin-shell acquisition and handover never navigate');
  } finally { page.close(); }
}

async function testGeneralFseControlSafety() {
  const page = fixture({ siteFrame: 'fse', url: 'https://bhranti.wp.local/wp-admin/site-editor.php', context: { screen: 'site-editor' }, session: siteTask() });
  try {
    assertSiteControlled(page, 'Clean native Site Editor'); equal(page.api.editorState().editor_active, true, 'Site Editor retains native active-editor heartbeat semantics');
    page.setDirty(true); page.api.render(); assertSiteReleased(page, 'Native Site Editor unsaved template changes'); equal(page.api.runtimeContext().unsaved_changes, true, 'Native template edits preserve the server execution dirty flag');
    page.setDirty(false); page.setSaving(true); page.api.render(); assertSiteReleased(page, 'Native Site Editor save in flight');
    page.setSaving(false); page.api.render(); assertSiteControlled(page, 'Native template save completion');
    page.setGesture(true); page.api.render(); assertSiteReleased(page, 'Native template block-drag gesture'); page.setGesture(false); page.api.render(); assertSiteControlled(page, 'Finished native template gesture');
    page.w._wpCollaborationEnabled = true; page.api.render(); assertSiteReleased(page, 'Native collaborative editing'); page.w._wpCollaborationEnabled = false; page.api.render(); assertSiteControlled(page, 'Noncollaborative native template editing');
    equal(page.w.document.querySelectorAll('.bhranti-editor-control').length, 0, 'General Site Editor control never claims a native post/page identity'); equal(page.requests.length, 0, 'Site Editor control never edits, saves or fetches a template');
  } finally { page.close(); }
}

async function testGeneralControlWithoutExternalOpener() {
  const page = fixture({ siteFrame: 'fse', url: 'https://bhranti.wp.local/wp-admin/site-editor.php', context: { screen: 'site-editor' }, session: siteTask({ phase: 'action' }) });
  try {
    const doc = page.w.document; equal(doc.getElementById('bhranti-close').disabled, false, 'An in-flight task can close with its dedicated reopening control'); doc.getElementById('wpadminbar').remove(); page.api.render();
    const overlay = siteOverlay(page); check(overlay, 'A fullscreen/no-bar Site Editor retains its bound native action protection');
    const opener = doc.getElementById('bhranti-site-opener'); check(opener.hidden, 'An open sidebar needs no redundant floating button');
    equal(doc.getElementById('bhranti-close').disabled, false, 'A no-bar admin can close because the dedicated opener survives');
    page.api.closePanel(); equal(page.api.state.open, false, 'Closing a running action leaves only the dedicated reopening control'); equal(siteOverlay(page), overlay, 'Closing retains the same exact action protection');
    check(page.api.triggerAvailable(opener), 'The dedicated opener becomes available while every native frame stays inert'); equal(doc.activeElement, opener, 'Closing moves focus to the accessible reopening button');
    page.api.state.busy = true; opener.click(); await page.advance();
    equal(page.api.state.open, true, 'The dedicated opener restores chat during an in-flight action'); equal(doc.getElementById('bhranti-site-opener'), opener, 'Reopening preserves the same control without replacing an active click target'); check(opener.hidden, 'Reopening hides the redundant floating button again');
    page.api.state.busy = false;
    check(!doc.getElementById('bhranti-stop').disabled, 'Stop remains usable in the only visible task controls');
    page.api.state.session.run.phase = 'model'; page.api.state.session.events.push(tool('handover', 'read')); delete page.api.state.session.run.next_action; page.api.render();
    equal(doc.getElementById('bhranti-close').disabled, false, 'Normal Close becomes available after safe server handover');
    page.api.closePanel(); equal(page.api.state.open, false, 'A handed-over no-bar task may close normally'); assertSiteReleased(page, 'Closed fullscreen task after handover');
    equal(page.requests.length, 0, 'No-opener safety does not issue a task, cancel or replay request');
  } finally { page.close(); }
}

async function testSettingsActionBridgeProof() {
  const page = fixture({ siteFrame: 'admin', settingsForm: 'clean', url: 'https://bhranti.wp.local/wp-admin/admin.php?page=bhranti', session: session('running', { phase: 'model' }) });
  try {
    let changes = 0; page.w.addEventListener('bhranti:settings-changed', () => { changes++; });
    const savedTarget = { type: 'bhranti_settings', title: 'Bhranti Settings', url: '/wp-admin/admin.php?page=bhranti' };
    const successful = tool('fresh-settings-write', 'write', { target: savedTarget, task_limit: { max_steps: 129, revision: 'fixture-revision-129' } });
    for (const event of [tool('settings-read', 'read', { target: savedTarget }), tool('foreign-settings-write', 'write', { target: savedTarget, run_id: 'another-run' }), tool('failed-settings-write', 'write', { target: savedTarget, success: false }), tool('unsafe-settings-target', 'write', { target: { ...savedTarget, url: 'https://outside.invalid/wp-admin/admin.php?page=bhranti' } })]) {
      page.api.state.session.events.push(event); page.api.render(); equal(changes, 0, 'Only a new owned successful native Settings write can request a saved configuration refresh');
    }
    page.api.state.session.events.push(successful); page.api.render(); equal(changes, 1, 'A newly observed successful Settings write emits one local safe-read notification');
    page.api.state.session = clone(page.api.state.session); page.api.render(); page.api.markExistingEvents(); equal(changes, 1, 'Cloned polling and existing-history marking never repeat the Settings notification');
    page.api.state.session.events.push(tool('fresh-settings-undo', 'rollback', { target: savedTarget, task_limit: { max_steps: 128, revision: 'fixture-revision-undo' } })); page.api.render(); equal(changes, 2, 'A new successful owned Undo also requests the latest saved Settings');
    equal(page.requests.length, 0, 'The event bridge never replaces settings or dispatches a POST itself');
    const historical = fixture({ siteFrame: 'admin', settingsForm: 'clean', session: { ...session('complete'), events: [successful] } });
    try { let replays = 0; historical.w.addEventListener('bhranti:settings-changed', () => { replays++; }); historical.api.render(); historical.api.markExistingEvents(); equal(replays, 0, 'Opening a completed historical Settings chat never requests a synchronization replay'); } finally { historical.close(); }
  } finally { page.close(); }
}

async function main() {
  await testTransportBoundaries();
  await testReferenceImageContext();
  await testAwaitingInputAndIntent();
  await testPollingOutage();
  await testNoncriticalAndExpiredChat();
  await testInitialOutageCard();
  await testDraftFocusAndScroll();
  await testCompositionPreservation();
  await testResolvedErrorsAndControls();
  await testActionTargetLinks();
  await testCommerceTargetLinks();
  await testSubmissionAcknowledgement();
  await testUncertainActionRecovery();
  await testBackgroundPreparedStepRace();
  await testPendingHeartbeatRecovery();
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
  await testGeneralSiteControlLifecycle();
  await testGeneralSiteControlProofAndRestoration();
  await testGeneralSiteControlDirtyAndDialogs();
  await testClassicProductControlAndPriceProtection();
  await testSettingsStateControlAndHeartbeat();
  await testGenericNativeNavigationAndGeometry();
  await testGeneralAdminShellRestoration();
  await testGeneralFseControlSafety();
  await testGeneralControlWithoutExternalOpener();
  await testSettingsActionBridgeProof();
  console.log('PASS ' + checks + ' isolated product UX checks; no HTTP, AI, WordPress load or DB access');
}
const watchdog = setTimeout(() => { console.error('FAIL: unresolved isolated UI operation exceeded the 20-second test deadline'); process.exit(1); }, 20000);
main().catch((error) => { console.error(error); process.exitCode = 1; }).finally(() => clearTimeout(watchdog));
