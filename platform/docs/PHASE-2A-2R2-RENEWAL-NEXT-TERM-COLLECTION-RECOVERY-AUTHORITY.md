# Phase 2A.2-R2 — Renewal, Next-Term, Recurring Enrolment/Collection, Recovery, Lapse & Refund Authority

**Status:** candidate (implementation). Awaiting independent review; not merged, not deployed.
**Schema:** 26 / `026_renewal_recurring_enrolment_authority`
**Build:** `phase2a2r2-renewal-next-term-collection-recovery-20260923.1`
**Base:** `main` @ `f9df3bfb0fda79fba7dee916c4687464ee67d480` (Schema 25 / R1 merged, closed)
**Governing contract:** [PHASE-2A-2R2-IMPLEMENTATION-CONTRACT.md](PHASE-2A-2R2-IMPLEMENTATION-CONTRACT.md)

## Authority

R2 is an orchestration and cross-Term authority layer above R1. It owns:

- **Recurring Enrolment** — frozen currency/region, mutable audited `manual`/`automatic` collection
  mode, and an `active/suspended/closed` lifecycle.
- **Renewal Cycle** — progression-derived next-Term boundary, manual same-slot guarantee
  (`n = 4` weeks), payment requirement, collection confirmation, next-Term binding, lapse/cancel.
- **Collection Intent** — provider-neutral `manual_payment_required`/`automatic_charge` states only.
- **Recovery** — provider-neutral representation; no automatic lapse while the recovery policy is
  unset.
- **Refund/Reversal review** — record and route for human review; `academic_consequence` stays NULL.
- **Continuous protection** — links a renewal cycle to an R1 protected-capacity claim.

It never reimplements R1 pricing, acceptance, funding derivation, protected claims or Term binding.
Next-Term creation delegates to `CommercialTermFundingService` → Phase-L
`CanonicalTermAuthorityService`; protected-capacity release delegates to `CommercialCapacityService`.

## Authority preservation

Every next-Term purchase uses the existing R1 chain (offer → accepted payment evidence → purchase →
bound entitlement → protected-capacity handoff → Phase-L Term → funding plan). R2 records only the
cross-Term orchestration outcome, and every R2 mutation is serialised on the R1
`commercial_account_roots` row for the owning beneficiary Student, so the fixed R1 lock order
(commercial account root → canonical Enrolment → ascending Teacher → Assignment → per-Teacher
scheduling root) is never inverted. R2 owns no independent lock, no provider column, and no
Term/Lesson/schedule writer.

## Boundary derivation

`RenewalCycleService` derives the next-Term boundary as the first R1 Regular recurring-pattern
occurrence strictly after the current Term's last applicable scheduled interval. Occurrences are
resolved through the canonical pattern authority and the Phase-Q wall-clock rule, so a DST transition
shifts the UTC instant without changing the agreed local class time. The boundary is never
`intro + 7 days`, never inferred from provider data, and fails closed with `boundary_facts_required`
when the current Term schedule or the authorised pattern is unavailable.

The manual guarantee window is the locked `n = 4` weeks resolved in the pattern timezone and recorded
on the cycle as `guarantee_deadline_at`; the advance automatic-charge instant is derived only when
`AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` is recorded.

## Correction round 1 — derived-fact ownership and terminal-cycle integrity

The first candidate hardened five boundaries that a reader of the contract can check directly. None of
them adds product policy, a provider column, a lock, a Term/Lesson/schedule writer or a delivery path.

1. **Source-Term authority.** `open_cycle` derives the boundary from the recurring enrolment's *own*
   canonical Term history only. An unknown Term, another Enrolment's Term, a legacy Term, an archived
   Term or a cancelled Term fails closed with `canonical_source_term_required` before any fact — or the
   cycle row — exists.
2. **Collection-obligation ownership.** A `manual_payment_required`/`automatic_charge` intent may only
   name an R1 obligation issued to the same beneficiary Student and Course in the cycle's frozen
   currency; anything else fails closed with `collection_obligation_ownership_conflict`, so another
   Student's settled obligation can never be presented as this cycle's collection.
3. **Authoritative first obligation.** `confirm_collection` consumes the cycle's earliest
   non-cancelled collection intent. A later obligation — tranche 2 may settle early — can never collect
   the cycle on its own, and a cancelled intent can never stand in for one.
