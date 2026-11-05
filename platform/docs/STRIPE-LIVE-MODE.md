# Stripe Live Mode: configuration, webhooks and activation

This document describes the dashboard-managed Stripe Live Mode extension. It is an extension of the
existing payment-execution seam; it does not move settlement authority, replace the ledger or add a
second secret store.

## Environment and accounts

| Mode | Account selector | Webhook URL |
| --- | --- | --- |
| Test | `stripe_test` | `https://delnavazan.com/wp-json/delnavazan-platform/v1/payment-provider-events/stripe/stripe_test` |
| Live | `stripe_live` | `https://delnavazan.com/wp-json/delnavazan-platform/v1/payment-provider-events/stripe/stripe_live` |

The live URL is only shown as ready after the `stripe_live` account row exists. The dashboard derives the
URL from the registered account selector; it never invents a live selector.

Test and live credentials are separate rows in the existing encrypted payment-secret vault. A secret is
never returned to the browser or written to a log. Live secrets are only readable in a production
environment. Saving live credentials never activates live payments.

## Supported webhook events

Required by the deployed adapter (also shown in the dashboard):

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `payment_intent.succeeded`
- `payment_intent.payment_failed`
- `charge.refunded`
- `refund.created`
- `refund.updated`

Additional events the translator can normalise safely (subscribe if needed):

`charge.succeeded`, `charge.failed`, `invoice.paid`, `invoice.payment_failed`,
`payment_intent.processing`, `payment_intent.requires_action`, `setup_intent.succeeded`,
`payment_method.attached`, `mandate.updated`, and the recurring families that are recorded as
`provider_recurring_semantics_unresolved`.

## API version and payload

The adapter does not pin a preview API version. Use the Stripe account's stable/default API version and
the **Snapshot** event payload format. The live webhook verification reads the destination's
`api_version` back from Stripe and records it with the verified snapshot; an empty or missing version
does not pass readiness.

## Activate live payments

1. Open Stripe Dashboard → Live Mode → Developers → Webhooks → Add endpoint.
2. Enter the live Delnavazan URL once the `stripe_live` account is registered.
3. Select the required events above (the full supported list is safe).
4. Choose Snapshot payloads and the account's stable API version. Save the endpoint.
5. Copy the endpoint signing secret (`whsec_…`).
6. In Delnavazan → Settings → Integrations → Stripe (also Finance → Settings → Stripe), enter the live
   publishable key, live secret key and signing secret. Save. Live payments remain inactive.
7. Press **Test live connection**. This is a read-only balance request and confirms `livemode: true`.
8. Press **Verify live webhook**. This reads the destination list from Stripe and checks URL, enabled
   status, required events and API version.
9. Send a webhook delivery (or complete an authorised low-value live verification) and confirm a
   verified receipt in the dashboard. A 200 response alone is not settlement.
10. When the checklist reaches **Ready**, tick the confirmation and press **Activate Live Payments**.

Deactivation stops new live charging immediately, keeps every credential and historical payment
record, and returns Checkout to the existing test configuration when test mode is available.

## Readiness stages

`Not configured → Configured → Verified → Ready → Active`

- **Configured**: the live account and encrypted credential records exist.
- **Verified**: Stripe accepted the live key and the live event destination is confirmed by Stripe.
- **Ready**: every blocking check passes (environment, TLS ingress, adapter/translator/port, account
  binding, credentials, webhook destination, schema 070, worker principal, reconciliation, activation
  consistency).
- **Active**: an administrator explicitly activated live payments after readiness.

API-key presence alone never reaches Verified or Ready.

## Webhook failure diagnosis

Every inbound delivery is receipted before parsing. Use the dashboard's recent delivery table:

- `webhook_account_unresolved` (404): the `stripe_live`/`stripe_test` account selector is not registered
  or not active.
- `webhook_secret_unconfigured` (503): no active encrypted signing secret for that account/mode.
- `signature_invalid` (400): the Stripe endpoint signing secret does not match the stored secret, or the
  exact raw body was altered.
- `signature_outside_tolerance` (400): clock skew over 300 seconds.
- `https_required` (400): WordPress cannot see TLS. Set the site behind TLS correctly or configure the
  `dzn_platform_payment_trusted_proxy` option with the trusted proxy header.
- `method_not_allowed`, `unsupported_content_type`, `empty_payload`, `payload_too_large`: Stripe is not
  delivering a normal JSON POST to the endpoint.
- `unmapped_provider_account` / `ambiguous_obligation_attribution`: the signed event reached the wrong
  account or lacks the required Checkout metadata.

A verified delivery is only evidence; settlement is recorded separately as accepted commercial payment
evidence.

## Rollback and historical isolation

`070_stripe_live_mode` adds `provider_mode` to `checkout_sessions` and `intro_checkout_sessions`, with
existing rows defaulting to `test`. Live and test Checkout identities (`cs_live_`, `cs_test_`) and
account digests are distinct. A live webhook can never be attributed to a historical test session, and
the test readiness/settlement queries remain scoped to the test account digest.

## Tests

- `platform/tests/stripe-live-mode-contract.php` — dashboard, readiness, vault, migration and mode gates.
- `platform/tests/stripe-live-mode-unit.php` — encrypted isolation, live connection, webhook destination
  verification, exact-body signatures, activation, rollback and test-mode continuity.
- Existing `stripe-*-contract.php` and `stripe-*-unit.php` guards continue to cover the test path,
  duplicate delivery, reconciliation and adapter boundaries.
