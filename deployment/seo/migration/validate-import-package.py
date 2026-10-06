#!/usr/bin/env python3
"""Fail-closed validation and promotion inventory for a filtered Delnavazan WXR.

This is a read-only check. It never imports or publishes content. The baseline CSV
is the URL and per-object SEO preservation contract; global Rank Math settings,
target-site collisions and live rendering still require separate operator checks.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import sys
import xml.etree.ElementTree as ET
from collections import Counter
from pathlib import Path

BASELINE = Path(__file__).resolve().parents[1] / "production-seo-baseline.csv"
EXPECTED_TYPES = {"post", "page", "attachment"}
EXPECTED_EXCLUSIONS = {
    "nav_menu_item", "wp_global_styles", "wp_font_face", "wp_font_family",
    "wp_navigation", "custom_css", "wp_template", "wp_template_part",
}
ROUTE_HOLDS = {"enrol": "The /enrol/ application route owns this URL."}
TRANSACTIONAL_HOLDS = {
    "booking-cancelled", "booking-received", "cancellation-help", "payment-success"
}


def local_name(tag: str) -> str:
    return tag.rsplit("}", 1)[-1]


def child_text(element: ET.Element, name: str) -> str:
    for child in element:
        if local_name(child.tag) == name:
            return (child.text or "").strip()
    return ""


def read_baseline(path: Path) -> dict[tuple[str, str], dict[str, str]]:
    with path.open(encoding="utf-8", newline="") as handle:
        rows = list(csv.DictReader(handle))
    records = {(row["post_type"], row["slug"]): row for row in rows}
    if len(records) != len(rows):
        raise ValueError("baseline contains duplicate post_type/slug entries")
    return records


def validate(package: Path, baseline_path: Path) -> dict:
    baseline = read_baseline(baseline_path)
    root = ET.parse(package).getroot()
    channel = next((el for el in root.iter() if local_name(el.tag) == "channel"), None)
    if channel is None:
        raise ValueError("WXR has no channel")
    items = [el for el in channel if local_name(el.tag) == "item"]
    if not items:
        raise ValueError("WXR has no channel items")

    errors: list[str] = []
    content: dict[tuple[str, str], dict] = {}
    counts: Counter[str] = Counter()
    statuses: Counter[str] = Counter()
    attachment_ids: set[str] = set()
    excluded: Counter[str] = Counter()
    for item in items:
        post_type = child_text(item, "post_type")
        slug = child_text(item, "post_name")
        status = child_text(item, "status")
        counts[post_type] += 1
        statuses[f"{post_type}:{status}"] += 1
        post_id = child_text(item, "post_id")
        if post_type == "attachment":
            attachment_ids.add(post_id)
        if post_type in EXPECTED_EXCLUSIONS:
            excluded[post_type] += 1
        if post_type in {"post", "page"}:
            key = (post_type, slug)
            if key in content:
                errors.append(f"duplicate content item: {post_type}/{slug}")
            content[key] = {"status": status, "id": post_id, "meta": {}, "thumbnail_id": ""}
            for meta in item:
                if local_name(meta.tag) != "postmeta":
                    continue
                key_name = child_text(meta, "meta_key")
                if key_name.startswith("rank_math_"):
                    content[key]["meta"][key_name] = child_text(meta, "meta_value")
                if key_name == "_thumbnail_id":
                    content[key]["thumbnail_id"] = child_text(meta, "meta_value")

    unexpected = sorted(set(counts) - EXPECTED_TYPES)
    if unexpected:
        errors.append("unexpected WXR object types: " + ", ".join(unexpected))
    if excluded:
        errors.append("retired presentation objects must be excluded: " + json.dumps(excluded, ensure_ascii=False))

    expected_keys = set(baseline)
    actual_keys = set(content)
    for post_type, slug in sorted(expected_keys - actual_keys):
        errors.append(f"baseline content missing: {post_type}/{slug}")
    for post_type, slug in sorted(actual_keys - expected_keys):
        errors.append(f"content absent from baseline: {post_type}/{slug}")

    inventory = []
    for key in sorted(expected_keys & actual_keys):
        post_type, slug = key
        row, item = baseline[key], content[key]
        expected_status = "draft" if post_type == "page" and slug == "enrol" else row["status"]
        if item["status"] != expected_status:
            errors.append(f"status changed for {post_type}/{slug}: expected {expected_status}, got {item['status']}")
        for column, meta_key in (
            ("rank_math_title", "rank_math_title"),
            ("rank_math_description", "rank_math_description"),
            ("rank_math_focus_keyword", "rank_math_focus_keyword"),
        ):
            expected = row[column]
            actual = item["meta"].get(meta_key, "")
            if expected != actual:
                errors.append(f"Rank Math {column} mismatch for {post_type}/{slug}")
        hold = ROUTE_HOLDS.get(slug) if post_type == "page" else None
        if not hold and slug in TRANSACTIONAL_HOLDS:
            hold = "Requires explicit noindex and transactional-page review before public promotion."
        if post_type == "page" and slug == "home":
            hold = "Do not assign as static front page; retain the recovered Theme homepage."
        if row["status"] != "publish":
            hold = hold or f"Source status is {row['status']}; explicit editorial approval required."
        inventory.append({
            "post_type": post_type,
            "slug": slug,
            "source_status": row["status"],
            "package_status": item["status"],
            "production_url": row["production_url"],
            "featured_image_expected": row["has_featured_image"] == "yes",
            "promotion": "hold" if hold else "eligible_after_global_seo_and_target_checks",
            "hold_reason": hold,
        })

    expected_featured = sum(row["has_featured_image"] == "yes" for row in baseline.values())
    for key, row in baseline.items():
        if row["has_featured_image"] == "yes" and key in content:
            thumbnail_id = content[key]["thumbnail_id"]
            if not thumbnail_id:
                errors.append(f"featured-image reference missing for {key[0]}/{key[1]}")
            elif thumbnail_id not in attachment_ids:
                errors.append(f"featured-image attachment {thumbnail_id} missing from package for {key[0]}/{key[1]}")
    post_count = sum(1 for (kind, _slug) in baseline if kind == "post")
    if counts["post"] != post_count:
        errors.append(f"post count mismatch: expected {post_count}, got {counts['post']}")
    if sum(1 for row in inventory if row["featured_image_expected"]) != expected_featured:
        errors.append("internal baseline featured-image count mismatch")

    return {
        "package": package.name,
        "sha256": hashlib.sha256(package.read_bytes()).hexdigest(),
        "ready": not errors,
        "errors": errors,
        "counts": dict(sorted(counts.items())),
        "statuses": dict(sorted(statuses.items())),
        "attachment_objects": counts["attachment"],
        "baseline_featured_image_posts": expected_featured,
        "promotion_inventory": inventory,
        "external_gates": [
            "Confirm the candidate target inventory and preserve any current canonical WordPress page or route; this validator cannot inspect the target.",
            "Install and configure the approved SEO authority, import production Rank Math global settings, and verify canonical, robots, sitemap and schema behaviour.",
            "Keep staging non-indexable; only approve public promotion after exact-URL, media, internal-link and rendered metadata checks.",
            "Create redirects only for approved URL changes; preserve all baseline slugs byte-for-byte.",
        ],
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--package", required=True, type=Path, help="filtered WXR produced by build-import-package.py")
    parser.add_argument("--baseline", type=Path, default=BASELINE)
    parser.add_argument("--json", dest="json_path", type=Path, help="also write report JSON here")
    args = parser.parse_args()
    try:
        report = validate(args.package, args.baseline)
    except (OSError, ET.ParseError, ValueError) as exc:
        print(f"NOT READY: {exc}", file=sys.stderr)
        return 2
    rendered = json.dumps(report, ensure_ascii=False, indent=2) + "\n"
    print(rendered, end="")
    if args.json_path:
        args.json_path.write_text(rendered, encoding="utf-8")
    return 0 if report["ready"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
