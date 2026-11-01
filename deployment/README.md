# Deployment

## Release 2026-11-01 — Teacher induction v2

Adds progressive Teacher induction separate from account activation, a dedicated induction hub,
contextual guidance, and a five-step onboarding presentation. The only schema change is the
additive, idempotent `064_teacher_induction_progress` table; schema identity remains 57. No teacher,
lesson, attendance, finance or notification authority changed. See
`platform/docs/RELEASE-2026-11-01-TEACHER-INDUCTION-V2.md`. After deployment verify the migration
ledger contains `064_teacher_induction_progress` and `{prefix}dzn_teacher_induction_progress` exists.

## Release 2026-10-31 — Teacher availability and finance transparency

The Teacher Portal now records positive teaching availability only, integrates Teacher-local Golden Hours with a consolidated capacity summary, reuses public booking instrument artwork, and removes redundant presentation cards. Both authenticated portals share a read-only illustrative paid-term allocation component with exact minor-unit reconciliation and an explicit 1% unallocated row. Payment execution, invoices, receipts, teacher statements and settlements are unchanged. See `platform/docs/RELEASE-2026-10-31-TEACHER-AVAILABILITY-FINANCE-TRANSPARENCY.md`.

The private `Mr-M13/delnavazan` monorepo is the canonical source of truth.

## Release path

Use this path for every release:

1. Start a feature branch from current `main`.
2. Run the relevant PHP/source-contract checks locally and open a pull request.
3. Merge only after review and the required PR runtime gate passes.
4. From `main`, run **Deploy production** manually with the full SHA that is still the current `origin/main` commit.
5. Review the deployment record and read-only production smoke result before closing the release.

`.github/workflows/pr-runtime-gate.yml` runs on pull requests. It covers the Platform source contracts and production deployment tooling. Production deploys are manual-only, serialized, pinned to current `main`, and use a protected GitHub Actions environment named `production`. Configure required reviewers on that environment and add these environment secrets:

- `PRODUCTION_SSH_USER` — restricted DirectAdmin account name.
- `PRODUCTION_SSH_PRIVATE_KEY` — deploy key scoped to the hosting account.
- `PRODUCTION_KNOWN_HOSTS` — pinned host key for `cp119.hosting-cloud.net`; do not generate trust with `ssh-keyscan` during a deploy.
- `PRODUCTION_BACKUP_DIR` — absolute private writable directory outside `public_html`, such as `/home/delnavaz/backups/delnavazan-production-cutover`.

`.github/workflows/deploy-production.yml` calls the PR source gate, requires dispatch from `main`, checks the exact full SHA, and deploys only `domains/delnavazan.com/public_html`. The deployment packages `platform/` and `theme/` from that exact commit, stamps `main-<12 SHA characters>`, verifies checksums, takes a database export and application-file backup, and calls the canonical forward-only `Migrator::maybe_upgrade()`. A migration or identity failure restores the backed-up application files, leaves maintenance mode enabled, and preserves the database backup and failure record for an operator decision. It never restores a database automatically. After a successful deploy, the workflow runs `deployment/qa/production-smoke.sh`, which uses read-only HTTPS requests and does not submit bookings, charge Stripe, send notifications, or create Google events.

The workflow does not edit DNS, WordPress URLs, mail records, SSL settings, or catalogue data. Those are controlled production configuration and data operations. Production currently serves `https://delnavazan.com`; `www` redirects to the apex.

## Production smoke reliability and maintenance recovery

The deploy workflow runs `deployment/qa/production-smoke.sh` after the atomic deployment and
identity check. Smoke requests send browser-like `User-Agent`, `Accept` and `Accept-Language`
headers and use a bounded retry policy (`deployment/qa/production-smoke-lib.sh`):

- immediate success is one request;
- transports timeouts/connection failures (`curl` 6/7/28/35/52/56), HTTP `429`, `503`, `504` and
  Cloudflare origin `52x` are retried up to three attempts with short delays and a 50-second
  per-probe budget;
