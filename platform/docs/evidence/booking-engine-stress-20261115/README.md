# Booking engine stress-test evidence — 2026-11-15

This directory contains the curated machine-readable results of the disposable
WordPress/MariaDB audit described in
`platform/docs/BOOKING-ENGINE-STRESS-AUDIT-20261115.md`.

## What is here

- `results.json` — every `AUDIT_RESULT` line emitted by the executed scenarios,
  plus environment, database and pure-test metadata.

It contains synthetic Teacher/Student/request identifiers only. It contains no
production data, real contact details, credentials or provider payloads.

## Environment

- WordPress 7.1.2, Delnavazan Platform schema 61.
- PHP 8.3.33 in the CLI container (host PHP 8.3.35), MariaDB 11.4.13.
- Disposable stack: `dzn-booking-audit`, loopback `http://127.0.0.1:18083`.
- Docker VM memory: approximately 3.8 GiB, which is why the 100-process
  concurrency workers use the test-only SHORTINIT bootstrap.
- No Google, Stripe, email, SMS or WhatsApp request was made.

## Reproduce

From the repository root, with a disposable stack already installed and its
runtime variables exported:

```bash
DZN_STRESS_SKIP_SCENARIOS=0 \
  bash platform/runtime/bin/run-booking-engine-stress.sh
```

The harness refuses to run unless:

- WP-CLI is present;
- `wp_get_environment_type()` is `local` or `development`;
- `home_url()` resolves to `127.0.0.1`, `localhost` or `::1`.

Targeted runs:

```bash
# Only scenarios A–G, no concurrency
DZN_STRESS_LEVELS="" DZN_STRESS_ACTIONS="" \
DZN_STRESS_PARTIAL=0 DZN_STRESS_PROCESS=0 \
DZN_STRESS_IDEMPOTENT=0 DZN_STRESS_CRASH=0 \
  bash platform/runtime/bin/run-booking-engine-stress.sh

# Only the 100-worker submission and acceptance bursts
DZN_STRESS_SKIP_SCENARIOS=1 DZN_STRESS_LEVELS=100 \
DZN_STRESS_ACTIONS="submit accept" \
DZN_STRESS_PARTIAL=0 DZN_STRESS_PROCESS=0 \
DZN_STRESS_IDEMPOTENT=0 DZN_STRESS_CRASH=0 \
  bash platform/runtime/bin/run-booking-engine-stress.sh
```

The runner writes per-worker logs and verify output under
`$TMPDIR/dzn-booking-stress-logs-$DZN_COMPOSE_PROJECT`.

## Executed vs not executed

Executed: allocation fairness, multi-instrument scope, mixed pressure,
lifecycle, availability boundaries, preferences and claims, failure injection,
2–100 concurrent submissions, 2–100 competing acceptances, partially
overlapping submissions, idempotent replay, two-worker expiry processing,
worker crash and retry, and a stop/start database outage.

Not executed: real Google Calendar/Meet calls, real email/SMS/WhatsApp
delivery, Stripe/payment execution, production data, and attendance/no-show
allocation effects (the allocation code does not read attendance; this is a
code-review finding only).
