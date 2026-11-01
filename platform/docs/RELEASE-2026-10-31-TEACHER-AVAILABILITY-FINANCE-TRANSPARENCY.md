# Teacher availability UX and illustrative tuition allocation

## Scope

This release keeps `TeacherAvailabilityService` as the only availability authority and redesigns the Teacher Portal around positive availability: Teachers add only times when they can teach; uncovered time remains unavailable to booking assessment. Existing recurring facts are not rewritten, date-specific blocked exceptions remain supported, and availability edits do not modify canonical Lessons or their schedules.

The settings order is weekly selection, Teacher-local Golden Hours, one selected-hours/capacity summary, then holidays and exceptional dates. Golden Hours still come from the finance-owned policy projection. Selected overlaps are highlighted, partial overlaps are split, and the interface describes them as potential eligibility rather than earned pay.

Teacher instrument cards reuse the public booking artwork mapping at reduced dimensions. The redundant class guidance card and future-calendar kicker were removed; class actions and the canonical future calendar remain unchanged.

## Illustrative finance boundary

`TuitionAllocationEstimator` centrally defines the approved 100% illustrative distribution. The former unallocated 1% is now part of Delnavazan's 3% share; the other percentages are unchanged. The rows are:

- Teacher payments — 28%
- Tax — 28%
- Advertising — 20%
- GST — 10%
- Transaction fees — 3.2%
- Employees and management — 2%
- Infrastructure — 2%
- Messaging — 1%
- Projects (working title) — 2.8%
- Delnavazan share — 3%

The shared Theme component renders percentages only, inside an expandable `<details>` block. It does not read or display a Term, a paid amount or a monetary column, so the Teacher and Student portals always show identical labels and shares. The estimate is not audited expenditure: the 10% GST row is a planning share of revenue, not a statement of GST payable.

`TermTuitionAllocationReadService` remains an authenticated, read-only projection used to prove the allocation boundary:

- Students see only paid Terms joined to their canonical Student principal.
- Teachers see only paid Terms joined through their current applicable assignment and active Teacher authority.
- The projection is read-only and does not create or update Stripe payments, invoices, receipts, settlements, statements, tax facts or accounting records.

Both portals render the same Theme component and the required Persian illustrative-only notice. The existing Teacher statement and Student checkout authorities remain unchanged.

## Teacher monthly finance reconciliation

`TeacherFinanceSummaryReadService` now reconciles the monthly overview to canonical Lesson, schedule and delivery facts. The Teacher-profile timezone's local month is converted to UTC for every boundary. Scheduled duration is reported separately from taught duration:

- `scheduled_minutes_month` is the scheduled duration of authorised and completed occurrences.
- `minutes_month` is the completed/delivered duration only. An effective delivered outcome counts even before the denormalised lifecycle row reaches `completed`; a future authorised occurrence never contributes.
- `completed_lessons_month` uses canonical completion and effective delivery facts, not merely a calendar date.
- An explicit non-delivery or unresolved review outcome is never fabricated into teaching time.
- `upcoming_lessons` counts future authorised canonical occurrences.
- `reschedule_requests_month` and `pending_reschedules` come from the reschedule proposal authority.

The same read model exposes the agreement rate from the single active `finance_teacher_rates` interval, issued completed-period statements, and recorded golden-hour details. It returns `unavailable` rather than guessing when the rate is absent, multiple active intervals make it ambiguous, a statement has not been issued, or a detail is missing. This build has no Teacher payout ledger, so payment status, paid amount and unpaid amount remain explicitly unavailable; expected earnings are never presented as settled payments. No synthetic production statement is created. The regression fixture proves the reported "1.5 hours, 3 scheduled, 0 completed" case with three delivered occurrences.

## Verification

- `teacher-availability-summary-unit.php` covers positive-only minutes and partial Golden Hours overlap.
- `tuition-allocation-unit.php` covers the AUD 250 example, the exact 100% category list, minor-unit rounding and exact reconciliation.
- `tuition-allocation-transparency-contract.php` proves the shared component has no `مبلغ` column, monetary formatting or term selector.
- `teacher-finance-summary-reconciliation-unit.php` covers the reported monthly regression, scheduled-vs-completed minutes, honest rate ambiguity and the unavailable payout state.
- `teacher-portal-ux-finance-contract.php` covers form states, authoritative booking rules, exception preservation, instrument mapping, removed duplicates, portal access scoping and the read-only finance boundary.
- Existing booking availability, Golden Hours, portal, payment, statement and deployment contracts remain in the PR runtime gate.
