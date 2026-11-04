# Sequential introductory booking allocation — release notes

## Architecture and gaps addressed

The public submission transaction persists the booking request and requested times, then chooses one eligible Teacher. The per-course cursor serializes the initial round-robin choice. The existing delivery row remains the confirmation anchor; `booking_allocation_offers` records each individual offer and its 24-hour deadline. A decline advances in the response transaction. The bounded two-minute worker advances expired offers and raises a high-priority alert after 72 hours measured from the original request timestamp. Exhaustion and no viable Teacher open the canonical coordination case. Acceptance revalidates the exact time and retires the offer before calendar projection.

Earlier code sent one delivery to a Teacher but had no durable offer history, deadline, rotation, or automatic escalation. The accepting-state default was fixed separately in PR #495. This change retains that fix and adds the offer authority. The student receipt remains in the existing booking notification queue; the Teacher offer uses a separate per-offer outbox and portal notification. A terminal calendar projection failure opens a coordination referral for a human to resolve.

## Migration and rollback

Schema 58 adds four InnoDB tables through migration `069_booking_allocation`: offers, course cursors, alerts, and notification obligations. The migration is additive and verifies the uniqueness indexes for one active offer and one offer per Teacher per request. It does not rewrite accepting-state rows, existing confirmations, or lessons. Back up the database and deploy the matching code and migration together; verify the schema option and migration ledger after activation.

If release validation fails, stop the allocation and notification workers, revert the code to the prior release, and retain the new tables for investigation. Do not drop them or reset the schema marker while any request could have an offer: doing so would discard offer history and permit duplicate contact. Restore a database backup only after reconciling requests and any emails or provider actions that occurred after the backup. Historical recovery is a separate, explicit administrator action, never an automatic migration side effect.

## Production recovery preflight

Before any mutation, inspect the exact reference `REQ-01M6DRJPEHA733V2GFMG20RY1K` for lifecycle, delivery, confirmation, original timestamp, and requested times. Then use the administrator recovery preview to re-evaluate current eligibility and time viability. Record its proposed action. A pending legacy delivery is reconciled once; an expired one advances immediately. A confirmed or erased request is preserved. If times are no longer viable, refer to coordination. Apply recovery only after the read-only report is reviewed; re-run the preview afterward to establish idempotence.

On 2026-11-02, the connected production site reported schema 57 and **zero rows for that exact reference** in `dzn_booking_requests`, deliveries, and confirmations. A broader reference listing was rejected by automatic approval review because it would reveal unrelated requests. Therefore the identified request cannot yet be recovered or characterized from production evidence. Do not infer its state from the absence of a row. Resolve the reference discrepancy through a narrowly authorized identifier or approved inspection before any recovery mutation.

The production `wp cron test` reported a successful HTTP spawn, and WP-Cron is not disabled. This proves the site can start due events when triggered, but it does not establish an independent low-traffic schedule. A hosting-level timer or monitored external trigger remains a release prerequisite for the 24-hour deadlines.

## Release verification

Run the PR source contracts, runtime migration jobs, booking allocation unit tests, and portal acceptance checks against the release candidate. Confirm a dependable server-side cron trigger for the two-minute hooks on low-traffic days; a scheduled WP-Cron event alone does not guarantee wall-clock execution. Verify offer mail and portal delivery, failure retry, administrator queue, privacy erasure, and the exact production recovery preview before merging or deploying.
