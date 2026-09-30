# Phase 2A.2-R1 — Commercial Purchase, Funding & Current-Term Capacity Authority

**Status:** **merged and closed on `main`.** Authoritative `main` is
`f9df3bfb0fda79fba7dee916c4687464ee67d480`: the Phase-R1 commercial purchase, funding and
current-Term capacity authority as reviewed through correction round 6 plus the subsequent
fresh-install capability-bootstrap ordering correction. Schema 25 / migration
`025_commercial_purchase_funding_authority` / build
`phase2a2r1-commercial-purchase-funding-authority-20260920.1`. **Not deployed, and no production
cutover has occurred** — source completion never authorises deployment, and Stripe, notifications,
portals, Theme, refunds, payouts and deployment remain outside Phase R1.

Correction round 6 was the reviewed candidate: additive descendants of the reviewed
correction-round-5 commit `2af26260d1ba711a18f9fc73c15923531cab69cd`, closing the one finding the
correction-round-5 independent re-review returned (`C6-MAJOR-001` complete operation-specific
`commercial_commands` selector shape — §0f). The independent re-reviews of correction rounds 1–5
each FAILED on their then-open findings while passing everything else, and remain historical review
evidence. Correction round 6 passed independent re-review and was merged as a fast-forward; the
following fresh-install capability-bootstrap correction is also on `main`. §0–§0f below are retained
as that review history. Phase 2A.2-R2 / Schema 26 renewal, next-Term, recurring collection, recovery,
lapse and refund-review authority is the active successor **candidate**; it adds an orchestration
layer above this authority and changes none of it.

## 0. Independent review correction round 1

The first independent review of candidate `10fe40618af3de76f2af37a093e611af10cc6ccc` failed on eight
findings. All eight are corrected on descendants of that commit (never rewritten):

| Finding | Correction |
| --- | --- |
| R1-BLOCK-001 promotion redemption + adjustment consumption | Accepted purchase convergence is now the only consumption boundary: the promotion row and the exact snapshotted adjustment are locked and revalidated, both promotion limits are enforced under that lock, exactly one redemption and one `granted → consumed` transition with its append-only event are written, replay converges, and a second purchase can never reuse the adjustment. Offer issuance still consumes nothing. |
| R1-BLOCK-002 exact protected interval → Phase-N occupancy | A protected interval authorises an occupancy only when it IS that interval: Teacher, Term, canonical session, exact UTC bounds, buffered occupied end, schedule timezone and local wall clock. Zero, multiple, time, Teacher, Term, sequence or timezone/wall-clock mismatches fail closed, the exact interval is locked inside the scheduling transaction, and only that one interval is excluded from arbitration. |
| R1-BLOCK-003 historical capacity intervals | Only `claim.state = active` AND `interval.state = protected` blocks. `satisfied` and `released` rows are history; a legitimately released claim is reusable and no longer reported as corruption, while genuinely impossible aggregates still fail closed. |
| R1-BLOCK-004 Course identity continuity | The continuation case, authorised slot, hold, product, offer, recurring pattern, claim and Term Enrolment must agree on one Course. R1 selects or substitutes no Course; every mismatch fails closed before any commercial truth, capacity mutation or Term creation. |
| R1-MAJOR-005 exact provider-evidence convergence | Each evidence row stores a canonical immutable-fact digest (provider key, reference, kind, exact nullable amount and currency, obligation reference, occurrence instant, account digest, attributed offer/obligation). Identical facts converge idempotently; a material difference preserves the original row, never manufactures settlement truth and routes `conflicting_payment_evidence` / `ambiguous_obligation_attribution`, sequentially and concurrently. |
| R1-MAJOR-006 Teacher serialisation for claim release | `releaseClaim()` acquires the canonical per-Teacher scheduling root before reading or mutating the claim intervals, preserving the established global lock order and adding no inverse edge. |
| R1-MAJOR-007 migration + structural integrity | The repository migration policy deliberately avoids foreign keys and CHECK constraints, so equivalent durable enforcement is implemented instead: class-B policy allowlisting with a fail-closed read for a malformed or structural stored key, funding-plan/entitlement/purchase/offer ownership validation, offer↔product↔case Course and Student ownership validation, claim/interval Teacher and aggregate validation, and a corruption suite that exercises every listed cross-authority reference. |
| R1-MAJOR-008 critical test proof | Behavioural runtime, corruption and concurrency coverage asserts durable database state and authority ownership for every corrected invariant (see §5). |

