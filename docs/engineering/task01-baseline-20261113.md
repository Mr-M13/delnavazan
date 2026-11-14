# Task 01 — Verified engineering baseline (in progress)

Status: **BLOCKED** until the remaining independent checks are evidenced. This report is a point-in-time record, not approval to deploy.

## Source and release evidence

- Baseline source at initial inspection: `main` `091fd927d1359b7ddfa97ae3894675240a00ee4f`.
- Latest successful production deployment workflow observed at initial inspection: run `43228258004`, targeting that SHA. The actual live runtime SHA and migration ledger have **not** been independently read.
- `main` advanced during Task 01; branch reconciled with `main` `1481dce77fbe0657b168aa0721040b09b2b3a851`. One booking fixture conflict was resolved in favour of `main` because it already included equivalent isolated-student logic. PR #606 is mergeable. Re-evaluate if `main` advances again.
- Direct read-only production SSH check on 2026-11-14: installed plugin build ID `main-1481dce77fbe`; WordPress option `dzn_platform_schema_version=61`. The full deployed SHA was not independently attested from the runtime build ID (12-character prefix only).
- Production deployment uses a manual exact-SHA workflow, read-only HTTPS smoke checks, private backups and forward-only migrations. Do not interpret workflow success as proof of recoverability.

## Reproduced tests

- Local PHP 8.3 and disposable Docker WordPress/MariaDB 11.4 images available.
- Fresh installation and schema idempotence: PASS.
- Teacher-statement runtime: PASS (14 checks).
- Booking concurrency, timezone overlap, buffer/half-open boundary, failed-confirmation exclusion, canonical and legacy lesson occupancy: PASS at `47176896c3ceb3760994a9423c80da9bcd1bd2f5`.
- Runtime path guards, shell syntax and whitespace: PASS.
- Booking boundary test originally reused the concurrency fixture's student and collided with its future-dated slot. Test fixture now has an isolated synthetic student; no production booking logic changed.
- GitHub exact-head PR runtime gate: **PASS** on merged-main reconciliation head `2ebc50032993c757f475b3e3d12bfbfcd891ed3c` (run `43548737666`, 18/18 jobs).

## Isolation and controls

- All edits performed in dedicated `/private/tmp/dzn-task01-runtime-cleanup` worktree and branch `task01/runtime-cleanup-guards` (PR #606). Existing dirty user worktrees were not modified.
- Runtime state and WordPress HTTP port are disposable and loopback-only; provider production calls are not part of the tested workflows.
- This PR contains only test fixture, runtime cleanup and documentation changes. Notification, availability, booking and finance business logic is unchanged.

## Remaining blockers / acceptance

1. Re-run exact-head GitHub CI on any subsequent report/reconciliation commit; latest reconciled head passed 18/18.
2. Production build prefix and active schema are read directly. Independently attest full deployed SHA and inspect the migration ledger, not just the schema option.
3. Database recovery rehearsal **PASS** on 2026-11-14: production backup `20261103T213614Z-876876-edfdfa19467ff1aa461c20d5b9dca3904ee57d2e` streamed via SSH to a network-disabled disposable MariaDB 11.4 container on the authorised Mac. SQL imported successfully; 269 tables restored; `mariadb-check --check --silent` returned success. Disposable DB/container deleted on exit. Application archive `application-files.tar.gz` passed `tar -tzf` integrity listing; full restored WordPress application boot/smoke remains **PENDING**. This backup is from 2026-11-03, so it does not establish freshness of the current recovery point.
4. GitHub production environment verified with `main`-only branch policy and administrator bypass disabled; manual deployment and exact-SHA gate are enforced in the workflow. Confirm staging/runtime isolation and any remaining controls.
5. Rebase or reconcile against current `main`, rerun exact-head tests, and only then decide merge and deployment.

**Release decision: BLOCKED. Do not merge or deploy on the strength of this report.**
