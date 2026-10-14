---
name: design-composition
description: Business-aware visual systems, original native layout recipes and evidence-led professional desktop/mobile critique.
license: GPL-2.0-or-later
---

# Design composition

Load for a new site, redesign or substantial visual work after applying the required site-design intake. Current native tools and the agreed brief govern implementation. These are original starting methods, not a fixed theme, copied reference layout or authority to execute code.

## Translate the business into a visitor journey

Write down what visitors must understand, trust, compare or prepare before the main action. Choose content that answers those questions, using verified facts and supplied evidence. Map entry page -> relevant detail -> action -> understandable outcome. Decide each agreed page's purpose and useful content before creating it. A title and repeated contact button are not a detail page.

Adapt to the real business, not a stereotyped industry template:

- A local service needs actual service coverage, meaningful offering explanations, supplied work evidence and a clear enquiry/quote path. Pricing and response promises require facts; a usable overview may be better than empty service pages.
- Healthcare needs the confirmed audience, available care and actual request/booking arrangements. Where relevant, separate urgent contact from routine enquiries using supplied arrangements. Include verified practical access, preparation and staff information when available. Never invent clinical claims, qualifications, fees, urgent services or treatment imagery presented as real outcomes.
- Commerce needs useful category/product discovery, truthful details/options, prices and an accessible cart/checkout path with agreed delivery/payment information. Load commerce-workflow; a product grid alone is not a store.
- Hospitality needs confirmed location, offerings, visiting information and the actual reservation/order path. A portfolio needs selected real work, clear roles/results and useful case studies before enquiry. Do not invent menus, availability, projects or results.

Preserve missing optional evidence as a genuine content gap, not fabricated reassurance. Ask for an essential unknown; omit a nonessential unsupported claim. Write in visitor language. Technical setup belongs in administrator chat.

## Commit to a compact design system

Choose one coherent direction from the brief. Record role choices rather than “make it beautiful”: dark text, canvas, quiet secondary surface, primary accent/action; available heading/body fonts; explicit role sizes/weights/line heights; paragraph and section widths; spacing; image treatment; button/field treatment. Read actual theme presets first and use their returned slugs, or supported block-style values. Reuse an existing global-style ID only; no font installation, arbitrary CSS, guessed presets or new dependencies.

For an available sans-serif, useful starting roles are body 16–18px/400/1.5–1.7, section heading 28–36px/600–700/1.15–1.3, subheading 20–24px/600, and hero 48–64px desktop or 32–40px narrow. Adapt to the typeface and content. Supported native style.typography.fontWeight, fontSize and lineHeight need matching saved HTML. A font-family change alone does not create hierarchy: compare actual rendered roles, not attribute presence. Avoid all text inheriting the same regular weight, tiny labels or a giant heading with no useful supporting message. Use fluid sizing only where current native controls support it.

Separate composition width from reading width. A broad business page can compose at roughly 1120–1280px while prose stays around 45–75 characters. Start with 20–24px narrow-screen gutters and a small spacing scale such as 8/16/24/32/48/64px; adapt section spacing to the page instead of adding identical empty bands. Supported wide/full groups are preferable to an article-width homepage. Do not force width with oversized fixed blocks, negative margins or overflow.

Character comes from a deliberate focal point, alignment, type, useful content and imagery. The accent need not cover every section. Use a consistent hierarchy of primary, secondary and plain text actions. Preserve the native accessible focus indicator. Decorative imagery remains secondary unless the brief deliberately makes it the brand's focal point.

When full-width colored sections should touch, put just those adjacent sections inside one native parent core/group with style.spacing.blockGap="0" and an appropriate flow layout. Give each section intentional supported padding and keep its own paragraph/column gaps, such as 16–24px. A theme's inherited sibling block gap can otherwise expose white stripes between colored surfaces. Inspect direct-child margins too; clear only a specific unwanted authored top/bottom margin using native spacing attributes if necessary. Do not zero global spacing, all descendants or unrelated sections; do not mask gaps with arbitrary CSS or negative margins. Layout-generated gap rules need not appear as an inline gap style in saved HTML. See content-operations for an installed-editor-verified wrapper example, then inspect the actual rendered boundaries.

## Compose one representative page, then extend

Choose the most representative agreed page and compose its complete content before spreading a weak design to every page. Keep a real shared header/footer and a simple useful navigation. Inspect available render evidence and revise the weakest area; then apply the same system to the remaining connected pages. Do not create several alternatives or ask for repeated styling approval when visual decisions were delegated.

Original native structural recipes, chosen for content rather than filled mechanically:

