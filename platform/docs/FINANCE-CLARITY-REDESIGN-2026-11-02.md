# Finance clarity redesign — implementation, verification and limitations

**Date:** 2026-11-02
**Branch:** `feat/finance-administration-redesign-20261102`
**Base:** `feat/admin-overview-finance-classroom-20261102`
**Status:** implementation candidate; not merged and not deployed.
**Companion map:** `FINANCE-REDESIGN-MAP-2026-11-02.md`
**Stripe refund assessment:** `FINANCE-STRIPE-REFUND-ASSESSMENT-2026-11-02.md`

## 1. What changed

- Four visible Finance tabs, in order: **Student payments**, **Teacher payments**, **Manual ledger**, **Settings**.
- Canonical tab parameters remain `payments`, `statements`, `ledger`, `settings`; old/human aliases (`student-payments`, `teacher-statements`, `teacher-payments`, `central-ledger`, `manual-ledger`, …) still resolve.
- Student payments:
  - sourced monthly summary;
  - payments needing attention (including an urgent refund exception);
  - searchable/sortable active-student table and exact student/offer/obligation detail;
  - recent settlements and outstanding payments;
  - instalment schedules and discount codes higher;
  - student receipts report with calendar-month/custom-range CSV;
  - manual refunds and Stripe-refund assessment lower;
  - introductory payments at the bottom in a collapsed free-mode section.
- Teacher payments:
  - searchable teacher summary table for current/previous Persian month, with earned and paid separate;
  - reviewed workflow Preview → Generate drafts → Review → Issue/publish → Send;
  - separate Send to teachers and Send batch summary to staff;
  - collapsible batch history with retry and individual failures;
  - individual statement review retained, standalone draft panel removed;
  - statement template/sample/placeholders collapsed; approved theme logo supplied by default;
  - Persian-month draft-only scheduler using Asia/Tehran boundaries and idempotent run keys.
- Manual ledger: unchanged manual authority, separate currencies, independent from online payments, receipts and teacher payments.
- Settings:
  - finance summary at top;
  - one validated regional-pricing form with shared intro mode/effective date and preview;
  - simplified teacher compensation plus advanced course override and intro-payability checkbox;
  - editable golden-hour rows with copy/retire, overnight support, overlap refusal, collapsed history and existing coverage charts;
  - booking horizon moved to General Settings;
  - Stripe integration collapsed with its existing safeguards.
- No schema migration was added. Existing tables and authorities are reused. Golden-hour `rules[]` is an additive shape inside the existing policy JSON, with a legacy fallback.

## 2. New code

| File | Purpose |
| --- | --- |
| `src/Core/Support/PersianCalendar.php` | Pure Jalali/Gregorian conversion, leap/month lengths and Asia/Tehran half-open month ranges. |
| `src/Core/Application/Finance/Read/FinanceAdminRedesignReadService.php` | Active-student payment rows/detail, attention projection, teacher summary, sourced finance summary. |
| `src/Core/Application/Finance/Reporting/StudentReceiptsReportService.php` | Confirmed receipts + accepted refunds report, currency/source totals and CSV. |
| `src/Core/Application/Finance/Reporting/StripeRefundAssessmentService.php` | Read-only Stripe-refund feasibility assessment; no provider call, no refund. |
| `src/Core/Application/Finance/Statements/TeacherStatementBatchWorkflowService.php` | Draft/issue/send/staff-summary workflow and durable batch history. |
| `src/Admin/Controller/GeneralSettingsController.php` | Booking-horizon form and versioned action. |
| `src/Admin/Controller/FinanceAdminController.php` | Four-tab shell, new panels, guarded actions, summary/report/workflow UI. |
| `src/Core/Application/Finance/Statements/TeacherStatementBatchService.php` | Persian/custom completed periods and preview detail. |
| `src/Core/Application/Finance/Statements/TeacherStatementScheduleService.php` | Persian-month draft-only scheduler. |
| `src/Core/Application/Finance/GoldenHourRule.php`, `GoldenHourPolicyService.php`, `GoldenHourPayService.php` | Editable per-row golden-hour rules, overlap validation and calculation. |
| `src/Core/Application/Finance/Pricing/RegionalPricingService.php` | Whole-form preview/validation/save through the existing versioned writer. |
| `src/Core/Application/Finance/FinanceSettingsService.php` | Approved theme logo fallback. |

