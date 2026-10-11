=== Bhranti ===
Contributors: sumanbasuli
Tags: ai, assistant, admin, productivity
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.11
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Bhranti sidebar for WordPress with native AI connectors, reviewed API actions, deletion confirmations, rollback and traceable logs.

== Description ==

Open Bhranti from the WordPress admin bar to chat alongside the current editor or site page. The #1E1E1E sidebar uses WordPress palette controls, with direct Chats, New chat and Settings icons. It stays open across admin navigation until closed and keeps unsent drafts separately for each chat. It can attach current page HTML/text, selected Gutenberg blocks and a tab screenshot chosen through the browser’s native sharing picker; Bhranti > Settings and Bhranti > Logs are separate native administration screens.

Settings provides native WordPress action switches plus React cards for a system prompt, additional guardrails, and named agents with pasted agent.md and skill.md documents. Select an agent from the chat menu. Each request keeps a snapshot of its starting configuration. Markdown is instruction text, never executable code; built-in permissions, confirmation and rollback checks remain mandatory.

Bhranti searches and inspects the site and performs real reviewed WordPress API actions. It creates a dedicated managed Bhranti account on first use, with a random password and blocked interactive login, application passwords and password resets. The requesting administrator and acting account must both have permission for each action.

Routine actions default on, including directory plugin installation and activation; plugin deletion defaults off. Every operation enforces the current switches even if instructions or an older run say otherwise. Supported rollback remains available after switching off new actions.

Supported operations include posts, pages, registered content using core REST controllers, comments, navigation/menu items, template customizations, global styles, media metadata, native AI image generation, installed-theme discovery, existing terms/menus, selected site settings, and reviewed plugin listing, WordPress.org installation, activation/deactivation and explicitly approved inactive-plugin uninstallation. Bhranti and its native AI/connector dependencies are protected from disruption. Unsupported operations include arbitrary source code, core/plugin/theme files, account/role/credential/security changes, unreviewed third-party routes, arbitrary media upload or file replacement, irreversible content deletion, arbitrary plugin ZIP/URL uploads, deletion of existing templates, and new taxonomy terms/classic menus without a supported Trash rollback.

* Private conversations owned by the requesting administrator.
* Native WordPress AI Client and Settings > Connectors, with no Bhranti provider settings or credentials.
* Prepared action details, real operation results and item links in the chat; navigation preserves active editors and unsaved work.
* Persisted WordPress-scheduled continuation after closing the browser, with a configurable 12–1,000 step limit (128 by default) and Stop between steps.
* A concrete Approve/Deny card before every Trash operation or opted-in permanent plugin uninstall. The server binds approvals to their requester, session and reviewed item state; approvals expire after ten minutes and cannot be reused.
* Seven-day snapshots for supported rollback. Later edits block restoration instead of being overwritten.
* Durable paginated logs with requester and actor usernames/IDs, action/route/target references and outcomes.
* WordPress notices, data export/erasure, conversation deletion and uninstall cleanup.

Native plugin uninstall executes the plugin’s cleanup routine and can permanently remove its data. Its confirmation warns that deletion cannot be rolled back. Reinstalling through chat can install the directory package again, but does not recover deleted data. Plugin activation rollback restores status only; plugin lifecycle effects are outside rollback.

Rollback restores supported WordPress state. Emails, webhooks and other plugin hook side effects cannot be universally undone. WordPress Trash settings and later edits can affect restoration. Writes are blocked when native filtering under the managed user's permissions would alter existing markup. Deleting a conversation removes its rollback records, but does not undo site actions or delete their audit records.

=== External services and privacy ===

Bhranti has no telemetry or remote assets and does not call a third-party API directly. AI providers, routing, models and credentials remain in WordPress Settings > Connectors. Opening the panel does not contact the AI provider. Native connector approvals remain authoritative.

Sending a request shares your message, up to 12 recent conversation messages, basic site information, attached current page HTML/text and selected-block context, explicitly captured tab screenshots, selected system/agent/skill instructions, image-generation prompts, and site content/query/action results needed for the task through the native WordPress AI Client. Site content, instructions and messages may contain personal data. Do not include passwords or API keys. Requests may incur provider charges or use a connected account's allowance.

The destination and service terms depend on the installed native connector. Common policies:

* OpenAI: https://openai.com/policies/services-agreement/ and https://openai.com/policies/privacy-policy/
* Anthropic: https://www.anthropic.com/legal/commercial-terms and https://www.anthropic.com/legal/privacy
* Google Gemini: https://ai.google.dev/gemini-api/terms and https://policies.google.com/privacy

Other connectors have their own terms. Bhranti never stores connector credentials. Its tools exclude credentials and security settings, and audit records exclude prompt/content bodies and raw provider errors.

