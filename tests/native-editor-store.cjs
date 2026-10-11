/** Actual installed WordPress bundles and reducers, in an offline JSDOM registry. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
let JSDOM, VirtualConsole;
try { ({ JSDOM, VirtualConsole } = require('jsdom')); }
catch { ({ JSDOM, VirtualConsole } = require(process.env.BHRANTI_TEST_JSDOM_PATH || '/Users/txsadhu/node_modules/jsdom')); }
const wpRoot = process.env.BHRANTI_TEST_WP_ROOT || '/Users/txsadhu/Documents/WordPress/Plugins';
const packagesFile = path.join(wpRoot, 'wp-includes/assets/script-loader-packages.php');
const packages = fs.readFileSync(packagesFile, 'utf8');
const dependencies = new Map();
for (const match of packages.matchAll(/\t'([^']+\.js)' => array\(\n\t\t'dependencies' => array\(([\s\S]*?)\n\t\t\)/g)) {
  dependencies.set('wp-' + match[1].slice(0, -3), [...match[2].matchAll(/'([^']+)'/g)].map((item) => item[1]));
}
assert.ok(dependencies.has('wp-core-data'), 'Read the installed native package dependency manifest');
const vendorDependencies = new Map([['react', []], ['react-dom', ['react']], ['react-jsx-runtime', ['react']], ['moment', []], ['lodash', []], ['regenerator-runtime', []], ['wp-polyfill', []]]);
let unsupportedStylesheets = 0;
const virtualConsole = new VirtualConsole();
virtualConsole.on('jsdomError', (error) => { if (error.message === 'Could not parse CSS stylesheet') unsupportedStylesheets++; else throw error; });
const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://bhranti.wp.local/wp-admin/post.php?post=99001&action=edit', runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole });
const w = dom.window;
const loaded = [], visiting = new Set(), seen = new Set();
let httpCalls = 0, checks = 0;
w.fetch = () => { httpCalls++; throw new Error('The native editor-store fixture must not make HTTP requests.'); };
w.XMLHttpRequest = function() { httpCalls++; throw new Error('The native editor-store fixture must not use XMLHttpRequest.'); };
w.matchMedia = (media) => ({ media, matches: false, onchange: null, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {}, dispatchEvent() { return true; } });
w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
w.requestIdleCallback = (callback) => w.setTimeout(() => callback({ didTimeout: false, timeRemaining: () => 50 }), 0);
w.cancelIdleCallback = w.clearTimeout.bind(w);
w.TextEncoder = TextEncoder; w.TextDecoder = TextDecoder;
function load(handle) {
  if (seen.has(handle)) return;
  assert.ok(!visiting.has(handle), 'Native dependency graph is acyclic at ' + handle); visiting.add(handle);
  const vendor = vendorDependencies.has(handle);
  const deps = vendor ? vendorDependencies.get(handle) : dependencies.get(handle);
  assert.ok(deps, 'Use a registered native dependency: ' + handle);
  deps.forEach(load);
  const filename = vendor ? path.join(wpRoot, 'wp-includes/js/dist/vendor', handle + '.js') : path.join(wpRoot, 'wp-includes/js/dist', handle.slice(3) + '.js');
  // Real script elements preserve native top-level globals such as the strict
  // vendor ReactJSXRuntime binding; eval would incorrectly hide that binding.
  let evaluationError = null;
  const capture = (event) => { evaluationError = event.error; event.preventDefault(); };
  w.addEventListener('error', capture);
  const script = w.document.createElement('script'); script.textContent = fs.readFileSync(filename, 'utf8') + '\n//# sourceURL=' + filename; w.document.head.appendChild(script);
  w.removeEventListener('error', capture);
  if (evaluationError) throw evaluationError;
  if (handle === 'lodash') w.lodash = w._;
  seen.add(handle); visiting.delete(handle); loaded.push(handle);
}
function equal(actual, expected, label) { assert.equal(actual, expected, label); checks++; }
function check(value, label) { assert.ok(value, label); checks++; }
function dto(text, modified = '2026-10-09T00:00:00', id = 99001, type = 'page') {
  return { id, type, status: 'draft', title: { raw: 'Native store fixture', rendered: 'Native store fixture' }, content: { raw: '<!-- wp:paragraph --><p>' + text + '</p><!-- /wp:paragraph -->', rendered: '<p>' + text + '</p>' }, excerpt: { raw: '', rendered: '' }, modified, modified_gmt: modified };
}
async function testMountedNativeBlockEditor() {
  // The real global registry supplies native parent stores (including keyboard
  // shortcuts) to the actual provider's block-editor subregistry.
  const registry = w.wp.data, actions = registry.dispatch(w.wp.coreData.store), selectors = registry.select(w.wp.coreData.store), id = 99003;
  w.wp.apiFetch.use(w.wp.apiFetch.createPreloadingMiddleware({ '/wp/v2/types?context=view': { body: { page: { name: 'Pages', slug: 'page', rest_base: 'pages', rest_namespace: 'wp/v2', taxonomies: [] }, post: { name: 'Posts', slug: 'post', rest_base: 'posts', rest_namespace: 'wp/v2', taxonomies: [] } }, headers: {} } }));
  await registry.resolveSelect(w.wp.coreData.store).getEntitiesConfig('postType');
  const before = { ...dto('Mounted native editor before Bhranti.', '2026-10-09T00:00:00', id), meta: {} };
  actions.receiveEntityRecords('postType', 'page', before);
  for (const selector of ['getEntityRecord', 'getRawEntityRecord', 'getEditedEntityRecord']) actions.finishResolution(selector, ['postType', 'page', id]);
  const previousBlocks = w.wp.blocks.parse(before.content.raw);
  actions.editEntityRecord('postType', 'page', id, { blocks: previousBlocks, selection: { selectionStart: { clientId: previousBlocks[0].clientId } } });
  equal(selectors.hasEditsForEntityRecord('postType', 'page', id), false, 'The actual mounted fixture begins with clean native transient blocks and selection');
  const container = w.document.createElement('div'); container.id = 'native-mounted-fixture'; w.document.body.appendChild(container);
  const calls = []; let renderedBlocks = null;
  function Harness() {
    const [blocks, onInput, onChange] = w.wp.coreData.useEntityBlockEditor('postType', 'page', { id }); renderedBlocks = blocks;
    return w.wp.element.createElement(w.wp.blockEditor.BlockEditorProvider, { value: blocks, settings: { allowedBlockTypes: true, hasFixedToolbar: true },
      onInput: (...args) => { calls.push('input'); return onInput(...args); }, onChange: (...args) => { calls.push('change'); return onChange(...args); } }, w.wp.element.createElement(w.wp.blockEditor.BlockList));
  }
  const root = w.wp.element.createRoot(container);
  const settle = () => new Promise((resolve) => w.setTimeout(resolve, 250));
  try {
    root.render(w.wp.element.createElement(Harness)); await settle();
    equal(renderedBlocks.length, 1, 'Actual mounted useEntityBlockEditor parses the first saved paragraph'); equal(renderedBlocks[0].name, 'core/paragraph', 'Actual native BlockList begins with the saved paragraph type'); check(container.textContent.includes('Mounted native editor before Bhranti.'), 'The native mounted BlockList renders the original saved text'); equal(selectors.hasEditsForEntityRecord('postType', 'page', id), false, 'Mounting actual native editor components leaves the saved entity clean'); equal(calls.length, 0, 'Initial native mount emits no user-change callback');
    const blocks = [
      w.wp.blocks.createBlock('core/paragraph', { content: 'Fresh canonical content received without refresh.' }),
      w.wp.blocks.createBlock('core/table', { body: [{ cells: [{ content: 'Coat color', tag: 'td' }, { content: 'Example', tag: 'td' }] }, { cells: [{ content: 'Orange', tag: 'td' }, { content: 'Tabby', tag: 'td' }] }] }),
      w.wp.blocks.createBlock('core/image', { url: 'https://bhranti.wp.local/wp-content/uploads/offline-fixture.png', alt: 'Offline native fixture', style: { border: { color: '#000000', width: '1px' } } }),
    ];
    const canonical = w.wp.blocks.serialize(blocks); check(canonical.includes('has-custom-border'), 'Native serialization supplies the image border class required by native validation');
    for (const [label, markup, expectedInvalid] of [
      ['canonical paragraph/table/image', canonical, 0],
      ['image with a missing border class', canonical.replace(' has-custom-border', ''), 1],
      ['canonical content after the invalid image', canonical, 0],
    ]) {
      const capture = selectors.getEntityRecordEdits('postType', 'page', id);
      const fresh = { ...before, content: { raw: markup, rendered: '' }, modified: '2026-10-09T00:02:00' };
      actions.receiveEntityRecords('postType', 'page', fresh, undefined, true, capture); await settle();
      equal(renderedBlocks.length, 3, 'Actual mounted hook receives all fresh blocks for ' + label); equal(renderedBlocks.map((block) => block.name).join(','), 'core/paragraph,core/table,core/image', 'Native mounted block types match ' + label);
      equal(renderedBlocks.filter((block) => !block.isValid).length, expectedInvalid, 'Native block validation classifies ' + label);
      equal(selectors.getRawEntityRecord('postType', 'page', id).content, markup, 'Mounted native effects preserve the exact saved raw content for ' + label); equal(selectors.getEditedEntityRecord('postType', 'page', id).content, markup, 'Mounted edited content remains canonical for ' + label);
      equal(selectors.hasEditsForEntityRecord('postType', 'page', id), false, 'Actual mounted BlockList receive stays clean for ' + label); equal(Object.keys(selectors.getEntityRecordNonTransientEdits('postType', 'page', id)).length, 0, 'Native mounted effects create no persistent edits for ' + label); equal(Object.keys(selectors.getEntityRecordEdits('postType', 'page', id) || {}).length, 0, 'Native mounted effects create no transient edit echo for ' + label); equal(calls.length, 0, 'Incoming saved content does not invoke native onInput/onChange for ' + label);
      check(container.textContent.includes('Fresh canonical content received without refresh.'), 'Actual mounted BlockList renders the freshly saved paragraph for ' + label); check(container.querySelector('table'), 'Actual mounted native table renders for ' + label);
      if (!expectedInvalid) check(container.querySelector('img[alt="Offline native fixture"]'), 'The actual native mounted image edit renders the saved image for ' + label);
    }
  } finally { root.unmount(); container.remove(); }
}
async function main() {
  load('wp-core-data');
  load('wp-block-library'); w.wp.blockLibrary.registerCoreBlocks();
  check(typeof w.wp.coreData.store === 'object', 'Actual installed core-data exports its store descriptor');
  const registry = w.wp.data.createRegistry(); registry.register(w.wp.coreData.store);
  const actions = registry.dispatch(w.wp.coreData.store), selectors = registry.select(w.wp.coreData.store);
  w.wp.apiFetch.use(w.wp.apiFetch.createPreloadingMiddleware({ '/wp/v2/types?context=view': { body: { page: { name: 'Pages', slug: 'page', rest_base: 'pages', rest_namespace: 'wp/v2', taxonomies: [] }, post: { name: 'Posts', slug: 'post', rest_base: 'posts', rest_namespace: 'wp/v2', taxonomies: [] } }, headers: {} } }));
  await registry.resolveSelect(w.wp.coreData.store).getEntitiesConfig('postType');
  const entity = selectors.getEntityConfig('postType', 'page');
  equal(entity.baseURL, '/wp/v2/pages', 'Actual native loader creates the page entity from the in-memory preload');
  check(typeof entity.transientEdits.blocks.read === 'function', 'Actual native page config supplies its own transient block parser');
  check(entity.rawAttributes.includes('content'), 'Actual native page config supplies its raw content projection');
  const before = dto('Before the completed Bhranti write.'); actions.receiveEntityRecords('postType', 'page', before);
  for (const selector of ['getEntityRecord', 'getRawEntityRecord', 'getEditedEntityRecord']) actions.finishResolution(selector, ['postType', 'page', 99001]);
  equal(selectors.getRawEntityRecord('postType', 'page', 99001).content, before.content.raw, 'Actual native raw saved entity starts with the old HTML');
  const originalRaw = selectors.getRawEntityRecord('postType', 'page', 99001);
  check(selectors.getRawEntityRecord('postType', 'page', 99001) === originalRaw, 'Actual native raw entity selectors keep a stable reference across repeated reads');
  equal(selectors.hasEditsForEntityRecord('postType', 'page', 99001), false, 'Actual native record begins clean');
  const staleBlocks = [{ name: 'core/paragraph', clientId: 'old-block', attributes: { content: 'Before the completed Bhranti write.' } }];
  actions.editEntityRecord('postType', 'page', 99001, { blocks: staleBlocks, selection: { start: { clientId: 'old-block' } } });
  check(selectors.getRawEntityRecord('postType', 'page', 99001) === originalRaw, 'Clean transient edits do not churn the actual native saved entity reference');
  const manual = dto('The user manually saved before the next Bhranti action.', '2026-10-09T00:00:30');
  actions.editEntityRecord('postType', 'page', 99001, { content: manual.content.raw });
  equal(selectors.hasEditsForEntityRecord('postType', 'page', 99001), true, 'Actual native user content edit marks the entity dirty before saving');
  actions.receiveEntityRecords('postType', 'page', manual, undefined, true, { content: manual.content.raw });
  equal(selectors.hasEditsForEntityRecord('postType', 'page', 99001), false, 'Actual native completed manual save clears its persisted content edit');
  equal(selectors.getRawEntityRecord('postType', 'page', 99001).content, manual.content.raw, 'Native manual save receives its canonical persisted content');
  const captured = selectors.getEntityRecordEdits('postType', 'page', 99001);
  equal(selectors.hasEditsForEntityRecord('postType', 'page', 99001), false, 'Actual native transient blocks and selection do not mark the record dirty');
  check(captured.blocks === staleBlocks, 'Actual native edit selector holds the old transient block reference');
  const after = dto('The newly committed Bhranti content.', '2026-10-09T00:01:00');
  actions.receiveEntityRecords('postType', 'page', after, undefined, true);
  check(selectors.getEntityRecordEdits('postType', 'page', 99001).blocks === staleBlocks, 'A bare native saved receive preserves old transient blocks and demonstrates why the captured clean sixth argument is needed');
  actions.receiveEntityRecords('postType', 'page', after, undefined, true, captured);
  equal(selectors.getRawEntityRecord('postType', 'page', 99001).content, after.content.raw, 'Actual native reducer receives the new raw saved HTML');
  equal(selectors.getEditedEntityRecord('postType', 'page', 99001).content, after.content.raw, 'Actual native edited entity exposes the newly saved content');
  equal(selectors.getEntityRecordEdits('postType', 'page', 99001)?.blocks, undefined, 'Actual native RECEIVE_ITEMS clears the matched captured old blocks');
  const reparsed = entity.transientEdits.blocks.read(selectors.getEntityRecord('postType', 'page', 99001));
  equal(reparsed[0].name, 'core/paragraph', 'Actual native entity rereads the saved content as a native paragraph block');
  equal(String(reparsed[0].attributes.content), 'The newly committed Bhranti content.', 'Actual native parser exposes the new paragraph text rather than the stale block');
  check(reparsed[0].clientId !== 'old-block', 'Native saved content is reparsed into a fresh block instead of retaining the old transient block');
  const serialized = w.wp.blocks.serialize(reparsed); check(serialized.includes('The newly committed Bhranti content.') && !serialized.includes('Before the completed Bhranti write.'), 'Actual native block serialization contains only the newly received saved paragraph');
  equal(selectors.hasEditsForEntityRecord('postType', 'page', 99001), false, 'Native saved-record receive leaves the document clean');
  actions.editEntityRecord('postType', 'page', 99001, { blocks: staleBlocks, selection: { start: { clientId: 'old-block' } } });
  const earlierCapture = selectors.getEntityRecordEdits('postType', 'page', 99001);
  const newerBlocks = [{ name: 'core/paragraph', clientId: 'new-local-block', attributes: { content: 'Newer local user block.' } }];
  actions.editEntityRecord('postType', 'page', 99001, { blocks: newerBlocks, content: 'Newer local unsaved user content.' });
  actions.receiveEntityRecords('postType', 'page', after, undefined, true, earlierCapture);
  check(selectors.getEntityRecordEdits('postType', 'page', 99001).blocks === newerBlocks, 'Actual native reducer preserves newer unmatched transient blocks after a stale captured receive');
  equal(selectors.getEditedEntityRecord('postType', 'page', 99001).content, 'Newer local unsaved user content.', 'Actual native reducer preserves newer unmatched user content edits');
  equal(selectors.hasEditsForEntityRecord('postType', 'page', 99001), true, 'Unmatched new user edits remain dirty rather than silently becoming saved');
  equal(selectors.getEntityRecordEdits('postType', 'page', 99001).selection, undefined, 'Matched old selection alone is cleared from the stale snapshot');
  const postBefore = dto('Old post content.', '2026-10-09T00:00:00', 99002, 'post'); actions.receiveEntityRecords('postType', 'post', postBefore);
  for (const selector of ['getEntityRecord', 'getRawEntityRecord', 'getEditedEntityRecord']) actions.finishResolution(selector, ['postType', 'post', 99002]);
  actions.editEntityRecord('postType', 'post', 99002, { blocks: staleBlocks }); const postCapture = selectors.getEntityRecordEdits('postType', 'post', 99002);
  const postAfter = dto('Fresh post content.', '2026-10-09T00:02:00', 99002, 'post'); actions.receiveEntityRecords('postType', 'post', postAfter, undefined, true, postCapture);
  equal(selectors.getEditedEntityRecord('postType', 'post', 99002).content, postAfter.content.raw, 'The actual native saved-record pattern also updates posts'); equal(selectors.hasEditsForEntityRecord('postType', 'post', 99002), false, 'Actual native post synchronization leaves the post clean');
  const postEntity = selectors.getEntityConfig('postType', 'post'); const postBlocks = postEntity.transientEdits.blocks.read(selectors.getEntityRecord('postType', 'post', 99002));
  equal(String(postBlocks[0].attributes.content), 'Fresh post content.', 'Native post entity transient block reader parses the fresh saved HTML');
  await testMountedNativeBlockEditor();
  equal(httpCalls, 0, 'Native registry tests have no HTTP traffic');
  console.log('PASS ' + checks + ' actual native WordPress store checks; ' + loaded.length + ' installed bundles; no HTTP, AI, PHP or DB access');
}
const watchdog = setTimeout(() => { console.error('FAIL: native editor-store fixture did not finish its asynchronous checks.'); process.exitCode = 1; dom.window.close(); }, 10000);
main().catch((error) => { console.error(error); process.exitCode = 1; }).finally(() => { clearTimeout(watchdog); dom.window.close(); });
