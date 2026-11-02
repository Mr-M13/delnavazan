# Finance clarity redesign — panel, action and policy map

**Date:** 2026-11-02
**Branch:** `feat/finance-administration-redesign-20261102`
**Base:** integrated Overview/Finance/Enrolment candidate (`feat/admin-overview-finance-classroom-20261102`)
**Purpose:** map every current Finance panel and write action to its new location before coding, and record the policy/data-model decisions this change relies on.

## 1. Visible navigation

| Old visible label | Old tab parameter | New visible label | Canonical parameter | Compatibility aliases |
| --- | --- | --- | --- | --- |
| Payments | `payments` | **Student payments** | `payments` | `student-payments`, `student_payments` |
| Teacher Statements | `statements` | **Teacher payments** | `statements` | `teacher-payments`, `teacher_payments`, `teacher-statements`, `teacher_statements` |
| Central Ledger | `ledger` | **Manual ledger** | `ledger` | `manual-ledger`, `manual_ledger`, `central-ledger`, `central_ledger` |
| Settings | `settings` | **Settings** | `settings` | — |

The old protected direct routes (`dzn-payment-execution`, `dzn-finance-statements`, `dzn-finance-cashbook`, `dzn-finance-rates`, `dzn-finance-policies`, `dzn-stripe-settings`) remain registered. The visible submenu still has one Finance destination. Bookmarks using the old tab parameters resolve to the same canonical tab.

## 2. Student payments

| Current panel / action | New location | Notes |
| --- | --- | --- |
| Payment state cards (attempts, pending, checkout complete, failed, settled, refund evidence) | Student payments → summary | Kept, with **checkout complete** still distinct from **settled**. |
| "This month at a glance" | Student payments → summary strip | New: confirmed receipts, outstanding due this month, expected due next month, by currency and source. |
| Payments needing attention | Student payments → immediately below summary | New: failed attempts, completed-but-unsettled checkouts, overdue obligations, open refund cases, interrupted refund reservations, pending payability. An urgent refund exception is shown here and is not hidden lower. |
| Active-student payment table | Student payments → primary table after attention | New: searchable/sortable; enrolment and course, teacher, term and renewals, plan/method, confirmed paid, outstanding, next due, days/overdue, discounts, replacements. Row opens the exact student/offer/obligation detail. |
| Exact student/obligation detail | `?tab=payments&student_id=…&offer_uid=…#student-payment-detail` | New read-only detail with obligations, settlements, discounts and replacement count. "Open student profile" links to the Students workspace. |
| Checkout attempts table | Student payments → collapsed **Checkout attempts and payment records** | Kept for investigation; explicitly not settlement. |
| Outstanding payments + manual settlement (`dzn_finance_manual_payment`) | Student payments → **Outstanding payments** after recent settlements | Existing canonical offline settlement and authority preserved. |
| Recent settlements | Student payments → after active students | Existing read model, including manual payments with no checkout attempt. |
| Instalment schedules | Student payments → after outstanding payments | Existing read-only schedule authority preserved. |
| Discount codes (`dzn_finance_promotion`) | Student payments → **Discount codes** | Existing capability and snapshot behaviour preserved; placed before refunds. |
| Student receipts report | Student payments → after discounts, before refunds | New: calendar month or custom range, gross/refunds/net by currency, Stripe vs recorded-offline source, Asia/Tehran date basis, CSV download. Not a tax invoice. |
| Manual refunds / refund review cases (`dzn_finance_refund`, `dzn_finance_refund_review`, `dzn_finance_refund_release`) | Student payments → lower **Manual refunds** | Manual/offline path kept separate. Urgent exceptions surface at the top. |
| Integrated Stripe refund | Student payments → **Manual refunds → implementation assessment** | No action is exposed. Assessment explains why the retained evidence is insufficient. |
| Introductory lesson payments | Student payments → bottom, collapsed | Kept, with free-mode notice. Paid-intro support remains. |

## 3. Teacher payments