4. **Terminal protection precondition (§5.6).** A cycle may only lapse or be cancelled once every
   continuous protection it owns has been released through the protected `release_protection` command,
   which delegates to the R1 capacity authority under the same per-Teacher scheduling root. A lapse or
   cancellation with an active protection fails closed with `recurring_protection_release_required`
   instead of releasing R1 capacity as a silent side effect or orphaning a live claim behind a terminal
   cycle. This is the same "release active protection first" rule the contract states for closing a
   recurring enrolment.
5. **Closure guard completeness (§5.1).** Closing a recurring enrolment is blocked by an open
   `open`/`review_required` refund/reversal review on its own canonical Enrolment, in addition to an
   active protection or an open recovery case (`recurring_enrolment_not_closable`).

The Schema 26 verifier now also rejects any table smuggled in beside the phase storage: a table that
claims an R2-owned prefix without being one of the eighteen declared tables fails migration
verification closed (`unexpected renewal storage`).

## Correction round 2 — the successor Term position, cross-commitment ownership and accepted evidence

The second pass corrected one orchestration defect that would have blocked the documented renewal
path and closed three cross-commitment gaps in the same family as correction round 1. It adds no
product policy, no provider column, no lock, no Term/Lesson/schedule writer and no delivery path.

1. **The Phase-L aggregate position is forwarded, never omitted.** Phase L (`CanonicalTermAuthorityService::create`)
   refuses to create a successor Term unless the caller proves the aggregate position it replaces —
   the latest canonical Term of the Enrolment and its terminal state (`closed`/`cancelled`) — and a
   real renewal always has a current Term, so the previous `bind_next_term` shape could never have
   completed. `bind_next_term` now takes the operator's authorised
   `expected_latest_term_id`/`expected_latest_state`, proves from stored facts that the Term is the
   latest non-archived canonical Term of the cycle's *own* Enrolment and that its lifecycle state is
   that terminal state (`renewal_aggregate_position_required`, `renewal_aggregate_position_mismatch`),
   and forwards the pair to R1. R2 still never closes, cancels or guesses a Term: the position is an
   authorised fact supplied to the command, and Phase L remains the sole authority that acts on it.
2. **Next-Term entitlement ownership.** Before delegating, `bind_next_term` proves the entitlement's
   purchase beneficiary Student, Course and currency are the cycle's own
   (`renewal_entitlement_ownership_conflict`), so another commitment's purchase chain can never be
   recorded as this cycle's next Term. After the delegated binding, the created Term's funding plan
   must additionally belong to a canonical Enrolment of the same Student and Course
   (`renewal_term_binding_conflict`).
3. **Continuous-protection claim ownership.** `establish_protection` now re-proves the claim inside
   the serialised transaction (a provisional read is no longer trusted): the adopted claim must be
   `active` and belong to the cycle's own beneficiary Student and Course
   (`recurring_protection_claim_conflict`), and a claim may not be adopted by a second cycle
   (`recurring_protection_already_exists`).
4. **Accepted-evidence settlement (§5.3).** A collection intent — and the cycle it collects — may
   only be confirmed by *accepted* R1 payment evidence for the exact obligation. `RecurringSupport::settlementReason()`
   is the single seam both consumers use: no settlement at all stays `obligation_not_settled`, and a
   settlement recorded against evidence R1 never accepted (`rejected`/`unmatched`) now fails closed
   with `accepted_payment_evidence_required`. This gives the declared
   `CONFIRM_REQUIRES_ACCEPTED_EVIDENCE` rule an actual enforcement point.
5. **Refund-review evidence ownership.** `record_refund_evidence` proves the reviewed purchase
   carries the recorded currency, that the obligation belongs to that purchase, and that the evidence
   is that purchase's and obligation's *accepted* payment evidence
   (`refund_review_evidence_conflict`, `accepted_payment_evidence_required`).

The Schema 26 verifier additionally rejects a raw key or raw reference column on any append-only
phase table (`renewal evidence must stay digest-only`), so the digest-only storage rule of the
contract is enforced by the verifier and not only by application habit.

