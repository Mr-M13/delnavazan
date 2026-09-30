# Core-dataset technical prerequisites

Schema 33 adds bounded operational evidence storage only. It creates no Enrolment, Teacher Assignment, Term or Lesson rows, performs no backfill, selects no canonical-versus-legacy policy, calls no provider, and does not deploy or cut over.

The existing authorities remain the writers:

- Enrolment conversion: `EnrolmentConversionService::convert()`.
- Initial Teacher Assignment: `TeacherAssignmentService::assignInitial()`.
- Canonical Lesson issuance: `CanonicalLessonAuthorityService::createStandard()` / `createReplacement()`.

The readiness service provides a read-only-over-domain, deterministic expected-vs-actual projection for each bounded scope (`enrolments`, `teacher_assignments`, `canonical_lessons`). The digest is SHA-256 over the ordered projection; a mismatch is reported and never repaired. Each projection is then persisted as an append-only `core_dataset_reconciliation_runs` row carrying the acting operator, the expected and actual counts and digests and the match state, with deterministic `core_dataset_reconciliation_findings` rows when a run mismatches — so the recorded reconciliation digest outlives the request that produced it, while no Core domain row is created, changed or repaired. The run row and every finding it requires are written in one transaction, so a mismatched run is never durable without its findings, and a projection read that fails is thrown rather than projected, so no run is recorded for a dataset that could not be read. Operator evidence records the actor, operation, target, reason and evidence-reference digest, and the idempotency payload binds that same evidence-reference digest, so replaying a command key against different operator evidence is a conflicting payload rather than an exact replay: an exact replay of a completed readiness operation converges on its recorded row and a same-key/different-payload replay is refused. Corrections are append-only records containing prior and corrected digests; they do not mutate a domain aggregate.

Durable actor/provenance is recorded only for future evidence. Existing rows remain untouched, including nullable historical actor fields. No value is inferred for a missing actor or source.

## Authenticated Core operator entrypoint

The **Core dataset readiness** submenu is the intended authenticated admin/operator entrypoint. Its
pre-render `load-$hook` handler accepts only the following nonce-bound POST commands, each behind the
authority's existing narrow capability:

| Command | Existing authority | Required capability | Readiness receipt |
| --- | --- | --- | --- |
| Convert accepted Service Arrangement | `EnrolmentConversionService::convert()` | `dzn_convert_service_arrangements_to_enrolments` | `enrolment_conversion` |
| Assign initial Teacher | `TeacherAssignmentService::assignInitial()` | `dzn_manage_teacher_assignments` | `initial_teacher_assignment` |
| Issue standard canonical Lesson | `CanonicalLessonAuthorityService::createStandard()` | `dzn_manage_canonical_lessons` | `canonical_lesson_issuance` |

The submenu is registered behind the actor's own exact command capability — the first of the three
capabilities above that the actor holds — and never behind `dzn_view_diagnostics` or any other
read capability. WordPress registers a submenu page together with its callback and its
`$_registered_pages` entry only when the actor holds the registration capability, and otherwise
records a no-privilege entry that denies the page, so an actor holding exactly one of the three
documented command capabilities reaches this entrypoint while an actor holding none of them gets no
entrypoint page and no POST hook at all. The unrelated diagnostics capability is therefore neither
required nor sufficient to reach a Core operator command.

The handler rejects an actor without the operation's exact capability with HTTP 403 before nonce
verification or a domain call. The action-bound admin nonce is then verified before dispatch. The same
capability is checked again by the wrapper and by its owning authority. On success the wrapper records a
`CoreDatasetReadinessService::recordOperation()` receipt with `operator_entrypoint`, the operator-supplied
evidence-reference digest, command-key binding and the domain result id; the receipt id is returned in the
post/redirect/get notice. The entrypoint adds no REST route, provider call, credential use, data seed,
deployment or cutover path. It does not make `recordCorrection()` a domain command: corrections remain
append-only evidence of a separately executed audited authority command.

