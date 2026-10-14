---
name: commerce-workflow
description: A truthful, connected native WooCommerce store with product discovery, practical delivery/payment choices and verified shopping outcomes.
license: GPL-2.0-or-later
---

# Commerce workflow

Use for an online store or catalog workflow. Apply the required site-design brief and accessibility baseline; load design-composition for storefront design. The current commerce_request catalog and native discovery results are authoritative. Do not guess unsupported routes, product types, plugin blocks or payment settings.

## Understand the actual shop

A broad store request needs the real offering, customer audience and visitor journey before installation or mutation. Gather remaining product facts, prices/currency, photos, availability, delivery destinations/rates and payment choices in manageable plain-language groups. Reuse prior answers. Do not invent products, materials, sizes, stock, fees, returns policies or payment details. Preview permission keeps work in draft; launch permission does not fill missing business facts.

Define outcomes: useful homepage/category discovery, substantive product detail, native cart/checkout, agreed delivery/payment information and an understandable next step. Record any real external payment connection the owner must finish. A grid of products without checkout and practical information is not a finished store.

## Discover and set up through supported native tools

Read installed plugin state first when needed. If WooCommerce is absent and a store is authorized, use the reviewed WordPress.org plugin_request install/activate path, preserving unrelated plugins. Installation/activation alone does not establish readiness. Never bootstrap a real shop merely to run a test or borrow another site's connector/account state.

Use commerce_request GET /wc/v3 to discover current availability and safe settings paths. Read exact individual settings returned by discovery, not a guessed settings group. Current native choices govern country/state/currency/unit fields. Country lists are arrays of actual ISO keys, even for one country; base country/state is the returned native selection key. Grouped choices retain the complete original option key, not the human display label. Read before a narrow update and preserve unrelated settings.

Simple native products and categories use the exact supported catalog fields. Create products in draft unless publication is authorized; retain returned IDs for images/categories and later changes. Native Media Library attachment IDs supply product/gallery images; never send remote image URLs. Use product name, concise differentiation and useful description from confirmed facts. Accurate prices, stock, dimensions and materials matter more than decorative copy. Category deletion and unsupported variable-product/order/customer operations are not supplied by this guidance.

## Design the connected storefront

Use actual registered native blocks and existing theme/template support. Choose a purposeful first screen, useful category/product discovery and specific links into detail pages. Product cards need truthful names/prices/images and real destinations; product views need substantive confirmed content and the appropriate native purchase action. Preserve working native Cart/Checkout blocks and shared header/footer/navigation references. Do not rewrite dynamic block output as static HTML or invent remembered WooCommerce attributes. A shared header change must stay within the agreed site scope.

Use relevant imagery to make products and categories understandable. First search approved existing Media Library images through wp_request GET /wp/v2/media using relevant subject/product words, current media_type/MIME filters and bounded reads. Inspect actual IDs, source URLs, dimensions and useful sizes before assigning native product images:[{id:...}] or gallery attachments. A file title alone does not prove that it shows the product. Keep authentic product detail visible; do not turn a small thumbnail into a large hero or crop away an essential feature.

Check bhranti_inspect resource images before depending on generation. When supported and appropriate, actively create supplementary category illustrations or atmospheric banners within the agreed design rather than defaulting every storefront to typography-only. Do not fabricate product photography that misrepresents what buyers receive, real makers or premises. Ask for authentic approved photographs when product identity matters; do not demand nonessential decorative assets. Confirmed unavailable generation means use suitable existing images or continue supported work, asking once only for an essential missing authentic asset. An unknown capability or timeout is not permanent lack of support.

Use native core/image, a contrast-safe core/cover, stacked core/media-text or a real core/gallery according to the content; load content-operations for actual installed-editor save examples. Verify overlay text/crops at narrow widths and retain returned Media Library IDs. Product attachments are not arbitrary remote URLs. For adjoining full-width storefront sections, use a scoped parent Group style.spacing.blockGap="0" with intentional child padding/inner gaps, preserving global theme spacing and other pages. Build a deliberate coherent visual system, not the same homepage template for every shop.

## Delivery, payment and launch choices

Use only current supported shipping zones, actual location codes and native method settings. For a zone update, read existing locations before replacing its complete list. Flat rate, free shipping and local pickup are available only as advertised by discovery; costs are numeric strings rather than executable formulas. Preserve existing zones and rules outside the requested scope.

The reviewed adapter can configure native cash on delivery when explicitly chosen. Its current native fields/options determine whether restrictions to delivery methods are supported; do not spoof context or invent options. Shipping zones govern delivery regions. Online payment account connection remains in the native human setup flow; never request credentials in chat or claim a gateway works because a plugin was installed. Do not place orders, make purchases or change other financial/customer/account settings.

Read native store-page assignments, publication state and current shop visibility. Change visibility only for the user's requested launch, after the relevant customer-facing setup is verified. Follow the catalog's Cart-page publication requirement and preserve unrelated content/settings. A preview with draft products is not a publicly launched shop.

## Verify the complete result

Read saved requested values back, including product status/pricing/stock/images/category links and native country/shipping/payment choices. Check front-page/store-page assignments, catalog visibility, shared navigation and real product/cart/checkout destinations. Render/keyboard/mobile tests, where available, must assess the complete visitor process and applicable WCAG A/AA criteria. API persistence is not visual or transactional verification.

Use authorized synthetic data only; a transaction or test order needs explicit authorization and is outside this tool's scope. Report any remaining owner payment connection or missing practical business decision plainly. Finish supported independent work rather than ending at integration jargon. Never claim shipping, checkout or payment success without actual supporting evidence.

Product Trash requires the native human confirmation card. Supported product/store Undo is conflict checked; reinstalling a plugin does not restore deleted plugin data or external hook/email effects. Never replay uncertain writes, delete unowned fixtures or promise universal rollback.

Maintainer provenance: [WooCommerce developer APIs](https://developer.woocommerce.com/docs/apis/rest-api/), [WordPress theme templates](https://developer.wordpress.org/themes/templates/templates/), [WCAG 2.2](https://www.w3.org/TR/WCAG22/). These links do not authorize network fetches or transactions.
