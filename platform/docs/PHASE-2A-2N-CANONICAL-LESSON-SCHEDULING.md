# Phase 2A.2-N — Canonical Lesson Scheduling & Teacher Capacity Authority

Status: **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED**

Schema 21 / migration `021_canonical_lesson_schedule_authority` / build `phase2a2n-canonical-lesson-schedule-authority-20260917.1` are authoritative. Final independently approved candidate `9f92ada6ba82d809c1515566fb426c90a5a68e57`, tree `ec29baabf2d737d0eecd72d857a152b8eba4b163`, was merged as `08138270f4bd32e5829ef0f5a18a316f6780607d` with exact tree preservation.

## Locked contract

A scheduled canonical Lesson is a **canonical Lesson in `authorised` state plus exactly one applicable canonical schedule version**. Phase N never introduces a `scheduled` Lesson lifecycle state, and it never mutates Phase-M Lesson lifecycle as a side effect of scheduling.

Phase N owns initial scheduling, schedule revision, schedule release, immutable schedule-version history, the append-only schedule event chain, Teacher occupied-time authority, Teacher-capacity conflict prevention, digest-only scheduling command evidence, protected canonical schedule reads and the cross-phase terminalisation guards listed below.

Phase N does not own attendance, delivery completion, payment, renewal, payroll, Google Calendar, Google Meet, WhatsApp, Amelia, CRM/Hamnavaz, portals, recurrence or external-provider synchronisation.

## Capacity model

Teacher capacity is **one concurrent canonical Lesson per Teacher**. Occupancy uses the half-open occupied interval `[starts_at_utc, occupied_ends_at_utc)` where `occupied_ends_at_utc = ends_at_utc + buffer_minutes`. A Lesson starting exactly at another Lesson's occupied end does not conflict; a Lesson starting one second earlier does. Each schedule version freezes `duration_minutes`, `buffer_minutes`, `duration_source`, `ends_at_utc` and `occupied_ends_at_utc`.

Duration comes from the canonical Course's `default_duration_minutes`; an explicit per-command duration override is permitted only as an audited, frozen value (`duration_source = override`). Buffer comes from `default_buffer_minutes`; Phase N adds no arbitrary buffer override. Teacher occupancy is **derived** from applicable canonical schedule versions — there is no mutable reservation projection and no mutable capacity counter.

## Time policy

Every `schedule_initial` and `schedule_revise` command supplies an explicit IANA timezone. The version persists the IANA timezone, the local wall date and wall time, the UTC lesson interval and the UTC occupied end. Conversion reuses the DST-correct `AvailabilityLocalTime` infrastructure: nonexistent spring-forward wall times and ambiguous fall-back wall times are rejected and never guessed. The server timezone and the Teacher availability profile timezone are not scheduling authority. No recurring Enrolment/Term timezone is introduced in Phase N.

## Past / horizon policy

`schedule_initial` and `schedule_revise` require a strictly future start; scheduling authority cannot be newly created or moved into the past, and Phase N deliberately adds no "past correction" channel. There is no maximum future horizon. `schedule_release` may release existing authority where the domain guards permit.

## Enrolment, Term, Lesson and Assignment policy

- `schedule_initial` and `schedule_revise` require a `current` canonical Enrolment and a `current` canonical Term; a paused Enrolment cannot create or revise scheduling authority, and `schedule_release` remains permitted while the Enrolment is `current`, `paused` or `closed`.
- Pausing an Enrolment neither cascades nor silently alters schedule history.
- Term close and Term cancel reject while active future canonical schedule authority exists (`active_future_schedule_exists`), in addition to the existing authorised-Lesson guard.
- A canonical Lesson with an active future schedule cannot complete or cancel; the operational order is release first, then terminalise. Historical released/superseded versions remain valid evidence.
- Enrolment closure additionally rejects any active future canonical schedule belonging to the Enrolment.
- Teacher Assignment replacement rejects while the Enrolment has active future canonical schedule authority: future Teacher-time belongs to the outgoing Teacher snapshot and is never silently transferred. A fresh Lesson issued under the new Assignment can then be scheduled.
- Teacher archival rejects while that Teacher owns active future canonical schedule authority.

No guard cascades and no guard releases a schedule implicitly.

## Teacher availability

Availability remains an upstream fact and is consumed as a scheduling constraint: normally the lesson interval must be covered by an effective non-`blocked` Teacher availability segment. A capability-controlled administrative availability override (`dzn_override_canonical_lesson_schedule_availability`) bypasses **availability only**, records an audited reason, evidence channel, digest, actor and timestamp on the version and command, and never bypasses capacity conflict, stale Assignment, Enrolment state, Term state, Lesson lifecycle, aggregate integrity, idempotency integrity or the terminalisation guards.

## Serialization and lock order

Every scheduling transaction acquires the shared canonical context first and the dedicated per-Teacher scheduling root last:

```text
enrolment_identity_roots → Enrolment → Term → canonical Lesson → Lesson lifecycle evidence
→ schedule versions → schedule events → recorded Assignment → Teacher share lock
→ {prefix}dzn_teacher_schedule_roots FOR UPDATE (innermost)
```

