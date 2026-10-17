export function GET() {
  return new Response('# Page not found\n\nThis page has moved, or the address is not correct.\n\n- [Bhranti home](https://bhranti.org/)\n- [Documentation](https://bhranti.org/docs/)\n', { headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Content-Disposition': 'inline' } });
}
