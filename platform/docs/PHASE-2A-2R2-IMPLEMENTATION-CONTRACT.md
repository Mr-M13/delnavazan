# Phase 2A.2-R2 — Renewal, Next-Term, Recurring Enrolment/Collection, Recovery, Lapse & Refund Authority

**Status:** implementation contract (preflight). Planning/audit only.
**Schema:** 026 (`026_renewal_recurring_enrolment_authority`)
**Build:** `phase2a2r2-renewal-next-term-collection-recovery-20260923.1` (proposed)
**Base:** `main` @ `f9df3bfb0fda79fba7dee916c4687464ee67d480` (Schema 25 / R1 complete)

This contract is implementation-ready for the R2 authority layer. It does not authorise deployment,
merge, provider calls, live credentials, notifications delivery, or Theme work.

## 1. Verified authoritative state

| Fact | Verified value |
| --- | --- |
| Repository root | current checkout (`git rev-parse --show-toplevel` = workspace) |
| Branch / remote | `main...origin/main`, clean tree |
| HEAD | `f9df3bfb0fda79fba7dee916c4687464ee67d480` |
| Platform version | 0.1.0 |
| Schema | `25` |
| Build | `phase2a2r1-commercial-purchase-funding-authority-20260920.1` |
| Migration ledger | 001–025; latest `025_commercial_purchase_funding_authority` |
| R1 merge state | merged/closed on `main` (round 6 plus fresh-install bootstrap correction) |

The seven canonical planning documents still describe pre-R1 state (Schema 24 / "R1 unmerged"). This
is stale documentation only; the plugin bootstrap and `Migrator` are authoritative. Correcting those
documents is listed as a pre-implementation prerequisite, not part of R2 authority code.

## 2. Locked commercial invariants R2 must preserve

- Money is integer minor units with explicit ISO-4217 currency; no floats, no conversion.
- The Platform-issued offer is the only whole-Term price snapshot.
- Free intro remains separate from paid Terms.
- One Term is 12 sessions; payment is full or two ordered instalments (1–6 then 7–12).
- Financial settlement is distinct from academic effectiveness.
- Tranche 2 may settle early but remains `prerequisite_pending` until tranche 1 settles.
- Funded allowance is derived from accepted obligations; no mutable funded-session counter.
- No Lessons 7–12 before the required funding.
- Phase L is the sole Term authority; Phase M owns Lessons; Phase N owns schedules; Phase Q owns the
  pre-payment hold.
- The Phase-Q first regular slot is never derived from `intro + 7`.
- Flexible protection covers only explicitly known capacity.
- Provider-neutral business authority; provider evidence is never business authority.
- Legacy Terms without funding plans preserve academic behaviour.

## 3. R2 authority boundaries

### In scope

1. **Recurring Enrolment aggregate** — frozen currency, `manual`/`automatic` collection mode,
   lifecycle state, immutable provenance.
2. **Renewal Cycle authority** — progression-derived next-Term boundary (derived only from
   authoritative facts), renewal decision points, guarantee/protection/collection/term-bound
   lifecycle.
3. **Collection modes as recorded provider-neutral states** — no live charge, no provider call.
4. **Manual same-slot guarantee** — `MANUAL_GUARANTEE_EXPRESSION` with `n =
   MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS = 4`, resolved in the pattern timezone.
5. **Continuous automatic protection** — `AUTOMATIC_RENEWAL_SLOT_PROTECTION` while valid or in
   recovery; release only via lapse/cancel under the same per-Teacher scheduling root.
6. **Recovery and lapse representation** — recorded provider-neutral states and explicit commands;
   no automatic lapse while `PAYMENT_RECOVERY_POLICY` is unset.
7. **Provider-neutral refund/reversal review trajectory** — record/route for human review; no
   academic-consequence rule.
8. **Channel-neutral notification intents** — finalise the intent set consumed by Phase S; no
   delivery.

### Out of scope

- Stripe SDK, webhook, credentials, Checkout, subscription object, or live charge.
- Notification delivery, templates, attempt/delivery lifecycle (Phase S).
- Refund academic consequences (product decision, isolated in §4).
- Automatic-charge execution and opt-in/out default (product decision, isolated in §4).
- Teacher payout, accounting, tax, invoices (Phase U).
- Portals, public routes, Theme (Phase W and later).
- Deployment, production cutover, Amelia writes/removal.

