/** Real launcher + installed WordPress React/element; native right-toolbar Fill glue only. No HTTP, AI, WP load or DB. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
let JSDOM, VirtualConsole;
try { ({ JSDOM, VirtualConsole } = require('jsdom')); }
catch { ({ JSDOM, VirtualConsole } = require(process.env.BHRANTI_TEST_JSDOM_PATH || '/Users/txsadhu/node_modules/jsdom')); }
const pluginRoot = process.env.BHRANTI_TEST_PLUGIN_ROOT || path.dirname(__dirname);
const wpRoot = process.env.BHRANTI_TEST_WP_ROOT || '/Users/txsadhu/Documents/WordPress/Plugins';
const source = fs.readFileSync(path.join(pluginRoot, 'assets/editor.js'), 'utf8');
const portalCSS = (fs.readFileSync(path.join(pluginRoot, 'assets/admin.css'), 'utf8').match(/\.bhranti-editor-launcher-floating(?:\s+\.bhranti-editor-launcher)?\s*\{[^}]*\}/g) || []).join('\n');
const bundles = ['vendor/react.js', 'vendor/react-dom.js', 'escape-html.js', 'element.js'].map((name) => ({ name, source: fs.readFileSync(path.join(wpRoot, 'wp-includes/js/dist', name), 'utf8') }));
const pause = () => new Promise((resolve) => setTimeout(resolve, 15));
let checks = 0, httpCalls = 0, currentCase = 'initialization';
function check(value, label) { assert.ok(value, label); checks++; }
function equal(actual, expected, label) { assert.equal(actual, expected, label); checks++; }
function rect(left = 130, top = 20, width = 98, height = 32) { return { x: left, y: top, left, top, width, height, right: left + width, bottom: top + height, toJSON() { return { left, top, width, height }; } }; }
function fixture(options = {}) {
  const errors = [], virtualConsole = new VirtualConsole(); virtualConsole.on('jsdomError', (error) => errors.push(error));
  const dom = new JSDOM('<!doctype html><html><head><style>body.is-fullscreen-mode #wpadminbar{display:none}</style></head><body><main id="editor" class="block-editor__container"><header id="native-header"><div class="editor-header__back"><button id="native-back" type="button">Native back</button></div><div class="editor-header__settings"><button id="native-other" type="button">Native action</button><span id="native-pinned-items"></span></div></header><div contenteditable="true" id="native-content">Saved fixture</div></main><div id="bhranti-app" hidden><textarea id="fixture-draft">Unsent fixture draft</textarea></div><div id="plugin-area"></div></body></html>', { url: 'https://bhranti.wp.local/wp-admin/post.php?post=99&action=edit', runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole });
  const w = dom.window, registrations = new Map(), listeners = new Map(), nativeSubscribers = new Set(), mutationObservers = new Set(), resizeObservers = new Set(), toggles = [];
  let root = null, header = w.document.getElementById('native-header'), bounds = rect(), readyEvents = 0, genericActivations = 0;
  let fullscreen = !!options.fullscreen;
  let preferences = { wide: options.wide !== false, labels: !!options.labels };
  const app = w.document.getElementById('bhranti-app'), editor = w.document.getElementById('editor');
  const portalStyle = w.document.createElement('style'); portalStyle.textContent = portalCSS; w.document.head.appendChild(portalStyle);
  const activeListeners = (prefix) => [...listeners].filter(([key]) => key.startsWith(prefix)).reduce((count, [, entries]) => count + entries.size, 0);
  function track(target, prefix, names) {
    const add = target.addEventListener.bind(target), remove = target.removeEventListener.bind(target);
    target.addEventListener = (name, callback, capture) => { if (names.includes(name)) { const key = prefix + name + ':' + !!(capture && (typeof capture === 'boolean' || capture.capture)); if (!listeners.has(key)) listeners.set(key, new Set()); listeners.get(key).add(callback); } return add(name, callback, capture); };
    target.removeEventListener = (name, callback, capture) => { if (names.includes(name)) { const key = prefix + name + ':' + !!(capture && (typeof capture === 'boolean' || capture.capture)); if (listeners.has(key)) listeners.get(key).delete(callback); } return remove(name, callback, capture); };
  }
  track(w.document, 'document:', ['bhranti:panel-state', 'bhranti:editor-launcher-position']); track(w, 'window:', ['resize', 'scroll']);
  const NativeObserver = w.MutationObserver;
  w.MutationObserver = class extends NativeObserver {
    constructor(callback) { super(callback); this.active = false; mutationObservers.add(this); }
    observe(...args) { this.active = true; return super.observe(...args); }
    disconnect() { this.active = false; return super.disconnect(); }
  };
  w.ResizeObserver = class {
    constructor(callback) { this.callback = callback; this.active = false; resizeObservers.add(this); }
    observe(node) { this.active = true; this.node = node; }
    unobserve(node) { if (this.node === node) { this.active = false; this.node = null; } }
    disconnect() { this.active = false; }
  };
  // JSDOM has no layout. Model only visibility/geometry, leaving real DOM, focus,
  // React scheduling, native event propagation, hooks and createPortal intact.
  w.HTMLElement.prototype.getBoundingClientRect = function() { return this.matches('.bhranti-editor-launcher-slot') ? bounds : rect(0, 0, 100, 32); };
  w.HTMLElement.prototype.getClientRects = function() {
    if (!this.isConnected) return [];
    for (let node = this; node && node.nodeType === 1; node = node.parentElement) { const style = w.getComputedStyle(node); if (node.hidden || style.display === 'none' || ['hidden', 'collapse'].includes(style.visibility)) return []; }
    return [this.getBoundingClientRect()];
  };
  w.fetch = () => { httpCalls++; throw new Error('Launcher fixture cannot fetch.'); };
  w.XMLHttpRequest = function() { httpCalls++; throw new Error('Launcher fixture cannot use XMLHttpRequest.'); };
  w.open = () => { throw new Error('Launcher fixture cannot navigate.'); };
  function evaluate(text, name) {
    let error = null; const capture = (event) => { error = event.error; event.preventDefault(); }; w.addEventListener('error', capture);
    const script = w.document.createElement('script'); script.textContent = text + '\n//# sourceURL=' + name; w.document.head.appendChild(script); w.removeEventListener('error', capture); if (error) throw error;
  }
  bundles.forEach((bundle) => evaluate(bundle.source, bundle.name));
  const element = w.wp.element, h = element.createElement, Pinned = element.createContext(null);
  // Native SlotFill placement and control glue, with the installed React portal.
  function Fill({ name, children }) { assert.equal(name, 'PinnedItems/core', 'Use the existing native right-toolbar pinned slot'); const node = element.useContext(Pinned); return node ? element.createPortal(children, node) : null; }
  function Button({ icon, label, children, showTooltip, tooltipPosition, size, className, ...props }) { return h('button', { type: 'button', ...props, className: 'components-button ' + (className || ''), 'aria-label': label, title: showTooltip ? label : undefined }, icon, children); }
  w.wp.editor = { PluginSidebar: () => { throw new Error('The opener must not mount or replace native sidebars.'); } };
  w.wp.components = { Button, Fill };
  w.wp.plugins = { getPlugin: (name) => registrations.get(name), registerPlugin: (name, specification) => { assert.ok(!registrations.has(name), 'No duplicate native plugin registration'); registrations.set(name, specification); } };
  const subscribe = (callback) => { nativeSubscribers.add(callback); return () => nativeSubscribers.delete(callback); };
  const select = (store) => { assert.equal(store, 'core/preferences'); return { get: (scope, key) => { assert.equal(scope, 'core'); assert.equal(key, 'showIconLabels'); return preferences.labels; } }; };
  w.wp.compose = { useViewportMatch: (breakpoint) => { assert.equal(breakpoint, 'large'); return element.useSyncExternalStore(subscribe, () => preferences.wide); } };
  w.wp.data = { useSelect: (callback) => element.useSyncExternalStore(subscribe, () => callback(select)) };
  w.wp.i18n = { __: (text) => text };
  w.bhrantiSettings = { isAdmin: true, context: { screen: 'page', post_type: 'page', post_id: 99 }, ...(options.settings || {}) };
  if (fullscreen) w.document.body.classList.add('is-fullscreen-mode');
  function nativePinnedSlot() { if (!header) return; const pinned = header.querySelector('[id^="native-pinned-items"]'); if (preferences.labels && !preferences.wide) { if (pinned) pinned.remove(); } else if (!pinned) { const next = w.document.createElement('span'); next.id = 'native-pinned-items'; header.querySelector('.editor-header__settings').appendChild(next); } }
  nativePinnedSlot(); if (options.noHeader) { header.remove(); header = null; }
  function createBar() { const bar = w.document.createElement('div'); bar.id = 'wpadminbar'; bar.innerHTML = '<div id="wp-admin-bar-bhranti"><a href="#">Bhranti</a></div>'; w.document.body.prepend(bar); return bar; }
  if (options.bar !== 'absent') { const bar = createBar(); if (options.bar === 'hidden') bar.hidden = true; }
  if (options.noRoot) app.remove();
  if (options.missing) delete w.wp[options.missing];
  if (options.noFill) delete w.wp.components.Fill;
  if (options.noSidebar) delete w.wp.editor.PluginSidebar;
  if (options.duplicate) registrations.set('bhranti-editor-launcher', { render: () => null });
  w.document.addEventListener('bhranti:editor-launcher-ready', () => readyEvents++);
  // Observe the actual React onClick's document event, without adding a second
  // panel controller. The actual admin.js bridge has its own isolated suite.
  w.document.addEventListener('bhranti:editor-launcher-toggle', (event) => toggles.push({ trigger: event.detail.trigger, target: event.target }));
  w.document.addEventListener('click', (event) => { if (event.target.closest && event.target.closest('[data-bhranti-open]')) genericActivations++; });
  evaluate(source, 'bhranti/assets/editor.js');
  function Harness() { const plugin = registrations.get('bhranti-editor-launcher'); return h(Pinned.Provider, { value: header && header.querySelector('[id^="native-pinned-items"]') }, h(plugin.render)); }
  async function settle() { await pause(); await Promise.resolve(); if (errors.length) throw errors[0]; }
  async function mount() { assert.ok(registrations.has('bhranti-editor-launcher')); root = element.createRoot(w.document.getElementById('plugin-area')); root.render(h(Harness)); await settle(); }
  async function replaceHeader() { const next = w.document.createElement('header'); next.id = 'native-header'; next.innerHTML = '<div class="editor-header__back"><button id="native-back-remounted" type="button">Native remounted back</button></div><div class="editor-header__settings"><button id="native-other-remounted" type="button">Native remounted action</button><span id="native-pinned-items-remounted"></span></div>'; if (header) header.replaceWith(next); else editor.prepend(next); header = next; nativePinnedSlot(); root.render(h(Harness)); await settle(); return next; }
  return { w, dom, app, editor, registrations, mutationObservers, resizeObservers, nativeSubscribers, toggles, createBar, settle, mount,
    buttons: () => [...w.document.querySelectorAll('[data-bhranti-editor-open]')], host: () => w.document.querySelector('.bhranti-editor-launcher-floating'), ready: () => readyEvents, activations: () => toggles.length, genericActivations: () => genericActivations, activeListeners,
    async panel(open, controlled = false) { app.hidden = !open; editor.toggleAttribute('inert', controlled); let overlay = w.document.querySelector('.bhranti-editor-control'); if (controlled && !overlay) { overlay = w.document.createElement('div'); overlay.className = 'bhranti-editor-control'; w.document.body.appendChild(overlay); } else if (!controlled && overlay) overlay.remove(); const anchor = w.document.querySelector('#wp-admin-bar-bhranti > a'); const adminBarAvailable = !!(anchor && anchor.getClientRects().length && !anchor.closest('[hidden], [inert], [aria-hidden="true"]')); w.document.dispatchEvent(new w.CustomEvent('bhranti:panel-state', { detail: { open, adminBarAvailable, controlled } })); await settle(); },
    async features(next) { if (Object.hasOwn(next, 'fullscreen')) { fullscreen = next.fullscreen; w.document.body.classList.toggle('is-fullscreen-mode', fullscreen); } preferences = { ...preferences, ...next }; nativePinnedSlot(); nativeSubscribers.forEach((callback) => callback()); root.render(h(Harness)); await settle(); },
    remountHeader: replaceHeader,
    relocate(next) { bounds = next; },
    async position(next, event = 'resize') { bounds = next; w.dispatchEvent(new w.Event(event)); await settle(); },
    async unmount() { if (root) { root.unmount(); root = null; } await settle(); },
    async close() { if (root) { root.unmount(); root = null; } await settle(); dom.window.close(); },
    reevaluate() { evaluate(source, 'bhranti/assets/editor.js:duplicate'); },
  };
}
async function until(page, predicate, label) { for (let attempt = 0; attempt < 30; attempt++) { await page.settle(); if (predicate()) return; } assert.fail(label); }
function oneButton(page, label) { equal(page.buttons().length, 1, label); return page.buttons()[0]; }

async function testRegistrationAndNativeState() {
  const page = fixture({ bar: 'absent' });
  try {
    equal(page.registrations.size, 1, 'Register exactly one native editor plugin'); await page.mount();
    const button = oneButton(page, 'Missing admin bar supplies one native toolbar opener'); check(page.w.document.querySelector('.editor-header__settings').contains(button), 'Idle opener uses the native right-toolbar Fill'); check(!page.w.document.querySelector('.editor-header__back').contains(button), 'Opener does not replace the native left/back control');
    equal(button.getAttribute('aria-label'), 'Bhranti', 'Native Button retains its accessible name'); equal(button.getAttribute('aria-controls'), 'bhranti-app', 'Opener controls the existing panel'); equal(button.getAttribute('aria-haspopup'), 'dialog', 'Opener identifies the dialog'); equal(button.getAttribute('aria-expanded'), 'false', 'Closed panel has collapsed state'); equal(button.getAttribute('aria-pressed'), 'false', 'Closed panel has unpressed state'); check(!button.hasAttribute('data-bhranti-open'), 'Native opener avoids the generic delegated click path'); equal(button.type, 'button', 'Native Button does not submit a form');
    equal(button.querySelector('svg').getAttribute('aria-hidden'), 'true', 'Decorative icon stays out of the accessible name'); check(page.ready() > 0, 'Mounted native launcher emits its ready bridge event');
    const other = page.w.document.getElementById('native-other'); other.focus(); await page.panel(true); equal(oneButton(page, 'Opening state never duplicates an opener'), button, 'Normal panel state retains the same native button'); equal(button.getAttribute('aria-expanded'), 'true', 'Panel-state event expands the opener'); equal(button.getAttribute('aria-pressed'), 'true', 'Panel-state event presses the opener'); equal(page.w.document.activeElement, other, 'Launcher state updates do not steal native toolbar focus');
    await page.panel(false); equal(button.getAttribute('aria-expanded'), 'false', 'Closing state collapses the opener'); equal(page.w.document.getElementById('fixture-draft').value, 'Unsent fixture draft', 'Launcher state never changes the draft');
    page.reevaluate(); equal(page.registrations.size, 1, 'Reevaluation cannot register a second plugin'); equal(page.w.document.querySelectorAll('.bhranti-editor-launcher-floating').length, 1, 'Reevaluation cannot create a second floating host'); oneButton(page, 'Reevaluation retains exactly one actionable opener');
  } finally { await page.close(); }
}
async function testAdminBarVisibilityAndFullscreen() {
  const page = fixture();
  try {
    await page.mount(); equal(page.buttons().length, 0, 'Visible WordPress admin bar suppresses fallback'); equal(page.w.document.querySelectorAll('.editor-header__back button').length, 1, 'Suppressed Fill preserves the single existing native back control');
    equal(page.w.getComputedStyle(page.host()).pointerEvents, 'none', 'Empty retained host cannot intercept native toolbar input by its CSS contract');
    const bar = page.w.document.getElementById('wpadminbar'); bar.hidden = true; await until(page, () => page.buttons().length === 1, 'Hiding the admin bar must expose fallback'); oneButton(page, 'Hidden admin bar has one fallback');
    bar.hidden = false; await until(page, () => page.buttons().length === 0, 'Restored admin bar must suppress fallback');
    await page.features({ fullscreen: true }); await until(page, () => page.buttons().length === 1, 'Native fullscreen CSS must expose fallback'); oneButton(page, 'Fullscreen has one fallback');
    await page.features({ fullscreen: false }); await until(page, () => page.buttons().length === 0, 'Leaving fullscreen must restore native admin-bar entry'); check(page.w.document.getElementById('native-other'), 'Fullscreen transitions preserve unrelated native toolbar controls');
    const anchor = bar.querySelector('a'); anchor.style.visibility = 'hidden'; await until(page, () => page.buttons().length === 1, 'Hidden descendant opener must expose fallback'); anchor.style.visibility = 'visible'; await until(page, () => page.buttons().length === 0, 'Shown descendant opener must suppress fallback');
  } finally { await page.close(); }
}
async function testLateAndReplacedAdminBars() {
  for (const initial of ['absent', 'visible']) {
    const page = fixture({ bar: initial === 'absent' ? 'absent' : undefined });
    try {
      await page.mount(); const old = page.w.document.getElementById('wpadminbar'); if (old) old.remove(); const bar = page.createBar(); await until(page, () => page.buttons().length === 0, 'New native admin bar must suppress fallback');
      bar.style.display = 'none'; await until(page, () => page.buttons().length === 1, 'A ' + initial + ' initial bar must observe later replacement visibility'); oneButton(page, 'Late/replaced hidden admin bar has one fallback');
      bar.style.display = ''; await until(page, () => page.buttons().length === 0, 'Showing a late/replaced admin bar must suppress fallback'); bar.querySelector('a').hidden = true; await until(page, () => page.buttons().length === 1, 'Late/replaced bar observes descendant visibility'); bar.querySelector('a').hidden = false; await until(page, () => page.buttons().length === 0, 'Late/replaced bar observes descendant recovery');
      if (old) { old.hidden = true; await page.settle(); equal(page.buttons().length, 0, 'Detached former admin bar cannot control current fallback state'); }
    } finally { await page.close(); }
  }
}
async function testNativeControlsAndToolbarRemount() {
  const page = fixture({ bar: 'absent' });
  try {
    const back = page.w.document.getElementById('native-back'), other = page.w.document.getElementById('native-other'), before = [back.outerHTML, other.outerHTML]; await page.mount();
    for (const fullscreen of [true, false, true]) { await page.features({ fullscreen }); oneButton(page, 'Toolbar transitions retain one Bhranti opener'); equal(page.w.document.getElementById('native-back'), back, 'Launcher never replaces the existing native back control'); equal(page.w.document.getElementById('native-other'), other, 'Launcher never replaces the existing right-toolbar action'); assert.deepEqual([back.outerHTML, other.outerHTML], before); checks++; }
    const remounted = await page.remountHeader(); const button = oneButton(page, 'Native toolbar remount has one Bhranti opener'); check(remounted.querySelector('.editor-header__settings').contains(button), 'Remounted Fill uses the current right toolbar'); equal(remounted.querySelectorAll('.editor-header__back button').length, 1, 'Toolbar remount preserves the ordinary native back control'); check(page.w.document.getElementById('native-other-remounted'), 'Toolbar remount retains its ordinary right action'); equal(page.w.document.querySelectorAll('.bhranti-editor-launcher-floating').length, 1, 'Toolbar remount does not allocate a second host');
    await page.panel(true, true); const again = await page.remountHeader(); check(!again.contains(oneButton(page, 'Controlled toolbar remount retains one outside opener')), 'Controlled opener stays portalled across toolbar remount');
  } finally { await page.close(); }
}
async function testExplicitReactToggleExactlyOnce() {
  const page = fixture({ bar: 'absent' });
  try {
    await page.mount(); const button = oneButton(page, 'React activation fixture has one right-toolbar opener');
    const targets = [button, button.querySelector('.bhranti-editor-launcher-label'), button.querySelector('svg'), button.querySelector('svg path')];
    for (let index = 0; index < targets.length; index++) { targets[index].dispatchEvent(new page.w.MouseEvent('click', { bubbles: true, cancelable: true })); equal(page.activations(), index + 1, 'Button/label/SVG/path activation emits exactly one explicit toggle'); equal(page.toggles[index].trigger, button, 'Explicit toggle captures the real React currentTarget button'); equal(page.toggles[index].target, page.w.document, 'Explicit toggle dispatches on the document bridge'); equal(page.genericActivations(), 0, 'React activation cannot also invoke generic delegated opening'); }
    page.w.document.getElementById('native-back').click(); page.w.document.getElementById('native-other').click(); equal(page.activations(), targets.length, 'Unrelated native toolbar actions cannot toggle Bhranti');
    await page.panel(true, true); const floating = oneButton(page, 'Controlled activation fixture has one portalled opener'); floating.querySelector('svg path').dispatchEvent(new page.w.MouseEvent('click', { bubbles: true, cancelable: true })); equal(page.activations(), targets.length + 1, 'Real React portal nested SVG activation emits exactly one toggle'); equal(page.toggles.at(-1).trigger, floating, 'Portalled toggle passes the current outside-inert button'); equal(page.genericActivations(), 0, 'Portalled toggle cannot also invoke generic delegated opening');
  } finally { await page.close(); }
}
async function testVisibleAdminBarDuringControl() {
  for (const preferences of [{ wide: true, labels: false }, { wide: false, labels: true }]) {
    const page = fixture(preferences);
    try {
      await page.mount(); equal(page.buttons().length, 0, 'Available admin bar suppresses only the uncontrolled fallback');
      const bar = page.w.document.querySelector('#wp-admin-bar-bhranti > a'); check(bar.getClientRects().length, 'Control fixture retains a visible native admin-bar opener');
      await page.panel(true, true); let button = oneButton(page, 'Visible admin bar still supplies one controlled toolbar opener'); check(page.host().contains(button) && !button.closest('[inert]'), 'Controlled opener stays accessible in the outside body portal'); equal(button.getAttribute('aria-expanded'), 'true', 'Visible-bar controlled opener reflects the open panel');
      await page.panel(false, true); button = oneButton(page, 'Closing in-flight control retains its visible-bar opener'); equal(button.getAttribute('aria-expanded'), 'false', 'Closed controlled opener reflects collapsed panel'); button.querySelector('svg path').dispatchEvent(new page.w.MouseEvent('click', { bubbles: true, cancelable: true })); equal(page.activations(), 1, 'Closed visible-bar controlled opener emits one explicit toggle'); equal(page.toggles[0].trigger, button, 'Closed controlled toggle identifies the current outside-inert button'); check(page.editor.hasAttribute('inert'), 'Reopen activation preserves native editor protection');
      await page.panel(false, false); equal(page.buttons().length, 0, 'Action handover suppresses redundant opener with the visible bar'); equal(page.host().children.length, 0, 'Visible-bar handover leaves no actionable floating control'); equal(page.w.document.querySelectorAll('.bhranti-editor-launcher-fallback').length, 0, 'Visible-bar handover removes any owned small-label mount');
    } finally { await page.close(); }
  }
}
async function testNarrowIconLabelNativeSlotOmission() {
  const page = fixture({ bar: 'absent', wide: false, labels: true });
  try {
    await page.mount(); check(!page.w.document.querySelector('[id^="native-pinned-items"]'), 'Native fixture omits the pinned slot only for narrow icon labels');
    let button = oneButton(page, 'Omitted native pinned slot still has one Bhranti opener'); const mount = page.w.document.querySelector('.bhranti-editor-launcher-fallback'); check(mount && page.w.document.querySelector('.editor-header__settings').contains(mount), 'Only owned fallback mounts beside existing native right controls'); check(mount.contains(button), 'Real React portal renders the small-label opener in its owned mount'); equal(page.w.document.querySelectorAll('.bhranti-editor-launcher-fallback').length, 1, 'Narrow labels allocate one owned mount');
    const back = page.w.document.getElementById('native-back'), other = page.w.document.getElementById('native-other'); await page.panel(true, true); button = oneButton(page, 'Small-label control preserves one outside opener'); check(page.host().contains(button) && !button.closest('[inert]'), 'Small-label controlled button moves outside inert'); equal(page.w.document.getElementById('native-back'), back, 'Small-label fallback preserves the native back control'); equal(page.w.document.getElementById('native-other'), other, 'Small-label fallback preserves native right controls');
    page.relocate(rect(330, 72, 98, 32)); const currentHeader = await page.remountHeader(); await until(page, () => currentHeader.contains(mount), 'Owned fallback mount must follow native header replacement'); equal(page.w.document.querySelectorAll('.bhranti-editor-launcher-fallback').length, 1, 'Header replacement reuses one owned small-label mount'); oneButton(page, 'Header replacement cannot duplicate portalled small-label opener');
    // Same-size relocation need not notify native ResizeObserver; the reattach
    // must position itself without requiring a viewport resize/scroll event.
    equal(page.host().style.left, '330px', 'Controlled fallback follows header relocation without viewport events'); equal(page.host().style.top, '72px', 'Controlled fallback follows the new native header vertical position');
    await page.features({ wide: true }); equal(page.w.document.querySelectorAll('.bhranti-editor-launcher-fallback').length, 0, 'Large viewport releases the owned omitted-slot mount'); check(page.host().contains(oneButton(page, 'Large viewport still retains one controlled opener')), 'Large viewport preserves outside-inert controlled opener');
    await page.panel(false, false); check(page.w.document.querySelector('[id^="native-pinned-items"]').contains(oneButton(page, 'Normal pinned slot receives opener after handover')), 'Normal native Fill regains its opener');
    await page.features({ wide: false, labels: false }); equal(page.w.document.querySelectorAll('.bhranti-editor-launcher-fallback').length, 0, 'Narrow icon-only toolbar uses the ordinary native Fill'); oneButton(page, 'Narrow icon-only toolbar retains one opener');
    await page.features({ labels: true }); oneButton(page, 'Enabling narrow icon labels returns one owned fallback'); equal(page.w.document.querySelectorAll('.bhranti-editor-launcher-fallback').length, 1, 'Icon-label preference transitions recreate only one owned mount'); await page.unmount(); equal(page.w.document.querySelectorAll('.bhranti-editor-launcher-fallback').length, 0, 'Unmount removes the owned omitted-slot mount'); equal(page.nativeSubscribers.size, 0, 'Unmount releases public preference and viewport subscriptions'); check([...page.mutationObservers].every((observer) => !observer.active), 'Unmount disconnects fallback/header observers');
  } finally { await page.close(); }
  const late = fixture({ bar: 'absent', wide: false, labels: true, noHeader: true });
  try { await late.mount(); equal(late.buttons().length, 0, 'Missing native header does not create an unanchored opener'); equal(late.w.document.querySelectorAll('.bhranti-editor-launcher-fallback').length, 0, 'Missing header has no stray owned mount'); const header = await late.remountHeader(); await until(late, () => late.buttons().length === 1, 'Late native right toolbar must receive the owned small-label opener'); check(header.querySelector('.editor-header__settings').contains(oneButton(late, 'Late toolbar insertion supplies one opener')), 'Late native mount uses current right toolbar'); equal(late.w.document.querySelectorAll('.bhranti-editor-launcher-fallback').length, 1, 'Late native toolbar creates one owned fallback'); }
  finally { await late.close(); }
}
async function testControlledPortalAndGeometry() {
  const page = fixture({ bar: 'absent' });
  try {
    await page.mount(); const native = page.w.document.getElementById('native-other'); await page.panel(true, true);
    let button = oneButton(page, 'Controlled native editor has one opener'); check(page.host().contains(button), 'Real React portal renders opener in body host'); check(!button.closest('[inert]'), 'Controlled opener is outside all inert ancestors'); check(page.editor.hasAttribute('inert'), 'Portal does not release editor protection'); equal(page.w.document.getElementById('native-other'), native, 'Portal preserves the ordinary native toolbar control'); equal(page.w.document.querySelector('.bhranti-editor-launcher-slot').children.length, 0, 'Native Fill retains only its measured placeholder while controlled');
    equal(page.w.getComputedStyle(page.host()).position, 'fixed', 'Actual launcher CSS anchors host to native viewport geometry'); equal(page.w.getComputedStyle(page.host()).pointerEvents, 'none', 'Host passes through input around the opener'); equal(page.w.getComputedStyle(button).pointerEvents, 'auto', 'Portalled opener remains interactive by its actual CSS contract');
    equal(page.host().style.left, '130px', 'Portal follows native placeholder horizontal position'); equal(page.host().style.top, '20px', 'Portal follows native placeholder vertical position'); equal(page.host().style.width, '98px', 'Portal preserves native placeholder width');
    await page.position(rect(210, 42, 98, 32)); equal(page.host().style.left, '210px', 'Native resize updates portal position'); await page.position(rect(220, 52, 32, 32), 'scroll'); equal(page.host().style.top, '52px', 'Native scroll updates portal position'); equal(page.host().style.width, '32px', 'Compact viewport geometry follows the native placeholder');
    page.relocate(rect(260, 82, 32, 32)); page.w.document.dispatchEvent(new page.w.CustomEvent('bhranti:editor-launcher-position')); await page.settle(); equal(page.host().style.left, '260px', 'Overlay geometry event remeasures horizontal placement without resize or scroll'); equal(page.host().style.top, '82px', 'Overlay geometry event remeasures vertical placement'); equal(page.host().style.width, '32px', 'Same-size overlay movement preserves opener width'); equal(page.host().style.height, '32px', 'Same-size overlay movement preserves opener height'); equal(oneButton(page, 'Overlay movement retains exactly one opener'), button, 'Overlay movement retains the same real React button');
    button.focus(); button.click(); equal(page.activations(), 1, 'Portalled native Button emits exactly one explicit toggle'); equal(page.toggles[0].trigger, button, 'Portalled toggle identifies the current opener'); equal(page.w.document.activeElement, button, 'Activation leaves real focus on the outside-inert opener');
    await page.panel(false, true); button = oneButton(page, 'Closed panel with an in-flight control retains one accessible opener'); check(page.host().contains(button) && !button.closest('[inert]'), 'Closed in-flight action can reopen from outside inert'); equal(button.getAttribute('aria-expanded'), 'false', 'Closed controlled opener has current collapsed state'); button.click(); equal(page.activations(), 2, 'Closed controlled opener still reaches the bridge'); equal(page.w.document.getElementById('fixture-draft').value, 'Unsent fixture draft', 'Portal transitions preserve the exact unsent draft');
    await page.panel(true, false); check(page.w.document.getElementById('native-header').contains(oneButton(page, 'Action handover returns exactly one opener to native Fill')), 'Real portal handover returns to native header'); equal(page.host().children.length, 0, 'Action handover empties the floating host');
  } finally { await page.close(); }
}
async function testUnmountAndRemountCleanup() {
  const page = fixture({ bar: 'absent' });
  try {
    await page.mount(); await page.panel(true, true); equal(page.activeListeners('document:'), 2, 'Mount has one panel-state and one position subscription'); equal(page.nativeSubscribers.size, 2, 'Viewport and preferences use the real React external-store hooks');
    await page.unmount(); equal(page.buttons().length, 0, 'Unmount removes every actionable native opener'); equal(page.host(), null, 'Unmount removes the owned floating host'); equal(page.activeListeners('document:'), 0, 'Unmount releases panel-state listener'); equal(page.activeListeners('window:'), 0, 'Unmount releases resize and scroll listeners'); equal(page.nativeSubscribers.size, 0, 'Unmount releases public viewport/preference subscriptions'); check([...page.mutationObservers].every((observer) => !observer.active), 'Unmount disconnects every native DOM observer'); check([...page.resizeObservers].every((observer) => !observer.active), 'Unmount disconnects every native size observer');
    const ready = page.ready(); page.createBar().hidden = true; page.w.dispatchEvent(new page.w.Event('resize')); await page.settle(); equal(page.ready(), ready, 'Unmounted launcher does not emit or remount itself');
    await page.mount(); oneButton(page, 'Native plugin remount recreates one opener'); equal(page.w.document.querySelectorAll('.bhranti-editor-launcher-floating').length, 1, 'Native plugin remount reattaches exactly one body host'); equal(page.activeListeners('document:'), 2, 'Native plugin remount restores one panel-state and one position listener');
  } finally { await page.close(); }
}
async function testGuards() {
  for (const options of [{ noRoot: true }, { settings: { isAdmin: false } }, { settings: { isAdmin: true, context: { post_type: 'attachment' } } }, { settings: { isAdmin: true, context: { post_type: 'wp_template' } } }, { settings: { isAdmin: true, context: {} } }, ...['editor', 'element', 'components', 'plugins', 'compose', 'data'].map((missing) => ({ missing })), { noFill: true }, { noSidebar: true }, { duplicate: true }]) {
    const page = fixture(options);
    try { equal(page.registrations.size, options.duplicate ? 1 : 0, 'Unsupported/front-end/dependency/duplicate guard does not register a launcher: ' + JSON.stringify(options)); equal(page.host(), null, 'Guarded module creates no body host'); equal(page.buttons().length, 0, 'Guarded module creates no actionable opener'); equal(page.activeListeners('document:') + page.activeListeners('window:'), 0, 'Guarded module creates no subscriptions'); }
    finally { await page.close(); }
  }
}
async function main() {
  for (const test of [testRegistrationAndNativeState, testAdminBarVisibilityAndFullscreen, testLateAndReplacedAdminBars, testNativeControlsAndToolbarRemount, testExplicitReactToggleExactlyOnce, testVisibleAdminBarDuringControl, testNarrowIconLabelNativeSlotOmission, testControlledPortalAndGeometry, testUnmountAndRemountCleanup, testGuards]) { currentCase = test.name; if (process.env.BHRANTI_TEST_EDITOR_TRACE === '1') console.log('CHECK ' + currentCase); await test(); }
  equal(httpCalls, 0, 'All launcher checks remain offline'); console.log('PASS ' + checks + ' isolated native editor launcher checks; real installed React hooks/portals, no HTTP, AI, WordPress load or DB access');
}
const watchdog = setTimeout(() => { console.error('FAIL: launcher fixture did not complete ' + currentCase); process.exitCode = 1; }, 15000);
main().catch((error) => { console.error(error); process.exitCode = 1; }).finally(() => clearTimeout(watchdog));
