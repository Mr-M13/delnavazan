# Platform Phase 1 — Core Foundation & Canonical Data Model

## 1. Status

**Not started. Requires explicit product-owner approval.**

This document defines the proposed boundary and acceptance criteria for a future
implementation phase. It does not authorize PHP, plugin bootstrap code, database
tables, deployment, production data access, or changes to Amelia.

## 2. Objective

Create the smallest reliable platform foundation needed for controlled manual
Core data setup and later bounded runtime replacements. Phase 1 establishes
canonical Core identity/state contracts, schema migrations, and module
boundaries without importing Amelia data or changing live business authority.

## 3. Proposed deliverables

After approval, Phase 1 may deliver:

1. a valid WordPress plugin lifecycle/bootstrap with explicit module loading;
2. application version and independent schema/data version tracking;
3. a retry-safe migration runner with lock, audit result, and failure recovery;
4. minimum canonical Teacher, Student, Instrument, Course, Enrolment, Term,
   Lesson, Lesson Schedule Version, Operational Exception, optional Hamnavaz
   link, and generic extension/audit foundations;
5. internal numeric primary keys, immutable opaque ULID-style UIDs, approved
   `DZN-*` reference-code support, and audit/archive conventions;
6. application-service interfaces for creating and administering Core identities
   without automatic duplicate merges;
7. a versioned domain event envelope plus durable idempotency/outbox boundary;
8. capability-protected administrator diagnostics showing version, schema,
   migration health, and documentation links;
9. automated tests for schema-migration retry, identity non-merging, state
   separation, exception handling, authorization, and direct-file safety;
10. build/package validation and a documented rollback plan.

These deliverables remain proposals until a separately reviewed Phase 1 brief
approves the physical schema and implementation choices.

## 4. Approved product decisions and remaining design work

Phase 1 must implement the binding decisions in
[PRODUCT-DECISIONS.md](PRODUCT-DECISIONS.md), including:

- permanent Teacher/Student identity and no automatic duplicate merges;
- separate first-class Instrument, Course, Enrolment, Term, and Lesson records;
- normal rescheduling that preserves Lesson identity and schedule history;
- UTC Lesson instants, explicit IANA timezones, and separate calendar/locale
  presentation;
- numeric internal keys plus immutable opaque ULID-style UIDs and approved
  human-readable `DZN-*` references that never authorize access;
- explicit audited one-to-one Core Teacher/Hamnavaz Profile linking;
- soft archive by default and separate authorized deletion/anonymization;
- approved initial lifecycle vocabulary, Course fields, identifier prefixes,
  custom-table direction, schedule-version model, Term replacement defaults,
  timezone onboarding, and Operational Exception framework;
- manual Phase 2 Core data setup with no Amelia importer or parity engine.

Before implementation, the Phase 1 brief must resolve or deliberately defer the
applicable retention durations, any historical Amelia archive fields required as
manual references, and exact implementation-level indexes/constraints. Google
failed-revoke support remains a later integration decision.

## 5. Scope boundaries

### In scope after approval

- Core infrastructure and domain contracts only;
- schema creation/migration for approved minimum entities;
- administration visible only to appropriately capable users;
- synthetic/anonymised fixtures and non-production automated tests;
- no-op or test-only domain event publication needed to validate the boundary;
- documentation of every schema field and migration.

### Explicitly out of scope

- Amelia data import, synchronization, parity tooling, or table queries;
- any write to Amelia;
- changes to Amelia configuration, hooks, portals, or production records;
- teacher/student self-service portals;
- scheduling UI or scheduling authority;
- attendance reconciliation or signed public actions;
- Google OAuth/Meet provider calls;
- Meta/WhatsApp sends or webhook cutover;
- Stripe/payment processing;
- teacher payment statements;
- Finance module tables, TeacherRate persistence, Lesson finance snapshot
  persistence, or audited financial-correction implementation;
- Hamnavaz Phase 4, public directory search, or profile changes;
- production migration, cutover, deactivation, or deletion;
- copying production personal data into tests, docs, commits, or PRs.

## 6. Minimum entity contract

The Phase 1 schema must encode the invariants in
[DATA-MODEL.md](DATA-MODEL.md), not merely mirror Amelia tables.

At minimum:

- Teacher and Student use an internal numeric primary key, immutable opaque
  ULID-style UID, and approved human reference prefix independent of mutable
  contact data; matches never auto-merge identities;
- Instrument is shared reference data and Course is a minimal Academy product
  that references one Instrument, with the approved fields `name_fa`, `name_en`,
  `course_type`, `status`, `default_duration_minutes`, and
  `default_buffer_minutes`; its initial types are `standard` and
  `introductory`, and status is `active`, `inactive`, or `archived`;
- Enrolment is the continuing Student–Teacher–Course relationship and Term is a
  separate bounded allocation/payment/renewal cycle within it;
- the initial Enrolment and Term state vocabularies and default policy are
  encoded: standard Term allocation is 12 Lessons with two eligible replacement
  Lessons, subject only to explicit audited administrator override;
- an introductory Lesson has nullable Enrolment/Term references and may exist
  for a Student before a continuing Enrolment; the initial Lesson types are
  `introductory`, `standard`, and `replacement` only;
- every Lesson stores direct `student_id`, `teacher_id`, and `course_id` as its
  operational/historical identity; standard Lessons require an Enrolment and
  Term, while replacement Lessons normally require them and must link through
  `replacement_for_lesson_id` to an original Lesson;
- the Lesson creation/service layer validates direct Teacher/Student/Course
  references against the selected Enrolment for standard and replacement
  Lessons; introductory Lessons retain their direct references while their
  Enrolment/Term may be NULL;
- Lesson stores canonical UTC instants plus explicit schedule
  timezone/wall-clock provenance, and keeps schedule history separately from
  attendance, delivery, and payability;
- normal rescheduling preserves Lesson identity; a genuine replacement/make-up
  Lesson explicitly links to the original;
- append-only Schedule Versions contain the approved version, UTC, timezone,
  wall-clock, reason, actor, and supersession fields, with one current version
  per Lesson;
- Operational Exceptions use `open`, `acknowledged`, `resolved`, and
  `dismissed`; ordinary form-validation errors do not create exceptions;
- calendar preference is independent of timezone and supports at least Gregorian
  and Persian/Jalali presentation without changing canonical instants;
- academy Teachers default to `Asia/Tehran`, Iran-based Persian Teachers default
  to Persian calendar presentation, country Iran, and editable settings;
- Student country/city onboarding resolves to an explicit IANA timezone with
  provenance; later Student timezone changes affect display only and never
  reschedule Lessons or recurring schedules;
- an optional Core Teacher/Hamnavaz Profile link is one-to-one, explicitly
  administrator-authorized, and audited; candidate matching never creates it;
- every mutable aggregate has created/updated provenance and reversible archive
  metadata;
- no provider secret or arbitrary raw payload is stored in Core entity rows.

Phase 1 uses InnoDB WordPress-prefixed custom tables with
`$wpdb->get_charset_collate()`: `{prefix}dzn_teachers`,
`{prefix}dzn_students`, `{prefix}dzn_instruments`, `{prefix}dzn_courses`,
`{prefix}dzn_enrolments`, `{prefix}dzn_terms`, `{prefix}dzn_lessons`,
`{prefix}dzn_lesson_schedule_versions`,
`{prefix}dzn_operational_exceptions`, and
`{prefix}dzn_teacher_profile_links`. Defined business fields use defined columns;
generic JSON metadata columns are not a substitute for the Core model. Exact
indexes and constraints require design review in the approved implementation PR.

## 7. Migration framework contract

- Application and schema versions are separate.
- Each migration has a unique ordered identifier and an auditable result.
- A lock prevents concurrent execution.
- Re-running after completion is a safe no-op; re-running after partial failure
  either resumes from a checkpoint or repairs forward deterministically.
- Migration failure leaves the plugin in a safe, observable state and never
  triggers provider writes.
- Phase 2 manual data setup is separate from schema migration and does not run
  automatically on ordinary page requests.
- Deactivation preserves data and removes only ephemeral schedules/locks defined
  by the approved lifecycle.
- Uninstall preserves valuable Core data by default. Destructive removal requires
  explicit future policy and authorization.

## 8. Domain event foundation

Phase 1 should define an event envelope containing:

