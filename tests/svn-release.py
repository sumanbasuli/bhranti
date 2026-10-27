#!/usr/bin/env python3
"""Offline publication regressions: disposable Git, fresh ZIPs, native SVN XML."""

from contextlib import ExitStack, redirect_stdout
import hashlib
import importlib.util
import io
import os
from pathlib import Path
import re
import shutil
import stat
import struct
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import warnings
import zipfile


sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parent.parent
# Allows a staged helper to be tested before it is copied into a complete checkout.
SOURCE = Path(os.environ.get('BHRANTI_RELEASE_TEST_SOURCE', ROOT))
spec = importlib.util.spec_from_file_location('bhranti_svn_release', ROOT / 'scripts/svn-release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def snapshot(path):
    if not path.exists():
        return None
    return {p.relative_to(path).as_posix(): ('link', os.readlink(p)) if p.is_symlink()
            else ('directory',) if p.is_dir() else ('file', digest(p))
            for p in sorted(path.rglob('*'))}


def set_version(root, version):
    previous = (root / 'VERSION').read_text().strip()
    (root / 'VERSION').write_text(version + '\n')
    plugin = root / 'bhranti.php'
    plugin.write_text(re.sub(r'(?m)^(\s*\*?\s*Version:\s*).+$', lambda m: m[1] + version,
                            plugin.read_text()).replace("'BHRANTI_VERSION', '" + previous + "'",
                                                        "'BHRANTI_VERSION', '" + version + "'"))
    readme = root / 'readme.txt'
    readme.write_text(re.sub(r'(?m)^Stable tag: .+$', 'Stable tag: ' + version,
                            readme.read_text()).replace('= ' + previous + ' =', '= ' + version + ' ='))


class SourceFixture(unittest.TestCase):
    def setUp(self):
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.root = Path(self.stack.enter_context(tempfile.TemporaryDirectory(prefix='bhranti-svn-test-')))
        for name in ('VERSION', 'bhranti.php', 'readme.txt', 'LICENSE'):
            shutil.copyfile(SOURCE / name, self.root / name)
        set_version(self.root, '1.2')
        self.stack.enter_context(patch.object(release, 'ROOT', self.root))
        self.stack.enter_context(patch.object(release.builder, 'ROOT', self.root))

    def git(self, *args):
        return subprocess.run(['git', *args], cwd=self.root, check=True, text=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout.strip()

    def commit(self, message):
        self.git('add', '--all')
        self.git('commit', '--quiet', '--message', message)
        return self.git('rev-parse', 'HEAD')

    def trusted_git_sources(self):
        self.git('init', '--quiet', '--initial-branch=main')
        self.git('config', 'user.name', 'Release fixture')
        self.git('config', 'user.email', 'release-fixture@example.invalid')
        self.git('config', 'commit.gpgsign', 'false')
        self.git('config', 'core.hooksPath', '/dev/null')
        (self.root / '.gitignore').write_text('/checked-artifacts/\n/build/\n')
        (self.root / 'SVN-RELEASE').write_text('0.0\n')
        return self.commit('Checked source and inactive publication marker')

    def runtime_sources(self):
        for name in release.builder.REQUIRED_FILES:
            target = self.root / name
            if not target.exists():
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(SOURCE / name, target)
        shutil.copytree(SOURCE / 'includes', self.root / 'includes')
        shutil.copytree(SOURCE / 'wordpress-org/assets', self.root / 'wordpress-org/assets')


class SelectionTests(SourceFixture):
    def setUp(self):
        super().setUp()
        self.before = self.trusted_git_sources()

    def select(self, event='push', ref='refs/heads/main', before=None, requested=''):
        return release.plan(event, ref, self.before if before is None else before,
                            self.git('rev-parse', 'HEAD'), requested)

    def bump(self, marker):
        (self.root / 'SVN-RELEASE').write_text(marker + '\n')
        return self.commit('Select publication marker ' + marker)

    def test_inactive_marker_never_publishes_even_without_previous_push(self):
        result = self.select(before='0' * 40)
        self.assertEqual((result['active'], result['publish'], result['version']), (False, False, '1.2'))

    def test_manual_dry_run_with_inactive_marker_checks_current_plugin(self):
        result = self.select(event='workflow_dispatch', requested='1.2')
        self.assertEqual((result['active'], result['publish'], result['version']), (True, False, '1.2'))
        self.assertEqual((self.root / 'SVN-RELEASE').read_text(), '0.0\n')
        for requested in ('', '0.0', '1.3', '1.2.0'):
            with self.subTest(requested=requested), self.assertRaises(ValueError):
                self.select(event='workflow_dispatch', requested=requested)

    def test_only_main_and_supported_events_are_accepted(self):
        for event, ref, requested in [('push', 'refs/heads/feature', ''), ('push', 'refs/tags/v1.2', ''),
                                      ('workflow_dispatch', 'refs/tags/v1.2', '1.2'),
                                      ('pull_request', 'refs/heads/main', ''),
                                      ('push', 'refs/heads/main', '1.2')]:
            with self.subTest(event=event, ref=ref, requested=requested), self.assertRaises(ValueError):
                self.select(event, ref, requested=requested)

    def test_known_checked_commit_is_required(self):
        for commit in ('short', 'a' * 40):
            with self.subTest(commit=commit), self.assertRaises(ValueError):
                release.plan('push', 'refs/heads/main', self.before, commit, '')

    def test_zero_to_release_transition_is_live(self):
        commit = self.bump('1.2')
        self.assertEqual(self.select(), dict(active=True, publish=True, version='1.2', commit=commit))

    def test_unchanged_published_marker_skips_unrelated_main_changes(self):
        self.before = self.bump('1.2')
        (self.root / 'notes.txt').write_text('Unrelated subsequent main change\n')
        self.commit('Unrelated change')
        result = self.select()
        self.assertEqual((result['active'], result['publish']), (False, False))

    def test_unknown_previous_commit_stops_an_active_marker(self):
        self.bump('1.2')
        for before in ('', 'short', '0' * 40, 'f' * 40):
            with self.subTest(before=before), self.assertRaises(ValueError):
                self.select(before=before)

    def test_missing_previous_marker_requires_explicit_inactive_commit(self):
        (self.root / 'SVN-RELEASE').unlink()
        self.before = self.commit('Historical commit without publication marker')
        self.bump('1.2')
        with self.assertRaisesRegex(ValueError, 'previous commit has no marker'):
            self.select()

    def test_metadata_mismatch_decrease_and_equivalent_versions_cannot_publish(self):
        self.bump('1.3')
        with self.assertRaises(ValueError):
            self.select()
        self.before = self.git('rev-parse', 'HEAD')
        self.bump('1.2')
        with self.assertRaisesRegex(ValueError, 'increase'):
            self.select()
        self.before = self.git('rev-parse', 'HEAD')
        set_version(self.root, '1.2.0')
        self.bump('1.2.0')
        with self.assertRaisesRegex(ValueError, 'increase'):
            self.select()

    def test_inconsistent_plugin_header_stops_selection(self):
        self.bump('1.2')
        (self.root / 'VERSION').write_text('1.3\n')
        self.commit('Commit inconsistent version metadata')
        with self.assertRaises(ValueError):
            self.select()

    def test_dirty_or_untracked_release_inputs_stop_even_manual_dry_runs(self):
        for path, content in [('bhranti.php', '\nUncommitted tracked input\n'),
                              ('includes/untracked.php', '<?php // untracked runtime\n'),
                              ('scripts/untracked.py', '# untracked release helper\n'),
                              ('.github/workflows/untracked.yml', 'name: untracked\n')]:
            with self.subTest(path=path):
                target = self.root / path
                prior = target.read_bytes() if target.exists() else None
                target.parent.mkdir(parents=True, exist_ok=True)
                with target.open('a') as output:
                    output.write(content)
                with self.assertRaisesRegex(ValueError, 'uncommitted or untracked'):
                    self.select(event='workflow_dispatch', requested='1.2')
                if prior is None:
                    target.unlink()
                else:
                    target.write_bytes(prior)

    def test_publication_marker_requires_ascii_version_digits(self):
        for value in ('1.\u0662', '\u0661.2', '1.\uff12', '01.2', '1.02'):
            with self.subTest(value=value), self.assertRaises(ValueError):
                release.marker(value + '\n')
            with self.subTest(version=value), self.assertRaises(ValueError):
                release.version_number(value)

    def test_current_main_uses_real_local_remote_and_rejects_advancement(self):
        remote = Path(self.stack.enter_context(tempfile.TemporaryDirectory(prefix='bhranti-svn-origin-'))) / 'origin.git'
        self.git('init', '--quiet', '--bare', str(remote))
        self.git('remote', 'add', 'origin', str(remote))
        self.git('push', '--quiet', 'origin', 'main')
        selected = self.git('rev-parse', 'HEAD')
        release.current_main(selected)
        (self.root / 'advance.txt').write_text('New main commit\n')
        self.commit('Advance remote main')
        self.git('push', '--quiet', 'origin', 'main')
        self.git('checkout', '--quiet', '--detach', selected)
        with self.assertRaisesRegex(ValueError, 'main has advanced'):
            release.current_main(selected)
        self.git('remote', 'set-url', 'origin', str(self.root / 'missing-origin.git'))
        with self.assertRaisesRegex(ValueError, 'publication stopped'):
            release.current_main(selected)


class ArtifactTests(SourceFixture):
    def setUp(self):
        super().setUp()
        self.stack.enter_context(redirect_stdout(io.StringIO()))
        self.runtime_sources()
        self.trusted_git_sources()
        self.artifacts = self.root / 'checked-artifacts'
        self.artifacts.mkdir()
        self.plugin = self.artifacts / 'bhranti-1.2.zip'
        self.assets = self.artifacts / 'wordpress-org-assets.zip'
        release.builder.create_zip(self.plugin, release.builder.collect_files())
        release.builder.create_zip(self.assets, release.builder.collect_listing_assets(),
                                   root=self.root / 'wordpress-org/assets', prefix='')
        sentinel = self.root / 'build/bhranti/retain-on-failure.txt'
        sentinel.parent.mkdir(parents=True)
        sentinel.write_text('Existing output must survive rejected inputs\n')

    def prepare(self, plugin_sha=None, assets_sha=None):
        return release.prepare('1.2', plugin_sha or digest(self.plugin), assets_sha or digest(self.assets), self.artifacts)

    def outputs(self):
        return snapshot(self.root / 'build'), snapshot(self.root / 'wordpress-org/assets')

    def reject_without_output_changes(self):
        before = self.outputs()
        with self.assertRaises((ValueError, zipfile.BadZipFile)):
            self.prepare()
        self.assertEqual(self.outputs(), before, 'Rejected artifacts must not alter existing deployment outputs')

    def rewrite(self, archive, transform):
        with zipfile.ZipFile(archive) as source:
            entries = [(entry, source.read(entry)) for entry in source.infolist()]
        updated = transform(entries)
        with warnings.catch_warnings(), zipfile.ZipFile(archive, 'w') as target:
            warnings.simplefilter('ignore', UserWarning)
            for entry, data in updated:
                target.writestr(entry, data)

    def test_valid_checked_artifacts_restore_exact_svn_root_layout(self):
        expected_plugin = {p.relative_to(self.root).as_posix(): p.read_bytes() for p in release.builder.collect_files()}
        expected_art = {p.name: p.read_bytes() for p in release.builder.collect_listing_assets()}
        result = self.prepare()
        plugin_root = self.root / 'build/bhranti'
        self.assertEqual(result['main_file'], 'build/bhranti/bhranti.php')
        self.assertTrue((plugin_root / 'bhranti.php').is_file())
        self.assertFalse((plugin_root / 'bhranti').exists())
        self.assertEqual({p.relative_to(plugin_root).as_posix(): p.read_bytes() for p in plugin_root.rglob('*') if p.is_file()}, expected_plugin)
        self.assertEqual({p.name: p.read_bytes() for p in (self.root / 'wordpress-org/assets').iterdir()}, expected_art)

    def test_wrong_checksum_does_not_change_any_outputs(self):
        before = self.outputs()
        with self.assertRaisesRegex(ValueError, 'checksum'):
            self.prepare(plugin_sha='0' * 64)
        self.assertEqual(self.outputs(), before)

    def test_uncommitted_tracked_and_untracked_payload_cannot_replace_outputs(self):
        for name in ('assets/admin.js', 'includes/untracked-runtime.php'):
            with self.subTest(path=name):
                target = self.root / name
                prior = target.read_bytes() if target.exists() else None
                target.write_bytes((prior or b'<?php') + b'\nUncommitted payload change\n')
                before = self.outputs()
                with self.assertRaisesRegex(ValueError, 'uncommitted or untracked'):
                    self.prepare()
                self.assertEqual(self.outputs(), before)
                if prior is None:
                    target.unlink()
                else:
                    target.write_bytes(prior)

    def test_ignored_payload_is_still_an_untrusted_release_input(self):
        ignore = self.root / '.gitignore'
        ignore.write_text(ignore.read_text() + '/includes/ignored-fixture.php\n')
        self.commit('Record a runtime ignore rule')
        (self.root / 'includes/ignored-fixture.php').write_text('<?php // ignored, but still release input\n')
        before = self.outputs()
        with self.assertRaisesRegex(ValueError, 'uncommitted or untracked'):
            self.prepare()
        self.assertEqual(self.outputs(), before)

    def test_duplicate_zip_path_is_rejected(self):
        self.rewrite(self.plugin, lambda entries: entries + [entries[0]])
        self.reject_without_output_changes()

    def test_traversal_path_is_rejected_without_writing_outside_output(self):
        def traversal(entries):
            entry, data = entries[0]
            entry.filename = 'bhranti/../../escaped.txt'
            return [(entry, data)] + entries[1:]
        self.rewrite(self.plugin, traversal)
        self.reject_without_output_changes()
        self.assertFalse((self.root / 'escaped.txt').exists())

    def test_nested_wrapper_and_missing_root_main_file_are_rejected(self):
        original = self.plugin.read_bytes()
        for name in ('bhranti/bhranti/bhranti.php', 'bhranti/renamed.php'):
            with self.subTest(name=name):
                self.plugin.write_bytes(original)
                def replace_main(entries):
                    for entry, _ in entries:
                        if entry.filename == 'bhranti/bhranti.php':
                            entry.filename = name
                    return entries
                self.rewrite(self.plugin, replace_main)
                self.reject_without_output_changes()

    def test_source_bytes_mismatch_even_at_same_size_is_rejected(self):
        def change(entries):
            entry, data = entries[0]
            return [(entry, bytes([data[0] ^ 1]) + data[1:])] + entries[1:]
        self.rewrite(self.plugin, change)
        self.reject_without_output_changes()

    def test_symlink_entry_is_rejected(self):
        def linked(entries):
            entries[0][0].external_attr = (stat.S_IFLNK | 0o777) << 16
            return entries
        self.rewrite(self.plugin, linked)
        self.reject_without_output_changes()

    def test_declared_uncompressed_size_must_match_checked_source(self):
        data = bytearray(self.plugin.read_bytes())
        offset = data.index(b'PK\x01\x02')
        size = struct.unpack_from('<I', data, offset + 24)[0]
        struct.pack_into('<I', data, offset + 24, size + 1)
        self.plugin.write_bytes(data)
        self.reject_without_output_changes()

    def test_bad_listing_archive_prevents_valid_plugin_replacement(self):
        self.assets.write_bytes(b'not a ZIP archive')
        self.reject_without_output_changes()

    def test_oversized_artifact_is_rejected_before_any_output_changes(self):
        with self.plugin.open('r+b') as output:
            output.truncate(64_000_001)
        self.reject_without_output_changes()

    def prepared_tree(self):
        tree = self.root / 'svn-checkout'
        for directory in ('trunk', 'tags/1.2'):
            for source in release.builder.collect_files():
                target = tree / directory / source.relative_to(self.root)
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(source, target)
        shutil.copytree(self.root / 'wordpress-org/assets', tree / 'assets')
        (tree / 'trunk/.svn').mkdir()
        (tree / 'trunk/.svn/wc.db').write_bytes(b'Native working-copy metadata is excluded')
        return tree

    def test_post_action_tree_accepts_only_exact_trunk_tag_and_assets(self):
        tree = self.prepared_tree()
        release.verify_tree(tree, '1.2')
        main = tree / 'trunk/bhranti.php'
        (tree / 'trunk/bhranti').mkdir()
        main.rename(tree / 'trunk/bhranti/bhranti.php')
        with self.assertRaises(ValueError):
            release.verify_tree(tree, '1.2')

    def test_post_action_changed_tag_or_listing_file_is_rejected(self):
        tree = self.prepared_tree()
        tag = tree / 'tags/1.2/bhranti.php'
        original = tag.read_bytes()
        tag.write_bytes(original + b'\nChanged after preparation\n')
        with self.assertRaises(ValueError):
            release.verify_tree(tree, '1.2')
        tag.write_bytes(original)
        (tree / 'assets/unreviewed.txt').write_text('Unexpected listing output')
        with self.assertRaises(ValueError):
            release.verify_tree(tree, '1.2')

    def remote_transport(self, changed_tag=False, changed_asset=False, property_failure=''):
        def transport(command):
            export = ['svn', 'export', '--ignore-externals', '--non-interactive']
            if command[:4] == export and command[4] == release.SVN_URL + '/tags/1.2':
                destination = Path(command[5])
                for source in release.builder.collect_files():
                    target = destination / source.relative_to(self.root)
                    target.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copyfile(source, target)
                if changed_tag:
                    (destination / 'bhranti.php').write_text('Different published runtime\n')
                return ''
            if command[:4] == export and command[4] == release.SVN_URL + '/assets':
                destination = Path(command[5])
                shutil.copytree(self.root / 'wordpress-org/assets', destination)
                if changed_asset:
                    (destination / 'banner-772x250.png').write_bytes(b'Different published banner')
                return ''
            if command == ['svn', 'propget', 'svn:mime-type', '--xml', '--recursive', '--non-interactive', release.SVN_URL + '/assets']:
                if property_failure == 'malformed':
                    return '<properties'
                entries = []
                for source in release.builder.collect_listing_assets():
                    mime = {'.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml'}[source.suffix]
                    if source.name == 'banner-772x250.png':
                        if property_failure == 'missing':
                            continue
                        if property_failure == 'wrong':
                            mime = 'application/octet-stream'
                    entries.append('<target path="' + release.SVN_URL + '/assets/' + source.name + '"><property name="svn:mime-type">' + mime + '</property></target>')
                return '<?xml version="1.0"?><properties>' + ''.join(entries) + '</properties>'
            self.fail('Unexpected remote verification command: ' + repr(command))
        return transport

    def test_remote_verification_checks_exported_runtime_and_listing_bytes(self):
        with patch.object(release, 'run', side_effect=self.remote_transport()):
            release.verify_remote('1.2')
        for changed in ('tag', 'asset'):
            with self.subTest(changed=changed), patch.object(release, 'run', side_effect=self.remote_transport(
                    changed_tag=changed == 'tag', changed_asset=changed == 'asset')):
                with self.assertRaisesRegex(ValueError, 'exactly match'):
                    release.verify_remote('1.2')

    def test_remote_listing_requires_complete_correct_native_mime_properties(self):
        for failure in ('wrong', 'missing', 'malformed'):
            with self.subTest(failure=failure), patch.object(release, 'run', side_effect=self.remote_transport(property_failure=failure)):
                with self.assertRaisesRegex(ValueError, 'MIME'):
                    release.verify_remote('1.2')


def svn_xml(path, entries):
    return '<?xml version="1.0"?><lists><list path="' + release.SVN_URL + '/' + path + '">' + ''.join(
        '<entry kind="' + kind + '"><name>' + name + '</name><commit revision="1"><author>fixture</author></commit></entry>'
        for kind, name in entries) + '</list></lists>'


class SvnPreflightTests(SourceFixture):
    def responses(self, tags=(), trunk=(), readme='Stable tag: 1.1\n', runtime='Version: 1.1\n'):
        def transport(command):
            if command == ['svn', 'list', '--xml', '--non-interactive', release.SVN_URL + '/tags/']:
                return svn_xml('tags/', tags)
            if command == ['svn', 'list', '--xml', '--non-interactive', release.SVN_URL + '/trunk/']:
                return svn_xml('trunk/', trunk)
            if command == ['svn', 'cat', '--non-interactive', release.SVN_URL + '/trunk/readme.txt']:
                return readme
            if command == ['svn', 'cat', '--non-interactive', release.SVN_URL + '/trunk/bhranti.php']:
                return runtime
            self.fail('Unexpected subprocess command: ' + repr(command))
        return transport

    def test_native_empty_or_older_svn_layout_allows_new_release(self):
        for tags, trunk in [((), ()), ((('dir', '1.1'), ('dir', 'historical-beta')), (('file', 'readme.txt'),))]:
            with self.subTest(tags=tags), patch.object(release, 'run', side_effect=self.responses(tags, trunk)):
                release.svn_check('1.2')

    def test_existing_tag_and_semantically_equal_or_newer_versions_stop(self):
        for version in ('1.2', '1.2.0', '1.2.1', '1.3', '2.0'):
            with self.subTest(version=version), patch.object(release, 'run', side_effect=self.responses((('dir', version),))):
                with self.assertRaisesRegex(ValueError, 'already contains'):
                    release.svn_check('1.2')

    def test_existing_numeric_tag_file_cannot_be_replaced_by_a_release_directory(self):
        for version in ('1.2', '1.2.0', '1.3'):
            with self.subTest(version=version), patch.object(release, 'run', side_effect=self.responses((('file', version),))):
                with self.assertRaisesRegex(ValueError, 'already contains'):
                    release.svn_check('1.2')

    def test_newer_trunk_runtime_blocks_release_despite_stale_stable_tag(self):
        for version in ('1.2', '1.2.0', '1.3', 'garbage'):
            with self.subTest(version=version), patch.object(release, 'run', side_effect=self.responses(
                    trunk=(('file', 'readme.txt'), ('file', 'bhranti.php')),
                    readme='Stable tag: 1.1\n', runtime='Version: ' + version + '\n')):
                with self.assertRaises(ValueError):
                    release.svn_check('1.2')

    def test_equal_newer_or_malformed_trunk_stable_tag_stops(self):
        for stable in ('1.2', '1.2.0', '1.3', 'trunk', 'garbage', '01.2'):
            with self.subTest(stable=stable), patch.object(release, 'run', side_effect=self.responses(
                    trunk=(('file', 'readme.txt'),), readme='Stable tag: ' + stable + '\n')):
                with self.assertRaises(ValueError):
                    release.svn_check('1.2')

    def test_malformed_native_xml_is_not_treated_as_empty_svn(self):
        for xml in ('<lists>', '<error/>', '<lists/>', 'upstream unavailable'):
            with self.subTest(xml=xml), patch.object(release, 'run', return_value=xml), self.assertRaises(ValueError):
                release.svn_check('1.2')

    def test_timeout_and_transport_failure_stop_without_leaking_diagnostics(self):
        with patch.object(release.subprocess, 'run', side_effect=subprocess.TimeoutExpired(['svn'], 120)):
            with self.assertRaises(subprocess.TimeoutExpired):
                release.svn_check('1.2')
        result = subprocess.CompletedProcess(['svn'], 1, stdout='', stderr='private server diagnostic')
        with patch.object(release.subprocess, 'run', return_value=result), self.assertRaises(ValueError) as stopped:
            release.svn_check('1.2')
        self.assertNotIn('private server diagnostic', str(stopped.exception))


if __name__ == '__main__':
    unittest.main(verbosity=2)
