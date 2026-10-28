# Teacher classes, Jalali presentation and rescheduling — 2026-10-28

Branch: `feat/teacher-classes-reschedule-20261028`

## Architecture finding

Canonical rescheduling already existed as the staff application service
`CanonicalLessonScheduleService::revise()`. It owns schedule-version supersession, Course policy,
teacher occupancy, protected capacity, idempotency, concurrency and the append-only canonical
audit trail. It did not provide a teacher proposal or student acceptance workflow, and its
capability is deliberately staff-only.

## Schema version

**Schema 54 / migration `054_lesson_reschedule_authority`.**

PR #380 (`feature/phase-2a2y-recording-consent-retention-deletion`) reserves Schema 53. This branch
is based on the current authoritative `main` (Schema 52) and publishes the next unused version,
54. It does not renumber or modify any released migration. At merge time the next unused version
must be rechecked; do not assume 54 if another migration has landed afterwards.

## Transaction boundary

Student acceptance uses one caller-owned transaction. Within that transaction it:

1. locks and re-reads the proposal row;
2. re-reads and validates the expected canonical schedule version;
3. revalidates the teacher assignment/availability, teacher occupancy and protected capacity
   through the canonical service;
4. acquires the per-Student scheduling root and performs the final Student-conflict query;
5. invokes `CanonicalLessonScheduleService::reviseWithinTransaction()`;
6. appends the accepted proposal event;
7. transitions the proposal to `accepted` and binds the resulting schedule version;
8. enqueues both notification rows and the Google Calendar hand-off job; then
9. commits.

`revise()` remains the public self-contained transaction used by staff callers.
`reviseWithinTransaction()` is the same implementation with transaction ownership delegated to the
caller; it never starts, commits or rolls back a transaction itself. There is one implementation of
supersession, policy resolution, occupancy checking and version creation. No controller or theme
code writes canonical schedule tables.

A crash before commit cannot leave a revised canonical schedule without its accepted event and
durable outbox rows. A refusal for conflict, stale version or unavailable actor rolls back the
whole transaction, leaving the proposal pending and changing no outbox state.

## Student-conflict lock and global lock order

A `student_schedule_roots` row is the minimum canonical lock added to serialise schedule changes
affecting the same Student. It is acquired before the Student–Course identity root and before the
Teacher root. The final Student-conflict query runs after this lock, inside the acceptance
transaction, and covers all applicable canonical Lessons for the Student, legacy Lessons and
confirmed introductory bookings. Intervals are half-open occupied intervals.

Lock order:

```text
reschedule proposal row
→ Student scheduling root
→ Student–Course identity root
→ Enrolment
→ Term
→ canonical Lesson
→ Lesson lifecycle evidence
→ schedule versions
→ schedule events
→ recorded Assignment
→ Teacher share lock
→ Teacher scheduling root
```

## Audit identities

The proposal decision event records the real authenticated Student user (`authenticated_user_id`,
`decided_by`), proposal ID/UID, Student ID, previous/resulting proposal states, proposed schedule
facts, expected old schedule version, resulting new schedule version, decision timestamp,
request/decision idempotency digest and controlled reason/evidence fields.

The canonical schedule version and canonical schedule event continue to record the configured
scheduling system user as their actor. A Student never receives
`dzn_manage_canonical_lesson_schedules`.

## Required system actors

- `dzn_reschedule_system_user_id`: active user with only
  `dzn_manage_canonical_lesson_schedules`. It must not have `manage_options` or
  `dzn_manage_provider_integrations`.
- `dzn_reschedule_calendar_system_user_id`: active user with
  `dzn_manage_provider_integrations`. It must not have `manage_options` or
  `dzn_manage_canonical_lesson_schedules`.

Both actors fail closed when missing, inactive or over-privileged. They never fall back to the
current Student or a general administrator.

## Calendar and notification recovery

Acceptance enqueues durable notification rows and one calendar job in the same transaction as the
canonical revision. Notification idempotency and calendar-job idempotency are enforced by unique
database keys. Calendar retries never create a second schedule revision. A missing/underprivileged
calendar actor leaves the job `queued` with attempts unchanged; provider failures use bounded
backoff. The existing provider integration authority records new schedule-version projections and
the existing projection worker dispatches them.

## Deployment checks

- confirm the deployed commit is the reviewed merge commit;
- confirm the migration is the next unused schema version at merge time (currently 54; 53 is
  reserved by PR #380);
- confirm clean installation and upgrade from Schema 52 pass exactly once;
- confirm both system-user options are configured and least-privilege;
- confirm cron/outbox workers and the Google integration connection are healthy;
- take a verified database backup and record the current schema and commit;
- verify `student_schedule_roots`, proposal, event, notification and calendar-job tables/indexes;
- verify acceptance creates exactly one successor version and one logical set of outbox jobs;
- process notification/calendar retries and verify no duplicate projections.

## Rollback limitations

A code rollback does not reverse accepted canonical schedule changes. Do not decrement the schema
version, drop the new tables or delete proposal/event/canonical history. Disable the rescheduling
entry points and pause workers if necessary. Any schedule correction must be a new audited
canonical revision. Provider jobs must be reconciled before workers resume.
