---
title: "Troubleshooting"
description: "Short answers for common setup, chat, editor and task problems."
order: 13
group: "Help and privacy"
---

Start with the message Bhranti is showing and its last recorded action. Check what completed before repeating a request.

## I cannot find Bhranti

Confirm the plugin is active and you are using an administrator account with permission to manage site settings. Open **Bhranti** from the WordPress admin bar. If that button is unavailable in a post or page editor, look in the editor header.

Requests run as your signed-in administrator account. Bhranti does not create another user or require permission to create users. Check your current WordPress permissions and the action switches in Bhranti Settings. Logs identifies the account that requested each action.

## No AI provider is configured

Open **Settings → Connectors**, configure a compatible text provider and complete any approval WordPress requests. Bhranti has no API-key field of its own. See [Get started](/docs/get-started/).

If a provider reports a usage limit or sign-in problem, resolve it in the connector or provider account before continuing.

## Send or New chat is disabled

Check whether Bhranti is working, loading the chat, preparing an attachment or showing a connection error. You can draft your next message while it works, but sending, starting or switching chats and adding attachments wait until the task can accept input.

If Bhranti asks a question, answer in the same chat. If it is still running and you want to end it, select **Stop** and wait for the current step to finish.

## Bhranti says another request is running

Wait for the current step to finish, including work started in another browser tab. Reopen the conversation to check its saved status before sending the same request again.

If the message says **Bhranti could not secure this operation**, the server could not verify the lock that prevents overlapping changes. Try again once. If it continues, share the error code `bhranti_lock_guard` with your site administrator or hosting support so they can check server locking support.

## Bhranti is waiting for my answer

Reply in the same conversation with the missing decision, such as the audience, price, image source or whether to publish. Bhranti will wait for your answer before continuing.

## An approval-required change has not happened

Look for **Approval required**. Use the button to approve; a typed “yes” does not replace it. A plugin installation card can include activation with no second prompt. If it lists installation only, the plugin stays inactive; later status changes need a new card. For plugin changes, select **Cancel** to stop the current task. Form creation, notification destination changes, supported Trash actions and visibility changes that expose pages use **Approve/Deny**. If no card appears, ask Bhranti to show one for the named item.

Approvals expire after ten minutes. If an approval expires or the item changes, ask for a new one. Bhranti moves supported content to Trash rather than permanently deleting it. Plugin uninstall is a separate opt-in feature and can permanently remove plugin data.

## The open editor did not update

Check for unsaved work, active typing, a save/autosave in progress or another editor tab. Save or deliberately discard local changes through WordPress, then use **Resume** if offered.

Bhranti can update a supported open editor when it has no unsaved changes. Check the completed action and saved page before refreshing an editor with work you need to keep.

## A background task seems idle

Reopen the same conversation to check its status. Bhranti may be waiting for an answer, approval, an editor to close or unsaved edits to be resolved. Page-preview checks need the sidebar open. Background work also depends on WordPress’s scheduled tasks running; on a quiet site, your host may need to configure a regular scheduler.

Closing the sidebar does not stop a task. See [Working with Bhranti](/docs/working-with-bhranti/) for how background work continues.

## The connection failed

Use **Reconnect** when the sidebar asks you to reload the saved conversation. Use **Resume task** when offered to continue after an AI connection problem. Use **Resume** after resolving unsaved editor changes.

Check the affected item and **Bhranti → Logs** before asking for the same change again. Bhranti does not automatically repeat a change whose result is uncertain. [Logs and recovery](/docs/logs-and-recovery/) explains each option.

## I cannot find Undo

The button is labelled **Roll back**. Open the conversation, expand the completed-actions group and then the specific action. The button appears only for changes that can be restored. Wait for the current task to finish or stop before using it.

Supported changes can be rolled back for seven days, provided later edits do not conflict. Restoring a page leaves generated images in Media Library. Plugin uninstall and sent emails cannot be undone this way. Form notification destination changes and visibility changes that would expose pages need a new request and approval instead of Roll back. See [Approvals and Undo](/docs/permissions-and-undo/) for the full limits.

## The browser preview check could not finish

Keep the same conversation open and open the saved page through WordPress’s **Preview** while signed in. If the preview itself fails to load, resolve that page or sign-in problem first. Then ask Bhranti to check the saved page again and keep the sidebar open while it works.

Earlier saved changes remain in place. Treat the rendered check as unfinished until it returns a result; review the page before publishing. See [Review the rendered page](/docs/content-workflows/#review-the-rendered-page) for what these checks cover.

## My attachment was rejected

Use a valid PNG, JPEG or WebP file within the size and pixel limits. If the image is incomplete, export a fresh copy. You can attach one reference image or screenshot at a time; document files are not supported.

For a screenshot, choose a browser tab in the browser’s sharing picker. If tab capture is unsupported, attach a saved image instead. [Context and attachments](/docs/context-and-attachments/) lists the steps and limits.

## Image generation is unavailable

Bhranti checks whether your provider can generate an image and your WordPress site can save it. If that is unavailable, choose an existing Media Library image or upload one. A temporary provider error may be resolved without changing providers.

Upload a chat reference to Media Library separately if you want to use it on a page. A generated image stays in Media Library even if a later page edit fails.

## Settings will not save

Read the notice. Check for missing agent or skill names, instructions over the length limit, or another administrator’s saved changes. **Task step limit** must be a whole number from 12 to 1,000.

Your edits remain on the page after a save failure. **Reload saved settings** replaces them with the saved version; choose **Keep editing** if you still need to work with your changes.

## The requested action is unsupported

Check the relevant action switch and your WordPress permissions. Bhranti cannot edit source code, change user accounts or security settings, control arbitrary third-party services, install plugin ZIPs through chat, manage customer orders or transactions, or permanently delete content.

Use WordPress or the relevant plugin directly for those tasks. For Bhranti’s supported features, see [Pages, design and images](/docs/content-workflows/) and [Stores and plugins](/docs/store-and-plugins/).

## I still need help

Open **Bhranti → Logs**, set **Outcome** to **Failed** or **Needs attention**, and select **Filter**. Read the relevant event and its nearby **Conversation activity** or **Task activity**. Expand **Event details** for its error code and task ID when available.

You can ask Bhranti, “Look at the logs for this chat and explain what happened without making changes.” State “all site activity” when you want it to look beyond the current conversation.

If you need to share the sequence for support, apply the relevant date or task filters and select **Download logs (.ndjson)**. Review the file before sharing it. Include what Bhranti was waiting for: an answer, approval, an editor, the AI provider or a page-preview check. See [Logs and recovery](/docs/logs-and-recovery/) for the full steps.