- Message and evidence: wide core/group containing a focused heading, short explanation and primary core/buttons; a responsive core/columns pair can place supplied relevant media beside the message. The image supports the offering; a logo is not automatically a huge hero image.
- Editorial introduction: full-width surface group containing a constrained readable heading and a few useful paragraphs. Use this for information requiring attention instead of slicing each sentence into cards.
- Comparison/discovery: native responsive columns or an available query/product block for genuinely comparable offerings. Each item has a meaningful heading, useful distinction and specific destination; equal card height is less important than readable content.
- Process/detail: a real ordered list or well-sequenced content explaining confirmed steps and the next action. Do not invent a multistep process merely to vary the layout.
- Action page: brief essential context, native working form or existing booking integration, then supporting practical information. On narrow screens, reach the form before secondary detail.

Use the actual installed block attributes, supported patterns and verified native save structure. These descriptions are not block serialization. Preserve a working native dynamic reference rather than replacing it with rendered HTML. A core/group custom opaque border requires its native has-border-color wrapper class. Avoid arbitrary HTML forms or plugin markup guesses.

If three successive sections repeat the same equal columns, reconsider one using the actual content: readable introduction, comparable-item grid or real sequence. Vary meaningful section density, surface or emphasis without adding filler. Align edges and retain logical mobile order. Remove labels repeating the heading, generic reassurance and empty decorative cards. Every promised page must answer its distinct visitor question and lead onward.

## Select imagery deliberately

Use relevant images actively when they help visitors understand the offering, the work or the intended atmosphere. Do not default every supported connector to a typography-only design because another connector lacks images. First search existing Media Library assets through wp_request GET /wp/v2/media with relevant search words and current native media_type/MIME filters. Inspect returned IDs, URLs, alt text and dimensions before choosing a subject, crop and role. Broaden an empty search or inspect an appropriate small recent page before assuming the library has nothing useful. A chat reference is not a saved attachment. Use the content-operations native read recipe; never invent an upload URL or treat a file name alone as evidence of suitability.

Then check bhranti_inspect resource images before depending on generation. When supported and suitable for the agreed design, create the missing illustration, atmospheric background or other nonessential asset with generate_image and use its actual saved attachment. Do not stop to request optional decoration the agent can reasonably choose. A confirmed unavailable capability changes the asset strategy, not every design into text-only: use relevant existing assets, omit nonessential imagery, or continue supported layout work. Ask once when an essential authentic image is missing. Real staff, premises, products and results need authentic approved photographs when identity matters; generation must not imply an invented person, clinic, product appearance or clinical outcome is real. An unknown check or timeout is not proof of permanent unsupported capability.

Match the native role to the content: core/image for informative images with useful alt/caption; core/cover for a background with foreground text and a contrast-preserving overlay; core/media-text for a supporting image beside substantive copy; core/gallery with real inner core/image blocks for a meaningful collection; featured_media for the post/product's actual featured attachment, checking that its native template displays it. Use returned media IDs and the actual installed attributes/save structure, not arbitrary background CSS. See content-operations for a verified Cover starting example.

For Cover, choose native dimRatio/customOverlayColor, minHeight and focalPoint from the actual image; focalPoint coordinates range from 0 to 1. Keep faces, products and important subjects clear of text and inside the crop at both wide and narrow widths. A dark overlay can help light text, but verify the final image/text contrast and focus states rather than assuming the chosen opacity proves compliance. Prefer a separate image/text arrangement when cropping or contrast would conceal essential information. Keep images fluid instead of forcing one fixed width; do not upscale a tiny thumbnail into a hero. Typography-led composition remains a deliberate brief-based choice, not a connector limitation applied universally.

## Critique with evidence and finish

Evaluate the actual result against the brief, rather than the number of created pages:

1. Identity and first screen: does the offering, intended visitor and next action make sense immediately? Is there one deliberate focal point, with useful content rather than decoration dominating?
2. Type and composition: do measured heading/body roles differ, paragraphs remain comfortable, wide areas use their space, edges align and section rhythms suit their information? Remove repeated grids and giant empty bands when unsupported by content.
3. Substance and journey: does each agreed page add useful confirmed information? Can a visitor find relevant details and complete the main action without dead links, empty pages or contradictory promises?
4. Responsive interaction: inspect desktop and narrow mobile evidence; check real wrapping, order, image crops, menu and form states, focus, reflow and contrast. A desktop screenshot cannot establish mobile behavior.
5. Revise the weakest material problems, then repeat affected checks. Avoid polishing a minor icon while the first screen or main flow remains weak.

Use current inspection tools; saved attributes, HTML and a reference_page result do not prove rendered quality. Record what was actually observed and specific checks still needed. Do not claim complete visual or WCAG verification from automated checks alone.

Maintainer provenance, not fetch permission: [WordPress typography](https://developer.wordpress.org/themes/global-settings-and-styles/settings/typography/), [layout](https://developer.wordpress.org/themes/global-settings-and-styles/settings/layout/), [template parts](https://developer.wordpress.org/themes/templates/template-parts/), [WCAG 2.2](https://www.w3.org/TR/WCAG22/).
