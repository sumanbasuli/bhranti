# Future TODO: native JavaScript block generation and validation

Status: Deferred; not implemented. Recorded 22 October 2026.

## Goal

Improve generated blocks by asking the AI for structured block data and letting the installed WordPress JavaScript runtime produce and validate the saved markup. Keep the existing PHP harness responsible for permissions, approved actions, sanitization, persistence, audit and recovery.

This is a future design note, not a description of current functionality or authorization to implement it.

## Current approach and limitation

Bhranti currently asks the model to produce Gutenberg markup. The PHP harness reviews the action and content, applies revision-checked section patches where appropriate, and saves through native WordPress REST controllers. JavaScript refreshes a clean editor from saved records without creating unsaved edits.

PHP can produce valid block markup. The difficulty is reliably matching every installed block's JavaScript `save()` implementation. Parsing markup, passing safety checks, saving successfully or checking the rendered page does not establish full Gutenberg editor validity.

## Proposed flow

1. Ask the model for bounded JSON data containing block names, attributes and nested blocks. Never accept generated JavaScript or PHP to execute.
2. In the correct installed editor runtime, discover available block definitions and create blocks using native `createBlock()`. Verify attribute types and unsupported values rather than silently dropping the model's intended changes.
3. Use native `serialize()` to generate markup. Parse it again and recursively inspect validity, including every `innerBlocks` descendant. Use supported native validation APIs and check that the round trip preserves the requested content and structure.
4. Return bounded, specific validation errors to the AI for a limited correction cycle. Keep successful earlier work and generated media; do not repeat them during repair.
5. Submit the resulting markup through the existing PHP action pipeline. Retain current permissions, sanitization, revision/conflict checks, editor protection, approvals, audit and Undo. Account for any server filtering that changes the markup after JavaScript validation; browser evidence must never bypass server checks.
6. Refresh only the matching clean editor after an accepted saved change. Validate and serialize separately from the live editing canvas; do not insert temporary blocks, trigger autosaves or overwrite unsaved work to perform validation.

## Decisions to resolve before implementation

- **Background work:** WP-Cron runs in PHP and cannot use a closed browser's editor runtime. Define an explicit pending-validation/resume strategy, including which independent task steps can continue. Do not silently remove background support or save unvalidated content as a fallback.
- **Available runtimes:** the Dashboard, frontend and scheduled requests do not automatically have an initialized editor. Decide how a narrowly scoped native validation worker obtains the correct registrations and settings without rebuilding the whole editor or requiring Node.js on ordinary hosting.
- **Compatibility:** use the site's actual WordPress and installed block versions. Handle dynamic blocks, third-party blocks, theme-dependent support, existing unknown blocks and shared references deliberately. Initial support may need a tested subset.
- **Job integrity:** bind any browser result to the exact site, user, task step, candidate content and saved revision. Handle stale results, duplicate submissions, Stop, browser closure and permission changes safely.
- **Separate evidence:** editor-valid blocks do not prove good design, accessibility, correct links or a working visitor journey. Keep rendered and interaction verification distinct.

## Reference prototype

The separately supplied `block-generator` ZIP was reviewed as source only; it was not executed or integrated. Its `serialize-blocks-headless.cjs` uses pinned WordPress packages under Node/jsdom:

`JSON tree → createBlock → serialize → parse → validity check → serialize again`

It also contains pattern and navigation examples. Its explicit `isValid` filtering checks top-level blocks; a Bhranti implementation must inspect nested children too. Its pinned core runtime does not establish compatibility with every live site or installed plugin. Treat it as an architectural reference, not a drop-in dependency; review licensing before reusing code.

## Future acceptance checklist

- [ ] Agree on the structured-data schema, supported blocks and bounded repair policy.
- [ ] Prove serialization and recursive validation against the actual native editor.
- [ ] Test paragraphs, rich text, headings, images, nested Groups/Columns, Cover, Gallery and navigation, including malformed nested blocks and unknown types.
- [ ] Test attribute preservation, dynamic/shared references and changes made by server sanitization.
- [ ] Test dirty editors, concurrent edits, stale jobs, Stop and close/reopen recovery without repeated writes or regenerated images.
- [ ] Resolve background execution before changing the existing task contract.
- [ ] Verify saved read-back and relevant rendered behavior; document the actual supported scope.

## Official references

- [WordPress blocks API: creation, serialization and validation](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-blocks/)
- [Block edit/save contract and editor validation](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/)
- [Stored block markup](https://developer.wordpress.org/block-editor/getting-started/fundamentals/markup-representation-block/)
