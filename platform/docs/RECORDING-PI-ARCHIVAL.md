# Delnavazan Lesson Recording and Pi Archival

**Branch:** `feat/recording-mbox-archival-20261103`
**Base:** PR #512 `feat/consent-lifecycle-complete` at `3f738464b491205159ed56111c996bb1733432e0`
**State:** staging implementation and synthetic tests only. Nothing merged to `main`, released to
production, activated for real lessons, ingested into ML, purchased, decommissioned or deleted.

## 1. Preserved architecture

- WordPress remains the canonical authority for lesson, schedule version, teacher, student,
  guardian, consent, policy, portal and administration.
- LiveKit participant tokens keep `roomRecord=false`; browsers and WordPress never hold Egress
  authority.
- `RecordingAuthorisationGate::assertCaptureAuthorised()` is mandatory before a recording request.
- Google Meet recording remains refused (`recording_provider_not_consent_enforced`).
- Media never enters WordPress uploads.
- No long-running capture, hashing, transfer or deletion runs inside a web request.
- Existing consent, guardian, withdrawal, research-separation and administrative structures are
  extended, not replaced.

## 2. WordPress data model (migration `067_recording_archive_authority`)

| Table | Purpose |
| --- | --- |
| `dzn_recordings` | Recording UID, canonical lesson/schedule snapshot, participants, authorisation evidence/digest, policy/notice references, provider Egress ID, content version, lifecycle, media metadata, source bytes/SHA-256, authoritative location, legal hold, disposition, optimistic version. |
| `dzn_recording_events` | Append-only accepted/refused/delayed/duplicate/conflict evidence with idempotency key and payload digest. |
| `dzn_recording_commands` | Durable start/stop/reconcile/transfer/transfer-retry/archive-verification/source-deletion/withdrawal outbox with leases, attempts, bounded backoff, version and safe failure class. |
| `dzn_recording_archive_confirmations` | Exact recording/version, source/destination size and SHA-256, archive node, object key, durable commit receipt and signature. |
| `dzn_recording_playback_access` | Append-only allow/refuse/start/complete/abort audit; only a keyed jti digest, never a raw token. |

The migration is additive, creates no decision or recording row, backfills no consent and is
verified by table/engine/column/index/digest/shape checks. It follows `066_consent_lifecycle_authority`
in the ledger; the package schema identity remains `57` as in PR #512.

Capabilities are administrator-only: `dzn_manage_recording_operations`,
`dzn_view_recording_operations`, `dzn_retry_recording_commands`, `dzn_reconcile_recordings`,
`dzn_manage_recording_legal_hold`. Teacher/Student roles receive none.

## 3. Lifecycle and idempotency

States: `authorised`, `recording_requested`, `recording_active`, `recording_finalising`,
`recording_verified`, `awaiting_archive`, `transferring`, `transfer_retry_pending`,
`archive_verification`, `archived`, `vps_deletion_pending`, `archived_vps_deleted`, `failed`,
`quarantined`, `deletion_required`.

Every accepted/refused event requires expected state and optimistic version. Exact replay is
idempotent; the same idempotency key with a different payload digest is refused and recorded.
Provider webhooks cannot skip required states. Out-of-order events are appended as durable evidence
and trigger reconciliation rather than a state jump. A reschedule/teacher replacement before capture
invalidates the recording; once capture starts, the immutable schedule/participant snapshot is never
re-bound.

### WordPress-side secrets (never in uploads or the web root)

Define in the protected `wp-config.php`/environment: `DZN_RECORDING_SERVICE_SECRET`,
`DZN_RECORDING_SERVICE_KEY_ID`, `DZN_RECORDING_PLAYBACK_SECRET`, `DZN_RECORDING_PLAYBACK_KEY_ID`,
`DZN_RECORDING_PLAYBACK_GATEWAY_URL`, `DZN_MBOX_CONFIRMATION_SECRET`,
`DZN_MBOX_CONFIRMATION_KEY_ID`. The archive confirmation secret is a third key, distinct from the
recorder callback key and the playback key. Rotating the playback key invalidates all outstanding
grants without touching archive media.

## 4. Feature flags

