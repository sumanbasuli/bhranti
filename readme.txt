=== Bhranti ===
Contributors: sumanbasuli
Tags: ai, assistant, content, productivity, automation
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.6.8
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Write content, update pages and manage everyday WordPress tasks with an AI assistant that works alongside your editor.

== Description ==

Bhranti helps you work on your WordPress site through conversation. Update an opening time, improve a page, find an image or set up a product catalog. It reads relevant content, makes supported changes and shows what happened.

Open Bhranti from the admin bar or the block editor toolbar. Share page or block context and review the results in a sidebar beside your work.

Bhranti is currently in beta. It uses WordPress's built-in AI Client and a compatible connector configured in Settings > Connectors. Text chat needs a compatible connection; image features depend on your connector and model.

= What you can do =

* Write and edit posts and pages, find existing content, and recover earlier wording from saved revisions.
* Work with supported block templates, template parts, navigation and styles using native WordPress features.
* Search the Media Library, update image descriptions, and generate images when your AI connection supports them.
* Find and install plugins from WordPress.org, and activate or deactivate permitted plugins.
* Create Contact Form 7 forms when the plugin is available. Existing working forms are preferred.
* With WooCommerce, manage simple, non-downloadable products and categories, plus supported store, stock, shipping and cash-on-delivery settings. Payment account connections remain a manual step.
* Check a saved page at desktop and mobile sizes for common layout and accessibility issues. These automated checks do not certify full WCAG conformance or test every visitor interaction.

Small edits can happen directly. For larger projects, Bhranti asks about your audience, content and design choices, then keeps a work plan and works in smaller steps.

= Stay in control =

Choose which actions Bhranti may take in Bhranti > Settings. Deleting a plugin is disabled by default. Every supported deletion or move to Trash needs your approval through a button in the chat, even when that action is enabled.

Changes run through WordPress as a dedicated Bhranti account. Both your account and the Bhranti account must have the required permissions. Bhranti does not edit WordPress core, theme or plugin source files, or change accounts, roles, credentials or security settings.

While it works on a supported clean editor, saved updates can appear in front of you. A control overlay helps prevent accidental edits; existing unsaved work remains protected. You can stop a task between steps.

Bhranti > Logs records the requested actions, outcomes, affected items and the requesting and acting users. Search, filter, view or download activity, or ask Bhranti to explain it.

= Make it work your way =

Add a system prompt and extra guardrails in Settings. Create named agents by pasting agent.md instructions and attach skills by pasting skill.md content. Instructions cannot override permissions or approval requirements.

Ask Bhranti to remember preferences about language, tone, design or workflow. Review or reset your private memory in Settings. Chats keep separate drafts, and the sidebar stays open across admin pages until closed.

== Installation ==

1. In Plugins > Add New Plugin > Upload Plugin, select the installable Bhranti ZIP and activate it. You need WordPress 7.0 or later and PHP 7.4 or later.
2. Configure a compatible AI text connection in Settings > Connectors and complete any approval your connector requires.
3. Open Bhranti > Settings to review the allowed actions and optional instructions.
4. Open Bhranti from the admin bar or block editor toolbar and send your first request. Add only the page context or images you want to share.

Bhranti is for administrators. An administrator who can create users must initialize its dedicated account on first use. WooCommerce, Contact Form 7 and image generation are optional features with their own requirements.

== Frequently Asked Questions ==

= Do I enter an API key in Bhranti? =

No. Manage your AI connection in WordPress Settings > Connectors. Bhranti has no separate provider credentials or model settings. It does not require the optional WordPress AI plugin.

= Can I undo a change? =

Supported changes have a Roll back action for seven days. Bhranti checks for later edits before restoring an item so it does not overwrite someone else's work.

Undo is not available for everything. Plugin installation or uninstallation, generated media and newly created forms have no automatic removal Undo. Plugin status changes can restore the earlier active status, but not every effect of activation. Plugin uninstall may permanently remove its data; reinstalling does not restore it. Emails, webhooks and other plugin side effects may remain.

