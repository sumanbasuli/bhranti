import { defineConfig } from 'astro/config';

export default defineConfig({
  site: 'https://bhranti.org',
  output: 'static',
  devToolbar: { enabled: false },
  // Dynamic .md endpoints must accept extension URLs without a trailing slash.
  trailingSlash: 'ignore',
});
