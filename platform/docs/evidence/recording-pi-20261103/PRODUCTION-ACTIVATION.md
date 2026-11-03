# Production Activation — Recording and Pi Archival

**Activated:** 2026-11-03 UTC
**Production build:** `main-6aeac51fdd8a` (main SHA `6aeac51fdd8af1e605c9494f7d9f695cc7dc29dc`)
**WordPress target:** `https://delnavazan.com`
**Posture:** capture enabled for consenting lessons; research export remains disabled.

## Code and migration state

- Main integration PR #519 reconciled the consent and recording branches on current `main`.
- `065_confirmed_intro_cancellation` (pre-existing main) was preserved.
- Consent migration renumbered to `066_consent_lifecycle_authority`.
- Recording archive migration renumbered to `067_recording_archive_authority`.
- Production ledger contains all three; platform schema remains `57`.
- Plugin active, theme `delnavazan-theme` active, build `main-6aeac51fdd8a`.

Backups: `/home/delnavaz/backups/dzn-production/<timestamp>-<sha>/` (application files + database dump + deployment metadata).

## OVH production services

| Component | Container / endpoint |
| --- | --- |
| PostgreSQL 16 | `dzn-recorder-db-prod`, loopback `5433`, volume `dzn-recorder-pgdata-prod` |
| Recorder/archiver | `dzn-recorder-prod`, loopback health `:8091` |
| LiveKit Egress | `delnavazan-egress-prod`, `livekit/egress:v1.13.0`, CPU-cost override |
| Egress template router | `dzn-egress-template-prod`, loopback `:8091` |
| Playback gateway | `delnavazan-playback-prod`, loopback `:8092` |
| Public playback TLS | `https://rtc.delnavazan.com:8443` via Caddy L4 |

Recording storage: `/srv/delnavazan-recordings` (tmp/ready, 0710/0700, same filesystem).
Pi mount: `/mnt/delnavazan-pi-archive`, read-only SSHFS.

## Secure Pi connectivity

- Production archive and playback keys were generated on OVH and added to the dedicated Pi accounts.
- Pi archive account uses the restricted forced-command helper (`rsync` write + verify/commit/delete only).
- Pi playback account uses chrooted read-only SFTP.
- Pi runs `delnavazan-ovh-tunnel-prod.service`, a persistent reverse SSH tunnel to OVH loopback `2222`.
- OVH known-hosts pin the Pi tunnel host key; SSH is non-interactive with `StrictHostKeyChecking=yes`.
- Verified: archive identity `/dev/sdb1`, free ~1.84 TB; the real 2,714,872-byte archive object hashes to `5859428124ad6559ec42751e8583afef02bfa6fa7e58f972da413f1feb619450` through the production mount.

Tailscale is **not installed on OVH** and no dedicated auth key was available. The hardened reverse-SSH transport is the supported secure path for this activation; Tailscale remains a future hardening option.

## Monitoring and emergency stop

- Recorder `/metrics` (loopback `:8091`) exposes readiness, VPS/Pi storage, archive availability, emergency-stop state, oldest queue age and per-state queue depth.
- Playback `/metrics` and `/health` require `Authorization: Bearer <DZN_PLAYBACK_ADMIN_TOKEN>`; unauthenticated requests return `404`.
- WordPress `dzn_recording_service_health` now persists queue depth, host load/memory and emergency-stop state.
- Production emergency stop was engaged and released through the control plane: capture was refused while engaged and restored after release.
- Host-local kill file: `/run/delnavazan-recorder/emergency-stop` (container path) refuses new Egress starts even if WordPress is unreachable.

## Deletion reconciliation and retention

- Queue claims now return the post-claim CAS version, so transfer/deletion completion cannot be left `leased` by an off-by-one update.
- Crash-after-unlink reconciliation completes only with durable proof (`delete_archive`, WordPress `archived_vps_deleted`, or an existing `source_deleted` event); otherwise it fails closed.
- Pi-offline retention is covered by the `Pi offline/reconnect` service test and by the earlier real staging run: the complete VPS copy and no deletion job are retained while the archive mount is absent; transfer/confirm/delete proceed after reconnect.

## Production Pi-outage drill

The production Pi tunnel was stopped and the OVH SSHFS mount removed, without creating or deleting
any recording:

| Check | Result |
| --- | --- |
| Recorder health | `ready=false`, `archive_available=false`, empty mount identity |
| Playback health (admin token) | `503`, `archive_mount_absent` |
| New capture readiness | refused by the recorder `ready` gate |
| Reconnect | tunnel active, mount restored, recorder `ready=true`, archive identity `/dev/sdb1`, playback `200` |

This confirms the production fail-closed behaviour; the transfer-level retention proof is the
`Pi offline/reconnect` service test and the earlier real staging run.

## Activation state

```
DZN_RECORDING_CAPTURE_APPROVED=true
dzn_recording_staging_cohort=["all-approved"]
CAPTURE_ENABLED=false            # build kill-switch unchanged
RESEARCH_EXPORT_ENABLED=false    # research remains separate
emergency stop: engaged=false
```

Consent, teacher/student authority, exact lesson/schedule binding, guardian rules, withdrawal, legal hold and the recording authorisation gate still apply per lesson. A lesson can record only when the canonical consent gate authorises it.

## Remaining risks / next verification

1. A production test lesson with approved test identities has not yet been recorded end to end; the real Egress/archive/playback proof was executed in staging.
2. Egress consumes roughly 1–2 vCPU on the 2-vCPU OVH host. Host admission and the emergency stop are the current mitigations; a separate Egress worker or larger VPS is still recommended.
3. Tailscale on OVH remains unavailable; the reverse-SSH tunnel is the active secure transport.
4. Playback depends on the Pi tunnel and SSHFS mount; loss of either returns `503 recording_temporarily_unavailable` and never restores a deleted VPS copy.

## Rollback

1. Engage the WordPress emergency stop or set `DZN_RECORDING_CAPTURE_APPROVED=false`.
2. Clear/restrict `dzn_recording_staging_cohort`.
3. Stop the OVH production containers, unmount SSHFS, stop the Pi production tunnel.
4. Restore application files/database from the recorded production backup if a code rollback is required; migrations are additive and may be left in place.
5. Do not delete Pi archive media or unrelated production media.
