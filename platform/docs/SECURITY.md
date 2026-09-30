# Delnavazan Platform Security Architecture

> **Current-source reconciliation (2026-09-27):** Schema-31 Phase-W registers three public routes
> whose WordPress permission callback is intentionally public, but each route independently requires
> the exact public-action option value and a purpose-bound capability before it can act. Failures are
> normalized to a non-enumerating response. The exact grants, owner seams, evidence and prohibited data
> are in the [Portal authorization registry](PORTAL-AUTHORIZATION-REGISTRY.md). Registration is not
> authorisation for public enablement, deployment or cutover.

## 1. Security objective

The platform protects academy identities, lesson state, attendance evidence,
contact information, provider credentials, financial records, and public actions
while retaining the minimum attack surface needed during coexistence with
Amelia.

Security boundaries are architectural. Hiding a UI element, possessing an
external ID, matching an email, or receiving a provider callback does not grant
authority by itself.

## 2. Trust boundaries

Treat all of the following as untrusted until verified:

- browser and portal input;
- WordPress request parameters and uploaded files;
- Amelia hooks, API responses, table values, and cabinet tokens;
- Google OAuth callbacks and API payloads;
- Meta webhook events and delivery IDs;
- Stripe events and customer/payment references;
- historical snapshots and exports;
- names, emails, phone numbers, URLs, timezones, and provider status strings.

Core application services validate commands and authorize the acting principal.
Integration adapters validate provider authenticity and translate data; they do
not bypass Core rules.

## 3. Authorization and CSRF protection

- Every administrator mutation requires an explicit WordPress capability and an
  intent-specific nonce.
- Object-level authorization is checked server-side for the exact Core record.
- Authenticated portals (where they exist) derive authorization from an
  authenticated, mapped principal, never from a browser-only flag, email
  parameter, or external ID. This holds for authenticated surfaces only.
- The separately authorised public-capability path (Phase 2A.2-W) has no session
  principal. It requires the exact `dzn_platform_portal_actions === 'enabled'`
  option gate plus a purpose-bound capability (`lesson_join` or `lesson_absence`)
  bound to the exact authorised Lesson and applicable schedule version, an
  unexpired token, and an allowlisted sealed HTTPS join host. Handle/token
  material is stored only as a digest; refusals are a uniform non-enumerating
  `404` with `Cache-Control: no-store` (the refusal response does not send
  `Referrer-Policy`); successful redirect and absence responses send
  `Referrer-Policy: no-referrer`. Every public-route request also passes a
  best-effort `PortalRateLimiter` admission before the option gate and before
  any capability verification — keyed on a salted digest of the client network
  signal plus the presented handle — and a refusal takes the same uniform
  non-enumerating `404` and is recorded as `portal_rate_limited`. The bucket is
  the invoked route's own fixed surface constant, passed by that route's
  registered callback and never read from the request, so no query value, path
  suffix or query-style REST route can select or collapse a limiter bucket or a
  denial-audit row. The admission is fail-open: a salt or cache failure is
  allowed through, and `portal_rate_limited` is refused only after a completed
  `allow()` returns false. The numeric
  budget remains an owner decision. An absence confirmation delegates
  evidence-only to the Phase-P
  `CanonicalAttendanceIntakeService::submitCapabilityClaim()` without settling
  attendance. See the
  [Portal authorization registry](PORTAL-AUTHORIZATION-REGISTRY.md).
- Read and write capabilities are separate where practical.
- Background tasks run with a named system actor and bounded service permission.
- Hiding controls is presentation only and never the sole control.
- State transitions use allowlisted commands and reject invalid prior states.

## 4. External secrets

Reusable OAuth credentials, API tokens, webhook secrets, and similar values must
use authenticated encryption at rest.

Minimum requirements:

- prefer Sodium secretbox or AES-256-GCM with unique random nonces;
- derive application key material from WordPress secure salts where appropriate,
  with domain separation and a stored key/cipher version;
