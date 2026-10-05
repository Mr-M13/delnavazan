# Post-release launch-readiness checkpoint — 2026-10-06

## Release identity

- Branch: `codex/post-release-launch-readiness`
- Source/deployed build observed in production: `5fd8efe9bd230a3f7626ce202a1167180d6baeee` (`main-5fd8efe9bd23`)
- Production workflow reported by coordinator: `37386119352`
- Scope: read-only production admin inspection and source/QA review. No booking, payment, message, calendar event, teacher, student, or operational-exception mutation was performed.

## Verified production state

- System health reports Core schema ready, all listed core tables present, schema 37 current, and migrations 001–037 recorded.
- System health reports one open and zero acknowledged operational exceptions.
- The sole open exception is warning `booking_request_match_attention`, exception #1 / UID `01M45YDB68FPQR4E86CNY9Y9SC`, for booking request #1. Safe detail is `criteria=v1;reason=no_current_coverage`; retry is unavailable. This is an unresolved live coverage/coordination requirement, not a false health signal. An operator must coordinate the request through the booking/request-coordination authority and only then use the exception lifecycle appropriate to its outcome. This checkpoint does not resolve, acknowledge, dismiss, or retry it.
- Google Console reports OAuth client `not_configured` and connection test `not_run`. Deployment configuration must provide `DZN_GOOGLE_OAUTH_CLIENT_ID` and `DZN_GOOGLE_OAUTH_CLIENT_SECRET`; authorization and calendar projection were not exercised. The interface correctly exposes no credential value.
- Stripe test-mode readiness: adapter, webhook endpoint, schema, and reconciliation route are ready. Blocked: production environment (expected fail-closed), no single active enabled test account, no encrypted test API key, no encrypted webhook signing secret, deliberate test activation disabled, and no least-privilege payment worker principal. Production payment activation remains disabled; no provider traffic was sent.
- Dashboard aggregates show 1 teacher, 1 open booking request, 0 students, 0 active enrolments, 0 onboarding reviews, and 0 requests needing coordination. These counts do not by themselves prove journey readiness.

## Source/runtime finding

Google Console rendered each calendar projection state twice because the controller iterated the same projection-state aggregate twice. Removed the duplicate iteration and strengthened `platform/tests/google-projection-diagnostics-contract.php` to require exactly one rendering loop. This does not alter Google projection state or provider behavior.

## Verification limits in this checkout

- `platform/runtime/bin/run-pure-tests.sh` could not start: its pinned no-pull harness requires cached `mariadb:11.4`, which is absent. Direct `php` is also not installed, and Docker daemon access is denied in this environment.
- `deployment/qa/production-smoke.sh` could not resolve `delnavazan.com` or `www.delnavazan.com` from shell DNS. The authenticated production browser session did load the admin pages above, but this does not replace that complete public HTTP smoke suite.
- Consequently the source correction has regression coverage added but not executed here; exact-head CI/runtime verification remains required before merge.

## Readiness boundary

Production observation confirms the deployed build and schema health, not end-to-end launch readiness. Authenticated student/teacher/staff journeys, browser viewport checks, actual OAuth connection/projection, and any Stripe test-mode transaction remain unverified. Do not infer provider connectivity from configuration presence or test readiness from code registration alone. The open no-coverage exception and external configuration prerequisites above remain explicit operational gates.
