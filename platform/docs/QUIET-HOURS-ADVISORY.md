# Quiet Hours Advisory

Quiet Hours are a **soft, configurable advisory** for the public introductory
booking flow. They never remove a slot, change Teacher eligibility, affect
occupancy or holds, or block submission.

## Configuration

Admin Dashboard → Settings → Policies → **Quiet Hours advisory**:

| Field | Meaning |
|---|---|
| Enable Quiet Hours advisory | Master switch for the advisory computation |
| Start / End | Wall-clock times; a start later than the end crosses midnight |
| Timezone interpretation | Each Teacher's local availability timezone (default) or the academy timezone |
| Show the advisory to students | Second switch for the student-facing caption |

Defaults are **01:00–06:00 teacher-local**, enabled. Historical `quiet_start`
and `quiet_end` values from the previous hard-restriction policy are reused.
Obsolete `student_quiet_*` keys are ignored and are not exposed. The policy
version increments through the existing `BookingAvailabilityPolicy` option; no
migration or new table is added.

## Availability and submission

`BookingAvailabilityAssessmentService` still applies the authoritative Teacher
eligibility, complete lesson-plus-buffer availability, canonical occupancy,
booking offers/confirmations and active holds. It no longer calls any Quiet Hours
blocking API. The public day grid therefore returns every otherwise bookable
start, including starts inside Quiet Hours. Submission accepts the same slots.

## Advisory computation

The preview response for each requested time contains a `quiet_hours` object:

- `enabled`, `policy_enabled`, `student_advisory_enabled`;
- `scope`: `none`, `some` or `all` across the actual candidate Teacher timezones;
- `candidate_count` and `affected_candidate_count`;
- `quiet_start`, `quiet_end`, `timezone_basis`;
- `candidates`: one entry per distinct candidate timezone with:
  - `teacher_local_start` and `teacher_local_date`;
  - `applies`;
  - the Quiet Hours occurrence converted to UTC and to the student's selected
    timezone, with start/end times and local dates;
  - `student_crosses_midnight`;
  - `student_range_available` (false when a DST gap/fold makes a boundary
    ambiguous; the advisory still shows the teacher-local start but does not
    invent a student range).

No Teacher identity or raw availability is exposed. Candidate timezones are the
same distinct matching timezones already used by the existing teacher-time note.
If all candidates are affected the UI says the time is inside the Quiet Hours of
all potential Teachers; if only some are affected it says so and never claims
otherwise.

## Student caption

The caption sits beneath the selected time in the priority list and in the final
review. It shows:

- the actual Teacher-local class start time;
- the dynamically converted Quiet Hours range in the student's selected timezone;
- a clear statement that the student can continue with the time.

The student's calendar preference (Gregorian or Persian/Jalali) formats any dates
needed when the converted range crosses midnight. The existing booking calendar
remains Gregorian; the advisory respects the preference for its own date labels.

## Coverage clock

The 24-hour instrument coverage clock deliberately does not render Quiet Hours.
Quiet Hours never grey out a segment, and they are unrelated to Golden Hour
designation or remuneration.
