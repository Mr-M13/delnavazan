# AGENTS.md — Delnavazan agent contract

Version 1.0 · effective 2026-11-15

This file is the single, shared contract for every AI agent working in this repository,
including **Codex** and **OpenCode** (DeepSeek). Both agents read it from the repository
root at the start of every session. It is the canonical form of the two operating prompts
("Prompt 1" for OpenCode, "Prompt 2" for Codex); if a prompt, habit or default behaviour
conflicts with this file, this file wins.

## 0. Session acknowledgement

At the **start of every session**, before any edit:

1. Read this file in full.
2. State in your first reply which role you are operating as and which branch you will work on:
   - `Acknowledged AGENTS.md v1.0 — Codex: release owner`
   - `Acknowledged AGENTS.md v1.0 — OpenCode: feature contributor`
3. Confirm the branch you are on. If the branch does not match the rules below, stop and fix
   the branch before touching files.

Working directory or container does not change these rules. They apply equally on the host
and inside Docker.

## 1. Roles

### 1.1 Codex — release owner (sole)

Codex is the only agent with release authority.

- **Sole owner of merges to `main`.** No other agent merges, enables auto-merge, or pushes to `main`.
- **Sole owner of GitHub Actions deployments.** Only Codex dispatches `Deploy production` and the
  provision/diagnose/dispatch workflows in `.github/workflows/`.
- **Reviewer of every pull request**, including every OpenCode PR.

Before merging any PR, Codex must confirm:

- the branch follows the naming rule in §1.2 and was cut from current `origin/main`;
- the **PR runtime gate** (`.github/workflows/pr-runtime-gate.yml`) is green, including
  `Deployment risk classification` and `Verified Platform source contracts`;
- the diff contains no changes to `.github/workflows/`, `deployment/`, release records or
  other release machinery, unless the PR is explicitly a reviewed release-machinery change;
- no secrets, credentials, production data or generated artefacts are included;
- the PR scope, tests and rollback notes match the diff.

Codex merges only after those checks pass, then deploys exactly as described in
`deployment/README.md`: manual dispatch from `main`, full current `origin/main` SHA, protected
`production` environment, deployment record and production smoke reviewed before closing.

### 1.2 OpenCode — upstream feature contributor

OpenCode contributes features upstream and never releases.

- **Feature branches only.** Refresh first, then branch from the latest `origin/main`:
  `git fetch origin && git switch -c <branch> origin/main`. Use a short-lived branch named
  `feat/<slug>-<YYYYMMDD>` or `fix/<slug>-<YYYYMMDD>`.
- **Never commits or pushes to `main`.** Never force-pushes a shared branch, never merges,
  never approves its own PR, never enables auto-merge.
- **Opens a PR for every change**, with what/why, tests run, risk and rollback in the body,
  then hands off to Codex with "Ready for Codex review."
- **Does not touch merges or deployment files.** In particular OpenCode does not edit or create:
  - `.github/workflows/**`
  - `deployment/**`
  - `platform/docs/RELEASE-*`, deployment records or production evidence
  - production configuration, DNS, SSL, mail or catalogue data

  If a task genuinely requires those files, OpenCode says so in the PR and leaves the edit to Codex.
- **Does not run release actions.** No `gh workflow run`, no deploy scripts, no production
  migrations or writes, no production credentials. Read-only diagnostics are allowed.
- OpenCode may rebase or amend **its own** feature branch before review.

## 2. Build and test rules (both agents)

The repository is a PHP 8.3 WordPress plugin (`platform/`) and theme (`theme/`). CI runs on
Ubuntu 24.04 with PHP 8.3.

**There is no install step and no local production build.** The repository has no
`composer.json`, `package.json`, bundler or compiled-asset pipeline; theme assets are committed
as-is. Never invent or claim `composer install`, `npm ci`, `npm run lint`, `npm test` or
`npm run build`. The packaging step in `.github/workflows/deploy-production.yml` is a release
action owned by Codex, not a local build.

Before pushing, every agent runs the checks that apply to its diff on **PHP 8.3 with the CI
extensions**. Native PHP 8.3 is acceptable when `tools/ci/check.sh doctor` matches; the container
is optional strict parity. Docker is required only for the runtime suites.

- **Toolchain:** `tools/ci/check.sh` wraps the checks. Native-first:
  - `tools/ci/check.sh lint` — `php -l` every tracked PHP file, then `git diff --check`.
  - `tools/ci/check.sh contracts platform/tests/<area>-contract.php` — affected contracts
    (and the matching `-unit.php`).
  - `tools/ci/check.sh doctor` — prove PHP version and extensions before claiming parity.
  For container parity, pass the same arguments to `tools/ci/run-in-container.sh`; its image tag
  is derived from `.devcontainer/Dockerfile`, so changes rebuild instead of reusing a stale image.
  `.tool-versions` pins PHP 8.3.
