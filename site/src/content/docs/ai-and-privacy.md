---
title: "AI and privacy"
description: "See what is shared, where it is stored, and how to export or erase it."
order: 11
group: "Help and privacy"
---

Bhranti sends requests to the AI provider configured in **Settings → Connectors**, using WordPress’s AI Client. Manage provider sign-in and connection approvals there. Available models and features depend on your connection. Bhranti stores no separate connector key, collects no telemetry and loads its interface assets from your site.

Opening the sidebar does not contact the AI provider. Sending a request can use your connected account’s allowance or incur provider charges.

## Review sharing before you send

1. Open Bhranti.
2. Expand **You’re chatting with AI. Details.** below the message box.
3. Review your page/block chips, image preview and any retained Reference image chip.
4. Remove context or attachments you do not want to include.
5. Send when the message and attachments are ready.

Your provider’s terms apply to the information you send. Check chats, screenshots, links and instructions for passwords, keys or personal information you do not intend to share.

## If you paste a credential

Bhranti checks text for recognizable passwords, API keys, access tokens, private keys and sign-in URLs before saving a message or sending it to AI. If it finds one, it shows a warning and leaves your message in the box so you can remove it. The blocked message is not saved to chat history or browser session storage. Instructions in Settings are checked before saving, too.

A second check runs on the server before each AI request, including background steps. Sensitive text found in a tool result is withheld from the conversation copy while completed actions keep their recorded outcome.

These checks cannot recognize every secret or read secrets inside screenshot pixels. Review attachments yourself. Use **Settings → Connectors** to connect an AI account; never paste its credentials into Bhranti.

Older conversations are not automatically erased. If one contains a recognized credential, Bhranti blocks further requests from that chat. Start a new chat without it, delete the old chat when appropriate, and rotate any real credential that was previously shared.

## What can go to the provider

A request may include:

- Your message and up to **12 recent conversation messages**.
- Basic site information, text and HTML from an attached page, and selected-block context.
- Explicitly attached screenshots or reference images.
- Relevant site content, results of searches and changes, and saved task notes.
- System prompt, guardrails, selected agent and skill instructions.
- Relevant saved preferences and information about supported image features.
- Image-generation prompts when an image is requested.
- Text from public design references and results from page layout and accessibility checks, when used for the task.
- Relevant activity-log metadata when you ask Bhranti to investigate its actions or errors. This can include action times, usernames, affected item IDs and recorded results, but not other people’s private chat messages.

Bhranti may read a limited amount of text and structure from a public HTTPS reference you provide. It does not sign in or send your browser’s cookies, and reading the page does not capture its appearance as a screenshot.

## What is stored in WordPress

Conversations, plans, action results, task references, rollback records and Logs are stored in your WordPress database. Bhranti’s chat interface shows only your own conversations. Site administrators can see activity records in Logs, and people with database or server access can access the stored data.

**Stored conversations are not encrypted.** Site-wide prompts, agents and skills remain saved until you change them or uninstall Bhranti. Copies of the instructions and settings used for a conversation are included when you export or erase that conversation’s data.

Unsent text drafts are kept separately for each conversation in the current browser tab. Unsent images stay in browser memory until sent or removed and may be lost when you reload or close the tab. Sent reference images are stored with the private conversation or task; they are not automatically published or added to Media Library.

## How private memory differs

Preferences you explicitly ask Bhranti to remember, along with a limited set of checked image capabilities, are encrypted separately in the WordPress database for your account on this site. Chat still works if your server does not support private memory.

Review and reset your own memory in **Bhranti → Settings → Memory**. [See the memory guide](/docs/memory/) for the exact controls and what a reset removes.

## Export your personal data

Bhranti is included in WordPress’s personal-data export.

1. Open **Tools → Export Personal Data** in WordPress.
2. Enter the relevant user’s email address and follow the request process.
3. Follow WordPress’s confirmation and export flow.
4. Review the export, which includes Bhranti records and memory alongside data from other participating plugins.

Use these WordPress tools to export your personal data; the Bhranti sidebar has no separate chat-download button.

For site activity, administrators can use **Bhranti → Logs → Download logs (.ndjson)**. That download contains all events matching the applied filters, with one JSON record per line and timestamps in UTC. It excludes prompts and credentials but includes usernames and actions, so review it before sharing. [Logs and recovery](/docs/logs-and-recovery/) explains the filters and download.

## Erase or delete records

Use **Tools → Erase Personal Data** to request erasure. This process includes Bhranti’s conversation records and memory for that user.

For one chat, open it, select **Chats → Delete this chat**, and review the permanent-deletion confirmation. Deleting the conversation removes its private work plan and rollback records. It does not undo the site actions or erase their audit entries.

Use **Reset memory** to remove saved preferences and capability information while keeping your chats and site content.

## Uninstall Bhranti

Uninstall removes Bhranti’s private workspace data and saved preferences. Site content, authorship and existing user accounts stay in place.

Uninstall leaves completed site changes in place, including content edits, published pages and plugin changes. If you want to restore a supported earlier state, use **Roll back** before uninstalling.
