# Phase-W remediation evidence

These artifacts are intentionally split by the recovery-review finding so a disposable
WordPress/MariaDB runtime can execute each proof independently. They do not create fixtures,
change options, register routes, or claim evidence when the runtime is unavailable.

| Artifact | Proof boundary | Local status |
| --- | --- | --- |
| `phase-2a2w-migration-runtime.php` | additive/repeat-safe Schema 031 and marker-only migration | runtime not available in this workspace |
| `phase-2a2w-principal-runtime.php` | zero/one/many principal resolution and input isolation | runtime not available in this workspace |
| `phase-2a2w-authorization-runtime.php` | subject/object ownership and owner-validator fail-closed behaviour | runtime not available in this workspace |
| `phase-2a2w-capability-runtime.php` | mint/rotate/revoke, command replay, binding and one-way storage | runtime not available in this workspace |
| `phase-2a2w-public-action-runtime.php` | non-mutating GET, one-time POST, redemption/outcome/refusal evidence, and the `portal_absence_submission_pending` refusal of a replay that arrives while another delegator holds the confirmation's lease | runtime not available in this workspace |
| `phase-2a2w-concurrency-runner.sh` (plus `-concurrency-setup.php`, `-concurrency-worker.php`, `-concurrency-wait.php` and `-concurrency-verify.php`) | per-Lesson root serialisation with Performance Schema wait attribution, refusal/outcome writes racing a redemption and a rotation, disjoint-Lesson non-contention, and single-delegation convergence for `replay_during_delegation` and `replay_after_crash` | runtime not available in this workspace |
| `phase-2a2w-corruption-runtime.php` | digest, signature, sealed-target and substituted-result failures | runtime not available in this workspace |
| `phase-2a2w-failure-runtime.php` | rollback, abandoned claim and durable refusal evidence, the declared delegation-lease bound, and the idempotent return of an outcome that another delegator already recorded | runtime not available in this workspace |
| `phase-2a2w-theme-isolation-contract.php` | no Theme/provider/session/outbox coupling | source-only artifact; run when PHP is available |
| `phase-2a2w-persistence-failure-unit.php` | behavioural §15.6 split — refusal-versus-failure classification, the one-transaction refusal evidence, and the checked capability transaction boundaries (a failed begin/commit is the declared persistence failure and no Join `302`, minted capability or evidence survives it) | executed locally under the PHP 8.3/8.5 CLI WebAssembly runtime; embedded and executed by `phase-2a2w-contract.php` |
| `phase-2a2w-blocking-findings-contract.sh` | source-only guard for the corrected legacy Teacher schema predicate, exact guardian grant-scoped read re-proof, refused public-action event evidence, the Lesson-root lock on every capability-bearing append, the declared delegation lease, the one-transaction public refusal evidence (action row plus denial row), the locked §5.2 action-state vocabulary, and the checked §15.6 capability transaction boundaries | executed locally; no WordPress/MariaDB claim |

The source-only remediation contract is executable without a database once PHP is available:
`phase-2a2w-remediation-contract.php`. No runtime artifact is represented as executed by this
candidate because PHP, WordPress and MariaDB are absent locally.

Correction round 2 closes the review of `770d9bc432bd1e312b50a0908892478a51ac9fab` / tree
`8419814ba038ee874cf562009ba21b72957e3e57`: every capability-bearing append — the refusal evidence of
a handle that resolves to a capability, the redemption claim, the durable `delegating` lease and the
post-delegation outcome — now begins its transaction by taking the Lesson's
`portal_lesson_capability_roots` row `FOR UPDATE` and re-reading the capability under that lock; and
the confirmation's own append-only lease row is the single delegator, so an exact replay re-reads the
recorded terminal outcome before delegating, never delegates twice, and returns an already-recorded
outcome instead of failing the unique redemption key. Three source guards that could not have passed
when executed are corrected in the same candidate: the GET-renderer write guards in
`phase-2a2w-public-action-runtime.php` and `phase-2a2w-remediation-contract.php` now bound the
renderer function instead of everything above the confirmation service, and
`phase-2a2w-contract.php` asserts the consumed-replay principal bypass at its actual seam
(`bool $required=true` and the `false` owner argument).

