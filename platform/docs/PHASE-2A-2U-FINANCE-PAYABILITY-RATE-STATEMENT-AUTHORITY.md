# Phase 2A.2-U — Finance, Payability, Effective-Dated Teacher Rates, Statements & Audited Corrections (Schema 30)

**Status:** implementation candidate — **independent review correction round 5 applied** (the review of
`f5138509a0a256df335015b1ef1d6f42dfd06b96` / tree `85db4f8afc99159e4d2f3c8e294d1247d820a899` returned
FAIL — CORRECTION REQUIRED with one blocking finding on §15.3 — `corrected_rate_amount_minor` was omitted
from the correction's canonical command facts, so a second, self-consistent correction that differed only
in its corrected rate amount could replay over the recorded one; it is closed, with the round-4, round-3,
round-2 and round-1 corrections retained — see §6–§9). Not authoritative until independently reviewed and
merged; not deployed and not merged.
**Schema:** 30 / migration `030_finance_payability_rate_statement_authority`.
**Build:** `phase2a2u-finance-payability-rate-statement-20260925.1`.
**Contract:** `docs/PHASE-2A-2U-FINANCE-PAYABILITY-RATE-STATEMENT-AUTHORITY-CONTRACT.md`
(SHA-256 `402f1334dd169a62aea450929d3bee25a116930c3e93bf69fa175a41bed6d79b`; correction round 2 adds
§0g and the §6.2/§7.2/§9.2/§9.3/§10.5/§12.2/§13.5/§15.3/§15.4/§15.5 amendments, correction round 3
adds §0h — the §15.3 replay re-verification the sections already required — and correction round 4 adds
§0i and the §15.3 amendment that declares the exact typed result shape of every operation, and correction
round 5 adds §0j and the §12.2/§15.3 amendments that carry the corrected rate amount in the correction's
canonical command facts).
**Base:** `b36561dc6bb6e87fd142a28ae67fbc4f2fdc9279` — the materialised Phase 2A.2-T candidate tree at
Schema 29. Phase T (and R2, V) remain unmerged candidates; this candidate is therefore **explicitly
scoped to that recorded base**, exactly as contract §20 prerequisite 2 allows, and it consumes no
R2/T-only selector.

## 1. What this candidate implements

| Contract section | Surface |
| --- | --- |
| §6 policy authority | `src/Core/Application/Finance/FinancePolicyService.php`, `FinancePolicyRepository`, `finance_policies`, `finance_policy_commands`, the seeded singleton `finance_policy_roots` |
| §7 effective-dated rates | `TeacherRateService`, `TeacherRateRepository`, `finance_teacher_rates`, `finance_teacher_rate_events`, `finance_teacher_rate_commands`, `FinanceRateIntegrity` |
| §8 per-Lesson snapshots | `LessonFinanceSnapshotService`, `FinanceSnapshotRepository`, `finance_lesson_snapshots`, `finance_snapshot_corrections`, `finance_snapshot_commands`, `FinanceSnapshotIntegrity` |
| §9 payability | `LessonPayabilityService`, `FinancePayabilityRepository`, `finance_payability_evaluations`, `finance_payability_overrides`, `finance_payability_commands`, `FinancePayabilityIntegrity` |
| §10 statements | `TeacherStatementService`, `FinanceStatementRepository`, `finance_statements`, `finance_statement_lines`, `finance_statement_events`, `finance_statement_commands`, `FinanceStatementIntegrity` |
| §11 reconciliation | `FinanceReconciliationService`, `FinanceReconciliationRepository`, `finance_reconciliation_runs`, `finance_reconciliation_findings`, `finance_reconciliation_commands`, `FinanceReconciliationIntegrity` |
| §12 audited corrections | `FinanceCorrectionService` (snapshot correction, payability override, statement supersession) |
| §5/§16 vocabulary and upstream facts | `FinanceRule`, `FinanceIdempotency`, `FinanceSupport`, `FinanceFacts` |
| §14 reads and screens | `Read/TeacherRateReadService`, `Read/LessonFinanceReadService`, `Read/TeacherStatementReadService`, `Read/FinanceReconciliationReadService`, five administrator-only controllers under the existing Platform menu |
| §13/§13.5 storage | `Migrator::install_finance_payability_rate_statement_authority()` — twenty-one `dbDelta` tables, the single `finance_policy_roots` row and the three declared default policy versions |
| §13.4 verifier | `Migrator::verify_finance_payability_rate_statement_schema()` at all three declared call sites |
| §14.2 capabilities | `dzn_manage_finance_policies`, `dzn_manage_teacher_rates`, `dzn_manage_lesson_payability`, `dzn_manage_finance_statements`, `dzn_view_finance_authority` — repaired per capability and removed from `dzn_teacher` |
| §17 intents | `TEACHER_STATEMENT_ISSUED`, `TEACHER_STATEMENT_SUPERSEDED`, `FINANCE_RECONCILIATION_EXCEPTION_RAISED`, written identity-only through the unchanged `platform_outbox` seam |
| §18 suites | `tests/phase-2a2u-contract.php`, `-replay-unit.php`, `-migration-runtime.php`, `-runtime.php`, `-statement-runtime.php`, `-reconciliation-runtime.php`, `-corruption-runtime.php`, `-failure-runtime.php`, `-concurrency-runner.sh` (+ setup/worker/verify), `-fixture.php` |