### Authority-preservation rule

R2 is an **orchestration and cross-Term authority layer above R1**. It must never reimplement R1
pricing, acceptance, funding derivation, protected claims, or Term binding. Every next-Term purchase
uses the existing `CommercialOfferService` → `CommercialPaymentService` →
`CommercialCapacityService` → `CommercialTermFundingService` → Phase-L `CanonicalTermAuthorityService`
chain.

## 4. Product decisions and safe defaults

The following remain product-owner decisions. R2 must not invent them.

| Decision | R2 safe default / seam | Gates |
| --- | --- | --- |
| Refund/reversal academic consequences | `dzn_refund_review_cases.academic_consequence` stays `NULL`; read model reports `unresolved`; no funded-session clawback or reversal is performed | Future refund-consequence sub-slice |
| Automatic renewal opt-in/out and lead time | `collection_mode` is recorded only; no default opt-in/out; `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` unset means no advance charge date is computed | Future automatic-charge sub-slice |
| Recovery/lapse thresholds | `PAYMENT_RECOVERY_POLICY` unset means no lapse and capacity stays protected; recovery is representation only | Future recovery automation |
| Recurring-payment confirmation / provider-specific behaviour | Provider-neutral only; actual confirmation arrives through R1 `commercial_payment_evidence`; no Stripe adapter | Phase T adapter |

## 5. Lifecycle and state machines

### 5.1 Recurring Enrolment

States: `active`, `suspended`, `closed`.

Transitions:

- `active → suspended` (administrator/manual hold; protection and collection intents are not
  silently cancelled)
- `suspended → active` (resume with evidence)
- `active | suspended → closed` (terminal; requires release of all active protection and no open
  refund/recovery case)

`collection_mode` is a mutable audited attribute, values `manual` and `automatic`. A mode change is
an append-only event and never rewrites historical cycles.

### 5.2 Renewal Cycle

States:

`pending → guarantee_protected → payment_required → collected → term_bound → closed`

terminal branches: `pending|guarantee_protected|payment_required|collected → lapsed | cancelled`.

Meaning:

- `pending` — cycle recorded; next-Term boundary derived and stored but no downstream action taken.
- `guarantee_protected` — manual same-slot guarantee interval is protected until
  `guarantee_deadline_at`.
- `payment_required` — a collection intent is open.
- `collected` — the authoritative first obligation is settled via R1 payment evidence.
- `term_bound` — next Term created through Phase L and the R1 entitlement is bound.
- `closed` — cycle finished; next Term is current.
- `lapsed` — recovery failed or policy lapsed; capacity released; never silently reopens.
- `cancelled` — explicit cancel; capacity released; never silently reopens.

`boundary_derived_at` is immutable per cycle and comes from the single canonical boundary derivation
service using the current Term's applicable schedule versions, R1 Regular pattern intervals, and
explicit authorised facts. It is never `intro + 7`, never wall-clock guessing, and never inferred
from provider data.

### 5.3 Collection Intent

States: `pending`, `submitted`, `confirmed`, `failed`, `recovered`, `cancelled`.

- `manual_payment_required` and `automatic_charge` are the two recorded kinds.
- `submitted` means a provider-neutral request was authorised; it performs no external call.
- `confirmed` only follows accepted R1 payment evidence for the exact cycle obligation.
- `failed → recovered` and `failed → cancelled` are explicit, evidenced transitions.

### 5.4 Recovery Case

States: `open`, `recovering`, `recovered`, `lapsed`.

- `open` records a failed collection intent.
- `recovering` records ongoing recovery activity.
- `recovered` records the exact R1 evidence that settled the obligation.
- `lapsed` is terminal and only allowed when policy authorises it or an explicit administrator
  command supplies evidence.

No mutable attempt counter exists; attempts are append-only events.

### 5.5 Refund/Reversal Review Case

States: `open`, `review_required`, `resolved`, `dismissed`.

- `open` records R1 `refund`/`reversal` evidence against a purchase/obligation.
- `review_required` requires human review.
- `resolved` records the human decision and preserves `academic_consequence` as `NULL` until the
  product decision is made.
- `dismissed` records a rejected/duplicate review without altering settlement facts.

### 5.6 Continuous Protection

`dzn_recurring_protections` links a renewal cycle to an R1 protected-capacity claim.

States: `active`, `released`, `lapsed`.