Correction round 3 closes the review of `eb76ee59b5743978da5c417739bdc38fd61e350e` / tree
`5364db95e2bd71e984ace4f91d2d8c19121d733e`: a public-action refusal now commits exactly one piece of
refusal evidence. The refused `portal_public_action_events` row **and** its digest-only
`portal_access_denials` row are appended by `PortalPublicActionService::recordRefusal()` inside the one
Lesson-root-serialized transaction, in the §15.2 order, and a failure of either insert — or of the
commit — rolls both back (`portal_action_evidence_persistence_failed`). A refused post-delegation
outcome commits its outcome row and its denial row in the same transaction, so `recordOutcome()` is
the only writer for that path. The `PortalPublicActionController` no longer inserts denial evidence and
no longer passes a surface, and the denial carries the route-declared surface plus the resolved
`capability_id` and `lesson_id` when the handle resolves to a capability. The contract's locked §5.2
`Public action state` vocabulary now lists `delegating`, and §13.1–13.2, §15.6 and §18 state the
one-transaction refusal-evidence rule. `phase-2a2w-blocking-findings-contract.sh` and
`phase-2a2w-remediation-contract.php` guard the new seam (the denial insert lives inside the refusal
transaction and the controller holds no denial writer), and `phase-2a2w-concurrency-verify.php`
asserts in `refusal_vs_redeem` that the refusal's durable denial exists — written by the service when
the worker calls `recordRefusal()` directly, so that proof does not depend on the controller at all.

Correction round 3 closes the review of `ba47412e9b05dff53e83fa1b88f99c1dc015966e` / tree
`7d1ad0199aa8c8ce9adf5c40e0443df6edb25bf8`, which returned two blocking findings. First, the
`PortalCapabilityService` transaction boundaries were unchecked: `join()` ignored the results of both
`START TRANSACTION` and `COMMIT` (and `mint()`, `transition()`, `rotate()` and `recordRefusal()` had the
same unchecked commit/begin paths), so a failed boundary could continue outside the required transaction
or hand the public route a `302` after a failed commit, bypassing the controllers' failure
classification. Every begin/commit now goes through the checked `begin()`/`commit()` helpers, a failure
re-raises the declared `portal_capability_persistence_failed`, and `rollback()` is issued only for a
transaction the call actually opened. The same candidate adds the failure-path coverage: §10 of
`phase-2a2w-persistence-failure-unit.php` drives a failed begin and a failed commit through the real
public Join callback (no response object and no evidence survive either) and through `mint()`/`revoke()`
(no minted capability and no durable row survive), and
`phase-2a2w-blocking-findings-contract.sh` guards the helpers, the single-call-site rule for the raw
`START TRANSACTION`/`COMMIT`/`ROLLBACK` statements, the opened-transaction rollback and the new proofs.
The unit's own skip guard was also corrected: it keyed on `WP_REST_Response`, which
`phase-2a2w-public-rate-limit-unit.php` declares first when `phase-2a2w-contract.php` embeds both proofs,
so the embedded §15.6 run was silently skipped; it now keys on the core `wpdb` class /
`register_rest_route()`, and the embedded proof executes. Second, `docs/PORTAL-AUTHORIZATION-REGISTRY.md`
simultaneously claimed Schema 31 / the W build and Schema 32 for the same candidate; its opening now
identifies `2ab0c71` only as the historical Schema-31 portal base and states one unambiguous current
package identity — Schema 32 and build `phase2a2s-notification-communications-authority-20260924.1`,
with `phase2a2w-portal-facing-services-20260927.2` as the W slice's own stamp.
