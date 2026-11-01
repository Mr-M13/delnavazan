# Finance Administration

**Status:** implementation candidate on `feat/finance-admin-redesign-20261026`.
**Schema:** 51 / migrations `049_finance_administration`, `050_intro_payment_authority` and `051_teacher_rate_origin`.

## 1. What administrators see

One **Finance** destination has exactly four tabs:

| Tab | Purpose |
| --- | --- |
| **Payments** | Student checkout attempts, amount, student/course/offer context, checkout state, settled/refund evidence, provider-neutral reconciliation status, outstanding obligations, recent settlements, administrator-only manual refunds and refund-review cases, and two-instalment schedules with their stored outstanding balance. Checkout views are read-only; an authorised administrator can record an offline payment against one exact stored obligation. |
| **Central Ledger** | Manual academy bookkeeping across the Australia (AUD) and Iran (TOM) accounts. Search, filters, pagination, signed amount, currency and statement reconciliation status. The view never invents a fee or a counterparty: the cashbook stores neither. This ledger is intentionally independent from Stripe, teacher rates and student pricing. |
| **Teacher Statements** | Teacher selector, statement draft/issue/withdraw/supersede actions, monthly batch generation for every Teacher with work, branded email delivery, statement history, recorded preview, print/download and statement template settings with live sandboxed preview. |
| **Settings** | Global regional student prices, free/paid introductory lessons, Toman teacher global default and per-Teacher overrides, Stripe setup/readiness, finance policies and commercial timing policies. |

The old visible finance submenu entries are removed. Their protected direct routes remain registered
for compatibility and for deep links from records, but ordinary configuration no longer requires
navigating a set of separate pages.

## 2. Single student-price authority

Business rule: **all standard Courses have the same student price within one currency region.**

`finance_regional_prices` stores one active row per region:

- `region_code` and `currency` from the preserved region mapping;
- `standard_amount_minor` — exact integer minor units;
- `introductory_mode` (`free` or `paid`);
- `introductory_amount_minor` — `NULL` for free, a positive exact amount for paid;
- `effective_from`, `effective_until`, `status`, `active_slot` for effective-dated history.

Administrators type normal currency units. `PricingMoney::toMinor()` accepts Persian/Arabic digits,
grouping and decimal separators and refuses values that would require rounding. There is no automatic
exchange-rate conversion; each region is configured manually.

`RegionalPricingService` is the only writer. It:

1. validates region/currency/amount and the introductory mode;
2. closes and supersedes the previous regional row without mutating it;
3. inserts the new authoritative row;
4. synchronises the active `commercial_prices` row of every active standard Course to the same
   amount/currency, while leaving older price rows intact;
5. leaves every accepted `commercial_offers.course_price_id` and payment snapshot untouched.

`CommercialCatalogueService::resolvePrice()` refuses a product price that contradicts the configured
regional authority (`regional_price_authority_conflict`). New products receive the current regional
price through `ensureProductPrice()`. Checkout therefore consumes the same authority as the public site.

The homepage/course presentation reads `RegionalPricingService::publicConfig()`; the previous hardcoded
A$250/US$250/€150 values are gone. If a region is not configured, the public site asks the visitor to
contact the academy rather than showing a stale or converted amount.

## 3. Introductory lessons

Free and paid introductory pricing is independent of introductory lesson **availability**.

- **Free:** `introductory_amount_minor = NULL`; the existing introductory booking, teacher acceptance,
  Google Calendar and Google Meet workflows continue without a charge.
- **Paid:** a positive amount in the region currency is required. The amount is stored exactly and is
  displayed on the public pricing and booking surfaces. The existing authorised payment path remains
  the only payment path; configuration does not invent a second charge mechanism.

The old instrument-level “paid intro unsupported” rejection and the fake “Free” instrument field are
removed from ordinary administration. The instrument setup action still creates the introductory
Course and default link; it does not create a second price.

### Paid introductory lifecycle

```text
Booking request → Teacher accepts
  ├─ free price → existing Calendar/Meet projection → intro lesson
  └─ paid price → one intro payment obligation (state=pending)
                   → student-owned Stripe Checkout (same test-mode adapter)
                   → verified webhook success → obligation paid
                   → existing Calendar/Meet projection → intro lesson
```

