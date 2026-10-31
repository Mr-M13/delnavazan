# Golden-hour teacher pay

## Configuration

Finance → **Settings** → **Teacher compensation** → **Golden hour pay bonus**.

The editor requires the existing `dzn_manage_teacher_rates` capability. Coverage-dashboard access
(`dzn_view_booking_requests`) does not grant pay-editing authority.

Each serviced area stores a versioned policy in `finance_settings`:

- enabled/disabled (default disabled);
- area IANA timezone;
- weekdays;
- local start/end (overnight windows are rejected because the existing coverage model does not
  represent them);
- bonus percentage from 0 to 100 with at most two decimals, stored as exact basis points;
- effective-from and optional effective-until UTC instants.

The shared `GoldenHourPolicyService` window definitions are used by both the coverage dashboard and
teacher pay. The area label always displays its exact configured IANA timezone.

## Explicit Enrolment service area

Every Enrolment has one nullable `service_area_key`. Allowed values are `AU`, `EU`, `GB`,
`NA_EAST` and `NA_WEST`. The Enrolment detail screen provides a labelled **Service area for golden
hours** selector showing each area with its configured timezone.

The value is never inferred from country, Teacher timezone, browser timezone, or the largest bonus.
A blank value records base pay with zero bonus, `calculation_outcome=service_area_required`, and
`review_reason=service_area_required`. Existing Enrolments are left blank by migration rather than
guessed.

## Calculation and commitment

The area window is converted from its IANA timezone using the Lesson's canonical UTC occurrence.
Boundaries are `[start, end)`. Overlapping windows are merged and the highest applicable percentage
applies once to each minute. The base amount is the rate already resolved and snapshotted by the
existing Finance rate authority, including teacher/course overrides.

The fixed per-session rule is applied proportionally:

`bonus = snapshotted session base × eligible payable seconds / total payable seconds × percentage`

`LessonFinanceSnapshotService::capture()` is the compensation commitment point. It writes the
canonical snapshot and then one row in `finance_golden_hour_details`, inside the same transaction.
That detail row carries the snapshot reference, policy version, service area, timezone, UTC interval,
base amount/currency, eligible duration, applied window breakdown, total bonus, total committed pay,
calculation version, outcome, review reason and creation timestamp. The table has a unique snapshot
version reference and exposes no update or delete operation.

A technical calculation or persistence failure throws, rolling the whole snapshot command back and
leaving the Lesson Finance pending. A missing area does not throw.

Statement drafting, issuance freshness, reconciliation and teacher earnings reads all use the same
`GoldenHourPayService::committedTotal()` projection for the effective snapshot/correction version.

An authorised snapshot correction appends a new canonical correction version and a corresponding
detail row with `snapshot_correction_id`; it never edits or deletes the previous detail. A
pre-feature snapshot without a detail is not backfilled.

## Completion workflow

The existing completion and attendance-settlement workflows call
`LessonFinanceOrchestrationService::captureAfterCompletion()`. It reuses the Finance snapshot and
payability authorities. Finance failure is recorded as pending and never reverses attendance or
completion. Repeated completion, capture or statement drafting converges on the one committed
snapshot.

## Validation

- `php platform/tests/golden-hour-pay-unit.php`
- `php platform/tests/golden-hour-pay-contract.php`
- Existing source contracts: `static.php`, `teacher-portal-presentation-contract.php`,
  `teacher-classes-contract.php`, `portal-truth-contract.php`, `finance-administration-contract.php`.
- Disposable WordPress/MariaDB runtime proofs:
  `golden-hour-pay-runtime.php`, `golden-hour-pay-completion-runtime.php` and `golden-hour-pay-migration-runtime.php`.

## Current limits

- The canonical model represents one Student per Lesson. Group sessions require an explicit
  canonical session-area fact; the model does not currently have one.
- Overnight windows are rejected; the existing coverage model cannot represent them.
- A blank Enrolment service area deliberately keeps base pay and shows the review reason until an
  administrator sets it.