## 3. Verification

### Pure/source contracts (all pass)

- `tests/static.php`
- `tests/finance-administration-contract.php`
- `tests/finance-clarity-redesign-contract.php` (new)
- `tests/persian-calendar-unit.php` (new)
- `tests/golden-hour-rules-unit.php` (new)
- `tests/golden-hour-pay-unit.php`
- `tests/golden-hour-pay-contract.php`
- `tests/finance-pricing-unit.php`
- `tests/finance-statement-template-unit.php`
- `tests/intro-payment-unit.php`
- `tests/finance-payment-completion-contract.php`
- `tests/finance-refund-batch-contract.php`
- `tests/finance-schedule-export-contract.php`
- `tests/teacher-statement-email-unit.php`
- `tests/admin-navigation-target-contract.php`
- `tests/admin-navigation-teacher-finance-contract.php`
- `tests/admin-dashboard-finance-classroom-contract.php`

### Disposable WordPress/MariaDB runtime

A disposable WordPress + MariaDB runtime was installed from this worktree. The actual four-tab screen rendered without a PHP fatal/notice on all four tabs; tab labels/order and legacy tab aliases were checked by rendering the page. Synthetic fixture rows were then used to exercise:

- active-student payment rows: confirmed paid from an accepted settlement, instalment outstanding, next due, days until due, discount, manual/offline source; confirmed paid never came from a checkout attempt;
- student detail: exact student/offer/obligation, obligations, settlements;
- monthly summary: receipts by currency, outstanding due this month, expected due next month, all labelled by source;
- receipts report: custom Asia/Tehran range, gross AUD 122.50 / refunds AUD 50.00 / net AUD 72.50, Stripe vs recorded-offline sources, clean CSV;
- teacher summary: current Persian-month completed class and minutes, earned base + golden-hour bonus, paid separate, no statement;
- Stripe refund assessment: explicitly `eligible=false` with the evidence reasons; assessment made no provider call;
- Persian scheduler: previous Persian month 1405-07, draft-only run, second run returned `already_completed`;
- batch workflow: draft run persisted in `finance_statement_batch_runs` and appeared in history;
- regional bundle: preview and save produced the expected AU standard/introductory values;
- golden-hour policy: row save and latest read retained per-row bonus/effective date;
- General Settings: booking-horizon form rendered with its action.

No live Stripe refund, charge or teacher email was made. Email/failed-refund paths were exercised at the source/unit level only; transport was not invoked.

### Visual evidence

Desktop and mobile screenshots of all four tabs are in:

`docs/evidence/finance-redesign-2026-11-02/`

- `desktop-{payments,statements,ledger,settings}.png` — readable viewport captures.
- `desktop-{payments,statements,ledger,settings}-full.png` — full-page captures.
- `mobile-{payments,statements,ledger,settings}.png` — mobile viewport captures.
- `mobile-{payments,statements,ledger,settings}-full.png` — mobile full-page captures.

The screenshots use synthetic `…@example.invalid` / `phase-2a2j.invalid` data in the disposable local environment.

## 4. Requirement checklist

