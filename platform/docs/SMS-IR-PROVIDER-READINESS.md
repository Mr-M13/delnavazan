# SMS.ir provider readiness (not yet activated)

The SMS channel is independent of WhatsApp. No automatic WhatsApp-to-SMS behaviour exists.

## Routing contract

- Students: Telnyx, regardless of destination.
- Teachers with Iranian mobile numbers (`+989` followed by nine digits): SMS.ir **only when its binding is configured**.
- Teachers with non-Iranian numbers: Telnyx.
- Until SMS.ir is configured, existing Telnyx SMS routing remains available for teachers. A configured SMS.ir transport failure is **not** retried through Telnyx.
- `recipient_kind` must come from the authorised notification record, not a caller-provided value.

## Activation checklist

1. Open and verify the SMS.ir account and permitted Iranian sender / line; confirm the current API endpoint, authentication, message templates and applicable domestic sending rules against the provider's documentation.
2. Implement and test a dedicated `NotificationTransportPort` adapter with secrets stored outside the repository. Do not invent or hardcode provider credentials.
3. Register a second `dzn_notification_transport_bindings` entry with `channel=sms`, `provider=sms_ir`, `configured=false` until the adapter and sender are ready. Existing Telnyx SMS bindings may omit `provider` (treated as `telnyx`).
4. Test an Iranian teacher, an overseas teacher, and a student with an Iranian phone number; verify the selected provider, consent/suppression, retries, idempotency, provider delivery receipts and no cross-provider duplicate sends.
5. Enable `configured=true` only after an approved live test and an operational rollback plan.

No SMS.ir account or live integration is required to merge the routing readiness change. Do not claim SMS.ir is operational until the provider adapter and account are verified.
