=== Bhranti ===
Contributors: txsadhu
Tags: ai, assistant, content, productivity, automation
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Write content, update pages and handle everyday WordPress tasks with an AI assistant beside your editor.

== Description ==

Look after your WordPress site through conversation. Ask Bhranti to update opening hours, improve a page's introduction, find an image or add a product. It reads relevant content, makes supported changes and shows what happened.

Open Bhranti from the admin bar or editor toolbar. Share page or block context and review results beside your work.

Bhranti requires an administrator account, WordPress 7.0 or later, PHP 8.0 or later, and a compatible AI text connection in Settings > Connectors. Optional image generation depends on your connector and model.

Try: "On this draft, shorten the introduction to two sentences. Keep the rest and leave it unpublished."

[Get started with Bhranti](https://bhranti.org/docs/get-started/) for setup and your first request.

= What you can do =

* Write and edit posts and pages, find content, and recover wording from saved revisions.
* Edit supported templates, template parts, navigation and styles.
* Search the Media Library, improve image descriptions and generate images with a supported connection.
* Install WordPress.org plugins and activate or deactivate permitted plugins, with your approval.
* Create Contact Form 7 forms when available, or reuse working forms.
* With WooCommerce, manage simple, non-downloadable products, categories and supported store, stock, shipping and cash-on-delivery settings. Payment account connections remain manual.
* Check saved pages at desktop and mobile sizes for common layout and accessibility issues. Checks do not certify WCAG conformance or test every visitor interaction.

Small, clear edits can happen directly. Larger projects begin with questions about your audience, content and design, followed by a work plan.

= Stay in control =

Choose allowed actions in Bhranti > Settings. Plugin installation, later activation/deactivation and uninstall each need an Approve/Cancel card. Installation can include activation when listed; otherwise, it installs inactive. Plugin deletion is off by default.

Creating a Contact Form 7 form, changing its notification destination, moving supported content to Trash, or making a WooCommerce store or other site pages visible needs an exact Approve/Deny card. Declining stops the task; earlier saved changes remain.

Actions and Undo use your signed-in account, current WordPress permissions and action switches. Bhranti does not create users, edit core, theme or plugin source files, or change accounts, roles, credentials or security settings.

When the editor has no unsaved changes, saved updates can appear as Bhranti works. An overlay helps prevent accidental edits. Stop works between steps; it cannot recall a sent request.

In Bhranti > Logs, administrators can search, filter and download actions, outcomes, affected items and requester details. Records retain the executing account.

= Make it work your way =

Add instructions and guardrails in Settings. Create named agents with pasted agent.md text and skills with skill.md text. These cannot override permissions or approvals.

Ask Bhranti to remember language, tone, design or workflow preferences. Review or reset memory in Settings. Drafts stay with each chat; the sidebar stays open across admin pages.

== Installation ==

1. Install and activate Bhranti. For a ZIP, use Plugins > Add New Plugin > Upload Plugin.
2. Set up a compatible AI text connection in Settings > Connectors and complete any connector approval.
3. Review allowed actions and optional instructions in Bhranti > Settings.
4. Open Bhranti from the admin bar or block editor toolbar and send your first request.

== Frequently Asked Questions ==

= Do I enter an API key in Bhranti? =

No. Use Settings > Connectors; Bhranti has no separate credentials or model settings. The WordPress AI plugin is not needed. WooCommerce and Contact Form 7 are optional.

= Can I undo a change? =

Supported changes offer Roll back for seven days, with confirmation in chat. Bhranti checks for later edits before restoring an item.

Plugin installation/uninstall, generated media and new forms have no automatic Undo. Restoring a form's earlier notification destination, or a WooCommerce visibility setting that would expose pages, needs a new request and approval.

Plugin status Undo restores active/inactive status. Uninstall may permanently remove data; reinstalling does not recover it. Activation effects, emails, webhooks and other side effects may remain.

= Will tasks continue if I close the browser? =

Submitted tasks can continue through WordPress scheduling, which needs site traffic or a configured scheduler. They pause for answers, approvals or unsaved editor changes. Page checks need an open browser.

Saved work and images remain after interruptions. Temporary AI failures can be retried or resumed; uncertain changes are not automatically repeated.

= Can it work with every plugin? =

It supports the features listed above. It cannot run arbitrary code, install uploaded ZIPs, switch themes or update plugins. WooCommerce support excludes orders, customer transactions and payment credentials.

= Should I share a password or key in chat? =

No. Bhranti warns about recognizable credentials before saving or sending requests. Flagged drafts stay in browser memory. Checks also cover instructions, background requests and preferences.

Detection cannot find every secret or read credentials in images. Review attachments yourself. Existing chats are not silently erased; recognized older credentials block further AI requests.

== Privacy and external services ==

Bhranti uses WordPress's AI Client to send requests to your connector's provider. Opening the sidebar does not send an AI request. A message may share:

* Your request, relevant conversation history, work plans and instructions.
* Basic site information, relevant content, search/action results and log metadata.
* Attached page text or HTML, blocks, images and screenshots.
* Image prompts, relevant preferences and checked image capabilities.
* Design-reference content and rendered layout/accessibility check results.

Your connector determines the provider and account. Usage may consume your allowance or incur charges. Provider terms and privacy:

* OpenAI: [Terms](https://openai.com/policies/services-agreement/) and [Privacy](https://openai.com/policies/privacy-policy/).
* Anthropic: [Terms](https://www.anthropic.com/legal/commercial-terms) and [Privacy](https://www.anthropic.com/legal/privacy).
* Google Gemini: [Terms](https://ai.google.dev/gemini-api/terms) and [Privacy](https://policies.google.com/privacy).

Plugin searches and installations use WordPress.org services ([Privacy](https://wordpress.org/about/privacy/)). Bhranti may retrieve a public HTTPS design reference you supply without sign-in cookies or credentials. Rendered checks use your browser and a local accessibility engine; page scripts and resources still load normally.

Bhranti has no telemetry. Interface assets load locally. Logs exclude chat bodies and raw provider errors.

Conversations, context, results, plans, rollback records and logs are stored on your site. Conversations are not encrypted in the database. Optional memory is encrypted separately for each user and site. Ordinary unsent drafts use this tab's session storage; unsent images stay in browser memory.

Chats are private to their creator. Other administrators can view Logs; database/server administrators can access stored data. WordPress personal-data export and erasure include Bhranti data. You can delete chats and reset memory. Chat deletion removes rollback records, without reversing changes or removing audit entries.

Uninstall removes Bhranti's private data and preferences. Site content, authorship and existing users stay in place.

== Screenshots ==

1. Edit a post beside Bhranti and review saved changes in the action history.
2. Follow a task while Bhranti protects the editor from accidental edits.
3. Preview the updated draft on your site with the conversation still open.

== Source code ==

[Public source, issues and release tools](https://github.com/sumanbasuli/bhranti). Build instructions are in docs/release.md.

Editable PHP, JavaScript and CSS are included in includes/ and assets/. First-party JavaScript is not compiled, bundled or minified. WordPress supplies React components and dependencies. No npm or Composer build is needed; packaging copies these files unchanged into a ZIP.

== License and credits ==

Bhranti uses GNU GPL version 2 or later; see LICENSE. Provided without warranty. Bundled third-party code retains its notices.

Bootstrap Icons SVG paths in assets/admin.js and assets/editor.js come from https://github.com/twbs/icons and use MIT (assets/bootstrap-icons-LICENSE.txt).

The bundled axe-core 4.14.0 by Deque Systems uses MPL-2.0 (assets/vendor/axe-core/LICENSE). [Original module source and build instructions](https://github.com/dequelabs/axe-core/tree/v4.14.0) are upstream. Its folder includes the readable distribution, package metadata, source hashes and third-party notices.

== Changelog ==

= 1.2 =
* Improve server locking, Roll back confirmation and interrupted-response recovery.
* Run reviewed actions as the requesting administrator and retain execution identity in logs.
* Improve private memory, connection setup, path compatibility and activity headings.

[Full release history](https://github.com/sumanbasuli/bhranti/blob/main/changelog.txt).
