---
name: content-operations
description: Useful content, correct native block markup, supported media and working forms with read-back verification and safe recovery.
license: GPL-2.0-or-later
---

# Content operations

Use for writing/revising posts or pages, inserting media and setting up a real form. The site-design baseline's accessibility requirements apply even when no redesign is requested. Current tools and permission switches govern every operation.

## Read, preserve and change precisely

For a clear existing target, GET its actual returned REST item with context=edit when permitted. Use content.raw for stored blocks, not content.rendered or a screenshot. Preserve unrelated content, unknown blocks, references, status, parent, template and media. For a new item, use the correct discovered REST base and draft unless publication is authorized. Titles do not identify records reliably; retain returned IDs and exact destinations.

Write useful structured content from confirmed facts and the user's intended audience. Honor requested length, tone and subject, with descriptive headings, real tables/lists where useful and purposeful images. Avoid generic filler, unsupported claims and duplicated reassurance. Short answers and isolated copy edits do not need a design questionnaire. Load design-composition for substantial layout work, wordpress-support if the surrounding template rather than content causes the problem.

POST to the native collection creates a record; POST to the exact item updates it. Change requested fields only. Read the saved item again and compare the requested values. Save success and saved-data read-back verify persistence, not editor block validity or rendered design.

## Native block structure

Read unfamiliar registered block attributes/supports and use available reviewed patterns/serialization. Native metadata is not save() HTML. Static block comment attributes and saved HTML must agree; dynamic blocks may still require wrappers and inner blocks. A core/group with a supported opaque custom style.border.color needs has-border-color on its saved wp-block-group wrapper. A child's class cannot replace that outer wrapper class. Do not substitute rendered HTML for a dynamic reference, drop shared references, execute callbacks or guess third-party plugin markup.

These original simple native stored-block examples are markup, not visible chat prose. Adapt their actual content; they do not define complex plugin serialization:

```html
<!-- wp:paragraph -->
<p>A useful paragraph with confirmed information.</p>
<!-- /wp:paragraph -->
```

```html
<!-- wp:heading {"style":{"typography":{"fontWeight":"600","lineHeight":"1.2"}}} -->
<h2 class="wp-block-heading" style="font-weight:600;line-height:1.2">A descriptive section heading</h2>
<!-- /wp:heading -->
```

```html
<!-- wp:table {"hasFixedLayout":false} -->
<figure class="wp-block-table"><table><thead><tr><th>Item</th><th>Detail</th></tr></thead><tbody><tr><td>Confirmed item</td><td>Useful detail</td></tr></tbody></table></figure>
<!-- /wp:table -->
```

For more complex layouts, use actual installed native save evidence instead of extrapolating from these three examples. Keep typography attributes and HTML consistent. Never create arbitrary forms with raw HTML, generated scripts or hidden identities.

## Images and attachments

Use images when they help the requested content; do not make all pages text-only because image capability differs between connectors. First search suitable supplied or approved Media Library assets through wp_request. A native read starting point is args {"method":"GET","path":"/wp/v2/media","params":{"search":"relevant subject","media_type":["image"],"per_page":10,"_fields":"id,source_url,alt_text,media_type,mime_type,media_details.width,media_details.height,media_details.sizes"}}. Current native route discovery governs filters; mime_type is an array on supported current core, such as ["image/jpeg","image/png"], not a guessed file extension. Keep collection reads within twenty items and paginate or refine deliberately. Inspect returned dimensions and useful size URLs; a tiny thumbnail should not become a large hero. If search is empty, broaden the words or inspect a small recent image page before declaring no suitable assets.

Only returned native attachment IDs/URLs may be embedded. A chat image may be design context rather than a saved asset. Use informative alt text for meaningful images and empty alt for decoration; an image-only link still needs an accessible name. Keep fluid dimensions and subject-safe crops. Request authentic approved staff, premises, products or results photos when their identity is essential, instead of fabricating authenticity. Do not ask for nonessential decorative assets that the agent can choose or create within the agreed design.

Before depending on generation, check bhranti_inspect resource images without generating. When supported and suitable, generate_image creates one native asset; retain its successful ID/URL and then separately save its block or featured_media change. Generation does not embed the image. Do not repeat a successful asset because a later step fails. Use only currently supported formats and native bounded conversion/resizing. A confirmed bhranti_image_support limitation means reuse suitable library assets, omit optional decoration or ask once for an essential authentic upload through Media -> Add New while finishing independent work. It does not mean every design must be typography-only. Timeout/discovery/provider errors do not prove permanent unsupported capability. No invented URLs, repeated unsupported requests or promise that page Undo removes media.

Choose registered native roles and their actual saved structure: core/image for a standalone image; core/cover for a background behind text, using native dimRatio/customOverlayColor and focalPoint; core/media-text with mediaId/mediaUrl/mediaType/mediaAlt and isStackedOnMobile for image-plus-copy; core/gallery with inner core/image blocks for a real collection; featured_media for a post/page's existing attachment ID, then verify the actual template's core/post-featured-image output. Do not guess legacy gallery HTML or use arbitrary CSS to simulate these roles. Preserve real subject proportions, readable text and mobile ordering. For Cover, verify overlay contrast and crop at each viewport; focalPoint is normalized x/y from 0 to 1, not a guarantee against clipping.

