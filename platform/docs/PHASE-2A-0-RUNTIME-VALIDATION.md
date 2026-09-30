# Phase 2A.0 — Isolated Runtime Validation

This is the controlled validation procedure for the approved Phase 2A.0 PR
head. It is not deployment authority. It does not authorize a beta upload,
production activation, a provider send, an Amelia change, a real Teacher or
Student mutation, or a real WordPress account.

## Safety boundary

Run the complete matrix only in a disposable, non-production WordPress +
MySQL/MariaDB installation with the plugin ZIP under review. Use a dedicated
database and a controlled administrator. The fixture label is
`DZN 2A0 RUNTIME TEST — DO NOT USE`; each email must use a generated
`@example.invalid` address. No beta data or accounts may be used.

The ZIP contains only `delnavazan-platform.php`, `uninstall.php`, and `src/`.
It deliberately excludes this runbook and every file under `tests/`. The
isolated helper is a WP-CLI-only assertion, requires an explicit environment
acknowledgement plus a matching database marker, blocks `production`, and never
prints a recipient or raw invitation secret.

Before any test, capture the empty database/site identity, record the rollback
method (drop and recreate the disposable database), and confirm Amelia remains
installed, active, unmodified, and unused. Never use a provider transport:
delivery preparation ends at the in-memory payload boundary.

## Isolated fixtures and marker

Create one controlled active Core Teacher through the existing protected
Teacher screen or service. Record its ID. Create only two disposable WordPress
users when the cases below require them: a controlled administrator and a
controlled existing-principal user whose email matches the frozen recipient.
No Core principal link exists before finalisation.

Set a unique run identifier such as `DZN2A0-20260905-A`, then write exactly
that value as the disposable-site option
`dzn_phase_2a0_isolated_runtime_marker`. The helper refuses to run unless the
environment variable `DZN_PHASE_2A0_RUNTIME_TEST=isolated`, the run identifier,
the marker option, a controlled administrator, and a generation ID all agree.

## Migration and package matrix

| ID | Procedure | Pass condition |
| --- | --- | --- |
| M01 | Inspect the ZIP before install. | Only the three approved runtime paths are present; no docs, tests, Git metadata, local files, archive, credentials, or Amelia files are packaged. |
| M02 | Activate on a brand-new disposable database. | No fatal/warning; schema version is `3`; completed migrations include `001_initial_core_schema`, `002_principal_invitation_foundation`, and `003_invitation_recipient_snapshot`; all Core and 2A.0 tables exist. |
| M03 | Re-run activation/upgrade without changing the database. | No duplicate tables, rows, indexes, or migration records; schema version remains `3`. |
| M04 | Restore a separately captured disposable schema-2 snapshot made from the pre-snapshot Phase 2A.0 package, then activate this ZIP. | `003_invitation_recipient_snapshot` is added once; `recipient_snapshot`, `recipient_digest`, `secret_digest`, `delivery_prepared_at`, and `delivery_attempt_count` have the verified shape; re-run is safe. This is the 002 → 003 path. |
| M05 | In a throwaway copy only, make a required verification fact unavailable before upgrade. | Upgrade reports an observable failure and does not record the failed migration or advance schema version. Restore/drop the database afterwards. |
| M06 | Run `tests/phase-2a0-isolated-delivery-assertion.php` through WP-CLI after issuing a queued synthetic generation. | It verifies schema/migration records, critical indexes and recipient/secret column types, prepares the synthetic generation, and emits no recipient or raw secret. |

The assertion helper is intentionally not a test endpoint. Invoke it only with
the explicit isolated environment variables described above and a WP-CLI
`--user` for the controlled administrator. Do not redirect its output to a
shared log. It performs no user provisioning and creates no data itself.

## Invitation, claim, and authority matrix

Use a fresh synthetic Teacher/invitation generation for each terminal case.
Record only numeric IDs, generation status, timestamps, and safe command keys
in evidence; do not record recipient snapshots, secrets, secret digests, or
delivery keys in tickets or screenshots.

