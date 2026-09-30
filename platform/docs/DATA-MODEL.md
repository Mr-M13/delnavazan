# Delnavazan Platform Conceptual Data Model

> **Current-source reconciliation (2026-09-27):** the reviewed local `main` baseline at
> `5bd376881769b15a27f5b20a5c02cef5bad0397c` (tree `f6dac0bd978e62ecef3247a5f64e8957b1b96d5a`)
> declares **Schema 33**. The formerly recorded `4ccd43c` source identity is a historical review
> snapshot only, not the current HEAD or checkout.
> Historical Schema 32 comprises Schema 031
> `031_portal_facing_services_principal_authorization` (portal capability roots, capabilities,
> capability events/commands, public action events and access denials) plus the additive Schema 032
> `032_notification_communications_authority` (notification workflow/template/snapshot/notification/
> event/command/attempt/delivery/suppression/tombstone storage and the `platform_outbox` extension).
> The portal tables are access artefacts;
> they do not become canonical Lesson, schedule, delivery or attendance truth, and the notification
> storage is not delivery or provider authority. Schema 33 migration
> `033_core_dataset_technical_prerequisites` adds five bounded readiness-evidence tables:
> `core_dataset_provenance`, `core_dataset_reconciliation_runs`,
> `core_dataset_reconciliation_findings`, `core_dataset_operator_commands` and
> `core_dataset_corrections`. It creates no Core domain row or backfill; its migration verifier runs
> before its ledger entry and schema-option advance. The precise fields,
> route gates and prohibited data are recorded in the
> [Portal authorization registry](PORTAL-AUTHORIZATION-REGISTRY.md). Schema-25/26 and Schema-32 state
> statements below are retained as provenance.

> **Phase 2A.2-U storage (Schema 030, candidate) — not authoritative until independently reviewed and
> merged:** finance, payability, effective-dated teacher rates, per-Lesson rate/currency snapshots,
> teacher compensation statements, reconciliation and audited corrections. Twenty-one additive tables in
> migration `030_finance_payability_rate_statement_authority`: the immutable singleton root
> `dzn_finance_policy_roots` (the global policy serialisation root) and the lazily created per-Teacher
> `dzn_finance_teacher_roots`; the versioned `dzn_finance_policies` (immutable in value, one auditable
> `status` column, unset as the absence of a covering version) with its `dzn_finance_policy_commands`
> result store; `dzn_finance_teacher_rates` (immutable identity/amount/currency/`effective_from`, exactly
> three mutable columns — `effective_until`, `status`, `active_slot`) with its append-only
> `dzn_finance_teacher_rate_events` and `dzn_finance_teacher_rate_commands`; the immutable
> `dzn_finance_lesson_snapshots` (one per canonical Lesson, with its recorded rate/currency amount and
> derivation digest) with the append-only `dzn_finance_snapshot_corrections` chain and
> `dzn_finance_snapshot_commands`; the append-only `dzn_finance_payability_evaluations` chain with
> `dzn_finance_payability_overrides` and `dzn_finance_payability_commands`; `dzn_finance_statements`
> (mutable only in `state`, the supersession edge and the one-time `issued_at`/`issued_by` evidence) with
> immutable `dzn_finance_statement_lines`, append-only `dzn_finance_statement_events` and
> `dzn_finance_statement_commands`; the append-only `dzn_finance_reconciliation_runs`,
> `dzn_finance_reconciliation_findings` and `dzn_finance_reconciliation_commands`; and the sole freely
> mutable `dzn_finance_exceptions`. Every amount is an exact integer number of minor units with an
> explicit ISO-4217 currency; there is no float, no rounding, no conversion and no per-item FX anywhere in
> the phase, and the phase stores no student price, obligation, settlement, payout, bank or tax fact.

> **Phase 2A.2-T storage (Schema 029, candidate) — not authoritative until independently reviewed and
> merged:** provider-neutral payment execution only. Fifteen additive seam tables in migration `028` plus
> the one-table decision-claim aggregate of migration `029_payment_event_decision_claim_authority`
> (sixteen in total, [C10-1]): `dzn_payment_provider_accounts`
> and its append-only account event/command pair, `dzn_payment_provider_objects` and its append-only
> object event/command pair, `dzn_payment_provider_secrets` (authenticated ciphertext plus its nonce and
> versions, one active row per declared scope), `dzn_payment_execution_commands` (immutable, with no
> terminal column of its own), `dzn_payment_execution_attempts` and `dzn_payment_execution_results`
> (append-only, at most one of each per command, `UNIQUE command_result`), `dzn_payment_execution_dispatches`
> (the phase's only mutable execution row — the durable dispatch claim and the only home of the
> adapter-sealed descriptor), `dzn_payment_provider_event_receipts`, `dzn_payment_provider_events`,
> `dzn_payment_provider_event_decisions` and `dzn_payment_provider_event_decision_claims` (the mutable
> per-event decision claim, whose lease is the bounded window its owner re-proves and renews immediately
> before every R1/R2 work unit it runs — and, since correction round 11, from inside each unit's own
> transaction before every statement that unit runs, so the row is held for the whole transaction a unit
> runs in and a unit whose window closed mid-transaction is rolled back rather than committed in part).
> Every table declares `id bigint unsigned NOT NULL AUTO_INCREMENT`
> with `PRIMARY KEY(id)` and `uid char(26) NOT NULL` with `UNIQUE uid(uid)`; every `*_id` reference is
> `bigint unsigned` with a declared named index and a declared parent — a Phase-T table or a frozen
> external authoritative parent (`students`, `commercial_offers`, `commercial_purchases`,
> `commercial_offer_obligations`, `commercial_payment_evidence`, `collection_intents`, `renewal_cycles`,
> `recurring_enrolments`). No raw provider reference, credential, card field or IBAN is stored anywhere;
> the only place a raw provider reference can exist is the adapter-sealed descriptor ciphertext, which Core
> cannot open. No column was added to any commercial, R1 or R2 table. See
> [PHASE-2A-2T-PAYMENT-EXECUTION-SEAM-STRIPE-ADAPTER-CONTRACT.md](PHASE-2A-2T-PAYMENT-EXECUTION-SEAM-STRIPE-ADAPTER-CONTRACT.md).