- The obligation is unique per booking request and confirmation. A repeat acceptance or checkout
  command converges on the same row.
- A Checkout attempt is unique per obligation while active. Re-driving an unresolved attempt reuses
  the same provider idempotency key.
- Failed or expired attempts leave the obligation unpaid and retryable; they never mark the lesson paid.
- A verified refund is recorded as `refunded`; it does not rewrite an existing confirmed lesson or
  touch post-intro enrolment.
- Payment completion never creates a standard-course Enrolment, Term, Lesson or post-intro offer.
- The amount and currency are captured from the regional authority at acceptance. Later price changes
  do not rewrite an existing obligation.

### 3.1 Administrator-recorded offline payments

A bank transfer, cash receipt or another off-platform payment is recorded from **Finance → Payments →
Outstanding payments**. The administrator chooses the stored obligation and supplies a receipt/bank
reference and the actual received date; the browser never supplies an amount. The service reads the
exact obligation amount and currency and submits provider-neutral `manual` evidence through the same
`CommercialPaymentService` used by the Stripe webhook. The purchase, entitlement, funding plan,
capacity handoff and post-intro activation therefore derive exactly as they do for verified Stripe
evidence.

- An already-settled obligation is refused (`manual_payment_already_settled`); there is no second
  settlement path.
- A closed or expired offer is refused by the canonical offer-window checks. The administrator must
  issue a current offer instead.
- Manual evidence is recorded with `provider_key=manual`, `evidence_kind=success` and
  `evidence_channel=staff_record`. It is visible in **Recent settlements** and is never mistaken for a
  Stripe signature.
- The paid introductory obligation uses the same paid transition and booking projection as the Stripe
  path (`IntroPaymentService::recordManualSettlement`). A projection failure after commit leaves the
  payment paid and the existing booking worker retries the projection.
- **The manual Central Ledger is not written automatically.** Settling a Student obligation changes
  commercial truth; the cashbook remains a separate manual record. This is deliberate and prevents a
  bookkeeping entry from silently marking a Student paid.

## 4. Teacher compensation (Toman)

Teacher pay is separate from student prices and is **always in Iranian toman (`TOM`)**.

- `TEACHER_GLOBAL_RATE_TOMAN_V1` in `finance_settings` stores the global default (`amount_minor`,
  `currency=TOM`, `effective_from`).
- Saving the global default applies it to active Teachers that are not marked as explicit overrides,
  writing effective-dated rows through the existing `TeacherRateService`.
- A teacher-scope explicit rate is entered in normal Toman units, converted to exact minor units,
  recorded through `TeacherRateService` with `rate_origin=explicit`, and marks that Teacher as an
  override. A course-specific explicit rate keeps precedence for its Course but does not block the
  teacher-scope global default.
- A newly activated Teacher inherits the later of the global rate's effective date and the recorded
  onboarding activation instant; an inherited rate is never effective before activation.
- “Resume global default” records a new inherited effective rate version and removes the override marker.
- Historical lesson snapshots and statements keep the rate row/version they recorded.

Per-Teacher overrides are tracked in `TEACHER_RATE_OVERRIDE_IDS_V1`; this is a configuration index, not a
second compensation model.

## 5. Statement templates

`finance_settings` stores versioned presentation templates (`STATEMENT_TEMPLATE_V1`). Each save creates a new `setting_version`; the editor shows the current template version and all previous versions remain in the append-only settings history. Template fields:

- header HTML, lesson-table HTML, footer HTML, CSS;
- logo URL (HTTPS only) and default language/direction.

Rendering is presentation-only. Amounts, period, teacher, statement version and state are supplied by
the recorded statement/read service. Values are HTML-escaped when substituted. Author HTML is sanitised
with `wp_kses` (or a no-WordPress allowlist in unit tests); scripts, frames, forms, event handlers,
unsafe URLs and remote CSS loads are removed. The live preview uses a sandboxed iframe with no
same-origin or script permission. Reset restores the delivered default. Changing a template cannot
change a calculation, a recorded total or a statement state.

