# Replacement reliability and production verification

## Scope

Follow-up to the merged missed-lesson replacement lifecycle (PR #399). No replacement business
logic was redesigned. This release closes three operational gaps:

1. intermittent production smoke `403` handling;
2. staging end-to-end coverage of the replacement lifecycle, including both portal projections;
3. controlled Google Calendar/Meet retry and idempotency verification, plus production worker
   diagnostics.

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

**Live provider limitation:** no real staging Google account/calendar was available for this run, and
production synthetic lesson changes are prohibited. The production Google OAuth connection and
calendar actor are verified read-only; the final live event/Meet write remains to be observed on the
first genuine accepted replacement or a dedicated staging Google account.

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