The shared R2 fixture was also corrected: a funded Enrolment now carries its authoritative
occupancy — the funded Term is activated, receives a Teacher Assignment, one standard canonical
Lesson and that Lesson's first schedule version — because the boundary derivation reads the current
Term's applicable schedule versions. A fixture without those facts could only ever have proved
`boundary_facts_required`, never the derivation itself.

## Pre-implementation prerequisites closed with this candidate

§13 of the governing contract lists prerequisites that gate *execution* of R2. Each is closed here,
and each closure is additive to the corresponding authority rather than a change of it.

1. **Concurrency runner executability (§13.1).** The disposable-runtime host validation of
   `f9df3bf` failed every R1 concurrency mode with `/tests/phase-2a2r1-concurrency-runner.sh:
   Permission denied`, because the runner was committed without its executable bit. Every
   `tests/*-concurrency-runner.sh` — the six that were mode `100644` (`2a2g`, `2a2i`, `2a2o`,
   `2a2p`, `2a2r1` and the new `2a2r2`) and the seven already `100755` — is now committed as
   `100755`, so the documented `phase-2a2r1-concurrency-runner.sh MODE` / `phase-2a2r2-concurrency-runner.sh MODE`
   invocations are executable in a fresh clone.
2. **Phase-P/Phase-Q fixture-order runtime failure (§13.2).** The same host validation failed the
   *R1 failure runtime* with `InvalidArgumentException: teacher_slot_conflict` raised at
   `CanonicalContinuationService::holdFirstRegularSlot()` while the shared fixture built its second
   scenario. The cause is fixture order, not authority: the disposable runtime keeps one database per
   suite, the shared helper `tests/phase-2a2r1-fixture.php` derived every scenario's authorised first
   regular slot from the same fixed formula, and an earlier suite legitimately left *applicable
   canonical Lesson schedules* for the same Teacher (the fixture uses one Teacher throughout) at that
   exact interval — so the next suite's Phase-Q hold collided with a real, correct occupancy. The
   helper now authorises the first whole-week candidate whose interval is free of applicable
   canonical Lesson schedules, effective Phase-Q holds and active protected R1 capacity intervals,
   and fails loudly if none exists. The slot is still an explicitly administrator-authorised record
   (`administrator_attestation`), so no authority is weakened and no suite asserts the slot's offset
   from the introduction.
3. **Stale-documentation debt (§13.3).** `ARCHITECTURE.md`, `MODULE-BOUNDARIES.md`,
   `MIGRATION-STRATEGY.md`, `COMMERCIAL-POLICY-REGISTRY.md`, `DATA-MODEL.md`, `README.md`,
   `CHANGELOG.md`, `DELNAVAZAN-CORE-CONTINUITY.md` and the Phase-R1 phase document no longer describe
   R1 as an unmerged candidate: they record R1 merged and closed at Schema 25 on `main` and the
   Schema 26 R2 candidate above it, with the three renewal/collection class-B policies recorded.
4. **Product sign-off (§13.4)** remains a product-owner decision and is *not* invented here: the four
   deferred decisions stay behind the safe defaults in the table below, and each still gates its own
   later sub-slice.

## Correction round 4 — recovery-state enforcement (§5.4)

Independent review of the round-3 candidate found one correctness gap in the Recovery Case authority:
the aggregate recorded the *representation* of a recovery but did not enforce the two states the
contract fixes for it. Both are now enforced from stored facts, inside the serialised transaction and
after the owning Student's R1 commercial account root is held, so neither can be defeated by a stale
pre-state read or by a racing transition. No product policy, provider column, lock,
Term/Lesson/schedule writer or delivery path was added.

1. **`open` records a failed collection intent.** A pending, submitted, confirmed, recovered or
   cancelled intent can never seed a recovery case, and a cycle that has already reached a terminal
   state (`lapsed`, `cancelled`, `closed`) is never reopened by a recovery record. Both are re-read
   under the lock and fail closed with `collection_intent_not_failed` / `invalid_renewal_cycle_state`,
   so a recovery case can no longer be attached to a collection that never failed or to a cycle whose
   cross-Term authority has already ended. The live-cycle vocabulary is one locked constant
   (`RecurringRule::CYCLE_LIVE_STATES`) shared with the continuous-protection guard.
