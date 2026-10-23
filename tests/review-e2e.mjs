/** Fresh PHP 8 / WordPress 7 native integration; no existing site or credentials. */
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, readdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const source = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const packageRoot = path.resolve(process.env.BHRANTI_PACKAGE_ROOT || path.join(source, 'build/bhranti'));
const reports = await mkdtemp(path.join(tmpdir(), 'bhranti-native-review-'));
const { runCLI } = await import(process.env.BHRANTI_PLAYGROUND_MODULE || '@wp-playground/cli');
const phpVersion = process.env.BHRANTI_REVIEW_PHP || '8.0';
const wordpressVersion = process.env.BHRANTI_REVIEW_WP || '7.0';
const manifest = {};
async function record(directory, prefix = '') {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const name = path.posix.join(prefix, entry.name);
    if (entry.isSymbolicLink()) throw new Error('Packaged symlinks are forbidden: ' + name);
    if (entry.isDirectory()) await record(path.join(directory, entry.name), name);
    else if (entry.isFile()) manifest[name] = createHash('sha256').update(await readFile(path.join(directory, entry.name))).digest('hex');
  }
}
await record(packageRoot);
if (!manifest['bhranti.php'] || !manifest['includes/class-bhranti-memory.php']) throw new Error('Build the exact candidate package first.');
await writeFile(path.join(reports, 'package-manifest.json'), JSON.stringify(manifest, null, 2));