> **Phase 2A.2-V storage (Schema 27, candidate) — not authoritative until independently reviewed and
> merged:** provider-neutral integration metadata only. `dzn_integration_connections` (one active
> Teacher+provider connection per lifecycle generation, mutable `connection_state`/`connection_version`,
> nullable `active_slot`, `identity_digest` set only after identity/scope validation),
> `dzn_integration_credentials` (`state`, `key_version`, `cipher_version`, `nonce`, `ciphertext` and a
> quarantine/revocation instant — never a plaintext token column),
> `dzn_integration_oauth_authorizations` (one-time consent intent bound to its exact `connection_id`:
> `state_digest`, `verifier_digest`, client/scope/redirect/principal binding, `expires_at`, write-once
> `consumed_at`/`consumption_result`, and an explicit `rejected` outcome for a superseded attempt),
> `dzn_provider_identity_mappings` (keyed `subject_digest` → Core Teacher, versioned with
> `active_slot`/`superseded_by_mapping_id`), `dzn_provider_calendar_event_mappings` and
> `dzn_provider_meeting_mappings` (keyed provider digests → exact Lesson + canonical schedule
> version, with the copied canonical interval and wall-clock provenance; a projection may be `pending`
> until its provider result is acknowledged), append-only
> `dzn_provider_ingest_events` (immutable receipt in a `received` state: separate provider
> `occurred_at` and local `received_at`, plus the authenticating `transport` and its
> `proof_reference_digest`), append-only `dzn_provider_ingest_outcomes` (the handoff outcome of each
> attempt — the only place an admission is ever recorded, with its Phase-P result digest),
> append-only `dzn_provider_event_conflicts` and append-only digest-only
> `dzn_provider_integration_commands`. There is no provider-specific column, no raw provider payload,
> no raw command key and no Lesson, schedule, delivery, attendance, notification or payment storage.

> **Phase 2A.2-R1 storage (Schema 25, merged and closed on `main`):** commercial purchase storage —
> the versioned runtime commercial policy registry (class-B keys only), product/price, promotions and
> immutable redemptions, account-specific adjustments with append-only events, immutable purchase
> offers with their pricing snapshot, applied adjustments, applied policy versions and ordered
> payment obligations, purchases, bounded entitlements, Term funding plans, provider-neutral payment
> evidence/facts/settlements, the canonical Regular recurring pattern, current-Term protected
> capacity claims with per-interval state, the commercial exception registry and digest-only
> commercial command evidence. Money is an exact integer number of minor units with an explicit
> currency. There is no provider-specific column, no Term/Lesson/schedule storage and no cross-Term
> recurring-enrolment storage.

> **Phase 2A.2-R2 storage (Schema 26, candidate) — not authoritative until independently reviewed and
> merged:** additive cross-Term recurring storage only: `dzn_recurring_enrolments` (frozen
> `currency`/`region_code`, mutable audited `collection_mode`, bounded `active`/`suspended`/`closed`
> state, unique `enrolment_id`), `dzn_renewal_cycles` (progression-derived immutable
> `boundary_derived_at`, nullable `guarantee_deadline_at`, the cycle's frozen `collection_mode` and
> whole-Term `amount_minor` snapshot copied from the accepted R1 offer, nullable `next_term_id`),
> `dzn_collection_intents` (R1 `obligation_id`, provider-neutral `kind`/`state`, nullable `charge_at`
> and `failure_reason_code`), `dzn_recovery_cases`, `dzn_refund_review_cases` (nullable
> `academic_consequence`, always NULL) and `dzn_recurring_protections` (R1 `claim_id`), each with an
> append-only event table and a digest-only command table. There is no provider column, no Term,
> Lesson, schedule or notification storage, and no mutable attempt counter — recovery attempts are
> append-only events.

## Historical pre-Schema-33 state — canonical Lesson authority with scheduling and delivery outcomes