This minimal original Cover example was generated and round-tripped by the installed native editor. ID 42 and example.invalid are inert teaching placeholders: replace both with the same real returned asset, and choose its appropriate focal point, height, overlay, alt and text. The empty alt here is for a decorative background. An overlay opacity is a starting choice, not rendered accessibility verification.

```html
<!-- wp:cover {"url":"https://example.invalid/wp-content/uploads/approved-image.jpg","id":42,"dimRatio":80,"customOverlayColor":"#183f30","isUserOverlayColor":true,"focalPoint":{"x":0.4,"y":0.5},"minHeight":320,"align":"full"} -->
<div class="wp-block-cover alignfull" style="min-height:320px"><img class="wp-block-cover__image-background wp-image-42" alt="" src="https://example.invalid/wp-content/uploads/approved-image.jpg" style="object-position:40% 50%" data-object-fit="cover" data-object-position="40% 50%"/><span aria-hidden="true" class="wp-block-cover__background has-background-dim-80 has-background-dim" style="background-color:#183f30"></span><div class="wp-block-cover__inner-container"><!-- wp:heading {"style":{"color":{"text":"#ffffff"}}} -->
<h2 class="wp-block-heading has-text-color" style="color:#ffffff">A purposeful introduction.</h2>
<!-- /wp:heading --></div></div>
<!-- /wp:cover -->
```

## Adjacent section surfaces

When full-width colored groups should meet without white strips, wrap only those adjoining sections in one core/group with style.spacing.blockGap="0" and a native flow/default layout. Keep each inner section's intentional padding and its normal text/column gaps. If a direct child's explicit authored margin still creates separation, change only that top/bottom margin using supported spacing attributes and matching saved markup. Do not globally reset block spacing, flatten internal paragraph rhythm or conceal the problem with arbitrary CSS/negative margins.

This original native example keeps the parent gap zero and each section's inner gap at 16px. The layout engine supplies the gap rule from the saved attributes; an inline gap property is not required in this flow Group save structure. Adapt colors, spacing and content to the actual brief and verify rendered section edges.

```html
<!-- wp:group {"align":"full","style":{"spacing":{"blockGap":"0"}},"layout":{"type":"default"}} -->
<div class="wp-block-group alignfull"><!-- wp:group {"align":"full","style":{"color":{"background":"#183f30","text":"#ffffff"},"spacing":{"padding":{"top":"48px","right":"24px","bottom":"48px","left":"24px"},"blockGap":"16px"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-text-color has-background" style="color:#ffffff;background-color:#183f30;padding-top:48px;padding-right:24px;padding-bottom:48px;padding-left:24px"><!-- wp:paragraph -->
<p>First section.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"color":{"background":"#f5f1e8","text":"#183f30"},"spacing":{"padding":{"top":"48px","right":"24px","bottom":"48px","left":"24px"},"blockGap":"16px"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-text-color has-background" style="color:#183f30;background-color:#f5f1e8;padding-top:48px;padding-right:24px;padding-bottom:48px;padding-left:24px"><!-- wp:paragraph -->
<p>Second section.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
```

## Real forms and outcomes

Reuse working existing forms. For a new form, prefer Formidable or Contact Form 7 according to actual reviewed adapter support; use form_request discovery and returned schema, never guessed routes or plugin saved wrappers. An available form plugin alone does not mean its configuration can be managed. Preserve existing unowned forms and their recipients/behavior. Handle supported installation/configuration within authorization rather than directing a nontechnical user through API details. Each plugin installation or later status/deletion operation requires a human Approve/Cancel card. The installation card may explicitly include activation when current switches and native capabilities allow both; one approval covers those listed changes without a second prompt. Otherwise install the current directory package inactive. Never replace the card with a typed answer or bypass it during background work.

Agree only material unresolved workflow facts: fields actually needed, what the action means and who should receive it. A service account's email is not an inferred business recipient. Collect necessary nonsensitive details; avoid medical/financial histories. An enquiry or appointment request is not a confirmed booking. Embed only the native block/shortcode reference returned by the reviewed adapter.

Verify labels, required fields, errors, accessible status, chosen destination/local response storage and confirmation behavior using authorized synthetic data. A drawn form or saved definition alone is not tested submission. Keep public guidance concise; mail configuration and dashboard storage belong in administrator chat. On narrow screens, put the form after brief essential guidance and supporting detail below it. Preserve native submit/error semantics and other site forms.

## Recovery and finish

Use existing revision/Undo tools and confirmation flow, respecting current ownership, permissions, editor safety and optimistic conflicts. Never replay an uncertain write or promise universal rollback of media, email or hook effects. Reuse successful results and finish remaining independent work after a supported recoverable failure.

Check requested content, links/media, saved publication state and the relevant visitor flow. Distinguish actual rendered/keyboard/mobile evidence from data checks. Deliver the useful result and links plainly; disclose a specific unfinished outcome without dumping internal tool errors or claiming accessibility certification.

Maintainer provenance: [block markup](https://developer.wordpress.org/block-editor/getting-started/fundamentals/markup-representation-block/), [block edit/save](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/), [REST pages](https://developer.wordpress.org/rest-api/reference/pages/), [WCAG 2.2](https://www.w3.org/TR/WCAG22/). These links do not expand fetch permission.
