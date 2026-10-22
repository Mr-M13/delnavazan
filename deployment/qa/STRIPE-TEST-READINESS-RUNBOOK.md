# Stripe test readiness: investigation and guarded operations

## Evidence and remaining uncertainty

Investigated `main` at `625b78c1ca0b795cedfd12a23de846dd424023f6`.
PR #330 commit `7f47198f3f1a01378652225dbdd8d0bf11d075db` and merge
`80846a9b` are ancestors of that main. This proves source inclusion, not production deployment.
GitHub API access is blocked by the onboarding network policy, and no production SSH binding is
present in this environment. Production SHA, role state, principal option and accepted settlement
are therefore **unverified** here. The reported dashboard observations are not fresh runtime evidence.

#330 is a capability bootstrap repair, **not a numbered database migration**. It changes the Phase T
capability marker to `2a2t2` and installs/repairs `dzn_payment_worker` in `Migrator::ensure_capabilities()`.
It deliberately neither creates a user nor sets `dzn_platform_payment_worker_principal`.
The schema remains independent (current main declares 48). A current schema alone cannot prove
that #330 is installed; neither can the marker alone prove the role was repaired successfully.

## Confirmed defects and limits

* Test Connection required `livemode === false` on `/v1/account`. Stripe's Account schema has no
  `livemode` property. A valid HTTP 200 account therefore failed as authentication. The patch uses
  authenticated GET `/v1/balance`, whose Balance object includes `livemode`, and requires a balance
  object with the strict boolean `false`. Reference: Stripe OpenAPI
  https://github.com/stripe/openapi/blob/master/openapi/spec3.json (Account vs Balance schemas),
  https://docs.stripe.com/api/balance/retrieve. No provider error text is reflected.
* The heading used `checkout_ready` (all configuration checks AND activation) as the enabled flag,
  while the detail used `checkout_enabled` (account activation and canonical binding). A blocked
  worker made them disagree. The patch displays activation and effective readiness separately;
  the underlying gates are unchanged.
* Worker refusal is intentional: unset/nonpositive option, missing user, nonzero `user_status`, any
  role set other than the sole worker role, a truthy direct grant other than its role marker,
  any missing required capability, any forbidden administrative capability, or any other truthy
  capability outside the allowlist. The reported `blocked` alone cannot identify which condition holds.
* `stripe_test` is a **local canonical authority reference**, not Stripe's external `acct_…` identity.
  A local reference/digest mismatch is reported by `account_reference_binding`. A key for a different
  Stripe test account can pass Test Connection. This check cannot prove publishable/API/webhook
  credentials belong to one Stripe account or endpoint; it validates local vault decryptability and
  API reachability/mode only. Verify account ownership in Stripe's authenticated test dashboard.
* No accepted settlement means end-to-end settlement is unproven, even if configuration is ready.
  These changes do not manufacture settlement evidence or authorize a payment.

## Read-only production verification (authorised operator)

Use the existing audited host access, pinned SSH host key and approved WP-CLI identity. Do not
copy production credentials into this cloud workspace or print `wp config list`, salts, option dumps,
credential rows, provider bodies, or HTTP Authorization headers. Do not use shell tracing.

On `cp119.hosting-cloud.net`:

```sh
cd "$HOME/domains/delnavazan.com/public_html"
wp --skip-themes eval 'echo DZN_PLATFORM_BUILD_ID, "\n", DZN_PLATFORM_SCHEMA_VERSION, "\n";'
wp --skip-themes option get dzn_platform_schema_version
wp --skip-themes option get dzn_platform_capability_version_2a2t
```

Compare `main-<12 SHA>` to the exact full SHA in the protected successful production deployment
record and private `deployment.json`; establish that #330 is its ancestor with:

```sh
git merge-base --is-ancestor 80846a9b <full-deployed-sha>
git show <full-deployed-sha>:platform/src/Core/Infrastructure/Migration/Migrator.php | sha256sum
```

Compare the hash to `sha256sum wp-content/plugins/delnavazan-platform/src/Core/Infrastructure/Migration/Migrator.php`
on the host. The migrator is not build-stamped. Do not treat a truncated build stamp alone as full
integrity proof. Verify schema equals the deployed package, marker is `2a2t2`, and run the reviewed
`deployment/qa/stripe-test-readiness.php` from a private operator directory using:

```sh
wp --skip-themes eval-file /absolute/private/path/stripe-test-readiness.php
```

The diagnostic itself only reads metadata; normal WordPress bootstrap hooks still run. It outputs no
credentials, user login, email, names or secret records. Require `worker_role_present`,
`worker_role_exact`, `worker_recognised` and `live_execution_closed` to be true. It lists specific
worker refusal categories. If the role is missing or widened, use the protected release/upgrade path
for the reviewed SHA containing #330; do not hand-patch roles or run an ad hoc schema migration.

## Provisioning proposal — requires separate explicit production authorisation

Do not execute this section as part of this investigation. First establish the deployment identity,
exact role grants, approved service-account ownership, no existing suitable principal, and keep live
execution closed. If an existing binding is wrong or overprivileged, stop for an operator decision;
do not repurpose a human or administrator account. Use the approved identity-management process
where available. Otherwise, an audited WP-CLI session may create a fresh non-human account:

