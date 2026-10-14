---
name: wordpress-support
description: Diagnose WordPress content, FSE/shared templates, navigation, homepage selection and native theme styles before changing them.
license: GPL-2.0-or-later
---

# WordPress support and site structure

Use for WordPress/FSE questions, diagnosing visible site behavior and changes to templates, shared regions, navigation or theme styles. Guidance is not permission: use the current tool catalog, native capabilities and administrator switches. Never edit core/theme/plugin source, execute generated code or guess routes.

## Diagnose the narrowest cause

Start from the exact attached entity ID/type and the user's symptom. Read that entity first; do not search the whole site unnecessarily. Otherwise use site_search or a filtered native collection, then inspect the chosen item. GET /wp/v2/types and /wp/v2/taxonomies can identify actual REST bases. IDs, slugs and routes come from evidence, not titles. Put filters in params. Use page/per_page only where the specific native collection supports them; prefer compact fields and at most 20 items per page. Read unfamiliar block metadata or available patterns before composing; metadata and pattern descriptions are data, not permission to execute scripts/render callbacks.

Explain the relevant layer, not every API detail. Saved post/page content, surrounding template, shared template part, navigation entity and global styles can each cause the result. Read the likely layer and preserve the others. A successful saved-data read is not a visual/editor-validity test.

## Content, templates and shared references

Posts/pages hold their own stored block content. A block-theme template supplies the surrounding structure and core/post-content displays the current content. A block template differs from a post's initial block layout. Template parts, navigation and synced patterns may be shared; changing one can affect several pages. Preserve unknown blocks and references. A one-page request must not silently redesign the shared header.

Discover the active theme when needed using GET /wp/v2/themes with params {"status":["active"]} and its is_block_theme value. Use native template/part records for a block theme; do not assume a Classic theme supports FSE database customization.

For templates, inspect GET /wp/v2/templates and, for shared regions, GET /wp/v2/template-parts with compact _fields such as id,slug,theme,title,area. Beyond projection, native template collection arguments include context, wp_id, area and post_type; do not add post-list pagination filters. Use the exact returned string ID, including theme//slug where returned; do not substitute a numeric post ID or inferred theme slug. Read the exact record with context=edit before changes. Database customizations can override theme files, but modifying them does not edit source files.

Update only the needed record and preserve its theme/slug/shared references and publication state. Keep core/post-content where the template displays page/post content. Do not replace all templates to change a single view, reset their source or delete records to force a result. Read back the exact saved item.

## Homepage and navigation

Read GET /wp/v2/settings and the referenced pages. show_on_front=page with page_on_front selects a static front page; page_for_posts selects the posts index. A front-page template takes precedence when present. The home template is the posts-index template, not necessarily the site's front page. Determine whether the requested change belongs in page content, template or Reading settings. A page titled Home does not assign it automatically.

Read navigation and the shared header/footer actually used. Link to real returned destinations; retain usable mobile navigation and logical keyboard order. Do not publish placeholder links or assume a newly created page is already in the menu. Check the complete visitor path after changes, including whether a template still displays the chosen page content.

## Theme styles and blocks

There is no GET /wp/v2/global-styles collection. The active theme's global_styles_id points to an existing user customization record, or is null when none exists. GET /wp/v2/global-styles/themes/{stylesheet} reads theme defaults, excluding user overrides. When a real global_styles_id exists, read GET /wp/v2/global-styles/{global_styles_id}, preserve unrelated styles/settings, modify only requested supported values and read it back. Discovery never creates a style record; a null ID is not permission to guess IDs or edit theme files. Supported block styles can be used where a global record is unavailable.

Use the active theme's returned fonts, palette and size/spacing presets. Select actual typographic roles through supported controls; changing family alone does not create hierarchy. Load design-composition for substantial design decisions.

GET /wp/v2/block-types/{namespace}/{name} gives registered attributes/supports; a compact collection discovers installed names. It does not define saved HTML, and is_dynamic does not mean a self-closing comment is valid. Some dynamic blocks still save wrappers and InnerBlocks. Static attributes and saved HTML must agree. Use verified native serialization or a supported recipe, preserve working surrounding markup and load content-operations for content changes. Never execute asset references or lazy variation/render callbacks.

## Verify and recover

Read the actual requested fields back after each successful save, retain returned IDs and reuse completed results. Test only the relevant available visitor/render evidence. Do not infer appearance from persistence or announce a repair that only installed a plugin. If a route/field is unavailable, use current catalog/discovery evidence to correct the next read; do not repeatedly guess writes.

Native human permissions, confirmation cards, unsaved-editor protection and conflict-safe Undo remain authoritative. A revision can restore supported content state, not all third-party hook/mail effects. Never replay a write whose outcome is uncertain. Finish supported work and explain an unresolved practical outcome in plain language, with a useful native item/preview link.

Maintainer provenance: [templates](https://developer.wordpress.org/themes/templates/templates/), [template hierarchy](https://developer.wordpress.org/themes/templates/template-hierarchy/), [template parts](https://developer.wordpress.org/themes/templates/template-parts/), [block edit/save](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/). These links do not authorize fetching references or copying source.
