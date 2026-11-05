# Google Meet classroom recovery — 2026-11-04

Status: **source repair prepared; production dry run, live Google acceptance and production data
repair remain unexecuted until explicitly approved.**

## Confirmed source-level root causes

1. **Existing lessons were never discovered by a recurring worker.** The only backfill path was the
   manual `wp dzn-meet migrate` command. `GoogleWorkspaceWorker` enumerated only rows already present
   in `meet_classrooms`; it had no query for a future authorised Lesson with no active classroom.
   Therefore migration 068 could be applied correctly while existing scheduled lessons still showed
   `Classroom: not provisioned` and `Meet: not provisioned`.
2. **Booking projection retries were gated behind a retired/legacy worker context.**
   `BookingCalendarProjectionWorker` returned unless `GoogleOAuthController::configured()` was true and
   a legacy `ProviderProjectionWorkerContext` service principal existed. The central organizer flow
   does not need either. The central `GoogleWorkspaceWorker` skipped booking rows because its retry
   loop required both `lesson_id` and `schedule_version_id`; introductory classrooms intentionally
   have neither. A transient Google failure therefore stranded the accepted booking in `retryable` or
   `awaiting_conference`.
3. **The Teacher response message collapsed retryable and awaiting-conference states into the old
   “accepting” wording.** The acceptance was durably recorded, but the portal did not explain that
   projection was queued for retry or that an administrator recovery action existed.
4. **Permanent and transient provider failures shared one retry path.** A permanent co-host refusal
   could be retried by the worker indefinitely, while a bounded transient failure had no durable
   booking retry. Provider HTTP status was not consistently persisted with a permanent/transient
   meaning.
5. **Central introductory reschedule/cancellation were incomplete.** `BookingCalendarProjectionService`
   looked only for the legacy `booking_calendar` vault. A central classroom therefore had no provider
   event to reschedule/cancel through that branch.
6. **A blocked central classroom could fall through to a legacy teacher-owned link.** Join services
   attempted central resolution and then fell back to the old mapping when readiness was false. This
   would bypass the central co-host/moderation block.
7. **A transient OAuth refresh failure cleared the active connection slot.** The next worker run could
   not use the organizer until manual re-consent, even when the refresh failure was temporary.

## Repair summary

- Added one deterministic `GoogleWorkspaceClassroomReconciler::plan()` used by both preview and apply.
  The plan has one total operation limit, deterministic imminent-first ordering, per-record dedupe,
  current/expected state, automatic versus administrator-required mode, and an approval-bound digest.
- Apply requires the reviewed digest and revalidates every record version immediately before mutation;
  changed records are skipped as stale rather than applied.
- Added a bounded, secret-free dry-run report and idempotent apply path for missing lessons/bookings,
  stale schedules, Teacher mismatch, retryable classrooms and stranded confirmations.
- Hardened `retry-classroom` with lesson/schedule validation, advisory locking, a version
  compare-and-swap, an audited administrator reason, and no reactivation of inactive/cancelled
  historical rows.
- Added automatic discovery to the existing five-minute worker, gated behind
  `DZN_MEET_AUTO_RECONCILE`/`dzn_meet_auto_reconcile` so deployment cannot trigger an unreviewed
  missing-row production backfill. Stale schedule/booking projections and Teacher mismatch on rows
  that already exist remain recoverable under the existing-row retry policy.
- Central booking retries now run without the legacy principal; the legacy path still stays inside
  its capability context.
- Added an advisory lock per booking confirmation and per worker run. Duplicate execution converges
  on the deterministic Calendar event and the unique active classroom row.
- Added bounded co-host retries, permanent-block protection and explicit operations:
  `retry-classroom`, `approve-cohost-exception`, `retry-booking`.
- Persisted the exact provider status and a controlled failure code (`*_retryable` versus
  `*_refused`) and surfaced blocked/failed rows in Settings → Integrations.
- Central booking reschedule patches the same event; central cancellation is queued through the
  existing retraction worker and preserves classroom/recording history.
- Join readiness is a controlled state (`missing`, `provisioning`, `blocked`, `failed`, `unavailable`)
  with localized recovery copy. A central row prevents fallback to a legacy link.
- The join window is the shared 30-minute early / 30-minute late `ParticipantJoinWindow` policy.
- A transient OAuth refresh failure keeps the organizer connected and active for the next retry.

## Read-only production diagnostic

Run the manual production diagnostic against the exact deployed `main` SHA:

```sh
gh workflow run diagnose-production-release.yml --ref main \
  -f commit_sha=<full-current-main-sha>
```