2. **`recovered` records the exact R1 evidence that settled the obligation.** `mark_recovered` now
   re-proves *accepted* R1 settlement for the case's own collection intent through the same
   `RecurringSupport::settlementReason()` seam the collection commands consume: an obligation with no
   settlement fails closed with `obligation_not_settled`, and a settlement recorded against evidence
   R1 never accepted (`rejected`/`unmatched`) fails closed with `accepted_payment_evidence_required`.
   R2 still writes no settlement, reversal or clawback of its own. The proof runs after the aggregate
   is locked and before the state row is written, so a recovery can never be recorded ahead of — or on
   the strength of — evidence R1 did not accept. A recovery may be recorded from `open` or
   `recovering`: the contract orders the recovery *activity* but never requires an attempt event
   before the settling evidence.

The previous `recovered` source set (`recovering` only) was also wrong in the other direction: it made
the concurrency mode `recovery_vs_satisfaction` impossible, because its pre-state opens the case and
its holder then records the settling evidence — the assertion `the recovering worker must record the
recovery` could never hold. A recovery may now be recorded from `open` or `recovering`, which is what
§5.4 describes: the contract orders the recovery *activity* but never requires an attempt event before
the settling evidence.

`tests/phase-2a2r2-runtime.php` proves both branches — a pending and a submitted intent are refused, a
failed one seeds the case, an unsettled obligation and non-accepted evidence are both refused with
their exact shared reason, an accepted settlement records the recovery and its `PAYMENT_RECOVERED`
intent, and a failed intent of a cancelled cycle is refused without reopening the cycle — while
`tests/phase-2a2r2-contract.php` asserts the enforcement points and the single live-cycle vocabulary.
The failure-injection suite opens the recovery case while its intent is still failed, matching the
enforced order.

## Correction round 5 — refund provenance, derived mode and charge, protection release, fail-closed reads

The independent review of the host-materialized candidate (host correction round 2 of this task chain;
failed candidate `98b01601a8005efada67553fc1b5a4bba7d284bc`, tree
`1d21b3c1963425f962d829bb9633643a584cd03f`) returned **FAIL — CORRECTION REQUIRED** on five blocking
findings. Each is closed from stored facts, inside the serialised transaction, without adding product
policy, a provider column, a lock, a Term/Lesson/schedule writer or a delivery path.

1. **Refund/reversal provenance (§5.5).** `record_refund_evidence` no longer accepts *any* accepted
   payment evidence with a caller-asserted kind and sum. The reviewed evidence must really be that
   purchase's and obligation's *accepted* evidence **of the reviewed kind**, and its exact amount and
   currency are adopted as the recorded review sum: an ordinary successful payment fails closed with
   `refund_review_evidence_conflict`, a caller value that disagrees with the evidence with
   `refund_review_amount_conflict`, and evidence R1 never accepted with
   `accepted_payment_evidence_required`. A `reversal` is refused outright with
   `reversal_evidence_not_supported`, because R1 records no authoritative reversal representation
   (`CommercialRule::EVIDENCE_KINDS`) and R2 must not invent one; when R1 represents reversals the
   locked `RecurringRule::REVIEW_EVIDENCE_KINDS` list is the single seam that admits them.
2. **The cycle mode is snapshotted, never supplied (§5.1/§5.2).** `open_cycle` locks and reads the
   recurring enrolment and derives the cycle's `collection_mode` from *its* recorded mode; the previous
   free input is gone, and a caller that restates a different mode fails closed with
   `recurring_collection_mode_conflict`. A manual cycle can therefore no longer be opened from an
   automatic enrolment (or the reverse), and the audited
   `collection_mode_changed` history keeps its meaning.
3. **The collection intent is lifecycle- and mode-bound, and its charge instant is derived (§4/§5.3).**
   A collection intent opens only on a live `payment_required` cycle (`invalid_renewal_cycle_state`) —
   a pending, terminal or already-collected cycle owns no open collection — and only in the kind the
   cycle's frozen mode authorises (`collection_intent_kind_conflict`). `charge_at` is derived solely
   through the single `RenewalCycleService::automaticChargeAt()` seam from the recorded
   `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` policy and the cycle's own derived boundary; a caller-supplied
   charge instant is refused with `collection_charge_time_not_authoritative` rather than silently
   ignored, so the unset-policy safe default (no advance charge date, no
   `AUTOMATIC_RENEWAL_UPCOMING`) cannot be bypassed. Obligation ownership is still proven first, so
   another Student's settled obligation can never be presented as this cycle's collection.
