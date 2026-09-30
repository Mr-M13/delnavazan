# Delnavazan Platform Architecture

> **Current-source reconciliation (2026-09-27):** the reviewed local `main` baseline at
> `5bd376881769b15a27f5b20a5c02cef5bad0397c` (tree `f6dac0bd978e62ecef3247a5f64e8957b1b96d5a`)
> declares **Schema 33** with the unchanged build
> `phase2a2s-notification-communications-authority-20260924.1`. The formerly recorded
> `4ccd43c` / `6673e7b` source identity is a historical review snapshot only, not the current HEAD
> or checkout. Migration
> `033_core_dataset_technical_prerequisites` is additive on the historical Schema-32 W/S integration
> and provides five bounded Core-dataset readiness-evidence tables; it is verified before its ledger
> entry and schema-version advance. The Phase-W portal implementation remains present. This is a
> checked-in source fact,
> not a claim that W or S is deployed, publicly enabled or cut over. The three
> registered W public routes remain exact-option-gated; authenticated portal read ports are internal
> seams, not current REST endpoints. See [Portal authorization registry](PORTAL-AUTHORIZATION-REGISTRY.md).
> Earlier Schema-25/26 text below is retained as historical provenance.

> **Phase 2A.2-R1 merged and closed (historical Schema 25, superseded by the Schema-33 current-state
> override below); Phase 2A.2-R2 candidate (Schema 26) — not authoritative:** Phase 2A.2-R1 commercial purchase, funding and current-Term
> capacity authority is merged and closed on `main` at
> `f9df3bfb0fda79fba7dee916c4687464ee67d480` (Schema 25, migration
> `025_commercial_purchase_funding_authority`): an immutable Platform-issued purchase offer and
> pricing snapshot, ordered payment obligations, provider-neutral evidence, derived academic funding,
> bounded entitlements bound to Terms through the existing Phase-L authority, the canonical Regular
> recurring pattern and protected capacity with mandatory Phase-Q → R1 → Phase-N succession. The
> active candidate adds Phase 2A.2-R2 / Schema 26 renewal, next-Term, recurring
> enrolment/collection, recovery, lapse and refund-review authority strictly **above** that layer: a
> recurring-enrolment aggregate, progression-derived renewal cycles, provider-neutral recorded
> collection modes and collection intents, recovery/lapse representation, a provider-neutral
> refund/reversal review trajectory and continuous cross-Term protection. R2 reimplements no R1
> pricing, acceptance, funding derivation, protected claim or Term binding, makes no provider call,
> delivers no notification and decides none of the four deferred product questions. Phase 2A.2-U
> (Schema 30, candidate) adds the Finance authority above the canonical facts: a versioned four-key finance policy
> registry, effective-dated scoped teacher rates, one immutable per-Lesson rate/currency snapshot per
> canonical Lesson, a versioned payability derivation with an audited additive override, single-currency
> per-Teacher compensation statements, read-only exact-integer reconciliation and append-only audited
> corrections. It creates no ledger, journal, invoice, tax rule, payout, bank detail, provider call,
> credential, route or front-end surface, and it mutates no canonical, commercial or provider row.
> That R1/R2/T/U layer description authorises no live behaviour. Current `main` additionally contains
> the narrow Phase-W access-artefact layer (see the reconciliation note above and the
> [Portal authorization registry](PORTAL-AUTHORIZATION-REGISTRY.md)). Live provider execution,
> notification delivery, Theme changes, refund academic consequences, automatic charging, recovery
> automation, payouts, deployment and production cutover remain outside Platform authority.

## 1. Status and scope

This document preserves the canonical Phase 0 architectural direction, ownership and migration constraints. It is not authority for a big-bang Amelia replacement, deployment or unbounded implementation.

