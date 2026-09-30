# Delnavazan Core — Living Project Continuity Record

> **Current-source reconciliation (2026-09-27):** The reviewed local `main` baseline is
> `5bd376881769b15a27f5b20a5c02cef5bad0397c` (tree `f6dac0bd978e62ecef3247a5f64e8957b1b96d5a`);
> package constants declare Schema 33 (migrations
> 001–033) and the unchanged Notification-Communications build
> `phase2a2s-notification-communications-authority-20260924.1`. Schema 33 is the additive
> `033_core_dataset_technical_prerequisites` migration after the historical Schema-032 Notifications S
> re-land. The formerly recorded `4ccd43c62d1fd8311cce75bac55681b81b85eda9` atop `origin/main`
> `6673e7bb6b0dc267d455b61220c3da77eb1b4356` is a historical review snapshot, not the current HEAD
> or checkout. This is source
> topology/implementation presence only, not an independent-review, deployment, public-enablement or
> cutover claim. The source-derived W boundary is recorded in
> [Portal authorization registry](PORTAL-AUTHORIZATION-REGISTRY.md).
>
> **Notifications S topology truth:** the reviewed Schema-32 Notifications S re-land
> (`56cd831ae09b89baf5c1ca396a68c05411439213`) **is** merged into this integrated candidate. The
> unrelated recovery ref `origin/recovery/platform-s-notifications-3f67d3d` at
> `16039363957afa8cabb72ee60c0374ac4160fb5e` is still **not an ancestor** of this tree; its merge-base is
> `559b1736621c9ed32e41dd2b785dd0f040dcb647`, and queue/history metadata about it must not be described as
> that merge. No notification activation or delivery is authorised by this documentation update.

**Continuity snapshot:** 19 September 2026
**Purpose:** Durable handover for a new CD/ChatGPT session or implementation agent. Source, migrations and locked domain contracts remain authoritative; this record supplies the current state, boundaries and next action without relying on chat history.

## 1. Current state

### 1.1 Current authoritative source inventory (2026-09-27)

| Item | Authoritative source state |
|---|---|
| Repository | `Mr-M13/delnavazan-platform` |
| Current reviewed source baseline | Local `main` `5bd376881769b15a27f5b20a5c02cef5bad0397c`, tree `f6dac0bd978e62ecef3247a5f64e8957b1b96d5a`; `4ccd43c62d1fd8311cce75bac55681b81b85eda9` atop `origin/main` `6673e7bb6b0dc267d455b61220c3da77eb1b4356` is a historical review snapshot only. |
| Platform / declared schema | 0.1.0 / **Schema 33** |
| Migrations | 001–033; latest `033_core_dataset_technical_prerequisites`, additive on historical Schema 32 |
| Build identity | `phase2a2s-notification-communications-authority-20260924.1`; the W slice's own stamp is `phase2a2w-portal-facing-services-20260927.2` |
| Completed authority chain | Phases 2A.2-A … 2A.2-Q are merged and closed (Schema 24 at Q); Phase 2A.2-R1 is merged and closed (Schema 25). |
| Additive candidates present on current main | Phases R2 (Schema 26), V (Schema 27), T (Schema 29), U (Schema 30), W (Schema 31) and S (Schema 32) are present in this checkout as additive candidate trees with their recorded review/activation posture unchanged: independently reviewed and closed where a merge record says so, otherwise awaiting independent review; none deployed or activated. |
| Schema-33 readiness storage | Five additive, append-only readiness-evidence tables: `core_dataset_provenance`, `core_dataset_reconciliation_runs`, `core_dataset_reconciliation_findings`, `core_dataset_operator_commands` and `core_dataset_corrections`. Migration 033 is verified before it is added to the completed ledger and before `dzn_platform_schema_version` may advance; it creates no domain rows and performs no backfill. |
| Current top candidate | The local, unmerged Schema-33 readiness-storage candidate awaits independent review and runtime evidence. The historical Schema-32 W/S integration remains source provenance; public actions remain exact-option gated and source presence is not review, deployment or enablement. |
| Notifications S topology | The reviewed Schema-32 S re-land `56cd831ae09b89baf5c1ca396a68c05411439213` **is** merged into this integrated candidate. The unrelated recovery ref `origin/recovery/platform-s-notifications-3f67d3d` at `16039363957afa8cabb72ee60c0374ac4160fb5e` is not an ancestor; merge-base `559b1736621c9ed32e41dd2b785dd0f040dcb647`. |
| Next Platform action | **Independent review of the Schema-33 readiness-storage candidate and its outstanding runtime evidence.** No live credential, provider traffic, production data, live charge/refund, notification delivery, payout, Theme change, deployment or production cutover is authorised. |

This inventory records source topology and implementation presence only. It does not mark any candidate independently reviewed, deployed, publicly enabled or cut over.

### 1.2 Historical candidate and merge records (provenance only)

The table below is the prior continuity snapshot (19 September 2026) retained as historical provenance. Its “current authoritative main”, “active candidate” and “Schema 24 / Phase Q” values describe that snapshot, not current source; current source is Schema 33 as recorded in §1.1. Its candidate narratives remain each phase’s historical record.

