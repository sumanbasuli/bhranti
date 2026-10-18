# Bhranti website

The public product site and documentation for **bhranti.org**, built with Astro. The site is static: it needs no WordPress installation, database, server adapter, or connector credentials.

## Work locally

Use Node.js 22.12 or newer (Node 24 is recommended).

```sh
npm ci
npm run dev
```

Open the local URL printed by Astro. Before shipping:

```sh
npm run check
npm run build
npm run check:output
npm run preview
```

The deployable files are in `dist/`. `node_modules/`, `dist/`, and `.astro/` are ignored. This folder is a separate website and should be excluded from any WordPress plugin distribution ZIP.

## Deploy to Cloudflare

For a Cloudflare Workers project connected to the parent repository:

- Root directory: `site`
- Build command: leave blank
- Deploy command: `npx wrangler deploy`
- Node version: `24`

The checked-in `wrangler.jsonc` runs `npm run build` before deploying the static `dist/` directory. Keep its `name` aligned with your Cloudflare Worker name (`bhranti`). This site does not need the Astro Cloudflare server adapter.

For a Cloudflare Pages project instead:

- Root directory: `site`
- Build command: `npm run build`
- Output directory: `dist`
- Node version: `24`

If the website is moved to its own repository, leave the root-directory setting blank. The static `dist/` directory can also be deployed to another static host.

Set the custom domain to `bhranti.org` in the host. Astro already uses `https://bhranti.org` for canonical and sitemap URLs. Update `astro.config.mjs` if that domain changes. The `public/_headers` file supplies Cloudflare static-asset headers, including the Markdown content type.

Official deployment guides: [Workers](https://developers.cloudflare.com/workers/framework-guides/web-apps/astro/) and [Pages](https://developers.cloudflare.com/pages/framework-guides/deploy-an-astro-site/).

## Edit the content

Documentation lives in `src/content/docs/`. Each guide is rendered as a readable web page and served as a `.md` companion from the same Markdown source. The documentation index has its own matching source. Frontmatter controls its title, description, navigation order, and group.

The documentation currently contains 13 focused guides: setup, chats, context and attachments, task behavior, settings, approvals and restoration, agents and skills, memory, content workflows, stores and plugins, privacy, logs and recovery, and troubleshooting. The index searches guide titles, descriptions and body text; navigation and guide counts derive from the collection.

Real documentation screenshots live in `public/images/docs/`. Keep them separate from the illustrative marketing demo. Use linked Markdown images with meaningful alt text, followed by a short caption explaining the control or next step; readers can open the original at full size. Keep capture provenance and testing notes in maintainer records, not in public guides. Label sample content simply as an example; do not narrate whether a screenshot setup was saved or sent. Preserve practical guidance about saving a reader’s changes, permissions, privacy and restoration limits. Do not reconstruct missing runtime states as real captures. Markdown endpoints make image and guide links absolute so assistants can follow them outside the site.

Every page links to its Markdown version with a visible link, an alternate `text/markdown` link, and a `data-llm-hint` discovery attribute. `/llms.txt` lists the available documents. The attribute is a custom discovery aid, not a promise that every agent supports it.

The marketing pages use reconstructed, illustrative product UI. The demo does not connect to an AI provider or edit WordPress. Keep this distinction visible when changing it. Product claims should be checked against the plugin's current README and implementation; never imply universal rollback, arbitrary code access, a public release that is unavailable, or guaranteed accessibility certification.

## Assets and privacy

The Bhranti mark is based on the sparkle icon already used by the plugin. Bootstrap icon paths retain their MIT notice in `public/licenses/bootstrap-icons.txt`. No analytics or third-party font requests are required by the site.

Read `PRODUCT.md` and `DESIGN.md` before larger content or visual changes.

## Release download

The header and primary actions lead to `/download/`. `downloadUrl` in `src/data/release.ts` remains `#` until the release ZIP is published. Replace it with the public `sumanbasuli/bhranti` release ZIP URL, then rebuild before deploying the download page. The version and compatibility information share that release configuration.

## WordPress icon

The WordPress icon is the official mark from [WordPress/Gutenberg](https://github.com/WordPress/gutenberg/blob/trunk/packages/icons/src/library/wordpress.svg), distributed under GPL-2.0-or-later. Its license is included at `public/licenses/wordpress-gpl-2.0.txt`. The mark identifies WordPress; Bhranti is an independent plugin.

Markdown files use `text/plain` plus inline disposition so browsers can read them directly instead of initiating a download. The `.md` extension, HTML alternate link and `data-llm-hint` identify their Markdown representation.