### 5.1 Statement issuance lifecycle

The Statements tab now exposes the recorded authority instead of only reading its output:

1. **Draft** — choose the Teacher and the first/last UTC calendar day. The recorded period is
   half-open (`start 00:00:00` to the day after the inclusive end). Every non-archived Lesson in the
   period must be finalised, snapshotted and have an effective payability evaluation.
2. **Issue** — the existing fail-closed issuance gate runs (totals, elapsed period, timezone triple,
   snapshots, pending payability, blocking exceptions, overlaps and double statements). A failed gate
   is reported as a controlled reason and changes nothing.
3. **Withdraw** — terminal for a draft; its lines are retained and never reused.
4. **Supersede** — only an issued statement; a new draft version is built from current recorded facts
   and the predecessor is marked superseded.

`FINANCE_STATEMENT_TIMEZONE` remains unset by default. A draft records the timezone version in force
at drafting, so the Statements tab now warns when no timezone policy exists and links to Finance →
Settings → Finance policies. Recording the timezone after a draft does not repair that draft; draft
again.

### 5.2 Branded statement presentation

The delivered statement template and the email renderer share the Academy palette and typography:

| Role | Hex |
| --- | --- |
| Ivory background | `#FBF8F2` |
| Card | `#FFFFFF` |
| Deep teal | `#104A4D` |
| Primary teal | `#1E7B70` |
| Pomegranate accent | `#A33E48` |
| Saffron accent | `#D4AB4F` |
| Ink | `#252726` |
| Muted | `#5F625F` |
| Border | `#DDD6CA` |

Font: **Vazirmatn** with `Tahoma, Arial, sans-serif` fallback. The web/print statement loads the
theme-local `Vazirmatn-Variable.woff2` through a trusted `@font-face` outside the editable CSS. The
email is a fixed, 640px, table-based, inline-styled layout with the same palette and fallback stack; it
makes no remote font or stylesheet request, so Gmail, Outlook, Apple Mail and mobile clients render the
same structure.

Each lesson row shows the occurrence date, course, duration, base rate, golden-hour bonus, final
amount and payability status. The summary shows payable total, payable lesson count and total lesson
count. The statement remains explicitly **compensation only**, not a payout receipt or tax invoice.

### 5.3 Monthly batch statements

The Statements tab can generate one statement for every Teacher with at least one active Lesson in a
completed UTC month:

1. **Preview** lists the candidate Teachers, their active/archived Lesson counts, any existing live
   statement and whether a recorded email address exists.
2. **Generate** drafts each missing statement through the recorded authority. If “issue” is selected,
   each draft passes the normal issuance gate independently.
3. **Email** sends each issued statement to the Teacher's recorded address. The email is recorded as an
   `emailed` or `email_failed` statement event.
4. A per-Teacher result table reports drafted, issued, skipped, failed and emailed outcomes. One
   Teacher's failure never blocks another.

A batch rerun does not duplicate a live statement; an exact issued statement is skipped and only a
missing successful email is retried. The batch remains operator-triggered; a draft-only monthly cron
with an explicit administrator confirmation is the recommended next step.

### 5.4 Administrator-only manual refunds

A refund is recorded from Payments → Manual refunds. The form selects an already-settled obligation,
takes the amount (blank = full remaining), the actual refund date, an evidence reference and an
optional note. `ManualRefundService`:

- requires `dzn_manage_refund_reviews`; this capability is deliberately not part of the Finance staff
  bundle, so only an administrator can refund;
- records accepted `manual` refund evidence through the existing commercial evidence authority;
- caps the amount at the settled amount minus already-accepted refund evidence;
- opens the existing refund-review case and retains the note;
- never rewrites the settlement, purchase, entitlement, funding plan, Lesson or statement.

The review case can then be **routed for review**, **resolved** with a decision note, or **dismissed**.
That decision is a recorded human fact. Moving money remains a completely manual external action in the
bank/Stripe dashboard; this page does not initiate or reverse a transfer.

## 6. Manual Central Ledger

