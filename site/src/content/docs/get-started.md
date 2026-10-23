---
title: "Get started"
description: "Install Bhranti, connect your provider, and send your first request."
order: 1
group: "Start here"
---

Bhranti opens beside WordPress, so you can ask for help where you are already working. Begin with a small read-only request. You can try an edit once you have seen how the conversation and action history work.

## Before you begin

You need:

- **WordPress 7.0 or later** and **PHP 8.0 or later**.
- An administrator account. First-time setup also needs permission to add users.
- A compatible text provider configured in WordPress’s native **Settings → Connectors** screen.

[Download the Bhranti plugin ZIP](/download/), then follow the installation steps below.

## 1. Install and activate the plugin

1. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
2. Choose the Bhranti ZIP and install it.
3. Activate Bhranti when installation finishes.

If you manage plugins through the filesystem, place the Bhranti folder in `wp-content/plugins`, then activate it from **Plugins**.

Use the installable Bhranti release ZIP. GitHub’s automatic **Source code** archives contain the development checkout and are not the installation package. The [public GitHub repository](https://github.com/sumanbasuli/bhranti) contains the source, build instructions and issue tracker.

## 2. Connect your AI provider

1. Open **Settings → Connectors** in WordPress.
2. Follow your chosen provider’s setup instructions.
3. Complete any connection approval your site asks for.

Manage your provider connection in Connectors. Available models and features depend on that connection. You do not need to enter an API key in Bhranti Settings or install the separate WordPress AI plugin.

## 3. Open the sidebar

Select **Bhranti** in the WordPress admin bar. In a post or page editor where the admin-bar opener is not available, look for the Bhranti button in the editor header.

Bhranti uses your existing WordPress account. It does not create another user. Your current WordPress permissions and the action switches in Settings determine what it can do. Logs identify the account that requested each action.

The sidebar header includes **Chats**, **New chat**, **Bhranti Settings**, and **Close Bhranti**. Point at an icon, or focus it with the keyboard, to identify its control.

[![The Bhranti sidebar with Chats, New chat, Settings and Close in its header, and a Page context chip above the composer.](/images/docs/chat-sidebar.jpg)](/images/docs/chat-sidebar.jpg)

*Use the plus in the header to start a chat. Use the plus beside the message box to attach the current page. Select the screenshot to view it at full size.*

Open **You’re chatting with AI. Details.** below the message box to review what a submitted request can share. Opening the sidebar alone does not contact your AI provider.

## 4. Ask a read-only question

1. Open the page or admin screen you want to work with.
2. Check the context chips above the message box. A page chip and, in the block editor, a selected-block chip may be present.
3. Type a specific request in **Message Bhranti**.
4. Select **Send message**, the upward-arrow button, or press **Enter**. Use **Shift+Enter** for a new line.

Try:

> Find the About page and tell me its current heading. Do not change anything yet.

Bhranti shows what it is about to do, followed by the result. Expand a completed action to inspect its details or open the affected item.

## 5. Try a focused edit

Open a draft page, save any local changes, then ask:

> On this draft, replace the opening sentence with “A small team making useful things.” Keep everything else and leave the page unpublished.

Review the saved page and the completed action. Where the action supports Undo, its expanded details offer **Roll back**. [Learn exactly what can be restored](/docs/permissions-and-undo/).

For a new site, store or major redesign, expect Bhranti to ask about the audience, content and decisions it cannot infer. Answer in the same chat. Small, clearly specified edits do not need a long setup conversation.

## Choose your next guide

- [Chats and the sidebar](/docs/chats/) — start, find and delete conversations.
- [Context and attachments](/docs/context-and-attachments/) — control the page context and images you send.
- [Settings and permissions](/docs/settings/) — understand every switch before larger work.
- [Working with Bhranti](/docs/working-with-bhranti/) — follow actions, plans and background tasks.