## 2. How the locked decisions are expressed

- **U-D4 locked instant.** `FinanceFacts::context()` resolves the Lesson's effective Phase-O outcome
  anchors and falls back to the applicable Phase-N schedule version (canonical) or the current
  `lesson_schedule_versions` row (a legacy introductory Lesson). The recorded
  `snapshot_instant_utc`/`snapshot_boundary` is taken verbatim from that anchor and never recomputed.
- **U-D6/U-D19 registries.** Both effective-dated registries carry the same guard: a rate whose
  `effective_from` is not strictly later than the Teacher's greatest snapshot instant is refused
  `rate_effective_from_precedes_snapshot`, and a policy version whose `effective_from` is not strictly
  later than its key's recorded consumption maximum is refused
  `policy_effective_from_precedes_recorded_consumption`. The consumption maximum is a bounded read of
  the declared rows through their declared indexes (`FinancePolicyRepository::consumptionMaximum()`), and
  the verifier proves the equivalent coverage invariant over the recorded pairs and triples.
- **§15.1 one root, chosen from the target's scope.** `FinanceSupport::lockPolicyRootThenTeacher()` is
  the only ordering used by a policy consumer; `lockPolicyRoot(true)` appears only in the three mutating
  policy commands; a period-wide reconciliation `run()` and a `resolveException()` on a teacher-less
  exception take the global policy root shared, and a Teacher-scoped `run()`/`resolveException()` takes
  that Teacher's root and no policy root.
- **§15.8 two outcome classes.** `FinanceSupport::runCommand()` runs the attempt inside its own
  transaction under the selected root, rolls back on a `FinanceRefusalException` and then commits exactly
  the refusal evidence (refused command row, matching `finance_exceptions` row, digest-only audit rows)
  in the declared refusal-evidence transaction. A fail-closed validator whose message is a declared
  exception reason code is converted into that refusal; any other failure rolls the whole command back.
- **§15.7 allowlist.** Every Finance write goes through `FinanceSupport::insertRow()` /
  `compareAndSwap()` (declared-table guard) or the shared boundary's audit/outbox writes; no Finance code
  path names any other table.

## 3. Implementation notes a reviewer should read before the runtime gate

These are the places where the contract states an outcome without fixing every mechanical detail. Each
is implemented conservatively and is flagged here rather than left implicit.

1. **Legacy, non-introductory Lessons.** A canonical Lesson is `record_model='canonical_term_lesson_v1'`
   with kind `standard`/`replacement`; an `introductory` Lesson is the legacy-classified Lesson with no
   Term that Phase Q already treats as authoritative. Any other Lesson row encountered inside a
   statement/reconciliation period (for example a legacy `standard` row) is not a Finance fact and fails
   the derivation closed with `finance_lesson_kind_not_allowed`, exactly as contract §8.5 declares —
   Finance never derives from a non-canonical Lesson. Historical capture of pre-Schema-030 Lessons stays
   the open decision of contract §21.