| Item | Authoritative state (historical snapshot) |
|---|---|
| Snapshot scope | Every use of “current”, candidate status, Schema 24–32 or build identity in this table is historical snapshot provenance only; the current checked-in package is Schema 33 per §1.1. |
| Repository | `Mr-M13/delnavazan-platform` |
| Pre-Phase 2A.2-M main | `c83c1f99557b2cd1f2f81aa82a05177887a468e6` |
| Phase 2A.2-M approved candidate | `f44b502509f5dcb9b5d0281cfda4329407abd3bf`; tree `85a8309bb6179a2061c86245ea1ad9a975c00c2e` |
| Phase 2A.2-M implementation merge | `ed11086ad8ddc65899c3b855248611b1eb9e09a4`; tree `85a8309bb6179a2061c86245ea1ad9a975c00c2e` |
| Platform | 0.1.0 |
| Historical current authoritative main (at snapshot) | Phase 2A.2-Q implementation merge `815a301679fdebc5d058646749e5a206ab492629`; the docs-only closeout commit that follows is recorded in the task closeout because a commit cannot embed its own hash. Historical: superseded by the Schema-33 inventory in §1.1. |
| Active Platform candidate | Phase 2A.2-R1 — Commercial Purchase, Funding & Current-Term Capacity Authority. Branch `phase-2a2r1-commercial-purchase-funding-authority`, authoritative base `1b9d7aaef0ca21fdb861ccc9da1d15634cb5d843`, Schema 25 / migration `025_commercial_purchase_funding_authority` / build `phase2a2r1-commercial-purchase-funding-authority-20260920.1`. **Correction round 6 is the current candidate on the R1 branch, awaiting independent re-review; not merged into `main`, not deployed.** Correction rounds 1–5 (`10fe4061`, `186fc501` → `3aaf3081`, `2ff3d6e3`, `6de25b8c`, `2af2626`) are immutable historical review evidence: round 1 failed on eight findings, round 2 passed all but `R1-MAJOR-007` / `NEW-C2-001` (complete purchase/entitlement commitment ownership before capacity and Term truth), round 3 passed all but `NEW-C3-001`/`NEW-C3-002`/`NEW-C3-003` (acceptance-fact chain, existing-claim integrity, replay after at-rest corruption), round 4 passed all but `C5-MAJOR-001`/`C5-MAJOR-002`/`C5-MAJOR-003` (release-replay lifecycle, settlement/fact timeline, command-result validation), and round 5 passed all but `C6-MAJOR-001` (complete operation-specific command selector shape). Candidate commit/tree SHAs are recorded in the task closeout because a commit cannot embed its own hash. Historical snapshot: `main` was Schema 24 / Phase Q at that time; current source is Schema 33 per §1.1. |
| Phase 2A.2-U finance candidate | Schema 30 / migration `030_finance_payability_rate_statement_authority` / build `phase2a2u-finance-payability-rate-statement-20260925.1`, strictly additive on the Phase-T candidate tree (`b36561dc6bb6e87fd142a28ae67fbc4f2fdc9279`, Schema 29) and explicitly scoped to that recorded base. Implements the contract `docs/PHASE-2A-2U-FINANCE-PAYABILITY-RATE-STATEMENT-AUTHORITY-CONTRACT.md` (SHA-256 `402f1334dd169a62aea450929d3bee25a116930c3e93bf69fa175a41bed6d79b`, whose §0g records correction round 2, whose §0h records correction round 3, whose §0i records correction round 4 and whose §0j records correction round 5): the versioned four-key finance policy registry with its seeded singleton global serialisation root, the temporal admissibility guard over both effective-dated registries, effective-dated scoped teacher rates with three mutable columns and interval-based resolution, one immutable per-Lesson rate/currency snapshot per canonical Lesson, the versioned payability derivation with an audited additive override, single-currency per-Teacher statements with immutable lines and a fail-closed issuance gate that stamps its one-time evidence, read-only exact-integer reconciliation, append-only audited corrections, five administrator-only capabilities, and the §15.7 allowlist (the twenty-one declared tables plus digest-only `platform_audit_events` and the three identity-only §17 `platform_outbox` intents). It creates no ledger, invoice, payout, bank/tax field, provider call, credential, route or front-end surface, and it mutates no upstream row. **It is a candidate only** — correction round 5 applied, awaiting independent review, not merged, not deployed, and scoped to the unmerged Phase-T base. **Correction round 2 closes the six blocking findings of the review of `86d57606cabcddba15d076edfe14fb4e7257e60f` (tree `6010181bfbf66e01fa49154c9ba266d1dd4888c4`)**: every `finance_*_commands` row now records its declared `result_state` and every business refusal the declared `refused` state with `NULL` typed results; a successor rate closes and supersedes its predecessor before claiming the scope's live slot; both append-only chains append with an empty `applicable_slot`, release the predecessor and then claim the slot, so exactly one applicable row survives a re-evaluation, an override or a second correction; `record()` inserts only its version and the audited `supersede()` command keeps the conditional status move; the issuance gate compares every stored line with the current effective snapshot and payability evaluation and refuses a stale draft `statement_derivation_mismatch` for withdrawal and re-drafting; and the reconciliation run closure captures its payload so a replayed run converges. Two further corrections are declared in §0g — the policy verifier now asserts the migration's seed *shape* (so a live installation that records a policy through the declared commands is never failed closed on every request) and the rate-withdrawal interval proof runs only where it writes a closure — together with two suite defects. **Its source contract suite is executed and passing** (`phase-2a2u-contract: OK`, PHP 8.5.8 WASM CLI) **and all 461 candidate PHP files parse cleanly**, while every §18 runtime, migration, corruption, failure and concurrency suite still needs the disposable WordPress + MariaDB runtime, which is unavailable in this environment, so executing them remains a mandatory acceptance gate. **Correction round 3 closes the one blocking finding of the review of `97a572937e07c021bf9c0fc9c65da23cdae92e08` (tree `370eda98f7aa84182c7d06c91cbca7caf79637c2`): every command family's idempotent replay now re-loads its recorded typed result under the root it already holds and re-proves that result's selectors and its own derivation before it may converge, so a deleted, corrupted or mismatched result fails closed `command_replay_conflict` instead of being reported as a successful replay, with a new WordPress-free §15.3 replay unit suite (`phase-2a2u-replay-unit: OK`) and one corruption-replay probe per command family added to the corruption suite.** **Correction round 4 closes the two blocking findings of the review of `5e0daf221918634a047c83e9b5034b9f833ea7d9` (tree `0968a4f6b96ea828848354b09a473c2f3001cac4`): the snapshot-correction replay now reconstitutes the recorded command payload from the re-loaded correction row and proves it with `assertReplayPayload()`, so a corrupted `result_correction_id` naming a different, self-consistent correction of the same Lesson and snapshot fails closed instead of converging on it; and §15.3 now declares the exact typed result shape of every operation (`FinanceRule::COMMAND_RESULT_COLUMNS` and `FinanceRule::COMMAND_OPERATION_RESULTS`), re-proved on every replay by the new `FinanceSupport::assertReplayResultShape()`, so no replay may return an unverified secondary typed result — the override, correction, run and exception results now come from the re-loaded rows and are cross-linked. Six substituted-secondary-result corruption probes and the extended pure replay unit suite cover it; the source contract suite and the §15.3 replay unit suite remain **executed and passing** and all 461 candidate PHP files parse cleanly.** **Correction round 5 closes the one blocking finding of the review of `f5138509a0a256df335015b1ef1d6f42dfd06b96` (tree `85db4f8afc99159e4d2f3c8e294d1247d820a899`): `corrected_rate_amount_minor` was omitted from `FinanceCorrectionService::correctionFacts()`, so a second, self-consistent correction of the same Lesson, snapshot, corrected rate row/version, corrected derived amount, currency and reason that differed only in its corrected rate amount could replay over the recorded one; the corrected rate amount is now a canonically validated fact on both the write and the replay path, contract §12.2 and §15.3 name the restated rate amount explicitly, a `command_replay_conflict` unit probe and runtime corruption probe for that field are added (with the corruption suite's original correction probe corrected to the code the service actually produces and its derivation-digest proof kept by a prior-snapshot-digest probe), and the source contract suite and the §15.3 replay unit suite remain **executed and passing**.** |
| Phase 2A.2-T payment-execution candidate | Schema 29 / migrations `028_payment_execution_seam_provider_adapter` and `029_payment_event_decision_claim_authority` / build `phase2a2t-payment-execution-seam-stripe-adapter-20260925.14`, strictly additive on top of the Phase-V candidate tree. Implements the round-14 contract `docs/PHASE-2A-2T-PAYMENT-EXECUTION-SEAM-STRIPE-ADAPTER-CONTRACT.md` (SHA-256 `bdd88f8f0a1a0d8ac16ffa82fc081d938c683d1f359ce515c1cb7c8158aa16ad`): the provider-neutral execution seam, the provider account/mapping registry, the immutable execution command with its append-only attempt/result rows and its single mutable dispatch claim (sealed descriptor, generation/token fence, reconcile-before-re-issue), the Stripe adapter and its exact-raw-body webhook route — whose `OPTIONS` delivery, which WordPress answers outside normal route dispatch, is intercepted ahead of WordPress's own handler so every registered method is receipted — the durable provider-event intake with its per-event decision claim (the mutable intake row that makes an owed decision recoverable and exactly-once — since correction round 10 its aggregate is created by the scheduled migration `029_payment_event_decision_claim_authority`, and since correction round 11 its bounded window is proved — and, outside a transaction, renewed — from inside each work unit's own transaction before every statement that unit runs, so the claim row is held for the whole transaction a unit runs in, a take-over can no longer interleave with a unit, a unit whose window closes mid-transaction is rolled back by its own service, and the stale generation releases the claim it appended nothing to, and since correction round 12 the lease bounds the decision append itself — the `claimed → settled` transition requires the owner's own live slot and an unexpired lease, so a window that lapses after the final work unit publishes nothing, the owner releases the claim it appended nothing to, and the next delivery completes the event, since correction round 13 that window is judged at the instant the transition itself runs, from one database-time expression that fences the predicate and stamps the settlement alike, since correction round 14 the transition takes the claim row's lock with its own fenced locking read before the conditional update that judges the window — a database-time expression is evaluated once, at statement start, so the wait for that row must never be the statement that judges it —), the bounded ordered R2 consequence, and the authenticated-encryption secret vault. `LIVE_EXECUTION_PROVIDERS` and `PROVISIONABLE_PROVIDERS` are empty, so no outbound call and no provider credential are reachable. **It is a candidate only** — awaiting independent review, not merged, not deployed. **Its contract, migration, runtime, webhook, secret, corruption, failure and twenty-four-mode concurrency suites have not been executed: PHP and the disposable WordPress + MariaDB runtime are unavailable in this environment**, and executing them is a mandatory acceptance gate. |
| Phase 2A.2-V reconstructed candidate | Schema 27 / migration `027_google_calendar_meet_provider_integration` / build `phase2a2v-provider-neutral-google-calendar-meet-20260925.5`, strictly additive on base `559b1736621c9ed32e41dd2b785dd0f040dcb647` (tree `4e7fb1736b4a30de323cd25fe5dd4b6ceaad65a1`). **The previously recorded Phase-V candidate `3724edb3c36959f3657e6b495ab96f26d347c476` / tree `5402cf890e2db898bccb15ba8ffc97176ced6457` was lost from every current workspace, backup object store, reflog and remote branch.** This candidate is a contract-faithful reconstruction from the completed `PLATFORM-V-GOOGLE-CONTRACT-PREFLIGHT` contract, the authoritative current code/docs and the preserved Phase-V run evidence. **Correction round 2 is applied on top of the reviewed-and-failed candidate `6c2ab1ce32676286d10a8248fab358ed02e7694a` / tree `ed10e67cf29bb4bcfc599175df0f20d351a3a78c`** (deterministic consent-replay digest, consent intent bound to its exact connection, explicit settlement of competing consent lifecycles, pending→acknowledged projection promotion, authenticated delivery envelope only, immutable receipts with append-only handoff outcomes, view-capability requirement for self-service reads). **Correction round 3 is applied on top of the reviewed-and-failed candidate `d9eec89715f20c8d12c61173e4c7b6e9c5769fef` / tree `5850a5a405800cd981c667d36d99328394af6b49`** (the deterministic normaliser now translates only the digest-bound authenticated body — never a caller-supplied outer fact array — and the append-only handoff-outcome attempt number is allocated inside a transaction that locks the parent receipt, so concurrent duplicate retries converge instead of colliding on the unique attempt index). **Correction round 4 is applied on top of the reviewed-and-failed candidate `d9c47b60d085817009a0734e51886154352b6359` / tree `bc02802f40a780b19750d5e349e44f98f206739d`** (`lockLessonRoots()` now takes the canonical chain in the declared order — Student–Course identity root → Enrolment → Term → Lesson — with relationship revalidation, the acknowledgement locks the chain before the mapping row, and a new provider-event receipt allocates its provider-scoped `event_sequence` under a provider-scoped serialisation taken before the head is read and released after the receipt transaction ends). **Correction round 5 is applied on top of the reviewed-and-failed candidate `389a5463877a2ae92eaefd7020789a5ad33d2943` / tree `d2965765ff8d5afb824f83f5a44d19b9ca65a410`** (the provider event key itself is now decided under that provider-scoped serialisation: the committed receipt is re-read under the lock, and a materially changed context that loses the key race is durably recorded through `recordConflict()` — including from the unique-index guard — instead of being reduced to a bare `IdempotencyConflictException`, with the new `cross_lesson_event_key_race` concurrency mode asserting it). It is a candidate only — awaiting independent review, not merged, not deployed, with no live credential, provider traffic, production data or cutover. See `docs/PHASE-2A-2V-GOOGLE-CALENDAR-MEET-PROVIDER-NEUTRAL-INTEGRATION.md`. |
| Historical schema (at snapshot) | 24 (current source is Schema 33 per §1.1) |
| Historical migrations (at snapshot) | 001–024; latest `024_post_intro_continuation_slot_reservation_authority` (current horizon is 001–033 per §1.1) |
| Historical build identity (at snapshot) | `phase2a2q-post-intro-continuation-slot-reservation-20260920.1` (current build is `phase2a2s-notification-communications-authority-20260924.1` per §1.1; the `phase2a2w-portal-facing-services-20260927.2` stamp is the historical W-slice identity only) |
| Historical latest slice (at snapshot) | Phase 2A.2-Q — Post-Intro Continuation & Slot Reservation Authority (Phase 2A.2-R1 is now merged and closed at Schema 25 per §1.1) |
| Phase 2A.2-Q approved candidate | `815a301679fdebc5d058646749e5a206ab492629`; tree `13757b52dd84810d44e6dba19550ec7e668ad169` |
| Phase 2A.2-Q implementation merge | `815a301679fdebc5d058646749e5a206ab492629` (fast-forward; equals the approved candidate because no merge commit was required) |
| Phase 2A.2-P approved candidate | `30fe1f11c425ad7066408f20ca57e2ca084841d6`; tree `34c111c01932f14552ada366f911f9b404111692` |
| Phase 2A.2-P implementation merge | `30fe1f11c425ad7066408f20ca57e2ca084841d6` (fast-forward; equals the approved candidate because no merge commit was required) |
| Phase 2A.2-O approved candidate | `f5b43741f4b404fd102330aeb75d58ed8b3e2976`; tree `7182101fcaf9855f8834cd08bc2e7a4a0311791c` |
| Phase 2A.2-O implementation merge | `f5b43741f4b404fd102330aeb75d58ed8b3e2976` (fast-forward; the implementation-merge SHA equals the approved candidate because main required no merge commit) |
| Phase 2A.2-N merge state | Phase N independent re-review PASS; exact approved tree merged as `08138270f4bd32e5829ef0f5a18a316f6780607d` |
| Closeout baseline (pre-O) | `08138270f4bd32e5829ef0f5a18a316f6780607d`; the docs-only closeout commit is recorded in the task closeout because a commit cannot embed its own hash |
| Current 2A.2-M state | **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED** |
| Current 2A.2-N state | **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED** |
| Current 2A.2-O state | **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED** (final independent re-review PASS after correction rounds 1–3) |
| Current 2A.2-P state | **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED** (final independent re-review PASS after correction rounds 1–3). Provenance: original `6a6a4ce8249f79df0371753479587e5d9c1fcd27` failed (P-1…P-11); `eda3df2a24f5de67dcbba1dbe6e4ae863b325e13` is historical incomplete; `629310e835a4cad3bc25d5406280944c2b6e6145` failed; `0d6f0f3ab18fe7989367b78610a73beb0e3d7bd9` failed; `30fe1f11c425ad7066408f20ca57e2ca084841d6` passed and was fast-forward merged. Phase P is provider-neutral intake/evidence/assessment/review authority only; Phase O remains canonical delivery/attendance consequence authority and Lesson authority owns completion. No Google/provider credential, no production cutover, no deployment; Theme/NIU untouched. |
| Current 2A.2-Q state | **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED.** Provenance: original `33368f6a…` failed on Q-1/Q-2/Q-3; `c43a89b3…` failed on Q-R1-1/Q-R1-2/Q-R1-3; `815a301…` passed final independent re-review and was fast-forward merged. Phase Q owns exactly the post-introductory continuation decision and a bounded pre-payment hold on the single explicitly authorised first regular slot; Phase P remains complete/closed at Schema 23. No payment/Stripe, Term or Lesson materialisation, notification, provider/Google, Teacher/Student Portal or production activation occurred; Theme/NIU untouched. **Known follow-up:** the `expiry_vs_new_claim` concurrency mode's two-second window can invert its expected winner order under load while production behaviour stays correct (two consecutive full-matrix runs passed with a widened window); a bounded test-harness stability change should follow in a future correction round because the approved tree must not be altered by this merge. |
| Historical next action (at snapshot) | **Independent review of the Phase 2A.2-U finance candidate, of the Phase 2A.2-T payment-execution base it is scoped to, and of the outstanding runtime evidence for both** (the Phase 2A.2-T payment-execution candidate (and of the preserved Phase-V, R2 and R1 candidates) is the only open Platform action, together with the outstanding Phase-T runtime evidence.** This record approves no new slice: do not begin live provider execution, credential provisioning, charges, refunds, cross-Term renewal/recovery execution, notification delivery, Portal work, production continuation/attendance cutover, merge or deployment on the strength of this document, of the Phase-T candidate or of the Phase-V/R2/R1 branches. Superseded for the current next action by §1.1. |

The completed coordination chain is:

1. **2A.2-A** — protected Booking Request advisory assessment.
2. **2A.2-B** — Coordination Case and Candidate Teacher foundation.
3. **2A.2-C** — Teacher Availability Assent.
4. **2A.2-D** — Proposal Family, Teacher-specific Option and immutable Proposal Version.
5. **2A.2-E** — Provisional Acceptance Evidence: immutable, exact Proposal-Version evidence only.
6. **2A.2-F** — Student Identity & Acceptance Authority Foundation: reviewed identity resolution, Student capacity, provenanced principal links, guardian representative grants, and informational eligibility reads.
7. **2A.2-G** — Final Acceptance + Accepted Service Arrangement Foundation: current authority revalidation, immutable accepted arrangement facts, sibling Option outcomes, and deterministic finality/idempotency evidence.
8. **2A.2-H** — Canonical Enrolment Foundation: legacy-compatible Student + Course identity, reserved lifecycle/lineage, conflict inspection and protected history without creation or conversion authority.
9. **2A.2-I** — Enrolment Conversion Authority: informational readiness, explicit capability-protected atomic/idempotent conversion, authoritative source revalidation and canonical lifecycle-origin evidence.
10. **2A.2-J** — Teacher Assignment Foundation: separate current-Teacher authority, retained initial provenance, assignment-specific replacement evidence, atomic lifecycle and protected reads/offboarding.
11. **2A.2-K** — Canonical Term Foundation: legacy-compatible Enrolment + sequence identity, canonical lifecycle/applicability storage, append-only history and protected reads without creation or lifecycle mutation authority.
12. **2A.2-L** — Canonical Term Creation & Lifecycle Authority: explicit capability-protected creation, bounded transitions, durable idempotency and Enrolment-first concurrency control.
13. **2A.2-M0** — Canonical Enrolment Lifecycle Authority: explicit activate/pause/resume/close commands, durable idempotency, protected integrity reads and subordinate closure guards.
14. **2A.2-M** — Canonical Lesson Authority: bounded standard/replacement issuance and terminal completion/cancellation, immutable Term/Enrolment/Assignment/Teacher provenance, append-only lifecycle evidence and idempotent command evidence.
15. **2A.2-O** — Canonical Lesson Delivery & Attendance Outcome Authority: provider-neutral exceptional delivery/attendance outcomes, append-only correction, explicit historical-completion reconciliation (O-D8), controlled advance academy-cancellation debt (O-D9) and a distinct immutable academy-obligation authority that never consumes the Phase-M replacement allowance.
16. **2A.2-P** — Canonical Attendance Intake & Review Authority: durable provider-neutral provider-account → canonical-participant identity registry, prospective immutable cutover policy (one policy per cutover instant), per-occurrence intake cases, append-only evidence/decisions/anomalies/conflicts, digest-only command evidence, controlled provider-evidence overlap assessment and administrative adjudication; every canonical consequence is delegated to Phase O and Lesson authority.
17. **2A.2-Q** — Post-Intro Continuation & Slot Reservation Authority: the post-introductory continuation disposition (continue / different Teacher / contact me / not continuing, plus the Teacher's match-exception report), an explicit administrator-authorised first regular slot record, and a bounded pre-payment capacity hold that converges a delayed slot record into the original continuing decision; administrator interventions for alternative Teacher, contact requests and unsuitable matches; every canonical consequence (delivery, completion, Term, Lesson, obligation, payment, notification) remains outside Phase Q.

Historical snapshot: on 19 September 2026, `main` was authoritative at Schema 24 / Phase Q; current source is Schema 33 per §1.1. Canonical Lesson identity and lifecycle, canonical scheduling and Teacher capacity (Phase N), Phase-O delivery/attendance outcome authority, Phase-P provider-neutral intake/evidence/assessment/review authority, and Phase-Q post-intro continuation and pre-payment slot reservation authority are now authoritative alongside the completed Enrolment, Term, Assignment and Lesson authority chain. Production continuation/attendance cutover, payment/Stripe, Term or Lesson materialisation, provider/Meet/OAuth/webhook/credential integration, renewal, notification, calendar, Amelia, payroll, Teacher Portal and Student Portal integration remain outside Platform scope.

## Completed Phase 2A.2-P — Canonical Attendance Intake & Review Authority

**COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED.** Authoritative pre-merge main was `7b9aea68fddd651cd614f279f77e78b104885c4d`. The final independently approved candidate `30fe1f11c425ad7066408f20ca57e2ca084841d6`, tree `34c111c01932f14552ada366f911f9b404111692`, was fast-forward merged into `main` (implementation-merge SHA equals the approved candidate because no merge commit was required). Schema 23 / `023_canonical_attendance_intake_authority` / build `phase2a2p-canonical-attendance-intake-20260919.1` are authoritative. No deployment occurred; production, Theme/NIU, Google/provider credentials, Stripe/payment, notification and portal surfaces were untouched, and no production attendance cutover was activated.

Phase P owns provider-neutral attendance intake, evidence and review only: a durable participant identity registry, prospective immutable cutover policy, per-occurrence cases and append-only evidence/decisions/conflicts. It does not own canonical delivery/attendance truth (Phase O) or completion (Lesson authority), and it introduces no Google/Amelia/calendar/payment/notification authority. Locked owner decisions P-D1 … P-D12 are authoritative (threshold 1200, pre-grace 0, post-grace 900; no responsibility inference; human claims never settle; no raw provider payload; duplicate cutover-instant rejection and ambiguity fail-closed; protected read fails closed on superseded schedules; full-context duplicate-command recovery).

## Completed Phase 2A.2-N — Canonical Lesson Scheduling & Teacher Capacity Authority

Phase N is **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED**. The independently approved final candidate `9f92ada6ba82d809c1515566fb426c90a5a68e57`, tree `ec29baabf2d737d0eecd72d857a152b8eba4b163`, was merged without squash or rebase as `08138270f4bd32e5829ef0f5a18a316f6780607d`, whose merge tree exactly matches the approved tree. Schema 21 / `021_canonical_lesson_schedule_authority` / build `phase2a2n-canonical-lesson-schedule-authority-20260917.1` are authoritative.

The final correction ensures Phase-N storage verification runs after migration 021, during current-schema verification, and unconditionally before Schema 21 activation, including the retained-021/stale-schema-version recovery path. The focused independent re-review returned no findings. No deployment occurred; production, Theme/NIU, Amelia and external integrations were untouched.

## Completed Phase 2A.2-M — Canonical Lesson Authority

Phase M uses additive Schema 20 / `020_canonical_lesson_authority` and build `phase2a2m-canonical-lesson-authority-20260917.1`. It preserves legacy Lesson rows, requires a current canonical Enrolment, current canonical Term and current Teacher Assignment, and derives Teacher provenance from that Assignment. It adds immutable command/lifecycle evidence, permanent 12-standard/2-replacement allocation and no scheduling or delivery authority. M0 closure rejects an authorised canonical Lesson without cascade; terminal Lessons do not block closure, and malformed Lesson evidence fails closed. It is **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED**.

### Correction round 1 — 2026-09-17

The reviewed candidate `1d723e0d7b5ef73db7bc6c24683a73c62684432c` (tree `cb577b7ece33d8533b2e47dacd4b3894566376ec`) failed independent review. Correction branch `phase-2a2m-canonical-lesson-authority-correction1` addresses the four findings without changing the locked Phase-M product or authority model:

1. `CanonicalLessonAuthorityValidator::valid()` is now the single canonical aggregate hydration and integrity gate: Lesson↔Term enrolment, Lesson↔Enrolment Student and Course identity, the recorded Teacher Assignment's structural belonging with its immutable Teacher, and complete replacement-origin lineage including controlled replacement-eligible non-delivery evidence. Historical Lessons are never required to keep the historical Assignment current.
2. Replay revalidates every operation-specific command intent against the operation facts, the result aggregate through the canonical validator, and the durable lifecycle evidence the command recorded; a corrupted command, result or history may not replay as success.
3. `tests/phase-2a2m-concurrency-runner.sh` commits a deterministic process-level runner that coordinates setup, gated worker start, lock-wait observation, controlled release, worker completion and final database verification, consumes and asserts worker artefacts, and fails non-zero on any violated race invariant.
4. Failure injection spans standard creation, replacement creation, completion, cancellation, lifecycle/history evidence writes and command evidence writes, proving complete rollback with no false replay.

Verification for this round: all 29 static/source contract tests pass; the Phase F, G, I, J, L, M0 and M runtime suites pass on disposable MariaDB 11.4.13 baselines, including a genuine Phase-H Schema-14 fixture database upgraded to Schema 20; the 16-mode gated concurrency matrix passes deterministically. The stale finite schema/build enumerations in `tests/phase-2a2h-contract.php` and `tests/phase-2a2l-runtime.php` were replaced with the repository's established minimum-schema plus canonical-build-identity pattern; both were failing on the reviewed candidate itself. Independently approved candidate `f44b502509f5dcb9b5d0281cfda4329407abd3bf`, tree `85a8309bb6179a2061c86245ea1ad9a975c00c2e`, was merged as `ed11086ad8ddc65899c3b855248611b1eb9e09a4`, whose tree exactly matches the approved tree. No deployment occurred.

## Completed Canonical Enrolment Lifecycle Authority — Phase 2A.2-M0

From authoritative pre-M0 main `8490d712d18116b3ca606d6c2e3dbb8560d2ce54`, independently approved candidate `66ba811c47a3494f12f47dbed03775ca7c4e5ba0`, tree `6a9631505a86893abf1fcefa89a78675e7ed3901`, was merged without squash or rebase as `c316a5153c7a56b810732495b3785786771c695a`. The implementation merge tree exactly equals the approved candidate tree. Schema 19 / `019_canonical_enrolment_lifecycle_authority` and build `phase2a2m0-enrolment-lifecycle-authority-20260917.1` are authoritative.

M0 makes the explicit canonical Enrolment graph operational, adds immutable digest-only command evidence, preserves pause applicability, validates protected lifecycle reads, and blocks closure while applicable canonical Term or Teacher Assignment authority exists. It adds no canonical Lesson storage or authority and performs no cascading subordinate mutation.

Phase 2A.2-M0 is **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED**. No deployment occurred.

## Completed Canonical Term Authority — Phase 2A.2-L

From authoritative pre-L main `788bf0989f4365607ba41322471e50fca75a5e81`, independently approved candidate `26beab6147df545fed70949f06b83d83042184cd`, tree `b283c319b9a3af3267cf43ad9591e30d93085986`, was merged without squash or rebase as `36e1d6b754079efcf6fdff02ed2a029099071455`. The merge tree exactly equals the approved candidate tree. Schema 18 / `018_canonical_term_authority` and build `phase2a2l-canonical-term-authority-20260916.1` are authoritative.

Phase L adds administrator-only canonical Term creation plus `authorised → current`, `authorised → cancelled`, `current → closed`, and `current → cancelled`. Durable digest-only command evidence, exact replay/conflict handling, pre-lock aggregate-position binding and Enrolment-first process concurrency preserve stale-command safety. It adds no Lesson consumption, Teacher authority, payment, scheduling, external integration or cross-aggregate mutation.

Phase 2A.2-L is **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED**. No deployment occurred.

## Completed Canonical Term Foundation — Phase 2A.2-K

From authoritative pre-K main `cf222d20e4ab0d08c992e7ec38bcece8b7625d9e`, independently approved candidate `a6491ccc1cf624be205d1ea022421a5f7903eb2b`, tree `9a59062c2a6aa10c95e88e4d24909c6e770dd27d`, was merged as `25e213c69d7f299c9ed5330eed7cd9ba4051c022`. Schema 17 / `017_canonical_term_foundation` and build `phase2a2k-canonical-term-foundation-20260916.1` are authoritative.

The candidate preserves all existing Terms as `legacy_phase1` without translating status, payment, allocation, dates or archive state. It reserves `canonical_enrolment_term_v1` for a Teacher-neutral Term identified by canonical Enrolment plus immutable sequence; adds `authorised`, `current`, `closed`, and `cancelled` lifecycle storage, one-applicable-row arbitration and append-only digest-only history; and provides protected integrity classification and privacy-minimised reads.

The independent correction closed the merge-blocking cross-aggregate defect: a validly closed canonical Enrolment with an `authorised` or `current` applicable Term now fails closed as `data_integrity_conflict`, while terminal Term history remains valid. Phase K intentionally contains no ordinary canonical Term creator, lifecycle mutation command, command-idempotency table, Lesson creation/consumption, scheduling, payment, renewal or external authority. The existing legacy Term/Lesson path remains available only through explicit legacy record-model guards. Generic archive/restore cannot mutate canonical lifecycle.

Phase 2A.2-K is **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED**. No deployment occurred.

## Completed Teacher Assignment Foundation — Phase 2A.2-J

Starting from pre-J main `7bd4430737f460fdb995bbe05dea272b55641294`, approved candidate `b1fd5aebf47a0bce33f74ab56b53c5334d719bf4`, tree `5766e126a5abd7e9160e6c00dc96b6837f49b05e`, was merged as `763a9fa9f792cd45114e10978a1bda0a53662c21`. Schema 16 / `016_teacher_assignment_foundation`, build `phase2a2j-teacher-assignment-foundation-20260915.1`, establishes a first-class Assignment aggregate and explicitly preserves `enrolments.teacher_id` as historical Accepted Service Arrangement context.

Zero Assignment is valid and migration 016 performs no backfill. Initial Assignment is limited to canonical Enrolments in `authorised`, `current`, or `paused` and must use the exact retained final-arrangement Teacher. Historical Availability Assent provenance remains usable even after time passes or the source record later transitions. A different Teacher requires new assignment-specific authenticated-Teacher acceptance or authorised staff attestation; availability, eligibility, proposal history, the historical Enrolment Teacher, and administrator preference do not substitute.

Replacement atomically terminates the predecessor and creates its successor; no future replacement can be staged. Expected-current identity arbitrates stale commands, nullable-slot uniqueness is final database arbitration, and same-key replay/different-key identical-authority behavior is explicit. Lifecycle evidence is append-only and command/evidence references are digest-only. The protected read seam returns only stable identifiers, sequence, state, and assigned time.

Assignment commands lock Enrolment → Teachers with shared locks in ascending ID order → concrete Assignments under `READ COMMITTED`. Teacher archival takes an exclusive Teacher lock and rechecks applicable Assignment state transactionally. J-5 keeps principal/onboarding offboarding independent: it does not archive the Teacher, invalidate existing Assignment authority, or prevent retained-provenance initial and authorised staff-attested replacement authority, but it prevents authenticated-Teacher replacement after principal authority is revoked. Applicable Assignment authority blocks incompatible Teacher archival. Review correction round 1 restricts duplicate arbitration to named indexes plus complete operation-specific authority proof; terminal failures cannot converge from a merely current Assignment. Exhaustive failure/corruption and twenty-mode bidirectional process-concurrency coverage, including six real principal-offboarding races, is committed.

The original independent review found one HIGH and three MEDIUM findings. Correction round 1 closed the HIGH finding plus failure/corruption and regression gaps. The remaining offboarding concurrency question exposed a contract ambiguity; J-5 clarified that archival and principal offboarding are distinct authority boundaries. The final candidate added deterministic offboarding coverage without production-source changes, and the final independent check passed. The MariaDB reviewer environment lacked MySQL-specific `data_locks` / `data_lock_waits` attribution tables; this limitation was explicitly documented and no runtime success was inferred from Ina's execution.

Phase 2A.2-J is **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED**. No Enrolment lifecycle, Term, Lesson, schedule, capacity, payment, notification, calendar, Amelia, Hamnavaz, CRM, Theme, NIU, or production authority was introduced. No deployment occurred.

## 2. Locked coordination domain contract

The authoritative hierarchy is:

```text
Booking Request
→ Coordination Case
→ Candidate Teacher Consideration
→ Teacher Availability Assent
→ Proposal Family
→ Teacher-specific Proposal Option
→ immutable Proposal Version
→ provisional/final acceptance
→ Accepted Service Arrangement
→ conversion readiness
→ explicit conversion authority
→ idempotent Enrolment conversion
→ explicit Teacher Assignment authority
→ canonical Term foundation (storage/read only)
→ future explicit Term creation/lifecycle authority
→ later canonical Lesson authority
```

These distinctions are deliberate and must not be collapsed:

- Teacher Availability Assent is not Teacher Assignment, capacity reservation or Lesson booking.
- A Proposal creates offer authority only; it is not acceptance or arrangement authority.
- Acceptance is not conversion authority.
- Conversion readiness is neither conversion authority nor a successful conversion.
- A successful conversion creates exactly one Enrolment authorised by the accepted arrangement. It does not create Teacher Assignment.
- Teacher Assignment is separate, effective-dated authority.

Any subsequent work must preserve this graph. Phase K does not authorise Term creation or lifecycle mutation, and no work may silently advance to Lesson, scheduling, capacity, payment, notification, calendar or Amelia authority.

## Completed Enrolment Conversion Authority — Phase 2A.2-I

Starting from pre-I main `f855103c6449ad466ff267c7a9740b56c9ffed66`, approved candidate `4e40c5fa7665a8f96fcbf19def2cac719e7bc38b` was merged as `d0bfbe1b808e60e8bcffae0da6c16a4fda1dc928`. Schema 15 / `015_enrolment_conversion_authority`, build `phase2a2i-enrolment-conversion-authority-20260914.1`, establishes:

- informational, read-only conversion readiness distinct from explicit capability-protected conversion authority;
- locked revalidation of the authoritative Accepted Service Arrangement source graph, including exact frozen identity-resolution and capacity-classification evidence;
- retention of PII-free final arrangement authority after Booking Request privacy erasure, without reconstructing or copying erased contact data;
- canonical Student + Course identity serialization through a concrete identity root, while the frozen Teacher remains historical/service context only;
- atomic, digest-only idempotent conversion to exactly one canonical Enrolment beginning in `authorised` state;
- contamination-safe replay and already-converted results that remain valid through legitimate later canonical lifecycle progression;
- return-after-closure predecessor selection only from complete authoritative lifecycle history, with malformed or ambiguous evidence rejected.

Final independent re-review result: **PASS — MERGE READY**. Phase 2A.2-I is **COMPLETE / MERGED / CLOSED**.

Teacher Assignment remains future work and was not started. Conversion creates no Teacher Assignment, Term or Lesson authority and no payment, calendar, Amelia, notification or communication authority. No deployment occurred; Theme, NIU, Amelia, Hamnavaz, CRM and production were untouched.

## Completed Canonical Enrolment Foundation — Phase 2A.2-H

Starting from pre-H main `aa626e89c66a393977caf002355589990aec03df`, approved candidate `6ae121d0a96b8bd034663992f4ee5ee0812a9893` was merged as `672e5334fe9f37fff5f872cf3fdad18af5ab50d5`. Schema 14 / `014_canonical_enrolment_foundation`, build `phase2a2h-canonical-enrolment-foundation-20260913.1`, establishes:

- canonical Enrolment structural identity as Student + Course, with record models `legacy_phase1` and `canonical_student_course_v1`;
- the reserved canonical lifecycle `authorised → current → paused → closed`;
- immutable Accepted Service Arrangement provenance, one applicable canonical Enrolment per Student + Course, and append-only lifecycle-history foundations;
- protected applicability/conflict inspection and internal read/history surfaces;
- exact preservation of legacy status and historical `teacher_id`, plus a constrained capability-protected legacy/bootstrap compatibility seam;
- closed generic/manual canonical creation and repository-level protection against legacy archive/restore mutation of canonical records.

Independent-review findings HIGH-1, HIGH-2, HIGH-3 and MEDIUM-1 were corrected. Final independent re-review result: **PASS — MERGE READY**. Phase 2A.2-H is complete, independently reviewed and merged.

No Accepted Service Arrangement → Enrolment conversion was implemented. There is no Teacher Assignment authority and no canonical Term or Lesson authority. Conversion readiness and explicit conversion authority remain distinct future work and must not be silently conflated with Phase 2A.2-H. No deployment occurred; Theme, NIU, Amelia, Hamnavaz and production were untouched.

## Completed Final Acceptance + Accepted Service Arrangement Foundation — Phase 2A.2-G

Starting from pre-G main `0607be0ce6f2dcd32dc60a4d8ff6c76d0f4012c8`, independently reviewed candidate `701f62595327ba464d81299b1832ba7825eddc4e` was merged as `3a33bafd5943b15b139bbe40cb198cbb201cc943`. Schema 13 / `013_final_acceptance_arrangement_foundation` establishes:

- exact final acceptance of the current immutable Proposal Version against current Booking Request, Student identity, capacity and adult-self or guardian authority;
- immutable, PII-safe Accepted Service Arrangement facts and atomic sibling Proposal Option outcomes;
- HMAC-digested, replay-safe idempotency and one final arrangement per Proposal Family;
- Proposal finality after acceptance, without conversion or operational authority;
- protected internal administration only;
- deterministic concurrency evidence for competing acceptance, Proposal replacement, privacy erasure, capacity and authority invalidation, shared idempotency keys and unrelated Families;
- attributed principal-first and guardian-first contention on the concrete Student authority serialization row, with stale authority rejected after release.

Production authority-lock source candidate `d96378d7f16a2ff1c90ec1fc99273361f3630808` passed independent source review. Candidate `701f62595327ba464d81299b1832ba7825eddc4e` added evidence and semantic contract hardening only. Final independent review result: **PASS — MERGE READY**.

No deployment occurred. Deliberate exclusions remain: no Booking Request conversion, Enrolment creation, Teacher Assignment, Term, Lesson, payment, notification, scheduling, calendar or Amelia authority.

## Completed Student Identity & Acceptance Authority Foundation — Phase 2A.2-F

PR #15 merged approved candidate `f7b8066ce6f45c6bee461cdb13cc2614283988bd` as merge commit `c578f137ed537276524a460a9bb4771ec6fbcc4c`. Schema 12 / `012_student_identity_acceptance_authority` establishes:

- explicit human-reviewed, append-only Booking Request-to-Student identity resolution, with no automatic identity matching;
- append-only `adult`, `minor` and `unknown` Student acceptance-capacity classifications;
- authoritative, versioned and provenanced Student-to-WordPress principal links with atomic supersession lineage;
- effective and revocable `guardian_representative` authority scoped to `service_acceptance`, with strict intervals and append-preserving lazy expiry;
- derived adult-self authority; adult delegation remains unavailable in V1;
- protected internal administration and an informational, non-bearer eligibility read service;
- the Booking Request privacy-erasure boundary, including rejection of new authority after erasure;
- a deterministic common authority lock order and attributed R1–R11 concurrency harness;
- real Schema 11 → 12 migration and repeat-upgrade evidence.

Deliberate exclusions remain: no automatic PII matching, public identity workflow, adult delegation, final acceptance, Accepted Service Arrangement, Booking Request conversion, Enrolment, Teacher Assignment, Term, Lesson, payment, notification, calendar or Amelia authority.

> This prerequisite gate was resolved before Phase 2A.2-G implementation; the historical Phase 2A.2-F boundary remains recorded here.

## 3. Completed Proposal Foundation — Phase 2A.2-D

Schema 10 / `010_proposal_foundation` implements the minimum proposal lineage:

- one canonical Proposal Family per Booking Request / Coordination Case;
- Teacher-specific Proposal Options, with unique Family/Candidate and Family/Teacher constraints;
- append-only immutable Proposal Versions, each belonging to exactly one Option;
- A2 supersedes A1 only inside Option A; a Teacher B Option remains independent;
- exact Family + Option + Version retrieval and a guarded current-Version pointer;
- authoritative Assent consumption before issuance, not a duplicate currentness check;
- Proposal-scoped HMAC idempotency with no raw client-key persistence;
- controlled historical facts and provenance without unnecessary Booking Request contact PII;
- capability `dzn_issue_booking_request_proposals`;
- protected internal coordination surface only, with no public Proposal REST route.

It does not create Student, Enrolment, Teacher Assignment, Lesson, payment, notification, calendar, Amelia or public Proposal authority.

### Validation record

The completed 2A.2-D validation included:

- Schema 9 → 10 and repeated-migration behaviour;
- capability repair;
- Proposal lineage and immutability;
- Assent currentness/rejection;
- idempotency and privacy/authority boundaries;
- simultaneous initial issuance and simultaneous replacement;
- issuance racing Assent invalidation and eligibility invalidation.

Final validation was **PASS WITH NON-BLOCKING LIMITATIONS — MERGE READY**, then PR #13 merged.

The following failures are historical regressions on the exact base, not 2A.2-D regressions:

- `phase-1c-executable.php`
- `phase-1d-contract.php`
- `phase-1f-contract.php`
- `phase-2a0-independent-review-contract.php`

## 4. Next Platform action

**No next Platform slice is authorised.** Phase 2A.2-R1 is merged and closed at Schema 25; the
Phase 2A.2-R2 candidate below is **awaiting independent review** and is not a next action. A further
bounded slice requires an explicit product/architecture decision from the owner before
reconnaissance, a branch or implementation begins.

The R2 candidate is bounded by `docs/PHASE-2A-2R2-IMPLEMENTATION-CONTRACT.md` (the authoritative
preflight contract). Its four deferred product decisions
(refund academic consequences, automatic-renewal opt-in/lead time, recovery/lapse thresholds and the
provider-specific confirmation adapter) remain owner decisions and were **not** invented.

Two pre-existing adjacent failures were repaired so the pure regression suite can be quoted green:
`tests/phase-2a2r1-contract.php` now accepts the monotonic `phase2a2r*` package build identity and
locates the 025 required-migration entry instead of requiring it to be last, and
`tests/phase-2a2a-capability-lifecycle.php` now reads the reconciliation marker from the
`Migrator` authority and covers the current protected capability set.

This record does not authorise a continuation or delivery/attendance cutover, provider integration, Google/Meet/OAuth/webhook/credential work, Amelia/calendar work, payment/Stripe/Finance work, Term or Lesson materialisation, notification authority, Teacher Portal, Student Portal integration, a public route or any later phase.

Historical roadmap note (superseded): this section previously recorded Phase 2A.2-L, the Phase 2A.2-M0/M boundaries, the Phase-O candidate, the Phase-P candidate and the Phase-Q correction rounds as the next action. Those slices are complete and merged; the earlier wording is retained only as provenance and is **not** the current next action.

## Completed Phase 2A.2-O — Canonical Lesson Delivery & Attendance Outcome Authority

**COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED.** Authoritative pre-merge base was post-Phase-N `main` at `b0687fec98748144f96e3fc56f7e4fb53e01f673`. The final independently approved candidate `f5b43741f4b404fd102330aeb75d58ed8b3e2976`, tree `7182101fcaf9855f8834cd08bc2e7a4a0311791c`, was fast-forward merged into `main` (the implementation-merge SHA equals the approved candidate because no merge commit was required), so Schema 22 / migration `022_canonical_lesson_delivery_attendance_authority` / build `phase2a2o-canonical-lesson-delivery-attendance-authority-20260918.1` are authoritative. No deployment occurred; production, Theme/NIU, Amelia, Google/calendar, Meta and Stripe were untouched.

Phase O is **provider-neutral**. It records what actually happened to a canonical Lesson occurrence with no provider coupling, no payment or payroll effect, no notification authority and no public surface.

### Locked product decisions O-D1 … O-D9

| Decision | Locked meaning |
| --- | --- |
| O-D1 | Canonical Lesson `completed` means the occurrence was actually delivered. No new Lesson lifecycle state was added. |
| O-D2 | Exception-based recording: ordinary delivery is the absence of an outcome row, so no routine attendance record is required per Lesson. |
| O-D3 | A student no-show may coexist with delivered/completed — it is a distinct attendance fact, the Lesson is consumed, and no automatic academy remedy is created. |
| O-D4 | Teacher/academy non-delivery creates an academy obligation that is distinct from the Phase-M replacement. |
| O-D5 | Advance cancellation remains distinct from post-occurrence non-delivery and can never describe the same event. |
| O-D6 | Corrections remain append-only: they supersede history, never rewrite or reopen Lesson lifecycle, and never move money. |
| O-D7 | Canonical Phase-O mutation authority requires the Phase-O administrator capability (`dzn_manage_canonical_lesson_delivery`), enforced inside the public write seam before any lookup or mutation. |
| O-D8 | The historical completion event is preserved immutably while the effective delivery truth may be explicitly reconciled to Teacher/academy non-delivery; the academy obligation is bound to canonical completion lineage. Nothing reopens, reschedules, materialises or pays automatically. |
| O-D9 | A controlled advance Teacher/academy cancellation (`academy_unavailable`) may establish academy debt; a generic Student cancellation does not. |

### Canonical lifecycle authority (Round-3 architecture)

Academy-obligation authority does **not** define Lesson completion. `CanonicalAcademyObligationValidator` consumes `CanonicalLessonAuthorityValidator::valid()` before accepting any obligation lineage; the delivery validator consumes it whenever a reconciliation pointer exists; and the reconciliation command consumes it instead of scanning raw lifecycle rows. Completion is bound to the canonical chain's terminal event, which follows from the validated legal progression rather than from selected row fields or a latest-completed-ID scan. The call is one-way (Lesson authority references no Phase-O authority), so there is no recursion, no competing lifecycle authority and no lock-order change; a malformed source Lesson lifecycle causes obligation protected reads to fail closed.

### Security / integrity closeout

- The public obligation write seam (`CanonicalAcademyObligationService::owe()`) self-enforces `dzn_manage_canonical_lesson_delivery` before any lookup, validation or mutation; an unauthorized principal fails closed with no obligation row and no partial authority.
- Same-kind replay validates the supplied source and immutable evidence: exact legitimate replay is idempotent with a single row, while conflicting evidence or source identifiers fail closed (`obligation_replay_conflict`) and a conflicting source kind fails closed (`obligation_source_conflict`).
- Aggregate Term/Enrolment obligation reads validate every selected obligation through canonical authority, discover rows by the union of the stored selector and the source-Lesson relationship so a corrupted selector cannot disappear silently, and fail the whole aggregate closed on any invalid row; the Term count derives from the same validated authority and no raw SQL count seam exists.
- Round-3 sequencing keeps guards and reads honest inside the Phase-M transaction: the append-only lifecycle event and any academy obligation are written before the advance-cancellation guards, so guards validate the exact state the transaction will commit, and a rejection rolls everything back together with its precise reason.

### Historical correction records (superseded by the final merge)

The three sub-sections below record correction rounds that are now complete. They are historical provenance: the candidate SHAs they name are superseded by the merged candidate `f5b43741f4b404fd102330aeb75d58ed8b3e2976`, and no active work remains from them.

#### Correction round 3 (independent re-review of round 2 failed — HIGH/MEDIUM)

Round-2 candidate `5b18f20d576e5446cee6cecc0b21d010fb62ffa1` (tree `af035208b7096dad12e60b768e928372fb094778`) failed independent re-review. The round-3 correction is a descendant of that candidate and remains validation, test and documentation work; Schema 22, the migration, the build identity, O-D1…O-D9 and the round-1/2 fixes are preserved.

- **HIGH — no competing lifecycle authority:** canonical completion is no longer decided inside academy-obligation authority. `CanonicalAcademyObligationValidator` consumes `CanonicalLessonAuthorityValidator::valid()` (which references no Phase-O authority, so the reuse is one-way with no cycle, recursion or lock-order change) before accepting obligation lineage, the delivery validator consumes it whenever a reconciliation pointer exists, and the delivery service's reconciliation command consumes it instead of scanning for a maximum completed event id. Completion is bound to the canonical chain's terminal event as a consequence of the validated legal progression. The obligation remains subordinate and never decides whether a malformed Lesson lifecycle is acceptable.
- **MEDIUM — referenced-event corruption coverage:** the referenced completion event itself is now corrupted by tests (`from_state`, `event_sequence`, and an injected structurally noncanonical completed row that Lesson authority rejects), each exercised through all three public aggregate seams with repair proving recovery. The earlier claim that the referenced event was already proven canonical was an overclaim and is corrected.
- **Sequencing:** the append-only lifecycle event and any academy obligation are written before the advance-cancellation guards inside the caller's transaction, so the guards validate the exact committed state; the guard also runs at the service seam before mutation. Rejections roll back the event, obligation and lifecycle update together and retain their precise reasons (`delivery_outcome_exists`, `occurrence_already_started_use_delivery_outcome`).

#### Correction round 2 (independent re-review of round 1 failed — HIGH/MEDIUM/LOW)

Round-1 candidate `c63c1327047bd76f3155788874ec6ea6ae7a38e0` (tree `7fa33f0d406e25a403e0f2b5c7d4668923faaaca`) failed independent re-review. The round-2 correction is a descendant of that candidate and remains validation, test and documentation work; Schema 22, the migration, the build identity, O-D1…O-D9 and the round-1 fixes are all preserved.

- **HIGH — O-D8 completion-event lineage:** an academy obligation raised by a reconciled completion now has its `source_event_id` validated against the effective outcome's canonical reconciliation lineage: an ordinary non-reconciled non-delivery must carry **no** completion-event lineage (a stored identifier is an integrity conflict), while a reconciled one must name exactly the event the effective outcome supersedes, and that event must exist in the Lesson's canonical lifecycle, belong to the same Lesson, be the `completed` event and still be the Lesson's latest completed event with the Lesson in `completed` state. The obligation remains subordinate to the canonical Lesson/outcome/reconciliation facts and never becomes the authority over completion.
- **MEDIUM — corruption coverage:** Teacher Assignment identity was added to the aggregate identity matrix, and four O-D8 completion-lineage corruption cases (wrong identifier, completion event belonging to another Lesson, missing lineage where reconciliation requires it, wrong lifecycle-event type) plus a spurious-lineage case on an ordinary non-reconciled non-delivery are now exercised through all three public aggregate seams with repair proving recovery. The round-1 claim that aggregate corruption coverage already included complete source event lineage and Teacher Assignment was an overclaim and is corrected.
- **LOW — idempotent replay:** `owe()` validates the supplied source and the existing obligation's binding and immutable evidence before reporting an idempotent success; exact legitimate replay stays idempotent with no duplicate row, and conflicting replay intent fails closed with `obligation_replay_conflict`. Uniqueness and concurrency semantics are unchanged.

#### Correction round 1 (independent review failed O-1/O-2/O-3)

The first owner-verified candidate `4f8af9067aa1ed463916d86b32a04f4193f44d23` (tree `8a79719e39496476debef4c67971648ab3447d57`) failed final independent review. The correction is a descendant of that reviewed candidate and changes only application-service validation, capability enforcement, tests and documentation — Schema 22, the migration, the build identity and O-D1…O-D9 are unchanged.

- **O-1 boundary:** `CanonicalAcademyObligationService::owe()` — the single public academy-obligation write seam — enforces `dzn_manage_canonical_lesson_delivery` as its first statement, before any source lookup, validation or mutation. An unauthorized principal fails closed with `Unauthorized`, no obligation row is created and no partial authority survives. Its two legitimate callers (the Phase-O non-delivery path and the Phase-M advance-cancellation reconciliation) already run behind their own capability check inside a transaction, so authorized behaviour and atomicity are unchanged; an advance cancellation by a principal without the Phase-O capability now fails closed and rolls back completely. This is the only path by which academy debt can be created.
- **O-2 aggregate fail-closed validation:** `outstandingForTerm()`, `outstandingCountForTerm()` and `outstandingForEnrolment()` hydrate each selected obligation against its canonical source Lesson and validate it through `CanonicalAcademyObligationValidator`, which now requires the stored Term, Enrolment, Student, Course, Teacher and Teacher Assignment selectors to agree with the source authority, checks source outcome/cancellation-event lineage, schedule-version and occurrence anchors, actor, classification and evidence, and requires the obligation's evidence to be identical to the evidence of the source fact. One invalid row fails the whole aggregate closed (`canonical_obligation_integrity_conflict`); no partial apparently-valid aggregate is returned, and the Term count is derived from the same validated aggregate rather than a raw SQL count. Term and Enrolment selectors use the union of stored-selector and source-Lesson membership, so a corrupted stored selector is validated instead of disappearing.
- **O-3 test-count evidence (corrected):** at Phase-O closeout, 29 files matched `tests/*contract*.php` and all passed. PHP lint (`tests/static.php`) and `sh -n` shell syntax checks are separate syntax checks, not contract tests. The earlier "36 contract tests" figure was a miscount and must not be quoted. **Current repository count is 30** `tests/*contract*.php` files on authoritative `main` because the merged Phase-P authority adds `tests/phase-2a2p-contract.php`.

Phase O is a **provider-neutral** canonical authority over what actually happened to a canonical Lesson occurrence. It adds no provider integration, no Google/Amelia/calendar/notification coupling, no payment or payroll effect and no public surface.

### Locked product decisions O-D1 … O-D9

| Decision | Locked meaning |
| --- | --- |
| O-D1 | Canonical Lesson `completed` means the occurrence was delivered. No new Lesson lifecycle state is added. |
| O-D2 | Exception-based recording: ordinary delivery is the absence of an outcome row, so no manual attendance record is required per Lesson. |
| O-D3 | A student no-show is a distinct fact: the Lesson is consumed, completion is still permitted, and no entitlement is created. |
| O-D4 | Teacher/academy non-delivery blocks completion and creates an academy-owed occurrence. |
| O-D5 | Cancellation stays a pre-occurrence scheduling/lifecycle fact; post-occurrence non-delivery is a delivery fact. The two can never describe the same event. |
| O-D6 | Corrections supersede append-only history, never mutate it, never reopen the Lesson and never move money. |
| O-D7 | Authorised administrator recording is immediate, idempotent and needs no second approval. Student/Teacher self-service is not authorised. |
| O-D8 | A completed Lesson may later be reconciled by an explicit authorised append-only command into Teacher/academy non-delivery. The historical completion event stays immutable; the effective delivery truth becomes non-delivery; the academy obligation is established; nothing reopens, reschedules, replaces or pays. |
| O-D9 | An advance Teacher/academy cancellation (`academy_unavailable`) establishes an academy obligation; a Student-requested cancellation never does. |

### Provider-neutral evidence boundary

Evidence is a controlled channel (`staff_record`, `authenticated_platform`, `document_reference`) plus an opaque keyed digest, an observed time and an actor. Phase O makes no provider call and never lets provider output become authority merely because it was emitted. Roadmap Phase 4 (direct Google integration) can ingest meeting evidence (teacher/student join, join/leave interval, overlap, duration) through this same boundary without changing canonical identity or adding Google coupling to the domain. No Google-specific schema exists.

### Phase-M replacement allowance vs Phase-O academy-owed occurrence

These are deliberately different authorities and must not be merged:

- the **Phase-M replacement allowance** is the Student's own bounded make-up allowance — at most two per Term, one per eligible origin, created only by a pre-occurrence cancellation carrying the controlled `attested_non_delivery` attestation, materialised as a `replacement` Lesson, and expiring with the Term;
- the **Phase-O academy-owed occurrence** is a teaching occurrence the academy owes because it failed to deliver. It is recorded in `{prefix}dzn_canonical_academy_obligations`, bounded to one immutable row per source occurrence, never counted against the two-per-Term allowance, never materialised as a `replacement` Lesson, and never removed or deactivated by Term closure. Materialising it into a delivered future occurrence remains a later explicit command/phase.

Historical `canonical_lesson_cancelled_replacement_eligible` and `attested_non_delivery` records keep their historical Phase-M meaning; there is no semantic backfill.

### Current exclusions

Attendance cutover, Google Meet/Calendar evidence ingestion, signed public join/absence links, Student/Teacher portals, notifications/WhatsApp, payment/Stripe, Finance, payability, teacher payroll, renewal automation, partial-delivery proration, legacy attendance import, retention policy, Theme/NIU work and any materialisation or scheduling of an owed occurrence remain later authority. The Phase O candidate itself creates none of them.

Full contract, validation record and residual notes: [PHASE-2A-2O-CANONICAL-LESSON-DELIVERY-ATTENDANCE-AUTHORITY.md](PHASE-2A-2O-CANONICAL-LESSON-DELIVERY-ATTENDANCE-AUTHORITY.md).

## Phase 2A.2-R1 candidate — Commercial Purchase, Funding & Current-Term Capacity Authority (unmerged)

**CANDIDATE / INDEPENDENT REVIEW PENDING.** Branch `phase-2a2r1-commercial-purchase-funding-authority`
from authoritative base `1b9d7aaef0ca21fdb861ccc9da1d15634cb5d843`. Schema 25 / migration
`025_commercial_purchase_funding_authority` / build
`phase2a2r1-commercial-purchase-funding-authority-20260920.1`. **Not merged into `main`, not deployed,
no production cutover**, and no Phase J–Q accepted decision was reopened.

The candidate owns canonical commercial authority only: sellable product identity and region/currency
prices, bounded promotions, distinct account-specific adjustments, an immutable purchase offer with a
deterministic whole-Term pricing snapshot, full and two-instalment plans decomposed into ordered
obligations, provider-neutral payment evidence, obligation settlement, derived academic effectiveness,
purchase acceptance, a bounded 12-session entitlement, Term binding through the existing Phase-L
authority, the canonical Regular recurring pattern (explicit authority only, never `intro + 7 days`),
current-Term protected capacity with the mandatory succession Phase-Q hold → R1 protected claim →
Phase-N schedule, commercial exceptions/reconciliation and the versioned runtime commercial policy
registry (`docs/COMMERCIAL-POLICY-REGISTRY.md`).

Locked representative decisions: one 12-session commitment (two instalments never create two Terms);
funding is derived only from accepted obligations; unfunded occurrences are never Lessons; an
obligation may be settled while prerequisite-blocked, and tranche 2 alone never becomes academically
effective; an existing capacity authority is never released until its successor is durable under the
same per-Teacher scheduling root; unset policy never lapses anything; and no provider column, SDK,
webhook, credential or subscription object exists.

Detailed contract, integration table, testing evidence, exclusions and follow-ups:
[PHASE-2A-2R1-COMMERCIAL-PURCHASE-FUNDING-CAPACITY-AUTHORITY.md](PHASE-2A-2R1-COMMERCIAL-PURCHASE-FUNDING-CAPACITY-AUTHORITY.md).
**Correction round 1:** the first independent review of `10fe40618af3de76f2af37a093e611af10cc6ccc`
failed on eight findings; all eight are corrected on descendants of that commit (benefit consumption
at accepted purchase convergence; exact protected-interval ↔ Phase-N occupancy identity; historical
capacity rows non-blocking; Course identity continuity; canonical provider-evidence fact convergence;
Teacher-root serialisation for claim release; durable structural-integrity enforcement in place of
foreign keys; behavioural proof for each). The reviewed commit was not rewritten and no force push was
used.
**Correction round 2 (historical; independent re-review FAILED on one remaining defect):** the independent re-review of `186fc5012fe294ea3d91b85aeefdd738448471a0`
(tree `40fc2643eacc6811882b552785f55e7db5c04e30`) passed the exact protected-interval identity, the
historical commercial-capacity lifecycle and the Teacher-root claim-release serialisation, and failed
four remaining integrity areas plus behavioural coverage and one documentation item. Additive
descendants of that commit now (i) prove the locked account-adjustment source still corresponds
exactly to its immutable `commercial_offer_adjustments` snapshot — identity, economics, application
order, currency, the discount recomputed against the post-promotion running amount and the re-derived
snapshot digest — before any benefit, settlement, purchase, entitlement or funding truth; (ii) provide
one canonical transaction-aware offer-lineage validator for the stored chain case → authorised slot →
pre-payment hold → product → price → offer → Student → Teacher → Course, invoked by the offer read
seam, payment acceptance, capacity handoff and Term binding; (iii) make the initially-unattributed
provider-evidence race recover through the one canonical duplicate-evidence boundary, so conflicting
immutable facts are durably routed instead of silently converging onto the first row; (iv) exercise
every owning mutation boundary directly in failure/corruption/concurrency evidence; and (v) synchronise
this document, the Phase R1 testing matrix and the changelog with the modes actually executed. The
reviewed commits remain immutable and no force push was used. State:
`CORRECTION ROUND 2 CANDIDATE`; that candidate's own independent re-review then passed every area
above but failed `R1-MAJOR-007` / `NEW-C2-001` (complete purchase/entitlement commitment ownership
before capacity and Term truth), which correction round 3 closes. No database foreign key or CHECK
constraint was added.
**Correction round 3 (historical; independent re-review FAILED on three commitment-layer findings):** additive descendants of the reviewed correction-round-2
commit `3aaf3081a0d5d12501715589a2a518c68af5ad98` (tree `dfba34c3a986f4a4cb9aa2f9a8b77a953efbf119`).
One canonical `CommercialCommitmentValidator` now proves the downstream commitment chain —
`entitlement → purchase → offer → canonical upstream offer lineage` — row-by-row from stored values,
including the purchase's own-purchase identity for its offer, the accepted beneficiary/product/
currency/amount/plan snapshot, the bounded session quantity, the mutation-appropriate entitlement
state, the acceptance evidence that minted the purchase, and the ownership of any existing protected
capacity claim (including the idempotent existing-claim fast path). Capacity handoff and Term binding
prove it before creating any capacity claim, protected interval, Term or funding plan and before
releasing the Phase-Q hold; read endpoints remain unchanged and are explicitly not relied upon for
integrity. Corruption evidence covers a purchase repointed at another otherwise-valid offer, a
re-pointed beneficiary/product/currency/amount/plan, a re-pointed or resized entitlement and a claim
belonging to another commitment, each failing closed at both owning boundaries with no silent repair
and converging after restoration. The reviewed commits remain immutable and no force push was used.
State: `CORRECTION ROUND 3 CANDIDATE`; that candidate's own independent re-review then failed
`NEW-C3-001` (acceptance-evidence/settlement/payment-fact ownership), `NEW-C3-002` (idempotent
existing-claim integrity) and `NEW-C3-003` (command replay after at-rest corruption).
**Correction round 4 (historical; independent re-review FAILED on three findings):** additive descendants of the reviewed correction-round-3
commit `2ff3d6e3a81ede8ebbf44f3144f1afc801b93531` (tree `ab0a568a3ed73bf0471ce76c2a572b2b04812d01`).
`CommercialCommitmentValidator` now proves the exact acceptance-fact chain behind the purchase — a
successful, accepted evidence row for the exact offer and obligation, its amount/currency/occurrence
against the authoritative obligation and the purchase's recorded acceptance instant, the exact
obligation settlement for that evidence, and the exact payment fact binding purchase, evidence and
obligation — and an R1 successor claim is accepted only when it carries its mandatory Phase-Q
predecessor hold, an active successor state, coherent immutable source/pattern identity and a complete
interval aggregate validated by the canonical claim validator over locked intervals. Command replay
for capacity handoff, claim release and Term binding may report idempotent success only after that
current stored aggregate is re-proved with locks inside a transaction; the rolled-back duplicate path
re-runs the revalidation in its own transaction. Valid unchanged state still replays idempotently, and
at-rest corruption fails closed without creating a second result or rewriting the corruption. The
reviewed commits remain immutable and no force push was used. State: `CORRECTION ROUND 4 CANDIDATE`; that candidate's own independent re-review then failed
`C5-MAJOR-001` (release replay lifecycle/result integrity), `C5-MAJOR-002` (settlement
occurrence/currency and payment-fact currency) and `C5-MAJOR-003` (full Term command-result
validation).
**Correction round 5 (historical; independent re-review FAILED on one finding):** additive descendants of the reviewed correction-round-4
commit `6de25b8c32a21d060c27e0f98a8a05a4d1a7bfaa` (tree `24cf30abbb689d8668fc90aee2793114a736685b`).
A recorded release now replays only against the exact released lifecycle (a coherent active aggregate
can no longer satisfy it, no protected interval may remain, the controlled release reason and
`released_at` are required, and the recorded command must identify that exact claim with no borrowed
offer identity); the settlement occurrence is bound to the authoritative acceptance timeline
(`settled_at` = the accepted evidence's `ingested_at`) with settlement and payment-fact currency
positively validated across the obligation/evidence/purchase/offer chain; and Term-binding replay
requires the recorded command result to agree exactly with the revalidated commitment, claim, Term and
funding plan (`result_state`, `result_id`, `term_id`, `entitlement_id`, `purchase_id`, `offer_id`,
`claim_id`, `student_id`). Corruption of any of those at rest fails closed with no duplicate result and
no silent repair, and every probe converges again after exact restoration. The reviewed commits remain
immutable and no force push was used. State: `CORRECTION ROUND 5 CANDIDATE`; that candidate's own independent re-review then failed
`C6-MAJOR-001` (complete operation-specific `commercial_commands` selector shape).
**Correction round 6 (current candidate):** additive descendants of the reviewed correction-round-5
commit `2af26260d1ba711a18f9fc73c15923531cab69cd` (tree `46c712744ad545d7a7cb49ec03defddc97080f1a`).
`CommercialCommandShape` is the one canonical complete-shape check for a replayed commercial command:
it enumerates every nullable selector the command schema carries, declares the exact selector set each
operation owns, requires every owned selector to equal the revalidated aggregate, requires every other
selector (including `term_id`/`obligation_id` on capacity commands and `teacher_id`/`obligation_id` on
Term binding) to be exactly NULL, and requires the domain, operation, `result_state`, `result_id` and
audit fields to be exactly the revalidated recorded result. A foreign-but-valid identifier in an
inapplicable selector now fails closed with no duplicate command or downstream truth and no silent
repair. The reviewed commits remain immutable and no force push was used. State: `CORRECTION ROUND 6
CANDIDATE — AWAITING INDEPENDENT RE-REVIEW` (not passed, not merged, not deployed). Schema 25 /
migration `025_commercial_purchase_funding_authority` / build
`phase2a2r1-commercial-purchase-funding-authority-20260920.1` are unchanged.
Deferred to Phase R2: recurring enrolment, renewal cycles and next-Term boundary movement, automatic
and manual collection modes, the four-week manual guarantee, recovery/lapse, refund review trajectory
and the channel-neutral notification intents. Terms & Conditions alignment remains a separate
downstream workstream. **Known follow-up (pre-existing, unrelated to R1):** the Phase-Q *runtime*
suite cannot run green on a freshly built disposable runtime and fails identically on untouched
`1b9d7aae` (`assignment_changed`), so it must be repaired before it can be quoted as regression
evidence.

## Phase 2A.2-R2 candidate — Renewal, Next-Term, Recurring Enrolment/Collection, Recovery, Lapse & Refund Authority (unmerged)

**CANDIDATE / INDEPENDENT REVIEW PENDING.** Authoritative base is post-R1 `main` at
`f9df3bfb0fda79fba7dee916c4687464ee67d480` (Schema 25 / R1 merged). Schema 26 / migration
`026_renewal_recurring_enrolment_authority` / build
`phase2a2r2-renewal-next-term-collection-recovery-20260923.1`. **Not merged, not deployed, no
production access**, and no Stripe/notification/Theme/Amelia surface was acquired.

R2 is an orchestration and cross-Term authority layer above R1 and preserves every R1 invariant: money
stays integer minor units with an explicit currency, the Platform-issued offer remains the only
whole-Term price snapshot, funding is derived only from accepted obligations, Phase L remains the sole
Term authority, Phase M owns Lessons, Phase N owns schedules and Phase Q owns the pre-payment hold. It
never reimplements R1 pricing, acceptance, funding derivation, protected claims or Term binding:
next-Term creation delegates to `CommercialTermFundingService` → Phase-L, and protected-capacity
release delegates to `CommercialCapacityService`.

It owns six aggregates with append-only events, digest-only commands, version-guarded transitions and
capability-protected PII-minimised read seams: `recurring_enrolments`, `renewal_cycles`,
`collection_intents`, `recovery_cases`, `refund_review_cases` and `recurring_protections`.

Locked representative decisions and boundaries:

- The next-Term boundary is **progression-derived** from the current Term's applicable schedule
  versions and the R1 Regular recurring-pattern occurrences resolved through the Phase-Q wall-clock
  rule. It is never `intro + 7 days`, never wall-clock guessing and never provider-inferred; an
  unavailable fact fails closed with `boundary_facts_required`.
- The manual same-slot guarantee is the locked `n = 4` weeks window resolved in the pattern timezone
  and recorded at boundary derivation.
- Every R2 mutation serialises on the **R1 commercial account root** for the owning beneficiary
  Student, so the fixed R1 lock order is never inverted and unrelated Students never contend. R2 owns
  no independent serialisation device.
- R2 never nests a transaction inside a delegating R1 command. The R1 binding and the R1 capacity
  release commit as their own units; R2 then verifies the durable R1 outcome inside its own serialised
  transaction and records it, so a retry adopts the durable fact instead of duplicating a Term,
  funding plan, entitlement or release.
- Notification intents are provider-neutral **intent names only**, published through the existing
  `platform_outbox` seam with a keyed digest identity. No template, no recipient and no delivery
  record exists; delivery belongs to Phase S and the charge adapter to Phase T.
- The four deferred product decisions stay behind safe unset/deferred seams: `academic_consequence` is
  never written (reads report `unresolved`, no funded-session clawback or reversal), no advance
  automatic-charge instant exists while `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` is unset, nothing lapses
  automatically while `PAYMENT_RECOVERY_POLICY` is unset (capacity stays protected), and provider
  confirmation arrives only as R1 `commercial_payment_evidence`.

**Correction round 1 (derived-fact ownership and terminal-cycle integrity).** The candidate now
proves the source-Term authority of every boundary derivation (a Term the recurring enrolment does not
own fails closed with `canonical_source_term_required`), proves that a collection intent names the
exact cycle obligation (same R1 beneficiary Student and Course, cycle currency) with
`collection_obligation_ownership_conflict` otherwise, collects a cycle only from its authoritative
first non-cancelled obligation, refuses to make a cycle terminal while it still owns an active
continuous protection (`recurring_protection_release_required`, so R1 capacity is never released as a
silent side effect and no live claim is orphaned behind a lapsed cycle), and blocks closing a
recurring enrolment while a refund/reversal review of its own canonical Enrolment is still open. The
Schema 26 verifier additionally rejects storage smuggled in beside the phase tables. No product
decision, provider column, lock, Term/Lesson/schedule writer or delivery path was added.

**Correction round 2 (successor-Term position, cross-commitment ownership and accepted evidence).**
`bind_next_term` now forwards the Phase-L aggregate position it replaces — the latest non-archived
canonical Term of the cycle's own Enrolment and its `closed`/`cancelled` state — proved from stored
facts before delegation, because Phase L refuses to create a successor Term without that proof and a
real renewal always has a current Term. The command fails closed with
`renewal_aggregate_position_required`/`renewal_aggregate_position_mismatch` and never closes,
cancels or guesses a Term itself. In the same family it now proves the next-Term entitlement belongs
to the cycle's own beneficiary Student/Course/currency before delegating
(`renewal_entitlement_ownership_conflict`) and that the created Term's funding plan sits in that same
chain afterwards (`renewal_term_binding_conflict`); continuous protection re-proves its adopted claim
inside the serialised transaction and refuses a foreign or already-linked claim
(`recurring_protection_claim_conflict`); a collection intent and a cycle may only be confirmed by
*accepted* R1 payment evidence for the exact obligation (`accepted_payment_evidence_required`); and a
refund/reversal review must record its own purchase, obligation and accepted evidence
(`refund_review_evidence_conflict`). The Schema 26 verifier now also rejects a raw key or raw
reference column on append-only phase storage, and the shared fixture now gives the funded Term its
authoritative occupancy (activated Term, Teacher Assignment, one standard Lesson and its first
schedule version) so the boundary derivation is exercised against real schedule facts.

**Correction round 3 (contract §13 pre-implementation prerequisites).** Three prerequisites that the
contract lists as gating R2 execution are closed, each additively to the authority rather than by
changing it. (a) Every `tests/*-concurrency-runner.sh` is now committed as mode `100755`: the
host-run disposable-runtime validation of `f9df3bf` failed all nine R1 concurrency modes with
`/tests/phase-2a2r1-concurrency-runner.sh: Permission denied`, which is a file-mode defect, not an
authority defect. (b) The shared `tests/phase-2a2r1-fixture.php` no longer derives every scenario's
authorised first regular slot from the same fixed interval: because the disposable runtime keeps one
database per suite and the fixture uses one Teacher throughout, an earlier suite's legitimately
scheduled applicable canonical Lesson at that exact interval made the *next* suite's Phase-Q hold fail
with `teacher_slot_conflict` (fixture order, not authority). The helper now authorises the first
whole-week candidate free of applicable canonical Lesson schedules, effective Phase-Q holds and
active protected R1 capacity intervals, and fails loudly when none exists; the slot is still an
explicitly administrator-authorised record. (c) The stale-documentation debt is closed: README,
continuity, architecture, module boundaries, migration strategy, policy registry, the Phase-R1 phase
document, data model and changelog now record R1 merged/closed at Schema 25 on `main` with the Schema
26 R2 candidate above it. Product sign-off on the four deferred decisions remains an owner decision
and was not invented.

**Correction round 4 (recovery-state enforcement, §5.4).** Independent review of the round-3
candidate failed on one finding: the Recovery Case authority recorded the representation of a
recovery without enforcing the two states the contract fixes for it. Both are now proved from stored
facts inside the serialised transaction, after the owning Student's R1 commercial account root is
held. A recovery case may only be opened on a **failed** collection intent of a still-live cycle — a
pending, submitted, confirmed, recovered or cancelled intent, or a cycle that already reached a
terminal state, fails closed with `collection_intent_not_failed` / `invalid_renewal_cycle_state` — and
`recovered` records the **accepted R1 evidence that settled the obligation** through the same shared
settlement seam the collection commands consume (`obligation_not_settled` when nothing settled,
`accepted_payment_evidence_required` when the settlement rests on evidence R1 never accepted). R2
writes no settlement, reversal or clawback, and a recovery may be recorded from `open` or
`recovering`. No product policy, provider column, lock, Term/Lesson/schedule writer or delivery path
was added.

Detailed contract, seams, test matrix and evidence status:
[PHASE-2A-2R2-RENEWAL-NEXT-TERM-COLLECTION-RECOVERY-AUTHORITY.md](PHASE-2A-2R2-RENEWAL-NEXT-TERM-COLLECTION-RECOVERY-AUTHORITY.md).
State: `CANDIDATE — AWAITING INDEPENDENT REVIEW` (not passed, not merged, not deployed).

## 5. Persistent architectural boundaries

Delnavazan Platform is an incremental authority migration away from architectural dependence on Amelia. Core owns stable business identity and canonical state; integrations reference that state through explicit boundaries.

- Do not add new Amelia dependencies or write to Amelia merely because Platform source exists.
- Teachers, Students, Enrolments, Terms and Lessons remain distinct concepts; Lesson is the later operational centre.
- Provider mappings are not business identity. Do not automatically merge identities.
- Migrations are versioned, ordered, retry-safe and separate from authority cutover.
- Security, privacy, capability checks, provenance, idempotency and concurrency testing are first-class requirements.
- No deployment, merge, public endpoint, external communication or payment activity is implied by source completion.

Historical Phase 0/Phase 1 planning documents remain useful where they do not conflict with this newer coordination/Proposal/Acceptance contract.

## 6. Theme status and boundaries

| Item | State |
|---|---|
| Theme repository | `Mr-M13/delnavazan-theme` |
| 0.4.1 source baseline | `e8f6da4cc365b61aaf1e0356f11100c24449730d` |
| 0.4.1 validated package | `delnavazan-production-theme-0.4.1.zip` |
| 0.4.1 package SHA-256 | `4f90bea5c58b0426d6ae81b16c676d0d477d97fa6c3047918f2c90f276a435b3` |
| NIU 0.4.1 state | Manually installed; no production deployment |
| Historical 0.4.2 branch | `codex/increment-0.4.2-art-direction` |
| Historical 0.4.2 candidate | `a4dfc4acd614f049c725f599a4d856ea40f6d517` |

Theme 0.4.2 implements a contemporary Persian cultural-institution direction: an asymmetric replaceable hero, restrained Persian typography, Custom Logo header/footer, corrected SVG hamburger, six-item instrument folio, human coordination reassurance, presentation-only regional pricing, FAQ before articles, homepage date removal, and responsive/accessibility refinements.

Final Academy-owned or licensed hero/instrument imagery remains required before visual sign-off. The Theme remains presentation only: it has no Platform, pricing-authority, payment, enrolment, Teacher, scheduling, notification, calendar or Amelia business authority.

### Current header and homepage continuity

The public Theme remains Persian-first (`fa-IR`), RTL, refined/editorial/warm/human, with warm ivory, Delnavazan turquoise, restrained pomegranate, PHP templates, `theme.json`, Gutenberg and minimal JavaScript. Desktop navigation order is `خانه`, `ورود هنرجویان`, `ورود اساتید`, `مقالات`, `ثبت نام`. Ordinary links use deep Delnavazan turquoise; only `ثبت نام` receives CTA treatment. Preserve Custom Logo, the warm-ivory header and mobile-menu behaviour.

Current homepage work includes refined responsive styling, corrected mobile/full-width sections, the green pricing/finale section, and reuse of `BG-Ornoments.webp`. The artwork already carries reduced opacity, so CSS renders it at `opacity: 1`. Footer intent is logo right/top, actions centred/top, legal left/bottom and secondary navigation right/bottom.

The homepage shows three randomly selected published articles. Gutenberg Query Loop remains presentation markup. The identifying class `dzn-home-random-posts` belongs on the inner `core/post-template` block; `query_loop_block_query_vars` then targets that query with `posts_per_page = 3`, `orderby = rand` and `ignore_sticky_posts = true`. Putting the class only on the outer `core/query` block does not target the query-vars filter. Authoritative Theme source must contain no temporary forced `post__in`, post ID or footer diagnostic comment.

### Theme staging

NIU (`https://niu-nailhouse.com`) is disposable, sanitised Theme staging. Preserve the MU staging guard and keep ordinary plugins inactive. Do not casually re-run sanitisation. NIU itself is not globally unavailable: product-owner normal browser access works, while Cloud Browser currently receives 502/connection-refused responses. Do not repeatedly spend agent quota retrying that browser path.

## 7. Locked commercial and public-contact facts

### Payment journey

Free introductory lesson → learner decides whether to continue → term tuition paid → paid 12-session term begins → educational Lesson 1 of 12.

Canonical public wording is **one term / 12 weekly private lessons**. Do not call the canonical term “3 months”.

### Regional set pricing

This is configured regional pricing, not live FX conversion:

| Region | Currency | Amount |
|---|---|---:|
| Australia | AUD | 250 |
| New Zealand | NZD | 250 |
| United States | USD | 250 |
| Canada | CAD | 250 |
| Euro pricing region | EUR | 150 |
| United Kingdom | GBP | 150 |

UAE/AED, Kuwait/KWD, Turkey/TRY and other Gulf regions are future candidates only, not active offers.

Theme 0.4.2 may suggest a supported region from a location signal, accepts an explicit manual selection and stores that UI preference locally. It remains neutral for unsupported/failed detection and never silently defaults to the United States. It has no FX, payment or entitlement authority.

The future authority flow is: location signal → suggested region → visitor selection → UI preference → enrolment context → Platform authoritative price revalidation → later payment/Stripe authority.

### Public contact

- Customer phone: **0413 413 004**
- Email: **delnavazan@mail.com**
- Instagram: **@insta.delnavazan**
- `+61 431 364 200` is separate WhatsApp notification infrastructure and must not be exposed as customer-facing contact.

## 8. Execution posture

CD / ChatGPT is the architect, orchestrator, dependency manager and handoff coordinator: it controls sequencing, dependencies, collision avoidance and review routing.

Ina / Codex Local and Hamed / Codex Cloud operate under a dual-owner, reciprocal-review model. Either may own a complete bounded slice and perform reconnaissance, implementation, tests and self-review. Ownership alternates according to dependency, quota/capacity, task shape, context and efficiency; neither identity is permanently restricted to developer or reviewer work.

For consequential migrations, authority transitions, concurrency/idempotency, identity/privacy, payments, external side effects, destructive operations and consequential merge candidates, the non-owner performs independent cross-review. Routine deterministic/documentation work does not automatically require independent review. After an independent PASS, the implementation owner may perform deterministic merge/closeout only when the exact reviewed candidate is preserved.

Parallelise genuinely dependency-independent work. Do not parallelise sequential authority work where that would create speculative implementation, collisions or rework. Avoid duplicate expensive reconnaissance and runtime work.

Delivery cadence should reuse established patterns rather than automatically splitting every capability into tiny phases. Prefer a larger bounded increment when authority boundaries are established, rollback is clear, validation remains tractable, concurrency risk is controlled and independent review can inspect one coherent candidate. Retain smaller increments when migration or authority risk genuinely requires them.

Delivery posture: **CONTROLLED MOMENTUM**. For bounded, reversible work: IMPLEMENT → TEST → INSPECT → CORRECT. Remain strict around production, real-user/private data, security/privacy, destructive migrations, payments, external communications, Amelia/calendar, identity/guardian authority, acceptance/conversion authority and consequential deployment.

## 9. New-session checklist

Before starting another slice, establish:

1. the exact repository, branch and SHA;
2. whether the work is merged, a candidate, or reconnaissance only;
3. the relevant locked domain boundary and explicit exclusions;
4. whether runtime/deployment authority exists;
5. which agent/environment is already working on adjacent scope;
6. the single next authorised action.

Never treat this document as authority to deploy, merge, access production, change NIU, activate plugins, send communications, or extend a later phase.

### Historical candidate records (superseded — provenance only)

The two paragraphs below described Phase 2A.2-L and Phase 2A.2-M0 while they were still unmerged candidates. Both slices have since been independently reviewed, merged and closed (Phase L at Schema 18, Phase M0 at Schema 19), so they are historical provenance and must not be read as current state.

> **Historical Phase 2A.2-L record:** Phase L was an unmerged candidate adding explicit canonical Term creation and bounded lifecycle authority with durable idempotency. Canonical Enrolment remains Student + Course; Teacher Assignment remains the sole current-Teacher authority. Term closure/cancellation does not mutate Enrolment, and no Lesson, payment, scheduling or integration authority follows from a Term command.

> **Historical Phase 2A.2-M0 record:** the formerly unmerged M0 candidate advanced its package identity to Schema 19 / `019_canonical_enrolment_lifecycle_authority` and build `phase2a2m0-enrolment-lifecycle-authority-20260917.1`, made the explicit Enrolment lifecycle graph operational and left canonical Lesson authority non-authoritative. The frozen pre-M0 Lesson candidate was subsequently resumed as Schema 20 / Phase M.
