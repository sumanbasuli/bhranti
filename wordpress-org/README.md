# WordPress.org listing assets

The deployable images and Playground blueprint live in `assets/`; editable banner source and its export script live in `design/`.

| Asset | Files |
| --- | --- |
| Banner | `banner-772x250.png`, `banner-1544x500.png` |
| Plugin icon | `icon-128x128.png`, `icon-256x256.png`, `icon.svg` |
| Real screenshots | `screenshot-1.jpg`, `screenshot-2.jpg`, `screenshot-3.jpg` |
| Playground preview | `blueprints/blueprint.json` |

The artwork uses Bhranti's existing sparkle mark, WordPress blue and charcoal palette. The banner shows a real editor screenshot. Screenshot numbers match the captions in `readme.txt`.

Run `./build.sh` from the repository root to create `build/wordpress-org-assets.zip`. Its contents belong in the WordPress.org SVN repository's top-level `assets/` directory, beside `trunk/` and `tags/`. These listing files are excluded from the installable plugin ZIP. The blueprint opens the real Settings and sidebar in a temporary native WordPress sandbox; it contains no provider connection or simulated AI. See the metadata workflow in the release instructions for readme/blueprint updates without a new plugin version. See [release instructions](../docs/release.md) and the [official assets guide](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).

## License and credits

The Bhranti listing compositions and screenshots are distributed under the GNU General Public License, version 2 or later. See [LICENSE](../LICENSE).

The sparkle path comes from [Bootstrap Icons](https://github.com/twbs/icons), under the [MIT license](../assets/bootstrap-icons-LICENSE.txt).

The photograph visible in the screenshots and banner is “Person typing on a typewriter,” bundled by the Twenty Twenty-Five theme as `assets/images/typewriter.webp`. The theme credits identify it as a public-domain CC0 image from [Rawpixel](https://www.rawpixel.com/image/12240004/photo-image-face-person-technology), under [CC0 1.0 Universal](https://creativecommons.org/publicdomain/zero/1.0/).