- never commit secrets or production configuration to Git;
- keep encrypted options non-autoloaded where feasible;
- redact secrets from logs, notices, exports, diagnostics, exceptions, and docs;
- never send refresh tokens or provider secrets to a browser;
- support key rotation or explicit re-authorisation when salts/keys change;
- fail closed and visibly when encryption/decryption is unavailable;
- limit which service can decrypt each secret class.

The existing Delnavazan Enhancements authenticated-encryption approach is a
proven starting concept. Its exact storage layout is not the permanent Core
schema and plaintext migration must never be logged.

## 5. OAuth security

Google and future OAuth integrations require:

- unpredictable state with short expiry;
- one-time consumption and replay rejection;
- binding to initiating principal, intended Teacher, client configuration, and
  validated return target;
- exact redirect URI validation;
- PKCE where supported and appropriate;
- minimum approved scopes;
- explicit validation of returned identity and granted scopes;
- encrypted renewable credentials;
- observable refresh failure and reconnection state;
- local disconnect plus provider revoke support;
- no authorization start URL for an unverified teacher session.

During coexistence, an Amelia employee session may be a verified input to the
Legacy Adapter, but the resolved authority must be a mapped Core Teacher. The
bridge remains short-lived and is retired with Amelia portal identity.

## 6. Google disconnect and revoke

Disconnect and revoke are separate recorded outcomes:

- **disconnect** immediately prevents Delnavazan from using the connection and
  removes or quarantines locally usable credentials according to policy;
- **revoke** requests invalidation from Google and records provider success or a
  retryable failure;
- a failed provider revoke never leaves the connection usable locally;
- reconnect creates a new consent lifecycle rather than reviving an invalidated
  token silently.

The product owner must approve retention and support handling for failed revokes
before implementation.

## 7. Webhooks and provider callbacks

- Verify Meta, Stripe, and other webhooks with the provider-supported signature
  or token mechanism over the exact raw request where required.
- Enforce HTTPS, request-size limits, supported methods, timestamp/replay windows,
  and content-type expectations.
- Store a provider event ID or deterministic fingerprint for idempotency.
- Process only recognised event types and locally known object/message IDs.
- Preserve event time separately from receive/process time.
- Do not regress a delivery state because events arrived out of order.
- Return provider-appropriate success for already processed duplicates.
- Queue or durably record work before acknowledging when loss would be material.
- Never let an external reference act as authorization for an unrelated Core
  record.

The current secret-URL webhook and shared Amelia callback observer are
transitional behaviours, not the final provider-authentication contract.

## 8. Public lesson actions

Public Join/Absence links must not expose predictable Lesson IDs. Use a random
public capability or a signed opaque token with:

- explicit action/purpose;
- binding to one Lesson and current schedule/capability version;
- strong unguessable entropy and constant-time signature comparison;
- absolute expiry;
- revocation/rotation on reschedule, cancellation, archive, or suspected leak;
- one-way storage or keyed verification where feasible;
- safe, non-enumerating invalid/expired responses;
- a confirmation page before state-changing absence action;
- idempotent final mutation and conflict handling.

A join action may redirect only to an allowlisted HTTPS meeting host or approved
provider resource. A click records evidence but never proves attendance.

## 9. Data validation and output

- Validate IDs, state transitions, timestamps, timezones, email, phone, URL,
  currency, and enum fields against explicit contracts.
- Normalize only after retaining required provenance; normalization must not
  silently merge people.
- Use prepared SQL for every dynamic query and bounded pagination for
  administrator lists, archive inspection, and data operations.
- Escape output for its exact context: HTML, attribute, URL, JSON, CSV, or email.
- Sanitize rich text through an explicit allowlist; do not execute unrelated
  global content filters or shortcodes at a public boundary.
