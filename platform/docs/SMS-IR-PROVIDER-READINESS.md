# SMS provider routing and SMS.ir activation

SMS is an independently enabled notification channel. It is never a fallback for WhatsApp or another SMS provider.

## Administrator configuration

Settings > Integrations > SMS contains two saved provider preferences:

- Iranian mobile destinations (`+989` followed by nine digits): **SMS.ir** by default.
- All other destinations: **Telnyx** by default.

Routing depends on the normalised destination number, regardless of whether the recipient is a teacher or student. Admins with provider-integration management access can select either provider for either destination group. The settings are stored in `dzn_sms_provider_routing`; existing settings default to the choices above. Selecting an unavailable provider does not silently use the other provider. This is an explicit configuration choice, not automatic fallback.

## SMS.ir readiness (not activated)

1. Verify SMS.ir account, sender/line, API endpoint, authentication and Iran delivery rules against current provider documentation.
2. Implement and test a dedicated SMS.ir `NotificationTransportPort` adapter, secure encrypted/write-only credentials, delivery receipts and idempotent retry behaviour.
3. Register an SMS transport binding with `channel=sms`, `provider=sms_ir`, and `configured=false` until credentials and sender are verified. Telnyx bindings may omit `provider` for compatibility.
4. Test Iranian and overseas destinations for both teachers and students, provider unavailability, consent, suppression, retries and duplicate prevention.
5. Only set `configured=true` after approved live tests and a rollback plan.

No SMS.ir account, credentials, live send or operational adapter exists in this preparation. A destination mapped to an unconfigured SMS.ir binding cannot be sent until setup is completed, unless an administrator deliberately changes its provider selection to Telnyx.
