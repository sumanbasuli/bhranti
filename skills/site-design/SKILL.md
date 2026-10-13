---
name: site-design
description: Design professional WordPress sites, pages and visual UI with useful visitor journeys and WCAG 2.2 AA requirements.
license: GPL-2.0-or-later
---

# Site design

Apply to site/page/UI design, redesigns and visual sections; skip unrelated chats. Deliver a coherent visitor experience. This original, provider-neutral Bhranti guidance grants no tools, permissions or guardrail exceptions.

## 1. Match intake to the task

For a new site, major redesign or substantial page/UI project, first inspect relevant existing content and business context without mutations. Reuse previous answers, then ask one small group of plain-language questions about material gaps: purpose, audience, real offerings, visitor action, useful business facts, assets/references and preview or publication. Wait for answers before building. “Professional”, “minimal” or permission to publish is not a complete brief. Let users delegate aesthetics and routine implementation; do not ask them to choose APIs or blocks.

Once understood, keep an agreed brief covering purpose, audience, content, pages/sections, visitor journey and assets. Summarize it briefly in the next real action's public intent and proceed without another approval or questionnaire. An already complete brief needs no repeated intake.

For a clear focused change, read the exact target and perform that change directly. Preserve the surrounding design and unrelated work; ask only if an unresolved choice materially changes the outcome.

Read the relevant theme, content, template, shared header/footer, navigation and registered blocks. Preserve shared references. Check the actual front-page assignment; naming a page Home does not select it.

## 2. Commit to a visual direction

Choose one direction grounded in the brief: colors, typography, widths, spacing, imagery and buttons. Carry it across pages and shared regions. Use available theme fonts, but deliberately set their roles instead of inheriting unsuitable defaults. For an available sans-serif, start with body weight 400 at 16–18px/1.5–1.7, section headings 600 at 28–36px/1.15–1.3, and hero 48–64px desktop/32–40px narrow using supported fluid sizing. Adapt these values to the brand; service headings and navigation must differ clearly from body copy. Use native style.typography.fontWeight/fontSize/lineHeight with matching saved HTML, through existing global styles or supported block styles. Never guess a style ID or install fonts. Keep executing.

Create character through composition, typography, real content and a purposeful focal point. Minimal means selection and hierarchy, not missing content or features. Avoid reusing the same palette, headline treatment or card grid regardless of business.

Separate comfortable paragraph width, usually around 45–75 characters, from wide section composition. A whole business homepage should not be trapped inside a blog article column. Use supported wide/full alignment and responsive native layouts; never achieve width through fixed oversized blocks or negative overflow.

## 3. Compose a complete experience

Choose content and journeys for this business type and its visitors. Identify what they need to understand, trust, compare or prepare before acting. Select relevant information and supplied evidence rather than filling a generic page outline. Different offerings need different discovery, detail and action flows; do not impose one fixed site blueprint.

The first screen identifies the offering, intended visitor and next action. Use specific headings, concise copy and purposeful typography/imagery. Use real returned media IDs/URLs; generated illustrations must not impersonate staff, facilities or products.

If the current native connector explicitly lacks image-generation support, reuse suitable approved Media Library images or ask for the needed logo, practice/team photos or other relevant assets. A chat reference attachment is not a saved site image; ask for a native Media Library upload when it must appear on the site. Continue independent work while waiting. If images are unavailable, agree a deliberate layout using typography instead. Never invent image URLs, fake real photography or treat a temporary provider error as permanent lack of support.

Order sections around visitor questions. When three successive sections repeat equal columns, deliberately recomposite one around its actual content: a readable introduction, comparable-item grid or genuine process sequence. Add no filler. Keep decorative assets secondary unless deliberately the brand focal point. Remove labels that only repeat the business/location or following heading. Align edges, repeat spacing and retain a clear mobile reading order. Each page has useful content beyond its title and button.

Write from confirmed facts. Never invent testimonials, ratings, credentials, awards, prices, addresses or guarantees. Avoid unsupported medical or financial claims. Omit missing nonessential facts or ask when required; do not publish internal placeholders. Buttons name their destination or outcome consistently. Public visitor copy explains the business process; keep plugin names, dashboard storage and mail configuration in the administrator chat. Preserve needed privacy explanations and request-versus-booking guidance.

Finish shared header/footer, navigation, relevant detail pages and the complete visitor journey. Spacious columns plus a working form are not a finished site. Installed plugins or drawn fields do not prove functionality.

