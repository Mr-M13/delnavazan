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

The current hosting account has SSH disabled. DirectAdmin supports HTTPS remotes for public repositories, but private Git remotes require SSH/keyfile access. Until hosting SSH is enabled, the historical public component repositories are retained only as deployment mirrors:

- `Mr-M13/delnavazan-platform` branch `monorepo-deploy`
- `Mr-M13/delnavazan-theme` branch `monorepo-deploy`

DirectAdmin staging repositories track those mirror branches and deploy to:

- `domains/staging.delnavazan.com/public_html/wp-content/plugins/delnavazan-platform`
- `domains/staging.delnavazan.com/public_html/wp-content/themes/delnavazan-theme`

Do not develop directly in the mirror branches. Generate them from the monorepo using `deployment/sync-mirrors.sh`.

## Safety boundary

Staging is the only deployment target during frontend completion. Public `delnavazan.com`, its DNS, and the old production site remain unchanged until explicit cutover approval.