- `RecordingDecisionAuthorityService::CAPTURE_ENABLED=false` remains a hard build kill-switch.
- `RecordingDecisionAuthorityService::RESEARCH_EXPORT_ENABLED=false` remains unchanged.
- Staging capture additionally requires `DZN_RECORDING_CAPTURE_STAGING_ENABLED=true`, a
  `local`/`development`/`staging` environment, and an explicit `dzn_recording_staging_cohort`
  lesson/`all-staging` cohort.
- Production capture requires the explicit `DZN_RECORDING_CAPTURE_APPROVED=true` constant, the
  `production` environment, and a lesson cohort in `dzn_recording_staging_cohort` (explicit lesson
  IDs or `all-approved`). The build kill-switch remains false and is never changed by configuration.
  Setting `DZN_RECORDING_EMERGENCY_STOP=true` or engaging the admin emergency stop overrides all of
  the above.
- Recording consent never satisfies ML/research eligibility; `MlDatasetEligibilityService` remains a
  separate gate and no recording/playback code writes `consent_dataset_items`.

## 5. Capture and verification

The recorder service owns the LiveKit Egress credential and starts Room Composite Egress with MP4,
H.264/AAC, `dzn-two-person`, both participant regions, a recording-native watermark and a custom
layout template. Classroom audio publication options are untouched, so music-mode stereo behaviour
is not changed by recording.

A recording is verified only after Egress completion, closed file (exclusive lock), MP4 parse, one
video + one audio stream, H.264/AAC compatibility, plausible duration, stable size and SHA-256 of the
final file. Media evidence is validated again in WordPress before `recording_verified`.

Synthetic measurement (`services/recorder-archiver/tests/integration.php`) reports:

- MP4: one video (`h264`/`avc1`) and one audio (`aac`/`mp4a`) track, 3000 ms, 1280×720, A/V marker.
- Music fixture: stereo, 48 kHz, 16-bit, 1536 kbps, non-mono difference, 8 transients,
  peak −0.18 dBFS, zero clipped samples, 2.000 s.
- This is synthetic evidence, not real Egress output. The real two-person Egress test remains a
  staging blocker.

## 6. OVH recorder/archiver

Dedicated SQLite-WAL or PostgreSQL schema with `recording_jobs`, `recording_assets`,
`provider_events`, `transfer_jobs`, `transfer_attempts`, `archive_confirmations`, `deletion_jobs`,
`service_inbox`, `service_outbox`.

`services/recorder-archiver/src/Database.php` supplies both DDLs. Tests exercise SQLite WAL only
because no PostgreSQL image is available in this environment; PostgreSQL is the production default
in `recorder.env.example`. Concurrency and restart recovery are exercised by real parallel processes
against SQLite WAL with `busy_timeout` and `BEGIN IMMEDIATE`; PostgreSQL deployment must rerun the
same suite before activation.

Queues use database-time leases, hashed lease tokens, CAS versions, unique idempotency keys,
payload-digest conflict refusal, bounded exponential backoff and safe lease takeover. `/srv/delnavazan-recordings`
uses `tmp`/`ready` on the same filesystem, directories `0700`, files `0600`, atomic rename, fsync and
a free-byte/percentage gate.

## 7. Pi transfer and deletion

- Dedicated identities, no public archive exposure; ACL example in
  `services/infra/tailscale-acl.example.hujson`. The current staging path uses a Pi-initiated
  reverse SSH tunnel to OVH loopback with restricted forced commands and read-only SSHFS; Tailscale
  remains the preferred production transport once an OVH auth key is available.
- Restricted `delnavazan-archive` account and `/mnt/mbox/DelnavazanRecordings`; mount identity is
  verified before every write. No root-filesystem fallback.
- Restricted rsync over SSH to a recording-specific temporary filename, `--append-verify` resume,
  remote size/SHA-256 verification, file and directory sync, atomic rename.
- Signed confirmation persisted on OVH then sent to WordPress; only then is VPS deletion queued.
- Existing same content is idempotent; different content is quarantined and never overwritten.
- Offline Pi preserves the complete VPS copy, queue entry, bounded retry and alert.
- Deletion derives the path from the database, checks archive confirmation/destination digest,
  legal hold, traversal/symlink, device/inode/size/digest, opens the trusted directory, unlinks the
  basename, flushes the directory and appends a receipt. Request paths are ignored.
- Queue claims return the post-claim CAS version, so completion and retry updates cannot silently
  fail after a claim. A crash between `unlink()` and the bookkeeping update reconciles only when
  durable evidence proves the delete already happened (`delete_archive`, WordPress state
  `archived_vps_deleted`, or an existing `source_deleted` outbox event); otherwise the row fails
  closed and the job remains retryable.

