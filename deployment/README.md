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

## Finance administration redesign (Schema 49)

Release `phase2a2x-finance-administration-20261026.1` adds Schema 49
(`049_finance_administration`). It is additive: `finance_regional_prices` and `finance_settings`.
It creates no offer, purchase, payment, statement, ledger, Term, Lesson or schedule row. On an existing
installation it seeds a regional price only when every active standard Course already agrees on one
currency and one amount; conflicting regions remain unset and appear in Finance → Settings for explicit
resolution. Existing `commercial_prices`, offers, payments and statements are not rewritten.

After deployment, verify:

1. The exact `origin/main` SHA and build identity.
2. `dzn_platform_schema_version` is `49` and the migration ledger contains `049_finance_administration`.
3. `finance_regional_prices` and `finance_settings` exist with InnoDB.
4. Finance has exactly four tabs: Payments, Central Ledger, Teacher Statements and Settings.
5. No production price was changed by the migration; review any conflict notice before saving a price.

Rollback: keep the additive tables and restore the previous code release, or take a database backup,
drop only the two new tables, remove the 049 ledger entry and restore the previous schema-version option.
Never delete `commercial_prices`, `commercial_offers`, `commercial_payment_evidence` or statement rows.

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
