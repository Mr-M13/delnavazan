# Notifications, Stripe and background recovery audit — Phase 3 (2026-11-12)

**Scope:** read-only inspection of handover branch snapshot `251461bf`. No production verification or live provider calls.

## Confirmed architecture
- `platform/src/Integrations/Notifications/NotificationWorkerBootstrap.php:8-24`: notification worker is registered only when environment is staging/production and `NotificationRuntimeFactory::configured()` returns true; schedules five-minute WP-Cron, and rechecks eligibility on execution.
- `platform/src/Core/Application/NotificationDispatchWorker.php:8-41`: each pass recovers expired leases, reconciles SMS fallbacks, claims a notification attempt, hands it off, and records acknowledged, terminal or retryable outcomes.
- `platform/src/Integrations/Notifications/CompositeNotificationTransport.php:10-54`: WhatsApp-first routing; no immediate fallback on transient errors; separate durable SMS fallback after verified terminal failure. Recipient and rendered copy are resolved transport-side, not accepted from upstream command.
- `platform/src/Integrations/Payment/Stripe/StripeWebhookController.php:62-86,129-160`: public webhook intentionally uses provider signature verification in the payment intake authority and records inbound requests, including rejected method/shape. Public WordPress permission callback is not itself a demonstrated authentication flaw.
- `platform/src/Core/Application/Finance/LessonFinanceOrchestrationService.php:28-83`: finance capture returns `finance_pending` for failures and emits hooks; completion can persist independently. Verify durable finance recovery downstream.

## High-priority contract mismatch: notification policy vs requested UI
**NOTIF-01 (high product/UX priority, verified source mismatch against agreed requirements):** `platform/src/Integrations/Notifications/NotificationChannelPolicy.php:10-30` still requires catalogue channel eligibility, event-enabled status and category policy. Its `isMandatory()` delegates to catalogue mandatory status. `platform/src/Integrations/Notifications/NotificationTemplateLibrary.php:492,513-533` still maps `sms` to `sms_fallback`, forces both together in some configuration paths, and contains category-level security/essential/financial/operational policy and mandatory flags. This does **not** implement the desired independent per-event toggles for Portal, Email, WhatsApp, SMS fallback and primary SMS. Need trace admin UI persistence and all dispatch paths before modifying to avoid bypassing legally required communications or consent. This is a requirements/code mismatch, not proof that a recent pending PR hasn't already fixed it elsewhere.

## Reliability and recovery validation
**NOTIF-02 (high operational):** Verify `NotificationRuntimeFactory::configured()` in production and whether `dzn_notification_dispatch` is both scheduled **and actually executing**; report oldest queued, retryable, expired lease, terminal failures and SMS fallback ledger. A healthy WP-Cron schedule alone is insufficient.

**NOTIF-03 (high):** Test terminal WhatsApp failure → exactly one durable SMS fallback; transient WhatsApp failure → retry without SMS; acknowledged WhatsApp delivery → no fallback; explicit primary SMS selection → direct SMS independently, subject to consent and safety policy. Ensure admin toggle persistence and re-enablement.

**PAY-01 (high):** Trace Stripe signature/receipt → canonical verified settlement → `PostIntroSettlementHook` → activation job → enrolment and portal. Test duplicate/reordered webhooks, split instalments, delayed activation, expired hold and recovery. Do not equate HTTP response with completed enrolment.

**FIN-01 (medium/high):** Finance orchestration returns `finance_pending` on capture/payability failures; ensure a durable recovery job/exception exists and that operator UI can identify and retry without double capture.

## Suggested implementation boundaries (not authorised)
1. Audit existing notification catalogue, editor, channel policy, transport routing, persistence and tests together; propose a single canonical per-event channel policy and explicit primary-SMS vs fallback distinction.
2. Create one operator health dashboard with oldest-job age, retries, provider delivery state, recovery action and permission-gated diagnostics across notifications, payment, activation and finance.
3. Add integration tests for full payment-to-portal and WhatsApp/SMS state transitions before changing policy.

**No application changes, merges or deployments.**
