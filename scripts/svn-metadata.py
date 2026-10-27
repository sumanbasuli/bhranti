#!/usr/bin/env python3
"""Prepare, then explicitly publish only the current WordPress.org listing metadata."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('bhranti_release', ROOT / 'scripts/svn-release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
BLUEPRINT = 'assets/blueprints/blueprint.json'


def fail(message):
    raise ValueError(message)


def run(command, password=None):
    # Secrets never enter argv, child environments, artifacts or arbitrary diagnostics.
    environment = {key: value for key, value in os.environ.items() if key not in ('SVN_USERNAME', 'SVN_PASSWORD')}
    result = subprocess.run(command, cwd=ROOT, env=environment, text=True, input=password,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=180, check=False)
    if result.returncode:
        fail('{} failed; metadata publication stopped'.format(command[0]))
    if len(result.stdout) > 8_000_000:
        fail('Metadata command output exceeded its limit')
    return result.stdout


def xml(command, root):
    try:
        tree = ET.fromstring(run(command))
    except ET.ParseError:
        fail('SVN returned invalid XML')
    if tree.tag != root:
        fail('SVN did not return the requested metadata')
    return tree


def regular(path):
    if path.is_symlink() or not path.is_file() or any(parent.is_symlink() for parent in path.parents):
        fail('Metadata inputs must be regular files without linked parents')
    if path.stat().st_size > 1_000_000:
        fail('Metadata input exceeds its limit')
    return path.read_bytes()


def candidates(version):
    readme = regular(ROOT / 'readme.txt')
    if release.builder.header(readme.decode('utf-8'), 'Stable tag') != version:
        fail('The listing Stable tag must remain the current VERSION')
    plugin = regular(ROOT / 'bhranti.php').decode('utf-8')[:8192]
    if release.builder.header(plugin, 'Plugin Name') != 'Bhranti':
        fail('The runtime Plugin Name must remain Bhranti')
    blueprint = regular(ROOT / 'wordpress-org' / BLUEPRINT)
    if len(blueprint) > release.builder.LISTING_BLUEPRINT_MAX_BYTES:
        fail('The reviewed Playground blueprint exceeds its size limit')
    release.builder.validate_listing_blueprint(blueprint)
    return {'trunk/readme.txt': readme, 'tags/' + version + '/readme.txt': readme, BLUEPRINT: blueprint}


def hashes(directory):
    result = {}
    for path in sorted(directory.rglob('*')):
        name = path.relative_to(directory)
        if '.svn' in name.parts:
            continue
        if path.is_symlink():
            fail('SVN listing snapshot contains a symlink')
        if path.is_file():
            result[name.as_posix()] = hashlib.sha256(path.read_bytes()).hexdigest()
    return result


def remote_revision():
    tree = xml(['svn', 'info', '--xml', '--non-interactive', release.SVN_URL], 'info')
    entry = tree.find('entry')
    if entry is None or entry.get('kind') != 'dir' or not re.fullmatch(r'[1-9][0-9]*', entry.get('revision', '')):
        fail('SVN did not return an existing plugin directory revision')
    return entry.get('revision')


def stable_tree(directory, version):
    for folder in ('trunk', 'tags/' + version):
        readme = regular(directory / folder / 'readme.txt').decode('utf-8')
        plugin = regular(directory / folder / 'bhranti.php').decode('utf-8')[:8192]
        if (release.builder.header(readme, 'Stable tag') != version
                or release.builder.header(plugin, 'Version') != version
                or release.builder.header(plugin, 'Plugin Name') != 'Bhranti'):
            fail('Existing SVN trunk and current tag must both remain Bhranti ' + version)


def changes(directory, allowed):
    tree = xml(['svn', 'status', '--xml', '--no-ignore', str(directory)], 'status')
    files, directories = [], []
    for entry in tree.findall('.//entry'):
        path = Path(entry.get('path', ''))
        if not path.is_absolute():
            path = ROOT / path
        try:
            name = path.relative_to(directory).as_posix()
        except ValueError:
            fail('SVN status escaped the prepared checkout')
        status = entry.find('wc-status')
        if status is None or any(status.get(key) == 'true' for key in ('tree-conflicted', 'copied', 'switched')):
            fail('Unexpected SVN working-copy state')
        item, props = status.get('item'), status.get('props')
        if name == 'assets/blueprints' and item == 'added' and props in ('none', 'normal') and path.is_dir():
            directories.append(name)
        elif (name in allowed and item in ('modified', 'added') and path.is_file()
              and (item != 'added' or name == BLUEPRINT)
              and (props in ('none', 'normal') or (name == BLUEPRINT and item == 'added' and props == 'modified'))):
            files.append(name)
        else:
            fail('SVN changes include a path or property outside the metadata allowlist')
    if directories and BLUEPRINT not in files:
        fail('A new blueprint directory must contain only the reviewed blueprint')
    return sorted(files), sorted(directories)


def checked_tree(directory, plan, payload):
    expected = dict(plan['before'])
    expected.update({name: hashlib.sha256(data).hexdigest() for name, data in payload.items()})
    if hashes(directory) != expected or expected != plan['after']:
        fail('Prepared SVN bytes differ from the recorded metadata-only change')
    files, directories = changes(directory, payload)
    actual = sorted(name for name in payload if plan['before'].get(name) != expected[name])
    if files != actual or files != plan['changed'] or directories != plan['added_directories']:
        fail('Prepared SVN status differs from the exact changed-path list')
    stable_tree(directory, plan['version'])
    return files + directories


def require_confirmation(changed, version, publish, acknowledged):
    if publish and 'tags/' + version + '/readme.txt' in changed and not acknowledged:
        fail('Publishing the released readme requires acknowledgement that Release Confirmation is already disabled')


def prepare(directory, commit, event, ref, publish, release_confirmation_disabled, actor):
    if event != 'workflow_dispatch' or ref != 'refs/heads/main':
        fail('Metadata maintenance is manual and must select main')
    release.current_main(commit)
    version = release.builder.validate_metadata()
    release.version_number(version)
    if version == '0.0':
        fail('Metadata maintenance requires an existing stable version')
    payload = candidates(version)
    if not re.fullmatch(r'[A-Za-z0-9_\[\]-]{1,100}', actor):
        fail('Expected the GitHub actor for the audit report')
    directory = Path(directory).absolute()
    if directory.exists() or any(parent.is_symlink() for parent in directory.parents):
        fail('Preparation requires a new directory without linked parents')
    directory.mkdir(parents=True)
    checkout, report = directory / 'svn', directory / 'report'
    report.mkdir()
    revision = remote_revision()
    run(['svn', 'checkout', '--depth', 'immediates', '--ignore-externals', '--non-interactive',
         '--revision', revision, release.SVN_URL + '@' + revision, str(checkout)])
    run(['svn', 'update', '--set-depth', 'infinity', '--ignore-externals', '--non-interactive',
         '--revision', revision, str(checkout / 'trunk'), str(checkout / 'assets'), str(checkout / 'tags' / version)])
    stable_tree(checkout, version)
    if changes(checkout, payload) != ([], []):
        fail('SVN checkout must be clean before metadata preparation')
    before = hashes(checkout)
    if (checkout / 'assets/blueprints').exists() and not (checkout / 'assets/blueprints').is_dir():
        fail('The blueprint parent must be a directory')
    for name, data in payload.items():
        target = checkout / name
        new = not target.exists()
        if new and name != BLUEPRINT:
            fail('Both existing readmes are required')
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        if new:
            run(['svn', 'add', '--parents', '--no-auto-props', '--non-interactive', str(target)])
            run(['svn', 'propset', '--non-interactive', 'svn:mime-type', 'application/json', str(target)])
    files, directories = changes(checkout, payload)
    require_confirmation(files, version, publish, release_confirmation_disabled)
    plan = dict(schema=1, commit=commit, version=version, actor=actor, svn_url=release.SVN_URL,
                revision=revision, publish=publish, release_confirmation_disabled=release_confirmation_disabled,
                before=before, after=hashes(checkout), changed=files, added_directories=directories)
    checked_tree(checkout, plan, payload)
    (report / 'plan.json').write_text(json.dumps(plan, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    (report / 'metadata.diff').write_text(run(['svn', 'diff', '--non-interactive', str(checkout)]), encoding='utf-8')
    (report / 'readme.txt').write_bytes(payload['trunk/readme.txt'])
    (report / 'blueprint.json').write_bytes(payload[BLUEPRINT])
    return plan


def publish(directory, commit, release_confirmation_disabled):
    directory = Path(directory).absolute()
    report, checkout = directory / 'report', directory / 'svn'
    plan = json.loads(regular(report / 'plan.json'))
    if (plan.get('schema') != 1 or plan.get('publish') is not True
            or plan.get('release_confirmation_disabled') is not release_confirmation_disabled
            or plan.get('commit') != commit or plan.get('svn_url') != release.SVN_URL):
        fail('Publication requires the exact explicitly approved metadata preparation')
    release.current_main(commit)
    if plan['version'] != release.builder.validate_metadata():
        fail('Prepared metadata no longer matches the current source version')
    payload = candidates(plan['version'])
    if (regular(report / 'readme.txt') != payload['trunk/readme.txt']
            or regular(report / 'blueprint.json') != payload[BLUEPRINT]):
        fail('Review artifact differs from the selected source commit')
    targets = checked_tree(checkout, plan, payload)
    require_confirmation(targets, plan['version'], True, release_confirmation_disabled)
    # Reject any plugin change since preparation; unrelated repositories may advance global HEAD.
    changed = xml(['svn', 'diff', '--summarize', '--xml', '--non-interactive',
                   '--revision', plan['revision'] + ':HEAD', release.SVN_URL], 'diff')
    if changed.findall('.//path'):
        fail('The remote plugin changed after preparation; run a fresh dry run')
    if not targets:
        return dict(published=False, unchanged=True, commit=commit, version=plan['version'])
    username, password = os.environ.get('SVN_USERNAME', ''), os.environ.get('SVN_PASSWORD', '')
    if not username or not password or any(character in username + password for character in '\r\n\0'):
        fail('The existing SVN_USERNAME and SVN_PASSWORD repository secrets are required')
    client = run(['svn', '--version', '--quiet']).strip().split('.')
    if len(client) < 2 or not all(part.isdigit() for part in client[:2]) or tuple(map(int, client[:2])) < (1, 14):
        fail('SVN 1.14 or newer is required for password input through stdin')
    with tempfile.TemporaryDirectory(prefix='bhranti-svn-auth-') as auth:
        target_file = Path(auth) / 'targets.txt'
        target_file.write_text(''.join(str(checkout / name) + '\n' for name in targets), encoding='utf-8')
        output = run(['svn', 'commit', '--non-interactive', '--no-auth-cache', '--config-dir', auth,
                      '--username', username, '--password-from-stdin', '--depth', 'empty',
                      '--targets', str(target_file), '--message',
                      'Update Bhranti ' + plan['version'] + ' listing metadata; Git ' + commit], password + '\n')
    revisions = re.findall(r'^Committed revision ([1-9][0-9]*)\.$', output, re.M)
    if len(revisions) != 1:
        fail('SVN commit result is uncertain; inspect SVN before any retry')
    revision = revisions[0]
    log = xml(['svn', 'log', '--xml', '--verbose', '--non-interactive', '--revision', revision, release.SVN_URL], 'log')
    info = xml(['svn', 'info', '--xml', '--non-interactive', release.SVN_URL + '@' + revision], 'info')
    relative = info.findtext('entry/relative-url', '')
    if not relative.startswith('^/'):
        fail('SVN did not identify the committed plugin path')
    base = relative[1:].rstrip('/')
    paths = log.findall('logentry/paths/path')
    expected = {base + '/' + name for name in targets}
    if {path.text for path in paths} != expected or any(path.get('action') not in ('A', 'M') for path in paths):
        fail('Committed revision contains unexpected paths; inspect SVN before any retry')
    run(['svn', 'update', '--ignore-externals', '--non-interactive', '--revision', revision, str(checkout)])
    if hashes(checkout) != plan['after'] or changes(checkout, payload) != ([], []):
        fail('Committed SVN bytes differ from the reviewed metadata-only result; inspect SVN before any retry')
    stable_tree(checkout, plan['version'])
    result = dict(published=True, revision=revision, commit=commit, version=plan['version'], actor=plan['actor'], changed=targets)
    (report / 'published.json').write_text(json.dumps(result, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    for name in ('prepare', 'publish'):
        command = commands.add_parser(name)
        command.add_argument('--directory', required=True)
        command.add_argument('--commit', required=True)
        command.add_argument('--release-confirmation-disabled', choices=('false', 'true'), default='false')
        if name == 'prepare':
            command.add_argument('--event', required=True)
            command.add_argument('--ref', required=True)
            command.add_argument('--publish', choices=('false', 'true'), default='false')
            command.add_argument('--actor', required=True)
    args = parser.parse_args()
    if args.command == 'prepare':
        result = prepare(args.directory, args.commit, args.event, args.ref, args.publish == 'true',
                         args.release_confirmation_disabled == 'true', args.actor)
        result = {key: value for key, value in result.items() if key not in ('before', 'after')}
    else:
        result = publish(args.directory, args.commit, args.release_confirmation_disabled == 'true')
    print(json.dumps(result, sort_keys=True))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError) as error:
        print('Metadata maintenance stopped: ' + str(error), file=sys.stderr)
        sys.exit(1)
