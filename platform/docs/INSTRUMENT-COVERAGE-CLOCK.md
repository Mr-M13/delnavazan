# Seven-day Instrument Coverage Clock

The coverage clock is one instrument, one 24-hour canvas and seven concentric
weekday rings. It is a **read-only projection** over the existing scheduling,
availability, booking and remuneration authorities.

## Structure

- Saturday → Friday, outside → inside.
- The single Golden Hours ring is outside the seven weekday rings.
- 00:00 at the top, 06:00 right, 12:00 bottom, 18:00 left.
- 48 visual 30-minute segments per ring.
- The current Saturday–Friday week is the default. Choosing a date shows the
  Saturday–Friday week containing that date.
- The current day gets a subtle ring outline and a compact “Today” marker in the
  ring key; it never obscures capacity.
- The ring key identifies every weekday, its date and its coverage percentage.
- The date control is always a **Gregorian** browser date input; the chosen week is
  then labelled in the selected Gregorian or Persian calendar.

## Metric period

`InstrumentCoverageMetrics` is the single period authority for the administrator
view:

- **Current week:** headline coverage, Golden gaps and uncovered durations cover
  the same actionable remainder. Elapsed slots are excluded consistently.
- **Future or past week:** the whole selected week is used, and the period label
  says so explicitly.

Day percentages, the weekly percentage, Golden gaps and uncovered durations all
come from that one source. An ended day shows “Ended” instead of a stale
percentage. A day inside a future/past week shows its full-day percentage.

## Availability precision

The server retains **15-minute booking precision** in
`InstrumentCoverageClockAnalyzer::build()`. A separate
`InstrumentCoverageClockAnalyzer::visualize()` aggregates pairs into 48 visual
segments:

- both halves bookable → covered/limited;
- one half bookable → `partial`;
- neither bookable → uncovered;
- unknown half → unknown.

Each visual segment keeps both half states, teacher counts and Golden flags, so
partial availability is visible even though the ring is divided into 30 minutes.
The complete class-plus-buffer interval is still validated against the
authoritative availability, occupancy, offers/confirmations and holds.

## Gap reasons

Each 15-minute segment carries an authoritative reason from the analyzer. The
ring compact payload keeps that reason; the detail panel never infers a cause from
colour or teacher count.

| Reason | Meaning |
|---|---|
| `no_availability` | No Teacher declared availability for the complete interval |
| `fully_booked` | Declared Teachers are occupied by confirmed/offered classes |
| `fully_held` | Declared Teachers are blocked by active pre-payment holds |
| `one_teacher` | Exactly one eligible, free Teacher remains |
| `partial_15_minute` | One 15-minute half is bookable and the other is not |
| `unknown_data` | Availability facts are incomplete for an eligible Teacher |
| `nonexistent_local_time` | A DST gap/fold cannot identify one UTC interval |

A fully booked interval is shown as **Fully booked**, not as “no eligible
Teacher”. Booked and held Teacher counts travel with the selected segment.

## Priority gaps

The administrator view derives **Priority gaps this week** from the same clock
projection (`InstrumentCoverageGapList`) rather than a second calculation. Rows
are ordered by:

1. uncovered Golden demand;
2. other uncovered time;
3. single-teacher dependency.

Each row shows instrument, weekday/date, local time range, relevant region,
duration and cause. Selecting a row highlights every visual segment in the gap and
opens the same detail panel as the ring. A cross-instrument row navigates with
`coverage_focus`, which reopens that instrument, day and segment without changing
any scheduling fact. The list links to the existing teacher-availability admin
page; it never assigns a Teacher, edits availability or creates a booking.

## Capacity colours

The weekday rings use a muted Saturday→Friday progression:

| Day | Colour |
|---|---|
| Saturday | green |
| Sunday | teal-green |
| Monday | teal |
| Tuesday | blue-teal |
| Wednesday | blue |
| Thursday | blue-violet |
| Friday | violet |

Capacity is communicated by the ring colour; one-teacher segments use a hatch,
partial segments show one coloured half and one grey half, unavailable segments
are grey, unknown segments are hatched, and selected segments get an outline.
Colour is never the only cue.

## Golden Hours ring

The outer ring aggregates the existing regional structural demand windows from
`GoldenHourPolicyService::coverageWindows()` across the seven displayed dates.
It does not create a second region authority and does not replace the global pay
policy.