4. **Protection binds the current-Term claim, and its release is authorised (§5.6).** The adopted claim
   must be the *active claim of the cycle's own recorded `source_term_id`* for the cycle's own Student
   and Course: another active claim of the same Student — including the successor-Term claim the
   renewal itself creates — fails closed with `recurring_protection_claim_conflict`, and the live cycle
   state is re-proved inside the serialised transaction rather than trusted from the provisional read.
   `release_protection` now requires either a **durable successor** (the cycle's next Term recorded and
   carrying its own R1 funding plan *and* its capacity claim) or an **authorised terminal path**: the
   underlying claim is already released in R1 (the capacity has returned to the Teacher through R1's own
   authority — recording that durable fact is the only way a release whose R1 half committed before an
   R2 write-boundary failure can converge, and it never releases anything new), the cycle itself already
   reached `lapsed`/`cancelled`, or an explicit, evidenced terminal recovery lapse of that very cycle.
   Anything else — notably the ordinary "release the guaranteed slot early" shape — fails closed with
   `renewal_successor_not_durable` *before* the delegated R1 capacity release is invoked, so a
   predecessor claim can never be dropped while a successor is still missing, and no silent release
   becomes possible.
5. **Every read model fails closed on a malformed aggregate (§7.3).** `RecurringIntegrity` proves each
   stored aggregate against its own append-only history before any read returns it: gap-free contiguous
   `event_sequence`, a leading null `from_state`, a chain in which every event continues from its
   predecessor, only the locked legal transitions and event types, same-state events only for the
   declared bookkeeping types (`collection_mode_changed`, `extended`, `attempt_recorded`), the final
   event agreeing with the recorded current state, and the recorded aggregate version equal to the
   number of events that advanced it (every event except the version-neutral `extended`, which appends
   history without changing state or version). The six read services additionally re-prove the linked
   ownership facts — the canonical Enrolment, the cycle's own source Term and currency, the cycle's own
   obligation, the recovery case's own cycle and intent, the reviewed purchase/obligation/evidence, and
   the protection's claim Term. The recovery case also records its attempt with the declared
   `attempt_recorded` event type rather than the state name, so the locked event vocabulary is the one
   the services write. The review's example (a row rewritten to `closed` while its history still ends at
   `established`) is now a refused read, and
   `tests/phase-2a2r2-corruption-runtime.php` proves both directions: a rewritten row and a rewritten
   history row.

The proofs were extended accordingly: `tests/phase-2a2r2-runtime.php` exercises every new guard, the
successor-Term claim refusal and the terminal-lapse release path; the corruption suite adds the
version/row/history probes and the exact refund-evidence provenance; the failure-injection suite moves
its protection block behind the delegated binding so the release it exercises is authorised; the
concurrency pre-state builds a durable successor Term for `release_vs_succession`, records real refund
evidence for `refund_vs_settlement`, and requires payment before a collection intent; and
`mode_change_vs_cycle` now proves the cycle inherits the *committed* recorded mode rather than a
caller-supplied one.

## Correction round 6 — per-aggregate event-type proof, audited mode continuity and fail-closed history reads

The independent review of the correction candidate (host correction round 3 of this task chain; failed
candidate `54a4ce29ea3d6dab9ea39475a2f6072057b94965`, tree
`d12c3f0584873fe73c386f6cbf4ac979193bd348`) returned **FAIL — CORRECTION REQUIRED** on two blocking
findings. Both are closed additively, without adding product policy, a provider column, a lock, a
Term/Lesson/schedule writer or a delivery path.