| ID | Procedure | Pass condition |
| --- | --- | --- |
| I01 | Issue an invitation from the protected onboarding screen. | Exactly one queued generation and one pending delivery intent; issuance returns no secret; the onboarding view shows state only. |
| I02 | Reissue with a changed synthetic recipient before delivery preparation. | A new generation has the new frozen recipient snapshot; the old unclaimed generation is superseded and its pending intent cancelled. |
| I03 | Revoke a queued and an active unclaimed synthetic invitation. | Each becomes revoked, related pending/prepared intent is cancelled, and claim rejects it. |
| I04 | Let a very short synthetic TTL expire before claim. | Claim rejects the expired generation without creating a Teacher link. |
| I05 | Prepare delivery using the isolated helper. | Only a keyed digest is stored; the secret exists only in the helper process payload and is immediately unset. The helper proves the raw invitation secret is absent from the bounded invitation, audit, outbox, and claim persistence surfaces. |
| I06 | Inspect onboarding rendering, audit/outbox records, and the cleared host log after I05. | No secret, recipient snapshot, or delivery key is rendered or logged. The source contract is supplementary evidence, not a replacement for this runtime observation. |
| I07 | Begin/finalize an existing-account claim while signed in to the matching controlled existing authenticated WordPress account. | Finalisation creates one active Teacher principal link and onboarding state; matching email without that authenticated account is rejected. |
| I08 | Begin new-account provisioning, then simulate an uncertain external provisioning result. | Attempt becomes `recovery_required`; no Teacher principal link or Core authority exists. Repeating the command returns/reconciles the durable attempt rather than silently creating another user. |
| I09 | Attempt two claims for the same active generation. | Only one claim attempt can own the generation; the competing/double claim is rejected and no second Teacher link is created. |
| I10 | Try stale/superseded/revoked/expired secrets after reissue, revoke, or expiry. | Every attempt rejects and does not finalise a link. |
| I11 | Create a controlled Student-domain link for the same disposable WordPress user, then offboard the Teacher. | Teacher link is revoked and onboarding offboarded; the separate Student-domain link is untouched. |
| I12 | Try onboarding POSTs as low privilege and with an invalid/missing nonce. | Both reject; no invitation, audit, outbox, onboarding, or principal-link row changes. |
| I13 | Attempt terminal recipient anonymization while a queued or active generation remains. Then terminalize every generation and repeat. | Live generation rejects with no mutation; terminal-only operation clears generation snapshot/digest and replaces the parent digest with its non-recipient tombstone. |
| I14 | Induce a controlled persistence failure inside an invitation transaction (for example, a duplicate command key in a disposable database). | The operation rolls back atomically: no partial invitation/generation/outbox/audit state remains. |

## Evidence and cleanup

For every row, mark PASS, FAIL, or BLOCKED. A source check is never runtime
evidence. Preserve only safe identifiers, SQL row counts, migration/index
facts, screen captures with PII redacted, and error messages without tokens.

Cleanup is deliberately simple and reversible: revoke any remaining synthetic
generation, verify there is no active Teacher principal link, remove the two
controlled WordPress users and all `DZN 2A0 RUNTIME TEST — DO NOT USE` records
from the disposable database, remove the marker option, deactivate the plugin
if desired, then drop the entire disposable database/files. Do not run this
procedure against beta or production. No real data, accounts, media, Amelia
settings, or unrelated plugins are touched.

## Runtime blockers

Local source inspection cannot execute WordPress, `dbDelta()`, MySQL
transactions/locks, WP capability/nonce behavior, actual account provisioning,
or browser/log rendering. Those facts remain BLOCKED pending the isolated
WordPress + MySQL runtime described here. Provider delivery is deliberately out
of scope: the test ends before any send.

### Ready-Teacher fixture for later isolated gates

`tests/phase-2a0-isolated-ready-teacher-fixture.php` is a WP-CLI-only,
non-production fixture for later Platform runtime validation. With the same
isolated marker and a `local` or `development` WordPress environment, it runs
the real Core sequence: synthetic Teacher creation, invitation issue, internal
delivery preparation, synthetic matching WordPress principal creation,
existing-account claim, and `finalizeClaim()`. It keeps the invitation secret
only in memory, asserts the active Teacher principal link and `active`/`ready`
onboarding result, and uses normal offboarding during cleanup. It is excluded
from deployable plugin packages and is not a public or administrator claim
completion route.
