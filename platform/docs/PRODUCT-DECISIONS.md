# Delnavazan Platform Product Decisions

## 1. Status

These decisions were approved on 3 September 2026 after Platform Phase 0. They
are binding inputs to the Platform Phase 1 implementation brief. They do not
authorise implementation, production data access, an Amelia cutover, or
Hamnavaz Phase 4.

> **Current-state override:** The initial Phase-1 academy model and lifecycle vocabulary below remain historical product provenance. Current canonical Enrolment identity is Student + Course; canonical `enrolments.teacher_id` is retained Accepted Service Arrangement context, not current Teacher authority. Current Teacher authority belongs to the separate Teacher Assignment aggregate. Schema 17 adds a Teacher-neutral canonical Term foundation with lifecycle `authorised`, `current`, `closed`, or `cancelled`; Schema 18 Phase L adds bounded Term creation/lifecycle commands but no Lesson consumption or payment authority.

> **Source-state reconciliation (2026-09-27):** Current `main` contains Schema-31 portal access
> artefacts and disabled-by-default public capability routes. This is not a product decision to distribute
> links, enable public actions, activate a provider, change Theme presentation or cut over a workflow.
> The implementation inventory is the [Portal authorization registry](PORTAL-AUTHORIZATION-REGISTRY.md).

## 2. Identity

- Teacher and Student are permanent Core identities.
- Email, phone, WordPress, Google, Stripe, Amelia, and other provider IDs are
  attributes or explicit mappings, never identity.
- Matching data may identify a possible duplicate but must never auto-merge
  Core identities.
- A merge requires an explicit, capability-protected administrator action that
  records the actor, reason, surviving identity, superseded identity, mapping
  changes, and time.

## 3. Academy model

- Enrolment and Term are separate first-class entities.
- Enrolment is the continuing Student–Teacher–Course relationship.
- Term is a bounded lesson-allocation, payment, and renewal cycle within one
  Enrolment.
- Instrument is shared Delnavazan reference data.
- Academy Course is the teaching product, references an Instrument, and is the
  catalogue item referenced by Enrolments.
- Phase 1 Course fields are: numeric internal ID, immutable opaque ULID-style
  UID, human reference code, `instrument_id`, `name_fa`, `name_en`,
  `course_type`, `status`, `default_duration_minutes`,
  `default_buffer_minutes`, and audit/archive metadata.
- The initial Course types are `standard` and `introductory`.
- Pricing, location, Stripe information, curriculum, and teacher-specific
  commercial configuration are outside Phase 1.

## 4. Initial lifecycle vocabulary

The following are approved initial states. Later migrations may deliberately
extend them.

| Entity | States |
| --- | --- |
| Teacher | `active`, `inactive`, `archived` |
| Student | `active`, `inactive`, `archived` |
| Course | `active`, `inactive`, `archived` |
| Enrolment | `draft`, `active`, `paused`, `ending`, `completed`, `cancelled`, `archived` |
| Term | `draft`, `awaiting_payment`, `active`, `completed`, `cancelled`, `archived` |
| Lesson | `draft`, `scheduled`, `cancelled`, `completed`, `archived` |
| Operational Exception | `open`, `acknowledged`, `resolved`, `dismissed` |

## 5. Lesson relationship and scheduling

- The initial Lesson types are `introductory`, `standard`, and `replacement`.
- An `introductory` Lesson may exist with `enrolment_id = NULL` and
  `term_id = NULL`. Its `student_id`, `teacher_id`, and `course_id` are direct
  Lesson references.
- A `standard` Lesson requires the applicable Enrolment and Term.
- A `replacement` Lesson normally requires the applicable Enrolment and Term
  and must explicitly reference its original Lesson through
  `replacement_for_lesson_id`.
- Every Lesson stores direct `student_id`, `teacher_id`, and `course_id`.
  These preserve the operational/historical identity of the occurrence. When a
  standard or replacement Lesson is created, the service layer must verify that
  these direct references match its selected Enrolment; a later change to a
  Teacher, Course, or Enrolment does not retrospectively alter the Lesson.