2. **Snapshot corrections asserting a rate that does not cover the snapshot instant.** Contract §12.2
   admits such a correction only with explicit historical-rate evidence. This candidate records the
   correction **and** an informational `snapshot_derivation_mismatch` exception carrying the evidence
   (so the difference appears in reconciliation and is never hidden), rather than refusing the operator's
   evidenced assertion. The correction still has to be complete (`snapshot_correction_incomplete`) and
   still has to be paired with any affected issued statement's supersession
   (`statement_supersession_required`).
3. **A correction that would change an issued statement** is refused `statement_supersession_required`
   until the operator has run the statement supersession; the pairing is therefore a two-command
   authorisation, never a silent rewrite.
4. **A period with no finance-relevant Lesson** has no derivable single currency, so drafting it is
   refused `statement_derivation_mismatch` rather than inventing a currency for an empty statement.
5. **Two policy keys are read but only the applied one is recorded.** `evaluate()` and `capture()`
   resolve the three payability keys at the snapshot instant and record only the version the applied
   derivation row actually consumed; an unset key that governs the fact blocks the fact
   (`finance_policy_unset`), an unset key that governs nothing does not.
6. **Reconciliation inputs.** `legacy_flag_differs` is emitted from the controlled comparison input
   (`legacy_comparison`) of a run, and `provider_evidence_unmatched` from recorded, provider-neutral
   R1 evidence whose processing state is not `accepted` — both as visibility, never as authority, exactly
   as §11.1/§11.3 require. A run that cannot hydrate a Lesson records the run `failed` with
   `upstream_aggregate_invalid` and appends no unproven finding.
7. **Confirmed Schema 030 (contract §20 prerequisite 1).** Owner brief said "029"; 029 is owned by the
   Phase-T candidate, so this candidate targets the next free identifier, `030_finance_payability_rate_statement_authority`,
   and re-assigns nothing. Confirming 030 remains the first acceptance-gate item.

## 4. Evidence status — read this before accepting the candidate

| Evidence | Status |
| --- | --- |
| Source contract suite (`tests/phase-2a2u-contract.php`) | **Executed and passing** under a PHP 8.5.8 WASM CLI (`php-wasm`, PHP 8.5.8, no WordPress and no database): `phase-2a2u-contract: OK`. Two defects in the suite itself were found and corrected with it — a malformed `str_contains()` needle for the per-capability repair and a closure `use` clause carrying a default value in `tests/phase-2a2u-corruption-runtime.php` |
| §15.3 replay re-verification unit suite (`tests/phase-2a2u-replay-unit.php`) | **Executed and passing** under the same PHP 8.5.8 WASM CLI, with no WordPress and no database: `phase-2a2u-replay-unit: OK`. It loads the four real classes the shared helpers live in (stubbing only `wp_salt()`, the digest key) and proves the declared answers of a replay: a `refused` row converges on its own reason code, a run may replay `completed` or `failed`, a non-declared state, an absent or deleted typed result, a result naming another aggregate row and a result that no longer reproduces the command payload all fail closed with `command_replay_conflict`, and a matching result is returned. Round 4 adds the declared typed result columns and per-operation shapes and proves that a substituted secondary typed result, an absent required typed result and a secondary result the operation never records all fail closed `command_replay_conflict` |
| §15.3 correction-payload reconstitution proof (inside `tests/phase-2a2u-replay-unit.php`) | **Executed and passing**: the facts the correction replay rebuilds from a recorded correction row reproduce the recorded `command_payload_digest` exactly, while a substituted corrected **rate** amount, a substituted corrected derived amount or a substituted corrected rate row is refused `command_replay_conflict`; the builder's fact set is proved to carry the corrected rate amount and the corrected derived amount as separate facts (round 4, §8; round 5, §9) |
| PHP syntax of the whole candidate | **Executed and passing**: all 461 `.php` files of `src/`, `tests/`, `delnavazan-platform.php` and `uninstall.php` tokenise and parse cleanly under PHP 8.5.8 (`token_get_all(..., TOKEN_PARSE)`) |
| Migration/verifier at all three call sites | Implemented; **not executed** |
| Runtime, statement, reconciliation, corruption, failure suites | Written; **not executed** — the corruption suite carries one §15.3 corruption-replay probe per command family, **three payload/digest probes for the correction family** (corrected rate amount, corrected derived amount and prior-snapshot digest; round 5, §9) **and one substituted-secondary-typed-result probe per family** (round 4, §8), and its fixture flow is corrected so a canonical capture resolves the Lesson's own recorded outcome anchor (round 3, §6) |
| Concurrency runner (`phase-2a2u-concurrency-runner.sh` + setup/worker/verify) | Written; **not executed** |
| Adjacent L/M/M0/N/O/P/Q/R1/R2/T regressions | **Not re-run** |

