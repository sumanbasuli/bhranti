import { getCollection } from 'astro:content';

export async function GET() {
  const guides = (await getCollection('docs')).filter((entry) => entry.id !== 'index').sort((a, b) => a.data.order - b.data.order);
  const text = [
    '# Bhranti',
    '',
    '> A WordPress assistant that uses the native WordPress AI Client and connectors to inspect a site and perform reviewed WordPress actions.',
    '',
    'These are Markdown companions to the public website. They contain the same product information and documentation as the human-readable pages.',
    '',
    '## Website',
    '',
    '- [Home](https://bhranti.org/index.md)',
    '- [Product](https://bhranti.org/product.md)',
    '- [Download and requirements](https://bhranti.org/download.md)',
    '- [Documentation index](https://bhranti.org/docs/index.md)',
    '',
    '## Guides',
    '',
    ...guides.map((entry) => `- [${entry.data.title}](https://bhranti.org/docs/${entry.id}.md): ${entry.data.description}`),
    '',
    '## Product boundaries',
    '',
    'Action permissions and individual human approvals for plugin changes and deletions remain authoritative. One plugin installation approval may include activation when the card lists both; otherwise the package installs inactive and later status changes need approval. Bhranti does not edit arbitrary source code, accounts, credentials, or security settings. Undo is supported only for specific changes. Automated accessibility checks are not a full conformance certification.',
    '',
  ].join('\n');
  return new Response(text, { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
}
