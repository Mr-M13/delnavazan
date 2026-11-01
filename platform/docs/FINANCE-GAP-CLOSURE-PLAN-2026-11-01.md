# Finance gap-closure plan and statement design

**Date:** 2026-11-01
**Companion audit:** `FINANCE-AUDIT-2026-11-01.md`
**Status:** the operational gaps in this plan are implemented in the current tree; the remaining
product decisions are sequenced below.

---

## 1. Objective

Close the Finance gaps between the recorded commercial/statement authority and the work an academy
administrator actually performs:

1. mark an offline payment complete without inventing a second payment authority;
2. issue a statement for every Teacher who worked in a month, including the pay detail;
3. email a branded statement that looks like Delnavazan;
4. record, review and resolve a refund manually, administrator-only;
5. leave the manual Central Ledger and the academic funding authority honest and separate.

## 2. Delivered in this change

| Capability | Implementation |
| --- | --- |
| Offline payment completion | `ManualPaymentSettlementService` + Payments → Outstanding payments |
| Manual introductory payment | `IntroPaymentService::recordManualSettlement()` |
| Single-Teacher statement lifecycle | Draft / Issue / Withdraw / Supersede in the Statements tab |
| Monthly batch for all Teachers with work | `TeacherStatementBatchService` + monthly preview and result table |
| Branded statement web/print design | `StatementTemplateRule` default template |
| Branded statement email | `TeacherStatementEmailRenderer` + `TeacherStatementEmailService` |
| Manual refund evidence | `ManualRefundService` |
| Refund review lifecycle | Route / Resolve / Dismiss in Payments → Manual refunds |
| Refund reporting | Refunded/remaining amounts in Recent settlements and review cases |

## 3. Statement design specification

### 3.1 Palette

The statement reuses the Academy palette from `theme.json` and `theme.css`:

| Role | Hex | Use |
| --- | --- | --- |
| Ivory | `#FBF8F2` | page/email background |
| Surface | `#F2ECE1` | quiet blocks |
| Card | `#FFFFFF` | statement card |
| Ink | `#252726` | primary text |
| Deep teal | `#104A4D` | headings, table header, total band |
| Primary teal | `#1E7B70` | eyebrow text, links |
| Pomegranate | `#A33E48` | top brand rule / accent |
| Saffron | `#D4AB4F` | teacher strip and golden-hour emphasis |
| Muted | `#5F625F` | metadata |
| Border | `#DDD6CA` | table/rule borders |

### 3.2 Typography

- **Web/print:** `Vazirmatn, Tahoma, Arial, sans-serif`. The web statement loads the theme-local
  `Vazirmatn-Variable.woff2` through a trusted `@font-face` injected outside the editable template CSS.
- **Email:** `Vazirmatn, Tahoma, 'Segoe UI', Arial, sans-serif`. No external font request is made; email
  clients use the local Vazirmatn if installed, otherwise Tahoma/Segoe UI. This is deliberate: remote
  fonts are blocked by Gmail/Outlook and would make the statement inconsistent.

### 3.3 Structure

1. **Header** — logo, academy name, “Teacher compensation statement”, period, statement number/version.
2. **Summary band** — total payable, payable lesson count, total lesson count.
3. **Teacher strip** — name, reference, currency.
4. **Lesson table** — date, course, duration, base rate, golden-hour bonus, final amount, status.
5. **Total band** — the recorded payable total in deep teal.
6. **Boundary note** — “lesson compensation only; not a payout receipt, tax invoice or bank instruction”.
7. **Footer** — statement identity, generated instant, support address and site.

The email version is a fixed 640px, table-based, inline-styled layout with the same structure. It is
not driven by the admin-editable template, so an administrator cannot introduce arbitrary HTML into an
email. The web/print version remains editable for presentation only.

## 4. Monthly batch mechanism

### 4.1 Candidate discovery

`FinanceStatementRepository::teachersWithWork(start, end)` finds Teachers with at least one canonical
occurrence anchored in the UTC month. `TeacherStatementBatchService::preview()` then re-reads each
Teacher's authoritative period set with `TeacherStatementService::periodLessons()` and only keeps
Teachers with at least one active (non-archived) Lesson.

### 4.2 Per-Teacher execution

For each candidate:

1. If an exact live draft exists and “issue” is selected → issue it.
2. If an exact live issued statement exists → skip; send the email only if it was not emailed before.
3. If any other live statement overlaps the period → skip with `statement_period_overlap`.
4. Otherwise → draft one statement from recorded snapshots/payability, then optionally issue and email.

