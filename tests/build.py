#!/usr/bin/env python3
"""Packaging regressions; no WordPress bootstrap, database, AI or network calls."""

from contextlib import contextmanager, redirect_stdout
import hashlib
import importlib.util
import io
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile


sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("bhranti_builder", ROOT / "scripts/build.py")
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


@contextmanager
def fixture():
    """Use disposable copies, never modify real runtime inputs for failure cases."""
    with tempfile.TemporaryDirectory(prefix="bhranti-build-test-") as temporary:
        destination = Path(temporary)
        for name in ("VERSION",) + builder.REQUIRED_FILES:
            target = destination / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / name, target)
        shutil.copytree(ROOT / "includes", destination / "includes")
        with patch.object(builder, "ROOT", destination):
            yield destination


class PackagingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.version = (ROOT / "VERSION").read_text().strip()
        cls.archive = ROOT / "build/bhranti.zip"
        cls.versioned = ROOT / ("build/bhranti-" + cls.version + ".zip")
        cls.builds = []
        for attempt in (1, 2):
            result = subprocess.run(
                [str(ROOT / "build.sh")], cwd=ROOT, text=True,
                stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=240,
            )
            if result.returncode:
                raise RuntimeError("Build {} failed:\n{}".format(attempt, result.stdout))
            cls.builds.append((
                cls.archive.read_bytes(),
                (ROOT / "build/SHA256SUMS").read_bytes(),
                Path(str(cls.versioned) + ".sha256").read_bytes(),
            ))
            print("Real-source build {}: passed through ./build.sh".format(attempt), flush=True)

    def test_deterministic_real_source_build(self):
        self.assertEqual(self.builds[0], self.builds[1], "Identical inputs must produce identical ZIPs and checksums")
        self.assertEqual(self.archive.read_bytes(), self.versioned.read_bytes())

    def test_runtime_allowlist_and_clean_folder(self):
        # Explicit expected runtime inventory catches accidentally broadened packaging.
        names = {
            "bhranti.php", "uninstall.php", "readme.txt", "LICENSE",
            "assets/admin.css", "assets/admin.js", "assets/credentials.js", "assets/logs.css", "assets/editor.js",
            "assets/forms.css", "assets/render-check.js", "assets/settings.css",
            "assets/settings.js", "assets/bootstrap-icons-LICENSE.txt",
            "assets/vendor/axe-core/axe.js", "assets/vendor/axe-core/LICENSE",
            "assets/vendor/axe-core/README.md", "assets/vendor/axe-core/SOURCE.json",
            "assets/vendor/axe-core/package.json", "assets/vendor/axe-core/THIRD-PARTY-NOTICES.md",
            "skills/commerce-workflow/SKILL.md", "skills/content-operations/SKILL.md",
            "skills/design-composition/SKILL.md", "skills/site-design/SKILL.md",
            "skills/wordpress-support/SKILL.md",
        }
        names.update(path.relative_to(ROOT).as_posix() for path in (ROOT / "includes").rglob("*.php"))
        expected = {"bhranti/" + name for name in names}
        with zipfile.ZipFile(self.archive) as archive:
            self.assertIsNone(archive.testzip())
            self.assertEqual(set(archive.namelist()), expected)
            self.assertEqual(len(archive.namelist()), len(expected), "No duplicate ZIP entries")
            for name in names:
                self.assertEqual(archive.read("bhranti/" + name), (ROOT / name).read_bytes())
                self.assertEqual(archive.read("bhranti/" + name), (ROOT / "build/bhranti" / name).read_bytes())
        folder = ROOT / "build/bhranti"
        installed = {path.relative_to(folder).as_posix() for path in folder.rglob("*") if path.is_file()}
        self.assertEqual(installed, names)

    def test_checksums_cover_both_packages(self):
        expected = "".join(
            hashlib.sha256(path.read_bytes()).hexdigest() + "  " + path.name + "\n"
            for path in (self.archive, self.versioned)
        )
        self.assertEqual((ROOT / "build/SHA256SUMS").read_text(), expected)
        for path in (self.archive, self.versioned):
            line = hashlib.sha256(path.read_bytes()).hexdigest() + "  " + path.name + "\n"
            self.assertEqual(Path(str(path) + ".sha256").read_text(), line)

    def test_metadata_version_mismatch_is_rejected(self):
        with fixture() as root:
            (root / "VERSION").write_text("9999.0.0\n")
            with self.assertRaisesRegex(ValueError, "must match"):
                builder.validate_metadata()

    def test_runtime_version_constant_mismatch_is_rejected(self):
        with fixture() as root:
            path = root / "bhranti.php"
            source, count = re.subn(
                r"(define\(\s*'BHRANTI_VERSION',\s*')[^']+(')",
                r"\g<1>9999.0.0\2", path.read_text(),
            )
            self.assertEqual(count, 1)
            path.write_text(source)
            with self.assertRaisesRegex(ValueError, "BHRANTI_VERSION"):
                builder.validate_metadata()

    def test_missing_packaged_skill_is_rejected(self):
        with fixture() as root:
            (root / "skills/site-design/SKILL.md").unlink()
            with self.assertRaisesRegex(ValueError, "Required runtime file.*skills/site-design/SKILL.md"):
                builder.collect_files()

    def test_missing_php_dependency_is_rejected(self):
        with fixture() as root:
            (root / "includes/class-bhranti-commerce-setup.php").unlink()
            with self.assertRaisesRegex(ValueError, "Missing packaged PHP dependency"):
                builder.validate_dependencies(builder.collect_files())

    def test_unlisted_skill_dependency_is_rejected(self):
        with fixture() as root:
            registry = root / "includes/class-bhranti-skills.php"
            registry.write_text(registry.read_text().replace("/skills/site-design/SKILL.md", "/skills/missing/SKILL.md"))
            with self.assertRaisesRegex(ValueError, "Missing packaged skill"):
                builder.validate_dependencies(builder.collect_files())

    def test_modified_vendor_source_is_rejected(self):
        with fixture() as root:
            path = root / "assets/vendor/axe-core/axe.js"
            path.write_bytes(path.read_bytes() + b"\n// accidental change\n")
            with self.assertRaisesRegex(ValueError, "source hash mismatch: axe.js"):
                builder.validate_dependencies(builder.collect_files())

    def test_symlinked_runtime_file_and_parent_are_rejected(self):
        with fixture() as root:
            path = root / "assets/admin.js"
            path.unlink()
            path.symlink_to(ROOT / "assets/admin.js")
            with self.assertRaisesRegex(ValueError, "symlink"):
                builder.collect_files()
        with fixture() as root:
            (root / "assets").rename(root / "real-assets")
            (root / "assets").symlink_to(root / "real-assets", target_is_directory=True)
            with self.assertRaisesRegex(ValueError, "symlink"):
                builder.collect_files()
        with fixture() as root:
            (root / "includes/link.php").symlink_to(ROOT / "bhranti.php")
            with self.assertRaisesRegex(ValueError, "Symlinks"):
                builder.collect_files()

    def test_build_symlink_is_rejected_before_writing(self):
        with fixture() as root:
            outside = root / "outside"
            outside.mkdir()
            marker = outside / "preserve.txt"
            marker.write_text("unchanged")
            (root / "build").symlink_to(outside, target_is_directory=True)
            with patch.object(builder.tempfile, "TemporaryDirectory", side_effect=AssertionError("Staging must not start")):
                with self.assertRaisesRegex(ValueError, "real directory"):
                    builder.package(self.version, builder.collect_files())
            self.assertEqual(marker.read_text(), "unchanged")
            self.assertEqual(list(outside.iterdir()), [marker])

    def test_named_output_symlinks_are_rejected_without_following(self):
        for name in ("bhranti", "bhranti.zip", "SHA256SUMS"):
            with self.subTest(output=name), fixture() as root:
                (root / "build").mkdir()
                marker = root / "outside.txt"
                marker.write_text("unchanged")
                output = root / "build" / name
                output.symlink_to(marker)
                with self.assertRaisesRegex(ValueError, "Refusing to overwrite a symlink"):
                    builder.package(self.version, builder.collect_files())
                self.assertTrue(output.is_symlink())
                self.assertEqual(marker.read_text(), "unchanged")
                self.assertEqual(list((root / "build").iterdir()), [output])

    def test_secrets_and_development_paths_inside_includes_are_rejected(self):
        for name in (".env", "secret.key", "credentials.json", "docs/draft.php", "tests/fixture.php"):
            with self.subTest(path=name), fixture() as root:
                path = root / "includes" / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("must never ship")
                with self.assertRaises(ValueError):
                    builder.collect_files()

    def test_nonruntime_files_are_excluded(self):
        with fixture() as root:
            for name in (".env", "site/public/private.jpg", "site/node_modules/secret.json", "docs/private.txt", "tests/fixture.php", "assets/debug.log"):
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("must never ship")
            collected = {path.relative_to(root).as_posix() for path in builder.collect_files()}
            self.assertFalse(any("must never ship" in (root / path).read_text() for path in collected))

    def test_unrelated_build_files_are_preserved(self):
        with fixture() as root:
            build = root / "build"
            build.mkdir()
            marker = build / "unrelated.txt"
            marker.write_text("keep this")
            old = build / "bhranti/obsolete.php"
            old.parent.mkdir()
            old.write_text("old package file")
            with redirect_stdout(io.StringIO()):
                builder.package(self.version, builder.collect_files())
            self.assertEqual(marker.read_text(), "keep this")
            self.assertFalse(old.exists(), "The new clean plugin folder must not retain stale runtime files")


if __name__ == "__main__":
    unittest.main(verbosity=2)
