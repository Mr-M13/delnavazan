#!/usr/bin/env python3
"""Repair known verifier-only defects in disposable Schema 30/31 fixtures."""

from __future__ import annotations

import re
import sys
from pathlib import Path


EXPECTED_BIGINT_CHECKS = {"30": 9, "31": 10}
EXPECTED_EXTRA_CHECKS = {"30": 6, "31": 7}

BIGINT_CHECK = re.compile(
    r"strtolower\(\s*\(string\)\s*(\$[A-Za-z_][A-Za-z0-9_]*)->Type\s*\)"
    r"\s*!==\s*'bigint unsigned'"
)
PORTABLE_BIGINT_CHECK = re.compile(
    r"preg_match\('/\^bigint\(\?:\\\\\(\\\\d\+\\\\\)\)\? unsigned\$/',"
    r" strtolower\(trim\(\(string\) (\$[A-Za-z_][A-Za-z0-9_]*)->Type\)\)\) !== 1"
)
EXTRA_CHECK = re.compile(
    r"strtoupper\(\s*\(string\)\s*(\$[A-Za-z_][A-Za-z0-9_]*)->Extra\s*\)"
    r"\s*!==\s*'AUTO_INCREMENT'"
)

CONTRADICTORY_DIGEST = (
    "array('integration_connections','identity_digest'),"
    "array('integration_oauth_authorizations','state_digest')"
)
CORRECTED_DIGEST = "array('integration_oauth_authorizations','state_digest')"


def fail(message: str) -> None:
    raise SystemExit(f"error: {message}; refusing fixture adjustment")


def portable_bigint_check(match: re.Match[str]) -> str:
    variable = match.group(1)
    return (
        "preg_match('/^bigint(?:\\\\(\\\\d+\\\\))? unsigned$/', "
        f"strtolower(trim((string) {variable}->Type))) !== 1"
    )


def adjust(schema: str, path: Path) -> None:
    if schema not in EXPECTED_BIGINT_CHECKS:
        fail(f"unknown historical schema {schema}")
    if not path.is_file():
        fail(f"historical Migrator not found at {path}")

    source = path.read_text()
    expected_bigints = EXPECTED_BIGINT_CHECKS[schema]
    expected_extras = EXPECTED_EXTRA_CHECKS[schema]

    # MariaDB returns `bigint(20) unsigned` from SHOW COLUMNS for these historical
    # `bigint unsigned` definitions. Accept only the semantically identical optional
    # display width, matching the current production verifier without changing it.
    raw_bigints = len(BIGINT_CHECK.findall(source))
    portable_bigints = len(PORTABLE_BIGINT_CHECK.findall(source))
    if raw_bigints == expected_bigints and portable_bigints == 0:
        source = BIGINT_CHECK.sub(portable_bigint_check, source)
    elif raw_bigints != 0 or portable_bigints != expected_bigints:
        fail(
            "unexpected historical bigint verifier "
            f"(raw={raw_bigints}, portable={portable_bigints})"
        )

    # SHOW COLUMNS reports `auto_increment` on MariaDB. The frozen verifier already
    # normalises Extra with strtoupper(), so preserve its strict, case-insensitive
    # primary-key/auto-increment assertion and guard against an unexpected snapshot.
    extra_checks = len(EXTRA_CHECK.findall(source))
    if extra_checks != expected_extras:
        fail(f"unexpected historical Extra verifier count {extra_checks}")

    contradictory = source.count(CONTRADICTORY_DIGEST)
    corrected = source.count(CORRECTED_DIGEST)
    if contradictory == 1:
        source = source.replace(CONTRADICTORY_DIGEST, CORRECTED_DIGEST, 1)
    elif contradictory != 0 or corrected != 1:
        fail(
            "unexpected historical identity_digest verifier "
            f"(contradictory={contradictory}, corrected={corrected})"
        )

    if len(BIGINT_CHECK.findall(source)) != 0:
        fail("historical bigint verifier remained unadjusted")
    if len(PORTABLE_BIGINT_CHECK.findall(source)) != expected_bigints:
        fail("historical bigint verifier adjustment count changed")
    if source.count(CONTRADICTORY_DIGEST) != 0 or source.count(CORRECTED_DIGEST) != 1:
        fail("historical identity_digest verifier remained contradictory")

    path.write_text(source)


def main(argv: list[str]) -> None:
    if len(argv) != 3:
        raise SystemExit("usage: adjust-historical-migrator.py <30|31> <Migrator.php>")
    adjust(argv[1], Path(argv[2]))


if __name__ == "__main__":
    main(sys.argv)
