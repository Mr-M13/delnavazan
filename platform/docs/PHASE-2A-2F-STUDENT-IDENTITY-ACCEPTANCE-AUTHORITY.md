# Phase 2A.2-F — Student Identity & Acceptance Authority Foundation

Schema 12 / migration `012_student_identity_acceptance_authority` establishes bounded, internal-only evidence needed before a future acceptance workflow can be designed. It does not accept an arrangement or create an Accepted Service Arrangement.

## Identity and privacy

- A Booking Request becomes associated with a Student only after a human reviewer records an append-only identity-resolution event.
- Reviewers may resolve to an existing Student, deliberately create a distinct Student with an independently entered display name, or record `unresolved`, `ambiguous`, or `rejected`.
- The service never matches or copies Booking Request email, mobile, WhatsApp, payer, submitter, address, WordPress account, hashes, or document data into Student identity.
- Resolution and capacity evidence stores controlled basis/channel values plus opaque evidence references; it stores no raw document or date-of-birth data.
- Erasure before resolution blocks resolution. Erasure after a valid resolution clears Booking Request PII while retaining the minimum independent Student and non-PII authority history. An erased request cannot establish new identity or acceptance authority.

## Capacity and authority

- Capacity classifications are append-only `adult`, `minor`, or `unknown` records, with a Student current pointer.
- `student_principal_links` is extended with versioned provenance and one-active-link constraints. `students.wordpress_user_id` is not used as authority.
- Principal replacement is one atomic supersession transaction. The old current row becomes `superseded`, records actor/reason/time and `superseded_by_link_id`, while the provenanced replacement becomes active without a committed authority gap.
- V1 can create only an effective, revocable `guardian_representative` grant scoped to `service_acceptance`. The table reserves vocabulary for a future `adult_delegate`, but no V1 path creates, uses, or returns it.
- Guardian timestamps are strict UTC calendar values and the end must be later than the start. Elapsed grants confer no eligibility; a later authority mutation retires their active slot as `expired`, preserving the historical row and permitting a valid replacement without cron.
- Adult-self eligibility requires a resolved non-erased request, active Student, current `adult` capacity classification, and a current provenanced Student-to-WordPress principal link.
- Minor guardian eligibility requires the same resolved/active state, current `minor` capacity classification, and an effective exact guardian grant.
- `StudentAcceptanceAuthorityReadService` is informational only; it returns eligibility/blocked states and no bearer credential or final acceptance power.

## Concurrency and migration evidence

Authority mutations lock the Student, involved WordPress users in ascending ID order, relevant principal links by ID, then relevant guardian grants by ID before mutation. The executable local harness covers resolution/erasure, capacity, principal and guardian establish/revoke/supersede races plus informational currentness reads using explicit post-lock gates.

The migration test begins with representative Schema 11 active/revoked principal links and Phase 2A.2-E acceptance state, runs the normal Schema 11 → 12 upgrader, verifies exact backfill/index/foreign-key/capability behavior, and repeats the upgrader to prove idempotency.

## Deliberate exclusions

This slice does not promote `accepted_pending_conditions`, perform final acceptance, create an arrangement, convert a request, create enrolments, assign Teachers, create Terms/Lessons/attendance, handle payment/notification/calendar/Amelia, or expose a public identity workflow.
