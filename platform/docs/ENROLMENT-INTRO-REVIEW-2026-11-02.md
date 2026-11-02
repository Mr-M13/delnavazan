# Enrolment Intro / Review changes

Date: 2026-11-02

Branch: `feat/enrolment-intro-review-20261102`

Base: `main` at the merge of PR #501.

## What changed

### Enrolment tabs

The Enrolment workspace now has three visible sub-tabs:

1. **Intro** — incoming requests, the guided introductory booking, action queue and
   the conversion overview.
2. **Review** — every active confirmed introductory request with the facts needed
   for follow-up.
3. **Enrolled classes** — the existing Term/class workspace, unchanged.

The old “Enrolment administration” card/eyebrow was removed. The first tab is
now called **Intro**.

### Introductory conversion overview

The overview now renders a table with:

- Student
- Course (and instrument)
- Teacher
- Class date (local time and timezone)
- Time elapsed since the request was created
- Conversion outcome and reason

### Create introductory lesson

- The separate “Check this slot” preview form and the second save form were
  removed. A single **Create** action performs the validation and the save.
- Local start time is a 24-hour dropdown in fixed half-hour steps.
- Local timezone is a dropdown based on the academy’s service areas
  (`AU`, `EU`, `GB`, `NA_EAST`, `NA_WEST`) so the same civil time is not entered
  against a mismatched IANA timezone.
- A validation error returns to the same form (`#dzn-create-intro`), keeps the
  submitted values for two minutes, and shows the relevant error inside the
  form. The page does not jump to the top.
- The existing Teacher agreement, availability override, staff reason and
  collision checks are unchanged.

### Manual introductory booking notifications and portals

Root cause of the reported “created successfully but missing from the Student
and Teacher accounts, with no notifications” issue:

`AdministratorBookingMatchService` confirmed the booking without injecting
`BookingCalendarProjectionService`. Only the Teacher-owned acceptance path did
that. As a result the confirmation stayed `pending_projection`, the request was
not closed, the Student/Teacher upcoming projections did not include it, and the
confirmation notification was never queued.

The administrator path now uses the same projector as the Teacher path. A
disposable runtime proof asserts that the projector is invoked on the
administrator-created confirmation. When the provider is unavailable the
confirmation remains retryable and the existing projection worker can recover
it; it is never silently accepted as portal-visible.

### Review queue

The Review tab shows the current active introductory requests with:

- Date
- Instrument / course
- Teacher
- Student
- Status
- Country
- Attended or not
- Payment link sent (with local date/time)
- Reminder count and last reminder
- Follow-up completed

A request is removed from the active queue when the payment link was sent more
than 14 days ago and there is no recorded decision, verified payment or
activation. No record is deleted; the request remains in History.

### Cancellation and reschedule

`IntroLessonLifecycleService` now owns cancellation/reschedule for a confirmed
introductory booking without inventing a Term, Enrolment or synthetic Lesson.

- **Admin** may cancel and reschedule.
- **Teacher** may cancel and reschedule their own confirmed introductory booking.
- **Student** may cancel their own confirmed introductory booking.
- Ownership is proven through the existing Student/Teacher principal links.
- Reschedule revalidates the future instant, Teacher occupancy, protected
  continuation holds, commercial capacity and Student conflicts.
- Both actions append an audit event and queue durable Student and Teacher
  notification rows.
- When the booking has a confirmed Google Calendar projection, the provider
  event is moved or removed through the existing credential/vault authority. If
  the provider is unavailable, the local portal state and notifications still
  complete; a reschedule reports the provider sync state and a cancellation
  records the provider follow-up in its audit detail.

### Portal notification banner

The full notification cards in the Student and Teacher portals were removed.
The portal now renders a narrow, unread-only banner fixed above the mobile tab
bar / at the bottom of the window on desktop. It:

- renders only when there is an unread notification;
- links to the notification detail when the notification supplies a link;
- offers “read” through the existing notification authority;
- can be closed for the browser session.

## Verification

- All tracked PHP files pass `php -l`.
- The pure source/unit bundle passes, including the new
  `platform/tests/enrolment-intro-review-contract.php`.
- Disposable WordPress/MariaDB acceptance (`acceptance/`: `make enrolment-workspace`)
  covers the existing Enrolment workspace and the new runtime proofs:
  - administrator-created intro invokes the projector;
  - admin cancellation writes the cancelled state, closes the request, writes
    audit evidence and queues Student + Teacher notifications;
  - admin reschedule persists the new confirmation facts and queues both
    notifications;
  - the Intro and Review views render the requested columns and the single
    Create action;
  - the shared notification banner renders only for an unread item.
- Portal and notification presentation contracts pass after the card removal.

## Deployment

Deployment is the protected manual `Deploy production` workflow from the exact
current `main` commit. It is not triggered by this feature branch.
