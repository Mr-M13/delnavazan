# Phase 2A.2-U — Finance & Reporting Authority: Lesson Payability, Effective-Dated Teacher Rates, Per-Lesson Rate/Currency Snapshots, Teacher Compensation Statements, Reconciliation Read Models & Audited Corrections (Schema 030)

**Status:** implementation contract (preflight). Contract authoring and planning only — this document
creates no schema, migration, service, capability, test, route or configuration, and it authorises no
implementation, merge, deployment or production access by itself.
**Schema:** 030 (`030_finance_payability_rate_statement_authority`).
**Build:** `phase2a2u-finance-payability-rate-statement-20260925.1` (proposed).
**Correction round 2:** additive descendant of the reviewed candidate
`4804dcc59112fa8da462fe935f66045126eaedb7` (tree `859da7a579b0ac72695f2436c766cb6ecb7786f7`), closing
the six blocking findings of that independent review — §0a.
**Correction round 3:** additive descendant of the reviewed candidate
`deeb4cec1fbf6c02ec387d58555c645ffeb7c16d` (tree `2aac11c076e0ce752dc1c524b460c1d221515aae`), closing
the six blocking findings of that independent review — §0b.
**Correction round 4:** additive descendant of the reviewed candidate
`aedd162cfc29ff1857e9d84d2622e2734bf1265c` (tree `0ea3fc572dc935392467291dc16ce349e2795e64`), closing
the three blocking findings of that independent review — §0c.
**Correction round 5:** additive descendant of the reviewed candidate
`aedd162cfc29ff1857e9d84d2622e2734bf1265c` (tree `0ea3fc572dc935392467291dc16ce349e2795e64`), closing
the one blocking finding of the independent review of that candidate — §0d. Rounds 4 and 5 are carried in
this same candidate: the round-4 review inspected the candidate tree of `aedd162…` itself, so §0c's
corrections and §0d's are both stated on top of it and neither rewrites a word of it.
**Correction round 6:** additive descendant of the reviewed candidate
`61b72d9ec6a4c851ec741eba085e73f531d56913` (tree `e4d447dcd3d103484b2c029de9b248781b1517e0`), closing
the three blocking findings of the independent review of that candidate — §0e. Rounds 2–5 are carried
unchanged in this same candidate: none of them is rewritten, amended, rebased or force-pushed, and §0e
states its corrections on top of them.
**Base:** `main` @ `b36561dc6bb6e87fd142a28ae67fbc4f2fdc9279` (tree
`51c887ee62dc10cefbfb7d4763b7be63ecb329a2`), whose content is the materialised Phase 2A.2-T
implementation candidate at Schema 29.
**Owner brief this document answers:** *"Prepare Phase 2A.2-U / Schema 029 implementation contract for
Finance and Reporting: lesson payability policy, effective-dated teacher rates, immutable per-Lesson
rate/currency snapshots, statements, reconciliation read models and audited corrections. Extend M/O and
commercial/payment facts; do not create accounting authority outside Platform. No production or
deployment."*

## 0. Schema number reconciliation — read this before anything else

The owner brief says **Schema 029**. In this repository Schema 029 is **already owned** by the Phase
2A.2-T candidate: `dzn_platform_schema_version` is `29`, the migration ledger's latest entries are
`028_payment_execution_seam_provider_adapter` and `029_payment_event_decision_claim_authority`, and both
migration identifiers are already written into `Migrator::maybe_upgrade()`, `verify_current_schema()`
and the Phase-T capability repair block. A migration identifier is never re-used, and a completed
migration is never re-applied.

Therefore this contract targets:

| Field | Value |
| --- | --- |
| Phase | 2A.2-U — Finance & Reporting authority |
| Schema | **030** |
| Migration identifier | **`030_finance_payability_rate_statement_authority`** |
| Previous schema | 029 (Phase 2A.2-T candidate tree) |

The owner's "029" is read as the *next* free schema at the time the brief was written, not as a
re-assignment of the Phase-T aggregate. Re-assigning 029 would require deleting a migration identifier
that already exists in code, which the migration discipline of this repository forbids. **Confirming
030 (or explicitly re-scoping the ledger) is prerequisite 1 of §20.** Nothing else in this contract
depends on that confirmation: every design decision below is independent of the number.

## 0a. Independent review correction round 2 — findings and corrections

The independent review of candidate `4804dcc59112fa8da462fe935f66045126eaedb7` (tree
`859da7a579b0ac72695f2436c766cb6ecb7786f7`) returned **FAIL — CORRECTION REQUIRED** with six blocking
findings. All six are corrected on descendants of that commit (never rewritten, never amended, never
rebased, never force-pushed), and each correction is stated in the section it governs:

| Finding | Correction |
| --- | --- |
| U-C2-BLOCK-001 — temporal resolution discarded history | Both resolvers now resolve **the fact that covers the requested instant**, independently of any current/live marker, under one safe future-effective successor model; neither reads the wall clock. `TeacherRateService::resolveFor()` builds its candidate set from **interval containment alone** (`effective_from ≤ instant < COALESCE(effective_until, '9999-12-31')`), so a `superseded` predecessor that covers the instant resolves exactly like a live row, a rate successor recorded with a future `effective_from` closes its predecessor at precisely that instant, and a delayed capture of an earlier Lesson resolves the rate that was in force then (§7.1–§7.4). `FinancePolicyService::resolve()` resolves the version with the greatest `effective_from ≤ instant`, where a `superseded` version is normal history and only a `withdrawn` version shadows its own instant into unset (§6.1). `status` is therefore a live/retraction marker and never an applicability gate. Delayed historical-capture tests, a future-effective-successor test and a withdrawn-coverage test are added (§18). |
| U-C2-BLOCK-002 — `finance_policies` immutability contradicted its own transitions | One model is now stated everywhere: `finance_policies` is **immutable in value and mutable in exactly one column** (`status`), the same *audited conditional state stop* idiom as `finance_teacher_rates` but with a single column. `policy_key`, `policy_version`, `policy_value`, `value_type` and `effective_from` are never updated; the only two permitted moves are the conditional `active → superseded` and `active`/`superseded` → `withdrawn` statements of §6.1, each with its affected-row count as the outcome (§6.1–§6.2, §13.2, §13.4 rules 2 and 9, §13.6, §14.1, §18). The §13.4 verifier's permitted-mutation list now names `finance_policies`' single `status` column, so the required transition and the verifier can no longer contradict each other. |
| U-C2-BLOCK-003 — declared tables omitted named indexes for declared `*_id` columns | §13.3 now states the invariant precisely (**every declared `*_id` column is the leftmost column of at least one declared named index**; audit actor columns are explicitly out of scope), and §13.2 declares the missing keys — `finance_lesson_snapshots` `enrolment_id`/`teacher_assignment_id`/`course_id`, `finance_payability_evaluations` `delivery_outcome_id`/`academy_obligation_id`/`schedule_version_id`/`override_id`/`superseded_by_evaluation_id`, `finance_statement_lines` `snapshot_correction_id`/`payability_evaluation_id`/`teacher_id`/`course_id`/`rate_id` — together with the same treatment for every other declared table (corrections, overrides, statement events, findings, exceptions, rate commands) and an explicit named index for every command-table selector. §13.4 rule 3 and the §18 migration suite reject a `*_id` that is not the leading column of a named index. The same declared-set-versus-assertion defect is closed for table identity: §13.1 previously asserted a `uid`/`reference_code` on **every** table while §13.2 declared neither on twelve of them, so §13.1 now names exactly the five handle tables and §13.4 rule 1 asserts that split. |
| U-C2-BLOCK-004 — legacy `lesson_schedule_versions.id` was a declared Finance parent | `lesson_schedule_versions.id` is **removed** from §13.3's frozen-parent set. `canonical_lesson_schedule_versions.id` is the only schedule parent any Finance row may name, the legacy Phase-1 schedule table is never a Finance parent, and the verifier rejects a reference to it (§13.3, §13.4 rule 3). |
| U-C2-BLOCK-005 — reason-code vocabulary incomplete and inconsistent | One exhaustive allowlist (`FinanceRule::REASON_CODES`) now governs every refusal, blocker, exception, finding, run failure code and chain/event reason, with two declared views over it (`EXCEPTION_REASON_CODES`, `FINDING_CODES`) and one declared operator/migration set (§5.2.1). `rate_timeline_overlap`/`rate_timeline_gap` are unified to `teacher_rate_timeline_overlap`/`teacher_rate_timeline_gap`, `snapshot_replay_conflict` to `command_replay_conflict`, and `correction_requires_statement_supersession`/`statement_requires_supersession` to the single `statement_supersession_required`. Every code any section emits or blocks on — including `finance_policy_timeline_overlap`, `policy_effective_from_precedes_recorded_consumption`, `rate_referenced_by_snapshot`, `rate_effective_from_precedes_snapshot`, `occurrence_anchor_missing`, `finance_lesson_kind_not_allowed`, `finance_amount_not_exact`, `payability_supersession_conflict`, `payability_override_target_invalid`, `statement_period_too_long`, `statement_derivation_mismatch`, `snapshot_correction_incomplete`, `finance_vocabulary_member_not_allowed` — is a declared member, and §5.4 and §18 require services and tests to use it exclusively. |
| U-C2-BLOCK-006 — no valid draft representation while the statement timezone policy is unset | The statement timezone representation is now an explicit, validated, all-or-nothing triple (§10.1–§10.2): `period_timezone`, `period_label` and `timezone_policy_version` are recorded together (policy set) or are all `NULL` (policy unset, the recorded unset representation). Drafting with the policy unset is legal; a partial triple is corruption (`statement_timezone_representation_invalid`) and fails closed; issuance requires the recorded triple, refuses `policy_unset_for_statement_period` otherwise, and the operator then sets the policy and withdraws + re-drafts (§10.5 #8). The column types, the verifier and the statement tests are corrected with it (§13.2, §13.4, §18). |

## 0b. Independent review correction round 3 — findings and corrections

The independent review of candidate `deeb4cec1fbf6c02ec387d58555c645ffeb7c16d` (tree
`2aac11c076e0ce752dc1c524b460c1d221515aae`) returned **FAIL — CORRECTION REQUIRED** with six blocking
findings. All six are corrected on descendants of that commit (never rewritten, never amended, never
rebased, never force-pushed), and each correction is stated in the section it governs:

| Finding | Correction |
| --- | --- |
| U-C3-BLOCK-001 — the statement-timezone policy had two incompatible unset representations | One representation now governs the whole phase: **unset is the absence of a covering version, never a row.** `FINANCE_STATEMENT_TIMEZONE` is **not seeded** and has **no** `finance_policies` row until the academy records one, and every `finance_policies` row is value-bearing (`policy_value` and `value_type` both `NOT NULL`), so the "recorded null-valued version" shape is removed instead of surviving as a second, unverifiable unset state. The §6.1 key table, the §6.1 version-shape rule, the §13.2 column spec, the §13.4 rule 9 verifier, the §13.5 three-seed migration rule, the §18 contract/migration/statement suites and the §23 definition of done state the same thing (§6.1, §10.1, §13.2, §13.4, §13.5, §18, §23). |
| U-C3-BLOCK-002 — the policy lifecycle forbade a transition its own behaviour promised | `finance_policies.status` may now move **at most twice per row, in one declared direction**: `active → superseded` (only when a successor version exists) and then `superseded → withdrawn`, or `active → withdrawn` directly. `withdrawn` is terminal, a second supersession is refused, and each move is its own audited conditional statement with its own affected-row count. The "moves once per row" prose, the §13.2 rule, the §13.4 rule 2 verifier and the §13.6 repository rule now match the two permitted transitions of §6.1, so retracting superseded historical authority is representable and re-verifiable (§6.1, §6.2, §13.2, §13.4, §13.6, §18). |
| U-C3-BLOCK-003 — the rate mutation limit named two columns while its lifecycle moved three | §7.2 now declares the **three** mutable physical columns `effective_until`, `status` and `active_slot`, with `effective_until` written at most once and `status` moving at most twice in the declared order `active → superseded → withdrawn` (or `active → withdrawn`), each move clearing `active_slot` in the same conditional statement and recording its own event row. The §13.2 heading, the §13.4 rule 2 verifier, the §18 contract suite and the §23 definition of done state the same three columns and the same at-most-twice lifecycle (§7.1, §7.2, §13.2, §13.4, §18, §23). |
| U-C3-BLOCK-004 — the rate resolver returned conflicting outcomes for withdrawn coverage | §7.3 is now **one ordered algorithm**: it builds the interval-coverage set regardless of status, fixes the winning specificity from that set, then **detects withdrawn coverage at the winning specificity before any scope fallback**. A withdrawn `teacher_course` row therefore blocks its instant and never falls back to a broader `teacher`-scoped row, and an eligible set is drawn only from `active`/`superseded` rows at that specificity. The `withdrawn-course-plus-active-teacher` scenario is a required §18 test (§7.3, §7.4, §8.5, §11.1, §18). |
| U-C3-BLOCK-005 — polymorphic command `result_id`, an undeclared `line_id` parent and an unrecordable refusal reason | The generic `result_id` column is **removed**. Every command table declares only typed result references — `result_rate_id`, `result_snapshot_id`, `result_correction_id`, `result_evaluation_id`, `result_override_id`, `result_statement_id`, `result_run_id` — each nullable, each the leftmost column of its own declared named index, and each with a declared parent in §13.3; the refusal `reason_code` of §10.5 is declared on the command tables as well. `finance_statement_lines.id` is added to the §13.3 declared-parent set as the parent of `finance_reconciliation_findings.line_id`. The §18 contract suite asserts the typed set, the absence of any polymorphic `result_id` and every command result parent (§13.2, §13.3, §13.4, §18). |
| U-C3-BLOCK-006 — corrections promised policy versions they had no column to record, and issuance stamped evidence the verifier forbade | `finance_snapshot_corrections` now declares the immutable `intro_policy_key`/`intro_policy_version` pair, restated exactly as the snapshot records it (both set for an `introductory` Lesson, both `NULL` otherwise, a half-set or kind-disagreeing pair refused `snapshot_correction_incomplete`), so a required correction is durably representable and re-verifiable; `finance_statements` now declares the one-time issuance evidence `issued_at`/`issued_by` as part of the conditional `draft → issued` transition, and the §13.4 verifier, the §13.6 repository rule, the §18 mutation/failure tests and the §23 definition of done permit exactly that update and no other (§10.5, §12.2, §13.2, §13.4, §13.6, §18, §23). |

## 0c. Independent review correction round 4 — findings and corrections

The independent review of candidate `aedd162cfc29ff1857e9d84d2622e2734bf1265c` (tree
`0ea3fc572dc935392467291dc16ce349e2795e64`) returned **FAIL — CORRECTION REQUIRED** with three blocking
findings. All three are corrected on descendants of that commit (never rewritten, never amended, never
rebased, never force-pushed), and each correction is stated in the section it governs:

| Finding | Correction |
| --- | --- |
| U-C4-BLOCK-001 — policy mutations were Finance commands with neither a command result store nor any possible serialisation root | The policy registry now declares **both** missing surfaces. `finance_policy_commands` (declared table 20) is the digest-only, immutable command result store for `record`/`supersede`/`withdraw`, with the typed `result_policy_id` and no polymorphic `result_id`; `finance_policy_roots` (declared table 21) is the **global policy serialisation root** — one immutable row (`root_key = 'finance_policy'`, `UNIQUE root_key`) seeded by the migration — because a policy row has no `teacher_id` and therefore no per-Teacher root to take. §15.1–§15.2 place the global root **first** in the fixed lock order, state that it is never taken after a Teacher-scoped lock, and define policy-change-versus-draft-statement handling exactly: a policy mutation and a statement `draft`/`issue` are totally ordered on that root, so a draft records one coherent policy state (a resolved version, or the unset triple) and a retraction that commits after a draft makes that draft stale and is refused at issuance (§6.2, §10.1, §10.5, §13.1–§13.6, §14.1, §15.1–§15.3, §18, §23). |
| U-C4-BLOCK-002 — `resolveException` was declared a Finance command that could record nothing | `finance_reconciliation_commands` now declares the typed exception selector `exception_id` **and** the typed result `result_exception_id` (`finance_exceptions.id`), each the leftmost column of its own declared named index; `finance_exceptions.id` is added to the §13.3 declared-parent set; the §14.1 `resolveException` row names the digest-only command row it writes and the typed result it records, and §18 proves the typed set, the declared parents, the leading indexes and a recorded, replayed and conflicting resolution (§13.2, §13.3, §13.4, §14.1, §18, §23). |
| U-C4-BLOCK-003 — the required audit and notification writes were forbidden by the declared-table rule | A new §15.7 declares the **complete infrastructure write allowlist** for this phase: exactly two tables outside the declared Finance set — `platform_audit_events` (digest-only audit evidence, one insert per audited Finance row, keyed by a derived digest) and `platform_outbox` (the §17 notification intents only, keyed and inserted exactly like the existing `RecurringOutboxRepository` seam) — both written insert-only inside the transaction of the Finance fact they evidence and both carrying identifiers and digests only, the outbox write including the seam's own mandatory insert metadata (its derived key and initial pending state) as §15.7 and §0f declare. U-D3, §13.4 rule 12, §13.6, §15.2, §15.6, §17, §18 and §23 now state the same allowlist, and the §18 source scan proves no other non-Finance table is ever written (§6.2, §13.4, §13.6, §15.6, §15.7, §17, §18, §23). |

Read the BLOCK-003 row above together with §15.7, which is the operative contract: an audit row carries
identifiers, a digest, a reason code and a short safe detail, and an outbox row carries the seam's own
identity columns plus only the seam's own mandatory insert metadata, and nothing else (§15.7) — never a raw
key, a request body, a provider reference, a template, a recipient, a channel address, a delivery outcome, a
populated lease or processed value, an amount, a period bound, a reason code or an entity id beyond those
columns.
"Digest-only" describes the
audit payload discipline; it does not relax the identity-only rule for the intents of §17, which **§0e**
refines and **§0f** completes: "identity-only" governs the row's **business payload** (the three identity
columns), while the row is still written with the seam's own mandatory insert metadata §15.7 declares — the
permitted Finance facts are hydrated by Phase S from the declared aggregate the outbox row names, never
carried on the row itself.

## 0d. Independent review correction round 5 — finding and corrections

The independent review of candidate `aedd162cfc29ff1857e9d84d2622e2734bf1265c` (tree
`0ea3fc572dc935392467291dc16ce349e2795e64`) returned **FAIL — CORRECTION REQUIRED** with one blocking
finding and three required corrections. Each required correction is applied on a descendant of that commit
(never rewritten, never amended, never rebased, never force-pushed) and is stated in the section that
governs it:

| Finding | Correction |
| --- | --- |
| U-C5-BLOCK-001 (a) — a back-dated policy version could invalidate immutable Finance facts: recording required only an `effective_from` later than the prior version, so a successor could be recorded **after** an introductory Lesson snapshot committed with an effective instant **before** that snapshot's locked occurrence instant, and resolution would then select the new version for that historical instant while the immutable snapshot retained the prior one | Policy recording now has a **temporal admissibility rule** (§6.3, U-D19). A version may never be recorded with an `effective_from` that is not strictly later than its key's **recorded consumption maximum** — the greatest policy-relevant instant of an already-recorded dependent Finance fact of that key, where that instant is the recorded occurrence/period instant (a snapshot's `snapshot_instant_utc`, the snapshot instant a governed evaluation binds, a statement's recorded drafting instant) and never the row's insertion time. The refusal writes no version, supersedes nothing, and records the durable reason `policy_effective_from_precedes_recorded_consumption` (§5.2.1, §6.1–§6.3, §13.2, §13.4 rule 14, §14.1, §18.2, §23). Recording into time no recorded fact has consumed stays admissible, so U-D4's historical resolution and the safe future-effective successor model are unchanged. |
| U-C5-BLOCK-001 (b) — policy writes had no shared policy/key lock with the captures that consume them | The global policy root `finance_policy_roots` is now taken by **every policy consumer, not only by statement `draft`/`issue`**: snapshot capture and payability evaluation/override acquire it **shared and first**, for the whole transaction, exactly like `draft`/`issue`, because each resolves a policy version and records the resolution on the row it writes (§6.2, §6.3, §8.2, §9.2, §15.1–§15.2). A mutating policy command holds the same root **exclusively** and performs §6.3's guard read inside that transaction, so the guard can never miss an in-flight consumer, and a policy change and a capture are totally ordered in either direction. §15.1's root discipline, §15.2's ordering table, §18.1's shared-holder sentence, §18.2 and §23 state the same rule. |
| U-C5-BLOCK-001 (c) — the effect of a retraction on already-recorded facts was implicit | §6.3 now declares the **audited consequence path for every affected immutable fact** instead of leaving it implicit, which is the alternative the review named. A retraction of a version some recorded fact consumed is admissible and rewrites nothing: the snapshot keeps its pair, the evaluation keeps its key and version, the statement keeps its triple (U-D7); an affected draft is refused at issuance with `policy_unset_for_statement_period` (§10.5 #8); an affected re-derivation is refused `finance_policy_unset` and appends nothing (§9.4); and a recorded consequence that must actually change goes only through §12's audited `snapshot_correction`/`payability_override`/`statement_supersession` path. A historical fact therefore stays re-verifiable together with the retraction's own record (U-D2, U-D4), and nothing is silently invalidated. |
| U-C5-BLOCK-001 (d) — the required tests did not exist | §18.2 declares the mandatory runtime, corruption, failure and concurrency proofs for a **post-capture back-dated policy record and its rejection/convergence** — a refused record with its exact code and durable reason, an admissible later control, the "never consumed" case, the timezone key against a statement's recorded drafting instant, the §13.4 rule 14 coverage invariant, and the new `policy_record_vs_snapshot_capture` concurrency mode with its capture-first refusal/convergence and record-first coherent-pair outcomes — in addition to the retraction and evaluation cases of the same rule (§6.3, §18.2, §23). |

The round-4 review labelled this finding `U-C4-BLOCK-001`; §0c already uses that label for the round-3
review's first finding, so §0d and §18.2 refer to the back-dated-version finding as `U-C5-BLOCK-001` while
keeping the reviewer's wording intact. Rounds 4 and 5 are carried in this same candidate: the round-4
review inspected the candidate tree of `aedd162…` itself, so §0c's corrections and §0d's are both stated on
top of `aedd162…`, and neither rewrites a word of it.

## 0e. Independent review correction round 6 — findings and corrections

The independent review of candidate `61b72d9ec6a4c851ec741eba085e73f531d56913` (tree
`e4d447dcd3d103484b2c029de9b248781b1517e0`) returned **FAIL — CORRECTION REQUIRED** with three blocking
findings. All three are corrected on descendants of that commit (never rewritten, never amended, never
rebased, never force-pushed), and each correction is stated in the section it governs:

| Finding | Correction |
| --- | --- |
| U-C6-BLOCK-001 — the declared notification intents had no representable outbox payload | The §17 intents are now **identity-only in their business payload**, exactly as the existing `platform_outbox` seam stores them. An outbox row carries the seam's own three identity columns — `aggregate_type` (the declared Finance aggregate), `aggregate_id` (the id of the declared Finance row that raised the intent) and `event_type` (the intent name) — plus only the seam's own mandatory insert metadata (§15.7, refined by §0f), and the permitted Finance facts (`teacher_id`, period bounds, currency, payable total, finding/reason codes, entity ids) are **hydrated by Phase S** from that declared aggregate through the declared Finance read services of §14.1. No amount, period bound, currency, reason code, entity id, template, recipient, channel address or message body is written to `platform_outbox`, no payload column is added, and migration 030 still performs no `ALTER` on that table (§13.5, §15.7, §17, §18, §23). |
| U-C6-BLOCK-002 — the serialisation root of a teacher-less `resolveException` (and every other teacher-less Finance command) was stated against itself | Root selection is now a single declared function of the command's **target scope**, not of its operation name. A command whose target row carries a `teacher_id` takes that Teacher's finance root; a command whose target row has `teacher_id = NULL` (policy-command refusals, period-wide reconciliation runs and their findings, and a `resolveException` on a teacher-less exception) takes the **global policy root shared** as its one root. Policy consumers additionally take the global policy root **shared and first** (§6.3, §11.2, §14.1, §15.1–§15.2, §18.1, §18.3, §23). |
| U-C6-BLOCK-003 — a business refusal's required durable evidence was destroyed by the whole-transaction rollback rule | §15.8 now declares the split the contract was missing: a **business refusal** commits exactly its refused `finance_*_commands` row, the matching `finance_exceptions` row and their digest-only audit evidence in the declared **refusal-evidence transaction**, while every attempted mutation of the command rolls back; a **persistence or corruption failure** rolls the whole command back — no Finance row, no command row, no exception, no audit row and no intent — and reserves whole-transaction rollback for exactly that case (§6.3, §15.6–§15.8, §18.1, §18.3, §23). |

The round-4 prose that described an outbox row as carrying "identifiers and exact integers" is refined by
U-C6-BLOCK-001: the operative rule is §15.7 and §17, where an outbox row carries the seam's own identity
columns plus only the seam's mandatory insert metadata, and Phase S hydrates the permitted facts from the
declared aggregate. Where the historical round-2…round-5 prose of §0a–§0d and that paragraph is narrower
or broader than §15.7/§17/§15.8, this round's sections govern, and no earlier round is rewritten. Round 7
(§0f) refines the U-C6-BLOCK-001 row above — corrected in place to state the same split — without
relocating the rule: "identity-only" is a statement about the row's **business payload** (the three
identity columns), and a Finance outbox write still supplies the seam's own mandatory metadata — the
derived `idempotency_key`, the initial `pending` state and the explicit `NULL` lease and legacy-identity
columns — because the unchanged `platform_outbox` table cannot be inserted without them (§15.7, §17,
U-C7-BLOCK-001).

## 0f. Independent review correction round 7 — finding and correction

The independent review of candidate `7e4d82e1773dfbb78793670da0ef267f7a57b213` (tree
`7abf56656a3c83d4c63d28f336f961da6b3db57a`) returned **FAIL — CORRECTION REQUIRED** with one blocking
finding, corrected on a descendant of that commit (never rewritten, never amended, never rebased, never
force-pushed) and stated in the section it governs:

| Finding | Correction |
| --- | --- |
| U-C7-BLOCK-001 — the identity-only rule forbade the write the unchanged seam requires | The round-6 rule was written as though an outbox row may carry **nothing** beyond `aggregate_type`/`aggregate_id`/`event_type`. That is incompatible with the seam: `platform_outbox.idempotency_key` and `created_at` are `NOT NULL` with no default, `status` and `available_at` are `NOT NULL`, and the established publisher (`RecurringOutboxRepository::publish()`, over the `platform_outbox` table created by the principal-invitation foundation migration `002_principal_invitation_foundation`) writes them together with `attempt_count = 0` and explicit `NULL` for `leased_at`, `processed_at`, `invitation_id` and `generation_id`. The contract therefore simultaneously **required** an idempotent outbox insert and **prohibited** the columns that insert is made of, and its mandated source scan would have rejected a valid seam-compatible implementation. The rule is now split, not weakened: §15.7 and §17 declare the **exact allowed insert shape** — the seam's three identity columns plus only the seam's own **mandatory seam metadata** (`idempotency_key` = the derived digest under the table's `UNIQUE idempotency_key`, `status = 'pending'`, `available_at` and `created_at` = the transition instant, `attempt_count = 0`, and the explicit `NULL` `leased_at`/`processed_at`/`invitation_id`/`generation_id`) — while every Finance **business fact** on the row and every payload column stay prohibited: no amount, period bound, currency, reason or finding code, entity id beyond the row's own `aggregate_id`, template, recipient, channel address, message body, raw key, command digest or request body. Phase U therefore **initializes** the seam's required pending delivery state and does not own the delivery lifecycle that follows: it writes the initial `pending` row exactly once and never writes a later `status`, lease, attempt or `processed` value. The same split is stated in U-D3, §13.4 rule 12, §15.7, §17, §18.3–§18.4 and §23, and a seam-compatible insert of exactly the declared shape is explicitly valid rather than a verifier failure. |

The round-6 prohibition aimed at the right thing — no Finance fact may ride the outbox row — but it was
stated against the wrong axis (the columns written rather than the payload they carry). Round 7 keeps
every prohibition on Finance business facts and payload columns, and adds only the seam's own required
insert metadata, which carries no Finance meaning and is already the shape the existing
`RecurringOutboxRepository` seam writes. §0e's row and closing paragraph are corrected **in place** to
state the same split; the wider prohibitions on business facts and payload columns throughout §0a–§0e are
unchanged.

## 0g. Implementation-candidate review correction round 2 — findings and corrections

The independent review of the **implementation candidate** `86d57606cabcddba15d076edfe14fb4e7257e60f`
(tree `6010181bfbf66e01fa49154c9ba266d1dd4888c4`) returned **FAIL — CORRECTION REQUIRED** with six
blocking findings. All six are corrected on descendants of that commit (never rewritten, never amended,
never rebased, never force-pushed), each in the section it governs; the sections below are the operative
contract, and where an earlier round's prose is narrower than this round's, this round's sections govern.

| Finding | Correction |
| --- | --- |
| U-C8-BLOCK-001 — the command tables' `NOT NULL` `result_state` was never written | Every `finance_*_commands` insert now supplies its declared state. §15.3/§15.6/§15.8 add the one declared success state per mutating operation (`FinanceRule::COMMAND_SUCCESS_STATES`), the one declared refusal state `refused`, and the declared `failed` run state (`COMMAND_RESULT_STATES`): snapshot `capture`/correction `correct_snapshot`/payability `evaluate`/`override`/statement `draft`/exception `resolve_exception` record `recorded`, a reconciliation `run` records `completed` (or `failed` for a run that cannot hydrate its scope), rate `close` records `closed`, and the three conditional moves record `issued`/`superseded`/`withdrawn`. `FinanceSupport::commitRefusal()` now writes the refused row as `result_state = 'refused'` with a `NULL` typed result and the exact `reason_code` for every command table, so the §15.8 refusal-evidence row can no longer keep the attempt's success state. The runtime, statement, reconciliation and failure suites now prove both paths — a declared state on every recorded command row of all six tables, and a refused row with its `NULL` typed result — for `capture`, `evaluate`, `draft`, `run` and refusals alike. |
| U-C8-BLOCK-002 — a successor rate claimed the live slot before its predecessor released it | `TeacherRateService::record()` now closes and supersedes the predecessor **before** the successor claims the scope's live slot: the closure and the status move are each a conditional statement whose affected-row count must be `1`, both run in the command's single transaction, and the successor is inserted afterwards. The declared `UNIQUE teacher_scope_slot` therefore admits exactly one live row per scope and a second rate in a scope closes and supersedes the first instead of colliding with it. §7.2 states the order. The same lifecycle now also refuses nothing it should accept: withdrawing a rate a successor has already closed rewrites no interval, so the interval-free proof runs only when this command is about to write the closure (§7.2 rule 1). |
| U-C8-BLOCK-003 — an appended chain row pre-claimed the one applicable slot | Both append-only chains now use the declared **two-statement protocol** of §15.4: the successor is appended with `applicable_slot = NULL`, the §15.4 supersession statement releases the predecessor (affected-row count `1`), and a second conditional statement claims the successor's slot (`SET applicable_slot = 1 WHERE id = ? AND applicable_slot IS NULL AND superseded_by_<child>_id IS NULL`, affected-row count `1`). `UNIQUE lesson_applicable` / `UNIQUE snapshot_applicable` therefore admit exactly one applicable row per Lesson and per snapshot, and a re-evaluation, an override or a second correction appends instead of colliding. §9.2, §9.3, §12.2 and §15.4 state the protocol; the runtime and statement suites prove it. |
| U-C8-BLOCK-004 — `record()` consumed the `supersede()` command's only transition | `FinancePolicyService::record()` now inserts its version and performs **no** status move: the version it writes (`policy_id`/`result_policy_id` both name that row) is recorded, and the predecessor's conditional `active → superseded` transition belongs to the separate, audited `supersede()` command, which keeps its own command row, its own audit evidence and its own affected-row count (§6.2). `record()`'s own command row is therefore no longer the only place a status move is evidenced, and `supersede()` can succeed exactly once per row. The runtime suite proves the two-command lifecycle, including the refusal of a second supersession. |
| U-C8-BLOCK-005 — issuance revalidated the snapshot but never the stored line | §10.5 rule 6 is now implemented line by line: the issuance gate compares **every stored line** with the Lesson's *current* effective snapshot and payability evaluation — the snapshot id, the applicable correction id, the payability evaluation id, the disposition, the basis code, the rate row and version, the compensation basis, the currency, and the exact recomputed amount (`payable ⇒ the effective snapshot's amount`, otherwise `0`). A draft a correction, an override or a re-derivation has out-covered is refused `statement_derivation_mismatch`, its totals are left exactly as recorded, and the operator withdraws and re-drafts it under §10.6. §15.5 states the same. |
| U-C8-BLOCK-006 — the reconciliation `run()` closure never captured its payload | `FinanceReconciliationService::run()` now captures `$payload` in its attempt closure, so a replayed run converges through the §15.3 digest invariant instead of raising an undefined variable. The reconciliation suite adds the required identical replay (one run, one command row, no second run) and the materially different replay refused `command_replay_conflict`. |

**Two further corrections this round, declared here rather than left implicit.** (a) *The policy verifier
refused a live installation.* §13.4's seed assertions were implemented as "no `finance_policies` row for
`FINANCE_STATEMENT_TIMEZONE`" and "at most four `finance_policies` rows in total", which is the *fresh*
state §18 asserts but not a state a live installation can keep: `record()` is the declared way the
statement timezone policy is set (§10.5) and every successor version adds a row, so the verifier — which
runs on every current-schema verification, i.e. on every request through `Migrator::maybe_upgrade()` —
would have failed closed as soon as the operator performed a declared operation. The verifier now asserts
the **seed shape** instead: the only migration-authored (`recorded_by = 0`) rows are the three declared
seed keys' version 1, and no migration-authored row names the statement timezone key. Recorded versions
(authored by the acting administrator) are ordinary recorded history and are never counted as seeds.
(b) *The rate-withdrawal guard judged time that is not written.* Withdrawing an already-closed rate wrote
no interval, yet the interval-free proof still measured the withdrawal instant against a later
successor's interval and refused the retraction of a superseded row. The proof now runs exactly where a
closure write happens (§7.2, U-C8-BLOCK-002's row).

The concurrency fixture's `concurrent_policy_record` mode also raced two versions at an instant its own
seeded predecessor already claimed, so neither contender could succeed and the mode's declared "one
succeeds, the loser refuses `finance_policy_timeline_overlap`" outcome was unreachable; its competing
instant is now admissible for the winner while the two refusal modes keep recording into claimed time.
One syntax error in `tests/phase-2a2u-corruption-runtime.php` (a closure `use` clause carrying a default
value) and one malformed `str_contains()` needle in `tests/phase-2a2u-contract.php` are corrected with it.

## 0h. Implementation-candidate review correction round 3 — finding and correction

The independent review of the **implementation candidate** `97a572937e07c021bf9c0fc9c65da23cdae92e08`
(tree `370eda98f7aa84182c7d06c91cbca7caf79637c2`) returned **FAIL — CORRECTION REQUIRED** with one
blocking finding. It is corrected on a descendant of that commit (never rewritten, never amended, never
rebased, never force-pushed), and §15.3 — which already required this — is the operative section.

| Finding | Correction |
| --- | --- |
| U-C9-BLOCK-001 — `replay()` returned a recorded result id without re-loading or validating the authoritative result row | §15.3 permits an idempotent replay **only after the authoritative aggregate and the recorded result row are re-verified**, and every command family now does exactly that. Two declarations make it uniform and enforceable: `FinanceRule::commandOutcomeStates()` names the *one* declared non-refusal outcome state of each operation (plus `failed` for a reconciliation run, and never `refused`), and `FinanceSupport` gains the three shared helpers `assertReplayState()` (a `refused` row converges on its refusal; any other undeclared state fails closed `command_replay_conflict`), `replayResultRow()` (the typed result is re-loaded under the held root with its own named-index locking read, must exist, and must still carry the command's own selectors or the replay fails closed `command_replay_conflict`), and `assertReplayPayload()` (the recorded result must still reproduce the exact command payload). Each service's `replay()` then re-proves its own section's derivation: the policy version row's key/version/vocabulary and recorded payload (`record`), the §7 rate integrity proof and the exact recorded closure/retraction, the §8.2 snapshot digest with its rate row, version and interval coverage, the §9.1 evaluation derivation digest bound to its own snapshot (and, for `override`, the override row that names it), the §12.2 correction derivation digest with its corrected rate and prior-snapshot digest, the §10.3 statement totals/line-set/derivation proof and §10.1 timezone triple plus the per-operation recorded outcome (`issue` evidence, `withdraw` state, `supersede` predecessor move), and the §11.2 run/finding proof with the recomputed findings digest (and, for `resolve_exception`, the resolution evidence). A deleted, corrupted or mismatched result therefore fails closed and preserves the original command record. Coverage is added in two places: `tests/phase-2a2u-replay-unit.php` (a pure, WordPress-free and database-free unit proof of the shared helpers, executed in the implementation environment) and one corruption-replay probe per command family in `tests/phase-2a2u-corruption-runtime.php`, each proving the fail-closed replay and the converging replay after exact restoration — including the absent-result case, which deletes the recorded policy version row and restores it exactly. `tests/phase-2a2u-contract.php` now scans every service for the re-load and the owning re-proof, so the correction cannot silently regress. |

## 0i. Implementation-candidate review correction round 4 — findings and corrections

The independent review of the **implementation candidate** `5e0daf221918634a047c83e9b5034b9f833ea7d9`
(tree `0968a4f6b96ea828848354b09a473c2f3001cac4`) returned **FAIL — CORRECTION REQUIRED** with two
blocking findings, both on §15.3. They are corrected on a descendant of that commit (never rewritten,
never amended, never rebased, never force-pushed), and §15.3 — which already required the re-verification
and which this round makes exact about the *typed result shape* — is the operative section.

| Finding | Correction |
| --- | --- |
| U-C10-BLOCK-001 — the correction replay never reconstituted and checked the recorded command payload, so a corrupted `result_correction_id` naming a *different* valid correction of the same Lesson, snapshot and reason converged on the substituted row | The correction command facts are now canonical and built in one place (`FinanceCorrectionService::correctionFacts()`, fed on the write path by `canonicalInt()`/`canonicalCurrency()`), and the replay reconstitutes exactly those facts from the **re-loaded correction row** and proves them against the recorded `command_payload_digest` with `FinanceSupport::assertReplayPayload()`, so a correction whose corrected rate row, version, corrected amount or currency differs is refused `command_replay_conflict` even though its own recorded derivation digest is self-consistent. The replay additionally cross-links the command's typed `result_snapshot_id` and its `correction_id` selector to the snapshot and correction row it re-loaded. A corruption probe that substitutes a second, valid correction of one snapshot is added, and the pure replay unit suite proves the payload proof's fail-closed answer. |
| U-C10-BLOCK-002 — override replay verified the override reached through `evaluation->override_id` but returned the unverified `command.result_override_id`, and the same unverified-secondary-result pattern existed for the snapshot `capture`'s `result_correction_id` and the reconciliation `run`'s `result_exception_id` / `resolve_exception`'s `result_run_id` | §15.3 now **declares the exact typed result fields of every operation** (`FinanceRule::COMMAND_RESULT_COLUMNS` and `FinanceRule::COMMAND_OPERATION_RESULTS`) and re-proves them on every replay through the new shared `FinanceSupport::assertReplayResultShape()`: every required typed result of the operation must be present and every other typed result column of that same table must be `NULL`. `LessonPayabilityService::replay()` returns the **verified** `override_id` (the override row the re-loaded evaluation carries) and additionally requires the command's `result_override_id` and its `override_id` selector to name that row and its `evaluation_id` selector to name the override's own prior evaluation; `LessonFinanceSnapshotService::replay()` returns no secondary result and requires a `capture`'s selector and typed result to be the one snapshot; `FinanceReconciliationService::replay()` returns no secondary result and cross-links each operation's selector to the row it typed. Six corruption probes that substitute a real, valid secondary typed result (including the substituted correction id the review named) prove the fail-closed answer and the converged answer after exact restoration. |

**Coverage added with the correction.** `tests/phase-2a2u-replay-unit.php` (executed, WordPress-free and
database-free) now also proves the declared typed result columns and per-operation shapes, and proves that
a command row carrying a substituted secondary typed result, an absent required typed result, or a
secondary result its operation never records fails closed `command_replay_conflict`; it additionally
proves the correction command facts' own canonical reconstitution — that the facts rebuilt from a recorded
correction row reproduce the recorded `command_payload_digest` exactly, while a substituted corrected
amount or corrected rate row does not.
`tests/phase-2a2u-corruption-runtime.php` gains one substitution probe per command family (correction,
capture, derivation, override, reconciliation run and exception resolution), each asserting the
fail-closed replay and the converged replay after exact restoration, plus the report-shape assertions that
a converged override replay reports the override its own evaluation carries and that a converged capture,
run and resolution replay report no secondary result. `tests/phase-2a2u-contract.php` scans the two
declarations, the shared helper, every family's shape re-proof, the absence of any replay that returns a
recorded typed result without re-loading it, and the presence of every substitution probe, so the
correction cannot silently regress.

## 0j. Implementation-candidate review correction round 5 — finding and correction

The independent review of the **implementation candidate** `f5138509a0a256df335015b1ef1d6f42dfd06b96`
(tree `85db4f8afc99159e4d2f3c8e294d1247d820a899`) returned **FAIL — CORRECTION REQUIRED** with one
blocking finding on §15.3. It is corrected on a descendant of that commit (never rewritten, never
amended, never rebased, never force-pushed), and §15.3 — which already required that a replayed operation
re-produce the recorded `command_payload_digest` exactly — is the operative section.

| Finding | Correction |
| --- | --- |
| U-C11-BLOCK-001 — `corrected_rate_amount_minor` is a material correction fact but was omitted from `FinanceCorrectionService::correctionFacts()` and therefore from both the recorded `command_payload_digest` and the replay reconstitution, so a second, self-consistent correction of the same Lesson, snapshot, corrected rate row/version, corrected derived amount, currency and reason that differed only in its corrected rate amount could replace `result_correction_id` and replay successfully | The correction's canonical command facts now carry the **complete material correction fact set**: `correctionFacts()` takes the corrected rate amount as its own canonically validated fact (`self::canonicalInt($input['corrected_rate_amount_minor']??null)` on the write path, `(int)$correction->corrected_rate_amount_minor` on the replay path) beside the corrected derived amount, so a correction whose corrected rate amount alone differs moves the payload and is refused `command_replay_conflict` instead of converging on the substituted row. §12.2's completeness rule and §15.3's payload clause now name the restated rate amount explicitly, so the payload fact set is declared rather than implied. Coverage: `tests/phase-2a2u-replay-unit.php` adds a payload probe in which only the corrected rate amount differs, `tests/phase-2a2u-corruption-runtime.php` adds the matching runtime corruption probe (corrupt the recorded `corrected_rate_amount_minor`, prove the identical replay fails closed `command_replay_conflict`, restore the row exactly, prove the identical replay converges on the recorded correction id), and `tests/phase-2a2u-contract.php` scans for both so the omission cannot silently regress |

**One further defect in the same probe family, found and corrected with this round.** The corruption
suite's original correction probe corrupted the recorded `corrected_derived_amount_minor` and asserted
`snapshot_derivation_mismatch`, but the correction's corrected derived amount is *also* a payload fact, and
§15.3's payload re-proof runs **before** the family's own derivation-digest re-proof
(`FinanceSupport::assertReplayPayload()` precedes the `FinanceRule::CORRECTION_DIGEST_FIELDS` comparison in
`FinanceCorrectionService::replay()`), so that corruption in fact fails closed `command_replay_conflict`.
The probe is corrected to assert the code the code produces, and the family's own derivation-digest
re-proof is kept covered by a third correction probe that moves a fact the payload does not carry (the
recorded `prior_snapshot_digest`) and asserts `snapshot_derivation_mismatch`.

**Coverage added with the correction.** `tests/phase-2a2u-replay-unit.php` (**executed and passing**,
WordPress-free and database-free) proves the corrected fact set through reflection: `correctionFacts()`
carries the corrected rate amount and the corrected derived amount as separate facts, the facts rebuilt
from a recorded correction row reproduce the recorded `command_payload_digest` exactly, and a correction
whose corrected rate amount alone differs (or whose corrected derived amount, corrected rate row or
version differs) is refused `command_replay_conflict`. `tests/phase-2a2u-corruption-runtime.php` (written,
**not executed**) now carries three correction replay probes — corrected rate amount, corrected derived
amount and prior-snapshot digest — alongside the substituted-secondary-result probes, so the correction
family proves both its payload proof and its own derivation-digest proof. `tests/phase-2a2u-contract.php`
(**executed and passing**) scans for both new probes.

## 1. Verified authoritative state

| Fact | Verified value (this checkout) |
| --- | --- |
| Repository root | `git rev-parse --show-toplevel` = this workspace |
| Branch / remote | `main...origin/main`; working tree clean (`git status --short` empty) |
| HEAD | `b36561dc6bb6e87fd142a28ae67fbc4f2fdc9279`, tree `51c887ee62dc10cefbfb7d4763b7be63ecb329a2` |
| Platform version | `DZN_PLATFORM_VERSION` = `0.1.0` |
| Schema | `DZN_PLATFORM_SCHEMA_VERSION` = `29`; migration ledger 001–029; latest `029_payment_event_decision_claim_authority` |
| Build | `phase2a2t-payment-execution-seam-stripe-adapter-20260925.14` |
| Capability markers today | `dzn_platform_capability_version_2a2o`…`_2a2r1`, `_2a2r2`, `_2a2v`, `_2a2t` (each `2a2o`…`2a2t`), plus the base `dzn_platform_capability_version` = `2a2n` |
| Merged authority | Phases A–Q merged and closed at Schema 24; Phase 2A.2-R1 merged and closed at Schema 25 |
| Candidate authority in this tree | Phase 2A.2-R2 (Schema 26), Phase 2A.2-V (Schema 27) and Phase 2A.2-T (Schemas 28/29) are candidates awaiting independent review; none is deployed |
| Upstream canonical facts available to Finance | Phase M canonical Lessons, Phase N schedule versions, Phase O delivery outcomes and academy obligations, Phase P attendance cases/decisions/evidence, Phase L Terms and Enrolments, Phase J Teacher Assignment |
| Upstream commercial/payment facts available to Finance | R1 offers, offers' obligations, purchases, payment evidence/facts, obligation settlements, entitlements, Term funding plans; R2 recurring enrolments, renewal cycles, collection intents, recovery cases, refund-review cases; T execution commands/attempts/results, provider events, decision claims |
| Finance storage today | **none** — no `finance_*` table, class, capability, constant, option or route exists anywhere under `src/` |
| Teacher-payment storage today | **none in Platform** — the live teacher-payment report and the legacy `payable`/`payment_note` attendance columns live in the Amelia-era operational plugin, never in Platform |
| Outbound capability today | `wp_remote_*`/`curl_*` appear in exactly one file, `src/Integrations/Payment/Stripe/StripePaymentAdapter.php`; **no** scheduling function (`wp_schedule_event`, `wp_schedule_single_event`, `wp_cron`, `'cron'`) exists anywhere under `src/` |
| Runtime availability | **PHP is absent in this environment** (`php: command not found`); no disposable WordPress + MariaDB runtime was available to this contract-authoring session |
| Surface size | 40 documents under `docs/`; 228 PHP files under `src/`; 205 entries under `tests/` (186 PHP files, 35 of them contract suites) |

Three consequences are structural, not incidental:

1. Phase U introduces the **first Platform authority whose subject is money owed to a Teacher**. It must
   therefore be the only place where payability, a teacher rate, a per-Lesson rate/currency snapshot, a
   compensation statement or a financial correction is decided — and it must not become a general
   ledger, a payout executor or an accounting system of record (§2, §22).
2. Phase U **adds no column to any existing table**. Lesson, schedule, delivery, attendance, Term,
   Enrolment, `commercial_*`, R2 and T storage all stay exactly as their own phase verifiers left them;
   Finance attaches to them by reference only (§13).
3. Phase U **consumes** upstream facts but never substitutes for their authority: a Finance row can
   never create, complete, cancel, reschedule, archive or re-price a Lesson, and it can never move a
   student's commercial position (U-D3, U-D10).

## 2. What this phase owns

### In scope

1. **Finance policy authority** — a versioned, effective-dated, Class-B policy registry for the
   finance decisions the academy may configure, with an explicit recorded state for an unset policy
   (§6).
2. **Effective-dated teacher rate authority** — teacher compensation rates with explicit scope
   (`teacher` or `teacher_course`), explicit currency, half-open effective intervals, no mutable
   "current rate" and no retroactive rewrite of an interval that already exists (§7).
3. **Per-Lesson rate/currency snapshot authority** — exactly one immutable snapshot per canonical
   Lesson, derived from the authoritative rate in force at the Lesson's locked snapshot instant, with
   append-only audited corrections that never mutate the snapshot (§8).
4. **Lesson payability authority** — a versioned derivation of `payable`/`non_payable`/`pending` from
   canonical Lesson, schedule, delivery and academy-obligation facts, plus an audited
   administrator override, with no mutable payability counter and no silent re-derivation (§9).
5. **Teacher compensation statement authority** — draft/issued statements per Teacher and period, with
   immutable lines, recorded totals, a fail-closed issuance gate and append-only supersession (§10).
6. **Reconciliation read models** — read-only cross-checks between Finance facts, their canonical
   upstream facts and the provider-neutral commercial/payment facts, each difference carrying an exact
   bounded finding code and an exact integer comparison (§11).
7. **Audited corrections** — the only way an already-recorded Finance fact may change meaning: an
   append-only correction that names the exact prior row and never mutates it (§12).
8. **Schema 030** — additive storage for the above with its fail-closed verifier (§13).

### Out of scope (this phase creates none of them)

- any general ledger, double-entry journal, chart of accounts, trial balance, accrual or deferral
  schedule, revenue recognition rule, tax/VAT rule or statutory report;
- any invoice to a Student, any dunning, any payout, any bank/IBAN/card detail, any payment
  instruction, any remittance advice, any provider dashboard change and any accounting-system
  integration;
- any student-side pricing decision, discount, promotion, adjustment, refund or settlement (R1/R2/T
  own those facts; Finance only reads them);
- any Lesson, Term, Enrolment, schedule, delivery outcome, attendance decision or academy obligation
  creation or mutation (Phases L/M/N/O/P own those);
- any provider call, credential, webhook, adapter or transport (Phases T/V own those);
- notification delivery, templates, attempts or transport (Phase S);
- portals, self-service statement surfaces, public routes, Theme/NIU work, deployment, production
  access, production cutover, Amelia writes or Amelia removal.

### Authority-preservation rule

Phase U is an **authority layer above the canonical facts**, never a second source of them. Every
Finance fact is derived from, and re-verifiable against, a canonical row that Phase U does not own:
a `lessons` row, a `lesson_schedule_versions` row, a `canonical_lesson_delivery_outcomes` row, a
`canonical_academy_obligations` row, or a `commercial_*`/`recurring_*`/`payment_*` row. Where a
canonical row is missing, ambiguous, contradictory or corrupt, Finance **fails closed and records the
blocker** — it never fills the gap with a default, an approximation, a legacy flag or a provider value.

## 3. Preserved invariants (must not regress)

Phase U is additive and must not weaken a single existing invariant. In particular:

- **Money is exact.** Every amount is an integer number of minor units with an explicit ISO-4217
  currency. No float, no rounding, no tolerance, no currency conversion and no per-item FX exist
  anywhere in this phase (U-D1).
- **Student pricing and teacher compensation stay separate concepts.** A Finance rate, snapshot,
  statement or correction can never be read as a price, a discount, an amount due, an obligation
  amount or a settlement (PRODUCT-DECISIONS §12).
- **The Platform-issued offer remains the only whole-Term price snapshot** (R1-D4) and Phase U adds no
  price, discount or adjustment authority.
- **Settlement is distinct from academic effectiveness** (R1-D6) and from delivery (Phase O).
- **Funded allowance is derived from accepted obligations; no mutable funded-session counter exists**
  (R1-D7). Finance reads funding facts and writes none.
- **Capacity succession is unchanged** (R1-D10): the Phase-Q hold → R1 claim → Phase-N schedule chain is
  never touched by a Finance command, and Finance holds no capacity lock.
- **Unset policy stays unset** (R1-D11 / R2 §4). A Finance policy that this phase does not choose stays
  recorded as unset, and the dependent action refuses (§6, §21).
- **Provider neutrality of commercial storage is preserved** (R1-D12): Phase U adds no column to any
  `commercial_*`, `recurring_*`, `payment_*` or `canonical_*` table and stores no provider-specific
  column of its own.
- **Phase O's separation of delivery, attendance, remedy and archive is preserved**: payability is a
  Finance decision derived from those facts, never a rewrite of them; an O-D8 reconciliation keeps the
  immutable completion event and produces non-delivery meaning, and Finance reacts to that meaning
  rather than re-deciding it.
- **An archive is reversible metadata, never a financial erasure** (Phase 0 archive decision): archiving
  a Lesson may exclude it from *new* drafting, but it never silently removes an already-recorded
  snapshot, evaluation or statement line.
- **A provider failure cannot undo a committed business fact** and no provider value becomes authority
  merely because it was emitted (Phases O/P/T).
- **The legacy teacher-payment report is not migrated by this contract.** Legacy `payable`/`payment_note`
  flags remain readable in the operational plugin until the Phase 9 cutover is separately authorised,
  and they never become Platform authority (§16).

## 4. Locked Phase U decisions (U-D1 … U-D19)

| Decision | Locked meaning |
| --- | --- |
| **U-D1 exact money** | Every amount is an integer number of minor units (`bigint unsigned`) with an explicit ISO-4217 `char(3)` currency. No float, no rounding, no tolerance, no conversion, no per-item FX and no implicit currency substitution exist in this phase. A derivation whose result is not an exact integer fails closed with `finance_amount_not_exact` rather than rounding. |
| **U-D2 derivation, never assertion** | Payability, a rate, a snapshot amount and a statement total are always **derived** from canonical Platform facts and re-verifiable from them. No legacy flag, provider payload, adapter result, browser value, administrator-supplied amount or exported spreadsheet can establish any of them. |
| **U-D3 no upstream mutation** | No Finance command may create, update, delete, complete, cancel, reschedule, archive or re-price a Lesson, schedule version, delivery outcome, academy obligation, attendance case/decision/evidence, Term, Enrolment, Teacher Assignment, `commercial_*`, `recurring_*`, `payment_*` or `canonical_*` row. Finance storage is written by Finance only. The only tables a Finance code path may write outside its own declared tables are the two declared platform infrastructure seams of §15.7 — `platform_audit_events` (digest-only audit evidence) and `platform_outbox` (the §17 notification intents) — each written insert-only, inside the transaction of the Finance row it evidences; an audit row carries identifiers and digests only, and an outbox row carries the seam's own `aggregate_type`/`aggregate_id`/`event_type` identity columns plus only the seam's mandatory insert metadata — its derived `idempotency_key`, the initial `pending` state and the explicit `NULL` lease and legacy-identity columns (§15.7) — with no Finance business fact on the row, because the permitted Finance facts are hydrated by Phase S from the declared aggregate rather than carried on the row (U-C4-BLOCK-003, U-C6-BLOCK-001, U-C7-BLOCK-001). No other non-Finance table is written by this phase. |
| **U-D4 locked snapshot instant** | A Lesson's snapshot instant is the **recorded occurrence start** of the occurrence the Lesson's effective facts bind: the effective delivery outcome's `occurrence_starts_at_utc` when an outcome exists, otherwise the applicable Phase-N schedule version's `starts_at_utc`. It is recorded verbatim on the snapshot, is never recomputed after the fact, and is never the statement date, the ingestion time, the completion time or "now". The rate and the policy versions that apply to the snapshot are the ones whose recorded interval covers that instant — resolved against history, never against the row that is live at capture time (§6.1, §7.3) — so a capture made after a successor was recorded still prices the occurrence under the authority that was actually in force. |
| **U-D5 one snapshot per Lesson** | Exactly one applicable `finance_lesson_snapshots` row may exist per canonical Lesson (`UNIQUE lesson_id`). Capture is idempotent, deterministic and re-derivable; a second capture of an unchanged Lesson converges on the existing row, and a capture that cannot resolve an effective rate or an applicable occurrence creates **no** row and records the exact blocker. |
| **U-D6 no retroactive rate rewrite** | A rate row whose effective interval already contains a committed snapshot can never be edited, withdrawn, re-scoped or re-versioned. Changing a rate means recording a **new** version with a later effective start; changing history means an explicit audited correction (U-D15). |
| **U-D7 history is not recalculated** | Changing a current rate, a current policy or a current upstream fact never recalculates a historical snapshot, an already-recorded payability evaluation, an existing statement line or an issued statement total (PRODUCT-DECISIONS §12). The difference is **reported** and requires an explicit command. |
| **U-D8 versioned payability derivation** | Payability is produced by a single named derivation version (`FinanceRule::PAYABILITY_DERIVATION_VERSION`) whose inputs are canonical facts only: the Lesson's kind and lifecycle, its applicable schedule/occurrence anchors, its effective delivery outcome and its academy obligation. The derivation is deterministic, total and replay-checked. |
| **U-D9 `pending` blocks, never resolves itself** | A `pending` payability disposition (for example an unresolved Phase-O `review_required` outcome, or a Lesson that is not yet finalised) can never be silently treated as payable or non-payable. It blocks statement issuance until Phase O resolves the outcome or an audited override resolves it (U-D10). |
| **U-D10 overrides are audited and additive** | An administrator override appends a **new** payability evaluation with basis `administrator_override`, names the exact derivation it replaced, and records actor, reason code, evidence channel, keyed evidence digest and observed time. It never edits the derivation row, never changes an upstream fact and never marks the difference as resolved. |
| **U-D11 immutable draft lines** | A statement's lines are immutable from the moment they are written. A draft whose inputs changed is **withdrawn** (state `withdrawn`, reason recorded) and re-drafted as a new version; lines are never edited, deleted or re-sequenced. |
| **U-D12 issued statements are immutable** | An `issued` statement may move to `superseded` exactly once, through one conditional statement that records `superseded_at` and `superseded_by_statement_id`. Its teacher, period, currency, lines, totals, rule versions and issuance evidence can never change. A correction after issue is a **new statement version** that references the superseded statement. |
| **U-D13 one currency per statement** | A statement is single-currency. Its currency is derived from its lines; a Teacher period whose payable lines carry two currencies is a blocking exception (`currency_mismatch_for_statement`) and cannot be issued. There is no conversion and no split-currency statement in this phase. |
| **U-D14 reconciliation is read-only** | A reconciliation run appends a run record and findings. It changes no Finance fact, resolves no exception by itself, repairs nothing, and compares exact integers with no tolerance. Every difference carries one bounded finding code and one exact pair of values. |
| **U-D15 corrections name their target** | Every correction is append-only, carries its own sequence, names the exact prior row it corrects (and that row's digest), and never mutates it. A correction that cannot prove it names a live, re-verified target fails closed. |
| **U-D16 fail closed and visible** | Every refusal is a durable reason-coded command result, a `finance_exceptions` row, or both. Unknown state, missing parent, corrupt aggregate, ambiguous rate, unset policy, unrecorded timezone and conflicting replay are recorded — never silently defaulted, repaired, retried into a different answer, or reported as success. |
| **U-D17 no accounting authority outside Platform, and no general ledger inside it** | The Platform is the only authority for payability, teacher rates, per-Lesson snapshots, statements and finance corrections. No Stripe/Amelia/legacy/plugin/external-accounting surface may compute, store or override them. Equally, this phase creates **no** ledger, journal, invoice, tax, payout or accounting system of record: it records what the academy owes a Teacher, and stops there. |
| **U-D18 least privilege, digest-only evidence** | Every Finance write is capability-gated administrator authority. No Teacher role and no Student role holds a Finance capability. Commands persist keyed digests only, never raw keys, raw request bodies or raw provider references. Every read hydrates and validates its aggregate through the owning validator and fails closed on any malformed shape. |
| **U-D19 no coverage change for a recorded fact** | A policy version may never be recorded into time an already-recorded dependent Finance fact has consumed. Recording refuses any `effective_from` that is not strictly later than the key's recorded consumption maximum — the greatest policy-relevant instant (a snapshot's locked occurrence instant, the snapshot instant a governed evaluation binds, a statement's recorded drafting instant) of a dependent Finance fact of that key — so no later recording can change what a committed snapshot, evaluation or statement resolves to at its own instant; a version recorded where nothing has been consumed stays admissible, including an instant in the past, so historical resolution (U-D4) is unchanged (§6.3). Every command that resolves a policy version and records the resolution on a Finance row takes the global policy root before the Teacher root, and every mutating policy command takes that root exclusively, so the guard's read and the version's insert are totally ordered against every consumer. A retraction is the one declared way authority is withdrawn: it is audited and reasoned, it rewrites no recorded fact, and its consequence for the facts that consumed it is the declared one of §6.3 (U-C5-BLOCK-001). |

## 5. Module layout and locked vocabulary

### 5.1 Layout

New surfaces, all inside existing modules — Phase U introduces **no new module** and **no provider
surface**:

| Path | Contents |
| --- | --- |
| `src/Core/Application/Finance/` | `FinanceRule` (locked constants and vocabularies), `FinancePolicyService`, `TeacherRateService`, `LessonFinanceSnapshotService`, `LessonPayabilityService`, `TeacherStatementService`, `FinanceCorrectionService`, `FinanceReconciliationService` |
| `src/Core/Application/Finance/Integrity/` | `FinanceRateIntegrity`, `FinanceSnapshotIntegrity`, `FinancePayabilityIntegrity`, `FinanceStatementIntegrity`, `FinanceReconciliationIntegrity` — pure, non-mutating, repository-hydrated validators |
| `src/Core/Infrastructure/Repository/Finance*Repository.php` | One repository per aggregate, followed by the established `begin()`/`commit()`/`rollback()` wrapper, named-index duplicate arbitration and insert-only methods for append-only tables |
| `src/Core/Application/Finance/Read/` | `TeacherRateReadService`, `LessonFinanceReadService`, `TeacherStatementReadService`, `FinanceReconciliationReadService` — PII-minimised, digest-only |
| `src/Admin/Controller/Finance*Controller.php` | Capability-protected administrator screens under the existing Platform menu; **no public route, no REST route and no front-end surface** |

There is deliberately **no** `src/Integrations/Finance/`. Finance has no provider, no transport and no
credential; the only provider-derived material it ever sees is the provider-neutral,
already-validated R1/R2/T evidence it reads.

### 5.2 Locked vocabularies

| Vocabulary | Locked members |
| --- | --- |
| Payability disposition (`FinanceRule::DISPOSITIONS`) | `payable`, `non_payable`, `pending` |
| Payability basis (`FinanceRule::BASIS_CODES`) | `delivered_occurrence`, `student_no_show`, `interruption`, `teacher_non_delivery`, `academy_obligation`, `delivery_review_required`, `occurrence_not_attempted`, `lesson_not_finalised`, `introductory_policy_non_payable`, `administrator_override` |
| Teacher rate scope (`FinanceRule::RATE_SCOPE_KINDS`) | `teacher`, `teacher_course` |
| Teacher rate status (`FinanceRule::RATE_STATES`) | `active`, `superseded`, `withdrawn` — the member records which row is the *live* row of a scope (`active_slot = 1`); it never gates historical resolution (§7.3) |
| Compensation basis (`FinanceRule::COMPENSATION_BASES`) | `per_session` — the only member in this phase; a second basis requires a new contract (`per_hour` is *recorded as deferred*, never partially implemented) |
| Statement state (`FinanceRule::STATEMENT_STATES`) | `draft`, `issued`, `superseded`, `withdrawn` |
| Statement event type (`FinanceRule::STATEMENT_EVENT_TYPES`) | `drafted`, `issued`, `superseded`, `withdrawn` |
| Correction kind (`FinanceRule::CORRECTION_KINDS`) | `snapshot_correction`, `payability_override`, `statement_supersession` |
| Reconciliation finding code (`FinanceRule::FINDING_CODES`) | the 21-member declared view of `FinanceRule::REASON_CODES` written to `finance_reconciliation_findings.finding_code` — exact list in §5.2.1 |
| Finance exception reason (`FinanceRule::EXCEPTION_REASON_CODES`) | the 43-member declared view of `FinanceRule::REASON_CODES` written to `finance_exceptions.reason_code`, to every refused command result and to a chain/event `reason_code` that records a refusal — exact list in §5.2.1 |
| Structural constants (`FinanceRule`) | `PAYABILITY_DERIVATION_VERSION` (binds the U-D8 derivation), `SNAPSHOT_BOUNDARY = 'occurrence_start'` (U-D4), `MAX_STATEMENT_PERIOD_DAYS = 62`, `STATEMENT_CURRENCY_RULE = 'single_currency'`, `AMOUNT_EXACTNESS = 'exact_integer'`, `FINANCE_TIMEZONE_SOURCE = 'recorded_policy'`, `POLICY_ADMISSIBILITY_RULE = 'later_than_recorded_consumption'` (binds the U-D19 guard of §6.3) |

### 5.2.1 The single reason-code allowlist

`FinanceRule::REASON_CODES` is the **only** allowlist for every durable reason this phase can record: a
refused command result, a `finance_exceptions.reason_code`, a
`finance_reconciliation_findings.finding_code`, a `finance_reconciliation_runs.failure_reason_code`, and
the `reason_code` column of every chain, event, correction, version and seed row
(`finance_policies`, `finance_teacher_rates`, `finance_teacher_rate_events`,
`finance_snapshot_corrections`, `finance_payability_evaluations`, `finance_payability_overrides`,
`finance_statement_events`). Three sets are declared; every write uses a member of the set that owns its
row, and no code outside `REASON_CODES` is ever written anywhere.

**Durable exception and refusal reasons — `FinanceRule::EXCEPTION_REASON_CODES`** (43 members), written
to `finance_exceptions.reason_code`, to every refused command result, and to a chain/event `reason_code`
that records a refusal:
`finance_vocabulary_member_not_allowed`, `finance_parent_not_declared`, `finance_parent_not_live`,
`finance_policy_key_not_allowed`, `finance_policy_version_conflict`,
`finance_policy_effective_from_missing`, `finance_policy_value_type_invalid`,
`finance_policy_timeline_overlap`, `policy_effective_from_precedes_recorded_consumption`,
`finance_policy_unset`, `policy_unset_for_statement_period`, `rate_missing_for_lesson`,
`ambiguous_teacher_rate`, `teacher_rate_timeline_overlap`, `teacher_rate_timeline_gap`,
`teacher_rate_state_not_resolvable`, `rate_scope_violation`, `rate_referenced_by_snapshot`,
`rate_effective_from_precedes_snapshot`, `occurrence_anchor_missing`,
`finance_lesson_kind_not_allowed`, `finance_amount_not_exact`, `snapshot_lesson_not_finalised`,
`snapshot_missing_for_lesson`, `snapshot_derivation_mismatch`, `snapshot_correction_incomplete`,
`command_replay_conflict`, `payability_pending`, `payability_conflicts_with_delivery_fact`,
`payability_supersession_conflict`, `payability_override_target_invalid`, `statement_period_too_long`,
`statement_period_not_elapsed`, `statement_period_overlap`, `statement_state_transition_conflict`,
`statement_supersession_required`, `statement_totals_mismatch`, `statement_derivation_mismatch`,
`statement_timezone_representation_invalid`, `lesson_stated_twice`, `lesson_not_finalised_in_period`,
`currency_mismatch_for_statement`, `upstream_aggregate_invalid`.

**Reconciliation finding codes — `FinanceRule::FINDING_CODES`** (21 members, written to
`finance_reconciliation_findings.finding_code`): `lesson_missing_from_statement`, `lesson_stated_twice`,
`lesson_not_finalised_in_period`, `line_amount_differs_from_recomputation`, `statement_totals_mismatch`,
`statement_derivation_mismatch`, `snapshot_missing_for_lesson`, `snapshot_derivation_mismatch`,
`rate_missing_for_snapshot`, `teacher_rate_timeline_overlap`, `teacher_rate_timeline_gap`,
`payability_pending`, `payability_conflicts_with_delivery_fact`, `currency_mismatch_for_statement`,
`statement_period_overlap`, `lesson_archived_after_issue`, `delivery_outcome_changed_after_issue`,
`snapshot_corrected_after_issue`, `override_applied`, `legacy_flag_differs`,
`provider_evidence_unmatched`.

**Operator-supplied and migration reasons** (4 members, recorded on a version, correction, override or
event row as the administrator's or migration's stated reason, never as an exception):
`phase_u_declared_default`, `operator_recorded_error`, `operator_decision`,
`operator_evidence_correction`. Every other `reason_code`/`failure_reason_code` value a Finance row
records — a policy or rate version reason, a correction reason, an evaluation or override reason, a
statement event reason and a reconciliation run failure code — is a member of one of those two sets.

Rules that bind the allowlist:

- **Exhaustive and exclusive.** `FinanceRule::REASON_CODES` is exactly the union of the three sets
  above. Every reason a Finance service emits — a refusal, a blocker, an exception row, a finding row,
  a run failure code, a chain/event `reason_code`, the migration seed reason and every
  operator-supplied reason — is a member of the set that owns the row it is written to, and therefore
  of `REASON_CODES`. A member outside that set is refused `finance_vocabulary_member_not_allowed` and
  is never written to the database or to a command result.
- **One condition, one code.** A condition has exactly one spelling, used by every site that raises it:
  every rate-interval overlap raises `teacher_rate_timeline_overlap`, every rate-interval gap
  `teacher_rate_timeline_gap`, every command-replay conflict `command_replay_conflict`, a rate recorded
  into an instant a snapshot already consumed `rate_effective_from_precedes_snapshot`, a policy recorded
  into an instant a recorded dependent Finance fact already consumed
  `policy_effective_from_precedes_recorded_consumption`, and every refusal that requires a
  supersession of an issued statement `statement_supersession_required`. No alias, abbreviation or
  caller-invented reason exists anywhere in the phase.
- **Declared overlap.** Exactly twelve members are shared by both views, because the same condition can
  be both a durable blocker and a reconciliation difference: `lesson_stated_twice`,
  `lesson_not_finalised_in_period`, `statement_totals_mismatch`, `statement_derivation_mismatch`,
  `snapshot_missing_for_lesson`, `snapshot_derivation_mismatch`, `payability_pending`,
  `payability_conflicts_with_delivery_fact`, `currency_mismatch_for_statement`,
  `statement_period_overlap`, `teacher_rate_timeline_overlap`, `teacher_rate_timeline_gap`. No other
  member appears in both views.
- **Two unset codes, two distinct subjects.** `finance_policy_unset` is a *derivation-time* refusal: a
  policy key a snapshot or a payability evaluation needs resolves to unset at its locked instant.
  `policy_unset_for_statement_period` is the *statement-time* refusal of §10.5 #8: the recorded
  timezone triple was not recorded because `FINANCE_STATEMENT_TIMEZONE` resolved to unset at drafting
  time. Neither is a default, and neither ever substitutes a value.
- **Two recording guards, two registries, one shape.** The policy guard
  `policy_effective_from_precedes_recorded_consumption` (§6.3, U-D19) and the rate guard
  `rate_effective_from_precedes_snapshot` (§7.4, U-D6) are the same rule over the two effective-dated
  registries: neither registry may be recorded into an instant that an already-recorded dependent Finance
  fact has consumed. They are separate members because they name separate registries and separate
  consuming facts, and neither may be used for the other's condition.
- **No free text, no payload.** A human explanation lives in `note`, `safe_detail` or `summary` — never
  in a reason code — and no reason code carries an amount, a provider reference, a key or personal
  data (U-D18).
- **Deferred codes are not reserved slack.** A code exists only for a path this contract defines; a
  path that needs a new code is a contract change, not a configuration or migration change.

### 5.3 Payability derivation (U-D8, locked)

| Lesson fact (canonical) | `disposition` | `basis_code` | Blocks completion? | Blocks statement issue? |
| --- | --- | --- | --- | --- |
| **(1)** Academy obligation exists for the Lesson (any O-D4/O-D8/O-D9 route) | `non_payable` | `academy_obligation` | n/a | no |
| **(2)** Lesson not finalised (`draft`/`scheduled`) **and** a snapshot already exists for it | `pending` | `lesson_not_finalised` | n/a | **yes** |
| **(3)** Effective outcome `review_required` | `pending` | `delivery_review_required` | yes (Phase O) | **yes** |
| **(4)** Effective outcome `teacher_non_delivery` | `non_payable` | `teacher_non_delivery` | yes (Phase O) | no |
| **(5)** Lesson kind `introductory` and `INTRO_PAYABILITY_POLICY` = `non_payable` (recorded default) | `non_payable` | `introductory_policy_non_payable` | no | no |
| **(6)** Lesson `completed`, no effective delivery outcome (ordinary delivery) | `payable` | `delivered_occurrence` | n/a | no |
| **(7)** Effective outcome `delivered` | `payable` | `delivered_occurrence` | no | no |
| **(8)** Effective outcome `student_no_show` | policy `STUDENT_NO_SHOW_COMPENSATION_POLICY` (recorded default `payable`) | `student_no_show` | no | no |
| **(9)** Effective outcome `interruption` | policy `INTERRUPTION_COMPENSATION_POLICY` (recorded default `payable`) | `interruption` | no | no |
| **(10)** Lesson `cancelled` before the occurrence, no academy obligation | `non_payable` | `occurrence_not_attempted` | n/a | no |
| **(11)** Any of the above, overridden by an audited administrator command (U-D10) | the overridden disposition | `administrator_override` | no | only if it resolves a `pending` |

Rules that bind the table:

- The table is evaluated **in the numbered order** for the effective facts; the **first** matching row
  decides, and an audited override (row 11) is the only thing that can replace that verdict.
  `academy_obligation` therefore outranks every delivery-derived row, so a Lesson whose completion was
  reconciled to non-delivery (O-D8) is non-payable even though a historical completion exists; the
  introductory policy outranks the delivery-derived rows, so an introductory Lesson that was delivered
  normally is still non-payable under the recorded default; and an unresolved review is `pending`
  rather than payable.
- Row 2 is a **corruption guard, not a normal path**: under §8.2 a snapshot is only captured for a
  finalised Lesson, so it is reachable only when a Lesson's lifecycle moved backwards behind a
  commitment — which Phase M forbids — and it fails closed rather than pricing a plan.
- `introductory` is decided from the **canonical Lesson kind**, never from an Amelia service category
  id. Category `3` is evidence about the legacy system, not a Platform domain rule.
- Archive state is **not** an input to this table. Archive excludes a Lesson from new drafting; it never
  changes a recorded disposition (see §10.4).
- The mapping above is a Class-A structural invariant except for the two policy-governed rows
  (`student_no_show`, `interruption`) and the intro default, which are Class-B keys recorded in
  `finance_policies` (§6). No other row is configurable.
- Rows 5, 8 and 9 read their key at the **snapshot instant** through §6.1's resolution rule; a key that
  resolves to unset at that instant blocks the derivation with `finance_policy_unset` (never an implied
  default, never "the nearest version"), and the required version is recorded on the row that is
  produced.

### 5.4 Vocabulary drift prevention

- `FinanceRule` is the only allowlist in this phase. A caller can never supply a disposition, a basis
  code, a scope kind, a compensation basis, a statement state, a finding code or a reason code of its
  own choosing; an unknown member is refused with `finance_vocabulary_member_not_allowed`.
- The three reason-code sets of §5.2.1 are the only reason vocabulary in this phase. A `reason_code`,
  `finding_code` or `failure_reason_code` written by any Finance service is a member of the set that
  owns its row; an operator-supplied reason (a withdrawal, a correction, an override) is chosen from
  the declared operator set and is never free text; and a service or test that invents a literal is
  refused by the contract test of §18.
- The Phase-U contract test asserts every vocabulary above literally, asserts the absence of any second
  literal list anywhere under `src/`, and asserts that the derivation table is the single place where a
  disposition is decided. It additionally asserts the three reason-code sets literally, asserts that
  both views are subsets of `FinanceRule::REASON_CODES`, asserts the twelve declared shared members,
  and scans `src/Core/Application/Finance/**` to prove that every reason literal used there is a member
  of the set for the row it is written to.
- A member added to any vocabulary is a contract change, not a configuration change.

## 6. Finance policy authority

`finance_policies` is the versioned Class-B registry for the finance decisions the academy may set.
It mirrors the commercial registry (`dzn_commercial_policies`) exactly: immutable-in-value rows, one row
per key and version, an explicit recorded effective instant, an explicit recorded status, and an
explicit recorded state for "not set". Its status column is the only column that ever moves; a row's
status moves **at most twice per row, in one declared direction** (§6.1), and every move is an audited
conditional statement whose affected-row count is the outcome. Its recording rule is temporal as well as
structural: a version is never recorded into time an already-recorded dependent Finance fact has already
consumed (§6.3, U-D19).

### 6.1 The only permitted keys

| Policy key | Recorded default | Meaning if set | Enforcing seam |
| --- | --- | --- | --- |
| `INTRO_PAYABILITY_POLICY` | `non_payable` (recorded, active) | Whether an `introductory` Lesson with otherwise payable delivery facts is payable | `LessonPayabilityService::evaluate()` (§9) |
| `STUDENT_NO_SHOW_COMPENSATION_POLICY` | `payable` (recorded, active) | Whether a `student_no_show` outcome is teacher-compensable | `LessonPayabilityService::evaluate()` (§9) |
| `INTERRUPTION_COMPENSATION_POLICY` | `payable` (recorded, active) | Whether an `interruption` outcome is teacher-compensable | `LessonPayabilityService::evaluate()` (§9) |
| `FINANCE_STATEMENT_TIMEZONE` | **unset** (no version row exists at all — never seeded) | The IANA timezone a statement's human-facing period label is rendered in | `TeacherStatementService::draft()` (§10.2) |

Rules:

- **Only these four keys may exist.** `FinanceRule::POLICY_KEYS` is the allowlist; a structural
  invariant (a Term allocation, the snapshot boundary, the derivation table, the single-currency rule,
  the archive rule) is refused `finance_policy_key_not_allowed` with the message `Structural finance
  invariants are not configurable finance policies`.
- **A version is written once.** `policy_key`, `policy_version`, `policy_value`, `value_type` and
  `effective_from` are never updated after insert, and no repository path can update them (§13.6).
  **Every version row is value-bearing**: `policy_value` carries the recorded value and `value_type`
  declares its type, and both columns are `NOT NULL` (§13.2). There is no null-valued policy row and no
  "recorded unset version": a row whose `policy_value` or `value_type` is absent fails closed with
  `finance_policy_value_type_invalid` and is rejected by the verifier (§13.4 rule 9). A missing
  `effective_from` fails closed (`finance_policy_effective_from_missing`), a duplicate or
  non-consecutive `(policy_key, policy_version)` fails closed (`finance_policy_version_conflict`), and
  an `effective_from` that is not strictly later than the key's existing maximum fails closed
  (`finance_policy_timeline_overlap`; no two rows of one key may claim one instant, which `UNIQUE
  policy_effective` also prevents structurally). An `effective_from` that is also not strictly later than
  the key's **recorded consumption maximum** fails closed `policy_effective_from_precedes_recorded_consumption`:
  a version may never be recorded into time a recorded dependent Finance fact has already consumed, so no
  recording can change what a committed snapshot, evaluation or statement resolves to at its own instant
  (§6.3, U-D19). The two guards are independent — monotonicity is about the key's own versions, the
  consumption guard is about the facts those versions have already priced — and both are proved before any
  write.
- **Supersession and retraction are the only two status moves, and one row may make both.** `active →
  superseded` when a successor version of that key exists, and `active|superseded → withdrawn` when the
  version is retracted (explicit, reasoned, evidenced): each is a single conditional statement whose
  affected-row count is the outcome, and neither may touch a value column. A row therefore moves **at
  most twice, in exactly that order** — `active → superseded → withdrawn` — or once, straight from
  `active` to `withdrawn`. `withdrawn` is terminal, a `superseded` row is never superseded a second
  time, a `superseded` row **may** still be withdrawn (retracting superseded historical authority is a
  normal audited retraction, never a repair), and no transition returns a row to `active`. There is no
  delete, no truncate and no re-use of a version number.
- **Unset is the absence of a covering version, never a row.** A key is unset at an instant when it has
  no version at or before that instant ("never set"), or when the version covering that instant has been
  withdrawn (retracted at T); no null-valued row ever encodes either state, and the resolved result
  reports the covering `policy_version` for the withdrawn case so a consumer can tell the two apart. An
  unset policy is **not** a default: while `FINANCE_STATEMENT_TIMEZONE` is unset, a statement may be
  drafted but not issued — issuance refuses `policy_unset_for_statement_period` and records the blocker.
  No server timezone, site timezone, Lesson timezone or operator-supplied timezone may substitute, and
  the draft records the unset representation rather than a guessed zone (§10.1). A key that any other
  derivation needs (`INTRO_PAYABILITY_POLICY`, `STUDENT_NO_SHOW_COMPENSATION_POLICY`,
  `INTERRUPTION_COMPENSATION_POLICY`) blocks that derivation with `finance_policy_unset` when it
  resolves to unset at the instant the derivation needs it.
- Every consumer records the exact policy version it applied, on the row it produced: the snapshot
  records the intro-policy version it used, and the statement records the timezone-policy version it
  used. A later policy change can therefore never rewrite what was already recorded.
- **Resolution is by the covered instant, never by live status** (U-D4, U-D6, U-D7).
  `FinancePolicyService::resolve($key, $atUtc)` returns the version of that key with the greatest
  `effective_from ≤ $atUtc`. A key's versions are strictly ordered and contiguous from its first version
  onward, so exactly one version governs each instant; resolution is deterministic for every instant —
  past, present and future-dated — and never reads the wall clock or the current time. **A newer version
  therefore never changes what an earlier instant resolves to**, and it never makes an earlier version
  unresolvable.
  - The resolved version's `status` has exactly two effects, neither of which is "prefer the live row":
    `active` and `superseded` both resolve to that version's recorded value — **a `superseded` version
    is normal history, never an error** — while `withdrawn` resolves to **unset**, because a retraction
    shadows its own instant and never falls back to an earlier version's value.
  - A key with no version at or before `$atUtc` resolves to **unset** ("never set"), never to the
    nearest version; a resolved `withdrawn` version also resolves to unset, with its `policy_version`
    reported on the result, so a consumer can always distinguish "never set" from "retracted at T".
  - **A future-effective successor is safe by construction.** A version whose `effective_from` lies in
    the future is simply not yet the greatest such instant: every instant before it resolves to its
    predecessor and every instant at or after it resolves to it. There is no window in which two
    versions of one key claim one instant, and no clock-dependent answer exists.

### 6.2 Policy commands

`FinancePolicyService::record`, `supersede`, `withdraw`, `resolve` — capability
`dzn_manage_finance_policies`. The three mutating operations are **Finance commands** and obey the same
two universal rules as every other Finance command: each is arbitrated by a keyed
`command_key_digest`/`command_payload_digest` pair with a durable command result row (§15.3), and each
writes under a serialisation root (§15.1). Because a policy row has **no `teacher_id`**, the per-Teacher
root cannot serialise them: they take the **global policy serialisation root** `finance_policy_roots`,
the single immutable row of §13.2, which is also the first element of the fixed lock order (§15.2).

- `record()` inserts one version — and **nothing else**: it performs no status move of its own, because
  the version it replaces stays `active` until the operator runs `supersede()`, and its recorded
  `policy_id`/`result_policy_id` both name the row it wrote. It refuses every structural defect named
  above before any write, and it refuses **every instant that is not admissible under §6.3's temporal
  admissibility rule**
  (`policy_effective_from_precedes_recorded_consumption`). It takes the global policy root **exclusively**
  for the whole transaction, reads the key's recorded consumption maximum inside that transaction, and only
  then inserts — so two competing versions of one key can never interleave, can never share an instant
  (§15.2), and can never steal the coverage of a fact that a consumer committed while the command was in
  flight (§6.3, U-D19).
- `supersede()` is the single conditional `active → superseded` statement on the row a newly recorded
  successor replaces; its affected-row count is the outcome, and it is the **only** command that performs
  that move, so the `record()`/`supersede()` pair is the declared two-command lifecycle rather than one
  command that hides the transition (§0g, U-C8-BLOCK-004). It takes the global policy root
  **exclusively**, like every mutating policy command of §15.1.
- `withdraw()` is the single conditional `active|superseded → withdrawn` statement; it never edits
  `policy_value`, `value_type` or `effective_from`, and it retracts rather than repairs (the affected
  instants resolve to unset, §6.1). It takes the global policy root **exclusively**. Retraction is the one
  declared way recorded authority is withdrawn, so it is admissible even when a dependent Finance fact has
  already consumed the version — and §6.3 declares exactly what that does and does not change: nothing is
  rewritten, a draft that consumed the version is blocked at issuance (§10.5 #8), a re-derivation is
  refused `finance_policy_unset` (§9.4), and a recorded consequence that must actually change goes only
  through the audited corrections of §12.
- `resolve()` is the read path of §6.1; it takes no lock, writes nothing, writes no command row and
  returns the resolved version or the unset result. A command that resolves a policy version and records
  the applied version on a Finance row holds the global policy root **shared** for the whole transaction
  and takes it **first**, before the Teacher root: statement `draft`/`issue` (§10.1, §10.5), snapshot
  capture (§8.2) and payability evaluation/override (§9.2–§9.3) all do, so a policy mutation and any such
  command are totally ordered and §6.3's guard can never miss an in-flight consumer (§6.3, §15.1–§15.2).
- Every mutating policy command appends exactly one digest-only `finance_policy_commands` row — the
  command result store declared for this registry — naming `policy_key`/`policy_version`/`policy_id` as
  its selectors and the typed `result_policy_id` (parent `finance_policies.id`, §13.3) as its result, and
  carrying `result_state` `recorded`/`superseded`/`withdrawn` or `refused` plus the `reason_code` of a
  refusal. An idempotent replay converges on the existing row; a materially different replay is refused
  `command_replay_conflict` and preserves the original record (§15.3).
- Every mutating policy command also appends its digest-only audit evidence to `platform_audit_events`
  under §15.7 — one insert per audited Finance row, in the same transaction, keyed by a derived digest so
  a replay never duplicates it.

**Why this registry moves one column while `finance_teacher_rates` moves three.** A rate must be able to
disappear from resolution and must hold at most one live row per scope under concurrency, so it needs an
interval bound (`effective_until`) and a live slot (`active_slot`) alongside its `status` — §7.2 permits
exactly those three conditional columns. A policy version needs none of them: its instant is its
identity, a successor needs no closure of its predecessor, and "retracted" is a status rather than an
interval. Both registries share the same audited conditional idiom and the same at-most-twice, one-way
status lifecycle, so the asymmetry is in columns only, and it is asserted by the §18 contract test.

### 6.3 Policy consumption safety — the temporal admissibility rule (U-D19)

A version's `effective_from` decides which instants it covers, and resolution returns the version with the
greatest `effective_from ≤ instant` (§6.1). Recording a version is therefore the only act in this phase
that can change what an **already-recorded** dependent Finance fact resolves to at its own instant: a
successor recorded with an `effective_from` at or before an instant a committed fact already consumed
would steal that fact's coverage, and the immutable fact could then no longer prove that the version it
recorded covered the instant it recorded. Phase U forbids that outright. A policy version is never
recorded into time that a recorded dependent Finance fact has already consumed.

**The dependent Finance facts, and their policy-relevant instant.** A dependent Finance fact is a recorded
Finance row that resolved a policy key and recorded the version it applied:

| Key consumed | Recorded by | Recorded version | Policy-relevant instant |
| --- | --- | --- | --- |
| `INTRO_PAYABILITY_POLICY` | `LessonFinanceSnapshotService::capture()`, for an `introductory` Lesson | `finance_lesson_snapshots.intro_policy_key`/`intro_policy_version` | the snapshot's recorded `snapshot_instant_utc` |
| `INTRO_PAYABILITY_POLICY` (restated) | `FinanceCorrectionService::correctSnapshot()` | `finance_snapshot_corrections.intro_policy_key`/`intro_policy_version` | the corrected snapshot's `snapshot_instant_utc` |
| `INTRO_PAYABILITY_POLICY`, `STUDENT_NO_SHOW_COMPENSATION_POLICY`, `INTERRUPTION_COMPENSATION_POLICY` | `LessonPayabilityService::evaluate()` (and the evaluation an `override()` appends) | `finance_payability_evaluations.policy_key`/`policy_version` | the `snapshot_instant_utc` of the snapshot the evaluation binds |
| `FINANCE_STATEMENT_TIMEZONE` | `TeacherStatementService::draft()` | `finance_statements.timezone_policy_version` | the statement's recorded drafting instant (`created_at`), the instant at which the triple of §10.1 was resolved and frozen |

The instant is the **recorded instant of the covered occurrence or period — never the row's insertion
order, the wall clock or "now"** (U-D4). That is what makes the guard reproducible rather than
timing-dependent: the same fact yields the same instant however long after the fact a reviewer reads it,
and a fact whose recorded instant is unchanged can never change its own coverage.

**The recorded consumption maximum.** For a policy key `k`, `C(k)` is the greatest policy-relevant instant
among the dependent Finance facts of `k` that are already recorded, or **"never consumed"** when there is
none. `C(k)` is a bounded read of declared rows through their declared named indexes —
`finance_lesson_snapshots`' declared `intro_policy (intro_policy_key, intro_policy_version)` lookup
together with its `teacher_instant (teacher_id, snapshot_instant_utc)` index,
`finance_payability_evaluations`' declared `evaluation_policy (policy_key, policy_version)` lookup
together with its `snapshot (snapshot_id)` index, and `finance_statements`' `teacher_state` and `period`
indexes — and no counter, watermark table, derived row or second copy of the fact is declared for it, so
it can neither drift from the facts it summarises nor become a shadow record of them.

**The admissibility rule (recording).**

1. `record()` **refuses** any version whose `effective_from` is **not strictly later than** `C(k)` for its
   key, with `policy_effective_from_precedes_recorded_consumption` (§5.2.1). The refusal writes no
   `finance_policies` row, moves no predecessor to `superseded`, and records its durable reason: a
   `refused` `finance_policy_commands` row naming the key and the refused version with a `NULL`
   `result_policy_id`, the matching (teacher-less) `finance_exceptions` row, and its digest-only audit
   evidence. This is a **business refusal**, so the three evidence rows are committed by the declared
   refusal-evidence transaction of §15.8 while every attempted mutation of the command — the version
   insert and the predecessor supersession — rolls back; it is never the whole-transaction rollback of a
   persistence failure (§6.2, §15.3, §15.7–§15.8).
2. **The guard is evaluated inside the exclusive-root transaction.** `record()` takes the global policy
   root `finance_policy_roots` **exclusively** (§15.1) and only then reads `C(k)` — after every in-flight
   consumer of that root has either committed or been excluded — and then inserts. Two competing versions
   of one key therefore cannot pass the guard against one consumption state and then interleave, and a
   consumer cannot commit a fact the guard's read did not see (§15.1–§15.3).
3. **A version recorded where nothing has been consumed is unconstrained beyond monotonicity.** When
   `C(k)` is "never consumed" — no introductory Lesson captured, no governed evaluation appended, no
   statement drafted for the timezone key — a version may be recorded with any `effective_from` later than
   the key's existing maximum, including an instant in the past. An academy setting its policy for the
   first time can therefore record the authority that was in force before its first capture, which is
   exactly what U-D4's historical resolution needs. Back-dating is admissible into unconsumed time and
   refused into consumed time; the rule is temporal, never a matter of how old the instant is.
4. **`supersede()` inherits the guard and adds no coverage decision of its own.** A predecessor is only
   ever superseded by a successor that `record()` admitted, so `supersede()` can neither admit nor rescue
   an inadmissible instant: it is the audited conditional move of the row the admitted successor replaces.

**Retraction is the one declared way authority is withdrawn, and it never rewrites a recorded fact.** A
`withdraw()` of a version that a recorded fact consumed is admissible, audited and visible (§6.1, §6.2) —
an academy must be able to retract a policy it recorded wrongly — and its consequence for the facts that
consumed it is declared rather than implicit:

- no recorded fact is edited, re-derived or re-versioned (U-D7): the snapshot keeps the pair it applied,
  the evaluation keeps the key and version it applied, and the statement keeps the triple it recorded;
- the retraction is itself the durable, reasoned, evidenced record that keeps the difference
  re-verifiable (U-D2, U-D4): a reader of a historical snapshot can always see both the version that was in
  force when the fact was recorded and the retraction's own instant, and can therefore reconstruct the
  authority timeline around the fact's instant;
- an affected statement draft can no longer be issued — §10.5 #8 refuses
  `policy_unset_for_statement_period`, records the matching exception and the operator withdraws and
  re-drafts (§10.6) — and an affected re-derivation is refused `finance_policy_unset` and appends no
  evaluation (§9.4);
- a recorded consequence that must actually change — a snapshot amount or policy pair, an issued statement
  — changes **only** through the audited correction/supersession path of §12 (`snapshot_correction`,
  `payability_override`, `statement_supersession`), which leaves the target intact and re-verifiable;
- and the affected facts are reported on the reconciliation surface (§11), so a retraction that invalidates
  a live draft or blocks a derivation is visible rather than silent.

**Which commands take the policy root.** The root discipline of §15.1 is therefore: the mutating policy
commands (`record`, `supersede`, `withdraw`) take `finance_policy_roots` **exclusively**, and every command
that resolves a policy version and records the resolution on a Finance row — snapshot capture, payability
evaluation/override, and statement `draft`/`issue` — takes it **shared** and holds it for the whole
transaction, always **first**, before the Teacher root. Policy writes and policy-consuming captures and
drafts are therefore totally ordered, the guard's read can never miss an in-flight consumer, and two
shared holders still never block each other, so two Teachers still never contend on a policy row
(§15.1–§15.2, §18). A snapshot correction is deliberately **not** in that list: it restates the corrected
snapshot's own recorded policy pair rather than resolving a version (§12.2), so it records no new
coverage and needs no policy root.

**A teacher-less command takes the global root for a different reason, and takes no other root.** A
reconciliation `run` and a `resolveException` resolve no policy version, so they are **not** policy
consumers; they take the global policy root only when the command has no Teacher of its own, because
§15.1 requires every Finance write to take one root first and a row with `teacher_id = NULL` has no
per-Teacher root to take: a period-wide `run()` (no `teacherId`) and a `resolveException()` whose target
exception carries `teacher_id = NULL` take `finance_policy_roots` in **shared** mode. A `run()` scoped to
a Teacher, and a `resolveException()` whose target exception carries a `teacher_id`, take that Teacher's
`finance_teacher_roots` row and no policy root at all (§11.2, §14.1, §15.1–§15.2, §15.8). The root is
therefore selected from the **target row's own scope**, never from the operation name, and no command ever
takes the global root after a Teacher-scoped row.

**The rate registry already carries the same guard.** §7.4 already declares the same rule for
`finance_teacher_rates` — recording a rate whose `effective_from` is earlier than an existing snapshot
instant of the same Teacher is refused `rate_effective_from_precedes_snapshot` — because a rate's
consuming facts are that Teacher's snapshots. §6.3 is the identical rule for the registry whose consuming
facts are not Teacher-scoped, and §18 asserts both guards together.

## 7. Effective-dated teacher rate authority

`finance_teacher_rates` records what the academy pays a Teacher. It is effective-dated, scoped,
currency-explicit and interval-bounded. There is deliberately **no** "current rate" column and no
mutable rate value anywhere in this phase.

### 7.1 Scope and identity

| Column | Rule |
| --- | --- |
| `teacher_id` | `bigint unsigned NOT NULL`; parent `dzn_teachers.id`. Required on every row. |
| `scope_kind` | `teacher` or `teacher_course`. |
| `course_scope_id` | `bigint unsigned NOT NULL`. The course id for `teacher_course`; the declared sentinel **`0`** for `teacher`. A `teacher`-scoped row with a non-zero `course_scope_id`, or a `teacher_course`-scoped row with `0`, fails closed on insert and on read with `rate_scope_violation`, and is rejected by the verifier (§13.4 rule 8). |
| `compensation_basis` | `per_session` only (§5.2). |
| `amount_minor` | `bigint unsigned NOT NULL`, minor units of `currency`. |
| `currency` | `char(3) NOT NULL`, ISO-4217. |
| `effective_from` | `datetime NOT NULL`, inclusive. |
| `effective_until` | `datetime NULL`, exclusive. `NULL` means "until superseded". |
| `status` | `active`, `superseded` or `withdrawn`. It records only which row is the scope's *live* row (`active_slot = 1`), or that a row was retracted; it never gates historical resolution (§7.3). |
| `rate_version` | `int unsigned NOT NULL`, strictly increasing per `(teacher_id, scope_kind, course_scope_id)`. |
| `active_slot` | `tinyint unsigned NULL`, the R1 idiom: `1` on the live row of a scope, `NULL` once the row is superseded or retracted. |

Uniqueness and arbitration:

- `UNIQUE teacher_scope_slot (teacher_id, scope_kind, course_scope_id, active_slot)` — the R1
  `active_slot` discipline, so a scope has at most one live row and a superseded history may be
  unbounded.
- `UNIQUE scope_version (teacher_id, scope_kind, course_scope_id, rate_version)`.
- `KEY teacher_status (teacher_id, status)`, `KEY course_scope (course_scope_id)`,
  `KEY effective (effective_from, effective_until)`.
- **No two rows of one scope may have overlapping effective intervals.** The service proves this under
  the Teacher finance root before any insert or closure; a detected overlap fails closed with
  `teacher_rate_timeline_overlap` and writes nothing.

### 7.2 The only permitted mutation

A rate row is inserted complete. After insert, exactly **three physical columns** may move —
`effective_until`, `status` and `active_slot` — through at most two conditional statements, each of
which reports its affected-row count as the outcome and each of which writes its own
`finance_teacher_rate_events` row (`from_status`/`to_status`):

1. `effective_until` — written **at most once**, from `NULL` to the successor's `effective_from` when a
   successor becomes active, or to the withdrawal instant when the row is retracted before any successor
   exists (retracting a row that has not yet started closes an empty interval). A withdrawn row carrying
   an open `effective_until` is corruption, never a second write (§13.4 rule 8).
2. `status` + `active_slot` — `active`→`superseded` with `active_slot` cleared when a successor takes
   the scope's live slot, and then `superseded`→`withdrawn` with `active_slot` cleared again (already
   `NULL`), or `active`→`withdrawn` directly when the row is retracted with no successor. A row's
   `status` therefore moves **at most twice, in that one declared direction**; `withdrawn` is terminal,
   there is no transition back to `active` and no second supersession. A `superseded` row that is later
   withdrawn therefore has two status events and two affected-row counts, and remains ordinary history
   until the withdrawal.

`teacher_id`, `scope_kind`, `course_scope_id`, `compensation_basis`, `amount_minor`, `currency`,
`effective_from`, `rate_version`, `reason_code` and every audit column are **never** updated after
insert. The verifier proves this by rejecting any implementation whose repository exposes an update
path for those columns (§13.4).

**The write order is declared, not incidental.** A successor is inserted **after** its predecessor has
released the scope's live slot, because `UNIQUE teacher_scope_slot` admits exactly one live row per scope
(§13.2): `record()` closes the predecessor's interval and moves its `status`/`active_slot` first — each
move a conditional statement whose affected-row count must be `1` — and inserts the successor, all inside
one transaction, so a failure after the predecessor moved rolls the whole command back instead of leaving
a closed gap or two live rows (§0g, U-C8-BLOCK-002). Symmetrically, `withdraw()` writes a closure only
when the row still carries an open interval, so the interval-free proof is measured exactly where a
closure is written and a rate a successor already closed is retractable without re-judging its own past
instant.

An interval is half-open `[effective_from, effective_until)`. An interval that is closed at or before
its own start covers no instant at all: it is retained as history and is never a resolution candidate.

### 7.3 Resolution

`TeacherRateService::resolveFor($teacherId, $courseId, $instantUtc)` resolves **the interval that covers
the requested instant**, never "the live rate" (U-D4, U-D6, U-D7). It is **one ordered algorithm** with
one outcome per instant, and no branch of it can fall back to another scope:

1. validates `$teacherId`/`$courseId` are live canonical rows and that the Teacher aggregate validates —
   a missing or non-live canonical parent fails closed `finance_parent_not_live`, a parent outside
   §13.3 fails closed `finance_parent_not_declared`, and a corrupt aggregate fails closed
   `upstream_aggregate_invalid`;
2. builds the **coverage set** from interval containment alone: every row of that Teacher whose recorded
   interval contains `$instantUtc` (`effective_from ≤ $instantUtc < COALESCE(effective_until,
   '9999-12-31')`), whether or not the row is the scope's live row and whatever its `status`. `status`
   never decides which interval covers an instant, and a withdrawn row still occupies its own instants —
   that is precisely why step 4 can see it;
3. fixes the **winning specificity** from that coverage set: `teacher_course` if the set contains any
   `teacher_course` row, otherwise `teacher`. If the coverage set is empty for both specificities the
   instant has no rate at all, and step 7's gap outcome applies;
4. **detects withdrawn coverage at the winning specificity before any scope fallback.** If the winning
   specificity's covering rows are **all** `withdrawn`, the instant has **no** rate: the algorithm stops
   here, reports `rate_missing_for_lesson` with `teacher_rate_timeline_gap`, and **never** consults a
   broader-scoped row. A withdrawn row is not a fallback target, and its presence is not a gap that a
   broader scope fills: substituting another rate would invent a money fact;
5. takes the **eligible set** as the winning specificity's covering rows whose `status` is `active` or
   `superseded`. A `withdrawn` row is never eligible and is never returned, while a `superseded` row is
   ordinary history and is exactly as eligible as an `active` one;
6. decides the eligible set: **exactly one** eligible row is returned with its `rate_id`,
   `rate_version`, `scope_kind`, `compensation_basis`, `amount_minor`, `currency` and its recorded
   `status` (which may be `superseded`); **more than one** is corruption or mis-configuration and fails
   closed with `ambiguous_teacher_rate` — nothing is chosen, ranked, preferred by recency or resolved by
   the caller. (The eligible set cannot be empty at this point: a winning specificity with covering rows
   that are not all `withdrawn` has at least one `active`/`superseded` row, and the all-withdrawn case
   already stopped at step 4.)
7. records the outcome when no rate resolves: **empty coverage** (step 3) and **withdrawn coverage**
   (step 4) both produce the same *recorded blocker* `rate_missing_for_lesson` — materialised as a
   `finance_exceptions` row, reported by `teacherRateCoverage()` as `teacher_rate_timeline_gap`, and
   carried as snapshot debt (`snapshot_missing_for_lesson`) — so the Lesson simply has no snapshot and
   the statement cannot issue. `teacher_rate_state_not_resolvable` is therefore reserved for an
   *integrity fault*, not a coverage outcome: a row surfaced through the live index (`active_slot = 1`)
   that does not carry `status = 'active'`, or a withdrawn row that still carries `active_slot = 1` or an
   open interval.

Two consequences are locked with the resolver:

- **Delayed historical capture is the ordinary path.** A Lesson finalised *after* a successor rate was
  recorded is captured at its own locked instant and resolves the interval that covers that instant —
  the predecessor — even though the predecessor is `superseded` and the successor is the scope's live
  row. Nothing is recomputed, no row is rewritten, and the resolved `rate_id`/`rate_version` is what the
  snapshot and every statement keep (U-D6, U-D7).
- **A future-effective successor is safe.** A successor recorded with an `effective_from` in the future
  closes its predecessor at exactly that instant and takes the scope's live slot immediately; every
  instant before it resolves to the predecessor and every instant at or after it resolves to the
  successor. Resolution never consults the wall clock, so an instant's answer is identical before and
  after that instant arrives, and no window exists in which two intervals of one scope and one
  specificity cover the same instant (`teacher_rate_timeline_overlap`, proven before every insert and
  before every closure).

### 7.4 Withdrawal and history

- A rate may be withdrawn only while **no snapshot references it** (`active`/`superseded` →
  `withdrawn` — the second and terminal status move of §7.2, available to a live row and equally to a
  `superseded` historical one). The refusal is `rate_referenced_by_snapshot`, and it is not overridable
  by a flag or a policy (U-D6).
- A withdrawal retracts the interval from resolution (§7.3 rule 7): the affected instants become a
  recorded gap (`teacher_rate_timeline_gap`) with `rate_missing_for_lesson` debt, never a silent
  substitution of another rate and never a silent default.
- Recording a rate whose `effective_from` is earlier than an existing snapshot instant of the same
  Teacher is refused with `rate_effective_from_precedes_snapshot`: a rate is never inserted
  retroactively into an interval that a committed snapshot has already resolved. History is corrected
  only through the audited correction path of §12, which leaves every prior row intact. This is the
  rate registry's instance of the general rule of §6.3 (U-D19) — the same guard, over the registry whose
  consuming facts are Teacher-scoped snapshots — and the two guards are asserted together by §18.
- Closing an interval is not a correction: it is the ordinary, evidential consequence of a successor
  becoming active, and it changes nothing a snapshot already recorded.

## 8. Per-Lesson rate/currency snapshot authority

The per-Lesson rate/currency snapshot is the fact Phases M, N, O and P do not own and Finance must own:
what this exact Lesson occurrence is worth to this exact Teacher, in an exact currency, under the
authority that was in force at that exact occurrence.

### 8.1 What a snapshot is, and what it is not

`finance_lesson_snapshots` records one applicable snapshot per canonical Lesson:

| Recorded fact | Source |
| --- | --- |
| `lesson_id`, `enrolment_id`, `term_id`, `teacher_id`, `teacher_assignment_id`, `course_id` | Phase M/J canonical rows, validated against each other |
| `lesson_kind` (`standard`, `replacement`, `introductory`) | Phase M canonical Lesson kind |
| `schedule_version_id`, `occurrence_starts_at_utc`, `occurrence_ends_at_utc`, `duration_minutes` | Phase N applicable schedule version, or the effective Phase-O outcome's recorded occurrence anchors when one exists |
| `rate_id`, `rate_version`, `scope_kind`, `compensation_basis`, `rate_amount_minor`, `currency`, `rate_effective_from` | `finance_teacher_rates` resolution at the snapshot instant (§7.3) |
| `snapshot_instant_utc` | U-D4's locked instant, verbatim |
| `snapshot_boundary` | the literal `occurrence_start` (U-D4); recorded so a later reader cannot reinterpret the boundary |
| `derived_amount_minor` | the derived amount for this occurrence under `compensation_basis` (§8.3). This is the occurrence's compensation fact; it is **not** the amount a statement totals |
| `intro_policy_key`, `intro_policy_version` | the intro payability policy applied at capture, or `NULL` for a non-introductory Lesson |
| `payability_disposition_at_capture` | provenance only — the derivation result that existed at capture time. It is **not** the effective payability (§9) |
| `derivation_digest` | keyed digest over the exact inputs above (§8.3) |
| `created_at`, `created_by`, `reference_code`, `uid` | audit identity |

A snapshot is **not** a price, a discount, an invoice line, a student obligation, a payout, a payment,
an accrual or a ledger entry. It is a recorded compensation fact for one Lesson occurrence.

### 8.2 Capture

`LessonFinanceSnapshotService::capture($lessonId)` — capability `dzn_manage_finance_statements`:

1. takes the global policy serialisation root **shared** and then the Teacher finance root
   (`finance_teacher_roots`, §15.1) derived from the Lesson's effective Teacher, in the fixed order of
   §15.2 — the capture resolves a policy version and records it, so it is a policy consumer of §6.3 and
   can never be ordered against a policy mutation in any other way;
2. hydrates and validates the Lesson through `CanonicalLessonAuthorityValidator` (Phase M) and
   `CanonicalLessonDeliveryValidator`/`CanonicalAcademyObligationValidator` where an outcome or an
   obligation exists — a corrupt upstream aggregate fails closed with `upstream_aggregate_invalid`;
3. requires the Lesson to be **finalised** — `completed` or `cancelled`, archived or not. A
   `draft`/`scheduled` Lesson is a *plan*, its occurrence anchor can still move under Phase N, and a
   snapshot of a plan could land in the wrong statement period; the attempt is refused
   `snapshot_lesson_not_finalised` and changes nothing;
4. derives the snapshot instant (U-D4) and the occurrence anchors;
5. resolves the rate through §7.3's **interval** resolution, or records the blocker and returns without
   writing a snapshot;
6. resolves the intro policy version that applies at the snapshot instant (§6.1) — for an
   `introductory` Lesson the capture is refused `finance_policy_unset` when that key resolves to unset,
   and for every Lesson the resolved version (or `NULL` for a non-introductory Lesson) is recorded. The
   recorded pair is the capture's policy consumption of §6.3: because the capture holds the global policy
   root, no version can be recorded concurrently that would resolve for this instant and leave the
   snapshot's own pair unable to prove coverage of its own `snapshot_instant_utc`;
7. computes `derived_amount_minor` exactly (U-D1) and the `derivation_digest`;
8. inserts the snapshot inside the same transaction, with `UNIQUE lesson_id` as the convergence
   guard;
9. writes digest-only command evidence in `finance_snapshot_commands`.

Replay rules (the R1/R2/T discipline, unchanged):

- The command key is a keyed digest plus a payload digest. An identical replay converges on the existing
  snapshot **only after** the existing row is re-verified: same Lesson, same occurrence anchors, same
  rate row and version, same amount and currency, same `derivation_digest`.
- A replay whose intent differs from the recorded snapshot fails closed with
  `command_replay_conflict`; it never overwrites and never writes a second row.
- A capture for a Lesson that already has a snapshot never inserts a second row (a `UNIQUE lesson_id`
  collision is adopted, re-verified and converged).

### 8.3 Exact amount and derivation digest

- `derived_amount_minor` is derived under `compensation_basis`. In this phase the only member is
  `per_session`, so the derived amount equals `rate_amount_minor` for one occurrence: an exact copy with
  no arithmetic.
- Any future basis that multiplies (`per_hour` × duration, for example) must produce an exact integer;
  a non-exact result fails closed with `finance_amount_not_exact` and records a blocker. Rounding,
  banker's rounding, half-up and truncation are all forbidden.
- `derivation_digest` is an HMAC-style keyed digest (the domain-separated key pattern already used by
  R1/R2/T) over the exact canonical inputs listed in §8.1, in a declared field order
  (`FinanceRule::SNAPSHOT_DIGEST_FIELDS`). It is recomputed on every read and on every statement
  derivation; a mismatch fails closed with `snapshot_derivation_mismatch` and is never repaired in
  place.

### 8.4 Immutability and the *effective* snapshot

- `finance_lesson_snapshots` has **no** `updated_at`, no update method and no delete method. Once
  written, its columns are permanent.
- The **effective** snapshot of a Lesson is: the Lesson's snapshot when no correction names it,
  otherwise the newest applicable row of `finance_snapshot_corrections` for that snapshot (§12.2).
  Every consumer — the payability derivation's provenance check, statement drafting, reconciliation —
  reads the effective snapshot through `FinanceSnapshotIntegrity::effective($lessonId)`, never through a
  raw repository row.
- A snapshot whose recorded facts no longer match the canonical facts it named (for example Phase O
  appended a new effective delivery outcome, or Phase N recorded a replacement schedule version) is
  **stale, not wrong**: the difference is reported (`delivery_outcome_changed_after_issue`,
  `snapshot_derivation_mismatch`), the original snapshot stands, and only an explicit audited
  correction or a new statement version may change the recorded finance consequence.

### 8.5 Refusals (all durable, all reason-coded)

| Condition | Result | Side effect |
| --- | --- | --- |
| Lesson not finalised (`draft`/`scheduled`) | `snapshot_lesson_not_finalised` | nothing written; the Lesson appears as snapshot debt once it is finalised and still uncaptured |
| No effective rate at the snapshot instant | `rate_missing_for_lesson` | `finance_exceptions` row + `snapshot_missing_for_lesson` debt; **no** snapshot |
| Two candidates at the winning specificity | `ambiguous_teacher_rate` | `finance_exceptions` row; no snapshot |
| Lesson has no applicable occurrence anchor | `occurrence_anchor_missing` | exception; no snapshot |
| Lesson kind is not one of the three canonical kinds | `finance_lesson_kind_not_allowed` | exception; no snapshot |
| Upstream aggregate fails its owning validator | `upstream_aggregate_invalid` | exception; no snapshot |
| The instant's coverage at its winning specificity is withdrawn (§7.3 step 4) | `rate_missing_for_lesson` (+ `teacher_rate_timeline_gap`) | exception; no snapshot; no fallback to a broader scope |
| Corrupt live index (a `active_slot = 1` row that is not `active`, or a withdrawn open interval) | `teacher_rate_state_not_resolvable` | exception; no snapshot |
| An `introductory` Lesson whose intro-policy key resolves to unset at its instant | `finance_policy_unset` | exception; no snapshot |
| Corrupt existing snapshot on replay | `snapshot_derivation_mismatch` | fail closed; no write, no repair |

## 9. Lesson payability authority

### 9.1 The evaluation chain

`finance_payability_evaluations` is append-only and chain-per-Lesson, using the Phase-O idiom:
`evaluation_sequence`, one applicable row per Lesson (`applicable_slot`) and an explicit
`superseded_at`/`superseded_by_evaluation_id` edge. It never mutates a prior row's disposition, basis
or evidence.

Each row records: the disposition and basis code, the Lesson kind, the exact canonical facts it read
(`delivery_outcome_id`, `delivery_state`, `attendance_state`, `remedy_class`, `academy_obligation_id`,
`schedule_version_id`), the exact policy versions it applied, the snapshot it is bound to, the exact
override row when one applies, `derivation_digest`, the evidence channel/keyed digest/observed time,
the actor and time, and its supersession edge.

**Effective payability** = the applicable evaluation (uppermost non-superseded row) of the Lesson.
Every consumer reads it through `FinancePayabilityIntegrity::effective($lessonId)`; a raw row is never
treated as authoritative.

### 9.2 Evaluation commands

`LessonPayabilityService::evaluate($lessonId)` — capability `dzn_manage_lesson_payability`:

- takes the global policy serialisation root in **shared** mode and then the Teacher finance root, in the
  fixed order of §15.2, because the evaluation resolves a policy-governed derivation and records the exact
  version it applied on the row it appends (§6.1, §6.3); `override()` is a policy consumer for the same
  reason and takes the same two roots in the same order;
- derives the disposition and basis from the §5.3 table under
  `FinanceRule::PAYABILITY_DERIVATION_VERSION`;
- appends a new evaluation **only** when it differs from the effective one, or when the effective one
  is absent; an identical re-derivation is a no-op convergence, not a duplicate row;
- appends the new row with an **empty** applicable slot and moves the Lesson's one applicable slot through
  §15.4's two-statement protocol — the predecessor's supersession statement first, the successor's slot
  claim second — so the declared `UNIQUE lesson_applicable` never sees two non-NULL slots in one Lesson
  (§0g, U-C8-BLOCK-003);
- writes digest-only evidence in `finance_payability_commands`.

An evaluation requires the Lesson's **effective snapshot**: a Lesson with no snapshot cannot be
evaluated, and the attempt records `snapshot_missing_for_lesson` rather than inventing an amount or a
disposition. Every evaluation row therefore carries the `snapshot_id` it is bound to (§13.2).

`evaluate()` is invoked explicitly and, in addition, is invoked idempotently by the bounded
post-outcome consequence when a Phase-O/M change is published to the Platform outbox
(`finance.payability_review_required`). That consequence appends an evaluation; it **never** edits an
existing one and **never** rewrites a statement.

### 9.3 Overrides

`LessonPayabilityService::override($lessonId, $disposition, $reasonCode, $evidence)` — capability
`dzn_manage_lesson_payability`:

1. appends a `finance_payability_overrides` row naming the exact prior evaluation id and its
   `derivation_digest`, the actor, reason code, evidence channel, keyed evidence digest and observed
   time; the reason code is chosen from the declared vocabulary of §5.2.1 (never free text, §5.4);
2. appends a new evaluation with basis `administrator_override` carrying that override id;
3. supersedes the previous applicable evaluation and claims the Lesson's one applicable slot for the
   appended row through the two-statement protocol of §15.4 (§0g, U-C8-BLOCK-003).

An override may move a disposition in **any** direction, including resolving a `pending`. It always
leaves the derivation it replaced intact and visible, always appears in reconciliation
(`override_applied`), and never changes an upstream fact (U-D3).

### 9.4 Conflicts and refusals

| Condition | Result |
| --- | --- |
| Effective disposition contradicts the effective delivery fact (for example `payable` under an effective `teacher_non_delivery`) | `payability_conflicts_with_delivery_fact` exception; statement issue blocked |
| `pending` at issue time | `payability_pending` blocker |
| Supersession attempted by a non-applicable row | `payability_supersession_conflict`; fail closed, no write |
| Override naming a foreign or missing prior evaluation | `payability_override_target_invalid`; no write |
| A policy-governed derivation (an `introductory`, `student_no_show` or `interruption` fact) whose key resolves to unset at the snapshot instant | `finance_policy_unset`; no evaluation appended |
| Corrupt chain (two applicable rows, a broken sequence, a superseded row with no successor) | fail closed through `FinancePayabilityIntegrity`; never repaired |

### 9.5 No money moves

An evaluation, an override or a correction never changes a `finance_lesson_snapshots` amount, an
already-written statement line, an issued statement total, a Term, a Lesson, an Enrolment, an academy
obligation, a student obligation, a settlement or a provider state. Where a change in effective
payability would change a recorded statement, the difference is **reported** and requires an explicit
correction or supersession (§12).

## 10. Teacher compensation statement authority

### 10.1 Identity and period

`finance_statements` is identified by Teacher + half-open period + version:
`UNIQUE teacher_period_version (teacher_id, period_start_utc, period_end_utc, statement_version)`.

- The period is half-open `[period_start_utc, period_end_utc)`; a Lesson belongs to exactly one period,
  decided by its snapshot instant (U-D4), never by the statement date, the correction date or the
  Lesson's current schedule.
- `period_start_utc < period_end_utc` and
  `period_end_utc − period_start_utc ≤ FinanceRule::MAX_STATEMENT_PERIOD_DAYS` (62 days). A longer span
  is refused `statement_period_too_long`; a calendar-month helper is a UI concern, not an authority.
- The period must have **elapsed** at draft time (`period_end_utc ≤ now`, both UTC). A statement covers
  an interval that has already happened; an open-ended period is refused
  `statement_period_not_elapsed`. This is what makes a period's Lesson set closed: a Lesson can never
  appear inside a period that was already issued, so nothing is stranded by a period boundary.
- Two **live** (`draft`/`issued`) statements of one Teacher may not overlap in time: the service proves
  non-overlap under the Teacher finance root and refuses `statement_period_overlap` otherwise.
- `currency` is derived from the lines (§10.3) and is fixed at draft time.
- `period_timezone`, `period_label` and `timezone_policy_version` are **one all-or-nothing recorded
  triple**, written at draft time and never edited afterwards:
  - **policy set at the drafting instant** (§6.1): `period_timezone` records the resolved IANA zone,
    `period_label` is this period rendered in that zone, and `timezone_policy_version` records the exact
    policy version applied;
  - **policy unset at the drafting instant**: all three are `NULL`. That is the *recorded unset
    representation* — drafting still succeeds, because a draft is a derived artefact (U-D11), but the
    draft can never be issued (§10.5 #8), no label is rendered anywhere for it, and **no substitute
    source** (server, site, Lesson, operator or UTC default) is ever used to fill the triple;
  - a partially recorded triple is corruption (`statement_timezone_representation_invalid`) and fails
    closed on read, inside `assertTotals()` and in the verifier (§13.4);
  - recording, superseding or retracting the timezone policy **after** a draft exists never re-labels a
    statement in place and never rewrites a recorded triple (U-D7). A successor leaves the draft's
    recorded zone correct — resolution is by covered instant and a `superseded` version is normal history
    (§6.1) — so re-drafting after a successor is a freshness choice, not a correctness requirement; a
    **retraction** of the recorded version resolves that instant to unset, makes the draft stale and
    blocks its issuance (§10.5 #8), and the operator withdraws and re-drafts it (§10.6). `draft` and
    `issue` hold the global policy serialisation root (§15.1–§15.2), so a policy mutation and a draft are
    totally ordered and a draft can never record a mixture of two policy states. The recorded drafting
    instant (`created_at`, the instant at which this triple was resolved and frozen) is the
    `FINANCE_STATEMENT_TIMEZONE` key's **policy-relevant instant** for §6.3: once a statement has recorded
    a triple, no version of that key may be recorded with an `effective_from` that is not strictly later
    than that instant, so a later recording can never out-cover the version this statement recorded.

### 10.2 Drafting

`TeacherStatementService::draft($teacherId, $periodStartUtc, $periodEndUtc)` — capability
`dzn_manage_finance_statements`:

1. takes the global policy serialisation root and then the Teacher finance root in the fixed order of
   §15.2, and validates the Teacher aggregate;
2. enumerates the period's **finance-relevant Lesson set** from canonical occurrences — every Lesson of
   that Teacher whose applicable occurrence start (U-D4's anchor, resolved from the effective outcome
   or the applicable Phase-N schedule version) falls inside the period, whether or not a snapshot
   exists — excludes the archived members of that set with a counted exclusion (§10.4), and requires
   every remaining member to be finalised and snapshotted (§10.5);
3. for each such Lesson, re-verifies the effective snapshot and the effective payability through their
   integrity validators, and recomputes the **line amount** from the effective snapshot under §10.3;
4. appends one immutable `finance_statement_lines` row per Lesson, in a deterministic total order
   (`occurrence_starts_at_utc`, then `lesson_id`);
5. recomputes and stores the recorded totals (§10.3);
6. records the applied rule/policy versions (`PAYABILITY_DERIVATION_VERSION`,
   `SNAPSHOT_BOUNDARY`, the timezone policy version when the policy is set, the derivation version of
   the line set) in `rule_version`, records the timezone triple of §10.1, and writes `derivation_digest`;
7. appends the `drafted` statement event and digest-only command evidence.

A draft is a **derived artefact**, not an authority: it may be withdrawn and re-drafted, and it may not
be issued while any blocker of §10.5 stands.

### 10.3 Recorded totals and integrity

Each statement records, from its line set:

| Total | Definition |
| --- | --- |
| `total_line_count` | number of lines |
| `payable_line_count`, `payable_amount_minor` | lines whose effective disposition is `payable`, and their exact sum |
| `non_payable_line_count` | lines whose effective disposition is `non_payable` (all of them zero-amount, and all of them **visible**) |
| `pending_line_count` | lines whose effective disposition is `pending` — a non-zero value is a blocker |
| `excluded_archived_count` | Lessons excluded from drafting under §10.4 |
| `superseded_statement_id` | the statement this version supersedes, or `NULL` |

A statement line's `line_amount_minor` is **the amount this statement totals**: it equals the effective
snapshot's `derived_amount_minor` when the line's disposition is `payable`, and it is `0` otherwise. So
a `non_payable` line (including a zero-value introductory line) and a `pending` line both record
`line_amount_minor = 0` while their snapshot keeps the derived occurrence amount as a readable fact.

`payable_amount_minor` sums `payable` lines only. A `pending` line contributes nothing to the payable
total, and its presence blocks issuance under §10.5 — a pending line can never be silently totalled as
payable or as non-payable.

`FinanceStatementIntegrity::assertTotals($statementId)` recomputes every total and the
`derivation_digest` from the stored lines and compares them exactly with the recorded values. Any
inequality fails closed with `statement_totals_mismatch` or `statement_derivation_mismatch` — it never
adjusts a total, never re-sums into the record and never silently drops a line.

### 10.4 Inclusions and exclusions (legacy behaviour preserved)

- **Zero-value lines are visible.** An `introductory` Lesson that is non-payable appears as a real line
  with disposition `non_payable` and `line_amount_minor = 0`. Introductory Lessons are never hidden,
  collapsed or omitted merely because they are non-payable.
- **Distinct Lesson counting.** Each canonical Lesson counts exactly once, keyed by `lesson_id`. A
  legacy provider appointment is never the counting unit; the historical "distinct source-appointment"
  rule applies only inside a controlled migration comparison as a recorded difference reason
  (`legacy_flag_differs`), never as a silent dedupe rule inside the Platform.
- **Archive excludes from drafting only.** A Lesson whose canonical lifecycle state is `archived` is
  excluded from a **new** draft, and the exclusion is counted in `excluded_archived_count` so it is
  never invisible. Such a Lesson does not need a snapshot to be excluded (an archived, never-captured
  Lesson is simply counted out); if it already has a snapshot, that snapshot and its evaluations remain
  readable. Archive never removes an existing snapshot, evaluation or statement line, and a Lesson
  archived **after** issuance is reported as `lesson_archived_after_issue` rather than silently removed
  from the issued statement.
- **Replacement Lessons are ordinary compensable occurrences.** A `replacement` Lesson is a real
  teaching occurrence and is snapshotted, evaluated and stated like a `standard` Lesson. An academy
  obligation (Phase O) is a *student-side* remedy and is never a compensable occurrence: it is
  non-payable with basis `academy_obligation`.
- **No proration, no partial delivery arithmetic, no per-minute interpolation** exists in this phase:
  a compensation basis that would require them is deferred (§21).

### 10.5 Issuance gate

`TeacherStatementService::issue($statementId)` — capability `dzn_manage_finance_statements` — succeeds
only when **all** of the following hold, evaluated inside one transaction under the global policy
serialisation root and then the Teacher finance root in the fixed order of §15.2:

1. the statement is `draft`;
2. `FinanceStatementIntegrity::assertTotals()` passes;
3. the period has elapsed (`period_end_utc ≤ now`) and is not claimed by another live statement;
4. every non-archived Lesson in the period's finance-relevant set is finalised and has an effective
   snapshot (otherwise `lesson_not_finalised_in_period` or `snapshot_missing_for_lesson`), and every one
   of them appears in the line set; an archived member is counted in `excluded_archived_count`. A Lesson
   that belongs to the period is therefore either stated or counted as an archive exclusion — never
   silently omitted;
5. `pending_line_count = 0` (every `pending` payability was resolved by Phase O or by an audited
   override);
6. every line's effective snapshot re-verifies **and every stored line is compared, field by field, with
   the Lesson's current effective snapshot and payability evaluation** — the snapshot id, the applicable
   snapshot-correction id, the payability-evaluation id, the disposition, the basis code, the rate row
   and version, the compensation basis, the currency and the exact recomputed amount (a `payable` line
   equals the effective snapshot's amount, every other disposition is `0`). A line a later correction,
   override or re-derivation has out-covered is refused `statement_derivation_mismatch` — a declared
   §10.5 gate refusal — the draft stays `draft` with its recorded lines and totals untouched, and the
   operator withdraws and re-drafts it under §10.6 (a draft can never be re-labelled in place). A stale
   draft is therefore never issued and a corrected amount can never disagree with an issued statement
   (§0g, U-C8-BLOCK-005; §15.5);
7. all lines carry one currency, and `statement.currency` equals it;
8. the timezone triple of §10.1 is **fully and consistently recorded and still in force**:
   `period_timezone`, `period_label` and `timezone_policy_version` are all non-null, `period_timezone`
   equals the value stored on that exact policy version (a stored version is immutable in value, so a
   later successor or retraction never rewrites it), and that recorded version's `status` is `active` or
   `superseded`. A draft recorded while the policy was unset therefore cannot be issued: it is refused
   `policy_unset_for_statement_period`, the matching exception is recorded, and the operator sets the
   policy and withdraws + re-drafts under §10.6. The same refusal applies to a draft whose recorded
   version was **retracted** after drafting — a `withdrawn` version resolves its instant to unset (§6.1),
   so that draft is stale and is re-drafted rather than issued — while a *successor* recorded after
   drafting leaves the recorded zone correct and does not block issuance (§10.1). A malformed (partially
   recorded) triple is refused `statement_timezone_representation_invalid` and is never repaired in
   place;
9. no open `finance_exceptions` row with `severity = blocking` exists for this Teacher and period;
10. no other live statement of this Teacher overlaps the period;
11. no other live statement of this Teacher contains any of these `lesson_id`s
   (`lesson_stated_twice` guard).

A failed attempt writes a `refused` command result with the exact reason, ensures the corresponding
`finance_exceptions` row exists, and leaves the statement `draft`. It never partially issues, never
marks lines issued and never issues a different version than the one evaluated.

On success the statement moves `draft → issued` through **one conditional statement that both performs
the transition and stamps the one-time issuance evidence**: it compares on `state = 'draft'` and sets
`state = 'issued'`, `issued_at = <now>`, `issued_by = <actor>` and `updated_at`/`updated_by`, with its
affected-row count as the outcome. `issued_at`/`issued_by` are therefore `NULL` for every `draft` and
`withdrawn` statement, are recorded **exactly once** by this transition, and are **never updated
afterwards** — a supersession stamps only `superseded_at`/`superseded_by_statement_id` (§10.6). This is
the declared one-time issuance evidence of §13.2, permitted by the mutation limit of §13.4 rule 2 and
asserted by rule 7; a statement whose `state` and issuance evidence disagree is rejected rather than
repaired. The `issued` event is appended in the same transaction and records the same actor and instant.

### 10.6 Withdrawal and supersession

- `withdraw($statementId, $reasonCode, $evidence)` — `draft → withdrawn`, terminal, append-only event,
  lines retained. A withdrawn statement is never re-used as a version. Both transitions are single
  conditional statements; a transition attempted on a statement in any other state is refused
  `statement_state_transition_conflict` with the statement's current state, and changes nothing.
- `supersede($issuedStatementId, $reasonCode, $evidence)` — appends a **new** draft statement with
  `statement_version + 1`, `superseded_statement_id` naming the issued predecessor, and one conditional
  `issued → superseded` transition on the predecessor stamping `superseded_at` and
  `superseded_by_statement_id`. The predecessor's lines, amounts, totals and evidence never change.
- A `superseded` statement is never re-issued and never re-opened. A `withdrawn` or `superseded`
  statement is **not** deleted.

### 10.7 What "issued" means

An `issued` statement records that the academy owes the Teacher the recorded payable total for the
recorded period under the recorded authority versions. It is **not** a payment, a payment instruction,
a remittance advice, a bank instruction, a tax document, an invoice or a settled/unsettled payout
state. This phase records no payout of any kind and stores no bank, card, IBAN or tax identifier
(§22).

## 11. Reconciliation read models

Reconciliation in Phase U is read-only, exact and finding-coded. Nothing in §11 changes authority.

### 11.1 Read models

| Read model | Question it answers |
| --- | --- |
| `statementReconciliation($statementId)` | Do this statement's lines still equal a fresh derivation from canonical facts, and do its totals equal its lines? |
| `teacherRateCoverage($teacherId, $atUtc)` | Is a rate effective for this Teacher at this instant under §7.3's interval resolution — **including a rate whose row is `superseded`** — and does the recorded timeline have overlaps (`teacher_rate_timeline_overlap`) or gaps (`teacher_rate_timeline_gap`)? An instant whose winning specificity is covered only by a withdrawn row is reported as a **gap**, never as coverage by a broader-scoped row (§7.3 step 4). |
| `snapshotDebt($periodOrTeacher)` | Which finance-relevant Lessons in scope have no effective snapshot, and why? |
| `payabilityDebt($periodOrTeacher)` | Which Lessons are `pending`, and which recorded dispositions contradict their delivery facts? |
| `lessonFinanceTimeline($lessonId)` | The full, ordered Finance history of one Lesson: snapshot, corrections, evaluations, overrides, statement lines and supersessions. |
| `studentCommercialCrossCheck($termIdOrPurchaseId)` | Read-only visibility of the student-side facts (offer, obligations, settlements, evidence, funding plan, execution results) beside the Lessons they funded. It asserts nothing about student money and can never alter it. |
| `providerEvidenceCrossCheck($period)` | Are R1 payment evidence/facts, R2 collection intents and T execution results internally consistent (unmatched evidence, unconfirmed intents, attempted-without-result) — as *visibility*, never as finance authority? |

### 11.2 Runs and findings

`FinanceReconciliationService::run($periodStartUtc, $periodEndUtc, $teacherId = null)` — capability
`dzn_manage_finance_statements` — appends one `finance_reconciliation_runs` row (period, optional
Teacher, `rule_version`, counts, `findings_digest`) and one `finance_reconciliation_findings` row per
difference, each carrying: the finding code (§5.2.1), the severity (`informational`, `blocking`), the
exact Lesson/statement/snapshot/evaluation/rate ids, the exact compared values (as digests and exact
integers, never free text), and the detected instant.

Rules:

- **No tolerance.** Amounts are compared as exact integers. There is no epsilon, no rounding band, no
  "close enough" and no automatic adjustment.
- **No repair.** A run never writes a snapshot, evaluation, statement line, rate or policy. A finding is
  resolved only by an explicit human command (§12) or by the upstream authority changing and a new
  evaluation being appended.
- **No silent omission.** A Lesson that cannot be hydrated fails the run closed
  (`upstream_aggregate_invalid`) rather than being skipped; a run that cannot enumerate its scope
  reports `failed` and appends no findings it did not prove.
- **Root.** The root is selected from the command's target scope, not from its operation name (§15.1,
  §15.8): a run scoped to a Teacher takes that Teacher's finance root; a period-wide run with no Teacher
  takes the global policy root in shared mode. `resolveException` follows the same rule against the
  **target exception row**: an exception with a `teacher_id` resolves under that Teacher's finance root,
  and a teacher-less exception — a policy-command refusal, or a period-wide run's exception — resolves
  under the global policy root in shared mode. No Finance write is ever lock-free, a period-wide run or a
  teacher-less resolution still never blocks a per-Teacher command, and no command takes a root that does
  not match its target row's scope.
- A run is append-only evidence: a second run of the same period is a new run, never an edit.

### 11.3 Migration comparison (deferred, but named)

The controlled before/after comparison of MIGRATION-STRATEGY §9 — legacy teacher-payment totals versus
Platform statement totals — is **not** implemented here. What this contract fixes is the interface: such
a comparison is a reconciliation run with `legacy_flag_differs` findings, an explicit difference reason
per Lesson, and a finance sign-off; it is never a continuously synchronised parity engine and never an
Amelia importer.

## 12. Audited corrections

A correction is the **only** way an already-recorded Finance fact may acquire a different meaning. Every
correction is append-only, names its exact target row and that row's digest, and leaves the target
untouched (U-D15).

### 12.1 Correction kinds

| Kind | Target | Effect | Never does |
| --- | --- | --- | --- |
| `snapshot_correction` | one `finance_lesson_snapshots` row | appends a `finance_snapshot_corrections` row carrying the corrected rate/currency/amount, the correction sequence, the evidence and the actor; the corrected row becomes the **effective** snapshot for every later derivation | never edits the snapshot; never invalidates a stated lesson silently; never rewrites an issued statement |
| `payability_override` | one effective `finance_payability_evaluations` row | appends an override and a new evaluation (U-D10, §9.3) | never edits the derivation; never changes a delivery fact |
| `statement_supersession` | one `issued` statement | appends a superseding statement version (§10.6) | never edits the issued statement's lines, totals, currency or period |

### 12.2 Snapshot correction chain

`finance_snapshot_corrections` mirrors the Phase-O supersession idiom: per-snapshot sequence, one
applicable row per snapshot (`applicable_slot`), explicit `superseded_at`/`superseded_by_correction_id`
edge, a recorded `reason_code`, a note, the evidence channel/keyed digest/observed time, the actor, and
a `derivation_digest` over the corrected values.

Additional locked rules:

- A correction must be **complete**: it names the snapshot, the exact rate row and version it asserts,
  the **restated rate amount** (`corrected_rate_amount_minor`) alongside the corrected derived amount and
  the currency, and the **intro payability policy version in force at the snapshot
  instant**, restated on the correction row exactly as the snapshot records it
  (`intro_policy_key`/`intro_policy_version`: both recorded for an `introductory` Lesson and both `NULL`
  for every other Lesson, §13.2). A partial correction — including a half-set policy pair — is refused
  `snapshot_correction_incomplete`, and the verifier rejects a correction whose policy pair is half-set
  or disagrees with the Lesson kind of the snapshot it corrects (§13.4 rule 7). The correction row is
  insert-only, so the restated pair is immutable and the requirement is re-verifiable from the stored
  row alone.
- A correction may **not** assert a rate version whose effective interval does not contain the snapshot
  instant unless the correction also records the explicit historical-rate evidence that justifies the
  assertion; the exception is recorded on the correction row and appears in reconciliation.
- A correction never deletes, hides or downgrades the snapshot it corrects. Both rows remain readable in
  `lessonFinanceTimeline()`.
- A correction is appended with an **empty** applicable slot and becomes the snapshot's effective
  correction only through §15.4's two-statement protocol — the previous applicable correction's
  supersession statement first, this row's slot claim second — so `UNIQUE snapshot_applicable` admits
  exactly one applicable correction per snapshot and a second correction supersedes the first instead of
  colliding with it (§0g, U-C8-BLOCK-003).
- A correction that would change the totals of an `issued` statement must be paired, in the same
  authorisation, with a `statement_supersession` for every affected issued statement. A correction
  without that pairing fails closed with `statement_supersession_required`, so a difference
  can never exist between an issued statement and the authority it was issued under.

### 12.3 Correction ordering and concurrency

- Corrections take the Teacher finance root first, then the snapshot row, then the applicable
  evaluation row, then the statement row — the single declared order of §15.2.
- A correction and a statement issuance for the same Lesson serialise on the same Teacher root; the
  loser re-reads, re-verifies and either converges or refuses with an exact reason code.
- Every correction command writes digest-only evidence. A correction's replay is idempotent only after
  the existing correction row is re-verified against its target and digest.
- Corrections are never batch-applied by a script, an import, a reconciliation run or an adapter: each
  correction is one capability-gated, evidenced administrator command.

## 13. Schema 030 data model and migration

Additive only. No backfill of any domain fact, no inferred rate, no inferred snapshot, no inferred
evaluation and no inferred statement. No foreign key and no `CHECK` constraint: the repository relies
on application services and the phase verifier, exactly as every other phase does.

### 13.1 The declared table set (exactly twenty-one)

`finance_policy_roots`, `finance_teacher_roots`, `finance_policies`, `finance_policy_commands`,
`finance_teacher_rates`, `finance_teacher_rate_events`, `finance_teacher_rate_commands`,
`finance_lesson_snapshots`, `finance_snapshot_corrections`, `finance_snapshot_commands`,
`finance_payability_evaluations`, `finance_payability_overrides`, `finance_payability_commands`,
`finance_statements`, `finance_statement_lines`, `finance_statement_events`, `finance_statement_commands`,
`finance_reconciliation_runs`, `finance_reconciliation_findings`, `finance_reconciliation_commands`,
`finance_exceptions`.

Every table declares `id bigint unsigned NOT NULL AUTO_INCREMENT` with `PRIMARY KEY (id)`, runs
`ENGINE=InnoDB`, and uses the repository's `datetime` convention (UTC instants). Exactly five tables are
addressed by a stable public handle and additionally declare `uid char(26) NOT NULL` with
`UNIQUE uid (uid)` and `reference_code` with `UNIQUE reference_code` — `finance_teacher_rates`,
`finance_lesson_snapshots`, `finance_statements`, `finance_reconciliation_runs` and
`finance_exceptions`; every other declared table (`finance_teacher_roots`, `finance_policies`, the
chain, line, event and finding tables, and the six command tables) is addressed by its parent plus a
recorded sequence — or by `command_key_digest` for a command row — and declares no `uid` and no
`reference_code`. `finance_policy_roots` is the one declared table addressed by its own `root_key`
rather than by a handle or a parent (§13.2). Append-only tables declare **no**
`updated_at`/`updated_by`. Mutable tables declare exactly the mutable columns listed below and nothing
more.

### 13.2 Column specifications

`finance_policy_roots` — immutable singleton serialisation root

- `root_key varchar(32) NOT NULL`, `created_at`, `created_by`.
- `UNIQUE root_key (root_key)` — exactly one row exists, seeded by migration 030 with
  `root_key = 'finance_policy'` (§13.5); it declares **no** mutable column, no update method and no
  delete path. This is the global policy serialisation root of §15.1 and the first element of the fixed
  lock order of §15.2: taken exclusively by the mutating policy commands of §6.2 (the commands that decide
  the three payability keys and `FINANCE_STATEMENT_TIMEZONE`) and in shared mode by **two declared
  classes** of command: every policy consumer of §6.3 — which resolves a policy version and records it, so
  statement `draft`/`issue` (consuming `FINANCE_STATEMENT_TIMEZONE`), snapshot `capture` and payability
  `evaluate`/`override` — and every command that has **no Teacher of its own** — a period-wide
  reconciliation `run()` and a `resolveException()` on a teacher-less exception (§11.2, §13.2 selectors,
  §15.1–§15.2, §15.8). A teacher-scoped `run()` or `resolveException()` takes the Teacher root instead and
  no policy root, because it is not a policy consumer.

`finance_teacher_roots` — mutable root

- `teacher_id` (`bigint unsigned NOT NULL`), `created_at`, `created_by`.
- `UNIQUE teacher (teacher_id)` — one root per Teacher, the per-Teacher serialisation root of §15.1.

`finance_policies` — mutable in exactly one column (`status`), at most twice per row

- `policy_key varchar(64)`, `policy_version int unsigned`, `policy_value varchar(191) NOT NULL`,
  `value_type varchar(24) NOT NULL`, `effective_from datetime NOT NULL`, `status varchar(16)`,
  `reason_code varchar(64) NULL`, `evidence_channel varchar(32) NULL`,
  `evidence_reference_digest char(64) NULL`, `evidence_at datetime NULL`, `recorded_at`,
  `recorded_by`, `created_at`, `created_by`, `updated_at`.
- `UNIQUE policy_version (policy_key, policy_version)`,
  `UNIQUE policy_effective (policy_key, effective_from)`,
  `KEY policy_status (policy_key, status)`.
- `status` moves **at most twice per row, in one declared direction** — `active → superseded` and then
  `superseded → withdrawn`, or `active → withdrawn` directly — through the two conditional statements of
  §6.1, each with its own affected-row count and its own audit evidence. Every other column is
  insert-only, `{policy_value, value_type}` are a `NOT NULL` pair (there is no null-valued version, §6.1)
  and `effective_from` is never NULL (a version without an effective instant could never be resolved and
  is refused at insert, §6.1).
- `effective_from` must additionally satisfy §6.3's temporal admissibility rule: at the instant it is
  recorded it must be strictly later than the key's recorded consumption maximum, or the record is refused
  `policy_effective_from_precedes_recorded_consumption` (§6.2, §6.3, U-D19). A schema verifier cannot read
  a past recording instant, so it proves the equivalent **coverage invariant** over recorded facts instead
  (§13.4 rule 14) while the record-time guard itself is proved by the §18 runtime and concurrency suites.

`finance_teacher_rates` — mutable in exactly three columns (`effective_until`, `status`, `active_slot`)

- `uid`, `reference_code varchar(32) NULL`, `teacher_id`, `scope_kind varchar(16)`,
  `course_scope_id bigint unsigned`, `compensation_basis varchar(24)`,
  `amount_minor bigint unsigned`, `currency char(3)`, `effective_from datetime NOT NULL`,
  `effective_until datetime NULL`, `status varchar(16)`, `reason_code varchar(64) NULL`,
  `evidence_channel varchar(32)`, `evidence_reference_digest char(64)`, `evidence_at`,
  `rate_version int unsigned`, `active_slot tinyint unsigned NULL`, `recorded_at`, `recorded_by`,
  `created_at`, `created_by`, `updated_at`.
- `UNIQUE reference_code`, `UNIQUE scope_version (teacher_id, scope_kind, course_scope_id, rate_version)`,
  `UNIQUE teacher_scope_slot (teacher_id, scope_kind, course_scope_id, active_slot)`,
  `KEY teacher_status (teacher_id, status)`, `KEY course_scope (course_scope_id)`,
  `KEY effective (effective_from, effective_until)`.

`finance_teacher_rate_events` — append-only

- `rate_id`, `event_sequence int unsigned`, `event_type varchar(24)`, `from_status varchar(16) NULL`,
  `to_status varchar(16) NOT NULL`, `effective_until datetime NULL`, `reason_code varchar(64)`,
  `evidence_channel varchar(32)`, `evidence_reference_digest char(64)`, `evidence_at`, `occurred_at`,
  `recorded_at`, `recorded_by`, `created_at`, `created_by`.
- `UNIQUE rate_sequence (rate_id, event_sequence)`.

`finance_policy_commands`, `finance_teacher_rate_commands`, `finance_snapshot_commands`,
`finance_payability_commands`, `finance_statement_commands`, `finance_reconciliation_commands` —
digest-only, immutable

- `command_domain varchar(48)`, `operation varchar(48)`, `command_key_digest char(64)`,
  `command_payload_digest char(64)`, the aggregate selectors listed below, the typed result reference(s)
  listed below, `result_state varchar(32)`, `reason_code varchar(64) NULL`, `created_at`, `created_by`.
- Selectors, per command table: policy commands carry `policy_key`, `policy_version`, `policy_id` (the
  registry is global, so a policy command carries **no** `teacher_id`); rate commands carry `teacher_id`,
  `rate_id`, `scope_kind`, `course_scope_id`; snapshot commands carry `teacher_id`, `lesson_id`,
  `snapshot_id`, `correction_id`; payability commands carry `teacher_id`, `lesson_id`, `evaluation_id`,
  `override_id`; statement commands carry `teacher_id`, `statement_id`; reconciliation commands carry
  `teacher_id`, `run_id`, `exception_id` (the typed selector of `resolveException`, §14.1).
- **`teacher_id` nullability is declared per command table, and it is the root selector of §15.1–§15.2.**
  Every command whose subject always has a Teacher carries `teacher_id bigint unsigned NOT NULL` — rate,
  snapshot, payability and statement commands. `finance_policy_commands` carries no `teacher_id` at all
  (the policy registry is global). `finance_reconciliation_commands.teacher_id` is **`bigint unsigned
  NULL`**, because a period-wide `run()` and a `resolveException()` on a teacher-less exception have no
  Teacher (a policy-command refusal, or a period-wide run's finding/exception, records `NULL`). The
  recorded `teacher_id` therefore selects the serialisation root: `NOT NULL` ⇒ that Teacher's
  `finance_teacher_roots` row, `NULL` ⇒ the shared global `finance_policy_roots` root (§15.1–§15.2,
  §15.8), and no command may record a `teacher_id` that differs from its target row's scope (§14.1, §18).
- **Typed results only — no command table declares a polymorphic `result_id`.** A command's result is
  named by a typed column whose parent is declared in §13.3, and a refusal carries `NULL` there with its
  `reason_code` (the refused command result of §10.5): policy commands carry `result_policy_id` (parent
  `finance_policies.id`); rate commands carry `result_rate_id` (parent `finance_teacher_rates.id`);
  snapshot commands carry `result_snapshot_id` (`finance_lesson_snapshots.id`) and
  `result_correction_id` (`finance_snapshot_corrections.id`); payability commands carry
  `result_evaluation_id` (`finance_payability_evaluations.id`) and `result_override_id`
  (`finance_payability_overrides.id`); statement commands carry `result_statement_id`
  (`finance_statements.id`); reconciliation commands carry `result_run_id`
  (`finance_reconciliation_runs.id`) and `result_exception_id` (`finance_exceptions.id`, the resolved
  exception of `resolveException`, §14.1).
- Every declared selector **and every typed result reference** is the leftmost column of its own named
  index, so no declared `*_id` of a command table is left without a leading index (§13.3): `UNIQUE
  command_key_digest`,
  `KEY policy_key_operation (policy_key, operation)`,
  `KEY policy_operation (policy_id, operation)`,
  `KEY result_policy_operation (result_policy_id, operation)`,
  `KEY teacher_operation (teacher_id, operation)`,
  `KEY rate_operation (rate_id, operation)`,
  `KEY course_scope_operation (course_scope_id, operation)`,
  `KEY lesson_operation (lesson_id, operation)`,
  `KEY snapshot_operation (snapshot_id, operation)`,
  `KEY evaluation_operation (evaluation_id, operation)`,
  `KEY override_operation (override_id, operation)`,
  `KEY correction_operation (correction_id, operation)`,
  `KEY statement_operation (statement_id, operation)`,
  `KEY run_operation (run_id, operation)`,
  `KEY exception_operation (exception_id, operation)`,
  `KEY result_rate_operation (result_rate_id, operation)`,
  `KEY result_snapshot_operation (result_snapshot_id, operation)`,
  `KEY result_correction_operation (result_correction_id, operation)`,
  `KEY result_evaluation_operation (result_evaluation_id, operation)`,
  `KEY result_override_operation (result_override_id, operation)`,
  `KEY result_statement_operation (result_statement_id, operation)`,
  `KEY result_exception_operation (result_exception_id, operation)` and
  `KEY result_run_operation (result_run_id, operation)`, each declared only on the command table(s) that
  carry its column. `policy_key` and `policy_version` are value selectors rather than `*_id` references,
  so §13.3's physical index invariant does not apply to them; `policy_key` is nevertheless the leading
  column of `policy_key_operation` because the registry's duplicate and replay arbitration reads it.

`finance_lesson_snapshots` — immutable

- `uid`, `reference_code`, `lesson_id` (`UNIQUE`), `enrolment_id bigint unsigned NULL`,
  `term_id bigint unsigned NULL`, `teacher_id`, `teacher_assignment_id`, `course_id`,
  `lesson_kind varchar(16)`, `schedule_version_id`, `occurrence_starts_at_utc`,
  `occurrence_ends_at_utc`, `duration_minutes smallint unsigned`, `snapshot_instant_utc`,
  `snapshot_boundary varchar(24)`, `rate_id`, `rate_version int unsigned`, `scope_kind varchar(16)`,
  `compensation_basis varchar(24)`, `rate_amount_minor bigint unsigned`, `rate_effective_from`,
  `currency char(3)`, `derived_amount_minor bigint unsigned`, `intro_policy_key varchar(64) NULL`,
  `intro_policy_version int unsigned NULL`, `payability_disposition_at_capture varchar(16)`,
  `derivation_digest char(64)`, `rule_version varchar(48)`, `created_at`, `created_by`.
- `UNIQUE reference_code`, `UNIQUE lesson_id`, `KEY teacher_instant (teacher_id, snapshot_instant_utc)`,
  `KEY enrolment (enrolment_id)`, `KEY term (term_id)`,
  `KEY teacher_assignment (teacher_assignment_id)`, `KEY course (course_id)`, `KEY rate (rate_id)`,
  `KEY schedule_version (schedule_version_id)`,
  `KEY intro_policy (intro_policy_key, intro_policy_version)` — the declared lookup §6.3's guard reads
  when it establishes the `INTRO_PAYABILITY_POLICY` consumption maximum.

`finance_snapshot_corrections` — append-only chain

- `snapshot_id`, `lesson_id`, `correction_sequence int unsigned`, `applicable_slot tinyint unsigned NULL`,
  `corrected_rate_id`, `corrected_rate_version int unsigned`,
  `corrected_rate_amount_minor bigint unsigned`, `corrected_currency char(3)`,
  `corrected_derived_amount_minor bigint unsigned`, `intro_policy_key varchar(64) NULL`,
  `intro_policy_version int unsigned NULL`, `prior_snapshot_digest char(64)`,
  `derivation_digest char(64)`, `reason_code varchar(64)`, `note text NULL`,
  `evidence_channel varchar(32)`, `evidence_reference_digest char(64)`, `evidence_at`,
  `superseded_at datetime NULL`, `superseded_by_correction_id bigint unsigned NULL`, `recorded_at`,
  `recorded_by`, `created_at`, `created_by`.
- `UNIQUE snapshot_sequence (snapshot_id, correction_sequence)`,
  `UNIQUE snapshot_applicable (snapshot_id, applicable_slot)`, `KEY lesson (lesson_id)`,
  `KEY corrected_rate (corrected_rate_id)`, `KEY superseded_by (superseded_by_correction_id)`.
- `intro_policy_key`/`intro_policy_version` are the correction's immutable restatement of the corrected
  snapshot's **intro payability policy pair** (§12.2): both recorded for an `introductory` Lesson and
  both `NULL` for every other Lesson, insert-only, refused when half-set or when they disagree with the
  Lesson kind of the snapshot the correction names (`snapshot_correction_incomplete`, §13.4 rule 7).
  They are value columns rather than `*_id` references, so §13.3's index invariant does not apply to
  them — exactly as on `finance_lesson_snapshots`.

`finance_payability_evaluations` — append-only chain

- `lesson_id`, `evaluation_sequence int unsigned`, `applicable_slot tinyint unsigned NULL`,
  `disposition varchar(16)`, `basis_code varchar(48)`, `lesson_kind varchar(16)`,
  `delivery_outcome_id bigint unsigned NULL`, `delivery_state varchar(24) NULL`,
  `attendance_state varchar(24) NULL`, `remedy_class varchar(24) NULL`,
  `academy_obligation_id bigint unsigned NULL`, `schedule_version_id bigint unsigned NULL`,
  `snapshot_id`, `override_id bigint unsigned NULL`, `policy_key varchar(64) NULL`,
  `policy_version int unsigned NULL`, `derivation_digest char(64)`, `rule_version varchar(48)`,
  `reason_code varchar(64)`, `evidence_channel varchar(32)`, `evidence_reference_digest char(64)`,
  `evidence_at`, `superseded_at datetime NULL`, `superseded_by_evaluation_id bigint unsigned NULL`,
  `recorded_at`, `recorded_by`, `created_at`, `created_by`.
- `UNIQUE lesson_sequence (lesson_id, evaluation_sequence)`,
  `UNIQUE lesson_applicable (lesson_id, applicable_slot)`,
  `KEY lesson_disposition (lesson_id, disposition)`, `KEY snapshot (snapshot_id)`,
  `KEY delivery_outcome (delivery_outcome_id)`, `KEY academy_obligation (academy_obligation_id)`,
  `KEY schedule_version (schedule_version_id)`, `KEY override (override_id)`,
  `KEY superseded_by (superseded_by_evaluation_id)`,
  `KEY evaluation_policy (policy_key, policy_version)` — the declared lookup §6.3's guard reads when it
  establishes a payability key's consumption maximum through the evaluation's bound snapshot.

`finance_payability_overrides` — append-only

- `lesson_id`, `override_sequence int unsigned`, `prior_evaluation_id`, `prior_derivation_digest char(64)`,
  `disposition varchar(16)`, `reason_code varchar(64)`, `note text NULL`,
  `evidence_channel varchar(32)`, `evidence_reference_digest char(64)`, `evidence_at`,
  `result_evaluation_id bigint unsigned NULL`, `recorded_at`, `recorded_by`, `created_at`, `created_by`.
- `UNIQUE lesson_sequence (lesson_id, override_sequence)`.
- `KEY prior_evaluation (prior_evaluation_id)`, `KEY result_evaluation (result_evaluation_id)`.

`finance_statements` — mutable in state, the single supersession edge and the one-time issuance evidence only

- `uid`, `reference_code`, `teacher_id`, `period_start_utc`, `period_end_utc`,
  `period_timezone varchar(64) NULL`, `period_label varchar(64) NULL`, `currency char(3)`, `state varchar(16)`,
  `statement_version int unsigned`, `superseded_statement_id bigint unsigned NULL`,
  `superseded_at datetime NULL`, `superseded_by_statement_id bigint unsigned NULL`,
  `timezone_policy_version int unsigned NULL`, `rule_version varchar(48)`,
  `derivation_digest char(64)`, `total_line_count int unsigned`, `payable_line_count int unsigned`,
  `payable_amount_minor bigint unsigned`, `non_payable_line_count int unsigned`,
  `pending_line_count int unsigned`, `excluded_archived_count int unsigned`,
  `issued_at datetime NULL`, `issued_by bigint unsigned NULL`, `created_at`, `created_by`,
  `updated_at`, `updated_by`.
- `UNIQUE reference_code`,
  `UNIQUE teacher_period_version (teacher_id, period_start_utc, period_end_utc, statement_version)`,
  `KEY teacher_state (teacher_id, state)`, `KEY period (period_start_utc, period_end_utc)`,
  `KEY superseded_statement (superseded_statement_id)`, `KEY superseded_by (superseded_by_statement_id)`.
- `period_timezone`/`period_label`/`timezone_policy_version` are write-once at draft time and are
  `NULL` together or recorded together (§10.1); `assertTotals()` and the verifier reject a partial
  triple, and an `issued` statement may never carry a partial or unset triple.
- `state`, `superseded_at`, `superseded_by_statement_id` and the one-time issuance pair
  `issued_at`/`issued_by` are the only mutable columns. `issued_at`/`issued_by` are `NULL` while the
  statement is `draft` or `withdrawn`, are written **exactly once** by the conditional `draft → issued`
  transition of §10.5 — the same compare-and-swap that sets `state = 'issued'` — and are never updated
  afterwards; `superseded_at`/`superseded_by_statement_id` are written exactly once by the conditional
  `issued → superseded` transition of §10.6. The verifier rejects a statement whose `state` and issuance
  evidence disagree (§13.4 rule 7).

`finance_statement_lines` — immutable

- `statement_id`, `line_sequence int unsigned`, `lesson_id`, `snapshot_id`,
  `snapshot_correction_id bigint unsigned NULL`, `payability_evaluation_id`, `teacher_id`, `course_id`,
  `lesson_kind varchar(16)`, `occurrence_starts_at_utc`, `occurrence_ends_at_utc`,
  `duration_minutes smallint unsigned`, `delivery_state varchar(24) NULL`,
  `attendance_state varchar(24) NULL`, `remedy_class varchar(24) NULL`, `disposition varchar(16)`,
  `basis_code varchar(48)`, `rate_id`, `rate_version int unsigned`, `compensation_basis varchar(24)`,
  `rate_amount_minor bigint unsigned`, `currency char(3)`, `line_amount_minor bigint unsigned`,
  `derivation_digest char(64)`, `created_at`, `created_by`.
- `UNIQUE statement_sequence (statement_id, line_sequence)`,
  `UNIQUE statement_lesson (statement_id, lesson_id)`, `KEY snapshot (snapshot_id)`,
  `KEY lesson (lesson_id)`, `KEY statement_disposition (statement_id, disposition)`,
  `KEY snapshot_correction (snapshot_correction_id)`, `KEY evaluation (payability_evaluation_id)`,
  `KEY teacher (teacher_id)`, `KEY course (course_id)`, `KEY rate (rate_id)`.
- `line_amount_minor` is the amount the statement totals (§10.3): the effective snapshot's
  `derived_amount_minor` for a `payable` line, and `0` for every other disposition.

`finance_statement_events` — append-only

- `statement_id`, `event_sequence int unsigned`, `event_type varchar(24)`, `from_state varchar(16) NULL`,
  `to_state varchar(16) NOT NULL`, `superseded_by_statement_id bigint unsigned NULL`,
  `reason_code varchar(64)`, `evidence_channel varchar(32)`, `evidence_reference_digest char(64)`,
  `evidence_at`, `occurred_at`, `recorded_at`, `recorded_by`, `created_at`, `created_by`.
- `UNIQUE statement_sequence (statement_id, event_sequence)`.
- `KEY superseded_by (superseded_by_statement_id)`.

`finance_reconciliation_runs` — append-only

- `uid`, `reference_code`, `period_start_utc`, `period_end_utc`, `teacher_id bigint unsigned NULL`,
  `rule_version varchar(48)`, `state varchar(16)`, `failure_reason_code varchar(64) NULL`,
  `line_count int unsigned`, `matched_count int unsigned`, `mismatch_count int unsigned`,
  `unresolved_count int unsigned`, `findings_digest char(64)`, `created_at`, `created_by`.
- `UNIQUE reference_code`, `KEY teacher_period (teacher_id, period_start_utc, period_end_utc)`,
  `KEY state (state)`.

`finance_reconciliation_findings` — append-only

- `run_id`, `finding_sequence int unsigned`, `finding_code varchar(64)`, `severity varchar(16)`,
  `teacher_id bigint unsigned NULL`, `lesson_id bigint unsigned NULL`, `snapshot_id bigint unsigned NULL`,
  `evaluation_id bigint unsigned NULL`, `statement_id bigint unsigned NULL`,
  `line_id bigint unsigned NULL`, `rate_id bigint unsigned NULL`, `expected_digest char(64) NULL`,
  `observed_digest char(64) NULL`, `expected_amount_minor bigint unsigned NULL`,
  `observed_amount_minor bigint unsigned NULL`, `detected_at`, `created_at`, `created_by`.
- `UNIQUE run_sequence (run_id, finding_sequence)`, `KEY finding_code (finding_code, severity)`,
  `KEY teacher (teacher_id)`, `KEY lesson (lesson_id)`, `KEY snapshot (snapshot_id)`,
  `KEY evaluation (evaluation_id)`, `KEY statement (statement_id)`, `KEY line (line_id)`,
  `KEY rate (rate_id)`.

`finance_exceptions` — mutable state, append-only history

- `uid`, `reference_code`, `reason_code varchar(64)`, `severity varchar(16)`, `state varchar(16)`,
  `fingerprint char(64)`, `summary varchar(255)`, `safe_detail text NULL`,
  `teacher_id bigint unsigned NULL`, `lesson_id bigint unsigned NULL`,
  `statement_id bigint unsigned NULL`, `snapshot_id bigint unsigned NULL`, `detected_at`,
  `last_seen_at`, `occurrence_count int unsigned`, `resolved_at datetime NULL`,
  `resolved_by bigint unsigned NULL`, `resolution_note text NULL`, `created_at`, `created_by`,
  `updated_at`, `updated_by`.
- `UNIQUE reference_code`, `KEY fingerprint_state (fingerprint, state)`,
  `KEY reason_state (reason_code, state)`, `KEY detected_at (detected_at)`,
  `KEY teacher (teacher_id)`, `KEY lesson (lesson_id)`, `KEY statement (statement_id)`,
  `KEY snapshot (snapshot_id)`.

### 13.3 Declared parents (identity contract)

**Index invariant (exact).** Every declared `*_id` column of every declared table is
`bigint unsigned` **and** is the leftmost column of at least one declared named index of that table
(§13.2). The rule is physical, not notional: the verifier reads the table's own indexes and rejects a
table in which any declared `*_id` column — including a `superseded_by_*_id`, a `result_*_id`, a
`corrected_rate_id`, a command selector, a run/finding reference or an actor reference whose name ends
in `_id` — is not the leading column of at least one named index. The audit actor columns
`created_by`, `recorded_by`, `updated_by`, `issued_by` and `resolved_by` are deliberately **not**
`*_id` reference columns: they are actor audit columns, carry no declared parent and are not covered by
this invariant.

**Declared parent.** Every declared `*_id` column's parent must be either a declared Schema-030 table or
a frozen external authoritative parent, and for either kind the verifier proves **physically** that the
parent declares `id bigint unsigned NOT NULL AUTO_INCREMENT` with `PRIMARY KEY (id)`:

- Schema-030 parents: `finance_teacher_roots.id`, `finance_policies.id`, `finance_teacher_rates.id`,
  `finance_lesson_snapshots.id`, `finance_snapshot_corrections.id`,
  `finance_payability_evaluations.id`, `finance_payability_overrides.id`, `finance_statements.id`,
  `finance_statement_lines.id` — the declared parent of `finance_reconciliation_findings.line_id` —
  `finance_reconciliation_runs.id`, `finance_exceptions.id` — the declared parent of
  `finance_reconciliation_commands.exception_id` and of its typed result
  `finance_reconciliation_commands.result_exception_id`, so `resolveException` can name and record the
  exception it resolves (§13.2, §14.1).
- Frozen external authoritative parents: `teachers.id`, `courses.id`, `enrolments.id`, `terms.id`,
  `lessons.id`, `canonical_lesson_schedule_versions.id`, `teacher_assignments.id`,
  `canonical_lesson_delivery_outcomes.id`,
  `canonical_academy_obligations.id`, `commercial_offers.id`, `commercial_offer_obligations.id`,
  `commercial_purchases.id`, `commercial_payment_evidence.id`, `renewal_cycles.id`,
  `collection_intents.id`, `refund_review_cases.id`, `payment_execution_results.id`.

`finance_policy_commands.policy_id` and `finance_policy_commands.result_policy_id` name
`finance_policies.id` — the declared parent of both the policy version a policy command moves and the
policy version it records. `finance_policy_roots` declares no `*_id` reference column at all: its
identity is its `root_key`, so it is neither a declared parent nor in need of one.

A declared parent that does not exist, is not in one of those two sets, or does not declare the
identity above fails the verifier. **The legacy Phase-1 schedule lineage is not a Finance parent at
all**: `lesson_schedule_versions.id` does not appear in either set and may never be declared, and every
schedule reference this phase writes — `finance_lesson_snapshots.schedule_version_id` and
`finance_payability_evaluations.schedule_version_id` — must resolve to
`canonical_lesson_schedule_versions.id`. Phase N reserves `lesson_schedule_versions` for legacy
scheduling and forbids canonical Lessons from carrying a legacy scheduling projection, so allowing
Finance to bind a canonical Lesson snapshot to a legacy schedule row would break canonical-authority
isolation. A reference to the legacy table fails the verifier and fails closed at read time
(§13.4 rule 3).

### 13.4 Verifier rules

`verify_finance_payability_rate_statement_schema()` runs after migration 030, on current-schema
verification and unconditionally before the schema option may advance to 30 — the same three call
sites every other phase verifier uses — and rejects:

1. a missing declared table; a created table outside the declared set; a non-InnoDB table; a table
   without `id bigint unsigned NOT NULL AUTO_INCREMENT` and `PRIMARY KEY (id)`; a `uid`/`reference_code`
   column on a table outside the five declared handle tables of §13.1, or a missing one on those five;
2. `updated_at`/`updated_by` on any append-only table, and any mutable column beyond the three declared
   for `finance_teacher_rates` (`effective_until`, `status`, `active_slot`, §7.2), the single declared
   `status` column of `finance_policies` (§6.1), the state/supersession edge **and the one-time
   issuance-evidence pair** declared for `finance_statements` (`state`, `superseded_at`,
   `superseded_by_statement_id`, `issued_at`, `issued_by`, §10.5–§10.6) and the declared state columns of
   `finance_exceptions`; `finance_policy_roots` declares no mutable column at all, so a second column on
   it — or any mutable column on any of the six command tables of §13.2 — is rejected;
3. a missing declared uniqueness or lookup index; a `*_id` that is not `bigint unsigned`; a `*_id` that
   is not the leftmost column of at least one declared named index (§13.3); a polymorphic `result_id`
   column, or a declared command table missing one of its typed `result_*_id` references (§13.2) — a
   `finance_policy_commands` row without the typed `result_policy_id`, or a
   `finance_reconciliation_commands` row without both the `exception_id` selector and the typed
   `result_exception_id`, is exactly such a missing reference; or a parent outside §13.3 — including any
   column that names the legacy `lesson_schedule_versions` table;
4. a `char(64)` digest column that is nullable when it is not one of the declared optional digests
   (`evidence_reference_digest`, `prior_snapshot_digest`, `prior_derivation_digest`, `expected_digest`,
   `observed_digest`), or that is declared nullable-safe but malformed in length or character set;
5. a column outside the declared set whose name ends in `_reference` or `_ref` (only the declared
   `reference_code` and `*_reference_digest` forms may exist), and any column matching `%card%`,
   `%iban%`, `%pan%`, `%cvc%`, `%bank%`, `%account_number%`, `%tax%`, `%vat%`, `%payout%`,
   `%invoice%`, `%journal%`, `%ledger%` or `%plaintext%`; declared fields ending in `derivation_digest` are
   explicitly exempt because the word `derivation` incidentally contains the substring `vat`;
6. any smuggled table (`finance_lessons`, `finance_terms`, `finance_attendance`,
   `finance_notifications`, `finance_payments`, `finance_ledger`, `finance_invoices`,
   `finance_payouts`, `finance_prices`);
7. a second live statement for one Teacher whose period overlaps another live period, a statement whose
   `pending_line_count` is non-zero while `state = 'issued'`, a statement whose timezone triple is
   partially recorded, an `issued` statement whose timezone triple is not fully recorded, a statement
   whose `state` and issuance evidence disagree (`issued`/`superseded` with a `NULL` `issued_at` or
   `issued_by`, or `draft`/`withdrawn` with either recorded), a line referencing a snapshot and a
   `snapshot_correction_id` that do not belong to the same Lesson, a correction whose
   `intro_policy_key`/`intro_policy_version` pair is half-set or disagrees with the Lesson kind of the
   snapshot it corrects, an applicable evaluation whose `snapshot_id` does not equal the line's snapshot,
   and an evaluated Lesson with two applicable rows;
8. a rate scope violation (`teacher` with non-zero `course_scope_id`, `teacher_course` with zero), a
   non-`active` row carrying `active_slot = 1`, two active rows for one scope, a withdrawn row carrying
   `active_slot = 1` or an open `effective_until`, or an overlap of two effective intervals inside one
   scope;
9. a `finance_policies` row whose `policy_key` is outside `FinanceRule::POLICY_KEYS`, whose `status` is
   outside `active`/`superseded`/`withdrawn`, whose `effective_from` is NULL, whose `policy_value` or
   `value_type` is NULL (a null-valued version is not a legal unset representation, §6.1), whose
   `policy_version` is not strictly increasing for its key, or that shares an `effective_from` with
   another row of the same key;
10. a departure of any `finance_*` table from the declared set of §13.1, and — by re-running them —
    any change to a `commercial_*`, `recurring_*`, `payment_*`, `canonical_*`, `lessons`, `terms` or
    `enrolments` table, so "the phase added no column to any existing table" is asserted rather than
    assumed;
11. a reason code outside the set declared for its column (§5.2.1): a `finance_exceptions.reason_code`
    outside `FinanceRule::EXCEPTION_REASON_CODES`, a `finance_reconciliation_findings.finding_code`
    outside `FinanceRule::FINDING_CODES`, or any `reason_code`/`failure_reason_code` outside
    `FinanceRule::REASON_CODES`;
12. a Finance write path outside the declared allowlist: any write to a table other than the twenty-one
    declared tables of §13.1 and the two declared infrastructure seams of §15.7
    (`platform_audit_events`, `platform_outbox`), and any audit or outbox write outside §15.7's contract —
    an update or a delete of either table, a row without its keyed digest, an outbox intent outside the
    three declared intents of §17, a row not written in the transaction of the Finance row it evidences (a
    business refusal's rows are written in the §15.8 refusal-evidence transaction instead, §15.8),
    or an outbox row outside the **declared insert shape** of §15.7 — the seam's own
    `aggregate_type`/`aggregate_id`/`event_type` identity columns plus only the seam's mandatory metadata
    (the derived `idempotency_key`, `status = 'pending'`, `available_at` and `created_at` at the transition
    instant, `attempt_count = 0`, and the explicit `NULL` lease and legacy-identity columns) —
    and any outbox row carrying a Finance business fact: no amount, period bound, currency, reason or
    finding code, entity id beyond the row's own `aggregate_id`, template, recipient, channel address,
    message body, raw key or command digest, and no payload column added to the table, which migration 030
    never alters (§15.7, §17). A seam-compatible insert that supplies **exactly** that declared shape is
    valid and is never rejected for writing the seam's own required metadata (U-C7-BLOCK-001). The verifier
    asserts the allowlist as declared data, and the §18 contract suite proves the write paths themselves by
    source scan over
    `src/Core/Application/Finance/**` and `src/Core/Infrastructure/Repository/Finance*Repository.php`,
    because a schema verifier cannot read a write path.
13. a global policy root that does not exist exactly once: a missing `finance_policy_roots` table, a
    table holding zero rows or more than one row, a row whose `root_key` is not `finance_policy`, or a
    root table declaring any column beyond `id`, `root_key`, `created_at` and `created_by`. The root is
    created by migration 030 and is never deleted (§13.5), so a policy command can never run without it.
14. a policy version that has stolen the coverage of an already-recorded dependent Finance fact, which is
    §6.3's admissibility rule expressed as a state invariant the verifier can read: for **every** recorded
    dependent Finance fact of every key — every `finance_lesson_snapshots` row recording an
    `intro_policy_key`/`intro_policy_version` pair and every `finance_snapshot_corrections` row restating
    one, every `finance_payability_evaluations` row recording a `policy_key`/`policy_version` pair, and
    every `finance_statements` row recording a `timezone_policy_version` — the recorded version must exist
    for that key, must have an `effective_from` not later than the fact's policy-relevant instant (§6.3),
    and **no other version of the same key may carry an `effective_from` inside the half-open interval
    from the recorded version's `effective_from` (exclusive) to that fact's policy-relevant instant
    (inclusive)**. A version inside that interval would be the version resolution returns for the fact's
    instant, so the immutable fact could no longer prove that the version it recorded covered its own
    instant. `status` is deliberately not part of this invariant: it is a live/retraction marker, so a
    withdrawn version still satisfies it, while a back-dated version that out-covers a recorded fact fails
    the verifier (§6.3, §12, §18, U-D19).

### 13.5 Migration rules

- `030_finance_payability_rate_statement_authority` creates exactly the twenty-one tables of §13.1 with
  `dbDelta`, performs no `ALTER` on any existing table (including the two pre-existing infrastructure
  tables of §15.7, which it never touches), no backfill, no inference of any domain fact, no statement,
  no snapshot, no evaluation and no rate.
- **The one declared seed.** The installer writes exactly three `finance_policies` rows — version 1 of
  `INTRO_PAYABILITY_POLICY` (`non_payable`), `STUDENT_NO_SHOW_COMPENSATION_POLICY` (`payable`) and
  `INTERRUPTION_COMPENSATION_POLICY` (`payable`) — each with `status = 'active'`, `value_type`
  declaring its value, `effective_from = the migration instant` (never NULL, §13.2),
  `reason_code = 'phase_u_declared_default'` and evidence channel `platform_default`, so each of those
  three keys resolves from the migration instant onward under §6.1.
  This is configuration this contract declares, not an inference from data; leaving them unset would
  make payability undefined for every Lesson and force an operator to guess.
  `FINANCE_STATEMENT_TIMEZONE` is deliberately **not** seeded: **no `finance_policies` row for that key
  exists at migration time**, and its unset state is the absence of a covering version rather than a
  null-valued row (§6.1, §10.1). The verifier rejects any fourth seeded row, any seed inside a different
  policy version and any `finance_policies` row whose `policy_value`/`value_type` pair is not fully
  recorded.
  **"Seeded" means authored by the migration.** The verifier's seed assertions are asserted of the
  migration's own rows — the three declared keys' version 1, authored by the migration rather than an
  administrator — and never of recorded history: a version an operator records through §6.2's commands,
  including the statement timezone policy `record()` is the declared way to set (§10.5), is ordinary
  recorded history that no seed rule may refuse, so the verifier can be re-run on a live installation
  (§0g).
- **The one declared structural row.** The installer additionally writes exactly one
  `finance_policy_roots` row, `root_key = 'finance_policy'` (never NULL, §13.2), inserted with
  insert-or-resolve semantics on `UNIQUE root_key` so a repeat migration converges on the existing row.
  It is the global policy serialisation root of §15.1 — a structural arbitration row, **not** domain
  data, not a policy and not a configuration value — and the verifier rejects a missing root row, a
  second root row, a root row with any other `root_key`, or a `finance_policy_roots` table that declares
  a mutable column. No other structural row, and no other seeded row of any kind, is written by
  migration 030.
- The migration is repeat-safe from Schema 27, 28 or 29 and leaves every existing row unchanged. The
  Phase-T `028`/`029` verifiers, the R1/R2 verifiers and every canonical verifier are re-run, not
  duplicated, and a repaired installation never re-applies a completed migration.
- A completed-030 installation whose Finance table has vanished fails closed and is never silently
  repaired; the repair path is the scheduled migration itself, which is never added to an
  already-completed migration identifier.

### 13.6 Repository discipline

- Append-only tables expose insert and named-index read methods only: **no** update method, **no**
  delete method, **no** truncate, and no bulk write.
- `finance_policy_roots` exposes an insert-or-resolve on `UNIQUE root_key` and a named-key read only: no
  update method, no delete method, no truncate and no second row. A policy command resolves it before its
  first policy write, and the resolved row is the row that command locks first (§15.2).
- `finance_policy_commands` is append-only: insert plus named-index reads only (by `command_key_digest`,
  by `policy_key`/operation, by `policy_id`/operation and by the typed `result_policy_id`/operation),
  with no update, no delete and no bulk write, exactly like the other five command tables.
- `finance_policies` exposes insert and named-index reads plus exactly the two conditional status
  statements of §6.1 (`active → superseded`, `active|superseded → withdrawn`), each a single
  compare-and-swap whose affected-row count is the outcome. It exposes **no** method that can touch
  `policy_key`, `policy_version`, `policy_value`, `value_type`, `effective_from`, the evidence columns
  or the audit columns, and no method that can delete or re-use a version. A row may carry **both** moves
  (`active → superseded → withdrawn`), each as its own compare-and-swap with its own affected-row count
  and its own audit evidence; no method can move a `withdrawn` row again or return a row to `active`. The
  registry is therefore immutable in value while its single live-marker column stays auditable.
- `finance_teacher_rates` exposes exactly the conditional statements of §7.2 — the single
  `effective_until` closure and the at-most-two status moves that clear `active_slot` — and no method
  that can rewrite an identity, amount, currency or `effective_from`.
- `finance_statements` exposes the conditional `draft → issued` (which also stamps `issued_at`/`issued_by`
  exactly once), `draft → withdrawn` and `issued → superseded` (which stamps
  `superseded_at`/`superseded_by_statement_id`) transitions, each a single compare-and-swap whose
  affected-row count is the outcome, and no method that can touch a total, a currency, a period, a line,
  the issuance evidence once it is recorded, or a recorded timezone triple.
- `finance_payability_evaluations` and `finance_snapshot_corrections` expose one conditional
  supersession statement each (the Phase-O idiom), never an unconditional update.
- `finance_exceptions` is the only Finance table whose state may move freely; every transition stamps
  `updated_at`/`updated_by` and its history is reconstructible from `platform_audit_events` and the
  finding/run records.
- The only two tables any Finance code path writes outside the declared set of §13.1 are the declared
  infrastructure seams of §15.7 — `platform_audit_events` and `platform_outbox` — and both are written
  insert-only, inside the transaction of the Finance row they evidence (a business refusal's rows are
  written in the §15.8 refusal-evidence transaction, which commits no Finance mutation), with no update,
  no delete and no reuse. A Finance class that writes any other non-Finance table is a defect, and the
  §18 source scan proves it cannot happen.

## 14. Services, capabilities, read models, diagnostics

### 14.1 Services

| Surface | Capability | Notes |
| --- | --- | --- |
| `FinancePolicyService::record`, `supersede`, `withdraw`, `resolve` | `dzn_manage_finance_policies` | §6.2–§6.3; `record` refuses a structural or fifth key, a duplicate or non-consecutive `(key, version)`, a missing `effective_from`, an absent `policy_value`/`value_type`, a non-monotonic `effective_from` **and every instant inadmissible under §6.3's temporal admissibility rule** (`policy_effective_from_precedes_recorded_consumption`, §6.3, U-D19); `supersede` and `withdraw` are the two single conditional status moves of §6.1 (one row may carry both, in that order) and neither makes a coverage decision of its own; each mutating operation takes the global policy root `finance_policy_roots` **exclusively**, reads the key's recorded consumption maximum inside that transaction and appends one `finance_policy_commands` row with the typed `result_policy_id`; every one of these refusals is a business refusal whose refused command row, teacher-less `finance_exceptions` row and digest-only audit evidence are committed by the §15.8 refusal-evidence transaction with the attempted mutation rolled back; `resolve` is §6.1's interval-covering read, takes no lock, writes no command row, and never returns a null-valued version because none can be written |
| `TeacherRateService::record`, `close`, `withdraw`, `resolveFor`, `timeline` | `dzn_manage_teacher_rates` | §7; `record` proves scope, currency, non-overlap and `rate_effective_from_precedes_snapshot`; `close` writes `effective_until` at most once; `withdraw` is the terminal status move (a `superseded` row may be withdrawn) and refuses while a snapshot references the row; `resolveFor` is the single ordered algorithm of §7.3, which detects withdrawn coverage at the winning specificity before any scope fallback |
| `LessonFinanceSnapshotService::capture` | `dzn_manage_finance_statements` | §8; deterministic, idempotent, `UNIQUE lesson_id` convergence, digest re-verification on replay, no row when a blocker exists; takes the global policy root **shared** and then the Teacher root in the fixed order of §15.2, because it resolves and records a policy version (§6.3) |
| `LessonPayabilityService::evaluate`, `override` | `dzn_manage_lesson_payability` | §9; append-only chain, one applicable row, conditional supersession, audited override naming its predecessor; a policy consumer like `capture`, so both operations take the global policy root shared and then the Teacher root in the fixed order of §15.2 (§6.3) |
| `TeacherStatementService::draft`, `assertTotals`, `issue`, `withdraw`, `supersede` | `dzn_manage_finance_statements` | §10; `draft` and `issue` take the global policy root and then the Teacher root in the fixed order of §15.2, the issuance gate of §10.5 is evaluated inside that one transaction, and the conditional `draft → issued` transition stamps `issued_at`/`issued_by` exactly once; the drafting instant `draft` records is the timezone key's policy-relevant instant for §6.3 |
| `FinanceCorrectionService::correctSnapshot`, `overridePayability`, `supersedeStatement` | `dzn_manage_finance_statements` (snapshot/statement) or `dzn_manage_lesson_payability` (override) | §12; each correction names its exact target and digest, and a snapshot correction that changes an issued statement must be paired with that statement's supersession |
| `FinanceReconciliationService::run`, `findings`, `resolveException` | `dzn_manage_finance_statements` | §11; append-only runs/findings, no repair, exact-integer comparison; `resolveException` moves only a `finance_exceptions` row, names it with the typed `exception_id` selector and records the typed `result_exception_id`, and appends its digest-only `finance_reconciliation_commands` row; it resolves no policy version, so its root is selected from the **target exception** — a teacher-scoped exception takes that Teacher's finance root, a teacher-less exception (`teacher_id = NULL`, including a policy-command refusal) takes the shared global policy root — and it never takes both (§13.2, §15.1–§15.3, §15.8) |
| `TeacherRateReadService`, `LessonFinanceReadService`, `TeacherStatementReadService`, `FinanceReconciliationReadService` | `dzn_view_finance_authority` | PII-minimised, digest-only, fail closed on a malformed aggregate; no raw command digest, no evidence payload, no upstream provider reference. These are also the **Phase-S hydration surface** of §17: an intent's outbox row names a declared aggregate, and Phase S reads the permitted Finance facts for that aggregate through this read layer and never from the outbox row itself |

### 14.2 Capabilities

Exactly five new capabilities, administrator-only:
`dzn_manage_finance_policies`, `dzn_manage_teacher_rates`, `dzn_manage_lesson_payability`,
`dzn_manage_finance_statements`, `dzn_view_finance_authority`.

- `Migrator` gains `CAPABILITY_OPTION_U = 'dzn_platform_capability_version_2a2u'` /
  `CAPABILITY_VERSION_U = '2a2u'`, repaired **per capability** exactly like Phases O–T: each missing
  grant is added and the marker updated on any repair, and a fresh installation never fails closed on a
  partially granted state.
- All five are **removed** from `dzn_teacher` and from any student role, and the verifier refuses an
  installation in which a Teacher or Student role holds a Finance capability (U-D18). Phase U grants no
  Teacher-side finance surface of any kind.
- No front-end, REST or public route may be capability-gated for Finance: Phase U exposes **no** route.
  Administrator screens under the existing Platform menu are the only surface.

### 14.3 Diagnostics

Diagnostics report counts and states only, never a payload, an evidence value, a raw key or a
provider reference:

- rates by scope kind and status, and effective-timeline coverage per Teacher (effective, gap,
  overlap);
- lessons without a snapshot in a period (`snapshotDebt`), grouped by exception reason code;
- payability dispositions by basis code, `pending` by age, and overrides by reason code;
- statements by state, version and period, with `pending_line_count` and `excluded_archived_count`;
- reconciliation runs by state and finding codes by severity and age;
- Finance exceptions by reason code, severity and state;
- refused commands by operation and reason code, and conflicting replays.

## 15. Concurrency, idempotency and serialisation

### 15.1 Serialisation roots

Phase U declares **two** serialisation roots, and every Finance write takes one of them first, so no
Finance write is ever lock-free and both the digest/replay arbitration of §15.3 and this lock rule are
universal:

- The **global policy root** `finance_policy_roots` is the single immutable row seeded by migration 030
  (`root_key = 'finance_policy'`, §13.2, §13.5). It exists because a `finance_policies` row has no
  `teacher_id`, so a per-Teacher root cannot serialise the policy registry. The root plays **three
  declared roles**, which must never be conflated:
  1. **The policy registry's own root.** The mutating policy commands of §6.2 (`record`, `supersede`,
     `withdraw`) take it **exclusively** (`FOR UPDATE`) and hold it for the whole transaction, so two
     competing versions of one key can never interleave and §6.3's guard reads the key's recorded
     consumption maximum against a state no consumer can still be changing.
  2. **The root of every command that resolves and records a policy version.** `TeacherStatementService::draft()`
     and `issue()` (§10.1, §10.5, the commands that decide or consume `FINANCE_STATEMENT_TIMEZONE`),
     `LessonFinanceSnapshotService::capture()` (§8.2) and `LessonPayabilityService::evaluate()`/`override()`
     (§9.2–§9.3) each resolve a policy version and record the resolution on the row they write, so each
     takes the root **shared** (`LOCK IN SHARE MODE` / `FOR SHARE`) and holds it for the whole transaction;
     a policy mutation is thereby totally ordered against every in-flight or later holder.
  3. **The fallback root of a command with no Teacher of its own.** A period-wide reconciliation `run()`
     and a `resolveException()` whose target exception carries `teacher_id = NULL` take the root **shared**,
     because §15.1 requires every Finance write to take one root first and such a command has no
     per-Teacher root to take (§11.2, §13.2 selectors). This is a *scope* role, not a policy-consumption
     role: neither operation resolves a policy version, and a Teacher-scoped `run()` or `resolveException()`
     takes that Teacher's root instead and no policy root at all.
  The root is therefore selected from the **target row's own scope and the command's policy consumption**,
  never from the operation name alone; two shared holders never block each other and two command passes for
  two different Teachers still never contend.
- The **per-Teacher finance root** `finance_teacher_roots` (mirroring `commercial_account_roots` for
  Students) is created lazily by the first Finance command for that Teacher through an insert-or-resolve
  on `UNIQUE teacher_id` and is never deleted. Every other Finance write takes that row first, so all
  other Finance operations for one Teacher serialise and two Teachers never contend on it.

A command that takes the global policy root takes it **first**, before the per-Teacher root. No command
ever takes it after a Teacher-scoped row, and no command ever takes one Teacher's root while holding
another's.

**One root, chosen from the target's scope.** Every Finance command that commits a Finance row takes
exactly one of the two roots **first**, and which one is a function of the row it acts on, never of its
operation name:

- a command whose target row carries a `teacher_id` (a rate, snapshot, correction, evaluation, override,
  statement, line, event or **teacher-scoped** exception) takes that Teacher's `finance_teacher_roots` row,
  and adds the global policy root **shared and before it** only if the command is also a policy consumer of
  role 2 above (§6.3);
- a command whose target row has **`teacher_id = NULL`** (a policy mutation, a policy-command refusal's
  exception, a period-wide reconciliation run and its findings, a `resolveException` on a teacher-less
  exception) takes the global policy root `finance_policy_roots` **shared** — exclusively only for the
  policy mutations of role 1 — and takes no Teacher root at all.

A command may therefore never take both a Teacher root and a *different* Teacher's root, and a teacherless
command may never invent a Teacher root to lock (§6.3, §11.2, §13.2, §14.1, §15.2, §18).

### 15.2 Fixed lock order

When the global policy root is taken at all it is taken **first**, so the complete fixed order is:

`finance_policy_roots` (the global policy root, the first element whenever a command needs it) →
`finance_teacher_roots` → `finance_teacher_rates` (the rate row(s) being resolved, closed or whose
successor is inserted, ascending `id`) → `finance_lesson_snapshots` → `finance_snapshot_corrections` →
`finance_payability_evaluations` → `finance_statements` → `finance_statement_lines` (insert order).

The order is never inverted: a command that takes the global policy root takes it before any
Teacher-scoped row, and a command that needs both a rate row and a statement row takes the rate row
first. Phase U takes **no** Phase-L/M/N/O/P/Q/R1/R2/T lock, holds no capacity lock, and mutates no row
outside its own declared tables and the two declared infrastructure seams of §15.7; upstream aggregates
are read and validated through their owning validators and fail closed when corrupt.

**Policy change versus its consumers (explicit).** `draft`, `issue`, snapshot `capture` and payability
`evaluate`/`override` hold the global policy root and a mutating policy command holds the same root, so a
mutation and any of them are totally ordered: a consumer never observes a policy state that a concurrent
mutation is only half way through writing, never records a mixture of two policy states, and — because the
mutation's §6.3 guard is read inside its own exclusive transaction — a mutation can never be admitted
against a consumption maximum that an in-flight consumer has already invalidated, nor leave a consumer
unable to prove that its recorded version covered its own instant. The same root is also the **scope**
fallback for a **teacher-less** reconciliation `run` or `resolveException` (§15.1 role 3), which takes it
in shared mode because it has no Teacher root to take; a **Teacher-scoped** `run` or `resolveException`
takes that Teacher's `finance_teacher_roots` row instead and no policy root at all, because it resolves no
policy version and is not a policy consumer.

| Which commits first | Declared outcome |
| --- | --- |
| the policy mutation | the draft records the post-change resolution — the successor's zone with the successor's `timezone_policy_version`, or the all-`NULL` unset triple when the mutation was a first record or a retraction |
| the statement draft | the draft records the pre-change resolution; the recorded triple is a durable fact that is never re-labelled in place (U-D7), so a later **retraction** of the recorded version makes that draft stale and issuance refuses `policy_unset_for_statement_period` (§10.5 #8), while a later *successor* leaves the recorded zone correct and does not block issuance |
| the policy mutation, before a snapshot capture or a payability evaluation | the capture or evaluation resolves its own recorded instant against the post-change version set: an instant at or after the new `effective_from` records the new version and an earlier instant records its predecessor, and the pair recorded on the fact is always the version that covers the fact's own instant (§6.1, §6.3) |
| the snapshot capture or payability evaluation | the mutation's §6.3 guard reads that fact's instant and refuses `policy_effective_from_precedes_recorded_consumption` for any `effective_from` that is not strictly later than it — one refusal, no version written and no predecessor superseded — so the recorded pair is never out-covered after the fact (§6.3, §13.4 rule 14) |
| the policy **retraction** (`withdraw`) | nothing is rewritten: the recorded pair or triple stays exactly as recorded (U-D7), an affected draft is refused at issuance with `policy_unset_for_statement_period`, an affected re-derivation is refused `finance_policy_unset`, and any actual change of a recorded consequence goes through the audited corrections of §12 (§6.3) |

**Why the shared mode matters.** The global policy root is taken exclusively by a policy mutation and in
shared mode by every policy consumer of §6.3 — `draft`/`issue`, snapshot `capture` and payability
`evaluate`/`override` — so a mutation waits for every in-flight consumer pass and blocks every later one,
while two consumer passes never block each other. Two Teachers therefore still never contend, and the
root's only cost is the ordering of a policy change against the consumers it could invalidate — which is
exactly what it is for. An exclusive acquisition after a shared one, or an acquisition after any
Teacher-scoped row, is not a refusal path but a **defect**: no Finance code path may upgrade the shared
lock, and the §18 contract suite proves the declared call order by source scan.

**Every policy consumer takes the shared root — and why that is the correction, not the cost.** Snapshot
capture and payability evaluation previously took only the per-Teacher root, on the reasoning that a
version's value is immutable, that resolution covers a recorded instant rather than a live row, and that
the recorded version is a durable fact a later successor or retraction never rewrites (U-D7). That
reasoning is right about the value and was wrong about the **coverage**: a policy write could still be
recorded after the fact, with an `effective_from` inside the consumed interval, and change what the
immutable snapshot, evaluation or draft resolved to at its own instant. Capture and evaluation therefore
take the same shared root as `draft`/`issue` and hold it for their whole transaction, so §6.3's guard and
the version insert are ordered against every consumer (§6.3, U-D19). The concurrency cost is nil: shared
holders never block each other, so two captures, two evaluations or two drafts for two Teachers still
never contend, and the only ordering this introduces is the one the invariant needs — a policy mutation
against the consumers it could invalidate.

### 15.3 Command idempotency

Every Finance command follows the established arbitration: a keyed `command_key_digest` plus a
`command_payload_digest`, a named-index duplicate check, replay-versus-conflict discrimination, and an
idempotent replay only after the authoritative aggregate and the recorded result row are re-verified.
An identical replay converges; a materially different replay is refused with the operation's exact
conflict code and preserves the original record. Every mutating Finance command has a declared command
table of its own (§13.2) — including the three policy commands of §6.2, which record their typed
`result_policy_id` and their `policy_key`/`policy_version`/`policy_id` selectors — so no Finance command
is arbitrated without a durable result row. For the global policy registry the serialisation root of
§15.1 is what makes this arbitration decisive rather than merely advisory: two competing versions of one
key can never slip between each other's duplicate check and write, because only one of them holds the
global policy root at a time.

**`result_state` is declared, never implied.** Every command table's `result_state` column is `NOT NULL`
and the command's *outcome* is exactly one of its declared members (`FinanceRule::COMMAND_RESULT_STATES`):
the one declared success state of the operation the row records
(`FinanceRule::COMMAND_SUCCESS_STATES` — `recorded`, `completed`, `closed`, `issued`, `superseded` or
`withdrawn`), the declared `failed` state of a reconciliation run that could not hydrate its scope, or
the one declared refusal state `refused` with a `NULL` typed result and the exact `reason_code` (§15.8).
A row that omits its state, or a refusal that keeps the attempt's success state, is not a recordable
command outcome: the insert fails closed and the command rolls back (§0g, U-C8-BLOCK-001).

**The typed result shape is declared per operation, never inferred from the row.** A replay may converge
only on the *exact* typed result shape its own operation records, so one declaration names, per command
table, the typed `result_*` columns that table carries (`FinanceRule::COMMAND_RESULT_COLUMNS`) and, per
command table and operation, the typed results that operation must record
(`FinanceRule::COMMAND_OPERATION_RESULTS`): `record`/`supersede`/`withdraw` a policy `result_policy_id`, a
rate `record`/`close`/`withdraw` a `result_rate_id`, a snapshot `capture` a `result_snapshot_id`, a
`correct_snapshot` a `result_snapshot_id` **and** a `result_correction_id`, an `evaluate` a
`result_evaluation_id`, an `override` a `result_evaluation_id` **and** a `result_override_id`, every
statement operation a `result_statement_id`, a reconciliation `run` a `result_run_id`, and a
`resolve_exception` a `result_exception_id`. `FinanceSupport::assertReplayResultShape()` requires every
declared typed result of the operation to be present and every other typed result column of that same
table to be `NULL` — so a corrupted command row can never smuggle a second, unrelated typed result (a
substituted override, correction, run or exception id) into a successful replay of an operation that
never records it, and the replayed operation must also re-prove every **required cross-link** of the
typed result it names (an override's own row must name the evaluation it produced and the prior
evaluation the command recorded; a correction must name the snapshot the command recorded as its
result; a run and an exception resolution must each name the row their own selector recorded). Where the
operation's payload is a pure function of the typed result row's own facts — the snapshot `capture`, the
correction `correct_snapshot`, the policy `record`, the rate `record`/`close`/`withdraw`, the payability
`evaluate`/`override`, the statement `draft`/`issue`/`withdraw`/`supersede`, and the reconciliation
`run`/`resolve_exception` — the re-loaded result must reproduce the recorded `command_payload_digest`
exactly
(`FinanceSupport::assertReplayPayload()`), so an id that names a different but self-consistent row of the
same aggregate and reason fails closed `command_replay_conflict` instead of converging on the
substitution (§0h–§0j). For the correction `correct_snapshot` the canonical payload facts are the
**complete material correction fact set** — its Lesson and snapshot, its corrected rate row, version and
restated rate amount, its corrected derived amount and currency, and its operator reason
(`FinanceCorrectionService::correctionFacts()`) — so a second self-consistent correction of the same
Lesson, snapshot and reason that moves *any* of those facts, including the corrected rate amount alone, is
refused `command_replay_conflict` (§0j).

### 15.4 Conditional supersession

Each append-only chain has exactly one applicable slot (`applicable_slot`, `UNIQUE <parent>_applicable`)
and exactly one conditional supersession statement:

```text
UPDATE <chain table>
   SET superseded_at = <now>, superseded_by_<child>_id = <new id>, applicable_slot = NULL
WHERE <parent id> = ? AND applicable_slot = 1 AND superseded_by_<child>_id IS NULL
```

**The slot is claimed in a declared order, and the order is part of the contract.** Because the declared
uniqueness index admits exactly one non-NULL `applicable_slot` per parent, an appended row cannot carry
`applicable_slot = 1` while its predecessor still holds the slot. One appended row therefore moves through
three statements, all inside the command's single transaction:

1. the successor is inserted with `applicable_slot = NULL` and no supersession edge;
2. the statement above releases the predecessor — `superseded_at`, `superseded_by_<child>_id` and
   `applicable_slot = NULL` — and its affected-row count must be `1`;
3. a second conditional statement claims the slot for the successor —
   `UPDATE <chain table> SET applicable_slot = 1 WHERE id = ? AND applicable_slot IS NULL AND
   superseded_by_<child>_id IS NULL` — and its affected-row count must be `1`;

so exactly one applicable row exists before the command and exactly one after it, and no reader can ever
observe a committed chain with zero or two applicable rows. The affected-row counts are the only proof of
ownership: a second concurrent appender affects `0` rows, writes nothing and converges on the winner's
row; a chain with an applicable row but no live edge is an integrity fault and fails closed
(§0g, U-C8-BLOCK-003).

### 15.5 Statement concurrency

- `draft` takes the global policy root (shared) and then the Teacher root in the fixed order of §15.2,
  then proves period non-overlap against every live statement of that Teacher and Lesson uniqueness
  across those statements.
- `issue` re-reads the draft, re-verifies every line's snapshot and evaluation, re-runs
  `assertTotals()`, and performs the single conditional `draft → issued` transition; a loser affects
  `0` rows and reports the current state.
- A statement may not be issued while a snapshot correction or override for one of its Lessons is
  mid-flight: both take the same root, and the loser re-reads and either re-derives the draft
  (if still `draft`) or refuses with `statement_supersession_required` (if already `issued`).
- A draft whose lines no longer match the current effective snapshot and payability evaluation is
  **stale**, never re-labelled in place: `issue` refuses it `statement_derivation_mismatch` (a §10.5 gate
  refusal, recorded as a business refusal with its blocking exception), the draft keeps its recorded lines
  and totals, and the operator withdraws it and re-drafts under §10.6 (§10.5 rule 6, §0g,
  U-C8-BLOCK-005).
- `lesson_stated_twice` is prevented structurally (`UNIQUE statement_lesson` inside one statement) and
  behaviourally (the cross-statement Lesson guard under the root).

### 15.6 Failure, rollback and repair

- Every mutation runs inside an explicit transaction, and every command's outcome is exactly one of the
  two declared classes of §15.8:
  - a **business refusal** — a declared, reason-coded refusal the command decides from the facts it read
    (a structural, vocabulary, scope or parent defect; an instant inadmissible under §6.3; a missing or
    ambiguous rate; an unresolved policy; a coverage gap; either of the two statement derivation codes of
    §10.5's gate (`statement_totals_mismatch`, `statement_derivation_mismatch`); a replay conflict, or any
    other condition a section of this contract declares as a refusal outcome with a §5.2.1 reason code for
    that command). The attempted mutation writes nothing, and the refusal's durable evidence — the
    `refused` command row, the matching `finance_exceptions` row and their digest-only audit rows — is
    committed by the §15.8 refusal-evidence transaction, so a refusal is never silent, never re-tried into
    a different answer and never reported as success;
  - a **persistence or corruption failure** — a write that fails (a repository insert/update/compare-and-swap
    error, or a failed audit or outbox insert), an unexpected exception, or a detection that the **stored**
    Finance state is corrupt **where no section declares that shape as a refusal outcome of that command**
    (a broken chain edge, an applicable row whose parent is missing, a command result pointing outside its
    own aggregate). The whole command rolls back: no Finance row, no command row, no exception row, no
    audit row, no partial line set, no half-superseded chain and no orphan command row without its result.
    The command fails closed by throwing, and the corruption is left visible through the validators and the
    §14.3 diagnostics — it is never repaired, defaulted or retried into a different answer (U-D16, §15.8).
- No Finance code path performs a silent repair, a compensating rewrite, a background re-derivation or
  an automatic retry that could produce a different answer. A stale or corrupt aggregate is reported
  and left for an explicit command.
- Finance performs no outbound call, schedules no event, and writes nothing to `platform_outbox` except
  the notification intents of §17 — the second of the two declared infrastructure seams of §15.7.

### 15.7 Infrastructure writes (audit and outbox) — the declared allowlist

Phase U's rule from U-D3 is that a Finance command mutates no table outside its own declared Finance
storage — and it also requires two writes that are not Finance storage at all: digest-only audit
evidence, and the notification intents of §17. The allowlist is therefore declared exactly, and nothing
beyond it is permitted (U-C4-BLOCK-003).

**The complete allowlist.** A Finance code path may write exactly the twenty-one declared Finance tables
of §13.1 **plus** the two pre-existing platform infrastructure tables below, and no other table:

| Infrastructure table | What Phase U writes | Keyed by | Never |
| --- | --- | --- | --- |
| `platform_audit_events` | one digest-only audit row per audited Finance row — the row the command wrote, and one for each row a conditional status move changed — inserted in the same transaction as that Finance row | a 64-character digest derived from the command's `command_key_digest` and the audited row's identity, so a replayed command converges on exactly one audit row per audited row | updated, deleted, re-purposed, read back as authority, or given a raw payload |
| `platform_outbox` | the §17 notification intents only, one row per intent per transition, inserted in the same transaction as the transition that raises it, written exactly like the existing `RecurringOutboxRepository::publish()` seam and supplying **exactly** the declared insert shape of the Outbox contract below — the seam's three identity columns, the derived `idempotency_key`, and the seam's mandatory metadata (`status = 'pending'`, `available_at` = the transition instant, `created_at` = the transition instant, `attempt_count = 0`, and the explicit `NULL` `leased_at`/`processed_at`/`invitation_id`/`generation_id`) | the same derived-digest idiom the existing repository uses (`intentKey(aggregate, aggregate id, intent)`) under the table's `UNIQUE idempotency_key` | updated, deleted, leased, claimed, processed, delivered, read as authority, given a payload column, or carrying any Finance **business fact** — no amount, period bound, currency, reason or finding code, entity id beyond the row's own `aggregate_id`, template, recipient, channel address, message body, raw key or command digest — or any column outside the declared insert shape above |

**Audit contract.** An audit row records `aggregate_type` = the declared Finance aggregate,
`aggregate_id` = the id of the Finance row the command wrote or moved — a version, chain, snapshot,
correction, evaluation, override, statement, line, event, run, finding or exception row, all of which
declare `id bigint unsigned NOT NULL AUTO_INCREMENT` (§13.1) — `event_type` = the declared command
operation, `actor_type`/`actor_id` = the capability-checked administrator, `reason_code` = a §5.2.1
member or `NULL` for a plain success, `safe_detail` = a digest or short code only, `idempotency_key` =
the derived digest above (the table's own `UNIQUE` key), and `occurred_at` = the same instant recorded on
the Finance row. A command that performs a conditional status move in addition to its primary write — a
policy `record` that supersedes a predecessor, a rate `record` that closes one — writes one audit row
for the primary row and one for each row it moved, each with its own derived key. A **business refusal**
(§15.8) has no committed Finance mutation, so its audit rows evidence exactly the two rows it commits —
the `refused` command row and the matching `finance_exceptions` row — with `event_type` = the refused
operation, `reason_code` = the refusal's code and `occurred_at` = the instant recorded on those rows. No
audit row ever carries an amount, a raw key, a raw request body, a provider reference, an evidence payload,
a statement label or any personal data (§5.2.1, U-D18).

**Outbox contract.** `platform_outbox` is written for the three intents of §17 and for no other event. Its
**declared insert shape** has exactly two parts, and nothing outside those two parts is ever written
(U-C7-BLOCK-001).

*Part 1 — the identity-only business payload: the seam's three identity columns.* `aggregate_type` (the
declared Finance aggregate), `aggregate_id` (the id of the declared Finance row that raised the intent)
and `event_type` (the intent name). These three are the **only** Finance-supplied business facts on the
row. The existing table has no payload column, migration 030 performs no `ALTER` on it (§13.5), and no
payload column is added (§17, U-C6-BLOCK-001).

*Part 2 — the mandatory seam metadata: the write scaffolding the unchanged seam requires.*
`platform_outbox.idempotency_key` and `created_at` are `NOT NULL` with no default and
`status`/`available_at` are `NOT NULL`, and the established publisher writes them together with
`attempt_count = 0` and explicit `NULL` for `leased_at`, `processed_at`, `invitation_id` and
`generation_id`. The declared insert shape is therefore exactly: `idempotency_key` = the derived digest of
the existing repository's `intentKey(aggregate, aggregate id, intent)` idiom, under the table's
`UNIQUE idempotency_key`; `status = 'pending'`; `available_at` = the transition instant; `created_at` =
the transition instant; `attempt_count = 0`; and `leased_at` = `processed_at` = `invitation_id` =
`generation_id` = `NULL`. These columns are the seam's own scaffolding, not Finance results: they are what
makes the insert representable at all against the unchanged table, none of them carries a Finance business
fact, and a Finance write that supplies exactly this shape is valid and is never rejected by §13.4 rule 12
or §18's source scan.

*The prohibition.* Beyond those two parts the row carries nothing: no amount, no period bound, no currency,
no reason or finding code, no entity id beyond the row's own `aggregate_id`, no template, no recipient, no
channel address, no message body, no raw key, no command digest and no request body. The permitted Finance
facts are **hydrated by Phase S** from the declared aggregate the row names, through the declared Finance
read services of §14.1, and never taken from the outbox row itself. Phase U **initializes** the seam's
required pending state — it writes the initial `pending` row exactly once, inside the transaction of the
transition that raises it — but it does not own the seam's delivery lifecycle: it never updates the row,
never leases, claims or processes it, never writes a later
`status`/`leased_at`/`processed_at`/`attempt_count` value, never deletes it and never reads it as authority.
Delivery, attempts, leases and status **after** insertion belong to Phase S, and no Finance read model
depends on an outbox row.

**Transactional contract.** An audit or outbox write that evidences a **committed Finance row** is inside
that command's transaction, so the two are never separated: a **persistence failure** rolls them back
together with the Finance rows — no audit row without its fact, no intent without its transition — and the
`UNIQUE idempotency_key` of each table converges an idempotent replay on the original rows instead of
duplicating evidence. If the audit insert fails, the command fails closed and writes nothing, because an
unevidenced Finance write is not permitted; if the outbox insert of a transition that raises an intent
fails, that transition rolls back whole. The **one exception is a business refusal** (§15.8): a refused
command has **no** committed Finance row and writes **no** intent, so its refused command row, its matching
`finance_exceptions` row and their digest-only audit rows are committed by the §15.8 refusal-evidence
transaction while its attempted mutation rolls back — which is why §15.6's rollback rule distinguishes the
two classes rather than folding a business refusal into a persistence failure.

**Consequence for the exclusivity rules.** Wherever this contract says a Finance command "mutates no row
outside its own declared tables" (U-D3, §13.4 rules 10 and 12, §13.6, §15.2), it is read as "no mutation
outside the twenty-one declared Finance tables **plus** these two declared infrastructure tables", and
the §18 contract suite proves by source scan that no other non-Finance table is ever written.

### 15.8 Business refusals versus persistence failures — the declared refusal-evidence commit

§15.6 names two classes because the contract needs one durable outcome per command and the two classes
reach opposite conclusions about rollback. This section declares both exactly, so no section has to
choose between "the refusal must be recorded" (U-D16, §6.3 rule 1, §10.5) and "the command rolls back
whole" (U-C6-BLOCK-003).

**A business refusal is committed, and only its evidence is committed.** A business refusal is a
declared, reason-coded refusal of the command's intent, decided from facts the command has read — never
from a write that failed. When a command refuses for a business reason it:

1. writes **nothing** of its attempted mutation: no `finance_policies` version, no predecessor supersession,
   no rate row or closure, no snapshot, no correction, no evaluation, no override, no statement, no line,
   no event, no run, no finding and no total;
2. commits **exactly** its refusal evidence — the `refused` `finance_*_commands` row (with a `NULL` typed
   `result_*_id` and the exact `reason_code`), the matching `finance_exceptions` row (whose `teacher_id` is
   the command's teacher scope or `NULL` for a teacher-less command, §13.2), and one digest-only
   `platform_audit_events` row per committed Finance row, each keyed by its derived digest — in the
   declared **refusal-evidence transaction**, a second, short transaction opened after the attempted
   mutation's rollback and serialised under the **same root the command's own scope selected**
   (§15.1–§15.2), which the refusal-evidence transaction re-acquires, so a concurrent identical refusal
   converges on the same evidence rows instead of duplicating them and the evidence can never be lost to
   the mutation rollback;
3. raises **no** §17 intent: none of the three intents is a refusal, and a refusal never writes an outbox
   row.

A refusal whose expected rows already exist converges idempotently: the `UNIQUE command_key_digest` of the
command table and the `finance_exceptions` `fingerprint_state` key make a replayed refusal converge on the
original evidence rather than write a second copy, exactly as §15.3 declares for a replayed success.

**A persistence or corruption failure is rolled back whole.** When a write inside the command fails, when
an unexpected exception is raised, or when the command detects a corrupt stored shape **for which no
section declares a refusal outcome of that command**, the entire command — including any refusal evidence
it had already written in this attempt — is rolled back: no Finance row, no command row, no exception row,
no audit row and no intent. The command throws a fail-closed error and records nothing; the corruption
remains visible fail-closed through every read that hydrates the affected aggregate (§14.1) and through the
§14.3 diagnostics, and the repair path is the explicit operator command of §12, never an automatic retry.
U-D16's requirement that corrupt state be "recorded" is satisfied for these shapes by that fail-closed read
path and those diagnostics, and for every shape that does carry a declared reason code by the recorded
exception of the section that declares it.

**Which class a corrupt shape falls in is declared, never guessed.** A corrupt shape that a section of this
contract names as a refusal outcome with a §5.2.1 reason code — for example `snapshot_derivation_mismatch`
(§8.5) or the two statement derivation codes of §10.5's gate — is a **business refusal** and is recorded as
such; a corrupt shape no section declares an outcome for is a **corruption failure** and rolls back whole.
This is what keeps §10.5's "a failed issuance attempt writes a refused command result and ensures the
matching exception exists" and §15.6's whole-transaction rollback rule from contradicting each other.

**The two classes are exhaustive and disjoint.** Every command outcome is either "the attempt mutated
nothing and exactly its refusal evidence is committed" or "the attempt is rolled back whole and the
failure is surfaced"; there is no third outcome, no partially committed mutation, no committed mutation
without its evidence and no refusal without its durable reason. The distinction is drawn at the
**mutation boundary**: a refusal the command decides before its primary write is a business refusal; a
failure of that write, or of the evidence write itself, is a persistence failure. A refusal's own evidence
write is therefore the only write a business refusal performs, and if that evidence write fails, the
command fails closed with no Finance fact committed (the half-written attempt is rolled back), exactly as
§15.7's transactional contract requires.

## 16. Upstream fact extension map and legacy compatibility

### 16.1 What Finance consumes, and what it adds

| Upstream authority | Fact Finance consumes (read-only) | Fact Finance adds | Invariant |
| --- | --- | --- | --- |
| Phase L Term / Phase 2A.2-M Lesson | canonical Lesson identity, kind (`standard`/`replacement`/`introductory`), lifecycle, `replacement_for_lesson_id`, Term/Enrolment links | nothing on the Lesson; the snapshot is a new Finance aggregate keyed by `lesson_id` | Finance never creates, edits, completes, cancels or archives a Lesson |
| Phase 2A.2-N schedule | the applicable schedule version and its occurrence anchors | the recorded `snapshot_instant_utc` copied verbatim from the bound occurrence | Finance never reschedules or materialises an occurrence |
| Phase 2A.2-O delivery | `outcome_code`, `delivery_state`, `attendance_state`, `remedy_class`, `occurrence_attempted`, outcome supersession | the payability evaluation derived from those facts, and the non-payability of an academy obligation | Finance never records, supersedes or reinterprets a delivery outcome or an O-D8 reconciliation |
| Phase 2A.2-P attendance | attendance case/decision/evidence for review context | the `pending` disposition while review is unresolved | Finance never adjudicates attendance and never publishes a delivery state |
| Phase 2A.2-J Assignment | the current Teacher Assignment of the Lesson | the snapshot's recorded `teacher_assignment_id` | Finance never replaces or reassigns a Teacher |
| Phase 2A.2-R1 commercial | offers, obligations, purchases, evidence/facts, settlements, entitlements, funding plans | nothing; Finance references them only in `studentCommercialCrossCheck()` and never asserts student money | Finance never prices, discounts, settles, funds or unbinds anything |
| Phase 2A.2-R2 recurring | renewal cycles, collection intents, recovery and refund-review states | nothing; cross-checked for visibility only | Finance never collects, lapses or decides a refund consequence |
| Phase 2A.2-T payment execution | execution attempts/results and provider events (provider-neutral) | nothing; `providerEvidenceCrossCheck()` reports unmatched or attempted-without-result facts | Finance executes no call, holds no credential and treats no provider value as authority |

### 16.2 Legacy compatibility

- The legacy attendance `payable` and `payment_note` fields, the legacy teacher-payment report and
  export, and the Amelia-era service category `3` remain **operational-plugin** concerns. They are
  evidence about the legacy system; they never become Platform authority, and the Platform never
  reinterprets category `3` as a domain rule (the canonical Lesson kind plus
  `INTRO_PAYABILITY_POLICY` carry that meaning).
- No Amelia importer, shadow synchroniser, parity engine, checkpoint system or automated mapping
  pipeline is created. Only the minimum identifiers required for active operation may be recorded as
  `LegacyReference` mappings, with provenance, uniqueness and administrator review.
- Preserved legacy behaviours and their Platform expression: introductory visibility (zero-value
  `non_payable` lines, never hidden); distinct-Lesson counting (`lesson_id`, never a provider
  appointment); archive exclusion (from new drafting only, counted, never retroactive); manual override
  with audit; and exact date ranges (half-open periods recorded per statement).
- The controlled migration comparison of MIGRATION-STRATEGY §9 remains a documented validation
  technique with explicit difference reasons and a finance sign-off (see §11.3). The legacy report
  retires only after statement totals match approved periods and finance signs off on every difference.

### 16.3 Known consequence — no historical backfill

Because this phase performs no backfill, the migration itself creates no snapshot, so a statement can
only cover Lessons that were captured after Schema 030 exists. Historical periods therefore cannot be
stated by the Platform until the owner authorises a controlled historical capture path with its own
evidence rules (open decision 1 of §21). This is a deliberate consequence of "additive, no inferred
finance fact", not an oversight, and it must be stated plainly in the implementation handover.

This restriction is about **backfill**, not about timing: the ordinary §8.2 capture is a delayed
capture by design. A Lesson finalised after a rate successor, a policy version or an upstream change was
recorded is still captured at its own locked instant and resolves the rate, the policy version and the
occurrence anchor that cover that instant (§6.1, §7.3) — never the currently-live rate or policy. What
remains open is only whether Lessons that *predate* Schema 030 may be captured at all (§21 open
decision 1).

## 17. Notification intents

Phase U finalises the intent names that Phase S will deliver. It delivers nothing, renders nothing and
stores no message body, template, channel address or recipient.

**The intents are identity-only.** The existing `platform_outbox` table stores a row as `aggregate_type`,
`aggregate_id` and `event_type` plus its delivery-state columns, and it has no payload column; migration
030 performs no `ALTER` on it (§13.5). Phase U therefore declares each intent as **one declared Finance
aggregate plus one intent name**, and declares the permitted Finance facts as what Phase S **hydrates**
from that aggregate through the declared Finance read services of §14.1 — never as values carried on the
row (U-C6-BLOCK-001). "Identity-only" governs the row's **business payload**: those three identity columns
are the only Finance-supplied facts on the row. The row is still written with the seam's own mandatory
insert metadata, exactly as §15.7 declares it, because that metadata is what the unchanged
`platform_outbox` table requires before any row can exist at all (U-C7-BLOCK-001).

| Intent | Raised when | Outbox row identity columns (`aggregate_type` / `aggregate_id` / `event_type`; §15.7 insert shape) | Phase S hydrates (permitted facts only) |
| --- | --- | --- | --- |
| `TEACHER_STATEMENT_ISSUED` | the conditional `draft → issued` transition of `TeacherStatementService::issue()` commits | `finance_statements` / the issued statement id / `TEACHER_STATEMENT_ISSUED` | teacher id, statement id, period bounds, currency, payable total, statement version and `issued_at` — read from that statement row (identifiers and exact integers only) |
| `TEACHER_STATEMENT_SUPERSEDED` | the conditional `issued → superseded` transition of `supersede()` on the predecessor commits | `finance_statements` / the **superseded predecessor** statement id / `TEACHER_STATEMENT_SUPERSEDED` | teacher id, predecessor statement id, the new version's `superseded_by_statement_id`, `superseded_at` and the transition's reason code — read from the predecessor row and its `superseded` `finance_statement_events` row |
| `FINANCE_RECONCILIATION_EXCEPTION_RAISED` | a reconciliation `run()` commits with at least one **blocking** finding | `finance_reconciliation_runs` / the run id / `FINANCE_RECONCILIATION_EXCEPTION_RAISED` | teacher id (or `NULL` for a period-wide run), period bounds, and the blocking findings' finding/reason codes and entity ids — read from the run and its findings (never a free-text explanation of money) |

**Declared insert shape.** Each of the three intents is published as exactly one `platform_outbox` insert
of §15.7's declared shape — the identity columns of the table above, plus only the seam's mandatory
metadata: the derived `idempotency_key` (the `intentKey(aggregate, aggregate id, intent)` digest, under
the table's `UNIQUE idempotency_key`), `status = 'pending'`, `available_at` and `created_at` = the
transition instant, `attempt_count = 0`, and the explicit `NULL` `leased_at`, `processed_at`,
`invitation_id` and `generation_id`. That metadata is the seam's own scaffolding and never a Finance
result: it carries no amount, period bound, currency, reason code, entity id, template, recipient,
channel address or message body, and it is what makes the insert representable at all against the
unchanged table. Phase U writes this initial pending row exactly once per intent per transition and then
leaves the row alone: it **initializes** the seam's required pending state, but it does not own the
delivery lifecycle that follows, which is Phase S's.

**Hydration contract.** For each intent, Phase S reads the permitted facts **only** through the declared
Finance read layer of §14.1 (`TeacherStatementReadService` for the two statement intents,
`FinanceReconciliationReadService` for the reconciliation intent), re-verifies the aggregate through its
owning integrity validator, and fails closed on a malformed shape. The outbox row is a **pointer, not a
payload**: Phase S never treats it as authority, never reads a raw command digest through it and never
derives a value that is not recorded on the aggregate it names. The three `event_type` values above are
the only `event_type` values Phase U ever writes to `platform_outbox`.

Rules: no intent is emitted for a `draft`, for an informational finding or for personal data; no intent
carries an amount that is not already recorded on the referenced statement; and the wording, translation,
RTL rendering, channel and delivery lifecycle of every intent belong to Phase S. These three are the
**only** intents this phase ever publishes, and each is published exactly as §15.7 declares: one
`platform_outbox` row per intent per transition, keyed by a derived digest so a replayed command raises no
duplicate, written insert-only inside the transaction of the transition that raises it, carrying the
seam's own `aggregate_type`/`aggregate_id`/`event_type` identity columns and only the seam's mandatory
insert metadata of §15.7, and no Finance business fact — never an amount, a period bound, a currency, a
reason code, an entity id beyond the row's own `aggregate_id`, a template, a recipient, a channel address,
a message body, a raw key or a command digest. **A refusal is not a transition**: no §17 intent is raised
by a business refusal or by a persistence failure, so no
outbox row is written for either (§15.8). A blocking `finance_exceptions` row that no reconciliation
`run()` raised — for example the blocker a snapshot capture or an issuance refusal records — raises no
intent either: it is durable exception evidence (§5.2.1, U-D16) that the exception surface, the read
models of §11.1 and the §14.3 diagnostics report, and Phase S reads it from that surface rather than from
an outbox row. Phase U writes no recipient and no template, and it writes no delivery state **beyond the
initial `pending` row the seam requires**: the row is written exactly as §15.7 declares, that initial
pending state is initialized once and never revisited, and every subsequent status, lease, attempt and
delivery action remains Phase S's.

## 18. Test matrix

Every suite below must pass on the disposable WordPress + MariaDB runtime from a fresh clone with no
network access and no real credential, in addition to the existing adjacent suites.

| Suite | Required proof |
| --- | --- |
| `tests/phase-2a2u-contract.php` | Schema 30 identity and build shape; the migration/verifier call sites (after 030, on current-schema verification, before the option advances to 30); the declared twenty-one-table set, the three declared seed rows **and** the single declared `finance_policy_roots` structural row (`root_key = 'finance_policy'`), with the assertion that **no** `finance_policies` row exists for `FINANCE_STATEMENT_TIMEZONE`, that `{policy_value, value_type}` are a `NOT NULL` pair, and that the root table declares no mutable column and holds exactly one row; the locked vocabularies of §5.2, the reason-code sets of §5.2.1 (asserted literally, with both views proved to be subsets of `FinanceRule::REASON_CODES`, the twelve declared shared members proved, and a source scan of `src/Core/Application/Finance/**` proving every reason literal used there belongs to the set for the row it is written to) and the derivation table of §5.3 as single sources; `FinanceRule::POLICY_KEYS` is exactly four keys and refuses a structural key; digest-only/append-only/no-FK/no-CHECK discipline; the §13.3 index invariant and parent contract, including the absence of any reference to the legacy `lesson_schedule_versions` table, the presence of `finance_statement_lines.id` as `line_id`'s declared parent, and the presence of `finance_exceptions.id` as the declared parent of the reconciliation `exception_id` selector and its typed `result_exception_id`; **every one of the six command tables' typed `result_*_id` set and the absence of any polymorphic `result_id` column** — including `finance_policy_commands.result_policy_id` (parent `finance_policies.id`) and the reconciliation `exception_id`/`result_exception_id` pair — with each typed result reference and each declared selector proved to be the leftmost column of a declared named index and to name a declared parent; the presence of `finance_policy_commands` as the policy registry's declared command table and of the `finance_policy_roots` singleton as the global policy serialisation root of §15.1; the absence of any update path for the append-only tables, for the immutable snapshot columns, for `finance_policy_roots` (insert-or-resolve only) and for `finance_policies`' value columns; the three-column mutation limit of `finance_teacher_rates` with its at-most-twice one-way status lifecycle, the single-column status of `finance_policies` with its at-most-twice lifecycle, the state/supersession-edge plus one-time issuance-evidence limit of `finance_statements`, and the immutable `intro_policy_key`/`intro_policy_version` pair of `finance_snapshot_corrections`; the five capabilities, their per-capability repair, and the absence of any Finance capability on the Teacher or Student role; the absence of any Finance route, provider SDK, `wp_remote_*`/`curl_*` call, scheduling function or credential helper under `src/Core/Application/Finance`; the §15.7 infrastructure allowlist asserted as declared data (exactly `platform_audit_events` and `platform_outbox` outside §13.1, neither ever updated or deleted); and a source scan over `src/Core/Application/Finance/**` and `src/Core/Infrastructure/Repository/Finance*Repository.php` proving **no Finance class writes any table outside the declared twenty-one plus those two infrastructure tables**, that every audit write is a digest-only `platform_audit_events` insert, and that every outbox write is one of the three §17 intents written the way `RecurringOutboxRepository` writes them |
| `tests/phase-2a2u-migration-runtime.php` | fresh Schema 30 storage and identity; repeat safety from 27, 28 and 29; retained-stale-version advance; the three-seed contract, the single-root-row contract (exactly one `finance_policy_roots` row with `root_key = 'finance_policy'`, with a missing root row, a second root row, a foreign `root_key` and any mutable column on the root each rejected), the rejection of a fourth seed and the absence of any seeded `FINANCE_STATEMENT_TIMEZONE` row; malformed-storage rejection (unknown table, non-InnoDB, mutable append-only column, raw-reference column, bank/tax/payout/invoice/ledger-named column, `text` digest, missing index, a `*_id` that is not the leading column of a named index, `*_id` without a declared parent, a parent that does not declare `id`/`PRIMARY KEY(id)`, a polymorphic `result_id` column, a command table missing a typed result reference, a `finance_policy_commands` row without its typed `result_policy_id`, a `finance_reconciliation_commands` row missing the `exception_id` selector or the `result_exception_id` result, a reference to the legacy `lesson_schedule_versions` table, a `finance_policies` row with a NULL `effective_from`, a policy row with a NULL `policy_value` or `value_type` (the forbidden null-valued version), a duplicate policy instant, a partially recorded statement timezone triple, a statement whose `state` and issuance evidence disagree, a correction whose policy pair is half-set, and a reason code outside its declared set); proof that every existing table is unchanged by re-running the R1/R2/T and canonical verifiers **and that migration 030 never touches `platform_audit_events` or `platform_outbox`**; and the completed-030/missing-table case failing closed rather than being silently repaired |
| `tests/phase-2a2u-runtime.php` | rate lifecycle: record → resolve → record successor → automatic single `effective_until` closure → withdraw refused while referenced → **a superseded historical row may still be withdrawn** (the second, terminal status move, with its own event); **interval resolution independent of live status**: (a) a successor is recorded (predecessor `superseded`), then a Lesson whose locked instant lies inside the predecessor's interval is captured and resolves the predecessor's `rate_id`/`rate_version`; (b) a successor recorded with a **future** `effective_from` leaves the predecessor current for now and resolves the successor for an instant at or after it; (c) an instant covered only by a withdrawn row has **no** rate (`rate_missing_for_lesson` + `teacher_rate_timeline_gap`, no fallback to a broader scope); (c2) **withdrawn-course-plus-active-teacher**: an instant covered by a withdrawn `teacher_course` row *and* an `active` `teacher`-scoped row resolves to **no** rate — the withdrawn row wins its specificity and blocks the instant, the broader row is never consulted, the blocker is `rate_missing_for_lesson` + `teacher_rate_timeline_gap`, and **no** snapshot is written; (d) a delayed capture in a period that has already elapsed drafts and issues correctly and recomputes nothing already recorded; policy resolution on the same model (a version recorded after a successor still resolves for its own interval, a retracted version resolves to unset, `finance_policy_unset` blocks an introductory derivation whose key resolved to unset, and the unseeded `FINANCE_STATEMENT_TIMEZONE` key resolves to "never set" rather than to any row); snapshot capture: ordinary completed Lesson, `delivered`, `student_no_show`, `interruption`, `teacher_non_delivery`, `review_required` (`pending`), cancelled-before-occurrence, academy obligation, introductory Lesson, replacement Lesson; a `scheduled` Lesson refused `snapshot_lesson_not_finalised` with nothing written; idempotent replay; conflicting replay refused `command_replay_conflict`; missing rate → blocker and **no** snapshot; ambiguous rate → blocker; `rate_effective_from_precedes_snapshot` refused; payability re-derivation after an outcome change appends rather than edits; override appends and is reported |
| `tests/phase-2a2u-statement-runtime.php` | draft → totals → issue for a clean period; **issuance evidence**: the `draft → issued` transition stamps `issued_at`/`issued_by` exactly once in the same conditional statement, a second issuance attempt is refused `statement_state_transition_conflict`, a supersession stamps only `superseded_at`/`superseded_by_statement_id` and leaves the issuance evidence untouched, and the verifier rejects a statement whose `state` and issuance evidence disagree; the §10.5 gate refusing on each blocker in turn (period not elapsed, a non-finalised Lesson in the period, missing snapshot, pending line, currency mismatch, unset timezone policy, blocking exception, overlapping period, Lesson already stated); **the timezone triple**: a draft with the policy set records zone+label+version and issues; a draft with an unseeded (never-set) `FINANCE_STATEMENT_TIMEZONE` records all three as `NULL`, renders no label, is refused `policy_unset_for_statement_period` at issue, and after the policy is recorded the operator withdraws and re-drafts (§10.6) into a statement that records the triple and issues; a partially recorded triple is refused `statement_timezone_representation_invalid` by `assertTotals()` and by the verifier; a later policy successor or retraction never rewrites a recorded triple; a statement transition attempted from the wrong state is refused `statement_state_transition_conflict`; a period whose enumerable Lesson set is closed (a Lesson added to an already-issued period is impossible because the period must have elapsed and is claimed); zero-value introductory line visible with `non_payable` and `line_amount_minor = 0`; archived Lesson excluded from a new draft and counted in `excluded_archived_count`, and an issued statement unchanged when a Lesson is archived afterwards; withdraw of a draft; supersession of an issued statement with unchanged predecessor lines/totals; `assertTotals()` failing closed on a tampered line, a tampered total and a tampered `derivation_digest` |
| `tests/phase-2a2u-reconciliation-runtime.php` | every finding code of §5.2.1 reachable with its exact two compared values; no tolerance (a one-minor-unit difference is a finding); a run repairs nothing; a run never changes a snapshot, evaluation, line, rate or statement; `legacy_flag_differs` recorded without auto-resolution; `provider_evidence_unmatched` and `studentCommercialCrossCheck()` proving they mutate nothing; a run that cannot hydrate a Lesson reports `failed` instead of skipping it; a `finding_code` outside `FinanceRule::FINDING_CODES` is refused rather than stored |
| `tests/phase-2a2u-corruption-runtime.php` | each corrupted shape fails closed through its owning validator: two applicable evaluations; a broken evaluation sequence; a superseded row with no successor; a snapshot whose `derivation_digest` no longer matches its inputs; a snapshot whose recorded rate row/version does not exist; a correction chain with two applicable rows; a correction whose `intro_policy_key`/`intro_policy_version` pair is half-set or disagrees with the corrected Lesson's kind (`snapshot_correction_incomplete`); a statement whose totals disagree with its lines; a statement whose `state` and issuance evidence disagree (an `issued` statement with NULL `issued_at`/`issued_by`, and a `draft` statement carrying them); a line whose snapshot belongs to another Lesson; a line whose evaluation names a foreign snapshot; an applicable row whose Lesson is missing; a rate with overlapping intervals; two active rate rows in one scope; a withdrawn rate row carrying `active_slot = 1` or an open interval (`teacher_rate_state_not_resolvable`); a policy key with two rows sharing one instant and a policy row with a NULL `effective_from`, a NULL `policy_value` or a NULL `value_type`; a statement with a partially recorded timezone triple; and a statement with a foreign currency against its lines. Each is never silently repaired and converges after exact restoration |
| `tests/phase-2a2u-failure-runtime.php` | an injected write failure at every mutation boundary (rate insert, interval closure, the second status move of a rate, policy supersession, policy withdrawal, snapshot insert, evaluation append, override append, correction append, statement insert, line insert, event append, totals update, issuance transition with its evidence stamp, supersession stamp, run/finding append, exception write) leaves no partial state; a crash after the snapshot insert and before the command row leaves no unreachable authority; a crash after `assertTotals()` and before the issuance transition leaves the statement `draft`; a crash with the issuance transition committed stamps `issued_at`/`issued_by` exactly once and a retry is refused `statement_state_transition_conflict` rather than stamping again; and every retry converges on the same recorded facts |
| `tests/phase-2a2u-concurrency-runner.sh` | `duplicate_snapshot_capture` (two captures of one Lesson yield one snapshot and one converged command), `rate_close_vs_rate_record` (one closure, one successor, no overlap), `rate_change_vs_snapshot_capture` (the loser refuses `rate_effective_from_precedes_snapshot` or converges), `successor_record_vs_delayed_historical_capture` (a successor is recorded while a capture for an earlier locked instant is in flight; the capture resolves exactly one interval — predecessor or successor, never both and never none — and the pair leaves one snapshot, one closure and no overlap), `payability_override_vs_evaluation` (one applicable row; the loser affects `0` rows and converges), `concurrent_statement_draft` (one live statement per period; the loser refused `statement_period_overlap`), `concurrent_statement_issue` (the loser affects `0` rows and reports `statement_state_transition_conflict` with the current state), `concurrent_policy_record` (two competing versions of one key: one succeeds, the loser refuses `finance_policy_timeline_overlap` rather than sharing an instant), `statement_issue_vs_snapshot_correction` (exactly one outcome: the draft re-derives, or the issued statement refuses `statement_supersession_required`), `concurrent_reconciliation_run` (two runs append two independent run records and no repair), `duplicate_command_replay`, and `unrelated_teachers` (two Teachers never contend) |
| Adjacent regressions | Phase L, M, M0, N, O, P, Q, R1, R2 and T runtime suites re-run green (P and Q only where their pre-existing fixture-order limitations are recorded honestly) |

**18.1 Round-4 additions (U-C4-BLOCK-001 … U-C4-BLOCK-003).** The three findings of §0c add the
following required proofs to the suites above; each is mandatory and none replaces an existing row.

| Suite | Added required proof |
| --- | --- |
| `tests/phase-2a2u-contract.php` | the global policy root and the policy command table are declared and wired — one `finance_policy_roots` row (`root_key = 'finance_policy'`), no mutable column on it, `finance_policy_commands` present as the policy registry's command table with the typed `result_policy_id` and the `policy_key`/`policy_version`/`policy_id` selectors, and every one of its declared `*_id` columns the leading column of a named index; `finance_exceptions.id` declared as the parent of the reconciliation `exception_id`/`result_exception_id` pair; the §15.7 allowlist asserted as data (exactly `platform_audit_events` and `platform_outbox` outside §13.1); and a source scan over `src/Core/Application/Finance/**` and `src/Core/Infrastructure/Repository/Finance*Repository.php` proving the global policy root is acquired **before** any Teacher-scoped row, that the exclusive acquisition never follows a shared or Teacher-scoped one, and that no Finance class writes a non-allowlisted table |
| `tests/phase-2a2u-migration-runtime.php` | the single-root-row contract (exactly one `finance_policy_roots` row; a missing root row, a second root row, a foreign `root_key` and a mutable column on the root each rejected), the rejection of a command table missing its typed result reference for policy or reconciliation, and that migration 030 neither creates nor alters `platform_audit_events` or `platform_outbox` |
| `tests/phase-2a2u-runtime.php` | each mutating policy command (`record`, `supersede`, `withdraw`) resolves the global policy root first, appends exactly one `finance_policy_commands` row with its selectors and typed `result_policy_id`, writes its digest-only `platform_audit_events` evidence with the same `occurred_at` instant as the Finance row, converges on an identical replay (one command row, one audit row, no duplicate evidence), is refused `command_replay_conflict` on a materially different replay while preserving the original record, and records `result_state = 'refused'` with a `NULL` `result_policy_id` and the exact `reason_code` when a write is refused |
| `tests/phase-2a2u-statement-runtime.php` | **policy change versus draft**: a policy version recorded, superseded or retracted while a draft exists never rewrites a recorded triple and never re-labels a statement in place; a **retraction** of the draft's recorded `timezone_policy_version` makes the draft stale and issuance refuses `policy_unset_for_statement_period` with the matching exception recorded, after which the operator withdraws and re-drafts into a statement that records the triple and issues; a **successor** recorded after the draft leaves the recorded zone correct and does not block issuance; and no draft ever records a partially mixed triple |
| `tests/phase-2a2u-reconciliation-runtime.php` | `resolveException` names its exception with the typed `exception_id` selector, records the typed `result_exception_id`, appends exactly one `finance_reconciliation_commands` row, leaves every run, finding, snapshot, evaluation, line, rate, statement and exception-history row otherwise unchanged, converges on an identical replay, and is refused `command_replay_conflict` on a materially different replay; **both root paths**: a resolution of a teacher-scoped exception acquires that Teacher's `finance_teacher_roots` row first and takes no policy root, and a resolution of a teacher-less exception (`teacher_id = NULL`) acquires the shared global `finance_policy_roots` root and no Teacher root — each recorded command row carrying the target exception's own `teacher_id` (`NULL` for the teacher-less path), and neither path ever acquiring both roots |
| `tests/phase-2a2u-corruption-runtime.php` | a `finance_policy_commands` row missing its typed `result_policy_id`; a `finance_reconciliation_commands` row missing `exception_id` or `result_exception_id`; a missing, duplicated or foreign-keyed `finance_policy_roots` row; and a mutable column on the policy root. Each fails closed and converges after exact restoration |
| `tests/phase-2a2u-failure-runtime.php` | injected failures at the added mutation boundaries (policy-root resolve, policy version insert, policy command row, exception-resolution command row, audit row insert, outbox intent insert) leave no partial state; a failed audit or outbox insert rolls back the Finance rows it would have evidenced, so no Finance fact is ever committed unevidenced and no §17 intent is ever raised without its transition; an injected failure of the **refusal-evidence** write (the refused command row, the exception row or its audit row) leaves no Finance mutation and no half-written evidence, and the command fails closed (§15.8) |
| `tests/phase-2a2u-concurrency-runner.sh` | `policy_change_vs_statement_draft` — a policy mutation and a `draft` for the same period produce exactly one total order, one coherent timezone triple (never a mixture), and, when the mutation was a retraction of the recorded version, an issuance refused `policy_unset_for_statement_period`; `concurrent_policy_record` extended to prove the exclusive root acquisition serialises two competing versions of one key; `concurrent_exception_resolution` — two resolutions of one **teacher-scoped** exception contend on the one Teacher root, leave one recorded transition and either converge or refuse the loser, and two resolutions of two **teacher-less** exceptions contend only on the shared global policy root without blocking a per-Teacher command; and `duplicate_policy_command_replay` — an identical policy-command replay converges on one command row and one audit row |

The concurrency runner's existing `unrelated_teachers` case keeps its meaning under §15.1: two Teachers'
rate, snapshot, evaluation and reconciliation work never contend on any Teacher-scoped row, and the only
declared cross-Teacher serialisation point is the global policy root — taken exclusively by policy
mutations and in shared mode by every policy consumer of §6.3 (statement `draft`/`issue`, snapshot
`capture`, payability `evaluate`/`override`) **and** by the teacher-less scope fallback of §15.1 role 3 (a
period-wide reconciliation `run()` or a `resolveException()` on a teacher-less exception), so two shared
holders never block each other. A Teacher-scoped `run()` or `resolveException()` is not in that set: it
takes that Teacher's root, so it contends only with that Teacher's own commands (§15.1–§15.2, §15.8). One
adjacent case is added with it — a period-wide
reconciliation run racing a statement draft — proving that the two proceed without blocking each other
while a concurrent policy mutation is ordered against both.

**18.2 Round-5 additions (U-C5-BLOCK-001 — the round-4 review's back-dated policy version finding).** The
one finding of §0d adds the following required proofs to the suites above; each is mandatory and none
replaces an existing row.

| Suite | Added required proof |
| --- | --- |
| `tests/phase-2a2u-contract.php` | §6.3's admissibility rule asserted as declared data: `FinanceRule::POLICY_ADMISSIBILITY_RULE` is the single declared constant that binds it, `policy_effective_from_precedes_recorded_consumption` is a member of `FinanceRule::EXCEPTION_REASON_CODES` **and** of `REASON_CODES` and of no other set, each of the four declared policy keys names exactly one consuming surface and one recorded policy-relevant instant in §6.3's table, and the source scan over `src/Core/Application/Finance/**` and `src/Core/Infrastructure/Repository/Finance*Repository.php` proves that every policy-consuming command acquires the global policy root **shared and before any Teacher-scoped row** while every mutating policy command acquires it **exclusively** and never after a shared or Teacher-scoped acquisition |
| `tests/phase-2a2u-runtime.php` | **a post-capture back-dated policy record is refused and converges**: after an `introductory` Lesson's snapshot has committed at its locked instant `I`, recording `INTRO_PAYABILITY_POLICY` with an `effective_from ≤ I` is refused `policy_effective_from_precedes_recorded_consumption` — no `finance_policies` row is written, no predecessor is moved to `superseded`, the refused `finance_policy_commands` row and the `finance_exceptions` row carry that exact code with a `NULL` `result_policy_id`, the snapshot's recorded pair, amount and digest are unchanged, and an identical replay converges on the same refusal with no second evidence row; the **admissible control** — a version recorded with an `effective_from` strictly later than `I` — succeeds and leaves the earlier snapshot resolving its own recorded version; a key that is **"never consumed"** still accepts a back-dated version and resolves it for its own interval, so U-D4 historical resolution is unweakened; the same guard is proved for the bound snapshot instant a governed evaluation consumes and for the timezone key against a statement's recorded drafting instant; and a **retraction** of a consumed version is proved to rewrite nothing while producing exactly the declared consequences — a draft refused at issuance with `policy_unset_for_statement_period`, a re-derivation refused `finance_policy_unset`, and the differing fact reported rather than repaired |
| `tests/phase-2a2u-corruption-runtime.php` | the §13.4 rule 14 coverage invariant: a key carrying a version whose `effective_from` falls inside the half-open interval from a recorded fact's recorded version (exclusive) to that fact's policy-relevant instant (inclusive) fails the verifier for a snapshot pair, a restated correction pair, an evaluation pair and a statement triple alike, fails closed on read, is never silently repaired, and converges after exact restoration; a `withdrawn` version satisfying the invariant is accepted, and one that violates it is not |
| `tests/phase-2a2u-failure-runtime.php` | an injected failure between §6.3's guard read and the version insert leaves no policy row, no command row, no audit row and no superseded predecessor; a retry re-reads the consumption maximum rather than reusing the failed attempt's read, and two retries of one command converge on one version and one evidence set |
| `tests/phase-2a2u-concurrency-runner.sh` | `policy_record_vs_snapshot_capture` — a `capture()` for a locked instant `I` and a `record()` of the same key with a back-dated `effective_from ≤ I` race in both orders: capture-first ends in one refusal with that exact code, one unchanged snapshot whose recorded pair covers its own instant, no new policy version and no superseded predecessor; record-first ends in exactly one coherent pair for the captured instant (the new version when `I ≥ effective_from`, the predecessor when `I < effective_from`) and never a mixture, never two versions claiming one instant and never a fact whose recorded version is out-covered; `policy_change_vs_statement_draft` is extended to assert the timezone key's consumption guard against the draft's recorded instant; `concurrent_policy_record` is extended to assert that the winner's `effective_from` is strictly later than every recorded consumption instant of its key; and `unrelated_teachers` keeps its meaning because capture and evaluation consume the same root in shared mode |

**18.3 Round-6 additions (U-C6-BLOCK-001 … U-C6-BLOCK-003).** The three findings of §0e add the following
required proofs to the suites above; each is mandatory and none replaces an existing row.

| Suite | Added required proof |
| --- | --- |
| `tests/phase-2a2u-contract.php` | the §17 intents are **identity-only in their business payload** and declared as such: exactly three intent names exist, each mapped to exactly one declared Finance aggregate (`finance_statements` for `TEACHER_STATEMENT_ISSUED`/`TEACHER_STATEMENT_SUPERSEDED`, `finance_reconciliation_runs` for `FINANCE_RECONCILIATION_EXCEPTION_RAISED`) and to exactly one declared hydration read service of §14.1; the source scan proves **every** Finance write of `platform_outbox` supplies **exactly** §15.7's declared insert shape — the three identity columns, the derived `idempotency_key`, `status = 'pending'`, `available_at` and `created_at` at the transition instant, `attempt_count = 0`, and the explicit `NULL` `leased_at`/`processed_at`/`invitation_id`/`generation_id` — and no other key, so a seam-compatible insert of that shape is asserted valid rather than rejected; that no Finance business fact is ever passed to that write (no amount, period bound, currency, reason or finding code, entity id beyond the row's own `aggregate_id`, template, recipient, channel address or message body, and no raw key or command digest); and that no outbox write is reachable from a refusal path; and the write paths of `src/Core/Application/Finance/**` prove the root selected for every command is the one its recorded `teacher_id` implies — a `NOT NULL` teacher scope never takes the global root as its only root, and a `NULL` teacher scope never takes a `finance_teacher_roots` row |
| `tests/phase-2a2u-migration-runtime.php` | migration 030 does not add, alter or drop any column on `platform_outbox`, and `SHOW COLUMNS` after the migration is byte-identical to the pre-migration shape — so the identity-only business payload and §15.7's declared insert shape (the seam's mandatory `idempotency_key`/`created_at`/`status`/`available_at`/lease/attempt columns included) are proved against the pre-existing seam rather than against a widened table |
| `tests/phase-2a2u-runtime.php` | a policy admissibility business refusal (§6.3 rule 1) commits **exactly** the refused `finance_policy_commands` row (`NULL` `result_policy_id`, the exact `reason_code`), the matching `finance_exceptions` row and their digest-only audit rows, while the attempted version insert and the predecessor supersession are rolled back — no `finance_policies` row, no status move, no superseded predecessor; an identical replay converges on the same evidence rows with no duplicate command, exception or audit row; and the teacher-less exception row carries `teacher_id = NULL` with no `finance_teacher_roots` row created for it |
| `tests/phase-2a2u-failure-runtime.php` | **both §15.8 paths are proved against each other**: (a) a business refusal writes no mutation and commits exactly its refusal evidence (no outbox row, no `finance_policies` row, no rate/snapshot/statement row) — including a §10.5 gate refusal carrying `statement_totals_mismatch` or `statement_derivation_mismatch`, which is recorded and **not** rolled back; (b) an injected persistence failure — including a failure of the evidence write itself — leaves no Finance row, no command row, no exception row, no audit row and no intent, and the transaction is fully rolled back; and (c) a corruption failure the command has no declared refusal outcome for (a broken chain edge, an applicable row whose parent is missing) rolls the command back whole and reports the corruption through the validator rather than recording partial state |
| `tests/phase-2a2u-concurrency-runner.sh` | `concurrent_exception_resolution_teacherless` — two resolutions of two teacher-less exceptions serialise on the shared global policy root and never create or contend on any `finance_teacher_roots` row, while a concurrent Teacher-scoped command proceeds without blocking on them; and a `refusal_evidence_convergence` case — two identical concurrent policy-admissibility refusals commit exactly one refused command row, one exception row and one audit row between them (the loser converges), while a concurrent admissible `record()` still serialises exclusively on the same root and commits exactly one version |

**18.4 Round-7 additions (U-C7-BLOCK-001).** The finding of §0f adds the following required proofs to the
suites above; each is mandatory and none replaces an existing row.

| Suite | Added required proof |
| --- | --- |
| `tests/phase-2a2u-contract.php` | §15.7's **declared insert shape** is asserted as declared data and is **not** rejected by the allowlist scan: a Finance outbox write that supplies exactly the three identity columns, the derived `idempotency_key`, `status = 'pending'`, `available_at`/`created_at`, `attempt_count = 0` and the explicit `NULL` `leased_at`/`processed_at`/`invitation_id`/`generation_id` is accepted, the shape is proved against the pre-existing `platform_outbox` column set created by migration `002_principal_invitation_foundation` so that **every** `NOT NULL` column with no default has a declared source, and a write that supplies any additional key — a Finance business fact or a payload column — is refused by the scan |
| `tests/phase-2a2u-runtime.php` | a raised §17 intent is inserted against the unchanged seam: the row's `idempotency_key` is the derived digest under the table's `UNIQUE idempotency_key`, its `status`/`available_at`/`created_at`/`attempt_count` are the declared initial values, its `leased_at`/`processed_at`/`invitation_id`/`generation_id` are `NULL`, and a second identical transition converges on that one row rather than duplicating it or failing; Phase U performs no update of the row after insertion |
| `tests/phase-2a2u-failure-runtime.php` | an injected failure of the outbox insert of a transition that raises an intent rolls that transition back whole (no Finance row, no command row and no intent), and the same transition retried after the failure commits exactly one seam-compatible row |

**Execution status of this contract:** the suites above are **specified, not executed**. PHP is absent
in this contract-authoring environment (`php: command not found`) and no disposable WordPress + MariaDB
runtime was available, so no migration, runtime, corruption, failure or concurrency evidence exists
yet. Executing every suite above on the disposable runtime is a mandatory acceptance gate before the
Phase-U candidate may be reviewed.

## 19. Recommended implementation task identity

| Field | Value |
| --- | --- |
| Task ID | `PLATFORM-U-FINANCE-PAYABILITY-RATE-STATEMENT-IMPLEMENTATION` |
| Branch | `phase-2a2u-finance-payability-rate-statement` |
| Base | `main` with the Phase 2A.2-T candidate merged and closed (Schema 29), or an explicitly scoped base recorded in the task closeout |
| Dependency | `PLATFORM-LOCAL-TEST-RUNTIME` green (fresh install, migration, runtime, corruption, failure and concurrency) |
| Schema | 030 / `030_finance_payability_rate_statement_authority` |
| Build | `phase2a2u-finance-payability-rate-statement-20260925.1` |
| Review posture | single coherent candidate, independent review, additive-only descendant corrections |

## 20. Pre-implementation prerequisites

These gate execution, not the writing of this contract.

1. **Confirm Schema 030.** The owner brief said "Schema 029"; 029 is already owned by the Phase-T
   candidate (§0). Confirm 030 and the migration identifier
   `030_finance_payability_rate_statement_authority`, or explicitly re-scope the ledger. Nothing may be
   implemented on an ambiguous number.
2. **Settle the upstream base.** Phase 2A.2-T (and, if the first candidate is to be exercised against
   recurring collection, R2) must be merged and closed, or the candidate must be explicitly scoped to a
   recorded base with the dependent selectors unused. A candidate may not be reviewed against a base
   that does not contain the schema it verifies.
3. **Green disposable runtime.** The local test runtime must be able to run a fresh install, an
   upgrade from Schema 27/28/29, and the concurrency runner before Phase-U suites are added.
4. **Owner decisions on the three seeded default policies.** Confirm the recorded defaults
   `INTRO_PAYABILITY_POLICY = non_payable`, `STUDENT_NO_SHOW_COMPENSATION_POLICY = payable`,
   `INTERRUPTION_COMPENSATION_POLICY = payable`, or supply the owner's values. The
   `FINANCE_STATEMENT_TIMEZONE` value must be supplied by the academy before the first real statement
   is issued.
5. **Owner confirmation of the accounting boundary.** Confirm that this phase records what the academy
   owes the Teacher and performs no payout, no bank/tax data handling, no invoice and no ledger posting
   (§22), and that no external accounting system will be treated as a source of payability, rate,
   snapshot, statement or correction truth.
6. **Close the documentation debt in the same candidate** so a reviewer never reads stale prose:
   `README.md` (active/previous candidate rows), `docs/DELNAVAZAN-CORE-CONTINUITY.md` (state table and
   next action), `docs/ARCHITECTURE.md` (§1 override and the module table's Finance row),
   `docs/MODULE-BOUNDARIES.md` (a Phase 2A.2-U boundary block plus §7's owned/may-use/must-not lists),
   `docs/MIGRATION-STRATEGY.md` (a `030_finance_payability_rate_statement_authority` entry),
   `docs/DATA-MODEL.md` (Finance storage, the state-separation matrix's Payability row and §20's
   remaining-design-work list), `docs/PRODUCT-DECISIONS.md` (§12 gains the implemented decisions and
   keeps the deferred ones deferred), `docs/SECURITY.md` (§12's Finance sentence gains the no-ledger /
   no-payout boundary), `docs/CHANGELOG.md`, and a new `docs/FINANCE-POLICY-REGISTRY.md` mirroring the
   commercial registry's Class-A/Class-B structure and drift-prevention rules for the four finance
   keys and the structural invariants of §5.
7. **Record who owns the legacy report** during coexistence: the operational plugin keeps the live
   teacher-payment report until the Phase 9 cutover is separately authorised, and the Platform's
   statements are additive evidence until then.
8. **Decide the first statement coverage.** Confirm that the first periods will cover only
   post-Schema-030 Lessons (§16.3), or authorise a bounded historical capture path with its own
   evidence rules before implementation.
9. **Confirm the capability repair contract** for five new administrator capabilities and their removal
   from `dzn_teacher` and student roles, including the fresh-install path.

## 21. Open owner decisions (deliberately not chosen here)

| Decision | Why this phase must not choose it | What ships instead |
| --- | --- | --- |
| Whether pre-Schema-030 Lessons may be captured historically, and under what evidence | It is a data-provenance and finance-sign-off decision, not a domain rule | No backfill; `snapshotDebt` and `lesson_missing_from_statement` report the gap; historical capture needs its own authorised slice |
| Whether correcting an **issued** statement requires a second-person attestation | A control decision about money | One capability-gated command with evidence; the correction, its actor and its supersession are fully recorded and visible |
| Payout execution, remittance, bank/IBAN/card handling, tax and statutory documents | Out of this phase entirely; they carry legal and financial-accounting obligations | No payout state, no bank detail, no tax field, no invoice; the issued statement is the recorded obligation and nothing more |
| Carry-forward, offsetting or netting of a downward correction across periods | It changes what "a period's payable total" means | A correction is expressed as a superseding statement version with recomputed lines; no negative line, no offset, no carry-forward |
| Multi-currency Teachers (one Teacher with rates in two currencies) | It is a commercial policy decision | `currency_mismatch_for_statement` blocks issuance and is reported; no conversion exists |
| `per_hour` (or any duration-based) compensation basis, and its exactness rule | It requires a rounding/partial-delivery decision | Only `per_session` is admissible; a second basis needs a new contract and its own exactness rule |
| Calendar-month statement periods and their month-end rendering | A convenience/UX rule that must not become authority | Explicit half-open UTC ranges with a recorded IANA timezone; a month helper may be added later without changing the authority |
| Teacher-facing statement self-service (Portal) | Portal authority is a later phase | Administrator-only screens; no front-end surface, no public route |
| Whether the academy's own revenue/cost reporting is in scope | It is a broader finance product scope | Finance records teacher compensation facts only; student revenue facts stay with R1/R2 |
| Retention and purge policy for Finance rows | Retention is a separate owner decision | Append-only storage; no purge path in this phase |

## 22. Exclusions and explicit non-authorisation

This contract authorises no schema, migration, service, capability, test, route, option, template or
configuration to be written, and no implementation, review, merge, deployment, production access,
production cutover, Amelia write or Amelia removal.

It creates no general ledger, journal, chart of accounts, trial balance, accrual, deferral, revenue
recognition rule, tax/VAT rule, invoice, credit note, remittance advice, payout, payment instruction,
bank/IBAN/card detail, tax identifier or accounting-system integration. It records no student price,
discount, adjustment, obligation, settlement, funding plan or refund consequence, and it changes no
Lesson, Term, Enrolment, schedule version, delivery outcome, attendance case/decision/evidence, academy
obligation, Teacher Assignment, capacity claim, provider account, provider mapping, secret or payment
execution row.

It makes no provider call, holds no credential, schedules no event, sends no notification, exposes no
public route, touches no Theme/NIU or portal surface, and decides none of the open decisions in §21.

## 23. Definition of done

- Schema 030 / migration `030_finance_payability_rate_statement_authority` is additive, repeat-safe
  from Schema 27/28/29, leaves every existing row and column unchanged, seeds exactly the three
  declared default policy rows with **no** seeded `FINANCE_STATEMENT_TIMEZONE` row, and is proved by its
  own verifier running at all three call sites.
- Effective-dated teacher rates exist with explicit scope, currency, half-open intervals, a single
  `effective_until` closure, an at-most-twice one-way status lifecycle and no retroactive rewrite; a
  rate referenced by a snapshot can never be withdrawn or rewritten, and resolution is by the interval
  that covers the requested instant rather than by any live marker — with withdrawn coverage detected at
  the winning specificity **before** any scope fallback, so a withdrawn course row never falls back to a
  broader rate — so a delayed capture of an earlier Lesson resolves the rate that was in force at its
  locked instant and a future-effective successor resolves safely.
- `finance_policies` is append-only in value with exactly one auditable status column (the two
  conditional moves of §6.1, at most twice per row and always in the declared direction, and no other
  mutation), where **unset is the absence of a covering version and never a null-valued row**; it
  resolves by the version that covers the requested instant — a `superseded` version is normal history
  and a retracted version resolves to unset rather than to a neighbour — and every refusal, blocker,
  exception, finding and skip path uses one code from the single allowlist of §5.2.1.
- The policy registry is a first-class Finance command domain: every `record`/`supersede`/`withdraw`
  writes one digest-only `finance_policy_commands` result row with its typed `result_policy_id` and one
  digest-only audit row, and the seeded singleton `finance_policy_roots` row is the global policy
  serialisation root that orders a policy change against **every policy consumer** — statement
  `draft`/`issue`, snapshot `capture` and payability `evaluate`/`override` (exclusively by the mutation,
  shared by the consumers) — while two Teachers still never contend; `resolveException` records its typed
  `exception_id`/`result_exception_id` pair and selects its root from the **target exception** — a
  teacher-scoped exception under that Teacher's finance root, a teacher-less exception under the shared
  global policy root — so no declared Finance command is left without a durable command result, a declared
  parent and a root that matches its target's scope.
- No policy recording can invalidate a recorded Finance fact: a version whose `effective_from` is not
  strictly later than its key's recorded consumption maximum is refused
  `policy_effective_from_precedes_recorded_consumption` with a durable reason, no version row and no
  superseded predecessor; a version recorded where nothing has been consumed stays admissible, including
  one dated into the past, so historical resolution (U-D4) is unweakened; a retraction rewrites no
  snapshot, evaluation or statement and carries exactly the declared audited consequences of §6.3; and the
  §13.4 rule 14 coverage invariant is proved over every recorded pair and triple (§6.3, §13.4, §18, U-D19).
- Every Finance write is serialised under one of the two roots of §15.1 and arbitrated by a declared
  command row of §13.2, and the only tables a Finance code path writes outside the twenty-one declared
  Finance tables are the two declared infrastructure seams of §15.7 — digest-only
  `platform_audit_events` evidence and the §17 `platform_outbox` intents. An audit row carries identifiers
  and digests only; an outbox row carries the seam's own `aggregate_type`/`aggregate_id`/`event_type`
  identity columns plus only the seam's mandatory insert metadata (the derived `idempotency_key`, the
  initial `pending` state and the explicit `NULL` lease and legacy-identity columns, §15.7), and the
  permitted Finance facts are hydrated by Phase S from the declared aggregate the row names — no payload
  column is added and no amount, period bound, currency, reason code, entity id beyond the row's own
  `aggregate_id`, template, recipient or channel address is ever written to it. Phase U initialises that
  initial pending state and owns nothing after it. Both seams are insert-only, both are inside the transaction of the
  Finance row they evidence (a business refusal's evidence is the declared exception of §15.8), and the
  §18 source scan proves they are the only non-Finance writes.
- Every command outcome is exactly one of the two declared classes of §15.8: a **business refusal**
  commits exactly its refused command row, the matching `finance_exceptions` row and their digest-only
  audit rows while the attempted mutation rolls back, and raises no §17 intent; a **persistence or
  corruption failure** rolls the whole command back — no Finance row, no command row, no exception, no
  audit row and no intent — and fails closed visibly rather than repairing, defaulting or retrying into a
  different answer.
- Every declared `*_id` column is the leading column of a declared named index, every command result is
  a typed `result_*_id` reference with a declared parent (no polymorphic `result_id` exists), no
  declared parent is outside §13.3, and the legacy `lesson_schedule_versions` table is not a Finance
  parent anywhere.
- Exactly one immutable snapshot exists per canonical Lesson, derived from the rate in force at the
  recorded occurrence-start instant, with an exact amount, an explicit currency and a re-verified
  derivation digest; a Lesson with no effective rate has no snapshot and a recorded blocker.
- Payability is a versioned derivation from canonical facts, with `pending` blocking issuance and an
  audited, additive override; no legacy flag, provider value or caller input can establish it.
- Statements are single-currency, per Teacher and half-open period, with immutable lines, recomputed
  totals, a fail-closed issuance gate, counted archive exclusions, visible zero-value introductory
  lines, and append-only withdrawal and supersession; issuance records its `issued_at`/`issued_by`
  evidence exactly once inside the `draft → issued` transition, and an issued statement's lines, totals,
  currency, period and issuance evidence can never change.
- Reconciliation is read-only, exact-integer and finding-coded; it repairs nothing, tolerates nothing
  and reports every difference with its two exact values.
- Every correction is append-only, names its exact target and digest, restates the intro payability
  policy pair for an `introductory` Lesson so it is durably re-verifiable, leaves the target intact, and
  — when it would change an issued statement — is paired with that statement's supersession.
- Five administrator capabilities exist, are repaired per capability, and are absent from the Teacher
  and Student roles; no Finance route or front-end surface exists.
- Every §18 suite passes on the disposable runtime from a fresh clone with no network access and no
  real credential, and the adjacent L/M/M0/N/O/P/Q/R1/R2/T suites stay green.
- No ledger, invoice, tax, payout, bank detail, provider call, credential, notification delivery,
  Theme change, merge, deployment or production access occurred, and every decision this phase must not
  take remains recorded as open in §21.