| Requirement | Status | Evidence / note |
| --- | --- | --- |
| Isolated branch | Done | `feat/finance-administration-redesign-20261102` in a separate worktree. |
| Inspect Finance, statements, pricing, ledger, Stripe before changing | Done | `FINANCE-REDESIGN-MAP-2026-11-02.md`; existing authorities preserved. |
| Exactly four tabs, order Student payments / Teacher payments / Manual ledger / Settings | Done | Source contract + rendered HTML + screenshots. |
| Old protected URLs and tab parameters keep working | Done | Canonical values unchanged; aliases tested by rendering `teacher-payments`, `teacher-statements`, `central-ledger`, `manual-ledger`, `student-payments`. |
| Preserve visual design where it works | Done | Existing admin shell/cards/tables reused; new styles are additive. |
| Student summary | Done | Counts + sourced monthly summary; screenshot. |
| Payments needing attention | Done | Failed/unsettled/overdue/refund/payability projection; urgent refund notice. |
| Active-student table with required columns | Done | Search/sort, enrolment/course, teacher, term/renewals, plan/method, paid, outstanding, due/days, discounts, replacements. |
| Open exact student and obligation | Done | Row links to `student_id` + `offer_uid` detail; runtime detail render checked. |
| Confirmed settlement only; checkout attempt never received money | Done | `confirmed_paid_minor` reads settlements; checkout table is separate/collapsed. |
| Instalments and discounts higher | Done | Order: recent settlements → outstanding → checkout attempts → instalments → discounts. |
| Refund form/review lower; urgent exception visible | Done | Attention notice at top; full workspace lower. |
| Intro payments bottom, collapsed while free; paid support retained | Done | Free-mode detection; paid content and manual intro settlement retained. |
| Integrated Stripe refund investigated | Done | `FINANCE-STRIPE-REFUND-ASSESSMENT-2026-11-02.md`. |
| Stripe refund implementation assessment first | Done | Assessment service + UI panel; no action. |
| No false Stripe success | Done | No refund API call; assessment only. |
| Manual/offline refunds separate | Done | Existing manual refund/review authority unchanged. |
| Both refund paths out of manual ledger unless explicit | Done | Ledger untouched; docs and UI state separation. |
| Student receipts report (month/custom) | Done | `StudentReceiptsReportService`; calendar month/custom range form. |
| Gross/refunds/net by currency | Done | Totals and source breakdown per currency; never combined. |
| Stripe vs recorded offline | Done | Source key/label and source table. |
| Downloadable auditable report | Done | CSV with metadata, basis, totals and transaction rows; runtime CSV checked. |
| Timezone/date basis label | Done | Asia/Tehran, recorded settlement/received vs accepted refund occurrence. |
| Not called tax invoice | Done | Explicit note in UI and CSV. |
| Teacher summary table | Done | Current/previous Persian months, rate, bonus, earned, paid, latest payment/statement. |
| Earned and paid separate | Done | Separate columns and source facts. |
| Generate statements main action | Done | Preview/generate workflow is the first teacher action. |
| Persian month or custom completed range | Done | `persianPeriod()` and `customPeriod()`. |
| Preview teachers/lines/rates/bonuses/totals/missing/existing | Done | Preview table and `previewPeriod()` detail. |
| Canonical statement authority and issuance gates | Done | `TeacherStatementService::draft/issue` used unchanged. |
| Failed teacher isolated | Done | Per-teacher try/catch and result rows; history records failures. |
| Preview → Generate drafts → Review → Issue → Send | Done | Separate actions; generating never emails. |
| Only issued statements to portal/email | Done | Email service refuses non-issued; send action filters. |
| Queued/sent/failed/delivered only with evidence | Done | Durable outbox states; delivery not claimed without downstream fact. |
| Safe retry without duplicates | Done | Per-statement email intent uniqueness and batch retry keys. |
| Individual rate/statement management in Teachers profile | Preserved | Existing profile routes untouched; Finance keeps individual review. |
| Persian-month scheduler at month start, previous completed month | Done | `PersianCalendar::previousMonthForInstant`; Asia/Tehran boundaries; draft-only. |
| Idempotent scheduled job | Done | `persian_month:YYYY-MM` run key; second run `already_completed`. |
| Staff sees batch ready for review/sending | Done | History + workflow result; schedule is draft-only. |
| No UTC scheduler relabelled as Persian | Done | Scheduler calculations changed to PersianCalendar; legacy UTC month entry point retained only as explicit compatibility. |
| Test Nowruz, month lengths, leap years, timezone boundaries | Done | `persian-calendar-unit.php`. |
| Send to teachers vs staff summary separated | Done | Separate forms/actions; staff summary contains aggregates, not private statements. |
| Collapsible batch history with counts/totals/status/failures/retry | Done | `renderStatementHistory()`. |
| Remove standalone draft panel | Done | Removed; individual review retained. |
| Sample/placeholders collapsed; approved logo | Done | Template sample/placeholders in `<details>`; theme logo fallback. |
| Manual ledger balances/history/authority | Preserved | No change to cashbook authority; labels/order updated. |
| Ledger currencies separate; not derived | Done | Existing two-account cards and transactions; docs. |
| Overview balances read-only from same ledger | Preserved | No ledger calculation change. |
| Settings finance summary | Done | Confirmed receipts / due this month / expected next month by currency/source. |
| “Expected” from obligations, not forecast | Done | `dueByCurrency()` reads obligations; label says not a sales forecast. |
| One regional pricing form, shared intro mode/date, compact rows | Done | `save_regional_pricing_bundle` + preview. |
| Intro price fields only when paid | Done | Hidden by class, toggled by mode. |
| Validated/versioned save, preview | Done | `previewBundle`/`saveBundle`; client preview. |
| Preserve regional values/history | Done | Existing `saveRegion` supersession. |
| Teacher global default + teacher-specific amount/date | Done | Existing rate authority, compact forms. |
| Current rate/timeline underneath | Done | `TeacherRateReadService::timeline`. |
| Preserve course overrides/audit | Done | Advanced control; existing authority. |
| Do not rewrite completed lesson snapshots | Done | No snapshot writes. |
| Golden rows weekday/start/finish/bonus/effective date | Done | `rules[]` inputs. |
| Asia/Tehran labelled | Done | UI and rule timezone. |
| Add/copy/edit/retire rows | Done | Add blank row, copy action, edit-as-new-version, retire action. |
| Overnight support and overlap validation | Done | `golden_hour_period_overlap`; overnight unit test. |
| Active first, history collapsed | Done | Active table first; `<details>` history. |
| Coverage charts immediately below | Done | Existing coverage controller rendered below active rules. |
| Intro teacher payability one checkbox, distinct from student pricing | Done | Dedicated checkbox + copy; separate regional pricing. |
| Booking horizon moved to General Settings | Done | New form/action in General Settings. |
| Stripe integration collapsed with safeguards | Done | `<details>` wrapper; existing embedded settings. |
| Capabilities, audit, idempotency, effective history preserved | Done | Existing services/capabilities/nonces; new actions nonce-gated. |
| Student totals/refunds/offline-vs-Stripe/partial/failed/multi-currency tested | Partially | Runtime receipts/report and source contracts; live provider partial/failed refund is not enabled. |
| Teacher draft/review/issue/send/portal visibility tested | Partially | Source contracts + runtime batch/history; no email transport invoked (approval boundary). |
| Persian month transitions tested | Done | Unit tests for Nowruz, leap, month lengths; runtime scheduler. |
| Pricing/pay-rate history tested | Done | Existing unit/runtime contracts + regional/golden runtime checks. |
| Golden overnight windows tested | Done | `golden-hour-rules-unit.php` and existing golden-hour-pay-unit. |
| Manual ledger independence tested | Done | Source contract; existing ledger runtime. |
| No live refund/charge/teacher email in testing | Done | No provider/email call made without approval. |
| Desktop/mobile screenshots | Done | `docs/evidence/finance-redesign-2026-11-02/`. |
| Merge/deployment status | Not merged / not deployed | Branch-only implementation candidate; no PR merge or deployment performed. |