Site-wide prompt, agent and skill settings remain until replaced or uninstalled. Settings support 20 agents, 20 skills per agent, 16,000 characters per document and 200,000 characters for the full configuration. These instructions are included in the relevant conversation snapshots for personal-data export and erasure.

Conversations, run context, tool results, rollback snapshots and audit records are stored in this WordPress database until deleted or erased. The UI lists the latest 50 conversations and latest 100 timeline events; the separate audit log retains paginated records without that timeline limit. A conversation accepts 200 messages; each submitted message may contain 12,000 characters. Assistant replies show sanitized static HTML previews for standard WordPress images, tables and text formatting. Block delimiters are hidden; dynamic block callbacks are never executed. Raw response content remains available through Copy response. Per-tab editor protection stores only a UUID, editor/dirty flags and expiry. Clean closed tabs expire after two minutes; dirty markers remain until cleared or explicitly resumed. These markers support native export, erasure and uninstall. Background queue entries contain only conversation/run/requester identifiers. Unsent drafts are stored separately per chat in this browser tab’s session storage; attached screenshots stay in browser memory until sent or removed. Sent screenshots are bounded to 2 MiB and become native AI image message parts.

Uninstall removes Bhranti workspace records and revokes its managed account's elevated role. It preserves normal site content and the account's authorship, resets the account password and removes its application passwords. The managed account can subsequently be removed or reassigned through normal WordPress administration.

Bootstrap Icons are bundled locally under the MIT license; see assets/bootstrap-icons-LICENSE.txt and https://github.com/twbs/icons/blob/main/LICENSE.

== Installation ==

1. Upload bhranti to wp-content/plugins or install its ZIP through Plugins > Add New.
2. Activate on WordPress 7.0+ with PHP 7.4+.
3. Configure a compatible text provider in Settings > Connectors.
4. Open Bhranti in the admin bar and review its AI sharing details. Optional prompts, agents and skills are configured under Bhranti > Settings.
5. An administrator with create_users initializes the managed account on first use. Other users of Bhranti require manage_options; every tool also checks its own WordPress permissions.
6. If enabled, approve the connection through the site's native Connector Approvals flow.

== Frequently Asked Questions ==

= Does it need the official AI plugin? =

No. Bhranti uses the core WordPress AI Client and compatible installed native connectors. Optional connector approval and logging features can govern its requests.

= Can it change the site? =

Yes, through the reviewed core API operations described above. It never executes arbitrary generated code or changes user roles. Each operation enforces the requesting administrator’s permissions and current action switches.

= Can other administrators see my conversations? =

The plugin endpoints expose only conversations owned by the current administrator. Site administrators can view action metadata in Logs. Database/server administrators can access stored data, as with other WordPress data. Conversations are not encrypted at rest.

= Does it run without the browser? =

Yes. Native WordPress scheduling continues saved tasks after the panel or browser closes. WP-Cron requires site traffic or a configured native cron runner. Tasks pause for deletion approval and background writes wait while an editor is open. Unsaved tab markers remain protected until the tab reports clean, or you explicitly Resume after those markers expire. A request already sent cannot be recalled; Stop takes effect between steps.

= What happens if a request is interrupted? =

Completed operations remain in the audit log. An interrupted operation is not automatically replayed; inspect the item and logs before starting another request. A later failed step does not undo prior successful steps.

= Does it stream replies? =

The installed native WordPress AI Client returns a completed text result. Bhranti displays that result without simulated typing. Live token streaming requires support from the native client and connector transport. Prepared actions and request elapsed time remain visible. Sidebar status reads have a 20-second timeout per attempt and recover temporary connection failures with up to two delayed retries. Site-changing requests are not automatically replayed. Native text planning uses a scoped 120-second timeout and image generation uses 180 seconds.

= Will saved changes appear in my open editor? =

Yes. New successful actions on the open post or page update its native block editor from the saved WordPress record, during the task. This does not reload the page or create another unsaved edit. Synchronization waits while this editor has local changes, active typing or a save/autosave request. Saved updates may appear in a clean editor while another tab keeps its own unsaved work; writes retain the cross-tab protection. Historical chats, unrelated items and image generation alone do not change the canvas. Classic editors, template editing and collaborative editing retain their native behavior.

= Can I edit while Bhranti works? =

As soon as a task is ready to fetch or change the current clean post or page, a dark WordPress overlay protects the native block editor from accidental clicks and shortcuts. Saved updates continue to appear beneath it, and Bhranti and the admin bar remain accessible. Control returns when the task finishes, pauses for approval or stops. Existing typing, unsaved work and save requests remain protected; unrelated reads and edits to other items do not take control.

= Can it roll back everything? =

