# Golden-hour teacher pay

## Configuration

Finance → **Settings** → **Teacher compensation** → **Golden hour pay bonus**.

The editor requires the existing `dzn_manage_teacher_rates` capability. Coverage-dashboard access
(`dzn_view_booking_requests`) does not grant pay-editing authority.

The Iran policy stores a versioned record in `finance_settings`:

- enabled/disabled (default disabled);
- the fixed `Asia/Tehran` timezone;
- weekdays;
- one or more local periods, including overnight periods;
- bonus percentage from 0 to 100 with at most two decimals, stored as exact basis points;
- effective-from and optional effective-until UTC instants.

The admin preview follows the global teacher-rate setting and is not editable from the golden-hour
form. Actual bonuses use each teacher's snapshotted effective rate, so teacher-specific and
course-specific rates receive the percentage on their own rate rather than on the global default.

Golden-hour **pay** now has one global policy: **Iran global golden hours**, fixed to `Asia/Tehran`.
The admin can configure multiple daily periods, for example `06:00–10:00` and `22:00–02:00`.
Overnight periods are supported. The coverage dashboard continues to use its structural planning
windows independently of pay.

The teacher schedule shows every applied period converted into that teacher's own profile timezone.
Policy history supports editing a recorded version and removing previous non-active versions; these
changes affect future resolution only and never rewrite committed lesson details or statements.

## Legacy service area

The historical `enrolments.service_area_key` column may remain, but it no longer determines golden-hour
pay. Non-Iran bonus regions and the admin service-area selector are removed. Existing committed detail
rows retain their recorded legacy area provenance.

## Calculation and commitment

Each Iran period is converted from `Asia/Tehran` using the Lesson's canonical UTC occurrence.
Boundaries are `[start, end)`; a period whose end is earlier than its start crosses midnight.
Overlapping periods are merged and the highest applicable percentage applies once to each minute. The base amount is the rate already resolved and snapshotted by the
existing Finance rate authority, including teacher/course overrides.

The fixed per-session rule is applied proportionally:

`bonus = snapshotted session base × eligible payable seconds / total payable seconds × percentage`

`LessonFinanceSnapshotService::capture()` is the compensation commitment point. It writes the
canonical snapshot and then one row in `finance_golden_hour_details`, inside the same transaction.
That detail row carries the snapshot reference, Iran policy version, timezone, UTC interval,
base amount/currency, eligible duration, applied period breakdown, canonical period definitions,
total bonus, total committed pay, calculation version and outcome. The table has a unique snapshot
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
- The global Iran policy is the only pay window; non-Iran bonus regions and the Enrolment service-area
  selector are removed.
