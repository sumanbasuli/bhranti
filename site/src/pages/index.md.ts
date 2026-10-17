import { productWalkthrough, siteFooter } from '../data/marketing';

export function GET() {
  return new Response(`# More creating. Less clicking.

A helping hand for WordPress.

Meet Bhranti. Your WordPress-aware assistant for turning a thought into something on your site.

Content, design, and the everyday work in between. Right beside your editor.

[Download Bhranti](/download/) · [Get started](/docs/get-started/)

${productWalkthrough}

## At home in WordPress.

Bhranti works with the WordPress you already know: your blocks, your content, and your native AI connector. Open it from the admin bar. Keep your work in view. Pick up where you are.

[Find your starting point](/docs/get-started/)

## 01 — A little help goes a long way

### For the work between idea and done.

You bring the intention. Bhranti can help with the next practical step.

### Words into pages.

Draft and refine content, update pages, and work with the blocks already on your site.

“Give this page a clearer introduction.”

### The details, handled.

Work on navigation, design settings, and media through reviewed native WordPress operations.

“Add our new page to the navigation.”

### A store taking shape.

Prepare simple WooCommerce products, categories, and supported store settings. New products start as drafts.

“Draft a product for our new workshop.”

## 02 — Right here, with you

### Less explaining. More context.

The page you’re on. The block you’ve selected. The reference you’ve attached. Start with what’s in front of you, and give Bhranti the context to help.

[Explore how context works](/product/#context)

**Illustrative page and selected-block context:**

- Page: About us
- Selected Heading: “A small studio. A big imagination.”
- Message context: About us · Selected heading
- Message: “Make this sound a little more like us.”

## 03 — Helpful, with boundaries

### Your site. Your say.

Good help makes its work clear. And leaves you in control.

[Understand the safeguards](/product/#control)

### 01 — Follow the work.

See the intended action and affected item before it runs. Open the details, check the outcome, or stop the task.

### 02 — Have the final word.

Plugin changes and content Trash wait for approval. One installation card can include activation. Permission switches keep supported actions within the boundaries you choose.

### 03 — Room to change your mind.

Supported changes keep a seven-day Undo. If someone has edited the item since, Bhranti refuses to overwrite their work.

## Make it your own

### A familiar way of working. A little more you.

Give an agent a role. Add your writing style or a repeatable workflow as a Markdown skill. Keep your preferences close to the work.

[Meet agents & skills](/docs/agents-and-skills/)

## What will you make next?

Get to know Bhranti, connect your native WordPress AI provider, and start with one useful task.

[Download Bhranti](/download/) · [Read the docs](/docs/)

${siteFooter}

[Read this page as Markdown](/index.md)
`, { headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Content-Disposition': 'inline' } });
}