Selecting a Golden ring segment shows:

- selected time;
- contributing service regions;
- each region’s local window;
- the weekday/date context;
- the current global bonus percentage, clearly labelled as conditional on the
  global bonus window.

The outer ring is an **aggregate**; exact weekday eligibility is shown by a thin
gold inner edge on the relevant weekday-ring segment and in the day-specific
detail. The regional demand windows are not automatically remuneration windows.

On a weekday ring the detail panel states whether that day is a Golden Hour and
lists the contributing regions. Bonus eligibility is shown only when the existing
global pay policy applies, with conditional wording; the aggregate ring never
promises pay.

### Eligible Teachers

The headline **Eligible teachers** count is instrument-wide. It is not the number
free at the selected time. The selected interval’s remaining count is shown
separately in the detail panel, alongside the instrument-wide count.

## Authority boundaries

| Concern | Authority reused by the clock |
|---|---|
| Dated availability, exceptions, holidays | `TeacherAvailabilityService::effective()` |
| Canonical and legacy class occupancy | `CanonicalTeacherOccupancyReadService::inRange()` |
| Booking offers and confirmations | `TeacherBookingOccupancyQuery::conflictsRange()` |
| Active pre-payment holds | `CanonicalContinuationCapacityAuthority::activeHoldsInRange()` |
| Lesson duration and buffer | active introductory Course shape |
| Teacher eligibility and status | eligibility, onboarding/readiness and accepting state |
| Regional demand windows | `GoldenHourPolicyService::coverageWindows()` |
| Global pay/bonus | `GoldenHourPolicyService` (`GLOBAL_IRAN`) |
| Bookable catalogue shape | `PublicBookingOptionsReadService` |
| Week arithmetic | `InstrumentCoverageWeek` |

## Timezone and calendar

- Internal comparisons use canonical UTC timestamps.
- Admin display timezone is the academy timezone; Teacher display timezone is the
  Teacher availability-profile timezone.
- DST gaps/folds are never guessed; affected segments are `unknown`.
- Persian/Jalali labels use `PersianCalendar`; Gregorian labels respect the
  Teacher portal calendar preference. The Teacher ring key uses Persian month
  names when Gregorian is selected.

## Integration

- **Admin:** the Academy Overview panel renders the priority-gap list, the compact
  seven-ring clock, an instrument selector, a labelled Gregorian week date,
  calendar toggle and week navigation. The list, ring selection and detail panel
  stay in sync; on narrow screens the list rows and day buttons are the primary
  large tap targets.
- **Teacher:** the same shared view sits above the existing availability editor.
  Clicking a segment only prefills the existing form; it never saves. The
  proposed availability is drawn on the matching ring. The form remains the
  canonical write path.
- **Legacy atlas:** the regional demand-window atlas has been retired from the
  admin UI. Its read-only classes remain for historical/finance inspection but
  are no longer rendered.

## Teacher terminology

The Teacher Golden-gap metric is exactly:

**ساعت‌های طلایی بدون پوشش**

The redundant separate Golden Hours section, eligible-period list and capacity
grid were removed; Golden visibility and conditional bonus information remain in
the unified clock detail panel.

## Preview evidence

Representative administrator previews live in `platform/docs/evidence/`:

- `coverage-clock-priority-gaps-desktop.png`
- `coverage-clock-priority-gaps-mobile.png` (390px narrow phone, selected fully booked gap)
- `coverage-clock-priority-gaps-unknown.png` (390px, selected unknown interval)
- the matching `coverage-clock-priority-gaps-preview*.html` files

The synthetic week includes an uncovered Golden interval, a fully booked interval,
a held interval, a single-teacher interval, a 15-minute partial and an unknown
interval.

## Performance

- One instrument and eligibility query per request.
- One availability, occupancy and hold read per Teacher for the whole week.
- Request-local caches only; no persistent coverage snapshot.
- Regional Golden occurrence and pay-window projections are cached per date.

## Known limitations

- The outer Golden ring is a week/region aggregate, not a promise of remuneration.
- Unknown intervals stay visible on the ring but are not ranked as confirmed
  priority gaps.
- DST fold boundaries are shown as `unknown` rather than guessed.
- The clock is a coverage aid; the booking-time services remain authoritative.