The following is a historical pre-Schema-33 source snapshot: authoritative `main` was Schema 25 (Phase 2A.2-R1, merged and closed), with Schema 26 (Phase 2A.2-R2) the active candidate. The current reviewed source baseline is Schema 33 as recorded above. Phase 2A.2-P adds Schema 23 attendance intake/review storage that records evidence and review only and never becomes canonical delivery truth: a durable provider-neutral participant identity registry (`dzn_canonical_attendance_participant_mappings`, the only authority that decides which canonical Teacher or Student a provider account belongs to), immutable prospective cutover policies with one policy per cutover instant (`UNIQUE cutover_instant`), per-occurrence intake cases bound to their exact cutover policy, append-only evidence/decisions/anomalies, durable refused-conflict receipts and digest-only command evidence. Phase 2A.2-Q adds Schema 24 post-intro continuation storage that records the continuation decision and a bounded pre-payment hold only: `dzn_canonical_continuation_slot_authorities` (the explicit administrator-authorised first regular slot, the only source that may justify capacity, because an audit confirmed no existing proposal/arrangement source carries a recurring future class), `dzn_canonical_continuation_cases` (source introductory Lesson/occurrence, Student, Teacher, Course, optional Enrolment/Assignment/arrangement lineage, rule version, current decision), append-only `dzn_canonical_continuation_decisions` and `dzn_canonical_continuation_interventions`, the single `dzn_canonical_continuation_reservations` hold (bound `slot_authority_id`, frozen interval, frozen `expires_at`, `active`/`expired`/`released`), and digest-only `dzn_canonical_continuation_commands`. It creates no payment, Term or Lesson storage. Phase 2A.2-M additively separates existing `legacy_phase1` Lessons from `canonical_term_lesson_v1`. A canonical Lesson is identified by its canonical Term plus an immutable server-owned sequence. It snapshots the exact Teacher Assignment and Teacher identity at issuance, while canonical Term remains Teacher-neutral.

Schema 19 is the merged M0 canonical Enrolment lifecycle authority; Schema 20 is the merged Phase-M canonical Lesson authority; Schema 21 is the merged Phase-N canonical Lesson scheduling and Teacher capacity authority. A Lesson lifecycle remains only `authorised → completed|cancelled`; scheduling is a sibling fact (one applicable canonical schedule version) and never a Lesson state. Legacy `draft`, `awaiting_payment`, `active`, `completed`, `cancelled`, `archived`, and `payment_state` values retain only their Phase-1 meaning and are not canonical authority.

Canonical allocation-origin facts are 12 standard Lessons and 2 eligible replacements. Phase M derives permanent issued-authority consumption from canonical Lesson rows; it keeps no mutable counters. Cancellation does not restore allocation. Replacement requires one eligible origin and is unique per origin. Lifecycle and command evidence are append-only and digest-only.

**Phase 2A.2-O (Schema 22, merged and closed).** A provider-neutral canonical delivery/attendance outcome authority adds one applicable outcome per Lesson (with append-only supersession), immutable evidence provenance bound to the exact canonical schedule version and occurrence interval, digest-only command evidence and protected reads. `completed` means delivered: a `teacher_non_delivery` or `review_required` outcome blocks completion, while `student_no_show` and `interruption` do not. A final occurrence outcome only becomes recordable after the occurrence has ended. `student_no_show` consumes the Lesson and creates no entitlement. Teacher/academy non-delivery — recorded directly or by an explicit append-only reconciliation of a historical completion (O-D8) — creates an **academy-owed occurrence** in its own authority, one immutable row per source occurrence, never a Phase-M `replacement` Lesson and never counted against the two-per-Term allowance; it survives Term closure. An advance Teacher/academy cancellation (`academy_unavailable`) creates the same obligation, while a Student-requested cancellation never does. Cancellation remains a pre-occurrence scheduling fact and is refused once the occurrence has started. There is no schedule mutation, payment, renewal, notification or external-provider authority.

## Teacher Assignment foundation (Schema 16)

`teacher_assignments` is the first-class current-Teacher authority for a canonical Enrolment. It is not `enrolments.teacher_id`, which remains historical final-arrangement context. Zero rows is valid and migration 016 does not backfill. One nullable-slot unique key on `(enrolment_id, applicable_slot)` permits history while enforcing at most one applicable row. Ordered predecessor lineage prohibits a staged future replacement.

`teacher_assignment_lifecycle_events` retains append-only initial, replacement, end, and cancellation evidence. The initial event references retained Accepted Service Arrangement and Availability Assent provenance. A replacement records only assignment-specific evidence route/basis/channel, a digest of the opaque reference, and timestamps/actors; it copies no names, contact data, messages, addresses, provider data, or erased PII. `teacher_assignment_commands` retains immutable command-domain, operation, result IDs, and digest-only key/payload evidence.

Applicable Assignment state is `assigned`; terminal states are `replaced`, `ended`, and `cancelled`. Replacement terminates the predecessor and creates the successor atomically. It does not alter the Enrolment's `authorised`, `current`, `paused`, or `closed` lifecycle.

## Proposal provisional acceptance evidence (Schema 11)

`proposal_acceptance_events` is an InnoDB append-only evidence table. Each row binds the Booking Request and Coordination Case to exact copied Proposal Family, Option and immutable Version identifiers/fingerprint, with `event_kind=accepted_pending_conditions` and `accepting_subject_state=authority_unresolved`. It stores closed-channel, opaque evidence provenance and digest-only acceptance command idempotency. It holds no contact PII, Student identity, final-acceptance lifecycle, conversion or downstream operational authority.

## 1. Purpose

This document defines canonical concepts and invariants. The approved Phase 1
storage direction is dedicated WordPress-prefixed InnoDB custom tables using
`$wpdb->get_charset_collate()`: `{prefix}dzn_teachers`,
`{prefix}dzn_students`, `{prefix}dzn_instruments`, `{prefix}dzn_courses`,
`{prefix}dzn_enrolments`, `{prefix}dzn_terms`, `{prefix}dzn_lessons`,
`{prefix}dzn_lesson_schedule_versions`,
`{prefix}dzn_operational_exceptions`, and
`{prefix}dzn_teacher_profile_links`. It does not authorise creating them.

Application and schema versions are separate. Defined business fields use
defined columns; generic JSON metadata columns are not a substitute for Core
business fields. Final column types, indexes, and implementation mechanics
belong to the approved Phase 1 implementation brief.

