#!/usr/bin/env python3
"""Build the filtered Delnavazan staging import package from a production WXR export.

The production export is a full-site dump. Importing it unfiltered would also bring
across presentation artefacts (global styles, font faces, navigation block, the old
theme's Additional CSS and the old footer menu) that belong to the retired site and
must not override the rebuilt design.

This script keeps the content that must be preserved — posts, pages, attachments,
categories, tags and their per-object metadata — and writes a smaller WXR that the
WordPress importer can consume safely.

It deliberately changes exactly one object: the production page whose slug collides
with the `/enrol/` application route is demoted to `draft` so imported content can
never take over an application route. See CONTENT-MIGRATION-RUNBOOK.md.

Usage:
    python3 build-import-package.py --source <production.xml> --output <staging-import.xml>
    python3 build-import-package.py --source <production.xml> --dry-run
"""

from __future__ import annotations

import argparse
import hashlib
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

WP_NS = "http://wordpress.org/export/1.2/"

#: Object types that carry content and must be preserved.
KEEP_TYPES = {"post", "page", "attachment"}

#: Object types owned by the retired presentation and deliberately excluded.
DROP_TYPES = {
    "nav_menu_item",
    "wp_global_styles",
    "wp_font_face",
    "wp_font_family",
    "wp_navigation",
    "custom_css",
    "wp_template",
    "wp_template_part",
}

#: Slugs that collide with an application route, and the status they are forced to.
ROUTE_COLLISION_STATUS = {"enrol": "draft"}

ITEM_RE = re.compile(r"<item>.*?</item>", re.DOTALL)


def field(block: str, tag: str) -> str:
    """Return the text of a `<wp:tag>` or `<tag>` element, unwrapping CDATA."""
    match = re.search(
        rf"<{tag}>(?:<!\[CDATA\[(.*?)\]\]>|(.*?))</{tag}>", block, re.DOTALL
    )
    if not match:
        return ""
    return (match.group(1) or match.group(2) or "").strip()


def set_field(block: str, tag: str, value: str) -> str:
    """Replace the text of a `<tag>` element, preserving the CDATA wrapper shape."""
    match = re.search(rf"<{tag}>(.*?)</{tag}>", block, re.DOTALL)
    if not match:
        return block
    inner = match.group(1)
    wrapped = f"<![CDATA[{value}]]>" if inner.startswith("<![CDATA[") else value
    return block[: match.start()] + f"<{tag}>{wrapped}</{tag}>" + block[match.end() :]


def build(source: Path, output: Path | None, dry_run: bool) -> int:
    text = source.read_text(encoding="utf-8")
    items = ITEM_RE.findall(text)
    if not items:
        print("No <item> elements found — is this a WXR export?", file=sys.stderr)
        return 2

    kept: list[str] = []
    dropped: dict[str, int] = {}
    changed: list[str] = []
    kept_counts: dict[str, int] = {}

    for block in items:
        post_type = field(block, "wp:post_type")
        if post_type in KEEP_TYPES:
            slug = field(block, "wp:post_name")
            if slug in ROUTE_COLLISION_STATUS:
                status = field(block, "wp:status")
                forced = ROUTE_COLLISION_STATUS[slug]
                if status != forced:
                    block = set_field(block, "wp:status", forced)
                    changed.append(f"{post_type} '{slug}' status {status} -> {forced}")
            kept.append(block)
            kept_counts[post_type] = kept_counts.get(post_type, 0) + 1
        else:
            dropped[post_type or "(unknown)"] = dropped.get(post_type or "(unknown)", 0) + 1

    head, tail = text.split(items[0], 1)
    _, remainder = tail.split(items[-1], 1)
    filtered = head + "".join(kept) + remainder

    print(f"source: {source} ({len(text):,} bytes)")
    print(f"items in source: {len(items)}")
    for post_type, count in sorted(kept_counts.items()):
        print(f"  keep {post_type:<16} {count}")
    for post_type, count in sorted(dropped.items()):
        print(f"  drop {post_type:<16} {count}")
    for note in changed:
        print(f"  change {note}")

    # Re-parse the result: a package that does not parse must never be shipped.
    parsed = ET.fromstring(filtered)
    parsed_items = parsed.findall(f".//{{{WP_NS}}}post")  # lightweight structural touch
    if parsed_items is None:
        print("unexpected parse result", file=sys.stderr)
        return 3

    if dry_run or output is None:
        print("dry run — no file written")
        return 0

    output.write_text(filtered, encoding="utf-8")
    digest = hashlib.sha256(filtered.encode("utf-8")).hexdigest()
    print(f"wrote {output} ({len(filtered):,} bytes)")
    print(f"sha256 {digest}")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True, type=Path)
    parser.add_argument("--output", type=Path)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    return build(args.source, args.output, args.dry_run)


if __name__ == "__main__":
    raise SystemExit(main())
