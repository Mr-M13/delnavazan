# Deployment

The private `Mr-M13/delnavazan` monorepo is the canonical source of truth.

## Active components

- `platform/` -> `wp-content/plugins/delnavazan-platform/`
- `theme/` -> `wp-content/themes/delnavazan/`

## Canonical deployment branches

The monorepo carries generated subtree branches for deployable component roots:

- `deploy-platform` — contents of `platform/` at branch root.
- `deploy-theme` — contents of `theme/` at branch root.

Normal development belongs on `main`; these branches are deployment artefacts.

## DirectAdmin staging bridge

The historical hosting setup has SSH disabled. It used HTTPS Git deployments from public component repositories:

- `Mr-M13/delnavazan-platform` branch `monorepo-deploy`
- `Mr-M13/delnavazan-theme` branch `monorepo-deploy`

DirectAdmin staging repositories track those mirror branches and deploy to:

- `domains/staging.delnavazan.com/public_html/wp-content/plugins/delnavazan-platform`
- `domains/staging.delnavazan.com/public_html/wp-content/themes/delnavazan-theme`

Do not develop directly in the mirror branches. `deployment/sync-mirrors.sh` is a legacy packaging helper only: it force-pushes mirrors and does not back up staging, run migrations, or verify the deployed result. It must not be used as the staging deployment procedure.

## Controlled staging deployment

`.github/workflows/deploy-staging.yml` is the only supported automated deploy path. It is manual-only, serializes runs, accepts a full SHA, and `deployment/staging-deploy.sh` refuses it unless it still equals `origin/main`. The artifact packages `platform/` and `theme/` from that commit, stamps Platform build identity as `main-<12 SHA characters>`, and verifies the package checksum on the staging host.

The workflow first reuses the complete PHP source-contract gate and requires the student-portal and admin-navigation regression contracts from PRs #137 and #138 to be present on `main`. It then requires SSH to be enabled for a restricted DirectAdmin staging user. Configure a protected GitHub Actions environment named `staging`, with required reviewers if desired, and these environment secrets:

- `STAGING_SSH_USER` — restricted hosting account name.
- `STAGING_SSH_PRIVATE_KEY` — deploy key scoped to the hosting account.
- `STAGING_KNOWN_HOSTS` — pinned `staging.delnavazan.com` host key; do not generate trust with `ssh-keyscan` during a deploy.
- `STAGING_BACKUP_DIR` — absolute writable directory outside `public_html`.

The workflow writes only to the fixed staging paths in the deployment script. Preflight and backups use WP-CLI with plugins and themes skipped so an old schema cannot auto-migrate before the snapshot. It stores a private database export, application file archive, before-state JSON, and deployment record before enabling maintenance mode. The canonical Platform `Migrator::maybe_upgrade()` runs after package replacement. A migration or identity failure restores the prior application files, keeps maintenance mode enabled, and preserves the database backup and failure record for operator recovery. Successful runs emit a machine-readable artifact and then run the read-only staging smoke suite. If that final smoke fails, the workflow places staging in maintenance mode and preserves its output for recovery; it does not claim that the database can be rolled back automatically.

Do not enable or configure production credentials in this workflow. Do not invoke the legacy mirror helper from CI.

## Safety boundary

Staging is the only deployment target during frontend completion. Public `delnavazan.com`, its DNS, and the old production site remain unchanged until explicit cutover approval.
