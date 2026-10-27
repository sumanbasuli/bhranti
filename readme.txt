=== Bhranti - AI Agent Harness ===
Contributors: txsadhu
Tags: ai, ai-agent, ai-assistant, automation, content
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your AI agent for WordPress. Create pages, update content, manage plugins, and automate everyday tasks by simply asking.

== Description ==

Bhranti is an AI agent for WordPress that helps you manage your website through simple conversations.

Ask it to create a page, update existing content, improve your writing, manage supported plugins, or help set up your WooCommerce store. Bhranti can take action directly on your website instead of just telling you what to do.

It works alongside the WordPress editor, keeps you informed about changes, and asks for your approval before sensitive actions.

**Less clicking. Less repetitive work. More time to focus on your website.**

Requires WordPress 7.0+, PHP 8.0+, administrator access and a compatible text provider in Settings > Connectors. Image generation depends on the connector and model.

[Get started](https://bhranti.org/docs/get-started/) or visit the [support forum](https://wordpress.org/support/plugin/bhranti/).

= What you can do =

* **Posts and pages:** Draft, rewrite and find content, or recover wording from saved revisions.
* **Site design:** Customize supported templates, template parts, navigation and global styles.
* **Media and images:** Search the Media Library, improve image descriptions and generate images with a supported connection.
* **Plugins:** Find, install, activate or deactivate permitted WordPress.org plugins, with your approval.
* **Forms:** Reuse working forms or create Contact Form 7 forms when that plugin is available.
* **WooCommerce:** Manage simple, non-downloadable products, categories, stock, shipping, cash-on-delivery and supported store settings. Connect payment accounts manually.
* **Page checks:** Inspect saved pages for common desktop and mobile layout and accessibility issues. Checks do not certify WCAG conformance or cover every interaction.
* **Longer tasks:** Follow saved plans and progress, with background work through WordPress scheduling.

Focused edits can happen directly. Larger projects begin with questions about your audience, content and design, then a work plan.

= You choose what can change =

Choose allowed actions in Bhranti > Settings. Plugin installation, activation, deactivation and uninstall each ask for your approval in chat. One installation approval can include activation; otherwise, the plugin installs inactive. Plugin deletion is off by default.

Approval is required to create Contact Form 7 forms, change their notification destinations, move supported content to Trash, or change WooCommerce coming-soon settings to reveal store or site pages. Declining stops the task; earlier changes remain.

Actions and Roll back respect your WordPress permissions and the actions allowed in Settings. Bhranti does not create users, edit core, theme or plugin source files, or change accounts, roles, credentials or security settings.

With no unsaved editor changes, saved updates can appear as Bhranti works. An overlay helps prevent accidental edits. Stop works between steps; it cannot recall a sent request.

In Bhranti > Logs, search, filter or download results, affected items and who requested and performed each action.

= Make Bhranti work your way =

Add guardrails, writing styles or workflows in Settings. Agents use pasted agent.md instructions; skills use skill.md text. Permissions and approvals still apply.

Ask Bhranti to remember language, tone, design or workflow preferences. Review or reset memory in Settings. Drafts stay with each chat; the sidebar stays open across admin pages.

== Installation ==

1. Install and activate Bhranti. For a ZIP, use Plugins > Add New Plugin > Upload Plugin.
2. Connect a compatible AI text provider in Settings > Connectors and complete any connector approval.
3. Review allowed actions in Bhranti > Settings.
4. Open Bhranti from the admin bar or block editor (Gutenberg) toolbar and describe your task.

== Frequently Asked Questions ==

= Do I need an AI connection? =

Yes. Connect a text provider in Settings > Connectors. Bhranti has no separate credentials or model settings and needs no WordPress AI plugin. WooCommerce and Contact Form 7 are optional.

= Can I undo a change? =

Supported changes offer Roll back for seven days. Confirm in chat; Bhranti checks for later edits before restoring an item.

Plugin installation or uninstall, generated media and new forms have no automatic Undo. Restoring a form recipient or WooCommerce visibility that exposes pages needs a new request and approval.

Plugin status Undo restores active or inactive status. Uninstall can permanently remove data; reinstalling cannot recover it. Emails, webhooks and other activation effects may remain.

= Will tasks continue if I close the browser? =

Yes. WordPress scheduling needs site traffic or a configured scheduler. Tasks pause for answers or approvals; open editors can delay changes. Page checks need Bhranti's sidebar open.

Saved work and images survive interruptions. Retry or resume temporary AI failures; uncertain changes are not automatically repeated.

= Can it work with every plugin? =

No. Bhranti supports the listed WordPress, WooCommerce and Contact Form 7 features. Plugin changes work only on single-site WordPress. It cannot run arbitrary code, install uploaded ZIPs, switch themes or update plugins. WooCommerce support excludes orders, customer transactions and payment credentials.

= Should I share a password or key in chat? =

No. Bhranti checks for recognizable credentials before saving or sending requests, including instructions, background work and preferences. Flagged drafts stay in browser memory.

Detection is incomplete and cannot read secrets in images. Review attachments. Recognized older credentials block further AI requests; existing chats are not silently erased.

== Privacy and external services ==

Requests go through WordPress's AI Client to your connector's provider. Opening the sidebar sends no AI request. A message may share:

* Your request, relevant chat history, work plans and instructions.
* Basic site information, relevant content, search/action results and log metadata.
* Attached page text/HTML, blocks, images and screenshots.
* Image prompts, relevant preferences and checked image capabilities.
* Design-reference content and rendered layout/accessibility results.

Your connector selects the provider and account. Usage may consume your allowance or incur charges. Provider terms and privacy:

* OpenAI: [Terms](https://openai.com/policies/services-agreement/) and [Privacy](https://openai.com/policies/privacy-policy/).
* Anthropic: [Terms](https://www.anthropic.com/legal/commercial-terms) and [Privacy](https://www.anthropic.com/legal/privacy).
* Google Gemini: [Terms](https://ai.google.dev/gemini-api/terms) and [Privacy](https://policies.google.com/privacy).

Plugin searches and installs use WordPress.org ([Privacy](https://wordpress.org/about/privacy/)). Bhranti may read a public HTTPS design reference you supply without sign-in cookies or credentials. Rendered checks use your browser and a local accessibility engine; page scripts and resources load normally.

Bhranti has no telemetry. Interface assets load locally. Logs exclude chat bodies and raw provider errors.

Your site stores conversations, context, results, plans, rollback records and logs. Conversations are unencrypted in the database; optional memory is encrypted per user and site. Ordinary unsent drafts use this tab's session storage; unsent images stay in browser memory.

Chats are private to their creator; other administrators can view Logs. Server/database administrators can access stored data. WordPress data export and erasure include Bhranti records. You can delete chats or reset memory. Chat deletion removes rollback records but leaves site changes and audit entries.

Uninstall removes Bhranti's private data and preferences; site content, authorship and existing users remain.

== Screenshots ==

1. Edit a post beside Bhranti and review saved changes in the action history.
2. Follow a task while Bhranti protects the editor from accidental edits.
3. Preview the updated draft on your site with the conversation still open.

== Source code ==

[Public source, issues and release tools](https://github.com/sumanbasuli/bhranti). Build instructions: docs/release.md.

includes/ and assets/ contain editable PHP, JavaScript and CSS. First-party JavaScript is not compiled, bundled or minified. WordPress supplies React components and dependencies. Packaging copies files unchanged; no npm or Composer build is needed.

== License and credits ==

Bhranti uses GNU GPL version 2 or later; see LICENSE. Provided without warranty. Bundled third-party code retains its notices.

Bootstrap Icons SVG paths in assets/admin.js and assets/editor.js use MIT (assets/bootstrap-icons-LICENSE.txt). Source: https://github.com/twbs/icons

axe-core 4.14.0 by Deque Systems uses MPL-2.0 (assets/vendor/axe-core/LICENSE). [Source and build instructions](https://github.com/dequelabs/axe-core/tree/v4.14.0). Its folder includes the readable distribution, package metadata, source hashes and third-party notices.

== Changelog ==

= 1.2 =
* Improve server locking, Roll back confirmation and interrupted-response recovery.
* Run reviewed actions as the requesting administrator and retain execution identity in logs.
* Improve private memory, connection setup, path compatibility and activity headings.

[Full release history](https://github.com/sumanbasuli/bhranti/blob/main/changelog.txt).
