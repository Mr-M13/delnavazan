# Phase 1F — Controlled Runtime & Integration Validation

This runbook validates the Phase 1 Core plugin on the Delnavazan beta site. It
does not authorize automatic deployment, business-authority changes, Amelia
changes, imports, Phase 2, or any provider integration.

## Package, prerequisites, and rollback

- Package: delnavazan-platform-0.1.0-phase-1f.zip
- Plugin version: 0.1.0
- Minimum runtime: WordPress 6.4, PHP 8.1, and the MySQL/MariaDB supported by
  WordPress.
- Package contents are only delnavazan-platform.php, uninstall.php, and src/.
  It excludes tests, documentation, Git metadata, local environment files,
  credentials, and unrelated project files.

Before upload:

1. Obtain explicit beta-site upload/activation approval.
2. Confirm a current recoverable database/files backup, record its location and
   owner, and confirm the rollback operator.
3. Record the installed Platform state/version and screenshot Plugins and
   relevant admin pages.
4. Keep Amelia installed and active. Do not deactivate, alter, import, query,
   or write Amelia data.
5. Use a dedicated administrator and a separate low-privilege account. Never
   use real teacher or student records.

On fatal error, unexpected warning, schema problem, or site regression: stop;
deactivate only Platform if the admin remains available; restore the approved
backup if needed; preserve evidence; do not continue. Do not uninstall the
beta plugin. Uninstall preserves business data by design, but uninstall testing
is not authorized here.

## Installation and baseline

1. Verify the handed-off ZIP SHA-256.
2. Upload it through Plugins → Add New → Upload Plugin. Use the normal
   replacement flow only after confirming the backup.
3. Activate Delnavazan Platform and record any warning/fatal output.
4. Open Delnavazan → Core Status. PASS requires all three independent facts:
   all ten tables are ready, schema version is 1, and
   001_initial_core_schema is recorded. A green state without all three is a
   FAIL.
5. Record table names/counts before fixture creation.

## Controlled fixture

Create through the Phase 1E screens and record every numeric ID, UID, and
reference code:

| Entity | Controlled values |
| --- | --- |
| Teacher | استاد آزمایشی دلنوازان / Delnavazan Test Teacher; Iran; Tehran; Asia/Tehran; Persian calendar |
| Student | Delnavazan Test Student; Australia; Brisbane; Australia/Brisbane; Gregorian calendar |
| Instrument | تار / Tar; slug tar |
| Course | Tar Standard; standard; 30-minute duration; 15-minute buffer |
| Enrolment | fixture Student + Teacher + Course; active; Tuesday; 18:00:00; Australia/Brisbane |
| Term | fixture Enrolment; sequence 1; allocation 12; replacement allowance 2 |

Create an introductory Lesson with no Enrolment/Term, then a standard Lesson
with matching Enrolment/Term. Schedule the standard Lesson on a chosen
non-DST Brisbane date at 18:00:00, then reschedule it to a different local
time. Its Lesson ID, UID, and reference must not change; version 1 must be
superseded and version 2 the only current version. The 30-minute Course must
produce a 30-minute instructional interval: the 15-minute buffer must not
extend ends_at_utc. Create a replacement Lesson for that same
Student/Teacher/Course/Enrolment/Term pointing to the standard original.

## Controlled exception harness

There is intentionally no arbitrary Exception-create screen. From a controlled
shell with WP-CLI on the beta host, run this trusted engineering harness; never
expose it through a public URL:

    wp eval '
    $service = new \Delnavazan\Platform\Core\Application\ExceptionService();
    echo $service->recordTrusted([
      "exception_type" => "unknown_system_error",
      "severity" => "warning",
      "entity_type" => "system",
      "summary" => "PHASE 1F CONTROLLED TEST - DO NOT USE",
      "safe_detail" => "Controlled validation exception only.",
      "fingerprint_key" => "phase-1f-controlled-exception-v1",
      "retry_available" => true,
    ]) . PHP_EOL;
    '

Run it twice at least one second apart: the active duplicate must reuse the
same row and update last_seen_at. Use Exceptions for lifecycle/retry. After
resolution or dismissal, rerun the same command to prove recurrence creates a
new historical row. Use a read-only WP-CLI database query only when necessary
to prove resolution columns are SQL NULL.

## Archive and corruption boundaries

Use only synthetic records. Dependency-conflict attempts must leave children
untouched. An unused inactive synthetic parent can prove valid archive/restore;
an unscheduled draft introductory Lesson can prove draft restoration.