1. **The event type is proved against the transition it records (§5.1, §7.3).** The previous proof
   accepted any event type named in the aggregate's declared vocabulary, and it ignored
   `from_collection_mode`/`to_collection_mode` entirely for recurring-enrolment events. A
   valid-but-forged event type therefore passed the state and version checks while auditing a fact the
   aggregate never recorded, and a current mode rewritten from `manual` to `automatic` — a *valid*
   value — was returned as authority although no coherent audited mode history recorded it.
   `RecurringRule::AGGREGATE_EVENT_TRANSITIONS` is now the per-aggregate event-type/transition map: one
   entry per legal transition of the locked `AGGREGATE_TRANSITIONS` table, naming the single event type
   that may record it. `RecurringIntegrity` refuses a pairing the map does not carry, so a `resumed`
   event that does not resume, a `guarantee_protected` event that protects nothing, or an `extended`
   event rewritten to `released` is a refused read rather than an alternative spelling of the audited
   history. `tests/phase-2a2r2-contract.php` proves the map is *exactly* the locked transition table —
   no legal transition left unrecorded and no transition invented — by comparing the two locked
   constants directly.
2. **The recurring-enrolment collection mode is proved as an audited history (§5.1).** The mode is a
   mutable audited attribute, so it is proved exactly the way the state is: the opening event must carry
   one controlled mode, every later event must continue the mode its predecessor recorded, only a
   `collection_mode_changed` event may change it (and such an event must actually change it), every
   other event must leave it unchanged, and the final event's mode must equal the row's recorded
   `collection_mode`. A row rewritten to the *other* valid mode, an opening event the following event
   does not continue, and a mode change rewritten to change nothing all fail closed with the
   aggregate's own `recurring_enrolment_integrity_conflict` reason.
3. **A repeated recovery attempt stays legal and readable (§5.4).** The contract keeps attempts as
   append-only events precisely because there is no mutable attempt counter, so
   `record_recovery_attempt` on a case that is already `recovering` records a same-state
   `recovering|recovering` event. That step is now in the locked transition table and the event-type map
   records it as `attempt_recorded`, so a legitimately written second attempt is no longer
   indistinguishable from corruption; the runtime proves the repeat stays readable.
4. **Every public history read is the same fail-closed seam (§7.3).** `events()` previously returned the
   raw history table, so a malformed or orphaned aggregate handed out its events even though `one()`
   refused the aggregate. Each of the six read services now shares one private validated loader — the
   capability check, the linked-ownership facts and the `RecurringIntegrity` proof of the row *and* the
   append-only history — and both `one()` and `events()` call it, so `events()` can only ever shape the
   events of an aggregate that has already been proved.

`tests/phase-2a2r2-corruption-runtime.php` records its audited mode history through the real
`set_collection_mode` command and adds: a current row rewritten to the valid alternate mode, an opening
mode the following event does not continue, a mode change rewritten to change nothing, a
valid-but-forged event type on the recurring-enrolment and protection histories, and a fail-closed
history read for each of the six public seams (including an orphaned recovery history).
`tests/phase-2a2r2-runtime.php` proves the repeated recovery attempt stays readable and append-only, and
`tests/phase-2a2r2-contract.php` asserts the new rule table, the exact map↔table equality, the shared
validated loader and every new probe.

## Delegation is convergent, never nested

R2 never opens a transaction around a delegating R1 command. `bind_next_term` and
`release_protection` let R1 own its own transaction (Term + funding plan + entitlement/claim binding,
or the protected-interval release under the same per-Teacher root), then re-open R2's serialised
transaction, verify the durable R1 outcome and record it. A write-boundary failure between the two
halves leaves the R1 fact durable; the retry adopts it rather than duplicating a Term, funding plan,
entitlement or release. `bind_next_term` forwards the caller's authorised aggregate position into the
delegated R1 command, so the convergent retry keeps the same position and converges on the same Term.
`tests/phase-2a2r2-failure-runtime.php` proves both halves of that contract.

## Channel-neutral notification intents

The finalised intents are published through the existing `platform_outbox` seam with a keyed digest
identity: `AUTOMATIC_RENEWAL_UPCOMING`, `AUTOMATIC_RENEWAL_CHARGED`, `AUTOMATIC_RENEWAL_FAILED`,
`MANUAL_RENEWAL_PAYMENT_REQUIRED`, `GUARANTEE_DEADLINE_APPROACHING`, `GUARANTEE_EXPIRED`,
`PAYMENT_FAILED`, `PAYMENT_RECOVERED`, `TERM_LAPSED`, `REFUND_REVIEW_REQUIRED`, `REFUND_RESOLVED`.
These are intent names only: no template, no recipient and no delivery record exists. Delivery belongs
to Phase S; the charge adapter to Phase T.

