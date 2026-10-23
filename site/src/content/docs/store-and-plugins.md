---
title: "Stores and plugins"
description: "Set up supported WooCommerce features and manage WordPress.org plugins."
order: 10
group: "Make something useful"
---

Bhranti can help with selected WooCommerce features and plugin changes. Your WordPress permissions, Bhranti’s action switches and any required approvals apply throughout.

## Plan a store before changing it

For a new store, provide the products, audience, currency, selling locations, shipping approach, payment choices and launch expectations. Bhranti should ask for missing details before installing tools or changing the site.

> Help me set up a small store for three physical products. Keep products as drafts while we work. Ask me for the product facts, prices, shipping regions and payment choices before changing anything.

You can ask Bhranti to install WooCommerce from WordPress.org. You will still need to connect any payment accounts yourself.

## Create and update simple products

The WooCommerce integration supports **simple, non-downloadable products**, product categories and selected product fields. It can search existing products, create drafts, update supported content and assign existing Media Library images.

1. Provide the product name, description, price and other verified facts.
2. Choose existing Media Library images or upload images for your products.
3. State whether the product should stay a draft or may be published.
4. Review the saved product and its action results.
5. If you ask to move a product to Trash, review its **Approve/Deny** card.

New products default to draft unless you ask to publish them. Bhranti can create and update supported product categories. Product images must already be in Media Library; supplying an image URL does not import it.

Bhranti can inspect other product types, but cannot generally edit them. Use WooCommerce directly to edit variable products or delete product categories. Orders, customer records, financial transactions and features added by other store plugins are outside this integration.

## Configure supported store settings

Bhranti can inspect and change selected business, country, currency, catalog, unit, inventory and store-page settings. Name the setting or business decision you want help with.

For example:

> Review our store currency and weight unit. Tell me the current values before changing them.

Available changes depend on your installed WooCommerce version and the settings Bhranti supports. **Change site settings** and your WordPress permissions must allow the change.

## Set up shipping and cash on delivery

Supported shipping setup includes zones and locations, with **Flat rate**, **Free shipping**, and **Local pickup** methods. Provide the regions, rates and conditions. Bhranti supports simple numeric costs, not cost formulas. Use WooCommerce directly to delete existing zones or methods. **Roll back** may remove one Bhranti just created if it is unchanged and nothing else depends on it.

For **Cash on delivery**, Bhranti can help enable or disable it and edit supported text and restrictions. Restricting it to particular shipping methods is available only when your WooCommerce settings offer those choices.

Set up other payment gateways and account connections yourself. Before launch, test checkout and confirm that the correct shipping and payment choices appear.

## Change store visibility deliberately

Bhranti can change WooCommerce’s coming-soon and store-page visibility options. Turning off coming soon or revealing other site pages while the store remains in coming soon mode requires an exact **Approve/Deny** card. Review what visitors will see before approving.

If your store has an assigned Cart page, that page must be valid and published. WooCommerce may republish it during the visibility change, so Bhranti also checks the permissions to edit and publish it. A Cart page assignment is not always required.

State whether visitors should see the store while you work and which products may be published. A **Roll back** that would expose the store or other pages is unavailable; request that visibility change again and approve its new card.

## Install or activate a plugin

1. Name the plugin and why it is needed.
2. Ask Bhranti to inspect installed plugins first.
3. Review the installation card for that specific WordPress.org plugin. Check whether it lists **install and activate** or installation only.
4. Select **Approve** to perform exactly the listed changes, or **Cancel** to stop. Installation with activation uses one approval, with no second prompt. Installation alone leaves the current directory package inactive.
5. Review the recorded results, then complete any account connections or other setup.

Later activation or deactivation needs its own approval card. **Cancel stops the current task**. Resolved decisions fold into the task history in order and remain expandable. Approved plugin changes run as your signed-in WordPress account; Logs identifies you as the requester and retains execution identity in the raw record.

Bhranti supports plugin changes on single-site WordPress installations. **Install plugins** allows installation from WordPress.org; use WordPress directly for ZIP uploads and plugin updates. **Activate or deactivate plugins** controls activation changes separately. Bhranti cannot deactivate or delete itself or the AI plugins it depends on.

## Deactivate or delete a plugin

Deactivation and deletion are different operations, each requiring its own **Approve/Cancel** card. Deactivation changes status; deletion permanently uninstalls an eligible inactive plugin.

**Delete plugins** is disabled by default. An administrator can enable it in **Bhranti → Settings**. Bhranti then asks you to approve each uninstall through an **Approve/Cancel** card. Uninstalling can permanently remove plugin data; reinstalling does not recover it.

Explicit plugin Roll back runs as your account and restores the plugin’s active or inactive status. It does not reverse emails or other changes the plugin made while starting or stopping. See [Approvals and Undo](/docs/permissions-and-undo/) for restoration limits.
