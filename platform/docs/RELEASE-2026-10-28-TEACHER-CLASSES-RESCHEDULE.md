# Teacher classes, Jalali presentation and rescheduling — 2026-10-28

Branch: `feat/teacher-classes-reschedule-20261028`

## Architecture finding

Canonical rescheduling already existed as a staff application service:
`CanonicalLessonScheduleService::revise()` owns schedule-version supersession, Teacher occupancy,
Course duration/buffer policy, idempotency, concurrency and the append-only audit trail. It did
**not** provide a teacher-initiated proposal workflow or student acceptance, and its capability is
deliberately staff-only. This change reuses that service for the final commit rather than adding a
second scheduling system.

## Completed work

- Teacher **Today** is bounded to the teacher's local calendar day (including completed, ongoing
  and upcoming classes). **Upcoming** is bounded to tomorrow through the next 30 local calendar
  days. Both are read server-side with a UTC window derived from the teacher's IANA timezone.
- Presentation dates use ICU (`IntlDateFormatter` / `IntlCalendar`). Persian selection always
  renders Persian month/weekday names and Persian digits; Gregorian selection respects the
  configured locale. Calendar and timezone are independent. Canonical UTC timestamps are unchanged.
- Student and teacher class cards use full human names and instrument names. Internal reference
  codes and term identifiers remain available to backend services but are no longer class-card
  labels. The teacher account header shows the registered full name.
- Class cards were compacted into date/time, instrument, participant, status, primary Join action,
  small calendar action and an accessible chevron disclosure. The repeated join paragraph was
  removed; disabled joins keep a contextual tooltip and screen-reader explanation.
- New teacher reschedule proposals (`lesson_reschedule_proposals`) and append-only events. A
  proposal never changes canonical schedule authority. Student acceptance revalidates the current
  schedule version, Teacher occupancy and Student conflicts, then commits through
  `CanonicalLessonScheduleService::revise()`.
- Recoverable notification and calendar-hand-off jobs with unique idempotency keys and bounded
  retries. The calendar job retracts old provider mappings and records new projections through the
  existing provider integration authority; the existing projection worker dispatches them.
- Schema 53 adds the proposal, event, notification and calendar-job tables.

## Policy decisions

- Minimum reschedule notice: **60 minutes** before the current start.
- Maximum proposal horizon: **30 days** ahead.
- Only canonical, `authorised`, not-started classes can be proposed. Booking-only intro classes are
  refused because they have no canonical schedule version.
- The canonical Course buffer is re-applied at acceptance; the original instructional duration is
  preserved from the current applicable schedule version.

## Outstanding dependencies

- A WordPress user holding only `dzn_manage_canonical_lesson_schedules` must be configured in
  `dzn_reschedule_system_user_id`. This is the narrow commit actor; students never receive the
  scheduling capability.
- A WordPress user holding `dzn_manage_provider_integrations` must be configured in
  `dzn_reschedule_calendar_system_user_id`. Until configured, accepted reschedules keep the
  canonical schedule and remain queued/retryable for calendar hand-off.
- The existing Google Calendar integration records new schedule-version projections; provider-side
  deletion/update of the old event remains delegated to the existing projection/retention policy.

## Verification

Pure PHP tests added and run locally:

```text
php platform/tests/teacher-class-window-unit.php
php platform/tests/lesson-reschedule-service-unit.php
php platform/tests/lesson-reschedule-notification-unit.php
php platform/tests/lesson-reschedule-calendar-unit.php
php platform/tests/portal-identity-display-unit.php
php platform/tests/teacher-portal-presentation-contract.php
php platform/tests/teacher-classes-contract.php
php platform/tests/static.php
```

Existing booking/schedule/payment regression contracts were also run. No deployment was performed.
