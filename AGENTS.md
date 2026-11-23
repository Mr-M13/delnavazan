# AGENTS.md — Delnavazan agent contract

Version 3.0 · effective 2026-11-16

This file is the single, shared contract for AI work in this repository. **OpenCode (DeepSeek) is
the sole execution agent**: it develops, reviews, merges and releases, and may run several sessions
in parallel. External agents — Codex, ChatGPT, GitHub Copilot, Claude and any future tool — are
**advisory only**: they may read the repository, plan and comment, but they never execute.

The retired two-agent prompts ("Prompt 1" for OpenCode, "Prompt 2" for Codex) are superseded. If an
old prompt, habit or default behaviour conflicts with this file, this file wins.

## 0. Session acknowledgement

At the **start of every session**, before any edit:

1. Read this file in full.
2. State in your first reply that you are the sole execution agent, name the session and the branch:
   - `Acknowledged AGENTS.md v3.0 — OpenCode: sole execution agent, session <id>, branch <branch>`
3. Confirm the branch you are on. If the branch does not match the rules below, stop and fix
   the branch before touching files.

Working directory or execution environment does not change these rules. They apply on the host and in GitHub Actions.

## 1. Roles

### 1.1 OpenCode — sole execution agent

OpenCode holds development, review, merge and release authority, and may run several sessions in
parallel (see §1.4). GitHub branch protections, required status checks, the protected `production`
environment and GitHub Actions remain the enforcement and execution authority; this file describes
how OpenCode must use them.

**Development**

- Work on short-lived feature branches from current `origin/main`; never push directly to main.
- One concern per PR. Keep diffs reviewable and inside the stated scope.
- Run the checks in §2 that apply to the diff before pushing.
- Open the pull request with the template: scope, checks and the environment that ran them, risk,
  rollback, and the session that executed it.

**Review**

- Every change gets a recorded self-review before merge: read the full diff, confirm the scope is
  what the PR claims, confirm the checks are the ones the diff needs, and look specifically for
  authority, migration, payments, identity/privacy, concurrency, destructive-operation, calendar
  and production-surface risk.
- The self-review record lives in the PR body: what was verified, at which head SHA, and what was
  deliberately not verified and why.
- Advisory review from Codex, Copilot, ChatGPT or a human is welcome and should be recorded (§1.2),
  but it is evidence, not a gate. Do not block a release waiting on a reviewer that may not respond.

**Merge**

- Merge only when every required check is green for the exact head SHA on a strict up-to-date head
  and every review thread is resolved.
- Merge through the normal GitHub merge. The ruleset's administrator bypass is an emergency tool
  only; if it is used, record why in the PR and in the release report.
- Never merge around a failing, pending or skipped required check.

**Release**

- Dispatch only the canonical Deploy production workflow from `main` with the full current `main`
  SHA, and verify the exact SHA, run result, production smoke and deployment record before
  reporting success.
- Dispatch the host-tooling install only through
  `.github/workflows/install-production-host-tooling.yml`, from `main`, with the exact `main` SHA
  and the `INSTALL_HOST_TOOLING` token. It installs the reviewed backup/restore scripts and the
  nightly host cron and takes one rollback point; it cannot restore, and restore stays an operator
  action (§2).
- Do not dispatch a second production deployment while one is queued or running. On failure,
  diagnose and use the documented guarded retry or rollback procedure.
- Do not access or disclose deployment secrets, and do not run direct production deploy scripts or
  production writes outside audited GitHub workflows.
- A docs-only or test-only merge does not need a production dispatch; say explicitly in the handoff
  whether production was redeployed and which SHA it serves.

**Governance**

- Changes to `.github/workflows/**`, `deployment/**` or this file need their own PR, a written risk
  note, and a self-review that states exactly which controls change.
- Keep this file in sync with the live repository settings (§6). A control described here that is
  not actually enforced is a defect in this file.

### 1.2 External agents — advisory only

Codex, ChatGPT, GitHub Copilot, Claude and any other external tool are **advisory**. They may be
pointed at the repository to plan, review or audit, and may comment on pull requests and issues.

- They are never the executing identity: no write credentials, no branch pushes, no merges, no
  workflow dispatches, no production access.
- Their findings are advice. OpenCode re-verifies every accepted finding in the working tree and in
  CI before acting on it, and records where the advice came from.