- HTTP `403` is retried **only** when Cloudflare explicitly marks a challenge
  (`cf-mitigated: challenge|managed_challenge`) and the body is a Cloudflare challenge page;
- plain `403`, `401`, `404`, `500`, `502` and application failures are terminal on the first
  response;
- every attempt logs its label, status, classification and elapsed time. Non-success responses
  also log safe edge headers (`server`, `cf-ray`, `cf-mitigated`, `retry-after`, etc.) and a body
  marker; no credentials, cookies or tokens are sent or logged.

If the primary smoke fails, the workflow runs an independent `--confirm` probe. Maintenance mode
is enabled only when both the primary and confirmation probes fail, or when the decision step
itself is unavailable. A passing confirmation leaves production live and records a warning in the
run plus `production-verification.json`. This preserves fail-closed protection while avoiding an
unnecessary maintenance window for a temporary edge request failure.

Recovery when maintenance is active:

1. Inspect the failed run's `PROBE` lines and `production-verification.json`.
2. If both probes failed for an application reason, fix and redeploy current `main`. Do not
   manually disable maintenance without a healthy confirmation.
3. The deployment script detects an existing maintenance window, preserves it during the atomic
   replacement, and deactivates it only after migration/identity verification and a successful
   smoke/confirmation path.

## Replacement and calendar operations

Accepted missed-lesson replacements use the existing canonical authority:

- `dzn_replacement_system_user_id` — active service user with only
  `dzn_manage_canonical_lessons` and `dzn_manage_canonical_lesson_schedules`; no
  `manage_options` and no provider-integration capability.
- `dzn_reschedule_system_user_id` — scheduling capability only.
- `dzn_reschedule_calendar_system_user_id` — provider-integration capability only; no canonical
  schedule or administrator capability.
- `dzn_platform_provider_projection_worker_principal` — subscriber service user with only
  `dzn_dispatch_provider_integrations`; no canonical schedule, management, revoke, ingest, connect
  or administrator capability.

Student acceptance creates the replacement Lesson and its first schedule version in one
transaction, then queues one idempotent notification set and one `lesson_reschedule_calendar_jobs`
row. The job targets the replacement Lesson; a later reschedule of that replacement uses the same
Lesson and queues a new job from the old schedule version to the new one. The real Google worker
uses deterministic provider command keys and the transport converges on HTTP `409` by reading the
same event; retries therefore never intentionally create a second event. Deterministic provider
refusals are terminal; transport/outage failures use bounded backoff. A provider failure never
rolls back the committed canonical replacement.

## One-job calendar recovery

When the read-only release diagnostic reports a failed calendar job with
`"recoverable": true`, recover exactly that job:

```sh
gh workflow run recover-production-calendar-job.yml --ref main   -f commit_sha=<current-main-sha> -f job_id=<job-id> -f confirm=RECOVER
```

The workflow verifies the exact main SHA and the literal `RECOVER` confirmation, then the payload
re-proves that the job is `failed`, its proposal is `accepted`, the Lesson is active and the target
schedule version is still the applicable, non-superseded version. It requeues only that job,
dispatches it under the existing calendar actor, optionally runs the Google projection worker, and
prints safe job/mapping state booleans. It never edits canonical lessons, attendance, payments,
enrolments, proposals or notifications. A provider failure leaves the job visible as `retry`/`failed`;
the committed canonical schedule is never reversed.

## Pending Google Calendar/Meet projections

When the diagnostic reports pending calendar projections, run the existing projection worker
through the audited one-shot workflow:

```sh
gh workflow run dispatch-production-calendar-projections.yml --ref main   -f commit_sha=<current-main-sha> -f confirm=DISPATCH
```

