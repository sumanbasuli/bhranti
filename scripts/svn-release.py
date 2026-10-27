#!/usr/bin/env python3
"""Validate the publication marker and deploy only the checked Bhranti artifact."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
import zipfile

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('bhranti_build', ROOT / 'scripts/build.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
SVN_URL = 'https://plugins.svn.wordpress.org/bhranti'
VERSION_RE = r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))?'


def fail(message):
    raise ValueError(message)


def version_number(value):
    if not re.fullmatch(VERSION_RE, value):
        fail('Expected a numeric version such as 1.2 or 1.2.1')
    return tuple(int(part) for part in value.split('.')) + (0,) * (3 - len(value.split('.')))


def marker(text):
    if not re.fullmatch(VERSION_RE + r'\n?', text):
        fail('SVN-RELEASE must contain exactly one numeric version')
    return text.rstrip('\n')


def run(command):
    result = subprocess.run(command, cwd=ROOT, text=True, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=120, check=False)
    if result.returncode:
        # Never echo command arguments or arbitrary server diagnostics: later steps use SVN secrets.
        fail('{} failed; publication stopped'.format(command[0]))
    if len(result.stdout) > 2_000_000:
        fail('Release preflight output exceeded its limit')
    return result.stdout


def source_clean():
    paths = ['SVN-RELEASE', 'VERSION', 'includes', 'wordpress-org/assets', 'scripts', '.github'] + list(builder.REQUIRED_FILES)
    if run(['git', 'status', '--porcelain', '--untracked-files=all', '--ignored=matching', '--'] + paths).strip():
        fail('Release inputs contain uncommitted or untracked changes')


def checked_commit(value):
    if not re.fullmatch(r'[0-9a-f]{40}', value):
        fail('Expected the full checked Git commit')
    if run(['git', 'rev-parse', 'HEAD']).strip() != value:
        fail('The checkout is not the selected release commit')
    source_clean()
    return value


def plan(event, ref, before, commit, requested):
    checked_commit(commit)
    if ref != 'refs/heads/main':
        fail('WordPress.org releases and dry runs must use main')
    current = marker((ROOT / 'SVN-RELEASE').read_text(encoding='ascii'))
    if event == 'workflow_dispatch':
        if requested != builder.validate_metadata() or requested == '0.0':
            fail('Dry-run version must match the current plugin version')
        version_number(requested)
        return dict(active=True, publish=False, version=requested, commit=commit)
    if event != 'push' or requested:
        fail('Unsupported publication event')
    if current == '0.0':
        return dict(active=False, publish=False, version=builder.validate_metadata(), commit=commit)
    if not re.fullmatch(r'[0-9a-f]{40}', before) or before == '0' * 40:
        fail('A known previous main commit is required; push the inactive 0.0 marker first')
    run(['git', 'cat-file', '-e', before + '^{commit}'])
    if run(['git', 'ls-tree', '--name-only', before, '--', 'SVN-RELEASE']).strip() != 'SVN-RELEASE':
        fail('The previous commit has no marker; push SVN-RELEASE as 0.0 first')
    previous = marker(run(['git', 'show', before + ':SVN-RELEASE']))
    if current == previous:
        return dict(active=False, publish=False, version=builder.validate_metadata(), commit=commit)
    if version_number(current) <= version_number(previous):
        fail('SVN-RELEASE must increase; published versions cannot be replaced')
    if current != builder.validate_metadata():
        fail('SVN-RELEASE must match VERSION, the plugin header, constant and Stable tag')
    return dict(active=True, publish=True, version=current, commit=commit)


def current_main(commit):
    checked_commit(commit)
    result = run(['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/main']).split()
    if result != [commit, 'refs/heads/main']:
        fail('main has advanced; rebuild and validate the intended release commit')


def svn_listing(path):
    data = run(['svn', 'list', '--xml', '--non-interactive', SVN_URL + '/' + path])
    try:
        tree = ET.fromstring(data)
    except ET.ParseError:
        fail('SVN returned an invalid directory listing')
    listing = tree.find('list')
    if tree.tag != 'lists' or listing is None:
        fail('SVN did not return the requested directory')
    return [(entry.get('kind'), entry.findtext('name', '')) for entry in listing.findall('entry')]


def svn_check(version):
    target = version_number(version)
    if target == (0, 0, 0) or version != builder.validate_metadata():
        fail('SVN release version must match the checked plugin')
    for kind, name in svn_listing('tags/'):
        if name == version or (re.fullmatch(VERSION_RE, name) and version_number(name) >= target):
            fail('SVN already contains this version or a newer version; refusing to replace it')
    trunk = dict((name, kind) for kind, name in svn_listing('trunk/'))
    if 'readme.txt' in trunk:
        stable = builder.header(run(['svn', 'cat', '--non-interactive', SVN_URL + '/trunk/readme.txt']), 'Stable tag')
        if not re.fullmatch(VERSION_RE, stable) or version_number(stable) >= target:
            fail('SVN trunk already names this version or a newer version')
    if 'bhranti.php' in trunk:
        runtime = builder.header(run(['svn', 'cat', '--non-interactive', SVN_URL + '/trunk/bhranti.php']), 'Version')
        if not re.fullmatch(VERSION_RE, runtime) or version_number(runtime) >= target:
            fail('SVN trunk already contains this runtime version or a newer version')


def archive_payload(path, digest, expected, prefix):
    if not re.fullmatch(r'[0-9a-f]{64}', digest):
        fail('Expected the checked artifact SHA-256')
    if path.is_symlink() or not path.is_file() or path.stat().st_size > 64_000_000:
        fail('Missing, linked or oversized release artifact')
    if hashlib.sha256(path.read_bytes()).hexdigest() != digest:
        fail('Artifact checksum differs from the checked build')
    contents = {}
    with zipfile.ZipFile(path) as archive:
        entries = archive.infolist()
        if len(entries) != len(expected) or {entry.filename for entry in entries} != set(expected):
            fail('Artifact contains unexpected, missing or duplicate paths')
        for entry in entries:
            source = expected[entry.filename]
            mode = entry.external_attr >> 16
            if entry.flag_bits & 1 or (stat.S_IFMT(mode) not in (0, stat.S_IFREG)) or entry.file_size != source.stat().st_size:
                fail('Artifact contains an invalid file entry')
            data = archive.read(entry)
            if data != source.read_bytes():
                fail('Artifact differs from the checked source: ' + entry.filename)
            name = entry.filename[len(prefix):]
            if not name or Path(name).is_absolute() or '..' in Path(name).parts:
                fail('Artifact path escapes the deployment directory')
            contents[name] = data
    return contents


def prepare(version, plugin_sha, assets_sha, artifact_dir):
    source_clean()
    if version != builder.validate_metadata():
        fail('Artifact version does not match the checked source')
    files = builder.collect_files()
    listing = builder.collect_listing_assets()
    expected = {'bhranti/' + path.relative_to(ROOT).as_posix(): path for path in files}
    # The archive wrapper is stripped exactly once: the SVN root contains bhranti.php directly.
    if 'bhranti/bhranti.php' not in expected or 'bhranti/readme.txt' not in expected:
        fail('The main plugin file and readme must be directly inside the release root')
    artifacts = Path(artifact_dir)
    if artifacts.is_symlink() or not artifacts.is_dir():
        fail('Artifact directory must be a real directory')
    plugin = archive_payload(artifacts / ('bhranti-' + version + '.zip'), plugin_sha, expected, 'bhranti/')
    assets = archive_payload(artifacts / 'wordpress-org-assets.zip', assets_sha, {path.name: path for path in listing}, '')
    build = ROOT / 'build'
    if build.is_symlink() or (build.exists() and not build.is_dir()):
        fail('Build directory must not be a symlink')
    build.mkdir(exist_ok=True)
    targets = ((build / 'bhranti', plugin), (ROOT / 'wordpress-org/assets', assets))
    if any(target.is_symlink() for target, _ in targets):
        fail('Deployment directory must not be a symlink')
    with tempfile.TemporaryDirectory(prefix='.svn-artifact-', dir=build) as temporary:
        stage = Path(temporary)
        for index, (_, payload) in enumerate(targets):
            folder = stage / str(index)
            folder.mkdir()
            for name, data in payload.items():
                path = folder / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(data)
                path.chmod(0o644)
        for index, (target, _) in enumerate(targets):
            if target.exists():
                shutil.rmtree(target)
            (stage / str(index)).replace(target)
    return dict(plugin_files=len(plugin), listing_files=len(assets), main_file='build/bhranti/bhranti.php')


def compare_tree(directory, expected):
    files = {}
    if directory.is_symlink() or not directory.is_dir():
        fail('Missing deployment directory')
    for path in directory.rglob('*'):
        if '.svn' in path.relative_to(directory).parts:
            continue
        if path.is_symlink():
            fail('Unexpected linked file in SVN payload')
        if path.is_file():
            files[path.relative_to(directory).as_posix()] = path
    if set(files) != set(expected) or any(path.read_bytes() != expected[name].read_bytes() for name, path in files.items()):
        fail('SVN payload does not exactly match the checked build')


def verify_tree(directory, version):
    if version != builder.validate_metadata():
        fail('SVN version does not match the checked source')
    files = {path.relative_to(ROOT).as_posix(): path for path in builder.collect_files()}
    assets = {path.name: path for path in builder.collect_listing_assets()}
    directory = Path(directory)
    compare_tree(directory / 'trunk', files)
    compare_tree(directory / 'tags' / version, files)
    compare_tree(directory / 'assets', assets)


def verify_remote(version):
    if version != builder.validate_metadata():
        fail('SVN version does not match the checked source')
    with tempfile.TemporaryDirectory(prefix='bhranti-published-tag-') as temporary:
        target = Path(temporary) / 'tag'
        run(['svn', 'export', '--ignore-externals', '--non-interactive', SVN_URL + '/tags/' + version, str(target)])
        compare_tree(target, {path.relative_to(ROOT).as_posix(): path for path in builder.collect_files()})
        assets = Path(temporary) / 'assets'
        run(['svn', 'export', '--ignore-externals', '--non-interactive', SVN_URL + '/assets', str(assets)])
        expected = {path.name: path for path in builder.collect_listing_assets()}
        compare_tree(assets, expected)
        properties = run(['svn', 'propget', 'svn:mime-type', '--xml', '--recursive', '--non-interactive', SVN_URL + '/assets'])
        try:
            tree = ET.fromstring(properties)
        except ET.ParseError:
            fail('SVN returned invalid listing MIME properties')
        actual = {entry.get('path', '').rsplit('/', 1)[-1]: entry.findtext("property[@name='svn:mime-type']") for entry in tree.findall('target')}
        mime = {'.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml'}
        if actual != {name: mime[Path(name).suffix] for name in expected}:
            fail('Published listing MIME properties do not match the checked assets')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    selection = commands.add_parser('plan')
    for name in ('event', 'ref', 'before', 'commit', 'version'):
        selection.add_argument('--' + name, default='')
    selection.add_argument('--github-output')
    check = commands.add_parser('current-main')
    check.add_argument('--commit', required=True)
    for command in ('svn-check', 'verify-remote'):
        commands.add_parser(command).add_argument('--version', required=True)
    restore = commands.add_parser('prepare')
    for name in ('version', 'plugin-sha', 'assets-sha', 'artifact-dir'):
        restore.add_argument('--' + name, required=True)
    tree = commands.add_parser('verify-tree')
    tree.add_argument('--directory', required=True)
    tree.add_argument('--version', required=True)
    args = parser.parse_args()
    if args.command == 'plan':
        result = plan(args.event, args.ref, args.before, args.commit, args.version)
        if args.github_output:
            with open(args.github_output, 'a', encoding='utf-8') as output:
                for name, value in result.items():
                    output.write('{}={}\n'.format(name, str(value).lower() if isinstance(value, bool) else value))
    elif args.command == 'prepare':
        result = prepare(args.version, args.plugin_sha, args.assets_sha, args.artifact_dir)
    else:
        if args.command == 'current-main':
            current_main(args.commit)
        elif args.command == 'svn-check':
            svn_check(args.version)
        elif args.command == 'verify-tree':
            verify_tree(args.directory, args.version)
        else:
            verify_remote(args.version)
        result = dict(check=args.command, passed=True)
    print(json.dumps(result, sort_keys=True))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, subprocess.SubprocessError, zipfile.BadZipFile) as error:
        print('Release stopped: ' + str(error), file=sys.stderr)
        sys.exit(1)