## 0b. Independent review correction round 2

The independent re-review of correction round 1 candidate `186fc5012fe294ea3d91b85aeefdd738448471a0`
(tree `40fc2643eacc6811882b552785f55e7db5c04e30`) failed on four substantive integrity areas plus
behavioural coverage and one documentation item. All six are corrected on additive descendants of that
commit (never rewritten, never rebased, never squashed):

| Finding | Correction |
| --- | --- |
| R1-BLOCK-001 account-adjustment source ↔ immutable snapshot | `CommercialLineageValidator` now locks the authoritative adjustment source and proves it still corresponds exactly to the immutable `commercial_offer_adjustments` row captured at issuance: source id/type, kind, percentage basis points or fixed minor units, currency, application order, the recomputed discount against the **post-promotion running amount**, the offer's recorded contribution and the re-derived canonical snapshot digest (single-sourced with the issuance write). Any mismatch fails closed before redemption, adjustment consumption, evidence acceptance, settlement, purchase, entitlement, funding, capacity or Term truth, and the historical snapshot is never repaired or rewritten. |
| R1-BLOCK-004 authoritative offer lineage | One canonical, transaction-aware `CommercialLineageValidator` proves the stored chain `continuation case → authorised first regular slot → pre-payment hold → product → price → offer → Student → Teacher → Course (canonical Enrolment)`. It is reusable from a caller's transaction, operates on authoritative stored rows, fails closed on missing/mismatched/replaced relationships, locks the aggregate rows where the caller's serialization requires it, and is invoked by the offer read seam, payment acceptance, capacity handoff and Term binding. |
| R1-MAJOR-005 / R1-C1-NEW-001 concurrent initially-unattributed evidence | `recordUnattributed()` now recovers through the one canonical duplicate-evidence boundary: the winner is reloaded and revalidated, the incoming immutable facts are canonicalised with the same `evidence_fact_digest` algorithm, identical facts converge idempotently, and a materially different fact set preserves the winner unchanged and durably routes `conflicting_payment_evidence`. The conflicting fact set is never stored, never attributed as equivalent, and never manufactures settlement truth. |
| R1-MAJOR-007 integrity at owning mutation boundaries | Every owning mutation authority invokes the one canonical aggregate validator immediately before its mutation — payment acceptance, promotion/redemption consumption, account-adjustment consumption, capacity handoff, Term binding and evidence duplicate recovery — instead of relying on request validation, a read endpoint, a later verifier or post-purchase Term creation. No database foreign key or CHECK constraint is added; the established application/verifier integrity architecture is preserved. |
| R1-MAJOR-008 behavioural coverage | The corruption runtime now proves the at-rest account-adjustment mutation and rewritten snapshot, and proves that the same stored Course/ownership corruption is independently rejected by payment acceptance, capacity handoff and Term binding (each exercised directly, each asserting zero downstream mutation and authoritative predecessor state). A new deterministic concurrency mode covers initially-unattributed evidence with conflicting immutable facts, plus its identical-facts convergence counterpart, and the failure runtime injects a failure at the unattributed evidence write boundary. |
| R1-C1-NEW-002 documentation synchronisation | This document's testing matrix now lists the concurrency modes that are actually executed, the continuity record and changelog record correction round 2, and the new initially-unattributed conflicting-facts mode is listed here rather than claimed without execution evidence. |

R1-BLOCK-002 (exact protected interval → Phase-N occupancy), R1-BLOCK-003 (historical commercial
capacity lifecycle) and R1-MAJOR-006 (Teacher-root serialization for claim release) passed the
independent re-review and are deliberately unchanged.

## 0c. Independent review correction round 3

The independent re-review of correction round 2 candidate
`3aaf3081a0d5d12501715589a2a518c68af5ad98` (tree `dfba34c3a986f4a4cb9aa2f9a8b77a953efbf119`)
**PASSED** the account-adjustment snapshot correspondence, the exact protected-interval occupancy
identity, the historical capacity lifecycle, the upstream offer lineage, provider-evidence
convergence, Teacher-root claim-release serialization, the round-2 behavioural/concurrency matrix and
the documented matrix, and **FAILED one remaining integrity area**:

