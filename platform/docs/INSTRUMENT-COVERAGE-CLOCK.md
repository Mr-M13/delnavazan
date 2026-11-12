# Instrument Coverage Clock

The 24-hour instrument coverage clock is the primary presentation of teaching
coverage. It replaces the country/region atlas as the default view while the
regional atlas remains available as an explicitly labelled secondary detail.

## Authority boundaries

The clock is a **read-only projection**. It introduces no second scheduling,
availability, booking or remuneration system.

| Concern | Authority reused by the clock |
|---|---|
| Dated availability, exceptions, holidays | `TeacherAvailabilityService::effective()` |
| Structural weekly availability | `TeacherAvailabilityService::recurring()` |
| Canonical and legacy class occupancy | `CanonicalTeacherOccupancyReadService::inRange()` |
| Booking offers and confirmations | `TeacherBookingOccupancyQuery::conflictsRange()` |
| Active pre-payment holds | `CanonicalContinuationCapacityAuthority::activeHoldsInRange()` |
| Lesson duration and buffer | `BookingAvailabilityPolicy`, `AvailabilityLocalTime`, active Course shape |
| Teacher eligibility and operational status | `teacher_course_eligibilities`, onboarding/readiness, accepting state |
| Golden Hour designation | `GoldenHourPolicyService` + `GoldenHourClockProjection` |
| Bookable catalogue shape | `PublicBookingOptionsReadService` |

The booking-time services remain the final decision maker. The clock only says
whether an eligible Teacher can accept **the complete required interval**.

## Calculation methodology

For every instrument and every 15-minute clock segment:

1. Resolve the segment's wall time in the clock's display timezone. A nonexistent
   (spring-forward) or ambiguous (fall-back) wall time is marked `unknown`; the
   clock never guesses a DST occurrence.
2. Build the full candidate interval: `lesson minutes + buffer minutes` starting
   at the segment.
3. Count eligible Teachers whose resolved availability covers the complete
   candidate interval and whose canonical occupancy, booking offer/confirmation,
   or active hold does not overlap it.
4. Classify the segment:
   - `covered`: two or more Teachers retain capacity;
   - `limited`: one Teacher retains capacity;
   - `uncovered`: no Teacher retains capacity (`fully_booked` when declared
     availability exists, `no_availability` otherwise);
   - `unknown`: data could not be read or the wall time is not schedulable.

A free 15-minute hole is never advertised as a bookable class. The analyzer
validates the complete interval before counting a Teacher. The standard
introductory shape is a 30-minute class plus a 15-minute buffer (45-minute
occupied interval), read from the active introductory Course rather than
hardcoded in the clock. An instrument configured with a different Course shape
shows that authoritative shape.

### Weekly mode

Weekly mode uses recurring availability rules only. It deliberately ignores
existing bookings, offers, holds and dated exceptions so one date's operations do
not become a permanent structural loss. Seven weekday clocks are available.

### Actual-date mode

Actual-date mode applies dated exceptions, holidays, confirmed classes, legacy
occupancy, pending offers, confirmations and active holds. Capacity is recalculated
on every request; there is no cross-request cache to invalidate.

## Golden Hours

Golden Hour designation comes from the existing finance-owned global policy
(`GLOBAL_IRAN`) and is projected into the clock's display timezone. The outer ring
is always visible, independently of capacity:

- Golden Hour with capacity: gold inner segment and gold outer indicator;
- Golden Hour without capacity: grey inner segment and gold outer indicator.

The designation intentionally ignores whether the pay bonus is currently enabled,
matching the existing coverage semantics. No country or region label is shown on
the clock.

## Timezone and calendar

- All internal comparisons use canonical UTC timestamps.
- The display timezone is the academy timezone for administrators and the
  Teacher's availability-profile timezone for the Teacher portal.
- DST gaps and folds are not guessed; affected segments are `unknown`.
- Availability crossing midnight maps through the same UTC intervals as booking.
- Gregorian dates are canonical. Persian/Jalali labels use the existing
  `PersianCalendar` arithmetic; the administrator may switch the date label.

## Integration

- **Teacher portal:** the clock sits above the existing availability editor. It
  is limited to instruments where the Teacher is eligible for the introductory
  course. Clicking a segment only prefills the existing form; saving still uses the
  existing `TeacherAvailabilityService` mutations. The form preview updates
  immediately without saving.
- **Admin:** the Academy Overview coverage panel renders a compact instrument
  overview and one expanded clock. Default mode is actual-date coverage.
- **Legacy atlas:** the regional demand-window atlas is retained behind an
  explicit link for finance window review; existing configuration, historical
  records and remuneration logic are untouched.

## Performance

- One instrument query and one eligibility query per request.
- One availability read and one occupancy/hold read per Teacher for the whole
  range, not per 15-minute segment.
- Request-local caches only; no persistent coverage snapshot.
- Golden policy resolution is cached per display date for the request.

## Quiet Hours

Quiet Hours are a soft advisory only. They never remove a coverage-clock segment,
change capacity colours, alter Teacher eligibility or block a booking. The clock
does not display a Quiet Hours layer; Golden Hour and capacity colours remain
authoritative and separate. See [QUIET-HOURS-ADVISORY.md](QUIET-HOURS-ADVISORY.md).

## Known limitations

- A Teacher who is eligible only for a standard (non-introductory) course is not
  counted in introductory capacity. This matches the public booking authority.
- DST fold hours are presented as `unknown` rather than choosing one of two
  possible instants.
- The clock is a coverage aid, not a guarantee that a booking will succeed; the
  booking-time services remain authoritative.
