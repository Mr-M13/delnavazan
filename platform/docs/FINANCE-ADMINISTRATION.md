# Finance Administration

**Status:** implementation candidate on `feat/finance-admin-redesign-20261026`.
**Schema:** 51 / migrations `049_finance_administration`, `050_intro_payment_authority` and `051_teacher_rate_origin`.

## 1. What administrators see

One **Finance** destination has exactly four tabs:

| Tab | Purpose |
| --- | --- |
| **Payments** | Student checkout attempts, amount, student/course/offer context, checkout state, settled/refund evidence and provider-neutral reconciliation status. Read-only. |
| **Central Ledger** | Manual academy bookkeeping across the Australia (AUD) and Iran (TOM) accounts. Search, filters, pagination, gross/fees/net, currency and statement reconciliation status. This ledger is intentionally independent from Stripe, teacher rates and student pricing. |
| **Teacher Statements** | Teacher selector, statement history, recorded preview, print/download and statement template settings with live sandboxed preview. |
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

## 6. Manual Central Ledger

The ledger remains manual by design. It does not read or write Stripe settlement, student pricing or
teacher rates. It reuses the append-only academy cashbook command service (`OperationalCashLedgerService`)
and its capabilities. The four-tab ledger view adds cross-account search, filters, pagination and the
required accounting columns; corrections still use reversals, never edits.

## 7. Permissions

The page navigation gate is `dzn_access_finance_workspace`, which maps to one of the existing narrow
capabilities. Each tab and write keeps its own authority:

- Payments: `dzn_view_payment_execution_authority`;
- Ledger: `dzn_view_cash_ledger` / `dzn_manage_cash_ledger`;
- Statements: `dzn_view_finance_authority`, template writes `dzn_manage_finance_statements`;
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
  webhook convergence, failed payment retryability and no lesson/post-intro side effect.
- `tests/stripe-checkout-test-mode-e2e-runtime.php` — the existing signed Stripe webhook E2E also proves
  a paid intro Checkout attempt and its verified webhook route.

Updated navigation/catalogue contracts reflect the removed duplicate finance navigation and the
Finance-owned introductory pricing. The PR runtime gate executes the disposable WordPress/MariaDB catalogue,
post-intro, Stripe and OAuth suites; all are green for this candidate.
