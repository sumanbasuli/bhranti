import { getCollection } from 'astro:content';
import type { APIRoute } from 'astro';

export const GET: APIRoute = async ({ site }) => {
  const origin = site ?? new URL('https://bhranti.org');
  const url = (path: string) => new URL(path, origin).href;
  const entries = await getCollection('docs');
  const index = entries.find(doc => doc.id === 'index')!;
  const docs = entries.filter(doc => doc.id !== 'index').sort((a,b) => a.data.order - b.data.order);
  const links = docs.map(doc => `- [${doc.data.title}](${url(`/docs/${doc.id}/`)}): ${doc.data.description}\n  [Read as Markdown](${url(`/docs/${doc.id}.md`)})`).join('\n');
  return new Response(`# ${index.data.title}\n\n${index.body}\n\n[Your first few minutes with Bhranti](${url('/docs/get-started/')}): Connect a provider. Open the sidebar. Try a small edit.\n\n## Explore the guides\n\n${links}\n\nReading with an AI assistant? Every guide has a Markdown version.\n[Browse the agent-readable index](${url('/llms.txt')}).\n`, {
    headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Content-Disposition': 'inline' },
  });
};