- Validate redirect destinations and restrict cross-origin redirects.
- Reject nested/unexpected request shapes rather than coercing them to strings.

## 10. Logging and diagnostics

Logs must answer what happened without becoming a second sensitive database.

- Use correlation, migration, and event IDs rather than raw tokens.
- Redact credentials, OAuth codes, cabinet tokens, signed action tokens, full
  webhook URLs, and unnecessary personal data.
- Prefer stable error codes plus short safe messages.
- Record actor/source, Core entity ID, transition, outcome, and timestamp for
  privileged changes.
- Restrict detailed diagnostics to appropriate administrator capabilities.
- Define and enforce retention per log/evidence class.
- Never place production phone numbers, emails, access tokens, or private IDs in
  repository documentation or test fixtures.

## 11. Data lifecycle and deletion

- Soft archive is the default business workflow.
- Restore is explicit and audited.
- Permanent deletion requires a separately authorised retention operation with a
  narrowly resolved target set.
- Financial and attendance evidence is not erased by an unrelated archive or
  provider deletion.
- Amelia source tables are never deleted at initial cutover.
- Backups and rollback artifacts have access controls and retention rules; they
  are not committed to Git.
- The approved historical Amelia archive is read-only, access-controlled, and
  retained under its explicit policy. Temporary working exports are deleted
  under an approved process after use.

## 12. Integration isolation

- Core does not call Meta, Google, Stripe, or Amelia APIs.
- Provider clients live in Integrations or Legacy Adapters.
- Notifications decides workflow eligibility; Meta transport only sends and
  reports provider results.
- Finance owns payability/rate decisions; Stripe transport cannot insert Lessons.
- Phase 2A.2-U (Schema 30, candidate) gives Finance five administrator-only capabilities, removes all of
  them from the Teacher role and any student role, exposes no Finance route or front-end surface, stores
  no payout, bank/IBAN/card or tax field, and limits every Finance write to the twenty-one declared
  Finance tables plus digest-only `platform_audit_events` evidence and the three identity-only §17
  `platform_outbox` intents — an audit row carries identifiers and digests only, and an outbox row
  carries no amount, period bound, currency, reason code, entity id, template, recipient or message body.
- Attendance owns evidence/outcome; Google adapter cannot directly publish a
  final state without the Attendance policy service.
- Existing Amelia-dependent runtime stays isolated in Delnavazan Enhancements.
  New Core code does not acquire Amelia data-model access; any later temporary
  bridge requires explicit bounded scope and separate authorization.

## 13. WordPress implementation baseline

When implementation is approved:

- PHP entry points block unintended direct execution;
- activation, migration, cron, AJAX, REST, admin-post, and public routes all have
  explicit authorization and replay/idempotency rules;
- custom REST fields and endpoints default to private;
- file upload types, size, ownership, and access are constrained;
- scheduled jobs use atomic locks and observable retry rules;
- uninstall preserves valuable data unless an explicit destructive constant or
  dedicated authorised workflow is approved;
- WordPress, theme, Amelia, and third-party plugin files are never modified.

## 14. Security verification gates

Before any production authority transfer:

1. threat model the changed trust boundary;
2. test capability, nonce, object-level authorization, and invalid-state paths;
3. test OAuth state replay, identity mismatch, scope mismatch, disconnect, and
   revoke failure;
4. test webhook signature failure, replay, duplicate, out-of-order, unknown-ID,
   and oversized payload handling;
5. test signed-action expiry, tampering, reschedule rotation, cancellation, and
   non-enumerating errors;
6. scan repository and build artifacts for secrets/private production data;
7. verify redaction in logs, admin notices, exports, and provider errors;
8. restore from the documented backup/rollback path in a representative
   environment;
9. run focused security review before merge and runtime validation before
   cutover.

## 15. Platform Phase 0 restriction

This file defines requirements only. It does not authorise code, credentials,
provider reconfiguration, production tests, or changes to Amelia.