| Current panel / action | New location | Notes |
| --- | --- | --- |
| Teacher summary / compensation overview | Teacher payments → top searchable table | New: completed classes and hours for current and previous Persian month, base rate, golden-hour bonus, earned, paid, latest payment date, latest statement status. Earned and paid are separate columns. |
| Generate statements for every Teacher who worked in a month | Teacher payments → **Generate teacher statements** | Main action. Preview → Generate drafts → Review → Issue/publish → Send. |
| Monthly batch (`dzn_finance_statement_batch`) | Teacher payments → workflow | Legacy action retained; new UI posts `dzn_finance_statement_workflow` with separate operations. |
| Preview eligible teachers | Teacher payments → preview table | Includes Lesson/payable/pending counts, applied rate versions, bonus, total, missing evidence and existing statement. |
| Preview / generate / issue / email | Teacher payments → separate write actions | Generating never issues or emails. Only issued statements can be sent. |
| Send to teachers | Teacher payments → issued result panel | Enqueues each issued statement to its own recorded Teacher address. |
| Send batch summary to staff | Teacher payments → issued result panel | Sends an administrative summary only; never attaches every private statement. |
| Batch history | Teacher payments → collapsed **Statement batch history** | Period, counts, currency totals, state, individual failures, preview/retry. |
| Draft a statement for a completed period | Removed | Capability is available through Generate teacher statements; individual review remains. |
| Individual statement history, issue, withdraw, supersede, record payment | Teacher payments → collapsed **Individual statement review** | Kept for oversight and compatibility; individual rate/statement work also remains in the Teachers profile. |
| Statement timezone warning | Teacher payments → individual review | Kept. |
| Statement email / retry | Teacher payments → individual review and send action | Durable outbox preserved; queued/transport-accepted/failed/delivered are only shown from recorded evidence. |
| Statement template and placeholders | Teacher payments → collapsed **Statement template and sample** | Sample and placeholders collapsed; approved theme logo is the delivered default. |
| Persian-month draft-only schedule (`dzn_finance_statement_schedule`) | Teacher payments → collapsed **Persian-month draft-only schedule** | Now actually uses Asia/Tehran Persian month boundaries and idempotent Persian month run keys. |
| Statement CSV export / print / download | Teacher payments footer and preview | Preserved. |

## 4. Manual ledger

| Current panel / action | New location | Notes |
| --- | --- | --- |
| Iran and Australia balance cards | Manual ledger → top | Separate currencies; same manual cashbook calculation. |
| Ledger filters / transaction history | Manual ledger | Preserved. |
| Manual entry quick form (`dzn_cashbook_record`) | Manual ledger | Preserved authority and nonce. |
| Full cashbook link | Manual ledger | Preserved. |

The ledger is not derived from Stripe, student receipts, refunds, teacher payments or teacher rates. Overview balances remain read-only views of the same ledger calculation.

## 5. Settings

| Current panel / action | New location | Notes |
| --- | --- | --- |
| Finance summary | Settings → top | Confirmed receipts this month, outstanding due this month, expected due next month; each by currency and source. Expected comes from existing obligations. |
| Finance review queues | Settings → after summary | Preserved read-only links. |
| Per-region pricing cards (`save_regional_price`) | Settings → **Regional pricing** one form | Shared introductory mode, one effective date, compact per-region standard price rows, paid-only introductory fields, client preview. New `save_regional_pricing_bundle` validates all rows first. Legacy `save_regional_price` still accepted. |
| Teacher global default (`save_global_teacher_rate`) | Settings → Teacher compensation → Global default | Preserved effective-dated rate versions and inheritance. |
| Teacher-specific rate (`save_teacher_rate`) | Settings → Teacher compensation → Teacher-specific rate | Preserved. Course-specific override moved into **Advanced**. |
| Teacher rate history/resume | Settings → Teacher compensation → current rate and timeline | Preserved. |
| Teacher payability for introductory lessons | Settings → Teacher compensation → one checkbox (`save_intro_payability`) | Distinct from student introductory pricing. Changing one does not change the other. |
| Golden-hour policy | Settings → **Golden-hour pay bonus · Iran** editable rows | Rows carry weekdays, local start/finish, bonus percentage and effective date. Add row, copy to days, edit (new version) and retire. Overnight and overlap validation. |
| Golden-hour history | Settings → collapsed history | Prior policy versions retained. |
| Golden-hour coverage charts | Settings → immediately below active rules | Uses the existing `AcademyCoverageController::renderAtlas()` calculation. |
| Finance policies (`save_finance_policy`) | Settings → Finance policies | Preserved, including `INTRO_PAYABILITY_POLICY` audit table. |
| Commercial timing policies (`save_commercial_policy`) | Settings → Commercial timing and settlement policies | Preserved, except booking horizon. |
| Booking horizon (`INTRO_BOOKING_HORIZON`) | **General Settings → Booking horizon** (`dzn_general_booking_horizon`) | Moved because it is an academy scheduling rule. Existing commercial-policy authority is reused. |
| Stripe integration | Settings → collapsed **Stripe integration and safeguards** | Existing test/live separation, encryption, account binding, webhook verification and worker restrictions preserved. |

