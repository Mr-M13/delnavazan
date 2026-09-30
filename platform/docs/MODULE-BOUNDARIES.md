# Delnavazan Platform Module Boundaries

> **Current-source reconciliation (2026-09-27):** Schema-31 Phase-W source is present on current
> `main`. It owns portal access artefacts and delegates absence evidence to the attendance owner; it
> does not confer business authority. The currently registered W HTTP surface is three option-gated
> public capability routes, not an authenticated Student/Teacher REST portal. See
> [Portal authorization registry](PORTAL-AUTHORIZATION-REGISTRY.md). This does not authorise public
> enablement, provider work, Theme work, deployment or cutover.

> **Phase 2A.2-W boundary (Schema 031, present on current `main`; implementation/topology record).** Portals owns only portal
> access artefacts. Its dependency direction is one-way: the registered public capability controller →
> `PortalCapabilityService` → `PortalOwnerPorts` and the owner read ports / Phase-P absence intake.
> Canonical business reads are delegated to owner read ports; no class under `src/Portals/` reads a
> canonical Lesson, schedule, delivery or attendance table directly. The only direct table access inside
> `src/Portals/` is portal-access storage plus the session-principal resolution reads in
> `PortalPrincipalResolver` (the Phase-F `teacher_principal_links`, `student_principal_links` and
> `student_acceptance_authority_grants` identity surfaces). Portals performs no provider call, no
> `platform_outbox` write and no Theme write. The only registered W HTTP surface is the three
> exact-option-gated public capability routes; the authenticated read ports and diagnostics are
> internal-only, there is no admin mutation UI, and no authenticated Teacher/Student portal route
> exists. See the [Portal authorization registry](PORTAL-AUTHORIZATION-REGISTRY.md). This is an
> implementation/topology record, not authorisation for review, deployment, public enablement or cutover.

> **Phase 2A.2-U historical implementation/review record (Schema 030; present on current `main` as an additive candidate tree, independent-review posture unchanged, not deployed or activated).** Finance owns payability, effective-dated teacher rates, per-Lesson rate/currency snapshots,
> teacher compensation statements, reconciliation read models and audited corrections — and nothing
> else. It adds no column to any existing table; it reads canonical Lessons, schedule versions, delivery
> outcomes, academy obligations, Terms, Enrolments, Teacher Assignments and the R1/R2/T
> commercial/payment facts through their owning validators and fails closed on a corrupt aggregate. It
> creates no ledger, journal, invoice, tax/VAT rule, payout, bank/IBAN/card detail, remittance, provider
> call, credential, notification delivery, public/Portal route or Theme change, and it mutates no
> upstream row: the only tables a Finance code path may write outside the twenty-one declared Finance
> tables are digest-only `platform_audit_events` evidence and the three identity-only §17
> `platform_outbox` intents, both insert-only inside the transaction of the Finance row they evidence.
> Five administrator-only capabilities exist, are repaired per capability, and no Teacher or Student
> role holds one.

> **Phase 2A.2-T historical implementation/review record (Schema 029; present on current `main` as an additive candidate tree, independent-review posture unchanged, not deployed or activated):** Core gains the provider-neutral payment-execution seam — the port, the request/outcome
> vocabulary, the provider registry, the execution command/attempt/result authority with its single
> mutable dispatch claim, the provider account and mapping registry, the provider-event intake and the
> authenticated-encryption secret vault. Integrations gains exactly one provider client surface
> (`src/Integrations/Payment/Stripe/`) implementing that port plus the public webhook route. Core never
> names a provider SDK class, endpoint, header or payload field; only an adapter seals or opens a dispatch
> descriptor; only the adapter registered for a provider may decrypt its own secret class. Neither module
> may create, mutate or delete a Term, Enrolment, Lesson, schedule, attendance outcome, academy
> obligation, funded allowance, protected claim, provider recurring model or notification record.

> **Phase 2A.2-V historical implementation/review record (Schema 27; present on current `main` as an additive candidate tree, independent-review posture unchanged, not deployed or activated):** the Integrations module owns exactly one provider integration surface — one Teacher
> consent lifecycle per provider code, one sealed credential per connection, provider reference
> mappings, a bounded Calendar/Meet projection seam, and provider-event ingestion into the Phase-P
> evidence seam — plus its append-only receipts and digest-only command evidence. A projection that
> cannot be acknowledged by its own port is recorded as `pending` and is promoted only by a separate
> acknowledged provider result; ingestion accepts only a delivery envelope a named trusted transport
> already authenticated, writes the receipt once and records admission as a separate appended handoff
> outcome after Phase P accepted the fact. Core depends on the
> four `Provider*Port` interfaces only and never imports `Delnavazan\Platform\Integrations\*`. The
> phase owns no Lesson, schedule, delivery, attendance, settlement, payment, notification or Theme
> storage: a projection mirrors an already-applicable canonical schedule version without mutating it,
> a conference reference never proves attendance, participant identity remains
> `CanonicalAttendanceIdentityService`, and canonical delivery/completion remain Phase O and Lesson
> authority. Google-specific names, scopes and payload shapes exist only inside the pure translation
> adapter, which performs no HTTP call, opens no OAuth client and reads no credential.