## Safe defaults (undecided product policy)

| Decision | Seam |
| --- | --- |
| Refund academic consequences | `refund_review_cases.academic_consequence` is never written; reads report `academic_consequence_state = unresolved`; no funded-session clawback or settlement reversal |
| Automatic renewal opt-in/lead time | `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` unset means no advance charge instant and no `AUTOMATIC_RENEWAL_UPCOMING`; `collection_mode` is recorded only |
| Recovery/lapse thresholds | `PAYMENT_RECOVERY_POLICY` unset means no automatic lapse and capacity stays protected; an explicit evidenced administrator command is the only lapse path |
| Provider-specific confirmation | Provider-neutral only; confirmation arrives through R1 `commercial_payment_evidence` |

## Out of scope

Stripe SDK/webhook/credentials/live charge, notification delivery/templates, refund academic
consequences, automatic-charge execution, Teacher payout/accounting/tax, portals/public routes,
Theme, deployment and production access.

## Test matrix

| Suite | Required proof |
| --- | --- |
| `tests/phase-2a2r2-contract.php` | Schema 26 identity, build shape, migration/verifier call sites, rule constants, intent set, capability boundaries, no provider column, no FK/CHECK, immutable and digest-only append-only storage, exactly-eighteen-table installer set, the R1 serialization root, the outbox seam, delegation is not nested, the forwardable Phase-L aggregate position, the correction-round-2 ownership guards, the correction-round-5 derived-mode/charge, refund-provenance, protection-release and read-integrity guards, and the seven concurrency modes |
| `tests/phase-2a2r2-migration-runtime.php` | Fresh Schema 26; additive 25→26 ledger proof; no backfill/inferred renewal; repeat safety against a retained R1 sentinel; provider/mutable/digest/index/academic/raw-key/raw-reference malformed-storage rejection with exact restoration; a smuggled-table probe; retained-026 stale-version fail-closed; partial capability repair |
| `tests/phase-2a2r2-runtime.php` | `funding_plan_required`; establishment and digest-only replay; audited collection-mode change; the cycle snapshotting the recorded enrolment mode and refusing a conflicting input; derived boundary and price snapshot; manual guarantee; the payment-required lifecycle of a collection intent, its mode-bound kind, its derived (never caller-asserted) charge instant and obligation ownership; the forwarded aggregate position and its fail-closed refusals; next-Term R1 offer/acceptance/binding orchestration; continuous protection, its current-Term claim binding, its delegated release and the authorised terminal-lapse path; refund review trajectory with authoritative refund evidence and the refused reversal; recovery/lapse with the policy unset and with explicit evidence; the §5.4 recovery-state enforcement (failed source intent, live cycle, accepted R1 settlement); terminal lifecycle constraints; accepted-evidence settlement; cross-commitment ownership refusals; intent-set conformance |
| `tests/phase-2a2r2-corruption-runtime.php` | Frozen currency/mode, boundary/guarantee, price snapshot, recovery/lapse, cross-Term protection ownership, refund evidence (kind, sum, currency and authoritative-evidence provenance), rewritten current rows, rewritten history rows, unsupported aggregate versions and digest-only command-row corruption all fail closed, are never silently repaired, and converge after exact restoration |
| `tests/phase-2a2r2-failure-runtime.php` | An injected write boundary at every owning mutation rolls back completely; the identical retry converges; both delegating commands keep the durable R1 half and never duplicate it; a duplicate event sequence and a conflicting replay fail closed |
| `tests/phase-2a2r2-concurrency-runner.sh` | `renewal_vs_schedule`, `guarantee_vs_close`, `recovery_vs_satisfaction`, `release_vs_succession`, `mode_change_vs_cycle`, `refund_vs_settlement`, `unrelated_recurring_enrolments`, each with isolated DB state, a gated holder and a coherent-aggregate verifier |
| Adjacent regressions | Phase L/M/N/O/Q/R1 contract and pure suites, `static.php` lint, the schema contract, the fresh-install capability bootstrap and the migration-exception runtime |

### Executed outside the disposable runtime

