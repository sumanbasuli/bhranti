#!/usr/bin/env python3
"""Validate and package the plugin with Python's standard library."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import zipfile


ROOT = Path(__file__).resolve().parent.parent
SLUG = "bhranti"
MAIN_FILE = SLUG + ".php"
# Explicit assets/skills keep the Astro site and development files out of ZIPs.
REQUIRED_FILES = (
    "bhranti.php",
    "uninstall.php",
    "readme.txt",
    "LICENSE",
    "assets/admin.css",
    "assets/admin.js",
    "assets/credentials.js",
    "assets/logs.css",
    "assets/bootstrap-icons-LICENSE.txt",
    "assets/editor.js",
    "assets/forms.css",
    "assets/render-check.js",
    "assets/settings.css",
    "assets/settings.js",
    "assets/vendor/axe-core/LICENSE",
    "assets/vendor/axe-core/README.md",
    "assets/vendor/axe-core/SOURCE.json",
    "assets/vendor/axe-core/THIRD-PARTY-NOTICES.md",
    "assets/vendor/axe-core/axe.js",
    "assets/vendor/axe-core/package.json",
    "skills/commerce-workflow/SKILL.md",
    "skills/content-operations/SKILL.md",
    "skills/design-composition/SKILL.md",
    "skills/site-design/SKILL.md",
    "skills/wordpress-support/SKILL.md",
)
EXCLUDED_COMPONENTS = {"docs", "tests", "scripts", "node_modules", "build", "__pycache__"}


def fail(message):
    raise ValueError(message)


def header(text, name):
    matches = re.findall(r"^[ \t*]*" + re.escape(name) + r":[ \t]*(.+?)\s*$", text, re.M)
    if len(matches) != 1:
        fail("Expected exactly one {!r} header".format(name))
    return matches[0]


def collect_files():
    files = []
    for name in REQUIRED_FILES:
        path = ROOT / name
        linked_parent = any((ROOT / parent).is_symlink() for parent in Path(name).parents)
        if linked_parent or path.is_symlink() or not path.is_file():
            fail("Required runtime file is missing or is a symlink: " + name)
        files.append(path)
    src = ROOT / "includes"
    if src.is_symlink() or not src.is_dir():
        fail("Required runtime directory is missing or is a symlink: includes/")
    for path in sorted(src.rglob("*")):
        relative = path.relative_to(ROOT)
        if path.is_symlink():
            fail("Symlinks are not allowed in the release: " + str(relative))
        if any(part.startswith(".") or part.lower() in EXCLUDED_COMPONENTS for part in relative.parts):
            fail("Development or hidden path inside includes/: " + str(relative))
        if path.is_dir():
            continue
        if not path.is_file():
            fail("Unsupported runtime path: " + str(relative))
        if path.suffix.lower() != ".php":
            fail("File is outside the runtime allowlist: " + str(relative))
        files.append(path)
    if not any(src in path.parents for path in files):
        fail("includes/ must contain runtime files")
    return sorted(files, key=lambda path: path.relative_to(ROOT).as_posix())


def validate_metadata():
    version = (ROOT / "VERSION").read_text(encoding="utf-8").strip()
    if not re.fullmatch(r"\d+\.\d+\.\d+", version):
        fail("VERSION must contain a numeric version such as 0.1.0")
    plugin = (ROOT / MAIN_FILE).read_text(encoding="utf-8")[:8192]
    readme = (ROOT / "readme.txt").read_text(encoding="utf-8")
    for name in (
        "Plugin Name", "Description", "Version", "Requires at least", "Requires PHP",
        "Author", "License", "License URI", "Text Domain",
    ):
        header(plugin, name)
    for name in (
        "Contributors", "Tags", "Requires at least", "Tested up to", "Requires PHP",
        "Stable tag", "License", "License URI",
    ):
        header(readme, name)
    if header(plugin, "Version") != version or header(readme, "Stable tag") != version:
        fail("VERSION, plugin Version and readme Stable tag must match")
    runtime_versions = re.findall(r"define\(\s*['\"]BHRANTI_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)", plugin)
    if runtime_versions != [version]:
        fail("BHRANTI_VERSION must be defined once and match VERSION")
    if header(plugin, "Text Domain") != SLUG:
        fail("Plugin Text Domain must equal " + SLUG)
    for name in ("Requires at least", "Requires PHP", "License", "License URI"):
        if header(plugin, name) != header(readme, name):
            fail("Plugin and readme headers must match: " + name)
    for section in ("Description", "Installation", "Changelog"):
        if "== " + section + " ==" not in readme:
            fail("readme.txt is missing section: " + section)
    entries = re.findall(r"^= " + re.escape(version) + r" =\s*\n(.*?)(?=^=|\Z)", readme.partition("== Changelog ==")[2], re.M | re.S)
    if len(entries) != 1 or not entries[0].strip():
        fail("readme.txt needs exactly one nonempty changelog entry for " + version)
    if (ROOT / "LICENSE").stat().st_size == 0:
        fail("LICENSE must contain the distribution license")
    return version


def lint_php(files):
    php = shutil.which("php")
    if not php:
        fail("PHP CLI is required for PHP syntax checks")
    for path in files:
        if path.suffix.lower() != ".php":
            continue
        command = [php, "-l", str(path)]
        result = subprocess.run(command, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=180)
        if result.returncode:
            fail("PHP lint failed for {}:\n{}".format(path.relative_to(ROOT), result.stdout.strip()))
    print("PHP lint: passed (local PHP)")


def lint_javascript(files):
    node = shutil.which("node")
    if not node:
        fail("Node.js is required for JavaScript syntax checks")
    for path in files:
        if path.suffix.lower() != ".js":
            continue
        result = subprocess.run(
            [node, "--input-type=module", "--check"], input=path.read_text(encoding="utf-8"),
            text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=30,
        )
        if result.returncode:
            fail("JavaScript syntax failed for {}:\n{}".format(path.relative_to(ROOT), result.stdout.strip()))
    print("JavaScript syntax: passed")


def validate_dependencies(files):
    """Check fixed PHP/skill references and the vendored engine's source hashes."""
    included = {path.resolve() for path in files}
    for path in files:
        if path.suffix != ".php":
            continue
        source = path.read_text(encoding="utf-8")
        for name in re.findall(r"(?:require|include)(?:_once)?\s+__DIR__\s*\.\s*['\"]([^'\"]+)['\"]", source):
            if (path.parent / name.lstrip("/")).resolve() not in included:
                fail("Missing packaged PHP dependency: " + name)
    registry = (ROOT / "includes/class-bhranti-skills.php").read_text(encoding="utf-8")
    for name in re.findall(r"['\"](/skills/[^'\"]+\.md)['\"]", registry):
        if (ROOT / name.lstrip("/")).resolve() not in included:
            fail("Missing packaged skill: " + name)
    vendor = ROOT / "assets/vendor/axe-core"
    manifest = json.loads((vendor / "SOURCE.json").read_text(encoding="utf-8"))
    for name in ("axe.js", "LICENSE", "package.json", "README.md"):
        actual = hashlib.sha256((vendor / name).read_bytes()).hexdigest()
        if manifest.get("files_sha256", {}).get(name) != actual:
            fail("Bundled axe-core source hash mismatch: " + name)
    print("Runtime dependencies and bundled source hashes: passed")


