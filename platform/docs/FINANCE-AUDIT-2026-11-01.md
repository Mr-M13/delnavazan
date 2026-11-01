# Finance audit — student, teacher, statements, bookkeeping and administration

**Date:** 2026-11-01
**Scope:** `platform/src/Core/Application/Finance`, `CommercialPaymentService`, `PaymentExecution`, the
four-tab Finance workspace, the Student Portal payment surface, the Teacher Portal finance surface,
the operational cashbook and their schema/capability relationships.
**Result:** critical admin write gaps closed; remaining boundaries documented.

---

## 1. What the audit covered

- Student side: offer → obligation → Checkout/evidence → settlement → funding → lesson activation.
- Teacher side: rate timeline → Lesson snapshot → payability → statement draft → issue → portal view.
- Statements: period derivation, timezone policy, issuance gates, supersede/withdraw, print/export.
- Bookkeeping: Central Ledger entries, reversals, transfers, reconciliations, balances and reporting.
- Administration: the four tabs, capabilities, nonces, staff bundles and every write boundary.
- Failure modes: duplicate payment, rejected evidence, expired offer, missing snapshot, pending
  payability, overlap, stale totals, unset timezone and concurrent state changes.

## 2. Findings

| ID | Severity | Area | Finding | Status |
| --- | --- | --- | --- | --- |
| F1 | **Critical** | Admin payments | The Payments tab was entirely read-only. There was no way for an administrator to record a bank transfer, cash receipt or other offline payment. The only settlement path was a verified Stripe webhook. | **Fixed** — `ManualPaymentSettlementService` + Outstanding Payments UI. |
| F2 | **Critical** | Statements | `TeacherStatementService::draft()/issue()/withdraw()/supersede()` existed but no admin route called them. The Statements tab could only read statements that already existed. | **Fixed** — guarded draft/issue/withdraw/supersede actions. |
| F3 | **High** | Contract types | A `state=completed` Checkout attempt was rendered as "Settled evidence" even when R1 had rejected the evidence or had not accepted it. This could show a payment as paid when no obligation was settled. | **Fixed** — settlement is now derived only from an accepted settlement/evidence row; completed Checkout is labelled "settlement not accepted". |
| F4 | **High** | Intro payments | Paid introductory obligations could only be settled by Stripe. An offline introductory payment left the booking at `awaiting_payment` forever. | **Fixed** — `IntroPaymentService::recordManualSettlement()` reuses the paid transition and booking projection. |
| F5 | **High** | Staff access | The Finance staff bundle granted only the two view capabilities. A non-administrator Finance staff member could not mark a payment, issue a statement or write the ledger even after being given the Finance workspace. | **Fixed** — the bundle now grants the narrow manage capabilities the workspace exposes. |
| F6 | **High** | Statement issuance | `FINANCE_STATEMENT_TIMEZONE` is unset by default. A draft records the unset state, so issue fails later with `policy_unset_for_statement_period` and the administrator had no explanation. | **Fixed** — the Statements tab warns before drafting and links to the policy form. |
| F7 | **Medium** | Bookkeeping | The Ledger table showed a **Fees** column that was hard-coded to zero and a "Student / teacher" column that was hard-coded to `—`. The cashbook schema stores neither, so both columns were misleading. | **Fixed** — columns now show the stored category/description, signed amount and reconciliation result. |
| F8 | **Medium** | Bookkeeping | The simplified ledger form hard-coded `adjustment_direction=increase` and `reason_code=data_entry_correction`. A decrease adjustment was impossible from Finance. | **Fixed** — direction and reason are now explicit fields. |
| F9 | **Medium** | Reconciliation | The ledger marked a transaction "Reconciled to statement" from any later snapshot for the account, even when that snapshot recorded a nonzero difference. | **Fixed** — the latest difference is read and displayed as reconciled / difference. |
| F10 | **Medium** | Visibility | A manual settlement has no Checkout attempt, so it could not appear in the Checkout-attempt payment table. The Finance team could not see what it had just recorded. | **Fixed** — Recent settlements reads `commercial_obligation_settlements` directly. |
| F11 | **Medium** | Payment counts | The "Successful" card counted `checkout_sessions.state=completed`, not accepted settlement. | **Fixed** — separate "Checkout complete" and "Settled" counts. |
| F12 | **Medium** | Bookkeeping access | The Ledger tab exposed only a record form. Transfers, statement comparisons and reversals lived on a separate cashbook route with no link from Finance. | **Fixed** — Finance links to the full cashbook and the ledger form remains the narrow quick-entry path. |
| F13 | **Medium** | Student portal | Student payment visibility is behind `DZN_THEME_STUDENT_PAYMENTS`, which defaults to **off**. A manual settlement updates canonical truth, but the Student cannot see the Payments tab until the owner enables the flag. | **Not changed** (deliberate product gate); documented. |
| F14 | **Low** | Notifications | There is no dedicated "payment receipt" message on any settlement path. Manual settlement still fires the same `dzn_phase_2a2r1_after_settlement` hook, so the normal post-intro activation notification path runs. | **Documented** — a dedicated receipt is optional product work, not a manual-settlement gap. |
| F15 | **Medium** | Refunds | There was no admin refund action. Refund evidence was read-only and the review authority had no operator surface. | **Fixed** — administrator-only manual refund evidence and review lifecycle. |
| F16 | **Low** | Teacher portal | Statements were already readable, but because F2 prevented issuance, the Teacher usually saw an empty list. | **Resolved by F2**. |
| F17 | **Low** | Test coverage | No source contract or runtime assertion covered offline completion, statement issuance, batching, email or refunds. | **Fixed** — new source contracts, branded-email unit, Statements-tab runtime assertions, a canonical-offer manual-settlement runtime and refund/route/resolve runtime proof. |
| F18 | **High** | Monthly statements | There was no batch surface. An administrator had to select each Teacher and draft/issue one statement at a time, so a month with many Teachers was operationally impractical. | **Fixed** — monthly batch with preview, per-Teacher isolation, issue and email. |
| F19 | **High** | Statement presentation | The default statement was generic admin HTML/CSS (Tahoma, grey/blue) and was not suitable for email. | **Fixed** — brand-aligned web/print template and a fixed, table-based, inline-styled email. |
| F20 | **High** | Refund authority | `RefundReviewService` could only consume a refund evidence row created elsewhere. No administrator could create the original manual refund evidence or drive the review to a decision. | **Fixed** — `ManualRefundService` + Refund review UI. |


