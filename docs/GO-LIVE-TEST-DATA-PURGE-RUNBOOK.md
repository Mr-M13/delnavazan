# Go-live test-data purge runbook

**Status: NOT EXECUTED. This document is a controlled procedure, not approval.**

Owner decision recorded for the 2026-11-16 go-live window:

- Every current Student, Teacher, booking, class, payment and portal record is pre-launch **test
  data**; there is no real money, refund, consent or legal evidence to retain.
- Keep exactly one human account: `hamed.moqadasi@yahoo.com`, still an administrator.
- Also remove that account's own Student portal identity, bookings and Teacher association.
- Keep the catalogue, settings and integrations.
- Cleanup of Google Calendar/Meet events, LiveKit rooms and Stripe objects is **DB-only**; those
  provider-side objects are reconciled separately after the purge.

The destructive mode is dispatched only through the protected
`purge-production-test-data.yml` workflow; the payload then runs over the protected production SSH
account inside the `production` environment. A session never runs the execute mode directly
(`AGENTS.md` §3). The read-only `report` may be run from an approved workstation or by the workflow.

## 1. What the tool does

`deployment/qa/purge-test-data.sh` runs a payload that:

1. refuses to run outside a `production` WordPress environment or without WP-CLI;
2. requires the deployed build identity to equal `main-<first 12 chars of the passed main SHA>`;
3. requires the retained administrator to exist and hold `manage_options`;
4. inventories every `{prefix}dzn_*` table and **fails closed** if any table is not in the
   reviewed classification list (`deployment/qa/purge-test-data-tables.php`); classified tables
   this deployment has not created yet are reported as `absent_tables` and skipped (they hold no
   rows);
5. in `report` mode, changes nothing and prints a pre-state fingerprint;
6. in `execute` mode, takes a full `wp db export`, verifies its gzip integrity and SHA-256, then
   requires the exact report fingerprint and confirm token before deleting;
7. deletes inside one transaction, re-verifies inside the transaction, and only then commits;
8. writes a PII-minimised receipt under `wp-content/uploads/dzn-go-live-purge/`.

### Purged

- Every Student, Teacher, principal link, invitation, claim and onboarding row.
- Every booking request, coordination case, proposal, acceptance and contact snapshot.
- Every enrolment, term, lesson, schedule version, attendance, continuation and capacity row.
- Every commercial offer, purchase, entitlement, obligation, payment evidence, payment execution,
  refund, renewal, recovery and finance statement row.
- Every consent decision/authorisation and recording artefact row.
- Every notification, outbox, portal capability, reschedule, provider mapping, Meet classroom,
  LiveKit room and integration connection row.
- Every God Mode session/test row (the authority row for the retained admin is preserved).
- Public research-name submissions (`dzn_research_name` posts) and comments authored by deleted
  identities (with their metadata).
- Every WordPress user except the retained administrator and the dedicated
  automation/worker principals. The principal identities are discovered from the reviewed
  system-user options (`dzn_post_intro_automation_user_id`, `dzn_platform_payment_worker_principal`,
  `dzn_reschedule_system_user_id`, `dzn_reschedule_calendar_system_user_id`,
  `dzn_replacement_system_user_id`, `dzn_platform_provider_projection_worker_principal`,
  `dzn_notification_dispatch_user_id`, `dzn_provider_evidence_system_user_id`) and only when the
  login is a synthetic `dzn`/`dzn-` account without the `dzn_teacher` role. Content authored by
  deleted users is reassigned to the retained administrator rather than deleted.
- The retained administrator's `dzn_teacher` role and teacher-assignment user meta.
- The monthly-statement draft actor option (it points at a deleted Teacher identity); statement
  drafting is switched off and the administrator re-enables it deliberately after go-live.

### Preserved

Courses, instruments, intro-course defaults, commercial products/prices/promotions/policies,
finance policies/policy root/regional prices/settings/cash accounts, notification
templates/versions/workflows/rules, Telnyx templates, consent purposes, recording notice versions,
catalogue readiness evidence (`core_dataset_*`), payment-provider account and secret configuration,
Meet workspace connections/credentials, the Stripe live-activation marker, and the retained
administrator's God Mode authorisation.

Both lists are `deployment/qa/purge-test-data-tables.php`. The PR contract parses every
`CREATE TABLE` in the schema source and fails if a table is missing from either list, so a new
migration cannot silently leave user data behind or silently wipe configuration.

## 2. Preconditions

- [ ] Owner approval for this exact window, recorded in the change ticket.
- [ ] Field-level review of `purge-test-data-tables.php` against the current schema (PR contract green).
- [ ] Maintenance mode enabled so no Student/Teacher/provider write can land between report and execute.
- [ ] Provider workers, WP-Cron and payment reconciliation stopped for the window.
- [ ] No deployment is running or queued.
- [ ] An off-host copy target exists for the database export.