The implementation environment has no MariaDB and no reachable Docker daemon (the container socket is
denied by the sandbox), so no migration, authority, corruption, failure or concurrency evidence exists
yet: those suites need the disposable WordPress + MariaDB runtime, and executing every §18 suite on it is
a mandatory acceptance gate before this candidate may be merged. The static half of the gate is now
executed: the source contract suite passes (including its source scans over
`src/Core/Application/Finance/**` for the declared command result states, the reason-code allowlist, the
typed command results, the root discipline, the §15.3 replay re-verification of every command family, the
§15.7 write allowlist and the §17 intent shape), the shared §15.3 replay helpers are behaviourally proven
by `tests/phase-2a2u-replay-unit.php`, and every PHP file in the candidate parses.

## 5. Deliberate non-authorisations preserved

No ledger, journal, chart of accounts, tax/VAT rule, invoice, payout, bank/IBAN/card detail, remittance,
provider call, credential, notification delivery, public/Portal route, Theme change, Amelia write, merge,
deployment or production access exists in this candidate. Student pricing, obligations, settlements,
funding, collection and refund facts stay with R1/R2/T and are read-only here. Every open owner decision
of contract §21 remains open.

## 6. Independent review correction round 2 (implementation candidate)

The independent review of `86d57606cabcddba15d076edfe14fb4e7257e60f` (tree
`6010181bfbf66e01fa49154c9ba266d1dd4888c4`) returned **FAIL — CORRECTION REQUIRED** with six blocking
findings. All six are closed on this descendant (additive history only: no reset, no rebase, no amend, no
force-push), and contract §0g records them in the sections that govern them.

| # | Finding | Where the correction lives |
| --- | --- | --- |
| 1 | Command rows omitted the `NOT NULL` `result_state`, and refusal evidence kept the attempt's success state | `FinanceRule::COMMAND_SUCCESS_STATES`/`COMMAND_REFUSAL_STATE`/`COMMAND_FAILED_STATE`/`COMMAND_RESULT_STATES` + `FinanceRule::commandSuccessState()`; one declared success state on every `capture`/`correct_snapshot`/`evaluate`/`override`/`draft`/`issue`/`withdraw`/`supersede`/`run`/`resolve_exception` command row; `FinanceSupport::commitRefusal()` writes `result_state = 'refused'` with `NULL` typed results. Runtime, statement, reconciliation and failure suites now prove both paths on all six command tables |
| 2 | A successor rate claimed the scope's live slot before its predecessor released it (`UNIQUE teacher_scope_slot`) | `TeacherRateService::record()` closes and supersedes the predecessor first, each move a conditional statement requiring an affected-row count of `1`, inside the same transaction; `withdraw()` measures the interval-free proof only where it writes a closure. Contract §7.2 states the order |
| 3 | An appended evaluation/correction pre-claimed the one applicable slot (`UNIQUE lesson_applicable`/`snapshot_applicable`) | The declared two-statement protocol of contract §15.4: append with `applicable_slot = NULL`, release the predecessor, then claim the slot with its own conditional statement (`FinancePayabilityRepository::claimApplicable()`, `FinanceSnapshotRepository::claimApplicable()`), both counts checked. Runtime + statement suites prove re-evaluation, override and a second correction |
| 4 | `record()` silently superseded the predecessor, so the declared `supersede()` command could never succeed | `FinancePolicyService::record()` inserts one version and moves nothing (`policy_id`/`result_policy_id` both name the row it wrote); `supersede()` performs the single conditional `active → superseded` move with its own command row, audit evidence and affected-row count. The runtime suite proves the two-command lifecycle and the refusal of a second supersession |
| 5 | Issuance never compared the stored line with the current effective snapshot/evaluation | `TeacherStatementService::assertLineFresh()` compares snapshot id, applicable correction id, payability-evaluation id, disposition, basis code, rate row/version, compensation basis, currency and the exact recomputed amount; a stale draft is refused `statement_derivation_mismatch` with its recorded totals untouched and is withdrawn + re-drafted (§10.5 rule 6, §15.5). The statement suite proves refusal, withdrawal, re-draft and re-issuance against a corrected amount |
| 6 | The reconciliation `run()` closure never captured `$payload` | `$payload` is captured; the reconciliation suite adds the identical replay (one run, one command row, no second run) and the conflicting replay refused `command_replay_conflict` |

