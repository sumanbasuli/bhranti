/** Real admin.js with isolated native-shaped editor stores; no HTTP, AI or WordPress load. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
let JSDOM;
try { ({ JSDOM } = require('jsdom')); }
catch { ({ JSDOM } = require(process.env.BHRANTI_TEST_JSDOM_PATH || '/Users/txsadhu/node_modules/jsdom')); }
const source = fs.readFileSync(path.join(process.env.BHRANTI_TEST_PLUGIN_ROOT || path.dirname(__dirname), 'assets/admin.js'), 'utf8');
const marker = /syncTrigger\(\s*\);\s*if\s*\(\s*settings\.autoOpen\s*\)\s*openPanel\(\s*\);/g;
assert.equal((source.match(marker) || []).length, 1, 'Instrument only the actual known initializer');
const names = [...source.matchAll(/^\t(?:async )?function (\w+)\(/gm)].map((match) => match[1]);
const instrumented = source
  .replace(/window\.location\.assign\(([^;]+)\);/g, 'window.review.navigation.push({ kind: "assign", url: $1 });')
  .replace(/window\.location\.reload\([^;]*\);/g, 'window.review.navigation.push({ kind: "reload", url: window.location.href });')
  .replace(marker, (initializer) => 'window.review = { state, editorSync, ' + names.join(', ') + ', navigation: [] }; ' + initializer);
const runId = '91000000-1000-4000-8000-100000000017';
const clone = (value) => JSON.parse(JSON.stringify(value));
function record(id = 99, type = 'page', text = 'Saved before Bhranti.') {
  return { id, type, status: 'draft', title: { raw: 'Fixture title', rendered: 'Fixture title' }, content: { raw: '<!-- wp:paragraph --><p>' + text + '</p><!-- /wp:paragraph -->', rendered: '<p>' + text + '</p>' }, excerpt: { raw: '', rendered: '' }, modified: '2026-10-09T00:00:00', modified_gmt: '2026-10-09T00:00:00' };
}
function rawRecord(value) {
  return { ...value, title: value.title && value.title.raw, content: value.content && value.content.raw, excerpt: value.excerpt && value.excerpt.raw };
}
function action(overrides = {}) {
  return { id: 'new-write-1', type: 'action', success: true, operation: 'write', run_id: runId, action_id: 'snapshot-1', summary: 'The fixture page was updated.', target: { id: 99, type: 'page', title: 'Fixture title', url: '/wp-admin/post.php?post=99&action=edit' }, ...overrides };
}
function session(status = 'running', events = []) {
  return { id: 17, agent_id: 'bhranti', messages: [{ role: 'user', content: 'Update the fixture page.' }], events, run: { id: runId, status, phase: '', step: 4, background: true, editor: { dirty: false, active: false, write_blocked: false } } };
}
function preparedEditorAction(overrides = {}) {
  return { tool: 'wp_request', method: 'POST', path: '/wp/v2/pages/99', write: true, summary: 'Update this fixture page.', target: action().target, ...overrides };
}
const flush = async () => { await Promise.resolve(); await new Promise((resolve) => setImmediate(resolve)); };
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }
function fixture(options = {}) {
  const adminBar = options.adminBar === 'absent' ? '' : '<div id="wpadminbar"><div id="wp-admin-bar-bhranti"><a href="#">Bhranti</a></div></div>';
  const dom = new JSDOM('<!doctype html>' + adminBar + '<main id="wpbody-content"><div id="editor" class="block-editor__container"><div class="interface-interface-skeleton"><div class="editor-header edit-post-header"><div class="editor-header__toolbar"><button id="native-toolbar-control" type="button">Native editor toolbar</button></div><div class="editor-header__settings"><button id="native-settings-control" type="button">Native settings</button></div></div><div class="editor-post-title__input" contenteditable="true">Fixture title</div><div class="block-editor-writing-flow"><p id="native-editor-input" contenteditable="true">Saved before Bhranti.</p></div></div></div></main><div id="bhranti-app" hidden></div>', { url: options.url || 'https://bhranti.wp.local/wp-admin/post.php?post=99&action=edit', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  let now = 1791500000000, timerSequence = 0, currentId = 99, currentType = 'page', saved = record(), edits = {}, saving = false, autosaving = false, nonPostDirty = false, dragging = false, multiSelecting = false;
  let savedRaw = rawRecord(saved);
  let remote = { dirty: false, active: false, write_blocked: false, stale_dirty: false };
  const timers = new Map(), requests = [], received = [], forbidden = [], subscriptions = [];
  const timer = (callback, delay = 0, interval = false) => { const id = ++timerSequence; timers.set(id, { callback, delay, at: now + delay, interval }); return id; };
  w.Date.now = () => now; w.setTimeout = (callback, delay) => timer(callback, delay); w.clearTimeout = (id) => timers.delete(id); w.setInterval = (callback, delay) => timer(callback, delay, true); w.clearInterval = w.clearTimeout;
  w.requestAnimationFrame = (callback) => timer(() => callback(now), 16); w.cancelAnimationFrame = w.clearTimeout;
  const nativeBounds = () => ({ x: 32, y: 48, left: 32, top: 48, right: 1032, bottom: 748, width: 1000, height: 700 });
  w.document.getElementById('editor').getBoundingClientRect = nativeBounds; w.document.querySelector('.interface-interface-skeleton').getBoundingClientRect = nativeBounds;
  const bar = w.document.getElementById('wpadminbar'), nativeTrigger = w.document.querySelector('#wp-admin-bar-bhranti > a');
  if (bar) {
    const barBounds = () => ({ x: 0, y: 0, left: 0, top: 0, right: 1200, bottom: 32, width: 1200, height: 32 });
    bar.getBoundingClientRect = barBounds; bar.getClientRects = () => bar.hidden || w.getComputedStyle(bar).display === 'none' ? [] : [barBounds()];
    nativeTrigger.getBoundingClientRect = () => ({ x: 200, y: 0, left: 200, top: 0, right: 280, bottom: 32, width: 80, height: 32 }); nativeTrigger.getClientRects = () => bar.getClientRects();
    if (options.adminBar === 'hidden') bar.hidden = true;
    if (options.adminBar === 'display-none') bar.style.display = 'none';
    if (options.adminBar === 'visibility-hidden') bar.style.visibility = 'hidden';
  }
  if (options.fullscreen) { const style = w.document.createElement('style'); style.textContent = 'body.is-fullscreen-mode #wpadminbar { display: none; }'; w.document.head.appendChild(style); w.document.body.classList.add('is-fullscreen-mode'); }
  w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.fetch = () => { throw new Error('Editor sync tests must never make a network request.'); }; w.confirm = () => true;
  w.open = (url) => { w.review.navigation.push({ kind: 'open', url }); return null; };
  const notify = () => subscriptions.forEach((callback) => callback());
  const nonTransientEdits = () => Object.fromEntries(Object.entries(edits).filter(([key]) => !['blocks', 'selection', 'selectionStart', 'selectionEnd'].includes(key)));
  const core = {
    getEntityRecord: (kind, type, id) => kind === 'postType' && type === currentType && String(id) === String(currentId) ? saved : null,
    getRawEntityRecord: (kind, type, id) => kind === 'postType' && type === currentType && String(id) === String(currentId) ? savedRaw : null,
    getEditedEntityRecord: () => ({ ...savedRaw, ...edits }), getEntityRecordEdits: () => edits, getEntityRecordNonTransientEdits: nonTransientEdits,
    hasEditsForEntityRecord: () => saving || Object.keys(nonTransientEdits()).length > 0,
    isSavingEntityRecord: () => saving, isAutosavingEntityRecord: () => autosaving, isDeletingEntityRecord: () => false,
    getEntityConfig: () => ({ kind: 'postType', name: currentType, baseURL: '/wp/v2/' + (currentType === 'page' ? 'pages' : 'posts'), key: 'id' }),
    __experimentalGetDirtyEntityRecords: () => nonPostDirty ? [{ kind: 'root', name: 'site', key: 1 }] : [],
  };
  const editor = {
    getCurrentPostId: () => currentId, getCurrentPostType: () => currentType, getCurrentPost: () => savedRaw,
    getCurrentPostAttribute: (name) => savedRaw[name], getEditedPostAttribute: (name) => name in edits ? edits[name] : savedRaw[name],
    getEditedPostContent: () => 'content' in edits ? edits.content : saved.content.raw,
    isEditedPostDirty: () => saving || Object.keys(nonTransientEdits()).length > 0, isSavingPost: () => saving, isAutosavingPost: () => autosaving,
    hasNonPostEntityChanges: () => nonPostDirty,
  };
  const forbiddenAction = (store, name) => (...args) => { forbidden.push({ store, name, args }); throw new Error('Editor synchronization cannot call ' + store + '.' + name); };
  const coreDispatch = new Proxy({ receiveEntityRecords: (...args) => {
    received.push(args); const [kind, type, values] = args; const value = Array.isArray(values) ? values[0] : values;
    assert.equal(kind, 'postType', 'Only saved post entity state may be received'); assert.equal(type, currentType, 'Saved entity type remains the native current type'); assert.equal(value.id, currentId, 'Saved entity ID remains the native current ID');
    saved = clone(value); savedRaw = rawRecord(saved);
    // Match installed core-data RECEIVE_ITEMS: remove only edits equal to the
    // received value or to the captured persistedEdits sixth argument.
    const persisted = args[5];
    edits = Object.fromEntries(Object.entries(edits).filter(([key, edit]) => JSON.stringify(edit) !== JSON.stringify(saved[key] && saved[key].raw !== undefined ? saved[key].raw : saved[key]) && (!persisted || JSON.stringify(edit) !== JSON.stringify(persisted[key]))));
    notify();
  } }, { get: (target, key) => key in target ? target[key] : forbiddenAction('core', String(key)) });
  let handler = options.handler || (async (request) => {
    if (request.path.includes('/editor-state')) return remote;
    if (/\/sessions\/17$/.test(request.path)) return { ...clone(w.review.state.session), run: { ...clone(w.review.state.session.run), editor: remote } };
    if (request.path.startsWith('/wp/v2/pages/99')) return record(99, 'page', 'Saved after Bhranti.');
    throw new Error('Unexpected isolated editor request: ' + request.method + ' ' + request.path);
  });
  w._wpCollaborationEnabled = !!options.collaboration;
  w.bhrantiSettings = { autoOpen: false, isAdmin: true, userId: 1, backgroundEnabled: !!options.background, connectorsUrl: '/wp-admin/options-connectors.php', context: { screen: 'page', post_id: 99, post_type: 'page' }, ...(options.settings || {}) };
  w.wp = { i18n: { __: (text) => text, sprintf: (text, ...values) => { let index = 0; return text.replace(/%[sd]/g, () => values[index++]); } },
    data: { subscribe: (callback) => { subscriptions.push(callback); return () => {}; }, select: (store) => store === 'core/editor' ? editor : ['core', 'core/data'].includes(store) ? core : store === 'core/block-editor' ? { isDraggingBlocks: () => dragging, isMultiSelecting: () => multiSelecting, getSelectedBlockClientId: () => 'selected-block-1', getBlocks: () => [{ name: 'core/paragraph', clientId: 'selected-block-1', attributes: { content: editor.getEditedPostContent() } }] } : undefined,
      dispatch: (store) => ['core', 'core/data'].includes(store) ? coreDispatch : store === 'core/notices' ? { createNotice() {}, removeNotice() {} } : new Proxy({}, { get: (_, key) => forbiddenAction(store, String(key)) }) },
    notices: { store: 'core/notices' }, apiFetch: async (request) => { requests.push({ ...request, at: now }); return handler(request); } };
  w.eval(instrumented);
  const api = w.review; api.state.initialized = true; api.state.workspace = { sessions: [{ id: 17, title: 'Fixture' }], agents: [{ id: 'bhranti', name: 'Bhranti' }], ai: { available: true } }; api.state.session = options.session || session(); api.state.open = true;
  api.markExistingEvents(); api.render();
  return { dom, w, api, core, editor, requests, received, forbidden, timers,
    setHandler(next) { handler = next; }, setRemote(next) { remote = next; api.state.editor = next; },
    setEdits(next) { edits = { ...next }; notify(); }, setSaving(next, auto = false) { saving = next; autosaving = auto; notify(); }, setNonPostDirty(next) { nonPostDirty = next; notify(); },
    setGesture(kind, active) { if (kind === 'drag') dragging = active; else { assert.equal(kind, 'multi-select'); multiSelecting = active; } notify(); },
    switchPost(id, type = 'page') { currentId = id; currentType = type; saved = record(id, type); savedRaw = rawRecord(saved); edits = {}; notify(); },
    nativeSave(value = record(currentId, currentType, 'Saved manually during the fetch.')) { saved = clone(value); savedRaw = rawRecord(saved); edits = {}; saving = false; autosaving = false; notify(); },
    saved: () => clone(saved), edits: () => ({ ...edits }),
    async advance(milliseconds = 0) {
      now += milliseconds; let calls = 0;
      while (true) { const due = [...timers].filter(([, entry]) => entry.at <= now).sort((a, b) => a[1].at - b[1].at)[0]; if (!due) break;
        if (++calls > 100) throw new Error('Unbounded editor-sync fixture timer loop.'); const [id, entry] = due; timers.delete(id); if (entry.interval) timers.set(id, { ...entry, at: now + entry.delay }); entry.callback(); await flush(); }
      await flush();
    },
    close() { w.dispatchEvent(new w.PageTransitionEvent('pagehide')); dom.window.close(); },
  };
}
let checks = 0, currentCase = 'initialization';
function check(value, label) { assert.ok(value, label); checks++; }
function equal(actual, expected, label) { assert.equal(actual, expected, label); checks++; }
function noWrites(page, label) { equal(page.requests.filter((request) => request.method && request.method !== 'GET' && !request.path.endsWith('/editor-state')).length, 0, label); equal(page.forbidden.length, 0, 'No editor edits, block reset or save dispatch occurred'); }
function flushSync(page) { if (page.api.editorSync.timer !== null) { page.w.clearTimeout(page.api.editorSync.timer); page.api.editorSync.timer = null; } return page.api.flushEditorChanges(); }
const entityReads = (page) => page.requests.filter((request) => /^\/wp\/v2\/(posts|pages)\/\d+\?context=edit$/.test(request.path));
function queue(page, event = action(), observer = 'render') { page.api.state.session.events.push(event); page.api[observer](); }
async function until(test, page, delay = 20, count = 100) { for (let index = 0; index < count; index++) { await flush(); if (test()) return; await page.advance(delay); } throw new Error('The isolated editor synchronization did not settle.'); }

async function testSavedStateBeforeCompletion() {
  const full = { ...record(99, 'page', 'Saved canonical content from Bhranti.'), featured_media: 71, template: 'default', meta: { fixture: 'kept' } };
  const page = fixture({ handler: async () => full });
  try {
    const transient = { blocks: [{ name: 'core/paragraph', clientId: 'old-block', attributes: { content: 'Saved before Bhranti.' } }], selectionStart: { clientId: 'old-block' } };
    page.setEdits(transient);
    const input = page.w.document.getElementById('bhranti-composer'); input.focus(); input.value = 'An unsent follow-up'; input.dispatchEvent(new page.w.Event('input', { bubbles: true })); input.setSelectionRange(2, 7);
    queue(page); await flushSync(page);
    equal(page.api.state.session.run.status, 'running', 'A completed action updates the native editor before the task finishes');
    equal(page.received.length, 1, 'The successful committed action receives one native saved entity');
    const args = page.received[0]; equal(args[0], 'postType', 'Native receive targets saved postType entity state'); equal(args[1], 'page', 'Native receive targets the exact current post type');
    equal(args[3], undefined, 'Native receive uses the canonical default entity context'); equal(args[4], true, 'Native receive invalidates stale saved record caches');
    assert.deepEqual(args[5], transient); checks++;
    equal(page.saved().content.raw, full.content.raw, 'Native saved content becomes the canonical action result');
    equal(page.saved().featured_media, 71, 'Full canonical record preserves featured media'); equal(page.saved().meta.fixture, 'kept', 'Full canonical record preserves other saved attributes');
    equal(Object.keys(page.edits()).length, 0, 'Only unchanged clean transient block/selection edits are cleared');
    equal(page.editor.isEditedPostDirty(), false, 'Receiving the committed record does not create an unsaved edit');
    equal(page.w.document.activeElement.id, 'bhranti-composer', 'Native saved record synchronization retains sidebar focus');
    equal(page.w.document.activeElement.value, 'An unsent follow-up', 'Native editor sync retains the exact unsent draft'); equal(page.w.document.activeElement.selectionStart, 2, 'Native editor sync retains the draft selection');
    equal(page.api.navigation.length, 0, 'Native editor sync never reloads or navigates the page'); noWrites(page, 'Native editor synchronization performs GET only');
    const reads = entityReads(page).length; page.api.render(); page.api.markExistingEvents(); await page.advance();
    equal(entityReads(page).length, reads, 'Repeated renders and normal seen-event updates cannot refetch the same action');
    equal(page.received.length, 1, 'A committed event is applied only once');
    queue(page, action({ id: 'new-write-2', action_id: 'snapshot-2' }), 'markExistingEvents'); await flushSync(page);
    equal(page.received.length, 2, 'A second new action updates the native entity independently');
    queue(page, action({ id: 'rollback-3', operation: 'rollback' })); await flushSync(page);
    equal(page.received.length, 3, 'A committed rollback also receives the fresh saved native entity');
  } finally { page.close(); }
}

async function testEventEligibilityAndHistory() {
  const page = fixture({ session: session('complete', [action({ id: 'historical-success' })]) });
  try {
    await page.advance(); await flushSync(page); equal(entityReads(page).length, 0, 'Opening a completed historical chat does not overwrite native saved state');
    const invalid = [
      { type: 'error' }, { success: false }, { success: null }, { operation: 'read' }, { operation: 'image' }, { operation: 'unknown' }, { run_id: 'other-run' }, { id: '' },
      { target: { ...action().target, id: 100 } }, { target: { ...action().target, type: 'post' } }, { target: { ...action().target, type: 'attachment' } },
      { target: { ...action().target, url: '' } }, { target: { ...action().target, url: 'https://external.invalid/post' } }, { target: { ...action().target, url: '/wp-admin/post.php?post=99&action=delete' } },
    ];
    for (let index = 0; index < invalid.length; index++) { queue(page, action({ id: 'invalid-' + index, ...invalid[index] })); await flushSync(page); equal(entityReads(page).length, 0, 'Unproven/read/image/foreign/unsafe event ' + index + ' cannot synchronize editor state'); }
    equal(page.received.length, 0, 'Ineligible events never reach native saved entity dispatch');
    page.api.state.session = { ...session('complete', [action({ id: 'other-history', run_id: 'other-run' })]), id: 18, run: { ...session().run, id: 'other-run', status: 'complete' } }; page.api.render(); await flushSync(page);
    equal(entityReads(page).length, 0, 'Switching to a different chat seeds that chat history without editor application');
    page.api.state.open = false; queue(page, action({ id: 'closed-new-write', run_id: 'other-run' }), 'markExistingEvents'); await flushSync(page);
    equal(page.received.length, 1, 'An already observed closed chat can receive its newly committed matching action');
    equal(page.api.navigation.length, 0, 'Closed observer synchronization never opens or reloads the editor'); noWrites(page, 'Eligibility and history checks never write native content');
  } finally { page.close(); }
  for (const unsupported of ['wp_template', 'wp_template_part', 'attachment']) { const other = fixture(); try { other.switchPost(99, unsupported); queue(other, action({ target: { ...action().target, type: unsupported } })); await flushSync(other); equal(entityReads(other).length, 0, 'Unsupported ' + unsupported + ' editor remains untouched'); } finally { other.close(); } }
  const collaboration = fixture({ collaboration: true }); try { queue(collaboration); await flushSync(collaboration); equal(collaboration.received.length, 0, 'Native collaboration skips local saved-state synchronization'); equal(entityReads(collaboration).length, 0, 'Native collaboration performs no canonical editor read'); } finally { collaboration.close(); }
  const unavailable = fixture(); try { delete unavailable.core.getEntityRecordEdits; queue(unavailable); await flushSync(unavailable); equal(unavailable.received.length, 0, 'Missing native safety selectors fail closed'); } finally { unavailable.close(); }
}

async function testLocalDeferral() {
  for (const kind of ['dirty', 'saving', 'autosaving', 'nonpost-dirty', 'focused', 'iframe']) {
    const page = fixture();
    try {
      if (kind === 'dirty') page.setEdits({ content: 'Exact unsaved user content.' });
      if (kind === 'nonpost-dirty') page.setNonPostDirty(true);
      if (kind === 'saving') page.setSaving(true); if (kind === 'autosaving') page.setSaving(true, true);
      if (kind === 'focused') page.w.document.getElementById('native-editor-input').focus();
      if (kind === 'iframe') { page.api.state.session.run.status = 'complete'; const frame = page.w.document.createElement('iframe'); page.w.document.body.appendChild(frame); frame.focus(); }
      queue(page); await flushSync(page); equal(entityReads(page).length, 0, kind + ' native editing defers before the canonical GET'); equal(page.received.length, 0, kind + ' state cannot be overwritten');
      check(page.api.editorSync.pending, kind + ' deferral retains the exact committed event checkpoint'); check(page.api.editorSync.message, kind + ' deferral has a nonblocking editor status');
      if (kind === 'dirty') equal(page.edits().content, 'Exact unsaved user content.', 'Deferral preserves exact native user edits');
      page.setEdits({}); page.setSaving(false); page.setNonPostDirty(false); page.w.document.getElementById('bhranti-composer').focus(); page.api.nativeEditorChanged();
      await flushSync(page); equal(page.received.length, 1, kind + ' pending update applies after the native state is clean and idle'); equal(page.api.editorSync.message, '', kind + ' deferral status clears after successful synchronization');
      equal(page.editor.isEditedPostDirty(), false, kind + ' recovery does not invent unsaved edits'); equal(page.api.navigation.length, 0, kind + ' recovery uses native stores instead of navigation'); noWrites(page, kind + ' recovery never repeats a content mutation');
    } finally { page.close(); }
  }
}

async function testNativeBlockGestures() {
  for (const kind of ['drag', 'multi-select']) {
    const initial = fixture();
    try {
      initial.setGesture(kind, true); queue(initial); await flushSync(initial);
      equal(entityReads(initial).length, 0, 'Active native ' + kind + ' defers before fetching saved content'); equal(initial.received.length, 0, 'Active native ' + kind + ' cannot have blocks replaced'); check(initial.api.editorSync.pending, 'Active ' + kind + ' retains the committed action checkpoint');
      initial.setGesture(kind, false); await flushSync(initial);
      equal(initial.received.length, 1, 'Completed native ' + kind + ' permits one fresh saved record receive'); equal(initial.editor.isEditedPostDirty(), false, 'Finishing ' + kind + ' does not fabricate an unsaved edit'); noWrites(initial, 'Native ' + kind + ' deferral never replays the Bhranti action');
    } finally { initial.close(); }
    for (const endsBeforeResponse of [false, true]) {
      const old = deferred(); let reads = 0; const latest = record(99, 'page', 'Saved version after the native ' + kind + ' gesture.');
      const pending = fixture({ handler: async () => ++reads === 1 ? old.promise : latest });
      try {
        const toolbar = pending.w.document.createElement('button'); toolbar.type = 'button'; toolbar.textContent = 'Native editor toolbar'; pending.w.document.getElementById('wpbody-content').appendChild(toolbar); toolbar.focus();
        queue(pending); const syncing = flushSync(pending); await flush(); equal(entityReads(pending).length, 1, 'Native ' + kind + ' race begins with exactly one canonical read');
        pending.setGesture(kind, true); if (endsBeforeResponse) pending.setGesture(kind, false);
        old.resolve(record(99, 'page', 'Stale response from before native interaction.')); await syncing;
        if (!endsBeforeResponse) { equal(pending.received.length, 0, 'Native ' + kind + ' beginning during GET invalidates that result'); check(pending.api.editorSync.pending, 'Mid-read native ' + kind + ' keeps the committed action while the gesture is active'); pending.setGesture(kind, false); await flushSync(pending); }
        equal(pending.received.length, 1, 'Native ' + kind + ' end applies a fresh canonical record once'); equal(entityReads(pending).length, 2, 'Native ' + kind + ' race requires a new GET after the interaction epoch changes');
        check(pending.received.every((args) => (Array.isArray(args[2]) ? args[2][0] : args[2]).content.raw === latest.content.raw), 'Native ' + kind + (endsBeforeResponse ? ' start/end before response' : ' held through response') + ' never receives the stale saved content');
        equal(pending.w.document.activeElement, toolbar, 'Native ' + kind + ' recovery preserves focus on the outer editor toolbar'); equal(pending.api.navigation.length, 0, 'Native ' + kind + ' recovery never reloads or navigates'); noWrites(pending, 'Native ' + kind + ' recovery performs no editor saves or action replay');
      } finally { old.resolve(record()); pending.close(); }
    }
  }
}

async function testInFlightRaces() {
  for (const kind of ['dirty', 'saving', 'focused', 'post-switch', 'type-switch', 'chat-switch', 'pagehide']) {
    const wait = deferred(), page = fixture({ handler: async () => wait.promise });
    try {
      queue(page); const syncing = flushSync(page); await flush(); equal(entityReads(page).length, 1, 'Race ' + kind + ' starts one canonical saved read');
      if (kind === 'dirty') page.setEdits({ content: 'Typed while the canonical read was pending.' }); if (kind === 'saving') page.setSaving(true);
      if (kind === 'focused') page.w.document.getElementById('native-editor-input').focus();
      if (kind === 'post-switch') page.switchPost(100); if (kind === 'type-switch') page.switchPost(99, 'post');
      if (kind === 'chat-switch') { page.api.state.session = { ...session(), id: 18 }; page.api.render(); }
      if (kind === 'pagehide') page.w.dispatchEvent(new page.w.PageTransitionEvent('pagehide'));
      wait.resolve(record(99, 'page', 'A stale response that must not be received.')); await syncing;
      equal(page.received.length, 0, 'In-flight ' + kind + ' invalidates the old canonical response');
      if (kind === 'dirty') equal(page.edits().content, 'Typed while the canonical read was pending.', 'In-flight typing preserves the new native content');
      equal(page.api.navigation.length, 0, 'In-flight ' + kind + ' recovery never navigates'); noWrites(page, 'In-flight ' + kind + ' does not replay any mutation');
    } finally { page.close(); }
  }
  const wait = deferred(); let reads = 0; const manual = record(99, 'page', 'The user typed and saved while the older GET was pending.');
  const page = fixture({ handler: async () => ++reads === 1 ? wait.promise : manual });
  try {
    queue(page); const syncing = flushSync(page); await flush(); page.setEdits({ content: manual.content.raw }); page.nativeSave(manual);
    wait.resolve(record(99, 'page', 'Older saved result.')); await syncing;
    equal(page.received.length, 1, 'Coalesced recovery drains the fresh read after the dirty-then-saved epoch changes');
    check(page.received.every((args) => (Array.isArray(args[2]) ? args[2][0] : args[2]).content.raw === manual.content.raw), 'Dirty then manually saved clean never receives the older response'); equal(page.saved().content.raw, manual.content.raw, 'Manual save remains authoritative through the fresh canonical read');
    equal(entityReads(page).length, 2, 'The changed editor generation fetches a new canonical version'); equal(page.saved().content.raw, manual.content.raw, 'Recovery receives the latest manually saved version without restoring stale content'); noWrites(page, 'Manual-save race recovery performs no content mutation');
  } finally { page.close(); }
}

async function testNewerActionWins() {
  const older = deferred(); let reads = 0; const latest = record(99, 'page', 'The second committed Bhranti action.');
  const page = fixture({ handler: async () => ++reads === 1 ? older.promise : latest });
  try {
    queue(page); const first = flushSync(page); await flush(); queue(page, action({ id: 'new-write-2' })); const coalesced = flushSync(page);
    equal(coalesced, first, 'Concurrent editor flushes await the same in-flight canonical read');
    equal(entityReads(page).length, 1, 'A newer event does not launch a parallel unbound saved read');
    older.resolve(record(99, 'page', 'The first action response.')); await Promise.all([first, coalesced]);
    equal(page.received.length, 1, 'Coalesced flush drains the newer event before resolving'); check(page.received.every((args) => (Array.isArray(args[2]) ? args[2][0] : args[2]).content.raw === latest.content.raw), 'New event token invalidates the older in-flight result');
    equal(page.saved().content.raw, latest.content.raw, 'The latest committed action updates the native saved entity'); equal(entityReads(page).length, 2, 'Newer event triggers one fresh canonical read after the old read settles');
    page.api.render(); await page.advance(); equal(page.received.length, 1, 'Out-of-order action reads cannot duplicate native dispatch'); noWrites(page, 'Multiple action notifications never replay the server actions');
  } finally { page.close(); }
}

async function testFollowupRunAndRemoteSafetyEpoch() {
  const oldRead = deferred(); let reads = 0;
  const latest = record(99, 'page', 'The prior committed action remains saved during a read-only follow-up.');
  const followup = fixture({ handler: async () => ++reads === 1 ? oldRead.promise : latest });
  try {
    queue(followup); const syncing = flushSync(followup); await flush();
    followup.api.state.session.run = { ...followup.api.state.session.run, id: 'followup-no-write', phase: 'model' }; followup.api.render();
    check(followup.api.editorSync.pending, 'A same-chat read-only follow-up retains the earlier successful owned action for fresh editor synchronization');
    oldRead.resolve(record(99, 'page', 'Old GET started before the follow-up run.')); await syncing;
    equal(followup.received.length, 1, 'Coalesced same-chat run recovery finishes the fresh read before resolving'); check(followup.received.every((args) => (Array.isArray(args[2]) ? args[2][0] : args[2]).content.raw === latest.content.raw), 'A new same-chat run invalidates the prior run in-flight response');
    equal(entityReads(followup).length, 2, 'Same-chat follow-up refetches the retained committed update once'); equal(followup.saved().content.raw, latest.content.raw, 'Read-only follow-up still receives the latest already saved content'); noWrites(followup, 'Retaining prior committed action never replays that action or submits the follow-up');
  } finally { oldRead.resolve(record()); followup.close(); }
  const older = deferred(); let dirty = false, canonicalReads = 0;
  const remote = fixture({ background: true, handler: async (request) => {
    if (request.path.endsWith('/editor-state')) return { dirty, active: true, write_blocked: dirty, stale_dirty: dirty };
    if (request.path.startsWith('/wp/v2/pages/99')) return ++canonicalReads === 1 ? older.promise : record(99, 'page', 'Newest canonical state after the remote editor saved.');
    throw new Error('Unexpected remote safety epoch fixture request.');
  } });
  try {
    await flush(); queue(remote); const syncing = flushSync(remote); await until(() => entityReads(remote).length === 1, remote);
    dirty = true; await remote.api.reportEditor(false, true); dirty = false; await remote.api.reportEditor(false, true);
    older.resolve(record(99, 'page', 'Stale record from before the remote dirty-clean interval.')); await syncing;
    equal(remote.received.length, 1, 'Coalesced remote epoch recovery drains one fresh canonical read'); check(remote.received.every((args) => (Array.isArray(args[2]) ? args[2][0] : args[2]).content.raw === record(99, 'page', 'Newest canonical state after the remote editor saved.').content.raw), 'Remote dirty then clean before GET resolves still invalidates the stale saved response');
    equal(entityReads(remote).length, 2, 'Changed remote safety epoch requires one fresh canonical GET'); equal(remote.saved().content.raw, record(99, 'page', 'Newest canonical state after the remote editor saved.').content.raw, 'Remote dirty-clean recovery receives the latest saved state rather than the older response'); noWrites(remote, 'Remote safety recovery never repeats a Bhranti action or native content write');
  } finally { older.resolve(record()); remote.close(); }
}

async function testBoundedFailures() {
  for (const status of [401, 403, 404, 503]) {
    let failing = true;
    const page = fixture({ handler: async () => { if (failing) throw Object.assign(new Error('Local fixture read failed.'), { data: { status } }); return record(99, 'page', 'Recovered saved content.'); } });
    try {
      queue(page); await flushSync(page); equal(entityReads(page).length, 1, status + ' first canonical read returns without an immediate accidental retry');
      if (status === 503) { await page.advance(1999); equal(entityReads(page).length, 1, 'Canonical transient read retry respects its full two-second backoff'); await page.advance(1); }
      else await page.advance(2500);
      await flush();
      equal(entityReads(page).length, status === 503 ? 2 : 1, status + ' canonical read failure has bounded safe retries'); equal(page.received.length, 0, status + ' failed read cannot overwrite native saved state');
      equal(page.api.state.error, '', status + ' read failure remains a nonblocking editor status'); check(page.api.editorSync.pending, status + ' read failure retains its recovery checkpoint');
      equal(page.api.state.notices.length, 0, status + ' read failure does not duplicate generic error notices'); equal(page.saved().content.raw, record().content.raw, status + ' failure preserves the previous native saved entity');
      const attempts = entityReads(page).length; await page.advance(10000); equal(entityReads(page).length, attempts, status + ' failure cannot poll indefinitely');
      failing = false; queue(page, action({ id: 'new-recovery-' + status })); await flushSync(page); equal(page.received.length, 1, status + ' later proven action retries the canonical read without replaying a mutation'); equal(page.api.editorSync.message, '', status + ' recovered read clears its editor status'); noWrites(page, status + ' read recovery performs no content writes');
    } finally { page.close(); }
  }
  for (const invalid of [null, { ...record(), id: 100 }, { ...record(), type: 'post' }, { ...record(), status: 'trash' }, { ...record(), content: { rendered: 'Missing raw field.' } }]) {
    const page = fixture({ handler: async () => invalid }); try { queue(page); await flushSync(page); equal(page.received.length, 0, 'A malformed/wrong/trashed canonical record is never received'); equal(page.saved().content.raw, record().content.raw, 'Invalid canonical data preserves the native saved record'); noWrites(page, 'Invalid canonical data never causes a mutation'); } finally { page.close(); }
  }
  const timed = fixture({ handler: async (request) => new Promise((resolve, reject) => {
    request.signal.addEventListener('abort', () => reject(Object.assign(new Error('The isolated canonical read timed out.'), { name: 'AbortError' })), { once: true });
  }) });
  try {
    queue(timed); const first = flushSync(timed); await flush(); await timed.advance(20000); await first;
    equal(timed.received.length, 0, 'Timed-out canonical read never receives a partial saved entity'); equal(entityReads(timed).length, 1, 'First canonical read has a bounded 20-second abort deadline');
    await timed.advance(2000); equal(entityReads(timed).length, 2, 'Canonical timeout permits only one delayed safe retry'); await timed.advance(20000); await flush();
    const attempts = entityReads(timed).length; await timed.advance(10000); equal(entityReads(timed).length, attempts, 'Exhausted canonical timeouts cannot retry indefinitely');
    check(entityReads(timed).every((request) => request.signal.aborted), 'Every timed-out read receives the native abort signal'); equal(timed.api.state.error, '', 'Editor read timeout does not falsely fail the agent task'); noWrites(timed, 'Canonical timeout never repeats a prepared action or editor save');
  } finally { timed.close(); }
}

async function testFreshCrossTabSafety() {
  const page = fixture({ background: true, handler: async (request) => {
    if (request.path.endsWith('/editor-state')) return { dirty: true, active: true, write_blocked: true, stale_dirty: true };
    if (request.path.startsWith('/wp/v2/pages/99')) return record(99, 'page', 'Saved while another browser keeps its local edits.');
    throw new Error('Unexpected cross-tab fixture request.');
  } });
  try {
    await flush(); queue(page); await flushSync(page);
    equal(entityReads(page).length, 1, 'A dirty remote browser does not block the clean local saved-record GET'); equal(page.received.length, 1, 'Saved data is received only into this clean local editor');
    equal(page.api.editorSummary().dirty, true, 'Receiving saved data preserves the remote dirty summary used by server writes'); equal(page.api.editorSummary().write_blocked, true, 'Remote write protection stays enabled');
    equal(page.saved().content.raw, record(99, 'page', 'Saved while another browser keeps its local edits.').content.raw, 'The local editor displays already committed canonical content without cleaning another browser');
    equal(page.api.editorSync.message, '', 'A successful saved read has no misleading remote-tab pause'); equal(page.editor.isEditedPostDirty(), false, 'The remote dirty summary does not create a local unsaved edit'); equal(page.api.navigation.length, 0, 'Cross-tab saved receive keeps the editor page open'); noWrites(page, 'Cross-tab saved receive posts only its own editor lease status, never agent or core content writes');
    page.setEdits({ content: 'Local edits while another browser is also dirty.' }); queue(page, action({ id: 'remote-and-local-dirty' })); await flushSync(page);
    equal(entityReads(page).length, 1, 'Real local dirty state still blocks even a read while another browser is dirty'); equal(page.received.length, 1, 'Local dirty state cannot be overwritten after remote restrictions are removed'); equal(page.edits().content, 'Local edits while another browser is also dirty.', 'Exact local user changes remain untouched');
  } finally { page.close(); }
}

async function testNativeAndClassicDirtyDetectors() {
  const native = fixture(); let classicCalls = 0;
  try {
    native.w.wp.autosave = { server: { postChanged: () => { classicCalls++; return true; } } };
    equal(native.api.editorState().unsaved_changes, false, 'A clean initialized native post ignores an unrelated Classic autosave DOM baseline'); equal(classicCalls, 0, 'Classic autosave is not consulted for an initialized block editor');
    queue(native); await flushSync(native); equal(native.received.length, 1, 'A stale Classic detector cannot prevent a clean native saved-record update'); equal(classicCalls, 0, 'Canonical receive and rerender still use only native dirty selectors'); equal(native.editor.isEditedPostDirty(), false, 'Ignoring the Classic detector does not create native unsaved edits');
    native.setEdits({ content: 'Real unsaved native content.' }); equal(native.api.editorState().unsaved_changes, true, 'Actual native dirty content remains authoritative'); queue(native, action({ id: 'native-dirty-with-classic' })); await flushSync(native); equal(native.received.length, 1, 'Native dirty content still blocks saved reception'); equal(native.edits().content, 'Real unsaved native content.', 'Native dirty content is preserved exactly');
    native.setEdits({}); native.setNonPostDirty(true); equal(native.api.editorState().unsaved_changes, true, 'Linked dirty native entities remain protected even when the current content is clean'); await flushSync(native); equal(native.received.length, 1, 'Linked dirty native entities still defer saved reception'); noWrites(native, 'Native detector checks never perform editor writes');
  } finally { native.close(); }
  const classic = fixture(); let changed = true, fallbackCalls = 0;
  try {
    delete classic.editor.getCurrentPostId; delete classic.editor.getCurrentPostType;
    classic.w.wp.autosave = { server: { postChanged: () => { fallbackCalls++; return changed; } } };
    equal(classic.api.editorState().editor_active, true, 'The native post.php screen remains an editor when using Classic'); equal(classic.api.editorState().unsaved_changes, true, 'Classic autosave remains the fallback for a screen without an initialized native entity'); check(fallbackCalls > 0, 'The Classic fallback actually invokes the native postChanged detector');
    changed = false; equal(classic.api.editorState().unsaved_changes, false, 'A saved Classic editor becomes clean using its own detector'); queue(classic); await flushSync(classic); equal(classic.received.length, 0, 'Classic fallback does not imitate native block-store synchronization'); noWrites(classic, 'Classic detector checks perform no content writes');
  } finally { classic.close(); }
}

async function testInitialSessionSurvivesNativeURLRewrite() {
  for (const query of ['', '?post=99&action=edit&bhranti_session=18']) {
    const workspace = deferred(), expectedId = query ? 18 : 17;
    const restored = { ...session('complete', [action({ id: 'historical-on-native-open' })]), id: expectedId };
    const page = fixture({ settings: { initialSessionId: 17 }, handler: async (request) => {
      if (request.path.endsWith('/workspace')) return workspace.promise;
      if (request.path.endsWith('/sessions/' + expectedId)) return restored;
      throw new Error('Unexpected initial-session fixture request: ' + request.path);
    } });
    try {
      page.api.state.initialized = false; page.api.state.session = null; page.api.rememberSession(999);
      page.w.history.replaceState(null, '', '/wp-admin/post.php?post=99&action=edit&bhranti_session=17');
      const opening = page.api.openPanel(); await flush(); equal(page.requests.filter((request) => request.path.endsWith('/workspace')).length, 1, 'Opening the native editor waits for the workspace before loading its explicitly requested chat');
      page.w.history.replaceState(null, '', '/wp-admin/post.php' + (query || '?post=99&action=edit'));
      workspace.resolve({ sessions: [{ id: expectedId, title: 'Fresh offline chat' }], agents: [{ id: 'bhranti', name: 'Bhranti' }], ai: { available: true } }); await opening;
      equal(page.api.state.session.id, expectedId, query ? 'An explicit current query wins over the initial PHP fallback' : 'The PHP-captured initial chat opens after the native editor strips its query parameter');
      equal(page.requests.filter((request) => request.path.endsWith('/sessions/' + expectedId)).length, 1, 'Exactly the owned requested chat is read'); equal(page.requests.filter((request) => request.path.endsWith('/sessions/999')).length, 0, 'An older remembered chat cannot override an explicitly requested native-editor chat');
      equal(page.received.length, 0, 'Opening the chat seeds historical events without applying saved editor updates'); equal(entityReads(page).length, 0, 'Historical completed actions do not trigger canonical editor reads on opening'); equal(page.api.state.error, '', 'A rewritten native URL does not produce an empty or failed chat'); noWrites(page, 'Initial chat recovery never replays a submission or action');
    } finally { workspace.resolve({ sessions: [], ai: { available: true } }); page.close(); }
  }
}

async function testHeartbeatReadRacesAndRecovery() {
  const wait = deferred(); let dirty = false, reads = 0;
  const latest = record(99, 'page', 'Latest canonical record while the other browser remains dirty.');
  const racing = fixture({ background: true, handler: async (request) => {
    if (request.path.endsWith('/editor-state')) return { dirty, active: true, write_blocked: dirty, stale_dirty: dirty };
    if (request.path.startsWith('/wp/v2/pages/99')) return ++reads === 1 ? wait.promise : latest;
    throw new Error('Unexpected heartbeat race request.');
  } });
  try {
    await flush(); queue(racing); const syncing = flushSync(racing); await until(() => entityReads(racing).length === 1, racing);
    dirty = true; await racing.api.reportEditor(false, true); wait.resolve(record(99, 'page', 'Older saved record before the remote safety epoch changed.')); await syncing;
    equal(racing.received.length, 1, 'A changed remote safety epoch refetches and applies fresh saved data while the local editor remains clean');
    check(racing.received.every((args) => (Array.isArray(args[2]) ? args[2][0] : args[2]).content.raw === latest.content.raw), 'Late remote dirtiness still invalidates the older response before receiving the latest saved content');
    equal(entityReads(racing).length, 2, 'A changed remote epoch requires a fresh read without waiting for another browser to discard edits'); equal(racing.saved().content.raw, latest.content.raw, 'The clean local editor receives only the latest saved version'); equal(racing.api.editorSummary().dirty, true, 'The other browser stays dirty after local saved-data reception');
    noWrites(racing, 'Late remote heartbeat never repeats an action or core save');
  } finally { wait.resolve(record()); racing.close(); }
  let failHeartbeat = false;
  const recovering = fixture({ background: true, handler: async (request) => {
    if (request.path.endsWith('/editor-state')) { if (failHeartbeat) throw Object.assign(new Error('Temporary local lease read failure.'), { data: { status: 503 } }); return { dirty: false, active: true, write_blocked: true, stale_dirty: false }; }
    if (request.path.startsWith('/wp/v2/pages/99')) return record(99, 'page', 'Saved after lease safety recovered.');
    throw new Error('Unexpected lease recovery fixture request.');
  } });
  try {
    await flush(); failHeartbeat = true; await assert.rejects(recovering.api.reportEditor(false, true)); checks++; queue(recovering); await flushSync(recovering);
    equal(entityReads(recovering).length, 1, 'A failed lease request does not block the independent clean local saved GET'); equal(recovering.received.length, 1, 'Local saved data can be received without relaxing write protection after a lease failure');
    check(recovering.api.state.editorError, 'Lease failure remains an actionable status for site writes'); equal(recovering.api.editorSync.pending, null, 'The successful local saved update completes its owned checkpoint');
    failHeartbeat = false; await recovering.api.reportEditor(false, true); await recovering.advance(); await until(() => recovering.received.length === 1, recovering);
    equal(recovering.api.state.editorError, '', 'Authoritative successful heartbeat clears its safety status'); equal(recovering.received.length, 1, 'A recovered heartbeat does not repeat the already received committed update');
    equal(recovering.saved().content.raw, record(99, 'page', 'Saved after lease safety recovered.').content.raw, 'Safety recovery fetches the latest canonical native state'); noWrites(recovering, 'Heartbeat recovery never replays the action');
  } finally { recovering.close(); }
}

async function testActualActionLoopObservation() {
  const planning = deferred(), canonical = deferred(); let page;
  const prepared = session(); prepared.run.phase = 'prepared'; prepared.run.next_action = { tool: 'wp_request', method: 'POST', path: '/wp/v2/pages/99', summary: 'Update the fixture.', write: true, target: action().target };
  const written = session('running', [action()]); const completed = session('complete', [action()]);
  page = fixture({ session: prepared, handler: async (request) => {
    if (request.path.endsWith('/execute')) return written;
    if (request.path.endsWith('/step')) return planning.promise;
    if (request.path.startsWith('/wp/v2/pages/99')) return canonical.promise;
    throw new Error('Unexpected action-loop fixture request.');
  } });
  try {
    const task = page.api.continueRun(); await until(() => entityReads(page).length === 1, page);
    equal(page.requests.filter((request) => request.path.endsWith('/step')).length, 0, 'The next model request waits for the saved editor GET instead of occupying the only site worker');
    equal(page.received.length, 0, 'The pending canonical read has not fabricated a visible editor update'); equal(page.api.navigation.length, 0, 'Waiting for native saved state never reloads the page');
    canonical.resolve(record(99, 'page', 'Visible before the remaining model step.')); await until(() => page.received.length === 1 && page.requests.some((request) => request.path.endsWith('/step')), page);
    equal(page.api.state.session.run.status, 'running', 'Actual action-loop event synchronizes while the next model step remains pending'); equal(page.saved().content.raw, record(99, 'page', 'Visible before the remaining model step.').content.raw, 'Action loop shows the committed content without waiting for terminal completion');
    equal(page.requests.filter((request) => request.path.endsWith('/execute')).length, 1, 'The prepared action is executed exactly once');
    planning.resolve(completed); await task; await page.advance(); equal(page.received.length, 1, 'Terminal continuation does not receive the same committed action again'); equal(page.api.navigation.length, 0, 'The full action loop preserves the opened native editor'); equal(page.forbidden.length, 0, 'The action loop never dispatches editor edits, resets or saves');
  } finally { canonical.resolve(record()); planning.resolve(completed); page.close(); }
}

const controlOverlay = (page) => page.w.document.querySelector('.bhranti-editor-control');
function controlIsVisible(page) { const overlay = controlOverlay(page); return !!(overlay && !overlay.hidden && page.w.getComputedStyle(overlay).display !== 'none'); }
function assertControlled(page, label) {
  const editor = page.w.document.getElementById('editor'), overlay = controlOverlay(page);
  check(controlIsVisible(page), label + ' shows the editor ownership overlay'); check(editor.hasAttribute('inert'), label + ' makes only the native editor inert'); equal(editor.getAttribute('aria-busy'), 'true', label + ' marks the native editor busy');
  const heading = page.editor.getCurrentPostType() === 'page' ? 'Page is under Bhranti’s control' : 'Post is under Bhranti’s control';
  equal(overlay.getAttribute('role'), 'status', label + ' exposes nonmodal status'); equal(overlay.textContent, heading, label + ' shows only the exact ownership heading'); equal(overlay.children.length, 1, label + ' has no ownership card or extra content'); equal(overlay.firstElementChild.tagName, 'H2', label + ' centers a single semantic heading'); equal(overlay.querySelectorAll('svg,p,button,a,.bhranti-editor-control-card').length, 0, label + ' has no icon, body text, card or overlay control'); check(!editor.contains(overlay), label + ' keeps status outside the inert editor');
  check(!page.w.document.getElementById('bhranti-app').hasAttribute('inert'), label + ' keeps the sidebar interactive'); equal(page.w.document.querySelectorAll('.bhranti-editor-control').length, 1, label + ' has one owned overlay');
  const trigger = [...page.w.document.querySelectorAll('#wp-admin-bar-bhranti > a, [data-bhranti-editor-open]')].find(page.api.triggerAvailable); check(trigger && !trigger.closest('[inert]'), label + ' keeps a visibly accessible WordPress Bhranti trigger outside inert');
}
function assertReleased(page, label) {
  check(!controlIsVisible(page), label + ' removes the visible ownership overlay'); check(!page.w.document.getElementById('editor').hasAttribute('inert'), label + ' restores native editor interaction'); equal(page.w.document.getElementById('editor').getAttribute('aria-busy'), null, label + ' restores the original absent busy attribute');
}
async function testEditorControlLifecycle() {
  const page = fixture();
  try {
    assertReleased(page, 'Initial planning without a proven editing action');
    page.api.state.session.run.phase = 'prepared'; page.api.state.session.run.next_action = preparedEditorAction(); page.api.render(); assertControlled(page, 'Exact current-page prepared write');
    const input = page.w.document.getElementById('bhranti-composer'); input.focus(); input.value = 'Keep my unsent draft'; input.dispatchEvent(new page.w.Event('input', { bubbles: true }));
    equal(input.disabled, false, 'The composer stays editable while the native editor is controlled'); check([...page.w.document.querySelectorAll('#bhranti-app button')].some((button) => button.textContent === 'Stop' && !button.disabled), 'Stop stays usable outside the inert canvas');
    page.api.state.session.events.push(action()); page.api.state.session.run.phase = 'model'; delete page.api.state.session.run.next_action; page.api.render(); assertControlled(page, 'Subsequent planning after a proven current-page write');
    page.api.state.session.run.phase = 'prepared'; page.api.state.session.run.next_action = preparedEditorAction({ method: 'GET', write: false }); page.api.render(); assertControlled(page, 'Read step within the same proven editing chain');
    equal(page.w.document.getElementById('bhranti-composer').value, 'Keep my unsent draft', 'Ownership changes preserve the exact unsent sidebar draft');
    for (const [label, set, clear] of [
      ['approval', () => { page.api.state.session.run.status = 'awaiting_confirmation'; }, () => { page.api.state.session.run.status = 'running'; }],
      ['dirty pause', () => { page.api.state.pausedForEditor = true; }, () => { page.api.state.pausedForEditor = false; }],
      ['request error', () => { page.api.state.error = 'Offline fixture request failed.'; }, () => { page.api.state.error = ''; }],
      ['editor safety error', () => { page.api.state.editorError = 'Offline fixture editor safety failed.'; }, () => { page.api.state.editorError = ''; }],
      ['interrupted request', () => { page.api.state.session.run.interrupted = true; }, () => { page.api.state.session.run.interrupted = false; }],
      ['native save', () => { page.setSaving(true); }, () => { page.setSaving(false); }],
      ['native autosave', () => { page.setSaving(true, true); }, () => { page.setSaving(false); }],
      ['local edits', () => { page.setEdits({ content: 'Unsaved human content.' }); }, () => { equal(page.edits().content, 'Unsaved human content.', 'Releasing control preserves exact local edits'); page.setEdits({}); }],
      ['linked native edits', () => { page.setNonPostDirty(true); }, () => { page.setNonPostDirty(false); }],
    ]) { set(); page.api.render(); assertReleased(page, label); clear(); page.api.render(); assertControlled(page, 'Clean recovery from ' + label); }
    for (const status of ['complete', 'failed', 'cancelled']) { page.api.state.session.run.status = status; page.api.render(); assertReleased(page, 'Terminal ' + status); page.api.state.session.run.status = 'running'; page.api.render(); assertControlled(page, 'Restored active fixture after ' + status); }
    page.api.closePanel(); assertReleased(page, 'Closing the sidebar during a queued read'); equal(page.api.state.draft, 'Keep my unsent draft', 'Closing releases the editor without deleting a draft');
    page.api.state.open = true; page.api.state.session.run.phase = 'action'; page.api.render(); assertControlled(page, 'Current native action in flight');
    for (const field of ['error', 'editorError']) {
      const bound = controlOverlay(page); page.api.state[field] = 'Transient fixture response uncertainty.'; page.api.render(); assertControlled(page, 'Bound in-flight action despite ' + field); equal(controlOverlay(page), bound, 'In-flight ' + field + ' keeps the same owned lock until server handover');
      page.api.state.session.run.phase = 'model'; page.api.render(); assertReleased(page, field + ' after durable action handover'); page.api.state[field] = ''; page.api.state.session.run.phase = 'action'; page.api.render(); assertControlled(page, 'Recovered in-flight action after ' + field);
    }
    page.api.state.session.run.interrupted = true; page.api.render(); assertReleased(page, 'Known interrupted native action'); page.api.state.session.run.interrupted = false; page.api.render(); assertControlled(page, 'Active known action after fixture interruption is cleared');
    page.api.closePanel(); assertControlled(page, 'Closing while the native action remains in flight');
    page.api.state.busy = true; page.w.document.querySelector('#wp-admin-bar-bhranti > a').click(); await flush(); equal(page.api.state.open, true, 'The WordPress admin-bar trigger reopens Bhranti during the controlled in-flight action'); equal(page.api.state.draft, 'Keep my unsent draft', 'Reopening from the native WordPress trigger preserves the chat draft'); page.api.state.busy = false; page.api.closePanel();
    page.api.state.session.run.phase = 'model'; page.api.render(); assertReleased(page, 'A closed sidebar after the in-flight action returns');
    page.api.state.open = true; page.api.render(); assertControlled(page, 'Reopening the proven active editing chain');
    page.w.dispatchEvent(new page.w.PageTransitionEvent('pagehide')); assertReleased(page, 'Pagehide');
    page.api.state.busy = true; page.w.dispatchEvent(new page.w.PageTransitionEvent('pageshow', { persisted: true })); await flush(); assertControlled(page, 'BFCache return to the same active editing chain'); page.api.state.busy = false;
    page.w.dispatchEvent(new page.w.PageTransitionEvent('pagehide')); page.api.state.session.run.status = 'complete'; page.api.state.busy = true; page.w.dispatchEvent(new page.w.PageTransitionEvent('pageshow', { persisted: true })); await flush(); assertReleased(page, 'BFCache return after the task completed'); page.api.state.busy = false;
    equal(page.received.length, 0, 'Overlay lifecycle itself never receives or fabricates native saved data'); equal(page.api.navigation.length, 0, 'Overlay lifecycle never navigates or reloads'); noWrites(page, 'Overlay lifecycle performs no core or agent mutation');
  } finally { page.close(); }
}

async function testEditorControlScopeAndRestoration() {
  const invalid = [
    preparedEditorAction({ write: false }), preparedEditorAction({ tool: 'generate_image' }), preparedEditorAction({ tool: 'plugin_request' }),
    preparedEditorAction({ path: '/wp/v2/pages/100' }), preparedEditorAction({ path: '/wp/v2/pages/99/revisions' }), preparedEditorAction({ path: '/wp/v2/posts/99' }), preparedEditorAction({ path: '/wp/v2/pages' }),
    preparedEditorAction({ target: { ...action().target, id: 100 } }), preparedEditorAction({ target: { ...action().target, type: 'post' } }),
    preparedEditorAction({ target: { ...action().target, url: '/wp-admin/post.php?post=99&action=delete' } }),
  ];
  for (let index = 0; index < invalid.length; index++) {
    const current = session(); current.run.phase = 'prepared'; current.run.next_action = invalid[index]; const page = fixture({ session: current });
    try { assertReleased(page, 'Nonmatching/read/image/plugin plan ' + index); noWrites(page, 'Nonmatching plan never edits native state'); } finally { page.close(); }
  }
  for (const override of [{ success: false }, { operation: 'image' }, { run_id: 'historical-run' }, { target: { ...action().target, id: 100 } }, { target: { ...action().target, type: 'post' } }]) {
    const page = fixture({ session: session('running', [action(override)]) }); try { assertReleased(page, 'Unproven or unrelated historical action'); noWrites(page, 'Historical proof never mutates the editor'); } finally { page.close(); }
  }
  const post = fixture();
  try {
    post.switchPost(99, 'post'); post.api.state.session.run.phase = 'prepared'; post.api.state.session.run.next_action = preparedEditorAction({ path: '/wp/v2/posts/99', target: { ...action().target, type: 'post' } }); post.api.render(); assertControlled(post, 'Exact native post target'); equal(controlOverlay(post).textContent, 'Post is under Bhranti’s control', 'Native post ownership label matches the actual entity type');
    post.api.state.session.run.phase = 'model'; delete post.api.state.session.run.next_action; post.api.state.session.events = [action({ operation: 'rollback', target: { ...action().target, type: 'post' } })]; post.api.render(); assertControlled(post, 'Successful current-post rollback proof'); noWrites(post, 'Post and rollback ownership never alter core records');
  } finally { post.close(); }
  const page = fixture();
  try {
    const editor = page.w.document.getElementById('editor'); editor.setAttribute('inert', 'existing-owner'); editor.setAttribute('aria-busy', 'false'); editor.classList.add('existing-editor-class'); editor.style.color = 'rgb(1, 2, 3)';
    page.api.state.session.run.phase = 'prepared'; page.api.state.session.run.next_action = preparedEditorAction(); page.api.render(); assertControlled(page, 'Prepared action on an already inert native wrapper');
    page.api.state.session.run.status = 'complete'; page.api.render(); check(!controlIsVisible(page), 'Completion removes Bhranti ownership from an already inert wrapper'); equal(editor.getAttribute('inert'), 'existing-owner', 'Release restores the exact previous inert attribute value'); equal(editor.getAttribute('aria-busy'), 'false', 'Release restores the exact previous aria-busy value'); check(editor.classList.contains('existing-editor-class'), 'Release preserves existing native classes'); equal(editor.style.color, 'rgb(1, 2, 3)', 'Release preserves existing native inline styling'); page.api.syncEditorControl(); equal(editor.getAttribute('inert'), 'existing-owner', 'Repeated release does not disturb another existing owner');
    editor.removeAttribute('inert'); editor.removeAttribute('aria-busy'); page.api.state.session.run.status = 'running'; page.api.render(); assertControlled(page, 'Ownership reacquired on the original current page');
    page.switchPost(100); page.api.render(); assertReleased(page, 'Native post switch'); page.switchPost(99); page.api.render(); assertControlled(page, 'Return to the exact proven entity');
    page.api.state.session = { ...session(), id: 18 }; page.api.render(); assertReleased(page, 'Switch to an unrelated chat'); noWrites(page, 'Identity and restoration checks never mutate native content');
  } finally { page.close(); }
  for (const className of ['components-modal__screen-overlay', 'media-modal']) {
    const dialog = fixture();
    try {
      dialog.api.state.session.run.phase = 'prepared'; dialog.api.state.session.run.next_action = preparedEditorAction(); dialog.api.render(); assertControlled(dialog, 'Before native ' + className);
      const modal = dialog.w.document.createElement('div'); modal.className = className; modal.getClientRects = () => [{ width: 200, height: 100 }]; dialog.w.document.body.appendChild(modal); dialog.api.syncEditorControl(); assertReleased(dialog, 'Visible native ' + className);
      modal.hidden = true; dialog.api.syncEditorControl(); assertControlled(dialog, 'Hidden native ' + className); modal.remove(); dialog.api.state.session.run.status = 'complete'; dialog.api.render(); assertReleased(dialog, 'Completion after native modal'); noWrites(dialog, 'Native modal ownership deferral makes no core writes');
    } finally { dialog.close(); }
  }
}

async function testEditorControlSavedReceiveAndKeyboard() {
  const page = fixture();
  try {
    const stylesheet = page.w.document.createElement('style'); stylesheet.textContent = fs.readFileSync(path.join(process.env.BHRANTI_TEST_PLUGIN_ROOT || path.dirname(__dirname), 'assets/admin.css'), 'utf8'); page.w.document.head.appendChild(stylesheet);
    const editor = page.w.document.getElementById('editor'), frame = page.w.document.createElement('iframe'); frame.name = 'editor-canvas'; editor.querySelector('.interface-interface-skeleton').appendChild(frame); frame.focus();
    equal(page.api.nativeEditorSnapshot().editing, true, 'The native canvas iframe initially owns focus');
    page.api.state.session.run.phase = 'prepared'; page.api.state.session.run.next_action = preparedEditorAction(); page.api.render(); assertControlled(page, 'Clean focused native canvas acquisition');
    const overlayStyle = page.w.getComputedStyle(controlOverlay(page)), background = overlayStyle.backgroundColor.match(/^rgba\(30,\s*30,\s*30,\s*([\d.]+)\)$/);
    check(background && Number(background[1]) > 0 && Number(background[1]) < 1, 'Actual overlay CSS uses translucent WordPress #1e1e1e'); equal(overlayStyle.display, 'grid', 'Actual overlay CSS establishes its heading alignment area'); equal(overlayStyle.placeItems, 'center', 'Actual overlay CSS centers the single heading'); equal(page.w.getComputedStyle(controlOverlay(page).querySelector('h2')).textAlign, 'center', 'Actual heading CSS centers its text without a card');
    equal(controlOverlay(page).style.top, '48px', 'Ownership overlay uses the actual native skeleton top'); equal(controlOverlay(page).style.left, '32px', 'Ownership overlay uses the actual native skeleton left'); equal(controlOverlay(page).style.width, '1000px', 'Ownership overlay covers the native editor width'); equal(controlOverlay(page).style.height, '700px', 'Ownership overlay covers the native editor height');
    let launcherPositions = 0; page.w.document.addEventListener('bhranti:editor-launcher-position', () => launcherPositions++);
    page.w.document.querySelector('.interface-interface-skeleton').getBoundingClientRect = () => ({ x: 64, y: 72, left: 64, top: 72, right: 964, bottom: 672, width: 900, height: 600 }); page.api.positionEditorControl();
    equal(launcherPositions, 1, 'A native editor geometry update notifies the portalled toolbar opener once without viewport events'); equal(controlOverlay(page).style.left, '64px', 'Sidebar-driven editor movement refreshes overlay horizontal position'); equal(controlOverlay(page).style.top, '72px', 'Sidebar-driven editor movement refreshes overlay vertical position'); equal(controlOverlay(page).style.width, '900px', 'Sidebar-driven editor movement refreshes the exact native overlay width'); equal(controlOverlay(page).style.height, '600px', 'Sidebar-driven editor movement refreshes the exact native overlay height');
    equal(page.api.nativeEditorSnapshot().editing, false, 'Focus on Bhranti ownership status does not falsely look like native typing'); check(controlOverlay(page).contains(page.w.document.activeElement) || page.w.document.getElementById('bhranti-app').contains(page.w.document.activeElement), 'Acquisition moves clean canvas focus safely outside inert');
    page.setEdits({ blocks: [{ name: 'core/paragraph', clientId: 'clean-before', attributes: { content: 'Saved before Bhranti.' } }], selection: { start: { clientId: 'clean-before' } } });
    queue(page); await flushSync(page); equal(page.received.length, 1, 'Bhranti own overlay does not block a matching canonical saved receive'); equal(entityReads(page).length, 1, 'The locked editor fetches the canonical saved entity exactly once'); equal(Object.keys(page.edits()).length, 0, 'The native receive clears only unchanged clean transient edits while controlled'); equal(page.editor.isEditedPostDirty(), false, 'The controlled saved update does not create an unsaved change'); assertControlled(page, 'Editing chain after its committed saved update');
    const nativeUndo = new page.w.KeyboardEvent('keydown', { key: 'z', ctrlKey: true, bubbles: true, cancelable: true }); controlOverlay(page).dispatchEvent(nativeUndo); equal(nativeUndo.defaultPrevented, true, 'Native undo is blocked while Bhranti controls this editor');
    const nativeSave = new page.w.KeyboardEvent('keydown', { key: 's', metaKey: true, bubbles: true, cancelable: true }); controlOverlay(page).dispatchEvent(nativeSave); equal(nativeSave.defaultPrevented, true, 'Native save shortcut is blocked while Bhranti controls this editor');
    const input = page.w.document.getElementById('bhranti-composer'); input.focus(); input.value = 'Editable sidebar draft'; input.dispatchEvent(new page.w.Event('input', { bubbles: true }));
    let escapedUndo = 0; const observeUndo = () => { escapedUndo++; }; page.w.document.addEventListener('keydown', observeUndo);
    const composerUndo = new page.w.KeyboardEvent('keydown', { key: 'z', ctrlKey: true, bubbles: true, cancelable: true }); input.dispatchEvent(composerUndo); equal(composerUndo.defaultPrevented, false, 'Browser undo remains available inside the sidebar composer'); equal(escapedUndo, 0, 'Sidebar undo does not reach native editor shortcut listeners'); page.w.document.removeEventListener('keydown', observeUndo);
    equal(input.value, 'Editable sidebar draft', 'Shortcut handling never changes the sidebar draft');
    page.api.state.session.run.status = 'complete'; page.api.render(); assertReleased(page, 'Completion after saved receive');
    const releasedSave = new page.w.KeyboardEvent('keydown', { key: 's', ctrlKey: true, bubbles: true, cancelable: true }); editor.dispatchEvent(releasedSave); equal(releasedSave.defaultPrevented, false, 'Native editor save shortcuts are restored after completion'); equal(page.api.navigation.length, 0, 'Editor control and saved updates do not navigate'); noWrites(page, 'Grey-overlay saved update and shortcuts never call native edits/reset/save or replay mutations');
  } finally { page.close(); }
}

async function testEditorControlStartsWithCurrentFetch() {
  for (const tool of ['wp_request', 'open_item']) {
    const page = fixture();
    try {
      page.api.state.session.run.phase = 'prepared'; page.api.state.session.run.next_action = preparedEditorAction({ tool, method: 'GET', write: false }); page.api.render(); assertControlled(page, 'Prepared exact-current fetch through ' + tool);
      equal(entityReads(page).length, 0, 'Prepared ' + tool + ' ownership alone does not fetch canonical canvas data'); equal(page.received.length, 0, 'Prepared ' + tool + ' read never changes saved entity state');
      page.api.state.session.run.phase = 'action'; page.api.render(); assertControlled(page, 'In-flight current fetch through ' + tool);
      queue(page, action({ id: tool + '-read-proof', operation: 'read' })); delete page.api.state.session.run.next_action; page.api.state.session.run.phase = 'model'; page.api.render(); await flushSync(page); assertControlled(page, 'Same-run successful fetch retained during planning through ' + tool);
      const original = page.saved(); page.api.state.session.run.phase = 'prepared'; page.api.state.session.run.next_action = preparedEditorAction({ tool: 'generate_image', method: 'GET', path: '', write: true, target: undefined }); page.api.render(); assertControlled(page, 'Image preparation after the same-run current fetch');
      queue(page, action({ id: tool + '-image-result', operation: 'image', target: { id: 123, type: 'attachment', url: '/wp-admin/post.php?post=123&action=edit' } })); page.api.state.session.run.phase = 'model'; delete page.api.state.session.run.next_action; page.api.render(); await flushSync(page); assertControlled(page, 'Planning after generated media retains the original current fetch proof');
      equal(entityReads(page).length, 0, 'Read and image events do not trigger a canonical saved editor GET'); equal(page.received.length, 0, 'Read and image events never dispatch native saved entity changes'); assert.deepEqual(page.saved(), original); checks++; equal(page.api.navigation.length, 0, 'Fetching current content does not navigate or refresh'); noWrites(page, 'Fetch ownership does not replay tools or make native writes');
      for (const status of ['awaiting_confirmation', 'complete', 'failed', 'cancelled']) { page.api.state.session.run.status = status; page.api.render(); assertReleased(page, 'Fetch task handover ' + status); page.api.state.session.run.status = 'running'; page.api.render(); assertControlled(page, 'Continued owned fixture after fetch status ' + status); }
      page.api.state.pausedForEditor = true; page.api.render(); assertReleased(page, 'Explicit pause after a current fetch'); page.api.state.pausedForEditor = false; page.api.render(); assertControlled(page, 'Resume after a current fetch pause');
      page.setEdits({ content: 'Human edit after current content was fetched.' }); page.api.render(); assertReleased(page, 'Local edits after a current fetch'); equal(page.edits().content, 'Human edit after current content was fetched.', 'Fetch ownership releases without altering newly dirty native content'); page.setEdits({}); page.api.render(); assertControlled(page, 'Clean native state after the fetch pause');
      page.api.closePanel(); assertReleased(page, 'Closed panel after current fetch handover'); equal(entityReads(page).length, 0, 'Fetch-only lifecycle creates no canonical editor requests'); equal(page.received.length, 0, 'Fetch-only lifecycle never changes native saved data');
    } finally { page.close(); }
  }
  const post = fixture();
  try {
    post.switchPost(99, 'post'); const target = { ...action().target, type: 'post' }; post.api.state.session.run.phase = 'prepared'; post.api.state.session.run.next_action = preparedEditorAction({ method: 'GET', write: false, path: '/wp/v2/posts/99', target }); post.api.render(); assertControlled(post, 'Prepared exact-current post fetch');
    queue(post, action({ id: 'post-read-proof', operation: 'read', target })); post.api.state.session.run.phase = 'model'; delete post.api.state.session.run.next_action; post.api.render(); await flushSync(post); assertControlled(post, 'Committed current-post read retained during planning'); equal(entityReads(post).length, 0, 'Fetching the current post does not queue canonical editor reads'); equal(post.received.length, 0, 'Fetching the current post does not update saved entity data'); noWrites(post, 'Current-post fetch ownership is UI only');
  } finally { post.close(); }
  const unrelatedReads = [
    preparedEditorAction({ method: 'GET', write: false, path: '/wp/v2/pages' }), preparedEditorAction({ method: 'GET', write: false, path: '/wp/v2/pages/100' }),
    preparedEditorAction({ method: 'GET', write: false, path: '/wp/v2/posts/99' }), preparedEditorAction({ method: 'GET', write: false, path: '/wp/v2/media/99', target: { ...action().target, type: 'attachment' } }),
    preparedEditorAction({ method: 'GET', write: false, path: '/wp/v2/search', tool: 'site_search' }), preparedEditorAction({ method: 'GET', write: false, path: '/wp/v2/plugins', tool: 'plugin_request' }),
    preparedEditorAction({ method: 'GET', write: false, path: '/wp/v2/pages/99/revisions' }), preparedEditorAction({ method: 'GET', write: false, target: { ...action().target, url: 'https://external.invalid/editor' } }),
    preparedEditorAction({ method: 'GET', write: false, tool: 'open_item', path: '/wp/v2/media/99' }),
  ];
  for (let index = 0; index < unrelatedReads.length; index++) {
    const current = session(); current.run.phase = 'prepared'; current.run.next_action = unrelatedReads[index]; const page = fixture({ session: current });
    try { assertReleased(page, 'Unrelated list/search/media fetch ' + index); equal(entityReads(page).length, 0, 'Unrelated fetch ' + index + ' never reads canonical editor state'); equal(page.received.length, 0, 'Unrelated fetch ' + index + ' never receives native data'); noWrites(page, 'Unrelated fetch ownership makes no tool calls'); } finally { page.close(); }
  }
  for (const override of [{ success: false }, { run_id: 'another-run' }, { target: { ...action().target, id: 100 } }, { target: { ...action().target, type: 'attachment' } }, { target: { id: 0, type: 'collection', url: '' } }, { target: { ...action().target, url: 'https://external.invalid/post' } }]) {
    const page = fixture({ session: session('running', [action({ operation: 'read', ...override })]) });
    try { assertReleased(page, 'Unproven or unrelated committed fetch'); equal(page.received.length, 0, 'Foreign or failed committed read does not receive native saved data'); noWrites(page, 'Unrelated committed fetch never makes tool calls'); } finally { page.close(); }
  }
}

// The separate editor-launcher suite mounts the real React component and its
// portal. Here a native-shaped mounted button exercises the actual admin bridge.
function mountedEditorOpener(page, outsideEditor = false) {
  const button = page.w.document.createElement('button'); button.type = 'button'; button.className = 'components-button bhranti-editor-launcher'; button.setAttribute('data-bhranti-editor-open', ''); button.setAttribute('aria-label', 'Bhranti');
  button.addEventListener('click', (event) => page.w.document.dispatchEvent(new page.w.CustomEvent('bhranti:editor-launcher-toggle', { detail: { trigger: event.currentTarget } })));
  const label = page.w.document.createElement('span'); label.className = 'bhranti-editor-launcher-label'; label.textContent = 'Bhranti'; button.appendChild(label);
  const bounds = () => ({ x: 80, y: 48, left: 80, top: 48, right: 178, bottom: 80, width: 98, height: 32 });
  button.getBoundingClientRect = bounds; button.getClientRects = () => button.hidden || page.w.getComputedStyle(button).display === 'none' ? [] : [bounds()];
  let parent = page.w.document.querySelector('.editor-header__settings');
  if (outsideEditor) { parent = page.w.document.createElement('div'); parent.className = 'bhranti-editor-launcher-floating'; page.w.document.body.appendChild(parent); }
  parent.appendChild(button); page.w.document.dispatchEvent(new page.w.CustomEvent('bhranti:editor-launcher-ready')); return button;
}
function assertOpener(page, opener, open, label) {
  equal(opener.getAttribute('aria-controls'), 'bhranti-app', label + ' controls only the Bhranti panel'); equal(opener.getAttribute('aria-expanded'), String(open), label + ' reports the panel open state'); equal(opener.getAttribute('aria-haspopup'), 'dialog', label + ' identifies the dialog popup');
  if (opener.hasAttribute('data-bhranti-editor-open')) { equal(opener.type, 'button', label + ' cannot submit a native editor form'); equal(opener.getAttribute('aria-pressed'), String(open), label + ' reports the pressed state'); check(!opener.hasAttribute('data-bhranti-open'), label + ' has only its native custom toggle path'); }
  check(page.api.triggerAvailable(opener), label + ' is connected, visible, enabled and outside inert');
}
async function testEditorOpenerBridgeAndVisibility() {
  for (const kind of ['visible', 'absent', 'hidden', 'display-none', 'visibility-hidden', 'fullscreen']) {
    const page = fixture({ session: session('complete'), adminBar: ['visible', 'fullscreen'].includes(kind) ? undefined : kind, fullscreen: kind === 'fullscreen' });
    try {
      const native = page.w.document.querySelector('#wp-admin-bar-bhranti > a'), hasBar = kind === 'visible', fallback = hasBar ? null : mountedEditorOpener(page), opener = fallback || native;
      equal(page.api.triggerAvailable(native), hasBar, kind + ' native admin-bar availability reflects actual visibility'); equal(page.w.document.getElementById('bhranti-app').style.top, hasBar ? '32px' : '0px', kind + ' sidebar starts below only the actual visible native bar'); assertOpener(page, opener, true, kind + ' initial opener');
      const changes = []; page.w.document.addEventListener('bhranti:panel-state', (event) => changes.push(clone(event.detail)));
      page.api.syncTrigger(); page.api.syncTrigger(); page.api.render(); equal(changes.length, 0, kind + ' unchanged renders do not dispatch repeated component-state notifications');
      page.api.closePanel(); assertOpener(page, opener, false, kind + ' closed opener'); equal(page.w.document.activeElement, opener, kind + ' close returns focus to the accessible opener'); equal(changes.length, 1, kind + ' close publishes one state transition'); equal(changes[0].open, false, kind + ' close state is authoritative'); equal(changes[0].adminBarAvailable, hasBar, kind + ' component learns native bar availability'); equal(changes[0].controlled, false, kind + ' completed conversation does not claim editor control');
      page.api.state.busy = true; const target = fallback ? fallback.querySelector('span') : opener; target.dispatchEvent(new page.w.MouseEvent('click', { bubbles: true, cancelable: true })); await flush(); assertOpener(page, opener, true, kind + ' reopened opener'); equal(page.api.state.open, true, kind + ' nested opener click opens the panel once'); equal(changes.length, 2, kind + ' reopening publishes one new transition'); equal(page.w.document.activeElement.id, 'bhranti-close', kind + ' busy opening focuses the enabled close control inside Bhranti'); page.api.state.busy = false;
      page.api.state.busy = true; opener.click(); await flush(); equal(page.api.state.open, false, kind + ' second opener click closes without creating another conversation'); assertOpener(page, opener, false, kind + ' toggled closed opener'); page.api.state.busy = false;
      equal(page.requests.length, 0, kind + ' bridge state and explicit busy toggling issue no workspace, model or action requests'); equal(page.received.length, 0, kind + ' opener does not fabricate saved native content'); equal(page.api.navigation.length, 0, kind + ' opener does not navigate'); noWrites(page, kind + ' opener cannot replay a submitted action');
    } finally { page.close(); }
  }
}
async function testEditorOpenerFocusAndPressedLifetime() {
  for (const kind of ['removed', 'hidden', 'disabled', 'inert', 'aria-hidden']) {
    const page = fixture({ session: session('complete'), adminBar: 'absent' });
    try {
      const old = mountedEditorOpener(page); page.api.state.busy = true; page.api.closePanel(); old.click(); await flush(); equal(page.api.state.open, true, kind + ' case starts with the original mounted opener');
      if (kind === 'removed') old.remove(); if (kind === 'hidden') old.hidden = true; if (kind === 'disabled') old.disabled = true; if (kind === 'inert') old.setAttribute('inert', ''); if (kind === 'aria-hidden') old.setAttribute('aria-hidden', 'true');
      const replacement = mountedEditorOpener(page); page.api.closePanel(); equal(page.w.document.activeElement, replacement, kind + ' close focuses the replacement instead of a stale or inaccessible lastTrigger'); assertOpener(page, replacement, false, kind + ' replacement state is reapplied by launcher-ready'); equal(page.requests.length, 0, kind + ' focus restoration sends no requests'); noWrites(page, kind + ' focus restoration never edits the native record');
    } finally { page.close(); }
  }
  const page = fixture({ session: session('complete'), adminBar: 'hidden' });
  try {
    const opener = mountedEditorOpener(page), input = page.w.document.getElementById('bhranti-composer'); input.value = 'Exact unsent toolbar draft'; input.dispatchEvent(new page.w.Event('input', { bubbles: true })); page.api.rememberDraft(); page.api.closePanel();
    for (const inputType of ['pointerdown', 'keydown']) {
      page.api.state.busy = true; const pressed = page.w.document.querySelector('[data-bhranti-editor-open]');
      pressed.dispatchEvent(inputType === 'keydown' ? new page.w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }) : new page.w.Event('pointerdown', { bubbles: true })); page.api.render(); page.api.nativeEditorChanged(); page.api.syncTrigger(); equal(page.w.document.querySelector('[data-bhranti-editor-open]'), pressed, inputType + ' opener survives a native/sidebar update before activation');
      pressed.click(); await flush(); equal(page.api.state.open, true, inputType + ' activation opens exactly once after the update'); equal(page.api.state.draft, 'Exact unsent toolbar draft', inputType + ' activation preserves the exact per-chat draft'); page.api.closePanel(); equal(page.w.document.activeElement, opener, inputType + ' close returns focus to the same connected visible opener'); await page.advance(); page.api.state.busy = false;
    }
    const bar = page.w.document.getElementById('wpadminbar'); bar.hidden = false; opener.hidden = true; page.api.syncTrigger(); const native = page.w.document.querySelector('#wp-admin-bar-bhranti > a'); page.api.closePanel(); equal(page.w.document.activeElement, native, 'Leaving fullscreen returns focus to the visible native admin-bar opener instead of a hidden toolbar fallback'); equal(page.w.document.getElementById('bhranti-app').style.top, '32px', 'Restored native admin bar updates the panel top'); equal(page.requests.length, 0, 'Pointer/keyboard status updates never fetch or mutate'); noWrites(page, 'Toolbar interaction cannot replay a POST');
  } finally { page.close(); }
  const portal = fixture({ session: session('complete'), adminBar: 'absent' });
  try {
    const old = mountedEditorOpener(portal, true); portal.api.state.busy = true; portal.api.closePanel(); old.click(); await flush(); portal.api.closePanel(); equal(portal.w.document.activeElement, old, 'Closing initially returns focus to the connected floating opener');
    old.remove(); const replacement = mountedEditorOpener(portal); equal(portal.w.document.activeElement, portal.w.document.body, 'Native React portal-parent replacement temporarily leaves focus on the body'); await portal.advance(16); equal(portal.w.document.activeElement, replacement, 'The next animation frame restores focus to the newly mounted accessible toolbar opener');
    portal.api.closePanel(); const native = portal.w.document.getElementById('native-toolbar-control'); native.focus(); await portal.advance(16); equal(portal.w.document.activeElement, native, 'Portal focus recovery never steals an explicit subsequent native toolbar focus'); equal(portal.requests.length, 0, 'Portal focus repair performs no workspace/model/action requests'); noWrites(portal, 'Portal parent changes never reset or save the native editor');
  } finally { portal.close(); }
}
async function testEditorOpenerDuringOwnedAction() {
  for (const kind of ['visible', 'absent', 'hidden', 'fullscreen']) {
    const page = fixture({ adminBar: kind === 'fullscreen' ? undefined : kind, fullscreen: kind === 'fullscreen' });
    try {
      const opener = mountedEditorOpener(page, true), id = page.api.state.session.id, uuid = page.api.state.session.run.id, changes = [];
      page.w.document.addEventListener('bhranti:panel-state', (event) => changes.push(clone(event.detail))); page.api.state.draft = 'Keep this exact unsent draft while controlled'; page.api.rememberDraft(); page.api.render();
      page.api.state.session.run.phase = 'prepared'; page.api.state.session.run.next_action = preparedEditorAction({ method: 'GET', write: false }); page.api.render(); assertControlled(page, kind + ' exact current fetch'); check(changes.some((detail) => detail.controlled === true && detail.adminBarAvailable === (kind === 'visible')), kind + ' component gets the owned control state so it can portal outside inert even with a visible native bar');
      page.api.state.session.run.phase = 'action'; const overlay = controlOverlay(page); page.api.closePanel(); assertControlled(page, kind + ' closed in-flight action'); assertOpener(page, opener, false, kind + ' outside-inert action opener'); equal(controlOverlay(page), overlay, kind + ' closing retains the same in-flight native protection');
      page.api.state.busy = true; opener.click(); await flush(); equal(page.api.state.open, true, kind + ' outside-inert opener reopens during native action'); equal(page.api.state.session.id, id, kind + ' reopening preserves the exact conversation'); equal(page.api.state.session.run.id, uuid, kind + ' reopening preserves the exact in-flight run'); equal(page.api.state.draft, 'Keep this exact unsent draft while controlled', kind + ' reopening preserves the exact unsent draft'); equal(page.w.document.getElementById('bhranti-composer').value, page.api.state.draft, kind + ' reopened composer shows the preserved draft'); assertOpener(page, opener, true, kind + ' active reopened opener'); assertControlled(page, kind + ' reopened in-flight protection');
      const native = page.w.document.getElementById('native-toolbar-control'); check(native.closest('[inert]'), kind + ' native toolbar remains blocked while only the Bhranti opener escapes'); equal(page.w.document.querySelectorAll('[data-bhranti-editor-open]').length, 1, kind + ' only one actionable fallback exists');
      page.api.state.session.run.phase = 'model'; page.api.closePanel(); assertReleased(page, kind + ' server action handover while sidebar is closed'); check(changes.some((detail) => detail.controlled === false), kind + ' handover releases the component portal state'); equal(page.requests.length, 0, kind + ' reopening in-flight never sends a model, workspace or action request'); equal(page.received.length, 0, kind + ' read-only control never receives saved data'); equal(page.api.navigation.length, 0, kind + ' toolbar action opener never navigates'); noWrites(page, kind + ' controlled fallback cannot submit or replay a mutation');
    } finally { page.close(); }
  }
}

async function main() {
  for (const test of [testSavedStateBeforeCompletion, testEventEligibilityAndHistory, testLocalDeferral, testNativeBlockGestures, testInFlightRaces, testNewerActionWins, testFollowupRunAndRemoteSafetyEpoch, testBoundedFailures, testFreshCrossTabSafety, testNativeAndClassicDirtyDetectors, testInitialSessionSurvivesNativeURLRewrite, testHeartbeatReadRacesAndRecovery, testActualActionLoopObservation, testEditorControlLifecycle, testEditorControlScopeAndRestoration, testEditorControlSavedReceiveAndKeyboard, testEditorControlStartsWithCurrentFetch, testEditorOpenerBridgeAndVisibility, testEditorOpenerFocusAndPressedLifetime, testEditorOpenerDuringOwnedAction]) {
    currentCase = test.name; if (process.env.BHRANTI_TEST_EDITOR_TRACE === '1') console.log('CHECK ' + currentCase); await test();
  }
  console.log('PASS ' + checks + ' isolated native editor synchronization checks; no HTTP, AI, WordPress load or DB access');
}
const watchdog = setTimeout(() => { console.error('FAIL: editor-sync fixture did not complete ' + currentCase + '.'); process.exitCode = 1; }, 10000);
main().catch((error) => { console.error(error); process.exitCode = 1; }).finally(() => clearTimeout(watchdog));
