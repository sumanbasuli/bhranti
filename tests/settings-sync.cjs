/** Real Settings React/state synchronization. No HTTP, AI, WordPress load or DB. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
let JSDOM, VirtualConsole;
try { ({ JSDOM, VirtualConsole } = require('jsdom')); }
catch { ({ JSDOM, VirtualConsole } = require(process.env.BHRANTI_TEST_JSDOM_PATH || '/Users/txsadhu/node_modules/jsdom')); }
const pluginRoot = process.env.BHRANTI_TEST_PLUGIN_ROOT || path.dirname(__dirname);
const wpRoot = process.env.BHRANTI_TEST_WP_ROOT || '/Users/txsadhu/Documents/WordPress/Plugins';
const source = fs.readFileSync(path.join(pluginRoot, 'assets/settings.js'), 'utf8');
const bundles = ['vendor/react.js', 'vendor/react-dom.js', 'escape-html.js', 'element.js'].map((name) => ({ name, text: fs.readFileSync(path.join(wpRoot, 'wp-includes/js/dist', name), 'utf8') }));
const permissionKeys = ['edit_content', 'publish_content', 'trash_content', 'manage_comments', 'manage_media', 'manage_navigation', 'manage_design', 'manage_site_settings', 'plugins_install', 'plugins_activate', 'plugins_delete'];
const clone = (value) => JSON.parse(JSON.stringify(value));
const initial = () => ({ config: { max_steps: 128, system_prompt: '', guardrails: '', permissions: Object.fromEntries(permissionKeys.map((key) => [key, key !== 'plugins_delete'])), agents: [{ id: 'bhranti', name: 'Bhranti', agent_md: '', skills: [] }] }, revision: 'initial' });
const initialMemory = () => ({ available: true, revision: 'memory-initial', preferences: [], capabilities: [], message: '' });
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const pause = () => new Promise((resolve) => setTimeout(resolve, 12));
let checks = 0, httpCalls = 0, currentCase = 'initialization';
function check(value, label) { assert.ok(value, label); checks++; }
function equal(actual, expected, label) { assert.equal(actual, expected, label); checks++; }
function deep(actual, expected, label) { assert.deepEqual(clone(actual), clone(expected), label); checks++; }

function fixture(options = {}) {
  const errors = [], virtualConsole = new VirtualConsole();
  virtualConsole.on('jsdomError', (error) => errors.push(error));
  const dom = new JSDOM('<!doctype html><html><body class="wp-admin"><main id="wpbody-content"><div id="bhranti-settings-app"></div></main></body></html>', { url: 'https://bhranti.wp.local/wp-admin/admin.php?page=bhranti', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole });
  const w = dom.window, mount = w.document.getElementById('bhranti-settings-app');
  const requests = [], states = [], savedEvents = [], changedListeners = new Set();
  let root = null, handler = options.handler, memoryHandler = options.memoryHandler, saved = clone(options.saved || initial()), memory = clone(options.memory || initialMemory());
  const add = w.addEventListener.bind(w), remove = w.removeEventListener.bind(w);
  w.addEventListener = (name, callback, ...args) => { if (name === 'bhranti:settings-changed') changedListeners.add(callback); return add(name, callback, ...args); };
  w.removeEventListener = (name, callback, ...args) => { if (name === 'bhranti:settings-changed') changedListeners.delete(callback); return remove(name, callback, ...args); };
  w.fetch = () => { httpCalls++; throw new Error('The Settings fixture cannot fetch.'); };
  w.XMLHttpRequest = function() { httpCalls++; throw new Error('The Settings fixture cannot use XMLHttpRequest.'); };
  w.open = () => { throw new Error('The Settings fixture cannot navigate.'); };
  bundles.forEach((bundle) => w.eval(bundle.text + '\n//# sourceURL=' + bundle.name));
  const element = w.wp.element, h = element.createElement;
  function wrapper(className, tag = 'div') { return ({ children }) => h(tag, { className }, children); }
  function Field({ label, value, onChange, disabled, help, rows, type, min, max, step }) {
    const id = element.useId();
    return h('div', { className: 'components-base-control' }, h('label', { htmlFor: id }, label), h(rows ? 'textarea' : 'input', { id, value, rows, disabled, type, min, max, step, onInput: (event) => onChange(event.target.value) }), help ? h('p', null, help) : null);
  }
  // Mock native component control glue only. Hooks, batching, layout/effects,
  // roots, controlled inputs and synthetic event propagation remain real.
  w.wp.element = { ...element, createRoot: (...args) => { root = element.createRoot(...args); return root; } };
  w.wp.components = {
    Card: wrapper('components-card'), CardHeader: wrapper('components-card__header'), CardBody: wrapper('components-card__body'), TextControl: Field, TextareaControl: Field,
    ToggleControl: ({ label, checked, disabled, onChange }) => { const id = element.useId(); return h('label', { htmlFor: id }, label, h('input', { id, type: 'checkbox', checked, disabled, onChange: (event) => onChange(event.target.checked) })); },
    Button: ({ children, onClick, disabled, isBusy, className }) => h('button', { type: 'button', onClick, disabled, className, 'aria-busy': isBusy ? 'true' : undefined }, children),
    Notice: ({ children, status }) => h('section', { role: 'status', className: 'components-notice is-' + status }, children), Spinner: () => h('span', null, 'Loading'),
    PanelBody: ({ children, title }) => h('section', { className: 'components-panel__body' }, h('h3', null, title), children),
  };
  w.wp.i18n = { __: (text) => text };
  w.wp.apiFetch = async (request) => {
    const captured = { path: request.path, method: request.method || 'GET', data: request.data === undefined ? undefined : clone(request.data), signal: request.signal };
    requests.push(captured);
    if (captured.path === '/bhranti/v1/memory' || captured.path === '/bhranti/v1/memory/reset') {
      if (memoryHandler) return memoryHandler(captured);
      if (captured.path.endsWith('/reset')) { assert.equal(captured.method, 'POST'); assert.deepEqual(Object.keys(captured.data), ['revision']); memory = { ...initialMemory(), revision: 'memory-reset' }; }
      else assert.equal(captured.method, 'GET');
      return clone(memory);
    }
    assert.equal(captured.path, '/bhranti/v1/settings', 'Only the reviewed Settings endpoint is called');
    assert.ok(['GET', 'POST'].includes(captured.method), 'The fixture exposes only read/save requests');
    if (handler) return handler(captured, requests.length);
    if (captured.method === 'POST') saved = { config: clone(captured.data.config), revision: 'saved-' + requests.length };
    return clone(saved);
  };
  w.bhrantiSettingsPage = { pluginDeletionAvailable: true };
  w.addEventListener('bhranti:settings-state', (event) => states.push({ dirty: mount.dataset.bhrantiDirty, busy: mount.dataset.bhrantiBusy, detail: event.detail }));
  w.addEventListener('bhranti:settings-saved', (event) => savedEvents.push(event.detail));
  w.eval(source + '\n//# sourceURL=bhranti/assets/settings.js');
  async function settle() { await pause(); await Promise.resolve(); if (errors.length) throw errors[0]; }
  function field(label, index = 0) { const labels = [...mount.querySelectorAll('label')].filter((node) => node.textContent === label); assert.ok(labels[index], 'Missing Settings field: ' + label); return w.document.getElementById(labels[index].htmlFor); }
  function button(label, index = 0) { const buttons = [...mount.querySelectorAll('button')].filter((node) => node.textContent === label); assert.ok(buttons[index], 'Missing Settings button: ' + label); return buttons[index]; }
  return { w, dom, mount, requests, states, savedEvents, changedListeners, settle, field, button,
    setHandler(next) { handler = next; }, setSaved(next) { saved = clone(next); }, setMemoryHandler(next) { memoryHandler = next; },
    gets: () => requests.filter((request) => request.path === '/bhranti/v1/settings' && request.method === 'GET'), posts: () => requests.filter((request) => request.path === '/bhranti/v1/settings' && request.method === 'POST'),
    memoryReads: () => requests.filter((request) => request.path === '/bhranti/v1/memory'), memoryResets: () => requests.filter((request) => request.path === '/bhranti/v1/memory/reset'),
    async input(label, value, index) { const node = field(label, index); node.value = value; node.dispatchEvent(new w.Event('input', { bubbles: true })); await settle(); },
    async click(label, index) { button(label, index).click(); await settle(); },
    async changed() { w.dispatchEvent(new w.CustomEvent('bhranti:settings-changed')); await settle(); },
    async unmount() { if (root) { root.unmount(); root = null; } await settle(); },
    async close() { if (root) { root.unmount(); root = null; } await settle(); dom.window.close(); },
  };
}
async function until(page, predicate, label) { for (let attempt = 0; attempt < 50; attempt++) { await page.settle(); if (predicate()) return; } assert.fail(label); }
function flags(page, dirty, busy, label) { equal(page.mount.dataset.bhrantiDirty, String(dirty), label + ': authoritative dirty flag'); equal(page.mount.dataset.bhrantiBusy, String(busy), label + ': authoritative busy flag'); }

async function testInitialAndReactDirty() {
  const held = deferred(), page = fixture({ handler: () => held.promise });
  try {
    await until(page, () => page.gets().length === 1, 'Initial Settings GET must start');
    flags(page, false, true, 'Unknown/loading Settings');
    check(page.states.some((state) => state.busy === 'true' && state.dirty === 'false'), 'Initial layout publishes unknown/loading state before acknowledgement');
    check(page.gets()[0].signal instanceof page.w.AbortSignal, 'Initial read uses the native cancellable GET contract');
    held.resolve(initial()); await until(page, () => page.mount.dataset.bhrantiBusy === 'false', 'Acknowledged initial Settings load must finish');
    flags(page, false, false, 'Acknowledged initial load'); equal(page.changedListeners.size, 1, 'Mount subscribes once to the committed-settings event');
    await page.input('System prompt', 'Explicit unsaved rules'); flags(page, true, false, 'Direct native TextareaControl edit');
    await page.input('System prompt', ''); flags(page, false, false, 'Exact baseline restoration');
    await page.click('Add agent'); flags(page, true, false, 'React Add agent without a native input event'); equal(page.mount.querySelectorAll('input:not([type="checkbox"])').length, 3, 'Added agent is actually rendered');
    await page.click('Remove agent'); flags(page, false, false, 'React Remove agent restores the baseline');
    await page.click('Add skill'); flags(page, true, false, 'React Add skill publishes dirty');
    await page.click('Remove skill'); flags(page, false, false, 'React Remove skill restores clean state');
    await page.input('Task step limit', '240'); flags(page, true, false, 'Direct native TextControl edit');
    equal(page.field('Task step limit').value, '240', 'React stores the requested limit'); equal(page.posts().length, 0, 'Editing and React add/remove never save automatically'); equal(page.gets().length, 1, 'Local edits never invent refresh reads');
    check(page.states.every((state) => state.detail === null && ['true', 'false'].includes(state.dirty) && ['true', 'false'].includes(state.busy)), 'Published state carries only the authoritative boolean dataset, without config or document payloads');
  } finally { held.resolve(initial()); await page.close(); }
}

async function testAcknowledgedAndFailedSave() {
  const page = fixture(), held = deferred();
  try {
    await until(page, () => page.mount.dataset.bhrantiBusy === 'false', 'Initial load');
    await page.input('System prompt', 'Literal rules <script>data()</script> 猫');
    page.setHandler((request) => { assert.equal(request.method, 'POST'); return held.promise; });
    await page.click('Save settings'); flags(page, true, true, 'Save awaiting actual acknowledgement');
    equal(page.posts().length, 1, 'Explicit Save sends once'); deep(Object.keys(page.posts()[0].data).sort(), ['config', 'revision'], 'Save uses only the exact revisioned payload'); equal(page.posts()[0].data.revision, 'initial', 'Initial save binds the loaded revision');
    equal(page.posts()[0].data.config.system_prompt, 'Literal rules <script>data()</script> 猫', 'Save retains exact literal instruction data'); check(page.field('System prompt').disabled, 'Native control is disabled while its save is unresolved'); equal(page.savedEvents.length, 0, 'No clean/save event is claimed before acknowledgement');
    const ack = { config: clone(page.posts()[0].data.config), revision: 'acknowledged-revision' }; held.resolve(ack);
    await until(page, () => page.mount.dataset.bhrantiBusy === 'false', 'Save acknowledgement must release busy'); flags(page, false, false, 'Acknowledged save'); equal(page.savedEvents.length, 1, 'Only acknowledged save emits settings-saved'); equal(page.savedEvents[0], null, 'Save event contains no raw documents');
    await page.input('System prompt', 'New edits after acknowledgement');
    page.setHandler((request) => { assert.equal(request.method, 'POST'); return Promise.reject(Object.assign(new Error('Exact fixture revision conflict'), { data: { status: 409 } })); });
    await page.click('Save settings'); await until(page, () => page.mount.textContent.includes('Exact fixture revision conflict'), 'Failed save remains visible');
    flags(page, true, false, 'Failed save'); equal(page.field('System prompt').value, 'New edits after acknowledgement', 'Failed save preserves the exact local edit'); equal(page.savedEvents.length, 1, 'Failed save cannot emit an acknowledgement'); equal(page.posts().length, 2, 'Failed POST is not retried automatically'); equal(page.gets().length, 1, 'Failed save cannot silently replace edits with GET');
    equal(page.posts()[1].data.revision, 'acknowledged-revision', 'Failed attempt retains the last acknowledged revision');
    page.setHandler((request) => ({ config: clone(request.data.config), revision: 'explicit-retry-revision' }));
    await page.click('Save settings'); await until(page, () => page.mount.dataset.bhrantiDirty === 'false', 'Human-initiated save retry'); equal(page.posts()[2].data.revision, 'acknowledged-revision', 'Only an explicit retry reuses the prior revision'); equal(page.posts().length, 3, 'Human retry is exactly one extra POST');
  } finally { held.resolve(initial()); await page.close(); }
}

async function testCommittedCleanRefreshAndDirtyDeferral() {
  const page = fixture();
  try {
    await until(page, () => page.mount.dataset.bhrantiBusy === 'false', 'Initial load');
    const committed = initial(); committed.config.max_steps = 320; committed.revision = 'committed-limit'; page.setSaved(committed);
    await page.changed(); await until(page, () => page.field('Task step limit').value === '320', 'Clean committed task limit is read back');
    flags(page, false, false, 'Clean committed task-limit refresh'); equal(page.gets().length, 2, 'Commit signal issues one safe Settings GET'); equal(page.posts().length, 0, 'Commit signal cannot replay the task-limit write'); equal(page.savedEvents.length, 0, 'Reading a committed change is not a user save');
    await page.input('Guardrails', 'Keep these unsaved guardrails');
    committed.config.max_steps = 420; committed.revision = 'later-limit'; page.setSaved(committed); await page.changed(); await page.changed();
    flags(page, true, false, 'Dirty Settings defers committed changes'); equal(page.gets().length, 2, 'Even repeated committed events cannot fetch over unsaved edits'); equal(page.field('Task step limit').value, '320', 'Deferred refresh cannot partially overwrite the local configuration'); equal(page.field('Guardrails').value, 'Keep these unsaved guardrails', 'Deferred refresh preserves exact draft documents');
    await page.input('Guardrails', ''); await until(page, () => page.field('Task step limit').value === '420', 'Returning to exact baseline releases the queued safe refresh'); flags(page, false, false, 'Deferred commit after clean baseline'); equal(page.gets().length, 3, 'Queued committed signals coalesce into one safe read'); equal(page.posts().length, 0, 'Dirty deferral and release never mutate settings');
  } finally { await page.close(); }
}

async function testEditsDuringRefresh() {
  const page = fixture(), held = deferred();
  try {
    await until(page, () => page.mount.dataset.bhrantiBusy === 'false', 'Initial load');
    page.setHandler((request) => { assert.equal(request.method, 'GET'); return held.promise; }); await page.changed();
    equal(page.gets().length, 2, 'Clean commit begins one held read'); await page.input('System prompt', 'Edits begun during the Settings read');
    const stale = initial(); stale.config.max_steps = 480; stale.revision = 'stale-read-revision'; held.resolve(stale); await page.settle(); await page.settle();
    flags(page, true, false, 'Mid-read edit remains authoritative'); equal(page.field('System prompt').value, 'Edits begun during the Settings read', 'A late GET cannot overwrite a mid-read edit'); equal(page.field('Task step limit').value, '128', 'Rejected stale GET cannot update just one saved field'); equal(page.gets().length, 2, 'Dirty rejection does not start a fetch loop');
    page.setHandler((request) => { assert.equal(request.method, 'POST'); return Promise.reject(Object.assign(new Error('Mid-read explicit save conflict'), { data: { status: 409 } })); });
    await page.click('Save settings'); await until(page, () => page.mount.textContent.includes('Mid-read explicit save conflict'), 'Explicit stale-revision save reports conflict'); equal(page.posts()[0].data.revision, 'initial', 'Rejected refresh preserves the exact original revision'); equal(page.posts()[0].data.config.system_prompt, 'Edits begun during the Settings read', 'Explicit save still contains the retained user edits');
    await page.click('Reload saved settings'); check(page.mount.textContent.includes('Reloading replaces your unsaved edits'), 'Conflict refresh asks before discarding local edits'); equal(page.gets().length, 2, 'Opening reload confirmation does not fetch'); await page.click('Keep editing'); flags(page, true, false, 'Keep editing retains dirty');
    page.setHandler((request) => { assert.equal(request.method, 'GET'); return clone(stale); }); await page.click('Reload saved settings'); await page.click('Reload');
    await until(page, () => page.mount.dataset.bhrantiDirty === 'false', 'Explicit acknowledged reload may replace local edits'); equal(page.field('Task step limit').value, '480', 'Explicit reload uses the committed limit'); equal(page.field('System prompt').value, '', 'Explicitly approved reload adopts saved documents'); flags(page, false, false, 'Acknowledged explicit load'); equal(page.posts().length, 1, 'Conflict recovery cannot replay the save');
  } finally { held.resolve(initial()); await page.close(); }
}

async function testChangedThenRestoredDuringRefresh() {
  const page = fixture(), held = deferred();
  try {
    await until(page, () => page.mount.dataset.bhrantiBusy === 'false', 'Initial load');
    const fresh = initial(); fresh.config.max_steps = 520; fresh.revision = 'fresh-after-edit'; let refreshes = 0;
    page.setHandler((request) => { assert.equal(request.method, 'GET'); return ++refreshes === 1 ? held.promise : clone(fresh); });
    await page.changed(); await page.input('System prompt', 'Transient local change'); await page.input('System prompt', '');
    flags(page, false, false, 'Edit restored while refresh is pending'); held.resolve(initial());
    await until(page, () => page.field('Task step limit').value === '520', 'Changed configuration identity triggers a fresh read even after exact undo');
    equal(page.gets().length, 3, 'Stale generation is discarded with one bounded replacement read'); equal(page.posts().length, 0, 'Generation recheck never saves automatically'); flags(page, false, false, 'Fresh generation accepted');
  } finally { held.resolve(initial()); await page.close(); }
}

async function testCommitWhileSaving() {
  const page = fixture(), held = deferred();
  try {
    await until(page, () => page.mount.dataset.bhrantiBusy === 'false', 'Initial load'); await page.input('System prompt', 'Saving my own acknowledged rules');
    const newest = initial(); newest.config.system_prompt = 'Saving my own acknowledged rules'; newest.config.max_steps = 640; newest.revision = 'latest-after-save';
    page.setHandler((request) => request.method === 'POST' ? held.promise : clone(newest)); await page.click('Save settings'); await page.changed();
    flags(page, true, true, 'Committed event waits for own save acknowledgement'); equal(page.gets().length, 1, 'Busy save defers all committed refresh reads'); equal(page.posts().length, 1, 'Committed event cannot replay an unresolved save');
    held.resolve({ config: clone(page.posts()[0].data.config), revision: 'own-save-ack' }); await until(page, () => page.field('Task step limit').value === '640', 'Clean acknowledged save releases the deferred committed read');
    flags(page, false, false, 'Committed refresh after save'); equal(page.gets().length, 2, 'Only one fresh GET follows acknowledgement'); equal(page.posts().length, 1, 'Safe refresh preserves the single explicit save'); equal(page.field('System prompt').value, 'Saving my own acknowledged rules', 'Acknowledged own documents survive the latest saved read');
  } finally { held.resolve(initial()); await page.close(); }
}

async function testRefreshFailureAndUnmount() {
  const page = fixture(), held = deferred();
  try {
    await until(page, () => page.mount.dataset.bhrantiBusy === 'false', 'Initial load'); page.setHandler(() => Promise.reject(new Error('Isolated saved-read failure'))); await page.changed();
    await until(page, () => page.mount.textContent.includes('Reload saved settings to see it here'), 'Failed committed GET reports a useful reload action');
    flags(page, false, false, 'Failed committed read'); equal(page.field('Task step limit').value, '128', 'Failed refresh preserves last acknowledged values'); equal(page.posts().length, 0, 'Failed committed read never writes'); equal(page.gets().length, 2, 'Read failure does not spin a retry loop');
    page.setHandler(() => held.promise); await page.changed(); equal(page.gets().length, 3, 'A later committed event can request a fresh read');
    await page.unmount(); equal(page.changedListeners.size, 0, 'Unmount removes its committed-change subscription'); const stateEvents = page.states.length, requests = page.requests.length;
    held.resolve(initial()); await page.settle(); await page.changed(); equal(page.requests.length, requests, 'Unmounted Settings cannot start another request'); equal(page.states.length, stateEvents, 'Late response cannot publish state from an unmounted Settings tree'); equal(page.mount.children.length, 0, 'Real React unmount removes the Settings controls');
  } finally { held.resolve(initial()); await page.close(); }
}

async function testNewCommitDuringRefresh() {
  const page = fixture(), held = deferred();
  try {
    await until(page, () => page.mount.dataset.bhrantiBusy === 'false', 'Initial load');
    const first = initial(); first.config.max_steps = 700; first.revision = 'first-commit'; const latest = initial(); latest.config.max_steps = 800; latest.revision = 'second-commit'; let refreshes = 0;
    page.setHandler((request) => { assert.equal(request.method, 'GET'); return ++refreshes === 1 ? held.promise : clone(latest); });
    await page.changed(); equal(page.gets().length, 2, 'First committed event begins a clean read'); await page.changed(); equal(page.gets().length, 2, 'Second commit does not overlap the pending GET');
    held.resolve(first);
    await until(page, () => page.field('Task step limit').value === '800', 'A second committed change during the first GET must trigger a fresh read, not leave the older limit');
    equal(page.gets().length, 3, 'The later committed generation needs exactly one follow-up GET'); equal(page.posts().length, 0, 'Later committed generation cannot replay a write'); flags(page, false, false, 'Latest committed generation displayed');
  } finally { held.resolve(initial()); await page.close(); }
}

async function testMemoryReviewAndConfirmedReset() {
  const memory = { ...initialMemory(), revision: 'reviewed-memory', preferences: [{ id: 'style', text: 'Use a calm visual style <script>not executable</script>' }], capabilities: [{ id: 'images', summary: 'Images can be saved as PNG, JPEG or WebP.', observed: 1791800000, expires: 1991800000, runtime: 'PRIVATE_RUNTIME_MARKER', details: { secret: 'PRIVATE_DETAIL_MARKER' } }] };
  const page = fixture({ memory }), held = deferred();
  try {
    await until(page, () => page.mount.textContent.includes('Images can be saved'), 'Saved memory is displayed');
    equal(page.memoryReads().length, 1, 'Memory loads once through its private read endpoint'); equal(page.memoryResets().length, 0, 'Reviewing memory cannot reset it');
    check(page.mount.textContent.includes(memory.preferences[0].text), 'Preferences render as literal readable text'); equal(page.mount.querySelectorAll('script').length, 0, 'Preference content is never executed'); check(!page.mount.textContent.includes('PRIVATE_'), 'Capability runtime and raw details never appear in the product');
    await page.input('System prompt', 'Keep this unrelated unsaved instruction'); await page.click('Reset memory');
    equal(page.memoryResets().length, 0, 'The first Reset button only requests confirmation'); check(page.mount.textContent.includes('Your chats and site content stay in place'), 'Reset explains its exact private-memory scope');
    await page.click('Keep memory'); equal(page.memoryResets().length, 0, 'Declining reset keeps all saved memory');
    page.setMemoryHandler((request) => { assert.equal(request.path, '/bhranti/v1/memory/reset'); return held.promise; });
    await page.click('Reset memory'); await page.click('Reset my memory'); flags(page, true, true, 'Confirmed memory reset protects in-flight Settings');
    equal(page.memoryResets().length, 1, 'Explicit confirmation resets once'); deep(page.memoryResets()[0].data, { revision: 'reviewed-memory' }, 'Reset sends only the revision the user reviewed');
    check(page.button('Refresh memory').disabled, 'Refresh cannot race an unacknowledged reset'); equal(page.field('System prompt').value, 'Keep this unrelated unsaved instruction', 'Memory reset leaves other Settings drafts intact');
    held.resolve({ ...initialMemory(), revision: 'fresh-empty' }); await until(page, () => page.mount.textContent.includes('Your memory was reset'), 'Reset waits for native acknowledgement');
    flags(page, true, false, 'Acknowledged reset returns control while preserving the Settings draft'); check(page.button('Reset memory').disabled, 'An empty acknowledged memory cannot accidentally be reset again');
    equal(page.posts().length, 0, 'Reset never submits site-wide Settings'); equal(page.savedEvents.length, 0, 'Memory reset never claims unrelated Settings were saved');
  } finally { held.resolve(initialMemory()); await page.close(); }
}

async function testMemoryFailureAndAvailability() {
  for (const status of [409, 502]) {
    const populated = { ...initialMemory(), preferences: [{ id: 'tone', text: 'Prefer concise explanations.' }] };
    const page = fixture({ memory: populated });
    try {
      await until(page, () => page.mount.textContent.includes('Prefer concise'), 'Memory initially loads');
      page.setMemoryHandler(() => Promise.reject(Object.assign(new Error('PRIVATE_ERROR_DETAIL'), { data: { status } })));
      await page.click('Reset memory'); await page.click('Reset my memory');
      await until(page, () => page.button('Reset memory').disabled, 'Unconfirmed reset requires a fresh review');
      equal(page.memoryResets().length, 1, 'Failed reset is never retried automatically'); check(!page.mount.textContent.includes('PRIVATE_ERROR_DETAIL'), 'Reset failures use actionable UI copy without backend detail'); check(page.mount.textContent.includes('Prefer concise'), 'Unconfirmed reset preserves the last known review'); flags(page, false, false, 'Failed memory reset releases busy state');
      await page.click('Reset memory'); equal(page.memoryResets().length, 1, 'Disabled Reset cannot resubmit uncertain deletion');
      page.setMemoryHandler(() => ({ ...populated, revision: 'new-review' })); await page.click('Refresh memory');
      equal(page.memoryReads().length, 2, 'A human refresh obtains a fresh memory revision'); check(!page.button('Reset memory').disabled, 'Successful refresh allows another explicit review and confirmation'); equal(page.memoryResets().length, 1, 'Refreshing never replays the earlier reset');
    } finally { await page.close(); }
  }
  const page = fixture({ memory: { ...initialMemory(), available: false, message: 'Memory is unavailable. Chat remains available.' } });
  try {
    await until(page, () => page.mount.textContent.includes('Chat remains available'), 'Unavailable storage is explained locally');
    check(page.button('Reset memory').disabled, 'Unavailable memory cannot be mutated'); await page.input('System prompt', 'Still edit normal settings'); check(!page.button('Save settings').disabled, 'Unavailable memory does not block normal Settings');
    equal(page.memoryResets().length, 0, 'Unavailable memory does not invoke reset');
  } finally { await page.close(); }
  const damaged = fixture({ memory: { ...initialMemory(), revision: 'a'.repeat(64), message: 'Saved memory could not be read. You can reset it here.' } });
  try {
    await until(damaged, () => damaged.mount.textContent.includes('Saved memory could not be read'), 'Damaged storage explains the recovery path');
    check(!damaged.button('Reset memory').disabled, 'Unreadable memory remains explicitly resettable even without decoded entries');
    await damaged.click('Reset memory'); await damaged.click('Reset my memory');
    await until(damaged, () => damaged.mount.textContent.includes('Your memory was reset'), 'Confirmed reset recovers unreadable private memory');
    equal(damaged.memoryResets()[0].data.revision, 'a'.repeat(64), 'Recovery retains the complete ciphertext revision without treating it as a UUID');
    equal(damaged.memoryResets().length, 1, 'Damaged private memory is reset only once after confirmation');
  } finally { await damaged.close(); }
  const failed = fixture({ memoryHandler: () => Promise.reject(new Error('/private/secret-storage/credential-marker')) });
  try {
    await until(failed, () => failed.mount.textContent.includes('Memory could not load'), 'Memory read failure provides an independent retry');
    check(!failed.mount.textContent.includes('credential-marker'), 'Storage transport details do not leak into the Settings product'); equal(failed.memoryReads().length, 1, 'Memory read failure does not spin an automatic retry loop');
    await failed.input('System prompt', 'Other settings remain usable'); check(!failed.button('Save settings').disabled, 'A failed memory read leaves unrelated Settings usable');
    failed.setMemoryHandler(() => initialMemory()); await failed.click('Refresh memory');
    await until(failed, () => failed.mount.textContent.includes('No saved preferences yet'), 'Manual refresh recovers the memory card');
    equal(failed.memoryReads().length, 2, 'Manual recovery issues exactly one new read'); equal(failed.memoryResets().length, 0, 'Read recovery cannot mutate private memory');
  } finally { await failed.close(); }
}

async function main() {
  for (const test of [testInitialAndReactDirty, testAcknowledgedAndFailedSave, testCommittedCleanRefreshAndDirtyDeferral, testEditsDuringRefresh, testChangedThenRestoredDuringRefresh, testCommitWhileSaving, testRefreshFailureAndUnmount, testNewCommitDuringRefresh, testMemoryReviewAndConfirmedReset, testMemoryFailureAndAvailability]) { currentCase = test.name; await test(); }
  equal(httpCalls, 0, 'All Settings React checks remain offline');
  console.log('PASS ' + checks + ' Settings sync checks; real WordPress React/element; no HTTP, AI, WordPress load or DB');
}
const watchdog = setTimeout(() => { console.error('FAIL: unresolved Settings operation exceeded the 20-second deadline in ' + currentCase); process.exit(1); }, 20000);
main().catch((error) => { console.error('FAIL after ' + checks + ' checks in ' + currentCase); console.error(error); process.exitCode = 1; }).finally(() => clearTimeout(watchdog));