Two further corrections are declared in contract §0g rather than left implicit, because the corrected
policy lifecycle and the declared issuance gate are only coherent with them:

- **The policy verifier refused a live installation.** The seed assertions were implemented against the
  *fresh* state ("no row for `FINANCE_STATEMENT_TIMEZONE`", "at most four rows in total"), which the
  declared `record()`/`supersede()` lifecycle necessarily leaves behind — the verifier runs on every
  current-schema verification, so the first recorded timezone policy would have failed closed on every
  subsequent request. It now asserts the seed **shape**: the only migration-authored (`recorded_by = 0`)
  rows are the three declared keys' version 1, and no migration-authored row names the timezone key.
- **The rate-withdrawal guard judged time it does not write.** Withdrawing a rate a successor already
  closed wrote no interval yet was measured against the successor's interval, so a superseded rate could
  not be retracted. The proof now runs only where a closure write happens.

Two defects in the suites themselves are corrected with this round: a closure `use` clause carrying a
default value (a syntax error that made `tests/phase-2a2u-corruption-runtime.php` unparseable) and a
malformed `str_contains()` needle in `tests/phase-2a2u-contract.php`; the concurrency fixture's
`concurrent_policy_record` mode also raced an instant its own seeded predecessor already claimed, so
neither contender could succeed and the mode's declared "one succeeds, the loser refuses
`finance_policy_timeline_overlap`" outcome was unreachable — its competing instant is now admissible for
the winner.

**Still outstanding, and flagged rather than silently rewritten:** the §18 *runtime* suites need the
disposable WordPress + MariaDB runtime to be executed, and they require fixture-flow work before they can
pass — their occurrence helper schedules then releases the canonical schedule version (so a capture has
no applicable anchor unless an outcome is recorded) and leaves the Lesson `authorised` (so §8.2's
`snapshot_lesson_not_finalised` gate refuses the capture), the outcome helpers require an occurrence that
has already ended, and several calls pass a 60 *minute* duration where the suite's own comment intends one
minute. Those are test-fixture defects, not candidate behaviour, and they are recorded here so the
runtime gate is executed against a corrected fixture rather than assumed green.

## 7. Independent review correction round 3 (implementation candidate)

The independent review of `97a572937e07c021bf9c0fc9c65da23cdae92e08` (tree
`370eda98f7aa84182c7d06c91cbca7caf79637c2`) returned **FAIL — CORRECTION REQUIRED** with one blocking
finding, U-C9-BLOCK-001. It is closed on this descendant (additive history only: no reset, no rebase, no
amend, no force-push) and contract §0h records it in the section that governs it.

