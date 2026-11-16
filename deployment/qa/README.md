# Read-only site QA

Run against production by default: `bash deployment/qa/site-smoke.sh`

Choose another target with `bash deployment/qa/site-smoke.sh https://site.example`. Add `true` as the second argument when that target is expected to be noindex, for example a staging hostname: `bash deployment/qa/site-smoke.sh https://staging.example.test true`.

This is read-only and never sends credentials, writes data or submits a booking.

## Coverage

- Public: HTTPS homepage, booking, enrol, login, 404, public indexing state, and available or protected WordPress and Rank Math sitemaps. The optional noindex mode requires noindex headers/meta and unavailable sitemaps.
- Redirect safety: followed redirects must finish on the supplied site origin; dashboard must redirect to that origin's `/login/`.
- Logged-out access: student and teacher portals redirect to `/login/` with a return target; operations keeps its controlled login-required page.
- REST availability: the WordPress API index advertises the Platform namespace and its public booking-options endpoint returns JSON without submitting a request.
- Visible PHP errors on every fetched HTML route.

This runner performs read-only HTTP checks only. It does not deploy code or create test identities/data. Do not use it as evidence that a synthetic data lifecycle is safe to run.

## Read-only production release diagnostic

Run from GitHub Actions against the exact current `main` SHA:

```sh
gh workflow run diagnose-production-release.yml --ref main \
  -f commit_sha=<full-current-main-sha>
```

Or on an approved operator workstation with the protected production SSH account:

```sh
PRODUCTION_SSH_USER=<restricted-user> \
  bash deployment/qa/production-release-diagnostic.sh <full-current-main-sha>
```

The payload is SELECT/read-only and never invokes provisioning, reconciliation, retries, WP-Cron
events, Google APIs, bookings, notifications or provider mutations. Its `meet_operational` section
reports effective feature flags and their source, worker hook/schedule presence, compact organizer
OAuth readiness, and redacted rows for lessons 22/23 and booking confirmation #9. It does not contain
tokens, e-mails, client identifiers or provider payloads. See
`platform/docs/GOOGLE-MEET-RECONCILIATION-2026-11-04.md` for interpretation and safe remediation.

## Go-live test-data purge

`deployment/qa/purge-test-data.sh` is the guarded, operator-only one-shot purge for the pre-launch
window. It removes all Student/Teacher/booking/class/payment/portal test data, keeps the retained
administrator (`hamed.moqadasi@yahoo.com`) and the catalogue/settings/integrations, and preserves
the two dedicated automation identities.

```sh
export PRODUCTION_SSH_USER=<protected-production-ssh-user>
MAIN_SHA="$(git rev-parse origin/main)"

# 1. Read-only inventory; prints the pre-state fingerprint. Deletes nothing.
bash deployment/qa/purge-test-data.sh report "$MAIN_SHA"

# 2. Destructive run, bound to the exact pre-state and a verified DB export.
bash deployment/qa/purge-test-data.sh execute "$MAIN_SHA" <fingerprint> PURGE_ALL_TEST_DATA
```

The classification lives in `purge-test-data-tables.php`; `purge-test-data-contract.sh` (run by the
PR gate) proves every schema table is classified and that the guard rails are present. The payload
never calls a provider. Follow `docs/GO-LIVE-TEST-DATA-PURGE-RUNBOOK.md` for approvals, maintenance
mode, verification, provider-side cleanup and rollback. Agents never execute the destructive mode
against production.