The payload verifies the projection-worker principal and Google OAuth configuration, runs the
existing `GoogleCalendarProjectionWorker`, and reports pending-before/after, verified count and
verified join-reference count. It never edits canonical lessons, schedules, attendance, payments,
enrolments, proposals or notifications. The Google projection service refreshes an expired active
credential through the existing encrypted refresh token before projecting; a failed refresh leaves
the mapping pending/fail-closed and visible. The diagnostic reports `join_reference_present` and
`portal_join_ready` without printing the Meet URL.

## Staging acceptance and production diagnostics

The disposable attendance runtime is the staging acceptance path:

```sh
DZN_RUNTIME_STATE_DIR=/tmp/dzn-attendance-runtime DZN_COMPOSE_PROJECT=dzn-attendance-runtime DZN_WP_PORT=18086 platform/runtime/bin/run-attendance-runtime.sh
```

It creates synthetic WordPress principals, Teacher/Student links, an Enrolment, Term, Teacher
Assignment and Lessons; reports a missed class; presumes completion; proposes and accepts a
replacement; reads both portal projections; verifies the linked replacement, preserved original,
allowance consumption, notification rows, calendar job and replacement reschedule. It does not
contact a live Google account.

Read-only production diagnostics:

```sh
gh workflow run diagnose-production-release.yml --ref main -f commit_sha=<current-main-sha>
gh workflow run diagnose-production-google-oauth.yml --ref main -f commit_sha=<current-main-sha>
```

The release diagnostic reports schema/build, maintenance state, replacement/reschedule/calendar
actor capabilities, replacement proposal and Lesson counts, calendar-job states, stale jobs,
notification states and worker cron hooks. It never prints secrets or mutates production.

Known limitation: a live Google Calendar/Meet event for a real replacement is not created by the
staging fixture. Verification of the final provider write requires a staging Google account and
calendar specifically authorised for testing. Do not create synthetic lesson changes in production
to satisfy that test.

## Pre-launch acceptance data

The live staging environment is being retired separately after its hosting account, database, and storage boundaries are positively verified. The staging deployment workflow is removed from the source tree. Production acceptance data must remain blocked until authenticated inspection confirms there is no genuine academy data and the synthetic fixture has an exact, complete manifest and verified teardown for every created record.

## Source and boundaries

- `platform/` -> `wp-content/plugins/delnavazan-platform/`
- `theme/` -> `wp-content/themes/delnavazan-theme/`
- `.github/workflows/pr-runtime-gate.yml` -> pull-request source checks
- `.github/workflows/deploy-production.yml` -> protected, manual production release

Generated `deploy-platform` and `deploy-theme` subtree branches are legacy packaging artefacts. `deployment/sync-mirrors.sh` force-pushes component mirrors and does not back up, migrate, or verify a production deployment. Do not use it for releases.

## Release 2026-10-29

Release `25857feb8b2246fddb3a67d54b7f3c2396e77384` (build `main-25857feb8b22`, schema 54) merged
#404 and deployed in run `40824363269`. It adds the unified notification management workspace, branded
email shell, editable immutable copy and the additive Schema 056 website inbox. Smoke passed; the
read-only diagnostic (`40824807952`) reports schema 54 and the deployed build. Copy is configured, but
no transport channel or new recipient category is activated. Back up the database and application files
before any rollback; dropping `portal_notifications` is safe only after code rollback. See
`platform/docs/RELEASE-2026-10-29-UNIFIED-NOTIFICATIONS.md`.

## Release 2026-10-29 (footer and homepage pricing surface)

PR #425 restored the footer and homepage price-finale turquoise backgrounds.
The cause was four self-referential design tokens, not a selector or block-style
override: `--dzn-color-text-secondary`, `--dzn-color-on-action`,
`--dzn-color-on-dark` and `--dzn-color-surface-elevated` resolved to
transparent. The original token values were restored in `theme.css`; the
active selectors are `.site-footer` and `.dzn-story__finale` (the transparent
`.dzn-price-ledger` sits inside the finale). No schema, attendance, enrolment,
payment, scheduling or replacement rule changed.

