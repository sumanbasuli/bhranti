/** Isolated credential parity and administration checks; no WordPress/provider requests. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');
let JSDOM;
try { ({ JSDOM } = require('jsdom')); }
catch { ({ JSDOM } = require(process.env.BHRANTI_TEST_JSDOM_PATH || '/Users/txsadhu/node_modules/jsdom')); }
const root = process.env.BHRANTI_TEST_PLUGIN_ROOT || path.dirname(__dirname);
const helper = fs.readFileSync(path.join(root, 'assets/credentials.js'), 'utf8');
const admin = fs.readFileSync(path.join(root, 'assets/admin.js'), 'utf8');
const settings = fs.readFileSync(path.join(root, 'assets/settings.js'), 'utf8');
const fakeKey = 'sk-proj-fixtureOnly0123456789';
const cases = [
  ['Discuss password resets and API key rotation.', false],
  ['Add basic information about the clinic.', false],
  ['Please check basic accessibility.', false],
  ['Explain Bearer authentication.', false],
  ['password: [QAZFixtureBracketPassword47!]', true],
  ['password: {QAZFixtureBracketPassword47!}', true],
  ['password: <QAZFixtureBracketPassword47!>', true],
  ['<b>password</b>: QAZFixtureHtmlPassword47!', true],
  ['pass<strong>word</strong>: QAZFixtureHtmlPassword47!', true],
  ['<p>Password</p><p>QAZFixtureAdjacentPassword47!</p>', true],
  ['<div><strong>API key</strong></div><code>QAZFixtureNestedKey47!</code>', true],
  ['<span>Access token</span><span>QAZFixtureToken47!</span>', true],
  ["define( 'DB_PASSWORD', 'QAZFixtureWpConfigPassword47!' );", true],
  ["define( 'AUTH_KEY', 'QAZFixtureWpAuthKey1234567890AbCdEf' );", true],
  ['password%3DQAZFixtureEncodedPassword47! %FF', true],
  ['password%3DQAZFixtureEncodedPassword47! %C0%AF', true],
  [{ password: ['QAZFixtureArrayPassword47!'] }, true],
  [{ password: { value: 'QAZFixtureNestedPassword47!' } }, true],
  [{ password: { value: '[REDACTED]' } }, false],
  ['The access token is missing.', false],
  ['password: "<YOUR_API_KEY>"', false],
  ['<p>Password</p><p>[REDACTED]</p>', false],
  ['<p>API key</p><p>Required</p>', false],
  ['<p>How to reset password</p><p>Use Settings</p>', false],
  ['api_key = [redacted]', false],
  ['password = ***', false],
  ['password = "required"', false],
  ['Use https://example.test/page and support@example.test.', false],
  ['The reference is 123e4567-e89b-12d3-a456-426614174000.', false],
  [fakeKey, true],
  ['password = "synthetic fixture password"', true],
  ['API_KEY: fixture-value-1234', true],
  ['Authorization: Bearer fixtureToken123', true],
  ['https://fixture:fixturepass@example.test/', true],
  ['-----BEGIN PRIVATE KEY-----', true],
  ['eyJfixture012345.fixturepayload123.fixturesignature123', true],
  ['pa\u200bssword = syntheticFixture', true],
  ['password&colon; &quot;syntheticFixture&quot;', true],
  ['password&emsp;=&emsp;syntheticFixture', true],
  ['api&UnderBar;key &equals; syntheticFixture', true],
  ['password%3DsyntheticFixture %ZZ', true],
  ['%2570assword%253DsyntheticFixture', true],
  ['\\u0070assword\\u003d\\"syntheticFixture\\"', true],
  [{ selected_block: { content: 'password: syntheticFixture' } }, true],
  [{ api_key: 'syntheticFixture' }, true],
  [{ api_key: 'required' }, false],
  [{ password: true }, true],
  [{ ordinary: [null, 2, false, 'Security discussion'] }, false],
];
const php = spawnSync(process.env.BHRANTI_TEST_PHP || 'php', ['-r',
  'define("ABSPATH", __DIR__); function wp_json_encode($value) { return json_encode($value); } require $argv[1]; $cases = json_decode($argv[2], true); echo json_encode(array("rules" => Bhranti_Credentials::rules(), "results" => array_map(static function($case) { return Bhranti_Credentials::contains($case[0]); }, $cases)));',
  path.join(root, 'includes/class-bhranti-credentials.php'), JSON.stringify(cases)], { encoding: 'utf8' });
assert.equal(php.status, 0, 'Canonical PHP rule fixture must run successfully');
const canonical = JSON.parse(php.stdout);
const sandbox = { window: { bhrantiCredentialRules: canonical.rules } };
vm.runInNewContext(helper, sandbox);
for (let index = 0; index < cases.length; index++) {
  assert.equal(sandbox.window.bhrantiCredentials.contains(cases[index][0]), cases[index][1], 'Browser classification fixture ' + index);
  assert.equal(canonical.results[index], cases[index][1], 'PHP classification fixture ' + index);
}
const cyclic = {}; cyclic.child = cyclic;
assert.equal(sandbox.window.bhrantiCredentials.contains(cyclic), true, 'Unbounded structured content is refused');
assert.equal(sandbox.window.bhrantiCredentials.contains(fakeKey), true);
assert.equal(sandbox.window.bhrantiCredentials.contains(fakeKey), true, 'Global pattern state is reset between calls');

function session(id = 17) { return { id, agent_id: 'bhranti', messages: [{ role: 'assistant', content: 'Earlier response' }], events: [], run: { id: 'old-run-' + id, status: 'complete', step: 1, phase: '' } }; }
const workspace = { sessions: [{ id: 17, title: 'First chat' }, { id: 18, title: 'Second chat' }], agents: [{ id: 'bhranti', name: 'Bhranti' }], ai: { available: true } };
const marker = 'syncTrigger(); if (settings.autoOpen) openPanel();';
assert.equal(admin.split(marker).length, 2);
const instrumented = admin.replace(marker, 'window.review = { state, render, sendMessage, newChat, openSession, restoreDraft, rememberDraft, draftKey }; ' + marker);
function fixture(options = {}) {
  const dom = new JSDOM('<main id="wpbody-content"><p id="native-content">Ordinary native content</p></main><div id="bhranti-app"></div>', { url: 'https://fixture.test/wp-admin/edit.php', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window, requests = [];
  w.fetch = () => { throw new Error('Isolated tests must not access the network.'); };
  w.bhrantiSettings = { autoOpen: false, isAdmin: true, userId: 1, connectorsUrl: 'https://fixture.test/wp-admin/options-connectors.php', context: options.context || { screen: 'dashboard' } };
  w.bhrantiCredentialRules = canonical.rules;
  let handler = options.handler || (async (request) => {
    if (request.path.endsWith('/workspace')) return workspace;
    if (request.method === 'GET') return session(Number(request.path.split('/').pop()));
    if (request.path.endsWith('/sessions')) return session(19);
    if (request.path.endsWith('/messages')) { const saved = session(Number(request.path.split('/')[4])); saved.messages.push({ role: 'user', content: request.data.content }); return saved; }
    throw new Error('Unexpected isolated request');
  });
  w.wp = { i18n: { __: (text) => text }, apiFetch: async (request) => { requests.push(request); return handler(request); } };
  w.eval(helper); w.eval(instrumented);
  const api = w.review;
  Object.assign(api.state, { initialized: true, open: true, workspace, session: options.newChat ? null : session() });
  api.render();
  return { dom, w, api, requests, setHandler(next) { handler = next; }, input() { return w.document.getElementById('bhranti-composer'); }, warning() { return w.document.getElementById('bhranti-credential-warning'); }, edit(value) { const input = this.input(); input.value = value; input.dispatchEvent(new w.Event('input', { bubbles: true })); }, close() { w.dispatchEvent(new w.PageTransitionEvent('pagehide')); w.close(); } };
}
const flush = async () => { await Promise.resolve(); await new Promise((resolve) => setImmediate(resolve)); };
async function until(test) { for (let count = 0; count < 100; count++) { if (test()) return; await flush(); } throw new Error('Isolated fixture did not settle'); }
async function uiChecks() {
  let page = fixture({ newChat: true });
  try {
    page.edit(fakeKey); const input = page.input(); input.focus(); input.setSelectionRange(3, 8);
    await page.api.sendMessage();
    assert.equal(page.requests.length, 0, 'Credential preflight precedes every POST, including chat creation');
    assert.equal(page.api.state.busy, false); assert.equal(page.api.state.session, null); assert.equal(page.api.state.draft, fakeKey);
    assert.equal(page.input(), input); assert.equal(input.value, fakeKey); assert.equal(input.selectionStart, 3);
    assert.equal(page.w.sessionStorage.getItem(page.api.draftKey(null)), null, 'Recognized credentials remain only in RAM');
    assert.equal(page.warning().hidden, false); assert.equal(page.warning().getAttribute('role'), 'alert'); assert.equal(page.warning().textContent.includes(fakeKey), false);
    assert.equal(input.getAttribute('aria-invalid'), 'true'); assert.match(input.getAttribute('aria-describedby'), /bhranti-credential-warning/);
    page.edit('Safe replacement'); assert.equal(page.warning().hidden, true); assert.equal(input.hasAttribute('aria-invalid'), false); assert.equal(page.input(), input, 'Typing does not rebuild the textarea');
    assert.equal(page.w.sessionStorage.getItem(page.api.draftKey(null)), 'Safe replacement');
    page.edit(fakeKey); page.api.newChat(); assert.equal(page.api.state.draft, fakeKey, 'A RAM-only new-chat draft is retained');
  } finally { page.close(); }
  page = fixture();
  try {
    page.edit('Normal first-chat draft'); await page.api.openSession(18); page.edit(fakeKey); await page.api.openSession(17);
    assert.equal(page.api.state.draft, 'Normal first-chat draft'); await page.api.openSession(18); assert.equal(page.api.state.draft, fakeKey, 'Per-chat RAM drafts survive switching');
    assert.equal(page.w.sessionStorage.getItem(page.api.draftKey(18)), null);
    page.w.sessionStorage.setItem(page.api.draftKey(99), fakeKey); assert.equal(page.api.restoreDraft(99), fakeKey); assert.equal(page.w.sessionStorage.getItem(page.api.draftKey(99)), null, 'Previously stored sensitive drafts are removed from storage');
  } finally { page.close(); }
  for (const location of ['block', 'rendered']) {
    page = fixture({ context: location === 'block' ? { selected_block: { name: 'core/paragraph', content: 'password = syntheticFixture' } } : undefined });
    try {
      if (location === 'rendered') page.w.document.getElementById('native-content').textContent = 'password = syntheticFixture';
      page.edit('Please review the selected content'); await page.api.sendMessage();
      assert.equal(page.requests.length, 0, 'Attached ' + location + ' credentials block before POST'); assert.equal(page.api.state.session.messages.length, 1); assert.equal(page.warning().hidden, false);
    } finally { page.close(); }
  }
  page = fixture();
  try {
    page.w.document.getElementById('native-content').innerHTML = '<p>Password</p><p>QAZFixtureAdjacentPassword47!</p>';
    page.edit('Please review the page'); await page.api.sendMessage();
    assert.equal(page.requests.length, 0, 'Adjacent-element credentials in captured HTML block before POST');
    assert.equal(page.warning().hidden, false);
  } finally { page.close(); }
  page = fixture();
  try {
    page.api.state.screenshot = { mime_type: 'image/png', data: fakeKey }; page.edit('Use the attached screenshot'); await page.api.sendMessage();
    assert.equal(page.requests.filter((request) => request.path.endsWith('/messages')).length, 1, 'Screenshot bytes are not parsed as text');
    assert.equal(page.requests.find((request) => request.path.endsWith('/messages')).data.context.screenshot.data, fakeKey, 'Image upload content is preserved');
  } finally { page.close(); }
  page = fixture();
  try {
    page.w.bhrantiCredentials.contains = () => false;
    page.setHandler(async (request) => { if (request.path.endsWith('/workspace')) return workspace; if (request.method === 'GET') return session(); throw Object.assign(new Error('Never display a server payload'), { code: 'bhranti_credentials' }); });
    page.edit('  retained editable draft  '); await page.api.sendMessage();
    assert.equal(page.api.state.draft, '  retained editable draft  '); assert.equal(page.w.sessionStorage.getItem(page.api.draftKey(17)), null, 'Server-recognized credentials stay only in RAM even after a browser miss'); assert.equal(page.api.state.session.messages.length, 1, 'Rejected optimistic message is removed');
    assert.equal(page.api.state.error, '', 'Credential rejection never enters the reconnect state'); assert.equal(page.warning().hidden, false); assert.equal(page.input().disabled, false); assert.equal(page.w.document.getElementById('bhranti-send').disabled, false, 'Draft remains editable and can be resubmitted');
    assert.equal(page.warning().textContent.includes('server payload'), false);
  } finally { page.close(); }
  page = fixture();
  try {
    page.w.bhrantiCredentials.contains = () => false; let resolveRead;
    page.setHandler(async (request) => { if (request.path.endsWith('/workspace')) return workspace; if (request.method === 'GET') return new Promise((resolve) => { resolveRead = resolve; }); throw Object.assign(new Error('Credential rejected'), { code: 'bhranti_credentials' }); });
    page.edit('Original rejected draft'); const pending = page.api.sendMessage(); await until(() => resolveRead);
    assert.equal(page.warning().hidden, false); assert.equal(page.input().disabled, false, 'Rejection is editable while its saved-session read is pending'); assert.equal(page.api.state.session.messages.length, 1, 'Optimistic rejection is removed before resync');
    const newer = session(18); page.api.state.session = newer; page.api.state.draft = 'Newer chat draft'; page.api.state.credentialWarning = false;
    resolveRead(session()); await pending;
    assert.equal(page.api.state.session, newer, 'A late rejection resync cannot replace a newer chat'); assert.equal(page.api.state.draft, 'Newer chat draft'); assert.equal(page.api.state.credentialWarning, false);
  } finally { page.close(); }
  page = fixture();
  try {
    const input = page.input(); page.edit('One normal submission');
    input.dispatchEvent(new page.w.CompositionEvent('compositionstart', { bubbles: true })); input.dispatchEvent(new page.w.KeyboardEvent('keydown', { key: 'Enter', keyCode: 229, isComposing: true, bubbles: true }));
    assert.equal(page.requests.length, 0, 'IME Enter never submits'); input.dispatchEvent(new page.w.CompositionEvent('compositionend', { bubbles: true }));
    input.dispatchEvent(new page.w.KeyboardEvent('keydown', { key: 'Enter', repeat: true, bubbles: true })); assert.equal(page.requests.length, 0, 'Held Enter never repeats submission');
    input.dispatchEvent(new page.w.KeyboardEvent('keydown', { key: 'Enter', shiftKey: true, bubbles: true })); assert.equal(page.requests.length, 0, 'Shift+Enter retains newline behavior');
    input.dispatchEvent(new page.w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true })); input.dispatchEvent(new page.w.KeyboardEvent('keyup', { key: 'Enter', bubbles: true })); await until(() => !page.api.state.busy);
    assert.equal(page.requests.filter((request) => request.path.endsWith('/messages')).length, 1, 'Normal Enter submits once');
  } finally { page.close(); }
}
async function settingsChecks() {
  const validateSource = settings.slice(settings.indexOf('\tfunction validate(config) {'), settings.indexOf('\tfunction validMemory(result) {'));
  const saveSource = settings.slice(settings.indexOf('\t\tasync function save() {'), settings.indexOf('\t\tfunction changeAgent('));
  const config = { max_steps: 128, permissions: {}, system_prompt: fakeKey, guardrails: '', agents: [{ id: 'bhranti', name: 'Bhranti', agent_md: '', skills: [] }] };
  const notices = [], posts = [];
  const context = { window: sandbox.window, config, docLimit: 16000, __: (text) => text, validPermissions: () => true, saving: false, loading: false, setNotice: (notice) => notices.push(notice), setSaving() {}, wp: { apiFetch: (request) => posts.push(request) } };
  vm.createContext(context); vm.runInContext(validateSource + saveSource + '\nthis.saveSettings = save;', context);
  await context.saveSettings(); assert.equal(posts.length, 0, 'Settings validation blocks credentials before POST'); assert.equal(notices[0].status, 'error'); assert.equal(notices[0].text.includes(fakeKey), false); assert.equal(config.system_prompt, fakeKey, 'Settings rejection preserves editable configuration');
  config.system_prompt = 'Discuss how API keys should be rotated.'; assert.equal(vm.runInContext('validate(config)', context), '', 'Ordinary security instructions remain valid');
}
(async () => { await uiChecks(); await settingsChecks(); console.log('PASS: shared PHP/browser credential fixtures, pre-POST chat/context protection, RAM-only sensitive drafts, editable rejection recovery, stale-chat resync guards, screenshot byte exclusion, Enter/IME, and Settings preflight'); })().catch((error) => { console.error(error); process.exitCode = 1; });
