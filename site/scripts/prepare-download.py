#!/usr/bin/env python3
"""Stage the exact verified GitHub release ZIP for the static website."""
import argparse
import hashlib
import importlib.util
import io
import os
from pathlib import Path
import re
import ssl
import stat
import sys
import tempfile
import time
from urllib.error import HTTPError
from urllib.parse import urlparse
from urllib.request import Request, urlopen
import zipfile

sys.dont_write_bytecode = True
MAX_BYTES = 64_000_000
RELEASES = 'https://github.com/sumanbasuli/bhranti/releases/download'


def fetch_once(url, limit):
    request = Request(url, headers={'User-Agent': 'Bhranti-site-release-build'})
    context = ssl.create_default_context()
    if (sys.platform == 'darwin' and 'SSL_CERT_FILE' not in os.environ
            and not ssl.get_default_verify_paths().cafile and Path('/etc/ssl/cert.pem').is_file()):
        context.load_verify_locations('/etc/ssl/cert.pem')
    started = time.monotonic()
    with urlopen(request, timeout=30, context=context) as response:
        destination = urlparse(response.geturl())
        if destination.scheme != 'https' or destination.hostname not in (
                'github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'):
            raise ValueError('Release download redirected outside GitHub HTTPS')
        data = bytearray()
        while True:
            chunk = response.read(min(65_536, limit + 1 - len(data)))
            data.extend(chunk)
            if len(data) > limit or time.monotonic() - started > 90:
                raise ValueError('Release download exceeded its size or time limit')
            if not chunk:
                return bytes(data)


def fetch(url, limit):
    for attempt in range(12):
        try:
            return fetch_once(url, limit)
        except HTTPError as error:
            if error.code != 404:
                raise
            if attempt == 11:
                raise ValueError('Matching GitHub release asset is unavailable; publish its ZIP and checksum before building the website') from error
            time.sleep(10)


def release_checksum(data, filename):
    match = re.fullmatch(rb'([0-9a-f]{64})  ' + re.escape(filename.encode('ascii')) + rb'\n?', data)
    if not match:
        raise ValueError('Published checksum must name the exact release ZIP')
    return match[1].decode('ascii')


def validate_package(data, digest, version, paths, requirements, header):
    if hashlib.sha256(data).hexdigest() != digest:
        raise ValueError('Release ZIP does not match its published SHA-256')
    expected = {'bhranti/' + path for path in paths}
    if not {'bhranti/bhranti.php', 'bhranti/readme.txt'}.issubset(expected):
        raise ValueError('Runtime inventory must contain the direct plugin file and readme')
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        entries = archive.infolist()
        if len(entries) != len(expected) or {entry.filename for entry in entries} != expected:
            raise ValueError('Release ZIP has unexpected, missing or duplicate runtime paths')
        if sum(entry.file_size for entry in entries) > MAX_BYTES or any(
                entry.flag_bits & 1 or stat.S_IFMT(entry.external_attr >> 16) not in (0, stat.S_IFREG)
                for entry in entries):
            raise ValueError('Release ZIP has linked, encrypted or oversized entries')
        if archive.testzip() is not None:
            raise ValueError('Release ZIP failed its integrity check')
        plugin = archive.read('bhranti/bhranti.php').decode('utf-8')[:8192]
        readme = archive.read('bhranti/readme.txt').decode('utf-8')
    if header(plugin, 'Version') != version or header(readme, 'Stable tag') != version:
        raise ValueError('Published plugin Version and Stable tag must match VERSION')
    constants = re.findall(r"define\(\s*['\"]BHRANTI_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)", plugin)
    if constants != [version] or header(plugin, 'Text Domain') != 'bhranti':
        raise ValueError('Published runtime version constant or text domain is incorrect')
    for name, value in requirements.items():
        if header(plugin, name) != value or header(readme, name) != value:
            raise ValueError('Published plugin requirement differs from this release: ' + name)


def prepare(root, output):
    spec = importlib.util.spec_from_file_location('bhranti_build', root / 'scripts/build.py')
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    version = (root / 'VERSION').read_text(encoding='ascii').strip()
    if not re.fullmatch(r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))?', version):
        raise ValueError('VERSION must contain an ASCII numeric release version')
    if builder.validate_metadata() != version:
        raise ValueError('Checkout release metadata does not match VERSION')
    plugin = (root / 'bhranti.php').read_text(encoding='utf-8')[:8192]
    requirements = {name: builder.header(plugin, name) for name in ('Requires at least', 'Requires PHP')}
    paths = {path.relative_to(root).as_posix() for path in builder.collect_files()}
    filename = 'bhranti-' + version + '.zip'
    url = RELEASES + '/v' + version + '/' + filename
    digest = release_checksum(fetch(url + '.sha256', 4096), filename)
    data = fetch(url, MAX_BYTES)
    validate_package(data, digest, version, paths, requirements, builder.header)
    if any(path.is_symlink() for path in (output, *output.parents)):
        raise ValueError('Static download directory must not follow symlinks')
    output.mkdir(parents=True, exist_ok=True)
    for name in (filename, filename + '.sha256'):
        target = output / name
        if target.is_symlink() or (target.exists() and not target.is_file()):
            raise ValueError('Refusing to replace a linked or non-file download')
    with tempfile.TemporaryDirectory(prefix='.release-', dir=output) as temporary:
        stage = Path(temporary)
        (stage / filename).write_bytes(data)
        (stage / (filename + '.sha256')).write_text(digest + '  ' + filename + '\n', encoding='ascii')
        for path in stage.iterdir():
            path.replace(output / path.name)
    print('Staged {} ({} bytes; SHA-256 {})'.format(filename, len(data), digest))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--output-dir', type=Path)
    args = parser.parse_args()
    root = args.root.resolve()
    prepare(root, args.output_dir or root / 'site/public/downloads')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, zipfile.BadZipFile) as error:
        print('Release download preparation failed: ' + str(error), file=sys.stderr)
        sys.exit(1)