## 2. Shared rules

- Every Core entity has an internal numeric primary key and an immutable opaque
  ULID-style UID, both independent of WordPress and external providers.
- Operational entities use human-readable reference codes: Teacher `DZN-TCH-`,
  Student `DZN-STU-`, Instrument `DZN-INS-`, Course `DZN-CRS-`, Enrolment
  `DZN-ENR-`, Term `DZN-TRM-`, and Lesson `DZN-LSN-`. Numeric padding is a
  minimum display format, not a maximum; `DZN-LSN-001842` is one example.
  Numeric IDs, UIDs, and reference codes never authorize access.
- Public actions use independent opaque or signed, purpose-bound, revocable
  capabilities.
- External identifiers are represented by mappings and are never silently used
  as Core primary keys.
- Mutable attributes such as email, phone, display name, and username are not
  identity keys.
- Canonical Lesson timestamps are stored in UTC. Teacher, Student, and recurring
  schedules use explicit IANA timezones, retaining wall-clock intent where it
  matters.
- Calendar/locale presentation is separate from timezone, supports at least
  Gregorian and Persian/Jalali, and never changes a canonical instant.
- Normal operational deletion uses soft archive. Permanent deletion follows an
  explicit retention and authorization policy.
- Required provider provenance is retained only for an explicit operational,
  audit, or support purpose.
- Created, updated, archived, merged, linked, and corrected actions retain
  auditable actor/source metadata.

## 3. Relationship overview

```text
Instrument 1 ─── * Course 1 ─── * canonical Enrolment * ─── 1 Student
                                  │
                                  ├── 0..* Teacher Assignment ─── 1 Teacher
                                  │
                                  * Term (no Teacher identity)
                                  │
                                  * standard/replacement Lesson
                                    ├── 0..1 Attendance aggregate
                                    ├── 0..* Schedule Versions
                                    ├── 0..* Notifications
                                    └── 0..* Legacy / provider references

Student 1 ─── 0..* introductory Lesson (no Enrolment or Term required)
Any Core entity 1 ─── 0..* OperationalException

Teacher 1 ──────── 0..* GoogleConnection
Teacher 1 ──────── 0..1 optional Hamnavaz Profile link

Any mapped Core entity 1 ──────── 0..* LegacyReference
```

## 4. Teacher

### Responsibility

Represents a stable Delnavazan academy teacher identity. It is not a Google
account, Amelia employee, WordPress user, or Hamnavaz profile.

### Conceptual attributes

- internal numeric primary key, immutable opaque ULID-style `teacher_uid`, and
  required `DZN-TCH-` human reference code;
- preferred/display name and normalized administrative name components;
- primary contact points needed for academy operations;
- locale, calendar preference, and explicit IANA timezone with provenance;
- lifecycle state: `active`, `inactive`, or `archived`;
- created, updated, and archived audit metadata.

Provider accounts and WordPress users are mappings. Sensitive contact data is
not automatically public.

### Invariants

- A Teacher survives a changed email or replaced Amelia employee record.
- Matching data may suggest a duplicate but never merges Teachers automatically.
  A merge is an explicit, administrator-authorized, audited action that redirects
  approved mappings to one survivor.
- Academy teachers default to country Iran, `Asia/Tehran`, and Persian
  locale/calendar; an authorized user may change each value.
- Academy operational state stays separate from the Hamnavaz listing lifecycle.
- A teacher may have multiple historical provider references and Google
  connections, but only approved active connections may be used.

## 5. Student

### Responsibility

Represents a stable learner identity independent of Amelia, Stripe, and
WordPress authentication.

### Conceptual attributes

- internal numeric primary key, immutable opaque ULID-style `student_uid`, and
  required `DZN-STU-` human reference code;
- preferred/display and administrative name fields;
- private contact channels;
- human-readable country and city, stored separately from resolved timezone;
- locale, calendar preference, and explicit IANA timezone with provenance;
- lifecycle state: `active`, `inactive`, or `archived`; consent state remains
  separate;
- optional portal-account link;
- created, updated, and archived audit metadata.

### Invariants

- A Student survives a changed email, phone, Stripe customer, Amelia customer,
  or WordPress user.
- Duplicate detection may propose matches but never merges records
  automatically. A merge requires an explicit, administrator-authorized,
  audited action.
- Stripe and Amelia mappings do not grant portal or record access.
- Notification consent and channel deliverability are separate from identity.
- Changing a Student timezone through Profile/Preferences changes display only;
  it never reschedules an existing Lesson or changes a recurring schedule.

## 6. Instrument

### Responsibility

Represents shared Delnavazan reference data for a musical instrument. Instrument
identity is not owned by an Amelia service, an Academy Course, or a Hamnavaz
taxonomy term, although explicit mappings may connect them.

### Minimum conceptual attributes

- internal numeric primary key, immutable opaque ULID-style UID, and required
  `DZN-INS-` human reference code;
- canonical display name and normalized administrative key;
- lifecycle/archive and audit metadata.

## 7. Course

### Responsibility

Represents the Academy teaching product. A Course references one Instrument and
is the catalogue item selected by an Enrolment. Phase 1 keeps its fields minimal
and does not mirror Amelia service configuration.

### Minimum conceptual attributes

- internal numeric primary key, immutable opaque ULID-style UID, and required
  `DZN-CRS-` human reference code;