**`R1-MAJOR-007` / `NEW-C2-001` — purchase/entitlement ownership is not revalidated before capacity
and Term truth.** The round-2 validator proved the upstream aggregate *from the offer*, so following
`entitlement.purchase_id → purchase.offer_id → offer` proved only that the selected **offer** was
valid. It did not prove that the selected **purchase and entitlement** still belong to that exact
offer and still carry the immutable accepted commitment.

| Correction Round 3 | Change |
| --- | --- |
| One canonical commitment validator | `CommercialCommitmentValidator` proves `entitlement → purchase → offer → canonical upstream offer lineage` from stored rows, and delegates the upstream proof to `CommercialLineageValidator` rather than duplicating it. |
| Entitlement → purchase | The exact purchase, equal beneficiary (entitlement, purchase and offer), the bounded session quantity equal to the accepted offer's commitment, a mutation-appropriate entitlement state, and the canonical entitlement aggregate. |
| Purchase → offer | The exact accepted offer, and its **own** purchase (`purchaseByOffer` identity, so one accepted offer can only ever own one purchase); equal beneficiary, product, currency, accepted amount, payment plan; `accepted`, valid reconciliation state, valid acceptance instant and version. |
| Acceptance evidence | The evidence row that minted the purchase must still be an accepted evidence row for this exact offer and one of that offer's obligations. |
| Existing claim ownership | A claim consumed by handoff or Term binding must belong to the same entitlement, purchase, Student, Teacher, Course, commitment size and pre-payment hold — including the idempotent existing-claim fast path, so a valid claim from another commitment can never satisfy this chain. |
| Owning boundaries | Both `CommercialCapacityService::handoffFromEntitlement()` and `CommercialTermFundingService::bindEntitlementToTerm()` prove the commitment before any capacity truth, before the Phase-Q hold is released, and before Phase-L Term creation. Read endpoints are unchanged and are explicitly **not** relied upon. |
| Corruption evidence | Two otherwise fully valid accepted commitments: a purchase repointed at another otherwise-valid offer (the alternate offer is asserted valid while the corruption is in place), a re-pointed beneficiary/product/currency/amount/plan, a re-pointed or resized entitlement, and a claim belonging to another commitment. Each case fails closed at both owning boundaries, creates no claim/interval/funding/Term truth, is never silently repaired, and converges once the authoritative value is restored. |

R1-BLOCK-001, R1-BLOCK-002, R1-BLOCK-003, R1-BLOCK-004, R1-MAJOR-005, R1-MAJOR-006, R1-MAJOR-008,
R1-C1-NEW-001 and R1-C1-NEW-002 passed the independent re-review and are deliberately unchanged; the
round-3 evidence re-runs all of them.

## 0d. Independent review correction round 4

Correction round 1, Correction round 2 and Correction round 3 remain historical review evidence; this
section records the fourth round.

The independent re-review of correction round 3 candidate
`2ff3d6e3a81ede8ebbf44f3144f1afc801b93531` (tree `ab0a568a3ed73bf0471ce76c2a572b2b04812d01`)
**FAILED** on three MAJOR findings in the commitment layer that round 3 introduced; everything else
passed and is deliberately unchanged.

| Finding | Correction |
| --- | --- |
| `NEW-C3-001` acceptance-evidence / settlement / payment-fact ownership | `purchase.first_evidence_id` is no longer merely "an intrinsically valid accepted row": the canonical commitment validation now proves it is the exact successful evidence that settled the obligation the purchase was accepted against — `evidence_kind = success`, `processing_state = accepted`, the exact offer and the exact obligation of that offer, evidence amount and currency equal to the authoritative obligation (and to the purchase and offer currency), a valid provider occurrence instant that exactly equals the purchase's recorded acceptance instant, the exact obligation settlement for that evidence (valid against the obligation, same evidence), and the exact payment fact binding purchase + evidence + obligation with matching amount, currency and occurrence. |
| `NEW-C3-002` idempotent existing-claim handoff | The existing-claim fast path now requires the claim to be an R1 Q→R1 successor claim with a **non-null** `predecessor_reservation_id` equal to the offer's Phase-Q hold, an active successor state, coherent immutable source/pattern identity, and a complete claim aggregate validated through the canonical `CommercialValidator::claimValid()` over its **locked** intervals (declared count, required intervals present, none extra, none corrupt). A released, expired, malformed, foreign or interval-corrupt claim can no longer be returned as idempotent handoff success — on the handoff or the Term-binding path. |
| `NEW-C3-003` command replay after at-rest corruption | Replay is no longer decided from the command row alone. A duplicate-command winner may be reported as an idempotent success only after the authoritative current stored aggregate is re-proved with locks: for capacity handoff the full commitment chain, the result claim's ownership/predecessor/complete interval aggregate and the recorded result state; for Term binding the full commitment chain, the bound claim's complete aggregate, the exact Term and the exact funding-plan relationship. The rolled-back duplicate path now re-runs that revalidation inside its own transaction instead of as loose autocommit reads. Valid unchanged state still replays idempotently. |