## 6. Policy and data-model decisions

1. **No new schema is required.** Existing tables are reused:
   - `finance_regional_prices` and `commercial_prices` for regional pricing;
   - `finance_settings` for statement templates and golden-hour policy versions;
   - `finance_teacher_rates` for effective-dated teacher rates;
   - `commercial_offers`, `commercial_offer_obligations`, `commercial_obligation_settlements`, `commercial_payment_evidence` for student payment truth;
   - `finance_lesson_snapshots`, `finance_payability_evaluations`, `finance_golden_hour_details`, `finance_statement_lines`, `finance_statements` and `finance_statement_events` for teacher compensation;
   - `finance_statement_batch_runs` for durable batch history;
   - `finance_manual_refund_reservations`, `refund_review_cases` for refunds;
   - `finance_cash_*` for the manual ledger.
2. **Golden-hour rows are an additive shape inside the existing policy JSON.** The new `rules[]` array sits alongside the legacy `periods`, `weekdays` and `bonus_basis_points` fields. The pay calculator reads `rules[]` when present and falls back to the legacy shape for historical versions. Committed lesson details are never rewritten.
3. **Regional bundle is a validated versioned operation, not a second price authority.** `previewBundle()` validates every region first; `saveBundle()` calls the existing `saveRegion()` writer per region, which supersedes only the current row and preserves historical offers/payments.
4. **Persian scheduling is a real boundary change, not a label.** The scheduler now uses `PersianCalendar` and Asia/Tehran half-open boundaries. It runs draft-only and is idempotent by `persian_month:YYYY-MM`. Existing UTC run history is retained; no historical statement is relabelled.
5. **Receipts report is append-only reporting.** It reads accepted settlement/refund evidence and never derives a new payment, refund or ledger entry. It uses the recorded settlement/received date and accepted refund occurrence date, with Asia/Tehran boundaries and one total per currency.
6. **Stripe refund is assessment-only.** See `FINANCE-STRIPE-REFUND-ASSESSMENT-2026-11-02.md`. No live refund capability is added.
7. **Booking horizon moves to General Settings.** It reuses `CommercialPolicyService::set('INTRO_BOOKING_HORIZON', …)` and the existing commercial-policy capability.
8. **Capabilities, nonces, audit events and effective-dated history are preserved.** New writes use the existing narrow capabilities and nonces; no new capability is introduced.

## 7. Action inventory after this change

| Hook | New purpose | Capability |
| --- | --- | --- |
| `admin_post_dzn_finance_settings` | Regional bundle, teacher rates, intro payability, golden rows, templates, policies | Existing per-operation capabilities |
| `admin_post_dzn_finance_promotion` | Discount code create/deactivate | `dzn_manage_commercial_promotions` |
| `admin_post_dzn_finance_manual_payment` | Offline settlement | `dzn_manage_payment_execution` + evidence intake |
| `admin_post_dzn_finance_statement` | Legacy single-statement lifecycle | `dzn_manage_finance_statements` |
| `admin_post_dzn_finance_statement_email` | Single issued statement email/retry | `dzn_manage_finance_statements` |
| `admin_post_dzn_finance_statement_batch` | Legacy monthly batch | `dzn_manage_finance_statements` |
| `admin_post_dzn_finance_statement_workflow` | Previewed draft/issue/send workflow | `dzn_manage_finance_statements` |
| `admin_post_dzn_finance_statement_staff_summary` | Administrative batch summary to one staff address | `dzn_manage_finance_statements` |
| `admin_post_dzn_finance_statement_schedule` | Persian-month schedule enable/disable/run | `dzn_manage_finance_statements` |
| `admin_post_dzn_finance_receipts_report` | CSV receipts report download | `dzn_view_payment_execution_authority` (+ refund view for refund rows) |
| `admin_post_dzn_finance_refund` / `_review` / `_release` | Manual refund and review lifecycle | `dzn_manage_refund_reviews` |
| `admin_post_dzn_finance_export` | Payments/refunds/statements CSV | Existing export capabilities |
| `admin_post_dzn_finance_statement_export` | Print/download statement HTML | `dzn_view_finance_authority` |
| `admin_post_dzn_general_booking_horizon` | Record booking horizon version | `dzn_manage_commercial_policies` |
| `admin_post_dzn_cashbook_record` | Manual ledger entry | `dzn_manage_cash_ledger` |
