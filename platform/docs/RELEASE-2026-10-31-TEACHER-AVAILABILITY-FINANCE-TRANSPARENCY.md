# Teacher availability UX and illustrative tuition allocation

## Scope

This release keeps `TeacherAvailabilityService` as the only availability authority and redesigns the Teacher Portal around positive availability: Teachers add only times when they can teach; uncovered time remains unavailable to booking assessment. Existing recurring facts are not rewritten, date-specific blocked exceptions remain supported, and availability edits do not modify canonical Lessons or their schedules.

The settings order is weekly selection, Teacher-local Golden Hours, one selected-hours/capacity summary, then holidays and exceptional dates. Golden Hours still come from the finance-owned policy projection. Selected overlaps are highlighted, partial overlaps are split, and the interface describes them as potential eligibility rather than earned pay.

Teacher instrument cards reuse the public booking artwork mapping at reduced dimensions. The redundant class guidance card and future-calendar kicker were removed; class actions and the canonical future calendar remain unchanged.

## Illustrative finance boundary

`TuitionAllocationEstimator` centrally defines the approved 100% illustrative distribution, including the explicit 1% unallocated row. It uses integer minor units and largest-remainder rounding, so every displayed term allocation reconciles exactly to the owner-authorised paid amount.

`TermTuitionAllocationReadService` is an authenticated, read-only projection:

- Students see only paid Terms joined to their canonical Student principal.
- Teachers see only paid Terms joined through their current applicable assignment and active Teacher authority.
- The projection exposes term reference, currency, paid amount and illustrative rows only.
- It does not create or update Stripe payments, invoices, receipts, settlements, statements, tax facts or accounting records.

Both portals render the same Theme component and the required Persian illustrative-only notice. The existing Teacher statement and Student checkout authorities remain unchanged.

## Verification

- `teacher-availability-summary-unit.php` covers positive-only minutes and partial Golden Hours overlap.
- `tuition-allocation-unit.php` covers the AUD 250 example, central percentages, minor-unit rounding and exact reconciliation.
- `teacher-portal-ux-finance-contract.php` covers form states, authoritative booking rules, exception preservation, instrument mapping, removed duplicates, portal access scoping and the read-only finance boundary.
- Existing booking availability, Golden Hours, portal, payment, statement and deployment contracts remain in the PR runtime gate.
