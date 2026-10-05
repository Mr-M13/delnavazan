# Deployment

The private `Mr-M13/delnavazan` monorepo is the canonical source of truth.

## Release path

Use this path for every release:

1. Start a feature branch from current `main`.
2. Run the relevant PHP/source-contract checks locally and open a pull request.
3. Merge only after review and the required PR runtime gate passes.
4. From `main`, run **Deploy production** manually with the full SHA that is still the current `origin/main` commit.
5. Review the deployment record and read-only production smoke result before closing the release.

`.github/workflows/pr-runtime-gate.yml` runs on pull requests. It covers the Platform source contracts and both deployment toolchains. Production deploys are manual-only, serialized, pinned to current `main`, and use a protected GitHub Actions environment named `production`. Configure required reviewers on that environment and add these environment secrets:

- `PRODUCTION_SSH_USER` — restricted DirectAdmin account name.
- `PRODUCTION_SSH_PRIVATE_KEY` — deploy key scoped to the hosting account.
- `PRODUCTION_KNOWN_HOSTS` — pinned host key for `cp119.hosting-cloud.net`; do not generate trust with `ssh-keyscan` during a deploy.
- `PRODUCTION_BACKUP_DIR` — absolute private writable directory outside `public_html`, such as `/home/delnavaz/backups/delnavazan-production-cutover`.

`.github/workflows/deploy-production.yml` calls the PR source gate, requires dispatch from `main`, checks the exact full SHA, and deploys only `domains/delnavazan.com/public_html`. The deployment packages `platform/` and `theme/` from that exact commit, stamps `main-<12 SHA characters>`, verifies checksums, takes a database export and application-file backup, and calls the canonical forward-only `Migrator::maybe_upgrade()`. A migration or identity failure restores the backed-up application files, leaves maintenance mode enabled, and preserves the database backup and failure record for an operator decision. It never restores a database automatically. After a successful deploy, the workflow runs `deployment/qa/production-smoke.sh`, which uses read-only HTTPS requests and does not submit bookings, charge Stripe, send notifications, or create Google events.

The workflow does not edit DNS, WordPress URLs, mail records, SSL settings, or catalogue data. Those are controlled production configuration and data operations. Production currently serves `https://delnavazan.com`; `www` redirects to the apex.

## Optional staging

Staging at `https://staging.delnavazan.com` is an optional pre-release environment. Use `.github/workflows/deploy-staging.yml` only when a release benefits from a staging rehearsal. Its protected `staging` environment and `STAGING_*` secrets are separate from production. Staging remains noindex. A staging deploy is not required before every production release and cannot deploy production.

The staging workflow deploys only `domains/staging.delnavazan.com/public_html`. Its database and runtime are not a code source. Never copy staging plugin/theme files into production or use the legacy mirror helper to deploy either environment.

## Source and boundaries

- `platform/` -> `wp-content/plugins/delnavazan-platform/`
- `theme/` -> `wp-content/themes/delnavazan-theme/`
- `.github/workflows/pr-runtime-gate.yml` -> pull-request source checks
- `.github/workflows/deploy-production.yml` -> protected, manual production release
- `.github/workflows/deploy-staging.yml` -> optional, protected staging rehearsal

Generated `deploy-platform` and `deploy-theme` subtree branches are legacy packaging artefacts. `deployment/sync-mirrors.sh` force-pushes component mirrors and does not back up, migrate, or verify a production deployment. Do not use it for releases.
