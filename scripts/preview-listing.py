#!/usr/bin/env python3
"""Preview the current readme and listing images locally, without building anything."""

import argparse
from html import escape
from http.server import BaseHTTPRequestHandler, HTTPServer
import os
from pathlib import Path
import re
import stat
from string import Template
import sys
from urllib.parse import quote, urlsplit

ROOT = Path(__file__).resolve().parent.parent
UI = "tools/listing-preview/"
MAX_TEXT = 512 * 1024
MAX_IMAGE = 16 * 1024 * 1024
SECTION = re.compile(r"^== ([^=\n]+) ==\s*$", re.M)
SUBHEADING = re.compile(r"^= ([^=\n]+) =\s*$", re.M)
ASSET = re.compile(r"(?:banner-(?:772x250|1544x500)|icon-(?:128x128|256x256))\.png|screenshot-[1-9]\d{0,2}\.(?:png|jpe?g|gif)")
INLINE = re.compile(r"`([^`\n]+)`|\*\*([^*\n]+)\*\*|\*([^*\n]+)\*|\[([^]\n]+)\]\(([^\s<>)]+)\)|(https?://[^\s<>]+)")


def read_file(root, relative, limit=MAX_TEXT):
    """Open only regular bounded files, refusing symlinks in every relative component."""
    parts = Path(relative).parts
    if not parts or Path(relative).is_absolute() or any(p in (".", "..") for p in parts):
        raise ValueError("Invalid source path")
    directory = os.open(root, os.O_RDONLY | os.O_DIRECTORY)
    try:
        for part in parts[:-1]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=directory)
            os.close(directory)
            directory = child
        descriptor = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
        with os.fdopen(descriptor, "rb") as source:
            info = os.fstat(source.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_size > limit:
                raise ValueError("Source is not a regular file within the size limit")
            data = source.read(limit + 1)
            if len(data) > limit:
                raise ValueError("Source exceeds size limit")
            return data
    finally:
        os.close(directory)


def inline(text):
    """Small inert Markdown subset; raw HTML and unsafe link schemes remain text."""
    result, cursor = [], 0
    for token in INLINE.finditer(text):
        result.append(escape(text[cursor:token.start()]))
        code, bold, emphasis, label, href, bare = token.groups()
        if code is not None:
            result.append("<code>" + escape(code) + "</code>")
        elif bold is not None:
            result.append("<strong>" + escape(bold) + "</strong>")
        elif emphasis is not None:
            result.append("<em>" + escape(emphasis) + "</em>")
        else:
            href = href or bare
            suffix = ""
            if bare:
                href = href.rstrip(".,;:!?)")
                suffix = bare[len(href):]
            try:
                target = urlsplit(href)
                safe = target.scheme in ("https", "http") and bool(target.netloc) and not target.username
            except ValueError:
                safe = False
            if safe:
                result.append('<a href="' + escape(href, quote=True) + '" rel="noreferrer">' + escape(label or href) + "</a>" + escape(suffix))
            else:
                result.append(escape(token.group()))
        cursor = token.end()
    result.append(escape(text[cursor:]))
    return "".join(result)


def markdown(text):
    result, paragraph, items = [], [], []
    list_kind = None

    def flush():
        nonlocal list_kind
        if paragraph:
            result.append("<p>" + inline(" ".join(paragraph)) + "</p>")
            paragraph.clear()
        if items:
            result.append("<" + list_kind + ">" + "".join("<li>" + inline(item) + "</li>" for item in items) + "</" + list_kind + ">")
            items.clear()
        list_kind = None

    for line in text.splitlines():
        line = line.strip()
        heading = SUBHEADING.fullmatch(line)
        item = re.match(r"(?:([*+-])|\d+\.)\s+(.+)", line)
        if not line:
            flush()
        elif heading:
            flush()
            result.append("<h3>" + inline(heading[1]) + "</h3>")
        elif item:
            kind = "ul" if item[1] else "ol"
            if paragraph or (list_kind and list_kind != kind):
                flush()
            list_kind = kind
            items.append(item[2])
        elif items:
            items[-1] += " " + line
        else:
            paragraph.append(line)
    flush()
    return "\n".join(result)


def parse_readme(text):
    text = text.replace("\r\n", "\n")
    title = re.match(r"^=== (.+) ===\s*\n", text)
    if not title:
        raise ValueError("readme.txt needs a === Plugin name === heading")
    chunks = SECTION.split(text)
    headers = dict((key.lower(), value.strip()) for key, value in re.findall(r"^([^:\n]+):[ \t]*(.+)$", chunks[0], re.M))
    sections = dict((chunks[i].strip().lower(), (chunks[i].strip(), chunks[i + 1].strip())) for i in range(1, len(chunks), 2))
    if "description" not in sections:
        raise ValueError("readme.txt needs a Description section")
    return title[1], headers, sections


def screenshots(root, text):
    figures = []
    for number, caption in re.findall(r"^([1-9]\d{0,2})\.[ \t]+(.+)$", text, re.M):
        filename = None
        for extension in ("png", "jpg", "jpeg", "gif"):
            candidate = "screenshot-" + number + "." + extension
            try:
                read_file(root, "wordpress-org/assets/" + candidate, MAX_IMAGE)
                filename = candidate
                break
            except FileNotFoundError:
                continue
        if filename:
            label = escape(caption, quote=True)
            figures.append('<figure><a class="screenshot" href="/assets/' + filename + '" aria-label="' + label + ' Enlarge screenshot ' + number + '"><img src="/assets/' + filename + '" alt="' + label + '" loading="lazy"></a><figcaption>' + inline(caption) + '</figcaption></figure>')
        else:
            figures.append('<p class="notice">Missing screenshot ' + number + ': ' + escape(caption) + '</p>')
    return '<section id="screenshots"><h2>Screenshots</h2><div class="gallery">' + "".join(figures) + '</div></section>' if figures else ""


def faq(text):
    chunks = SUBHEADING.split(text)
    if len(chunks) == 1:
        return markdown(text)
    return '<section id="faq"><h2>FAQ</h2>' + markdown(chunks[0]) + "".join('<details><summary>' + inline(chunks[i]) + '</summary><div>' + markdown(chunks[i + 1]) + '</div></details>' for i in range(1, len(chunks), 2)) + '</section>'


def render(root):
    title, metadata, sections = parse_readme(read_file(root, "readme.txt").decode("utf-8-sig"))
    section = lambda name: sections.get(name, ("", ""))[1]
    special = {"description", "installation", "frequently asked questions", "screenshots", "changelog", "upgrade notice"}
    description = '<h2>Description</h2>' + markdown(section("description"))
    description += "".join('<h3>' + escape(name) + '</h3>' + markdown(body) for key, (name, body) in sections.items() if key not in special)
    description += screenshots(root, section("screenshots")) + faq(section("frequently asked questions"))
    contributors = [name.strip() for name in metadata.get("contributors", "").split(",") if name.strip()]
    author = re.search(r"^[ \t*]*Author:[ \t]*(.+)$", read_file(root, "bhranti.php").decode("utf-8"), re.M)
    author_name = author[1].strip() if author else ", ".join(contributors)
    byline = '<a href="https://profiles.wordpress.org/' + quote(contributors[0], safe="") + '/">' + escape(author_name) + '</a>' if contributors else escape(author_name)
    contributor_links = "".join('<li><a href="https://profiles.wordpress.org/' + quote(name, safe="") + '/">' + escape(name) + '</a></li>' for name in contributors)
    rows = []
    for label, key, suffix in (("Version", "stable tag", ""), ("WordPress version", "requires at least", " or higher"), ("Tested up to", "tested up to", ""), ("PHP version", "requires php", " or higher")):
        rows.append('<div><dt>' + label + '</dt><dd>' + escape(metadata.get(key, "Not specified")) + (suffix if key in metadata else "") + '</dd></div>')
    tags = "".join('<a href="https://wordpress.org/plugins/tags/' + quote(tag.strip(), safe="") + '/">' + escape(tag.strip()) + '</a>' for tag in metadata.get("tags", "").split(",") if tag.strip())
    rows.append('<div><dt>Tags</dt><dd class="tags">' + tags + '</dd></div>')
    return Template(read_file(root, UI + "index.html").decode("utf-8")).substitute(
        title=escape(title), byline=byline, description=description,
        installation=markdown(section("installation")) or '<p>No installation instructions in readme.txt.</p>',
        contributors=contributor_links, changelog=markdown(section("changelog")) or '<p>No changelog in readme.txt.</p>',
        metadata="".join(rows),
    ).encode("utf-8")


class PreviewHandler(BaseHTTPRequestHandler):
    server_version = "BhrantiListingPreview/1.0"

    def setup(self):
        super().setup()
        self.connection.settimeout(5)

    def respond(self, code, body, content_type="text/plain; charset=utf-8"):
        self.send_response(code)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("Content-Security-Policy", "default-src 'none'; img-src 'self'; style-src 'self'; script-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action https://wordpress.org")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        expected = "127.0.0.1:" + str(self.server.server_port)
        if self.headers.get("Host") != expected or self.headers.get("Sec-Fetch-Site") == "cross-site":
            self.respond(403, b"Use the loopback URL printed by the preview command.")
            return
        if len(self.path) > 2048:
            self.respond(414, b"Request too long.")
            return
        try:
            path = urlsplit(self.path).path
        except ValueError:
            self.respond(400, b"Invalid request target.")
            return
        try:
            if path == "/":
                self.respond(200, render(self.server.root), "text/html; charset=utf-8")
            elif path in ("/preview.css", "/preview.js"):
                kind = "text/css" if path.endswith(".css") else "text/javascript"
                self.respond(200, read_file(self.server.root, UI + path[1:]), kind + "; charset=utf-8")
            elif path.startswith("/assets/") and ASSET.fullmatch(path[len("/assets/"):]):
                mime = {".png": "image/png", ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".gif": "image/gif"}[Path(path).suffix]
                self.respond(200, read_file(self.server.root, "wordpress-org" + path, MAX_IMAGE), mime)
            else:
                self.respond(404, b"Not found.")
        except (BrokenPipeError, ConnectionResetError):
            # A reload may cancel an in-flight image; there is no response to send.
            return
        except (OSError, ValueError, UnicodeError, KeyError):
            self.respond(422, b"Preview source is missing, invalid, too large, or a symlink. Check readme.txt, bhranti.php and listing assets, then reload.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", type=int, default=4331, help="loopback port (default: 4331)")
    args = parser.parse_args()
    if not 1 <= args.port <= 65535:
        parser.error("port must be between 1 and 65535")
    with HTTPServer(("127.0.0.1", args.port), PreviewHandler) as server:
        server.root = ROOT
        print("Listing preview: http://127.0.0.1:{}\nReads current files on every reload. Ctrl-C to stop.".format(server.server_port), flush=True)
        try:
            server.serve_forever()
        except KeyboardInterrupt:
            pass


if __name__ == "__main__":
    try:
        main()
    except OSError as error:
        print("Could not start listing preview: " + str(error), file=sys.stderr)
        sys.exit(1)