| # | Finding | Where the correction lives |
| --- | --- | --- |
| 1 | Every command family's `replay()` returned the recorded typed result id without re-loading or validating the authoritative result row, so a deleted, corrupted or mismatched result could be reported as a successful replay (§15.3) | `FinanceRule::commandOutcomeStates()` declares the one non-refusal outcome of each operation (plus `failed` for a reconciliation `run`, never `refused`); `FinanceSupport::assertReplayState()` refuses any recorded state outside that declaration, `FinanceSupport::replayResultRow()` re-loads the typed result under the root the command already holds (locking named-index read), fails closed `command_replay_conflict` when the row is absent or no longer carries the command's own selectors, and `FinanceSupport::assertReplayPayload()` proves the recorded result still reproduces the exact command payload; each service's `replay()` then re-proves its own section — policy vocabulary and recorded version payload, the §7 rate integrity proof and exact closure/retraction, the §8 snapshot digest with rate row/version/coverage, the §9 evaluation derivation digest with its own snapshot and (for `override`) the override row that names it, the §12 correction derivation digest with its corrected rate and prior-snapshot digest, the §10 statement totals/derivation and timezone triple plus the per-operation recorded outcome, and the §11 run/finding proof with the recomputed findings digest and (for `resolve_exception`) the resolution evidence |

**Coverage added with the correction, and what is executed.** `tests/phase-2a2u-contract.php` (source scan)
now asserts the shared helpers and, for each of the seven command families, that its `replay()` re-loads
its own typed result and re-proves it with its own section's validator, so the correction cannot silently
regress. `tests/phase-2a2u-replay-unit.php` is a new, pure §15.3 unit suite — no WordPress, no database —
that loads the four real classes the helpers live in and proves the declared answers: a `refused` row
converges on its own reason code, a run replays `completed` or `failed`, and an undeclared state, an
absent or deleted typed result, a result naming another aggregate and a result whose recorded fact has
moved all fail closed `command_replay_conflict`. Both suites are **executed and passing** under the PHP
8.5.8 WASM CLI in the implementation environment (`phase-2a2u-contract: OK`,
`phase-2a2u-replay-unit: OK`), and all 461 candidate PHP files parse cleanly. Finally,
`tests/phase-2a2u-corruption-runtime.php` gains one corruption-replay probe per command family (policy,
rate, snapshot, payability, correction, statement, reconciliation, payability override and exception
resolution): each records a real command, corrupts the exact fact its own §15.3 re-derivation reads —
including the absent-result case, which deletes the recorded policy version row — proves the identical
replay fails closed, restores the row exactly and proves the identical replay then converges on the same
recorded result.

**Fixture flow corrected so that coverage can actually run.** The corruption suite's own fixture flow is
corrected in this round: its three occurrences are one-minute occurrences whose delivery outcome is
recorded after they end and whose Lessons are then completed, so each capture resolves the Lesson's own
recorded outcome anchor instead of failing `occurrence_anchor_missing` (the helper releases the schedule
version, so the outcome is the anchor) or `snapshot_lesson_not_finalised`. This is the fixture defect §6
flagged; the same repair is **still outstanding** for `-runtime.php`, `-statement-runtime.php`,
`-reconciliation-runtime.php` and `-failure-runtime.php`, which remain written-but-not-executed, and the
disposable WordPress + MariaDB runtime is still required to execute every §18 authority, migration,
corruption, failure and concurrency suite. No runtime evidence is claimed for this candidate.

## 8. Independent review correction round 4 (implementation candidate)

The independent review of `5e0daf221918634a047c83e9b5034b9f833ea7d9` (tree
`0968a4f6b96ea828848354b09a473c2f3001cac4`) returned **FAIL — CORRECTION REQUIRED** with two blocking
findings, U-C10-BLOCK-001 and U-C10-BLOCK-002. Both are closed on this descendant (additive history only:
no reset, no rebase, no amend, no force-push) and contract §0i records them in §15.3, the section that
governs them.