> **Historical current-state override (2026-09-24, retained as provenance; superseded by the 2026-09-27 override):** Platform 0.1.0 `main` was authoritative at Schema 25 after the Phase 2A.2-R1 commercial purchase, funding and current-Term capacity merge and closeout; Phase 2A.2-R2 (Schema 26) is the active, unmerged candidate. Phases 2A.2-A through Q are complete, independently reviewed where consequential, merged and closed; canonical authority therefore covers Lesson identity/lifecycle, canonical scheduling and Teacher capacity (Phase N), provider-neutral exceptional delivery/attendance outcomes with a distinct academy-obligation debt authority (Phase O), Phase-P intake/evidence/assessment/review authority, and the Phase-Q post-intro continuation decision, explicit administrator-authorised first regular slot record and bounded pre-payment hold, integrated into the single canonical Teacher-capacity arbitration seam. Phase 2A.2-R1 (Schema 25, merged and closed) adds only the provider-neutral commercial purchase, offer/pricing snapshot, ordered obligation, funding, Regular recurring pattern and current-Term protected-capacity authority, and still creates no Term, Lesson, schedule, notification or provider authority. Phase 2A.2-R2 (Schema 26, candidate) adds only the cross-Term recurring-enrolment, renewal-cycle, collection-intent, recovery, refund-review and continuous-protection authority above R1; it stops before charge execution, notification delivery, refund academic consequences and provider adapters. Production continuation and attendance cutover, Google/Meet and calendar evidence, OAuth/webhooks/credentials, Amelia, payment execution, notification delivery, payroll, Teacher Portal and Student Portal integration remain outside Platform authority. **No next slice is authorised.** Read [DELNAVAZAN-CORE-CONTINUITY.md](DELNAVAZAN-CORE-CONTINUITY.md) for the exact delivery state.

> Historical note: this override previously recorded the Phase 2A.2-L / Schema 18, Phase 2A.2-M / Schema 20, Phase 2A.2-N / Schema 21, Phase 2A.2-O / Schema 22, Phase 2A.2-P / Schema 23 and Phase 2A.2-Q / Schema 24 states, and later the unmerged Schema-25 Phase-R1 candidate. Those states are historical provenance; the state recorded at that time was Schema 25 on `main` with Schema 26 the then-active Phase-R2 candidate (current source is Schema 33).