- event ID and version;
- event type;
- aggregate type/ID and aggregate version;
- occurred-at UTC time;
- actor/source;
- correlation and causation IDs;
- payload constrained to the documented event contract;
- publication/processing state and idempotency metadata.

An event is written atomically with the business change or through an equivalent
durable mechanism. External delivery is not performed inside a Core transaction.
Phase 1 need not implement production consumers.

## 9. Later provider-reference schemas

Provider IDs remain attributes/mappings rather than identity, but Phase 1 does
not create provider-reference persistence beyond the approved Core tables.
Later provider-specific integration schemas will define the required mapping
fields, provenance, uniqueness, and authorization boundaries. No Amelia-specific
SQL, importer, synchronizer, checkpoint, or source fingerprint belongs in Core
or Phase 2.

## 10. Security requirements

- follow [SECURITY.md](SECURITY.md);
- capability and intent-specific nonce checks for every admin mutation;
- object-level authorization for every Core record;
- prepared queries and bounded inputs;
- private-by-default REST/public behavior;
- no secret-bearing settings unless the approved Phase 1 scope explicitly needs
  the encryption service for test coverage;
- redacted audit/diagnostic output;
- no predictable public Lesson actions or public record IDs;
- no theme, WordPress core, Amelia, or unrelated plugin file changes.

## 11. Compatibility and rollout

Platform Phase 1 runs dark beside the current system:

- Delnavazan Enhancements remains active and unchanged;
- Amelia remains installed and authoritative;
- Hamnavaz remains independently operational at Hamnavaz Phase 3;
- no production workflow reads Core records for decisions;
- no provider or user-facing side effect originates from Core;
- activation/deactivation is reversible and preserves existing site behaviour.

Deployment to a beta runtime requires a recoverable backup and a separate,
explicitly authorised validation brief.

## 12. Validation matrix

Before Phase 1 can be proposed for merge, validate:

1. repository branch/base/scope and documentation consistency;
2. plugin activation, deactivation, and reactivation without fatal/warning;
3. application/schema version separation;
4. clean install and every supported upgrade/retry path;
5. migration lock, partial-failure recovery, and idempotent rerun;
6. stable Core ID behavior independent of email/provider IDs;
7. duplicate candidate handling and proof that no workflow auto-merges Core
   identities;
8. approved lifecycle vocabulary, Course fields/types, UID/reference-code
   formatting, and no identifier-as-capability behavior;
9. the approved `introductory`, `standard`, and `replacement` Lesson types;
   introductory nullable Enrolment/Term behavior; direct Lesson
   Student/Teacher/Course identities; creation-time Enrolment consistency for
   standard/replacement Lessons; and Student → Introductory Lesson → Enrolment
   → Term flow;
10. Schedule Version append-only/current-version behavior, normal rescheduling,
    replacement linking, timezone display-only changes, and recurring-schedule
    timezone separation;
11. Operational Exception state transitions and proof that normal form errors
    do not create exceptions;
12. optional Hamnavaz link authorization, audit, one-to-one constraint, and
    non-destructive unlink behavior without changing Hamnavaz itself;
13. archive/restore audit behavior;
14. capability, nonce, object-level authorization, SQL, sanitization, escaping,
    and direct-access protections;
15. private REST/public exposure baseline;
16. no Amelia queries/writes, provider calls, cron sends, Hamnavaz changes, or
    Finance module persistence;
17. no secrets or production personal data in repository/build artifacts;
18. static checks, tests, `git diff --check`, package contents/hash, and runtime
    smoke tests proportionate to the approved deliverable.

## 13. Acceptance criteria

Phase 1 is complete only when:

- approved physical schema and migrations implement the documented invariants;
- all validation passes with evidence;
- a new installation and retry-safe upgrade work in a representative WordPress
  environment;
- the working live system behaves exactly as before because no authority moved;
- the PR contains no Phase 2 data setup, integrations, portals, public routes,
  provider writes, or Hamnavaz Phase 4 work;
- independent review approves the boundary;
- the PR remains unmerged until the product owner explicitly authorizes merge.

## 14. Recommended first task after approval

Create a dedicated Phase 1 implementation branch from current `main`, finalize
the physical schema proposal against the remaining decisions, then build only
the foundation above. Do not combine Phase 1 with manual production data setup,
an Amelia importer, or a workflow cutover.