- `active` extends the current-Term claim across the next-Term boundary.
- `released` and `lapsed` are terminal; they release the underlying claim intervals under the same
  per-Teacher scheduling root and never release a predecessor before its successor is durable.

## 6. Schema 026 data model and migration

Additive only. No backfill, no inferred renewal, no external call, no provider-specific column, no
foreign key or CHECK constraint (the repository relies on application/verifier enforcement).

### 6.1 Tables

`dzn_recurring_enrolments`

- `id`, `uid char(26)`, `reference_code varchar(32)`
- `enrolment_id` unique, `student_id`, `course_id`
- `currency char(3)` frozen, `region_code varchar(8)`
- `collection_mode varchar(16)` (`manual` | `automatic`)
- `state varchar(16)` (`active` | `suspended` | `closed`)
- `rule_version varchar(48)`, `recurring_enrolment_version int unsigned`
- audit columns

`dzn_recurring_enrolment_events` (append-only)

- `uid`, `recurring_enrolment_id`, `event_sequence`, `event_type`, `from_state`, `to_state`,
  `from_collection_mode`, `to_collection_mode`, `reason_code`, `evidence_channel`,
  `evidence_reference_digest char(64)`, `evidence_at`, `occurred_at`, `recorded_at`, `recorded_by`,
  `created_at`, `created_by`

`dzn_recurring_enrolment_commands` (digest-only, immutable)

- `uid`, `command_domain`, `operation`, `command_key_digest char(64)`,
  `command_payload_digest char(64)`, `recurring_enrolment_id`, `result_state`, `result_id`,
  `created_at`, `created_by`

`dzn_renewal_cycles`

- `id`, `uid`, `reference_code`
- `recurring_enrolment_id`, `sequence int unsigned`
- `source_term_id`, `next_term_id` nullable
- `collection_mode varchar(16)` frozen at cycle creation
- `currency char(3)`, `amount_minor bigint unsigned` (whole-Term price snapshot copied from the
  accepted R1 offer)
- `boundary_derived_at datetime`, `guarantee_deadline_at datetime` nullable
- `state varchar(24)`, `renewal_cycle_version int unsigned`
- audit columns

`dzn_renewal_cycle_events` (append-only)

- `uid`, `renewal_cycle_id`, `event_sequence`, `event_type`, `from_state`, `to_state`,
  `reason_code`, `evidence_channel`, `evidence_reference_digest char(64)`, `evidence_at`,
  `occurred_at`, `recorded_at`, `recorded_by`, `created_at`, `created_by`

`dzn_renewal_cycle_commands` (digest-only, immutable)

- `uid`, `command_domain`, `operation`, `command_key_digest`, `command_payload_digest`,
  `renewal_cycle_id`, `result_state`, `result_id`, `created_at`, `created_by`

`dzn_collection_intents`

- `id`, `uid`, `reference_code`
- `renewal_cycle_id`, `obligation_id` (R1 obligation)
- `kind varchar(24)` (`manual_payment_required` | `automatic_charge`)
- `state varchar(16)`, `charge_at datetime` nullable, `failure_reason_code varchar(64)` nullable
- `collection_intent_version int unsigned`
- audit columns

`dzn_collection_intent_events` (append-only) and `dzn_collection_intent_commands` (digest-only)

`dzn_recovery_cases`

- `id`, `uid`, `reference_code`, `recurring_enrolment_id`, `renewal_cycle_id`,
  `collection_intent_id`
- `state varchar(16)`, `recovery_case_version int unsigned`
- audit columns

`dzn_recovery_case_events` (append-only) and `dzn_recovery_case_commands` (digest-only)

`dzn_refund_review_cases`

- `id`, `uid`, `reference_code`, `purchase_id`, `obligation_id`, `evidence_id`
- `kind varchar(16)` (`refund` | `reversal`)
- `amount_minor bigint unsigned`, `currency char(3)`
- `state varchar(24)`, `academic_consequence varchar(32)` **nullable, always NULL in R2**
- `resolution_note text` nullable, `refund_review_version int unsigned`
- audit columns

`dzn_refund_review_events` (append-only) and `dzn_refund_review_commands` (digest-only)

`dzn_recurring_protections`

- `id`, `uid`, `reference_code`, `renewal_cycle_id`, `claim_id` (R1 protected-capacity claim)
- `state varchar(16)` (`active` | `released` | `lapsed`), `recurring_protection_version int unsigned`
- audit columns