- A Student may therefore hold an Introductory Lesson before any continuing
  Enrolment exists: Student → Introductory Lesson → Enrolment → Term → standard
  Lessons.

- Normal rescheduling preserves Lesson identity and appends an audited schedule
  version/history record.
- `rescheduled` is an event/history fact, not a persistent Lesson state.
- A genuine replacement or make-up occurrence may be a new Lesson explicitly
  linked to the original. Creating it must be an intentional business action,
  not an automatic consequence of changing time.

Normal schedule history uses append-only Lesson Schedule Versions with:

- `lesson_id`, `version_number`, `starts_at_utc`, `ends_at_utc`,
  `schedule_timezone`, `local_wall_date`, `local_wall_time`, `reason`,
  `changed_by`, `created_at`, and `superseded_at`;
- exactly one current version per Lesson;
- `replacement_for_lesson_id` on a genuine replacement/make-up Lesson.

## 6. Term policy defaults

- A standard Term allocates 12 Lessons by default.
- A Term has an allowance of two eligible replacement Lessons by default.
- An eligible replacement belongs to the same Term and must be scheduled and
  used within that Term.
- Unused replacement entitlement expires when the Term closes; it does not
  automatically carry into the next Term.
- These are policy defaults, not immutable database constants. An exceptional
  administrator override is explicit and audited.

## 7. Timezone, location, and calendar

- Canonical Lesson instants are stored in UTC.
- Teacher and Student have explicit IANA timezones.
- Academy teachers default to `Asia/Tehran`; an authorised user may change that
  value.
- Recurring schedules retain the intended wall-clock time and the IANA schedule
  timezone so future occurrences survive offset-rule changes correctly.
- Calendar and locale presentation are separate from timezone. Presentation
  supports at least Gregorian and Persian/Jalali calendars.
- Iran-based Persian teachers default to the Persian calendar.
- Calendar conversion never changes the canonical instant.
- Student onboarding asks for human-readable country and city, resolves that
  choice to an explicit IANA timezone, and stores location separately from
  timezone with provenance/source.
- A Student may later change personal timezone under Profile/Preferences. That
  changes display only: it never reschedules existing Lessons or changes an
  Enrolment recurring schedule.
- Recurring schedule timezone is a separate scheduling value.
- Academy teachers default to country Iran, `Asia/Tehran`, and Persian
  locale/calendar; each remains editable.

## 8. Identifiers and public capabilities

- Every Core entity uses an internal numeric database primary key and immutable
  opaque ULID-style UID.
- Operational entities use human-readable reference codes with these prefixes:
  Teacher `DZN-TCH-`, Student `DZN-STU-`, Instrument `DZN-INS-`, Course
  `DZN-CRS-`, Enrolment `DZN-ENR-`, Term `DZN-TRM-`, and Lesson `DZN-LSN-`.
- Numeric padding is a minimum display format, not a maximum; for example,
  `DZN-LSN-001842`.
- Numeric IDs, opaque UIDs, and reference codes are identifiers, not
  authorization credentials.
- Public actions use independent opaque or signed, purpose-bound capabilities.

## 9. Physical storage direction

Phase 1 uses dedicated WordPress-prefixed InnoDB custom tables, with
`$wpdb->get_charset_collate()`:

- `{prefix}dzn_teachers`, `{prefix}dzn_students`, `{prefix}dzn_instruments`,
  `{prefix}dzn_courses`, `{prefix}dzn_enrolments`, `{prefix}dzn_terms`, and
  `{prefix}dzn_lessons`;
- `{prefix}dzn_lesson_schedule_versions`;
- `{prefix}dzn_operational_exceptions`;
- `{prefix}dzn_teacher_profile_links`.

Application and schema versions remain separate. Normal hard deletion is not
used. Defined business fields belong in defined columns; generic JSON metadata
columns are not a substitute for the Core model.

