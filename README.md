# Delnavazan

Unified product repository for the Delnavazan academy platform and WordPress frontend.

## Active components

- `platform/` — canonical Delnavazan Platform plugin and backend/application services.
- `theme/` — Delnavazan WordPress theme and user-facing frontend.
- `deployment/` — guarded production release and reusable read-only QA tooling.
- `legacy-review/` — migration notes for older Delnavazan plugins that are not active monorepo components.

## Source of truth

New product work is developed on feature branches in this repository and reviewed through pull requests. This monorepo is the sole code source for the live Delnavazan site.

## Production

The permanent public site is `https://delnavazan.com`. Releases follow feature branch → PR checks and review → `main` → protected manual production deployment. See [deployment/README.md](deployment/README.md) for the production workflow and host configuration.

## Pre-launch acceptance

Pre-launch acceptance data must use synthetic identities, an exact manifest, and a verified teardown path. Production must first be confirmed free of genuine academy data; the guarded fixture remains unavailable until that verification and its production safety review are complete.
