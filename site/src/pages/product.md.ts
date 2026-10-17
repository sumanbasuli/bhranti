import { productWalkthrough, siteFooter } from '../data/marketing';

export function GET() {
  return new Response(`# From “what if” to what’s next.

A closer look at Bhranti.

An assistant that understands where you’re working, takes practical steps, and lets you follow along.

[Find your first useful task](/docs/get-started/)

Explore the product: [01 Context](/product/#context) · [02 Actions](/product/#actions) · [03 Control](/product/#control) · [04 Make it yours](/product/#make-it-yours)

${productWalkthrough}

## 01 — Start with context

### Already on the same page.

Keep your page in view and your conversation beside it. Bhranti can work from the current page, a selected block, and the context you choose to share.

### A place to begin.

Open Bhranti from the WordPress admin bar. Ask about your site or focus on the page you’re editing.

### A clearer reference.

Attach a reference image or a browser-chosen screenshot. A public design URL can provide bounded page text and design hints.

### Your site, inspected.

Bhranti can search and inspect supported WordPress resources before deciding how to act.

### Unsaved work respected.

Open editors and dirty tabs have protections. Bhranti checks the saved state and avoids writing over local work.

Your messages and relevant site context are sent through the native WordPress AI Client to your configured provider. [Read about privacy.](/docs/ai-and-privacy/)

## 02 — Ideas into native actions

### Useful work. Familiar foundations.

Bhranti works through reviewed WordPress APIs and the permissions you enable. Here’s where it can help.

| Capability | How Bhranti can help |
| --- | --- |
| [01 Content & blocks](/docs/content-workflows/) | Search, draft, refine, and update posts or pages using the blocks installed on your site. |
| [02 Design & navigation](/docs/content-workflows/#build-a-new-page-or-redesign) | Work with supported native design settings, shared template parts, and navigation. |
| [03 Media & images](/docs/content-workflows/#use-existing-media) | Find and use your media. Generate images when your native connector and site support it. |
| [04 WooCommerce](/docs/store-and-plugins/) | Prepare simple products and categories, plus supported store, shipping, and offline payment settings. |
| [05 Directory plugins](/docs/store-and-plugins/#install-or-activate-a-plugin) | Use reviewed WordPress.org installation and activation operations when enabled in your settings. |
| [06 Longer tasks](/docs/working-with-bhranti/#continue-after-closing-the-browser) | Continue persisted work through WordPress scheduling, with visible progress and a Stop control. |

Background continuation needs site traffic or a configured native WordPress cron runner. Available image tools depend on your native AI connector.

## 03 — You stay in control

### Nothing hidden behind “done.”

A prepared action shows its intent, operation, and affected item. Completed actions have outcomes you can inspect. Failures and requests for approval stay visible.

### Action details — Illustration

**Updated page content**

About us · Page

| Detail | Value |
| --- | --- |
| Intent | Refine the introduction |
| Operation | Native page update |
| Outcome | Saved state verified |
| Restoration | Supported for 7 days* |

*If the saved result is unchanged.

Undo

Illustrative action details, using a fictional page.

### A human yes. Or no.

Content Trash always pauses for an explicit Approve or Deny button. Approvals are stored on the server, bound to the specific item state, and expire after ten minutes.

### Undo with a memory.

Supported changes keep seven-day snapshots. Undo checks that the item still matches Bhranti’s saved result, and refuses to overwrite a later edit.

### A record you can follow.

Expandable chat details show each action’s outcome. A separate Logs screen records activity and the human requester and managed actor.

### Clear limits are part of the product.

Bhranti does not edit arbitrary core, theme, or plugin source code, accounts, roles, credentials, or security settings. Its reviewed tools define what it can do.

Undo restores supported WordPress data. It cannot reverse emails, webhooks, or other third-party effects. Opt-in plugin deletion requires approval and may permanently remove plugin data.

[Read the full safety & Undo guide](/docs/permissions-and-undo/)

## 04 — A way of working that fits

### Give your assistant a point of view.

Create a named agent and paste in its instructions. Add Markdown skills for a writing style, a repeatable process, or the details that matter to your work.

Your agent and skills guide the task. Built-in permissions and approval checks still apply.

[Set up an agent](/docs/agents-and-skills/)

**Example: editorial-assistant / agent.md**

\`\`\`markdown
# Editorial assistant

Help us make useful, thoughtful pages.

## Our voice

Clear. Warm. Specific.
Make every sentence earn its place.

## Before making a change

Read the page. Understand its purpose.
Keep what is already working.
\`\`\`

Markdown instructions, handled as text.

## Connected, the WordPress way

### One native place to connect.

Choose your AI provider in **Settings → Connectors**. Bhranti uses the native WordPress AI Client and keeps provider configuration there.

Bhranti stores no connector credentials and adds no telemetry. The configured provider’s terms apply to the information you send.

[Read the setup guide](/docs/get-started/)

## A good place to start.

Open the guide, make the connection, and try one clear request on your WordPress site.

[Download Bhranti](/download/) · [All documentation](/docs/)

${siteFooter}

[Read this page as Markdown](/product.md)
`, { headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Content-Disposition': 'inline' } });
}
