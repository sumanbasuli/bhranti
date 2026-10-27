#!/usr/bin/env python3
"""Small stdlib regressions for the local listing preview; no builds or live site."""

import http.client
from http.server import HTTPServer
import importlib.util
from pathlib import Path
import shutil
import tempfile
import threading
import unittest

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("listing_preview", ROOT / "scripts/preview-listing.py")
preview = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preview)


class PreviewTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        (self.root / "tools").mkdir()
        shutil.copytree(ROOT / "tools/listing-preview", self.root / "tools/listing-preview")
        (self.root / "wordpress-org/assets").mkdir(parents=True)
        (self.root / "bhranti.php").write_text("<?php\n/*\n * Author: Test Author\n */", encoding="utf-8")
        self.readme = """=== Example & Preview ===
Contributors: example
Stable tag: 1.2
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.0
Tags: ai, content

Short description.

== Description ==
First **bold** paragraph.

= Subheading =
* One item
* Another item with [a link](https://example.com/).

== Installation ==
1. Activate.
2. Configure.

== Frequently Asked Questions ==
= Can I use it? =
Yes.

== Screenshots ==
1. The first screenshot.

== Extra section ==
Preserve this text.

== Changelog ==
= 1.2 =
* A change.
"""
        (self.root / "readme.txt").write_text(self.readme, encoding="utf-8")
        (self.root / "wordpress-org/assets/screenshot-1.jpg").write_bytes(b"image fixture")

    def tearDown(self):
        self.temporary.cleanup()

    def test_content_and_hot_reload(self):
        rendered = preview.render(self.root).decode()
        for expected in ("Example &amp; Preview", "Test Author", "<strong>bold</strong>", "<ol><li>Activate.", "<summary>Can I use it?</summary>", "Preserve this text.", "screenshot-1.jpg", "<h3>1.2</h3>"):
            self.assertIn(expected, rendered)
        (self.root / "readme.txt").write_text(self.readme.replace("First **bold** paragraph.", "Changed without restarting."), encoding="utf-8")
        self.assertIn("Changed without restarting.", preview.render(self.root).decode())

    def test_inert_untrusted_markup(self):
        rendered = preview.markdown('<img src=x onerror=alert(1)>\n\n[bad](javascript:alert) [bad](data:text/html,payload) **<script>** `</code><script>` [safe](https://example.com/?x="quoted")')
        self.assertNotIn("<img", rendered)
        self.assertNotIn("<script", rendered)
        self.assertNotIn('href="javascript:', rendered)
        self.assertNotIn('href="data:', rendered)
        self.assertIn("&lt;script&gt;", rendered)
        self.assertIn("&quot;quoted&quot;", rendered)
        self.assertIn("[bad](javascript:alert)", rendered)
        self.assertIn('href="https://example.com/" rel="noreferrer">Privacy</a>)', preview.inline("([Privacy](https://example.com/))"))

    def test_source_boundary(self):
        (self.root / "secret.txt").write_text("not public", encoding="utf-8")
        (self.root / "linked.txt").symlink_to(self.root / "secret.txt")
        (self.root / "linked-directory").symlink_to(self.root / "wordpress-org/assets", target_is_directory=True)
        for name in ("linked.txt", "linked-directory/screenshot-1.jpg", "../secret.txt"):
            with self.assertRaises((OSError, ValueError)):
                preview.read_file(self.root, name)
        with self.assertRaises(ValueError):
            preview.read_file(self.root, "secret.txt", 2)
        (self.root / "pipe").touch()
        (self.root / "pipe").unlink()
        import os
        os.mkfifo(self.root / "pipe")
        with self.assertRaises(ValueError):
            preview.read_file(self.root, "pipe")

    def test_http_allowlist_and_host(self):
        server = HTTPServer(("127.0.0.1", 0), preview.PreviewHandler)
        server.root = self.root
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            def request(path, headers=None):
                connection = http.client.HTTPConnection("127.0.0.1", server.server_port, timeout=3)
                connection.request("GET", path, headers=headers or {})
                response = connection.getresponse()
                result = response.status, dict(response.getheaders()), response.read()
                connection.close()
                return result
            status, headers, body = request("/")
            self.assertEqual(status, 200)
            self.assertIn(b"Example &amp; Preview", body)
            self.assertEqual(headers["Cache-Control"], "no-store")
            self.assertIn("frame-ancestors 'none'", headers["Content-Security-Policy"])
            for path in ("/readme.txt", "/bhranti.php", "/.env", "/assets/../bhranti.php", "/assets/%2e%2e/bhranti.php", "/assets/icon.svg", "/tools/listing-preview/index.html"):
                self.assertEqual(request(path)[0], 404, path)
            self.assertEqual(request("/", {"Host": "evil.example"})[0], 403)
            self.assertEqual(request("/", {"Sec-Fetch-Site": "cross-site"})[0], 403)
            self.assertEqual(request("/assets/screenshot-1.jpg")[0], 200)
            (self.root / "wordpress-org/assets/screenshot-2.jpg").symlink_to(self.root / "bhranti.php")
            self.assertEqual(request("/assets/screenshot-2.jpg")[0], 422)
        finally:
            server.shutdown()
            server.server_close()
            thread.join()


if __name__ == "__main__":
    unittest.main()