**No runtime evidence exists yet: none of the §11 suites has been executed against this candidate.**
The authoring sandbox for this round has **no PHP binary, no MySQL/MariaDB and no reachable Docker
daemon** (`docker ps` fails with `permission denied … /Users/hamed/.docker/run/docker.sock`; no
`php`, `mysql` or `mariadb` on the host `PATH`; no loopback port for the disposable stack), so the
WordPress + MariaDB suites are authored and environment-guarded but cannot be executed here. This is
an environment limit of the authoring sandbox, not a result: nothing below may be quoted as a pass.

Executed in that sandbox (reproducible, structural only):

- a lexical balance/lint pass over every PHP file (348 files, 0 unbalanced) that strips comments and
  both quoting styles;
- an installer↔verifier cross-check of migration 026: the 18 declared tables, every
  verifier-required column and every verifier-required index exist in the installer (0 missing);
- a service↔schema cross-check: every column written by the six R2 repositories and their services
  exists in the corresponding installed table (399 written keys, 0 mismatches);
- an R2 class/file-name match (23 files) and a repository-wide reference-resolution pass over the R2
  classes and suites (661 `new`/`::` references, 0 unresolved repo-level symbols);
- a forbidden-surface scan of the R2 application layer (`stripe`, `wp_remote_`, `wp_mail`, `curl_`,
  `webhook`, `floatval`, `round(`, `INSERT INTO`, `UPDATE `, `DELETE FROM`, cron scheduling,
  parallel Term/Lesson/funding/claim writers: none present);
- an assertion-level emulation of `tests/phase-2a2r2-contract.php`: every static assertion in the suite
  (identity, storage, verifier, rule tables, capability, lock root, outbox seam, delegation, boundary
  derivation, ownership guards, the correction-round-5 guards and the concurrency modes) evaluated
  against the same file contents through an equivalent primitive: 0 failures;
- `git diff --check` clean, `sh -n` on `tests/phase-2a2r2-concurrency-runner.sh`, and all thirteen
  `tests/*-concurrency-runner.sh` committed as mode `100755`;
- the delivered 404-file working tree hashed content-addressed into a single tree object; that tree
  hash is reported in the task handover rather than embedded here, because embedding it would change
  the tree it describes. The hashing method was validated against the reviewed candidate: run against
  the unchanged candidate content it reproduces that host-materialised tree exactly
  (`1d21b3c1963425f962d829bb9633643a584cd03f`), so the reported value is the tree the host will
  materialise into the new candidate commit.

Still outstanding, and required before this candidate may be described as green — the migration,
authority, corruption, failure-injection and concurrency suites for Schema 26 plus the adjacent
Phase L/M/N/O/Q/R1 and pure regressions, all on the disposable local runtime:

```sh
# once per disposable database
DZN_PHASE_2A2J_RUNTIME_TEST=fixture wp eval-file tests/phase-2a2j-fixture.php --user=1
# Phase 2A.2-R2
DZN_PHASE_2A2R2_RUNTIME_TEST=migration   wp eval-file tests/phase-2a2r2-migration-runtime.php  --user=1
DZN_PHASE_2A2R2_RUNTIME_TEST=authority   wp eval-file tests/phase-2a2r2-runtime.php            --user=1
DZN_PHASE_2A2R2_RUNTIME_TEST=corruption  wp eval-file tests/phase-2a2r2-corruption-runtime.php --user=1
DZN_PHASE_2A2R2_RUNTIME_TEST=failure     wp eval-file tests/phase-2a2r2-failure-runtime.php    --user=1
# seven modes, each with isolated DB state
for mode in renewal_vs_schedule guarantee_vs_close recovery_vs_satisfaction release_vs_succession \
            mode_change_vs_cycle refund_vs_settlement unrelated_recurring_enrolments; do
  DZN_PHASE_2A2R2_REPO=<repo> DZN_PHASE_2A2R2_WP_DIR=<wpdir> DZN_PHASE_2A2R2_NET=<net> \
    sh tests/phase-2a2r2-concurrency-runner.sh "$mode"
done
```

The disposable harness under `runtime-r2-recovery/` currently invokes only the R1 suites, and its
`.env` `DZN_REPO_ROOT` still points at another workspace; both must be pointed at this checkout before
the R2 suites and modes can be run. That is owner/host work, not candidate code.