- required `name_fa` and `name_en`;
- required `instrument_id`;
- required `course_type` (`standard` or `introductory`);
- required `status` (`active`, `inactive`, or `archived`);
- required `default_duration_minutes` and `default_buffer_minutes`;
- audit/archive metadata.

Pricing, location, Stripe information, curriculum, and teacher-specific
commercial configuration are not Phase 1 Course fields.

## 8. Enrolment

### Schema 14 canonical foundation

Phase 1 rows retain their original Student–Teacher–Course meaning, exact legacy status, and historical Teacher value under `record_model=legacy_phase1`; no status is mapped into the canonical lifecycle.

The new canonical structural identity is Student + Course under `record_model=canonical_student_course_v1`. Its inherited Phase 1 `status` column uses only the neutral sentinel `canonical`; `lifecycle_state` is the sole canonical lifecycle authority. Teacher is nullable context only. Canonical lifecycle is `authorised`, `current`, `paused`, then `closed`; applicable states carry `applicable_slot=1`, while closed history and legacy rows carry `NULL`. Accepted Service Arrangement provenance is required for a canonical row and unique. The database unique key on Student + Course + applicable slot is the final Phase H conflict boundary.

Lifecycle evidence is append-only. A predecessor may reserve only `successor`, `return_after_closure`, `correction`, or `distinct_concurrent_service`; those meanings neither bypass conflict review nor create operational authority. Booking Request contact PII is not copied. Schema 14 provides no ordinary canonical creator, readiness decision, conversion, Teacher Assignment, Term or Lesson authority.

### Schema 15 conversion evidence

Phase 2A.2-I added `enrolment_identity_roots` as a state-free concrete `(student_id, course_id)` serialization root and `enrolment_conversion_commands` as immutable conversion evidence. The command table uniquely binds a digest-only idempotency key to one canonical payload, Accepted Service Arrangement and resulting Enrolment; it has no mutable status or `updated_at`.

Explicit conversion is the only ordinary canonical writer. It copies exact final arrangement Student/Course identity and frozen Teacher context, writes lifecycle state `authorised`, and appends the sequence-1 conversion event. A return after clean closure history links to the unambiguous most-recent closure-evidenced predecessor. No Booking Request contact PII or operational authority is added.

### Historical Phase-1 model

The responsibility, attributes and invariants below record the original Phase-1 Enrolment model. They do not override the current Student + Course canonical identity, separate Teacher Assignment authority, or retained historical meaning of canonical `enrolments.teacher_id`.

### Responsibility

Represents the continuing relationship between one Student, one Teacher, and one
Course. It is independent of any single payment, renewal cycle, or lesson block.

### Conceptual attributes

- internal numeric primary key, immutable opaque ULID-style `enrolment_uid`,
  and required `DZN-ENR-` human reference code;
- `student_id` and `teacher_id`;
- required `course_id`;
- start date and optional end date;
- lifecycle state: `draft`, `active`, `paused`, `ending`, `completed`,
  `cancelled`, or `archived`;
- created, updated, archived, and decision provenance.

### Invariants

- An Enrolment belongs to exactly one Student, one Teacher, and one Course at a
  time.
- Reassignment creates an audited transition rather than rewriting history.
- A new Term does not create a new Enrolment when the continuing relationship is
  unchanged.

## 9. Term

> **Current-state clarification:** The lifecycle and payment-oriented material below documents the Phase-1 legacy model. The authoritative Schema 17 Phase-K boundary is defined above. It must not be used to restore `awaiting_payment`, `payment_state`, generic archive, or Teacher identity as canonical Term authority.

### Responsibility

Represents one bounded lesson-allocation, payment, and renewal cycle within an
Enrolment. Enrolment and Term are separate first-class entities from Phase 1.

### Conceptual attributes

- internal numeric primary key, immutable opaque ULID-style `term_uid`, and
  required `DZN-TRM-` human reference code;
- required `enrolment_id`;
- allocated lesson count and consumption policy;
- start/end dates and lifecycle state: `draft`, `awaiting_payment`, `active`,
  `completed`, `cancelled`, or `archived`;
- created, updated, archived, and decision provenance.

### Invariants

- A Term belongs to exactly one Enrolment.
- Lesson allocation and payment state are related but distinct; payment does not
  create a Lesson directly.
- Remaining-session calculations come from explicit allocation and canonical
  Lessons, not an untraceable provider count.
- Introductory/trial teaching is modelled explicitly rather than permanently
  depending on Amelia category ID `3`.
- Standard allocation defaults to 12 Lessons and two eligible replacement
  Lessons per Term. These are policy defaults, not immutable database constants.
- An eligible replacement belongs to the same Term and must be scheduled and
  used before that Term closes. Unused entitlement expires at closure and does
  not carry into the next Term without an explicit, audited administrator
  override.

### Candidate lifecycles

Enrolment and Term each have their own approved lifecycle above. There is no
combined Enrolment/Term status. Future migrations may deliberately extend the
initial vocabulary.

## 10. Lesson

### Responsibility

Represents one operational teaching occurrence and is the centre of scheduling,
attendance, notifications, Google evidence, finance, and reporting.

### Conceptual attributes

- internal numeric primary key, immutable opaque ULID-style `lesson_uid`, and
  required `DZN-LSN-` human reference code;
- required `lesson_type`, constrained to the initial types `introductory`,
  `standard`, and `replacement`;
- nullable `enrolment_id` and `term_id` for an `introductory` Lesson; required
  for a `standard` Lesson and normally required for a `replacement` Lesson;
