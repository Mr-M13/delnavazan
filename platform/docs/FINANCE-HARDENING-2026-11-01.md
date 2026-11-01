# Finance hardening — 2026-11-01

**Baseline:** `14855147572e6883f383b8d219c54b9a8789acd5` (PR #461)
**Schema identity:** 57 (additive migrations 060, 061, 062 and 063 remain inside the current 57 umbrella,
following the existing 058/059 convention).

## Root causes closed

1. **Refund cap was checked before any lock.** Two concurrent requests could both observe the same
   remaining balance. A reload also generated a new command key, allowing a duplicate partial refund.
2. **Evidence and review are separate transactions.** `CommercialPaymentService::ingest()` and
   `RefundReviewService::recordRefundEvidence()` each commit their own transaction, so a crash between
   them left evidence without a review and no durable recovery identity.
3. **Statement email was synchronous and not durable.** `wp_mail()` returning true was treated as the
   result, with no intent, lease, attempt history, retry or delivery distinction.
4. **Batch results collapsed draft, issue and email into one state.** A failed issue after a successful
   draft lost the draft identity in the result, and email had no queued/retryable state.
5. **Student payment history was hidden behind the checkout release flag.** A Student with accepted
   offline payments could see nothing when online checkout was disabled.
6. **Monthly statement work was manual only.** There was no recoverable, default-off draft-only job.
7. **No read-only finance exports.**

## Implemented

### Refund reservation authority (migration 060)

- `finance_manual_refund_reservations` with unique request key, evidence key and payload digests.
- `finance_manual_refund_events` append-only state history.
- The Student's `commercial_account_roots` row is locked while the refundable balance (settled minus
  accepted refunds minus still-reserved amounts) is read and the reservation is inserted.
- Reservation states: `reserved`, `evidence_recorded`, `review_open`, `evidence_failed`, `released`.
- Same key + same payload converges; same key + different payload fails with
  `manual_refund_request_conflict`; same payload with a new key converges on the existing reservation.
- A deterministic provider reference (`manual:refund:<reservation_uid>`) makes evidence replay converge.
- Evidence-to-review recovery: a retry from `evidence_recorded` creates or finds exactly one review.
- A pre-evidence reservation can be explicitly released; no evidence is deleted.
- No settlement, funding, Lesson, statement or ledger row is rewritten.

### Durable statement-email outbox (migration 061)

- `finance_statement_email_intents` (one per statement) and append-only
  `finance_statement_email_attempts`.
- States: `pending`, `leased`, `transport_accepted`, `retryable`, `failed`.
- Lease token and expiry allow a crashed worker to be reclaimed; the abandoned attempt is marked
  `lease_expired`.
- `transport_accepted` is explicitly **not** `delivered`; `delivered_at` remains NULL because no
  downstream delivery callback exists.
- Exponential retry is bounded by `max_attempts`; the worker is registered on the existing WordPress
  cron seam.
- Duplicate enqueue does not create a second intent or email.

### Batch and schedule accuracy (migration 062)

- Batch results now carry independent `draft_state`, `issue_state` and `email_state`, preserving a
  successful draft id when issue fails.
- Email is enqueued, not sent inline; batch results report `queued`/`transport_accepted`/`failed`.
- `finance_statement_batch_runs` stores one durable run per month/mode with attempt count, lease,
  state, result JSON and controlled error.
- Monthly scheduling is **default off**, draft-only, previous completed UTC month, never auto-issue or
  auto-email. A completed month is not drafted twice.

### Student payment history and receipts

- `StudentPaymentHistoryReadService` resolves the authenticated Student principal and returns only that
  Student's settled payments, outstanding obligations, refunds and receipt links.
- It is independent of `DZN_THEME_STUDENT_PAYMENTS`; the flag now controls checkout only.
- `StudentReceiptController` verifies a nonce, the Student principal and settlement ownership before
  rendering a branded receipt.

### Durable Student finance notifications (migration 063)

- `finance_notification_intents` stores one idempotent, retryable intent per finance event.
- Payment settlement and refund-review hooks enqueue notifications for accepted payments, recorded
  refunds, review decisions, completed refunds and dismissed reviews.
- Delivery reuses the existing branded `DelnavazanEmailRenderer` and records **transport accepted**,
  never confirmed delivery. `delivered_at` stays NULL.
- A notification failure is swallowed at the hook boundary and never reverses the financial fact.
- The aggregate-based notification authority has no authoritative payment-receipt event or recipient
  binding, so this finance outbox is the minimal durable seam; it does not create a second payment,
  refund or financial authority.

### Exports

- `FinanceExportService` provides read-only payments, refunds and statements CSV exports with stable
  identifiers, exact minor units, currency, status and CSV-injection neutralisation.
- Exports are capability-gated and never write financial state.

## Verification

- WordPress-free suite and static lint.
- Finance administration runtime (fresh install, migrations 060–062, tabs, batch, refund workspace).
- Manual payment/refund runtime (settlement, full refund, replay, over-refund refusal, route/resolve).
- Refund concurrency runtime (same-key convergence, different-key over-balance refusal, payload conflict,
  unauthorized/invalid request).
- Refund evidence/review recovery runtime (injected review failure, retry converges).
- Statement email outbox runtime (queue, duplicate, transport accepted, retryable failure, lease
  recovery, `delivered_at` NULL).
- Monthly schedule runtime (default off, enable actor, completed run, duplicate idempotency, history).
- Existing Stripe/booking regression suites remain part of the PR gate.

## Known limitations / unresolved decisions

- Statement and Student finance email delivery is **transport-accepted**, not confirmed delivered. A
  downstream provider delivery callback remains future work.
- The populated batch runtime is not added; the batch result state machine is source-contracted and the
  finance runtime executes the batch path with no candidates. A real multi-teacher fixture remains a
  follow-up.
- Ledger-to-settlement references are not added as columns. The existing evidence reference remains the
  explicit manual reference; exports are separate. No automatic ledger posting is introduced.