The workflow runs `deployment/qa/production-release-diagnostic.sh` on the production host through
WP-CLI. The payload performs SELECT/read operations only. It does not run reconciliation, retries,
WP-Cron events, Google APIs, bookings, notifications or provider mutations.

The diagnostic now includes `meet_operational`:

- **Flags** — the effective state and source of `DZN_MEET_CLASSROOM_ENABLED` and
  `DZN_MEET_AUTO_RECONCILE`. Source is one of `constant`, `environment`,
  `application_option` (for the auto-reconcile option) or `default`.
- **Worker** — class presence, hook registration, scheduled occurrences, recurrence, next run and
  `cron_disabled`.
- **Organizer OAuth** — compact configured/connection/identity/credential/expiration state only.
  Tokens, e-mail addresses, client identifiers and provider payloads are never included.
- **Lessons 22 and 23** — targeted, redacted classroom readiness rows.
- **Booking confirmation #9** — targeted, redacted confirmation and classroom readiness row.

### Worker presence versus effective flags

A registered hook and one scheduled event prove that the application registered the worker; they do
**not** prove that WP-Cron is executing it. Check all three:

1. `meet_operational.worker.hook_registered` is true.
2. `meet_operational.worker.scheduled` is true, `occurrences` is exactly `1`, and `recurrence`
   contains `dzn_five_minutes`.
3. `cron_disabled` is false, or the host has an external scheduler running
   `wp cron event run --due-now` (or `wp dzn-meet worker`) every five minutes.

`DZN_MEET_CLASSROOM_ENABLED=false` pauses new provisioning and interactive provisioning, but it does
not remove the worker or stop recording/attendance reconciliation. `DZN_MEET_AUTO_RECONCILE` remains
disabled by default; when false, missing-row discovery is off while existing-row retry and
reconciliation still run. Neither flag should be forced on merely to make the diagnostic look green.

### Verified projection is not co-host or reschedule proof

A verified Calendar/Meet projection and a present join reference prove that the provider event/space
exists. They do **not** prove that:

- the Teacher was accepted as a `COHOST`;
- a later Calendar reschedule job succeeded; or
- a booking confirmation reached `confirmed`.

Inspect the targeted lesson and booking rows separately. The lesson row reports the central
`meet_classrooms` state **and** any verified legacy
`provider_calendar_event_mappings`/`provider_meeting_mappings` projection. `projection_verified` and
`join_ready` are true when either path is verified with a join reference; a missing central
classroom alone is not proof that the lesson has no projection. Lessons 22 and 23 are expected to
have verified projections with join references; do not treat them as missing classrooms or run a
repair because of this diagnostic alone. Booking confirmation #9 must be read from its own row; its
state is never assumed from another confirmation or from the presence of a classroom row.

### Safe remediation for a blocked co-host

A permanent `meet_cohost_assignment_blocked`/`meet_cohost_assignment_refused` is a human-operational
issue. Investigate the Google Workspace/API policy first: external Teacher account eligibility,
same-domain co-host restrictions, OAuth scope, and any `permission_denied` detail. Do not retry it
automatically and never mark a co-host assignment successful without provider confirmation.

After the policy is corrected, use only the audited operator path:

```sh
wp dzn-meet retry-classroom --lesson_id=<id> \
  --reason="Documented tenant-policy correction" --user=<administrator_id>
```

Where an explicit, documented exception is approved, use the audited exception command. It records
the approver, reason, timestamp and exception state:

```sh
wp dzn-meet approve-cohost-exception --lesson_id=<id> \
  --reason="Documented operational exception" --user=<administrator_id>
```

A booking confirmation recovery uses `wp dzn-meet retry-booking --confirmation_id=<id>
--user=<administrator_id>` only after the same underlying policy/exception question is resolved.
It reuses an existing blocked classroom row and its sealed provider references, verifies COHOST
membership again, and leaves the row blocked if Google still refuses. Do not create a shortcut that
silently downgrades a co-host refusal or a second classroom/event/space.

### Never repair from stale diagnostic output

A diagnostic or dry run only describes the records at its generation instant. Re-run the read-only
diagnostic and the approved `repair --dry-run` immediately before any action, review the new
`plan_id`/`plan_digest`, and apply only that exact plan. A changed schedule version, classroom
version, booking version, state or ordering invalidates the old digest.

## Dry-run gate (required before production apply)

Run read-only:

```sh
wp dzn-meet status
wp dzn-meet repair --dry-run --limit=500 --json --user=<administrator_id> > /secure/meet-recovery-dry-run.json
```

Review at minimum:

