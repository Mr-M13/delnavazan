# Replacement reliability and production verification

## Scope

Follow-up to the merged missed-lesson replacement lifecycle (PR #399). No replacement business
logic was redesigned. This release closes three operational gaps:

1. intermittent production smoke `403` handling;
2. staging end-to-end coverage of the replacement lifecycle, including both portal projections;
3. controlled Google Calendar/Meet retry and idempotency verification, live production
   projection recovery and worker diagnostics.

## Smoke `403` investigation

The failed deploy smoke runs returned `403` for every dynamic page while static theme assets
returned `200`. The same release passed smoke before and after from different GitHub runners. A
fresh read-only probe from a GitHub runner (`135.233.117.225`, Cloudflare colo `MSP`) returned
`200` for all dynamic routes with both `curl` and a browser-like User-Agent. No PHP error, origin
5xx or application failure was present.

The failure is therefore consistent with a transient Cloudflare/datacenter-edge probe response, not
an application failure. The exact failed response headers were not preserved by the old smoke
script, so the fix is deliberately conservative:

- browser-like `User-Agent`, `Accept` and `Accept-Language` headers on every probe;
- bounded retries only for transport timeouts/connection failures, `429`, `503`, `504`, Cloudflare
  `52x`, and `403` **only** when Cloudflare explicitly marks `challenge`/`managed_challenge` and
  returns a challenge body;
- plain `403`, `401`, `404`, `500`, `502` and application errors fail immediately;
- every attempt logs status, classification, elapsed time and safe edge headers;
- a failed primary probe gets one independent `--confirm` probe;
- maintenance is enabled only when both probes fail, or when the decision is unavailable;
- a passing confirmation leaves production live and records a warning.

The previous workflow enabled maintenance on the first failed smoke. That was safe but could take a
healthy site offline for a transient edge response. The new decision is fail-closed on genuine
failure and avoids an unnecessary maintenance window on a confirmed-healthy transient probe.

## Staging acceptance

The disposable attendance runtime now proves the full synthetic lifecycle through both authenticated
portal read models:

1. presumed-completed original occurrence;
2. Student missed-class claim;
3. Teacher authenticated proposal (replacement kind);
4. Student portal pending proposal with original/proposed times and replacement label;
5. Student authenticated acceptance;
6. linked replacement Lesson and schedule;
7. original Lesson, schedule version and attendance evidence unchanged;
8. replacement allowance consumed exactly once;
9. Student and Teacher portal schedules contain the replacement;
10. notification rows for proposal and acceptance;
11. calendar hand-off targets the replacement;
12. duplicate acceptance is idempotent and a competing decision is refused;
13. the replacement can itself be rescheduled; the original remains unchanged and a new calendar
    hand-off is queued from the old replacement version to the new one.

Unit coverage also proves rejection, second proposal, three-rejection escalation, expired proposal,
exhausted allowance, stale schedule version, delivered/disputed outcomes, duplicate submission and
unauthorised actors.

## Google Calendar and Meet

Controlled adapter tests now cover:

- successful event creation and Meet extraction;
- HTTP `409` convergence by reading the deterministic event instead of creating a second one;
- asynchronous Meet creation (`conference_pending`);
- transport timeout;
- permanent provider refusal;
- untrusted Meet host;
- provider identity mismatch;
- invalid deterministic event id;
- worker crash after provider creation and retry with one provider event;
- stale queue snapshot / already-completed job not dispatched twice;
- replacement reschedule calendar hand-off.

Deterministic provider refusals are now terminal (`failed`) instead of consuming six retries;
transport/outage failures keep bounded backoff. The canonical replacement is never rolled back by a
provider failure.

### Live provider verification

Production had one failed reschedule calendar job (job 1, lesson 16, target version 14) and a
second pending projection (lesson 5). Read-only diagnostics exposed six real defects in the
hand-off path:

1. `ProviderIntegrationService` was constructed without its OAuth/calendar/meeting adapter ports;
2. `defaultDispatch` omitted the required `projection_reference`;
3. `dispatchJob` nested a transaction around the provider authority, so MySQL refused
   `SET TRANSACTION ISOLATION LEVEL READ COMMITTED`;
4. `GoogleCalendarProjectionService` required the management capability although the dedicated
   projection-worker principal holds only the dispatch capability;
5. an expired active Google credential was never refreshed;
6. the hand-off tried to project a separate `google_meet` mapping, which requires a second OAuth
   connection that the Google flow never creates.

After the fixes were deployed and the least-privilege projection-worker principal was provisioned,
the worker refreshed the expired credential through the existing encrypted refresh token and
dispatched the pending projections. Evidence:

- pending projections before: `2`; after: `0`;
- verified calendar mappings: `2`;
- verified join-reference count: `2`;
- lesson 16/version 14 and lesson 5/version 1: `projection_state=verified`, connection `connected`,
  identity `verified`, active credential not expired, event reference present, join reference
  present, `portal_join_ready=true`;
- failed calendar jobs: `0`; stale calendar jobs: `0`; the original failed job is `completed`.

This is **real Google Calendar/Meet provider verification** for existing real reschedule
occurrences. No synthetic production lesson change was made. No live replacement-specific event
exists because production has no real replacement lesson; the replacement hand-off is verified in
the disposable runtime with a controlled connected Teacher connection and by the shared Google
transport/worker tests.

Remaining limitation: a live replacement-specific event/Meet link requires the first genuine
accepted replacement or a dedicated staging Google account. Do not create a synthetic replacement
in production to satisfy that test.

### Operational recovery commands

- Failed hand-off: `recover-production-calendar-job.yml` with the exact main SHA, the failed
  `job_id` and `RECOVER`.
- Pending projections: `dispatch-production-calendar-projections.yml` with the exact main SHA and
  `DISPATCH`.
- Worker principal: `provision-production-projection-worker.yml` with
  `PROVISION_PROJECTION`.
- Read-only state: `diagnose-production-release.yml` and
  `diagnose-production-google-oauth.yml`.

## Production verification

- Deployed schema: 54 (no migration).
- Public smoke: `primary_smoke=success`, confirmation not required, maintenance `not_required`.
- Replacement actor: configured, active, canonical Lesson + schedule capabilities, no admin/provider
  capability.
- Reschedule actor: configured, active, scheduling capability only.
- Calendar actor: configured, active, provider-integration capability only.
- Projection worker: configured, active, dispatch capability only.
- Google OAuth: configured, connection connected, identity verified, provider reachable.
- Calendar jobs/projections: no failed or stale jobs; verified projections and join references
  present.

## Production diagnostics

The release diagnostic now reports:

- replacement, reschedule and calendar actor capabilities;
- replacement proposal/Lesson counts;
- calendar-job states, failed error codes and stale jobs;
- notification states;
- the `dzn_lesson_reschedule_dispatch` and `dzn_google_calendar_projection_dispatch` cron hooks.

It remains read-only and prints no IDs or secrets.

## Rollback

No schema change. Code-only rollback is safe. The canonical replacement, proposal, notification and
calendar-job rows remain; existing accepted replacements stay valid because the accepted proposal is
their provenance authority. Do not delete canonical Lessons, schedules, attendance evidence or
audit rows. If a smoke failure is confirmed by both probes, production remains in maintenance until a
healthy deployment or a reviewed manual recovery.
