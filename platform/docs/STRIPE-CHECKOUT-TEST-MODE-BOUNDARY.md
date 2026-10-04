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

The success and cancel URLs return to the Student Portal with an opaque attempt UID for display correlation. Loading either URL is not evidence of payment. Settlement continues through verified provider events and `CommercialPaymentService`.
