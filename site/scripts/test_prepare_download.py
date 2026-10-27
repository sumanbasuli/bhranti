#!/usr/bin/env python3
"""Release-download trust-boundary checks using small in-memory ZIP fixtures."""
import hashlib
import importlib.util
import io
from pathlib import Path
import re
import stat
import ssl
import sys
import unittest
from unittest.mock import Mock, patch
from types import SimpleNamespace
import warnings
from urllib.error import HTTPError
import zipfile

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('prepare_download', Path(__file__).with_name('prepare-download.py'))
download = importlib.util.module_from_spec(spec)
spec.loader.exec_module(download)
PATHS = {'bhranti.php', 'readme.txt', 'assets/admin.js'}
REQUIREMENTS = {'Requires at least': '7.0', 'Requires PHP': '8.0'}
PLUGIN = """<?php
/*
Version: 1.2
Requires at least: 7.0
Requires PHP: 8.0
Text Domain: bhranti
*/
define( 'BHRANTI_VERSION', '1.2' );
"""
README = '=== Bhranti ===\nStable tag: 1.2\nRequires at least: 7.0\nRequires PHP: 8.0\n\nPublished description.\n'


def header(text, name):
    matches = re.findall(r'^[ \t*]*' + re.escape(name) + r':[ \t]*(.+?)\s*$', text, re.M)
    if len(matches) != 1:
        raise ValueError('Expected exactly one header')
    return matches[0]


def package(files=None, linked=None, duplicate=False):
    files = files or {'bhranti/bhranti.php': PLUGIN, 'bhranti/readme.txt': README, 'bhranti/assets/admin.js': 'published code'}
    buffer = io.BytesIO()
    with warnings.catch_warnings(), zipfile.ZipFile(buffer, 'w') as archive:
        warnings.simplefilter('ignore', UserWarning)
        for name, data in files.items():
            entry = zipfile.ZipInfo(name)
            entry.create_system = 3
            entry.external_attr = ((stat.S_IFLNK if name == linked else stat.S_IFREG) | 0o644) << 16
            archive.writestr(entry, data)
        if duplicate:
            archive.writestr('bhranti/readme.txt', README)
    return buffer.getvalue()


def validate(data, digest=None):
    download.validate_package(data, digest or hashlib.sha256(data).hexdigest(), '1.2', PATHS, REQUIREMENTS, header)


