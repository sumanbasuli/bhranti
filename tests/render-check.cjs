/** Offline DOM/control-flow checks; geometry and engine results are fixture seams. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
let JSDOM;
try { ({ JSDOM } = require('jsdom')); } catch (_) { ({ JSDOM } = require('/Users/txsadhu/node_modules/jsdom')); }
const source = fs.readFileSync(path.join(__dirname, '../assets/render-check.js'), 'utf8');
const original = 'window.BhrantiRender = Object.freeze({ run });';
assert.equal(source.split(original).length, 2);
const instrumented = source.replace(original, 'window.BhrantiRender = Object.freeze({ run, plain, bytes, INTEGRITY });');
const axeUrl = 'https://render.invalid/wp-content/plugins/bhranti/assets/vendor/axe-core/axe.js?ver=4.14.0';
const job = { id: 'd663dfd7-bc9d-4b7b-b801-79e188644212', run_id: 'e47e4df9-40cb-4577-b52f-d58394802a02', step: 7, post_id: 17, path: '/wp/v2/pages/17', url: 'https://render.invalid/fixture/?bhranti_render=9&bhranti_render_job=d663dfd7-bc9d-4b7b-b801-79e188644212', revision: 'a'.repeat(64), environment_revision: 'b'.repeat(64), marker: 'c'.repeat(64), expires: Math.floor(Date.now() / 1000) + 600, engine: 'axe-core', version: '4.14.0', viewports: [{ width: 1280, height: 900 }, { width: 390, height: 844 }, { width: 320, height: 844 }] };
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
function equal(actual, expected, message) { assert.deepEqual(actual, expected, message); checks++; }
const clone = (value) => JSON.parse(JSON.stringify(value));
function fixture(options = {}) {
  const dom = new JSDOM('<!doctype html><html><head></head><body><input value="parent-unsaved-private-value"><button>Keep focus</button></body></html>', { url: 'https://render.invalid/wp-admin/admin.php?page=bhranti', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window, frames = [], children = [], scripts = [], scans = [], scrolls = [], messages = [];
  if (options.noRandomUUID) Object.defineProperty(w.crypto, 'randomUUID', { value: undefined });
  const timers = new Set(), listeners = new Map();
  const track = (view) => { const active = new Set(); listeners.set(view, active); const add = view.addEventListener.bind(view), remove = view.removeEventListener.bind(view); view.addEventListener = (type, callback, options) => { if (['message', 'pagehide'].includes(type)) active.add(callback); add(type, callback, options); }; view.removeEventListener = (type, callback, options) => { if (['message', 'pagehide'].includes(type)) active.delete(callback); remove(type, callback, options); }; };
  track(w);
  w.TextEncoder = TextEncoder;
  w.setTimeout = (callback, delay) => { let id; id = setTimeout(() => { timers.delete(id); callback(); }, delay >= 60000 ? 1000 : delay >= 2000 ? delay / 1000 : 0); timers.add(id); return id; };
  w.clearTimeout = (id) => { timers.delete(id); clearTimeout(id); };
  w.setInterval = (callback) => { const id = setInterval(callback, 1); timers.add(id); return id; };
  w.clearInterval = (id) => { timers.delete(id); clearInterval(id); };
  w.bhrantiSettings = { renderCheck: { axeUrl, version: '4.14.0' } };
  w.fetch = () => { throw new Error('No network in worker unit fixtures.'); };
  w.open = () => { throw new Error('No popups in worker unit fixtures.'); };
  const append = w.document.body.appendChild.bind(w.document.body);
  w.document.body.appendChild = (element) => {
    append(element);
    if (element.localName !== 'iframe') return element;
    frames.push(element);
    const marker = options.missingMarker ? '' : '<meta name="bhranti-render-check" content="' + (options.marker || job.marker) + '">';
    const body = options.body || '<main><h1>Signal café 🎮</h1><h2>Native section</h2><a id="private-id-token" href="#destination">Continue</a><section id="destination">Visitor content</section><img src="/secret-image.png?token=private-query-token" alt="Native subject"><form><input value="private-form-value"><button>Native submit</button></form></main>';
    const child = new JSDOM('<!doctype html><html lang="en"><head><title>Native fixture</title>' + marker + '</head><body>' + body + '</body></html>', { url: options.redirect || element.src, runScripts: 'outside-only', pretendToBeVisual: true });
    children.push(child);
    const view = child.window, doc = view.document;
    view.TextEncoder = TextEncoder; track(view);
    ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'].forEach((name) => { view[name] = w[name]; });
    if (options.loadPending || options.deferredPending) Object.defineProperty(doc, 'readyState', { value: options.deferredPending ? 'interactive' : 'loading' });
    if (options.deferredPending) {
      const add = doc.addEventListener.bind(doc);
      doc.addEventListener = (type, callback, settings) => { if (type !== 'DOMContentLoaded') add(type, callback, settings); };
    }
    if (options.cachedDomReady) {
      Object.defineProperty(doc, 'readyState', { value: 'interactive' });
      view.performance.getEntriesByType = () => [{ domContentLoadedEventEnd: 1 }];
    }
    if (options.transientLoad) {
      let parsed = false; Object.defineProperty(doc, 'readyState', { get: () => parsed ? 'interactive' : 'loading' });
      queueMicrotask(() => element.dispatchEvent(new w.Event('load')));
      w.setTimeout(() => { parsed = true; doc.dispatchEvent(new view.Event('DOMContentLoaded')); }, 0);
    }
    if (options.stylesPending || options.stylesReadyLater) {
      const link = doc.createElement('link'); link.rel = 'stylesheet'; if (!options.stylesEmpty) link.href = '/fixture.css'; doc.head.appendChild(link);
      if (options.stylesDisabled) link.disabled = true;
      if (options.stylesInactive) { link.media = 'print'; view.matchMedia = () => ({ matches: false }); }
      if (options.stylesUnknown) { link.media = 'screen'; view.matchMedia = () => { throw new Error('Unknown fixture media state'); }; }
      let loaded = false; Object.defineProperty(link, 'sheet', { get: () => loaded ? {} : null });
      if (options.stylesReadyLater) w.setTimeout(() => { loaded = true; link.dispatchEvent(new view.Event('load')); }, 0);
    }
    const deliver = (target, data, origin, source, targetOrigin, direction) => {
      messages.push({ data: clone(data), targetOrigin, direction });
      const attempts = options.messages ? options.messages(clone(data), direction) : [{ data, origin, source }];
      for (const attempt of attempts) queueMicrotask(() => { if (element.isConnected && targetOrigin === target.location.origin) { const event = new target.MessageEvent('message', { data: attempt.data, origin: attempt.origin || origin }); Object.defineProperty(event, 'source', { value: attempt.source || source }); target.dispatchEvent(event); } });
    };
    const parentProxy = { postMessage: (data, targetOrigin) => deliver(w, data, view.location.origin, observedWindow, targetOrigin, 'child') };
    const observedWindow = new Proxy(view, { get(target, key) { if (key === 'location' && options.opaqueOrigin) throw new view.DOMException('private inaccessible frame URL', 'SecurityError'); if (key === 'postMessage') return (data, targetOrigin) => deliver(view, data, w.location.origin, parentProxy, targetOrigin, 'parent'); return Reflect.get(target, key); } });
    Object.defineProperty(view, 'parent', { value: options.topLevel ? view : parentProxy });
    Object.defineProperty(element, 'contentWindow', { value: observedWindow, configurable: true });
    Object.defineProperty(element, 'contentDocument', { get() { if (options.opaqueOrigin) throw new view.DOMException('private inaccessible document', 'SecurityError'); return options.absentDocument ? null : doc; }, configurable: true });
    const remove = element.remove.bind(element); element.remove = () => { view.dispatchEvent(new view.Event('pagehide')); remove(); };
    Object.defineProperty(view, 'innerWidth', { value: options.wrongWidth ? 900 : parseInt(element.style.width), configurable: true });
    Object.defineProperty(view, 'innerHeight', { value: parseInt(element.style.height), configurable: true });
    Object.defineProperty(doc.documentElement, 'clientWidth', { get: () => view.innerWidth });
    Object.defineProperty(doc.documentElement, 'scrollWidth', { get: () => view.innerWidth + (options.overflow || 0) });
    Object.defineProperty(doc.body, 'scrollWidth', { get: () => view.innerWidth + (options.overflow || 0) });
    Object.defineProperty(doc.documentElement, 'scrollHeight', { get: () => options.tall ? 50000 : 1000 });
    Object.defineProperty(doc.body, 'scrollHeight', { get: () => options.tall ? 50000 : 1000 });
    Object.defineProperty(doc, 'fonts', { value: { ready: options.fontsPending ? new Promise(() => {}) : Promise.resolve() } });
    view.HTMLElement.prototype.getBoundingClientRect = function () { const width = this.getAttribute('data-fixture-width') ? Number(this.getAttribute('data-fixture-width')) : ['html', 'body', 'main'].includes(this.localName) ? view.innerWidth : 100; return { x: 0, y: 0, left: 0, top: 0, width, height: 30, right: width, bottom: 30 }; };
    view.scrollTo = (x, y) => { scrolls.push([x, y]); };
    Array.from(doc.images).forEach((image) => { Object.defineProperty(image, 'complete', { value: !options.imagesPending }); Object.defineProperty(image, 'naturalWidth', { value: options.brokenImages ? 0 : 1536 }); });
    const headAppend = doc.head.appendChild.bind(doc.head);
    doc.head.appendChild = (script) => {
      scripts.push(script); headAppend(script);
      view.axe = { version: options.wrongVersion ? '4.13.0' : '4.14.0', configure(config) { equal(JSON.stringify(config), JSON.stringify({ noHtml: true, allowedOrigins: [] }), 'The engine suppresses HTML output and nested frame messages.'); }, run(context, settings) {
        scans.push(settings); equal(context, doc, 'The engine receives the tested actual iframe document.');
        if (options.changeMarker) doc.querySelector('meta').setAttribute('content', 'd'.repeat(64));
        if (options.enginePending) return new Promise(() => {});
        return Promise.resolve(options.result ? options.result(doc) : { violations: [], incomplete: [], passes: Array(12).fill({}), inapplicable: Array(50).fill({}) });
      } };
      queueMicrotask(() => script.dispatchEvent(new view.Event(options.engineError ? 'error' : 'load')));
      return script;
    };
    view.bhrantiRenderFrame = { job: clone(job), renderCheck: { axeUrl, version: '4.14.0' } };
    if (options.autoFocus) element.focus();
    if (!options.blankPending) queueMicrotask(() => view.eval(instrumented));
    if (!options.loadPending && !options.domReadyOnly) queueMicrotask(() => element.dispatchEvent(new w.Event('load')));
    return element;
  };
  w.eval(instrumented);
  return { w, frames, children, scripts, scans, scrolls, timers, messages, listenerCount() { return Array.from(listeners.values()).reduce((sum, active) => sum + active.size, 0); }, close() { timers.forEach(clearTimeout); children.forEach((child) => child.window.close()); dom.window.close(); } };
}
async function failureCase(options, code, request = job) {
  const f = fixture(options);
  try { const result = await f.w.BhrantiRender.run(request, { axeUrl }); equal(JSON.stringify(result), JSON.stringify({ status: 'failed', code }), 'A bounded failure has only its fixed code.'); check(f.frames.every((frame) => !frame.isConnected), 'Failed jobs remove every owned frame.'); equal(f.timers.size, 0, 'Rejected jobs clean all readiness pollers and deadlines.'); equal(f.listenerCount(), 0, 'Rejected jobs remove both directions of message and pagehide listeners.'); } finally { f.close(); }
}
(async () => {
  const f = fixture();
  try {
    const api = f.w.BhrantiRender;
    equal(api.plain('Signal\n\t café 🎮', 100), 'Signal café 🎮', 'Control and whitespace normalization preserves ordinary letters and Unicode.');
    equal(api.plain('Signal\\u0000 literal', 100), 'Signal\\u0000 literal', 'Literal backslash-u text is not interpreted as a control character.');
    equal(api.plain('🎮🎮x', 2), '🎮🎮', 'The text bound does not split surrogate pairs.');
    equal(api.bytes({ text: 'café 🎮 /' }), 23, 'The report byte bound measures UTF-8 with unescaped Unicode/slashes, matching the server contract.');
    const expectedIntegrity = 'sha256-' + crypto.createHash('sha256').update(fs.readFileSync(path.join(__dirname, '../assets/vendor/axe-core/axe.js'))).digest('base64');
    equal(api.INTEGRITY, expectedIntegrity, 'SRI pins the exact locally vendored readable engine.');
    const focus = f.w.document.querySelector('button'); focus.focus();
    const progress = [];
    const report = await api.run(job, { axeUrl, onProgress: (state) => progress.push(state) });
    equal(report.status, 'complete', 'Three actual frame control-flow passes produce the strict report shape.');
    equal(JSON.stringify(report.profiles.map(({ width, height }) => [width, height])), '[[1280,900],[390,844],[320,844]]', 'Only the fixed desktop/mobile/narrow profiles run.');
    equal(f.frames.length, 3, 'The checker loads one frame per fixed viewport.');
    check(f.frames.every((frame) => !frame.isConnected && frame.getAttribute('sandbox') === 'allow-same-origin allow-scripts' && frame.getAttribute('aria-hidden') === 'true'), 'Frames are inaccessible to accidental input, sandboxed without form/popups/top navigation, then removed.');
    check(f.scripts.every((script) => script.src === axeUrl && script.integrity === expectedIntegrity && !script.textContent), 'Only the pinned same-origin engine is loaded; no generated or inline scripts.');
    check(f.scans.every((settings) => !settings.preload && !settings.iframes && settings.elementRef && settings.runOnly.values.join(',') === 'wcag2a,wcag2aa,wcag21a,wcag21aa,wcag22aa'), 'No best-practice/AAA claims, remote preload, or nested-frame execution.');
    check(report.profiles.every((profile) => profile.passes === 12 && profile.inapplicable === 50 && profile.limitations.includes('focus_not_tested') && profile.limitations.includes('automated_only')), 'Counts come from engine results and untested manual limits remain explicit.');
    equal(f.w.document.activeElement, focus, 'The parent native keyboard focus is preserved.');
    equal(progress.length, 9, 'Public progress uses three bounded phases per profile.');
    check(!JSON.stringify(report).includes('private-') && !JSON.stringify(report).includes('<input') && !JSON.stringify(report).includes('token='), 'No form values, raw markup, private element IDs or asset query tokens enter the report.');
    check(api.bytes(report) < 150000, 'The complete report stays within the server byte bound.');
  } finally { f.close(); }
  const domReady = fixture({ domReadyOnly: true });
  try { equal((await domReady.w.BhrantiRender.run(job, { axeUrl })).status, 'complete', 'A fully parsed native document can be inspected while unrelated resources still delay the iframe load event.'); equal(domReady.timers.size, 0, 'DOM-ready completion cleans every readiness timer.'); } finally { domReady.close(); }
  for (const readiness of [{ initialBlank: true }, { transientLoad: true, domReadyOnly: true }, { cachedDomReady: true, domReadyOnly: true }, { stylesReadyLater: true, domReadyOnly: true }, { stylesPending: true, stylesDisabled: true, domReadyOnly: true }, { stylesPending: true, stylesInactive: true, domReadyOnly: true }, { stylesPending: true, stylesEmpty: true, domReadyOnly: true }]) {
    const prepared = fixture(readiness);
    try { equal((await prepared.w.BhrantiRender.run(job, { axeUrl })).status, 'complete', 'Initial blank events, cached DOM completion and delayed active stylesheet readiness preserve the exact native preview gate.'); equal(prepared.timers.size, 0, 'Successful readiness removes all pollers and deadlines.'); } finally { prepared.close(); }
  }
  for (const changed of [null, { ...job, post_id: 18 }, { ...job, revision: 'bad' }, { ...job, expires: 1 }, { ...job, step: 1001 }, { ...job, version: '4.13.0' }, { ...job, viewports: [{ width: 600, height: 900 }] }]) await failureCase({}, 'load', changed);
  for (const url of ['https://outside.invalid/fixture/', 'https://user:secret@render.invalid/fixture/', 'https://render.invalid/wp-admin/', job.url + '&rest_route=/wp/v2/users', job.url + '&bhranti_render_job=duplicate', job.url + '#fragment']) await failureCase({}, 'origin', { ...job, url });
  await failureCase({ missingMarker: true }, 'marker');
  await failureCase({ missingMarker: true, domReadyOnly: true }, 'marker');
  await failureCase({ marker: '0'.repeat(64) }, 'marker');
  await failureCase({ redirect: 'https://outside.invalid/fixture/' }, 'load');
  for (const options of [{ opaqueOrigin: true, absentDocument: true }, { noRandomUUID: true }]) {
    const paired = fixture(options); try { equal((await paired.w.BhrantiRender.run(job, { axeUrl })).status, 'complete', 'Actual child measurement works without parent DOM access or randomUUID when native secure randomness remains available.'); check(paired.messages.every((message) => message.targetOrigin === 'https://render.invalid'), 'Both directions use the exact site target origin.'); equal(paired.timers.size, 0, 'Both protocol sides remove their owned timers.'); } finally { paired.close(); }
  }
  await failureCase({ deferredPending: true, domReadyOnly: true }, 'load');
  await failureCase({ stylesPending: true, domReadyOnly: true }, 'load');
  await failureCase({ stylesPending: true, stylesUnknown: true, domReadyOnly: true }, 'load');
  await failureCase({ changeMarker: true }, 'marker');
  await failureCase({ wrongWidth: true }, 'load');
  await failureCase({ body: '<main><h1>Large</h1>' + '<span>Part</span>'.repeat(5001) + '</main>' }, 'limit');
  await failureCase({ wrongVersion: true }, 'engine');
  await failureCase({ engineError: true }, 'engine');
  await failureCase({ enginePending: true }, 'timeout');
  await failureCase({ loadPending: true }, 'load');
  const loadingStop = fixture({ loadPending: true });
  try {
    const controller = new loadingStop.w.AbortController();
    await assert.rejects(loadingStop.w.BhrantiRender.run(job, { axeUrl, signal: controller.signal, onProgress(state) { if (state.phase === 'loading') loadingStop.w.setTimeout(() => controller.abort(), 0); } }), { name: 'AbortError' }); checks++;
    check(loadingStop.frames.every((frame) => !frame.isConnected), 'Stop during pending DOM readiness removes its owned frame.');
    equal(loadingStop.timers.size, 0, 'Stop during loading clears every readiness poller and deadline.');
    equal(loadingStop.scans.length, 0, 'An aborted loading job never starts the engine.');
  } finally { loadingStop.close(); }
  await failureCase({ topLevel: true }, 'load');
  await failureCase({ blankPending: true }, 'load');
  const deniedAsset = fixture();
  try { equal(JSON.stringify(await deniedAsset.w.BhrantiRender.run(job, { axeUrl: 'https://outside.invalid/axe.js' })), JSON.stringify({ status: 'failed', code: 'engine' }), 'No external or model-selected engine URL is accepted.'); equal(deniedAsset.frames.length, 0, 'Invalid asset metadata cannot start a page load.'); equal(JSON.stringify(await deniedAsset.w.BhrantiRender.run(job, null)), JSON.stringify({ status: 'failed', code: 'engine' }), 'Invalid options remain a finite preflight failure.'); equal(JSON.stringify(await deniedAsset.w.BhrantiRender.run(job, { axeUrl, signal: {} })), JSON.stringify({ status: 'failed', code: 'engine' }), 'Malformed cancellation metadata cannot throw an unbounded caller error.'); } finally { deniedAsset.close(); }
  const focusFixture = fixture({ autoFocus: true });
  try { const control = focusFixture.w.document.querySelector('button'); control.focus(); equal((await focusFixture.w.BhrantiRender.run(job, { axeUrl })).status, 'complete', 'A normal native page autofocus does not stop inspection.'); equal(focusFixture.w.document.activeElement, control, 'Native iframe autofocus returns control to the latest connected parent control without reading form values.'); } finally { focusFixture.close(); }
  const broken = fixture({ overflow: 100, brokenImages: true, body: '<main><h1>Signal</h1><a href="#missing">Broken link</a><p id="duplicate-secret">One</p><p id="duplicate-secret">Two</p><img alt="Native" src="/missing?token=private-query-token"><div data-fixture-width="1400">Wide</div></main>', result: (doc) => ({ violations: [{ id: 'color-contrast', impact: 'serious', description: 'Elements must have sufficient color contrast.', nodes: [{ element: doc.querySelector('a'), html: '<input value="private-form-value">', target: ['#private-id-token'], failureSummary: 'private-form-value', any: [{ data: { contrastRatio: 2.2, fgColor: '#777777', bgColor: '#ffffff' } }] }] }], incomplete: [{ id: 'image-alt', impact: null, description: 'Manual review needed.', nodes: [{ element: doc.querySelector('img') }] }], passes: [], inapplicable: [] }) });
  try {
    const report = await broken.w.BhrantiRender.run(job, { axeUrl });
    equal(report.status, 'complete', 'Observed defects are a completed check with findings, not an engine transport error.');
    check(report.profiles.every((profile) => profile.layout.document_width > profile.layout.viewport_width && profile.layout.overflow_count > 0 && profile.images.broken_count === 1 && profile.anchors.missing_count === 1 && profile.anchors.duplicate_count === 1), 'Real DOM observations retain overflow, broken images, missing fragment targets and duplicate IDs.');
    check(report.profiles.every((profile) => profile.violations[0].nodes[0].summary.includes('2.2') && profile.incomplete.length === 1 && profile.limitations.includes('axe_incomplete')), 'Measured contrast details and incomplete rules remain distinct.');
    check(!JSON.stringify(report).includes('private-') && !JSON.stringify(report).includes('<input'), 'Even axe raw HTML, failure summaries and attribute selectors cannot leak form values into reports.');
  } finally { broken.close(); }
  const clipped = fixture({ body: '<main><h1>Clipped decoration</h1><div data-fixture-width="2000">Wide but clipped</div></main>' });
  try { const report = await clipped.w.BhrantiRender.run(job, { axeUrl }); check(report.profiles.every((profile) => profile.layout.overflow_count === 0), 'Wide clipped child boxes do not invent page reflow failure when the actual document fits.'); } finally { clipped.close(); }
  const longReady = fixture({ tall: true });
  try { const report = await longReady.w.BhrantiRender.run(job, { axeUrl }); check(report.profiles.every((profile) => !profile.limitations.includes('lazy_limit')), 'Long native text does not exhaust the scroll budget when its existing images are already loaded.'); check(longReady.children.every((child) => Array.from(child.window.document.images).every((image) => image.loading === 'eager' || image.getAttribute('loading') === 'eager')), 'Only existing disposable-frame image loading mode is instrumented; source URLs stay unchanged.'); } finally { longReady.close(); }
  const limits = fixture({ fontsPending: true, imagesPending: true, tall: true, result: (doc) => ({ violations: Array.from({ length: 25 }, (_, index) => ({ id: 'rule-' + index, impact: null, description: 'Finite issue', nodes: Array(4).fill({ element: doc.body }) })), incomplete: [], passes: [], inapplicable: [] }) });
  try { const report = await limits.w.BhrantiRender.run(job, { axeUrl }); equal(report.status, 'complete', 'Partial asset/finding coverage is reported explicitly.'); check(report.profiles.every((profile) => ['fonts_timeout', 'images_timeout', 'lazy_limit', 'findings_limit'].every((name) => profile.limitations.includes(name)) && profile.violations.length === 24 && profile.violations.every((issue) => issue.nodes.length === 3)), 'Bounded asset waits, lazy loading and truncated findings are never silently treated as full coverage.'); } finally { limits.close(); }
  const stop = fixture({ fontsPending: true });
  try {
    const controller = new stop.w.AbortController();
    await assert.rejects(stop.w.BhrantiRender.run(job, { axeUrl, signal: controller.signal, onProgress(state) { if (state.phase === 'assets') controller.abort(); } }), { name: 'AbortError' }); checks++;
    check(stop.frames.every((frame) => !frame.isConnected), 'Stop tears down the current frame before any further scan.');
    equal(stop.scans.length, 0, 'Stop during asset readiness does not start the engine.');
  } finally { stop.close(); }
  const forged = fixture({ messages(data, direction) {
    const wrong = [];
    if (direction === 'child') {
      wrong.push({ data, origin: 'https://outside.invalid' }, { data, origin: 'null' }, { data, source: {} });
      for (const field of ['job_id', 'run_id', 'step', 'marker', 'revision', 'environment_revision', 'width', 'height']) { const bad = clone(data); bad.binding[field] = typeof bad.binding[field] === 'number' ? bad.binding[field] + 1 : 'stale'; wrong.push({ data: bad }); }
      const extra = clone(data); extra.extra = 'untrusted'; wrong.push({ data: extra });
      if (data.kind !== 'ready') { const stale = clone(data); stale.channel = '00000000-0000-4000-8000-000000000000'; wrong.push({ data: stale }); }
    }
    if (direction === 'parent' && data.kind === 'start') {
      wrong.push({ data, origin: 'null' }, { data, source: {} });
      const stale = clone(data); stale.binding.revision = '0'.repeat(64); wrong.push({ data: stale });
      const malformed = clone(data); malformed.channel = 'untrusted'; wrong.push({ data: malformed });
      const cancel = clone(data); cancel.kind = 'cancel'; wrong.push({ data: cancel });
    }
    return wrong.concat([{ data }, { data }]);
  } });
  try { equal((await forged.w.BhrantiRender.run(job, { axeUrl })).status, 'complete', 'Wrong origin/null origin/source, stale exact bindings/channels and extra keys cannot replace actual child observations; duplicate handshakes/progress/results settle once.'); equal(forged.scans.length, 3, 'Duplicate messages never repeat native engine work.'); equal(forged.timers.size, 0, 'Successful adversarial delivery removes all timers.'); equal(forged.listenerCount(), 0, 'Both sides remove all protocol/pagehide listeners after completion.'); } finally { forged.close(); }
  for (const field of ['width', 'height', 'extra']) await failureCase({ messages(data, direction) { if (direction === 'child' && data.kind === 'result' && data.payload.status === 'complete') { if (field === 'extra') data.payload.profile.extra = true; else data.payload.profile[field]++; } return [{ data }]; } }, 'engine');
  await failureCase({ messages(data, direction) { if (direction === 'child' && data.kind === 'result') data.payload.extra = 'x'.repeat(145001); return [{ data }]; } }, 'limit');
  await failureCase({ messages(data, direction) { if (direction === 'child' && data.kind === 'progress' && data.payload === 'assets') { data.kind = 'result'; data.payload = { status: 'complete', profile: {} }; } return [{ data }]; } }, 'engine');
  await failureCase({ messages(data, direction) { if (direction === 'parent') data.channel = '00000000-0000-4000-8000-000000000000'; return [{ data }]; } }, 'load');
  const hidden = fixture({ fontsPending: true });
  try { await assert.rejects(hidden.w.BhrantiRender.run(job, { axeUrl, onProgress(state) { if (state.phase === 'assets') hidden.w.dispatchEvent(new hidden.w.Event('pagehide')); } }), { name: 'AbortError' }); checks++; equal(hidden.scans.length, 0, 'Parent pagehide stops child work before the engine.'); equal(hidden.timers.size, 0, 'Pagehide removes parent and child deadlines.'); equal(hidden.listenerCount(), 0, 'Pagehide removes parent and child protocol listeners.'); check(hidden.frames.every((frame) => !frame.isConnected), 'Pagehide removes the owned frame.'); } finally { hidden.close(); }
  console.log('PASS ' + checks + ' rendered-worker DOM/negative checks; mocked geometry/engine, no network or actual browser WCAG claim.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