Corruption evidence for round 4 covers every listed class: eleven acceptance-fact probes (purchase
acceptance instant, evidence kind/amount/currency/occurrence, settlement amount and evidence link,
payment-fact purchase/obligation/amount/occurrence, and a purchase repointed at a synthetic accepted
non-success evidence row for the same offer and obligation); eight claim probes (null predecessor,
foreign predecessor, released state, expired state, invalid claim version, incoherent pattern identity,
declared interval count, missing required interval, corrupt interval aggregate); and replay probes that
corrupt the purchase, the evidence and the claim and then replay the **same command key** on both
operations, plus a funding-plan corruption that fails the binding replay while the unaffected capacity
replay still converges. Every probe asserts zero downstream truth, no silent repair, exact restoration
and normal convergence afterwards, and the release replay is covered positively and negatively too.

## 0e. Independent review correction round 5

The independent re-review of correction round 4 candidate
`6de25b8c32a21d060c27e0f98a8a05a4d1a7bfaa` (tree `24cf30abbb689d8668fc90aee2793114a736685b`)
**FAILED** on three MAJOR findings in the round-4 work; everything else passed and is unchanged.
Correction round 1, Correction round 2, Correction round 3, Correction round 4 and Correction
round 5 remain historical review evidence.

| Finding | Correction |
| --- | --- |
| `C5-MAJOR-001` release replay lifecycle/result integrity | A recorded release now replays only against the **exact** released lifecycle: the current claim state must be `released` (a coherent *active* aggregate can no longer satisfy it), the claim must carry a controlled release reason and a valid `released_at`, no interval may still be `protected`, and the recorded command must identify this exact claim with `result_state = released` and no borrowed offer identity. A release command also no longer writes the entitlement id into its `offer_id` column. |
| `C5-MAJOR-002` settlement occurrence/currency and payment-fact currency | The commitment validation now binds the settlement to the authoritative acceptance timeline (`settlement.settled_at` must equal the accepted evidence's `ingested_at`, the existing R1 fact written by the same acceptance transaction) and positively validates the settlement and payment-fact currency against the obligation, evidence, purchase and offer currency — in addition to the existing amount, ownership and occurrence correspondences. Settlement remains financially authoritative while academic effectiveness stays a derived function of obligations. |
| `C5-MAJOR-003` full Term replay command-result validation | The binding command now records its claim, and its replay requires `result_state = term_bound`, `result_id` and `term_id` equal to the revalidated Term, and `entitlement_id`, `purchase_id`, `offer_id`, `claim_id` and `student_id` equal to the revalidated commitment/claim. A contaminated command row can no longer be reported as idempotent success, and no duplicate result is created or repaired. |

Round-5 corruption evidence: the released lifecycle mutated back into an otherwise valid **active**
aggregate (asserted valid with the canonical claim validator) before replaying the same release key;
settlement occurrence, settlement currency and payment-fact currency probes; and same-key command-row
contamination probes for `result_state`, `result_id`, `term_id`, `entitlement_id`, `purchase_id`,
`offer_id`, `claim_id` and `student_id` on Term binding plus `result_state`, `claim_id` and `offer_id`
on the capacity handoff and release commands. Every probe asserts fail-closed behaviour, zero
duplicate result/mutation, no silent repair, and idempotent replay after exact restoration.

## 0f. Independent review correction round 6

The independent re-review of correction round 5 candidate
`2af26260d1ba711a18f9fc73c15923531cab69cd` (tree `46c712744ad545d7a7cb49ec03defddc97080f1a`)
**FAILED** on one MAJOR finding; everything else passed and is unchanged. Correction round 1,
Correction round 2, Correction round 3, Correction round 4 and Correction round 5 remain historical
review evidence.

| Finding | Correction |
| --- | --- |
| `C6-MAJOR-001` complete operation-specific `commercial_commands` selector shape | Replay previously validated the populated result/ownership fields but ignored selectors that should be NULL for the operation. `CommercialCommandShape` is now the one canonical complete-shape check: it enumerates every nullable command selector (`student_id`, `teacher_id`, `offer_id`, `obligation_id`, `purchase_id`, `entitlement_id`, `claim_id`, `term_id`), declares the exact selector set each operation owns, requires every owned selector to equal the revalidated aggregate, requires every other selector to be **exactly NULL** (so no foreign-but-valid identifier can be ignored), requires the command domain/operation, `result_state` and `result_id` to be exactly the revalidated result, and requires the recorded audit fields to be a valid persisted fact. |

The operation-specific selector matrix proved by replay:

| Operation | Owned selectors (must equal the revalidated aggregate) | Selectors that must be exactly NULL |
| --- | --- | --- |
| `establish_protected_capacity` | `student_id`, `teacher_id`, `offer_id`, `purchase_id`, `entitlement_id`, `claim_id` | `obligation_id`, `term_id` |
| `release_protected_capacity` | `student_id`, `teacher_id`, `purchase_id`, `entitlement_id`, `claim_id` | `offer_id`, `obligation_id`, `term_id` |
| `bind_entitlement_to_term` | `student_id`, `offer_id`, `purchase_id`, `entitlement_id`, `claim_id`, `term_id` | `teacher_id`, `obligation_id` |

Why these shapes: a capacity handoff is committed before any Term or obligation truth exists, so it
owns neither; a release is anchored on the claim alone (its offer is proved through the commitment
chain), so it owns no offer identity; and a Term binding is anchored on the Term, Enrolment and claim
— the Teacher authority for a bound Term travels with the claim and the Enrolment, so the command owns
no `teacher_id` and no `obligation_id`. The remaining `commercial_commands` columns are the row
identity (`id`, the generated surrogate `uid`), the replay lookup key (`command_key_digest`), the
compared payload digest (`command_payload_digest`), the shape-validated result pair
(`result_state`, `result_id`) and the audit pair (`created_at`, `created_by`); every selector is now
shape-validated, so no nullable selector can remain an unvalidated bypass.

Round-6 corruption evidence: same-key contamination probes that install a foreign-but-valid
identifier into each inapplicable selector — `obligation_id` and `term_id` on the handoff command,
`obligation_id`, `term_id` and `offer_id` on the release command, and `teacher_id` and `obligation_id`
on the binding command — plus a positive assertion that those selectors are genuinely NULL in the
written commands before any probe runs. Each probe proves rejection, preserved corruption, no
duplicate command or downstream truth, exact restoration and a successful idempotent replay.

## 1. What this phase owns

R1 establishes the canonical commercial authority that lets a paid Term exist without a payment
provider ever becoming business authority:

- sellable product identity for an Academy Course, with region/currency prices as data;
- bounded promotional discount authority and a distinct account-specific adjustment authority;
- an immutable purchase offer and whole-Term pricing snapshot with a deterministic calculation
  order (base → one promotion → one account adjustment → future credit seam);
- full payment and the V1 two-instalment plan, decomposed into ordered obligations;
- provider-neutral payment evidence, exact obligation settlement and derived academic effectiveness;
- purchase acceptance, a bounded 12-session entitlement, and Term binding through the *existing*
  Phase-L canonical Term authority;
- the canonical Regular recurring pattern derived only from explicit authorised facts;
- current-Term protected capacity with the mandatory succession
  Phase-Q hold → R1 protected claim → Phase-N schedule;
- commercial exceptions/reconciliation and the versioned runtime commercial policy registry.

## 2. Locked decisions (R1-D1 … R1-D12)

| Decision | Locked meaning |
| --- | --- |
| R1-D1 one commitment | A standard paid Term is always one 12-session commitment. Two instalments never create two Terms. |
| R1-D2 funding ≠ commitment | The Term allocation stays 12; commercial funding decides how much of it is currently materialisable. |
| R1-D3 exact money | Money is an integer number of minor units plus an explicit currency. No floats, no conversion, no per-charge discount. |
| R1-D4 immutable snapshot | The payable amount exists only in the Platform-issued offer; the browser, a redirect or a success page can never establish it. |
| R1-D5 whole-Term pricing | Discounts apply to the whole Term exactly once, then the plan divides the authorised amount deterministically. |
| R1-D6 settlement ≠ effectiveness | An obligation may be financially settled while its tranche is academically ineffective until every lower-sequence obligation is settled. |
| R1-D7 no fake funding | Funded sessions are derived only from accepted obligations; no mutable funded-session counter exists. |
| R1-D8 no fake Lessons | An unfunded occurrence is never a Lesson; capacity protection and Lesson existence are separate authorities. |
| R1-D9 explicit recurrence | A Regular pattern comes only from the explicit administrator-authorised first regular slot; no `intro + 7 days` inference exists. |
| R1-D10 succession | An existing capacity authority is never released until its successor is durable under the same per-Teacher scheduling root. |
| R1-D11 no invented policy | The instalment due instant and every class-B policy may remain unset; nothing lapses and no advance charge date exists until policy is configured. |
| R1-D12 provider neutrality | Stripe (or any provider) supplies evidence only; no provider column, SDK, webhook, credential or subscription object exists. |

## 3. Integration with closed authorities (additive only)

| Phase | Additive change | Why it is minimal |
| --- | --- | --- |
| L (Term authority) | `SESSION_ALLOCATION` / `REPLACEMENT_ALLOWANCE` constants; an optional caller-owned transaction and caller capability on `create()`; a protected-capacity guard before Term close/cancel | Term creation, its lifecycle and its command evidence remain Phase-L code; R1 never inserts a Term itself |
| M (Lesson authority) | Standard issuance caps at `min(recorded allocation, funded allowance)` with the distinct `standard_funding_exhausted` reason; replacement issuance reads the recorded Term change allowance | The ≤12 cap and every Phase-M invariant are preserved; a Term with no commercial funding plan behaves exactly as before |
| N (scheduling) | `assertCapacity()` also consults protected claims and the exact authorising interval is satisfied in the same transaction; a canonical schedule release returns the interval to protection | Scheduling stays Phase-N authority; no parallel schedule writer exists |
| Q (continuation) | The pre-payment hold path also refuses an interval already protected by a paid commitment | Phase-Q states, rules, tables and vocabulary are unchanged; the release still uses the existing version-checked seam |

## 4. Capacity succession

1. A verified, accepted payment creates the purchase, its entitlement and the obligation settlement.
2. Under the per-Teacher scheduling root, R1 validates the Phase-Q predecessor hold, resolves the
   committed intervals (Regular: the pattern's intervals; Flexible: only the explicitly authorised
   interval), arbitrates against existing schedules, other holds and other claims, then inserts the
   successor claim **and only then** marks the predecessor hold `released` — in one transaction.
3. Term binding consumes the entitlement and creates the Term through Phase L, records the funding
   plan and binds the claim to the Term. A Term cannot be bound before its successor capacity is
   durable (`commercial_capacity_handoff_required`).
4. Each Phase-N schedule satisfies the interval that authorised it; a released schedule restores
   protection. No interval is ever unowned and no interval is counted twice.

## 5. Testing evidence (owner-executed, disposable WordPress 6.8.3 + MariaDB 11.4.13)

| Suite | Result |
| --- | --- |
| `tests/phase-2a2r1-contract.php` and the full `tests/*contract*.php` set (32 files) | pass |
| `tests/phase-2a2r1-migration-runtime.php` | pass (Schema 25 identity, repeat safety, verifier refuses provider columns and mutable append-only columns) |
| `tests/phase-2a2r1-runtime.php` | pass (full-payment, two-instalment, early tranche 2, duplicate/mismatched/unattributed/refund evidence, succession, flexible, policy registry, exceptions, deferment allowance, Term-close guard) |
| `tests/phase-2a2r1-failure-runtime.php` | pass (10 injected write boundaries — including the initially-unattributed evidence boundary — each fully rolled back, each retry converging) |
| `tests/phase-2a2r1-corruption-runtime.php` | pass (offer, settlement, protected interval, entitlement and evidence corruption all fail closed; the correction-round-2 matrix: an account-adjustment source mutated after its snapshot, a rewritten immutable snapshot, and one stored Course/ownership corruption independently rejected by payment acceptance, capacity handoff and Term binding; the correction-round-3 commitment matrix: purchase ownership/economic corruption — including a purchase repointed at another otherwise-valid offer — entitlement ownership corruption, and a capacity claim belonging to another commitment; the correction-round-4 matrix: the exact acceptance evidence/settlement/payment-fact chain, the idempotent existing-claim aggregate and replay integrity after at-rest corruption; and the correction-round-5 matrix: release-replay lifecycle/result integrity against an otherwise valid active aggregate, the settlement occurrence/currency and payment-fact currency chain, and same-key command-result contamination on capacity handoff, claim release and Term binding — each rejected at the owning boundary, never silently repaired, with no duplicate result, and converging after exact restoration) |
| `tests/phase-2a2r1-concurrency-runner.sh` (`duplicate_evidence`, `handoff_vs_schedule`, `settlement_vs_lesson_seven`, `unrelated_commitments`, `promotion_global_limit`, `conflicting_evidence_replay`, `release_vs_satisfaction`, `unattributed_conflict`, `unattributed_convergence`) | pass (nine executed modes; the last two are the correction-round-2 initially-unattributed evidence races with conflicting and with identical immutable facts) |
| `tests/phase-2a2l-runtime.php`, `tests/phase-2a2m0-runtime.php`, `tests/phase-2a2m-runtime.php`, `tests/phase-2a2n-runtime.php`, `tests/phase-2a2o-runtime.php` (adjacent regressions for the authorities R1 integrates with) | pass |
| `tests/phase-2a2p-runtime.php` (regression) | **cannot run green on a freshly built disposable runtime, and fails identically on the untouched base `1b9d7aae`** (`no_available_source` at its own fixture step). Pre-existing environment/fixture dependency, not an R1 regression |
| `tests/phase-2a2q-runtime.php` (regression) | **cannot run green on a freshly built disposable runtime, and fails identically on the untouched base `1b9d7aae`** (`no_available_source`, and `assignment_changed` in its own assignment-replacement scenario, depending on fixture order). Pre-existing environment/fixture-order dependency, not an R1 regression |

PHP lint (311 files under `src/` and `tests/` plus the plugin file) and shell syntax for the
concurrency runner are clean, `git diff --check` reports nothing, and the whole matrix above was
re-executed from a genuinely fresh clone of `origin` at the pushed candidate commit.

## 6. Exclusions

No Stripe API, Checkout, webhook endpoint, credential or live provider call; no automatic recurring
charge execution; no cross-Term recurring-enrolment authority, renewal guarantee, recovery or lapse;
no renewal-date movement; no notification delivery; no Theme, Student/Teacher/Admin portal or
wp-admin screen work; no gift cards or stored-value ledger; no refund academic consequences; no
Teacher payout, accounting, tax or invoice work; no deployment or production cutover; no change to
any Phase J–Q accepted product decision.

## 7. Deferred to Phase R2

Recurring enrolment (frozen currency, mode, state), renewal cycles with a progression-derived
next-Term boundary, automatic and manual collection modes, the four-week manual same-slot guarantee,
continuous automatic protection, recovery representation, cancellation/lapse and capacity release,
the refund/reversal review trajectory, and the channel-neutral notification intents listed in the
policy registry.

## 8. Known limitations / follow-up

- The sellable product references the Academy Course that the canonical Enrolment is bound to; the
  paid relationship currently inherits the Course identity of the accepted service arrangement.
  Selecting a distinct paid-Term Course is future authority, not an R1 decision.
- Protected-capacity claims are Task-scoped to the current Term. Cross-Term protection is Phase R2.
- `INSTALMENT_DUE_DATE_POLICY` is registered but not interpreted: R1 records an explicit authorised
  due instant when one is supplied and never invents a number.
- Phase-Q `expiry_vs_new_claim` harness hygiene patch (`/tmp/phase2a2q-test-harness-stability.patch`)
  remains outstanding and deliberately untouched by this candidate.