`dzn_recurring_protection_events` (append-only) and `dzn_recurring_protection_commands`
(digest-only)

### 6.2 Migration rules

- `026_renewal_recurring_enrolment_authority` installs the tables above.
- `verify_renewal_recurring_enrolment_schema()` runs after migration 026, on current-schema
  verification, and unconditionally before the schema option advances to 26 (including the
  retained-026/stale-version path).
- Verifier rejects: provider-specific columns, non-InnoDB tables, `updated_at`/raw-key/reference
  columns on append-only event/command tables, malformed `char(64)` digests, and any Lesson/Term/
  schedule/notification table smuggled into the phase.

## 7. Commands, services, repositories, events, read models

### 7.1 Application services and commands

`RecurringEnrolmentService` (capability `dzn_manage_recurring_enrolments`)

- `establish` — from an applicable canonical Enrolment and an R1 funding plan; freezes currency.
- `set_collection_mode` — `manual` ↔ `automatic`, evidenced append-only event.
- `suspend`, `resume`, `close`.

`RenewalCycleService` (capability `dzn_manage_renewal_cycles`)

- `derive_boundary` / `open_cycle`
- `activate_manual_guarantee`
- `require_payment`
- `confirm_collection` — consumes accepted R1 payment evidence; no duplicated acceptance.
- `bind_next_term` — delegates Term creation to Phase L and entitlement binding to R1.
- `lapse`, `cancel`, `close`.

`CollectionIntentService` (capability `dzn_manage_collection_intents`)

- `open_manual_payment_required`, `schedule_automatic_charge`
- `submit`, `confirm`, `record_failure`, `record_recovery`, `cancel`.

`RecoveryService` (capability `dzn_manage_recovery`)

- `open_recovery`, `record_recovery_attempt`, `mark_recovered`, `mark_lapsed`.

`RefundReviewService` (capability `dzn_manage_refund_reviews`)

- `record_refund_evidence`, `route_for_review`, `resolve`, `dismiss`.

`RecurringProtectionService` (capability `dzn_manage_recurring_protection`)

- `establish_protection`, `extend_protection`, `release_protection`.

### 7.2 Repositories

- `RecurringEnrolmentRepository`
- `RenewalCycleRepository`
- `CollectionIntentRepository`
- `RecoveryRepository`
- `RefundReviewRepository`
- `RecurringProtectionRepository`

Each follows the established `READ COMMITTED` transaction wrapper, named-index duplicate
arbitration, digest-only command evidence, and append-only event/history discipline.

### 7.3 Read models

- `RecurringEnrolmentReadService`
- `RenewalCycleReadService`
- `CollectionReadService`
- `RecoveryReadService`
- `RefundReviewReadService`
- `RecurringProtectionReadService`

All reads are capability-protected, PII-minimised, and fail closed on malformed aggregates.

### 7.4 Domain events and notification intents

R2 publishes provider-neutral facts through the existing `platform_outbox` seam. Delivery belongs to
Phase S.

Finalised intents:

- `AUTOMATIC_RENEWAL_UPCOMING`
- `AUTOMATIC_RENEWAL_CHARGED`
- `AUTOMATIC_RENEWAL_FAILED`
- `MANUAL_RENEWAL_PAYMENT_REQUIRED`
- `GUARANTEE_DEADLINE_APPROACHING`
- `GUARANTEE_EXPIRED`
- `PAYMENT_FAILED`
- `PAYMENT_RECOVERED`
- `TERM_LAPSED`
- `REFUND_REVIEW_REQUIRED`
- `REFUND_RESOLVED`

These are intent names, never templates and never delivery records.

## 8. Concurrency, idempotency and serialisation

- Serialization root: the R1 `commercial_account_roots` row for the beneficiary Student.
- Lock order is fixed and must not be inverted:
  `commercial account root → canonical Enrolment → ascending Teacher → Assignment →
  per-Teacher scheduling root`.
- Cross-Term protection release uses the same per-Teacher scheduling root as R1-D10; a predecessor
  claim/hold is released only after its successor is durable.
- Commands use HMAC key digest + payload digest, replay-vs-conflict arbitration, and fail-closed
  corruption (no silent repair).
