# AGENTS.md — Delnavazan agent contract

Version 1.2 · effective 2026-11-16

This file is the single, shared contract for every AI agent working in this repository,
including **Codex** and **OpenCode** (DeepSeek). Both agents read it from the repository
root at the start of every session. It is the canonical form of the two operating prompts
("Prompt 1" for OpenCode, "Prompt 2" for Codex); if a prompt, habit or default behaviour
conflicts with this file, this file wins.

## 0. Session acknowledgement

At the **start of every session**, before any edit:

1. Read this file in full.
2. State in your first reply which role you are operating as and which branch you will work on:
   - `Acknowledged AGENTS.md v1.2 — Codex: feature contributor`
   - `Acknowledged AGENTS.md v1.2 — OpenCode: release coordinator`
3. Confirm the branch you are on. If the branch does not match the rules below, stop and fix
   the branch before touching files.

Working directory or execution environment does not change these rules. They apply on the host and in GitHub Actions.

## 1. Roles

### 1.1 OpenCode — release coordinator

OpenCode is the primary coordinator for development, review handoff, CI monitoring, and approved releases. GitHub branch protections, required reviewers, protected environments and GitHub Actions remain the enforcement and execution authority.

- Work on short-lived feature branches from current origin/main; never push directly to main.
- Open pull requests, run native validation, monitor exact-head-SHA required checks, inspect failures, and remediate on the branch.
- Request independent review and explicit owner approval for merges and production deployment; never self-approve.
- Never use the ruleset administrator bypass on your own initiative. Each bypass is recorded in the repository audit log, and it is permitted only for a specific merge the owner has explicitly authorised.
- After approval and green required checks, OpenCode may merge through GitHub's protected merge mechanism, provided its GitHub identity has permission. If not, stop and request an authorised operator.
- Dispatch only the canonical Deploy production workflow from main with the full current main SHA, subject to the protected production environment and its reviewers. Verify exact SHA, run result, production smoke and deployment record before reporting success.
- Do not access or disclose deployment secrets. Do not run direct production deploy scripts or production writes outside audited GitHub workflows.
- Do not dispatch a second production deployment while one is queued or running. On failure, diagnose and use the documented guarded retry or rollback procedure, with owner approval when required.
- Changes to .github/workflows/**, deployment/** or release governance require a dedicated reviewed PR.

### 1.2 Codex and other agents — feature contributors

Codex and other agents may create branches, implement changes, run checks and open PRs. They must not maintain a competing release process, merge without owner authorisation, or dispatch production deployments. OpenCode coordinates releases; GitHub Actions executes them. All agents must use the same canonical repository, tests and safeguards.

### 1.3 Enforced GitHub controls

These controls live in repository settings, so they hold even when an agent ignores this file:

- **Ruleset `Protect main`** (active; only the repository `admin` role can bypass): blocks deletion and non-fast-forward pushes, requires a pull request, requires one approving review, dismisses stale reviews on push, requires the last push to be approved, requires review threads to be resolved, requires code-owner review from `.github/CODEOWNERS`, and requires the four mandatory status checks on a strict up-to-date head.
- **`production` environment**: deploys are restricted to `main` and must be approved manually by the required reviewer before the deploy job starts.
- **`admin` bypass**: the owner role keeps bypass access only because GitHub forbids self-approval and this repository has a single maintainer. Bypass is audit-logged and governed by the rule above.

**Known limitation.** With one GitHub identity there is no second approver. GitHub enforces
separation of duties against other collaborators, not against the owner's own token, and the
owner can approve the environment gate from the same identity. Genuine independent review needs a
second GitHub account with write access (to approve pull requests) added to the environment's
required reviewers. Until then, owner approval is a process control and the audit log is the
evidence.

## 2. Build and test rules (both agents)

The repository is a PHP 8.3 WordPress plugin (`platform/`) and theme (`theme/`). CI runs on
Ubuntu 24.04 with PHP 8.3.

**There is no install step and no local production build.** The repository has no
`composer.json`, `package.json`, bundler or compiled-asset pipeline; theme assets are committed
as-is. Never invent or claim `composer install`, `npm ci`, `npm run lint`, `npm test` or
`npm run build`. The packaging step in `.github/workflows/deploy-production.yml` is a protected GitHub Actions release step, not a local build.

Before pushing, every agent runs the checks that apply to its diff on **PHP 8.3 with the CI
extensions**. Native PHP 8.3 is acceptable when `tools/ci/check.sh doctor` matches; the container
is optional strict parity. Docker is required only for the runtime suites.

- **Toolchain:** `tools/ci/check.sh` wraps the checks. Native-first:
  - `tools/ci/check.sh lint` — `php -l` every tracked PHP file, then `git diff --check`.
  - `tools/ci/check.sh contracts platform/tests/<area>-contract.php` — affected contracts
    (and the matching `-unit.php`).
  - `tools/ci/check.sh doctor` — prove PHP version and extensions before claiming parity.
  `lint` covers worktree, index and committed whitespace; `all` is lint plus services and does
  not run platform contracts, which are targeted explicitly with `contracts`.
  For container parity, pass the same arguments to `tools/ci/run-in-container.sh`; its image tag
  is derived from `.devcontainer/Dockerfile`, so changes rebuild instead of reusing a stale image.
  `.tool-versions` pins PHP 8.3.
- **Whitespace:** `git diff --check`.
- **Source contracts:** `platform/tests/<area>-contract.php` and `-unit.php`. Add the extensions
  CI installs when a test needs them (`pdo_sqlite`, `sqlite3`, `curl`, `mbstring`). The contract
  list in `.github/workflows/pr-runtime-gate.yml` is the authoritative set. The notification
  remediation gate includes `php platform/tests/notification-template-readiness-contract.php`
  beside the notification management contracts. The booking reliability gate also includes the
  host-only `php platform/tests/booking-allocation-workload-unit.php`,
  `php platform/tests/booking-engine-capacity-unit.php`,
  `php platform/tests/booking-engine-scenario-date-unit.php` and
  `bash platform/tests/booking-stress-runner-contract.sh`.
- **Runtime suites:** `platform/runtime/bin/run-<suite>-runtime.sh`. These already drive Docker
  with the CI images `mariadb:11.4`, `wordpress:php8.3-apache` and `wordpress:cli-php8.3`.
  The booking slot conflict suite also proves that two valid reschedules send Calendar revisions
  in version order and that Teacher availability covers the introductory buffer.
  The `booking-stress-runtime` job provisions a disposable stack with
  `platform/runtime/bin/reset-disposable.sh`, then runs
  `platform/runtime/bin/run-booking-engine-stress.sh`: scenarios A–G plus the 2–100 worker
  matrix, crash recovery, idempotent replay and partial-overlap contention. Its raw evidence is
  uploaded as the `booking-stress-evidence` workflow artifact and is never committed. The
  1,000-request measurement is scenario A's request volume, not a 1,000-worker burst; the
  harness spawns at most 100 concurrent processes.
- **Service tests:** `tools/ci/check.sh services` (runs `services/tests/run-all.sh` on PHP 8.3
  with `pdo_sqlite`, `sqlite3`, `curl`, `mbstring`).

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

The local agent path does **not** require Docker: lint, source contracts and the recording
services run natively on PHP 8.3 (`tools/ci/check.sh lint|contracts|services|all`), and the
container is optional parity. The runtime suites (`platform/runtime/bin/run-*-runtime.sh`) are the
only remaining Docker dependency and are **CI-only for agents**: the PR runtime gate runs them,
and a green native run never substitutes for green CI. Removing Docker from the repository
entirely means replacing that harness with a native MariaDB/WordPress setup, or deleting the
suites — both need release-governance review and a workflow change.

## 3. Change discipline

- One concern per PR; keep diffs reviewable and inside the stated scope.
- Preserve existing behaviour unless the PR explicitly changes it, and say so.
- Do not edit unrelated files, reformat untouched code, or bump dependencies opportunistically.
- Production is read-only for agents. Never run migrations, writes, charges, notifications or
  calendar mutations against production.
- If a required check cannot be fixed inside the branch scope, stop and report the blocker.
  Never merge around a failing check.

## 4. Verification and handoff

- Contributors report PR URL, changed files, checks, environment, risk and rollback. No local production build exists.
- OpenCode verifies required CI for the exact head SHA, obtains independent review and owner approval, merges through protections (recording any administrator bypass), dispatches the canonical workflow, then confirms the deployed SHA, smoke results and release record.
- A pending, skipped or failed required check is not a pass. A successful workflow run alone is not evidence that production serves the expected commit.
- Never claim a merge, deploy, permission or Mac-level Docker audit without direct evidence.

## 5. Repository map

- `platform/` — canonical Delnavazan Platform plugin and backend/application services.
- `theme/` — Delnavazan WordPress theme and user-facing frontend.
- `deployment/` — guarded production release and read-only QA tooling (protected release path).
- `.devcontainer/` — PHP 8.3 CI-parity image for containerized agent sessions.
- `tools/ci/` — `check.sh` plus the `run-in-container.sh` wrapper for lint and contracts.
- `legacy-review/` — migration notes; not active code.

`README.md` and `deployment/README.md` remain authoritative for product background and release
mechanics. This file is authoritative for **who may do what**.

## 6. Contract synchronization (release coordinator-owned)

Whenever the release coordinator changes CI checks, build or test scripts, package-manager commands,
Node/PHP/runtime versions, Docker build requirements, or any workflow in
`.github/workflows/**`, the release coordinator must review and update this `AGENTS.md` in the
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