- `plan_id` and `plan_digest` — the approval identity of this exact record set;
- `limit` — one total operation limit across every category;
- `counts.total_operations`, `counts.administrator_interventions`;
- `counts.missing_lesson_classrooms`, `counts.missing_booking_classrooms`;
- `counts.stale_lesson_classrooms`, `counts.stale_booking_classrooms`;
- `counts.teacher_mismatch_classrooms`, `counts.unconfirmed_booking_projections`;
- `counts.failed_booking_projections`, `counts.blocked_or_failed_classrooms`;
- every operation's `current_state`, `expected_state`, `mode`, intent and UTC start.

Preview and apply use the same deterministic planner, eligibility rules, ordering and total limit.
Operations are ordered by imminent UTC start, so classes in the next 24 hours are naturally first.
Dry-run performs no Google call and no database mutation. `failed_booking_projections` and blocked
co-host/classroom entries are marked `requires_admin` interventions and are never auto-executed.

No production-wide apply is authorised by this document. The report contains no e-mail address,
Meet URL, provider object reference or credential.

## Booking and missing-lesson recovery

A terminal booking is recovered without a second Teacher acceptance. `retry-booking` resets only the
confirmation (and its accepting delivery) to retryable, then runs the same provider path. When a
previous blocked classroom exists, recovery **reuses that exact classroom row, sealed Calendar/Meet
reference and provider space**; it does not create a second classroom row, Calendar event or Meet
space. The row becomes `provisioning` only while the provider verifies COHOST membership, and remains
blocked if Google still refuses. Cancelled/retired history and bookings whose occurrence has ended
are never revived.

Lessons 22/23 are missing-row discovery, not existing-row retry. They must be planned explicitly:

```sh
wp dzn-meet repair --dry-run --limit=500 --json --user=<administrator_id>
# Review plan_id, plan_digest and missing_lesson_classrooms. Confirm lessons 22 and 23 are present.
wp dzn-meet repair --limit=500 --plan=<reviewed_plan_digest> --user=<administrator_id>
```

Missing-row discovery remains disabled in the scheduled worker while
`DZN_MEET_AUTO_RECONCILE` is false. Do not enable it merely to recover these two rows.

## Apply sequence after approval

```sh
# 1. Re-run the exact dry run immediately before apply. The digest must be captured.
wp dzn-meet repair --dry-run --limit=500 --json --user=<administrator_id> > /secure/meet-recovery-dry-run-2.json
# Approve plan_digest from that reviewed file.

# 2. Apply only the approved batch. A changed record or limit changes the digest and is refused.
PLAN_DIGEST="$(php -r '$j=json_decode(file_get_contents("/secure/meet-recovery-dry-run-2.json"),true); echo $j["plan_digest"];')"
wp dzn-meet repair --limit=500 --plan="$PLAN_DIGEST" --user=<administrator_id>

# 3. Generate a new dry run for the next batch; never reuse an older digest.
wp dzn-meet repair --dry-run --limit=500 --json --user=<administrator_id> > /secure/meet-recovery-dry-run-3.json

# 4. Retry a reviewed terminal booking without asking the Teacher to accept again.
wp dzn-meet retry-booking --confirmation_id=<id> --user=<administrator_id>

# 5. Optional: preview the scheduled worker (read-only). Do not enable automatic discovery as part
#    of this recovery; it requires a separate approved operational decision.
wp dzn-meet worker --dry-run --limit=25 --user=<administrator_id>
```

Do **not** run `wp option update dzn_meet_auto_reconcile 1` as part of the booking/lesson recovery.
The scheduled worker continues existing-row retries and recording/attendance reconciliation, while
missing-row discovery remains opt-in.

A dry run and apply with the same records, limit and discovery mode produce the same
`plan_digest`. Any changed schedule version, classroom version, booking version, state or ordering
causes `meet_reconciliation_plan_stale`; no operation is applied.

Manual `worker --discover` is preview-only and cannot apply. Missing-row discovery must use the
reviewed `repair --plan=<digest>` path. Manual worker apply is limited to existing-row recovery and
also requires a matching plan digest. Every mutating manual command requires
`--user=<administrator_id>`.

Do not auto-retry blocked co-hosts. Correct the Google Workspace tenant policy first, then:

```sh
wp dzn-meet retry-classroom \
  --lesson_id=<id> \
  --reason="Documented tenant-policy correction" \
  --user=<administrator_id>
```

`retry-classroom` locks the lesson/schedule, rejects archived/cancelled/completed/ended, superseded
or stale-schedule lessons, never reactivates a cancelled/retired historical row, resets an active row
with a version compare-and-swap, and audits the administrator reason. If no active row exists,
recovery creates a new idempotent projection row rather than reviving history. Reschedule a stale
schedule through the reviewed reconciliation plan first.

## Configuration