def create_zip(destination, files):
    with zipfile.ZipFile(destination, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in files:
            name = SLUG + "/" + path.relative_to(ROOT).as_posix()
            entry = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.create_system = 3
            entry.external_attr = (stat.S_IFREG | 0o644) << 16
            archive.writestr(entry, path.read_bytes())


def verify_zip(destination, files):
    expected = {SLUG + "/" + path.relative_to(ROOT).as_posix() for path in files}
    with zipfile.ZipFile(destination) as archive:
        if archive.testzip() is not None:
            fail("ZIP integrity check failed")
        names = archive.namelist()
        if set(names) != expected or len(names) != len(expected):
            fail("ZIP contents do not match the runtime allowlist")
        for path in files:
            name = SLUG + "/" + path.relative_to(ROOT).as_posix()
            if archive.read(name) != path.read_bytes():
                fail("ZIP content does not match source: " + name)


def package(version, files):
    build = ROOT / "build"
    if build.is_symlink() or (build.exists() and not build.is_dir()):
        fail("build/ must be a real directory inside the plugin root")
    build.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix=".package-", dir=build) as temporary:
        stage = Path(temporary)
        folder = stage / SLUG
        folder.mkdir()
        for path in files:
            target = folder / path.relative_to(ROOT)
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(path, target)
            target.chmod(0o644)
        archive = stage / (SLUG + ".zip")
        create_zip(archive, files)
        verify_zip(archive, files)
        versioned = stage / (SLUG + "-" + version + ".zip")
        shutil.copyfile(archive, versioned)
        checksum_lines = []
        for path in (archive, versioned):
            digest = hashlib.sha256(path.read_bytes()).hexdigest()
            line = digest + "  " + path.name + "\n"
            (stage / (path.name + ".sha256")).write_text(line, encoding="ascii")
            checksum_lines.append(line)
        (stage / "SHA256SUMS").write_text("".join(checksum_lines), encoding="ascii")
        # Replace only named outputs; never remove the build root.
        for path in stage.iterdir():
            target = build / path.name
            if target.is_symlink():
                fail("Refusing to overwrite a symlink: " + str(target.relative_to(ROOT)))
            if target.is_dir():
                if path.name != SLUG:
                    fail("Unexpected directory at output path: " + str(target.relative_to(ROOT)))
        for path in sorted(stage.iterdir()):
            target = build / path.name
            if target.is_dir():
                shutil.rmtree(target)
            path.replace(target)
    print("ZIP contents: passed ({} runtime files)".format(len(files)))
    print("Built {} {}".format(SLUG, version))
    for name in (SLUG + "/", SLUG + ".zip", SLUG + "-" + version + ".zip", "SHA256SUMS"):
        print("  build/" + name)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="validate metadata, runtime dependencies and syntax without producing packages")
    args = parser.parse_args()
    files = collect_files()
    version = validate_metadata()
    print("Metadata: passed (version {})".format(version))
    lint_php(files)
    lint_javascript(files)
    validate_dependencies(files)
    if args.check:
        print("Source checks passed")
    else:
        package(version, files)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        print("error: " + str(error), file=sys.stderr)
        sys.exit(1)