- Duplicate recovery is constrained to named unique indexes for the owning aggregate.
- A collection confirmation is idempotent against the same R1 payment evidence and never creates a
  duplicate purchase, entitlement, funding plan, or next Term.

## 9. Legacy and backward compatibility

- No backfill. Legacy `legacy_phase1` Terms and canonical Terms without an R1 funding plan are not
  touched and preserve existing academic behaviour.
- A recurring enrolment can only be established from an applicable canonical Enrolment that already
  has an authoritative R1 funding plan; otherwise it fails closed with
  `funding_plan_required`.
- Schema 25 → 26 is repeat-safe and leaves every R1 row unchanged.

## 10. Provider-neutral seams

- No provider SDK, webhook, credential, or subscription object.
- Provider confirmation arrives exclusively through R1 `commercial_payment_evidence`.
- Collection intents record provider-neutral state; the actual charge execution is Phase T.
- Notification intents are provider-neutral names; transport is Phase S/Meta/email adapters.

## 11. Test matrix

| Suite | Required proof |
| --- | --- |
| `tests/phase-2a2r2-contract.php` | Schema 26 identity, build shape, migration/verifier call sites, rule constants, intent set, capability boundaries, no provider column, no FK/CHECK, immutable append-only tables |
| `tests/phase-2a2r2-migration-runtime.php` | fresh Schema 26; 25→26 rehearsal; repeat; partial capability repair; no backfill; retained-026 fail-closed; malformed storage |
| `tests/phase-2a2r2-runtime.php` | establish recurring enrolment; collection-mode change; boundary derivation from authorised facts; manual guarantee; automatic protection; recovery/lapse with policy unset and set; refund review trajectory; next-Term R1 offer/acceptance/binding orchestration |
| `tests/phase-2a2r2-corruption-runtime.php` | frozen currency/mode; boundary/guarantee; recovery/lapse; cross-Term protection; refund evidence; command-row corruption — all fail closed and converge after restoration |
| `tests/phase-2a2r2-failure-runtime.php` | injected write boundary at every owning mutation; full rollback; retry convergence |
| `tests/phase-2a2r2-concurrency-runner.sh` | `renewal_vs_schedule`, `guarantee_vs_close`, `recovery_vs_satisfaction`, `release_vs_succession`, `mode_change_vs_cycle`, `refund_vs_settlement`, `unrelated_recurring_enrolments` |
| Adjacent regressions | Phase L, M, N, O, Q, R1 runtime suites re-run green |

The local runtime must execute all of the above. Fresh-install, 25→26 upgrade, idempotency and
representative runtime tests are mandatory acceptance gates.

## 12. Recommended implementation task identity

| Field | Value |
| --- | --- |
| Task ID | `PHASE-2A-2R2-RENEWAL-NEXT-TERM-COLLECTION-RECOVERY-AUTHORITY` |
| Branch | `phase-2a2r2-renewal-next-term-collection-recovery-authority` |
| Base | `main` @ `f9df3bfb0fda79fba7dee916c4687464ee67d480` |
| Dependency | PLATFORM-LOCAL-TEST-RUNTIME green (fresh + runtime + concurrency) |
| Schema | 026 / `026_renewal_recurring_enrolment_authority` |
| Build | `phase2a2r2-renewal-next-term-collection-recovery-20260923.1` |
| Review posture | single coherent candidate, dual-owner independent review, additive-only descendants |

## 13. Pre-implementation prerequisites

1. Resolve the local-runtime concurrency runner to green (`phase-2a2r2-concurrency-runner.sh` and the
   R1 concurrency script currently fail in the disposable runtime).
2. Repair the pre-existing Phase-P / Phase-Q fixture-order runtime failures so adjacent regressions
   can be quoted as green evidence.
3. Close the stale-documentation debt (README, continuity, architecture, module boundaries,
   migration strategy, policy registry, R1 phase doc, data model, changelog) to Schema 25 / R1
   merged.
4. Obtain product-owner sign-off on the four decisions in §4 before implementing the refund
   consequence, automatic charge, recovery automation, or provider adapter sub-slices.

None of these prerequisites block writing this contract; they gate execution of R2.

## 14. Definition of done

- Schema 26 and verifier are additive and preserve Schema 25.
- All §11 suites pass on the disposable runtime from a fresh clone.
- No product decision is silently invented; every deferred decision is behind the safe defaults in §4.
- No provider call, credential, notification delivery, Theme change, merge, or deployment occurred.