> **Phase 2A.2-R1 boundary (Schema 25, merged and closed):** the commercial authority owns purchase
> offers, pricing snapshots, promotions, account adjustments, obligations, provider-neutral payment
> evidence, funding derivation, bounded entitlements, Term funding plans, the Regular recurring
> pattern and current-Term protected capacity. It owns no Term, Lesson, schedule, delivery,
> attendance or provider storage: Term creation stays with Phase L, Lesson issuance with Phase M,
> scheduling with Phase N, and payment providers remain evidence sources only.

> **Phase 2A.2-R2 historical implementation/review record (Schema 26; present on current `main` as an additive candidate tree, independent-review posture unchanged, not deployed or activated):** the recurring layer owns exactly the cross-Term orchestration aggregates — recurring
> enrolment, renewal cycle, collection intent, recovery case, refund/reversal review case and
> continuous cross-Term protection — plus their append-only events and digest-only command evidence
> and capability-protected reads. It owns no price, offer, obligation, settlement, entitlement,
> funding plan, protected claim, Term, Lesson, schedule or delivery storage: every next-Term purchase
> is delegated through the existing `CommercialOfferService → CommercialPaymentService →
> CommercialCapacityService → CommercialTermFundingService →` Phase-L chain, capacity release is
> delegated to the R1 capacity authority under the same per-Teacher scheduling root, and provider
> confirmation arrives only as R1 `commercial_payment_evidence`. Notification intents are names
> published to the existing `platform_outbox` seam; delivery belongs to Phase S.

## 1. Boundary rule

Core owns business identity and canonical state. Modules collaborate through
application services, explicit read models, commands, and versioned domain
events. They do not reach into another module's tables or use an external
provider as an implicit shared domain model.

> **Current implementation boundary:** The ownership sections below describe the target modular architecture. At the Phase-M candidate boundary, canonical Enrolment identity is Student + Course, current Teacher authority is a separate Teacher Assignment, canonical Term has bounded creation/lifecycle authority, and canonical Lesson authority is separately capability-controlled. Scheduling, attendance, payment, calendar and external-provider authority remain excluded; target ownership must not be mistaken for implemented authority.

The dependency direction is inward toward Core contracts:

```text
Portals ───────┐
Academy ───────┤
Attendance ────┤
Notifications ─┼──> Core contracts and identities
Finance ───────┤
Hamnavaz ──────┘

Integrations and Legacy Adapters implement provider-facing ports.
Core never depends on those implementations.
```

## 2. Core

### Owns

- stable Teacher and Student identities;
- canonical Lesson identity and minimum lifecycle foundation;
- stable identifiers and audit metadata;
- Operational Exception lifecycle and exception-management conventions;
- schema/data migration coordinator;
- external/legacy-reference contract and mapping integrity;
- domain event envelope, idempotency contract, and durable publication boundary;
- common clock, actor, transaction, and archive conventions.

### May use

- WordPress storage/lifecycle infrastructure;
- provider-neutral interfaces implemented by other modules;
- explicitly published read models or commands.

### Must not

- call Meta, Google, Stripe, or Amelia APIs;
- query Amelia tables;
- derive identity from a provider ID, email, name, or WordPress user;
- own Hamnavaz public profile fields;
- own notification templates, attendance evidence, or finance reports;
- depend on a portal controller or theme.

## 3. Academy

### Owns

- Enrolment and Term educational relationships;
- lesson allocation/consumption policy;
- shared Instrument references and minimal Academy Course catalogue rules;
- term lifecycle and remaining-session facts;
- introductory/trial classification at the business level.

### May use

- Core Teacher, Student, and Lesson identities;
- Finance status through a documented eligibility service;
- scheduling commands that create or amend Core Lessons.

### Must not

- treat Amelia service/category IDs as canonical catalogue identity;
- send notifications directly;
- charge through Stripe directly;
- determine attendance from Google payloads;
- expose provider credentials.

## 4. Attendance

### Owns

