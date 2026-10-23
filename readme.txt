=== Bhranti ===
Contributors: txsadhu
Tags: ai, assistant, content, productivity, automation
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Write content, update pages and manage everyday WordPress tasks with an AI assistant that works alongside your editor.

== Description ==

Bhranti helps you work on your WordPress site through conversation. Update an opening time, improve a page, find an image or set up a product catalog. It reads relevant content, makes supported changes and shows what happened.

Open Bhranti from the admin bar or the block editor toolbar. Share page or block context and review the results in a sidebar beside your work.

Bhranti uses WordPress's built-in AI Client and a compatible connector configured in Settings > Connectors. Text chat needs a compatible connection; image features depend on your connector and model.

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

Choose which actions Bhranti may take in Bhranti > Settings. Review an Approve/Cancel card before a plugin installation, later status change or uninstall. One installation approval can include activation when the card lists it; otherwise the plugin stays inactive. Creating a Contact Form 7 form, changing its notification destination, moving supported content to Trash, or making a WooCommerce store or other site pages visible requires an exact Approve/Deny card. Declining approval stops the current task. Deleting a plugin is disabled by default.

Actions and Undo run as your authenticated WordPress account, subject to your current permissions and Bhranti's action switches. Bhranti does not create another user account. Logs identify who requested each action; raw records retain the account that performed it. Bhranti does not edit WordPress core, theme or plugin source files, or change accounts, roles, credentials or security settings.

While it works on a supported clean editor, saved updates can appear in front of you. A control overlay helps prevent accidental edits; existing unsaved work remains protected. You can stop a task between steps.

Bhranti > Logs records requested actions, outcomes, affected items and the requesting user. Search, filter, view or download activity, or ask Bhranti to explain it. Raw records also retain the account that performed each action, including older activity where it differs.

= Make it work your way =

Add a system prompt and extra guardrails in Settings. Create named agents by pasting agent.md instructions and attach skills by pasting skill.md content. Instructions cannot override permissions or approval requirements.

Ask Bhranti to remember preferences about language, tone, design or workflow. Review or reset your private memory in Settings. Chats keep separate drafts, and the sidebar stays open across admin pages until closed.

== Installation ==

1. In Plugins > Add New Plugin > Upload Plugin, select the installable Bhranti ZIP and activate it. You need WordPress 7.0 or later and PHP 8.0 or later.
2. Configure a compatible AI text connection in Settings > Connectors and complete any approval your connector requires.
3. Open Bhranti > Settings to review the allowed actions and optional instructions.
4. Open Bhranti from the admin bar or block editor toolbar and send your first request. Add only the page context or images you want to share.

Bhranti is for administrators and works through your existing WordPress account. It does not require permission to create users. WooCommerce, Contact Form 7 and image generation are optional features with their own requirements.

== Frequently Asked Questions ==

= Do I enter an API key in Bhranti? =

No. Manage your AI connection in WordPress Settings > Connectors. Bhranti has no separate provider credentials or model settings. It does not require the optional WordPress AI plugin.

= Can I undo a change? =

Supported changes have a Roll back action for seven days. Bhranti checks for later edits before restoring an item so it does not overwrite someone else's work.

Undo is not available for everything. Plugin installation or uninstallation, generated media and newly created forms have no automatic removal Undo. A form update that changes its notification destination cannot be rolled back; request the earlier destination again and approve the new card. The same applies when undoing a restrictive WooCommerce visibility change would expose pages to visitors. Plugin status changes can restore the earlier active status, but not every effect of activation. Plugin uninstall may permanently remove its data; reinstalling does not restore it. Emails, webhooks and other plugin side effects may remain.

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

Conversations, context, action results, plans, rollback records and activity logs are stored on your WordPress site. Conversations are not encrypted in the database. Optional preference memory is encrypted separately in the WordPress database for each user and site. Unsent ordinary drafts use this browser tab's session storage; unsent image attachments stay in browser memory.

Chats are owned by the administrator who created them. Other administrators can view site activity in Logs, while database or server administrators can access stored data. WordPress personal-data export and erasure include Bhranti's data. You can delete individual chats and reset memory in Settings. Deleting a chat removes its rollback records, but does not reverse site changes or remove their audit entries.

Uninstall removes Bhranti's private workspace data and preferences. Created site content, authorship and existing user accounts stay in place.

== Screenshots ==

1. Edit a post beside Bhranti and review its saved changes and action history.
2. Follow Bhranti's actions as it works, with the editor protected from accidental edits.
3. Preview the updated draft on your site while keeping the conversation open.

== Source code ==

The public source repository, issue tracker and release packaging tools are available at https://github.com/sumanbasuli/bhranti. Build instructions are in docs/release.md in that repository.

Bhranti's editable PHP, JavaScript and CSS source is included in this plugin. The files in includes/ and the first-party files in assets/ are maintained directly. The first-party JavaScript is not compiled, bundled or minified; WordPress supplies its native React components and script dependencies.

No npm or Composer build is needed to modify the plugin's browser assets. Edit the JavaScript and CSS files directly. Release packaging copies these source files unchanged into a standard ZIP archive.

== License and credits ==

Bhranti is free software under the GNU General Public License, version 2 or any later version, matching WordPress. See LICENSE for the full text. It is distributed without warranty.

Bundled third-party code keeps its own notices. Bootstrap Icons SVG paths in assets/admin.js and assets/editor.js come from https://github.com/twbs/icons and use MIT (assets/bootstrap-icons-LICENSE.txt).

The bundled axe-core 4.14.0 accessibility engine by Deque Systems uses MPL-2.0 (assets/vendor/axe-core/LICENSE). Its original module source and build instructions are available at https://github.com/dequelabs/axe-core/tree/v4.14.0. The axe-core folder includes the readable upstream distribution, package metadata, source hashes and additional third-party notices.

== Changelog ==

= 1.2 =
* Run reviewed actions as the requesting administrator without creating another WordPress user or granting extra capabilities.
* Store private preferences as encrypted, nonautoloaded WordPress database records, with export, erasure and reset controls.
* Show one requesting user in Logs while retaining execution identity in raw audit records.

= 1.1 =
* Add screenshots of editing, live actions and Bhranti on the website.
* Keep agent and skill instruction panels open while you edit.
* Improve clean activation across supported hosting environments.
* Require PHP 8.0 or later and improve compatibility on hosts without optional PHP extensions.
* Improve source readability and document the included source and third-party libraries.
* Correct the WordPress.org contributor name.

= 1.0.1 =
* Add links to the Bhranti website and the plugin author's website.

= 1.0.0 =
* Work on content and supported site tasks from a sidebar alongside WordPress.
* Review changes, approve sensitive actions, and find activity in Bhranti Logs.
* Connect through WordPress's native AI Client and choose allowed actions in Settings.
