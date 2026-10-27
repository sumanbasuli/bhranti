# Build and release Bhranti

Use `./build.sh` for every installable plugin package, locally and in GitHub Actions. It packages the source checkout without editing source files or accessing a WordPress site, database, AI connector, or remote service.

## Build locally

Requirements: Bash, Python 3.9 or newer, PHP CLI, and Node.js. No npm or Composer install is needed for the plugin: its browser assets are already readable, ready-to-use JavaScript and CSS.

```sh
./build.sh
```

For version `1.2`, the outputs are:

| Output | Purpose |
| --- | --- |
| `build/bhranti/` | Clean installable plugin directory |
| `build/bhranti.zip` | ZIP to upload through WordPress Plugins → Add New Plugin → Upload Plugin |
| `build/bhranti-1.2.zip` | Identical versioned ZIP for a release |
| `build/bhranti.zip.sha256` | Unversioned ZIP checksum |
| `build/bhranti-1.2.zip.sha256` | Versioned ZIP checksum |
| `build/wordpress-org-assets.zip` | Banner, icons and screenshots for the WordPress.org listing |
| `build/wordpress-org-assets.zip.sha256` | Listing assets checksum |
| `build/SHA256SUMS` | Checksums for all three ZIPs |

The generated `build/` directory is ignored by Git. Builds replace their own named outputs; unrelated files and older versioned archives remain. Identical inputs on the same Python/zlib toolchain produce identical ZIP bytes, with sorted entries, fixed timestamps and file modes.

To validate without making a ZIP:

```sh
./build.sh --check
```

To run package regression checks, including two real builds and rejection of invalid inputs:

```sh
python3 tests/build.py
```

Verify the checksums on macOS with `cd build` followed by `shasum -a 256 -c SHA256SUMS`. On Linux use `sha256sum -c SHA256SUMS`.

## What ships

The ZIP has a single `bhranti/` root containing the plugin entry file, `uninstall.php`, `readme.txt`, `changelog.txt`, `LICENSE`, PHP classes under `includes/`, and the explicit assets and built-in skills listed in `scripts/build.py`.

Keep the five bundled `skills/*/SKILL.md` documents: the running harness reads them. Keep `assets/vendor/axe-core/` source, license, notices and provenance files: rendered accessibility checks use that library. Its recorded source hashes are checked during the build. Add new runtime assets to the allowlist when introducing them.

The Astro website, screenshots, development docs, tests, build tools, Git files, local configuration and dependencies are excluded. The plugin relies on the site's native WordPress AI Client and connectors; it does not bundle other installed plugins. Packages reject symlinked runtime inputs and output targets.

## WordPress.org listing assets

Keep the deployable listing images in `wordpress-org/assets/`. They are packaged separately from the installable plugin:

| File | Dimensions | Purpose |
| --- | --- | --- |
| `banner-772x250.png` | 772 × 250 | Standard directory banner |
| `banner-1544x500.png` | 1544 × 500 | High-resolution banner |
| `icon-128x128.png` | 128 × 128 | Standard plugin icon |
| `icon-256x256.png` | 256 × 256 | High-resolution icon |
| `icon.svg` | 256 × 256 viewBox | Scalable icon, with the PNG fallbacks above |
| `screenshot-1.jpg` through `screenshot-3.jpg` | Original capture sizes | Real editor, live action and website screenshots |

Keep one numbered caption for every screenshot under `== Screenshots ==` in `readme.txt`, in the same order. Check that screenshots contain no private site information. Editable banner source and regeneration instructions live in `wordpress-org/design/` and `wordpress-org/README.md`; these files do not enter either release ZIP.

The build checks the exact filenames, screenshot captions, regular files and symlink boundaries. It checks JPEG markers, PNG framing and declared dimensions, and a self-contained SVG icon. It enforces WordPress.org's 10 MB screenshot, 4 MB banner and 1 MB icon limits. These are packaging checks, not full image decoding. Unexpected files are rejected.

The separate `build/wordpress-org-assets.zip` contains only the deployable images at its root. Extract its contents into the SVN checkout's top-level `assets/` directory, beside `trunk/` and `tags/`. Set `svn:mime-type` to `image/jpeg`, `image/png` or `image/svg+xml` as appropriate. Listing artwork must not go into `trunk/assets/` or a version tag. The captions remain in the released plugin's `readme.txt`.

