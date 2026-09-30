# Changelog

All notable changes to the Delnavazan Platform repository are documented here.
Platform phase numbers are independent of Hamnavaz phase numbers.

## Operational-readiness integration — correction round 3 — candidate, unmerged — 2026-09-27

- Closes both blocking findings of the host review of `ba47412e9b05dff53e83fa1b88f99c1dc015966e` / tree
  `7d1ad0199aa8c8ce9adf5c40e0443df6edb25bf8` (the review's `CORRECTION ROUND 3`, verdict
  `FAIL - CORRECTION REQUIRED`). The candidate stays local: nothing is pushed, no authoritative `main` is
  moved, nothing is merged or deployed, no provider, credential or external send is used, and public
  actions stay disabled by default.
- **Finding 1 — `PortalCapabilityService` ignored transaction-boundary results.** `join()` discarded the
  result of both `START TRANSACTION` and `COMMIT`, so it could run outside the required transaction or
  return the Join `302` after a failed commit; `mint()` and `transition()` had the same unchecked
  commit/begin paths, and `rotate()` / `recordRefusal()` issued an unconditional `ROLLBACK`. That
  bypassed the §15.6 all-or-nothing, fail-closed persistence invariant and the controllers' new failure
  classification.
  - Correction: every capability transaction now opens through the checked `begin()`, commits through the
    checked `commit()` and rolls back through `rollback()`. A failed `START TRANSACTION` or `COMMIT`
    throws the declared `portal_capability_persistence_failed` instead of continuing, and the rollback is
    issued only for a transaction the call actually opened
    (`$opened=false;$this->begin();$opened=true;` … `if($opened)$this->rollback();`). A failed commit on
    any path therefore returns no capability and no `302`.
  - Coverage: §10 of `tests/phase-2a2w-persistence-failure-unit.php` drives the real public Join callback
    with a failed begin and with a failed commit — the call re-raises the declared persistence failure,
    produces no response object, and appends/commits no action, capability or denial evidence — and drives
    `mint()`/`revoke()` the same way (no minted capability, no durable row). The unit's own skip guard is
    corrected too: it keyed on `WP_REST_Response`, a class the embedded
    `tests/phase-2a2w-public-rate-limit-unit.php` declares first, so the embedded §15.6 run inside
    `tests/phase-2a2w-contract.php` was silently skipped; the guard now keys on the core `wpdb` class /
    `register_rest_route()` and the embedded proof executes.
  - Source guard: `tests/phase-2a2w-blocking-findings-contract.sh` now asserts the three helpers, that the
    raw `START TRANSACTION` / `COMMIT` / `ROLLBACK` statements each have exactly one call site (inside
    those helpers), that all five transaction-bearing methods open through the checked begin helper and
    roll back only when a transaction was opened, and that the new failure-path proofs are present.
- **Finding 2 — the portal authorization registry declared two schemas/builds at once.**
  `docs/PORTAL-AUTHORIZATION-REGISTRY.md` stated Schema 31 / build
  `phase2a2w-portal-facing-services-20260926.1` in its opening and Schema 32 for the same candidate two
  paragraphs later, although source declares Schema 32 and the S build.
  - Correction: the opening now records `2ab0c71f5cc53ca8aa4241db0b2f7d100de65997` (tree
    `4f1b7273f823149688ca3b75f9ed8b268a01d802`) only as the **historical Schema-31 portal base** and
    provenance, and states exactly one current package identity for this candidate: Schema 32 and build
    `phase2a2s-notification-communications-authority-20260924.1`, with the W slice's own stamp
    `phase2a2w-portal-facing-services-20260927.2`. Its stored-table heading is now
    `Migration-031 storage contract`, so no heading re-asserts a package schema, and the same W stamp is
    aligned in `docs/ARCHITECTURE.md` and `docs/DELNAVAZAN-CORE-CONTINUITY.md`.
- Executed evidence for this round, under the local PHP 8.3/8.5 CLI WebAssembly runtime with no WordPress
  and no database: `tests/phase-2a2w-contract.php` (with both embedded behavioural proofs now executing),
  `tests/phase-2a2w-persistence-failure-unit.php`, `tests/phase-2a2w-replay-runtime.php`,
  `tests/phase-2a2w-remediation-contract.php` and `tests/phase-2a2w-theme-isolation-contract.php` all exit
  0, and the PHP-free `tests/phase-2a2w-blocking-findings-contract.sh` and `tests/runtime-path-guards.sh`
  pass. No runtime, migration, concurrency or browser evidence is claimed.
- Unchanged: no portal table, route, capability, purpose or reason code changes; public actions remain
  disabled by default; deployment, production access, provider traffic and cutover remain unauthorised.

## Operational-readiness integration — correction round 2 — candidate, unmerged — 2026-09-27

- Closes both blocking findings of the host review of `8867999f77b42b3b458592eb48ea22eaac82a1ba` / tree
  `de5f4d1725734183630a40e6856be26652c08da6` (the review's `CORRECTION ROUND 2`, verdict
  `FAIL - CORRECTION REQUIRED`). The candidate stays local: nothing is pushed, no authoritative `main` is
  moved, nothing is merged or deployed, no provider, credential or external send is used, and public
  actions stay disabled by default.
- **Finding 1 — persistence failures were converted into new “business refusal” records.**
  `PortalPublicActionController::refused()` and `PortalCapabilityController::command()` caught every
  `Throwable`, normalised an unrecognised failure such as `portal_action_evidence_persistence_failed` to
  `portal_upstream_aggregate_invalid`, and then called `recordRefusal()` in a *new* transaction — so a
  transient failed insert or commit could be followed by durable refusal evidence, contrary to §15.6's
  all-or-nothing rollback.
  - Correction: `PortalRule` now declares the two Phase-W persistence codes
    (`PERSISTENCE_FAILURE_CODES = portal_action_evidence_persistence_failed`,
    `portal_capability_persistence_failed`) and one classification point,
    `PortalRule::refusalReason(\Throwable): ?string`, which returns a reason only for a declared business
    refusal (`EXCEPTION_REASON_CODES`, never a persistence code). Both controllers classify with it
    **before** writing anything: a declared refusal keeps its exact durable evidence, while every
    persistence, corruption or infrastructure failure — including any unexpected throwable — is re-raised
    unchanged after its own rollback and appends no refusal command row, action row, denial row or audit
    row. The refused evidence of one command remains one transaction. The same split is applied to the one
    remaining catch-all on that path, the post-delegation owner handoff in
    `PortalPublicActionService::confirmAbsence()`: a declared owner refusal is still recorded as the
    refused outcome with the owner's own reason and its denial row in that one transaction, while an owner
    persistence or infrastructure failure now propagates unchanged and records neither — leaving only the
    redemption claim and delegation lease the §15.8 bound and an exact replay converge.
  - New failure-path coverage: `tests/phase-2a2w-persistence-failure-unit.php` drives the real
    `PortalPublicActionService::recordRefusal()` and both real public callbacks against an in-memory store
    that fails on request, and proves: one declared refusal still commits exactly the refused action row
    and its denial row in one transaction; a failed refused-action insert, a failed denial insert and a
    failed commit each roll *both* rows back and re-raise `portal_action_evidence_persistence_failed` with
    no committed row; a failing capability/action evidence write inside the invoked service propagates
    unchanged out of `join` and out of `absence` and appends **no subsequent refusal row** because the
    controller opens no second transaction; the post-delegation owner handoff records a declared owner
    refusal as the refused outcome with the owner's reason and its denial row, and an owner persistence
    failure leaves only the committed claim and lease with no refused outcome and no denial; and only a
    declared refusal reason classifies as a refusal.
    `tests/phase-2a2w-contract.php` now asserts the classification seam in both controllers and embeds the
    new proof, and `tests/phase-2a2w-blocking-findings-contract.sh` gains the same static assertions.
    `docs/PHASE-2A-2W-PORTAL-FACING-SERVICES-PRINCIPAL-AUTHORIZATION-CONTRACT.md` §15.6 and §18 and
    `docs/PORTAL-AUTHORIZATION-REGISTRY.md` now record the split.
- **Finding 2 — the runtime harness could not validate this Schema-32 candidate.**
  `runtime/bin/run-fresh-install.sh` and `runtime/bin/run-retained-migration.sh` verified schema `31`
  through the removed `runtime/bin/verify-schema31.sh`, and `runtime/bin/run-schema30-to-31-rehearsal.sh`
  required exactly six added portal tables — while activating the candidate also applies migration 032 and
  creates notification storage. The advertised acceptance harness therefore failed deterministically
  against its own default target.
  - Correction: the harness now targets the Schema 32 the package declares.
    `runtime/bin/verify-schema32.sh` requires the schema option *and* `DZN_PLATFORM_SCHEMA_VERSION` to be
    `32`, the ledger to carry `031_portal_facing_services_principal_authorization` and
    `032_notification_communications_authority`, the six portal tables and the eighteen notification
    tables, the eleven added `platform_outbox` columns as nullable-without-default, the pre-existing
    `status`/`attempt_count` contract, and no seeded `dzn_platform_portal_actions`. The fresh install and
    the retained migration both end with it, and the retained run additionally pins the complete `32|32`
    ledger. The 30→31 rehearsal is replaced by a shared
    `runtime/bin/schema-upgrade-rehearsal.sh <base-schema> <base-ref> <declared table>...` plus two
    declared rehearsals: `run-schema30-to-32-rehearsal.sh` (Schema 30 from immutable `86d5760`) and
    `run-schema31-to-32-rehearsal.sh` (the integrated Schema-31 base from immutable `2ab0c71`, the tip the
    Schema-032 re-land was built on). Each builds the base schema, snapshots every pre-existing table's
    schema and data (only Migrator's two ledger options excluded), upgrades in place with the candidate
    worktree, and requires the added tables to be exactly the declared set, no table to disappear, and
    every other table to be unchanged — with the Schema-032 `platform_outbox` columns projected out of the
    comparison because that slice only adds nullable columns to that one existing table. `runtime/README.md`
    (and the `Makefile`, `.env.example`, the historical-suite manifest and `tests/runtime-path-guards.sh`)
    are updated to the same Schema-32 state and now assert it.
  - One more harness-level cause of a deterministic default-target failure is removed at the same time:
    `runtime/bin/run-pure-tests.sh` no longer runs the stale Phase-1 `tests/schema-contract.php` guard,
    which forbids the `finance` storage Schema 030 legitimately added and fails identically on
    authoritative `main` (recorded as a pre-existing failure in the integration manifest). It keeps the
    parse/lint sweep and the Phase-2A.2-W contract guard, so the acceptance sequence is not aborted by a
    defect outside this package.
- **Verification in this environment** (local PHP 8.3 WebAssembly CLI; no WordPress, no database, no
  Docker daemon, no network): `tests/phase-2a2w-contract.php` passes with both embedded proofs;
  `tests/phase-2a2w-persistence-failure-unit.php` passes standalone; the new proof **fails** when run
  against the failed candidate's controllers (negative control — it reports the propagation and
  no-second-transaction probes as failures and the removed `PERSISTENCE_FAILURE_CODES` constant as
  undefined); `tests/phase-2a2w-blocking-findings-contract.sh` and `tests/runtime-path-guards.sh` pass;
  every `runtime/bin/*.sh` passes `bash -n`; the new `verify-schema32.sh` PHP body and both rehearsal PHP
  snippets parse clean; and the rehearsal comparison logic was exercised against synthetic snapshots,
  passing on the declared additive change and failing on a changed pre-existing table, an undeclared added
  table and a disappeared table.
- **Not executed**: the WordPress/MariaDB `runtime/` harness itself and every suite that needs it (no
  cached images, no reachable Docker daemon, no network). Those remain required acceptance gates before
  any merge, deployment, public enablement or cutover. The Phase-2A.2-S suites under `tests/` likewise
  remain that phase's own gates; this harness proves the Schema-032 migration's stored result and upgrade
  behaviour directly instead of re-driving them.

## Phase 2A.2-W — revocation-replay claim correction, round 9 — candidate, unmerged — 2026-09-27

- Closes the one blocking finding of the review of `2aa2333522a84254cac33eaff1179049466058c0` / tree
  `6258f152b61997c9e4c8af2afd6f764632201c66` (the review's `CORRECTION ROUND 9`): four documents — §18 of
  `docs/PHASE-2A-2W-PORTAL-FACING-SERVICES-PRINCIPAL-AUTHORIZATION-CONTRACT.md`, the portal authorization
  registry's execution-evidence paragraph, this changelog's round-8 entry and the `README.md` active-candidate
  row — recorded
  `tests/phase-2a2w-replay-runtime.php` as proving consumed-confirmation replay after the Student's
  principal link was revoked, but the suite as then written only invoked the private
  `PortalCapabilityService::owner()` with a mock owner and `requirePrincipal=false`. It consumed no
  confirmation, revoked no principal link, never reached `verifyConsumed()`/`confirmAbsence()`, and asserted
  no replay/convergence or durable-event invariant, so the claimed validation scope did not match executable
  evidence and the security-relevant guarantee it described was untested.
- Correction taken — the review's first option, a real replay proof rather than a narrowed claim.
  `tests/phase-2a2w-replay-runtime.php` is replaced with a behavioural proof that drives the real service
  classes against an in-memory stub of the portal evidence tables and the two owner ports, the same
  stubbed-store technique `tests/phase-2a2w-public-rate-limit-unit.php` already uses and §18 already accepts:
  (1) it renders a one-time Student absence confirmation through the real
  `PortalPublicActionService::renderAbsenceConfirmation()`; (2) it consumes it through the real
  `::confirmAbsence()` while the principal link is live, appending the `confirmed_submitting` claim, the
  `consumed` capability event, the `consume` command and the `submitted` outcome and moving the capability to
  `state='consumed'`; (3) it revokes the Student's principal link, after which the ordinary verification path
  refuses `portal_principal_required`; and (4) it replays the exact same handle/token/confirmation, which
  converges on the recorded `submitted` outcome with `replayed=true` and re-resolves the immutable proof
  through `verifyConsumed()` with `requirePrincipal=false`, appending no second claim, event or command.
- §18, the portal authorization registry and this changelog now describe that executed scope exactly and still
  claim no runtime, migration, concurrency or browser evidence. The round-8 entry's own scope wording, which
  described the pre-round-9 stub as if it exercised the flow, is corrected in place and flagged there.
- The contract guard `tests/phase-2a2w-contract.php` no longer asserts the stub's `revoked_at` /
  `same consumed confirmation` strings; it now asserts the rewritten suite's real seams
  (`renderAbsenceConfirmation(`, `confirmAbsence(`, `verifyConsumed(`, `requirePrincipal`,
  `portal_principal_required`, `replayed`, `consumed_action_event_id`), so deleting or weakening the proof
  fails the guard instead of silently passing.
- Re-verified in this environment against this candidate (local PHP 8.3/8.4/8.5 CLI WebAssembly runtime; no
  WordPress, no database): the replay suite prints `Phase-W revoked-principal replay coverage passed` and
  exits 0 under all three; `tests/phase-2a2w-contract.php` prints `Phase-W contract passed` and exits 0 under
  8.3 and 8.5; `tests/phase-2a2w-public-rate-limit-unit.php` passes standalone under both; and 274 of 274
  `src/**/*.php` files parse clean under `token_get_all(..., TOKEN_PARSE)` under 8.3 and 8.5. Flipping the
  suite's `requirePrincipal=false` seam back to re-proving mutable owner state (reverting
  `PortalCapabilityService::verifyConsumed()` to pass `requirePrincipal=true`) makes the replay fail with
  `portal_principal_required`, so the proof is not a tautology.
- No `src/` file changed in this round: source is byte-identical to the reviewed candidate's. Only the replay
  suite, the contract guard and those four documentation claims moved.

## Phase 2A.2-W — contract §18 revocation-replay evidence correction, round 8 — candidate, unmerged — 2026-09-27