| # | Finding | Where the correction lives |
| --- | --- | --- |
| 1 | The correction replay never reconstituted and checked the recorded command payload, so a corrupted `result_correction_id` pointing at a *different* valid correction for the same Lesson, snapshot and reason converged on the substituted result (its own derivation digest passes) | `FinanceCorrectionService::correctionFacts()` is now the one place the `correct_snapshot` command facts are built — the write path feeds it the canonical `corrected_rate_id`/`corrected_rate_version`/`corrected_derived_amount_minor`/`corrected_currency` (`canonicalInt()`/`canonicalCurrency()`), and `replay()` reconstitutes exactly those facts from the **re-loaded correction row** and proves them with `FinanceSupport::assertReplayPayload()` against the recorded `command_payload_digest`. A correction whose corrected rate row, version, corrected amount or currency differs is refused `command_replay_conflict` even though its own recorded derivation digest is self-consistent. The replay also cross-links the command's typed `result_snapshot_id` (and its `correction_id` selector) to the snapshot and correction row it re-loaded |
| 2 | Override replay verified the override reached through `evaluation->override_id` but returned the unverified `command.result_override_id`; the same unverified secondary-result pattern existed for the snapshot `capture`'s `result_correction_id` and the reconciliation `run`'s `result_exception_id` / `resolve_exception`'s `result_run_id` | §15.3 now declares the exact typed result shape of every operation (`FinanceRule::COMMAND_RESULT_COLUMNS`, `FinanceRule::COMMAND_OPERATION_RESULTS`, `FinanceRule::commandOperationResults()`) and every family re-proves it through the new shared `FinanceSupport::assertReplayResultShape()`: every required typed result of the operation must be present, and every other typed result column of that same table must be `NULL`. `LessonPayabilityService::replay()` returns the **verified** override row the re-loaded evaluation carries and additionally requires the command's `result_override_id` and `override_id` selector to name that row and its `evaluation_id` selector to name the override's prior evaluation; `evaluate` must name an evaluation that carries no override. `LessonFinanceSnapshotService::replay()` returns no secondary result and requires the capture's selector and typed result to be the one snapshot. `FinanceReconciliationService::replay()` returns no secondary result and cross-links each operation's selector to the row it typed |

**Coverage added with the correction, and what is executed.** `tests/phase-2a2u-contract.php` (source
scan, **executed and passing**) now asserts the two declarations, their per-operation shapes (all twelve
declared mutating operations), the shared shape helper and its two fail-closed messages, that each of the
seven command families calls `FinanceSupport::assertReplayResultShape()`, that no replay returns a
recorded typed result without re-loading it, and that the corruption suite carries the nine family probes
plus six substituted-secondary-result probes and the report-shape assertions.
`tests/phase-2a2u-replay-unit.php` (**executed and passing**, no WordPress and no database) additionally
proves the declared columns and shapes and the fail-closed answer for a substituted, an absent and an
undeclared secondary typed result, and — through reflection over the service's own private facts builder,
which loads the class without constructing it and so needs no repository — proves the correction command
facts' **canonical reconstitution**: the facts rebuilt from a recorded correction row reproduce the
recorded `command_payload_digest` exactly, while a substituted corrected amount or corrected rate row is
refused `command_replay_conflict`. `tests/phase-2a2u-corruption-runtime.php` (written, **not executed**)
gains one substitution probe per command family — correction, capture, derivation, override,
reconciliation run and exception resolution — each corrupting a *real, valid* secondary typed result (the
correction probe substitutes a second, valid correction of one snapshot, the exact substitution the review
named), proving the identical replay fails closed `command_replay_conflict`, restoring the command row
exactly and proving the identical replay then converges; it also proves that a converged override replay
reports the override its own evaluation carries and that a converged capture, run and resolution replay
report no secondary result. Every 461 candidate PHP file still parses cleanly under PHP 8.5.8.

**Still outstanding, unchanged by this round.** No runtime, migration, corruption, failure or concurrency
evidence is claimed: those suites need the disposable WordPress + MariaDB runtime, which does not exist in
this environment, and executing every §18 suite on it remains a mandatory acceptance gate before this
candidate may be merged. The fixture-flow repair §6 flagged is still outstanding for `-runtime.php`,
`-statement-runtime.php`, `-reconciliation-runtime.php` and `-failure-runtime.php`.