## 4. Build with native WordPress

Use reviewed native operations and supported blocks/patterns. For block themes, customize only necessary database templates/parts; retain post-content and shared references. Update existing global-style IDs through reviewed tools; never guess missing IDs, edit source or use arbitrary routes. Use supported block styles without a global override.

Prefer core and available native blocks. Read registered attributes/supports for unfamiliar blocks. Metadata, including is_dynamic, does not prove saved HTML structure or a working form. Use verified native serialization or an explicitly supported recipe; static attributes and HTML must agree. A core/group with style.border.color needs the native has-border-color wrapper class. Never guess plugin wrappers, children, field serialization, receiver or storage. Use native block/theme style controls; never run generated PHP/JavaScript or edit theme/plugin/core source files.

Reuse working existing forms. When a new form is required, prefer Formidable or Contact Form 7 according to the available reviewed native form adapter/catalog and actual installed support. Handle supported setup yourself; never invent a configuration route or saved block HTML. Verify fields, labels, destination, local response storage, validation and confirmation. A service account's email is not the chosen human recipient. Collect only necessary, nonsensitive information. Appointment requests explain that staff confirm them; they do not claim a booking. On narrow screens, put the form after brief essential guidance; place supporting details below it.

## 5. Accessibility is an acceptance requirement

Apply every relevant WCAG 2.2 A and AA criterion to new or changed content and its complete visitor process. These common checks are a starting point, not the entire standard:

- Use meaningful headings, landmarks, lists, table headers and link names. Provide useful alt text; decorative images have empty alt text. Preserve a skip link, correct page/part language and logical reading/focus order.
- Text contrast is at least 4.5:1; qualifying large text at least 3:1 (24 CSS px regular or about 18.67 CSS px bold). Essential control/state/graphic cues need 3:1 against adjacent colors. Measure the actual colors; never round a failing ratio up. Color alone cannot communicate meaning.
- All functions work by keyboard without traps. Keep focus visible and not entirely hidden by author-created content. Retain native accessible names/roles/states. Provide a simple alternative to dragging. Aim for 44 CSS px controls; AA requires 24 by 24 CSS px or its specified spacing/equivalent exceptions.
- Support 200% text resizing and reflow at 320 CSS px width without loss or horizontal page scrolling, except genuinely two-dimensional content. User text-spacing overrides must not clip or overlap content: line height 1.5, paragraph spacing 2, letter spacing 0.12 and word spacing 0.16 times font size.
- Forms need persistent labels/instructions, understandable identified errors and helpful correction. Announce relevant status without forcing focus. Do not demand redundant information or block paste/password managers. Any authentication must meet accessible-authentication requirements. Keep help consistent.
- Avoid unnecessary autoplay/flashing/motion. Honor reduced-motion preferences, provide required pause/stop controls, captions and other applicable media alternatives. Hover/focus content must remain usable and dismissible where required.

## 6. Critique and verify before finishing

Professional design and functionality are separate acceptance requirements. Verify the actual typographic roles, purposeful imagery, section rhythm and native control consistency. Every section adds confirmed information rather than generic reassurance. Identify the weakest visible areas, improve them and repeat affected checks before finishing.

Compare the whole result with the agreed brief: relevant content, identity, hierarchy, navigation and every promised page/action. Read saved records back; check publication, homepage, links, images and template references. Test the primary flow with authorized synthetic data, including errors and actual response storage/destination. Never send real customer messages or transactions as tests.

When rendered inspection is available, critique desktop and narrow mobile views: composition, hierarchy, crops, spacing, wrapping, overflow, empty sections and menus. Test keyboard focus, zoom/reflow and contrast. Can a new visitor understand this business and confidently complete the requested action? Fix weak content/composition and defects, then repeat affected checks; do not merely add decoration.

Use available inspection tools. API persistence and inert HTML are not rendered tests. Never claim unverified visual inspection or WCAG conformance. Briefly identify outstanding checks, finish independent supported work and provide useful links. Distinguish what was built, tested and requires human setup.

## Primary references