Each step uses the existing statement command key and lock. One Teacher's failure is recorded in the
result row and never aborts the batch.

### 4.3 Batch result

The POST redirect stores a 15-minute per-user transient. The Statements tab renders:

- total Teachers, drafted, issued, skipped, failed and emailed;
- per-Teacher state, statement id, reason and email outcome;
- no silent retry: a later batch skips already-issued statements and only sends a missing email once.

### 4.4 Scheduling recommendation

The batch remains **operator-triggered** by default. A future phase can add a monthly cron that:

1. runs in “draft only” mode on the first day of the following month;
2. notifies the administrator to review;
3. runs “issue + email” only after an explicit administrator confirmation.

This keeps a human in front of the financial statement.

## 5. Manual refund design

- **Authority:** `dzn_manage_refund_reviews`, held by the administrator role only. It is not part of the
  Finance staff bundle.
- **Evidence:** `ManualRefundService` records accepted `manual` refund evidence through
  `CommercialPaymentService`; the amount/currency are capped by the settled obligation and existing
  accepted refund evidence.
- **Review:** `RefundReviewService::recordRefundEvidence` opens one review case per evidence row.
  The administrator can route it, resolve it with a note, or dismiss it.
- **No silent academic change:** the settlement, purchase, entitlement, funding plan, Lessons and
  statements are never rewritten by a refund. If the academy wants a funded-session clawback or term
  cancellation, that is a separate explicit product decision.
- **Money movement:** manual and external. This page records the evidence and the decision trail; it
  does not call Stripe or a bank.
- **Ledger:** the Central Ledger remains manual. The administrator can record the corresponding cash
  outflow separately if the academy wants it in the cashbook.

## 6. Remaining product decisions and sequencing

| Priority | Gap | Design / next step | Acceptance |
| --- | --- | --- | --- |
| P1 | Student payment history is hidden when the checkout flag is off | Split the release flag into read-only history and checkout. Show settled/outstanding obligations and receipts without enabling online checkout. | A Student sees their own paid history while `DZN_THEME_STUDENT_PAYMENTS` remains off. |
| P1 | Dedicated payment/receipt notification | Add a `payment.received.student` catalogue event and template for both Stripe and manual settlement. | One branded receipt per accepted settlement; no duplicate on replay. |
| P1 | Refund notification | Add `payment.refunded.student` with amount, currency and review status. | One message per accepted refund evidence; no message for a dismissed review unless the product says so. |
| P2 | Split / partial payments | Extend the commercial model to multiple settlements per obligation, prorating funding and activation. This is a schema/authority change, not a UI change. | A partial settlement leaves the obligation outstanding and funding reflects only the settled portion. |
| P2 | Refund academic consequence | Record an explicit policy: no clawback, pause remaining Lessons, or cancel the Term. Do not infer it. | A resolved refund case carries the chosen consequence version and an audit event. |
| P2 | Batch cron | Draft-only monthly run + admin confirm-issue step. | A failed month is visible, retryable and never auto-emails an unreviewed statement. |
| P3 | Email delivery/bounce tracking | Extend statement email events with a provider message id and delivery/bounce outcome. | An administrator can see delivered/failed without opening the mail provider. |
| P3 | Ledger linkage | Optional `settlement_id`/`refund_evidence_id` reference on a manual cashbook entry. | A ledger entry can cite a settlement/refund without changing either authority. |
| P3 | Financial reporting export | CSV/XLSX of monthly settlements, refunds, teacher statements and ledger activity. | Exact minor units and source references, no derived totals without labels. |
| P3 | Tax/withholding | Explicit policy and statement line types, not a silent deduction. | Withholding appears as its own recorded line and is visible to the Teacher. |

## 7. Acceptance for this change

- Manual payment: source contract + disposable WordPress/MariaDB runtime (single settlement,
  full-term funding, duplicate refusal, introductory settlement).
- Refund: same runtime (full refund, provider `manual`, review open → route → resolve, over-refund
  refusal).
- Statements: finance runtime renders the monthly batch generator and processes a batch without error;
  the existing Phase-U statement runtime remains the proof of draft/issue/supersede totals.
- Email: pure unit proof of the brand palette, Persian/English direction, pay detail, empty-template
  safety, no remote font/CSS, and the plain-text alternative.
- All WordPress-free contracts and the static lint pass.
