# Reproducible testing and risk-based regression guide — Phase 9 (2026-11-12)

**Source:** Read-only snapshot `251461bf`. This guide documents observed workflows, not a newly implemented CI change. Codex owns notifications; DeepSeek owns availability and golden hours.

## Prerequisites
- Match the PR gate runtime: PHP **8.3** (configured in `.github/workflows/pr-runtime-gate.yml:48-57`), repository checkout and Git.
- Source-only/unit checks can run from the repository root with `php platform/tests/<name>.php`. A passing static contract does not prove a live database or provider.
- Database-backed runtime checks need an **isolated disposable WordPress + MariaDB** install, working WP-CLI, `wp_get_environment_type()` returning `local` or `development`, platform installed/migrated, and an explicitly enabled per-test environment variable. Never point these commands at staging or production without a separate authorised safe test plan.
- Examples from actual test guards:
  - `DZN_BOOKING_SLOT_RUNTIME=runtime wp eval-file platform/tests/booking-slot-conflict-runtime.php`
  - `DZN_BOOKING_PROVISIONING_RUNTIME=runtime wp eval-file platform/tests/booking-provisioning-recovery-runtime.php`
  - `DZN_FINANCE_INTRO_RUNTIME_TEST=authority wp eval-file platform/tests/finance-intro-context-runtime.php --user=1`
  These are **reference commands for disposable local setup**, not commands executed during this audit. The finance script documents the `--user=1` requirement; other scripts require verifying fixture and user setup.
- Database runtime scripts may insert synthetic rows. Use a dedicated database with backup/cleanup, no real students, and test-only external integrations.

## Run tiers
### Tier 0 — every change
`git diff --check`; lint modified PHP with `php -l`; run changed-path source contracts and small unit tests. For JavaScript changes use `node --check` where applicable. Review access controls for changed endpoint/actions.

### Tier 1 — every PR / before merge
Use **the actual GitHub PR runtime gate** at the exact head SHA. Its `source-contracts` job lints all tracked PHP and invokes many source and unit contracts; the workflow has 260 direct `php platform/tests/` invocation lines (not 260 distinct tests). Review `risk-classification`, source contracts, relevant CodeQL/provider/finance jobs, and any changed-path tests. Require a green exact-head gate, peer review and no unresolved conflict. Do not waive required checks merely because Tier 0 passed.

### Tier 2 — high-risk or cross-domain PRs
On disposable WP/MariaDB run relevant guarded runtime tests, concurrency runners, migration upgrade/rollback recovery tests, provider-fake idempotency and owner/role HTTP checks. Booking, payment, enrolment, Meet, permissions, schema, webhook or cron changes always merit Tier 2.

### Tier 3 — preproduction release
On isolated staging verify complete journey: intro request → teacher acceptance → Google Meet event, teacher co-host and recording setting → attendance/manual fallback → payment link → **test-mode** Stripe settlement → funded enrolment → future lesson schedule and both portals. Inject failures: OAuth disconnected, provider timeout, duplicate webhook, missing cron, conflicting booking, reschedule. No real payments/messages. Record timestamps, IDs, traces and result.

### Tier 4 — production deploy
Use `.github/workflows/deploy-production.yml` manually on **current main SHA**, with protected `production` environment, required approvals, backup and migration checks. Read-only HTTPS smoke is a liveness check only. Follow with **read-only** queue/cron/Google/Stripe/Meet diagnostics and post-deploy exceptions. Do not invoke synthetic booking, charges or notifications on production.

## Change-to-test routing
| Change | Required focus |
|---|---|
| Booking/teacher acceptance | Occupancy, duplicate slots, acceptance/recovery, real DB concurrency |
| Meet/Google/OAuth | Provisioning retries, co-host/recording, credentials, event idempotency |
| Attendance/post-intro | Verified participant identity, teacher fallback, outcome idempotency, continuation |
| Stripe/payment/enrolment | Signature/replay, settlement idempotency, instalments, activation and funded schedule |
| Portal/frontend | API contracts, role ownership, user-visible failures, Persian RTL/Jalali/mobile |
| Admin/delegation/God Mode | Capability/nonce/IDOR matrix, audit, finance isolation |
| Notifications | Codex-owned: evaluate final PR, not competing implementation |
| Availability/golden hours | DeepSeek-owned: evaluate final PR, not competing implementation |
| Database/schema | Fresh install, migration upgrade, failure recovery, schema version, backup |
| Deployment | Deployment risk classifier, pinned SHA, backup/restore safeguards, read-only smoke |

## Practical release checklist
1. Record feature PR, exact head SHA, base SHA, changed paths, migration identifiers, owners and risk tier.
2. Run Tier 0; require Tier 1 green at exact head; add Tier 2 for critical areas.
3. Confirm no conflicting agent branches, no unreviewed generated artifacts, no missing migrations and no unexpected test fixture side effects.
4. Execute Tier 3 before sensitive production releases, retain evidence.
5. Deploy Tier 4 only after explicit approval; capture release SHA, verification JSON, backup location and exception queues.
6. For failures: stop, retain logs, distinguish environment guard/refusal from application assertion, fix and rerun at new head.

## Observed limitation
Three guarded tests returned exit 1 under plain `php` during the audit because the required WP-CLI/local runtime was not provided; do not call these regression failures. No disposable DB environment was set up in this phase. The original handover ZIP predates audit additions; make a separately named verified archive at finalisation.

**No application edits, merges or deployments.**
