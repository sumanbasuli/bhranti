---
title: "Approvals and Undo"
description: "Review destructive actions and restore supported changes with Roll back."
order: 6
group: "Control the work"
---

Your saved action switches and WordPress permissions determine what Bhranti can change. Approval cards let you review deletions, and Roll back can restore supported changes.

For the exact meaning of every switch, see [Settings and permissions](/docs/settings/).

## Review a Trash approval

1. Ask for the specific item to be moved to Trash.
2. Wait for the **Approval required** card in the conversation.
3. Read the action summary and check the named item. Open the item link if you need to inspect it.
4. Select **Approve** to allow that action, or **Deny** to decline it.
5. Read the resulting action status.

A typed “yes,” “approve,” or “go ahead” is not a replacement for this card. The approval applies only to your request and that item as you reviewed it. It expires after **ten minutes** and can be used only once. If it expires or the item changes, request a fresh review and approval.

Bhranti cannot approve a deletion for you while running in the background. Permanent content deletion is blocked.

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
6. Review the browser confirmation. Confirm to proceed, or cancel to keep the current state.
7. Wait for **Rollback completed.**, then inspect the item.

You can roll back supported changes for **seven days**. Before restoring, Bhranti checks that the current item still matches its saved result. If someone has edited it since, Bhranti will not overwrite their work. Roll back is unavailable for expired or unsupported changes.

Rolling back newly created content may move it to Trash. Rolling back an update restores its earlier values. What can be restored depends on the action.

## Know the limits

| Change | What Roll back can do |
| --- | --- |
| Supported page or post edit | Restore the earlier content while the saved copy is available, provided nobody has edited it since. |
| Image added to a page | Restoring the page does not delete the generated image from Media Library. |
| Plugin activation or deactivation | Restore the plugin’s earlier active/inactive status. Other effects of activation or deactivation may remain. |
| Permanent plugin uninstall | Cannot be rolled back. Reinstallation does not recover removed data. |
| New Contact Form 7 form | Cannot automatically delete the new form. You can separately roll back the page change that added it. |
| Emails, webhooks or other third-party side effects | Are not necessarily reversed when the site content is restored. |

Disabling an action switch does not itself undo earlier work. Deleting the conversation removes the saved copies needed to roll back its changes but leaves completed site changes and audit entries in place.

## What instructions cannot authorize

An agent document, skill, page instruction or chat message cannot turn a disabled permission switch back on or skip an approval. Bhranti cannot edit arbitrary core/plugin/theme source files, execute generated code, change user accounts or credentials, or use unsupported third-party features.

If Bhranti does not support an action, you may need to use the relevant WordPress screen or plugin directly.

Use [Logs](/docs/logs-and-recovery/) to see who requested a change, which account made it, what it affected and how it finished.