- An advisory review never substitutes for the self-review record or for green required checks.
- Do not install or re-enable an agent's coding integration — for example the Copilot coding-agent
  environment or a Codex cloud workspace — without a governance PR that updates this section.

### 1.3 Enforced GitHub controls

These controls live in repository settings, so they hold even when a session misbehaves:

- **Ruleset `Protect main`** (active): blocks deletion and non-fast-forward pushes, requires a pull
  request, requires the five mandatory status checks (`PHP source contracts`, `Stripe runtime E2E`,
  `Post-intro runtime lifecycle`, `OAuth scope MariaDB regression`, `Booking stress runtime and
  fairness`) on a strict up-to-date head, and requires review threads to be resolved. Approval and
  code-owner requirements are off: the repository has one write identity, so an approval gate could
  only be satisfied through an administrator bypass, which enforces nothing. The review record is
  the PR self-review instead.
- **`production` environment**: deploys are restricted to `main` by an explicit branch policy, and
  administrators cannot bypass the environment. Required reviewers are off for the same reason as
  above; the dispatched SHA and the deployment record are the release evidence.
- **`admin` bypass** on the ruleset: retained for emergency recovery, audit-logged, and not part of
  the normal path. A merge that needs it is a signal to fix the branch, not to bypass again.

**Residual risk.** The gate is now "required checks + recorded self-review + audit log" rather than
second-person review. Anyone holding the owner token — or any session it runs — can change `main`
and production. Two controls restore a human gate without touching workflows: add required
reviewers to the `production` environment, or add a second GitHub identity with write access and
turn the ruleset approval requirement back on. Record whichever is in force here when it changes.

### 1.4 Multi-session operation

Several OpenCode sessions may work at once. Sessions are parallel lanes of the same authority, not
separate authorities, so they must not collide:

- One session owns one branch and one PR. Check `git ls-remote --heads origin` for an existing
  branch before starting; reuse or coordinate rather than duplicating work.
- Use a separate `git worktree` per concurrent session, and never edit another session's working
  tree, branches or uncommitted files.
- Name branches `<area>/<slug>-<yyyymmdd>` and state the session in the PR body
  (`Session: <short id or slug>`).
- Only one production deployment may be queued or running at a time across all sessions; check
  `gh run list --workflow deploy-production.yml` before dispatching.
- A session that finds unreviewed work from another session in its scope stops and reports it
  instead of overwriting it.
- Merge order follows readiness — green checks and a complete self-review — not the order branches
  were opened.

## 2. Build and test rules

The repository is a PHP 8.3 WordPress plugin (`platform/`) and theme (`theme/`). CI runs on
Ubuntu 24.04 with PHP 8.3.

**There is no install step and no local production build.** The repository has no
`composer.json`, `package.json`, bundler or compiled-asset pipeline; theme assets are committed
as-is. Never invent or claim `composer install`, `npm ci`, `npm run lint`, `npm test` or
`npm run build`. The packaging step in `.github/workflows/deploy-production.yml` is a protected GitHub Actions release step, not a local build.

