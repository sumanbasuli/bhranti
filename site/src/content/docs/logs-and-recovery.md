---
title: "Logs and recovery"
description: "Understand site activity, search and download logs, and continue after an interruption."
order: 12
group: "Help and privacy"
---

Use **Bhranti → Logs** to understand what happened on your site, who asked for it and how each step finished. The conversation follows your current task; Logs keeps a separate site-wide activity record for administrators.

## Find an action

1. In the WordPress admin menu, open **Bhranti → Logs**.
2. Enter an action, username, item ID or error code in **Search activity**.
3. Use **Outcome** to narrow the results, such as **Failed** or **Needs attention**. Use **Activity** to choose a kind of work, such as **Site content** or **Images & media**.
4. Choose **20**, **50** or **100** in **Events per page**.
5. Select **Filter**. Use **Reset** to return to all activity.

Events appear newest first. The count above and below the results shows the visible range and the total matching events. Use the numbered pages, **Previous** or **Next** to read more results while keeping your filters.

[![Bhranti Logs with search and filters, readable activity summaries, outcomes and user identities.](/images/docs/activity-logs.jpg)](/images/docs/activity-logs.jpg)

*Find the action, read its outcome and explanation, then follow related activity when you need the wider sequence. Select the screenshot to view it at full size.*

### Narrow by date, person or task

Open **More filters** for:

- **From date** and **To date** — include activity on and between those dates, using your site's timezone.
- **Requested by (user ID)** — match the person who requested the work.
- **Conversation ID** — match the activity belonging to one chat.
- **Task ID** — match one submitted task within a conversation.

User IDs appear beside names in each event. Task IDs appear in **Event details** when recorded. Select **Conversation activity** or **Task activity** on an event to apply that filter without copying an ID. These links open **Raw logs** for that conversation or task and clear other filters so you can see its full recorded activity. Select **All site activity** to return to the wider log.

Select **Filter** after changing the fields. If there are no matches, try a wider date range, remove a filter or select **Reset**.

## Understand each event

Read the activity title and explanation first. The row may also include an affected item and a suggested next step. **Event details** holds labelled technical information, such as the exact event time in UTC, event or task ID, WordPress route and error code, for closer investigation or support.

| Outcome | How to read it |
| --- | --- |
| **Completed** | This recorded step finished successfully. |
| **Failed** | This step could not finish. Read its explanation and nearby events before repeating anything. |
| **Needs attention** | The event needs review, such as an interrupted task or an action that was stopped. |
| **Started / waiting** | A step started or the task was waiting at that point. Check later events for what happened next. |
| **Information** | A recorded update that does not itself claim a site change. |

Each row is one event, rather than the final status of an entire conversation. A step may have a start event and a later result. A failed AI request may be followed by a successful retry. Read the sequence before deciding that a task is still stuck or that a completed change needs repeating.

Earlier records remain as they happened. A later recovery adds its own event; it does not turn the original failure into a success. Older records may have fewer details than newer ones.

### See who requested the work

The **User** column identifies the person who requested an action. Current Bhranti actions and explicit Undo run through that person's signed-in WordPress account. Older events can have a different acting account; the raw record retains that identity. Task and action details show which work Bhranti performed on your behalf.

**Conversation activity** shows the related audit records. **Task activity** narrows them to a particular task when its ID is available. **View chat** reopens the messages only for your own conversations that still exist. Seeing another person's site activity does not give you access to their private chat.

### Read the raw records

Select **Raw logs** above the results to see the recorded JSON, including timestamps, original event names and messages, user identities, and available task, action, route and error details. The same search, filters and pagination apply. Use **Summary** to return to the readable table.

You can also expand **Raw log** on a single event in the summary. Raw records use UTC timestamps and exclude prompts, full page content and credentials. **Download logs (.ndjson)** saves all matching records across every page.

## Ask Bhranti to explain the logs

Open the relevant chat and ask a plain-language question, for example:

> Look at the logs for this chat. What went wrong, and did it recover? Please explain without changing anything.

Bhranti checks the current conversation's activity by default. For a wider question, state that you mean the whole site:

> Check all site activity from yesterday. Which tasks failed, and what should I do next? Do not make changes.

You can also name a date, affected item or task ID to help it find the right events. Bhranti can explain recorded actions, outcomes, people and suggested next steps. Reading the logs does not edit site content or settings, or expose other people's private chat messages. Relevant activity metadata is shared with your configured AI provider so it can answer; see [AI and privacy](/docs/ai-and-privacy/).

A log cannot establish an unrecorded cause or prove that every part of a page or workflow works. Bhranti should distinguish what the records show from what still needs checking.

## Download logs

1. Search or filter the activity you want to include.
2. Select **Filter** to apply those choices.
3. Select **Download logs (.ndjson)** at the top of the screen.
4. Save the downloaded file. Open it in a text editor or a tool that supports NDJSON if you want to inspect or share it.

The download includes **all matching events**, across every results page. Events per page affects the screen, not the number of downloaded records. Reset the filters first if you want all site activity.

NDJSON stores one JSON record per line. The file includes the recorded event, requester and acting-account identifiers, outcome, activity category and available technical details. For current actions, those identifiers normally match; older events can differ. Exported timestamps use **UTC**, even when the screen displays your site's timezone. This is an activity-log download, separate from WordPress's personal-data export.

Logs exclude prompts, full page content and credentials. They still contain usernames, actions and site information, so check the file before sharing it.

## Reconnect after a browser or network problem

If the sidebar shows **Reconnect to continue**, select **Reconnect**. It reloads the conversation so you can see whether your request was received and what completed.

A request may reach WordPress before the connection fails. Check the affected item and Logs before sending the same change again.

Bhranti can retry loading the chat after a brief connection failure. It does not automatically repeat a site change whose result is uncertain.

## Resume a paused provider request

Bhranti can retry some temporary AI connection failures after a countdown. You may see **Retrying connection in…**, **Continuing in smaller steps…**, or **Continuing saved task in…**.

If the task reaches **Connection paused** with **Resume task**:

1. Read the error and check **Settings → Connectors** if the provider needs attention.
2. Review the work already recorded in the conversation.
3. Select **Resume task** when the connector is ready.
4. Follow the continued task in the same chat.

**Resume task** appears only when Bhranti can safely continue. Resolve provider sign-in or usage-limit problems in the provider’s settings. Review any pending approval separately. If a change may already have happened, check its result before asking again. Earlier completed work stays saved when a later step fails.

## Resume after protecting an editor

An editor-related pause is different from a provider pause. If Bhranti asks you to save or discard local changes:

1. Check the current editor and other tabs editing the affected item.
2. Save or deliberately discard your local work through WordPress.
3. Return to the chat and use **Resume** when offered.

Bhranti may still pause for unsaved work after its editor tab closes. Check that your edits are saved or no longer needed, then use **Resume** when offered.

## Understand a partially completed task

Open **Work plan**, if present, and inspect the outcomes and checks. A task can retain completed work while marking a remaining outcome blocked or needing review.

If it stopped at the planning-step limit, review what remains and send a new message to continue. **Resume task** cannot extend that limit. Changes to **Task step limit** apply to future tasks.

If Bhranti asks you to start a new chat because the conversation is full, include a short description of the remaining work and links to the saved items. The earlier conversation’s actions and notes remain available there.

## Gather useful information for support

Find the matching event and expand **Event details** to copy its error code, time and task ID when available. Include the affected item and whether Bhranti was planning, making a change, waiting for approval or paused for an editor.

For a longer sequence, filter to the relevant conversation, task or dates and use **Download logs (.ndjson)**. Review the file before sharing it, and leave out passwords, keys and unrelated private content. [Troubleshooting](/docs/troubleshooting/) gives short next steps for common messages.