- Closes the one blocking finding of the review of `709b4f0468017da94b1ad229bf9faa591c25af4f` / tree
  `da63f488841ee271489f6aac4b6523e0b34626b0` (the review's `CORRECTION ROUND 8`): §18 of
  `docs/PHASE-2A-2W-PORTAL-FACING-SERVICES-PRINCIPAL-AUTHORIZATION-CONTRACT.md` (the reviewed candidate's
  lines 1138–1154) omitted the tracked W suite `tests/phase-2a2w-replay-runtime.php` and its scope and
  execution status, and this changelog's round-7 entry read as a complete enumeration of the candidate's W
  evidence, leaving that suite unstated and the contract's acceptance/evidence registry incomplete.
- §18 now carries that suite as its own row with its scope and an explicit status. Scope: the
  owner-binding behaviour of a consumed absence confirmation whose Student principal link was revoked
  after consumption — re-resolution through `PortalCapabilityOwnerPort::binding()` with
  `requirePrincipal=false` reuses the immutable Lesson/schedule/student proof instead of re-proving
  mutable owner state, so the same confirmation converges. As first written the suite checked only that
  delegation seam: it invoked the private `PortalCapabilityService::owner()` with a mock owner and
  `requirePrincipal=false`, consumed no confirmation, revoked no principal link and never reached
  `verifyConsumed()`/`confirmAbsence()`, so this row's scope and the guard-needle note first recorded here
  described more than the file exercised (**round 9 replaces the stub with a real render → consume → revoke
  → replay proof, corrects this scope, and re-points the guard needles; see the round-9 entry**). Status:
  **written and executed** — the current suite passes standalone under the local PHP 8.3/8.5 CLI WebAssembly
  runtime with no WordPress and no database, printing `Phase-W revoked-principal replay coverage passed` and
  exiting 0 under both. The §18 executed class and its closing paragraph now describe the source guard and
  these PHP-level behavioural proofs together, and still claim no runtime, migration, concurrency or browser
  evidence.
- Re-verified in this environment against this candidate (local PHP 8.3/8.5 CLI WebAssembly runtime; no
  WordPress, no database): `tests/phase-2a2w-replay-runtime.php` exits 0 under 8.3 and 8.5;
  `tests/phase-2a2w-contract.php` prints `Phase-W contract passed` and exits 0 under both;
  `tests/phase-2a2w-public-rate-limit-unit.php` passes standalone under both; and 274 of 274
  `src/**/*.php` files parse clean under `token_get_all(..., TOKEN_PARSE)` under both. The guard's
  revoked-principal assertion reads that suite, so removing or changing it fails the guard rather than
  silently passing.
- One blocking finding remained and is closed here; nothing else about the candidate moved. No source or
  test file changed in this round: `src/` and `tests/` in this candidate's tree are byte-identical to the
  reviewed candidate's.
- This round completes the round-7 entry's closing enumeration instead of retracting it: the suites that
  entry named are exactly the §18 suites whose files are absent from this candidate's tree, and the newly
  recorded revocation-replay suite is not among them (it is present in the tree and executed). The portal
  authorization registry's execution-evidence paragraph now names the same three PHP-only suites this
  entry records, so the registry, the contract and this changelog agree.

## Phase 2A.2-W — contract test-matrix evidence correction, round 7 — candidate, unmerged — 2026-09-27

- Closes the one blocking finding of the review of `7600a53e363c487ad3f89b7d39be6802e9a0f96b` / tree
  `7a23617f9fd1b77c2e27f1171f5b22ac3abc10ac` (the review's `CORRECTION ROUND 7`): §18 of
  `docs/PHASE-2A-2W-PORTAL-FACING-SERVICES-PRINCIPAL-AUTHORIZATION-CONTRACT.md` (the reviewed candidate's
  lines 1129–1141) still recorded `tests/phase-2a2w-contract.php` as "written, **not executed** (no PHP)"
  and closed with the blanket "Until the disposable runtime exists every row above is **written but not
  executed**", which contradicted this changelog's and the portal authorization registry's executed pass
  for that guard. §18 now separates the two classes of evidence instead of retracting the pass: the static
  source guard, with the behavioural proof it embeds from `tests/phase-2a2w-public-rate-limit-unit.php`,
  is recorded as **executed** under a local PHP 8.3/8.5 CLI WebAssembly runtime with no WordPress and no
  database, while every suite that needs the disposable WordPress/MariaDB runtime is recorded as
  **not present, not executed** and as an acceptance gate this candidate has not met before merge.
- The portal authorization registry needed no change: its execution-evidence paragraph already states
  this same static-guard-only evidence for the current candidate, so the contract now agrees with it
  instead of contradicting it. No other W evidence statement in this repository changed in this round.
- The same edit corrects a second instance of the same defect class in the same table: those runtime rows
  read "written" for files that are absent from this candidate's `tests/` directory, so each now records
  the absence rather than an unexecuted suite, and the closing paragraph states the split directly instead
  of blanket-claiming execution state for every row. No source or test file changed in this round: `src/`
  and `tests/` in this candidate's tree are byte-identical to the reviewed candidate's.
- Re-verified in this environment against this candidate (local PHP 8.3/8.5 CLI WebAssembly runtime; no
  WordPress, no database): `tests/phase-2a2w-contract.php` prints `Phase-W contract passed` and exits 0
  under both 8.3 and 8.5; `tests/phase-2a2w-public-rate-limit-unit.php` passes standalone under both
  (`phase-2a2w-public-rate-limit-unit: OK`); and 274 of 274 `src/**/*.php` files parse clean under
  `token_get_all(..., TOKEN_PARSE)` under both. Re-run against the pre-change source `9790c137…` this
  guard fails its fixed-surface assertion (`Public route surface is not a fixed registered constant`), so
  the recorded pass is not a restatement of the current source.
- Not executed here or in the review environment: the disposable WordPress/MariaDB runtime and the §18
  migration, principal, authorization, capability, public-action, corruption, failure, theme-isolation and
  concurrency suites, none of which is present in this candidate's tree. `tests/schema-contract.php` keeps
  its unchanged pre-existing legacy-direction failure. (Round 8 completes this enumeration: §18 also
  carries a PHP-only revocation-replay suite, `tests/phase-2a2w-replay-runtime.php`, which is present in
  this tree and executed, so it is not among the suites listed here; the suites listed here are exactly
  the §18 suites absent from the tree.)

## Portal authorization registry — validation-evidence correction — candidate, unmerged — 2026-09-27

- Closes the one blocking finding of the review of `deaf70c3bdb3b6b40a6a43c257609cd176b79ea9` / tree
  `6f8ec6bc06eff5709ba14156f62a71898b9e5b88` (the review's `CORRECTION ROUND 6`): the portal
  authorization registry still carried a "not executed here: PHP unavailable" note on both of its
  `tests/phase-2a2w-contract.php` anchors (`docs/PORTAL-AUTHORIZATION-REGISTRY.md:74` and `:449`) while
  the round-4 entry above records an executed pass, so the registry's validation evidence contradicted
  the changelog. Both anchors now record the claimed execution evidence instead, and the execution claim
  is not retracted. No source or test file changed in this round: `src/` and `tests/` in this candidate's
  tree are byte-identical to the reviewed candidate's.
- Re-verified in this environment against this candidate (local PHP 8.3/8.5 CLI WebAssembly runtime; no
  WordPress, no database): `tests/phase-2a2w-contract.php` passes under both 8.3 and 8.5, including its
  embedded behavioural proof, and `tests/phase-2a2w-public-rate-limit-unit.php` passes standalone under
  both. Re-run against the pre-change source `9790c137…` (this candidate's guards over that source) the
  contract guard fails its fixed-surface assertion and the behavioural proof fails at its fail-open probe,
  and 274 of 274 `src/**/*.php` files parse clean under `token_get_all(..., TOKEN_PARSE)` under both
  versions. The registry's `Scope, status and authority order` section now states this execution evidence
  and its limits directly.
- One older W entry carried the same class of stale note: the round recorded as `public rate-limit
  admission correction` said "Not executed here: PHP is unavailable in this environment" about this same
  guard. It is now marked as the historical note it was, pointing to the round-4 entry's execution
  evidence. No other W evidence statement in this changelog changed.
- Not executed here or in the review environment: the disposable WordPress/MariaDB runtime and the §18
  runtime, migration and concurrency suites. No runtime, migration, concurrency or browser evidence is
  claimed, and `tests/schema-contract.php` keeps its unchanged pre-existing legacy-direction failure.

## Phase 2A.2-W — public rate-limit admission correction, round 4 — candidate, unmerged — 2026-09-27

- The correction round requested against `f7647f76efe96a4b87df537077e4fae01c21043f` / tree
  `2b2d25d8e877f9e162e5160f51e834801bc9990a` had **no actionable blocking finding to close**. The
  independent review of that candidate reported no blocking finding and was recorded as
  "PASS - MERGE PLANNING MAY PROCEED": fixed route surfaces prevent request-text bucket/audit confusion,
  and limiter failures correctly fail open. Round 4 therefore changes no source: `src/` and `tests/` in this
  candidate's tree are byte-identical to the reviewed candidate's.
- This entry is the round's only change, together with the `README.md` status row. It records the round so
  the correction sequence is not silent; it asserts nothing new about the candidate's security posture.
- Re-verified in this environment against this candidate (a local PHP 8.3/8.5 CLI WebAssembly runtime; no
  WordPress, no database): `tests/phase-2a2w-contract.php` passes under both 8.3 and 8.5, including its
  embedded behavioural proof; `tests/phase-2a2w-public-rate-limit-unit.php` passes standalone; and 274 of
  274 `src/**/*.php` files parse clean under `token_get_all(..., TOKEN_PARSE)` (the equivalent of
  `tests/static.php`, whose `exec()` PHP_BINARY lint cannot run in a WebAssembly runtime). Both guards were
  re-run against the pre-change source `9790c137…`: the contract guard fails its fixed-surface assertion and
  the behavioural proof fails at its fail-open probe, so neither is a restatement of the current source.
- Not executed here or in the review environment: the disposable WordPress/MariaDB runtime and the §18
  runtime, migration and concurrency suites. No runtime, migration, concurrency or browser evidence is
  claimed, and `tests/schema-contract.php` keeps its unchanged pre-existing legacy-direction failure.

## Phase 2A.2-W — public rate-limit admission correction, round 3 — candidate, unmerged — 2026-09-27

- Closes the two blocking findings of the review of `9790c1377f2993fa7fc6a79764de43dffb909f39` / tree
  `9a5cae0426e29addfef76fe16d684421c941dbdf`.
- **Surface selection is no longer request-derived.** `PortalPublicActionController` declares the two
  fixed public-surface constants (`SURFACE_JOIN = 'portal_public_join'`,
  `SURFACE_ABSENCE = 'portal_public_absence'`) and each registered callback passes its own constant into
  both `admit()` and `refused()`. The former `$_SERVER['REQUEST_URI']` probe is gone, so a Join request
  carrying `?x=/portal/absence`, or a query-style `/index.php?rest_route=…portal/absence/confirm` route,
  can no longer select another surface's limiter bucket or denial-audit row. Both values are members of
  the declared public surface vocabulary; no third surface was introduced.
- **The fail-open contract now matches the code.** `admit()` keeps fingerprinting and the `allow()` call
  inside a single `try` and returns normally on any `\Throwable`; `portal_rate_limited` is thrown only
  after a completed `allow()` returns `false`. The former
  `catch(\InvalidArgumentException $e){ throw $e; }` — which converted a salt or cache failure of that
  type into a refusal — is removed.
- `tests/phase-2a2w-public-rate-limit-unit.php` is new behavioural coverage, embedded by
  `tests/phase-2a2w-contract.php`. It proves that the Join and absence buckets are selected by the
  registered surface and not by request text (both directions, including the adversarial query values), a
  genuine limit refusal recorded as `portal_rate_limited` on the Join surface before the option gate is
  consulted, and fail-open on a salt failure while fingerprinting and on a cache failure inside
  `allow()`. The contract guard also asserts the fixed-surface wiring and the fail-open structure
  statically.
- Evidence in this environment: `tests/phase-2a2w-contract.php` (its static seams together with the
  embedded behavioural proof) and `tests/phase-2a2w-public-rate-limit-unit.php` were executed under a
  local PHP 8.3 CLI runtime (a WebAssembly build; no WordPress and no database) and pass, and an
  equivalent of `tests/static.php`'s lint is clean across all 274 `src/**/*.php` files. Re-run against
  this candidate's own pre-change source `9790c137…`, the behavioural proof fails seven of its nine probes
  and the contract guard fails its fixed-surface assertion, so both are genuine regression guards rather
  than restatements. The disposable WordPress/MariaDB runtime, the §18 runtime/migration/concurrency
  suites and `tests/schema-contract.php` were not executed here, and no runtime, migration, concurrency
  or browser evidence is claimed. (`tests/schema-contract.php` fails identically on the pre-change
  candidate `9790c137…` and on the base W candidate `2ab0c71…`: its legacy direction guard forbids
  `finance`/`amelia` text that later-phase migrations already contain in `Migrator.php`. It is unchanged
  here and is not part of this correction.)

## Phase 2A.2-W — public rate-limit admission correction — candidate, unmerged — 2026-09-27

- `PortalPublicActionController` now invokes `PortalRateLimiter` for every request to all three registered
  public routes (`/portal/join`, `/portal/absence`, `/portal/absence/confirm`) before the
  `dzn_platform_portal_actions` gate and before any capability verification, so the best-effort
  abuse-control budget gates every public request rather than only the verified ones.
- Keying is a salted digest of the client network signal plus the presented handle; a refusal is
  normalized through the existing non-enumerating public failure
  (`404 {"code":"portal_action_unavailable"}`, `Cache-Control: no-store`) and recorded as
  `portal_rate_limited` in `portal_access_denials`. The declared best-effort limiter stays fail-open on
  its own cache or salt failure, and the numeric budget remains the open owner decision of W contract
  §21.5.
- Closes the blocking finding on candidate `f7f4e9f6b7cffbcc01fbb9fcd0b52b6450f7d4d0`: the registry's
  Class B rate-limit row, the three public registry rows and `docs/SECURITY.md` now record the
  implemented mechanism instead of the former gap.
- `tests/phase-2a2w-contract.php` guards that the admission precedes the option gate on both callbacks.
  Not executed in that round. (The "PHP is unavailable in this environment" note recorded here is the
  historical state of that round and is superseded: the guard's execution evidence appears in the round-4
  entry above.)

## Documentation reconciliation — current Schema-31 source state — 2026-09-27

- Recorded the current `main` source topology at `2ab0c71`: Schema 31 / migration
  `031_portal_facing_services_principal_authorization` and the Phase-W portal implementation are present.
  This entry does not mark the candidate independently reviewed, deployed, enabled or cut over.
- Added [Portal authorization registry](PORTAL-AUTHORIZATION-REGISTRY.md), derived from source, for all
  three W REST routes, the exact public gate, grants, public/internal operations, owner seams, evidence,
  refusal behaviour and prohibited data.
- Corrected W contract implementation-state assertions that predated the source: `src/Portals/`, Schema
  31, migration 031, the capability marker and the capability-attributed attendance handoff now exist;
  there remains no authenticated Student/Teacher portal REST controller.
- Recorded topology truth: Notifications S recovery tip `1603936` is not an ancestor of current `main`
  (shared merge-base `559b173…`). Historical queue metadata is not merge evidence. The operational-readiness
  integration merges the reviewed Schema-32 Notifications S re-land candidate `56cd831…` itself, which is
  additive on Schema 031 and is not that recovery branch.

## Phase 2A.2-S — Canonical Notification & Communications Authority — candidate, unmerged — 2026-09-24

Schema 32 / migration `032_notification_communications_authority` / build
`phase2a2s-notification-communications-authority-20260924.1`. Additive only: it extends the shared
`platform_outbox` seam and adds the S-owned notification storage, and preserves every Phase-1/R1/R2 row.

### Implementation correction round (independent review of candidate `a35d4f4`)

Five blocking findings were corrected in the product code; the schema identity, table count, migration
name and build identity are unchanged and no merge or deploy is involved.

- The dispatch claim now normalizes its evidence envelope and writes it on every history row it appends, so
  a lease can actually be acquired (`claim_lease` no longer passes `null` to `event_row`).
- The claim path re-evaluates the complete frozen eligibility set — subject, recipient, consent, guardian
  authority and the active suppression, all resolved through their own read sources — proves the frozen
  rule set against its digest, records the bound-evidence digest, and closes a no-longer-eligible
  notification in its controlled terminal state without a lease. `re_evaluate_eligibility` runs the same
  guard, and `hand_off` performs a full successful re-evaluation as an unavoidable prerequisite.
- The claim set requires the derived window to be open, and each claim pass first expires overdue queued
  work in one transaction per row (`expired`/`retry_window_exhausted`, outbox row closed in place), so a
  notification can never be sent outside its mandatory window.
- One shared aggregate verification re-derives the persisted schedule and identity, checks the outbox
  mirror, and proves every closed attempt's closure partition and persisted retry schedule; it now runs in
  `NotificationReadService`, `NotificationAttemptReadService`, the dispatch claim path and
  `verify_notification_communications_schema`.
- Observation resolves the version's active template version, freezes the immutable rendered-parameter
  snapshot in the observation transaction (failing closed with `template_variable_mismatch` /
  `envelope_decrypt_failure`), persists both `template_version_id` and `rendered_snapshot_id`, and
  hand-off builds its channel-neutral command from that frozen snapshot.

### Implementation correction round 2 (independent review of candidate `9dabd56`)

Four blocking findings were corrected in the product code; the schema identity, table count and build
identity are unchanged, the only schema addition is one nullable column inside migration 032, and no merge
or deploy is involved.

- The transport command is a strict allowlist: `NotificationDispatchService::authorisedCommand()` takes no
  caller input and returns exactly the six frozen fields — `notification_key_digest`, `attempt_sequence`,
  `audience`, `template_version_id`, `variable_codes` and the decrypted parameter map — each read from the
  persisted aggregate and its proved snapshot. The merge of caller input and the post-hoc `unset` loop are
  gone, so no raw payload, provider-specific field or operator envelope can reach
  `NotificationTransportPort`.
- The claim proves the aggregate under its own locks: `claim_lease()` now runs the new `aggregateGuard()`
  while the notification and outbox rows are locked and before any eligibility verdict or lease, re-deriving
  the frozen composition and policy, the persisted tier-F instants, the frozen identity and the attempt
  history and passing them to `NotificationIntegrity::aggregateIntegrity()`. A corrupted schedule,
  identity, mirror or prior closure refuses the claim whole.
- An attempt that is already open when the dispatch-time re-evaluation refuses the notification now closes
  as its own audited fourth closure class, `eligibility_abort` (a correction-round addition to the §14
  diagnostic vocabulary, recorded here with its `eligibility_abort_invalid` refusal code): it carries the refusal code identically on
  the attempt (`outcome_code`) and the notification (`failure_reason_code`), closes the notification in the
  controlled state that code maps to (one shared `NotificationRule::controlledState()`), appends its own
  `failed` attempt event, derives and persists no retry schedule and re-arms nothing, so it is never
  represented as — or rejected as — a retry closure. `NotificationIntegrity::closureIntegrity()` gained the
  matching partition and the `eligibility_abort_invalid` diagnostic, and `FAILURE_CLASSES` stays the closed
  caller-declarable vocabulary so only the refusal path can write the abort class.
- The canonical rendered-template variable contract is now persisted and proved:
  `notification_template_versions.variable_contract` (nullable `varchar(191)`) holds the sorted,
  deduplicated allowlisted codes as comma-separated text, with the digest and required count *derived* from
  exactly that text at registration; `NotificationIntegrity::variableContract()` /
  `renderParameters()` / `renderedSnapshot()` require the declared code set, the encrypted parameter keys
  and the canonical key-ordered `params_digest` to equal the contract exactly. Snapshot freeze, hand-off,
  the template read seam and the schema verifier all enforce it, so a snapshot can no longer claim an
  arbitrary required code set while encrypting a different or empty parameter map.

Two coherence repairs accompany them: `NotificationRetry::exhaustionReasonCode()` is now the single
mapping from an exhaustion gate to its closed reason code (both the closure path and lease-expiry recovery
use it), and the static contract suite asserts the draft-only rule guard over the sources that perform the
guarded write (the workflow service and its repository, per §6.2/§7.2) instead of the migration installer.

### Implementation correction round 3 (independent review of candidate `902b060`)

Three blocking findings were corrected in the product code; the schema identity, table count, migration
name and build identity are unchanged, no schema object is added, and no merge or deploy is involved.

- **Attempt transitions are enforced.** The hand-off is now a durable, idempotent reservation: `hand_off()`
  commits the exact `leased → handed_off` transition plus its digest-only `hand_off` command row (guarded on
  the persisted source state, on the outbox row carrying the *same* lease token and on a lease that has not
  elapsed, under the §10 lock order aggregate → outbox row → attempt row) **before** it calls
  `NotificationTransportPort`. A repeated hand-off of an already reserved attempt, and a replay of the same
  key, both return the persisted reservation without a second port call; an elapsed lease is refused
  outright because recovery owns it. `record_outcome` now admits an acknowledgement only from `handed_off`
  and a closure only from an open (`leased`/`handed_off`) attempt, refusing anything else with
  `notification_attempt_state_conflict`. `NotificationIntegrity::attemptHistoryIntegrity()` (new) proves
  every persisted history — contiguity from attempt 1, the closed state vocabulary, a contiguous chain of
  legal transitions ending on the persisted state, the open/closed marker agreeing with that state, at most
  one open attempt and never an open attempt beside a terminal notification — reporting
  `attempt_lifecycle_invalid`, and the shared aggregate verification runs it in every protected read, every
  dispatch claim, the attempt read seam and the schema verifier.
- **A terminal command resolves the live lease it finds.** `cancel`/`expire`/`suppress` now close the open
  attempt inside their own transaction through a fifth, audited closure class: the attempt closes
  `abandoned` with `failure_class = 'lease_cancelled'` and `outcome_code` equal to the terminal state the
  command produced, appends its own attempt event, persists no retry schedule and re-arms nothing, while the
  outbox row closes consistently. `closureIntegrity()` gained the matching partition and the
  `lease_cancellation_invalid` refusal code, `notification_lifecycle` gained the `handed_off|abandoned` and
  `dispatching|queued` transitions, `release_lease` now closes through the same bounded ceiling-first
  two-gate path as every other non-terminal closure (it previously wrote an undeclared `abandoned` attempt
  that no partition could judge), `erase_recipient` takes the root lock inside its own transaction, and a
  terminal notification with an open attempt is refused by aggregate integrity. The claim guard also
  re-validates `available_at`/`scheduled_for` under the lock, so a deferral or retry that moved the instant
  can no longer be leased.
- The §10 lock order is now uniform: `record_outcome` and `recover_expired_leases` previously locked the
  attempt row first and the aggregate second, which inverted the fixed order every other path (claim,
  hand-off reservation, terminal command, overdue expiry, erasure) already uses. Both now discover the ids
  by an unlocked read and then lock aggregate → outbox row → attempt row, so a hand-off racing a closure on
  one attempt can no longer deadlock, and the static contract suite asserts the order on both paths.
- **The concurrency harness drives the complete §15 matrix.** The runner declares `MODES` in the contract's
  own order and the setup, worker and verifier each implement every one of the fourteen modes
  (`dispatch_vs_retry`, `lease_expiry_vs_handoff`, `retry_exhaustion_vs_recovery`,
  `subject_transition_after_enqueue_vs_dispatch`, `policy_change_after_publication_vs_dispatch`,
  `deferral_vs_claim`, `activation_vs_dispatch`, `competing_activation_same_intent`,
  `rule_attach_vs_activation`, `suppress_vs_enqueue`, `cancel_vs_dispatch`, `delivery_vs_attempt_close`,
  `erase_vs_dispatch`, `unrelated_notifications`); the runner is committed executable (`100755`) and the
  static contract suite asserts the complete matrix, the executable bit and the new lifecycle vocabulary
  and guards against the sources. Runtime coverage was added for the new rules (retry suite §11 — an
  acknowledgement refused from `leased`, a repeated hand-off replaying without a second port call, and a
`cancel` resolving a live lease to a clean protected read; corruption suite §5 — truncated chain,
unreachable state and terminal-beside-open-attempt each failing closed with `attempt_lifecycle_invalid`
and converging when restored).

### Implementation correction round 4 (targeted correction of the preserved candidate `3f67d3d`)

A targeted, additive correction on top of the preserved candidate. Schema 32 /
`032_notification_communications_authority`, the eighteen tables, the migration name and the build identity
are unchanged, and no merge, deploy, provider activation, external send, Amelia or Theme change is involved.

- The five blocking findings of the candidate's first independent review (`a35d4f4`) are re-verified as
  already corrected in this candidate and are left unchanged: the claim normalizes its evidence envelope on
  entry and writes that array on every history row it appends; the claim and hand-off paths resolve the
  complete frozen eligibility set — subject, recipient, consent, guardian authority and the active
  suppression, each through its own read source — under the guard, record the bound-evidence digest, close a
  no-longer-eligible notification terminally without a lease, and make a successful re-evaluation an
  unavoidable prerequisite to hand-off; the claim set requires an open derived window and every claim pass
  expires overdue queued work in one transaction per row; and observation resolves the version's active
  template version, freezes the immutable rendered-parameter snapshot in the observation transaction and
  persists both `template_version_id` and `rendered_snapshot_id`.
- **The persisted outbox mirror is proved by the shared aggregate verification on every protected read and in
  the schema verifier.** `NotificationIntegrity::aggregateIntegrity()` now treats the mirror as part of the
  proof instead of an optional extra: a notification that carries an `outbox_id` and is handed over without
  its persisted row is refused whole with `schedule_derivation_divergence`, so no read path can skip the
  mirror check. `NotificationAttemptReadService` resolves the notification's persisted `platform_outbox` row
  and hands it to the shared verification (it previously passed `null`, so the attempt seam validated no
  mirror), and `Migrator::verify_notification_authority_data()` hands each notification's persisted mirror
  row to the same shared call in addition to its row-by-row mirror loop. The mirrored triple and the
  `available_at` contract are therefore proved by one rule on the aggregate read, the attempt read, the
  dispatch claim and schema verification.
- Coverage: `tests/phase-2a2s-contract.php` §14 asserts the unconditional mirror requirement and that the
  attempt read seam and the schema verifier hand the persisted mirror to the shared check (never `null`);
  `tests/phase-2a2s-corruption-runtime.php` §6 diverges a mirrored `scheduled_for`, proves the attempt read
  seam refuses it with `schedule_derivation_divergence` through both of its projections, and proves the read
  converges once the mirror is restored.

### Implementation correction round 5 (independent review of failed candidate `aefe39a`, tree `0a519a7d`)

The independent re-review of the previous correction candidate refused it with one blocking finding — the
outbox identity was not verified. The schema identity, table count, migration name and build identity are
unchanged, no schema object is added, and no merge, deploy, provider activation, external send, Amelia or
Theme change is involved.

- **The notification/outbox 1:1 relationship is now proved on both sides, not from the schedule alone.**
  `NotificationIntegrity::outboxMirror()` compared only the mirrored triple and `available_at`, so a
  notification whose `outbox_id` was NULL — or named a *different* valid/legacy row — was accepted whenever
  the supplied row mirrored its schedule, even with another row pointing back at the aggregate. The shared
  verification now refuses both halves of that split with `schedule_derivation_divergence` *before* it
  proves anything about the row: the row must name the notification (`platform_outbox.notification_id`) and
  the notification must name the row (`notifications.outbox_id`). Because that rule is the one every
  protected read, the dispatch claim, the attempt read seam and the schema verifier run, no path can accept
  a divergent reciprocal pointer.
- **The schema verifier compares both pointers row by row.** `Migrator::verify_notification_authority_data()`
  now selects `n.outbox_id` beside `o.id` and refuses any mirrored row whose notification does not point
  back at it (`notification outbox pointer divergence`), a NULL aggregate pointer included, in addition to
  handing each notification's persisted mirror to the same shared verification.
- Coverage: `tests/phase-2a2s-contract.php` §14 asserts both reciprocal checks on the shared rule and the
  row-by-row schema comparison; `tests/phase-2a2s-corruption-runtime.php` §7 mutates either pointer — a
  notification pointing at a different valid S-owned row, a NULL notification pointer, and an outbox pointer
  that no longer names its notification — while every mirrored schedule value stays exactly what the
  aggregate derived, and proves the aggregate read, the attempt read seam and the schema verifier each fail
closed, converging once the pointer is restored. The corruption suite also imports `NotificationRule`,
which its §5 assertion referenced without the import (a latent fatal in the suite itself).

### Implementation correction round 11 (host review `CORRECTION ROUND 3`, failed candidate `6673e7b`, tree `1b2cad8a`)

The host review refused the candidate with one blocking finding: `NotificationSupport::seconds()` validated
the shape and the calendar but never the declared `datetime` domain, so it accepted a valid date outside
`1000-01-01`…`9999-12-31` (`0999-12-31 23:59:59`); `NotificationSchedule::derive()` could therefore accept an
out-of-domain `observed_at` and persist it as `schedule_anchor_at`/`scheduled_for`, and `addSeconds()`
accepted an out-of-domain base whenever the sum happened to re-enter the domain. The schema identity, table
count, migration name and build identity are unchanged, no schema object is added, and no merge, deploy,
provider activation, external send, Amelia or Theme change is involved.

- **The declared `datetime` domain now binds the parse side as well as the format side.** `seconds()`
  refuses a parsed value below `DATETIME_MIN` or above `DATETIME_MAX` before it constructs anything (the
  declared `YYYY-MM-DD HH:MM:SS` shape fixes a four-digit zero-padded year, so the domain comparison is exact
  on the canonical text), so every caller inherits one refusal: the §8.1 evidence envelope
  (`notification_evidence_at_invalid`), the schedule observation instant and the newly guarded non-null
  subject instant (`schedule_derivation_divergence`), `dayDifference()`, `coalesceBucket()`, `utcToLocal()`
  and — because it parses its base through `seconds()` — `addSeconds()` itself, which consequently refuses an
  out-of-domain base even when the sum would land back inside the domain. `NotificationSchedule::defer()` and
  `NotificationEligibility::evaluate()` additionally parse both sides of their instant comparisons
  explicitly, so a refused instant can never be compared as a zero second count, and a derived result that
  leaves the domain still fails closed exactly as before.
- Coverage: `tests/phase-2a2s-contract.php` §16 asserts the new `seconds()` domain rule, the subject-instant
  domain guard and every new runtime case label, and documents that the parse side is bound by the same
  domain as the format side; `tests/phase-2a2s-schedule-derivation-runtime.php` §10 probes
  `seconds()`/`addSeconds()`/`dayDifference()`/`coalesceBucket()`/`utcToLocal()`/`evidence()` with
  `0999-12-31 23:59:59` beside the still-accepted declared boundaries, refuses an out-of-domain observation
  instant, an out-of-domain subject instant, an out-of-domain `addSeconds()` base inside a deferral, and a
  derivation whose expiry leaves the domain, and proves the production observation path raises
  `schedule_derivation_divergence` while persisting no notification and leaving the intent row unclaimed.
  This correction environment provides no PHP or WordPress runtime, so the runtime suites are updated and
  reviewed by source but were **not executed here**; no migration was re-run and no schema object, identity
  or build changed.

### Implementation correction round 10 (host review `CORRECTION ROUND 2`, failed candidate `63f6b5b`, tree `abd60da`)

The host review refused the candidate with one blocking finding: `NotificationSupport::instant()` rejected
every negative Unix second count, although the declared `datetime` domain begins at `1000-01-01`, so a valid
persisted pre-epoch date could not be derived and failed schedule derivation as
`schedule_derivation_divergence`, violating the stated datetime-domain invariant. The schema identity, table
count, migration name and build identity are unchanged, no schema object is added, and no merge, deploy,
provider activation, external send, Amelia or Theme change is involved.

- **`instant()` accepts the whole declared domain and refuses only what is outside it.**
  `NotificationSupport::instant()` no longer treats `$seconds < 0` as invalid: it formats the 64-bit integer
  second count and refuses a result only outside `DATETIME_MIN`…`DATETIME_MAX`
  (`1000-01-01 00:00:00` … `9999-12-31 23:59:59` UTC), so `-30610224000` resolves to the domain minimum,
  `-600` to `1969-12-31 23:50:00`, and only `0999-12-31 23:59:59` (one second below the minimum) or a year
  above `9999` is refused. `addSeconds()` judges its own 64-bit integer-second result by the same
  full-domain rule, so a pre-epoch base — or an offset that lands before the epoch — derives normally
  instead of failing closed as a divergence.
- **The §6.3 step-7 coalesce bucket keeps the contract's `floor(...)` below the epoch.** Opening the domain
  below 1970 makes a pre-epoch anchor reachable, and `intdiv()` truncates toward zero rather than flooring,
  so it placed `-600` in bucket `0` where step 7's `floor(anchor_at / (coalesce_window_minutes * 60))` puts
  it in bucket `-1`. `coalesceBucket()` now returns the exact floor for a negative anchor (identical to
  `intdiv()` for every anchor at or after the epoch), so the bucket index and the §9 identity stay the
  declared function.
- Coverage: `tests/phase-2a2s-contract.php` §16 asserts the domain constants, the absence of the negative
  refusal, the domain-only refusal and the floor bucket; `tests/phase-2a2s-schedule-derivation-runtime.php`
  §9 adds the pre-epoch derivation probe — a tier-F version whose frozen lead time places the anchor at
  `1969-12-31 21:30:00` derives that anchor and its own `1969-12-31 23:00:00` expiry instead of diverging,
  with the step-7 bucket at `-3` — beside the direct pre-epoch helper probes (`instant(-600)`,
  `addSeconds('1970-01-01 00:00:00', -600)`, `coalesceBucket('1969-12-31 23:50:00', 60) === '-1'`, and the
  unchanged `1` bucket at `1970-01-01 01:05:00`) and the domain-boundary probes one second below
  `1000-01-01` and one second above `9999-12-31`.
- Verification available here: this correction environment provides no PHP or WordPress runtime, so
  `tests/phase-2a2s-contract.php` and the runtime suites are updated and reviewed by source but were **not
  executed here**; the domain arithmetic is proved in 64-bit integer seconds against the contract's declared
  bounds. No migration was re-run and no schema object, identity or build changed.

### Implementation correction round 9 (host review `CORRECTION ROUND 7`, failed candidate `465a708`, tree `766ef7b4`)

The host review refused the candidate with one blocking finding: the notification-side retry-audit proof
required the `retry_scheduled` row's predecessor in the `event_sequence`-ordered result set to be the
`queued` row, but never required the pair's own sequences to be contiguous. The host ledger numbers this
review correction round 7 of the current implementation attempt — the identical blocking finding was also
returned as `CORRECTION ROUND 6` against this same failed candidate, and no candidate was produced in
between — while this record's own sequence continues at 9. The schema identity, table count, migration name
and build identity are unchanged, no schema object is added (the finding restates the retry-audit placement
§7.3 and §9 already state, with §14's `attempt_lifecycle_invalid` diagnostic already naming that contiguous
successor), and no merge, deploy, provider activation, external send, Amelia or Theme change is involved.

- **The notification-side retry audit row must be the numeric contiguous successor of its own `queued`
  row, not merely the next row the ordering returned.** `NotificationIntegrity::retryEvidenceIntegrity()`
  proved only that the preceding result-set row was `event_type = queued` with `to_state = queued` and that
  the audit row itself restated `queued → queued`, so a forged re-arm pair whose sequences skip one —
  `queued` at `Q`, `retry_scheduled` at `Q + 2`, with nothing between them — stayed adjacent in the
  ordered read and passed every protected read and the schema verifier. The per-re-arm match now refuses
  the row unless `(int) $event->event_sequence === (int) $previous->event_sequence + 1` beside the existing
  predecessor, state, reason and digest proofs, so the pair is judged by its own sequence numbers and a
  skipped sequence is refused whole with `attempt_lifecycle_invalid`.
- Coverage: `tests/phase-2a2s-corruption-runtime.php` §9(h) renumbers the intact ceiling walk's first
  re-arm audit row and every later notification-history row one higher, asserts the resulting `Q`/`Q + 2`
  pair is still adjacent in the ordered read, proves the aggregate read, the attempt read seam and the
  schema verifier each fail closed with `attempt_lifecycle_invalid`, then restores every sequence and
  asserts the walk reads clean again. `tests/phase-2a2s-contract.php` §15 asserts the numeric-contiguity
  source rule and every new runtime case label.
- Verification available here: this correction environment provides no PHP or WordPress runtime, so the
  runtime suites are updated and reviewed by source but were **not executed here**; no migration was
  re-run and no schema object, identity or build changed.

### Implementation correction round 8 (host review `CORRECTION ROUND 5`, failed candidate `52ebb18`, tree `e821b302`)

The host review refused the candidate with two blocking findings in the shared integrity proofs. This is
the same review the host ledger numbers correction round 5 of the current implementation attempt; this
record's own sequence continues at 8. The schema identity, table count, migration name and build identity
are unchanged, no schema object is added (the findings restate rules §6.6, §7.3 and §9 already state), and
no merge, deploy, provider activation, external send, Amelia or Theme change is involved.

- **The acknowledgement is now proved against the aggregate it produced, not only against its own row.**
  The null-class branch of `NotificationIntegrity::closureIntegrity()` accepted a class-less `acknowledged`
  attempt on its shape alone, so a forged attempt with `state`/`outcome_code = acknowledged`, no
  `failure_class` and no retry columns passed even beside a `failed`, `expired`, `suppressed` or
  `cancelled` notification — the outbox mirror constrains only the mirrored schedule, not the aggregate's
  status, and a class-less attempt leaves no closure partition to judge — although §6.6 defines the
  acknowledgement by its result: the port accepted the hand-off, so the notification it closes is
  `dispatched`. The branch now refuses unless the notification's `state` is exactly `dispatched` **and** its
  `failure_reason_code` is NULL, so an acknowledged-looking attempt beside a terminal status — or a
  `dispatched` row that still carries a failure code — is refused whole with `attempt_lifecycle_invalid` on
  every protected read, the dispatch claim, the attempt read seam and `verify_notification_communications_schema()`.
- **The notification-side `retry_scheduled` evidence is proved per re-arm, in lifecycle order, not as an
  unordered set.** `NotificationIntegrity::retryEvidenceIntegrity()` compared each audit row's digest
  against a set of acceptable digests, so for two retryable re-arms exchanging their distinct digests
  passed — both were expected, both preceding `queued` rows carried the same reason and the cardinality was
  unchanged — and the method never checked the audit row's own `from_state`/`to_state`, although §9
  requires each row to directly follow and restate the `queued` transition of its own re-arm. The expected
  evidence is now built as one entry per re-arming closure, ordered by the re-arming attempt's
  `attempt_sequence`, and the `retry_scheduled` rows are consumed in `event_sequence` order, so the *n*-th
  row must carry exactly the *n*-th re-arm's digest and outcome code (a missing row, a stray row beyond the
  expected count, or an exchanged pair is refused), and each row must be the contiguous immediate successor
  of its own `queued` row while itself restating `queued → queued`.
- Coverage: `tests/phase-2a2s-retry-runtime.php` §6 refuses the acknowledged attempt beside a `failed`, an
  `expired` and a failure-coded `dispatched` notification; `tests/phase-2a2s-corruption-runtime.php` §9(b)
  proves the forged acknowledgement pair end-to-end — a real acknowledgement whose notification is
  rewritten to `failed`/`retry_exhausted` (and, separately, `expired`/`retry_window_exhausted` and a
  `dispatched` row carrying a failure code) — fails the protected read **and** the schema verifier,
  converging once the `dispatched` status is restored, while §9(g) exchanges the two re-arms' distinct
  notification-side digests (refused) and rewrites the audit row's `from_state`, its `to_state` and its
  preceding `queued` row's `to_state` in turn (each refused), converging once restored.
  `tests/phase-2a2s-contract.php` §15 asserts every new source rule and every new runtime case label.
- Verification available here: this correction environment provides no PHP or WordPress runtime, so the
  runtime suites are updated and reviewed by source but were **not executed here**; no migration was
  re-run and no schema object, identity or build changed.

### Implementation correction round 7 (host review `CORRECTION ROUND 4`, failed candidate `8c83d2f`, tree `f2a0dee5`)

The host review refused the candidate with three blocking findings in the shared integrity proofs. This is
the same review the host ledger numbers correction round 4 of the current implementation attempt; this
record's own sequence continues at 7. The schema identity, table count, migration name and build identity
are unchanged, no schema object is added, and no merge, deploy, provider activation, external send, Amelia
or Theme change is involved.

- **The retry ceiling and the single live lease are proved, not asserted.** `NotificationIntegrity::
  attemptHistoryIntegrity()` now validates each `attempt_sequence` against the frozen `retry_max_attempts` —
  a persisted attempt above the ceiling is refused whole with `attempt_lifecycle_invalid` instead of being
  read as a further ceiling closure — and counts the live leases, so exactly one attempt may be open and
  only while the aggregate is `dispatching`. `closureIntegrity()` reads the same frozen ceiling and refuses
  an above-ceiling row itself: the ceiling shape belongs to the sequence **at** the ceiling alone, so `>=`
  is not a spelling of `==`.
- **A null `failure_class` is now allowed only for the acknowledgement.** The class-less shape is scoped to
  the closed `acknowledged` attempt carrying the shared acknowledgement outcome code
  (`NotificationRule::ACKNOWLEDGED_OUTCOME`, now written by the acknowledgement path as well) with no
  persisted retry schedule; a forged `failed`, `expired` or `abandoned` closure with a legal event chain is
  refused with `attempt_lifecycle_invalid`, and no closure class may borrow the acknowledgement member.
- **The digest-only `retry_scheduled` audit evidence is proved on both append-only histories.** The
  attempt-side row must be the closing attempt's own last history row, its `reason_code` must be that
  closure's outcome code and its `evidence_reference_digest` must be exactly the retry evidence of the
  persisted quadruple; the new `NotificationIntegrity::retryEvidenceIntegrity()` proves the notification's
  own `retry_scheduled` row — exactly one per re-arming attempt, directly after the `queued` row the same
  re-arm appended, carrying the same evidence, and none where no schedule was derived.
  `NotificationDispatchService` writes that row on both re-arm paths (the port-reported/defer closure and
  the lease-expiry recovery) from one shared `retryEvidence()` derivation, in the same transaction as the
  schedule, the transition and the outbox re-arm, and `retryScheduleIntegrity()` now reads the §9
  derivation in both directions (a closure that re-arms must carry the quadruple that reproduces it; a
  closure the derivation exhausts must carry none).
- Coverage: `tests/phase-2a2s-corruption-runtime.php` §8 walks a legitimate three-attempt ceiling and
  proves a forged `retry_max_attempts + 1` attempt — and a second open attempt beside a live lease — fail
  closed through the aggregate read, the attempt read seam and the schema verifier while the intact walk
  reads clean; §9 proves the three class-less closures, the removed/forged/reused retry evidence on either
  history, the evidence injected beside the exhausted closure, the legitimate acknowledgement that still
  reads clean, and a re-arm whose persisted quadruple disagrees with the derivation even when both evidence
  rows were recomputed to match it. `tests/phase-2a2s-retry-runtime.php` §6 asserts the above-ceiling
  refusal beside the passing ceiling shape and the three class-less refusals (its ceiling forgeries now sit
  **at** the ceiling, so the ceiling rule — not the window's — judges them, matching §7.3's scoping of the
  two diagnostics), and the concurrency verifier passes each raced notification's own frozen policy and
  proves the notification-side evidence.
- Verification available here: `tests/phase-2a2s-contract.php` §15 asserts every new source rule and every
  new runtime case label. This correction environment provides no PHP or WordPress runtime, so the runtime
  suites are updated and reviewed by source but were **not executed here**; no migration was re-run and no
  schema object, identity or build changed.

### Implementation correction round 6 (independent review of failed candidate `9fef9b9`, tree `301e359d`)

The independent re-review of the previous correction candidate refused it with one blocking finding: a
notification/outbox pair whose reciprocal pointers were **both** cleared was still accepted. The schema
identity, table count, migration name and build identity are unchanged, no schema object is added, and no
merge, deploy, provider activation, external send, Amelia or Theme change is involved.

- **The mirror requirement is unconditional, so a pair cleared on both sides is refused.** The shared
  `NotificationIntegrity::aggregateIntegrity()` refused an absent mirror row only when
  `notifications.outbox_id` was non-null, so corruption that cleared *both* `notifications.outbox_id` and
  the former `platform_outbox.notification_id` left nothing for either lookup to find and the notification
  was read as an aggregate with no mirror to compare — violating §6.5's exact 1:1 relationship and the
  fail-closed authority invariant. The rule now refuses **any** notification handed over without its
  persisted mirror row (`schedule_derivation_divergence`), before the mirror proof, so the aggregate read,
  the attempt read seam, the dispatch claim and schema verification all refuse that pair.
- **The schema verifier states the same rule as a row-level anti-join.**
  `Migrator::verify_notification_authority_data()` now runs
  `notifications LEFT JOIN platform_outbox ON o.notification_id = n.id WHERE o.id IS NULL` and refuses any
  S-owned notification with no mirror row (`notification without an outbox mirror:
  schedule_derivation_divergence`) — the row its outbox loop can no longer see — in addition to the
  reciprocal `n.outbox_id`/`o.id` comparison and the shared-call hand-over it already performed.
- Coverage: `tests/phase-2a2s-contract.php` §14 asserts the unconditional requirement (and that a NULL
  aggregate pointer can no longer be read as an acceptable missing mirror) and the anti-join;
  `tests/phase-2a2s-corruption-runtime.php` §7(d) clears both pointers on a mirrored, leased pair, proves
  the aggregate read, the attempt read seam (both projections) and the schema verifier each fail closed
  while every mirrored schedule value stays untouched, and proves convergence once both pointers are
  restored.

- `platform_outbox` gains the additive dispatch representation — `notification_id` (unique), `workflow_key`,
  `workflow_version`, `intent_key`, `audience`, `scheduled_for`, `expires_at`, `deferral_count`, `priority`,
  `lease_token_digest`, `failure_reason_code` — plus the `dispatch` and `intent_version` lookup indexes.
  Every added column is nullable with no default, so the R2 insert-only publisher and the Phase-1
  invitation delivery seam keep working unchanged and S never claims a row it does not own.
- Eighteen S-owned tables: workflow identity, immutable versions with the `workflow_active`/`intent_active`
  routing slots, draft-only frozen rule storage, digest-only commands, templates and their immutable
  versions, rendered-parameter snapshots, the notification aggregate and its append-only history, the
  attempt lifecycle with its persisted deterministic retry schedule, digest-only delivery facts, the
  channel-neutral suppression register and the digest-only privacy tombstones.
- `NotificationWorkflowService` freezes a version's complete required eligibility set, its closed §6.3
  schedule composition, its mandatory expiry window and its narrow-only §9 retry policy at activation, and
  arbitrates the single active version per consumed intent through the named routing slot.
- `NotificationService` observes an R2 intent from the seam, freezes the §6.2.3 bound evidence and the
  §6.2.4 tier-F instant, derives and mirrors the schedule, and closes an unavailable tier-F instant or an
  unresolvable timezone basis terminally instead of scheduling one.
- `NotificationDispatchService` leases through the existing `attempt_count` counter, hands off only through
  the channel-neutral `NotificationTransportPort`, and closes every attempt through the ceiling-first
  two-gate rule: ceiling exhaustion is `failed`/`retry_exhausted`, below-ceiling window exhaustion is
  `expired`/`retry_window_exhausted`, and a `terminal` class closes terminal `failed` with its own
  normalised reason code at every attempt sequence.
- Retry scheduling is deterministic and keyed — the immutable notification identity, the attempt sequence
  and the frozen parameters are the only inputs — and the base back-off is the §9 bounded recurrence, which
  is the sole canonical semantics.
- Bounded Phase 2A.2-R2 amendment (§6.2.4): `dzn_renewal_cycles.automatic_charge_at` is a durable,
  immutable per-cycle fact written in the cycle-open transaction, `CollectionIntentService::open()` reads
  that persisted column instead of re-deriving the instant from the current lead-time policy, and
  `AUTOMATIC_RENEWAL_UPCOMING` is published from the cycle-open fact alone.
- Provider-neutral and external-send-free: no transport binding, no credential, no provider template or
  identifier, no raw provider payload, no provider call, no Amelia or Theme change, no merge, no deploy.

## Phase 2A.2-U — Finance, Payability, Effective-Dated Teacher Rates, Statements & Audited Corrections — candidate, unmerged — 2026-09-25

Schema 30 / migration `030_finance_payability_rate_statement_authority` / build
`phase2a2u-finance-payability-rate-statement-20260925.1`, additive on the Phase-T candidate tree at
Schema 29 and explicitly scoped to that recorded base (contract §20 prerequisite 2). It implements the
contract `docs/PHASE-2A-2U-FINANCE-PAYABILITY-RATE-STATEMENT-AUTHORITY-CONTRACT.md` (SHA-256
`402f1334dd169a62aea450929d3bee25a116930c3e93bf69fa175a41bed6d79b`, whose §0g records correction
round 2, whose §0h records correction round 3, whose §0i records correction round 4 and whose §0j records
correction round 5).

**Independent review correction round 2** (the review of `86d57606cabcddba15d076edfe14fb4e7257e60f` /
tree `6010181bfbf66e01fa49154c9ba266d1dd4888c4` returned FAIL — CORRECTION REQUIRED with six blocking
findings; all six are closed on this descendant, additively, with no reset, rebase, amend or force-push):

- Every `finance_*_commands` row now records the declared `result_state` of its operation, and
  `FinanceSupport::commitRefusal()` writes a refusal as `result_state = 'refused'` with `NULL` typed
  results and the exact reason code, so the §15.8 refusal-evidence row is durable for every command table
  (U-C8-BLOCK-001).
- A successor teacher rate now closes and supersedes its predecessor **before** claiming the scope's live
  slot, so a second rate in one scope supersedes the first instead of colliding with
  `UNIQUE teacher_scope_slot`; a rate a successor already closed is retractable without re-judging its own
  past instant (U-C8-BLOCK-002).
- Both append-only chains now use §15.4's declared two-statement protocol — append with an empty
  `applicable_slot`, release the predecessor, then claim the slot — so exactly one applicable evaluation
  per Lesson and one applicable correction per snapshot survive a re-evaluation, an override or a second
  correction (U-C8-BLOCK-003).
- `FinancePolicyService::record()` now inserts only its version: the predecessor's conditional
  `active → superseded` move belongs to the separate, audited `supersede()` command, which therefore
  keeps its own command row, audit evidence and affected-row count (U-C8-BLOCK-004).
- The issuance gate now compares **every stored line** with the Lesson's current effective snapshot and
  payability evaluation — correction identity, evaluation identity, disposition, basis, rate pair,
  currency and the exact recomputed amount — and refuses a stale draft `statement_derivation_mismatch`
  for withdrawal and re-drafting instead of issuing stale lines and totals (U-C8-BLOCK-005).
- `FinanceReconciliationService::run()` captures its payload, so a replayed run converges through the
  §15.3 digest invariant, with the required identical- and conflicting-replay proofs added to the
  reconciliation suite (U-C8-BLOCK-006).

Two further corrections are declared in contract §0g: the policy verifier now asserts the *seed shape*
(the only migration-authored rows are the three declared seed keys' version 1, and the statement timezone
key is never migration-seeded) instead of the fresh-state row counts, which a live installation using the
declared `record()`/`supersede()` lifecycle could never satisfy — a fail-closed verifier on every request
was the consequence; and the rate-withdrawal interval proof now runs only where a closure write happens.
Two suite defects are corrected with it (a closure `use` clause carrying a default value in
`tests/phase-2a2u-corruption-runtime.php`, which made that file unparseable, and a malformed
`str_contains()` needle in `tests/phase-2a2u-contract.php`), the concurrency fixture's
`concurrent_policy_record` competing instant is now admissible for its declared winner, and the source
contract suite is **executed and passing** under a PHP 8.5.8 WASM CLI together with a clean parse of all
460 candidate PHP files. The §18 runtime suites remain unexecuted (no MariaDB and no Docker daemon in the
implementation environment) and, as recorded in the phase document, need fixture-flow work before they
can run.

**Independent review correction round 3** (the review of
`97a572937e07c021bf9c0fc9c65da23cdae92e08` / tree `370eda98f7aa84182c7d06c91cbca7caf79637c2` returned
FAIL — CORRECTION REQUIRED with one blocking finding; it is closed on this descendant, additively, with no
reset, rebase, amend or force-push):

- §15.3 replay re-verification is now implemented in every command family. `replay()` no longer returns a
  recorded result id on trust: it re-loads the typed result row under the root the command already holds
  (a locking named-index read), fails closed `command_replay_conflict` when that row is absent or no longer
  carries the command's own selectors, re-proves the family's own declared derivation/integrity, and (where
  the command payload is a pure function of the recorded row) proves the row still reproduces the exact
  payload the command recorded (U-C9-BLOCK-001). `FinanceRule::commandOutcomeStates()` and the shared
  `FinanceSupport::assertReplayState()` / `replayResultRow()` / `assertReplayPayload()` helpers make the
  arbitration uniform; a `refused` row still converges on its refusal, a reconciliation `run` still
  replays its declared `completed` or `failed` outcome, and a state outside the operation's declared
  outcome can never replay as its success.
- Coverage: a new pure, WordPress-free and database-free unit suite
  (`tests/phase-2a2u-replay-unit.php`, **executed and passing** as `phase-2a2u-replay-unit: OK`) proves the
  shared helpers' declared answers, and `tests/phase-2a2u-corruption-runtime.php` gains one
  corruption-replay probe per command family — policy, rate, snapshot, payability, correction, statement,
  reconciliation, payability override and exception resolution — each proving the fail-closed replay over a
  corrupted or deleted recorded result and the converging replay after exact restoration. The source
  contract suite additionally scans every service for the re-load and its own re-proof. The corruption
  suite's fixture flow is corrected with it so a canonical capture resolves the Lesson's own recorded
  outcome anchor; the remaining §18 runtime suites are still written-but-not-executed and the disposable
  WordPress + MariaDB runtime is still required.

**Independent review correction round 4** (the review of
`5e0daf221918634a047c83e9b5034b9f833ea7d9` / tree `0968a4f6b96ea828848354b09a473c2f3001cac4` returned
FAIL — CORRECTION REQUIRED with two blocking findings on §15.3; both are closed on this descendant,
additively, with no reset, rebase, amend or force-push):

- The snapshot-correction replay now reconstitutes and checks the recorded command payload. The
  `correct_snapshot` command facts are built in one place (`FinanceCorrectionService::correctionFacts()`,
  fed canonically on the write path), and `replay()` rebuilds exactly those facts from the **re-loaded
  correction row** and proves them against the recorded `command_payload_digest` with
  `FinanceSupport::assertReplayPayload()`, so a corrupted `result_correction_id` that names a different,
  self-consistent correction of the same Lesson, snapshot and reason — the substitution the review named —
  is refused `command_replay_conflict` instead of converging on it. The typed `result_snapshot_id` and the
  `correction_id` selector are cross-linked to the row the replay re-loaded (U-C10-BLOCK-001).
- Every replay now re-proves the **exact typed result shape its own operation declares**. §15.3 declares,
  per command table and operation, the typed `result_*` columns that operation records
  (`FinanceRule::COMMAND_RESULT_COLUMNS`, `FinanceRule::COMMAND_OPERATION_RESULTS`,
  `FinanceRule::commandOperationResults()`), and the new shared
  `FinanceSupport::assertReplayResultShape()` requires every required typed result to be present and every
  other typed result column of that same table to be `NULL` — so a corrupted command row can never smuggle
  a second, unrelated typed result into a successful replay. `LessonPayabilityService::replay()` returns
  the **verified** override row the re-loaded evaluation carries (never the recorded
  `command.result_override_id`) and additionally cross-links the command's override selector, its typed
  override result and its prior-evaluation selector; an `evaluate` must name an evaluation that carries no
  override. `LessonFinanceSnapshotService::replay()` returns no secondary result and requires a capture's
  selector and typed result to be the one snapshot; `FinanceReconciliationService::replay()` returns no
  secondary run or exception result and cross-links each operation's selector to the row it typed
  (U-C10-BLOCK-002).
- Coverage: `tests/phase-2a2u-replay-unit.php` (**executed and passing**) now also proves the declared
  typed result columns and per-operation shapes and the fail-closed answer for a substituted, an absent
  and an undeclared secondary typed result, and proves the correction command facts' canonical
  reconstitution (the facts rebuilt from a recorded correction row reproduce the recorded
  `command_payload_digest` exactly, while a substituted corrected amount or corrected rate row is
  refused); `tests/phase-2a2u-corruption-runtime.php` (written, **not
  executed**) gains one substituted-secondary-result probe per command family — correction, capture,
  derivation, override, reconciliation run and exception resolution — plus assertions that a converged
  override replay reports the override its own evaluation carries and that a converged capture, run and
  resolution replay report no secondary result; and `tests/phase-2a2u-contract.php` (**executed and
  passing**) scans the declarations, the helper, every family's shape re-proof, the absence of any replay
  that returns a recorded typed result without re-loading it, and the presence of every substitution
  probe. Contract §0i records the round in §15.3.

**Independent review correction round 5** (the review of
`f5138509a0a256df335015b1ef1d6f42dfd06b96` / tree `85db4f8afc99159e4d2f3c8e294d1247d820a899` returned
FAIL — CORRECTION REQUIRED with one blocking finding on §15.3; it is closed on this descendant,
additively, with no reset, rebase, amend or force-push):

- The snapshot-correction command payload now carries **every** material correction fact. The corrected
  rate amount is a canonical fact of `FinanceCorrectionService::correctionFacts()` beside the corrected
  derived amount — `self::canonicalInt($input['corrected_rate_amount_minor']??null)` on the write path and
  `(int)$correction->corrected_rate_amount_minor` on the replay path — so a second, self-consistent
  correction of the same Lesson, snapshot, corrected rate row/version, corrected derived amount, currency
  and reason that differed only in its corrected rate amount is refused `command_replay_conflict` by
  `FinanceSupport::assertReplayPayload()` instead of converging on the substituted row (U-C11-BLOCK-001).
  Contract §12.2's completeness rule and §15.3's payload clause now name the restated rate amount
  explicitly, so the fact set is declared rather than implied.
- The corruption suite's original correction probe corrupted the recorded `corrected_derived_amount_minor`
  and asserted `snapshot_derivation_mismatch`, but the corrected derived amount is itself a payload fact
  and §15.3's payload re-proof runs before the family's own derivation-digest re-proof, so that corruption
  in fact fails closed `command_replay_conflict`. The probe now asserts the code the service produces, and
  the family's own derivation-digest re-proof is kept covered by a third probe that moves a fact the
  payload does not carry — the recorded `prior_snapshot_digest` — and asserts `snapshot_derivation_mismatch`.
- Coverage: `tests/phase-2a2u-replay-unit.php` (**executed and passing**) proves through reflection that
  the builder carries the corrected rate amount and the corrected derived amount as separate facts and
  that a correction whose corrected rate amount alone differs is refused `command_replay_conflict`;
  `tests/phase-2a2u-corruption-runtime.php` (written, **not executed**) carries the three correction replay
  probes; and `tests/phase-2a2u-contract.php` (**executed and passing**) scans for both new probes. Every
  461 candidate PHP file still parses cleanly under the PHP 8.5.8 WASM CLI. Contract §0j records the round
  in §12.2 and §15.3.

- **A versioned finance policy registry** with four declared keys and a single reason-code allowlist; a
  version is immutable in value with exactly one auditable `status` column that moves at most twice in one
  declared direction, unset is the absence of a covering version rather than a null-valued row, resolution
  is by the covered instant and never by the live row, and `FINANCE_STATEMENT_TIMEZONE` is deliberately
  never seeded (see `docs/FINANCE-POLICY-REGISTRY.md`).
- **Temporal admissibility for both effective-dated registries.** A policy version whose `effective_from`
  is not strictly later than its key's recorded consumption maximum is refused
  `policy_effective_from_precedes_recorded_consumption` with a refused command row and a matching
  teacher-less exception and no version written; a rate whose `effective_from` is not strictly later than
  the Teacher's greatest snapshot instant is refused `rate_effective_from_precedes_snapshot`. The seeded
  singleton `finance_policy_roots` orders every policy change against every policy consumer (exclusively
  by the mutation, shared and first by the consumers), so a recorded fact can never be out-covered after
  the fact, and §13.4 rule 14 proves the same invariant over the recorded pairs and triples.
- **Effective-dated teacher rates** with explicit scope, currency and half-open intervals, exactly three
  mutable columns through at most two conditional statements, withdrawn coverage detected at the winning
  specificity before any scope fallback, and no retroactive rewrite.
- **One immutable per-Lesson snapshot** per canonical Lesson, derived from the rate in force at the
  Lesson's locked occurrence-start instant under the policy version that covers that instant, with an
  exact integer amount, an explicit currency and a re-verified derivation digest.
- **A versioned payability derivation** from canonical facts with `pending` blocking issuance and an
  audited, additive administrator override that names the exact derivation it replaced.
- **Teacher compensation statements** per Teacher and half-open period with immutable lines, recomputed
  totals, a fail-closed issuance gate and one-time `issued_at`/`issued_by` evidence stamped by the same
  conditional `draft → issued` transition, plus append-only withdrawal and supersession.
- **Read-only, exact-integer reconciliation** with one bounded finding code and one exact pair of values
  per difference; a run repairs nothing, resolves no exception and tolerates nothing.
- **Audited corrections** that name their exact target row and digest and never mutate it, with a snapshot
  correction that restates the immutable intro policy pair and requires the affected issued statement's
  supersession.
- **Five administrator-only capabilities**, repaired per capability and removed from the Teacher role; no
  Finance route, provider call, credential, schedule or front-end surface; no ledger, invoice, payout or
  bank/tax field; and no mutation of any upstream or provider row.

**Its §18 contract, migration, runtime, statement, reconciliation, corruption, failure and concurrency
suites have not been executed: PHP and the disposable WordPress + MariaDB runtime are unavailable in the
implementation environment**, so executing every suite on that runtime is a mandatory acceptance gate.

## Phase 2A.2-T — Provider-Neutral Payment Execution Seam & Stripe Adapter — candidate, unmerged — 2026-09-25

Schema 29 / migrations `028_payment_execution_seam_provider_adapter` and
`029_payment_event_decision_claim_authority` / build
`phase2a2t-payment-execution-seam-stripe-adapter-20260925.14`, additive on top of the Phase-V candidate
(Schema 27). Implements the RFC-style contract in
`docs/PHASE-2A-2T-PAYMENT-EXECUTION-SEAM-STRIPE-ADAPTER-CONTRACT.md` (correction round 14, SHA-256
`bdd88f8f0a1a0d8ac16ffa82fc081d938c683d1f359ce515c1cb7c8158aa16ad`).

**Correction round 14** (build `…20260925.14`) applies the independent review of the candidate
`057acaeea06bf8d0c2eef2f3039c66295988a3d9` / tree `1c5545c585e49b46b2f7608d89965f07bf3dd3a5` additively,
without rewriting that history. One blocking finding is closed:

- **The append's window is judged only once the claim row is held.** Round 13 took the append's verdict from
  one database-time expression *inside* the conditional statement — `lease_expires_at >= UTC_TIMESTAMP()`,
  with the settlement stamped from that same expression — but MySQL evaluates `UTC_TIMESTAMP()` **once, when
  the statement starts**, not when it reaches the row it judges. An append `UPDATE` that began while the
  lease was live, blocked on another transaction's lock of the claim row until after expiry, and acquired
  the row only afterwards therefore still satisfied the predicate with the instant it began waiting, settled
  the live claim and appended a decision after the decision window had closed — so a delay before the append
  statement acquired its row lock did not change the verdict. `settleDecisionClaim()` now takes the claim
  row's lock **first**, with its own fenced `SELECT … FOR UPDATE` (the owner's own `claim_state = 'claimed'`,
  `claim_generation`, `claim_token_digest`, `active_claim_slot = 1` and the same non-null `lease_expires_at >=
  UTC_TIMESTAMP()` predicate), and runs the conditional `claimed → settled` update only after that read: the
  wait for the row lands on the locking read, which decides nothing, and the update — which can no longer
  wait for that row — takes its database-time verdict after the wait rather than before it. The read can only
  refuse a claim the update would also refuse; the affected-row count remains the only proof of ownership, so
  a window that lapsed while the append was queued settles nothing, stamps nothing and publishes nothing.

`tests/phase-2a2t-contract.php` asserts the corrected source contract (the locking read's fence, `FOR UPDATE`
ahead of the conditional update, and every round-12/13 append property unchanged);
`tests/phase-2a2t-concurrency-runner.sh` adds `append_blocked_on_claim_row` (twenty-four modes): a separate
transaction holds the owner's claim row under `SELECT … FOR UPDATE` in its own transaction until the live
window the owner's append was granted has lapsed, while the owner holds at the append seam and then enters
the append transaction — whose first statement is now that fenced locking read, so the append is queued
behind that lock *inside* its window. The blocker attributes the queued wait to exactly that claim row
(`performance_schema.data_lock_waits` joined to `data_locks` on the claim table's `PRIMARY` index with the
lock data equal to the claim id, and InnoDB's `LOCK WAIT` state where the runtime does not expose those
tables) and releases the row only after the window had lapsed in real elapsed time. The verifier proves the
queued append **settled no claim and appended no decision** — the states an update judging the window with
the instant it began waiting would have turned into one settled claim and one published decision — that the
owner's own generation-1 claim ends `released` with no live slot and no lease and no generation above 1
exists, that the owner reports the event as still owing its decision, and that the next delivery completes
the event with its single decision, while the work the owner committed inside its window stands exactly once
(one R1 evidence, one R1 settlement, one confirmed collection intent, one collected renewal cycle).

**Correction round 13** (build `…20260925.13`) applies the independent review of the candidate
`e92a62747b82f8a37838f886a1034eb770f65e37` / tree `0f4ab9b6ab8de3021e0aa7b7e1e0f48f079c34df`
additively, without rewriting that history. One blocking finding is closed:

- **The append is fenced at the instant it runs, never at an instant its caller read before the seam.**
  Round 12's `settleDecisionClaim()` did require the claim's live slot and an unexpired `lease_expires_at`,
  but the intake captured `$now` *before* it fired the observable append seam
  `dzn_phase_2a2t_before_provider_event_decision_append` and passed that captured instant into the
  transition, which compared `lease_expires_at >= $now` and stamped the settlement from it. A hook callback
  registered on that seam — the seam exists precisely so an observer can hold the operation at its last
  bounded step — or any delay before the statement acquired the claim row's lock could therefore outlive the
  120-second lease while the update still compared the lease against the older instant, settling the live
  claim and appending a decision after the window had closed. The transition now takes its verdict **at
  statement execution**, from one database-time expression: it requires the owner's own
  `claim_state = 'claimed'`, its `claim_generation`, its `claim_token_digest`, its `active_claim_slot = 1`
  **and** a non-null `lease_expires_at >= UTC_TIMESTAMP()`, and stamps `settled_at`/`updated_at` with that
  same `UTC_TIMESTAMP()` — so the window verdict and the settlement can never be derived from two different
  clock readings. It takes **no** instant parameter at all, so no caller can supply one, and the intake
  reads the instants it records on the appended row only *after* the seam. A seam delay therefore changes
  the verdict itself: an append that runs after the lease has lapsed affects zero rows and publishes
  nothing, exactly like a replaced generation, and the owner still releases the live claim it appended
  nothing to and converges, so the next delivery completes the event. This round supersedes the round-12
  mechanism in two places: the window is no longer judged against the `$now` a caller captured before the
  seam, and the append race no longer ages its own claim row to simulate the lapse.

`tests/phase-2a2t-contract.php` asserts the corrected source contract (the database-time lease predicate and
its matching settlement stamp inside the one conditional statement, the absent instant parameter, the
claim-identity-only call site, and the intake's post-seam read of its own row instants);
`tests/phase-2a2t-failure-runtime.php` proves the transition behaviourally with a **real** delay: a claim
taken with a short, still-live window is judged only once the clock has genuinely passed that window, so the
append settles nothing and leaves the claim live — where a caller that had captured its own clock before the
delay would have settled it; `tests/phase-2a2t-concurrency-runner.sh`'s
`stale_owner_at_decision_append` no longer writes its claim row at all: the owner's window is let lapse **in
real elapsed time** at the append seam, so that mode runs for the structural 120-second decision-claim
lease. The verifier proves the lapse was a real one — the instant the owner read from its own live claim at
the seam is that claim's structural window, at least the full `DECISION_CLAIM_LEASE_SECONDS` forward of the
instant the claim was taken, and the release that follows the refused append lands strictly after it —
beside the existing proof that the owner reached the R1/R2 work boundaries, that no successor generation
replaced the stale one, that the append — and never a take-over — refused it (the owner's own generation-1
claim ends `released` with no live slot and no lease, no generation above 1 exists and no live claim
survives), that the stale generation appended nothing and reported the event as still owing its decision,
and that the next delivery is the one that completes the event with its single decision, while the work the
stale generation committed inside its window stands exactly once (one R1 evidence, one R1 settlement, one
confirmed collection intent, one collected renewal cycle).

**Correction round 12** (build `…20260925.12`) applies the independent review of the candidate
`09c4134381ba31c8332a0561bec96c9cbd5238e9` / tree `944b45c7fcb9570442d18468664396ee823994e4`
additively, without rewriting that history. One blocking finding is closed:

- **The decision append is bounded by the same window as the work it publishes.** `settleDecisionClaim()`
  fenced only `claim_state`, `claim_generation` and `claim_token_digest`, so a lease that lapsed after the
  last R1/R2 work unit but before the decision-append transaction still let the stale generation settle its
  live claim and append its decision — publishing authority inside a window §9.5 C10-2 had already closed,
  which makes the lease the bounded window the *whole* decision operation, the append included, runs
  inside. The fenced `claimed → settled` transition now also requires `active_claim_slot = 1` **and** a
  non-null, unexpired `lease_expires_at`, judged against the same `$now` the statement stamps the row with,
  so an append that runs past the lease affects zero rows and publishes nothing. The intake treats that
  zero-row outcome as a closed window: it rolls back, releases the live claim it appended nothing to
  (fenced by its own generation and token, so a successor's claim is never touched) and converges — so an
  expired-but-not-yet-taken-over claim no longer strands the event behind a lease nobody is working inside,
  and the next delivery completes it. The seam between the final work unit and that fence is observable
  through the new §8.4 hook `dzn_phase_2a2t_before_provider_event_decision_append` (ids only, outside the
  worker context and outside any transaction).

`tests/phase-2a2t-contract.php` asserts the corrected source contract (the settlement's own live-slot and
unexpired-lease conditions judged against its own `$now`, the append seam ahead of the fence, and the
release-then-converge order of a refused append); `tests/phase-2a2t-failure-runtime.php` proves the
transition behaviourally — an append that runs past the claim's lease settles nothing and leaves the claim
live, for that generation to release or for exactly one successor to take over; and
`tests/phase-2a2t-concurrency-runner.sh` adds `stale_owner_at_decision_append` (twenty-three modes): the
owner completes **every** R1/R2 work unit of the decision operation and then lets the window it still
exclusively holds lapse at the append seam, aged in the database, with no successor generation having taken
its claim over. The verifier proves the owner reached the R1/R2 work boundaries (so the window closed at
the append, never before the work), that the append — and never a take-over — refused it (the owner's own
generation-1 claim ends `released` with no live slot and no lease, no generation above 1 exists and no live
claim survives), that the stale generation appended nothing and reported the event as still owing its
decision, and that the next delivery is the one that completes the event with its single decision, while
the work the stale generation committed inside its window stands exactly once (one R1 evidence, one R1
settlement, one confirmed collection intent, one collected renewal cycle).

**Correction round 11** (build `…20260925.11`) applies the independent review of the candidate
`d60544a7c30231ec82b887b8238561b4c46dfce0` / tree `0f000a8d9df3312488534729efd44ce961579f27`
additively, without rewriting that history. One blocking finding is closed:

- **A work unit is fenced for the duration of its work, not merely at its entry.** The round-10 renewal
  proved and renewed the claim's bounded window *before* an R1/R2 unit ran, in its own transaction — but
  the R1 submission and every R2 command open separate transactions and fire WordPress hooks, so a unit
  could outlive the 120-second lease, allow another worker to take the expired claim over atomically, and
  still commit the mutation it had already started. The window is now fenced at the **connection's
  statement boundary, from inside the unit's own transaction**: the intake registers one listener on the
  declared `DECISION_UNIT_FENCE_FILTER` (`query`) for exactly the unit's duration and removes it in a
  `finally`, and that listener — which never rewrites a statement, passes `START TRANSACTION`/`ROLLBACK`/
  `SET …` through untouched and never fences its own proof — precedes every other statement (the unit
  transaction's `COMMIT` included) with one `SELECT … FOR UPDATE` of the claim row that must return
  exactly one row: this worker's own generation and token, `claimed`, its live slot and an unexpired
  lease; the lease is renewed first only for a statement that runs outside any transaction. The locking
  read therefore holds the claim row for the rest of the unit's transaction — a take-over needs that same
  row *and* an expired lease, so it can never interleave with a unit — and a window that closed before the
  next statement raises the controlled `DecisionClaimWindowClosed` *before* that statement executes, so
  the R1/R2 service owning the transaction rolls the whole unit back: the stale generation commits no
  statement of the unit it had started, releases the claim it appended nothing to (fenced by its own
  generation and token) and converges, and the next generation completes the event's decision exactly
  once.

`tests/phase-2a2t-contract.php` asserts the corrected source contract, and
`tests/phase-2a2t-concurrency-runner.sh` adds `stale_owner_inside_r1_unit` and
`stale_owner_inside_r2_unit` (twenty-two modes): the owner stalls inside the R1 mutation (respectively
the R2 mutation) with the window of that unit aged past expiry while a contender delivers the same event,
the contender performs no work and appends nothing, and the verifier proves from the successor's recorded
observation that the stale unit was rolled back inside its own transaction — no R1 evidence and no
settlement in the R1 case, after which the successor's own R1 and R2 units apply the ordered consequence
exactly once (one confirmed intent, one collected cycle), and in the R2 case the R1 unit that finished
inside its window stays committed while the intent is still `submitted`, the cycle still
`payment_required` and neither a confirmation event nor a confirmation command exists; that mode's event
occurrence instant is deliberately older than the settlement its own R1 unit committed, so the
successor's re-decision is the controlled `stale_provider_event` refusal and no generation confirms or
collects anything. Both leave exactly one R1 evidence/settlement, one decision appended by the successor,
one released generation-1 claim and one settled successor claim. The same round adds the runtime suite's missing locked-vocabulary
assertion for `provider_credentials_unconfigured`, `live_execution_not_authorised` and
`dispatch_in_flight` (a pre-existing self-inconsistency in the static contract suite, which required
those three strings in a file that never contained them).

**Correction round 10** (build `…20260925.10`) applies the independent review of the candidate
`5d8c379f92cef13baf0a818850661fcb7658509a` / tree `47f9062eea42aabf75fdc21bb80190c07f7fc4be`
additively, without rewriting that history. Two blocking findings are closed:

- **The decision-claim aggregate is a scheduled migration, not a late addition to a completed one.**
  The per-event decision-claim table had been added to `028`, whose schema identity stayed `28`. A
  database that had already completed `028` never re-applies it, so the new table was never created,
  while the strengthened verifier failed the pre-activation guard on the missing table — an
  unrecoverable state for an installation the ledger could have repaired. The claim aggregate is now
  **migration `029_payment_event_decision_claim_authority`'s own storage** and the plugin declares
  Schema `29`: `028` is restored to its fifteen-table set, its verifier neither requires nor validates
  the claim aggregate (it tolerates the scheduled sibling), and
  `verify_payment_event_decision_claim_schema()` runs after `029`, on current-schema verification and
  unconditionally before the schema option may advance to `29` — the same three call sites every other
  phase verifier uses. A completed-`028` installation is therefore repaired by the scheduled migration:
  one additive `dbDelta` of the claim table, no backfill, no claim inferred, nothing settled.
- **Ownership covers the whole decision operation, so an expired generation cannot resume into R1/R2.**
  The claim's 120-second lease fenced the decision *row*, not the work the claim serialises: a worker
  whose lease expired mid-decision could be superseded, have its decision completed by the takeover
  generation, and then resume and execute the same R1/R2 work before its fence failure surfaced at the
  append. The lease is now the **bounded window the owner works inside** — opened before any decision
  work, re-proved and renewed by one fenced conditional statement (own live generation *and* an
  unexpired lease) immediately before the R1 evidence submission and before every R2 command, and closed
  when the decision is published. A renewal can never resurrect an expired window, so a generation whose
  window has closed (its lease lapsed, or exactly one successor generation took the claim over) stops
  *before* the next work unit, performs no decision or consequence work, appends nothing, and converges
  on the owner's decision. The append stays fenced by the same generation and token.

`tests/phase-2a2t-contract.php` asserts both source contracts,
`tests/phase-2a2t-migration-runtime.php` adds the completed-`028` repair rehearsal (and the
completed-`029`/missing-table fail-closed case whose ledger-owned repair restores it), and
`tests/phase-2a2t-concurrency-runner.sh` adds `stale_owner_after_lease_expiry` (twenty modes): the owner
stalls past its own lease, the successor generation takes the claim over and completes the decision, and
the resumed stale generation is refused at the work-unit gate before its first R1/R2 unit — the suite
records every worker's arrival at the inherited R1/R2 work hooks, so it proves the stale worker reached
no work boundary at all, appended nothing, and left exactly one R1 settlement/evidence, one confirmed
intent, one collected cycle, one decision and one settled generation-2 claim. No authority, capability,
policy or provider call was added, no earlier commit was rewritten, and the recorded event row stays
immutable — the claim is its own aggregate.

**Correction round 9** (build `…20260925.9`) applies the independent review of the candidate
`5b477b72d97a329e7bc02ce041be8958af85fe81` / tree `a45d56fb8e1cdbe9785175ff272cf8ef5947b434`
additively, without rewriting that history. Four blocking findings are closed:

- **An event that still owes a decision is always completed.** The convergence path returned `recorded`
  when the recorded event had no decision row at all, so an event that was inserted and then left owing
  its first decision — a crash between the event insert and its decision — was never completed by a
  redelivery. No decision at all is now an owed decision: the redelivery takes the event's decision claim,
  runs the first decision and appends it exactly once. A `conflicting_provider_event` row is not the
  event's decision, so the owed-decision test reads the non-conflict decision timeline.
- **A pending-decision retry is owned by exactly one worker.** Two deliveries could both read the same
  `pending` decision, both run `decide()` and the R1/R2 consequence, and both allocate the same next
  `decision_sequence`, so one failed on the unique index after performing work. A durable per-event
  decision claim (`payment_provider_event_decision_claims`, `UNIQUE event_claim (provider_event_id,
  active_claim_slot)`) is now taken **before** any decision work runs: the winner alone may translate and
  run the consequence, the claim's generation and token fence the `claimed → settled` transition inside
  the transaction that inserts the decision, the loser performs no work at all and converges on the
  owner's decision, and an expired claim is taken over by exactly one new generation.
- **The HTTPS requirement has a configured, allowlisted proxy path.** The precheck ignored the declared
  `PaymentExecutionRule::HTTPS_PROXY_HEADERS` and had no configured-proxy trust path, so a TLS-terminated
  deployment relying on a trusted `X-Forwarded-Proto: https` had every webhook refused `https_required`.
  The transport rule is now direct TLS, the local environment, or a proxy header the operator has
  configured this site to trust and that is a member of the locked allowlist; an unconfigured, empty,
  malformed or non-allowlisted configuration trusts nothing, and only a leftmost `https` passes.
- **`OPTIONS` is receipted like every other registered method.** Declaring the method in the route's
  method set never delivered it: WordPress answers `OPTIONS` in `rest_handle_options_request()`, a
  `rest_pre_dispatch` filter that runs before normal route dispatch, so an `OPTIONS` delivery was answered
  with no receipt at all. The endpoint now registers its own `rest_pre_dispatch` interception at priority
  `1` — ahead of the core handler's `10` — for exactly its two route shapes, returns every other filter
  input untouched, and sends a matched delivery through the same controlled precheck and receipt as a
  routed `GET`/`PUT`/`PATCH`/`DELETE`: `refused`/`method_not_allowed`, `405`, the exact raw body digest and
  byte count, exactly one receipt and never an event. The same round corrected the webhook suite's
  disposable account selector, which was longer than the route's declared 32-character account segment
  and therefore could never reach the endpoint the routing proofs were meant to exercise.

`tests/phase-2a2t-contract.php` asserts the four source contracts, `tests/phase-2a2t-webhook-runtime.php`
proves the recovery, the claim shape, the abandoned-claim takeover, the transport matrix and the `OPTIONS`
interception (registered ahead of the core handler, and receipted with its exact raw body from both route
shapes), `tests/phase-2a2t-failure-runtime.php` proves the claim fence's atomicity,
`tests/phase-2a2t-corruption-runtime.php` proves the claim aggregate fails closed, and
`tests/phase-2a2t-concurrency-runner.sh` adds `pending_decision_retry` and `undecided_event_recovery`
(nineteen modes at that round; twenty after correction round 10). No authority, capability, policy or provider call was added, no earlier commit was
rewritten, and the recorded event row stays immutable — the claim is its own aggregate.

**Correction round 8** (build `…20260924.8`) applies the independent review of the candidate
`91bf288cd1cf4d5c08e7c99cb310a4d0743113b1` / tree `dec38b700c70f116cae53a1df64db011dbd99081`
additively, without rewriting that history. Four blocking findings are closed:

- **Every inbound request is receipted, and the receipt is byte-exact.** The webhook routes were
  registered for `POST` only, so WordPress matched the method before any callback and a
  `GET`/`PUT`/`PATCH`/`DELETE` delivery was answered by routing and never recorded. Both routes now
  declare one locked all-method set, the §9.2 method requirement is decided inside the controlled
  handler, and a non-`POST` delivery is receipted as `method_not_allowed` with `405`. The controller also
  substituted `''` for the raw body on every precheck refusal, so oversized, wrong-content-type and other
  refused requests recorded a zero-byte/different digest; the exact raw bytes are now always passed to the
  receipt, and a refused body is recorded with its true digest and byte count and still never becomes an
  event.
- **Attribution is exact.** Attribution resolved the obligation from metadata alone while the object check
  accepted any stored mapping, regardless of canonical kind, canonical id or active state, so a signed
  event could submit evidence for an obligation the mapped object did not own. The intake now resolves
  exactly one *active* mapping (`active_slot = 1`, `state = linked`) for the event's own provider object and
  requires the canonical obligation that mapping already owns (`obligation` → `canonical_id`;
  `collection_intent` → its `obligation_id`) to equal the obligation the event resolved: an absent or
  historical mapping is `unmapped_provider_object`, a mismatch or multiple candidates are
  `ambiguous_obligation_attribution`, and neither submits evidence.
- **A lost duplicate race converges.** Two concurrent deliveries of one event identity could both see no
  event, and the loser of the insert then received the winner's id after the unique-key collision and
  unconditionally appended a second decision (and a second R1/R2 consequence). Insert-or-resolve now
  reports whether this worker created the event; a worker that meets `UNIQUE provider_event` adopts the
  winner's row and enters the same fact-digest/terminal-decision convergence path a read duplicate enters.
- **A drain cannot translate changed facts.** `drain()` compared only the event-reference digest, so a
  changed but validly signed payload with the same event id bypassed the conflict path. The drain now
  recomputes the full recorded `event_fact_digest`; a mismatch preserves the recorded event, appends the
  controlled `conflicting_provider_event` decision with the R1 exception, and submits no evidence.

`tests/phase-2a2t-contract.php` asserts the four source contracts, `tests/phase-2a2t-webhook-runtime.php`
proves the routing/receipt, attribution and drain behaviour, and `tests/phase-2a2t-concurrency-runner.sh`
turns `duplicate_webhook` into a real duplicate-delivery race and adds `conflicting_duplicate_webhook`
(seventeen modes). No authority, table, capability, policy or provider call was added, and no earlier
commit was rewritten.

- **Owns:** the provider-neutral execution seam (port, request/outcome vocabulary, provider registry), the
  provider account and object-mapping registry, the immutable execution command with its append-only
  attempt/result rows and its single mutable dispatch claim, the Stripe adapter and its exact-raw-body
  webhook route, the durable provider-event intake with its bounded ordered R2 consequence, the
  authenticated-encryption secret vault, and Schema 028 with its fail-closed verifier.
- **Does not own:** any live provider call, credential, charge, refund, payout, notification delivery,
  Term/Enrolment/Lesson/schedule/attendance write, provider recurring model, automatic-charge lead time,
  recovery threshold, refund consequence, portal, Theme or deployment authority. `LIVE_EXECUTION_PROVIDERS`
  and `PROVISIONABLE_PROVIDERS` are empty, so the Stripe adapter is provably incapable of an outbound call
  and no code path — not even the capability-authorised vault surface — can store a Stripe credential.
- **Dispatch integrity:** the command, its sealed dispatch descriptor and its dispatch claim commit
  together before the account-root lock is released; the provider call happens outside any transaction;
  the envelope is opened exactly once, in the non-mutating pre-call preflight; the `ok` verdict mints the
  one-use capability the single call consumes; a descriptor failure before any call ends a `claimed` claim
  `released` with a durable `refused`/`dispatch_descriptor_unavailable` result; an expired lease is taken
  over by exactly one fenced generation and reconciled before any re-issue.
- **Evidence posture:** `git diff --check`, shell syntax, Git object integrity and source scans pass. The
  §17 PHP/runtime suites (contract, migration, runtime, webhook, secret, corruption, failure and the
  twenty-four-mode concurrency matrix) are written and wired but **could not be executed in this environment
  because PHP and the disposable WordPress + MariaDB runtime are unavailable**. Executing them is a
  mandatory acceptance gate and remains outstanding.
- **Candidates and merge:** single coherent candidate, no merge, no deployment.

## Phase 2A.2-V — Provider-Neutral Google Calendar & Meet Integration — candidate, unmerged — 2026-09-25

Schema 27 / migration `027_google_calendar_meet_provider_integration` / build
`phase2a2v-provider-neutral-google-calendar-meet-20260925.5`. Reconstructed candidate: the previously
recorded Phase-V candidate `3724edb3c36959f3657e6b495ab96f26d347c476` / tree
`5402cf890e2db898bccb15ba8ffc97176ced6457` was lost from every workspace, backup object store, reflog
and remote branch, so this slice was rebuilt from the approved provider-neutral contract, the
authoritative current code/docs and the preserved Phase-V run evidence. It is additive on top of the
materialised R1/R2 base and renumbers nothing. See
`docs/PHASE-2A-2V-GOOGLE-CALENDAR-MEET-PROVIDER-NEUTRAL-INTEGRATION.md`.

**Correction round 5** (build `…20260925.5`) applies the independent-review finding against the
candidate `389a5463877a2ae92eaefd7020789a5ad33d2943` / tree
`d2965765ff8d5afb824f83f5a44d19b9ca65a410`, additively and without rewriting that history:

- Concurrent deliveries of one provider event key with materially changed contexts on *different*
  Lessons could lose the durable conflict receipt the immutable-ingest contract requires. Both
  contenders could observe no receipt before either took the provider-scoped sequence lock; the
  winner committed, and the loser met the unique `provider_event` index, found the winner's fact
  digest different and threw `IdempotencyConflictException` without recording a conflict, so a
  materially changed context was merely rejected instead of durably recorded. The provider event key
  itself is now decided under that provider-scoped serialisation: the lock is taken before the
  committed head is read and held until the deciding transaction has ended, the committed receipt is
  re-read under it, and a contended loser records the durable conflict exactly as the sequential case
  does. The unique `provider_event` index stays as the durable guard, and a delivery that still loses
  that index race is routed through the same recorder (`conflictReceipt()` → `recordConflict()`)
  rather than being reduced to a bare rejection; `recordConflict()` converges on the recorded row
  under the unique `conflict_identity` index when two contenders carry the identical changed context.
  The concurrency matrix gains the `cross_lesson_event_key_race` mode and the static contract now pins
  the re-read under the lock and the durable conflict routing.

**Correction round 4** (build `…20260925.4`) applies the independent-review findings against the
candidate `d9c47b60d085817009a0734e51886154352b6359` / tree
`bc02802f40a780b19750d5e349e44f98f206739d` (materialised here as commit `eed38cda…`, identical tree),
additively and without rewriting that history:

- `ProviderIntegrationRepository::lockLessonRoots()` now takes the canonical chain in the declared
  Phase-V order — Student–Course identity root → Enrolment → Term → canonical Lesson — instead of the
  reverse, so a Phase-V ingest or projection can no longer form the lock cycle that deadlocked against
  a canonical Phase-L/M/N/O/P operation on the same aggregate. The Lesson is read first only as an
  unlocked hint that names its Enrolment; the locked relationship is revalidated before it is handed to
  a caller. `ProviderIntegrationService::acknowledge()` now also locks the canonical chain before the
  mapping row it needs, which was the same cycle in mirror image. The concurrency matrix gains the
  `ingest_vs_canonical_authority` mode and the static contract now pins the declared order.
- A new provider-event receipt no longer allocates `event_sequence` with an unsynchronised
  `MAX(...)+1`. The allocation is serialised on a provider-scoped lock
  (`lockProviderEventSequence()` / `releaseProviderEventSequence()`, `GET_LOCK`) that is taken before
  the head is read and released only after the receipt transaction has ended, so two deliveries for
  different Lessons take distinct sequences instead of colliding on the unique
  `(provider_code,event_sequence)` index, which stays as the durable guard. The concurrency matrix
  gains the `provider_event_sequence_race` mode.

**Correction round 3** (build `…20260925.3`) applies the independent-review findings against the
candidate `d9eec89715f20c8d12c61173e4c7b6e9c5769fef` / tree
`5850a5a405800cd981c667d36d99328394af6b49`, additively and without rewriting that history:

- `ContractProviderAdapters::normalise()` now translates the digest-bound `raw_body` of the
  authenticated envelope — re-verified against the envelope's own body digest — and never an outer
  `facts` field, which the ingest seam does not hand over and which a caller must never be able to
  state facts through. The adapter also imports `ProviderIntegrationRule`, so the deterministic
  transport actually verifies the envelope instead of failing before any fact is read.
- Handoff-outcome allocation is serialised: the attempt number is chosen inside a transaction that
  locks the parent receipt row, so concurrent retries of one received event cannot collide on the
  unique `(provider_ingest_event_id, handoff_attempt)` index after the Phase-P handoff. A contender
  whose outcome was already recorded — and a refusal offered for a receipt that already carries an
  admission, which is never superseded — re-reads the durable outcome and converges instead of
  surfacing a duplicate-key persistence failure.

**Correction round 2** (build `…20260925.2`) applies the independent-review findings against the
candidate `6c2ab1ce32676286d10a8248fab358ed02e7694a` / tree
`ed10e67cf29bb4bcfc599175df0f20d351a3a78c`, additively and without rewriting that history:

- Consent initiation now digests the deterministic request facts only and checks the recorded command
  before any one-time material exists, so an identical idempotency key converges and the replay
  returns the recorded `connection_id` and intent without re-issuing state, verifier or challenge.
- `integration_oauth_authorizations` carries `connection_id`, so completion locks and settles exactly
  the lifecycle its intent was created for; every competing issued intent is explicitly `rejected`
  and every competing authorizing/connected sibling is explicitly closed.
- A translation-only projection (the real Google seam) is recorded as a `pending` mapping with no
  active slot and is promoted to `verified` only by a separate
  `acknowledge_calendar_projection`/`acknowledge_meeting_projection` command carrying the
  acknowledged provider result; the acknowledged reference is digested on entry.
- Provider ingestion accepts only a delivery envelope a named trusted transport already
  authenticated (transport identity, UTC authenticated instant, exact body bound by digest, transport
  proof reference stored as a digest). A raw delivery, a bare body or a `x-goog-channel-token` header
  is never proof.
- `provider_ingest_events` is immutable — written once in its `received` state and never updated —
  and the new append-only `provider_ingest_outcomes` table records the handoff outcome per attempt.
  Admission is written only after the Phase-P handoff succeeded and carries that result's digest, so
  an interrupted request can no longer be answered with an admission Phase P never saw.
- Self-service reads require `dzn_view_provider_integrations` **and** exact Teacher ownership.

- Ten provider-neutral integration tables with a fail-closed verifier: `integration_connections`,
  `integration_credentials`, `integration_oauth_authorizations`, `provider_identity_mappings`,
  `provider_calendar_event_mappings`, `provider_meeting_mappings`, `provider_ingest_events`,
  `provider_ingest_outcomes`, `provider_event_conflicts` and `provider_integration_commands`. No
  provider-specific column, no plaintext credential column, no mutable column on append-only
  evidence, and a unique active slot per Teacher+provider, per provider subject and per
  Lesson+schedule version.
- `ProviderIntegrationService` owns the recorded Teacher consent lifecycle (connect/refresh/
  disconnect/revoke/reconnect-as-new-consent), server-side one-time state with PKCE, connection
  identity mappings, Calendar/Meet projections against one exact applicable canonical schedule
  version, retraction that never touches canonical truth, and digest-only command evidence with
  unconditional payload-digest replay comparison.
- `IntegrationSecretService` seals renewable credentials with `sodium_crypto_secretbox` (AES-256-GCM
  fallback), a unique nonce per row, a salt-derived key with `dzn_provider_integration` domain
  separation, stored key/cipher versions and an envelope bound to exactly one connection; decryption
  fails closed on an unknown version, malformed nonce, truncated ciphertext or failed authentication.
- `ProviderEventIngestService` accepts only an authenticated envelope, normalises and deduplicates
  provider events on full immutable context equality, records durable conflict receipts for a changed
  context without overwriting the original, and hands facts to
  `CanonicalAttendanceIntakeService::ingestProviderEvidence` only. It never resolves a participant
  identity, never writes Phase-O/P storage and holds no integration row across the Phase-P
  transaction.
- `GoogleCalendarMeetAdapter` is the only Google-specific seam and is a pure translation: no HTTP
  client, no OAuth library, no credential, no webhook and no provider call anywhere in the phase.
  `ContractProviderAdapters` implements all four ports deterministically for the evidence runs.
- Least privilege: administrator holds `dzn_connect_own_provider_calendar`,
  `dzn_manage_provider_integrations`, `dzn_revoke_provider_integrations`,
  `dzn_ingest_provider_events` and `dzn_view_provider_integrations`; the Teacher role holds only
  self-connect and self-view and is actively stripped of the management, revocation and ingestion
  grants. Capability repair is per capability.
- Evidence for correction round 2: the correcting environment had no PHP interpreter and no reachable
  container runtime (network restricted), so every PHP suite — including the two that need no
  WordPress — is marked NOT EXECUTED HERE and must be re-run in the reviewer's disposable harness;
  the earlier round's PASS results do not carry over to changed source. What was executed here: a
  structural balance check over every touched PHP file, a full manual review against the findings,
  `git diff --check` and a complete `git status` review.
- Evidence for correction round 3: the same environment constraint holds — no PHP interpreter on the
  `PATH` and no reachable Docker runtime (the CLI reports `permission denied` on the daemon socket) —
  so every suite in the phase's test surface, including `tests/phase-2a2v-contract.php` and
  `tests/phase-2a2v-isolated-runtime.php`, is again marked NOT EXECUTED HERE and must be re-run in the
  reviewer's disposable harness before acceptance. What was executed here: a structural balance check
  over every PHP file in `src/` and `tests/` (371 files, 0 unbalanced), a line-by-line review of each
  corrected path against both findings (including the fixture trace that proves the body-bound
  normalisation and the serialised attempt allocation), a symbol-resolution check that every class the
  touched files reference is imported or same-namespace — which is how the missing
  `ProviderIntegrationRule` import in the deterministic adapter was confirmed and closed — a
  content-level tree check, `git diff --check` and a complete `git status` review.
- Evidence for correction round 4: the environment constraint is unchanged, so every suite in the
  phase's test surface is once again marked NOT EXECUTED HERE and must be re-run in the reviewer's
  disposable harness before acceptance. What was executed here: a structural balance check over every
  touched PHP file, a line-by-line review of each corrected path against both findings (a trace of
  `lockLessonRoots()` and of `acknowledge()` through the canonical authorities they interleave with,
  and a trace of the provider-sequence allocation through the two-connection race the new mode
  stages), a symbol-resolution check over the touched files, `sh -n` over the extended concurrency
  runner, the extended static contract in `tests/phase-2a2v-contract.php` (declared lock order,
  revalidations, chain-before-mapping in the acknowledgement, and sequence serialisation before the
  receipt insert), `git diff --check` and a complete `git status` review.
- Evidence for correction round 5: the environment constraint is unchanged, so every suite in the
  phase's test surface is once again marked NOT EXECUTED HERE and must be re-run in the reviewer's
  disposable harness before acceptance. What was executed here: a structural balance check over every
  touched PHP file (delimiters, strings and comments scanned as tokens), a line-by-line review of the
  corrected path against the finding (a trace of the provider-scoped key decision through
  `lockProviderEventSequence()`, the re-read of the committed receipt, `duplicate()`, `conflict()` and
  `insertConflict()` to the durable conflict receipt, and a trace of the two-Lesson race the new mode
  stages), a symbol-resolution check over the touched files, `sh -n` over the extended concurrency
  runner, the extended static contract in `tests/phase-2a2v-contract.php` (re-read under the
  provider-scoped serialisation before the insert, durable conflict routing on the guarded path, and
  no bare idempotency-conflict rejection of a materially changed context), `git diff --check` and a
  complete `git status` review.

## Phase 2A.2-R2 — Renewal, Next-Term, Recurring Enrolment/Collection, Recovery, Lapse & Refund Authority — candidate, unmerged — 2026-09-23

Schema 26 / migration `026_renewal_recurring_enrolment_authority` / build
`phase2a2r2-renewal-next-term-collection-recovery-20260923.1`. Additive only: it adds the
recurring-enrolment orchestration layer above R1 and preserves every R1/Schema 25 invariant.

- Six aggregates with append-only events and digest-only commands: `recurring_enrolments`,
  `renewal_cycles`, `collection_intents`, `recovery_cases`, `refund_review_cases` and
  `recurring_protections`, each with capability-protected, PII-minimised read seams.
- `RecurringEnrolmentService` (establish/set_collection_mode/suspend/resume/close),
  `RenewalCycleService` (open/guarantee/require/confirm/bind/lapse/cancel/close),
  `CollectionIntentService`, `RecoveryService`, `RefundReviewService` and
  `RecurringProtectionService`; next-Term creation delegates to `CommercialTermFundingService`
  and Phase-L, protected-capacity release delegates to `CommercialCapacityService`.
- The next-Term boundary is progression-derived from the current Term's applicable schedule versions
  and the R1 Regular recurring-pattern occurrences resolved through the Phase-Q wall-clock rule, and
  fails closed with `boundary_facts_required` rather than inventing a boundary.
- Every R2 mutation serialises on the R1 `commercial_account_roots` row of the owning beneficiary
  Student, so the fixed R1 lock order is never inverted and unrelated Students never contend.
- R2 never nests a transaction inside a delegating R1 command: R1 owns its transaction, R2 verifies
  and records the durable outcome, and a retry adopts that outcome instead of duplicating a Term,
  funding plan, entitlement or release.
- The eleven channel-neutral notification intents are published as intent names only through the
  existing `platform_outbox` seam with a keyed digest identity; no template, recipient or delivery
  record exists.
- Undecided product decisions stay behind safe unset/deferred seams: refund `academic_consequence`
  is always NULL, automatic-charge lead time is unset by default, and recovery lapse fails closed
  while `PAYMENT_RECOVERY_POLICY` is unset.
- Two pre-existing adjacent failures were repaired so the pure regression suite can be quoted green:
  `tests/phase-2a2r1-contract.php` accepts the monotonic `phase2a2r*` build identity and locates the
  025 required-migration entry, and `tests/phase-2a2a-capability-lifecycle.php` reads the marker from
  the `Migrator` authority and covers the current protected capability set.

### Correction round 1 (derived-fact ownership and terminal-cycle integrity)

Five boundaries were hardened without adding product policy, a provider column, a lock, a
Term/Lesson/schedule writer or a delivery path:

- `open_cycle` now requires the source Term to be the recurring enrolment's **own** canonical Term
  (non-cancelled, non-archived); an unknown, foreign, legacy, archived or cancelled Term fails closed
  with `canonical_source_term_required` before any fact or cycle row exists.
- A collection intent now proves the **exact cycle obligation**: an R1 obligation issued to the same
  beneficiary Student and Course in the cycle's frozen currency, otherwise
  `collection_obligation_ownership_conflict`.
- `confirm_collection` consumes the cycle's **authoritative first non-cancelled** collection intent, so
  a later obligation (tranche 2 may settle early) or a cancelled intent can never collect the cycle.
- A cycle may only lapse or be cancelled once every **active continuous protection** it owns has been
  released through the protected `release_protection` command (the R1-delegated release under the same
  per-Teacher scheduling root); otherwise it fails closed with `recurring_protection_release_required`
  instead of orphaning a live protected claim behind a terminal cycle.
- Closing a recurring enrolment is now also blocked by an **open refund/reversal review** on its own
  canonical Enrolment (`recurring_enrolment_not_closable`), completing the §5.1 closure guard.

The Schema 26 verifier now additionally rejects any table smuggled in beside the phase storage that
claims an R2-owned prefix without being one of the eighteen declared tables (`unexpected renewal
storage`).

### Correction round 2 (successor-Term position, cross-commitment ownership and accepted evidence)

One orchestration defect that blocked the documented renewal path, plus three cross-commitment gaps,
were corrected without adding product policy, a provider column, a lock, a Term/Lesson/schedule
writer or a delivery path:

- `bind_next_term` now forwards the **Phase-L aggregate position** the successor Term replaces
  (`expected_latest_term_id` + a `closed`/`cancelled` `expected_latest_state`) instead of omitting it,
  and proves from stored facts that the term is the latest non-archived canonical Term of the cycle's
  own Enrolment. Phase L is the sole Term authority and refuses a successor Term without that proof,
  so the previous shape could never have completed a real renewal. A missing or inconsistent position
  fails closed with `renewal_aggregate_position_required` / `renewal_aggregate_position_mismatch`; R2
  still never closes, cancels or guesses a Term.
- `bind_next_term` proves the entitlement belongs to the cycle's own beneficiary Student, Course and
  frozen currency before delegating (`renewal_entitlement_ownership_conflict`) and proves the created
  Term's funding plan sits in the same Student/Course chain afterwards
  (`renewal_term_binding_conflict`).
- `establish_protection` re-proves the adopted claim inside the serialised transaction: it must still
  be active and must belong to the cycle's own Student and Course
  (`recurring_protection_claim_conflict`), and a claim may not be adopted by a second cycle.
- A collection intent and a cycle may only be confirmed by **accepted** R1 payment evidence for the
  exact obligation: a settlement recorded against `rejected`/`unmatched` evidence fails closed with
  `accepted_payment_evidence_required` (no settlement at all remains `obligation_not_settled`).
- `record_refund_evidence` now proves the reviewed purchase, obligation and accepted evidence belong
  to each other and share the recorded currency (`refund_review_evidence_conflict`).

The Schema 26 verifier additionally rejects a raw key or raw reference column on any append-only phase
table (`renewal evidence must stay digest-only`), and the migration runtime proves that rejection.

The shared R2 fixture now establishes the authoritative occupancy of the funded Term (activated Term,
Teacher Assignment, one standard canonical Lesson and its first schedule version), because the
next-Term boundary is derived from the current Term's applicable schedule versions. A fixture without
those facts could only have proved `boundary_facts_required` rather than the derivation.

### Correction round 3 (contract §13 pre-implementation prerequisites)

The prerequisites the governing contract lists as gating R2 execution are closed, additively to the
authority rather than by changing it:

- **Concurrency runner executability.** The host-run disposable-runtime validation of `f9df3bf`
  failed all nine R1 concurrency modes with `/tests/phase-2a2r1-concurrency-runner.sh: Permission
  denied`. Every `tests/*-concurrency-runner.sh` — the six committed as mode `100644` (`2a2g`,
  `2a2i`, `2a2o`, `2a2p`, `2a2r1` and the new `2a2r2`) plus the seven already `100755` — is now
  committed as `100755`.
- **Fixture-order runtime failure.** The same validation failed the *R1 failure runtime* with
  `teacher_slot_conflict` from `CanonicalContinuationService::holdFirstRegularSlot()`. The cause is
  fixture order, not authority: the disposable runtime keeps one database per suite and the shared
  fixture uses one Teacher, so an earlier suite's legitimately scheduled applicable canonical Lesson
  occupied the exact interval the fixed formula re-used. `tests/phase-2a2r1-fixture.php` now
  authorises the first whole-week candidate free of applicable canonical Lesson schedules, effective
  Phase-Q holds and active protected R1 capacity intervals, failing loudly when none exists; the slot
  remains an explicitly administrator-authorised record.
- **Stale-documentation debt.** `ARCHITECTURE.md`, `MODULE-BOUNDARIES.md`, `MIGRATION-STRATEGY.md`,
  `COMMERCIAL-POLICY-REGISTRY.md`, `DATA-MODEL.md`, `README.md`, `DELNAVAZAN-CORE-CONTINUITY.md`, the
  Phase-R1 phase document and this changelog now record Phase 2A.2-R1 merged and closed at Schema 25
  on `main` (with the Schema 26 R2 candidate above it) instead of describing R1 as an unmerged
  candidate, and the registry records the three renewal/collection class-B policies.

### Correction round 4 (recovery-state enforcement)

Independent review of the round-3 candidate failed on one finding: the Recovery Case authority
recorded the representation of a recovery without enforcing the two states §5.4 fixes for it. Both are
now enforced from stored facts inside the serialised transaction, after the owning Student's R1
commercial account root is held. No product policy, provider column, lock, Term/Lesson/schedule writer
or delivery path was added.

- **`open` records a failed collection intent.** A pending, submitted, confirmed, recovered or
  cancelled intent can no longer seed a recovery case, and a cycle that already reached a terminal
  state (`lapsed`, `cancelled`, `closed`) is never reopened by a recovery record; the source states
  are re-read under the lock and fail closed with `collection_intent_not_failed` /
  `invalid_renewal_cycle_state`. The live-cycle vocabulary is one locked constant
  (`RecurringRule::CYCLE_LIVE_STATES`) shared with the continuous-protection guard.
- **`recovered` records the exact R1 evidence that settled the obligation.** `mark_recovered`
  re-proves accepted R1 settlement for the case's own collection intent through the same
  `RecurringSupport::settlementReason()` seam the collection commands consume: an unsettled obligation
  fails closed with `obligation_not_settled` and a settlement recorded against non-accepted evidence
  with `accepted_payment_evidence_required`. R2 writes no settlement, reversal or clawback of its own.
  A recovery may be recorded from `open` or `recovering`: the contract orders the recovery activity but
  never requires an attempt event before the settling evidence. The previous `recovering`-only source
  set also made concurrency mode `recovery_vs_satisfaction` unsatisfiable — its pre-state opens the
  case and its holder records the settling evidence, so `the recovering worker must record the
  recovery` could never have held.
- `tests/phase-2a2r2-runtime.php` proves both branches and the terminal-cycle refusal;
  `tests/phase-2a2r2-contract.php` asserts the enforcement points and the shared vocabulary; the
  failure-injection suite opens its recovery case while the intent is still failed.

### Correction round 5 (independent review of the host-materialized candidate failed on five findings)

The independent review of the host-materialized candidate (host correction round 2 of this task chain;
failed candidate `98b01601a8005efada67553fc1b5a4bba7d284bc`, tree
`1d21b3c1963425f962d829bb9633643a584cd03f`) returned **FAIL — CORRECTION REQUIRED** on five blocking
findings. All five are closed here, additive to the authority above and with no product policy,
provider column, lock, Term/Lesson/schedule writer or delivery path added.

- **Refund/reversal provenance (§5.5).** `record_refund_evidence` no longer accepts any accepted payment
  evidence with a caller-supplied kind and sum. The reviewed evidence must be that purchase's and
  obligation's *accepted* evidence of exactly the reviewed kind, and its exact amount and currency
  become the recorded review sum; a caller value that disagrees fails closed
  (`refund_review_evidence_conflict`, `refund_review_amount_conflict`). A `reversal` has no
  authoritative R1 representation (`CommercialRule::EVIDENCE_KINDS` records none), so it is refused with
  `reversal_evidence_not_supported` rather than dressing an ordinary successful payment as one.
- **Derived cycle mode (§5.1/§5.2).** `open_cycle` no longer accepts a free `collection_mode`. It locks
  and reads the recurring enrolment and snapshots *its* recorded mode; a caller that supplies a
  different mode fails closed with `recurring_collection_mode_conflict`.
- **Derived collection intent (§4/§5.3).** A collection intent opens only on a live `payment_required`
  cycle (`invalid_renewal_cycle_state`), only in the kind the cycle's frozen mode authorises
  (`collection_intent_kind_conflict`), and its `charge_at` is derived solely through the single
  `RenewalCycleService::automaticChargeAt()` seam from the recorded
  `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` policy and the cycle's own boundary. A caller-supplied charge
  instant is refused (`collection_charge_time_not_authoritative`), so the unset-policy safe default can
  no longer be bypassed.
- **Current-Term protection ownership and release authority (§5.6).** Protection binds the active claim
  of the cycle's own recorded `source_term_id` for its own Student and Course — the successor-Term claim
  the renewal itself creates can never be adopted (`recurring_protection_claim_conflict`) — and the live
  cycle state is re-proved inside the serialised transaction. `release_protection` now requires a
  durable successor (the next Term's own R1 funding plan and its capacity claim) or an authorised
  terminal path (the claim already released in R1, a `lapsed`/`cancelled` cycle, or an explicit
  evidenced terminal recovery lapse of that very cycle); anything else fails closed with
  `renewal_successor_not_durable` *before* any R1 capacity is touched, so a release either adopts a
  durable fact or records one R2 is authorised to record.
- **Fail-closed aggregate reads (§7.3).** Every read model now proves its aggregate before returning it:
  gap-free contiguous `event_sequence`, a leading null `from_state`, a chain in which each event
  continues from its predecessor, only legal transitions and event types from one locked rule table,
  the final event agreeing with the recorded current state, a recorded aggregate version equal to the
  number of events that advanced it (every event except the version-neutral `extended`), and the linked
  ownership facts (Enrolment, source Term, cycle obligation, recovery cycle/intent, reviewed
  purchase/obligation/evidence, protection claim Term). A row rewritten to `closed` while its history
  still ends at `established` is now refused rather than returned as authority. The recovery attempt
  event also now uses its declared `attempt_recorded` type instead of the state name, so the locked event
  vocabulary is the vocabulary the services actually write.

`tests/phase-2a2r2-runtime.php` exercises every new guard (including the terminal-lapse release path and
the successor-Term claim refusal), `tests/phase-2a2r2-corruption-runtime.php` adds rewritten-row and
rewritten-history probes plus the exact refund-evidence provenance, `tests/phase-2a2r2-failure-runtime.php`
re-orders its protection block behind the delegated binding so the release is authorised, the concurrency
pre-state now builds a durable successor for `release_vs_succession` and a real refund evidence for
`refund_vs_settlement`, and `tests/phase-2a2r2-contract.php` asserts all of the above.

- State: `CANDIDATE — AWAITING INDEPENDENT REVIEW`. The delivered 404-file tree hash is reported in
  the task handover (embedding it here would change the tree it describes). No provider call,
  credential, notification delivery, Theme change, merge or deployment occurred. **No §11 runtime
  suite has been executed
  against this candidate** — the authoring sandbox has no PHP, no MySQL/MariaDB and no reachable
  Docker daemon, so the migration, authority, corruption, failure-injection and concurrency suites
  plus the adjacent regressions must still be run on the disposable local runtime (exact commands in
  `PHASE-2A-2R2-RENEWAL-NEXT-TERM-COLLECTION-RECOVERY-AUTHORITY.md`). Structural evidence only:
  balance/lint pass over 348 PHP files, installer↔verifier and service↔schema column cross-checks,
  reference resolution, forbidden-surface scan, `git diff --check`, `sh -n`, and the runner
  executable bits.

### Correction round 6 (independent review of the host-materialized correction candidate failed on two findings)

The independent review of the correction candidate (host correction round 3 of this task chain; failed
candidate `54a4ce29ea3d6dab9ea39475a2f6072057b94965`, tree
`d12c3f0584873fe73c386f6cbf4ac979193bd348`) returned **FAIL — CORRECTION REQUIRED** on two blocking
findings. Both are closed additively, with no product policy, provider column, lock,
Term/Lesson/schedule writer or delivery path added.

- **The event type is proved against the transition it records (§5.1, §7.3).** The read proof accepted
  *any* event type from the aggregate's declared vocabulary, so a valid-but-forged type — or a
  `collection_mode_changed` event rewritten to `resumed` — passed the state and version checks while
  auditing a fact the aggregate never recorded. `RecurringRule::AGGREGATE_EVENT_TRANSITIONS` now maps
  every legal transition of every aggregate to the single event type that may record it, and
  `RecurringIntegrity` refuses any other pairing. The static contract test also proves the map is
  exactly the locked transition table: no legal transition unrecorded, none invented.
- **The recurring-enrolment `collection_mode` is proved as an audited history (§5.1).** The mode is a
  mutable audited attribute, so it is now proved the way the state is: the opening event must carry a
  controlled mode, every later event must continue the mode its predecessor recorded, only a
  `collection_mode_changed` event may change it (and must change it), every other event must leave it
  unchanged, and the final event's mode must equal the row's. A current row rewritten from `manual` to
  `automatic` — a *valid* mode that previously passed every structural check — is therefore refused
  instead of returned as false authority, as is an opening event the following event does not continue.
- **A repeated recovery attempt stays legal and readable (§5.4).** Attempts are append-only events with
  no mutable counter, so `record_recovery_attempt` on a case that is already `recovering` records a
  same-state `recovering|recovering` event. The locked transition table now carries that step and the
  event-type map records it as `attempt_recorded`, so a legitimately written second attempt is no longer
  indistinguishable from corruption.
- **The public history reads are the same fail-closed seam (§7.3).** `events()` previously returned the
  raw history table, so a malformed or orphaned aggregate leaked its events even though `one()` refused
  the aggregate. Each of the six read services now shares one private validated loader: `one()` and
  `events()` both load the row and the append-only history, re-prove the linked ownership facts and run
  the `RecurringIntegrity` proof, and only then does `events()` shape the proved events.

`tests/phase-2a2r2-corruption-runtime.php` adds the valid-alternate-mode, mode-continuity,
valid-but-forged-event-type and fail-closed-history-read probes for all six aggregates (its audited
mode history is now recorded through the real `set_collection_mode` command, and each of the six
history seams is proved refused on a malformed aggregate), `tests/phase-2a2r2-runtime.php` proves the
repeated recovery attempt, and `tests/phase-2a2r2-contract.php` asserts the new rule table, the exact
map↔transition-table equality, the shared validated loader and every new probe.

- State: `CANDIDATE — AWAITING INDEPENDENT REVIEW`. No provider call, credential, notification
  delivery, Theme change, merge or deployment occurred. Structural evidence only: a brace/paren
  balance check over the touched PHP files, the extended static contract test cross-checked by hand
  against the locked rule table, `git diff --check`, and the reference-resolution and forbidden-surface
  scans. **No static contract, migration, authority, corruption, failure-injection or concurrency suite
  has been executed against this candidate** — the authoring sandbox has no PHP, no MySQL/MariaDB and no
  reachable Docker daemon, so all of them must still be run on the disposable local runtime (exact
  commands in `PHASE-2A-2R2-RENEWAL-NEXT-TERM-COLLECTION-RECOVERY-AUTHORITY.md`).

## Phase 2A.2-R1 — Commercial Purchase, Funding & Current-Term Capacity Authority — merged and closed — 2026-09-20

Merged to `main` as a fast-forward of the independently re-reviewed correction-round-6 candidate;
authoritative `main` is `f9df3bfb0fda79fba7dee916c4687464ee67d480`, which also carries the
subsequent fresh-install capability-bootstrap ordering correction. **Not deployed, no production
cutover.** The correction rounds below are retained as review history.

### Correction round 6 (independent re-review of `2af26260d1ba711a18f9fc73c15923531cab69cd` failed on one finding)

The independent re-review of correction round 5 **FAILED** on `C6-MAJOR-001`; everything else passed.
Additive descendants of `2af2626` close exactly that finding, with no amend, rebase, squash, reset or
force push.

- `C6-MAJOR-001` — replay validated populated result/ownership fields but ignored selectors that must
  be NULL for the operation. The new canonical `CommercialCommandShape` enumerates every nullable
  command selector (`student_id`, `teacher_id`, `offer_id`, `obligation_id`, `purchase_id`,
  `entitlement_id`, `claim_id`, `term_id`), declares the exact selector set each operation owns,
  requires every owned selector to equal the revalidated aggregate, requires every other selector to
  be **exactly NULL**, and requires the domain, operation, `result_state`, `result_id` and audit
  fields to be the revalidated recorded result. Operation shapes: handoff owns
  student/teacher/offer/purchase/entitlement/claim (`obligation_id`, `term_id` NULL); release owns
  student/teacher/purchase/entitlement/claim (`offer_id`, `obligation_id`, `term_id` NULL); Term
  binding owns student/offer/purchase/entitlement/claim/term (`teacher_id`, `obligation_id` NULL,
  because the Teacher authority travels with the claim and the Enrolment).
- Corruption evidence: same-key probes installing a foreign-but-valid identifier into every
  inapplicable selector (`obligation_id`/`term_id` on handoff, `obligation_id`/`term_id`/`offer_id` on
  release, `teacher_id`/`obligation_id` on binding), plus positive assertions that those selectors are
  genuinely NULL in the written commands. Each probe proves rejection, preserved corruption, no
  duplicate command or downstream truth, exact restoration and a successful idempotent replay.
- Validation (owner-executed, disposable WordPress 6.8.3 + MariaDB 11.4.13, fresh database per suite)
  is recorded in `docs/PHASE-2A-2R1-COMMERCIAL-PURCHASE-FUNDING-CAPACITY-AUTHORITY.md` §5. Schema 25 /
  migration `025_commercial_purchase_funding_authority` / build
  `phase2a2r1-commercial-purchase-funding-authority-20260920.1` are unchanged. State:
  `CORRECTION ROUND 6 CANDIDATE — AWAITING INDEPENDENT RE-REVIEW`; not passed, not merged, not deployed.

### Correction round 5 (independent re-review of `6de25b8c32a21d060c27e0f98a8a05a4d1a7bfaa` failed on three findings)

The independent re-review of correction round 4 **FAILED** on `C5-MAJOR-001`, `C5-MAJOR-002` and
`C5-MAJOR-003`; everything else passed. Additive descendants of `6de25b8c` close exactly those three,
with no amend, rebase, squash, reset or force push.

- `C5-MAJOR-001` — a recorded release replays only against the exact released lifecycle: the claim
  must currently be `released` (a coherent *active* aggregate can no longer satisfy the replay), carry
  a controlled release reason and a valid `released_at`, hold no interval that is still `protected`,
  and the recorded command must identify that exact claim with `result_state = released`. A release
  command also no longer writes a borrowed offer identity.
- `C5-MAJOR-002` — the settlement occurrence is bound to the authoritative acceptance timeline
  (`settlement.settled_at` must equal the accepted evidence's `ingested_at`, written by the same
  acceptance transaction) and the settlement and payment-fact currency are positively validated
  against the obligation, evidence, purchase and offer currency, in addition to the existing amount,
  ownership and occurrence correspondences. Financial settlement remains distinct from academic
  effectiveness.
- `C5-MAJOR-003` — the Term-binding command records its claim, and its replay requires
  `result_state = term_bound`, `result_id`/`term_id` equal to the revalidated Term, and
  `entitlement_id`, `purchase_id`, `offer_id`, `claim_id` and `student_id` equal to the revalidated
  commitment/claim. Contaminated command rows fail closed before any idempotent success, with no
  duplicate result and no silent repair.
- Corruption evidence: the released lifecycle mutated back into an otherwise valid active aggregate
  (asserted valid via the canonical claim validator) before replaying the same release key;
  settlement occurrence/currency and payment-fact currency probes; and same-key command-row
  contamination probes across capacity handoff, claim release and Term binding. Every probe asserts
  fail-closed behaviour, preserved corruption, no duplicate result and idempotent replay after exact
  restoration.
- Validation (owner-executed, disposable WordPress 6.8.3 + MariaDB 11.4.13, fresh database per suite)
  is recorded in `docs/PHASE-2A-2R1-COMMERCIAL-PURCHASE-FUNDING-CAPACITY-AUTHORITY.md` §5. Schema 25 /
  migration `025_commercial_purchase_funding_authority` / build
  `phase2a2r1-commercial-purchase-funding-authority-20260920.1` are unchanged. State:
  `CORRECTION ROUND 5 CANDIDATE — AWAITING INDEPENDENT RE-REVIEW`; not passed, not merged, not deployed.

### Correction round 4 (independent re-review of `2ff3d6e3a81ede8ebbf44f3144f1afc801b93531` failed on three commitment-layer findings)

The independent re-review of correction round 3 **FAILED** on `NEW-C3-001`, `NEW-C3-002` and
`NEW-C3-003`; everything else passed. Additive descendants of `2ff3d6e3` close exactly those three,
with no amend, rebase, squash, reset or force push.

- `NEW-C3-001` — `purchase.first_evidence_id` must be the exact successful evidence that minted the
  accepted purchase, so the commitment validation now proves the whole acceptance-fact chain before
  capacity or Term truth: `evidence_kind = success`, `processing_state = accepted`, the exact offer and
  the exact obligation of that offer, evidence amount/currency matching the authoritative obligation
  (and the purchase/offer currency), a valid provider occurrence instant exactly equal to the recorded
  acceptance instant, the exact obligation settlement for that evidence, and the exact payment fact
  binding purchase + evidence + obligation with matching amount, currency and occurrence.
- `NEW-C3-002` — the idempotent existing-claim path requires an R1 Q→R1 successor claim with a
  non-null `predecessor_reservation_id` equal to the offer's Phase-Q hold, an active successor state,
  coherent immutable source/pattern identity, and a complete claim aggregate validated through
  `CommercialValidator::claimValid()` over locked intervals. Released, expired, malformed, foreign or
  interval-corrupt claims can no longer be reported as idempotent handoff success, on either boundary.
- `NEW-C3-003` — a duplicate-command winner may only be reported as idempotent success after the
  authoritative current stored aggregate is re-proved with locks: for capacity handoff the complete
  commitment chain, claim ownership/predecessor/complete interval aggregate and the recorded result
  state; for Term binding the complete commitment chain, the bound claim aggregate, the exact Term and
  the exact funding-plan relationship. The rolled-back duplicate path re-runs that revalidation inside
  its own transaction instead of as loose autocommit reads. Valid unchanged state still replays
  idempotently.
- Corruption evidence: eleven acceptance-fact probes (including a purchase repointed at a synthetic
  accepted non-success evidence row for the same offer and obligation), eight claim-aggregate probes
  (null/foreign predecessor, released/expired state, invalid version, incoherent pattern identity,
  declared count, missing required interval, corrupt interval aggregate) and replay probes that
  corrupt the purchase, the evidence, the claim and the funding plan and then replay the **same
  command key** on both operations — plus a positive replay for handoff, binding and release. Every
  probe asserts zero downstream truth, no silent repair, exact restoration and normal convergence.
- Validation (owner-executed, disposable WordPress 6.8.3 + MariaDB 11.4.13, fresh database per suite)
  is recorded in `docs/PHASE-2A-2R1-COMMERCIAL-PURCHASE-FUNDING-CAPACITY-AUTHORITY.md` §5. Schema 25 /
  migration `025_commercial_purchase_funding_authority` / build
  `phase2a2r1-commercial-purchase-funding-authority-20260920.1` are unchanged. State:
  `CORRECTION ROUND 4 CANDIDATE — AWAITING INDEPENDENT RE-REVIEW`; not passed, not merged, not deployed.

### Correction round 3 (independent re-review of `3aaf3081a0d5d12501715589a2a518c68af5ad98` failed on one remaining defect)

The independent re-review of correction round 2 passed every area — account-adjustment source ↔
immutable snapshot, exact protected-interval → Phase-N identity, historical capacity lifecycle,
upstream Course/ownership offer lineage, provider-evidence convergence, Teacher-root claim-release
serialisation, the round-2 behavioural/corruption/concurrency matrix and the documented matrix — and
failed `R1-MAJOR-007` / `NEW-C2-001`: the downstream commitment layer created by acceptance
(purchase, entitlement) was not revalidated at the mutation owners, so following
`entitlement.purchase_id → purchase.offer_id → offer` proved only that the *selected offer* was valid.
Additive descendants of `3aaf3081` close that defect; no reviewed commit was amended, rebased,
squashed or force-pushed.

- One canonical `CommercialCommitmentValidator` proves `entitlement → purchase → offer → canonical
  upstream offer lineage` from stored rows and delegates the upstream proof to
  `CommercialLineageValidator` instead of duplicating it. Proved: the exact purchase; the purchase's
  **own** purchase identity for its offer (one accepted offer can only own one purchase); equal
  beneficiary across entitlement/purchase/offer; equal product, currency, accepted amount and payment
  plan; `accepted` state, valid reconciliation state/instant/version; the bounded session quantity
  equal to the accepted offer's commitment; a mutation-appropriate entitlement state; and the
  acceptance evidence that minted the purchase still being an accepted evidence row for this exact
  offer and one of its obligations.
- A protected-capacity claim consumed by handoff or Term binding must belong to the same entitlement,
  purchase, Student, Teacher, Course, commitment size and pre-payment hold — including the idempotent
  existing-claim fast path, so a valid claim from another commitment can never satisfy the chain.
- Both owning boundaries enforce it before any downstream truth:
  `CommercialCapacityService::handoffFromEntitlement()` before the successor claim, before protected
  intervals, before the Phase-Q hold is released and before any capacity mutation; and
  `CommercialTermFundingService::bindEntitlementToTerm()` before Phase-L Term creation and before the
  funding plan. Read endpoints remain unchanged and are explicitly not relied upon for integrity, and
  no corrupted row is ever repaired.
- Corruption evidence: two otherwise fully valid accepted commitments. A purchase repointed at another
  otherwise-valid offer (asserted valid while the corruption is in place, so the rejection is about
  ownership), a re-pointed beneficiary/product/currency/amount/plan, a re-pointed or resized
  entitlement, and a claim belonging to another commitment — each rejected at both capacity handoff
  and Term binding with zero downstream truth, never silently repaired, and converging after
  restoration. `commercial_entitlements`/`commercial_purchases` uniqueness means the repointed cases
  are probed against an unpurchased alternate offer and with a temporarily displaced sibling
  entitlement, both restored by the probe.
- Validation (owner-executed, disposable WordPress 6.8.3 + MariaDB 11.4.13, fresh database per suite)
  is recorded in `docs/PHASE-2A-2R1-COMMERCIAL-PURCHASE-FUNDING-CAPACITY-AUTHORITY.md` §5. Schema 25 /
  migration `025_commercial_purchase_funding_authority` / build
  `phase2a2r1-commercial-purchase-funding-authority-20260920.1` are unchanged. State:
  `CORRECTION ROUND 3 CANDIDATE — AWAITING INDEPENDENT RE-REVIEW`; not passed, not merged, not deployed.

### Correction round 2 (independent re-review of `186fc5012fe294ea3d91b85aeefdd738448471a0` failed)

The independent re-review passed the exact protected-interval → Phase-N identity, the historical
commercial-capacity lifecycle and the Teacher-root serialisation for `releaseClaim()`, and failed four
remaining integrity areas plus behavioural coverage and one documentation item. Additive descendants of
`186fc5012fe294ea3d91b85aeefdd738448471a0` (tree `40fc2643eacc6811882b552785f55e7db5c04e30`) correct
them; no reviewed commit was amended, rebased, squashed or force-pushed.

- **R1-BLOCK-001** — purchase acceptance now proves the locked authoritative account-adjustment source
  still corresponds exactly to the immutable `commercial_offer_adjustments` snapshot: source id/type,
  kind, percentage basis points or fixed minor units, currency, application order, the discount
  recomputed against the **post-promotion running amount**, the offer's recorded contribution and the
  re-derived canonical snapshot digest (one implementation, shared with issuance). A mutated source or a
  rewritten snapshot fails closed before redemption, adjustment consumption, evidence acceptance,
  settlement, purchase, entitlement, funding, capacity or Term truth, and no historical snapshot is ever
  repaired or overwritten.
- **R1-BLOCK-004 / R1-MAJOR-007** — one canonical, transaction-aware `CommercialLineageValidator` proves
  the stored chain `continuation case → authorised first regular slot → pre-payment hold → product →
  price → offer → Student → Teacher → Course (canonical Enrolment)` and is invoked by the offer read
  seam, payment acceptance, capacity handoff and Term binding immediately before each mutation. Capacity
  handoff now proves the aggregate row set *before* the Teacher scheduling root, keeping the repository
  lock order (`case → reservation → Teacher root`) instead of inverting it. No foreign key or CHECK
  constraint was added; the established application/verifier integrity architecture is preserved.
- **R1-MAJOR-005 / R1-C1-NEW-001** — `recordUnattributed()` recovers concurrently through the single
  canonical duplicate-evidence boundary: the winner is reloaded and revalidated, the incoming immutable
  facts are canonicalised with the same `evidence_fact_digest` algorithm, identical facts converge
  idempotently, and materially different facts preserve the winner unchanged and durably route
  `conflicting_payment_evidence` instead of silently converging onto the first row.
- **R1-MAJOR-008 / R1-C1-NEW-002** — behavioural and concurrency evidence added: an at-rest
  account-adjustment mutation and a rewritten snapshot; one stored Course/ownership corruption rejected
  independently by payment acceptance, capacity handoff and Term binding (each exercised directly with
  zero downstream mutation asserted); a deterministic initially-unattributed conflicting-facts race plus
  its identical-facts convergence counterpart; and a failure-injection boundary at the unattributed
  evidence write. The Phase R1 testing matrix, continuity record and this changelog now list the modes
  actually executed.
- Validation (owner-executed, disposable WordPress 6.8.3 + MariaDB 11.4.13, fresh database per suite)
  is recorded in `docs/PHASE-2A-2R1-COMMERCIAL-PURCHASE-FUNDING-CAPACITY-AUTHORITY.md` §5. State:
  `CORRECTION ROUND 2 CANDIDATE — AWAITING INDEPENDENT RE-REVIEW`; not passed, not merged, not deployed.

### Correction round 1 (independent review of `10fe40618af3de76f2af37a093e611af10cc6ccc` failed)

- All eight review findings corrected on descendants of the reviewed commit (never amended, rebased, squashed or force-pushed): accepted-purchase benefit consumption (promotion redemption + account adjustment, both limits enforced under the locked promotion row); exact protected-interval ↔ Phase-N occupancy identity (Teacher, Term, canonical session, exact UTC bounds, buffered occupied end, timezone and wall clock, with only the exact interval excluded); historical `satisfied`/`released` capacity becoming non-blocking while genuinely impossible aggregates still fail closed; Course identity continuity across case, slot, hold, product, offer, pattern, claim and Term Enrolment; a canonical immutable provider-evidence fact digest with exact convergence and durable routing of material differences (sequential and concurrent); Teacher-root serialisation for `releaseClaim()`; durable structural-integrity enforcement (class-B policy allowlisting with fail-closed reads, funding/offer ownership-chain validation) in place of foreign keys, which this repository's migration policy deliberately avoids; and behavioural proof for every corrected invariant.
- Correction-round evidence (disposable WordPress 6.8.3 + MariaDB 11.4.13, fresh database per suite): all 32 contract tests; R1 migration runtime; R1 authority runtime; 9-boundary failure injection; corruption runtime including the cross-authority reference matrix; seven concurrency modes (`duplicate_evidence`, `handoff_vs_schedule`, `settlement_vs_lesson_seven`, `unrelated_commitments`, `promotion_global_limit`, `conflicting_evidence_replay`, `release_vs_satisfaction`); Phase-M runtime regression; and the previously unrun Phase-Q concurrency matrix (15 of 16 modes pass — the sole failure is the documented pre-existing `expiry_vs_new_claim` harness window flake whose patch is deliberately not used).
- Pre-existing defects reproduced against both the authoritative base and the corrected candidate: fresh-install capability activation fails identically on both (`Canonical attendance intake capability installation failed: Teacher claim grant`), and the Phase-Q runtime suite's `assignment_changed` failure is state/order dependent and reproduces on the untouched base. Neither is an R1 regression and neither was "fixed" by changing closed Phase-P/Q behaviour.

- Branch `phase-2a2r1-commercial-purchase-funding-authority`, from authoritative base `1b9d7aaef0ca21fdb861ccc9da1d15634cb5d843`. **Not merged, not deployed, no production cutover.** Schema 25 / migration `025_commercial_purchase_funding_authority` / build `phase2a2r1-commercial-purchase-funding-authority-20260920.1`. The immutable candidate commit and tree SHAs are recorded in the task closeout, because a commit cannot embed its own hash.
- The phase establishes canonical commercial authority only: sellable product identity and region/currency prices, bounded promotions and distinct account adjustments, an immutable purchase offer with a deterministic whole-Term pricing snapshot, full and two-instalment plans decomposed into ordered obligations, provider-neutral payment evidence, settlement, derived academic effectiveness, purchase acceptance, a bounded 12-session entitlement, Term binding through the existing Phase-L authority, the canonical Regular recurring pattern, current-Term protected capacity with mandatory Phase-Q → R1 → Phase-N succession, commercial exceptions and the versioned runtime commercial policy registry. Stripe remains future evidence infrastructure; no provider SDK, webhook, credential or subscription object exists. Theme, portals, notifications, gift cards, refunds, payouts and deployment remain outside.
- Additive integration only: Phase L gains single-sourced structural constants, a caller-owned transaction option and a protected-capacity close guard; Phase M reads the recorded Term allocation/change allowance and caps standard issuance at the funded allowance with `standard_funding_exhausted`; Phase N consults and satisfies protected intervals; Phase Q refuses an interval already protected by a paid commitment. No Phase J–Q accepted product decision, table, state vocabulary or rule constant was changed.
- Closed-phase contract markers corrected to the repository's established minimum-schema plus canonical-build-identity pattern (`tests/phase-2a2q-contract.php`), because a later phase legitimately advances the platform build and the required-migration list is append-only. Phase-Q reservation states and recurrence rules are unchanged.
- Owner-executed evidence (disposable WordPress 6.8.3 + MariaDB 11.4.13): R1 contract, migration runtime, authority runtime, 9-boundary failure injection, corruption runtime and four concurrency modes all pass; the Phase-M runtime regression passes. The Phase-Q *runtime* suite cannot run green on a freshly built disposable runtime and **fails identically on the untouched base `1b9d7aae`** (its own assignment-replacement scenario returns `assignment_changed`); this is a pre-existing environment/fixture-order dependency, not an R1 regression, and is recorded as a follow-up.

## Phase 2A.2-Q — Post-Intro Continuation & Slot Reservation Authority — merged and closed — 2026-09-20

- Final independently approved candidate `815a301679fdebc5d058646749e5a206ab492629`, tree `13757b52dd84810d44e6dba19550ec7e668ad169`, was **fast-forward merged into `main`** from authoritative pre-merge main `b7378665af1bc3cce935cfae27d346326a1ddc10`; the implementation-merge SHA equals the approved candidate because no merge commit was required. Schema 24 / migration `024_post_intro_continuation_slot_reservation_authority` / build `phase2a2q-post-intro-continuation-slot-reservation-20260920.1` are authoritative. **No deployment occurred**; production, Theme/NIU, payment/Stripe, provider and Portal surfaces were untouched, and no production continuation workflow was activated.
- Phase Q is authoritative for exactly one interval of the Student journey: the post-introductory continuation decision and a bounded pre-payment hold on the single explicitly authorised first regular class slot. The introduction remains free and separate; the hold is never a Lesson, schedule, Term, Assent, Assignment, payment or entitlement; the first-regular-slot record is the only source that may justify capacity (no `+7 days` recurrence inference exists); a delayed slot record converges an existing continuing decision without duplicating consent and resolves the missing-slot requirement append-preservingly; a Phase-N Lesson schedule and a Phase-Q hold remain distinct authorities with bidirectional Teacher-capacity arbitration; accepted-arrangement lineage ambiguity fails closed; and the documented lock order matches the implementation.
- Review history retained: original candidate `33368f6a` failed on Q-1/Q-2/Q-3; Correction-Round-1 candidate `c43a89b3` failed on Q-R1-1/Q-R1-2/Q-R1-3; Correction Round 2 passed final independent re-review. Phase P remains complete and closed.
- **Known follow-up (test hygiene only):** the `expiry_vs_new_claim` concurrency mode's two-second expiry window can invert its expected winner order under load while production behaviour stays correct; widening the window made two consecutive full-matrix runs pass. This should be corrected in a future bounded test-harness change, not inside this merge.

## Phase 2A.2-Q — Post-Intro Continuation & Slot Reservation Authority — Correction Round 2 candidate, unmerged — 2026-09-20

- **Correction Round 2 (this candidate)** corrects the independent-review findings on Correction-Round-1 candidate `c43a89b3aeecd74d4335b2a1fabd70c4e0ad263e` (tree `0cdf92de266f70d9e22e887e6a384934082ec51a`), which **failed review** on Q-R1-1/Q-R1-2/Q-R1-3. All changes descend from `c43a89b3`. Schema remains 24; migration and build unchanged. **Not merged, not deployed.**
- **Q-R1-1 (HIGH)** — recording the authoritative first regular slot now converges an **already-recorded** `continue_with_teacher` decision into the hold in the same transaction: durable case hydration and revalidation, original decision preserved (the reservation references it), Teacher scheduling root acquired, Phase-N schedules and other holds rechecked, exactly one reservation created with the frozen expiry, the `first_regular_slot_authority_required` requirement resolved append-preservingly (`state='resolved'` plus resolution actor/instant), and durable command evidence that returns the same authority/case/reservation on exact replay. No second Student decision is created and no consent is duplicated. Any failure — including a capacity conflict — rolls the whole convergence back, so no false `continue + slot + no hold` success can be observed; cases that are no longer eligible keep correct history and are never reactivated.
- **Q-R1-2 (MEDIUM)** — the documented lock order now states the **implemented** order (introductory Lesson → Phase-F principal/guardian authority row → continuation case → per-Teacher scheduling root → Phase-N versions) with a deadlock audit showing Phase-F mutation paths never acquire Lesson, Phase-Q or Teacher-root locks, so no reverse edge or cycle exists.
- **Q-R1-3 (LOW)** — the dead concurrency-setup closure that still referenced the removed slot derivation was deleted; every fixture now uses an explicit slot authority, and the Phase-Q contract continues to reject any reintroduction of `+7 days` derived authority.
- Validation (owner-executed): Phase-Q contract, authority runtime (delayed convergence, original-decision preservation, intervention resolution, capacity-conflict rollback, expiry boundaries, DST rejection), 34-case corruption runtime, **10-boundary** failure injection, 10-case migration runtime and the **16-mode** concurrency runner including three delayed-slot races. Phase-P/O/M/M0/L and Migrator regressions re-run.

## Phase 2A.2-Q — Post-Intro Continuation & Slot Reservation Authority — Correction Round 1 candidate, unmerged — 2026-09-20

- **Correction Round 1 (this candidate)** corrects the independent-review findings on candidate `33368f6a37c128b476419542e507462952909702` (tree `a20c48d8c8f5aaf61688d029c5b5084daa113327`), which **failed review** on Q-1, Q-2 and Q-3. All corrections are descendants of `33368f6a`; no commit was amended, rebased or rewritten. Schema remains 24 / `024_post_intro_continuation_slot_reservation_authority`; build unchanged. **Not merged, not deployed.**
- **Q-1 (HIGH)** — the invented `introduction time + 7 days` future-slot rule is removed entirely. Audit confirmed no existing authority carries a recurring future class (`proposal_versions` / `accepted_service_arrangements` hold only `frequency_per_week`, `schedule_constraints`, a commencement window and a timezone), so Phase Q now owns the narrowest truthful fact: an immutable `dzn_canonical_continuation_slot_authorities` record created by the administrator-only `recordFirstRegularSlot()` command, binding the exact introductory occurrence, Student, Teacher, Course, timezone, local wall clock, resolved UTC interval, authority basis, evidence provenance and rule version. A hold may exist only when bound to that record; continuing before a slot is authorised records the decision, holds nothing and raises `first_regular_slot_authority_required`. Nonexistent/ambiguous wall clocks, a non-future slot and a duplicate slot all fail closed. The six-day expiry policy is unchanged and its first operand is now genuine authority.
- **Q-3 (MEDIUM)** — accepted-arrangement lineage is no longer chosen with `ORDER BY id LIMIT 1`: exactly one candidate is authoritative, several raise `continuation_arrangement_ambiguous`, and a bound arrangement must remain an intact accepted record.
- **Q-2 (HIGH)** — the four omitted cross-authority races are now process-proven with real connections and gated locks: Phase-Q hold vs Phase-N Lesson scheduling in **both** commit orders, hold-expiry vs new capacity claim across the frozen boundary, guardian decision vs guardian revocation, and Student principal change vs decision (both orders). The runner now starts every race from a freshly prepared fixture so the matrix is order-independent, and each race proves the contender genuinely waits, revalidates after the wait, and yields exactly one winner with no double occupancy, no orphan hold and no partial schedule. Lock order documented: Phase-F authority rows → Phase-Q lesson/slot → Phase-Q case → per-Teacher root → Phase-N versions.
- Validation (owner-executed): Phase-Q contract, authority runtime (including four explicit expiry-boundary cases, DST gap/ambiguity rejection, missing-slot-authority handling and a proof that the hold is never a `+7 days` derivation), **34-case** corruption runtime, **six-boundary** failure injection, migration runtime (10 malformed-storage cases), and the **13-mode** concurrency runner. Phase-P/O/N/M/M0/L regressions re-run.

## Phase 2A.2-Q — Post-Intro Continuation & Slot Reservation Authority — implementation candidate, unmerged — 2026-09-20

- Branch `phase-2a2q-post-intro-continuation-slot-reservation` from authoritative base `b7378665af1bc3cce935cfae27d346326a1ddc10` (Phase P merged and closed). Schema 24 / migration `024_post_intro_continuation_slot_reservation_authority` / build `phase2a2q-post-intro-continuation-slot-reservation-20260920.1`. **Not merged and not deployed.**
- Phase Q owns exactly one narrow interval of the Student journey — the post-introductory continuation decision and, for a continuing decision only, a bounded temporary hold on the single expected first regular class slot — and stops before payment. It creates no payment intent, Stripe object, invoice, receipt, `payment_state`, Term, standard/replacement Lesson, academy obligation, delivery/attendance outcome, notification or provider/calendar/Google authority.
- Source-graph audit: an introductory Lesson in this repository is a `legacy_phase1` Lesson with `lesson_type='introductory'` and no Enrolment/Term, scheduled through the legacy current `lesson_schedule_versions` occurrence. Phase Q binds that source plus the Phase-F Student/guardian authority, the Phase-J Teacher principal, optional canonical Enrolment/Assignment/accepted-arrangement lineage and Phase-N Teacher capacity. It never forces the introduction into a paid Term and never fabricates a delivery fact: the post-intro gate proves only that the scheduled introductory occurrence window has ended, which permits a decision to be recorded.
- Locked owner decisions Q-D1…Q-D12 are implemented: the introduction is free and separate; four controlled Student dispositions; continuing creates a bounded pre-payment hold (not a Lesson/schedule/Term/Assent/Assignment/payment); only the first expected post-intro occurrence is held; expiry is frozen as `min(expected slot start, introductory occurrence end + 6 days)`; expiry preserves all history; a different-Teacher request, a contact request and a Teacher match-unsuitable report each record a controlled administrator intervention (the Teacher report also releases the hold and suppresses ordinary continuation); not-continuing closes the immediate path with optional controlled feedback only; payment is deferred; administration is exception handling.
- Decision authority is explicit and self-enforcing: Student commands require Phase-F adult principal or guardian-representative authority matched to the Student's capacity classification; the Teacher command requires its own capability plus the authoritative Teacher principal for the bound introduction and grants no other authority; integrity flagging is administrator-only. Every command is digest-bound, exact-replay idempotent, changed-context fail-closed (no nullable expected digest), and validates the complete aggregate before and after mutation.
- Real capacity, no fake flag: the hold participates in canonical Teacher-capacity arbitration through one narrow seam consulted by Phase-N scheduling, and Phase Q consults Phase-N schedules before inserting a hold; both take the same per-Teacher scheduling root. Phase-N Lesson schedules and Phase-Q pre-payment holds remain distinct authorities. Expiry is deterministic and lazy (frozen `expires_at`; capacity-effective only while active and unexpired).
- Validation (owner-executed, disposable WordPress/MariaDB harness): Phase-Q contract, authority runtime, 24-case corruption runtime with repair/recovery, five-boundary failure injection with retry convergence, migration runtime (fresh Schema 24, 23→24 rehearsal, repeat, partial capability repair, no backfill, no payment/Term creation, provider-neutral storage, 8 malformed-storage cases, retained-024 fail-closed) and a six-mode deterministic gated concurrency runner. Phase-P, Phase-O, Phase-N, Phase-M, Phase-M0 and Phase-L regressions re-run unchanged.
- Deferred and explicitly not owned: payment/Stripe, Term and Lesson materialisation, notification delivery, Student/Teacher Portal integration, Google/Meet/OAuth/webhook/credential work, calendar, Amelia, remediation, rescheduling and deployment.

## Phase 2A.2-P — Canonical Attendance Intake & Review Authority — merged and closed — 2026-09-20

- Final independently approved candidate `30fe1f11c425ad7066408f20ca57e2ca084841d6`, tree `34c111c01932f14552ada366f911f9b404111692`, was **fast-forward merged into `main`** from authoritative pre-merge main `7b9aea68fddd651cd614f279f77e78b104885c4d`. The implementation-merge SHA equals the approved candidate because no merge commit was required. Schema 23 / migration `023_canonical_attendance_intake_authority` / build `phase2a2p-canonical-attendance-intake-20260919.1` are now authoritative on `main`. **No deployment occurred**; production, Theme/NIU, Google/provider credentials, Stripe/payment, notification and portal surfaces were untouched.
- Phase P passed final independent re-review after three correction rounds (original `6a6a4ce` failed; `eda3df2` is historical incomplete; `629310e` failed; `0d6f0f3` failed; `30fe1f1` passed). No production attendance cutover was activated and no provider integration was implemented.
- Phase P is provider-neutral attendance intake/evidence/assessment/review authority only: canonical delivery/attendance truth remains with Phase O, and Lesson completion remains with Lesson authority. No Google API/OAuth/webhook/credential, calendar, Amelia, payment, notification, Teacher Portal or Student Portal integration was introduced.

## Phase 2A.2-P — Canonical Attendance Intake & Review Authority — Correction Round 3 candidate, unmerged — 2026-09-20

- **Correction Round 3 (this candidate)** corrects the three final independent re-review findings on Round-2 candidate `0d6f0f3ab18fe7989367b78610a73beb0e3d7bd9` (tree `a554f8ac3cc026770ea55a4edc2ebb2dce7697c1`). All changes are descendants of `0d6f0f3`; no commit was amended, rebased or rewritten. Schema remains 23 / `023_canonical_attendance_intake_authority`; build remains `phase2a2p-canonical-attendance-intake-20260919.1`. **Not merged, not deployed.**
- **R3-1 (HIGH)** — `recordCutoverPolicy()` now resolves the canonical command digest against the durable command authority before applying wall-clock prospective validation. An exact completed cutover command stays idempotently replayable after its cutover instant passes (same policy id, no second row, no canonical mutation), while a genuinely new command at a passed/backdated instant still fails `cutover_instant_not_prospective` and a changed payload under the same command key still fails `Idempotency conflict`. Regression crosses the wall-clock boundary with a short 2-second future instant.
- **R3-2 (MEDIUM)** — `replayCommand()` now takes `string $payload` (non-nullable) and compares the expected digest unconditionally. No nullable or sentinel bypass remains, and every caller supplies the canonical expected digest.
- **R3-3 (LOW)** — the `command_key_exact` concurrency verify now proves exactly one non-idempotent creation, exactly one `idempotent=true` response, the same attendance case, the same durable evidence identifier and exactly one evidence row, while the six changed-context modes keep failing `Idempotency conflict`.
- Validation (owner-executed): PHP lint, shell syntax, 30/30 `tests/*contract*.php`, overlap runtime, authority runtime (with post-cutover exact replay), 30-case corruption runtime, failure runtime, migration runtime (9 malformed-storage cases), 13-mode gated concurrency runner, and Phase-O/N/M/M0/L plus Migrator `RuntimeException` regressions. The Phase-M0 protected-read runtime's six integrity cases pass; its legacy-bootstrap step remains a pre-existing empty-`uid` harness defect on a reused disposable DB, unrelated to Phase-P.

## Phase 2A.2-P — Canonical Attendance Intake & Review Authority — Correction Round 2 candidate, unmerged — 2026-09-20

- **Correction Round 2 (this candidate)** corrects the three authority/integrity defects found in the final independent re-review of Correction Round 1 (`629310e835a4cad3bc25d5406280944c2b6e6145`, tree `49b93a71afefbe0471fe57dafc8961cef4de6f31`). All Round-2 corrections are descendants of `629310e`; no commit was amended, rebased or rewritten. Schema remains 23 / `023_canonical_attendance_intake_authority`; build remains `phase2a2p-canonical-attendance-intake-20260919.1`. **Not merged, not deployed.**
- **R1 / P-8 (HIGH)** — the concurrent ingest duplicate-command exception recovery path now reconstructs the complete incoming command facts and computes the same canonical payload/context digest as the normal ingest path, then compares it against `winner.command_payload_digest` through `replayCommand()`. No helper passes a null expected digest; the claim recovery path is hardened identically. A same-Lesson/schedule command key with a changed provider payload, event, account, join/leave interval, observation instant or provenance now fails `Idempotency conflict` and can never be acknowledged as an exact replay. Source/contract coverage now rejects any null expected digest in ingest/claim recovery rather than relying on a surface string match.
- **R2 / P-7 (HIGH)** — cutover applicability can never fall back to the database insertion id. Migration 023 now declares `UNIQUE KEY cutover_instant(cutover_utc)` and the verifier requires it; `recordCutoverPolicy()` rejects a second distinct policy at the same instant (`duplicate_cutover_instant`) while exact replay stays idempotent; and `applicablePolicy()` fails closed (`cutover_policy_ambiguous`) if corrupted/legacy data contains several policies at the same newest cutover instant.
- **R3 (HIGH)** — the protected current-attendance read resolves the authoritative current schedule version and refuses a case bound to a superseded version with `schedule_version_conflict`, matching reassessment, adjudication and settlement. Historical evidence is retained but never presented as current truth, and the read remains read-only (no mutation, no write transaction).
- Validation (owner-executed): PHP lint, shell syntax, 30/30 `tests/*contract*.php`, overlap runtime, authority runtime (with duplicate-instant rejection, cutover-ambiguity fail-closed and same-occurrence duplicate-command context matrix), 30-case corruption runtime, failure runtime, migration runtime (9 malformed-storage cases including non-unique cutover instant), and a **13-mode** gated concurrency runner including seven same-Lesson/schedule command-key races. Phase-O/N/M/M0/L and Migrator `RuntimeException` regressions re-run. The Phase-M0 protected-read runtime's six integrity cases pass; its legacy-bootstrap compatibility step fails only on a reused disposable DB because the pre-existing `insertLegacyBootstrap` helper persists an empty `uid`, which is unrelated to this correction.

## Phase 2A.2-P — Canonical Attendance Intake & Review Authority — Correction Round 1 complete candidate, unmerged — 2026-09-19

- **Correction Round 1 (this candidate)** completes every outstanding independent-review finding on branch `phase-2a2p-canonical-attendance-intake`, as a descendant of partial correction `eda3df2a24f5de67dcbba1dbe6e4ae863b325e13` (tree `53af4c29cfb63dd21aad9fe99b2ef0e1d9d768aa`); the original reviewed candidate `6a6a4ce8249f79df0371753479587e5d9c1fcd27` (tree `f17ef71c989ae8449108bb4ccd279b2daec4026a`) failed independent review on P-1…P-11. No prior commit was amended, rebased, squashed or rewritten. Schema remains 23 / `023_canonical_attendance_intake_authority`; build remains `phase2a2p-canonical-attendance-intake-20260919.1`. **Not merged, not deployed, not yet independently re-reviewed.**
- **P-1 (HIGH)** — a durable, provider-neutral participant identity authority now decides which canonical Teacher or Student a provider account belongs to. New `dzn_canonical_attendance_participant_mappings` registry (provider code + keyed account digest + role + canonical participant + `verified`/`unverified`/`revoked` state + provenance/evidence digests + verification/revocation instants + mapping version) behind a new administrator capability `dzn_manage_canonical_attendance_identity`. Intake no longer accepts any caller-supplied identity or verification assertion; a missing, unverified, revoked, ambiguous, wrong-role or wrong-participant mapping is retained as evidence, never qualifies, and raises an explicit anomaly. The complete aggregate including the just-inserted row is re-validated before any assessment or canonical settlement.
- **P-2 (HIGH)** — `settleDelivered()` accepts a durable case ID (never a caller object), refuses any case without a durable settlement intent (`attendance_settlement_not_pending`), and re-validates the case aggregate, cutover-policy binding and exact schedule version before any Phase-O or Lesson-authority mutation. `adjudicate()` and `reassess()` hydrate the durable case, validate the aggregate and refuse a superseded schedule version (`schedule_version_conflict`).
- **P-3 (HIGH)** — provider replay proves full immutable context equality (provider, account digest, event key, payload digest, case, Lesson, schedule version, role, join/leave, observation, provenance). A cross-context replay is a classified conflict, never another case's evidence row.
- **P-4 (HIGH)** — a refused conflicting provider event leaves a durable receipt in the new append-only `dzn_canonical_attendance_conflicts` table plus a `duplicate_event_conflict` anomaly, surfaces through protected review, never overwrites the original evidence row and never touches canonical truth — including after settlement.
- **P-5 (HIGH)** — an exact replay of the original ingest command resumes forward settlement from durable `settlement_pending`; boundaries A–E are covered by executable tests and recovery never duplicates Phase-O truth, Lesson completion or the final Phase-P result.
- **P-6 (HIGH)** — `adjudicate()` requires caller-supplied `expected_case_version`, fails `stale_case_version` before any canonical consequence, and binds the expected version into the command identity. The concurrency matrix now proves **exactly one** winner for competing adjudications.
- **P-7 (HIGH)** — cutover activation must be prospective (`cutover_instant_not_prospective`); applicability is deterministic from the occurrence instant (never "highest database ID"); each admitted case stores the exact immutable policy row and its frozen rule version/threshold/graces; a later policy cannot reinterpret an earlier case; an occurrence before every activated cutover is refused. No production cutover was performed.
- **P-8/P-9** — every duplicate-key recovery path recomputes and compares the expected payload and context (no null-payload replay), and an exact cutover-policy replay is proven to close its transaction (`@@in_transaction`), retain no stale lock and survive a later unrelated rollback.
- **P-10 (MEDIUM)** — the validator and protected read validate `canonical_attendance_cases.rule_version`, the cutover-policy existence/constants/applicability, the identity registry justification of every resolved row, and that the stored assessment decision agrees with the stored evidence; corruption and repair are covered for all of them.
- **Capability repair** — Phase-P grants are installed per capability rather than gated on one grant, a partially installed state is repaired deterministically, and reserved review/adjudication/identity authority is actively withheld from the `dzn_teacher` role.
- **Lock-order correction** — identity-registry and cutover-policy rows are shared authority and are now read without row locks; locking the policy row inside `lockOccurrence` serialised unrelated occurrences and could surface a lock-wait timeout as `occurrence_before_cutover`. Per-occurrence write serialisation is unchanged.
- Validation (owner-executed, disposable WordPress/MariaDB harness): PHP lint (267 files), shell syntax (10 harnesses), **30/30** `tests/*contract*.php`, overlap runtime, authority runtime (including an **8-case identity matrix**, cross-context replay, 5 settlement-convergence boundaries, cutover-policy authority, duplicate-command recovery, case/schedule validation, capability repair, no-policy fail-closed and protected read), a **30-case** corruption runtime, failure injection (3 boundaries + replay convergence + durable payload conflict), migration runtime (fresh Schema 23, 22→23 rehearsal, repeat, partial capability repair, no backfill, no production cutover, provider-neutral storage, 8 malformed-storage cases, retained-023 fail-closed) and a **6-mode** deterministic gated concurrency runner. Adjacent regressions re-run: Phase-O authority/corruption/failure/migration, Phase-N authority/corruption/failure/migration, Phase-M authority/corruption/failure, Phase-M0 authority and protected read, Phase-L authority, and the Migrator `RuntimeException` regression.

- **Original implementation (superseded by the correction above).** Branch `phase-2a2p-canonical-attendance-intake` from authoritative base `7b9aea68fddd651cd614f279f77e78b104885c4d`, Schema 23 / migration `023_canonical_attendance_intake_authority` / build `phase2a2p-canonical-attendance-intake-20260919.1`. Phase P owns provider-neutral attendance evidence intake, human absence/attendance claims, deterministic overlap assessment, anomaly/review authority and administrative adjudication. It does **not** own canonical delivery truth: Phase O remains authoritative and Lesson authority owns completion. Nothing in Phase P writes Phase-O, lifecycle, obligation or replacement storage directly.
- Owner locks implemented: ordinary delivery settles automatically only from trusted provider evidence with ≥1200 seconds of qualifying simultaneous overlap inside `[scheduled start, scheduled end + 15 minutes)`; pre-class grace is zero; open intervals never qualify; a failed assessment identifies no responsibility and produces only anomaly/review state; Student "Notify Absence" is an advance claim only; human claims are evidence and never settle; conflicting evidence requires administrative adjudication; `review_required` is published to Phase O only by explicit adjudication; unresolved cases never block Term closure and survive it; late evidence is retained and flagged but never auto-settles; the slice is prospective only with a durable cutover policy and no historical import; only normalized facts and keyed digests are stored. Automatic settlement converges in stages (durable intent → Phase-O `delivered` → Lesson completion → verification → result) because the existing services own their transactions; no nested transaction is simulated.
- **Deferred and explicitly not owned:** Google/Meet API, OAuth, webhooks or credentials; calendar, WhatsApp, notifications, payment/Stripe, Finance, payroll, renewal; remedial Lesson materialisation or automatic rescheduling; Theme/portal changes; public attendance routes; production cutover and historical attendance import. Student self-service and the Student Portal contract remain deferred.

## Post-Phase-O maintenance — malformed Migrator RuntimeException construction — merged — 2026-09-19

- Bounded maintenance merged into `main` as `08d0d26a94b6f33f20000ca64726f732bf9fa46d` (tree `002ade4586bbb431f44f09891210a36df9da0334`), closing the residual defect reported in the Phase 2A.2-N entry below: the malformed `throw new\RuntimeException(...)` construction in `Migrator` is corrected to `throw new \RuntimeException(...)` at all eleven remaining failure-path sites (`install_enrolment_conversion_authority`, `install_teacher_assignment_foundation`, `verify_enrolment_conversion_schema`, `verify_teacher_assignment_schema`), which previously produced a fatal PHP `Error` instead of the intended controlled rejection.
- Regression coverage: `tests/schema-contract.php` now rejects any malformed construction in the migrator and pins the corrected failure-path counts (1/1/4/5); `tests/migrator-runtimeexception-runtime.php` proves representative install and verify failure paths throw `\RuntimeException`. Schema remains 22, latest migration remains `022_canonical_lesson_delivery_attendance_authority`, build identity is unchanged, no migration 023 exists, and no Platform authority, business policy or Phase-M/N/O semantics changed.

## Phase 2A.2-O — Canonical Lesson Delivery & Attendance Outcome Authority — merged and closed — 2026-09-19

- Final independently approved candidate `f5b43741f4b404fd102330aeb75d58ed8b3e2976`, tree `7182101fcaf9855f8834cd08bc2e7a4a0311791c`, from authoritative pre-merge main `b0687fec98748144f96e3fc56f7e4fb53e01f673`, was **fast-forward merged into `main`**; the implementation-merge SHA equals the approved candidate because no merge commit was required. Schema 22 / migration `022_canonical_lesson_delivery_attendance_authority` / build `phase2a2o-canonical-lesson-delivery-attendance-authority-20260918.1` are authoritative. **No deployment occurred**; production, NIU, Theme, Amelia, Google/calendar, Meta and Stripe were untouched.
- The slice was independently reviewed three times: the original candidate failed on O-1/O-2/O-3, the round-1 correction failed on the O-D8 completion-event lineage plus corruption-coverage and replay findings, and the round-2 correction failed because obligation authority still established canonical completion locally. Correction round 3 replaced that local approximation with direct consumption of `CanonicalLessonAuthorityValidator`, added referenced-lifecycle-event corruption coverage, and received the final independent re-review PASS.
- Locked product decisions O-D1…O-D9 are authoritative: completed means delivered; ordinary delivery needs no routine outcome row; student no-show may coexist with completion and creates no automatic remedy; Teacher/academy non-delivery creates an academy obligation distinct from the Phase-M replacement; advance cancellation stays distinct from post-occurrence non-delivery; corrections remain append-only; Phase-O mutation requires the Phase-O administrator capability; a historical completion may be reconciled to non-delivery through an explicit append-only command while the completion event stays immutable; and controlled advance academy cancellation may establish academy debt while generic Student cancellation does not.
- Phase-M replacement authority is unchanged: two-per-Term allowance, origin uniqueness, `replacement` Lesson materialisation and historical `attested_non_delivery` semantics. Academy obligation remains a distinct immutable debt authority that is never a `replacement` Lesson, never consumes the allowance, is not automatically materialised, scheduled or reopened, and is never erased by Term closure.
- Provider neutrality is preserved: no Google Meet, calendar, Amelia, WhatsApp, payment, payroll, notification or webhook authority, no provider credentials and no remedial-Lesson materialisation.
- Evidence provenance: final independent review independently reproduced Git identity/ancestry, `git diff --check`, 29/29 `tests/*contract*.php`, PHP lint, shell syntax, Schema/migration identity and the Round-3 commit-scope inspection. The Phase-O authority runtime, 44-case corruption runtime, five-boundary failure injection, migration runtime, ten-mode concurrency matrix and the Phase N/M/L/M0 runtime regressions are implementation-owner evidence on a disposable MariaDB/WordPress harness and were not re-executed by the independent reviewer.
- Next Platform action: **none authorised**. The next slice requires an explicit product/architecture decision.

### Phase 2A.2-O correction round 3 — independent re-review findings on round 2 — 2026-09-19

- Independent re-review of round-2 candidate `5b18f20d576e5446cee6cecc0b21d010fb62ffa1` (tree `af035208b7096dad12e60b768e928372fb094778`) returned **FAIL — CORRECTION ROUND 3 REQUIRED**. The round-3 correction is a descendant of that candidate; Schema 22, migration `022_canonical_lesson_delivery_attendance_authority`, the build identity, O-D1…O-D9 and the round-1/round-2 corrections are unchanged.
- **HIGH — canonical lifecycle authority, not a local approximation:** `CanonicalAcademyObligationValidator` no longer establishes canonical completion by scanning raw lifecycle rows (event id, lesson id, `to_state`, maximum id). It now consumes `CanonicalLessonAuthorityValidator::valid()` before accepting obligation lineage, and the delivery validator does the same whenever a reconciliation pointer exists; the delivery service's reconciliation command also consumes it instead of scanning for a maximum completed event id. Completion is bound to the canonical chain's terminal event, which is a consequence of the validated legal progression. `CanonicalLessonAuthorityValidator` references no Phase-O authority, so the reuse is one-way with no cycle, no recursive validation and no lock-order change. The obligation stays subordinate to canonical Lesson/outcome/reconciliation facts.
- **MEDIUM — referenced-event corruption coverage:** added `from_state` corruption, `event_sequence` corruption and an injected structurally noncanonical completed lifecycle row (with an explicit assertion that Lesson authority rejects it), each exercised through `outstandingForTerm()`, `outstandingCountForTerm()` and `outstandingForEnrolment()` with repair proving recovery. The round-2 claim that the referenced event was already proven to be the canonical completed event was an overclaim and is corrected in the contract document and continuity record.
- **Sequencing fix found during verification:** the append-only lifecycle event and (for academy cancellations) the academy obligation are now written before the advance-cancellation guards, inside the same transaction, so the guards validate the exact state the transaction will commit; the guard is also invoked at the service seam before any mutation. Rejections still roll back the event, obligation and lifecycle update together and keep their precise reasons.
- Correction validation: Phase-O contract, authority runtime, 44-case corruption runtime (18 delivery + 26 aggregate obligation classes), five failure-injection boundaries, migration runtime and the 10-mode concurrency matrix pass; Phase F–N regression suites re-run on a fresh empty-schema disposable runtime.

### Phase 2A.2-O correction round 2 — independent re-review findings on round 1 — 2026-09-19

- Independent re-review of round-1 candidate `c63c1327047bd76f3155788874ec6ea6ae7a38e0` (tree `7fa33f0d406e25a403e0f2b5c7d4668923faaaca`) returned **FAIL — CORRECTION ROUND 2 REQUIRED**. The round-2 correction is a descendant of that candidate; Schema 22, migration `022_canonical_lesson_delivery_attendance_authority`, the build identity and O-D1…O-D9 are unchanged, and the round-1 corrections (O-1 capability enforcement, O-2 fail-closed aggregate reads, O-3 corrected counts) are preserved.
- **HIGH — O-D8 completion-event lineage:** `CanonicalAcademyObligationValidator` now validates the obligation's `source_event_id` against the effective outcome's canonical reconciliation lineage. An ordinary non-reconciled non-delivery must carry no completion-event lineage (a stored identifier is an integrity conflict); an O-D8 reconciled completion must name exactly the event the effective outcome supersedes, which must exist in the Lesson's canonical lifecycle, belong to the same Lesson, be a `completed` event, still be that Lesson's latest canonical completed event, and coexist with a `completed` Lesson state. The obligation stays subordinate to the canonical reconciliation truth.
- **MEDIUM — corruption coverage:** added Teacher Assignment identity to the aggregate identity matrix and four O-D8 completion-lineage cases (wrong identifier, cross-Lesson event, missing lineage, wrong lifecycle-event type) plus the ordinary non-reconciled spurious-lineage case, each exercised through `outstandingForTerm()`, `outstandingCountForTerm()` and `outstandingForEnrolment()` with repair proving recovery. The round-1 documentation overclaim that aggregate corruption coverage already included complete source event lineage and Teacher Assignment is corrected.
- **LOW — idempotent replay:** `owe()` no longer returns an existing same-kind obligation before validating the supplied authority. Exact legitimate replay (same canonical source identifiers and identical immutable evidence) remains idempotent with no duplicate row, while conflicting replay intent now fails closed with `obligation_replay_conflict`; the existing obligation's canonical aggregate is revalidated before an idempotent success is reported. `UNIQUE(source_lesson_id)` and concurrency semantics are unchanged.
- Correction validation: Phase-O contract, authority runtime, 41-case corruption runtime (18 delivery + 23 aggregate obligation classes), five failure-injection boundaries, migration runtime and the 10-mode concurrency matrix pass; Phase F–N regression suites re-run on a fresh empty-schema disposable runtime.

### Phase 2A.2-O correction round 1 — independent-review findings O-1/O-2/O-3 — 2026-09-19

- Final independent review of candidate `4f8af9067aa1ed463916d86b32a04f4193f44d23` (tree `8a79719e39496476debef4c67971648ab3447d57`) returned **FAIL — CORRECTION REQUIRED**. The correction is a descendant of that reviewed candidate; Schema 22, migration `022_canonical_lesson_delivery_attendance_authority`, the build identity and every locked product decision are unchanged.
- **O-1 (HIGH)**: `CanonicalAcademyObligationService::owe()` is the public state-mutating academy-obligation seam and now enforces `dzn_manage_canonical_lesson_delivery` itself as its first statement, before any source lookup, validation or mutation. An unauthorized principal fails closed with `Unauthorized`, creates no obligation row and leaves no partial authority; the authorized delivery and advance-cancellation paths are unchanged and remain atomic inside their caller's transaction. No capability was broadened and no new public authority was added.
- **O-2 (HIGH)**: `outstandingForTerm()`, `outstandingCountForTerm()` and `outstandingForEnrolment()` now hydrate every selected obligation against its canonical source Lesson and validate it through `CanonicalAcademyObligationValidator`, which additionally requires the stored Term/Enrolment/Student/Course/Teacher/Assignment selectors to agree with the source authority, validates source outcome/event lineage, schedule-version and occurrence anchors, actor, classification and evidence, and requires the obligation evidence to be identical to the evidence of the source fact. Any invalid row fails the whole aggregate closed; nothing is silently omitted, and the Term count is derived from the same validated aggregate rather than a raw SQL count (the raw count seam was removed). Term and Enrolment selectors select the union of rows claiming the selector and rows whose source Lesson belongs to it, so a corrupted stored selector is validated instead of disappearing.
- **O-3 (LOW)**: the "36 static/source contract tests" claim was a miscount. Corrected to 29 files matching `tests/*contract*.php`, with `tests/static.php` PHP lint and `sh -n` shell syntax checks reported separately as syntax checks.
- Owner-suite robustness: the Phase-O runtime, corruption, failure and concurrency harnesses now choose synthetic occurrence leads that keep each interval inside one UTC day (the provisioned full-day availability rules meet at midnight, where a genuine one-second coverage gap exists) and settle pooled occurrences with one shared wait, making the suites independent of the wall-clock time they are run at.
- Correction validation: Phase-O contract, authority runtime (including the O-1 negative authorization test that invokes the obligation write seam directly as a subscriber), 35-case corruption runtime (18 delivery + 17 aggregate obligation classes), five failure-injection boundaries, migration runtime and the 10-mode concurrency matrix all pass; Phase F–N regression suites re-run unchanged on a fresh empty-schema disposable runtime.

## Phase 2A.2-O — Canonical Lesson Delivery & Attendance Outcome Authority — implementation candidate, unmerged — 2026-09-18

- Candidate branch `phase-2a2o-canonical-lesson-delivery-attendance-authority` from authoritative post-N main `b0687fec98748144f96e3fc56f7e4fb53e01f673`. Schema 22 / migration `022_canonical_lesson_delivery_attendance_authority` / build `phase2a2o-canonical-lesson-delivery-attendance-authority-20260918.1`. **Not merged and not deployed.**
- Locked product decisions: O-D1 `completed` means delivered; O-D2 exception-based recording (ordinary delivery needs no attendance row); O-D3 student no-show consumes the Lesson with no entitlement; O-D4 Teacher/academy non-delivery owes an academy-funded occurrence that never consumes the Student's allowance; O-D5 cancellation stays pre-occurrence while post-occurrence non-delivery is a delivery fact; O-D6 corrections supersede append-only history without reopening lifecycle; O-D7 administrator recording is immediate, idempotent and needs no second approval; O-D8 an explicit authorised append-only command reconciles a historical completion into Teacher/academy non-delivery while the immutable completion event is preserved; O-D9 an advance Teacher/academy cancellation establishes an academy obligation while a Student-requested cancellation never does.
- Adds `dzn_canonical_lesson_delivery_outcomes` (append-only outcome history, one applicable outcome per Lesson, immutable evidence provenance bound to the exact canonical schedule version, occurrence start/end and any reconciled completion event), `dzn_canonical_lesson_delivery_commands` (digest-only command evidence) and `dzn_canonical_academy_obligations` (immutable academy-owed occurrences, `UNIQUE(source_lesson_id)`, independent of Term lifecycle).
- Outcome vocabulary: `delivered`, `student_no_show`, `interruption`, `review_required` and `teacher_non_delivery`; delivery/attendance/remedy classifications are derived from the outcome code and validated on every read, so a caller cannot choose a favourable classification. Only `teacher_non_delivery` and `review_required` block completion.
- Temporal rule: a final delivery/no-show/non-delivery fact becomes recordable only after the governing occurrence has ended; the Teacher capacity buffer is not the waiting period.
- Cross-phase: Lesson completion refuses a known non-delivery or unresolved occurrence; the Phase-M replacement-eligible cancellation reason and the Phase-O `academy_unavailable` reason are both refused after the recorded occurrence start or when an outcome already exists; canonical scheduling refuses a Lesson with a recorded outcome. The Phase-M replacement cap, eligibility rule and Lesson classification are unchanged, historical `attested_non_delivery` records are never reinterpreted, and academy debt is never materialised as a `replacement` Lesson.
- Provider-neutral: evidence is a controlled channel plus an opaque keyed digest with observed time and actor; no provider call, webhook, credential, Google/Amelia coupling or finance consequence is introduced. Deferred: Google Meet evidence ingestion, signed public links, portals, notifications, payability, partial delivery and retention.
- Validation on disposable MariaDB 11.4.13: 29 static/source contract tests plus PHP lint and shell syntax checks, authority runtime, 18-class corruption runtime, five failure-injection boundaries, migration runtime (fresh Schema 22, exact 21→22 rehearsal, repeat safety, capability repair, legacy preservation, no backfill, six malformed-storage cases, retained-022 fail-closed) and a 10-mode deterministic gated concurrency matrix. Phase F–N regression suites re-run. No deployment, production, Theme/NIU, Amelia or external-system change occurred.

## Phase 2A.2-N — Canonical Lesson Scheduling & Teacher Capacity Authority — merged and closed — 2026-09-18

- Candidate branch `phase-2a2n-canonical-lesson-schedule-authority` from authoritative post-M main `f90c41e6d9e129d7d9e1a243941d4655fadc6b8e`, tree `15c257aedf6d2d18afc5c1ba4991d7c468f1195c`. Schema 21 / migration `021_canonical_lesson_schedule_authority` / build `phase2a2n-canonical-lesson-schedule-authority-20260917.1`.
- A scheduled canonical Lesson is a canonical Lesson in `authorised` state plus exactly one applicable canonical schedule version. Phase N adds no `scheduled` Lesson lifecycle state and does not mutate Phase-M Lesson lifecycle as a side effect.
- Adds `dzn_teacher_schedule_roots` (serialization anchor only), `dzn_canonical_lesson_schedule_versions` (immutable interval assertions with one applicable version per Lesson), `dzn_canonical_lesson_schedule_events` (append-only `scheduled`/`rescheduled`/`released` history) and `dzn_canonical_lesson_schedule_commands` (digest-only command evidence).
- Teacher capacity is one concurrent canonical Lesson per Teacher over the half-open occupied interval `[start, end + buffer)`. Occupancy is derived from applicable versions; there is no mutable reservation projection and no capacity counter. Duration and buffer are frozen on every version from the canonical Course policy, with an audited duration override only.
- Scheduling requires a future start, an explicit IANA timezone, a current canonical Enrolment and Term, and the Lesson's recorded Assignment still applicable. Release is permitted while the Enrolment is current, paused or closed. Availability is consumed as an upstream constraint with a capability-controlled, fully audited administrative override that bypasses availability only.
- Serialization uses a per-Teacher root acquired after the canonical Enrolment/Lesson/Assignment context and before the capacity decision; `READ COMMITTED` disables gap locking, so the root is required to prevent first-ever overlapping reservations. No phase may acquire a Teacher scheduling root and then an earlier Enrolment identity-root chain.
- Cross-phase guards: Lesson completion/cancellation, Enrolment closure, Term close/cancel, Teacher Assignment replacement and Teacher archival all reject `active_future_schedule_exists` rather than cascading or silently releasing authority.
- Legacy isolation: `LessonScheduleService` and `lesson_schedule_versions` remain legacy-only, there is no backfill or dual-read, and the canonical validator fails closed on legacy scheduling contamination of a canonical Lesson.
- Validation: 30 static/source contract tests; Phase-N authority, corruption (30 version/event, 29 command, released-state), failure-injection (11 boundaries), migration (fresh, rehearsed 20→21, repeat, capability repair, root backfill) and a 26-mode deterministic gated concurrency matrix on disposable MariaDB 11.4.13. No deployment, production, Theme/NIU or Amelia change occurred.

### Phase 2A.2-N correction round 1 — independent-review HIGH finding — 2026-09-18

- Correction branch `phase-2a2n-migration-verification-correction1`, a descendant of the reviewed candidate `503a96fb4bf14964117b5e2bb2c292dda848e912` (tree `0bf581ce40bb42c765085e818d789d74249f87e8`). Schema 21 / migration `021_canonical_lesson_schedule_authority` / build `phase2a2n-canonical-lesson-schedule-authority-20260917.1` and the locked Phase-N product and authority model are unchanged. The correction is uncommitted and unreviewed.
- HIGH finding: `Migrator::verify_canonical_lesson_schedule_authority_schema()` existed but was never invoked, so Schema 21 could activate — and stay current — without verifying its own concurrency-critical storage. The verifier is now wired into the two paths every earlier phase already uses: the per-migration loop records `021_canonical_lesson_schedule_authority` only after the Phase-N verifier passes, and `verify_current_schema()` (the path taken whenever the schema option already reads 21) now ends with the same verifier.
- Defect exposed by that wiring: the Phase-N verifier's rejection paths were written `throw new\RuntimeException(...)`, which PHP lexes as the qualified name `new\RuntimeException` and executes as a call to an undefined function, so corrupt Phase-N storage produced a fatal `Error` instead of a controlled rejection. The Phase-N verifier now throws `new \RuntimeException(...)` and fails closed. The same form remains in earlier verifiers (`Migrator.php` lines 167, 173, 204, 209, 210 and 211); it is deliberately left unchanged here and reported for a separate bounded correction.
- Regression coverage: `tests/phase-2a2n-migration-runtime.php` gains a malformed-storage section that damages authoritative Phase-N storage six ways (dropped `lesson_applicable` index, dropped `teacher_occupancy` index, dropped `teacher` root index, an added mutable `updated_at` on append-only evidence, a nullable `command_key_digest`, and a non-transactional storage engine) and proves each damaged state is rejected by `Migrator::maybe_upgrade()` and that repaired storage returns to Schema 21. `tests/phase-2a2n-contract.php` now asserts both call sites plus the `RuntimeException` failure form, so the wiring and the fail-closed error class cannot silently regress.
- Validation: 28/28 static/source contract tests; Phase-N contract, authority, corruption (30 version/event, 29 command, released-state), failure-injection and migration suites on disposable WordPress 6.8.3 / PHP 8.3 / MariaDB 11.4.13; Phase M0, M and L runtimes as adjacent-phase regression. Negative control: with the wiring removed, the new malformed-storage section fails with `Malformed canonical scheduling storage was accepted: dropped applicable-slot index` and the strengthened contract fails. No main, production, NIU, Theme, Amelia, Hamnavaz or external-system change occurred; nothing was merged, deployed, committed or pushed.

### Phase 2A.2-N correction round 2 — independent-review HIGH finding — 2026-09-18

- Correction branch `phase-2a2n-migration-verification-correction2`, a descendant of the correction-round-1 candidate `4a1741af1af622936e45b1f1d6f7419fcfcc3b43` (tree `e067496bd5ce8dca64573c794dd0cf8dc5ddaf8d`). Schema 21 / migration `021_canonical_lesson_schedule_authority` / build `phase2a2n-canonical-lesson-schedule-authority-20260917.1` and the locked Phase-N product and authority model are unchanged. The correction is uncommitted and unreviewed.
- HIGH finding: when the completed-migration ledger already recorded `021_canonical_lesson_schedule_authority` but the schema option still read 20 (021 recorded, then execution stopped before the option advanced), `Migrator::maybe_upgrade()` skipped 021 and advanced the schema option to 21 without invoking `verify_canonical_lesson_schedule_authority_schema()`. `maybe_upgrade()` now invokes the Phase-N verifier unconditionally immediately before it writes the schema option, so a retained-021/stale-schema-version activation is verified fail-closed. The verifier call after migration 021 and the `verify_current_schema()` call are unchanged.
- Regression coverage: `tests/phase-2a2n-migration-runtime.php` gains a retained-021 case that keeps the completed marker, sets the schema option to 20, drops the `lesson_applicable` index, proves `Migrator::maybe_upgrade()` rejects with `Migration verification failed` and leaves the schema option at 20, then repairs the index and proves recovery to Schema 21 exactly once. `tests/phase-2a2n-contract.php` now asserts the pre-activation verifier call site is adjacent to the schema-option write, so it cannot silently disappear.
- Negative control: with the pre-activation verifier call removed, the new retained-021 case fails with `Retained-021/stale-schema-version activation accepted damaged Phase-N storage` and the strengthened contract fails with `Phase N storage must be verified before the schema option is advanced to Schema 21`.
- Validation: 27/27 static/source contract tests; Phase-N authority, corruption (30 version/event, 29 command, released-state), failure-injection (11 boundaries) and migration (fresh Schema 21, rehearsed 20→21, repeat, capability repair, Teacher-root backfill, six malformed-storage states, retained-021 pre-activation) suites on disposable WordPress 6.8.3 / PHP 8.3 / MariaDB 11.4.13; Phase M0, M and L runtimes as adjacent-phase Migrator regression. Concurrency was not re-run: the correction touches migration verification only and does not change scheduling, capacity, lifecycle or authority semantics. No main, production, NIU, Theme, Amelia, Hamnavaz or external-system change occurred; nothing was merged, deployed, committed or pushed.

### Phase 2A.2-N final review and merge closeout — 2026-09-18

- Final independently approved candidate `9f92ada6ba82d809c1515566fb426c90a5a68e57`, tree `ec29baabf2d737d0eecd72d857a152b8eba4b163`. Focused independent re-review Round 2 returned no findings and PASS — MERGE READY.
- Merged without squash or rebase from pre-N main `f90c41e6d9e129d7d9e1a243941d4655fadc6b8e` as `08138270f4bd32e5829ef0f5a18a316f6780607d`; the merge tree exactly matches the approved candidate tree.
- Schema 21 / migration `021_canonical_lesson_schedule_authority` / build `phase2a2n-canonical-lesson-schedule-authority-20260917.1` are authoritative. No deployment, production, Theme/NIU, Amelia or external-system changes occurred.

## Phase 2A.2-M — Canonical Lesson Authority — merged and closed — 2026-09-17

- Correction branch `phase-2a2m-canonical-lesson-authority-correction1` created directly from the failed reviewed candidate `1d723e0d7b5ef73db7bc6c24683a73c62684432c`, tree `cb577b7ece33d8533b2e47dacd4b3894566376ec`. Schema 20 / migration `020_canonical_lesson_authority` / build `phase2a2m-canonical-lesson-authority-20260917.1` are unchanged; this round corrects review findings without changing the locked Phase-M product or authority model.
- Finding 1: `CanonicalLessonAuthorityValidator::valid()` is now the single canonical aggregate hydration and integrity gate. It validates the Lesson↔Term enrolment link, the Lesson↔Enrolment Student and Course identity, that the recorded Teacher Assignment structurally belongs to the Lesson enrolment with its immutable Teacher, and complete replacement-origin lineage, including controlled replacement-eligible non-delivery evidence. A historical Lesson is never required to keep a current Teacher Assignment.
- Finding 2: idempotent replay revalidates every recorded command intent (domain, operation, enrolment, Term, expected Teacher Assignment, expected Lesson, expected from-state, replacement origin, result Lesson, result state) against the operation's facts, revalidates the result aggregate through the canonical validator, and requires the durable lifecycle evidence the command recorded. A corrupted command, result or history can no longer replay as success.
- Finding 3: a committed deterministic process-level runner (`tests/phase-2a2m-concurrency-runner.sh`) now coordinates setup, gated worker start, lock-wait observation, controlled release, worker completion and final database verification, and consumes and asserts every worker artefact. It covers same-key standard issuance, the final standard allocation boundary, same-key replacement, same-origin replacement, the final replacement cap, Lesson versus Enrolment pause and close in both orders (with a close-first fixture that genuinely permits closure), Lesson versus Term close and cancel in both orders, Lesson versus Teacher Assignment replacement in both orders, and unrelated-root independence proved by completing one worker while the other stays gated.
- Finding 4: failure injection now spans standard creation, replacement creation (including origin claim release), completion, cancellation, lifecycle/history evidence writes and command evidence writes, with table-driven proof that each rollback leaves no partial aggregate, no orphan evidence and no falsely replayable command.
- Residual defect corrected: the stale finite schema/build enumerations in `tests/phase-2a2h-contract.php` and `tests/phase-2a2l-runtime.php` were replaced with the repository's established minimum-schema plus canonical-build-identity pattern used by the earlier Phase C–F contracts; both were failing on the reviewed candidate itself.
- Validation: all 29 static/source contract tests pass; Phase F, G, I, J, L, M0 and M runtime suites pass on disposable MariaDB 11.4.13 baselines, including a genuine Phase-H Schema-14 fixture database upgraded to Schema 20; the 16-mode gated concurrency matrix passes deterministically.
- Independently approved candidate `f44b502509f5dcb9b5d0281cfda4329407abd3bf`, tree `85a8309bb6179a2061c86245ea1ad9a975c00c2e`, was merged without squash or rebase from pre-M main `c83c1f99557b2cd1f2f81aa82a05177887a468e6` as `ed11086ad8ddc65899c3b855248611b1eb9e09a4`; its implementation merge tree exactly matches the approved tree. Schema 20 / `020_canonical_lesson_authority` and build `phase2a2m-canonical-lesson-authority-20260917.1` are authoritative.
- Phase M is **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED**. No deployment, production access, Theme/NIU, Amelia or external-system change occurred. Phase N has not started.

## Phase 2A.2-M0 — Canonical Enrolment Lifecycle Authority — merged and closed — 2026-09-17

- Merged independently approved candidate `66ba811c47a3494f12f47dbed03775ca7c4e5ba0`, tree `6a9631505a86893abf1fcefa89a78675e7ed3901`, from pre-M0 main `8490d712d18116b3ca606d6c2e3dbb8560d2ce54` as merge commit `c316a5153c7a56b810732495b3785786771c695a`; the implementation merge tree exactly matches the approved tree.
- Schema 19 / migration `019_canonical_enrolment_lifecycle_authority` and build `phase2a2m0-enrolment-lifecycle-authority-20260917.1` are authoritative.
- Independent review passed explicit lifecycle, replay/conflict, protected-read integrity, subordinate closure guards and concurrency boundaries after two focused correction rounds.
- Canonical Lesson authority remains non-authoritative. No deployment, production, Theme/NIU or Amelia change occurred.

## Phase 2A.2-L — Canonical Term Creation & Lifecycle Authority — merged and closed — 2026-09-16

- Merged independently approved candidate `26beab6147df545fed70949f06b83d83042184cd`, tree `b283c319b9a3af3267cf43ad9591e30d93085986`, from pre-L main `788bf0989f4365607ba41322471e50fca75a5e81` as merge commit `36e1d6b754079efcf6fdff02ed2a029099071455`; merge tree exactly matches the approved tree.
- Schema 18 / migration `018_canonical_term_authority` and build `phase2a2l-canonical-term-authority-20260916.1` are authoritative.
- Independent review passed creation, lifecycle, replay/conflict/corruption, failure injection and complete process-level concurrency validation, including stale close/create and cancel/create arbitration.
- No Lesson, Teacher, payment, scheduling, Amelia, Theme, NIU, deployment or production authority changed.

## Post-Phase 2A.2-K continuity normalisation — 2026-09-16

- Reconciled current-state architecture, data-model, migration, product-decision, module-boundary and continuity guidance with authoritative Schema 17 while preserving phase-specific historical provenance.
- Recorded the dual-owner/reciprocal-review execution model, bounded delivery cadence, Phase-L boundary, and current Theme homepage/random-article handoff facts.
- Documentation only: no application source, migration, schema, build, Theme runtime, deployment or production authority changed.

## Phase 2A.2-K — Canonical Term Foundation — merged and closed — 2026-09-16

- Merged independently approved candidate `a6491ccc1cf624be205d1ea022421a5f7903eb2b`, tree `9a59062c2a6aa10c95e88e4d24909c6e770dd27d`, from pre-K main `cf222d20e4ab0d08c992e7ec38bcece8b7625d9e` as merge commit `25e213c69d7f299c9ed5330eed7cd9ba4051c022`.
- Prepared Schema 17 / migration `017_canonical_term_foundation` and build `phase2a2k-canonical-term-foundation-20260916.1` from authoritative Schema 16 main `cf222d20e4ab0d08c992e7ec38bcece8b7625d9e`.
- Additively classifies existing Terms as `legacy_phase1` without translating status, payment, allocation, dates or archive state, and reserves `canonical_enrolment_term_v1` for the canonical foundation.
- Adds canonical `authorised`, `current`, `closed`, and `cancelled` lifecycle storage, one-applicable-Term-per-Enrolment database arbitration, and append-only digest-only lifecycle evidence.
- Adds capability-protected integrity classification and privacy-minimised canonical Term/history reads. Canonical Terms contain no Teacher identity and do not treat the legacy `payment_state` as authority.
- Preserves the legacy Term/Enrolment/Lesson path while closing generic canonical insertion and legacy archive/restore mutation of canonical Terms at both service and repository boundaries.
- Adds source, migration, legacy-preservation, history-integrity, uniqueness, capability-repair and write-boundary coverage. No ordinary canonical Term creator, lifecycle command, idempotency command table, Lesson, scheduling, payment or external-system authority is included.
- Independent correction ensures a closed canonical Enrolment cannot expose an authorised/current applicable Term while preserving valid terminal history. Independent re-review passed.
- Phase 2A.2-K is **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED**. No deployment occurred.

## Phase 2A.2-J — Teacher Assignment Foundation — merged and closed — 2026-09-16

- Merged independently reviewed candidate `b1fd5aebf47a0bce33f74ab56b53c5334d719bf4`, tree `5766e126a5abd7e9160e6c00dc96b6837f49b05e`, from pre-J main `7bd4430737f460fdb995bbe05dea272b55641294` as merge commit `763a9fa9f792cd45114e10978a1bda0a53662c21`.
- Review correction round 1 replaces broad duplicate-text recovery with named-index arbitration and complete operation-specific result proof. Terminal rollback cannot report success unless exact terminal state and evidence already exist through the ordinary locked convergence path.
- Added exhaustive write-boundary rollback and persisted-corruption runtime suites, plus the full competing-operation process matrix with lock attribution. Shared ascending Teacher locks retain archival exclusion and principal-offboarding serialization without serializing unrelated Enrolments that share a Teacher.
- Locked J-5 principal offboarding independence and added six deterministic real-service race modes covering initial, staff-attested replacement and authenticated-Teacher replacement in both commit orders. Principal offboarding revokes login/onboarding authority without becoming Teacher archival or invalidating otherwise valid teaching Assignment authority.
- Updated backward-compatible Phase C–F and earlier historical contracts to use minimum-schema and semantic invariants instead of stale build strings, finite schema enumerations, whitespace, or explanatory prose matches.
- Added Schema 16 / migration `016_teacher_assignment_foundation` and build `phase2a2j-teacher-assignment-foundation-20260915.1`.
- Added a first-class Teacher Assignment aggregate, one-applicable-row database invariant, ordered replacement lineage, append-only lifecycle evidence, and immutable digest-only command evidence.
- Preserved `enrolments.teacher_id` as historical final-arrangement context. Zero Assignment is valid; there is no backfill and no Enrolment lifecycle mutation.
- Initial Assignment revalidates exact retained final-arrangement/Availability Assent provenance. Replacement requires new assignment-specific authenticated-Teacher acceptance or authorised staff attestation and an expected-current Assignment ID.
- Added privacy-minimised readiness/current reads, atomic initial/replacement/end/cancel commands, replay/conflict/already-applied handling, deterministic Enrolment → ascending Teacher → Assignment locking, database arbitration, and transactional Teacher offboarding protection.
- Added source, Schema 15 → 16 migration, isolated runtime, rollback, uniqueness, capability lifecycle, and attributed process-level concurrency coverage.
- Independent review originally found one HIGH and three MEDIUM findings. Correction round 1 closed the HIGH plus failure/corruption and regression gaps; J-5 then clarified the remaining principal-offboarding concurrency ambiguity.
- The final candidate added deterministic offboarding coverage without production-source changes and passed the final independent check. MariaDB lacked MySQL-specific `data_locks` / `data_lock_waits` attribution tables; this was documented and no runtime success was inferred from Ina's execution.
- Phase 2A.2-J is **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED**. No deployment occurred and no Term, Lesson, schedule, capacity, payment, notification, calendar, Amelia, Hamnavaz, CRM, Theme, NIU, or production authority was introduced.

## Phase 2A.2-I — Enrolment Conversion Authority — merged and closed — 2026-09-15

- Merged final independently reviewed candidate `4e40c5fa7665a8f96fcbf19def2cac719e7bc38b` from authoritative pre-I main `f855103c6449ad466ff267c7a9740b56c9ffed66` as merge commit `d0bfbe1b808e60e8bcffae0da6c16a4fda1dc928`.
- Added Schema 15 / migration `015_enrolment_conversion_authority` and build `phase2a2i-enrolment-conversion-authority-20260914.1`.
- Added distinct capability-protected read-only readiness and explicit conversion command boundaries, a concrete Student + Course serialization root, digest-only idempotency evidence, a dedicated canonical writer, initial lifecycle evidence, and unambiguous closure-event predecessor selection.
- Conversion retains the final PII-free Accepted Service Arrangement after privacy erasure, copies the frozen Teacher only as historical context, and stops at an `authorised` canonical Enrolment without downstream operational authority.
- Added clean Schema 14 → 15, isolated rollback/uniqueness, and deterministic held-lock concurrency evidence. No Theme, NIU, Amelia, Hamnavaz, CRM or production system was touched.
- Independent-review corrections add frozen identity/capacity evidence revalidation, complete lifecycle-chain validation, contamination-safe replay, all nine readiness outcomes, explicit stale-readiness rejection, dual-source Race A convergence gates, and direct Phase I capability removal/repair coverage.
- Replay and already-converted semantics remain valid through legitimate `authorised`, `current`, `paused` and `closed` lifecycle progression while malformed history or state/applicability combinations fail closed.
- Phase 2A.2-I is **COMPLETE / MERGED / CLOSED**. Teacher Assignment remains future work and was not started. No deployment occurred; Theme, NIU, Amelia, Hamnavaz, CRM and production were untouched.

## Phase 2A.2-H — Canonical Enrolment Foundation — 2026-09-14

- Merged independently reviewed candidate `6ae121d0a96b8bd034663992f4ee5ee0812a9893` from authoritative pre-H main `aa626e89c66a393977caf002355589990aec03df` as merge commit `672e5334fe9f37fff5f872cf3fdad18af5ab50d5`.
- Added Schema 14 / migration `014_canonical_enrolment_foundation` and build `phase2a2h-canonical-enrolment-foundation-20260913.1`.
- Preserved Phase 1 Enrolments as explicit legacy history and added Student + Course canonical identity, immutable Accepted Service Arrangement provenance, applicable-row uniqueness, reserved lifecycle/lineage structure, and append-only lifecycle evidence.
- Record models are `legacy_phase1` and `canonical_student_course_v1`; the reserved canonical lifecycle is `authorised → current → paused → closed`.
- Added protected six-outcome applicability inspection, preserved constrained legacy/bootstrap compatibility, and closed generic/manual canonical creation, legacy archive/restore, and Phase 1 Term/Lesson/Teacher authority paths for canonical records.
- Independent-review findings HIGH-1, HIGH-2, HIGH-3 and MEDIUM-1 were corrected; final independent re-review result: **PASS — MERGE READY**. Phase 2A.2-H is complete, independently reviewed and merged.
- No Accepted Service Arrangement → Enrolment conversion, Teacher Assignment, canonical Term/Lesson authority, deployment or external-system authority was added. Conversion readiness and explicit conversion authority remain separate future work.
- No deployment occurred; Theme, NIU, Amelia, Hamnavaz and production were untouched.

## Phase 2A.2-G — Final Acceptance + Accepted Service Arrangement Foundation — 2026-09-13

- Merged reviewed candidate `701f62595327ba464d81299b1832ba7825eddc4e` from pre-G main `0607be0ce6f2dcd32dc60a4d8ff6c76d0f4012c8` as merge commit `3a33bafd5943b15b139bbe40cb198cbb201cc943`.
- Added Schema 13 / migration `013_final_acceptance_arrangement_foundation` and build `phase2a2g-final-acceptance-arrangement-20260910.1`.
- Added authority-revalidated final acceptance, immutable PII-safe Accepted Service Arrangements, atomic sibling Option outcomes, Proposal finality and digest-only idempotency.
- Production authority-lock candidate `d96378d7f16a2ff1c90ec1fc99273361f3630808` passed source review; `701f62595327ba464d81299b1832ba7825eddc4e` added deterministic contention evidence and semantic contract hardening only.
- Independent review result: **PASS — MERGE READY**. Deterministic authority contention evidence was accepted.
- No deployment occurred. Conversion, Enrolment creation, Teacher Assignment, Term/Lesson generation, payment, notification, scheduling, calendar and Amelia authority remain excluded. Phase 2A.2-H Enrolment foundation is next.

## 0.1.0 — 2026-09-09

Phase 2A.2-F merge main: `c578f137ed537276524a460a9bb4771ec6fbcc4c`

Schema 12 / migrations 001–012. Build identity: `phase2a2f-student-identity-acceptance-authority-20260909.1`.

### Phase 2A.2-F — Student Identity & Acceptance Authority Foundation

- Merged Schema 12 / migration `012_student_identity_acceptance_authority` from approved candidate `f7b8066ce6f45c6bee461cdb13cc2614283988bd` through PR #15 as merge commit `c578f137ed537276524a460a9bb4771ec6fbcc4c`.
- Added human-reviewed, append-only Booking Request identity resolution and Student capacity classification records, versioned Student principal-link provenance, and effective/revocable guardian representative grants.
- Added protected internal administration and an informational authority-read service. No final acceptance, conversion, public workflow, calendar, payment, notification, or Amelia authority was added.
- Added atomic Student-principal supersession, a common authority lock order, strict guardian intervals with append-preserving expiry, real Schema 11 → 12 migration evidence, and an executable deterministic race harness.
- Confirmed derived adult-self authority and `guardian_representative` / `service_acceptance` authority; adult delegation remains unavailable in V1, and eligibility reads are informational non-bearer results.
- Preserved the Booking Request privacy-erasure boundary and recorded attributed R1–R11 concurrency evidence.
- Completed foundation chain: 2A.2-A Booking Request assessment; 2A.2-B Coordination Case/Candidate Teacher; 2A.2-C Teacher Availability Assent; 2A.2-D Proposal Foundation; 2A.2-E Provisional Acceptance Evidence; 2A.2-F Student Identity & Acceptance Authority Foundation.
- Deliberate exclusions remain: no automatic PII matching, public identity workflow, adult delegation, final acceptance, Accepted Service Arrangement, Booking Request conversion, Enrolment, Teacher Assignment, Term, Lesson, payment, notification, calendar or Amelia authority.
- **BEFORE PHASE 2A.2-G / FINAL ACCEPTANCE-CONVERSION WORK: HAMNAVAZ DOMAIN / PLATFORM RECONCILIATION REQUIRED.**

### Phase 2A.2-E — Provisional Acceptance Evidence

- Merged Schema 11 / migration `011_provisional_acceptance_evidence` from reviewed candidate `740a29dbe3fce081ba0fd19d6b6259cb4d293134` through PR #14.
- Added append-only `accepted_pending_conditions` evidence with `authority_unresolved`; no final acceptance, Student/guardian authority, Accepted Service Arrangement, conversion or downstream operational authority.

### Phase 2A.2-D — Proposal Foundation

- Added Schema 10 / migration 010 for canonical Proposal Families, Teacher-specific Options, and immutable Versions.
- Added Assent-authorised initial and replacement issuance with Proposal-scoped idempotency and guarded pointer advancement.
- Added a dedicated protected coordination capability and internal issuance surface; no public endpoint or acceptance authority.
- Added source, migration, isolated-runtime, revision-race, and Assent-invalidation-race test coverage.
- Validated candidate `5b67442218baeba94b68d988f31f51e09ae1a58b` passed with non-blocking limitations and was merged through PR #13.

## Historical development record

### Phase 2A.0 — Principal invitation runtime-validation preparation

- Added an isolated-only, reversible WordPress/MySQL validation matrix and a
  WP-CLI delivery-preparation assertion helper. The helper is not packaged,
  creates no accounts, emits no raw secret, and requires an explicit
  non-production database marker.
- Stamped the clean runtime-validation package identity for the approved
  schema-version-3 Phase 2A.0 head. No deployment, provider send, Amelia
  change, or authority cutover is included.

### Phase 1 — Core Foundation & Controlled Runtime Validation

- Implemented the Phase 1 canonical schema, migration verification/locking,
  capabilities, Core identities, relationship validation, Lesson schedule
  history, archive safeguards, Operational Exceptions, and minimal protected
  engineering admin surfaces.
- Added a controlled Phase 1F beta validation runbook and clean-package
  preparation. Runtime validation remains pending; no business authority,
  Amelia dependency, provider integration, or Phase 2 work has begun.

### Product decision reconciliation — 3 September 2026

- Confirmed permanent Core Teacher and Student identities; contact details and
  provider IDs are attributes/mappings, and suspected duplicates never
  auto-merge.
- Confirmed separate first-class Instrument, Course, Enrolment, Term, and Lesson
  concepts, with Enrolment as the continuing relationship and Term as its
  bounded allocation/payment/renewal cycle.
- Confirmed that normal rescheduling preserves Lesson identity and audited
  schedule history; genuine replacement/make-up occurrences may be separately
  linked Lessons.
- Confirmed UTC canonical Lesson instants, explicit IANA timezones, retained
  recurring wall-clock intent, and separate Gregorian/Persian calendar
  presentation.
- Confirmed numeric internal keys plus immutable opaque ULID-style UIDs,
  approved `DZN-*` reference prefixes, and independent public-action
  capabilities.
- Confirmed explicit, administrator-authorized, audited, one-to-one optional
  Core Teacher ↔ Hamnavaz Profile links.
- Confirmed soft archive/default retention, separately authorized deletion or
  anonymization, and purpose-limited provider provenance.
- Confirmed effective-dated teacher rates and a teacher-rate/currency snapshot
  per Lesson, independent from Student pricing, as Platform Phase 9 Finance
  architecture rather than Phase 1 persistence.
- Confirmed the approved initial lifecycle vocabulary, Phase 1 Course fields,
  ULID-style UIDs/reference prefixes, custom-table direction, append-only Lesson
  Schedule Versions, introductory-Lesson relationship rules, Term replacement
  defaults, timezone onboarding, and Operational Exception framework.
- Corrected the initial Lesson vocabulary to `introductory`, `standard`, and
  `replacement`, and confirmed direct Lesson Student/Teacher/Course identity
  snapshots with creation-time Enrolment consistency validation.
- Removed premature Finance persistence from the proposed Phase 1 foundation;
  Finance tables, rate snapshots, and audited corrections remain Phase 9 work.
- Rejected the Phase 0 proposal for an automated Amelia importer, shadow
  synchronizer, parity engine, and repeatable mapping/checkpoint pipeline. The
  small active dataset will be recreated manually; historical Amelia data may
  be retained outside operational Core as a read-only archive.
- Renamed canonical Platform Phase 2 to **Core Data Setup & Cutover
  Preparation** without changing Phase 0–10 numbering.
- Preserved the runtime strangler strategy, authority ledger, bounded cutovers,
  rollback, and exit gates while prohibiting new Amelia data-model dependencies
  in Platform Core.

### Phase 0 — Existing System Audit & Architecture

- Established the initial canonical architecture for Core-owned Teacher,
  Student, Term/Enrolment, and Lesson identity/state; the combined
  Term/Enrolment question was resolved by the 3 September 2026 decision above.
- Defined Lesson as the operational centre while keeping attendance,
  notifications, scheduling, finance, reporting, and provider integrations in
  separate module boundaries.
- Documented the complete Delnavazan Enhancements migration map using KEEP,
  EXTRACT, REFACTOR, ADAPT, REPLACE, and RETIRE classifications.
- Defined the conceptual data model, `LegacyReference`, Google connection model,
  Hamnavaz/Core Teacher relationship, and state-separation rules.
- Defined the minimum security architecture for secrets, OAuth, webhooks, public
  lesson actions, authorization, logs, retention, and provider isolation.
- Considered a no-big-bang Amelia migration strategy with read-only imports and
  shadow comparison. The automated data-migration elements are superseded by the
  3 September 2026 decision above; per-capability authority cutovers, rollback,
  and exit gates remain current.
- Restored the canonical Platform Phase 0–10 roadmap and documented those
  migration mechanisms as cross-phase techniques rather than substitute phases.
- Scoped the proposed Platform Phase 1 Core foundation without implementing it.
- Added repository hygiene rules for local, secret-bearing, generated, export,
  and backup files.

### Current status

- Platform Phase 0 — Existing System Audit & Architecture is complete.
- Platform Phase 1 — Core Foundation & Canonical Data Model is implemented on
  its review branch and awaiting controlled Phase 1F beta runtime validation.
- Amelia remains installed, operational, authoritative, and readable.
- Hamnavaz Phase 4 remains intentionally paused.
## Phase 2A.2-G recovery candidate history

- Adds Schema 13 / migration 013 for immutable, PII-free Accepted Service Arrangements and sibling Proposal Option outcomes.
- Adds lock-revalidated adult-self/minor-guardian final acceptance, HMAC idempotency, privacy-safe replay, and a protected internal admin form.
- Freezes further Proposal issuance after a Family has a final arrangement. No deployment or downstream service conversion is included.

## Phase 2A.2-L candidate — 2026-09-16
- Advances the candidate to Schema 18 with migration `018_canonical_term_authority` and immutable digest-only canonical-Term command evidence.
- Adds explicit administrator-only canonical Term creation and the four bounded lifecycle transitions with Enrolment-first locking, fail-closed replay, and process-level race harnesses.
- Preserves legacy Term/Lesson behavior and adds no Lesson, Teacher, payment, scheduling, allocation-consumption, notification, or external-integration authority.

## Phase 2A.2-M0 candidate

- Advances the candidate to Schema 19 / `019_canonical_enrolment_lifecycle_authority` and build `phase2a2m0-enrolment-lifecycle-authority-20260917.1`.
- Adds explicit, idempotent canonical Enrolment activate, pause, resume, and guarded close commands plus minimal administrator invocation.
- Hardens shared Enrolment history validation to reject illegal lifecycle edges. Canonical Lesson authority remains non-authoritative.
