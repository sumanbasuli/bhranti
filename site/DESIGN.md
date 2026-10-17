# Bhranti design

## Visual thesis

A capable helper in familiar surroundings: WordPress charcoal and blue, presented with the spacious type and careful pacing of a small editorial studio.

## Source and assets

- The Bhranti sparkle mark is the existing locally bundled Bootstrap Icons path from `includes/class-bhranti-panel.php`. The website reuses that exact path in `Mark.astro` and its favicon.
- The WordPress mark is the official Gutenberg `wordpress.svg` path, shared through `WordPressMark.astro` and used beside WordPress copy in the home introduction, demo, and download requirements.
- The product walkthrough is reconstructed HTML, explicitly labelled as an illustrative interface using a fictional site.
- Secondary diagrams explain page/block context, action receipts, and Markdown agents. These are also labelled illustrations or examples.
- No stock photography, invented customers, testimonials, or unverified adoption metrics.
- Typography uses local system sans fonts and Georgia; no font network request or new font dependency.

## Tokens

- Canvas: `#1e1e1e`, matching the plugin sidebar.
- Surface: `#282828`; quiet section surface: `#232323`.
- Text: `#f0f0f1`; muted: `#b4b9be`.
- Border: `#45494d`; softer decoration: `#383b3e`.
- Accent: WordPress blue `#72aee6`; dark blue ink `#172635`.
- Light blue editorial band: `#b8d6ee`, with ink `#213747`.

## Type and geometry

- Body: system sans, 16px/1.6. Long marketing copy is usually 14–18px with restrained measures.
- Display: system sans at 48–89px with tight tracking. Georgia italic supplies a warm secondary phrase rather than an additional decorative layer.
- Secondary headings: 36–60px; small headings: 20–26px.
- Code and examples: system monospace, used only for real Markdown instructions.
- Container: 1200px maximum, 40px desktop gutters, 20px narrow-screen gutters.
- Navigation: 88px desktop height, 74px mobile; sticky with a solid-enough charcoal background.
- Borders carry grouping. Most geometry is square; rounds are reserved for meaningful marks/status controls and the native demonstration.
- Large sections have 100–116px vertical spacing, reducing to 64px at mobile widths.

## Composition

- Home begins with a split editorial headline and introduction, followed by the full product demonstration.
- A native WordPress introduction leads into three flat capability columns, a context diagram, and a human-control explanation.
- A light blue agent section creates one deliberate change in tone before the closing action.
- Product uses an anchored overview, the demo, asymmetric explanatory sections, an aligned capability table, an illustrative receipt, and a Markdown agent sample.
- Documentation inherits Base, typography, tokens, navigation, focus, and footer but owns its reading layout.
- Guides use real plugin screenshots as linked Markdown images, followed immediately by an italic Markdown caption. `DocsProse` renders the caption in a quiet upright style and adds only a thin edge to the image. Full-size links preserve native browser zoom; no separate image viewer is required.
- Use a complete editor screenshot for orientation, then focused native panels for individual instructions. Write alt text for the useful interface state and place the explanation in nearby text, so the screenshot is supporting evidence rather than the only instruction.
- Numbered instructions retain native list semantics. Reference tables use compact row rules and become keyboard-scrollable when their columns exceed the reading width.

## Interaction and accessibility

- No autoplay or scroll animation. Hover and focus feedback are short and restrained.
- Visible 2px focus ring, semantic landmarks, descriptive links, skip link, and native mobile `details` navigation.
- Mobile navigation supports Escape and closes after link activation or an outside click.
- All content reflows; grids stack at narrow widths. Long technical content must allow wrapping or scoped scrolling.
- Reduced-motion preference disables smooth scrolling and transitions.
- The demo’s actual keyboard and tab interaction is owned by ProductDemo.astro.
- Product examples are explicitly distinguished from real live site activity.
- No accessibility certification claim. Verify the rendered desktop/mobile interface and meaningful keyboard flows before delivery.

## Content contract

- Each public page links to its complete Markdown counterpart in its head and visible footer.
- Guide Markdown resolves root-relative image and link URLs against the configured canonical site. The HTML keeps relative image paths for local preview.
- Primary CTA is “Download Bhranti” at `/download/`, with the setup guide as the secondary action.
- The download page uses a full-width WordPress-blue hero. Its download action and matching Markdown link use `src/data/release.ts`; the requested temporary `#` value must be replaced with the published release ZIP URL.
- Do not add a public repository link or release availability claim without confirmed evidence.
- Version and compatibility requirements use `src/data/release.ts` as their source of truth.
