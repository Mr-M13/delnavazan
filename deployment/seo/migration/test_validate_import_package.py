"""Contract tests for the read-only SEO migration package preflight."""

from __future__ import annotations

import csv
import importlib.util
import tempfile
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path

MODULE_PATH = Path(__file__).with_name("validate-import-package.py")
SPEC = importlib.util.spec_from_file_location("validate_import_package", MODULE_PATH)
assert SPEC and SPEC.loader
VALIDATOR = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(VALIDATOR)
validate = VALIDATOR.validate


def make_wxr(path: Path, page_status: str = "draft", title: str = "Preserved SEO title") -> None:
    root = ET.Element("rss", {"version": "2.0"})
    channel = ET.SubElement(root, "channel")
    ns = "http://wordpress.org/export/1.2/"
    for post_id, kind, slug, status, meta in (
        ("45", "attachment", "cover", "inherit", []),
        ("8", "page", "enrol", page_status, [
            ("rank_math_title", title),
            ("rank_math_description", "Preserved description"),
            ("rank_math_focus_keyword", "lesson"),
            ("_thumbnail_id", "45"),
        ]),
    ):
        item = ET.SubElement(channel, "item")
        for name, value in (("post_id", post_id), ("post_type", kind), ("post_name", slug), ("status", status)):
            ET.SubElement(item, f"{{{ns}}}{name}").text = value
        for key, value in meta:
            postmeta = ET.SubElement(item, f"{{{ns}}}postmeta")
            ET.SubElement(postmeta, f"{{{ns}}}meta_key").text = key
            ET.SubElement(postmeta, f"{{{ns}}}meta_value").text = value
    ET.ElementTree(root).write(path, encoding="utf-8", xml_declaration=True)


class PackageValidationTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.package = self.root / "candidate.xml"
        self.baseline = self.root / "baseline.csv"
        with self.baseline.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=[
                "post_type", "status", "slug", "production_url", "rank_math_title",
                "rank_math_description", "rank_math_focus_keyword", "has_featured_image",
            ])
            writer.writeheader()
            writer.writerow({
                "post_type": "page", "status": "publish", "slug": "enrol",
                "production_url": "https://example.test/enrol/", "rank_math_title": "Preserved SEO title",
                "rank_math_description": "Preserved description", "rank_math_focus_keyword": "lesson",
                "has_featured_image": "yes",
            })

    def tearDown(self) -> None:
        self.temp.cleanup()

    def test_preserved_baseline_is_ready_and_route_collision_held(self) -> None:
        make_wxr(self.package)
        report = validate(self.package, self.baseline)
        self.assertTrue(report["ready"], report["errors"])
        item = report["promotion_inventory"][0]
        self.assertEqual(item["promotion"], "hold")
        self.assertEqual(item["package_status"], "draft")

    def test_live_route_or_changed_rank_math_fails_closed(self) -> None:
        make_wxr(self.package, page_status="publish", title="Changed title")
        report = validate(self.package, self.baseline)
        self.assertFalse(report["ready"])
        self.assertTrue(any("status changed" in error for error in report["errors"]))
        self.assertTrue(any("Rank Math rank_math_title mismatch" in error for error in report["errors"]))


if __name__ == "__main__":
    unittest.main()