Before pushing, every session runs the checks that apply to its diff on **PHP 8.3 with the CI
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
  `bash platform/tests/booking-stress-runner-contract.sh`. The paid-intro Option B
  surface is covered by `php platform/tests/booking-payment-expiry-unit.php`
  (deadline math, expiry transition and slot release) and
  `php platform/tests/booking-payment-slot-security-contract.php` (paid-intro
  canonical visibility, unpaid non-blocking and conflict-at-payment). The
  time-budgeted offer surface is covered by
  `php platform/tests/booking-offer-window-unit.php`
  (the 4h–24h formula, the T−12h terminal gate and the hold exception) and
  `php platform/tests/booking-sole-teacher-hold-unit.php` (extend-not-expire,
  one hold alert, terminal expiry and decline-is-not-a-hold). The Teacher
  booking-priority surface is covered by
  `php platform/tests/booking-priority-allocation-unit.php` (first offer,
  normal fall-through, ordering, cursor neutrality, the 8h/T−12h cap and the
  teacher-wide vs instrument-course scope). The re-offer lift is covered by
  `php platform/tests/booking-offer-reoffer-unit.php` (versioned offer/decline
  ledgers, same-set ban, new-set re-offer and sticky declines). The retired-URL
  surface is covered by
  `php platform/tests/seo-legacy-redirects-contract.php` and
  `php platform/tests/seo-legacy-redirects-unit.php`: every URL in
  `deployment/seo/production-seo-baseline.csv` must stay mapped to a live target, and a retired path
  must never be redirected while WordPress still serves it. The virtual-route canonical is covered by
  `php platform/tests/seo-virtual-route-canonical-contract.php` and
  `php platform/tests/seo-virtual-route-canonical-unit.php`.
  The read-only People directory (Students/Teachers/Leads + owner contact import) is covered by
  `php platform/tests/people-directory-status-rules-unit.php`,
  `php platform/tests/people-directory-import-unit.php` and
  `php platform/tests/people-directory-read-service-contract.php`.
  The operator-surface gate also runs
  `php platform/tests/operator-refusal-coverage-contract.php`, a ratchet that fails when a new
  snake_case domain refusal code has no operator sentence (its baseline is
  `platform/tests/fixtures/operator-refusal-unmapped.txt`; regenerate it only after mapping the new
  codes, with `DZN_REFUSAL_BASELINE_UPDATE=1`), and
  `php docs/audits/tools/method-call-check.php --root=platform/src`, which fails when a typed call
  site names a method its receiver class does not declare (the defect class behind the 2026-11-22
  `Enrollment`/`Enrolment` fatal).
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

- **Go-live data purge:** the one-shot pre-launch purge is
  `deployment/qa/purge-test-data.sh` (report, then execute with a pre-state fingerprint and a
  verified full database export). Its source contract
  `deployment/qa/purge-test-data-contract.sh` proves every schema table is classified as preserved
  configuration or purged user activity. The destructive mode is dispatched only through the
  protected, workflow-dispatch-only `purge-production-test-data.yml` from `main`, inside its
  `2026-11-23` window, with the pinned tool source SHA, the deployed build SHA, the report
  fingerprint and the confirm token. It requires the production-only
  `PURGE_ATTESTATION_TOKEN` secret and refuses a second run after the durable one-shot marker
  exists. A session never runs the execute mode directly.
- **Targeted test-account deletion:** the guarded single-account path is
  `deployment/qa/delete-test-student.sh` (report, then execute with a pre-state fingerprint and a
  verified full database export). The target is accepted only as the SHA-256 of the account email;
  the retained administrator hash and any administrator target are refused. The destructive mode
  is dispatched only through the protected, workflow-dispatch-only
  `delete-test-student.yml` from `main`, with the pinned tool source SHA, the deployed build SHA,
  the target email hash, the report fingerprint and the `DELETE_TEST_STUDENT` confirm token. It
  reuses the production-only `PURGE_ATTESTATION_TOKEN` secret and is serialized with production
  deploys. It deletes only rows reachable from the exact Student/booking/WP-user graph inside the
  reviewed purge table classification, preserves the catalogue/configuration counts, and verifies
  zero target rows remain. Its offline source contract,
  `bash deployment/qa/delete-test-student-contract.sh`, runs in the PR gate's production-tooling
  step. A session never runs the execute mode directly.
- **Imported-enrolment retirement:** the historical-course import ("Import existing enrolment")
  has no compensating action in the admin UI — its lessons are created through the schedule
  authority and become `scheduled`, so the generic archive path refuses them and no legacy cancel
  action exists. The one guarded reversal is `deployment/qa/retire-imported-enrolment.sh` (report,
  then execute with a pre-state fingerprint and a verified full database export). It targets one
  Enrolment by its `ENR-…` reference, the owning Student's `DZN-STU-…` reference and that import's
  `DZN-TRM-…` Term reference, and it **archives in order**: the imported lessons, the Term, then the
  Enrolment. It never deletes rows. It refuses
  anything without `enrolment.historical_import` audit evidence, a Term whose `payment_state`
  recorded money, a lesson that already started, a lesson linked to a booking request, any row in
  another `dzn_` table that still references the Enrolment or its lessons, and any existing
  canonical Enrolment for the same Student/Course/Teacher. Execute mode is dispatched only through
  the protected, workflow-dispatch-only `retire-imported-enrolment.yml` from `main`, with the
  pinned tool source SHA, the deployed build SHA, the report fingerprint and the
  `RETIRE_IMPORTED_ENROLMENT` confirm token. It reuses the production-only
  `PURGE_ATTESTATION_TOKEN` secret, is serialized with production deploys, and verifies afterwards
  that the lessons are gone from the Student portal, the Teacher's slot is free again, the Student
  and catalogue are intact, and one audit event exists. Its offline source contract,
  `bash deployment/qa/retire-imported-enrolment-contract.sh`, and the pure decision unit harness,
  `php deployment/qa/retire-imported-enrolment-unit.php`, run in the PR gate's production-tooling
  step. A session never runs the execute mode directly.