The [official plugin assets guide](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/) explains the required sizes, filenames, MIME properties and CDN caching. The [readme guide](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/) explains how Stable tag selects the published description and captions.

## Change the version

Before a release, update all of these to the same numeric `major.minor` or `major.minor.patch` version:

1. `VERSION`.
2. The `Version:` header and `BHRANTI_VERSION` constant in `bhranti.php`.
3. `Stable tag:` in `readme.txt`, plus one nonempty matching Changelog entry.
4. The website reads version and compatibility from these root files automatically. Publish the matching GitHub ZIP and checksum before deploying the website.

The builder rejects inconsistent plugin metadata rather than silently stamping a different version. Add a new version for a new published package; published releases are not overwritten.

## Automatic GitHub releases

The workflow is `.github/workflows/release.yml`. Configure a GitHub remote and push the repository before expecting it to run. Its release branch is `main`, matching the other project workflows; local work can remain on a development branch until ready.

| Event | Result |
| --- | --- |
| Push to `main`, unchanged `VERSION` | Build, check and retain an Actions artifact; no release |
| Push to `main`, changed `VERSION` | Build and publish its matching `v<version>` release |
| Push a matching `v<version>` tag | Publish after verifying the tag is on `main` |
| Manual run with an existing matching tag | Build and publish that tag after the same checks |
| Manual run without a tag | Build the selected branch and retain an artifact only |

A first push with no available previous `main` commit only creates an artifact. For the initial release, push `main`, then create and push the matching tag, for example:

```sh
git tag v1.2
git push origin v1.2
```

Create the tag only from the intended release commit after it is on `main`. Future changes to `VERSION` on `main` create their tags automatically. Versions starting with `0.` are marked as GitHub prereleases.

The package job has read-only repository permission. Only the release job has `contents: write`; it downloads the checked artifact, verifies its checksum, and takes release notes from the packaged `readme.txt`. It uses the automatic `GITHUB_TOKEN`, with no personal access token. The actions are pinned to immutable commits. Network/authentication errors fail instead of being interpreted as a missing release. Existing tags must identify the checked commit; an already published release is left unchanged. An unpublished draft can be completed only when its tag or target matches that commit.

Artifacts are retained for 30 days. GitHub releases attach the versioned installable ZIP and its checksum. GitHub's automatically generated source archives include development files; download the attached `bhranti-<version>.zip` for installation.

## Website and distribution checks

The website builds separately with `cd site`, `npm ci`, `npm run check`, `npm run build` and `npm run check:output`. Its output is `site/dist/` for static hosting. The plugin release workflow does not deploy the website. The website build retrieves the matching published GitHub ZIP and checksum, verifies their version and runtime paths, and serves those exact bytes from `/downloads/bhranti-<version>.zip`. It fails if that release is missing or invalid instead of deploying a broken download button. See `site/README.md` for the release order.

The build validates metadata, runtime dependencies, PHP/JavaScript syntax, archive contents and checksums. It does not replace the plugin's WordPress integration tests, WordPress Coding Standards, official Plugin Check, or live compatibility review. Run those checks against the intended distribution before broader release. The GitHub release workflow does not publish to WordPress.org. The separate SVN workflow below controls that publication.