The fix merge is `45dbd2d0630795a5586c40234db61e1f4cfa60fd`. It was first
deployed in `e07b98cdaa37fc6e981dc54991bcaccc9a55e4df` (run `40837789338`) and
is included in the current production build
`ab5a4ac2a1ff878e6b666e66d2c369a2a5405b90` (build `main-ab5a4ac2a1ff`, schema
54, run `40838148666`, smoke passed). A stale-SHA dispatch at `45dbd2d` was
correctly refused by the exact-current-main gate. See
`platform/docs/RELEASE-2026-10-29-FOOTER-PRICING.md`.

## Payment plan selection and interim attendance (Schema 52)

Release `payment-plan-selection-b2a-20261026` adds Schema 52 (`052_payment_plan_choice`). It is
additive: `post_intro_plan_choices` plus `post_intro_activation_jobs.funding_target` and
`funding_materialised`. It creates no offer, obligation, settlement, Term, Lesson, schedule or
statement row and does not rewrite an existing row. Full-term payment remains the default; two
instalments require the explicit Student opt-in before offer issuance.

B2b (`INSTALMENT_DUE_DATE_POLICY`) is merged but **unset by default**. The approved value is exactly
168 hours before canonical Lesson 7, recorded as one week or `604800` seconds. While unset there is
no due date and no invented deadline. Do not activate it in production until the business explicitly
chooses to enable it. Existing offers are never rewritten by a policy change.

The same Schema 52 release activates the interim manual attendance and presumed-completion policy.
Ended `authorised` Lessons may be presumed completed from silence by
`dzn_lesson_completion_automation_dispatch` after their canonical UTC end, provided there is no
Phase-O outcome, unresolved attendance case, conflict or integrity issue. Presumed completion is not
verified attendance and Finance keeps it `pending / presumed_completion_review`; it never releases a
teacher payment. Teachers and Students can submit Phase-P reports, and administrators can record or
correct outcomes append-only. No Google Meet attendance integration is included.

After deployment verify:

1. The exact `origin/main` SHA and build identity.
2. `dzn_platform_schema_version` is `52` and the ledger contains `052_payment_plan_choice`.
3. `post_intro_plan_choices`, `post_intro_activation_jobs.funding_target` and
   `post_intro_activation_jobs.funding_materialised` exist.
4. `INSTALMENT_DUE_DATE_POLICY` has no active value unless the business explicitly enabled it.
5. `dzn_post_intro_activation_dispatch` and `dzn_lesson_completion_automation_dispatch` are scheduled.
6. Finance shows the new instalment schedule section and no unexpected
   `presumed_completion_review` backlog; review exceptions before issuing statements.

Rollback: restore the database snapshot, or drop `post_intro_plan_choices` and the two additive
`post_intro_activation_jobs` columns, remove the 052 ledger entry and restore Schema 51. A code-only
rollback leaves the additive structures and any presumed-completion/correction history in place; that
is safe. Never delete an offer, settlement, purchase, Term, Lesson, lifecycle event, delivery
outcome, audit row or statement.

## Lifecycle regression

Run the bounded paid-lifecycle bundle from a clean checkout before every production release:

```sh
cd platform/runtime
make lifecycle-regression
```

It runs the pure source/DST/portal contracts, the offline Stripe test-mode E2E, the disposable
post-intro lifecycle (booking → delivered intro → settlement → 12 canonical Lessons → portals →
partial recovery), and the interim attendance/presumed-completion runtime. It is an operator
command; CI still runs the individual jobs. A release is not ready if this command does not end with
`Student lifecycle regression bundle: PASS`.

## Release 2026-10-29 (CSS and authenticated portal accessibility follow-up)