- **Intro pre-flight diagnostic:** a failed admin "Create intro" shows only an opaque
  `EWI-…` reference and the host keeps no readable PHP error log for it, so
  `deployment/qa/intro-preflight-diagnostic.sh` runs the same read-only pre-flight on the host
  and prints each check with the exact exception. It writes nothing, takes no backup, needs no
  confirm token and no attestation, and is dispatched only through the protected
  workflow-dispatch-only `diagnose-intro-preflight.yml` from `main`. Its offline source contract,
  `bash deployment/qa/intro-preflight-diagnostic-contract.sh`, runs in the PR gate's
  production-tooling step.
- **Backup and rollback tooling:** `deployment/qa/backup-point.sh` takes one verified production
  rollback point on the host — a gzip + SHA-256 database export, a `public_html` archive without
  `wp-content/cache`, and a manifest recording the `main-<12 SHA>` build identity and
  `dzn_platform_schema_version` — and keeps only the newest `DZN_BACKUP_KEEP=2`.
  `deployment/qa/restore-point.sh --confirm RESTORE` restores the newest or a named point under
  maintenance mode, refuses a corrupt archive, a missing token or a schema that does not pair with
  the deployed code, and prints the point's build identity to redeploy. Its source contract is
  `bash deployment/qa/backup-rollback-contract.sh`, which runs in the PR gate's production
  tooling step and is offline.
  Installing the tooling on the host and taking the first point is dispatched only through the
  protected, workflow-dispatch-only `.github/workflows/install-production-host-tooling.yml` from
  `main`, with the exact current `main` SHA, the literal `INSTALL_HOST_TOOLING` confirm token and
  the `production` environment. It creates `~/bin` (0700), copies the two reviewed scripts as
  `~/bin/dzn-backup-point.sh` and `~/bin/dzn-restore-point.sh` (0700), appends the single 03:07 UTC
  cron line only when its marker is absent, then takes one point with `DZN_BACKUP_REMOTE` empty
  (`OFFSITE=skipped`). Its source contract,
  `bash deployment/qa/install-host-tooling-contract.sh`, runs in the same PR gate step and is
  offline. A dispatch can copy two reviewed scripts, append one cron line and take a point; it
  cannot restore, delete, deploy, migrate, purge or read a credential. The nightly cron then runs on
  the host, and the encrypted rclone remote stays an owner action. **Restore remains operator-only
  and never runs from CI.** The procedure is `docs/BACKUP-ROLLBACK-RUNBOOK.md`.
- **WhatsApp template creation:** `.github/workflows/create-whatsapp-templates.yml` creates the
  reviewed Utility templates from `deployment/notifications/whatsapp-templates.json` in the owner's
  Telnyx WABA, so the platform can later map them via Integrations → WhatsApp → "Refresh approved
  templates". It is workflow_dispatch-only, runs in the protected `production` environment from
  `main`, and is bound to the exact current origin/main SHA plus the `CREATE_WA_TEMPLATES` confirm
  token; the default mode is `dry_run` (list + report only), and `apply` creates only the missing
  templates with ~2s pacing. It uses only the `TELNYX_API_KEY` and `TELNYX_WABA_ID` secrets, never
  prints them, and fails closed if either is missing. It can list templates (GET) and create missing
  ones (POST); it cannot update, delete, send a message, deploy, migrate or purge. Its source
  contract, `bash deployment/notifications/whatsapp-template-create-contract.sh`, runs in the PR
  gate's production-tooling step and is offline.