## 9. Independent review correction round 5 (implementation candidate)

The independent review of `f5138509a0a256df335015b1ef1d6f42dfd06b96` (tree
`85db4f8afc99159e4d2f3c8e294d1247d820a899`) returned **FAIL — CORRECTION REQUIRED** with one blocking
finding, U-C11-BLOCK-001. It is closed on this descendant (additive history only: no reset, no rebase, no
amend, no force-push) and contract §0j records it in §12.2 and §15.3, the sections that govern it.

| # | Finding | Where the correction lives |
| --- | --- | --- |
| 1 | `corrected_rate_amount_minor` is a material correction fact but was omitted from `FinanceCorrectionService::correctionFacts()`, and therefore from both the recorded `command_payload_digest` and the replay reconstitution, so a second, self-consistent correction of the same Lesson, snapshot, corrected rate row/version, corrected derived amount, currency and reason that differed **only** in its corrected rate amount could replace `result_correction_id` and replay successfully | `FinanceCorrectionService::correctionFacts()` now takes the corrected rate amount as its own canonically validated fact — `self::canonicalInt($input['corrected_rate_amount_minor']??null)` on the write path and `(int)$correction->corrected_rate_amount_minor` on the replay path — beside the corrected derived amount, so the correction's canonical payload carries the **complete material correction fact set**. A correction whose corrected rate amount alone differs therefore moves the payload and is refused `command_replay_conflict` by `FinanceSupport::assertReplayPayload()` instead of converging on the substituted row. Contract §12.2's completeness rule and §15.3's payload clause now name the restated rate amount explicitly, so the fact set is declared rather than implied |

**A second defect in the same probe family, corrected with this round.** The corruption suite's original
correction probe corrupted the recorded `corrected_derived_amount_minor` and asserted
`snapshot_derivation_mismatch`. The corrected derived amount is itself a payload fact, and §15.3's payload
re-proof runs before the family's own derivation-digest re-proof in `FinanceCorrectionService::replay()`
(`FinanceSupport::assertReplayPayload()` precedes the `FinanceRule::CORRECTION_DIGEST_FIELDS` comparison),
so that corruption actually fails closed `command_replay_conflict`. The probe now asserts the code the
service produces, and the family's own derivation-digest re-proof is kept covered by a third probe that
moves a fact the payload does not carry — the recorded `prior_snapshot_digest` — and asserts
`snapshot_derivation_mismatch`.

**Coverage added with the correction, and what is executed.** `tests/phase-2a2u-replay-unit.php`
(**executed and passing**, WordPress-free and database-free) proves through reflection over the service's
own private builder that `correctionFacts()` carries the corrected rate amount and the corrected derived
amount as separate canonical facts, that the facts rebuilt from a recorded correction row reproduce the
recorded `command_payload_digest` exactly, and that a correction whose corrected rate amount alone differs
(as well as one whose corrected derived amount or corrected rate row differs) is refused
`command_replay_conflict`. `tests/phase-2a2u-corruption-runtime.php` (written, **not executed**) now
carries three correction replay probes — corrected rate amount and corrected derived amount, each
asserting `command_replay_conflict` and convergence after exact restoration, and the prior-snapshot digest,
asserting `snapshot_derivation_mismatch` — so the correction family proves both its payload proof and its
own derivation-digest proof. `tests/phase-2a2u-contract.php` (**executed and passing**) now scans for both
new probes as well as the unit probe, so the omission cannot silently regress. Every 461 candidate PHP
file still parses cleanly (PHP 8.5.8 WASM CLI).

**Still outstanding, unchanged by this round.** No runtime, migration, corruption, failure or concurrency
evidence is claimed: those suites need the disposable WordPress + MariaDB runtime, which does not exist in
this environment, and executing every §18 suite on it remains a mandatory acceptance gate before this
candidate may be merged. The fixture-flow repair §6 flagged is still outstanding for `-runtime.php`,
`-statement-runtime.php`, `-reconciliation-runtime.php` and `-failure-runtime.php`.