class DownloadTests(unittest.TestCase):
    def test_download_context_keeps_certificate_and_hostname_verification(self):
        response = io.BytesIO(b"verified fixture")
        response.geturl = lambda: "https://github.com/asset"
        with patch.object(download, "urlopen", return_value=response) as request:
            self.assertEqual(download.fetch("https://github.com/asset", 100), b"verified fixture")
        context = request.call_args.kwargs["context"]
        self.assertEqual(context.verify_mode, ssl.CERT_REQUIRED)
        self.assertTrue(context.check_hostname)

    def test_system_ca_fallback_respects_platform_and_explicit_configuration(self):
        cases = (("darwin", {}, None, True, True), ("linux", {}, None, True, False), ("darwin", {"SSL_CERT_FILE": "/explicit.pem"}, None, True, False), ("darwin", {}, "/configured.pem", True, False), ("darwin", {}, None, False, False))
        for platform, environment, cafile, exists, expected in cases:
            context = Mock()
            response = io.BytesIO(b"fixture")
            response.geturl = lambda: "https://github.com/asset"
            with self.subTest(platform=platform, environment=environment, cafile=cafile, exists=exists), patch.object(download.sys, "platform", platform), patch.dict(download.os.environ, environment, clear=True), patch.object(download.ssl, "create_default_context", return_value=context), patch.object(download.ssl, "get_default_verify_paths", return_value=SimpleNamespace(cafile=cafile)), patch.object(download.Path, "is_file", return_value=exists), patch.object(download, "urlopen", return_value=response):
                download.fetch_once("https://github.com/asset", 100)
            if expected:
                context.load_verify_locations.assert_called_once_with("/etc/ssl/cert.pem")
            else:
                context.load_verify_locations.assert_not_called()

    def test_only_not_yet_published_assets_are_retried(self):
        missing = HTTPError("https://github.com/asset", 404, "Not Found", {}, None)
        with patch.object(download, "fetch_once", side_effect=[missing, b"asset"]) as request, patch.object(download.time, "sleep") as sleep:
            self.assertEqual(download.fetch("https://github.com/asset", 100), b"asset")
            self.assertEqual(request.call_count, 2)
            sleep.assert_called_once_with(10)
        with patch.object(download, "fetch_once", side_effect=missing) as request, patch.object(download.time, "sleep") as sleep, self.assertRaisesRegex(ValueError, "publish its ZIP and checksum"):
            download.fetch("https://github.com/asset", 100)
        self.assertEqual(request.call_count, 12)
        self.assertEqual(sleep.call_count, 11)
        unavailable = HTTPError("https://github.com/asset", 500, "Server Error", {}, None)
        with patch.object(download, "fetch_once", side_effect=unavailable) as request, patch.object(download.time, "sleep") as sleep, self.assertRaises(HTTPError):
            download.fetch("https://github.com/asset", 100)
        self.assertEqual(request.call_count, 1)
        sleep.assert_not_called()

    def test_download_size_deadline_and_redirect_are_bounded(self):
        for url, limit, elapsed in (("https://github.com/asset", 3, 1), ("https://github.com/asset", 10, 91), ("https://example.org/asset", 10, 1)):
            response = io.BytesIO(b"123456")
            response.geturl = lambda: url
            with self.subTest(url=url, limit=limit, elapsed=elapsed), patch.object(download, "urlopen", return_value=response), patch.object(download.time, "monotonic", side_effect=[0, elapsed]), self.assertRaises(ValueError):
                download.fetch("https://github.com/sumanbasuli/bhranti/releases/download/v1.2/bhranti-1.2.zip", limit)

    def test_published_package_and_different_readme_prose_are_accepted(self):
        validate(package())
        files = {'bhranti/bhranti.php': PLUGIN, 'bhranti/readme.txt': README + 'Older published wording is legitimate.\n', 'bhranti/assets/admin.js': 'older published code'}
        validate(package(files))

    def test_checksum_must_name_the_exact_asset(self):
        digest = 'a' * 64
        self.assertEqual(download.release_checksum((digest + '  bhranti-1.2.zip\n').encode(), 'bhranti-1.2.zip'), digest)
        for data in (digest + '  bhranti-1.1.zip\n', digest + '  ../bhranti-1.2.zip\n', digest + '  bhranti-1.2.zip\nextra'):
            with self.subTest(data=data), self.assertRaisesRegex(ValueError, 'exact release ZIP'):
                download.release_checksum(data.encode(), 'bhranti-1.2.zip')

    def test_checksum_mismatch_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'SHA-256'):
            validate(package(), '0' * 64)

    def test_stale_existing_zip_version_is_rejected(self):
        files = {'bhranti/bhranti.php': PLUGIN.replace('1.2', '1.1'), 'bhranti/readme.txt': README.replace('1.2', '1.1'), 'bhranti/assets/admin.js': 'old release'}
        with self.assertRaisesRegex(ValueError, 'Version and Stable tag'):
            validate(package(files))

    def test_unexpected_missing_nested_and_escaping_paths_are_rejected(self):
        original = {'bhranti/bhranti.php': PLUGIN, 'bhranti/readme.txt': README, 'bhranti/assets/admin.js': 'code'}
        for name in ('bhranti/site/package.json', 'bhranti/../secret.php', '/absolute.php', 'bhranti/bhranti/bhranti.php'):
            with self.subTest(name=name), self.assertRaisesRegex(ValueError, 'runtime paths'):
                validate(package({**original, name: 'must not ship'}))
        del original['bhranti/assets/admin.js']
        with self.assertRaisesRegex(ValueError, 'runtime paths'):
            validate(package(original))

    def test_duplicate_and_linked_entries_are_rejected(self):
        with self.assertRaisesRegex(ValueError, 'duplicate runtime paths'):
            validate(package(duplicate=True))
        with self.assertRaisesRegex(ValueError, 'linked'):
            validate(package(linked='bhranti/assets/admin.js'))

    def test_wrong_runtime_constant_text_domain_and_requirements_are_rejected(self):
        for plugin, readme in ((PLUGIN.replace("'1.2'", "'9.9'"), README), (PLUGIN.replace('Text Domain: bhranti', 'Text Domain: other'), README), (PLUGIN.replace('Requires PHP: 8.0', 'Requires PHP: 8.2'), README), (PLUGIN, README.replace('Requires at least: 7.0', 'Requires at least: 6.9'))):
            with self.subTest(plugin=plugin, readme=readme), self.assertRaises(ValueError):
                validate(package({'bhranti/bhranti.php': plugin, 'bhranti/readme.txt': readme, 'bhranti/assets/admin.js': 'code'}))

    def test_bounded_uncompressed_archive_and_broken_zip_are_rejected(self):
        with patch.object(download, 'MAX_BYTES', 10), self.assertRaisesRegex(ValueError, 'oversized'):
            validate(package())
        with self.assertRaises(zipfile.BadZipFile):
            validate(b'not a ZIP')


if __name__ == '__main__':
    unittest.main(verbosity=2)