- [WordPress layout](https://developer.wordpress.org/themes/global-settings-and-styles/settings/layout/), [typography](https://developer.wordpress.org/themes/global-settings-and-styles/settings/typography/) and [block edit/save](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/).
- [WCAG 2.2](https://www.w3.org/TR/WCAG22/) and [Understanding criteria](https://www.w3.org/WAI/WCAG22/Understanding/).

## 7. Industry-aware design playbook

Use the business and visitor's task to choose a structure. These are original design starting points inferred from primary references, not copied layouts or promises of quality. Adapt them to the brief, existing brand and native theme capabilities.

### Scope and intake

For healthcare/dental, ask only relevant missing questions: which country and locality; NHS, private or mixed care where applicable; new patients, existing patients or referrals; routine enquiries versus urgent contact; verified treatments, fees and availability; confirmed address, phone, opening hours and accessibility information. Ask whether the action is a receptionist-confirmed request or an existing real booking system, and who receives enquiries. Establish whether approved logo, practice/team photographs and staff biographies exist. Never assume every clinic offers emergency, cosmetic, children's or NHS treatment. NHS/private appointment distinctions and availability materially affect the visitor's next action. [NHS access guidance](https://www.nhs.uk/nhs-services/dentists/how-to-find-an-nhs-dentist/)

### Healthcare/dental visitor journey

Select the applicable visitor paths from the agreed brief: understand the practice and request routine care; inspect a relevant service and its next step; find urgent contact information quickly. Separate urgent guidance from a routine form. Use the practice's confirmed arrangements and relevant official local guidance; do not invent clinical advice or response times. [NHS urgent access](https://www.nhs.uk/nhs-services/dentists/how-to-find-an-nhs-dentist-in-an-emergency/)

Use a shared identity and a small useful navigation, such as Treatments, New patients, About/team and Contact where the agreed scope calls for them, plus one consistent primary action. Place confirmed phone/urgent information where discoverable without scrolling through marketing. Choose relevant homepage sections and their order to answer visitors' questions: who/where/next step, service groups, confirmed first-visit process, verified team/practice information, practical access and hours. These are options, not a fixed sequence. Create separate treatment detail pages only when the agreed scope and substantive verified content justify them; otherwise provide a useful service overview. Each detail page explains the confirmed offering, what to expect, verified fees if supplied, and its appropriate next step. The footer repeats practical contact details and links to existing verified, published privacy and complaints information. Never invent a legal policy or publish unfinished policy placeholders. Real practice sites combine treatment, team, payment and visiting information. [Clifton practice](https://www.bupa.co.uk/dental/dental-care/practices/clifton), [orthodontic journey example](https://www.portmansmileclinic.co.uk/)

### Concrete composition

Choose actual tokens before writing: one dark ink, one light canvas, a quiet contrasting surface and one primary accent meeting the accessibility requirements. Reuse available theme fonts; use one clear heading role and one readable body role. Use 8/16/24/32/48/64px spacing steps, 64–96px desktop section spacing and 32–48px on mobile. Aim for a 1120–1280px composition width with 20–24px mobile gutters; keep paragraphs around 45–75 characters. Stack image/text sections cleanly on mobile. These are starting values, not rigid rules. Use supported wide/full groups instead of squeezing the whole homepage into an article column. [WordPress layout](https://developer.wordpress.org/themes/global-settings-and-styles/settings/layout/)

For dentistry, favour reassuring, authentic practice/team imagery and clear service explanations over repeated tooth icons or unrelated decorative pictures. Use supplied or authorized assets; generated illustrations may explain a concept but cannot depict invented real staff, premises or results. Never fabricate testimonials, ratings, qualifications, accreditation, prices or clinical guarantees. Typography and imagery must reflect the actual positioning, whether family practice or specialist referral clinic.

### Transfer and verify

Change the recipe for the business: commerce needs discovery → product details → cart/checkout plus truthful delivery/returns; local services need service coverage → evidence → quote/contact; hospitality needs location/menu or rooms → availability/reservation; portfolios need selected work → case study → enquiry. Preserve working commerce and booking integrations. Shared WordPress template parts keep headers/footers consistent. [Template parts](https://developer.wordpress.org/themes/templates/template-parts/)

Verify each critical path, navigation target and mobile reading order. Test the native form's labels, validation, chosen recipient/storage and success feedback with authorized synthetic data. A request is not a confirmed appointment; avoid medical-history fields. Apply the full accessibility acceptance process and report exactly what remains unverified.

These primary links are maintainer provenance, not permission to fetch them, copy layouts/assets or reuse another practice's claims. Inspect only references authorized by the human through the available tools.