## 8. Playback

WordPress checks the signed-in canonical principal, lesson ownership, teacher/student/guardian
restriction, current withdrawal policy, archive state and Pi availability. It issues a keyed
≤300-second grant bound to one recording/content version, gateway audience, principal, key id,
not-before/expiry/issuer and jti, and appends allow/refuse audit.

The OVH gateway validates the grant independently, exchanges it for an opaque short-lived stream
ticket, and streams from the private Pi mount with `HEAD` and byte ranges. It enforces per-user
and global concurrency, optional bandwidth limits and allow/refuse/start/complete/abort logging.
Pi offline returns `503 recording_temporarily_unavailable`; it never restores a deleted VPS copy.

## 9. Administration, monitoring and emergency stop

Settings → Recordings (inside the existing consolidated shell) shows currently recording, finalising,
awaiting transfer, transferring, retry pending, archive verification, archived, VPS deletion pending,
unavailable, quarantined, consent block (24 h) and storage warning. Retry/reconcile/legal-hold are
capability-gated. There is no consent override and no filesystem-path field.

Health covers VPS/Pi storage, mount identity, secure transport, oldest queue age, repeated failures,
Egress CPU/RAM, completion failures, checksum mismatches, deletion failures and playback saturation.
The recorder and playback gateway expose Prometheus text metrics:

- Recorder (template/health router, loopback `:8091`): `/metrics` with readiness, storage, archive
  availability, emergency-stop state, oldest queue age and per-state queue depth.
- Playback gateway (loopback `:8092`): `/metrics` with archive mount state, active sessions and
  allow/refuse/unavailable counters for the last hour.

**Emergency stop.** Settings → Recordings has a capability-gated red button. Engaging it writes a
durable `dzn_recording_emergency_stop` option, refuses every new capture request and queues a `stop`
command for every `recording_requested`/`recording_active` recording. Releasing it only clears the
kill switch; the normal consent/authority/readiness gates still apply. A second, host-local hard stop
is available through `DZN_RECORDER_EMERGENCY_STOP_FILE` (default
`/run/delnavazan-recorder/emergency-stop`): when present, the recorder refuses every new start even
if WordPress is unreachable, and reports `emergency_stop_active=1` in health and metrics.

## 10. Tests and evidence

Run:

```bash
bash services/tests/run-all.sh
php platform/tests/recording-archive-policy-unit.php
php platform/tests/recording-archive-contract.php
bash platform/runtime/bin/run-recording-archive-runtime.sh
bash platform/runtime/bin/run-pure-tests.sh
```

Service tests cover successful capture/finalisation, synthetic media tracks, online transfer,
matching checksums, verified deletion, offline Pi retention/reconnect, mid-transfer resume,
checksum mismatch, legal hold, path injection, storage threshold, Egress crash/restart, duplicate
command replay, payload conflict, duplicate worker, concurrent leases, provider replay and WordPress
offline outbox retention.

The WordPress runtime suite covers consent denial (teacher/student/guardian), withdrawal before and
during capture, duplicate/out-of-order callbacks, playback authorisation/rejection, offline archive,
legal hold, migration idempotency and recording-vs-research separation.

## 10a. Verified in this run (synthetic/disposable only)

