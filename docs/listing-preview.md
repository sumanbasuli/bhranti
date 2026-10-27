# Preview the WordPress.org listing locally

From the repository root, run:

```sh
python3 scripts/preview-listing.py
```

Open **http://127.0.0.1:4331/**. Edit `readme.txt` or the images in `wordpress-org/assets/`, save, then reload the browser or select **Refresh preview**. The server reads the working copy on every request. Stop it with Ctrl-C. If the port is busy, use `python3 scripts/preview-listing.py --port 4332` and open the URL it prints.

This is a standalone development tool, like the separate website source. It requires only Python 3 and creates no build output. Its script, template, styles, JavaScript, documentation and tests stay in GitHub. The existing explicit package allowlists exclude them from the plugin ZIP, listing asset ZIP and WordPress.org SVN; the public website has no dependency on this preview.

The preview follows the WordPress.org listing layout: local banner and icon, title, author, tabs, details, screenshots that enlarge, FAQ, installation, changelog and metadata. It uses `readme.txt` plus the plugin header's Author field. Screenshots use the numbered captions and matching local images. Keep screenshots in the normal Details section; no extra screenshot navigation is added.

Readme formatting supports the repository's section/subsection headings, paragraphs, numbered and bulleted lists, bold, emphasis, inline code, HTTP(S) Markdown links and bare URLs. Raw HTML is displayed as text; unsupported Markdown is not a full emulation of WordPress.org's parser. Check the live directory after publication for final formatting. The preview uses system fonts and simplified directory navigation rather than fetching WordPress.org resources.

Reviews, ratings, install counts and publication time are not fabricated. Links to WordPress.org open real external pages. **Download** explains that this working-copy preview does not create a package and provides a link to the live listing; it never silently serves an older build. Short readme summaries are parsed by the directory for search cards; the individual plugin page uses the Description section.

The server binds only to `127.0.0.1`, accepts its exact loopback Host, serves only its named UI files and allowed raster listing assets, rejects symlinked source paths, and applies file-size limits and a restrictive content security policy. Do not put it behind a public proxy. No WordPress instance, provider connection or build step is involved.

Run the focused parser, reload and request-boundary checks with:

```sh
PYTHONDONTWRITEBYTECODE=1 python3 tests/listing-preview.py
```

## WordPress mark

The header reuses the official mark already documented in `site/src/components/WordPressMark.astro`, from [WordPress/Gutenberg](https://github.com/WordPress/gutenberg/blob/trunk/packages/icons/src/library/wordpress.svg), source blob `2941879b6b2e4299d7eebaf5bce1ce2cf69420fb`, under GPL-2.0-or-later. WordPress is a trademark of the WordPress Foundation; see the [trademark policy](https://wordpressfoundation.org/trademark-policy/). This local preview is not a WordPress.org service.
