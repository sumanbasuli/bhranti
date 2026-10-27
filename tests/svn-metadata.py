#!/usr/bin/env python3
"""Metadata regressions use only disposable Git and local file:// SVN repositories."""
from contextlib import ExitStack
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parent.parent
SOURCE = Path(os.environ.get('BHRANTI_METADATA_TEST_SOURCE', ROOT))
spec = importlib.util.spec_from_file_location('bhranti_svn_metadata', ROOT / 'scripts/svn-metadata.py')
metadata = importlib.util.module_from_spec(spec)
spec.loader.exec_module(metadata)


def command(*args, cwd=None):
    return subprocess.run(args, cwd=cwd, text=True, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout.strip()


class RequestTests(unittest.TestCase):
    def test_publication_without_release_confirmation_acknowledgement_fails_before_io(self):
        with patch.object(metadata.release, 'current_main') as current, self.assertRaisesRegex(ValueError, 'already disabled'):
            metadata.prepare('/unused', 'a' * 40, 'workflow_dispatch', 'refs/heads/main', True, False, 'fixture')
        current.assert_not_called()

    def test_automatic_events_or_non_main_refs_are_rejected_before_io(self):
        for event, ref in [('push', 'refs/heads/main'), ('workflow_dispatch', 'refs/heads/feature'), ('workflow_dispatch', 'refs/tags/v1.2')]:
            with self.subTest(event=event, ref=ref), patch.object(metadata.release, 'current_main') as current:
                with self.assertRaisesRegex(ValueError, 'manual'):
                    metadata.prepare('/unused', 'a' * 40, event, ref, False, False, 'fixture')
                current.assert_not_called()

    def test_failed_command_never_exposes_credentials_or_server_diagnostics(self):
        result = subprocess.CompletedProcess(['svn'], 1, stdout='private output', stderr='private diagnostic')
        with patch.object(metadata.subprocess, 'run', return_value=result) as execute:
            with patch.dict(os.environ, {'SVN_USERNAME': 'fixture', 'SVN_PASSWORD': 'private password'}):
                with self.assertRaises(ValueError) as stopped:
                    metadata.run(['svn', 'commit', '--password-from-stdin'], 'private password\n')
        self.assertNotIn('private', str(stopped.exception))
        self.assertNotIn('SVN_PASSWORD', execute.call_args.kwargs['env'])
        self.assertNotIn('SVN_USERNAME', execute.call_args.kwargs['env'])

    def test_workflow_is_manual_and_credentials_are_scoped_to_the_final_step(self):
        workflow = (ROOT / '.github/workflows/metadata.yml').read_text()
        self.assertIn('workflow_dispatch:', workflow)
        self.assertNotIn('\n  push:', workflow)
        self.assertEqual(workflow.count('default: false'), 2)
        final = workflow.partition('      - name: Publish only the explicitly selected metadata')[2]
        self.assertIn('if: inputs.publish && inputs.release_confirmation_disabled', final)
        self.assertEqual(final.count('${{ secrets.'), 2)
        self.assertEqual(workflow.count('${{ secrets.'), 2)
        self.assertIn('group: bhranti-wordpress-org', workflow)
        self.assertIn('persist-credentials: false', workflow)


@unittest.skipUnless(shutil.which('svn') and shutil.which('svnadmin'), 'Native SVN client and svnadmin are required')
class NativeSvnTests(unittest.TestCase):
    def setUp(self):
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.temporary = Path(self.stack.enter_context(tempfile.TemporaryDirectory(prefix='bhranti-metadata-test-')))
        self.source = self.temporary / 'source'
        self.source.mkdir()
        for name in ('VERSION', 'bhranti.php', 'readme.txt', 'LICENSE'):
            shutil.copyfile(SOURCE / name, self.source / name)
        self.version = (self.source / 'VERSION').read_text().strip()
        metadata.release.version_number(self.version)
        self.tag = 'tags/' + self.version
        self.repository = self.temporary / 'repository'
        command('svnadmin', 'create', str(self.repository))
        self.url = self.repository.as_uri() + '/bhranti'
        self.seed = self.temporary / 'seed'
        for folder in ('trunk', self.tag):
            target = self.seed / folder
            target.mkdir(parents=True)
            for name in ('readme.txt', 'bhranti.php'):
                shutil.copyfile(self.source / name, target / name)
            (target / 'includes').mkdir()
            (target / 'includes/unchanged.php').write_text('<?php /* Existing stable runtime. */\n')
        (self.seed / 'assets').mkdir()
        (self.seed / 'assets/banner-772x250.png').write_bytes(b'Existing listing artwork\n')
        command('svn', 'import', '--quiet', '--non-interactive', '--message', 'Existing Bhranti ' + self.version + ' fixture', str(self.seed), self.url)
        readme = self.source / 'readme.txt'
        readme.write_text(readme.read_text().replace('=== Bhranti ===', '=== Bhranti – WordPress AI assistant ===', 1) + '\nReviewed metadata fixture.\n')
        blueprint = self.source / 'wordpress-org/assets/blueprints/blueprint.json'
        blueprint.parent.mkdir(parents=True)
        blueprint.write_bytes((SOURCE / 'wordpress-org/assets/blueprints/blueprint.json').read_bytes())
        command('git', 'init', '--quiet', '--initial-branch=main', cwd=self.source)
        command('git', 'config', 'user.name', 'Metadata fixture', cwd=self.source)
        command('git', 'config', 'user.email', 'metadata-fixture@example.invalid', cwd=self.source)
        command('git', 'config', 'commit.gpgsign', 'false', cwd=self.source)
        command('git', 'config', 'core.hooksPath', '/dev/null', cwd=self.source)
        command('git', 'remote', 'add', 'origin', self.source.as_uri(), cwd=self.source)
        self.commit = self.commit_source()
        for module in (metadata, metadata.release, metadata.release.builder):
            self.stack.enter_context(patch.object(module, 'ROOT', self.source))
        self.stack.enter_context(patch.object(metadata.release, 'SVN_URL', self.url))
        self.directory = self.temporary / 'prepared'

    def commit_source(self):
        command('git', 'add', '--all', cwd=self.source)
        command('git', 'commit', '--quiet', '--message', 'Reviewed listing metadata', cwd=self.source)
        return command('git', 'rev-parse', 'HEAD', cwd=self.source)

    def prepare(self, publish=False, acknowledged=False):
        return metadata.prepare(self.directory, self.commit, 'workflow_dispatch', 'refs/heads/main', publish, acknowledged, 'fixture[bot]')

    def publish(self):
        with patch.dict(os.environ, {'SVN_USERNAME': 'fixture', 'SVN_PASSWORD': 'fixture-password-only'}):
            return metadata.publish(self.directory, self.commit, True)

    def remote_write(self, name, data):
        checkout = self.temporary / ('external-' + str(len(list(self.temporary.glob('external-*')))))
        command('svn', 'checkout', '--quiet', '--non-interactive', self.url, str(checkout))
        target = checkout / name
        target.write_text(data)
        command('svn', 'commit', '--quiet', '--non-interactive', '--message', 'Independent fixture change', str(target))

    def test_default_dry_run_retains_exact_review_and_never_commits(self):
        before = metadata.remote_revision()
        plan = self.prepare()
        self.assertFalse(plan['publish'])
        self.assertFalse(plan['release_confirmation_disabled'])
        self.assertEqual(metadata.remote_revision(), before)
        self.assertEqual(plan['changed'], ['assets/blueprints/blueprint.json', self.tag + '/readme.txt', 'trunk/readme.txt'])
        self.assertEqual(plan['added_directories'], ['assets/blueprints'])
        self.assertEqual(set(plan['before']) ^ set(plan['after']), {metadata.BLUEPRINT})
        for name in plan['before']:
            if name not in plan['changed']:
                self.assertEqual(plan['before'][name], plan['after'][name])
        self.assertIn('Reviewed metadata fixture', (self.directory / 'report/metadata.diff').read_text())
        self.assertEqual((self.directory / 'report/readme.txt').read_bytes(), (self.source / 'readme.txt').read_bytes())
        self.assertEqual(plan['actor'], 'fixture[bot]')
        with self.assertRaisesRegex(ValueError, 'explicitly approved'):
            self.publish()

    def test_live_native_commit_is_atomic_exact_and_preserves_runtime_and_version(self):
        plan = self.prepare(True, True)
        with patch.object(metadata, 'run', wraps=metadata.run) as transport:
            result = self.publish()
        self.assertTrue(result['published'])
        self.assertEqual(result['revision'], str(int(plan['revision']) + 1))
        self.assertEqual(result['changed'], plan['changed'] + plan['added_directories'])
        commits = [call for call in transport.call_args_list if call.args[0][:2] == ['svn', 'commit']]
        self.assertEqual(len(commits), 1)
        self.assertIn('--depth', commits[0].args[0])
        self.assertIn('--password-from-stdin', commits[0].args[0])
        self.assertNotIn('fixture-password-only', repr(commits[0].args[0]))
        self.assertEqual(commits[0].args[1], 'fixture-password-only\n')
        info = command('svn', 'log', '--xml', '--verbose', '--revision', result['revision'], self.url)
        self.assertNotIn('/bhranti/trunk/bhranti.php', info)
        self.assertNotIn('/bhranti/' + self.tag + '/bhranti.php', info)
        self.assertIn('/bhranti/' + self.tag + '/readme.txt', info)
        self.assertEqual((self.source / 'VERSION').read_text().strip(), self.version)
        self.assertEqual(metadata.hashes(self.directory / 'svn'), plan['after'])
        published = json.loads((self.directory / 'report/published.json').read_text())
        self.assertEqual(published['revision'], result['revision'])

    def test_existing_blueprint_changes_without_adding_any_directory(self):
        first = self.prepare(True, True)
        self.publish()
        shutil.rmtree(self.directory)
        blueprint = self.source / 'wordpress-org/assets/blueprints/blueprint.json'
        blueprint.write_bytes(blueprint.read_bytes() + b'\n')
        self.commit = self.commit_source()
        plan = self.prepare(True, True)
        self.assertEqual(plan['changed'], [metadata.BLUEPRINT])
        self.assertEqual(plan['added_directories'], [])
        result = self.publish()
        self.assertEqual(result['changed'], [metadata.BLUEPRINT])
        self.assertEqual(plan['before']['trunk/bhranti.php'], first['before']['trunk/bhranti.php'])

    def test_matching_metadata_is_a_noop_without_credentials(self):
        self.prepare(True, True)
        self.publish()
        shutil.rmtree(self.directory)
        self.prepare(True, True)
        with patch.dict(os.environ, {'SVN_USERNAME': '', 'SVN_PASSWORD': ''}):
            result = metadata.publish(self.directory, self.commit, True)
        self.assertEqual(result['published'], False)
        self.assertTrue(result['unchanged'])

    def test_unexpected_runtime_edit_or_property_change_is_rejected(self):
        self.prepare(True, True)
        path = self.directory / 'svn/trunk/includes/unchanged.php'
        original = path.read_bytes()
        path.write_bytes(b'Changed runtime')
        with self.assertRaisesRegex(ValueError, 'bytes differ'):
            self.publish()
        path.write_bytes(original)
        command('svn', 'propset', 'svn:eol-style', 'LF', str(self.directory / 'svn/trunk/readme.txt'))
        with self.assertRaisesRegex(ValueError, 'allowlist'):
            self.publish()

    def test_untracked_file_or_sibling_blueprint_is_rejected(self):
        self.prepare(True, True)
        extra = self.directory / 'svn/assets/blueprints/unreviewed.json'
        extra.write_text('{}\n')
        with self.assertRaisesRegex(ValueError, 'bytes differ'):
            self.publish()

    def test_changed_review_artifact_is_rejected(self):
        self.prepare(True, True)
        (self.directory / 'report/readme.txt').write_text('Tampered review')
        with self.assertRaisesRegex(ValueError, 'Review artifact'):
            self.publish()

    def test_remote_changes_after_preparation_stop_before_commit(self):
        self.prepare(True, True)
        self.remote_write('trunk/includes/unchanged.php', 'Independent new runtime\n')
        revision = metadata.remote_revision()
        with self.assertRaisesRegex(ValueError, 'remote plugin changed'):
            self.publish()
        self.assertEqual(metadata.remote_revision(), revision)

    def test_live_requires_acknowledgement_again(self):
        self.prepare(True, True)
        with self.assertRaisesRegex(ValueError, 'explicitly approved'):
            metadata.publish(self.directory, self.commit, False)

    def test_stale_commit_and_dirty_inputs_stop_before_preparation(self):
        with self.assertRaises(ValueError):
            metadata.prepare(self.directory, 'a' * 40, 'workflow_dispatch', 'refs/heads/main', False, False, 'fixture')
        (self.source / 'readme.txt').write_text('Uncommitted listing change\n')
        with self.assertRaisesRegex(ValueError, 'uncommitted'):
            self.prepare()
        self.assertFalse(self.directory.exists())

    def test_missing_or_mismatched_stable_tag_is_rejected(self):
        self.remote_write(self.tag + '/readme.txt', 'Stable tag: 0.0\n')
        with self.assertRaisesRegex(ValueError, 'both remain'):
            self.prepare()
        shutil.rmtree(self.directory)
        command('svn', 'delete', '--quiet', '--non-interactive', '--message', 'Remove fixture tag', self.url + '/' + self.tag)
        with self.assertRaises(ValueError):
            self.prepare()

    def test_runtime_name_and_version_cannot_change(self):
        plugin = self.source / 'bhranti.php'
        original = plugin.read_text()
        plugin.write_text(original.replace('Plugin Name: Bhranti', 'Plugin Name: Other', 1))
        self.commit = self.commit_source()
        with self.assertRaisesRegex(ValueError, 'remain Bhranti'):
            self.prepare()
        other_version = str(int(self.version.split('.')[0]) + 1) + '.0'
        plugin.write_text(original.replace('Version: ' + self.version, 'Version: ' + other_version, 1))
        self.commit = self.commit_source()
        with self.assertRaisesRegex(ValueError, 'must match'):
            self.prepare()

    def test_invalid_unreviewed_oversized_or_linked_blueprint_is_rejected(self):
        blueprint = self.source / 'wordpress-org/assets/blueprints/blueprint.json'
        original = blueprint.read_bytes()
        blueprint.write_text('not JSON')
        self.commit = self.commit_source()
        with self.assertRaisesRegex(ValueError, 'valid UTF-8 JSON'):
            self.prepare()
        unreviewed = json.loads(original)
        unreviewed['steps'].append({'step': 'runPHP', 'code': '<?php echo "unreviewed";'})
        for data in (json.dumps(unreviewed).encode(), original + b' ' * metadata.release.builder.LISTING_BLUEPRINT_MAX_BYTES):
            blueprint.write_bytes(data)
            self.commit = self.commit_source()
            with self.assertRaisesRegex(ValueError, 'reviewed Bhranti|size limit'):
                self.prepare()
        blueprint.unlink()
        blueprint.symlink_to(self.source / 'readme.txt')
        self.commit = self.commit_source()
        with self.assertRaisesRegex(ValueError, 'regular files'):
            self.prepare()


if __name__ == '__main__':
    unittest.main(verbosity=2)
