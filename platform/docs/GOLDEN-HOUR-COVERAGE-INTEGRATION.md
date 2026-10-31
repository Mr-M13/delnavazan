# Golden Hour Coverage Atlas — integration note

Coverage branch: `feat/golden-hour-coverage-atlas`

Coverage implementation: `feat(admin): add golden-hour coverage atlas`

This note records how the Atlas consumes the shared Golden Hour Teacher Pay window
authority. Finance owns window configuration, settings, versions, effective dates,
migrations and pay calculations. Coverage owns the Atlas presentation and the
coverage calculation.

## Shared window authority

`Delnavazan\Platform\Core\Application\Finance\GoldenHourPolicyService`

- `coverageWindows(): array` — the structural service-area windows used by
  coverage: `AU`, `EU`, `GB`, `NA_EAST`, `NA_WEST`, with weekdays, local start/end,
  IANA timezone and label.
- `resolveAt(string $area, string $occurrenceStartsAtUtc): array` — the global
  Iran pay policy resolution (`GLOBAL_IRAN` only). It is **not** a service-area
  window resolver; calling it with `AU`, `EU`, etc. throws
  `golden_hour_area_invalid`.
- `GoldenHourRule::defaults()` remains built from
  `AcademyDemandWindowRule::regions()`.

Coverage deliberately ignores `enabled` and `bonus_basis_points`. A window whose
pay bonus is disabled remains an operational coverage window.

## Coverage window resolution

`AcademyCoverageReadService::windowsForWeek(DateTimeImmutable $weekStart)`:

1. Calls `GoldenHourPolicyService::coverageWindows()` once.
2. Expands each structural area window across the local dates of the selected
   week when its configured weekdays include that date.
3. Emits one window row per area/date with the configured start/end, timezone and
   label.

This means:

- coverage remains available when the global pay bonus is disabled;
- a weekday excluded by the structural window contributes no slots and is shown
  as `No configured window`, not as a fabricated gap;
- a changed start/end changes the week's total half-hour slots;
- the current finance contract has no per-area effective-dated coverage versions.
  If finance adds them later, the replacement belongs at this `windowsForWeek`
  boundary; the presentation and availability calculation remain unchanged.

## Analyzer contract

```php
AcademyCoverageAnalyzer::analyze(
    array $instruments,
    array $teachers,
    ?DateTimeImmutable $week = null,
    array $unknownInstrumentIds = [],
    string $sort = 'gap',
    ?array $windows = null
): array
```

`$windows === null` retains the static `AcademyDemandWindowRule` interface (seven
days, 16:00–21:00), keeping the existing tests and non-finance callers intact.
The finance-resolved rows use this shape:

```php
[
    'region'      => 'AU',
    'label'       => 'Australia',
    'timezone'    => 'Australia/Brisbane',
    'date'        => '2026-11-02',
    'weekday'     => 1,
    'start_minute'=> 960,
    'end_minute'  => 1260,
    'version'     => 4,
]
```

`enabled` is intentionally not part of the coverage decision.

The analyzer still:

- counts distinct teacher IDs per slot;
- treats blocked intervals as winning over overlapping preferred/requestable
  intervals;
- uses `TeacherAvailabilityService::effective()` output supplied by the read
  service;
- produces `available`, `no_eligible_teachers` and `unknown` metric states;
- groups contiguous gaps per effective day/window.

## Presentation

`AcademyCoverageView` reads dynamic cell totals, times, per-day windows and
timezone labels. The detail grid uses the union of effective slot times for the
area; days outside the policy are shown explicitly as `No configured window`.

## Verification

Relevant checks:

- `php platform/tests/golden-hour-coverage-unit.php`
- `php platform/tests/golden-hour-coverage-view-unit.php`
- `php platform/tests/golden-hour-pay-contract.php`
- `php platform/tests/golden-hour-pay-unit.php`
- `php platform/tests/booking-availability-timezone-dst-contract.php`
- `php platform/tests/teacher-availability-dst-contract.php`
- `php platform/tests/teacher-availability-lifecycle-contract.php`
- `php platform/tests/admin-navigation-regression-contract.php`

Coverage tests cover midweek window changes, historical totals, weekday
denominators, disabled-bonus windows, dynamic detail headers, DST conversion,
distinct teachers, blocked intervals, empty data and unknown data.
