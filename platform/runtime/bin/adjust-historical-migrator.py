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

UNSCOPED_MUTABLE_CHECK = """
\t\t\tforeach ( array('updated_at','updated_by','active_slot','status','state','superseded_at','superseded_by_statement_id','superseded_by_correction_id','superseded_by_evaluation_id','issued_at','issued_by','resolved_at','resolved_by','resolution_note','effective_until','result_evaluation_id') as $mutableColumn ) {
\t\t\t\tif ( ! $wpdb->get_row( "SHOW COLUMNS FROM {$physical} LIKE '{$mutableColumn}'" ) ) continue;
\t\t\t\tif ( ! in_array( $mutableColumn, $mutable[$table] ?? array(), true ) ) throw new \\RuntimeException('Migration verification failed: an undeclared mutable column: '.$table.'.'.$mutableColumn);
\t\t\t}
"""
SCOPED_MUTABLE_CHECK = """
\t\t\tif ( ! in_array( $table, $appendOnly, true ) ) {
\t\t\t\tforeach ( array('updated_at','updated_by','active_slot','status','state','superseded_at','superseded_by_statement_id','superseded_by_correction_id','superseded_by_evaluation_id','issued_at','issued_by','resolved_at','resolved_by','resolution_note','effective_until','result_evaluation_id') as $mutableColumn ) {
\t\t\t\t\tif ( ! $wpdb->get_row( "SHOW COLUMNS FROM {$physical} LIKE '{$mutableColumn}'" ) ) continue;
\t\t\t\t\tif ( ! in_array( $mutableColumn, $mutable[$table] ?? array(), true ) ) throw new \\RuntimeException('Migration verification failed: an undeclared mutable column: '.$table.'.'.$mutableColumn);
\t\t\t\t}
\t\t\t}
"""
APPEND_ONLY_UPDATE_BAN = (
    "if ( in_array( $table, $appendOnly, true ) && "
    "( $wpdb->get_row( \"SHOW COLUMNS FROM {$physical} LIKE 'updated_at'\" ) || "
    "$wpdb->get_row( \"SHOW COLUMNS FROM {$physical} LIKE 'updated_by'\" ) ) ) "
    "throw new \\RuntimeException('Migration verification failed: Phase 2A.2-U "
    "evidence must be append-only: '.$table);"
)

UNSCOPED_FORBIDDEN_NAME_CHECK = (
    "foreach ( $forbiddenNames as $forbidden ) if ( str_contains( $name, $forbidden ) ) "
    "throw new \\RuntimeException('Migration verification failed: a forbidden Phase 2A.2-U "
    "column name: '.$table.'.'.$name);"
)
SCOPED_FORBIDDEN_NAME_CHECK = (
    "foreach ( $forbiddenNames as $forbidden ) if ( ! str_ends_with( $name, "
    "'derivation_digest' ) && str_contains( $name, $forbidden ) ) throw new "
    "\\RuntimeException('Migration verification failed: a forbidden Phase 2A.2-U "
    "column name: '.$table.'.'.$name);"
)