## Rehearsal

1. Use a disposable Schema-31/32 database snapshot and record its tree/SHA.
2. Apply the additive readiness slice and verify only the five `core_dataset_*` tables were added; assert all pre-existing table schemas and rows are byte-for-byte unchanged.
3. Run reconciliation for all three scopes with operator-supplied expected count/digest, using an operator identity. Each run records its own evidence row — actor, expected/actual counts and digests, match state and, on a mismatch, deterministic findings — while touching no Core domain row. Preserve both results, including mismatches.
4. In a disposable transaction, exercise each existing authority with a controlled fixture or stop at its validation boundary; do not commit a real domain row. Separately exercise the evidence recorder with non-production test identities.
5. Reconcile again, confirm no unexpected Core rows, and verify evidence rows carry the acting operator and digests.
6. Roll back by disabling the readiness menu/entrypoint and restoring the pre-rehearsal database snapshot. Do not delete evidence or domain rows in production; production correction is forward-only through the owning authority and an append-only correction record.

Acceptance is the preserved pre-existing tree, a recorded reconciliation digest, and a rehearsed rollback—not a cutover decision.

## Correction round 1

Applied on top of the reviewed-and-failed candidate `650e30ea77c5e8ae568af4663cb9a035df7aa566` / tree `20209a09b7fe7fe645caf0a1dc64ed5600611c40`, whose review found three blocking defects. The candidate is additive: the reviewed commit stays an ancestor and nothing was reset, rebased, amended or force-pushed.

1. **Schema-033 migration lock.** `033_core_dataset_technical_prerequisites` is now a member of the locked migration map in `Migrator::maybe_upgrade()` — installed, verified with `verify_core_dataset_technical_prerequisites_schema()` before it is recorded as complete and before `dzn_platform_schema_version` may advance — and it is required by `verify_current_schema()`, which also runs its verifier. The separate post-upgrade `ensure_core_dataset_technical_prerequisites()` path (activation hook and `plugins_loaded` call) is gone, so no database can publish Schema 33 without the five readiness tables or the ledger record.
2. **Recorded reconciliation.** `CoreDatasetReadinessService::reconcile()` still writes no Core row, but it now persists the run it just computed: an append-only `core_dataset_reconciliation_runs` row with the acting operator, expected/actual counts and digests and the match state, plus deterministic `core_dataset_reconciliation_findings` rows (`scope_count_mismatch`, `scope_digest_mismatch`) when a run mismatches. The returned result carries the new `run_id`.
3. **Idempotent operator evidence.** `CoreDatasetReadinessService::recordOperation()` now loads the row already carrying the command-key digest and returns its record id when the recomputed payload digest matches — including when the insert loses a duplicate-key race — and fails closed with `Core operator evidence replay conflict` only for a same-key/different-payload replay. A retry of a completed readiness operation therefore converges instead of failing on the unique key.

The bounded runtime harness was completed at the schema this package declares: `bin/verify-schema32.sh` (historical name) asserts Schema 33, the Schema-033 ledger entry and the five readiness tables; `bin/run-retained-migration.sh` pins the complete `33|33` ledger; both upgrade rehearsals declare the five Schema-033 tables; and `tests/runtime-path-guards.sh` re-asserts that same declared schema.

## Correction round 3

Applied on top of the reviewed-and-failed candidate `4942b5a330c61053dee20ccd8198da2e83bedd25` / tree `7de134e46dacd575d98be6ed1fdd1c4a9670fdfb`, whose review found two blocking defects. The candidate is additive: the reviewed commit stays an ancestor and nothing was reset, rebased, amended or force-pushed.

