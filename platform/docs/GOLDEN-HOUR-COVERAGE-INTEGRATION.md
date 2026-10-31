# Golden Hour Coverage Atlas — integration note

Coverage branch: `feat/golden-hour-coverage-atlas`

Coverage implementation: `feat(admin): add golden-hour coverage atlas`

This note records how the Atlas consumes the shared Golden Hour Teacher Pay window
authority. Finance owns window configuration, settings, versions, effective dates,
migrations and pay calculations. Coverage owns the Atlas presentation and the
coverage calculation.

## Shared window authority

`Delnavazan\Platform\Core\Application\Finance\GoldenHourPolicyService`

- `coverageWindows(): array` — the shared structural area/window defaults used by
  coverage and pay.
- `resolveAt(string $area, string $occurrenceStartsAtUtc): array` — the policy
  version effective at an instant, including weekdays, local start/end, IANA
  timezone, label and `enabled`.
- `GoldenHourRule::defaults()` remains built from
  `AcademyDemandWindowRule::regions()`.

Coverage deliberately ignores `enabled` and `bonus_basis_points`. A window whose
pay bonus is disabled remains an operational coverage window.

## Coverage window resolution

`AcademyCoverageReadService::windowsForWeek(DateTimeImmutable $weekStart)`:

1. Calls `GoldenHourPolicyService::coverageWindows()` for the shared area set and
   fallback metadata.
2. For each area and each local date of the selected week, calls
   `GoldenHourPolicyService::resolveAt($area, '<date> 12:00:00')`.
3. Emits one effective window row for that date when the resolved policy's
   weekdays include it.

This means:

- a version that takes effect during the selected week is used from that date;
- historical weeks resolve their own applicable versions;
- a weekday excluded by the policy contributes no slots and is shown as
  `No configured window`, not as a fabricated gap;
- a changed start/end changes the week's total half-hour slots;
- a disabled pay bonus does not remove the window.

The neutral `12:00:00` UTC probe resolves at date granularity. A policy effective
later on the same UTC date is used from the following coverage date. This is a
coverage-side date-level projection; the pay calculator still resolves the exact
policy at each Lesson occurrence instant.

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