No. Supported changes retain seven-day snapshots and reject conflicts or replay. Permanent content deletion and other unsupported content operations are blocked. Opted-in native plugin uninstall is an explicit irreversible exception with human approval. Generated images remain in Media Library when page content is restored. Hook side effects such as emails are outside content rollback.

== Changelog ==

= 0.4.11 =
* Add a right-side native post/page editor toolbar opener when the admin bar is unavailable or the control overlay is active, preserving Back navigation and the inspector. Keep the opener accessible during editor control and retain chat drafts and focus.

= 0.4.10 =
* Take editor control before fetching the current post or page and retain it through the task.
* Keep reads separate from saved canvas updates and preserve existing editor safeguards.

= 0.4.9 =
* Refine editor control to a translucent WordPress dark overlay with only its centered status text.

= 0.4.8 =
* Protect the current post/page editor with a dark WordPress control overlay during matching Bhranti edits.
* Keep saved updates, chat and navigation available; restore editing on task handover.
* Preserve native typing, unsaved work, dialogs and editor attributes.

= 0.4.7 =
* Allow committed updates in a clean post/page editor while other browser tabs preserve unsaved work.
* Use the native block editor dirty state instead of also testing Classic Editor form baselines.
* Preserve an explicitly opened chat when the native editor rewrites the startup URL.

= 0.4.6 =
* Finish a saved editor update before starting the next model request.
* Keep pending updates across follow-up messages and reject reads raced by changes in another tab.
* Protect active block dragging and range selection during synchronization.

= 0.4.5 =
* Show saved post/page changes in the open native block editor after each successful action, including rollback.
* Protect typing, unsaved changes, save/autosave requests and editor switches while loading saved updates.
* Retain native focus and clean saved state without reloading or repeating mutations.

= 0.4.4 =
* Remove the persistent Changes saved card; retain action links and protected automatic page updates.

= 0.4.3 =
* Recover temporary sidebar connection issues without hiding the conversation or repeating site changes.
* Preserve chat scroll position, focus and drafts, including drafts written during a task.
* Refresh matching WordPress lists and frontend pages after completed changes; protect active editors and unsaved forms.
* Offer clear saved-result and recovery controls, with resolved errors retained in expandable history.

= 0.4.2 =
* Honor native AI request timeouts when a local host overrides the HTTP transport.
* Retry temporary model connection failures after saved delays without discarding completed actions.
* Resume proven model connection failures with the same task history and existing images.

= 0.4.1 =
* Keep sidebar controls mounted during pointer and keyboard activation when native editor state changes.
* Preserve native toolbar dropdowns above the sidebar.
* Read native public post-type metadata through its exact core schema route.

= 0.4.0 =
* Continue durable tasks through native WordPress scheduling, with a configurable step limit.
* Show prepared actions, affected items and elapsed native request time.
* Protect editors and unsaved work across tabs, including background writes.
* Use strict native action-plan output and scoped text/image timeouts.
* Classify native failures without storing provider payloads.

= 0.3.6 =
* Remove the dark sidebar’s outer border for a seamless panel.
* Render safe static block previews in existing and new chat replies, including generated images and tables.
* Read installed themes through the native REST controller without permanent theme-changing permissions.
* Attach bounded rendered-page HTML/text and user-chosen screenshots as native AI context.
* Request action plans through native JSON output, with one bounded planning recovery before stopping.
* Generate images through native WordPress connectors and save traceable Media Library attachments.
* Record safe native AI error codes without provider payloads or credentials.
* Keep the main WordPress frame rounded and the outer chat panel square, with a #1E1E1E sidebar.
* Add direct Chats/New chat/Settings controls, separate per-chat drafts, and locally bundled Bootstrap Icons.
* Keep an opened sidebar across admin navigation until closed, without automatically opening it on the frontend.

= 0.3.1 =
* Open saved conversations without replaying historical editor navigation.
* Bound screen-loading requests and provide retry feedback when the local server stalls.

= 0.3.0 =
* Native WordPress React Settings cards for action permissions, system prompts, additional guardrails, agents and skills.
* Enforced action switches and reviewed native plugin installation/status changes, with opt-in approved permanent uninstall.
* Pasted Markdown configuration, revision-safe saving and per-request instruction snapshots.
* Bhranti branding, native WordPress colors and rounded sidebar/editor frames.

= 0.2.0 =
* Bhranti admin-bar sidebar with native WordPress colors, rounded frames, current page/block context and visible tool steps.
* Managed Bhranti acting account, reviewed WordPress API operations and native connector agent loop.
* Human deletion confirmations, supported rollback and durable requester/actor activity logs.
* Updated privacy export, erasure and uninstall handling.

= 0.1.0 =
* Initial private chat workspace, native AI integration and read-only boundaries.