References: [GitHub release creation](https://cli.github.com/manual/gh_release_create), [workflow permissions](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#permissions), and [WordPress Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).

## WordPress.org releases

`.github/workflows/svn-release.yml` publishes only when the root `SVN-RELEASE` marker increases on `main`. Its initial value is `0.0`, which means publication is inactive. It is not a plugin version and never creates an SVN tag. Ordinary builds and GitHub releases continue independently.

The marker is a publication decision, not another version source. During development it may remain at the last SVN version. At publication it must exactly match `VERSION`, the plugin header, `BHRANTI_VERSION` and the readme Stable tag. Versions are compared numerically, so `1.2.0` cannot replace `1.2`.

### First setup and dry run

1. Push the workflow and `SVN-RELEASE` containing `0.0` to `main`. This establishes the inactive baseline without publishing anything to WordPress.org.
2. In GitHub, open **Settings → Secrets and variables → Actions → New repository secret**. Add `SVN_USERNAME` as `txsadhu`, and `SVN_PASSWORD` as the dedicated SVN password from [WordPress.org account settings](https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password). Do not put that password in Git, issues or chat.
3. Open **Actions → Release to WordPress.org → Run workflow**. Select `main` and enter `1.2`. Manual runs are always dry runs and do not receive SVN credentials. They validate metadata, test packaging and publication safeguards, download the exact checked artifact, and let the pinned 10up action prepare a real SVN working copy without committing it.
4. Check that the dry run passes. Its summary records the source commit and both ZIP checksums. The post-action checks verify the prepared trunk, tag and listing images against the artifact.
5. Change only `SVN-RELEASE` from `0.0` to `1.2`, commit, and push to `main`. The plugin remains version `1.2`. This requests the first public SVN release.

The first upload is not another plugin submission. An existing GitHub `v1.2` release does not stop the first SVN `1.2` release, provided SVN has no corresponding version. If WordPress.org Release Confirmation is enabled, confirm the pending release there after the workflow completes.

### What is deployed

The workflow uses [10up/action-wordpress-plugin-deploy](https://github.com/10up/action-wordpress-plugin-deploy), pinned to reviewed commit `008657da18d18e065aaaccd287fa3af53ff768a1`. It includes the credential-free dry-run fix. Only the live deployment step receives the SVN secrets; builds and tests never receive them.

The build job runs the existing `./build.sh` packaging path and its regression tests. It retains the versioned plugin ZIP and listing-assets ZIP as one immutable Actions artifact. The deployment job downloads that exact artifact by ID, checks its recorded SHA-256 values, and checks every file against the selected source commit before extraction. It does not rebuild the ZIP in the deployment job.

`BUILD_DIR=build/bhranti` passes the **contents** of the extracted plugin folder to the action. `ASSETS_DIR=wordpress-org/assets` supplies only the separate listing images. The result is:

```text
trunk/bhranti.php
trunk/readme.txt
trunk/includes/...
trunk/assets/...              # Runtime CSS, JavaScript and licenses
trunk/skills/...
tags/1.2/bhranti.php
tags/1.2/readme.txt
tags/1.2/...                  # SVN copy of trunk for this release
assets/banner-772x250.png     # Directory listing artwork
assets/banner-1544x500.png
assets/icon-128x128.png
assets/icon-256x256.png
assets/icon.svg
assets/screenshot-1.jpg
assets/screenshot-2.jpg
assets/screenshot-3.jpg
```

There is no `trunk/bhranti/bhranti.php`. No ZIP, Astro website, tests, internal docs, Git data or build tools are uploaded to SVN. The action uses `svn copy` from trunk to the version tag and commits trunk, tag and listing images together. WordPress.org generates its own installable ZIP from SVN.

### Publication safeguards and later releases

- Live publication requires an actual marker increase on `main`, a known previous marker, consistent version metadata, clean source inputs and an exact current remote-main commit. Manual dispatch can never publish.
- Requests are serialized. An unavailable remote, invalid SVN listing, existing/equivalent version or downgrade stops publication. The workflow never treats a network failure as proof that a tag is absent.
- Both archives are checked for exact paths, duplicate entries, file sizes, symlinks, source bytes and checksums before extraction. The main plugin file and readme must be directly at the deployment root.
- After the action, the workflow checks the complete local SVN tree. After a live commit, it independently exports and verifies the public version tag and listing images and reads back their MIME properties.
- The helper contains no SVN commit command. The pinned action is the only live publication step.

For a later release, update the runtime version and changelog together, finish testing, and publish the matching GitHub release. Then change `SVN-RELEASE` to that same version and push to `main` to request WordPress.org publication. Do not edit or replace a published code tag.

If a release fails, first inspect whether its SVN tag exists. A failure after a commit, such as a verification network error, must not be treated as permission to publish again. If no tag was created and `main` has not advanced, rerun the failed workflow. If `main` has advanced, validate the new commit and re-arm the request with an inactive `0.0` commit followed by the intended marker; the remote tag/downgrade checks still apply. A real subsequent code update needs a new version.

Listing-only changes after publication need a separate readme/assets maintenance path. Do not manufacture a code release solely to replace a banner. The 10up release action skips an existing version, so it must not be used to overwrite a released tag.

The GitHub and WordPress.org workflows are intentionally independent: events created by the GitHub release workflow's `GITHUB_TOKEN` do not reliably launch another workflow. A marker push directly triggers SVN publication.

References: [WordPress SVN guidance](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/), [readme and Stable tag rules](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/), [release confirmation](https://developer.wordpress.org/plugins/wordpress-org/release-confirmation-emails/), and [10up deployment action](https://github.com/10up/action-wordpress-plugin-deploy).
