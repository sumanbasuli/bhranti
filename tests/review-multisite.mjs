/** Real native network bootstrap in a fresh offline Playground, never a live site. */
import { mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const source = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const reports = await mkdtemp(path.join(tmpdir(), 'bhranti-native-network-'));
const { runCLI } = await import(process.env.BHRANTI_PLAYGROUND_MODULE || '@wp-playground/cli');
const server = await runCLI({
  command: 'server', php: process.env.BHRANTI_REVIEW_PHP || '8.0', wp: process.env.BHRANTI_REVIEW_WP || '7.0',
  port: Number(process.env.BHRANTI_REVIEW_PORT || 9420), workers: 6,
  mount: [
    { hostPath: path.join(source, 'build/bhranti'), vfsPath: '/wordpress/wp-content/plugins/bhranti' },
    { hostPath: path.join(source, 'tests'), vfsPath: '/review-tests' },
  ],
  blueprint: {
    features: { networking: false },
    constants: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: true, DISABLE_WP_CRON: true, WP_HTTP_BLOCK_EXTERNAL: true, BHRANTI_REVIEW_E2E_TEST: true },
    siteOptions: { blogname: 'Bhranti isolated native network fixture' },
  },
});
const report = {};
async function run(name, code) {
  const response = await server.playground.run({ code });
  report[name] = { text: response.text, errors: response.errors, exitCode: response.exitCode };
  await writeFile(path.join(reports, 'results.json'), JSON.stringify(report, null, 2));
  if (response.exitCode || response.errors || /(?:Fatal error|Warning:|Notice:|Deprecated:|WordPress database error)/.test(response.text)) throw new Error(name + ': ' + response.text + response.errors);
  console.log(name + ': ' + response.text.trim());
}
try {
  await run('native-network-install', `<?php
require '/wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
wp_set_current_user(1);
foreach ($wpdb->tables('ms_global') as $table => $prefixed_table) { $wpdb->$table = $prefixed_table; }
install_network();
$result = populate_network(1,'127.0.0.1',get_userdata(1)->user_email,'Native review network','/',false);
if ($wpdb->last_error) throw new RuntimeException('Native network initialization failed.');
if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
echo 'Native network tables and metadata prepared.';
`);
  for (const [name, value] of Object.entries({ MULTISITE: true, SUBDOMAIN_INSTALL: false, DOMAIN_CURRENT_SITE: '127.0.0.1', PATH_CURRENT_SITE: '/', SITE_ID_CURRENT_SITE: 1, BLOG_ID_CURRENT_SITE: 1 })) {
    await server.playground.defineConstant(name, value);
  }
  await run('native-network-memory-identity', `<?php $_SERVER['REMOTE_ADDR']='127.0.0.1'; $_SERVER['HTTP_HOST']='127.0.0.1'; $_SERVER['SERVER_PORT']='80'; $_SERVER['REQUEST_URI']='/'; require '/review-tests/review-multisite-native.php';`);
  const final = JSON.parse(report['native-network-memory-identity'].text);
  if (final.status !== 'complete' || final.sites !== 2 || final.checks < 25) throw new Error('Native network assertions did not finish.');
  console.log('REPORTS ' + reports);
} catch (error) {
  console.error(error.message);
  console.error('REPORTS ' + reports);
  process.exitCode = 1;
} finally { await server[Symbol.asyncDispose](); }