The ledger remains manual by design. It does not read or write Stripe settlement, student pricing or
teacher rates. It reuses the append-only academy cashbook command service (`OperationalCashLedgerService`)
and its capabilities. The four-tab ledger view adds cross-account search, filters, pagination and the
required accounting columns; corrections still use reversals, never edits.

## 7. Permissions

The page navigation gate is `dzn_access_finance_workspace`, which maps to one of the existing narrow
capabilities. Each tab and write keeps its own authority:

- Payments: `dzn_view_payment_execution_authority` for reads, `dzn_manage_payment_execution` (plus the canonical `dzn_ingest_commercial_payment_evidence`) for an offline payment, `dzn_view_recurring_authority` for refund reads and `dzn_manage_refund_reviews` for refund evidence/review actions;
- Ledger: `dzn_view_cash_ledger` / `dzn_manage_cash_ledger`;
- Statements: `dzn_view_finance_authority` for reads, draft/issue/withdraw/supersede, batch generation, email and template writes `dzn_manage_finance_statements`;
- Regional prices: `dzn_manage_commercial_catalogue`;
- Teacher rates/default: `dzn_manage_teacher_rates`;
- Finance policies: `dzn_manage_finance_policies`;
- Commercial policies: `dzn_manage_commercial_policies`;
- Stripe: existing payment-provider/payment-execution capabilities.

Every write is nonce-checked and redirects with a safe result code. Secret Stripe values remain
write-only and are never returned by the Finance UI.

## 8. Migrations 049, 050 and 051 and rollback

`049_finance_administration`, `050_intro_payment_authority` and `051_teacher_rate_origin` are additive:

- creates `finance_regional_prices` and `finance_settings`;
- creates `intro_payment_obligations`, `intro_checkout_sessions`, `intro_payment_events` and `intro_payment_commands`;
- adds teacher-rate provenance columns `rate_origin` and `origin_setting_version`, and classifies only evidence-proven historical inherited rates;
- never backdates a new inherited rate before the Teacher activation instant;
- seeds a regional row only where every active standard Course already agrees on exactly one currency
  and one amount;
- skips conflicting or ambiguous regions for explicit resolution in Finance → Settings;
- creates no offer, purchase, payment, statement, ledger, Term, Lesson or schedule row;
- changes no historical amount.

Conflict resolution is explicit: Settings lists regions whose active course prices differ and asks the
administrator to choose one amount. The choice supersedes only the **current** commercial price rows;
historical price rows, offers, payments and statements stay intact.

Rollback/recovery:

1. The safest rollback is to restore the database snapshot taken immediately before Schema 51 (or before
   deployment) together with the previous code release.
2. If a database restore is not possible, take a backup first and verify no `commercial_offers` or
   `finance_statement_lines` rows are missing their recorded price/rate references.
3. Drop only `finance_regional_prices`, `finance_settings`, `intro_payment_obligations`,
   `intro_checkout_sessions`, `intro_payment_events` and `intro_payment_commands`, drop the additive
   `finance_teacher_rates.rate_origin` and `finance_teacher_rates.origin_setting_version` columns, and
   remove the `049_finance_administration`, `050_intro_payment_authority` and
   `051_teacher_rate_origin` entries from `dzn_platform_completed_migrations`; restore the previous
   schema-version option. Do **not** delete `commercial_prices` history. Dropping the two nullable
   provenance columns is required before restoring Schema-50 code because the older schema verifier
   rejects undeclared columns.
4. Restore the previous code release. Existing offers/payments/statements are not rewritten.

No production deployment or production price change is part of this change.

## 9. Outstanding boundary and review decisions

- **Paid introductory collection.** Implemented as a separate pre-intro obligation authority
  (`intro_payment_obligations`, `intro_checkout_sessions`, `intro_payment_events`) using the same
  Stripe test-mode adapter, verified webhook, account binding, signature checks and event idempotency
  as standard-course checkout. Teacher acceptance stops at `awaiting_payment`; only a verified
  successful payment releases the existing Google Calendar/Meet projection. Failed/cancelled/expired
  attempts stay unpaid and retryable; refunds are recorded without silently cancelling a confirmed
  lesson or touching post-intro enrolment.
