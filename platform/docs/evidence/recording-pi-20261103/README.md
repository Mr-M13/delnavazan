# Real Staging Evidence — LiveKit → OVH → Pi Archive → Playback

**Date:** 2026-11-03 UTC
**Environment:** disposable WordPress control plane on the operator Mac, OVH LiveKit VPS
(`vps-4e83ecc6` / `rtc.delnavazan.com`), Pi (`pixelbox` / `100.70.11.93`).
**Posture:** staging-only. No production WordPress deployment, no production recording, no
real-student lesson, no ML export, no infrastructure purchase and no Orchestra decommissioning.

## Recording and media

| Item | Value |
| --- | --- |
| Recording UID | `01M6FY8DF5AKKQCQRV8G8V1P75` |
| Canonical lesson UID | `01M6FVG3R8P49BQ5M723XKPJSZ` |
| Egress ID | `EG_J5wog7aaMTrX` |
| Room | `e2e-rec-room` (2 participants, 2 publishers) |
| Source/destination bytes | `2,714,872` |
| SHA-256 | `5859428124ad6559ec42751e8583afef02bfa6fa7e58f972da413f1feb619450` |
| Duration | `32.461 s` |
| Dimensions | `1280 × 720` |
| Streams | 1 video (`H.264`/`avc1`) + 1 audio (`AAC`/`mp4a`) |
| Pi object key | `01M6FVG3R8P49BQ5M723XKPJSZ/01M6FY8DF5AKKQCQRV8G8V1P75/1/5859428124ad6559ec42751e8583afef02bfa6fa7e58f972da413f1feb619450.mp4` |
| Archive node identity | `/dev/sdb1` |
| Durable commit receipt | `dev=2065;ino=…;size=2714872` (OVH-side confirmation record) |
| Deletion receipt | `dev=2049;ino=1773546;size=2714872;sha256=5859428124ad6559ec42751e8583afef02bfa6fa7e58f972da413f1feb619450;archive_deleted=0;deleted_at=2026-11-03 05:39:05` |

The Egress output was inspected, not inferred: a real 2.7 MB MP4 was produced, copied to the Pi, and
its source/destination SHA-256 matched exactly. The VPS source was then removed; the Pi object
remained unchanged and was re-hashed after deletion.

## WordPress lifecycle evidence

The disposable control plane recorded the full sequence:

`authorised → recording_requested → recording_active → recording_finalising →
recording_verified → awaiting_archive → transferring → archive_verification → archived →
vps_deletion_pending → archived_vps_deleted`

Final recording row: `lifecycle_state=archived_vps_deleted`,
`authoritative_location=mbox`, `source_bytes=2714872`, `source_sha256=5859…`.
The archive confirmation and deletion authorisation are append-only WordPress evidence.

## Playback evidence

| Check | Result |
| --- | --- |
| Authorised student grant | `200`, stream ticket issued |
| Authorised teacher grant | `200`, stream ticket issued |
| Full stream | `200`, `2,714,872` bytes, SHA-256 matched the Pi object |
| `Range: bytes=0-99` | `206`, 100 bytes, `Content-Range: bytes 0-99/2714872` |
| Tail range | `206`, 100 bytes |
| `HEAD` | `200`, `Content-Length: 2714872`, `Accept-Ranges: bytes`, `video/mp4` |
| Tampered grant | `403` |
| Replayed grant jti | `403` |
| Admin/unauthorised principal | WordPress refused before grant (`principal_required` / unresolved) |
| Pi offline | Gateway `503 recording_temporarily_unavailable` |
| Pi reconnect | Gateway health `200`; fresh student stream returned full bytes and matching SHA-256 |

Playback audit rows include student/teacher `allow`, and an administrator `refuse` with
`principal_unresolved`.

## Resource measurements (OVH, during one real Room Composite recording)

| Measurement | Value |
| --- | --- |
| Host | 2 vCPU, 3,814 MiB RAM, 38 GB ext4, ~34 GB free |
| Egress container CPU | `97% – 188%` of one core across samples |
| Egress container RSS | `401 – 424 MiB` |
| LiveKit CPU | `5.1 – 6.7%` |
| LiveKit RSS | ~`77 MiB` |
| Host load average | `0.29 → 0.98` during capture |
| Host available memory | ~`2.6 GiB` |
| Pi archive disk | 1.8 TB ext4, ~1.7 TB free |
| Pi sequential read | ~`33.8 MB/s` |
| Pi direct write | ~`84.5 MB/s` |

