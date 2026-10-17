import { readdir, readFile, access } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve('dist');
const files = [];
async function walk(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) await walk(full);
    else files.push(full);
  }
}
await walk(root);
const failures = [];
const pages = files.filter((file) => file.endsWith('.html'));
const attrs = (tag) => Object.fromEntries([...tag.matchAll(/([\w-]+)=["']([^"']*)["']/g)].map((m) => [m[1], m[2]]));
for (const file of pages) {
  const html = await readFile(file, 'utf8');
  const relative = path.relative(root, file);
  const pagePath = '/' + relative.replace(/index\.html$/, '');
  const alternate = [...html.matchAll(/<link\b[^>]*>/g)].map((m) => attrs(m[0])).find((a) => a.rel === 'alternate' && a.type === 'text/markdown');
  if (!alternate?.href) failures.push(`${relative}: missing Markdown alternate`);
  if (!/<h1(?:\s|>)/.test(html)) failures.push(`${relative}: missing page heading`);
  const hrefs = [...html.matchAll(/<a\b[^>]*>/g)].map((m) => attrs(m[0]).href).filter(Boolean);
  if (alternate?.href) hrefs.push(alternate.href);
  hrefs.push(...[...html.matchAll(/<img\b[^>]*>/g)].map((m) => attrs(m[0]).src).filter(Boolean));
  for (const href of new Set(hrefs)) {
    if (/^(mailto:|tel:|javascript:)/.test(href)) continue;
    const url = new URL(href.replaceAll('&amp;', '&'), `https://bhranti.org${pagePath}`);
    if (url.origin !== 'https://bhranti.org') continue;
    let target = path.join(root, decodeURIComponent(url.pathname));
    if (url.pathname.endsWith('/')) target = path.join(target, 'index.html');
    else if (!path.extname(target)) target = path.join(target, 'index.html');
    try {
      await access(target);
      if (url.hash && target.endsWith('.html')) {
        const destination = target === file ? html : await readFile(target, 'utf8');
        const id = decodeURIComponent(url.hash.slice(1));
        if (![...destination.matchAll(/\bid=["']([^"']*)["']/g)].some((m) => m[1] === id)) failures.push(`${relative}: missing anchor ${href}`);
      }
    } catch { failures.push(`${relative}: broken local link ${href}`); }
  }
}
for (const route of ['llms.txt', 'sitemap.xml', 'robots.txt']) {
  try { await access(path.join(root, route)); } catch { failures.push(`missing ${route}`); }
}
if (failures.length) {
  console.error(failures.join('\n'));
  process.exitCode = 1;
} else {
  console.log(`Verified ${pages.length} HTML pages: local links, image sources, anchors, headings, Markdown companions, and discovery files.`);
}
