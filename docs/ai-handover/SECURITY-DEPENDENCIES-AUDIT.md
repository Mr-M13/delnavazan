# Security and dependency audit — Phase 5 (2026-11-12)

**Scope:** Read-only targeted source scan of handover snapshot `251461bf`. Not a penetration test, complete dependency audit, or current-production verification. No Codex notification or DeepSeek availability files changed.

## Confirmed source observations
- Public REST routes in `RecordingServiceRestController`, `StripeWebhookController`, `TelnyxWebhookController`, `LiveKitWebhookController` and `PortalPublicActionController` intentionally register `permission_callback='__return_true'`. This is **not sufficient to classify them as unauthenticated vulnerabilities**: controllers perform service signatures, webhook verification, or capability-token checks inside handlers. Audit the complete verifier chains before rating risk.
- `platform/src/Integrations/Payment/Stripe/StripeWebhookController.php:129-160` passes raw request body to the canonical intake/receipt, with prechecks for method, HTTPS, content type, size and request shape. The trust decision is delegated to the payment intake service.
- `platform/src/Integrations/Notifications/TelnyxWebhookController.php:10-38` delegates signature validation to `TelnyxWebhookService`, designed around Ed25519 verification. No webhook payload is echoed to the client.
- `platform/src/Integrations/LiveKit/LiveKitWebhookController.php:17-46` validates webhook signature before parsing events.
- `platform/src/Portals/PortalPublicActionController.php:7-40` public join and absence routes use capability services, fixed rate-limit buckets and no-store/no-referrer headers. The rate limiter is explicitly **fail-open** on infrastructure errors; assess abuse tolerance and traffic-level protection separately.
- A scoped `grep` across `platform/src` found no direct `eval(`, `unserialize(`, `shell_exec(`, `exec(`, `passthru(` or `system(` uses. This does not exclude indirect invocation, other directories or runtime configuration.
- Scoped repository grep found no obvious embedded private-key PEM header or actual `sk_live_`/webhook secret; matches observed were documentation, validation patterns and redaction rules. No secret scanner or Git history scan was performed.
- No tracked `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `yarn.lock` or `pnpm-lock.yaml` was found in the snapshot. This limits automated ecosystem dependency inventory and SCA. Check runtime PHP/WordPress/plugin dependencies, third-party bundled JS and external service APIs manually.
- Bundled `theme/assets/js/vendor/livekit-client.umd.min.js` and substantial `platform/src/Integrations/LiveKit` integration remain despite the intended Google Meet classroom architecture. Do not delete until references, feature flags and production routing are proven unused.

## Risk and improvement register
**SEC-02 (medium, validate):** Public capability endpoints' rate limiting fails open when its backing infrastructure fails. Assess WAF/proxy limits, capability entropy/expiry, token replay and denial logging. Avoid turning legitimate join links into brittle fail-closed flows without impact analysis.

**SEC-03 (high, review needed):** Trace provider webhook verification end to end: raw-body signature, timestamp tolerance, replay/idempotency, account/environment binding, secret rotation and failure response. No exploit confirmed.

**DEP-01 (medium):** No tracked standard PHP/Node dependency manifests in this snapshot. Document exact supported WordPress/PHP versions, bundled library versions/licences, external service APIs and update/patch process. Determine whether manifest omission is intentional.

**DEP-02 (medium):** Legacy LiveKit runtime and bundled JS increase maintenance and potential attack surface if enabled. Inventory actual registrations, active options, templates and route exposure before decommissioning.

**SEC-04 (high, next):** Review capability/nonce checks on privileged admin-post, AJAX and REST actions; object-level ownership checks, CSRF, output escaping, SQL preparation, sensitive logs, credential handling and direct-object-reference risks. This is not yet complete.

## Test plan
- Automated SAST plus focused auth/IDOR and CSRF tests with isolated fixtures.
- Webhook invalid/replayed/mismatched-account signatures, secret rotation and malformed payloads.
- Capability-token expiry, wrong owner, replay, brute force and rate-limiter outage tests.
- WordPress/PHP compatibility matrix and JS vendor library version/licence/CVE inventory.
- LiveKit feature flag and route inventory, then controlled removal proposal if unused.

**No application changes, merges, deployments or destructive cleanup.**