1. **Evidence-reference binding.** `CoreDatasetReadinessService::recordOperation()` digested the operator evidence reference only on the inserted row, so the command-key idempotency payload did not cover it and a same-key replay carrying a different `evidenceReference` was accepted as an exact replay and returned the prior row. The service now computes `hash('sha256', $evidenceReference)` once, binds that digest into the payload that `command_payload_digest` is taken over, and stores the same value. A replay whose evidence reference differs is therefore a conflicting payload and fails closed with `Core operator evidence replay conflict`; a genuine retry of the same evidence still converges on the recorded row.
2. **Transactional run and findings.** `CoreDatasetReadinessService::reconcile()` inserted the `core_dataset_reconciliation_runs` row and only then wrote each required finding, so a failed finding insert left a mismatched run durable with no findings. The run insert and every required finding now run inside one transaction that opens before the run row is written, commits only after every finding has persisted, and `ROLLBACK`s (re-throwing the original failure) when the `START TRANSACTION`, the run insert, a finding insert or the `COMMIT` itself fails — matching the declared transaction idiom of `PortalCapabilityService`. A matched run writes no finding and still commits its single run row.

`tests/phase-opreadiness-core-dataset-contract.php` was extended with §4 and §5 to re-assert both corrections from the tree: the evidence-reference digest is bound into the payload before the payload digest is computed and is the same value the row stores, and the reconciliation transaction opens before the run insert, holds across `recordFindings()`, and commits only after it, with the `ROLLBACK`/rethrow on failure. The suite remains WordPress-free and reads the tree only.

## Correction round 5

Applied on top of the reviewed-and-failed candidate `738625cc2beab70b4004795b1b2028e7461e2889` / tree `22f5829ee11c7eb55623aa0583a9fe997842b45a`, whose independent review returned one blocking finding. The candidate is additive: the reviewed commit stays an ancestor and nothing was reset, rebased, amended or force-pushed.

1. **Fail-closed projection read.** `CoreDatasetReadinessService::reconcile()` reconciled its projection with `get_results(...) ?: array()`. A failed WordPress query does not return `null` from an `ARRAY_A` read: `wpdb::get_results()` returns `$new_array` built from `last_result`, and `wpdb::query()` flushes that to `array()` while it sets `last_error`. The `?:` fallback therefore turned a database/query failure into an apparently empty dataset, and the service hashed and persisted a zero-count projection — with its count and digest mismatch findings — instead of failing closed, breaking the invariant that a recorded run describes a projection that was actually read. The failure state is now preserved and checked: the query is issued once, `(string)$wpdb->last_error` is captured, a non-array result is refused, and the service throws `Core reconciliation projection read failed` **before** the digest is computed and **before** the evidence transaction opens. A projection-read failure therefore inserts no run row and no finding row, and the expected-versus-actual evidence invariant holds.

`tests/phase-opreadiness-core-dataset-projection-failure-unit.php` is the executable failure-injection proof the finding asked for, and `tests/phase-opreadiness-core-dataset-contract.php` §6 embeds it so the contract guard executes it alongside the static seams. The unit stubs `$wpdb` with a store reproducing the real failure shapes — WordPress' empty-array-plus-`last_error` read, a non-array read, and rows returned with a query error — and proves that each fails closed before the transaction opens, with no `START TRANSACTION`, no run or finding insert and no durable evidence row; that a failed run insert or finding insert still rolls the whole run back; and that the unchanged success path still commits exactly one run row for a match and its two deterministic findings for a mismatch, each in one transaction. It remains WordPress-free (the `ARRAY_A`/`OBJECT` constants a bare CLI lacks are declared locally) and needs no database, so `runtime/bin/run-pure-tests.sh` executes it with the rest of the guard. This implementation environment still has no host PHP and the sandbox denies the Docker socket, so the guard was not executed here and remains to be executed in a PHP-capable environment.

## Correction round 6

