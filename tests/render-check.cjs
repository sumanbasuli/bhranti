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
const instrumented = source.replace(original, 'window.BhrantiRender = Object.freeze({ run, validateJob, frameIdentity, selector, observations, findings, nodeSummary, sameDocument, plain, bytes, VERSION, INTEGRITY, VIEWPORTS });');
const axeUrl = 'https://render.invalid/wp-content/plugins/bhranti/assets/vendor/axe-core/axe.js?ver=4.14.0';
const job = { id: 'd663dfd7-bc9d-4b7b-b801-79e188644212', run_id: 'e47e4df9-40cb-4577-b52f-d58394802a02', step: 7, post_id: 17, path: '/wp/v2/pages/17', url: 'https://render.invalid/fixture/?bhranti_render=9&bhranti_render_job=d663dfd7-bc9d-4b7b-b801-79e188644212', revision: 'a'.repeat(64), environment_revision: 'b'.repeat(64), marker: 'c'.repeat(64), expires: Math.floor(Date.now() / 1000) + 600, engine: 'axe-core', version: '4.14.0', viewports: [{ width: 1280, height: 900 }, { width: 390, height: 844 }, { width: 320, height: 844 }] };
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
function equal(actual, expected, message) { assert.deepEqual(actual, expected, message); checks++; }
const clone = (value) => JSON.parse(JSON.stringify(value));
function fixture(options = {}) {
  const dom = new JSDOM('<!doctype html><html><head></head><body><input value="parent-unsaved-private-value"><button>Keep focus</button></body></html>', { url: 'https://render.invalid/wp-admin/admin.php?page=bhranti', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window, frames = [], children = [], scripts = [], scans = [], scrolls = [];
  const timers = new Set();
  w.TextEncoder = TextEncoder;
  w.setTimeout = (callback, delay) => { let id; id = setTimeout(() => { timers.delete(id); callback(); }, delay >= 60000 ? 1000 : delay >= 2000 ? delay / 1000 : 0); timers.add(id); return id; };
  w.clearTimeout = (id) => { timers.delete(id); clearTimeout(id); };
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
    Object.defineProperty(element, 'contentWindow', { value: view, configurable: true });
    Object.defineProperty(element, 'contentDocument', { value: doc, configurable: true });
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
    if (!options.loadPending) queueMicrotask(() => { if (options.autoFocus) element.focus(); element.dispatchEvent(new w.Event('load')); });
    return element;
  };
  w.eval(instrumented);
  return { w, frames, children, scripts, scans, scrolls, timers, close() { timers.forEach(clearTimeout); children.forEach((child) => child.window.close()); dom.window.close(); } };
}
async function failureCase(options, code, request = job) {
  const f = fixture(options);
  try { const result = await f.w.BhrantiRender.run(request, { axeUrl }); equal(JSON.stringify(result), JSON.stringify({ status: 'failed', code }), 'A bounded failure has only its fixed code.'); check(f.frames.every((frame) => !frame.isConnected), 'Failed jobs remove every owned frame.'); } finally { f.close(); }
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
  for (const changed of [null, { ...job, post_id: 18 }, { ...job, revision: 'bad' }, { ...job, expires: 1 }, { ...job, step: 1001 }, { ...job, version: '4.13.0' }, { ...job, viewports: [{ width: 600, height: 900 }] }]) await failureCase({}, 'load', changed);
  for (const url of ['https://outside.invalid/fixture/', 'https://user:secret@render.invalid/fixture/', 'https://render.invalid/wp-admin/', job.url + '&rest_route=/wp/v2/users', job.url + '&bhranti_render_job=duplicate', job.url + '#fragment']) await failureCase({}, 'origin', { ...job, url });
  await failureCase({ missingMarker: true }, 'marker');
  await failureCase({ marker: '0'.repeat(64) }, 'marker');
  await failureCase({ redirect: 'https://outside.invalid/fixture/' }, 'origin');
  await failureCase({ changeMarker: true }, 'marker');
  await failureCase({ wrongWidth: true }, 'limit');
  await failureCase({ body: '<main><h1>Large</h1>' + '<span>Part</span>'.repeat(5001) + '</main>' }, 'limit');
  await failureCase({ wrongVersion: true }, 'engine');
  await failureCase({ engineError: true }, 'engine');
  await failureCase({ enginePending: true }, 'timeout');
  await failureCase({ loadPending: true }, 'load');
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
  console.log('PASS ' + checks + ' rendered-worker DOM/negative checks; mocked geometry/engine, no network or actual browser WCAG claim.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