| Command | Result |
| --- | --- |
| `bash services/tests/run-all.sh` | PASS: cross-boundary signature contract, LiveKit Egress adapter unit, recorder/archiver integration, resilience suite, playback gateway integration. |
| `php platform/tests/recording-archive-policy-unit.php` | PASS: all 15 states, compare-and-swap, replay, conflicts, playback policy, media evidence and signature bounds. |
| `php platform/tests/recording-archive-contract.php` | PASS: source safeguards, migration, Admin consolidation, no Egress authority in WordPress, no upload path, gateway Range/HEAD, Pi fail-closed. |
| `platform/runtime/bin/run-recording-archive-runtime.sh` (disposable WordPress/MariaDB) | PASS: migration and behaviour runtime. |
| Real OVH Egress → Pi archive → WordPress → playback staging run | PASS: 2,714,872-byte MP4, H.264/AAC, SHA-256 match, Pi confirmation, VPS deletion receipt, Range/HEAD playback, offline 503, reconnect playback. |
| `platform/runtime/bin/run-pure-tests.sh` with `DZN_TARGET_REF` at the feature commit | PASS, including all pre-existing pure/contract tests. |
| `platform/runtime/bin/run-consent-lifecycle-runtime.sh` | PASS (PR #512 consent suite preserved). |
| `platform/runtime/bin/run-recording-decision-migration-runtime.sh` | PASS. |
| `platform/runtime/bin/run-recording-decision-behavior-runtime.sh` | PASS. |
| `php platform/tests/admin-shell-route-capability-runtime.php` | PASS (consolidated Settings navigation preserved). |
| `services/infra/orchestra-decommission-rehearsal.sh plan/verify` | PASS (plan only; nothing removed). |

The recorder resilience suite proves duplicate command/worker handling, conflicting payload refusal, Egress crash/restart, offline Pi retention/reconnect, mid-transfer resume, checksum mismatch, Pi capacity, VPS capacity, legal hold (local and WordPress-authorised), request-path injection rejection, expired lease takeover, concurrent inbox claims, concurrent transfer claims and withdrawal archive deletion.

The gateway suite proves authorised student stream, authorised teacher stream, unauthorised/other principal rejection, exact Range bytes, suffix range, unsatisfiable 416, HEAD metadata, Pi-offline 503, concurrency 429, grant replay refusal and access-log allow/refuse/start/complete.

The WordPress runtime proves canonical consent denials, guardian-authority denial, reschedule invalidation, withdrawal before/after capture, duplicate and out-of-order provider events, transfer retry, archive signature verification, signed deletion authorisation, legal hold refusal, playback allow/deny/offline, admin non-override and recording-vs-research separation.

## 10b. Production activation (2026-11-03)

Production `https://delnavazan.com` was deployed at main `6aeac51fdd8af1e605c9494f7d9f695cc7dc29dc`
with migrations `066_consent_lifecycle_authority` and `067_recording_archive_authority` applied.
OVH runs PostgreSQL, the recorder/archiver, Egress `v1.13.0`, the template router and the playback
gateway. Playback is served over TLS at `https://rtc.delnavazan.com:8443`; admin health/metrics
require a bearer token. Pi connectivity uses the hardened reverse-SSH tunnel (Tailscale is still
unavailable on OVH). Capture is gated by `DZN_RECORDING_CAPTURE_APPROVED=true` plus the
`dzn_recording_staging_cohort` cohort; research export remains false. Emergency stop was exercised
in production. Full activation evidence and rollback steps:
`platform/docs/evidence/recording-pi-20261103/PRODUCTION-ACTIVATION.md`.

## 11. Known gaps and blockers

1. A real staging run produced a real Egress MP4, transferred it to the Pi, independently verified
   SHA-256, archived it, deleted the VPS source with a receipt and played it back. Production was
   deployed and activated at main `6aeac51f`; a production test lesson with approved test identities
   is still recommended before relying on it for real lessons. Evidence:
   `platform/docs/evidence/recording-pi-20261103/`.
2. PostgreSQL 16 is now the production recorder database; the synthetic suite still uses SQLite WAL
   for speed.
3. Egress consumed ~97–188% of one core and ~400–424 MiB RSS on the 2-vCPU/4-GB OVH host. Classroom
   reliability headroom is insufficient; a separate Egress worker or larger VPS is recommended.
   Host admission and the emergency stop are the current mitigations.
4. The custom Egress template still uses the external LiveKit client CDN. Vendor or pin it before
   relying on the template for long-term production capture.
5. OVH has no Tailscale client and no dedicated auth key was available. The hardened reverse-SSH
   tunnel with restricted forced-command accounts and read-only SSHFS is the active secure transport;
   Tailscale remains a future hardening option.
6. Owner authorisation for production activation was recorded on 2026-11-03. Legal notice, retention
   and guardian-policy approvals should remain documented in the normal operational record.
7. Orchestra inventory could not be collected from the host; only a read-only rehearsal script and
   shared-resource declaration exist.

## 12. Rollback

Disable the staging constant/cohort and stop the recorder/playback units. Leave all evidence, media,
queue rows and confirmations in place. Rotate the playback key to invalidate outstanding grants.
Do not delete VPS/Pi media or revoke shared credentials. Do not drop audit tables; the migration is
additive and its evidence must be preserved.