- required direct `student_id`, `teacher_id`, and `course_id` on every Lesson;
  these preserve the operational/historical identity of the occurrence;
- canonical `starts_at_utc` and `ends_at_utc`;
- source timezone and wall-clock provenance;
- scheduling lifecycle and append-only reschedule/version history;
- required `replacement_for_lesson_id` for a `replacement` Lesson; absent for
  other initial Lesson types;
- sequence/allocation position where applicable;
- delivery mode and provider-neutral join-resource reference;
- created, updated, cancelled, completed, and archived audit metadata.

### Invariants

- A Lesson can exist before any provider appointment is created.
- An `introductory` Lesson may exist for a Core Student before that Student has
  an Enrolment or Term. Its direct `student_id`, `teacher_id`, and `course_id`
  identify the occurrence.
- A `standard` Lesson requires its Enrolment and Term. A `replacement` Lesson
  normally uses that same Enrolment/Term and must explicitly link to the
  original Lesson through `replacement_for_lesson_id`.
- On creation of a standard or replacement Lesson, the service layer validates
  that its direct `student_id`, `teacher_id`, and `course_id` match the selected
  Enrolment. Those direct Lesson references are not subsequently derived from,
  or rewritten by, Enrolment changes.
- Scheduling state is separate from Attendance outcome, Notification delivery,
  and Finance payability.
- Normal rescheduling preserves Lesson identity, appends the prior schedule to
  audited history, and invalidates or rotates affected public capabilities. It
  does not rewrite evidence tied to an earlier occurrence without an audited
  correction.
- A genuine replacement or make-up occurrence may be a new Lesson explicitly
  linked to the original; it is not created implicitly by an ordinary time
  change.
- Cancellation preserves the Lesson and its source/provider history.
- External appointments/customer bookings map to a Lesson through
  `LegacyReference`; their IDs are not accepted as authorization.
- A public join/absence action never exposes a predictable `lesson_id`.

### Candidate scheduling lifecycle

Lesson states are `draft`, `scheduled`, `cancelled`, `completed`, and `archived`.
`rescheduled` is an audited event/history fact, not a persistent Lesson state.
Attendance states do not appear in this lifecycle.

## 11. Lesson Schedule Version

Normal rescheduling preserves the same Lesson identity. Schedule history is
append-only in `{prefix}dzn_lesson_schedule_versions`.

### Required conceptual attributes

- `lesson_id` and monotonically increasing `version_number`;
- `starts_at_utc`, `ends_at_utc`, and `schedule_timezone`;
- `local_wall_date` and `local_wall_time`;
- `reason`, `changed_by`, `created_at`, and `superseded_at`.

### Invariants

- Exactly one schedule version is current for a Lesson.
- Changing a Student personal timezone changes display only; it never rewrites a
  schedule version or an Enrolment recurring schedule timezone.
- A genuine replacement/make-up occurrence is a new Lesson with
  `replacement_for_lesson_id`, not an ordinary schedule-version change.

Finance rate/currency snapshots and audited financial corrections remain future
Platform Phase 9 Finance responsibilities. They are not Phase 1 Lesson fields.

## 12. Attendance

### Responsibility

Records the attendance outcome and the evidence used to reach it for one Core
Lesson.

### Conceptual attributes

- `attendance_id` and required `lesson_id`;
- outcome such as pending, student absence reported, attended, no-show, or
  review required;
- source and method of the current decision;
- student/teacher participant evidence and overlap duration;
- join-link click evidence;
- human review note and audited manual decision;
- outcome timestamps and archive metadata.

Evidence items may be modelled separately so provider payload observations,
clicks, and manual decisions do not overwrite one another.

### Invariants

- A link click is evidence only and never completes a Lesson.
- Automatic attendance requires the assigned Teacher and booked Student to be
  identified in the same conference and overlap for the configured minimum.
- Missing identities, missing intervals, ambiguous matches, or insufficient
  overlap produce review rather than automatic attendance.
- A student absence report is idempotent and cannot overwrite a final attended
  or no-show decision.
- A provider or notification failure cannot undo an accepted absence report.
- Manual correction records actor, time, prior outcome, new outcome, and reason.

## 13. Notification

### Responsibility

Represents a requested communication independently of channel/provider delivery.

### Conceptual attributes

- `notification_id`;
- workflow key and version;
- optional `lesson_id`, `enrolment_id`, Teacher, or Student context;
- intended recipient entity/contact reference;
- channel and template key/version/language;
- rendered parameter snapshot or safe template data reference;
- scheduled time, attempts, provider message IDs, and delivery events;
- idempotency key;
- lifecycle, error code, and redacted diagnostic;
- created, completed, and retention metadata.

### Candidate lifecycle

`requested → queued → attempting → accepted → sent → delivered → read`

With terminal or diagnostic branches for `skipped`, `failed`, and `cancelled`.
Provider ordering rules must prevent a late lower-rank event from regressing an
already advanced state while still retaining the raw event for audit.

### Invariants

- Eligibility belongs to a versioned workflow policy, not the transport.
- A production send has a deterministic idempotency key.
- Test sends use an administrator-controlled recipient and never set production
  send history.
- Provider acceptance and later delivery/read/failure are distinct facts.
- Only known provider message IDs can update a Notification.
- Templates and parameters never contain secrets.

## 14. GoogleConnection

### Responsibility

Represents renewable, revocable Google authorization for a Core Teacher.

### Conceptual attributes

