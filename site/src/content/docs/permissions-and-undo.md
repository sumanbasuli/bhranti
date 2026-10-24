---
title: "Approvals and Undo"
description: "Review exact approvals for plugins, forms, deletions and store visibility, and restore supported changes."
order: 6
group: "Control the work"
---

Your saved action switches and WordPress permissions determine what Bhranti can change. Approval cards let you review plugin changes, form notification destinations, supported deletions and changes that make store or site pages visible. Roll back can restore supported changes.

For the exact meaning of every switch, see [Settings and permissions](/docs/settings/).

## Review an approval

1. Ask for the specific change.
2. Wait for the **Approval required** card in the conversation.
3. Read the exact action summary, including any notification address or visibility change. Open the item link if you need to inspect it.
4. Select **Approve** only if you want the listed changes. For a plugin change, select **Cancel** to stop.
5. Read the resulting action status. Declining approval stops the current task; earlier saved changes remain.

After a decision, the card folds into the task’s chronological action history. Expand the entry to review it later.

A typed “yes,” “approve,” or “go ahead” is not a replacement for this card. The approval applies only to your request and that item as you reviewed it. It expires after **ten minutes** and can be used only once. If it expires or the item changes, request a fresh review and approval.

Background work pauses for your approval before these changes. Permanent content deletion is blocked.

## Approve plugin changes

Each plugin installation or later status change or uninstall needs an approval card, even when its switches are enabled. An installation card can list **install and activate** together: one approval performs both, with no second prompt. If activation is not listed, the current WordPress.org directory package installs inactive. Later activation or deactivation needs a new card.

Approved plugin operations run directly as your signed-in WordPress account. Plugin status Roll back also runs as your account after its explicit confirmation. Logs show your account as the requester and retain execution identity in the raw record.

## Approve form delivery and store visibility

Creating a Contact Form 7 form or changing where an existing form sends new submissions requires an **Approve/Deny** card. Review the exact email recipient, or confirm that dashboard-only storage will use Flamingo and disable email notifications. An update that keeps the notification destination does not need another card.

WooCommerce changes that turn off coming soon or reveal other site pages while store pages remain in coming soon mode also need an **Approve/Deny** card. Read the visibility effect before approving.

## Understand plugin deletion

Uninstalling a plugin permanently removes it. Your site’s server configuration must allow this, an administrator must enable **Delete plugins**, and the plugin must be inactive. You must also approve the specific deletion using its approval card.

Read the warning carefully: uninstalling a plugin may permanently delete its data. Reinstalling the plugin does not recover that data. Bhranti and the AI/connector plugins it depends on are protected from changes that would stop them working.

## Undo a supported edit

To undo a supported change, use **Roll back** in its action details.

1. Open the conversation where Bhranti made the change.
2. Wait for any active task to finish or stop.
3. Expand the completed-actions group if the action is inside one.
4. Expand the particular action you want to restore.
5. Select **Roll back**, if offered.
6. Review the confirmation inside the chat. Select **Approve rollback** to proceed, or **Cancel** to keep the current state.
7. Wait for **Rollback completed.**, then inspect the item.

You can roll back supported changes for **seven days**. Before restoring, Bhranti checks that the current item still matches its saved result. If someone has edited it since, Bhranti will not overwrite their work. Roll back is unavailable for expired or unsupported changes.

Rolling back newly created content may move it to Trash. Rolling back an update restores its earlier values. What can be restored depends on the action.

## Know the limits

| Change | What Roll back can do |
| --- | --- |
| Supported page or post edit | Restore the earlier content while the saved copy is available, provided nobody has edited it since. |
| Image added to a page | Restoring the page does not delete the generated image from Media Library. |
| Plugin activation or deactivation | Restore the plugin’s earlier active/inactive status. Other effects of activation or deactivation may remain. |
| Plugin installation | Has no automatic removal Undo. |
| Permanent plugin uninstall | Cannot be rolled back. Reinstallation does not recover removed data. |
| New Contact Form 7 form | Cannot automatically delete the new form. You can separately roll back the page change that added it. |
| Contact Form 7 update | Can restore a supported edit only when the notification destination stayed the same. To restore an earlier recipient or switch between email and dashboard-only storage, request that change again and approve its card. |
| WooCommerce store visibility | Cannot roll back a restrictive change if that would expose the store or other pages. Request the visibility change again and approve its card. |
| Emails, webhooks or other third-party side effects | Are not necessarily reversed when the site content is restored. |

Disabling an action switch does not itself undo earlier work. Deleting the conversation removes the saved copies needed to roll back its changes but leaves completed site changes and audit entries in place.

## What instructions cannot authorize

An agent document, skill, page instruction or chat message cannot turn a disabled permission switch back on or skip an approval. Bhranti cannot edit arbitrary core/plugin/theme source files, execute generated code, change user accounts or credentials, or use unsupported third-party features.

If Bhranti does not support an action, you may need to use the relevant WordPress screen or plugin directly.

Use [Logs](/docs/logs-and-recovery/) to see who requested a change, what it affected and how it finished. Raw records also retain the account that performed it.