## 3. Preflight (read-only)

```sh
export PRODUCTION_SSH_USER=<protected-production-ssh-user>
MAIN_SHA="$(git rev-parse origin/main)"

# The wrapper pins the deployed build to main-<first 12 SHA characters>.
# Read it first; if it differs from MAIN_SHA, finish or wait for the deploy.
ssh "${PRODUCTION_SSH_USER}@cp119.hosting-cloud.net" \
  'wp --path="$HOME/domains/delnavazan.com/public_html" eval "echo DZN_PLATFORM_BUILD_ID, PHP_EOL;"'

bash deployment/qa/purge-test-data.sh report "$MAIN_SHA" | tee /secure/backups/go-live-purge/report.json
```

Review before continuing:

- `retained_admin.manage_options` is `true` and the email is `hamed.moqadasi@yahoo.com`.
- `unclassified_platform_tables` is absent (the tool aborts if it is not).
- `purge_row_total` matches the expected test-data volume.
- `unmanaged_tables` contains no table that holds Student/Teacher/payment data. If it does, **stop**
  and extend the reviewed classification before executing.
- `preserve_counts` is the intended catalogue/configuration baseline.

Record `fingerprint` from the report. It binds the execute run to exactly this pre-state.

## 4. Backup

`execute` takes the export itself and refuses to continue unless:

- the file is at least 1 MiB;
- `gzip -t` passes;
- the SHA-256 re-read by the payload matches the wrapper's checksum.

The wrapper stores `pre-purge-<UTC>.sql.gz` and `.sha256` under
`$HOME/backups/go-live-purge/` on the host. Copy both off-host before executing and verify:

```sh
gzip -t pre-purge-<UTC>.sql.gz
shasum -a 256 -c pre-purge-<UTC>.sql.gz.sha256
```

## 5. Execute

Execution is dispatched only through the protected, audited workflow. It runs the read-only
`report` first, aborts if the fingerprint differs from the approved value, then runs `execute`.

```sh
gh workflow run purge-production-test-data.yml --ref main \
  -f commit_sha=<full-sha-of-the-deployed-build> \
  -f fingerprint=<fingerprint-from-the-approved-report> \
  -f confirm=PURGE_ALL_TEST_DATA

gh run watch <run-id> --exit-status
```

The workflow is `workflow_dispatch`-only, runs in the `production` environment, shares the
`delnavazan-production-deploy` concurrency group so it cannot overlap a release, and refuses after
2026-11-23.

The run aborts without deleting anything if the fingerprint no longer matches (any intervening
write), if the deployed build SHA does not match, if the backup is missing or corrupt, or if the
confirm token is not exact. The report and execute JSON are retained as a 90-day workflow artifact.

## 6. Verify

From the execute JSON and the receipt file:

- `deletions_performed` is `true`, `post_verification.purge_rows_remaining` is `0`.
- `post_verification.users_remaining` equals the retained admin plus present automation identities.
- `post_verification.admin_manage_options` is `true`.
- `post_verification.catalogue_unchanged` and `schema_version_unchanged` are `true`.
- `backup_sha256` matches the off-host copy.

Then check the live site:

1. Log in as `hamed.moqadasi@yahoo.com`; confirm administrator access and the God Mode tab.
2. Confirm Students and Teachers lists are empty and the catalogue still lists courses/instruments.
3. Confirm the booking page loads and a read-only availability query returns no test leftovers.
4. Confirm no Student/Teacher portal session survives for the admin account.
5. Confirm `/wp-admin/` has no pending provider/notification jobs.
6. Disable maintenance mode only after all checks pass.

## 7. Provider-side cleanup (DB-only decision)

The purge deletes only local rows. Cancel or delete the following so they cannot resurface at
launch, then record the evidence in the receipt ticket:

- Google Calendar events and Meet spaces created by test bookings and God Mode sessions.
- LiveKit rooms/recordings created by test attendance.
- Stripe test-mode customers, payment intents and objects linked to the deleted evidence.
- Telnyx/portal notification history is local only; no provider action is required.

## 8. Rollback

If any pre-commit check fails, the payload rolls back automatically and deletes nothing.

If a post-commit check fails:

1. Enable maintenance mode and stop all workers.
2. Restore the verified `pre-purge-<UTC>.sql.gz` export with the hosting provider's database restore.
3. Re-run `wp option get dzn_platform_schema_version` and confirm the pre-purge schema value.
4. Re-run `report` and compare the fingerprint with the approved baseline.
5. Record the rollback in the change ticket.

The receipt is evidence, not a backup. The database export is the authoritative recovery path.

## 9. Explicit non-goals

- No provider API call, charge, notification, calendar mutation or recording deletion.
- No change to catalogue, price, policy, template or integration configuration.
- No second production deployment or schema migration.
- No unattended, scheduled or deployment-triggered execution.
