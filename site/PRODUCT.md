# Bhranti product facts for the public site

## Purpose and audience

Bhranti is a WordPress-aware assistant for administrators working on content, design, and supported site operations. The website helps visitors understand the product, inspect how it works, and follow a human-friendly setup guide.

The current work is a local static Astro website intended for `https://bhranti.org`. Cloudflare deployment is a later step. The download page uses the requested temporary `#` link from `src/data/release.ts`; replace it with the public `sumanbasuli/bhranti` release ZIP URL when published.

## Confirmed behavior

- Plugin and admin-bar label: Bhranti. Release version and compatibility requirements come from `src/data/release.ts`.
- Requires WordPress 7.0+ and PHP 7.4+.
- Chat stays alongside the current WordPress editor or site page.
- The native WordPress AI Client and Settings → Connectors own provider configuration. Bhranti does not hold connector credentials.
- Relevant current-page/block context and explicitly attached reference images or screenshots can accompany a message.
- Reviewed native WordPress API operations cover supported content, design/navigation, media, settings, and enabled WordPress.org plugin operations.
- A reviewed WooCommerce adapter supports simple products, categories, and specified store/shipping/offline-payment settings. It does not expose customer/order records or transact.
- Prepared action intent, operation, affected item, actual task status, and Stop are visible. Expandable outcomes and separate Logs support traceability.
- Content Trash requires a specific server-stored human Approve/Deny action. Approvals expire after ten minutes and bind to the item state.
- Supported changes have conflict-safe seven-day Undo. Later changes block restoration; third-party effects such as email/webhooks cannot be rolled back.
- Permission switches define supported actions. Dedicated managed-actor and requester permissions both apply.
- Unsaved editor state is protected.
- Persisted tasks may continue through native scheduling; site traffic or a configured native cron runner is required.
- Image generation depends on native connector and site support.
- Named agents and pasted Markdown skills guide work. Markdown is instruction text, never code execution.
- Private memory, when available, stores explicitly requested preferences and bounded verified facts, with review/reset and native privacy support.
- Bhranti adds no telemetry. Messages, recent conversation, relevant site information/content, selected instructions and other task context are sent to the configured native AI provider; its terms apply.

## Boundaries to preserve

- No arbitrary core/theme/plugin source code, account, role, credential, or security-setting edits.
- No universal WordPress executor or arbitrary third-party routes.
- No universal rollback claim. Plugin uninstall may permanently delete data; reinstalling a package does not restore that data.
- No simulated token streaming, fabricated progress, or fabricated success.
- Automated rendered inspection is not full WCAG certification, screenshot analysis, or a complete human interaction test.
- No invented customer names, testimonials, usage numbers, performance guarantees, pricing, public release dates, or download availability.

## Website content and journey

- `/`: product introduction, illustrative walkthrough, native WordPress foundation, capability examples, context, human control, agents, and the setup CTA.
- `/product/`: detailed context/action/control explanation, capability table, illustrative action receipt and Markdown agent, native connector explanation.
- `/download/`: beta release version and requirements, download action, setup steps, and relevant documentation links. The download link is controlled by `src/data/release.ts`.
- `/docs/`: human-readable documentation index, followed by focused guides.
- Every page has an explicit visible Markdown link and a `text/markdown` alternate in the HTML head.
- All illustrative interfaces use fictional sample content and are labelled. Sample controls must not imply that they change a real site.

## Voice

Warm, precise, practical. Explain useful tasks and decisions before technical internals. Use “Download Bhranti”, “Get started”, “Documentation”, “Approve”, “Deny”, “Undo”, “Stop”, and “Settings → Connectors” consistently. Qualify supported capabilities and restoration limits where a visitor makes the relevant decision.