CONTRADICTORY_DIGEST_OPTIONAL = (
    "$digestOptional = array('finance_policies.evidence_reference_digest',"
    "'finance_teacher_rates.evidence_reference_digest',"
    "'finance_teacher_rate_events.evidence_reference_digest',"
    "'finance_snapshot_corrections.prior_snapshot_digest',"
    "'finance_snapshot_corrections.evidence_reference_digest',"
    "'finance_payability_evaluations.evidence_reference_digest',"
    "'finance_payability_overrides.prior_derivation_digest',"
    "'finance_payability_overrides.evidence_reference_digest',"
    "'finance_statement_events.evidence_reference_digest',"
    "'finance_reconciliation_findings.expected_digest',"
    "'finance_reconciliation_findings.observed_digest');"
)
CORRECTED_DIGEST_OPTIONAL = (
    "$digestOptional = array('finance_policies.evidence_reference_digest',"
    "'finance_teacher_rates.evidence_reference_digest',"
    "'finance_teacher_rate_events.evidence_reference_digest',"
    "'finance_snapshot_corrections.evidence_reference_digest',"
    "'finance_payability_evaluations.evidence_reference_digest',"
    "'finance_payability_overrides.evidence_reference_digest',"
    "'finance_statement_events.evidence_reference_digest',"
    "'finance_reconciliation_findings.expected_digest',"
    "'finance_reconciliation_findings.observed_digest');"
)


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
    append_only_bans = source.count(APPEND_ONLY_UPDATE_BAN)
    if append_only_bans != 1:
        fail(f"unexpected historical append-only update ban count {append_only_bans}")

    contradictory = source.count(CONTRADICTORY_DIGEST)
    corrected = source.count(CORRECTED_DIGEST)
    if contradictory == 1:
        source = source.replace(CONTRADICTORY_DIGEST, CORRECTED_DIGEST, 1)
    elif contradictory != 0 or corrected != 1:
        fail(
            "unexpected historical identity_digest verifier "
            f"(contradictory={contradictory}, corrected={corrected})"
        )

    # These snapshots predate the production verifier fix in 3f6214d. Their
    # name-based mutable-column scan incorrectly inspects append-only event rows,
    # where `effective_until` is immutable event payload. Apply the established
    # production guard only to the disposable verifier; the historical DDL and
    # the separate updated_at/updated_by append-only rejection remain unchanged.
    unscoped_mutable = source.count(UNSCOPED_MUTABLE_CHECK)
    scoped_mutable = source.count(SCOPED_MUTABLE_CHECK)
    if unscoped_mutable == 1 and scoped_mutable == 0:
        source = source.replace(
            UNSCOPED_MUTABLE_CHECK, SCOPED_MUTABLE_CHECK, 1
        )
    elif unscoped_mutable != 0 or scoped_mutable != 1:
        fail(
            "unexpected historical mutable-column verifier "
            f"(unscoped={unscoped_mutable}, scoped={scoped_mutable})"
        )

    # `derivation` contains the forbidden substring `vat`. These six declared
    # digest fields therefore fail the unqualified name scan even though the
    # frozen DDL and verifier spec both require them. Mirror the narrow production
    # exception from 77a6a1a: only names ending in `derivation_digest` bypass that
    # substring scan, while all other forbidden names remain rejected.
    unscoped_forbidden = source.count(UNSCOPED_FORBIDDEN_NAME_CHECK)
    scoped_forbidden = source.count(SCOPED_FORBIDDEN_NAME_CHECK)
    if unscoped_forbidden == 1 and scoped_forbidden == 0:
        source = source.replace(
            UNSCOPED_FORBIDDEN_NAME_CHECK, SCOPED_FORBIDDEN_NAME_CHECK, 1
        )
    elif unscoped_forbidden != 0 or scoped_forbidden != 1:
        fail(
            "unexpected historical forbidden-name verifier "
            f"(unscoped={unscoped_forbidden}, scoped={scoped_forbidden})"
        )

    # The historical verifier says two required char(64) columns may be NULL,
    # contradicting their NOT NULL CREATE TABLE declarations. Apply the exact
    # production correction from 54c351e to this disposable verifier only.
    contradictory_optional = source.count(CONTRADICTORY_DIGEST_OPTIONAL)
    corrected_optional = source.count(CORRECTED_DIGEST_OPTIONAL)
    if contradictory_optional == 1 and corrected_optional == 0:
        source = source.replace(
            CONTRADICTORY_DIGEST_OPTIONAL, CORRECTED_DIGEST_OPTIONAL, 1
        )
    elif contradictory_optional != 0 or corrected_optional != 1:
        fail(
            "unexpected historical digest-nullability verifier "
            f"(contradictory={contradictory_optional}, corrected={corrected_optional})"
        )

    if len(BIGINT_CHECK.findall(source)) != 0:
        fail("historical bigint verifier remained unadjusted")
    if len(PORTABLE_BIGINT_CHECK.findall(source)) != expected_bigints:
        fail("historical bigint verifier adjustment count changed")
    if source.count(CONTRADICTORY_DIGEST) != 0 or source.count(CORRECTED_DIGEST) != 1:
        fail("historical identity_digest verifier remained contradictory")
    if source.count(UNSCOPED_MUTABLE_CHECK) != 0:
        fail("historical mutable-column verifier remained unscoped")
    if source.count(SCOPED_MUTABLE_CHECK) != 1:
        fail("historical mutable-column verifier adjustment count changed")
    if source.count(APPEND_ONLY_UPDATE_BAN) != 1:
        fail("historical append-only update ban changed")
    if source.count(UNSCOPED_FORBIDDEN_NAME_CHECK) != 0:
        fail("historical forbidden-name verifier remained unscoped")
    if source.count(SCOPED_FORBIDDEN_NAME_CHECK) != 1:
        fail("historical forbidden-name verifier adjustment count changed")
    if source.count(CONTRADICTORY_DIGEST_OPTIONAL) != 0:
        fail("historical digest-nullability verifier remained contradictory")
    if source.count(CORRECTED_DIGEST_OPTIONAL) != 1:
        fail("historical digest-nullability verifier adjustment count changed")

    path.write_text(source)


def main(argv: list[str]) -> None:
    if len(argv) != 3:
        raise SystemExit("usage: adjust-historical-migrator.py <30|31> <Migrator.php>")
    adjust(argv[1], Path(argv[2]))


if __name__ == "__main__":
    main(sys.argv)