## 3. Fixes applied

### 3.1 Offline payment completion

- `platform/src/Core/Application/Finance/ManualPaymentSettlementService.php`
  - reads the exact stored obligation amount/currency; the browser never supplies either;
  - records provider-neutral `manual` success evidence through `CommercialPaymentService`, so the
    canonical purchase, entitlement, funding, capacity and activation path is unchanged;
  - refuses an already-settled obligation, a closed/expired offer, a future receipt date and missing
    evidence;
  - supports both standard-course obligations and paid introductory obligations.
- `platform/src/Core/Application/Finance/Intro/IntroPaymentService.php`
  - new `recordManualSettlement()` with its own command idempotency and append-only event;
  - reuses the paid obligation transition and the existing booking projection;
  - a post-commit projection failure is reported as pending, never as a lost payment.
- `platform/src/Admin/Controller/FinanceAdminController.php`
  - `admin_post_dzn_finance_manual_payment`, nonce-checked and capability-gated;
  - Outstanding payments table with a receipt reference and received date;
  - Recent settlements table, including manual payments without a Checkout attempt.
- `platform/src/Admin/Controller/StaffAccessController.php`
  - the Finance bundle now includes `dzn_manage_payment_execution`,
    `dzn_ingest_commercial_payment_evidence`, `dzn_manage_finance_statements`,
    `dzn_view_cash_ledger` and `dzn_manage_cash_ledger`.

### 3.2 Statement issuance

- `FinanceAdminController` now registers `admin_post_dzn_finance_statement` and exposes:
  - draft from an inclusive UTC date range;
  - issue (the existing fail-closed gate);
  - withdraw a draft;
  - supersede an issued statement.
- Every action requires `dzn_manage_finance_statements`, a nonce and a fresh idempotency key.
- The Statements tab warns when the timezone policy is unset, because a draft records the policy
  version in force at drafting.

### 3.3 Correct payment and reconciliation truth

- A completed Checkout no longer implies settlement in the admin payment list.
- Payment cards distinguish **Checkout complete** from **Settled**.
- Manual payments appear in Recent settlements even without a Checkout attempt.
- The ledger no longer fabricates fees or a counterparty and reports the last recorded statement
  difference rather than a blanket "reconciled".

