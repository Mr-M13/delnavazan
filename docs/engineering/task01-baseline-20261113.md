# Task 01 — Verified engineering baseline (in progress)

Status: **BLOCKED** until the remaining independent checks are evidenced. This report is a point-in-time record, not approval to deploy.

## Source and release evidence

- Baseline source at initial inspection: `main` `091fd927d1359b7ddfa97ae3894675240a00ee4f`.
- Latest successful production deployment workflow observed at initial inspection: run `43228258004`, targeting that SHA. The actual live runtime SHA and migration ledger have **not** been independently read.
- `main` advanced during Task 01; all merge and deployment decisions must re-evaluate current `main` and PR head.
- Production deployment uses a manual exact-SHA workflow, read-only HTTPS smoke checks, private backups and forward-only migrations. Do not interpret workflow success as proof of recoverability.

## Reproduced tests

- Local PHP 8.3 and disposable Docker WordPress/MariaDB 11.4 images available.
- Fresh installation and schema idempotence: PASS.
- Teacher-statement runtime: PASS (14 checks).
- Booking concurrency, timezone overlap, buffer/half-open boundary, failed-confirmation exclusion, canonical and legacy lesson occupancy: PASS at `47176896c3ceb3760994a9423c80da9bcd1bd2f5`.
- Runtime path guards, shell syntax and whitespace: PASS.
- Booking boundary test originally reused the concurrency fixture's student and collided with its future-dated slot. Test fixture now has an isolated synthetic student; no production booking logic changed.
- GitHub exact-head PR runtime gate for final report commit: **PENDING**. A manual run for `47176896` was dispatched as run `43364866568`.

## Isolation and controls

- All edits performed in dedicated `/private/tmp/dzn-task01-runtime-cleanup` worktree and branch `task01/runtime-cleanup-guards` (PR #606). Existing dirty user worktrees were not modified.
- Runtime state and WordPress HTTP port are disposable and loopback-only; provider production calls are not part of the tested workflows.
- This PR contains only test fixture, runtime cleanup and documentation changes. Notification, availability, booking and finance business logic is unchanged.

## Remaining blockers / acceptance

1. Verify exact-head GitHub CI on the final report commit, not an older SHA.
2. Independently confirm production deployed application SHA, active schema version and migration ledger from an authorised read-only source.
3. Execute a **documented, isolated backup-and-restore rehearsal**, including integrity checks of restored DB and application files. A pre-deploy backup mechanism alone does not meet this criterion.
4. Confirm staging/runtime isolation and protected deployment environment controls from actual settings.
5. Rebase or reconcile against current `main`, rerun exact-head tests, and only then decide merge and deployment.

**Release decision: BLOCKED. Do not merge or deploy on the strength of this report.**