Provider-specific mapping schemas are later integration work. They may not make
a provider ID an identity or authorization credential.

## 10. Hamnavaz linking

- A Core Teacher ↔ Hamnavaz Profile link is optional, explicit,
  administrator-authorized, audited, and one-to-one where present.
- Matching fields may suggest a candidate link but never establish it.
- Linking or unlinking does not create or delete either a Core Teacher or a
  Hamnavaz Profile.

## 11. Retention and archive

- Historical business records are soft-archived and retained by default.
- Permanent deletion or anonymization is a separate, explicitly authorized
  process.
- Temporary technical data has an explicit retention policy.
- Provider provenance may be retained where it supports operations, audit, or
  support; unnecessary raw provider payloads need not be retained indefinitely.

## 12. Finance architecture — Platform Phase 9

- Phase 2A.2-U (Schema 30, candidate) implements the payability, effective-dated teacher-rate,
  per-Lesson rate/currency snapshot, teacher compensation statement, reconciliation and audited
  correction boundary. It records what the academy owes a Teacher and stops there: it creates no ledger,
  journal, chart of accounts, tax/VAT rule, invoice, credit note, remittance, payout, payment instruction
  or bank/IBAN/card detail, and no external accounting surface is ever treated as the source of a
  payability, rate, snapshot, statement or correction truth.
- Teacher rates are effective-dated.
- Each Lesson receives a teacher-rate and currency snapshot.
- Changing a current rate does not recalculate historical completed or payable
  Lessons.
- A post-completion financial correction is explicit and audited.
- Student pricing and teacher compensation are separate concepts.
- Phase 1 provides no TeacherRate persistence, Lesson finance snapshot
  persistence, Finance tables, or Finance implementation. It may provide only
  generic extension and audit foundations required by future modules.

## 13. Operational exceptions

The approved operating principle is: **Automate the happy path. Surface failures
and ambiguity. Require human intervention only where judgement is needed.**

Administration is primarily exception management, not routine booking
operation. `OperationalException` is therefore part of the Phase 1 Core
foundation and uses the states `open`, `acknowledged`, `resolved`, and
`dismissed`. Ordinary form-validation errors are not Operational Exceptions.

## 14. Amelia data setup and coexistence

The automated Amelia migration approach considered during Phase 0 is rejected.
The Platform will not build an Amelia importer, shadow synchronizer, parity
engine, repeatable import checkpoint system, or automated Amelia-to-Core mapping
pipeline for the initial data setup.

The live dataset is small enough for controlled manual recreation:

- approximately 13 services/courses;
- the handful of active teachers;
- the relevant active student or students;
- their Enrolments, Terms, and required Lessons.

Phase 2 therefore performs manual Core Data Setup & Cutover Preparation with
explicit counts, review, and validation. Historical Amelia data may be exported
and retained outside operational Core as a read-only archive when needed. The
archive is evidence, not an operational identity or synchronization source.

The runtime migration remains strangler-style. Amelia-dependent functionality
may continue temporarily in Delnavazan Enhancements while Attendance, Google,
Notifications/WhatsApp, Scheduling, Portals, Stripe, and Finance are replaced
through bounded, reversible cutovers. New Platform Core code must not acquire a
new Amelia data-model dependency.

Idempotency remains required for schema migrations, domain commands/events,
provider calls, and webhooks. Controlled before/after comparison remains a
validation technique for each replacement. Neither requirement reinstates an
Amelia importer, synchronizer, or parity engine.

## 15. Remaining later decisions

The approved direction does not yet choose:

- retention durations and anonymization rules for each business, technical, and
  archived provider-data class;
- Google failed-revoke support procedure and token-retention period;
- historical Amelia export format, storage location, access control, retention,
  and restore/inspection procedure;
- later Finance physical tables and audited correction implementation;
- later provider-specific integration schemas.

These items require explicit resolution or deliberate deferral in the relevant
implementation brief. Platform Phase 1 remains not started until separately
authorised.