- **Whitespace:** `git diff --check`.
- **Source contracts:** `platform/tests/<area>-contract.php` and `-unit.php`. Add the extensions
  CI installs when a test needs them (`pdo_sqlite`, `sqlite3`, `curl`, `mbstring`). The contract
  list in `.github/workflows/pr-runtime-gate.yml` is the authoritative set.
- **Runtime suites:** `platform/runtime/bin/run-<suite>-runtime.sh`. These already drive Docker
  with the CI images `mariadb:11.4`, `wordpress:php8.3-apache` and `wordpress:cli-php8.3`.
- **Service tests:** `bash services/tests/run-all.sh` (CI uses PHP 8.3 with `pdo_sqlite`,
  `sqlite3`, `curl`, `mbstring`).

Rules:

- State which environment ran the checks (native PHP 8.3 or the container image). Never claim
  container parity you did not verify.
- **Never** weaken, skip or delete a contract test to make a change pass. Fix the code or stop.
- The **PR runtime gate is authoritative**. A green local run never substitutes for green CI,
  and a pending or skipped required check is never treated as passing.
- Tests and acceptance data use synthetic identities only. Never use real academy data,
  real student/teacher identities, or live payment details. Follow the pre-launch acceptance
  rules in `README.md`.
- Never commit secrets, tokens, keys or `.env` values. Document required variables without values.

### 2.2 Docker retirement

Lint and source contracts do **not** require Docker: they run on any PHP 8.3 host with the CI
extensions, and the container is optional parity. Docker is still required for
`platform/runtime/bin/run-*-runtime.sh`, because those suites pull `mariadb:11.4`,
`wordpress:php8.3-apache` and `wordpress:cli-php8.3`. Docker can be retired from the repository
only when those suites gain a native harness or are formally declared CI-only; until then the PR
runtime gate remains authoritative.

## 3. Change discipline

- One concern per PR; keep diffs reviewable and inside the stated scope.
- Preserve existing behaviour unless the PR explicitly changes it, and say so.
- Do not edit unrelated files, reformat untouched code, or bump dependencies opportunistically.
- Production is read-only for agents. Never run migrations, writes, charges, notifications or
  calendar mutations against production.
- If a required check cannot be fixed inside the branch scope, stop and report the blocker.
  Never merge around a failing check.

## 4. Verification and handoff

- **OpenCode** finishes with: the PR URL; the list of changed files; the exact checks run and the
  environment used (native PHP 8.3 or container image/command); an explicit statement of the
  build status — this repository has no local production build, so never claim one passed; and the
  handoff line "Ready for Codex review." It does not merge or deploy.
- **Codex** finishes a release only after: PR gate green, review done, merge committed, deployment
  run succeeded, smoke result reviewed, and the release record updated.
- Neither agent claims a merge or deployment it did not perform. The evidence is the GitHub PR,
  workflow run and deployment record URLs.

## 5. Repository map

- `platform/` — canonical Delnavazan Platform plugin and backend/application services.
- `theme/` — Delnavazan WordPress theme and user-facing frontend.
- `deployment/` — guarded production release and read-only QA tooling (Codex-only).
- `.devcontainer/` — PHP 8.3 CI-parity image for containerized agent sessions.
- `tools/ci/` — `check.sh` plus the `run-in-container.sh` wrapper for lint and contracts.
- `legacy-review/` — migration notes; not active code.

`README.md` and `deployment/README.md` remain authoritative for product background and release
mechanics. This file is authoritative for **who may do what**.

## 6. Contract synchronization (Codex-owned)

Whenever Codex changes CI checks, build or test scripts, package-manager commands,
Node/PHP/runtime versions, Docker build requirements, or any workflow in
`.github/workflows/**`, Codex must review and update this `AGENTS.md` in the
**same commit** so OpenCode receives the current commands and boundaries.

- Record the exact executable commands that match GitHub Actions; never prescribe
  `npm ci`, `npm run lint`, `npm run build`, or `npm test` unless the repository
  actually defines and uses those commands.
- If a workflow change introduces a new check, make it mandatory for the affected
  PRs and describe it here. Never treat a skipped or pending required check as passing.
- OpenCode must refresh from current `origin/main` and reread `AGENTS.md` before
  beginning each new feature branch or resuming work after a release change.
- This document describes agent responsibilities; GitHub branch protections,
  permissions, and environment rules must separately enforce release access.
