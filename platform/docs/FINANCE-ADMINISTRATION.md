# Finance Administration

**Status:** implementation candidate on `feat/finance-admin-redesign-20261026`.
**Schema:** 49 / migration `049_finance_administration`.

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

## 4. Teacher compensation (Toman)

Teacher pay is separate from student prices and is **always in Iranian toman (`TOM`)**.

- `TEACHER_GLOBAL_RATE_TOMAN_V1` in `finance_settings` stores the global default (`amount_minor`,
  `currency=TOM`, `effective_from`).
- Saving the global default applies it to active Teachers that are not marked as explicit overrides,
  writing effective-dated rows through the existing `TeacherRateService`.
- A teacher-specific or teacher/course rate is entered in normal Toman units, converted to exact minor
  units, recorded through `TeacherRateService`, and marks that Teacher as an override.
- “Resume global default” records a new effective rate version and removes the override marker.
- Historical lesson snapshots and statements keep the rate row/version they recorded.

Per-Teacher overrides are tracked in `TEACHER_RATE_OVERRIDE_IDS_V1`; this is a configuration index, not a
second compensation model.

## 5. Statement templates

`finance_settings` stores versioned presentation templates (`STATEMENT_TEMPLATE_V1`). Template fields:

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

## 8. Migration 049 and rollback

`049_finance_administration` is additive:

- creates `finance_regional_prices` and `finance_settings`;
- seeds a regional row only where every active standard Course already agrees on exactly one currency
  and one amount;
- skips conflicting or ambiguous regions for explicit resolution in Finance → Settings;
- creates no offer, purchase, payment, statement, ledger, Term, Lesson or schedule row;
- changes no historical amount.

Conflict resolution is explicit: Settings lists regions whose active course prices differ and asks the
administrator to choose one amount. The choice supersedes only the **current** commercial price rows;
historical price rows, offers, payments and statements stay intact.

Rollback/recovery:

1. The additive tables can remain in place without affecting old code; this is the safest rollback.
2. If removal is required, take a database backup first and verify no `commercial_offers` or
   `finance_statement_lines` rows are missing their recorded price/rate references.
3. Drop only `finance_regional_prices` and `finance_settings`, and remove the
   `049_finance_administration` entry from `dzn_platform_completed_migrations`; restore the previous
   schema-version option. Do **not** delete `commercial_prices` history.
4. Restore the previous code release. Existing offers/payments/statements are not rewritten.

No production deployment or production price change is part of this change.

## 9. Outstanding boundary and review decisions

- **Paid introductory collection.** The configuration, validation, display and per-region amount are
  implemented. The existing Delnavazan payment execution authority is still scoped to post-intro
  commercial obligations; this change does not invent a second pre-intro payment seam. A paid
  introductory amount therefore cannot yet be charged by the current checkout path, and the UI does not
  claim otherwise. Wiring that collection requires a separately reviewed pre-intro obligation/checkout
  authority.
- **Global teacher default materialisation.** Saving the global default writes effective-dated rate rows
  for currently active, non-overridden Teachers. A Teacher created later receives the default the next
  time the global setting is saved or when an administrator records a Teacher-specific rate. A future
  teacher-creation hook could materialise it immediately.
- **Conversion and fees.** The user-specified AUD account view is not derived from online payments in
  this change. Stripe/accounting conversion, fees and taxes remain provider/accounting facts and do not
  write the manual Central Ledger. The ledger stays manual by design.
- **Runtime evidence.** The disposable WordPress + MariaDB runtime was not available in this
  environment. Static lint, pure unit suites and source contracts were executed; migration and
  role-runtime execution remain a pre-merge gate.

## 10. Tests

Pure/contract suites added:

- `tests/finance-pricing-unit.php` — shared pricing, minor units, Persian digits, free/paid intro,
  region/currency validation, no per-instrument key.
- `tests/finance-statement-template-unit.php` — HTML/CSS sanitisation, unsafe URL refusal, RTL/LTR,
  lesson loop, recorded totals, script-free output.
- `tests/finance-administration-contract.php` — four tabs, one visible Finance destination, migration
  049, shared authority reuse, Toman teacher rates, template editor, Stripe consolidation, manual ledger
  independence and capability/nonce gates.

Updated navigation/catalogue contracts reflect the removed duplicate finance navigation and the
Finance-owned introductory pricing. Runtime WordPress/MariaDB suites still need the disposable
environment for migration and permission execution; static lint and all pure suites are executable
here.