Applied on top of the reviewed candidate `82ef727f46518a3ea9b327ebb3e0e813cb105b11` / tree `c2dc6796c6b231c5b1f90e1605ad51c325f34541`, whose independent review reported no actionable blocking defect and closed with the verdict line `PASS - MERGE PLANNING MAY PROCEED`. Its recorded result text was: "Reviewed the exact candidate/tree against parent `738625c`. The projection-read path now fails closed before hashing or transaction start, and the added failure-injection coverage exercises empty-with-error, null, and partial-row error shapes. No actionable blocking defect found." The round was nevertheless raised, because the host's verdict parser matches its bare `FAIL` marker as a substring and that text contains the word `fails` — so the correction round ran against a review that had already passed the candidate. Round 6 therefore closes no finding: per this repository's precedent for a round whose review reports no actionable finding, it records itself and changes no source and no test.

The candidate is additive: it adds only this record, and changes no source or test file. The reviewed commit stays an ancestor; nothing was reset, rebased, amended or force-pushed; and no PHP source file, test file, schema, migration, build identity, table, route, capability or reason code changed. No merge, push, deployment, provider call, credential use or cutover is performed or authorised.

The one gate the review recorded as unmet is unchanged and is not closed by this round. `runtime/bin/run-pure-tests.sh` — which executes the WordPress-free contract guard and the projection-read failure-injection proof that guard embeds — could not run, because the prescribed cached `mariadb:11.4` image is unavailable and Docker access is denied. Executing that wrapper in a PHP-capable, Docker-capable environment remains the outstanding acceptance step.

## Final-acceptance entrypoint correction round 2

Applied on top of the reviewed-and-failed candidate `be7596d7b0094b68437474c4ee3da7a02d91d456` / tree `3ba735dca7a9eb1c5b6f8c469a99c5e5cf2657eb`, whose independent review returned one blocking finding. The candidate is additive: the reviewed commit stays an ancestor and nothing was reset, rebased, amended or force-pushed.

1. **Reachable narrow entrypoint.** `CoreDatasetReadinessController::menu()` registered the readiness submenu behind `dzn_view_diagnostics`. WordPress' `add_submenu_page()` returns `false` and records a `$_wp_submenu_nopriv` entry for any capability the current actor does not hold, so an actor holding only one documented Core command capability received no submenu entry, no `$_registered_pages` entry and no `load-$hook` handler — `user_can_access_admin_page()` denied the page before the POST hook could run. That contradicted the invariant that every command is available behind its exact existing narrow capability. The submenu is now registered behind the actor's own exact command capability: `entrypointCapability()` returns the first of `dzn_convert_service_arrangements_to_enrolments`, `dzn_manage_teacher_assignments` and `dzn_manage_canonical_lessons` that the actor holds, the registration passes that capability, and an actor holding none of the three is registered no entrypoint at all. The `VIEW_CAPABILITY` constant and its `dzn_view_diagnostics` render gate are gone, so the controller no longer references the diagnostics capability anywhere. Nothing in `dispatchOperatorAction()` changed: each action still requires its exact capability (HTTP 403) before the action-bound nonce and before any authority or receipt writer runs, and the UI still shows each form only to an actor holding that command's capability.

`tests/phase-opreadiness-core-operator-entrypoint-contract.php` §1 now also fails if the controller references `dzn_view_diagnostics`, if the submenu capability is not the actor-resolved command capability, or if that capability is not drawn from the action map. `tests/phase-opreadiness-core-operator-entrypoint-unit.php` gained WordPress-shape `add_submenu_page()`/`add_action()` fakes and proves behaviourally that the submenu and its `load-` hook register for each of the three command capabilities held on its own, and that neither the unrelated diagnostics capability alone nor an empty capability set produces any entrypoint page or POST hook. Both files remain WordPress-free and are executed by `runtime/bin/run-pure-tests.sh`.

The candidate adds no REST route, provider call, credential use, data seed, deployment or cutover path, and grants no new capability. This implementation environment still has no host PHP and the sandbox denies the Docker socket, so the pure guard was not executed here; running it on a PHP-capable, Docker-capable host remains the outstanding acceptance step.