A retained scheduled Lesson cannot be archived by ordinary Phase 1 UI because
scheduled Lessons are intentionally non-archivable. Validate scheduled restore
and missing/foreign/superseded/multiple-current corruption only with an
isolated integration-test database fixture. Do not manually corrupt the beta
database.

## Evidence matrix

Use one row per execution. Corrective commit is blank unless a confirmed defect
is fixed. Mark unavailable work as BLOCKED, never PASS.

| ID | Test | Result (PASS/FAIL/BLOCKED) | Evidence | Observed error | Corrective commit |
| --- | --- | --- | --- | --- | --- |
| R01 | Backup/rollback location and owner confirmed before upload |  |  |  |  |
| R02 | ZIP SHA-256 and version 0.1.0 verified |  |  |  |  |
| R03 | Plugin loads and activates without fatal/PHP warning |  |  |  |  |
| R04 | Administrator capabilities are registered |  |  |  |  |
| R05 | All ten expected Core tables exist |  |  |  |  |
| R06 | Schema version is exactly 1 |  |  |  |  |
| R07 | 001_initial_core_schema is recorded complete |  |  |  |  |
| R08 | Core Status is healthy only when R05–R07 pass |  |  |  |  |
| R09 | Migration rerun is safe and does not duplicate schema/data |  |  |  |  |
| R10 | Platform runs with Amelia active but needs no Amelia dependency |  |  |  |  |
| R11 | All Phase 1E admin screens are reachable for administrator |  |  |  |  |
| R12 | Teacher and Student fixture IDs, UIDs, references are stable |  |  |  |  |
| R13 | Instrument/Course fixture has 30-minute duration and 15-minute buffer |  |  |  |  |
| R14 | Enrolment and Term accept the valid fixture relationship |  |  |  |  |
| R15 | Introductory Lesson creates without Enrolment/Term |  |  |  |  |
| R16 | Standard Lesson and matching replacement Lesson create |  |  |  |  |
| R17 | Initial schedule stores UTC, Brisbane wall clock, and timezone |  |  |  |  |
| R18 | Reschedule preserves identity and makes version 2 solely current |  |  |  |  |
| R19 | Buffer does not extend instructional end; Brisbane/Tehran presentation checks |  |  |  |  |
| R20 | Standard Lesson without Enrolment or without Term is rejected |  |  |  |  |
| R21 | Wrong-Enrolment Term or mismatched Lesson identity is rejected |  |  |  |  |
| R22 | Replacement without/unrelated original is rejected |  |  |  |  |
| R23 | Invalid timezone, nonexistent time, ambiguous time, no-op reschedule reject |  |  |  |  |
| R24 | Teacher/Student/Instrument/Course dependency archive conflicts reject |  |  |  |  |
| R25 | Enrolment/Term conflicts reject with no cascade |  |  |  |  |
| R26 | Scheduled/completed/cancelled Lesson archive restrictions reject |  |  |  |  |
| R27 | Valid unused-parent archive/restore preserves identity/history |  |  |  |  |
| R28 | Unscheduled draft Lesson archive/restore returns draft |  |  |  |  |
| R29 | Valid retained schedule restores scheduled (isolated fixture) |  |  |  |  |
| R30 | Corrupt retained schedule rejects restore (isolated fixture) |  |  |  |  |
| R31 | Controlled Exception open → acknowledged retains SQL NULL resolution fields |  |  |  |  |
| R32 | Controlled Exception acknowledged → resolved writes resolution audit |  |  |  |  |
| R33 | Controlled Exception open → dismissed writes resolution audit |  |  |  |  |
| R34 | Invalid Exception transitions reject |  |  |  |  |
| R35 | Active duplicate fingerprint reuses row; closed recurrence creates new row |  |  |  |  |
| R36 | Retry increments only where retry_available is 1 |  |  |  |  |
| R37 | Duplicate email does not silently merge identities |  |  |  |  |
| R38 | Mutable-contact identity stability via controlled service harness if no edit UI |  |  |  |  |
| R39 | Low-privilege user is denied screens; invalid nonce/direct POST fail |  |  |  |  |
| R40 | Rendered test values are escaped; no writable Core REST/AJAX exists |  |  |  |  |
| R41 | IDs/references are not authorization secrets; diagnostics show no credentials |  |  |  |  |
| R42 | Deactivate/reactivate preserves fixtures, schedules, Exceptions, and healthy status |  |  |  |  |

## Closeout

This document is not runtime evidence. Attach screenshots, table/query evidence,
fixture IDs, and failures to PR review. Do not merge, deploy further changes, or
begin Phase 2 without independent review.
