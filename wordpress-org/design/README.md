# Listing artwork source

`banner.svg` is the editable banner at 1544 × 500. It embeds the original `screenshot-1.jpg` and uses Bhranti's existing mark, charcoal `#1e1e1e`, WordPress blue `#72aee6`, Helvetica/Arial and Georgia. Both PNG banner sizes share this composition. The icon uses the same mark, with a self-contained SVG and two PNG fallbacks.

## Export the artwork

Use Node.js 22 or later. The export script uses Sharp from the website dependencies. If those dependencies are not installed, run `npm ci` inside `site/` first. This is only needed to regenerate the artwork; the normal plugin build uses the checked-in images.

From the repository root:

```sh
node wordpress-org/design/build-assets.mjs
```

This writes the five deployable banner/icon files to `wordpress-org/assets/` and updates the editable `banner.svg` here. Keep this directory separate from the listing assets package.

Optional arguments select a different source checkout and export directory:

```sh
node wordpress-org/design/build-assets.mjs /absolute/path/to/bhranti /absolute/path/to/output
```

The exporter reads the exact sparkle path from `site/src/components/Mark.astro` and the unchanged screenshot from `wordpress-org/assets/screenshot-1.jpg`. Font rendering can vary across systems; inspect both banner sizes after regeneration. The exported PNGs have no font dependency.

For licenses and image credits, see [the listing assets README](../README.md).