- attendance aggregate per Lesson;
- absence and join-action policy;
- participant/click/manual evidence;
- overlap evaluation and review-required decisions;
- administrator review and correction audit;
- attendance-specific archive/read models.

### May use

- Core Lesson, Teacher, and Student identities;
- a Google evidence provider interface;
- domain events to request follow-up notifications.

### Must not

- own Teacher or Student identity;
- change Lesson scheduling state as a side effect of attendance;
- mark attendance complete because a join link was clicked;
- call Meta directly;
- decide financial rate or issue payment;
- query Amelia directly after the relevant adapter migration.

## 5. Notifications

### Owns

- notification workflow definitions and versions;
- eligibility, schedule, idempotency, and retry policy;
- rendered template parameter snapshots;
- notification/attempt/delivery lifecycle;
- channel-independent diagnostics and operational read models.

### May use

- Core and Academy read models needed by an approved workflow;
- Attendance events such as absence reported;
- provider-neutral transport interfaces from Integrations.

### Must not

- query Amelia directly after migration;
- own student/teacher identity or phone/email source-of-truth;
- let a Meta adapter decide business eligibility;
- roll back a business event when delivery fails;
- mark production history from a controlled test send.

## 6. Integrations

### Owns

- provider clients and protocol translation;
- Google OAuth/refresh/revoke and Meet API access;
- Meta Graph transport and webhook authentication/normalization;
- Stripe API/webhook translation;
- provider health, rate-limit, and redacted error handling;
- provider-specific references and credentials.

### May use

- stable Core entity IDs passed through explicit commands;
- integration-specific storage linked to those IDs;
- callback interfaces that submit verified provider facts to an owning module.

### Must not

- create or merge Core identities independently;
- let a Stripe event insert a Lesson directly;
- let a Google response set final attendance without Attendance policy;
- let a Meta webhook update an unknown notification/message;
- expose reusable secrets outside the adapter;
- make provider IDs authorization credentials.

## 7. Finance & Reporting

### Owns

- payability policy and audited overrides;
- effective-dated teacher rates and per-Lesson rate/currency snapshots;
- statement/report calculations;
- finance-specific reconciliation and read models;
- references to Stripe payment facts where applicable.

### May use

- Core Teacher, Student, Lesson, and Term identities;
- final Attendance outcomes;
- provider-neutral payment facts from Stripe integration;
- approved external/legacy references during controlled reconciliation.

### Must not

- create or reschedule Lessons;
- reinterpret Amelia category ID `3` as a permanent domain rule;
- alter Attendance outcomes;
- use teacher email as canonical identity;
- hide introductory lessons merely because they are non-payable.

## 8. Portals

### Owns

- authenticated student/teacher/admin presentation and workflow orchestration;
- session-to-Core-principal resolution;
- accessible forms, confirmations, and object-level access checks;
- account invitation/onboarding experience once Core identity is authoritative.

### May use

- application services exposed by Core and feature modules;
- provider-neutral authentication/mapping services;
- short-lived action capabilities issued by the owning module.

### Must not

- query module or Amelia tables directly;
- rely on browser local storage as identity authority;
- infer Teacher/Student identity from email alone;
- own business state transitions;
- reproduce brittle Amelia DOM/private-state coupling as a permanent design.

## 9. Hamnavaz

### Owns

- directory teacher/profile data;
- public eligibility and verification lifecycle;
- Country → normalized City → Instrument discovery data;
- public biography, media, contact allowlist, commercial profile fields, cards,
  profiles, routing, and SEO presentation.

### May use

- an optional, explicit Core Teacher link;
- approved public or portal services that operate on that linked identity;
- its existing private WordPress CPT/taxonomy architecture.

### Must not

- become the canonical academy Teacher record;
- require every directory teacher to exist in Core;
- copy all public/verification/commercial fields into Core;
- infer the link from name, email, phone, or WordPress author;
- expose Core operational state through public profiles.

## 10. Legacy Adapters

### Owns

- narrowly scoped transitional runtime bridges when a replacement phase cannot
  yet remove an existing Amelia dependency;
- translation of explicitly supported legacy runtime events into versioned Core
  commands/events;
- temporary Amelia session/notification hook bridges owned outside Core.

### May use

- Amelia tables/APIs/hooks only when a later bounded runtime-replacement brief
  explicitly requires that temporary bridge;
- explicit Core application services without exposing Amelia-shaped models;
- explicit feature flags and cutover configuration.

### Must not

- become a permanent domain layer;
- become a general Amelia importer, synchronizer, or parity engine;
- write to Amelia unless a later phase explicitly authorises one narrow path;
- allow Core to call back into Amelia;
- silently merge Core people;
- authorize access using an Amelia ID alone;
- delete Amelia tables or source data.