## 5. Remaining limitations

1. **Integrated Stripe refund is not enabled.** The retained evidence is a test-mode Checkout Session, not a verified PaymentIntent/charge, and no live refund authority exists. The assessment is in the separate document.
2. **Teacher statement email transport was not invoked.** The durable outbox and source contract are covered; actual delivery requires an approved mail transport and recipient.
3. **A fully canonical statement draft/issue runtime with a real lesson fixture was not re-run in this session.** The batch preview and history were exercised; the existing Phase-U statement runtime remains the canonical draft/issue proof. Individual statement actions are unchanged.
4. **No PR or deployment was performed.** The branch is an isolated candidate for review.
5. **Screenshots use synthetic local data.** They prove layout and state handling, not production data.
6. **Receipts report month is Gregorian with Asia/Tehran boundaries**, while teacher statements and scheduling use Persian months. The UI labels the basis explicitly; a future Persian receipts-month selector would be a presentation addition, not a change to the report evidence.

## 6. Handoff notes

- Review `FINANCE-REDESIGN-MAP-2026-11-02.md` against the current Finance controller before merging with the Overview/Teachers/Students/Enrolment WIP branches.
- Shared navigation remains in `AdminShellController`; this change does not add a visible Finance submenu or a competing top-level tab.
- Shared styling remains in `src/Admin/Assets/admin.css`; the Finance controller’s inline styles are scoped to `.dzn-finance-*` and add mobile summary/table rules.
- No migration or schema version bump is part of this branch.
- The intended merge order is: land the shared admin shell/workspace branches, then this Finance branch; re-run the pure suite and the disposable Finance runtime after conflict resolution.