```sh
# Operator supplies a unique approved service login and controlled email address.
# WP generates a password internally; --porcelain emits only the numeric user ID.
worker_id="$(wp --skip-themes user create "$approved_service_login" "$approved_service_email" \
  --role=dzn_payment_worker --porcelain)"
case "$worker_id" in ''|*[!0-9]*) exit 1;; esac
[ "$worker_id" -gt 0 ] || exit 1
# No send-email flag, direct capability grants or additional roles.
# Verify the candidate against the actual production validator BEFORE binding it.
wp --skip-themes eval '
$id=(int)$args[0];
add_filter("pre_option_dzn_platform_payment_worker_principal",static fn()=>$id);
if(\Delnavazan\Platform\Core\Application\PaymentExecution\PaymentExecutionSupport::workerPrincipalId()!==$id){WP_CLI::error("Worker validation refused");}
WP_CLI::success("Bounded candidate validated; option unchanged");
' "$worker_id"
# Only after successful validation and approval of this exact ID:
wp --skip-themes option update dzn_platform_payment_worker_principal "$worker_id" --autoload=no
```

The pre-option filter exists only in that CLI process, performs no settlement and persists nothing.
Re-run the read-only diagnostic; require recognition and exact role shape. Do not add administrator,
`manage_options`, `dzn_manage_payment_execution`, `dzn_manage_payment_providers`, or direct grants.
Account status must stay 0; role must stay solely `dzn_payment_worker`.

## Connection verification and triage

After independently reviewed merge and authorised normal deployment of the diagnostic fix, an
operator with provider authority uses the existing **Test Connection** action once. It makes a
read-only Stripe request and writes local validation audit/result facts. No credential save is needed.

| Code | Meaning and minimum response |
|---|---|
| `test_secret_unconfigured` | Missing active scoped secret record; inspect metadata before any separately authorised configuration work. |
| `test_secret_unavailable` | Local decryption/format validation failed; inspect Sodium availability, cipher/key versions and salt continuity securely. Do not rotate salts or re-enter keys blindly. |
| `test_secret_validation_failed` | Local validation raised an exception (e.g. storage/audit failure); investigate storage/runtime privately. Not proof of bad Stripe credentials. |
| `stripe_unreachable` / `stripe_transport_failed` | WordPress network/TLS/DNS/timeout error or transport exception; check verified HTTPS egress to `api.stripe.com:443`; preserve TLS verification. |
| `stripe_test_authentication_failed` | HTTP 401; Stripe rejected authentication. Confirm status securely; credential replacement requires separate authorisation. |
| `stripe_test_permission_denied` | HTTP 403; permission/access issue, not necessarily an invalid key. |
| `stripe_rate_limited` | HTTP 429; wait and retry under normal backoff. |
| `stripe_api_unavailable` | HTTP 5xx; provider availability issue. |
| `stripe_api_request_failed` | Other non-200 status, including redirect; diagnose request/proxy without exposing response bodies. |
| `stripe_response_invalid` | HTTP 200 with malformed JSON/wrong object/missing or nonboolean mode. |
| `stripe_test_mode_mismatch` | Balance reports live mode; stop, keep all live execution disabled, investigate securely. |
| `stripe_test_account_reachable` | Local credential validation passed and Stripe returned a test balance; not proof of account ownership or webhook settlement. |

Verify the test publishable key and stored API key originate from the same intended Stripe sandbox,
and the webhook signing secret belongs to that sandbox's **exact production test endpoint** shown by
settings, not Stripe CLI's separate listener secret. Do this in trusted settings/dashboard screens;
never paste values into commands or incident reports. Local account binding must also be ready.
No automatic comparison to an external `acct_…` ID exists in the current model.

## Acceptance boundary

Require fresh Test Connection success, all independent configuration checks ready, `checkout_enabled`
ready, effective `checkout_ready` true, and live execution closed. Check scheduling/authority state
through existing read-only operator surfaces; do not create Lessons, Terms, Assignments, obligations,
funding or mappings by hand. The heading must reflect enabled activation even while another gate blocks.

At this point the system may be ready for a **separately authorised controlled test-mode payment**.
Do not run `run-stripe-checkout-test-mode-e2e.sh` against production: it is a disposable fixture harness.
An operator-approved synthetic test needs its own exact scope, webhook checks, reconciliation evidence
and teardown. Require verified non-live webhook receipt, accepted provider event, accepted settlement,
correct bounded worker attribution, idempotent replay, expected R1/R2 consequences and intact scheduling
invariants before claiming end-to-end success. A redirect, open Checkout or successful connection is
insufficient. No such payment or production mutation was performed by this investigation.

## Local validation evidence

PHP 8.3 CLI, pinned container digest
`sha256:60a100afa8efd88c0f556ce60e891c9bb8b1e8bca15721e4db07eca4fa79c07a`:

* The complete `Run verified source-level Platform contracts` block from
  `.github/workflows/pr-runtime-gate.yml` passed, including the new regression suites.
* Test Connection: 21 scenarios passed, real Sodium encryption with in-memory repository persistence
  and synthetic HTTP; no Stripe traffic.
* Readiness heading: four activation/readiness combinations passed.
* Payment worker: ten valid/invalid identity scenarios passed, with unchanged production validator.
* Fresh-install capability bootstrap passed.
* All 876 PHP files under Platform, Theme and deployment parsed successfully; patch whitespace passed.
* Additional historical `phase-2a2t-contract.php` fails at its old Phase T build-stamp assertion.
  `platform/delnavazan-platform.php` is unchanged from main and declares the post-intro build instead;
  this is a pre-existing historical test incompatibility, not an unexplained new setup failure.
* WordPress/MariaDB end-to-end settlement suite was not run. Its synthetic Test Connection fixture
  was corrected to model Balance rather than the impossible Account-with-livemode response.
* Production role/configuration, real Stripe authentication/account ownership, webhook delivery and
  settlement remain unverified. Neither local passes nor the draft config establish production readiness.