> **Current-state override (2026-09-27):** Platform 0.1.0 local candidate is at **Schema 33**
> after the Phase 2A.2-R2, V, T, U and W candidate trees, the historical additive Schema-032 Notifications S
> re-land were materialised on top of the merged R1 (Schema 25) baseline; the reviewed local `main`
> baseline is `5bd376881769b15a27f5b20a5c02cef5bad0397c` (tree
> `f6dac0bd978e62ecef3247a5f64e8957b1b96d5a`), and the package declares build
> `phase2a2s-notification-communications-authority-20260924.1`
> (the W slice's own stamp is `phase2a2w-portal-facing-services-20260927.2`). Phases 2A.2-A through Q are complete, independently
> reviewed where consequential, merged and closed; R1 is merged and closed; R2/V/T/U/W and the S
> re-land are present in
> the current tree with their recorded review/activation posture (none deployed or activated). The
> implemented boundary now includes the narrow W access-artefact layer described by the
> [Portal authorization registry](PORTAL-AUTHORIZATION-REGISTRY.md): three exact-option-gated public
> capability routes, internal-only authenticated read seams, and no authenticated portal HTTP surface.
> There is no Theme or provider authority and no portal outbox write; the additive Schema-032
> notification slice adds notification storage and the `platform_outbox` extension but activates no
> delivery or provider. Schema 33 additionally records bounded Core-dataset provenance, reconciliation
> runs/findings, operator commands and append-only corrections; it creates no domain row and performs no
> backfill. Schema-24/25 and Schema-32 statements above and below are
> historical provenance. Read [DELNAVAZAN-CORE-CONTINUITY.md](DELNAVAZAN-CORE-CONTINUITY.md) §1.1 for
> the exact inventory.

### Phase 2A.2-J architectural seam

Teacher Assignment is separate from canonical Enrolment identity and lifecycle. `enrolments.teacher_id` remains Accepted Service Arrangement history; current Teacher authority comes only from the privacy-minimised Assignment read seam. Zero Assignment is valid, migration performs no backfill, and Assignment creation is confined to applicable canonical Enrolments. Initial authority follows exact final-arrangement Teacher provenance; replacement authority requires new Assignment-specific authenticated-Teacher evidence or authorised staff attestation. Assignment lifecycle cannot create or mutate Terms, Lessons, schedules, capacity, payments, notifications, calendars, Amelia, Hamnavaz, or CRM state.

Teacher Assignment commands serialize on the canonical Enrolment, use ascending shared Teacher locks to coordinate with exclusive archival/offboarding, and then lock concrete Assignment rows. Duplicate arbitration recognizes only named aggregate indexes and returns convergence only after complete operation-specific authority, lineage, lifecycle, evidence, and command validation; otherwise it fails closed.

### Phase 2A.2-K architectural seam

Canonical Term identity is canonical Enrolment plus immutable server-owned sequence. Term contains no Teacher identity and a Teacher Assignment replacement never re-identifies or mutates a Term. Schema 17 separates preserved Phase-1 Terms from canonical storage, provides append-only lifecycle evidence and protected reads, and stops before canonical creation, lifecycle commands, Lessons, consumption, scheduling, payment, renewal or external authority. An applicable Term (`authorised` or `current`) is coherent only beneath an applicable Enrolment (`authorised`, `current`, or `paused` with slot `1`); a closed Enrolment with an applicable Term fails closed as `data_integrity_conflict`.

## 2. Architectural goal

Delnavazan needs one durable business model that can support the academy without
making a booking plugin, payment provider, communications channel, or directory
the authority for identity. The platform must preserve the behaviours that work
today while allowing each external dependency to be replaced independently.

The defining principle is:

> **Delnavazan Core owns business identity and business state. Integrations
> reference Core entities. Core entities do not derive their identity from
> integrations.**

The immediate goal is not feature expansion. It is to establish canonical
Teacher, Student, Instrument, Course, Enrolment, Term, and Lesson records and
safe boundaries around them.

## 3. Modular platform

The intended modules are:

| Module | Primary responsibility |
| --- | --- |
| Core | Stable business identities, canonical state, identifiers, migrations, domain events, Operational Exceptions, and audit metadata |
| Academy | Enrolment, term allocation, course/instrument relationships, and academy policy |
| Attendance | Attendance outcomes, evidence, review, and absence/join actions tied to a Lesson |
| Notifications | Workflow eligibility, message attempts, idempotency, and delivery lifecycle across channels |
| Integrations | Provider adapters for Google, Meta, Stripe, email, calendars, and future external services |
| Finance & Reporting | Payability decisions, effective-dated teacher rates, per-Lesson rate/currency snapshots, statements, and financial reporting |
| Portals | Authenticated student, teacher, and administrator application surfaces |
| Hamnavaz | Directory profiles, discovery, public eligibility, and profile verification |
| Legacy Adapters | Narrow transitional runtime bridges isolated from Core; not a general Amelia import layer |

Detailed dependency rules appear in
[MODULE-BOUNDARIES.md](MODULE-BOUNDARIES.md).

## 4. Core ownership of identity

Core assigns stable Delnavazan identifiers. The following values are mappings,
not primary identities:

- WordPress user and post IDs;
- Amelia employee, customer, appointment, customer-booking, and service IDs;
- Google subject, account, email, conference, and calendar identifiers;
- Stripe customer, payment, subscription, price, and product identifiers;
- Meta message and template identifiers;
- Hamnavaz teacher/profile post IDs.

Mappings are recorded explicitly through `LegacyReference` or an
integration-specific reference linked to a Core entity. A changed email, merged
provider record, re-created Amelia customer, or replaced integration must not
change the Core identity.

Matching attributes may suggest duplicate Teachers or Students but never merge
them automatically. Identity merges are explicit, administrator-authorized, and
audited.

Core owns only business identity and canonical state. It does not absorb every
provider payload, public profile field, or operational log.

## 5. Lesson as the operational centre

Every scheduled teaching occurrence is represented by a Core Lesson. Modules
attach their own state to that lesson:

```text
Instrument ─ Course ─ Enrolment ─ Term ─ Lesson
                         │         │       ├─ Attendance
Teacher ─────────────────┘         │       ├─ Google
Student ───────────────────────────┘       ├─ Notifications
                                           ├─ Scheduling
                                           ├─ Finance
                                           └─ Reporting

Student ─ Introductory Lesson (no Enrolment or Term required)
```

The Lesson owns the canonical scheduled occurrence and its lifecycle. It
directly retains `student_id`, `teacher_id`, and `course_id` as the
operational/historical identity of that occurrence; these are not derived again
from a later Enrolment change. It does not directly own attendance evidence,
Meta delivery states, OAuth credentials, or accounting reports. Scheduling
state and attendance outcome remain distinct: a cancelled lesson is a
scheduling fact; a student absence is an attendance fact; a delivered reminder
is a notification fact.

Lesson instants are stored canonically in UTC. Teacher and Student use explicit
IANA timezones. Recurring schedules retain their schedule timezone and intended
wall-clock time. Calendar/locale presentation—including Gregorian and
Persian/Jalali—is separate and can never change the canonical instant.

## 6. Integrations as adapters and providers

An integration translates between a provider contract and a Delnavazan service
boundary. It may hold provider references and provider-specific state, but it may
not invent or become the owner of a Teacher, Student, Term, or Lesson.

Examples:

- the Google adapter maps a Core Teacher to a renewable Google connection and
  maps Meet evidence to a Lesson;
- the Meta adapter sends an already-authorised notification command and reports
  provider delivery events;
- a Stripe adapter maps external customers and transactions to Core identities
  and Finance records without creating lessons;
- Hamnavaz optionally maps a directory profile to a Core Teacher.

Provider reads and writes must be explicit. Existing Amelia-dependent runtime
behaviour may remain temporarily in Delnavazan Enhancements, but new Platform
Core code does not acquire an Amelia data-model dependency or general-purpose
Amelia importer.

## 7. Event-driven internal workflows

Modules coordinate through versioned domain events and application services,
not direct database access across module boundaries. Illustrative events include:

- `lesson.scheduled`;
- `lesson.rescheduled`;
- `lesson.cancelled`;
- `attendance.absence_reported`;
- `attendance.confirmed`;
- `enrolment.sessions_remaining_changed`;
- `notification.requested`;
- `notification.delivery_updated`;
- `google.connection_invalidated`.

An event states a fact that already occurred. Commands request an action. Event
handlers must be retry-safe and use an idempotency key. A failed notification or
provider call must not roll back an already accepted attendance fact.

The first implementation may use WordPress-native hooks and a durable outbox,
but hook names are not themselves the domain model. Important external side
effects require durable attempt and completion records so a process crash does
not silently lose or duplicate work.

## 8. Data lifecycle

The platform distinguishes:

1. active canonical state used by current workflows;
2. historical business evidence that must remain queryable;
3. archived records hidden from normal operations but restorable;
4. minimum provider provenance retained for a defined operational, audit, or
   support purpose;
5. secrets with a shorter, revocable lifecycle;
6. data eligible for permanent deletion under an approved retention policy.

Archive is normally soft and auditable. Archiving a Lesson or Attendance record
must not silently erase payment evidence, delivery history, or legacy mappings.
Permanent deletion is an explicit, authorised retention operation and is not an
ordinary status transition.

## 9. Schema migrations

Application version and schema version are separate. Each migration must be:

- explicit and ordered;
- safe to retry or able to prove it completed atomically;
- bounded and observable;
- compatible with rollback or forward repair;
- unable to reinterpret external IDs as Core IDs;
- tested against an anonymised production-shaped dataset;
- backed up before production execution.

Manual initial data setup is separate from schema migration and authority
cutover. A schema migration must not make a provider write. The Platform does
not require a bulk Amelia import framework for the approved migration path.

## 10. Amelia retirement philosophy

Amelia is replaced through strangler-style migration:

- keep it installed and readable;
- manually recreate and validate the small active operational dataset in Core;
- retain historical Amelia exports outside operational Core when needed;
- compare each replacement with current production behaviour through controlled
  validation, without building a shadow synchronizer or parity engine;
- move one workflow's read or write authority at a time;
- keep an immediate rollback path;
- stop creating new Amelia coupling in new work;
- do not delete Amelia tables at initial cutover.

Retirement is a verified operational outcome, not a code-completion milestone.
It requires evidence that no active route, portal, automation, report, or support
procedure still depends on Amelia.

## 11. Hamnavaz relationship

The relationship is optional and directional:

```text
Core Teacher ── optional link ── Hamnavaz Profile
```

A Core Teacher represents the academy/business identity. A Hamnavaz Profile
represents directory and public-presentation state. Hamnavaz listing status,
verification lifecycle, public contact fields, biography, profile media,
discovery taxonomies, commercial profile fields, and SEO data stay in Hamnavaz.

Hamnavaz must also support independent directory teachers with no Core Teacher.
Linking must therefore be nullable, explicit, administrator-authorized,
capability-protected, audited, and one-to-one where present. Matching fields may
suggest a link but never establish one. Linking or unlinking does not create or
delete either identity.

## 12. Canonical Platform roadmap

These are Platform phases and do not renumber Hamnavaz phases.

1. **Phase 0 — Existing System Audit & Architecture:** completed audit, architecture, migration map, module boundaries, security baseline and roadmap.
2. **Phase 1 / 2A foundations:** implementation is merged through Phase 2A.2-K Canonical Term Foundation. The current exact state and next bounded phase are maintained in the continuity record; this historical roadmap must not be read as a claim that downstream Term mutation or Lesson authority already exists.
3. **Phase 2 — Core Data Setup & Cutover Preparation:** manually create and
   validate the initial Instrument/Course catalogue, Teachers, active Students,
   Enrolments, Terms, and required Lessons; prepare cutover controls without an
   Amelia importer.
4. **Phase 3 — Attendance Migration:** move attendance evidence, outcome,
   signed-action, review, archive, and reconciliation ownership onto Core Lesson
   identities while preserving proven behaviour.
5. **Phase 4 — Direct Google Integration:** move teacher Google ownership away
   from Amelia and implement the complete connect, refresh, disconnect, and
   revoke lifecycle against Core Teacher identity.
6. **Phase 5 — Notification Platform / WhatsApp Migration:** extract Meta
   transport and webhook handling, and move workflows away from Amelia hooks to
   Core/Academy/Attendance events.
7. **Phase 6 — Availability & Scheduling:** implement native teacher
   availability, recurring scheduling, rescheduling, timezone handling, lesson
   buffers, and capacity.
8. **Phase 7 — Native Teacher & Student Portals:** replace operational dependence
   on Amelia Employee and Customer panels with Core-authenticated portal flows.
9. **Phase 8 — Stripe Payments & Term Automation:** connect payment facts to the
   canonical Enrolment and Term lifecycles and controlled Lesson generation.
10. **Phase 9 — Finance / Teacher Reporting Migration:** move payability,
    statements, payouts, and reporting completely onto Core Lesson and
    Attendance identities.
11. **Phase 10 — Amelia Cutover & Retirement:** complete the final dependency
    audit, controlled authority cutover, observation period, deactivation, and
    eventual retirement while preserving approved legacy evidence.

The authority ledger, bounded workflow cutovers, rollback gates, Amelia exit
gates, and strangler pattern apply throughout relevant phases. Idempotent schema
migrations/events and controlled replacement comparisons remain requirements,
but do not reinstate the superseded Amelia importer, synchronizer, or parity
engine. These techniques do not replace or renumber the roadmap above.

Every phase has its own approval, runtime validation, rollback evidence, and PR.
After Core stabilisation, Hamnavaz Phase 4 resumes against this shared
architecture under its own approval.

## 13. Architectural non-goals

This architecture does not:

- authorise removal, deactivation, or modification of Amelia;
- require rewriting proven Google, Meta, attendance, or reporting algorithms;
- make WordPress user accounts mandatory business identities;
- turn Hamnavaz profiles into academy identities;
- define a public API or mobile application;
- commit to microservices, a specific queue product, or a non-WordPress runtime;
- copy entire provider payloads into Core without a retention purpose;
- build an automated Amelia importer, shadow synchronizer, or parity engine for
  the approved initial data setup;
- make Stripe, Google, Meta, WordPress, or Amelia a scheduling authority;
- merge scheduling state, attendance outcome, notification delivery, and
  payability into one status field;
- independently authorise later Platform implementation, deployment or authority cutover.
# Phase 2A.2-G authority boundary

`FinalAcceptanceService` is the sole writer for final arrangements. It serializes on the Booking Request and Proposal Family lineage, then revalidates current Student identity, capacity, and adult-self or minor-guardian authority before atomically appending the arrangement and all sibling outcomes. `ProposalService` observes the same Family lock and rejects issuance after final acceptance. The existing Booking Request privacy erasure path shares the request lock, so erasure and acceptance have deterministic commit-order semantics.

## Phase 2A.2-H canonical Enrolment boundary

Schema 14 separates preserved `legacy_phase1` Enrolments from the future `canonical_student_course_v1` aggregate. Canonical structural identity is Student + Course; nullable Teacher context is not Teacher Assignment. Accepted Service Arrangement provenance, applicable-slot uniqueness, reserved lifecycle/lineage fields, append-only history and protected applicability reads are foundation data only. Generic creation is closed. Readiness, conversion authority/idempotency, lifecycle transitions, Teacher Assignment, Terms, Lessons and every external integration remain later boundaries.

Schema 15 completed the separation of informational conversion readiness from the explicit atomic conversion command. Conversion revalidates the immutable final source graph under canonical lock order, serializes on a concrete Student + Course identity root, and records one authorised canonical Enrolment, its initial lifecycle event and immutable digest-only command evidence. Schema 16 then introduced separate Teacher Assignment authority, and Schema 17 introduced the read/storage-only canonical Term foundation. None of these phases creates Lesson, payment, scheduling, calendar, notification or Amelia authority.

## Phase 2A.2-L candidate boundary
Canonical Term mutation is isolated in `CanonicalTermAuthorityService` and its dedicated repository. The aggregate locks its canonical Enrolment before ordered Term/history rows. Creation starts at `authorised`; only authorised→current/cancelled and current→closed/cancelled are valid. Durable commands contain digests, canonical identifiers and result facts, never raw keys or PII. This candidate does not coordinate Enrolment, Teacher Assignment, Lesson, payment or scheduling lifecycle.

## Phase 2A.2-M0 canonical Enrolment lifecycle authority

Schema 19 makes the canonical Enrolment graph operational: `authorised -> current`, `current -> paused`, `paused -> current`, and closure from every non-terminal state. Closed is terminal. Lifecycle commands serialize through the Student–Course identity root and Enrolment row, retain applicability through pause, store immutable digest-only command evidence, and block closure while applicable canonical Term or Teacher Assignment authority exists. Canonical Lesson authority remains later; its resumption must add the corresponding non-terminal Lesson closure guard.

## Phase 2A.2-N canonical Lesson scheduling & Teacher capacity authority (candidate)

Schema 21 makes scheduled Teacher time authoritative. A scheduled canonical Lesson is a canonical Lesson in `authorised` state plus exactly one applicable canonical schedule version; Phase N adds no `scheduled` Lesson lifecycle state and never mutates Lesson lifecycle as a side effect. Schedule versions freeze the UTC interval, the explicit IANA timezone and local wall provenance, duration, buffer and the occupied end `[start, end + buffer)`. Teacher capacity is one concurrent canonical Lesson per Teacher, derived from applicable versions with no mutable reservation projection.

Scheduling serializes on a dedicated per-Teacher root acquired after the canonical Enrolment/Lesson/Assignment context and before the capacity decision, because `READ COMMITTED` disables gap locking and an empty overlap range offers no row to lock. Lesson completion/cancellation, Enrolment closure, Term close/cancel, Teacher Assignment replacement and Teacher archival all reject `active_future_schedule_exists` rather than cascading. Legacy Phase-1 scheduling stays legacy-only with no backfill and no dual-read.