## 11. Allowed service boundaries

| Caller | Allowed boundary | Example |
| --- | --- | --- |
| Phase 2 administrator setup | Core application services | Manually create and validate the approved catalogue, identities, Enrolments, Terms, and required Lessons |
| Academy | Core exception service | Surface an ambiguity requiring human judgement without treating a normal validation error as an exception |
| Academy | Core lesson command service | Allocate and schedule a Lesson for an approved Enrolment |
| Attendance | Core lesson read service | Confirm participants and schedule for an attendance evaluation |
| Attendance | Google evidence port | Request normalized conference/participant intervals for a Lesson |
| Notifications | Meta transport port | Submit an authorised template send command with an idempotency key |
| Integrations/Meta | Notifications delivery service | Submit a verified, normalized provider delivery event |
| Finance | Attendance outcome read model | Calculate payable lessons without changing attendance |
| Portals | Feature application service | Submit a capability-protected absence command |
| Hamnavaz | Core teacher-link service | Resolve an explicitly linked Core Teacher without exposing private Core data |

## 12. Forbidden dependency examples

- Core calling the Meta API.
- Attendance joining directly to Amelia user tables after migration.
- Notifications scanning Amelia appointments after its Core event cutover.
- Stripe integration inserting Lessons or Enrolments.
- Google integration marking a Lesson attended directly.
- Finance updating an Attendance outcome.
- Portals reading encrypted Google or Meta tokens.
- Hamnavaz making a listing active because a Core Teacher is active.
- Legacy Adapter becoming the only place where Lesson lifecycle rules live.

## 13. Conceptual event flows

### Scheduled lesson reminder

```text
Academy/Core commits Lesson schedule
  → publishes lesson.scheduled
  → Notifications evaluates versioned reminder policy
  → records Notification + idempotency key
  → Meta adapter sends approved template
  → Meta webhook is verified and normalized
  → Notifications records delivery progression
```

### Student absence

```text
Signed capability resolves to Lesson
  → Attendance confirms current eligibility
  → records absence_reported atomically
  → publishes attendance.absence_reported
  → Notifications independently requests teacher/admin alerts
  → delivery failure cannot undo the absence fact
```

### Google attendance reconciliation

```text
Reconciliation selects due Core Lesson
  → Attendance requests normalized Meet evidence
  → Google adapter fetches provider records
  → Attendance evaluates assigned teacher/student overlap
  → attended or review_needed is recorded with evidence
  → Finance and reporting consume the resulting event/read model
```

### Manual Core setup and Amelia coexistence

```text
Administrator uses an approved setup checklist
  → creates the small Core catalogue and active academy records manually
  → records only required provider mappings with audited provenance
  → second-person review validates counts and relationships
  → existing Amelia-dependent runtime stays in Delnavazan Enhancements
  → later phases replace one bounded capability at a time
```

## 14. Enforcement expectations

An approved implementation must make these boundaries visible in namespaces,
directories, interfaces, tests, database access ownership, and code review.
Documentation alone is not sufficient evidence that a boundary is enforced.

## Phase 2A.2-M0 candidate boundary

Academy owns explicit canonical Enrolment lifecycle mutation. The authority neither derives state from payment/Teacher/schedule facts nor mutates Term or Teacher Assignment authority. Closure only validates and rejects stranded applicable subordinate authority. Canonical Lesson storage and authority remain outside M0.

## Phase 2A.2-N candidate boundary

Academy owns canonical Lesson scheduling and Teacher occupied-time authority. It owns exactly one applicable schedule version per scheduled canonical Lesson, the append-only schedule event chain, digest-only scheduling command evidence and the per-Teacher serialization root. Teacher occupancy is derived from applicable versions; no module may introduce a second, mutable capacity or reservation counter.

Teacher availability remains an upstream fact and is consumed as a constraint, never as occupancy authority. Teacher Assignment remains the sole current-Teacher authority; scheduling snapshots the recorded Assignment and refuses stale or replaced Assignments. No module may treat Amelia, Google Calendar, Google Meet, Meta/WhatsApp, Stripe or any other provider as canonical scheduling authority, and no module may cascade schedule release from Lesson, Enrolment, Term, Assignment or Teacher lifecycle changes.

The cross-phase guards (Lesson completion/cancellation, Enrolment closure, Term close/cancel, Assignment replacement, Teacher archival) validate the affected schedule aggregates before deciding, so corrupted scheduling state blocks the consuming authority instead of silently approving or stranding future Teacher time.