The Teacher scheduling root is a serialization anchor only: it holds no availability, capacity or authority state, and it is created lazily for Teachers that appear after migration. Range/gap locking is deliberately not used: the project runs at `READ COMMITTED`, where gap locking is disabled, so an empty overlap range offers no row to lock and two first-ever overlapping reservations could otherwise both commit.

**Contract rule:** no current or future phase may acquire a Teacher scheduling root and then attempt to acquire an earlier Enrolment identity-root chain.

## Idempotency and replay

Domain `canonical_lesson_schedule_v1`; operations `schedule_initial`, `schedule_revise`, `schedule_release`. Raw keys are digested with `wp_salt('dzn_canonical_lesson_schedule')` and never persisted. Commands persist complete operation-specific intent plus the resolved policy facts and result identity.

Replay revalidates the command-key digest, payload digest, domain, operation, Lesson, Enrolment, Term, Teacher, expected Assignment, expected active schedule version, expected Lesson lifecycle state, requested timezone/wall provenance, duration override, availability override evidence, reason/evidence, and the resolved interval/duration/buffer/duration-source/availability-basis against the stored version, then validates the whole resulting aggregate through the single canonical gate and requires the durable event evidence the command recorded. Corruption of any command, version or event field fails closed.

## Data model

| Table | Purpose |
|---|---|
| `{prefix}dzn_teacher_schedule_roots` | Stable per-Teacher serialization anchor (`UNIQUE(teacher_id)`), lazily ensured. |
| `{prefix}dzn_canonical_lesson_schedule_versions` | Immutable schedule interval assertions: Lesson, version number, `applicable_slot`, Enrolment/Term/Assignment/Teacher snapshots, UTC interval, duration/buffer/`duration_source`, occupied end, IANA timezone, local wall provenance, availability basis and override evidence, reason/evidence, supersession lineage. Only `applicable_slot`, `superseded_at` and `superseded_by_version_id` may mutate. |
| `{prefix}dzn_canonical_lesson_schedule_events` | Append-only per-Lesson event chain (`scheduled`, `rescheduled`, `released`). |
| `{prefix}dzn_canonical_lesson_schedule_commands` | Digest-only durable command evidence with complete intent and result identity. |

`UNIQUE(lesson_id, applicable_slot)` provides database arbitration for exactly one applicable version per Lesson; `UNIQUE(lesson_id, version_number)` and `UNIQUE(lesson_id, event_sequence)` keep history consecutive; `KEY teacher_occupancy(teacher_id, starts_at_utc, occupied_ends_at_utc)` supports the conflict query.

## Legacy isolation

Legacy Phase-1 `LessonScheduleService` and `lesson_schedule_versions` remain legacy-only. Canonical Lessons cannot be scheduled through the legacy path (the legacy repository filters `record_model='legacy_phase1'`), legacy Lessons cannot enter canonical scheduling authority, there is no dual-read, and the canonical validator fails closed if a canonical Lesson carries a legacy scheduling projection (`current_schedule_version_id`). Migration 021 is additive only: no legacy schedule backfill, no reinterpretation and no authority translation.

## Validation performed

Disposable MariaDB 11.4.13 (intended runtime target). No production, NIU, Theme, Amelia or external system was contacted.

- 30 static/source contract tests pass, including the new Phase-N contract.
- Authority runtime: initial scheduling, replay/conflict, revision, release, re-scheduling after release, capacity overlap, buffer adjacency, availability constraint, administrative override, past-start rejection, paused Enrolment, DST wall-time handling, legacy isolation, Assignment replacement guard, Teacher archival guard, repeat upgrade.
- Corruption runtime: 30 version/event fact classes plus 29 command-intent classes and released-state corruption, all fail closed through the protected read, Lesson completion and the capacity conflict path.
- Failure injection: 11 write-boundary injections across initial, revision and release, proving rollback, preserved occupancy, no orphan evidence and no falsely replayable command.
- Migration runtime: fresh Schema 21, legacy preservation, no canonical backfill, rehearsed Schema 20 → 21 upgrade, repeat migration, capability repair and Teacher-root backfill.
- Malformed-storage regression (correction round 1): `Migrator::verify_canonical_lesson_schedule_authority_schema()` is invoked both by the per-migration loop before `021_canonical_lesson_schedule_authority` is recorded and by `verify_current_schema()`, and six damaged-storage states (dropped `lesson_applicable`, `teacher_occupancy` and `teacher` indexes, a mutable `updated_at` on append-only evidence, a nullable `command_key_digest`, and a non-transactional engine) each fail closed with `Migration verification failed` instead of activating or retaining Schema 21.
- Retained-021 pre-activation regression (correction round 2): `Migrator::maybe_upgrade()` now invokes the Phase-N verifier unconditionally immediately before it writes the schema option, so a completed ledger that already records `021_canonical_lesson_schedule_authority` while the schema option still reads 20 cannot advance to 21 unless Phase-N storage verifies. A damaged `lesson_applicable` index is rejected fail-closed with the schema option held at 20, and repaired storage then recovers to Schema 21 exactly once.
- Concurrency: 26-mode deterministic gated matrix passing on MariaDB 11.4.13, including both orderings for pause, close, Term close, Term cancel, Lesson completion, Lesson cancellation, Assignment replacement and Teacher archival, plus empty-range capacity, buffer adjacency, availability contention, override, non-overlapping same-Teacher serialization and unrelated-Teacher independence.
