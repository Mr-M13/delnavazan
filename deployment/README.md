# Deployment

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

## Pre-launch acceptance data

The live staging environment is being retired separately after its hosting account, database, and storage boundaries are positively verified. The staging deployment workflow is removed from the source tree. Production acceptance data must remain blocked until authenticated inspection confirms there is no genuine academy data and the synthetic fixture has an exact, complete manifest and verified teardown for every created record.

## Source and boundaries

- `platform/` -> `wp-content/plugins/delnavazan-platform/`
- `theme/` -> `wp-content/themes/delnavazan-theme/`
- `.github/workflows/pr-runtime-gate.yml` -> pull-request source checks
- `.github/workflows/deploy-production.yml` -> protected, manual production release

Generated `deploy-platform` and `deploy-theme` subtree branches are legacy packaging artefacts. `deployment/sync-mirrors.sh` force-pushes component mirrors and does not back up, migrate, or verify a production deployment. Do not use it for releases.

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
