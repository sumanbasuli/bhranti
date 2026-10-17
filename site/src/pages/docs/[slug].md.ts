import { getCollection } from 'astro:content';
import type { APIRoute } from 'astro';

export async function getStaticPaths() {
  const docs = await getCollection('docs', ({ id }) => id !== 'index');
  return docs.map(doc => ({ params: { slug: doc.id }, props: { doc } }));
}

export const GET: APIRoute = ({ props, site }) => {
  const { doc } = props;
  const body = (doc.body || '').replace(/\]\((\/(?!\/)[^)\s]+)\)/g, (_match: string, path: string) => `](${new URL(path, site ?? 'https://bhranti.org').href})`);
  return new Response(`# ${doc.data.title}\n\n${doc.data.description}\n\n${body}\n`, {
    headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Content-Disposition': 'inline' },
  });
};
