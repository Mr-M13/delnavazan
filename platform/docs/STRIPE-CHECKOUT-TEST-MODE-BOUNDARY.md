# Stripe Checkout test-mode boundary

Hosted Checkout creation is available only when all of these conditions hold:

- `DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED` is explicitly defined as boolean `true`.
- `DZN_PLATFORM_PAYMENT_TEST_VAULT` is explicitly enabled for the disposable test environment.
- WordPress identifies the environment as `local`, `development`, or `staging`.
- Exactly one active Stripe test-mode provider account is execution-enabled and has configured credentials.
- That account has both an `sk_test_` API key and a `whsec_` webhook signing secret in the existing encrypted provider-secret vault.

The adapter refuses production/unknown WordPress environments, live-mode accounts, absent or malformed secrets, missing webhook configuration, and ambiguous multiple-account selection. It never logs provider responses or secrets. Provider HTTP is sent only to Stripe's fixed API host, uses a server-derived idempotency key, disables redirects, and accepts only a non-live `checkout.session` response matching the durable attempt and canonical amount/currency.

CI does not define either activation constant and uses source contracts/fakes only. A runtime test requires an operator to deliberately configure a disposable non-production WordPress environment, test-mode provider account, encrypted test credentials, webhook endpoint, and worker/reconciliation readiness. This boundary does not authorize live credentials, live traffic, production deployment, or production payment activation.

An open Checkout Session's provider ID and hosted URL are sealed with the provider-reference vault key and stored in `provider_projection_secrets`, bound to the local checkout-session row. The checkout-session table continues to hold only the keyed provider-ID digest. A repeated student checkout request reuses the stored hosted URL. The encrypted provider ID provides the exact reference for a later reconciliation retrieval instead of searching Stripe's list endpoint.

The Stripe adapter can retrieve only a supplied `cs_test_` ID through the fixed Checkout Session endpoint with redirects disabled and TLS verification enabled. It validates the returned ID, test-mode flag, payment state, amount/currency and the original attempt, obligation and account correlation metadata. A later application reconciliation service can consume these normalized facts.

The adapter can also search Stripe's retained Checkout completion events by type and creation time, then accepts only an event whose exact Session ID, test-mode flags, paid state, amount/currency and metadata all match. This supplies the provider event's own occurrence timestamp for offer-window validation. Stripe retains listable Events for up to 30 days; after that, the adapter leaves the case unresolved instead of inventing a payment time.

Stripe Checkout completion events enter the existing signed event intake only for a test account, a `cs_test_` identity, and `livemode: false`. The event is correlated by its keyed Checkout Session ID digest to exactly one local session and that session's canonical obligation. Unpaid completion remains an attempt fact; paid completion submits the provider facts through `CommercialPaymentService`, which alone can settle the obligation. A matching metadata value by itself is never enough.

The authenticated `GET /wp-json/delnavazan-platform/v1/student/checkout-status?attempt_uid=…` read returns only the canonical payment state, checkout state, and whether the student may continue, resume, or retry. It verifies current student ownership and the offer/obligation/session relationship. Loading Stripe's return URL never changes payment state.

The success and cancel URLs return to the Student Portal with an opaque attempt UID for display correlation. Loading either URL is not evidence of payment. Settlement continues through verified provider events and `CommercialPaymentService`.