The CSS consolidation from #402 and the portal accessibility follow-up from
#407 are deployed. #407 was deployed at
`3939feed0ec977f056eaf3ed262d4d2dbfbce167` (build `main-3939feed0ec9`, run
`40826085963`) and is included in the current production build
`ab5a4ac2a1ff878e6b666e66d2c369a2a5405b90` (see the footer/pricing release
above). Both deployment artifacts record `status: deployed`; the read-only
production smoke gate passed.

The follow-up fixes long teacher status labels wrapping at 200% text and gives
rendered nonce fields request-unique DOM ids via `dzn_theme_nonce_field()`;
field names, actions and server-side verification are unchanged. No attendance,
enrolment, payment, scheduling or replacement rule changed, and there is no
schema change. A code-only rollback to `9ba61e639b1a` is safe.

Authenticated Student/Teacher verification was run in the isolated acceptance
runtime with the deployed theme/CSS and the Phase-J/post-intro synthetic
lessons. Authenticated production accounts remain unavailable; see
`platform/docs/CSS-AUTH-VERIFICATION-2026-10-29.md`.

## Release 2026-10-27

The latest follow-up is `f9517eb159920c1aa078a5920aee232d7d252435`
(build `main-f9517eb15992`, schema 52), deployed in run `40616439515`.
It places the login button last (خانه / مقالات / ورود) and centres the currency
selector under the pricing copy with a palette consistent with the pricing block.

The preceding login-button follow-up is `386225bce57724779be8dffc50b85297e92bdc0a`
(build `main-386225bce577`, schema 52), deployed in run `40502315523`.

The preceding home-polish release is `9d568c69bd604d2e57fa551e3c2173a3287402d9`
(build `main-9d568c69bd60`, schema 52), deployed in run `40500551506`.
It changes the primary menu to خانه / ورود / مقالات, makes homepage instrument
tiles preselect the same instrument on `/enrol/`, removes the pricing
disclaimers and compacts the currency selector to AUD/NZD/USD/CAD/EUR/GBP.
See `platform/docs/RELEASE-2026-10-27-HOME-POLISH.md`.

The presentation/portal release is `508a8224e76943f25c5e56bd5996f41f08cb12aa`
(build `main-508a8224e769`, schema 52). It merged #376, #377 and #378; packages #371–#375
are the same commits carried by #376. The successful production deployment run is
`40494901611`; the previous production release was
`c952fe0de043244edeb78a4e71ec831bdc5fbdb8` (build `main-c952fe0de043`, schema 52).
The pre-deploy backup is under the configured production backup directory with the
`20261027T111939Z-1750281-508a8224e769...` stamp.

The first attempt (`40492504675`) deployed the application but failed the smoke gate
because the released legacy `/booking/` redirect is now a temporary `302` that
preserves the instrument while the smoke script still required `301`. The gate
correctly left maintenance mode enabled; #377 fixed the smoke contract and the exact
new `main` was redeployed successfully. #378 then fixed the homepage instrument
belt's fractional scroll accumulator and that exact `main` was deployed as
`508a822`. No schema migration ran.

There is no schema change in this release. A code-only rollback to `c952fe0` is safe;
keep schema 52 and all existing records. See
`platform/docs/RELEASE-2026-10-27-PRESENTATION.md` for evidence, smoke results,
the lesson-detail class-resolution check, Stripe test-mode confirmation and
follow-ups.

## Release 2026-10-26

The integrated release is `b1acd1d47b6f18756283fc0a3f3326ab595f993d` (build
`main-b1acd1d47b6f`, schema 52). It merged #354, #357, #361, #358, #360, #356 and #359. The
production deployment run is `40305480295`; the previous production release was
`97a1864c5d655a6b9a70cbf982c682c0c5c03258` (schema 51). The deployment record, smoke result and
Google OAuth diagnostic are preserved in the run artifacts. See
`platform/docs/RELEASE-2026-10-26-INTEGRATION.md` for the full evidence, rollback notes and
outstanding follow-ups.

