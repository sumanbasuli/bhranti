import { getCollection } from 'astro:content';

export async function GET() {
  const guides = (await getCollection('docs')).filter((entry) => entry.id !== 'index');
  const paths = ['/', '/product/', '/download/', '/docs/', ...guides.map((entry) => `/docs/${entry.id}/`)];
  const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${paths.map((path) => `<url><loc>https://bhranti.org${path}</loc></url>`).join('')}</urlset>\n`;
  return new Response(xml, { headers: { 'Content-Type': 'application/xml; charset=utf-8' } });
}
