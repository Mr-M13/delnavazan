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

1. **Physical audible LiveKit output is not verified.** A live local two-participant session proved
   publication, subscription, shared music, reconnection and exit/Finish; headless Chrome used a fake
   media device, so a final audible check on a staging device remains.
2. **True future-term obligation creation is BLOCKED by the current commercial authority.** #472
   safely exposes an already-issued future-term obligation and its selection/unit path passes. The
   staging environment has no canonical way to issue an unbound future-term offer while a current
   Term is applicable. Checkout, signed webhook and duplicate-webhook idempotency pass on a valid
   synthetic obligation.
3. **Settlement is full-statement only.** Partial settlement and bank-statement reconciliation are
   outside this authority. No bank/IBAN/card data is stored.
4. **Shared music mode is in-memory.** A simultaneous full reload of both participants resets it to
   off; there is no server-persisted room mode.
5. **No real money movement** was performed. The Stripe test-mode E2E is offline and local.
6. **Cancelled replacement release is a policy decision.** A cancelled replacement now releases the
   Student slot; a cancellation attributable to Student conduct may still need an audited decision.
7. **No production deployment or financial activation.**

## Production readiness

**Ready for owner review. Merge and deployment remain blocked** until the owner explicitly accepts or
resolves the two remaining BLOCKED checklist items: a physical audible LiveKit check and a canonical
future-term offer/collection path. Everything else is implemented and verified.

No merge, deployment, financial activation or production record change was performed.

---

## Final release-readiness checklist

| Requirement | Result | Evidence |
| --- | --- | --- |
| All component PR checks complete successfully | **PASS** | #464–#469 and #471–#474 are all 11/11 with no failures |
| #470 includes every final component commit and current main | **PASS** | Every PR head is an ancestor of `integration/portal-programme-20261101`; current `main` (#475 teacher induction) merged before the final sweep |
| Full integrated source suite | **PASS** | `platform/tests/*.php` source sweep: 0 failures |
| Full integrated runtime suite | **PASS** | finance admin/schedule/notification, statement+settlement, Teacher confirmation, replacement release, recording choices, LiveKit authorizer |
| Two-participant LiveKit: audio, music, reconnect, exit | **PASS** | live local LiveKit session: 2/2 participants, remote audio subscribed both sides, music propagated, reconnect preserved state, Student Exit left Lesson authorised, Teacher Finish completed it |
| LiveKit token permits shared data | **PASS** | `canPublishData=true` defect fixed in #469 |
| Audible speaker-output check on physical device | **BLOCKED** | headless fake media proves track publication/subscription, not audible output |
| Valid synthetic obligation checkout + webhook + idempotency | **PASS** | `stripe-checkout-test-mode-e2e-runtime.php`: PASS (local sandbox checkout, signed webhook, wrong-signature refusal, duplicate convergence, one settlement) |
| Future-term obligation creation and checkout end-to-end | **BLOCKED** | #472 safely exposes existing future-term obligations and its selection is unit-proven; the current commercial authority has no staging-valid path to issue an unbound future-term offer while a current Term is applicable. This is a product/authority gap, not a checkout defect |
| Statement generation and settlement on latest code | **PASS** | real completed-period statement drafted/issued, settled, replayed once, Teacher read `paid`/`up_to_date` |
| Replacement cancellation releases allowance | **PASS** | runtime: one cancelled + one active replacement → used 1, available restored |
| Replacement non-cancelled still consumes | **PASS** | same runtime asserts one active replacement remains consumed |
| Desktop/tablet/mobile no horizontal overflow | **PASS** | 21 authenticated loads at 1440/820/390; `scrollWidth === clientWidth` |
| Exhausted replacement shown as zero | **PASS** | authenticated Student page: `جبرانی: ۰ از ۲ باقی‌مانده` |
| Consent choices remain separate and Persian | **PASS** | recording runtime + authenticated Account page |
| Production money movement / deployment | **NOT PERFORMED** | no merge, deploy, charge, payout or production record change |

**Release decision:** the code is ready for owner review. Merge and production deployment remain
blocked until the two remaining BLOCKED items are explicitly accepted or completed: a physical
audible LiveKit check, and a canonical future-term offer/collection path for a true advance payment.

## Merge record (2026-11-01)

Owner approved merging after the release-readiness verification. Component PRs were merged into `main`
in the documented order, each after being updated onto the then-current `main` and re-running all 11
required gates:

| Order | PR | Merge commit |
| --- | --- | --- |
| 1 | #471 test contracts | `deda1110` |
| 2 | #464 canonical Teacher delivery confirmation | `f57c8156` |
| 3 | #465 finance accuracy | `c0f0d62c` |
| 4 | #473 Teacher statement settlements | `4b221261` |
| 5 | #468 Student classes and account | `092a8970` |
| 6 | #472 future-term payments | `3bdbc85d` |
| 7 | #474 cancelled replacement release | `db6dbc31` |
| 8 | #466 Teacher Today/Classes/Settings | `72899db1` |
| 9 | #469 LiveKit classroom sync | `ff4451dc` |
| 10 | #467 portal navigation accessibility | `8e50c1ba` |

This integration branch then merged the resulting `main` and re-ran the full source sweep (0 failures)
and the portal CSS/presentation contracts before the final merge.

No deployment, charge, payout, financial activation or production-data change was performed.