const server = await runCLI({
  command: 'server', php: phpVersion, wp: wordpressVersion,
  port: Number(process.env.BHRANTI_REVIEW_PORT || 9419), workers: 6, login: false,
  mount: [
    { hostPath: packageRoot, vfsPath: '/wordpress/wp-content/plugins/bhranti' },
    { hostPath: path.join(source, 'tests'), vfsPath: '/review-tests' },
  ],
  blueprint: {
    features: { networking: false },
    constants: {
      WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: true,
      WP_DISABLE_FATAL_ERROR_HANDLER: true, DISABLE_WP_CRON: true,
      WP_HTTP_BLOCK_EXTERNAL: true, WP_ENVIRONMENT_TYPE: 'local',
      BHRANTI_ACTIVATION_TEST: true, BHRANTI_REVIEW_E2E_TEST: true,
    },
    siteOptions: { blogname: 'Bhranti isolated review fixture' },
  },
});
const report = { packageRoot, reports, phpVersion, wordpressVersion, serverUrl: server.serverUrl, checks: {} };
async function run(name, code) {
  let result;
  try { result = await server.playground.run({ code }); }
  catch (error) {
    report.checks[name] = { error: String(error.message || error) };
    await writeFile(path.join(reports, 'results.json'), JSON.stringify(report, null, 2));
    throw error;
  }
  report.checks[name] = { exitCode: result.exitCode, text: result.text, errors: result.errors };
  await writeFile(path.join(reports, 'results.json'), JSON.stringify(report, null, 2));
  if (result.exitCode !== 0 || result.errors || /(?:Fatal error|Warning:|Notice:|Deprecated:)/.test(result.text)) {
    throw new Error(name + ': ' + result.text + '\n' + result.errors);
  }
  console.log(name + ': ' + result.text.trim());
  return result;
}
try {
  await run('clean-activation', `<?php require '/review-tests/activation-native.php';`);
  await run('human-only-workspace', `<?php require '/review-tests/human-access.php';`);
  await run('requester-identity', `<?php require '/review-tests/requester-identity.php';`);
  const native = await run('native-chat-actions', `<?php require '/review-tests/review-e2e-native.php';`);
  const fixturePage = Number(JSON.parse(native.text).fixture_page);
  if (!Number.isInteger(fixturePage) || fixturePage < 1) throw new Error('The native fixture page identity is missing.');
  const authentication = await server.playground.run({ code: `<?php
require '/wordpress/wp-load.php';
$created = array();
foreach (array('administrator','subscriber') as $index => $role) {
    $id = wp_insert_user(array('user_login'=>'review-http-'.$index,'user_email'=>'review-http-'.$index.'@example.invalid','user_pass'=>wp_generate_password(32,true,true),'role'=>$role));
    if (is_wp_error($id)) throw new RuntimeException('HTTP fixture user setup failed.');
    $created[] = $id;
}
$auth = array();
foreach (array_merge(array(1), $created) as $user_id) {
    wp_set_current_user($user_id);
    $expires = time()+600;
    $token = WP_Session_Tokens::get_instance($user_id)->create($expires);
    $cookie = wp_generate_auth_cookie($user_id,$expires,'logged_in',$token);
    $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
    $auth[] = array('user'=>$user_id,'cookie_name'=>LOGGED_IN_COOKIE,'cookie'=>$cookie,'nonce'=>wp_create_nonce('wp_rest'),'token'=>$token);
}
echo wp_json_encode(array('rest_url'=>rest_url(),'users'=>$auth));
` });
  const authenticationEnvelope = JSON.parse(authentication.text);
  const authenticationData = authenticationEnvelope.users;
  // Playground redirects its first external request once after boot; warm only a harmless public GET.
  await fetch(server.serverUrl, { redirect: 'follow' });
  const httpChecks = [];
  let httpSession = 0;
  async function authenticatedRequest(name, method, route, credentials, nonce, expected, payload = null) {
    const headers = { 'Content-Type': 'application/json' };
    if (credentials) headers.Cookie = credentials.cookie_name + '=' + credentials.cookie;
    if (nonce) headers['X-WP-Nonce'] = nonce;
    const url = new URL(authenticationEnvelope.rest_url);
    if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', '/bhranti/v1' + route);
    else url.pathname = url.pathname.replace(/\/$/, '') + '/bhranti/v1' + route;
    const response = await fetch(url, { method, headers, redirect: 'manual', ...(payload === null ? {} : { body: JSON.stringify(payload) }) });
    if (response.status >= 300 && response.status < 400) throw new Error('Unexpected native HTTP redirect for ' + name + ': ' + response.status + ' to ' + response.headers.get('location'));
    const value = await response.json();
    httpChecks.push({ name, status: response.status, code: typeof value.code === 'string' ? value.code : '' });
    if (response.status !== expected) throw new Error('Native HTTP authentication failed: ' + name + ', status ' + response.status + ', code ' + (value.code || 'none'));
    return value;
  }
  try {
    const [ownerAuth, otherAuth, subscriberAuth] = authenticationData;
    await authenticatedRequest('anonymous workspace denied', 'GET', '/workspace', null, null, 401);
    await authenticatedRequest('cookie without nonce denied', 'GET', '/workspace', ownerAuth, null, 401);
    await authenticatedRequest('wrong nonce rejected', 'GET', '/workspace', ownerAuth, 'invalid-review-nonce', 403);
    await authenticatedRequest('owned cookie plus nonce succeeds', 'GET', '/workspace', ownerAuth, ownerAuth.nonce, 200);
    const created = await authenticatedRequest('native authenticated chat creation', 'POST', '/sessions', ownerAuth, ownerAuth.nonce, 200);
    httpSession = created.id;
    await authenticatedRequest('other administrator cannot read chat', 'GET', '/sessions/' + httpSession, otherAuth, otherAuth.nonce, 404);
    await authenticatedRequest('other administrator cannot append to chat', 'POST', '/sessions/' + httpSession + '/messages', otherAuth, otherAuth.nonce, 404, { content: 'Unowned test message' });
    await authenticatedRequest('other administrator cannot delete chat', 'DELETE', '/sessions/' + httpSession, otherAuth, otherAuth.nonce, 404);
    await authenticatedRequest('subscriber cannot access workspace', 'GET', '/workspace', subscriberAuth, subscriberAuth.nonce, 403);
    await authenticatedRequest('subscriber cannot create chat', 'POST', '/sessions', subscriberAuth, subscriberAuth.nonce, 403);
    await authenticatedRequest('owner deletes exact chat', 'DELETE', '/sessions/' + httpSession, ownerAuth, ownerAuth.nonce, 200);
    httpSession = 0;
    report.checks['native-http-authentication'] = { checks: httpChecks, externalHttp: 0 };
    await writeFile(path.join(reports, 'results.json'), JSON.stringify(report, null, 2));
    console.log('native-http-authentication: PASS ' + httpChecks.length + ' real HTTP cookie/nonce/ownership checks; authentication values excluded from reports.');
  } finally {
    const tokens = Buffer.from(JSON.stringify(authenticationData.map(item => ({ user: item.user, token: item.token })))).toString('base64');
    await server.playground.run({ code: `<?php
require '/wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/user.php';
wp_set_current_user(1);
if (${Number(httpSession)}) wp_delete_post(${Number(httpSession)},true);
foreach (json_decode(base64_decode('${tokens}'), true) as $item) {
    WP_Session_Tokens::get_instance($item['user'])->destroy($item['token']);
    if (1 !== $item['user']) wp_delete_user($item['user']);
}
echo 'HTTP fixtures removed.';
` });
  }
  if (process.env.BHRANTI_REVIEW_KEEP_BROWSER === '1') {
    await run('browser-prepare', `<?php
require '/wordpress/wp-load.php';
wp_set_current_user(1);
wp_set_password('BhrantiReview2026!', 1);
echo 'Disposable browser fixture ready.';
`);
    console.log('REVIEW_READY ' + server.serverUrl + '/wp-admin/post.php?post=' + fixturePage + '&action=edit (admin / BhrantiReview2026!). Interrupt this process after browser checks to run native uninstall and dispose.');
    await new Promise(resolve => { process.once('SIGINT', resolve); process.once('SIGTERM', resolve); });
  }
  await run('native-uninstall', `<?php
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user(1);
$bre_page = get_post(${fixturePage});
if (!$bre_page) throw new RuntimeException('Expected authored fixture page is missing.');
$before = array($bre_page->post_author, $bre_page->post_content, $bre_page->post_title);
$users = get_users(array('fields'=>'ID'));
deactivate_plugins('bhranti/bhranti.php');
uninstall_plugin('bhranti/bhranti.php');
clean_post_cache($bre_page->ID);
$after = get_post($bre_page->ID);
if (!$after || $before !== array($after->post_author,$after->post_content,$after->post_title) || $users !== get_users(array('fields'=>'ID'))) throw new RuntimeException('Uninstall altered normal content or accounts.');
if (get_option('bhranti_settings',null) !== null || get_posts(array('post_type'=>array('bhranti_session','bhranti_event'),'post_status'=>array_keys(get_post_stati()),'fields'=>'ids'))) throw new RuntimeException('Uninstall retained private workspace records.');
echo 'PASS native deactivation/uninstall; authored content and human accounts preserved.';
`);
  console.log('REPORTS ' + reports);
} catch (error) {
  console.error(error.stack || error.message);
  if (error.cause) console.error('CAUSE ' + String(error.cause.message || error.cause));
  console.error('REPORTS ' + reports);
  process.exitCode = 1;
} finally {
  await server[Symbol.asyncDispose]();
}