## Finance administration redesign (Schema 51)

Release `phase2a2x-finance-administration-20261026.3` adds Schema 51
(`049_finance_administration` + `050_intro_payment_authority` + `051_teacher_rate_origin`). It is additive:
`finance_regional_prices`, `finance_settings`, `intro_payment_obligations`, `intro_checkout_sessions`,
`intro_payment_events` and `intro_payment_commands`.
It creates no offer, purchase, payment, statement, ledger, Term, Lesson or schedule row. On an existing
installation it seeds a regional price only when every active standard Course already agrees on one
currency and one amount; conflicting regions remain unset and appear in Finance → Settings for explicit
resolution. Existing `commercial_prices`, offers, payments and statements are not rewritten.

After deployment, verify:

1. The exact `origin/main` SHA and build identity.
2. `dzn_platform_schema_version` is `51` and the migration ledger contains `049_finance_administration`, `050_intro_payment_authority` and `051_teacher_rate_origin`.
3. `finance_regional_prices`, `finance_settings`, `intro_payment_obligations`, `intro_checkout_sessions`, `intro_payment_events` and the `rate_origin`/`origin_setting_version` columns on `finance_teacher_rates` exist.
4. Finance has exactly four tabs: Payments, Central Ledger, Teacher Statements and Settings.
5. No production price was changed by the migration; review any conflict notice before saving a price.

Rollback: restore the database snapshot taken immediately before Schema 51 (or before deployment) together
with the previous code release. If a database restore is not possible, take a backup, drop only
`finance_regional_prices`, `finance_settings` and the four `intro_payment*`/`intro_checkout*` tables, drop
the additive `finance_teacher_rates.rate_origin` and `finance_teacher_rates.origin_setting_version`
columns, remove the 049/050/051 ledger entries and restore the previous schema-version option. The
provenance columns must be dropped before restoring Schema-50 code because that release's schema verifier
rejects undeclared columns.
Never delete `commercial_prices`, `commercial_offers`, `commercial_payment_evidence` or statement rows.
Deploy this release atomically: a Schema-50 instance must not serve requests after Schema 51 has added the
teacher-rate provenance columns, because its older schema verifier rejects undeclared columns.

## Post-introductory continuation automation (Schema 48)

Release `post-intro-enrolment-automation-20261021.2` adds Schema 48
(`048_post_intro_enrolment_automation`). It is additive: `lessons.booking_request_id`,
`booking_request_contact_snapshots.adult_self_attested`, and three workflow tables. Existing rows keep
their defaults; no existing Course, product, price, Lesson, Enrolment, Term, or payment row is rewritten.

The migration is forward-only and is run by the standard protected `Migrator::maybe_upgrade()` path.
Do not run it outside that path. After deployment, verify:

1. The exact `origin/main` SHA and build identity.
2. `dzn_platform_schema_version` is `48` and the migration ledger contains
   `048_post_intro_enrolment_automation`.
3. Administrator role has `dzn_manage_post_intro_automation`.
4. The `post_intro_preparations`, `post_intro_activation_jobs`, and
   `booking_request_intro_outcomes` tables exist with InnoDB.
5. `Post-intro automation` opens read-only and lists no unexpected exceptions.
6. A synthetic post-intro delivery reaches `prepared`, a synthetic verified settlement reaches
   `payment_received`, and activation reaches `activated` with 12 scheduled Lessons. If Google is not
   connected, the activation job must remain retryable with a `provider_*` code and must not create a
   verified mapping or a fabricated Meet URL.

The automation is driven by the existing WP-Cron notification and activation workers. Confirm that
`dzn_post_intro_activation_dispatch` is scheduled after the release. If it is not, run the standard
WP-Cron recovery process; do not create Lessons, Enrolments, Terms, Assignments, or provider mappings
by hand. Use the admin retry surface only after repairing the owning authority named by the exception.
