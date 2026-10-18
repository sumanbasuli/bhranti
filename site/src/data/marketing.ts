import { release } from './release';

export const productWalkthrough = `## A small edit. The whole picture.

**Illustrative interface · Fictional sample site.** This interactive preview changes only its fictional example. It does not connect to a WordPress site.

The preview shows a WordPress editor beside the Bhranti sidebar. Its tabs are **01 Context**, **02 Edit**, and **03 Review**. Edit is the initial view.

### The fictional page

Home · Draft

**form&field.** — The studio · Our work · Visit us ↗

INDEPENDENT CREATIVE STUDIO

**Good things take shape.**

A space for curious minds, considered design, and new beginnings.

Selected Paragraph: Our doors open **24 October.** The original date is **17 October.**

Come say hello ↗

The accompanying illustration is a terracotta vase with foliage, captioned “Room to grow.”

Thoughtfully made. Open to possibility.

01 — THE BEGINNING

Editor status: Draft saved. Page → Group → Paragraph.

### The conversation: A new beginning

**You:** Move our opening day to 24 October. Keep the tone and layout as they are.

### 01 Context

**Bhranti:** I found the opening date on your Home page. It’s in the introduction, just above “Come say hello”.

**Read current page**

Home · Paragraph block

“Our doors open 17 October.”

The page and selected block provide the context for this edit.

Caption: Your page. Your blocks. Shared context.

### 02 Edit

**Bhranti:** The opening date is now **24 October**. Your wording and layout are unchanged, and the page is saved as a draft.

**Updated Home · 1 change**

Opening date:

- Removed: 17 October
- Added: 24 October

Draft saved.

Editor note: Opening date updated. The rest stays yours.

Caption: A focused change, in the editor you know.

### 03 Review

**Bhranti:** One paragraph updated on **Home**. The action record keeps the change and its outcome together.

**Page updated**

Home · Saved as draft

Opening day: 17 → 24 October

**Undo demo edit** restores the original date in this preview. After Undo, the record reads “Opening day restored to 17 October”, the editor note reads “Original date restored”, and the control becomes **Reapply demo edit**.

Try Undo to restore the original date in this preview.

Caption: See what changed. Restore when needed.

### Preview composer

Context: Home · Paragraph

What would you like to do?

Preview only · Explore with the tabs above
`;

export const siteFooter = `---

**Bhranti.** A helping hand for your WordPress.

Bhranti for WordPress ${release.wordpress}.

- [Explore the product](/product/)
- [Read the documentation](/docs/)
- [Download Bhranti](/download/)

Made for WordPress. Made for your ideas.

Bhranti ${release.version} · WordPress ${release.wordpress} · PHP ${release.php}
`;
