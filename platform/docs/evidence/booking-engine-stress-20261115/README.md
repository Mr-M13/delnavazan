# Booking engine stress-test evidence — 2026-11-15

This directory holds the instructions for reproducing the disposable
WordPress/MariaDB audit described in
`platform/docs/BOOKING-ENGINE-STRESS-AUDIT-20261115.md`.

## Generated output is not committed

The audit emits machine-readable `AUDIT_RESULT` lines, per-worker logs, verifier
output and environment metadata. That output is **generated**, so it is not part
of the repository: per `AGENTS.md`, pre-merge commits carry authored sources and
reproducibility instructions, never generated artefacts.

The runner writes it outside the tracked checkout, under the disposable runtime
state directory:

```
$DZN_RUNTIME_STATE_DIR/booking-stress-evidence/$DZN_COMPOSE_PROJECT/
├── scenario-a.log … scenario-g.log     # committed AUDIT_RESULT lines per scenario
├── concurrency-setup.log
├── <action>-<level>/launcher.log       # worker stdout/stderr
└── <action>-<level>/verify.log         # committed-row verifier output
```

`DZN_RUNTIME_STATE_DIR` must already be outside the shared checkout — the
harness refuses any other value — so evidence can never end up inside a commit
by accident. The directory is deleted and recreated on each run.

The output contains synthetic Teacher/Student/request identifiers only. It
contains no production data, real contact details, credentials or provider
payloads. Attach it to a pull-request comment or an external record when a
review needs the raw numbers; do not `git add` it.

## Environment

- WordPress 7.1.2, Delnavazan Platform schema 61.
- PHP 8.3.33 in the CLI container (host PHP 8.3.35), MariaDB 11.4.13.
- Disposable stack: `dzn-booking-audit`, loopback `http://127.0.0.1:18083`.
- Docker VM memory: approximately 3.8 GiB, which is why the 100-process
  concurrency workers use the test-only SHORTINIT bootstrap.
- No Google, Stripe, email, SMS or WhatsApp request was made.

## Reproduce

From the repository root, with the three cached images already present
(`mariadb:11.4`, `wordpress:php8.3-apache`, `wordpress:cli-php8.3`) and the
runtime variables exported:

```bash
export DZN_RUNTIME_STATE_DIR=/tmp/dzn-booking-stress-runtime
export DZN_COMPOSE_PROJECT=dzn-booking-stress-runtime
export DZN_WP_PORT=18090

# 1. Provision the disposable stack (MariaDB + WordPress + the candidate plugin)
platform/runtime/bin/reset-disposable.sh

# 2. Run every scenario and the concurrency matrix
platform/runtime/bin/run-booking-engine-stress.sh

# 3. Dispose of it
platform/runtime/bin/destroy.sh YES
```

`reset-disposable.sh` is required: the stress runner deliberately does not
provision, so that a run can never silently land on a stale or shared stack.

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

`DZN_STRESS_LEVELS` and `DZN_STRESS_ACTIONS` apply their documented defaults
only when **unset**. An explicitly empty value is an empty selection and
disables that loop, so the scenarios-only command above really does skip the
concurrency matrix.

Every selection is validated before any derived path is created or removed:
levels must be plain integers (`1`…`DZN_STRESS_MAX_LEVEL`, default `1000`) and
actions must be one of `submit`, `accept`, `submit_partial`, `process`,
`idempotent`, `submit_crash`. Anything else aborts with exit status `2` before
Docker starts and before the disposable-path guard is asked to delete anything.

Print the resolved plan without touching Docker or the filesystem:

```bash
DZN_STRESS_VALIDATE_ONLY=1 DZN_STRESS_LEVELS="" DZN_STRESS_ACTIONS="" \
  bash platform/runtime/bin/run-booking-engine-stress.sh
```

## Safety regressions for the runner itself

`platform/tests/booking-stress-runner-contract.sh` runs on a plain host with no
Docker and no WordPress. It proves that path-shaped, unsupported, signed,
zero and over-ceiling selections are refused, that unrelated files survive every
rejected invocation, that the runner deletes only through the validated
disposable-path primitive, and that empty selections plan zero jobs while unset
selections plan the documented default matrix.

`platform/tests/booking-engine-capacity-unit.php` covers the overlap authority:
adjacent lessons, buffer collisions, overlapping live offers, confirmed double
bookings, legitimate cross-Teacher competing offers, and single-counting across
lifecycle states.

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
