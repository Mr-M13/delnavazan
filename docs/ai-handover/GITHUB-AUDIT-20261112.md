# GitHub audit — 2026-11-12 (UTC)
Evidence: GitHub CLI read-only queries against `Mr-M13/delnavazan` from the authorised Mac, plus GitHub connector and main README. This is a point-in-time snapshot.

## Verified PR states
| PR | Title / purpose | State |
|---|---|---|
| [#588](https://github.com/Mr-M13/delnavazan/pull/588) | God Mode Super Tester | MERGED 2026-11-12 08:41:21 UTC |
| [#592](https://github.com/Mr-M13/delnavazan/pull/592) | Teacher intro opportunity board | MERGED 2026-11-10 00:50:54 UTC |
| [#552](https://github.com/Mr-M13/delnavazan/pull/552) | Post-intro survey/staff follow-up | CLOSED, NOT MERGED |
| [#551](https://github.com/Mr-M13/delnavazan/pull/551) | Booking recovery diagnostics | MERGED 2026-11-07 02:57:45 UTC |
| [#600](https://github.com/Mr-M13/delnavazan/pull/600) | Acceptance concurrency barrier | OPEN DRAFT, mergeability CONFLICTING at audit |
| [#601](https://github.com/Mr-M13/delnavazan/pull/601) | AI handover | OPEN DRAFT |

`gh pr list --state open` returned only #600 and #601 at audit time.

## Verified GitHub CI / deployment workflow records
- `main` remote SHA: `51309c72bb397f8bb67ca7561c3b5068586eed76`.
- Production deployment workflow [run 43194178157](https://github.com/Mr-M13/delnavazan/actions/runs/43194178157): **success**, dispatched 2026-11-12 09:44:48 UTC, targeting that main SHA.
- Main PR runtime gate [run 43193138991](https://github.com/Mr-M13/delnavazan/actions/runs/43193138991): success, workflow_dispatch, same SHA.
- Handover PR runtime gate [run 43206938409](https://github.com/Mr-M13/delnavazan/actions/runs/43206938409): success; CodeQL [run 43206934485](https://github.com/Mr-M13/delnavazan/actions/runs/43206934485): success.
- #600 CodeQL [run 43193195013](https://github.com/Mr-M13/delnavazan/actions/runs/43193195013): success. This **does not** prove its required booking runtime gate passed.

**Limit:** A successful GitHub deploy workflow does not itself establish production application health or independently confirm the active deployed SHA. Verify with read-only host checks.

## Acceptance barrier issue (#600)
PR description: follow-up to #598. Teacher scenarios and migration checks were reached, but the host runner could not create the `.dzn-opp-go` concurrency barrier in a root-owned WordPress bind mount. The proposed fix creates the barrier through WP-CLI inside the container. PR text explicitly requires booking runtime gate success before merge. As of audit it was conflicting, so reconcile in isolation and rerun exact-head checks; no merge authorised.

## Local worktree
Authorised Mac: `~/Projects/delnavazan`, branch `feat/settings-rbac-diagnostics-20261110`, **14 modified tracked files**. No local modifications were made during this audit. Preserve all edits and avoid resetting or switching this worktree.

## Unresolved verification
- Production site and runtime health, deployed SHA and current schema version.
- Full migration inventory, especially any historical migration 060 conflict and later 074/075 ordering.
- Whether closed #552 functionality was implemented through another merged PR.
- Whether Meet co-host/recording, payment follow-through, notification fallback and teacher rescheduling incidents are fixed in deployed code.
- Source-of-truth discrepancy with standalone `delnavazan-theme` README.

## Next actions
1. Update historical handover statuses from this audit.
2. Read-only production verification and migration inventory (with authorised access).
3. Inspect #600 diff, conflicts and exact-head booking gate; do not merge without explicit permission.
4. Reconcile #552 closure against main's post-intro implementation.
5. Back up handover outside the repository and test successor AI onboarding.
