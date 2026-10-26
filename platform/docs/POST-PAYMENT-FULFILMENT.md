# Post-payment fulfilment and paid-Enrolment recovery

This runbook covers the case where a Student has a genuine Stripe Checkout payment but the platform
has not produced the funded Term and weekly Lessons.

## Authoritative recovery order

1. **Do not create a second payment.**
2. **Do not manually insert a Term, Lesson, Teacher Assignment or payment evidence.**
3. Confirm the exact production environment and take the normal deployment/backup snapshot.
4. Run the read-only inspection:
   ```bash
   wp dzn-post-intro inspect <enrolment-id> --user=1
   ```
5. The inspection reports:
   - verified platform settlement (`payment_verified`);
   - checkout session state;
   - preparation state;
   - Term, Teacher Assignment and Lesson counts;
   - authoritative recurring pattern/timezone;
   - blockers and planned records;
   - recent refused provider webhook receipts.
6. If payment is **not** verified in the platform but an owned Stripe Checkout session exists, run the
   reviewed recovery:
   ```bash
   wp dzn-post-intro recover <enrolment-id> --user=1
   ```
   This calls the existing `StripeCheckoutReconciliationService`. It retrieves the exact Checkout
   session and its completion Event from Stripe, verifies the account, attempt, amount and currency,
   then settles through `CommercialPaymentService`. It does not create a second charge.
7. If payment is verified, recovery reuses the existing `PostIntroSettlementHook` and
   `PostIntroActivationWorker::runJob()` to create the canonical Term, Teacher Assignment and weekly
   Lessons. Repeated recovery is idempotent.
8. If the authoritative recurring pattern or wall clock is missing, recovery stops with
   `authoritative_schedule_missing`. Resolve the accepted weekly arrangement in its owning authority;
   do not guess a weekday, time or timezone.
9. Verify the Student Portal and Teacher Portal. Both read the same canonical Lesson schedules.

## Webhook signature failures

`payment_provider_event_receipts` may show `verification_state=refused` and
`refusal_reason_code=signature_invalid` while Stripe still shows a paid Checkout session. This means
the endpoint's signing secret does not match the active Stripe webhook signing secret. Do not disable
signature verification.

Correct the Stripe webhook signing secret (or use the reviewed Checkout reconciliation above), then
replay the original event from Stripe. A successful replay creates the normal settlement and
activation path.

## Enrolment 1 incident evidence (2026-10-26)

The following evidence was collected read-only from the deployed environment before any recovery:

- Enrolment `ENR000001` (id 1), Student 3, Teacher 7, Course 28, lifecycle `authorised`.
- Accepted arrangement 1: 1 session/week, 30 minutes, `Asia/Tehran`, commencement window
  2026-11-01 07:15:00 to 2026-11-08 07:15:00.
- Post-intro preparation 2: `checkout_pending`; offer 1, pattern 1, reservation 1.
- Checkout session 1: state `open`, 25000 AUD, Stripe test mode.
- No `commercial_purchases`, payment evidence, settlement, activation job, Term, Teacher Assignment
  or Lesson row existed.
- All eight received Stripe webhook receipts were refused with `signature_invalid`.
- Read-only Stripe verification found the exact Checkout session `complete`/`paid`, 25000 AUD,
  attempt and provider account matching, and the exact completion Event at
  `2026-10-26 01:26:53`.

Therefore the lifecycle stopped at **verified webhook intake**, before settlement. The missing
Term/Lessons are not caused by the empty legacy scheduling fields on the Enrolment: the authoritative
weekly arrangement is the accepted arrangement/pattern, not `started_at`/`preferred_weekday` on the
legacy Enrolment columns. The Student Portal failure was independently caused by the student enrolment
read requiring a Teacher Assignment/Term that did not exist yet.

The reviewed recovery is:

1. Fix the Stripe webhook signing secret, or use `wp dzn-post-intro recover 1 --user=1` under review
   to reconcile the exact paid Checkout session.
2. Confirm the recovery inspection shows `payment_verified=true`.
3. Run the reviewed recovery; it creates one Term, one initial Teacher Assignment and 12 canonical
   weekly Lessons from pattern 1.
4. Verify Student and Teacher portals show the same 12 classes.
5. Confirm no duplicate purchase, settlement, job, Term or Lesson was created.

No production record was mutated while producing this report.
