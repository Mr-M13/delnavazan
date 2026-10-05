# Delnavazan

Unified product repository for the Delnavazan academy platform and WordPress frontend.

## Active components

- `platform/` — canonical Delnavazan Platform plugin and backend/application services.
- `theme/` — Delnavazan WordPress theme and user-facing frontend.
- `deployment/` — guarded production release and optional staging deployment tooling.
- `legacy-review/` — migration notes for older Delnavazan plugins that are not active monorepo components.

## Source of truth

New product work is developed on feature branches in this repository and reviewed through pull requests. This monorepo is the sole code source for the live Delnavazan site.

## Production

The permanent public site is `https://delnavazan.com`. Releases follow feature branch → PR checks and review → `main` → protected manual production deployment. See [deployment/README.md](deployment/README.md) for the production workflow and host configuration.

## Optional staging

`https://staging.delnavazan.com` is an optional pre-release rehearsal environment and remains noindex. Staging runtime files are never a production code source.
