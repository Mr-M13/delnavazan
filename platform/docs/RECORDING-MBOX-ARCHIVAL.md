# Delnavazan Lesson Recording and M-Box Archival

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

## 2. WordPress data model (migration `066_recording_archive_authority`)

| Table | Purpose |
| --- | --- |
| `dzn_recordings` | Recording UID, canonical lesson/schedule snapshot, participants, authorisation evidence/digest, policy/notice references, provider Egress ID, content version, lifecycle, media metadata, source bytes/SHA-256, authoritative location, legal hold, disposition, optimistic version. |
| `dzn_recording_events` | Append-only accepted/refused/delayed/duplicate/conflict evidence with idempotency key and payload digest. |
| `dzn_recording_commands` | Durable start/stop/reconcile/transfer/transfer-retry/archive-verification/source-deletion/withdrawal outbox with leases, attempts, bounded backoff, version and safe failure class. |
| `dzn_recording_archive_confirmations` | Exact recording/version, source/destination size and SHA-256, archive node, object key, durable commit receipt and signature. |
| `dzn_recording_playback_access` | Append-only allow/refuse/start/complete/abort audit; only a keyed jti digest, never a raw token. |

The migration is additive, creates no decision or recording row, backfills no consent and is
verified by table/engine/column/index/digest/shape checks. It follows `065_consent_lifecycle_authority`
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
  lesson/all-staging cohort. Production can never satisfy the environment condition.
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

## 7. M-Box transfer and deletion

- Tailscale-only dedicated identities; ACL example in `services/infra/tailscale-acl.example.hujson`.
- Restricted `delnavazan-archive` account and `/mnt/mbox/DelnavazanRecordings`; mount identity is
  verified before every write. No root-filesystem fallback.
- Restricted rsync over SSH to a recording-specific temporary filename, `--append-verify` resume,
  remote size/SHA-256 verification, file and directory sync, atomic rename.
- Signed confirmation persisted on OVH then sent to WordPress; only then is VPS deletion queued.
- Existing same content is idempotent; different content is quarantined and never overwritten.
- Offline M-Box preserves the complete VPS copy, queue entry, bounded retry and alert.
- Deletion derives the path from the database, checks archive confirmation/destination digest,
  legal hold, traversal/symlink, device/inode/size/digest, opens the trusted directory, unlinks the
  basename, flushes the directory and appends a receipt. Request paths are ignored.

## 8. Playback

WordPress checks the signed-in canonical principal, lesson ownership, teacher/student/guardian
restriction, current withdrawal policy, archive state and M-Box availability. It issues a keyed
≤300-second grant bound to one recording/content version, gateway audience, principal, key id,
not-before/expiry/issuer and jti, and appends allow/refuse audit.

The OVH gateway validates the grant independently, exchanges it for an opaque short-lived stream
ticket, and streams from the private M-Box mount with `HEAD` and byte ranges. It enforces per-user
and global concurrency, optional bandwidth limits and allow/refuse/start/complete/abort logging.
M-Box offline returns `503 recording_temporarily_unavailable`; it never restores a deleted VPS copy.

## 9. Administration and monitoring

Settings → Recordings (inside the existing consolidated shell) shows currently recording, finalising,
awaiting transfer, transferring, retry pending, archive verification, archived, VPS deletion pending,
unavailable, quarantined, consent block (24 h) and storage warning. Retry/reconcile/legal-hold are
capability-gated. There is no consent override and no filesystem-path field.

Health covers VPS/M-Box storage, mount identity, Tailscale, oldest queue age, repeated failures,
Egress CPU/RAM, completion failures, checksum mismatches, deletion failures and playback saturation.

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
matching checksums, verified deletion, offline M-Box retention/reconnect, mid-transfer resume,
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
| `php platform/tests/recording-archive-contract.php` | PASS: source safeguards, migration, Admin consolidation, no Egress authority in WordPress, no upload path, gateway Range/HEAD, M-Box fail-closed. |
| `platform/runtime/bin/run-recording-archive-runtime.sh` (disposable WordPress/MariaDB) | PASS: migration and behaviour runtime. |
| `platform/runtime/bin/run-pure-tests.sh` with `DZN_TARGET_REF` at the feature commit | PASS, including all pre-existing pure/contract tests. |
| `platform/runtime/bin/run-consent-lifecycle-runtime.sh` | PASS (PR #512 consent suite preserved). |
| `platform/runtime/bin/run-recording-decision-migration-runtime.sh` | PASS. |
| `platform/runtime/bin/run-recording-decision-behavior-runtime.sh` | PASS. |
| `php platform/tests/admin-shell-route-capability-runtime.php` | PASS (consolidated Settings navigation preserved). |
| `services/infra/orchestra-decommission-rehearsal.sh plan/verify` | PASS (plan only; nothing removed). |

The recorder resilience suite proves duplicate command/worker handling, conflicting payload refusal, Egress crash/restart, offline M-Box retention/reconnect, mid-transfer resume, checksum mismatch, M-Box capacity, VPS capacity, legal hold (local and WordPress-authorised), request-path injection rejection, expired lease takeover, concurrent inbox claims, concurrent transfer claims and withdrawal archive deletion.

The gateway suite proves authorised student stream, authorised teacher stream, unauthorised/other principal rejection, exact Range bytes, suffix range, unsatisfiable 416, HEAD metadata, M-Box-offline 503, concurrency 429, grant replay refusal and access-log allow/refuse/start/complete.

The WordPress runtime proves canonical consent denials, guardian-authority denial, reschedule invalidation, withdrawal before/after capture, duplicate and out-of-order provider events, transfer retry, archive signature verification, signed deletion authorisation, legal hold refusal, playback allow/deny/offline, admin non-override and recording-vs-research separation.

## 11. Known gaps and blockers

1. No real LiveKit Egress run, OVH VPS, PixelBox, Tailscale identity or M-Box mount was available.
   Real-provider capture, real transfer, outage recovery and independently verified real deletion
   remain required before activation.
2. PostgreSQL execution was not run in this environment; only SQLite WAL was exercised.
3. Resource measurements on the stated 2-vCPU/4-GB/40-GB VPS were not possible. Run
   `services/infra/resource-measure.sh` and a real one-lesson load test; default remains a separate
   Egress worker.
4. Custom Egress template depends on the external LiveKit client script and the recorder's loopback
   HTTP service; validate in staging.
5. Playback gateway sshfs/mount provisioning for PixelBox must be completed and tested on the real
   tailnet.
6. Legal/owner approval of notices, retention, guardian rules and provider agreement remains from
   PR #512.
7. Orchestra inventory could not be collected from the host; only a read-only rehearsal script and
   shared-resource declaration exist.

## 12. Rollback

Disable the staging constant/cohort and stop the recorder/playback units. Leave all evidence, media,
queue rows and confirmations in place. Rotate the playback key to invalidate outstanding grants.
Do not delete VPS/M-Box media or revoke shared credentials. Do not drop audit tables; the migration is
additive and its evidence must be preserved.
