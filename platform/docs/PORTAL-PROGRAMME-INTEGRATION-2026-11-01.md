# Portal programme integration — 2026-11-01

**Branch:** `integration/portal-programme-20261101`
**Status:** implementation and verification complete for the code that can be exercised in this
environment; no merge, deployment, financial activation or production-data change was performed.

This branch is the integrated review surface for the separate component PRs. It is intentionally a
draft/integration PR and must not be merged before the component PRs are reviewed and merged in the
proposed order.

## Already-existing work reused

The investigation found these merged PRs on `origin/main` and reused them instead of duplicating
their fixes:

- **#456** Teacher availability UX and finance-transparency base.
- **#457** Compact LiveKit classroom UI, fullscreen, rotation and watermark.
- **#459** Absence/replacement dashboard gaps.
- **#460** LiveKit canonical attendance adapter.
- **#461** Offline payments, monthly statements, branded email and manual refunds.
- **#462** Term replacement allowance allocation, accepted-absence revisions and cancelled-lesson
  release.

The student replacement balance and finance statement/read authorities were therefore not
reimplemented. The remaining work builds on them.

The owner's uncommitted finance-hardening changes in the primary working tree and the local
`hardening/finance-20261101` branch were preserved and not included in these branches. No user work
was overwritten.

## Component PRs and merge order

| Order | Branch | Scope |
| --- | --- | --- |
| 1 | `feat/teacher-canonical-completion` (#464) | Canonical Teacher delivery confirmation, nonce fix, runtime proof |
| 2 | `feat/finance-accuracy-transparency` (#465) | Tasks 3–4: finance reconciliation/remuneration and shared percentage-only transparency |
| 3 | `feat/student-classes-account` (#468) | Tasks 6–7: replacement summary, timeline, account status, notifications, Persian consent, future-payment blocker |
| 4 | `feat/teacher-today-classes-settings` (#466) | Tasks 1, 2, 5: Today, Classes/calendar/history, Settings/help |
| 5 | `feat/livekit-classroom-sync` (#469) | Task 8: shared music mode, exit/finish, four-way rotation |
| 6 | `fix/portal-navigation-a11y` (#467) | Task 9: navigation contrast and responsive/state consistency |
| 7 | `integration/portal-programme-20261101` (#470, draft) | Task 10: integrated verification and release report (this branch) |

The completion backend must merge before the Teacher UI because the UI calls it. The LiveKit finish
button sends the same canonical endpoint and accepts the new `class_completed` status.

## Verification performed

- `php -l` on every tracked PHP file: pass.
- Source-contract sweep over `platform/tests/*.php` (excluding runtime/concurrency/browser fixtures):
  10 failures, all reproduced identically on clean `origin/main`:
  `booking-ux-state-contract`, `phase-2a2p-contract`, `phase-2a2r1-contract`,
  `phase-2a2s-contract`, `phase-2a2t-contract`, `phase-2a2u-contract`,
  `phase-2a2v-contract`, `phase-2a2w-remediation-contract`,
  `phase-2a2w-theme-isolation-contract`, `teacher-maintenance-invitation-honesty-contract`.
- Disposable WordPress/MariaDB acceptance:
  - `acceptance/make smoke` authenticated login/routing smoke: **pass**.
  - Phase-J fixture + `platform/tests/teacher-delivery-confirmation-runtime.php`: **pass**.
    Proves real locks, delivered outcome, Lesson completion, replay convergence and non-owner refusal.
- Targeted contracts: teacher Today/Classes/Settings, portal identity, finance reconciliation,
  tuition transparency, LiveKit sync/portal integration, student replacement summary/account,
  recording choices, navigation/accessibility, responsive shell, public hardening, static lint: **pass**.

## Run the runtime proof

```sh
cd acceptance
make smoke
source bin/common.sh
# run phase-2a2j-fixture.php and teacher-delivery-confirmation-runtime.php inside the disposable
# WordPress CLI container with DZN_PHASE_2A2J_RUNTIME_TEST=fixture and
# DZN_TEACHER_CONFIRMATION_RUNTIME=authority; see the integration notes in the PR body.
```

The runtime proof uses only synthetic local data and must never be pointed at production.

## Outstanding blockers

1. **No Teacher payout ledger.** Task 3 asks for paid/unpaid/overdue status based on actual payout
   records. The schema deliberately has no payout table. The Finance read returns an explicit
   `payout_ledger_unavailable` state; it does not fabricate payments. Product/authority work is
   required before a true payment status can be shown.
2. **No future-term advance checkout authority.** Task 7 asks for advance payment of future terms.
   The commercial read exposes obligations only for issued/accepted offers and there is no
   future-term obligation/checkout command. The Student button is disabled with a Persian
   explanation; no invoice, booking or charge is created. This needs a separate commercial
   authority tranche.
3. **No real completed-period statement was generated here.** The authorised admin statement
   workflow exists from #461, but this environment has no production/staging teacher with a
   completed period. Task 3's “review at least one real statement” remains a staging verification
   step.
4. **No authenticated browser screenshots.** Source/DOM contracts and the login/routing smoke were
   run, but no portal account with synthetic lessons was browser-driven. Visual review on staging is
   still required.
5. **Shared music mode is in-memory.** A simultaneous full reload of both participants resets the
   shared mode to off; there is no server-persisted room mode.
6. **Replacement lifecycle edge cases.** The canonical allocation counts replacement Lessons that
   exist; a cancelled replacement Lesson is not automatically released back to the Student by this
   programme. This needs a policy decision and a separate audited change.
7. **Pre-existing source-contract failures** listed above are outside this programme and were not
   “fixed” by weakening tests.

## Production readiness

**Not ready for deployment.** The code paths are implemented and the source/runtime checks listed
above pass, but the following must happen on staging with realistic synthetic records before release:

- canonical attendance and Teacher confirmation
- finance reconciliation with a real completed-period statement
- exhausted replacement balance and absence notification
- introductory vs standard availability
- LiveKit join/recording/attendance and the new finish action
- both portals on mobile and desktop
- notification and payment workflows

No merge, deploy, financial activation or production record change was performed.
