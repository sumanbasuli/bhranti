import { contactIntro, contactSections } from '../data/contact';
import { siteFooter } from '../data/marketing';

export function GET() {
  const sections = contactSections.map((section) => [
    `## ${section.title}`,
    '',
    section.description,
    '',
    ...section.links.map((link) => `[${link.label}](${link.href})`),
  ].join('\n')).join('\n\n');

  return new Response(`# Get in touch.\n\n${contactIntro}\n\n${sections}\n\nPlease leave passwords, API keys and private site information out of public posts.\n\n${siteFooter}\n\n[Read this page as Markdown](/contact.md)\n`, {
    headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Content-Disposition': 'inline' },
  });
}