| Setting | Purpose | Default |
| --- | --- | --- |
| `DZN_MEET_ORGANIZER_EMAIL` | Organizer account | `classroom@delnavazan.com` |
| `DZN_MEET_CLASSROOM_ENABLED` | Emergency stop for interactive and background provisioning; recording/attendance reconciliation continues | enabled; source reported as constant/environment/default |
| `DZN_MEET_AUTO_RECONCILE` | Scheduled missing-row discovery | disabled; source reported as constant/environment/application option/default |
| `DZN_MEET_ACCESS_TYPE` | Meet space access | `OPEN` |

The worker is registered on normal plugin load and schedules one
`dzn_google_meet_classroom_worker` event on the `dzn_five_minutes` recurrence. Deactivation and
uninstall clear only that scheduled hook; no business records are touched. WP-Cron must actually run
every five minutes. If the host disables loopback WP-Cron (`DISABLE_WP_CRON`), run
`wp cron event run --due-now` or `wp dzn-meet worker` from the host scheduler with the same cadence.
This is a hosting prerequisite, not something an application request can silently fix.

## Post-deployment smoke tests

Use test lessons/bookings only. Do not use real Student recordings or private data.

1. `wp dzn-meet status` shows the organizer connected, exact scopes, and worker scheduled.
2. `wp dzn-meet repair --dry-run --json --user=<administrator_id>` returns the reviewed record set; no Google API calls occur.
3. Provision one existing test Lesson. Confirm exactly one organizer Calendar event, one Meet URI,
   teacher `COHOST` verified, student invitee, and `classroom_state=ready`.
4. Re-run the approved dry run and a matching `repair --plan`; confirm no duplicate
   event/space/classroom/notification.
5. Accept one free test introductory booking. Confirm the portal reports either confirmed or retryable
   (never a lost acceptance). Trigger a controlled transient provider failure if available and verify
   the worker confirms it after recovery.
6. Verify teacher and student entry pages open inside 30 minutes before start / 30 minutes after end,
   and refuse outside the window.
7. Reschedule a test booking; confirm the same Calendar event and Meet space are retained.
8. Cancel a test booking; confirm the organizer event is cancelled by the retraction worker and the
   classroom/recording rows are preserved.
9. Replace a test Teacher; confirm the new teacher is `COHOST`, Calendar attendees update, and the old
   co-host is removed.
10. Force/observe a co-host refusal in a non-production tenant if possible; confirm `blocked`, an
    administrator alert, no automatic moderation downgrade and no legacy-link bypass.
11. Confirm recording remains `OFF` without current consent and no Drive file becomes public.
12. Check `meet_classroom_events` has the exact HTTP status/error class and no credential material.

## Rollback (least destructive first)

### 1. Pause automatic recovery only — default response to one failed batch

```sh
wp option update dzn_meet_auto_reconcile 0
```

This preserves the connected organizer, current lessons, all existing classroom references and Join
Class links. It stops missing-row discovery and scheduled retries. Manual reviewed
`repair --plan=<digest>` remains available. Do not disconnect the organizer for one failed batch.

### 2. Pause new provisioning — only when the provider path must stop creating anything

```sh
# Add to protected production configuration, then reload PHP.
DZN_MEET_CLASSROOM_ENABLED=false
```

Before using it, verify the effect on Join Class:

- existing `ready`/`rescheduled` central classrooms remain resolvable through the join services;
- the five-minute worker and central booking projection worker stop;
- accepted bookings remain durably retryable and are not converted into Teacher-owned Calendar events;
- no new organizer Calendar event or Meet space is created.

This is not an organizer disconnect. Do not describe it as a rollback of existing classrooms.

### 3. Full organizer rollback — explicit emergency approval only

```sh
wp dzn-meet rollback --user=<administrator_id>
```

This disconnects the organizer locally and is not the default response to a failed reconciliation
batch. It prevents new organizer provisioning and worker recovery. Existing rows, consent evidence,
Calendar identifiers, vaulted links, recordings and historical evidence are preserved.

### 4. Data and recording safety

- Never drop `meet_*` tables.
- Never delete Drive recordings or recording-access audit rows.
- Never rewrite completed lesson history or consent decisions.
- Re-deploy the archived LiveKit tag only as a separate, explicitly approved change window.

## Evidence boundary

Unit/source contracts prove deterministic rules, SQL candidate selection, idempotency guards,
classification, portal copy and fallback prevention. They do not prove Google tenant behaviour,
co-host rights, Calendar invitation delivery, recording privilege, or real Iran/mobile connectivity.
Those require the live acceptance matrix in `GOOGLE-MEET-CLASSROOM-MIGRATION.md` §8.