**Verdict:** Egress consumes roughly one to two vCPU on this 2-vCPU host. That is insufficient
headroom to guarantee classroom quality while recording. A separate Egress worker or a larger VPS
is recommended before production activation; no purchase or resize was performed.

## Final staging database snapshot (after the run)

| Table | Observed value |
| --- | --- |
| `recording_jobs` | `archived_vps_deleted`, provider `EG_J5wog7aaMTrX` |
| `recording_assets` | `verified`, `2,714,872` bytes, SHA-256 `5859…` |
| `archive_confirmations` | source = destination bytes, `sha_match = true`, node `/dev/sdb1` |
| `deletion_jobs` | `leased`, attempts `4` (see anomaly 1 below) |
| `service_outbox` `source_deleted` | `sent`, receipt `dev=2049;ino=1773546;…;archive_deleted=0` |
| VPS `ready/` copy | absent |
| Pi archive object | present, SHA-256 `5859428124ad6559ec42751e8583afef02bfa6fa7e58f972da413f1feb619450` |

## Connectivity and service state

- Pi Tailscale is running (`100.70.11.93`).
- OVH has **no Tailscale client installed**. A dedicated Tailscale auth key was not available; this
  remains the primary infrastructure blocker for the required OVH-to-Pi Tailscale path.
- Staging verification used a dedicated reverse SSH tunnel initiated by the Pi,
  least-privilege `delnavazan-archive` and `delnavazan-playback` accounts, restricted forced commands,
  and read-only SSHFS. This is explicitly a staging fallback, not the final Tailscale design.
- LiveKit Egress `v1.13.0` runs in host networking with a private output volume.
- Recorder/archiver runs in a container with PostgreSQL 16 staging database.
- Playback gateway runs on OVH at loopback `127.0.0.1:8092`; Pi archive is not public.
- Production WordPress still reports schema `57` and has no `066_recording_archive_authority` ledger
  entry. The feature branch was not deployed there.

## Hardening pass (same day, after the first staging run)

No new real lesson was recorded in this pass. The following changes were applied and verified with
the service suite and the disposable WordPress runtime:

| Change | Verification |
| --- | --- |
| Queue claims now return the post-claim CAS version, so transfer/deletion completion cannot be left `leased` by an off-by-one update. | `Egress crash/restart` asserts transfer `archived` and deletion `completed`. |
| Crash-after-unlink deletion reconciliation completes only with durable proof (`delete_archive`, `archived_vps_deleted`, or an existing `source_deleted` event). | `Deletion crash reconciliation` creates a missing source plus `source_deleted`, then asserts `completed` and a `source_missing=1` receipt. |
| Pi-offline retention remains fail-closed. | `Pi offline/reconnect` asserts the complete VPS copy and no deletion job while the archive mount is absent, then transfer/confirm/delete on reconnect. |
| Durable WordPress emergency stop blocks new capture and queues stops for active/requested recordings. | Recording archive WordPress runtime asserts the stop command, a blocked new request and release. |
| Host-local recorder emergency stop file refuses capture even if WordPress is unreachable. | `Emergency stop file` asserts zero Egress starts and health `emergency_stop_active`. |
| Prometheus metrics on the recorder and playback gateway. | Contract test asserts `/metrics`; health carries per-state queue depth and emergency-stop state. |

Commands: `bash services/tests/run-all.sh`, `php platform/tests/recording-archive-contract.php`,
`DZN_TARGET_REF=… platform/runtime/bin/run-recording-archive-runtime.sh` — all PASS.

## Remaining staging anomalies / gaps

1. The staging deletion job row remained `leased` even though the `source_deleted` outbox contains the
   complete receipt and the VPS file is gone. The code has since been hardened to complete the job after
   lease/version drift; this exact run was not replayed after that hardening.
2. Two old `transfer_failed` outbox rows remained pending because they were emitted before the clean
   successful transfer; they are non-authoritative duplicate failure evidence.
3. The disposable WordPress candidate worktree was partially refreshed with the latest recording code;
   the final branch source is the authority.
4. The real playback UI was not rendered in a production theme. Playback was verified through real
   WordPress-issued grants and the real gateway.
5. Tailscale on OVH, legal/owner notices, retention approval, real-provider staging load test and
   production WordPress deployment remain blocked/approval-dependent.
