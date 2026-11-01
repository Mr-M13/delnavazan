# Portal programme integration — 2026-11-01

**Branch:** `integration/portal-programme-20261101` (draft PR #470)
**Status:** implementation complete; integrated source, runtime and authenticated visual verification
passed. **No merge, deployment, financial activation or production-data change has been performed.**
Release still requires explicit owner approval.

This branch is the integrated review surface for the separate component PRs. It is intentionally a
draft/integration PR and must not be merged before the component PRs are reviewed and merged in the
proposed order.

## Existing work reused

- **#456** Teacher availability UX and finance-transparency base.
- **#457** Compact LiveKit classroom UI, fullscreen, rotation and watermark.
- **#459** Absence/replacement dashboard gaps.
- **#460** LiveKit canonical attendance adapter.
- **#461** Offline payments, monthly statements, branded email and manual refunds.
- **#462** Term replacement allowance allocation and accepted-absence revisions.
- **#463** Finance hardening, student payment history, receipts, schedules and notification outbox.

The owner’s uncommitted changes in the primary working tree and the local
`hardening/finance-20261101` branch were preserved; no user work was overwritten.

## Component PRs and merge order

| Order | PR / branch | Scope |
| --- | --- | --- |
| 1 | #471 `fix/phase-contract-obsolete` | Repairs 10 obsolete phase contracts; no production defects found there |
| 2 | #464 `feat/teacher-canonical-completion` | Canonical Teacher delivery confirmation, nonce fix, runtime proof |
| 3 | #465 `feat/finance-accuracy-transparency` | Tasks 3–4: finance reconciliation and shared percentage transparency |
| 4 | #473 `feat/teacher-statement-settlements` | Real statement generation defects, settlement event, paid/pending/overdue status |
| 5 | #468 `feat/student-classes-account` | Tasks 6–7: replacement summary, timeline, account, notifications, Persian consent |
| 6 | #472 `feat/future-term-payments` | Existing future-term obligation exposure through the secure checkout |
| 7 | #474 `fix/replacement-cancelled-release` | Cancelled replacement releases the Term allowance |
| 8 | #466 `feat/teacher-today-classes-settings` | Tasks 1, 2, 5: Today, Classes/calendar/history, Settings/help |
| 9 | #469 `feat/livekit-classroom-sync` | Task 8: shared music mode, exit/finish, four-way rotation |
| 10 | #467 `fix/portal-navigation-a11y` | Task 9: navigation contrast and responsive/state consistency |
| 11 | #470 `integration/portal-programme-20261101` | Task 10: full verification and release report (this branch) |

#473 is stacked on #465; #472 is stacked on #468. Merge those stacks in order.

## Verification performed

### Source contracts

- `php -l` on every tracked PHP file: **pass**.
- Full `platform/tests/*.php` source sweep (excluding runtime/concurrency/browser fixtures):
  **0 failures**.
- All 10 previously failing phase contracts now pass by repairing obsolete assertions, not by
  weakening safety checks. No production defect was found in those paths.
- `phase-2a2u-contract` now records the two added settlement refusal reasons and the `paid` state.

### Disposable WordPress/MariaDB runtime

All of these passed in the local acceptance harness:

| Runtime | Result |
| --- | --- |
| `finance-administration-runtime.php` | PASS |
| `finance-schedule-runtime.php` | PASS |
| `finance-notification-runtime.php` | PASS |
| `teacher-delivery-confirmation-runtime.php` | PASS |
| `teacher-statement-payment-runtime.php` | PASS (real completed-period issued statement, settlement, replay, Teacher paid/up-to-date read) |
| `replacement-cancelled-release-runtime.php` | PASS |
| `livekit-classroom-runtime.php` | PASS (canonical authorizer/launch; no live server contacted) |
| `recording-choices-ui-runtime.php` | PASS |

### Real finance defects repaired

The statement runtime exposed three genuine defects that source-only tests missed:

1. `FinanceStatementRepository::assignPublicHandle()` did not exist although statement drafting
   called it. Added the stable `FINST` public handle.
2. Snapshot/payability/statement-line derivation digests were computed from typed integers at
   capture but re-proved from repository strings. Normalised capture-time integer fields before
   re-proving; valid stored facts now verify.
3. No recorded settlement existed. Added an append-only `payment_recorded` event and
   `issued → paid` compare-and-swap, with administrator settlement evidence and
   `up_to_date` / `pending` / `overdue` status.

### Authenticated staging and visual evidence

Using synthetic Phase-J principals plus real canonical Term/Lesson/statement data:

- Teacher and Student login/routing: **pass**.
- `/teacher-portal/` Today, Classes, Finance, Settings: HTTP 200; no PHP fatal.
- `/student-portal/` Classes, Account, Payments: HTTP 200; no PHP fatal.
- 21 page loads across **1440, 820 and 390 CSS px**: `scrollWidth === clientWidth` in every case, so
  no horizontal overflow.
- Exhausted replacement balance rendered exactly `جبرانی: ۰ از ۲ باقی‌مانده`.
- Teacher finance rendered the real paid statement, status `تسویه‌شده`, paid `A$۵۰.۰۰`, unpaid
  `A$۰.۰۰`.
- Classroom rotation rendered and labelled `۰°`, `۹۰°`, `۱۸۰°`, `۲۷۰°` with upright controls/labels
  and a labelled test pattern proving the local video transform.

Screenshots and the full matrix are in
`platform/docs/evidence/portal-programme-2026-11-01/` (see `VISUAL-VERIFICATION.md`).

## Remaining blockers / limits

1. **Two-party LiveKit audio was not exercised against a live LiveKit server.** The canonical
   authorizer runtime, synchronization contracts and rotation screenshots passed; a real two-browser
   audio session remains a staging gate.
2. **Settlement is full-statement only.** Partial settlements and bank-statement reconciliation are
   outside this authority. No bank/IBAN/card data is stored.
3. **Future-term advance payment exposes existing obligations only.** #472 does not create a future
   term offer; a Platform-issued obligation must already exist. The enabled path is unit-proven, the
   authenticated fixture correctly showed the disabled state because no future offer existed.
4. **Shared music mode is in-memory.** A simultaneous full reload of both participants resets it to
   off; there is no server-persisted room mode.
5. **No real money movement or Stripe checkout** was performed. Finance runtime uses synthetic local
   evidence only.
6. **Cancelled replacement release is a policy decision.** A cancelled replacement now releases the
   Student slot; if cancellation is attributable to Student conduct, an administrator may still need
   a separate audited decision.
7. **No production deployment or financial activation.**

## Production readiness

**Ready for owner review and a controlled staging release; not deployed.** The source and runtime
safeguards pass, and authenticated desktop/tablet/mobile evidence is captured. Before production:

- run a two-participant LiveKit session on staging;
- run one real completed-period statement and settlement through the admin surface with synthetic
  staging records;
- verify a future-term obligation and its secure checkout end-to-end;
- confirm notification/payment workflows and mobile devices on staging.

No merge, deploy, financial activation or production record change was performed without explicit
approval.