- **Global teacher default materialisation.** Saving the global default writes effective-dated rate rows
  for currently active, non-overridden Teachers. The authoritative onboarding approval emits
  `dzn_teacher_activated`, which applies the current global Toman rate to the newly activated Teacher
  idempotently. Explicit teacher-scope rates are preserved as overrides; course-specific rates keep
  their Course precedence.
- **Conversion and fees.** The user-specified AUD account view is not derived from online payments in
  this change. Stripe/accounting conversion, fees and taxes remain provider/accounting facts and do not
  write the manual Central Ledger. The ledger stays manual by design.
- **Runtime evidence.** The PR runtime gate executed the disposable WordPress + MariaDB catalogue
  lifecycle, post-intro lifecycle, Stripe E2E and OAuth scope regression successfully. Static lint,
  pure unit suites and source contracts also pass.

### Instalment schedule visibility

Payments includes a read-only **Instalment schedules** table for every offer whose canonical
`plan_kind` is `two_instalments`. Each row shows the stored instalment amount, its exact Lesson range,
the recorded due instant (or `Before Lesson 7` when no explicit deadline exists), settlement state and
the offer's outstanding balance. The balance is presentation only: `offer.amount_due_minor` minus the
sum of already-settled obligations. The table creates no offer, settlement, ledger entry or statement,
and its filters and pagination read only offers, obligations and settlements that already exist.

The Student Portal payment section now groups obligations by their canonical offer and shows the
plan kind, full committed total, number of Lessons, each instalment's amount and Lesson range, and its
due state. A second instalment with no recorded date is shown as due before Lesson 7 because the
existing funding authority already prevents Lesson 7 until that tranche settles; no deadline is invented.

### B2a: full-term default and pre-offer plan choice

The normal path is **full-term payment**. The Student Portal shows the full-term amount and the
primary payment action first. Two instalments are a secondary, explicitly ticked option; the term is
never preselected into instalments. The server defaults to `full` when no plan is submitted and
accepts `two_instalments` only with the explicit opt-in and a valid pre-offer quote token.

The Student chooses before the canonical offer exists. A durable `post_intro_plan_choices` row records
the active choice and an audit trail of pre-offer changes. Once the offer is issued, its plan and
obligations are immutable; a different plan is refused with `plan_choice_conflict` and never creates a
second offer.

A two-instalment plan funds Lessons 1–6 with tranche 1 and Lessons 7–12 with tranche 2. Normal Student
Checkout for tranche 2 is refused until tranche 1 settles. A valid out-of-sequence payment received
through reconciliation is recorded financially, but academic funding remains zero until its
prerequisite settles; the existing `instalment_prerequisite_unsettled` signal is recorded.

When tranche 2 settles, the activation job's `funding_target` is raised and a convergence worker
materialises the remaining Lessons. The target is never lost when a worker completes an earlier
target: the hook raises the target under the job row lock and the worker re-checks effective sessions
before completing. Deterministic Lesson, schedule and provider keys make the later run replay, not
duplicate, Lessons 1–6, the Term, the Teacher Assignment or the provider projection.

The Student account speaks in term-level language: `ترم ۱ پرداخت شد`, `قسط ۲ از ۲`, and
`پرداخت ترم بعد: با ثبت پرداخت کامل ترم جدید`. A full payment is never labelled as an instalment.

### B2b: the second-instalment deadline

`INSTALMENT_DUE_DATE_POLICY` is the only source of a second-instalment deadline. It remains **unset by
default**. While unset, tranche 2 has no due date and the portal continues to say "Before Lesson 7";
no deadline is invented.

An administrator may configure the approved value — **168 hours before Lesson 7**, recorded as one
week (`value_type=weeks`, `policy_value=1`) or as `604800` seconds (`value_type=duration`). When that
value is active, offer issuance derives the due instant from the canonical pattern's seventh
occurrence (resolved in the pattern timezone) minus the lead time, records it on obligation 2, and
stores the exact policy version on the offer and the Student's pre-offer choice.