- **Article publishing:** `.github/workflows/publish-articles.yml` applies the reviewed article
  bodies and Rank Math SEO meta from `docs/articles/improved/*.md` (58 files, merged at #658) to
  the live WordPress posts they describe, from a runner over SSH + WP-CLI. It is
  workflow_dispatch-only, runs in the protected `production` environment from `main`, and is
  bound to the exact current origin/main SHA plus the `PUBLISH_ARTICLES` confirm token; the
  default mode is `dry_run` (locate + report only), and `publish` updates after writing a
  per-article pre-image backup. The runner-side parser
  `deployment/articles/publish-articles-plan.php` turns each article into a payload (slug from
  the exact URL path segment, SEO title/meta/focus keyword, tags, and the Markdown body converted
  to HTML with the honesty note, SEO block and header metadata excluded); the host-side processor
  `deployment/articles/publish-articles-host.php` locates each post BY SLUG (refusing when absent
  or ambiguous), and can update post_content, the Rank Math meta (`rank_math_title`,
  `rank_math_description`, `rank_math_focus_keyword`) and the post_tag terms of an existing post,
  then reads it back and proves the content hash. It can update the content + SEO meta of existing
  posts selected by slug and take pre-image backups; it cannot create a post, change a slug or a
  post status, delete anything, deploy, migrate or touch finance. Its source contract,
  `bash deployment/qa/publish-articles-contract.sh`, runs in the PR gate's production-tooling step
  and is offline; it also proves all 58 articles parse to a complete payload and that every
  workflow `run:` block parses with `bash -n`.
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
only remaining Docker dependency and are **CI-only for sessions**: the PR runtime gate runs them,
and a green native run never substitutes for green CI. Removing Docker from the repository
entirely means replacing that harness with a native MariaDB/WordPress setup, or deleting the
suites — both need governance review and a workflow change.

## 3. Change discipline

- One concern per PR; keep diffs reviewable and inside the stated scope.
- Preserve existing behaviour unless the PR explicitly changes it, and say so.
- Do not edit unrelated files, reformat untouched code, or bump dependencies opportunistically.
- Production is read-only for sessions. Never run migrations, writes, charges, notifications or
  calendar mutations against production.
- If a required check cannot be fixed inside the branch scope, stop and report the blocker.
  Never merge around a failing check.

## 4. Verification and handoff

- Each session reports PR URL, changed files, checks and the environment that ran them, risk,
  rollback and the self-review record. No local production build exists.
- OpenCode verifies required CI for the exact head SHA, merges through protections, dispatches the
  canonical workflow when the change needs to reach production, then confirms the deployed SHA,
  smoke results and release record.
- A pending, skipped or failed required check is not a pass. A successful workflow run alone is not
  evidence that production serves the expected commit.
- Never claim a merge, deploy, permission or Mac-level Docker audit without direct evidence.
- Advisory review, when used, is reported with its source and the SHA it was given.

## 5. Repository map

- `platform/` — canonical Delnavazan Platform plugin and backend/application services.
- `theme/` — Delnavazan WordPress theme and user-facing frontend.
- `deployment/` — guarded production release and read-only QA tooling (protected release path).
- `.devcontainer/` — PHP 8.3 CI-parity image for containerized agent sessions.
- `tools/ci/` — `check.sh` plus the `run-in-container.sh` wrapper for lint and contracts.
- `legacy-review/` — migration notes; not active code.

`README.md` and `deployment/README.md` remain authoritative for product background and release
mechanics. This file is authoritative for **who may do what**.

## 6. Contract synchronization (execution-agent-owned)

Whenever OpenCode changes CI checks, build or test scripts, package-manager commands,
Node/PHP/runtime versions, Docker build requirements, or any workflow in
`.github/workflows/**`, it must review and update this `AGENTS.md` in the
**same commit** so every session receives the current commands and boundaries.

- Record the exact executable commands that match GitHub Actions; never prescribe
  `npm ci`, `npm run lint`, `npm run build`, or `npm test` unless the repository
  actually defines and uses those commands.
- If a workflow change introduces a new check, make it mandatory for the affected
  PRs and describe it here. Never treat a skipped or pending required check as passing.
- OpenCode must refresh from current `origin/main` and reread `AGENTS.md` before
  beginning each new feature branch or resuming work after a release change.
- This document describes agent responsibilities; GitHub branch protections,
  permissions, and environment rules must separately enforce release access.