= Does it keep working if I close the browser? =

Submitted tasks can continue through WordPress scheduling. This needs site traffic or a configured scheduler. Tasks pause for answers, approvals or protected editors. Page checks need an open browser. Stop works between steps and cannot recall a sent request.

= What if a long task is interrupted? =

Completed work and saved images remain available. Bhranti can retry temporary AI failures in smaller steps or offer Resume. Uncertain changes are not automatically repeated.

= Can it manage any plugin or build an entire store? =

It supports the features listed above, rather than every plugin. It cannot run arbitrary code, install uploaded ZIPs, switch themes or update plugins. WooCommerce support does not include orders, customer transactions or payment credentials. The assistant asks when it needs business information or a manual setup step.

= Should I share a password or key in chat? =

No. Bhranti checks text for recognizable credentials before saving a request or sending it to AI, and shows an editable warning when it finds one. Recognized sensitive drafts stay in memory rather than browser session storage. Checks also cover instructions, background AI requests and saved preferences.

Detection cannot recognize every secret or read credentials inside screenshot pixels. Review attachments yourself. Existing chats are not silently erased; a chat containing a recognized older credential is blocked from further AI requests.

== Privacy and external services ==

Bhranti sends AI requests through WordPress's AI Client to the provider selected by your installed connector. Opening the sidebar does not send an AI request. Sending a message can share:

* Your request and relevant conversation history, work plans and selected instructions.
* Basic site information and relevant content, search results, action results or log metadata.
* Attached page text or HTML, selected blocks, and images or screenshots you choose to include.
* Image-generation prompts, relevant saved preferences and checked image capabilities.
* Relevant design-reference content and results from rendered layout and accessibility checks.

Your connector determines the provider, account and terms. Usage may use your account allowance or incur charges. Common policies:

* OpenAI: https://openai.com/policies/services-agreement/ and https://openai.com/policies/privacy-policy/
* Anthropic: https://www.anthropic.com/legal/commercial-terms and https://www.anthropic.com/legal/privacy
* Google Gemini: https://ai.google.dev/gemini-api/terms and https://policies.google.com/privacy

Plugin searches and installations use native WordPress.org services (https://wordpress.org/about/privacy/). If you supply a public HTTPS design reference, Bhranti may retrieve that page without sign-in cookies or credentials. Rendered checks run in your browser with a locally bundled accessibility engine; page scripts and resources still load normally.

Bhranti has no telemetry or separate connector credentials. Its interface assets load locally. Logs exclude chat message bodies and raw provider errors.

Conversations, context, action results, plans, rollback records and activity logs are stored on your WordPress site. Conversations are not encrypted in the database. Optional preference memory uses separate encrypted files. Unsent ordinary drafts use this browser tab's session storage; unsent image attachments stay in browser memory.

Chats are owned by the administrator who created them. Other administrators can view site activity in Logs, while database or server administrators can access stored data. WordPress personal-data export and erasure include Bhranti's data. You can delete individual chats and reset memory in Settings. Deleting a chat removes its rollback records, but does not reverse site changes or remove their audit entries.

Uninstall removes Bhranti's workspace data and revokes its managed account's elevated access. It preserves created site content and authorship; you can remove or reassign that account later through WordPress.

== License and credits ==

Bhranti is free software under the GNU General Public License, version 2 or any later version, matching WordPress. See LICENSE for the full text. It is distributed without warranty.

Bundled third-party code keeps its own notices: Bootstrap Icons uses MIT (assets/bootstrap-icons-LICENSE.txt); axe-core 4.14.0 by Deque Systems uses MPL-2.0 (assets/vendor/axe-core/LICENSE). The axe-core folder includes source information and additional third-party notices.

== Changelog ==

= 0.6.8 =
* Block recognizable credentials before saving chat messages or sending AI requests, with a warning that lets you edit the message.
* Keep credentials out of private memory and withhold sensitive tool text while preserving completed action records.

Earlier release notes are included in changelog.txt.
