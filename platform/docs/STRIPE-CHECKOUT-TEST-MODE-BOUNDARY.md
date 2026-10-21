# Stripe Checkout test-mode boundary

Hosted Checkout creation is available only when all of these conditions hold:

- `DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED` is explicitly defined as boolean `true`.
- `DZN_PLATFORM_PAYMENT_TEST_VAULT` is explicitly enabled for the disposable test environment.
- WordPress identifies the environment as `local`, `development`, or `staging`; production additionally requires the explicit pre-launch gate below.
- Exactly one active Stripe test-mode provider account is execution-enabled and has configured credentials.
- That account has both an `sk_test_` API key and a `whsec_` webhook signing secret in the existing encrypted provider-secret vault.

The adapter refuses unknown WordPress environments, live-mode accounts, absent or malformed secrets, missing webhook configuration, and ambiguous multiple-account selection. It never logs provider responses or secrets. Provider HTTP is sent only to Stripe's fixed API host, uses a server-derived idempotency key, disables redirects, and accepts only a non-live `checkout.session` response matching the durable attempt and canonical amount/currency.

CI does not define activation constants and uses source contracts/fakes only. A runtime test requires an operator to deliberately configure a disposable WordPress environment, test-mode provider account, encrypted test credentials, webhook endpoint, and worker/reconciliation readiness. This boundary does not authorize live credentials or live traffic.

Stripe Settings blocks credential writes and the Test Connection request in production by default; an unknown environment is also closed. Temporary pre-launch production acceptance requires all three independent gates to be boolean `true`: `DZN_PRELAUNCH_PRODUCTION_TEST_MODE_ENABLED`, the existing `DZN_STRIPE_CHECKOUT_TEST_MODE_AUTHORIZED`, and `DZN_PLATFORM_PAYMENT_TEST_VAULT`. Remove any one constant to disable production TEST mode immediately. The admin screen displays a prominent warning while the gates are active. Non-production configuration continues to use the existing staging/test path. Checkout activation still requires the explicit operator authorization and a complete sandbox credential set, and enables only the single Stripe TEST account.

The production path remains test-only: configuration accepts `pk_test_`, `sk_test_`, and `whsec_` values; provider accounts are created in `test` mode with execution disabled; Test Connection checks Stripe's `livemode: false`; Checkout accepts only `cs_test_` sessions and `livemode: false`. There is no production path for live credentials or live charging. The feature switch must remain off until an authenticated production data inventory confirms the academy is empty and a separately reviewed synthetic dataset/teardown is ready.

An open Checkout Session's provider ID and hosted URL are sealed with the provider-reference vault key and stored in `provider_projection_secrets`, bound to the local checkout-session row. The checkout-session table continues to hold only the keyed provider-ID digest. A repeated student checkout request reuses the stored hosted URL. The encrypted provider ID provides the exact reference for a later reconciliation retrieval instead of searching Stripe's list endpoint.

The Stripe adapter can retrieve only a supplied `cs_test_` ID through the fixed Checkout Session endpoint with redirects disabled and TLS verification enabled. It validates the returned ID, test-mode flag, payment state, amount/currency and the original attempt, obligation and account correlation metadata. A later application reconciliation service can consume these normalized facts.

The adapter can also search Stripe's retained Checkout completion events by type and creation time, then accepts only an event whose exact Session ID, test-mode flags, paid state, amount/currency and metadata all match. This supplies the provider event's own occurrence timestamp for offer-window validation. Stripe retains listable Events for up to 30 days; after that, the adapter leaves the case unresolved instead of inventing a payment time.

Payment Execution operators can request recovery for a local checkout attempt UID at `POST /wp-json/delnavazan-platform/v1/admin/payment-execution/checkout-reconcile`. The recovery path opens only the encrypted Session ID already stored for that attempt, verifies the current Stripe Session and matching retained success Event against its canonical correlation, then submits the provider event ID, amount, currency and occurrence time through `CommercialPaymentService` under the configured payment worker principal. R1 alone accepts or rejects the amount, currency and obligation against current commercial authority. The endpoint does not impersonate webhook signature verification or accept a Session ID, amount, currency, obligation reference, or payment status from the request. The action requires `dzn_manage_payment_execution` and a REST nonce. A missing reference, absent event, provider mismatch, stale commercial state, or missing worker principal stays unresolved.

Stripe Checkout completion events enter the existing signed event intake only for a test account, a `cs_test_` identity, and `livemode: false`. The event is correlated by its keyed Checkout Session ID digest to exactly one local session and that session's canonical obligation. Unpaid completion remains an attempt fact; paid completion submits the provider facts through `CommercialPaymentService`, which alone can settle the obligation. A matching metadata value by itself is never enough.

The authenticated `GET /wp-json/delnavazan-platform/v1/student/checkout-status?attempt_uid=…` read returns only the canonical payment state, checkout state, and whether the student may continue, resume, or retry. It verifies current student ownership and the offer/obligation/session relationship. Loading Stripe's return URL never changes payment state.

The success and cancel URLs return to the Student Portal with an opaque attempt UID for display correlation. Loading either URL is not evidence of payment. Settlement continues through verified provider events and `CommercialPaymentService`.

## Offline E2E evidence

CI runs `platform/tests/stripe-checkout-test-mode-e2e-contract.php`. It verifies that Checkout creation metadata, signed-webhook verification, exact session and obligation attribution, canonical settlement, replay convergence, and the Student return read stay connected without granting the return URL settlement authority.

For the durable database proof, run `platform/runtime/bin/run-stripe-checkout-test-mode-e2e.sh` in the existing offline disposable runtime after its cached-image prerequisites are available. The runner performs a fresh local install, creates only synthetic records and a generated per-run vault value, and sends a locally signed webhook body directly to the intake service. It never calls Stripe, creates a live or test Stripe Checkout Session, or uses a credential from source or the environment.

## Production seam and account-reference repair

The plugin bootstrap registers the Stripe provider-event translator and payment-execution port on `plugins_loaded`, before the webhook REST routes. Registration is idempotent and does not add Stripe to `LIVE_EXECUTION_PROVIDERS`, so live execution remains closed. The runtime E2E now asserts this process state instead of registering the seam itself.

The dashboard-managed Stripe test account has one canonical, non-secret reference: its stored `reference_code` must hash to `account_reference_digest`, which is what Checkout metadata carries and what signed webhook intake proves. The dashboard save registers `account_reference='stripe_test'`; migration `047_stripe_canonical_account_reference` repairs only the known legacy `delnavazan-stripe-test` digest in place and refuses a digest collision. A credential source that cannot prove the canonical binding fails closed instead of presenting metadata the intake would refuse.

Deliberate test Checkout activation and deactivation are append-only account transitions with authenticated operator evidence. Readiness reports configuration readiness, deliberate Checkout activation and verified end-to-end settlement separately; no page can report configuration ready merely because the classes exist. The E2E exercises the real dashboard save, encrypted persistence, Test Connection, hosted Checkout adapter, signed webhook, account-mismatch refusal, replay convergence and settlement readiness without contacting Stripe.