Any other explicit value fails closed with `instalment_due_date_policy_invalid` instead of inventing a
different deadline. Existing offers are never rewritten, and changing or unsetting the policy affects
only offers issued afterwards. The deadline is display and receivables truth; it does not release
Lesson 7 or a teacher payment, and the existing tranche-prerequisite funding rule still applies.

### Presumed completion and payability

A Lesson that ended without any Phase-O outcome may be presumed completed by the interim attendance
worker. That is not verified attendance. Finance derives `pending / presumed_completion_review` and
blocks statement issuance until an administrator records a verified delivered outcome or resolves the
case. A later delivered outcome follows the normal `outcome_delivered` row and becomes payable; a
non-delivery or disputed outcome follows its own non-payable or pending row. Presumed completion never
releases a teacher payment by itself.

### Schema 52

Schema 52 (`052_payment_plan_choice`) is additive and belongs to the post-intro payment-plan slice. It
adds `post_intro_plan_choices` and two activation-job funding columns. It creates or rewrites no
Finance table, price, offer, settlement, statement or ledger row. Finance read models that consume the
new plan-choice or instalment-schedule fields must still go through the existing
`dzn_view_payment_execution_authority` and `dzn_manage_lesson_payability` capabilities.

## 10. Tests

Pure/contract suites added:

- `tests/finance-pricing-unit.php` — shared pricing, minor units, Persian digits, free/paid intro,
  region/currency validation, no per-instrument key.
- `tests/finance-statement-template-unit.php` — HTML/CSS sanitisation, unsafe URL refusal, RTL/LTR,
  lesson loop, recorded totals, script-free output.
- `tests/finance-administration-contract.php` — four tabs, one visible Finance destination, migrations
  049/050/051, shared authority reuse, paid intro lifecycle, Toman teacher rates and inheritance, template
  editor/versioning, Stripe consolidation, manual ledger independence and capability/nonce gates.
- `tests/intro-payment-unit.php` — paid intro obligation idempotency, amount/currency refusal, failed
  payment visibility, duplicate provider-event convergence and malformed-reference rejection.
- `tests/finance-administration-runtime.php` — disposable WordPress/MariaDB proof for empty catalogue,
  conflicting legacy prices, per-currency pricing, historical offer/price preservation, repeated
  migrations, teacher-rate inheritance, paid intro Checkout reuse, successful settlement, duplicate
  webhook convergence, failed payment retryability, instalment schedule/outstanding reads and no
  lesson/post-intro side effect.
- `tests/stripe-checkout-test-mode-e2e-runtime.php` — the existing signed Stripe webhook E2E also proves
  a paid intro Checkout attempt, the Student plan projection (two ordered tranches, exact Lesson ranges,
  settled/outstanding state) and its verified webhook route.
- `tests/student-checkout-return-contract.php` — the identifier-only checkout boundary now also asserts
  that plan totals remain display-only and are never posted back to the checkout command.
- `tests/finance-payment-completion-contract.php` — administrator offline payment completion, the
  statement draft/issue/withdraw/supersede surface, the narrow Finance staff bundle, and the rule
  that a completed Checkout is not displayed as a settled payment by itself.
- `tests/finance-refund-batch-contract.php` — monthly batch, branded email and administrator-only
  manual refund source contract.
- `tests/teacher-statement-email-unit.php` — palette, RTL/LTR, pay detail, empty-template safety and
  no-remote-font/plain-text email proof.
- `tests/finance-manual-payment-runtime.php` — disposable WordPress/MariaDB proof that a manual
  settlement creates exactly one settlement and entitlement, funds Lessons 1–6 then the full 12-session
  Term, and refuses a duplicate settlement.
- `tests/payment-plan-choice-contract.php` — Schema 52, the pre-offer choice, full-term default,
  explicit instalment opt-in, quote digest as a stale guard only, single live offer, immutable plan,
  tranche-2 Checkout gate, convergent activation targets and term-level account wording.

Updated navigation/catalogue contracts reflect the removed duplicate finance navigation and the
Finance-owned introductory pricing. The PR runtime gate executes the disposable WordPress/MariaDB catalogue,
post-intro, Stripe and OAuth suites; all are green for this candidate.
