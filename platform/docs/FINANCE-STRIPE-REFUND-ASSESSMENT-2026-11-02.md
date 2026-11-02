# Integrated Stripe refund — implementation assessment

**Date:** 2026-11-02
**Status:** assessment only; no refund action is enabled.
**Decision:** the existing provider binding and webhook evidence are insufficient for a safe integrated Stripe refund. Manual/offline refunds remain the separate recorded path.

## 1. What the current evidence actually contains

### Standard student Checkout

- `checkout_sessions` stores the attempt, amount, currency, state and a **digested** provider reference. It deliberately has no raw `stripe_session_id`, checkout URL or provider payload.
- `CheckoutSessionReferenceService` stores an encrypted `cs_test_…` Checkout Session id in `provider_projection_secrets` (`mapping_kind=checkout_session`). The binding accepts only test-mode `cs_test_` references.
- `commercial_payment_evidence` stores a **digest** of the provider evidence reference and fact; it does not store a PaymentIntent or charge id.
- `payment_provider_event_receipts` and `payment_provider_events` store request/payload digests, not the raw Stripe event or `data.object`.
- `StripeEventTranslator` normalises `charge.refunded`, `refund.created` and `refund.updated` into `refund_recorded`, but the intake path records provider-neutral refund evidence; it does not provide a locally verified PaymentIntent/charge identifier for a new outbound refund.

### Introductory payments

- `intro_checkout_sessions` likewise stores a provider reference digest and the encrypted test-mode Checkout reference. There is no verified PaymentIntent/charge reference.
- `intro_payment_events` records accepted provider events and digests, not a reusable charge reference.

### Provider binding

- `StripeCheckoutAdapter` is explicitly test-mode only (`DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED` + test vault + test environment).
- `StripePaymentAdapter` refuses before an outbound call unless a provider account has an enabled execution state, configured credentials and live execution authority; live execution is empty in this build.
- The platform’s stated boundary is that a completed Checkout or an API request is not settlement; only accepted evidence is settlement.

## 2. Why a Checkout Session id is not enough

A Checkout Session is a container. A refund is created against a PaymentIntent or charge, not against the Session id. The local evidence has:

- an encrypted `cs_test_…` Session id (test mode only),
- digested event/reference facts,
- no verified `pi_…` or `ch_…` reference bound to the settled obligation and Stripe account.

To obtain a PaymentIntent from a Session, the platform would have to make a live provider retrieval, map `payment_intent` back to the obligation, verify the account and amount, and then create a refund. That is a new provider authority, not a display or reconciliation change.

## 3. What a guarded implementation would require

Before any administrator action is enabled:

1. **Verified reference binding.** Persist an encrypted, verified PaymentIntent or charge reference from the signed webhook (or a verified provider retrieval) and bind it to the settled obligation, provider account and currency. A Session id alone is insufficient.
2. **Live-mode refund execution authority.** A capability distinct from the read-only payment view, with explicit live-mode authorisation and the existing provider-account execution gates. Test-mode-only must remain test-mode-only.
3. **Refund request state machine.** `requested → pending → succeeded | failed`, driven by accepted Stripe refund evidence/webhooks. An API 2xx must not be recorded as `succeeded`.
4. **Remaining refundable enforcement.** Cap at the settled amount minus accepted refunds and outstanding reservations, with a concurrency-safe reservation/command key and an idempotency key sent to Stripe.
5. **Duplicate and replay handling.** One durable command per refund request; a repeated request converges on the recorded state and never creates a second refund.
6. **Evidence and reconciliation.** Record the provider refund reference digest, amount, currency, account, reason, operator, request instant and webhook outcome. Never write the manual Iran/Australia ledger unless staff explicitly enter a ledger transaction.
7. **Permissions and audit.** A narrow administrator-only capability, a reason, a confirmation step, and an audit event. The existing `RefundReviewService` remains the human review authority.

## 4. What this change does instead

- Adds `StripeRefundAssessmentService`, a read-only assessment that:
  - identifies whether a settlement was Stripe or manual;
  - computes the remaining refundable amount;
  - verifies that a retained Checkout reference exists and whether it is test/live;
  - reports that the retained reference is a Checkout Session, not a PaymentIntent/charge;
  - reports the absence of live refund execution authority and a refund-result webhook consequence.
- Shows the assessment in **Student payments → Manual refunds** with no refund button.
- Keeps the administrator-only manual/offline refund path unchanged and clearly labelled.
- Keeps both refund paths out of the manual ledger unless staff explicitly record a ledger transaction.

## 5. Acceptance boundary

- No Stripe API call is made by the assessment (`wp_remote_*`, `curl_` and `api.stripe.com` are absent).
- No live refund, charge or email is made during this change’s tests.
- A future integrated refund may be implemented only after the evidence and authority in §3 exist and have their own runtime proof.
