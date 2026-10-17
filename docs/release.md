# Build and release Bhranti

Use `./build.sh` for every installable plugin package, locally and in GitHub Actions. It packages the source checkout without editing source files or accessing a WordPress site, database, AI connector, or remote service.

## Build locally

Requirements: Bash, Python 3.9 or newer, PHP CLI, and Node.js. No npm or Composer install is needed for the plugin: its browser assets are already readable, ready-to-use JavaScript and CSS.

```sh
./build.sh
```

For version `0.6.6`, the outputs are:

| Output | Purpose |
| --- | --- |
| `build/bhranti/` | Clean installable plugin directory |
| `build/bhranti.zip` | ZIP to upload through WordPress Plugins → Add New Plugin → Upload Plugin |
| `build/bhranti-0.6.6.zip` | Identical versioned ZIP for a release |
| `build/bhranti.zip.sha256` | Unversioned ZIP checksum |
| `build/bhranti-0.6.6.zip.sha256` | Versioned ZIP checksum |
| `build/SHA256SUMS` | Both checksums |

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

The ZIP has a single `bhranti/` root containing the plugin entry file, `uninstall.php`, `readme.txt`, `LICENSE`, PHP classes under `includes/`, and the explicit assets and built-in skills listed in `scripts/build.py`.

Keep the five bundled `skills/*/SKILL.md` documents: the running harness reads them. Keep `assets/vendor/axe-core/` source, license, notices and provenance files: rendered accessibility checks use that library. Its recorded source hashes are checked during the build. Add new runtime assets to the allowlist when introducing them.

The Astro website, screenshots, development docs, tests, build tools, Git files, local configuration and dependencies are excluded. The plugin relies on the site's native WordPress AI Client and connectors; it does not bundle other installed plugins. Packages reject symlinked runtime inputs and output targets.

## Change the version

Before a release, update all of these to the same numeric `major.minor.patch` version:

1. `VERSION`.
2. The `Version:` header and `BHRANTI_VERSION` constant in `bhranti.php`.
3. `Stable tag:` in `readme.txt`, plus one nonempty matching Changelog entry.
4. The public website's version in `site/src/data/release.ts` when announcing that release.

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
git tag v0.6.6
git push origin v0.6.6
```

Create the tag only from the intended release commit after it is on `main`. Future changes to `VERSION` on `main` create their tags automatically. Versions starting with `0.` are marked as GitHub prereleases.

The package job has read-only repository permission. Only the release job has `contents: write`; it downloads the checked artifact, verifies its checksum, and takes release notes from the packaged `readme.txt`. It uses the automatic `GITHUB_TOKEN`, with no personal access token. The actions are pinned to immutable commits. Network/authentication errors fail instead of being interpreted as a missing release. Existing tags must identify the checked commit; an already published release is left unchanged. An unpublished draft can be completed only when its tag or target matches that commit.

Artifacts are retained for 30 days. GitHub releases attach the versioned installable ZIP and its checksum. GitHub's automatically generated source archives include development files; download the attached `bhranti-<version>.zip` for installation.

## Website and distribution checks

The website builds separately with `cd site`, `npm ci`, `npm run check`, `npm run build` and `npm run check:output`. Its output is `site/dist/` for static hosting. The plugin release workflow does not deploy the website. Once a release is published, set the real ZIP URL in `site/src/data/release.ts` and rebuild the website; the download button remains a placeholder until then.

The build validates metadata, runtime dependencies, PHP/JavaScript syntax, archive contents and checksums. It does not replace the plugin's WordPress integration tests, WordPress Coding Standards, official Plugin Check, or live compatibility review. Run those checks against the intended distribution before broader release. No release is submitted to WordPress.org by this workflow.

References: [GitHub release creation](https://cli.github.com/manual/gh_release_create), [workflow permissions](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#permissions), and [WordPress Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).