- `google_connection_id` and `teacher_id`;
- verified Google subject and email/display identity;
- OAuth client/configuration key and connection source;
- encrypted access and refresh tokens;
- granted scopes and token expiry;
- connected, refreshed, invalidated, disconnected, and revoked timestamps;
- last redacted error and health state;
- created/updated actor and provenance.

### Invariants

- The connection directly references `teacher_id`; email is an attribute, not a
  foreign key.
- OAuth state is unpredictable, short-lived, one-use, and bound to the initiating
  Teacher and return target.
- The granted Google identity must match the teacher identity approved for that
  flow under an explicit matching rule.
- Requested scopes are the minimum required for the approved capability.
- Access and refresh tokens are encrypted at rest and never logged or returned to
  a public client.
- Disconnect disables local use immediately. Revoke attempts provider revocation
  and records whether it succeeded; support policy defines safe handling when the
  provider is unavailable.

## 15. External and legacy references

### Responsibility

Provides traceable mapping between external/legacy records and Core entities
without promoting provider IDs into business identity. It is a later
provider-specific integration concern, not Phase 1 persistence and not an Amelia
import pipeline.

### Conceptual attributes

- `legacy_reference_id`;
- provider/source system;
- external entity type and external ID;
- Core entity type and Core entity ID;
- source version or account/tenant scope where necessary;
- first/last observed timestamps;
- mapping source, actor, reason, and created/updated timestamps;
- active, superseded, or retired state;
- optional redacted diagnostic, never a secret-bearing raw payload.

### Invariants

- The scoped `(provider, external_type, external_id)` mapping is unique unless an
  explicit historical-version policy says otherwise.
- A mapping cannot by itself authorize a user or public action.
- A mapping candidate cannot auto-create or merge a Core identity. Conflicts
  require explicit administrator review.
- Legacy references remain queryable after a provider is retired for audit and
  support.

## 16. Operational Exception

`OperationalException` represents a failure or ambiguity that requires human
judgement. Its approved states are `open`, `acknowledged`, `resolved`, and
`dismissed`.

The platform principle is: **Automate the happy path. Surface failures and
ambiguity. Require human intervention only where judgement is needed.**

Administration is primarily exception management, not routine booking
operation. Normal form-validation errors are not Operational Exceptions.

## 17. Hamnavaz profile relationship

```text
Core Teacher
   └── optional explicit link to Hamnavaz Profile
```

The link is optional in both workflows:

- an academy Teacher may have no public directory profile;
- a Hamnavaz directory teacher may have no academy/Core Teacher.

The link must be explicitly confirmed by an authorized administrator and audited.
It must not be inferred from matching name, email, phone, or WordPress author;
those fields may only suggest a candidate. Where present it is one-to-one.
Linking or unlinking does not create or delete either identity.

Core retains academy identity and operational contact. Hamnavaz retains public
name/biography/media, discovery taxonomies, Country → normalized City →
Instrument filtering data, public contacts, listing status, verification state,
directory commercial information, and SEO presentation.

## 18. State separation matrix

| Concern | Owning model/module | Example states |
| --- | --- | --- |
| Lesson scheduling | Lesson / Core or Academy service | scheduled, cancelled, completed |
| Attendance outcome | Attendance | pending, absence reported, attended, review needed, no-show |
| Notification delivery | Notification | requested, accepted, delivered, failed |
| Operational exception | OperationalException | open, acknowledged, resolved, dismissed |
| Payability | Finance record/policy | pending, payable, non-payable, overridden |
| Archive | Each owning aggregate | active, archived |
| Hamnavaz publication | Hamnavaz Profile | draft, active, verification due, on hold, archived |

One state change may emit events used by other modules, but it does not directly
overwrite their state.

## 19. Manual setup and coexistence rules

- Phase 2 manually creates and validates the small initial catalogue, active
  identities, Enrolments, Terms, and required Lessons.
- Introductory Lessons may be created for active Students before an Enrolment or
  Term exists; standard and replacement Lessons use the applicable relationship.
- Manual setup records actor, source/provenance where needed, validation outcome,
  and counts without introducing an Amelia importer or synchronizer.
- Existing Amelia-dependent runtime functionality remains in Delnavazan
  Enhancements until its bounded replacement phase transfers authority.
- New Core code does not query Amelia or acquire an Amelia data-model dependency.
- Historical Amelia data may be exported to an access-controlled, read-only
  archive outside operational Core under an approved retention procedure.
- Historical source cancellation or deletion never erases completed Core
  business history.
- Manual mapping never grants portal access, sends notifications, charges
  payments, or authorizes a public action.

## 20. Approved direction and remaining design work

The binding decisions are recorded in
[PRODUCT-DECISIONS.md](PRODUCT-DECISIONS.md). Remaining physical or workflow
choices include:

1. Retention durations and anonymization rules by data class.
2. Google failed-revoke support policy and token-retention period.
3. Historical Amelia archive format, storage, access, and inspection procedure.
4. Platform Phase 9 Finance physical tables and audited correction implementation.
5. Later provider-specific integration schemas.

## Phase 2A.2-F Student Identity & Acceptance Authority (Schema 12)

`booking_request_identity_resolution_events` is append-only reviewed identity history. A Booking Request holds only its current event pointer and Student projection; event provenance is controlled metadata with an opaque evidence reference, not contact data or documents.