### 3.4 Monthly statements, branded email and refunds

- `TeacherStatementBatchService` finds every Teacher with active Lesson work in a completed UTC month,
  drafts through the recorded statement authority, issues when the gate passes, and isolates failures
  per Teacher. The Statements tab now has a preview, an issue/email batch form and a per-Teacher result
  table.
- `TeacherStatementEmailRenderer` and `TeacherStatementEmailService` deliver a branded, table-based,
  inline-styled statement to the Teacher's recorded address. The email is issued-only, script-free,
  remote-font-free and recorded as an `emailed`/`email_failed` statement event.
- `StatementTemplateRule` now ships the Delnavazan palette and Vazirmatn-first typography, with lesson
  duration, base pay, golden-hour bonus and final amount columns. The web statement loads the
  theme-local font through a trusted font-face outside the editable CSS.
- `ManualRefundService` records administrator-only refund evidence capped by the settled balance and
  opens the existing refund-review case. The Payments tab exposes the refund/review lifecycle. It does
  not rewrite settlement, funding, Lessons or statements, and moving money remains external and manual.

### 3.5 Documentation

- `platform/docs/FINANCE-ADMINISTRATION.md` now documents offline completion, the statement
  lifecycle, the new capabilities and the independence of the manual ledger.
- `platform/tests/finance-payment-completion-contract.php` is the new source contract.

## 4. Verification performed

- `php tests/static.php` — pass.
- `php tests/finance-administration-contract.php` — pass.
- `php tests/finance-payment-completion-contract.php` — pass.
- `php tests/finance-pricing-unit.php` — pass.
- `php tests/finance-statement-template-unit.php` — pass.
- `php tests/intro-payment-unit.php` — pass.
- `php tests/golden-hour-pay-contract.php` — pass.
- `php tests/teacher-portal-ux-finance-contract.php` — pass.
- The complete WordPress-free suite listed by `platform/runtime/bin/run-pure-tests.sh` — pass.
- Disposable WordPress/MariaDB finance runtime (`run-finance-runtime.sh`, fresh install to Schema 57)
  — pass, including the Statements tab draft form and unset-timezone warning.
- Disposable WordPress/MariaDB manual-payment runtime
  (`run-finance-manual-payment-runtime.sh`, canonical Phase-R1 offer fixture) — pass:
  - a manual payment creates exactly one settlement and one entitlement;
  - the first instalment funds Lessons 1–6, the second funds the full 12-session Term;
  - a duplicate settlement of the same obligation is refused and creates no second row;
  - accepted manual evidence is recorded with `provider_key=manual`.
- Branded statement email unit (`teacher-statement-email-unit.php`) — pass: palette, RTL/LTR,
  duration/base/bonus/total, no template placeholders, no remote font/CSS, plain-text alternative.
- Refund/batch source contract (`finance-refund-batch-contract.php`) — pass.
- The manual-payment runtime also proves a full administrator refund: accepted `manual` refund
  evidence, an open review case, over-refund refusal, route → resolve with a decision note.
- The finance runtime renders the monthly batch generator and executes a batch without error.

## 5. Remaining gaps and recommendations

The sequenced plan is in `FINANCE-GAP-CLOSURE-PLAN-2026-11-01.md`. The items below are the remaining
product decisions; the operational gaps in the original audit are closed.

1. **Dedicated payment receipt (optional).** Manual and Stripe settlements both trigger the existing
   post-settlement activation hook. A separate receipt notification would be a new product message for
   both paths, not a manual-only fix.
2. **Manual settlement without an offer.** The current model requires an issued offer and obligation.
   If the academy wants to record a payment before an offer exists, that needs an explicit
   account-credit authority, not a bypass of the obligation model.
3. **Split/partial payments.** The canonical authority requires the full obligation amount. Supporting
   partial payment would be a commercial-model change, not a UI change.
4. **Student Payments flag.** Keep `DZN_THEME_STUDENT_PAYMENTS` off until the owner approves the
   return flow; otherwise the Student sees the "payments disabled" message even after an admin
   settlement.
5. **Refund workflow.** The existing refund-review authority should get a guarded admin action if the
   academy wants to record and act on refunds from Finance.
6. **Ledger linkage.** Keep the ledger manual. If the academy wants per-Student bookkeeping, add an
   explicit optional settlement reference on the cashbook entry; do not auto-write the ledger from a
   payment.
