---
title: "Pages, design and images"
description: "Practical requests for content, layout, media, forms and page checks."
order: 9
group: "Make something useful"
---

Bhranti can help you edit pages, organize content and improve your site’s design. Name the page, describe the result you want and say what should stay unchanged. Be specific when a change affects shared templates or navigation.

## Improve an existing page

1. Open the page and save any local edits.
2. Keep the page context attached. Select a block if the request concerns one part of the page.
3. Describe the change and say whether the page should stay a draft or may be published.
4. Review the completed actions and saved page.
5. Use **Roll back** if offered and you want to restore the supported prior state.

For example:

> On this draft, shorten the introduction to two paragraphs, keep the opening hours unchanged, and leave it unpublished.

Supported work includes posts, pages and other supported content types on your site. Bhranti can work through a long page section by section. It checks for changes to the saved content before updating it and may need to read it again if someone edits it during the task.

## Build a new page or redesign

Give Bhranti the page’s purpose, audience, required content, desired next action and publication status. Supply real business facts and appropriate brand assets. For larger work, answer its questions in the same chat before changes begin.

> Create a draft services page for our existing business. Use the services and prices already listed on the site. Ask me about anything missing, and use the existing contact form.

Bhranti uses WordPress blocks already available on your site and aims for clear structure and useful visitor paths. It can change supported templates, template parts and global styles when **Manage templates and design** and your WordPress permissions allow it.

Shared template or style changes can affect more than one page. State the intended scope and review other affected areas. Bhranti does not edit theme, plugin or WordPress core source files.

## Work with navigation and comments

For navigation, name the menu or navigation area, destination, label and desired order. Bhranti can inspect existing navigation and make supported menu-item changes. Create new classic menus and categories in WordPress first when you need them for this workflow.

For comments, identify the comment or describe which ones to review. Changes require **Manage comments**. Moving a comment to Trash also requires **Move content to Trash** and your approval.

## Use existing media

Ask Bhranti to find suitable items already in Media Library and use them in the intended role: a featured image, content image, gallery or other supported block.

Describe what the image should show. Supply your own photos when it needs to depict a particular person, product or place accurately.

Upload images for your pages through **Media → Add New Media File** or the page editor’s media controls. Images attached in chat are used as references and are not automatically added to Media Library.

## Generate an image when supported

1. State the image’s subject, intended role and preferred shape.
2. Let Bhranti check whether your connected provider supports image generation.
3. Review the generated Media Library item and its actual dimensions.
4. Review the page separately after the image is placed.

For example:

> Generate a landscape illustration of a small community garden for this draft’s opening section. Use a 16:9 shape if supported, and write useful alt text.

Bhranti uses a format and shape supported by your provider and WordPress site. If your requested shape is unavailable, it may use a similar shape or the provider’s default. Check the dimensions reported in the result. A temporary provider error may be resolved without changing providers.

If generation is unavailable, choose an existing Media Library image or upload one. Generated images stay in Media Library if a later page edit fails or is rolled back, so you can reuse them when continuing the work.

## Add or reuse a form

Ask Bhranti to look for an existing working form first. It can create and update supported forms with **Contact Form 7**. If the plugin is missing, ask Bhranti to install and activate it; the relevant permission switches must be enabled. Review the installation card: if it lists activation too, one approval covers both. Otherwise later activation needs its own card. To save submissions in the WordPress dashboard without sending email, request dashboard-only storage with **Flamingo**.

Provide the fields, labels, purpose and confirmation message. For email notifications, include the recipient’s business email address in your request. For dashboard-only storage, Flamingo must be active. Creating the form or changing its notification mode or recipient pauses for an exact **Approve/Deny** card showing where new submissions will go.

> Add a contact request form to this draft. Ask for name, email and a message. Send notifications to the address I provide, and show a clear success message after submission.

Forms can have 1–12 fields using name, email, text and select types, with 1–20 choices per select field. Bhranti adds the form to a page using its shortcode. Reading submissions, editing custom HTML or email templates, file-upload fields, autoresponders and form deletion are outside this integration.

Bhranti can update a form configuration it previously saved for you, provided nobody has changed it since. It can reuse other existing forms without rewriting them. Form updates that keep the same notification destination and changes to the page embedding have separate **Roll back** actions. A new form has no automatic deletion rollback. To restore an earlier email recipient or switch between email and dashboard-only storage, request the change again and approve its card.

Before using the form, send a test submission and confirm that the email arrives or the entry appears in the dashboard.

## Review the rendered page

Bhranti can check a saved post or page in WordPress’s preview while you are signed in. Keep or reopen the sidebar while it waits for the check. It checks desktop and mobile sizes of **1280 × 900**, **390 × 844**, and **320 × 844** for issues such as content extending beyond the screen, broken images, broken links to sections on the page and automated accessibility findings.

You still need to review the design, try keyboard navigation and important controls, test forms and checkout, and check the page while signed out. Automated checks do not establish full WCAG accessibility conformance.

Review the page at desktop and mobile widths before publication. If Bhranti changes a shared template or style, also check the other pages it affects.