`student_acceptance_capacity_classifications` is append-only `adult` / `minor` / `unknown` history with a Student current pointer. `student_principal_links` holds versioned, reviewed WordPress principal links; atomic supersession preserves old-to-new lineage through `superseded_by_link_id`, and the legacy `students.wordpress_user_id` field is not acceptance authority. `student_acceptance_authority_grants` records effective, revocable/supersedable `guardian_representative` authority scoped to `service_acceptance`. Elapsed grants are ineligible and are lazily retired from the active slot without deleting history. V1 creates no adult-delegate grant.

These facts support an informational eligibility read only. They do not represent a final acceptance, accepted arrangement, conversion, enrolment, schedule, payment, notification, calendar, or Amelia record.


## Phase 2A.2-D Proposal Foundation (Schema 10)

The Proposal aggregate is a three-level authority history:

- one canonical Proposal Family per Booking Request / Coordination Case groups alternatives but is not acceptable;
- one Proposal Option per Family and Teacher-specific Candidate lineage is independently acceptable;
- immutable Proposal Versions form a linear lineage within one Option, with only the Option current pointer changing.

The Version freezes controlled identities, canonical arrangement facts, source Assent identity/version, limited evidence provenance, and issuance actor/time. It contains no Booking Request contact PII. A later Assent lifecycle change or privacy erasure does not rewrite issued history, but the existing Assent authority gate prevents new issuance.

Schema 10 adds only `dzn_proposal_families`, `dzn_proposal_options`, and `dzn_proposal_versions`. It adds no acceptance, Accepted Service Arrangement, Student, Enrolment, Teacher Assignment, Lesson, payment, notification, calendar, or Amelia authority.
# Phase 2A.2-G additions

- `accepted_service_arrangements`: one immutable, PII-free final record per Proposal Family and selected Option; binds the exact Booking Request, Case, Family, Option, Version, provisional event, Student, accepting principal, identity/capacity evidence, authority route, canonical material facts, confirmation evidence, and HMAC command digests.
- `proposal_option_outcome_events`: one immutable terminal outcome per sibling Option, with exactly the selected Option recorded as `accepted` and the remaining siblings recorded as `closed_competing` in the same transaction.

These records are authority evidence only. They do not create Enrolments, assignments, Lessons, bookings, payments, notifications, calendar entries, or Amelia records.

### Schema 18 candidate: canonical Term commands
`dzn_term_commands` is immutable command evidence for canonical Term create/activate/close/cancel operations. A unique HMAC key digest and canonical payload digest distinguish replay from conflict; result identity/state support fail-closed validation. Raw keys and evidence references are not stored. Existing Terms are not backfilled or translated.

## Schema 19 candidate — canonical Enrolment lifecycle commands

`enrolment_lifecycle_commands` is immutable digest-only evidence for one explicit `activate`, `pause`, `resume`, or `close` intent. It binds the Enrolment, expected/result states, and exact lifecycle result event. It has no raw key, raw evidence reference, mutable status, or `updated_at`. The Enrolment projection and existing append-only event schema are unchanged.

## Schema 21 candidate — canonical Lesson schedule versions, events and commands

Phase 2A.2-N stores canonical Lesson scheduling authority separately from Lesson lifecycle. A scheduled canonical Lesson is a Lesson in `authorised` state plus exactly one applicable version in `{prefix}dzn_canonical_lesson_schedule_versions`.

### `dzn_canonical_lesson_schedule_versions`

Immutable interval assertions: `lesson_id`, `version_number`, `applicable_slot` (`1` for the single applicable version, `NULL` for history), snapshots of `enrolment_id`, `term_id`, `teacher_assignment_id` and `teacher_id`, `starts_at_utc`, `ends_at_utc`, `duration_minutes`, `buffer_minutes`, `duration_source` (`course_default` or `override`), `occupied_ends_at_utc`, `schedule_timezone`, `local_wall_date`, `local_wall_time`, `availability_basis` (`within_availability` or `administrative_override`) with the audited override reason/channel/digest/timestamp, `reason_code`, evidence channel/digest/timestamp, and supersession lineage (`superseded_at`, `superseded_by_version_id`). Only `applicable_slot`, `superseded_at` and `superseded_by_version_id` may mutate.

Uniqueness: `UNIQUE(lesson_id, version_number)` and `UNIQUE(lesson_id, applicable_slot)`; `KEY teacher_occupancy(teacher_id, starts_at_utc, occupied_ends_at_utc)` supports the half-open occupancy conflict query.

### `dzn_canonical_lesson_schedule_events`

Append-only per-Lesson event chain with `event_sequence`, `event_type` (`scheduled`, `rescheduled`, `released`), `from_schedule_version_id`, `to_schedule_version_id` and the recorded reason/channel/digest/timestamp. `scheduled` opens an authority period, `rescheduled` continues it and `released` closes it; a released Lesson may be scheduled again in a new period.

### `dzn_canonical_lesson_schedule_commands`

Digest-only durable command evidence for `schedule_initial`, `schedule_revise` and `schedule_release` in domain `canonical_lesson_schedule_v1`: command key and payload digests, Lesson/Enrolment/Term/Teacher identity, expected Assignment, expected active version, expected Lesson state, requested timezone/wall provenance, duration override, availability override intent and evidence, the resolved interval/duration/buffer/duration-source/availability-basis, and the result version/event/state. No raw keys and no raw evidence references are stored.

### `dzn_teacher_schedule_roots`

One row per Teacher (`UNIQUE(teacher_id)`), created by migration for existing Teachers and lazily for later ones. It is a serialization anchor only: it stores no availability, capacity or authority state. Teacher occupancy is derived from applicable schedule versions, never from a separate mutable reservation projection.
