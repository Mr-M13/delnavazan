# Delnavazan Recorder / Archive Infrastructure

Four boundaries:

1. WordPress control plane (canonical lesson/consent authority, outbox, callback reconciliation).
2. `recorder-archiver` on OVH (Egress, private storage, verification, resumable transfer, deletion).
3. Pi archive (Tailscale-only, restricted account, dedicated root).
4. `playback-gateway` on OVH (short-lived grant validation, Range streaming, limits, audit).

## Host layout

```text
/srv/delnavazan-recordings/            0700  private media root
  tmp/<recording_uid>/<version>/       0700  Egress temporary output
  ready/<recording_uid>/<version>/     0700  verified source (same filesystem as tmp)
  recorder.sqlite                      0600  only when SQLite WAL is explicitly selected
/mnt/mbox/DelnavazanRecordings/        0700  dedicated archive root
  <lesson_uid>/<recording_uid>/<content_version>/<sha256>.mp4
  ...manifest.json                      immutable metadata manifest (written by archive tooling)
/mnt/mbox/.delnavazan-mount            0600  mount identity marker
/etc/delnavazan/*.env                  0600  secrets, never web-readable
```

`tmp` and `ready` must be on the same filesystem. Finalisation is an atomic rename, file fsync and
parent-directory fsync. New jobs are refused below the configured absolute/percentage thresholds.

## Deploy checklist (staging only)

```bash
# 1. Install PostgreSQL (production requirement) or explicitly justify SQLite WAL.
# 2. Create the restricted archive account and verify the Pi mount.
sudo services/infra/mbox-provision.sh
sudo services/infra/verify-mbox-mount.sh

# 3. Install secrets with mode 0600 and owned by the service account.
install -m 0600 services/infra/recorder.env.example /etc/delnavazan/recorder.env
install -m 0600 services/infra/playback.env.example /etc/delnavazan/playback.env
# Replace every REPLACE_* value. Use different secrets for service callbacks, archive confirmation
# and playback grants.

# 4. Apply the service schema and start the workers.
/usr/bin/php /opt/delnavazan-recorder/bin/recorder.php migrate
systemctl enable --now delnavazan-recorder.service delnavazan-playback.service

# 5. Bind only to the Tailscale address. Never add a Caddy/WordPress alias for either media root.
ss -lntp | grep -E '8091|8092'
```

## Pi mount identity

The recorder and gateway both require `/mnt/mbox/.delnavazan-mount` and a non-root mount source.
If the mount disappears they return a temporary-unavailability condition; they never write to the
Pi root filesystem and never restore a deleted VPS copy.

## Transfer sequence

1. Verified `ready` asset.
2. Mount identity + Tailscale connectivity.
3. rsync over restricted SSH to a recording-specific `.part` file.
4. Resume safely with `--append-verify`.
5. Destination size + SHA-256.
6. `sync` file and parent directory.
7. Atomic rename to `<sha256>.mp4`.
8. Signed confirmation with source/destination size and digest, node identity, object key, receipt.
9. Persist on OVH and WordPress.
10. Queue VPS deletion.

An SSH/rsync exit code alone is not proof. A different existing object is quarantined and never
overwritten. Offline archive keeps the complete VPS copy and the queue entry.

## Deletion sequence

Deletion is derived only from the database identity:

```text
<srv-root>/ready/<recording_uid>/<content_version>/<sha256>.mp4
```

The worker re-verifies archive confirmation, legal hold, destination digest, source device/inode,
size and digest; rejects symlinks/traversal; opens the trusted directory, unlinks the basename,
flushes the directory and appends a receipt. Request-provided paths are ignored by design.

## Playback

WordPress issues a ≤300-second keyed grant bound to one principal, one recording/content version,
the gateway audience, issuer, not-before, expiry and jti. The gateway exchanges the fragment grant
for an opaque short-lived stream ticket, then serves `HEAD`/`GET` with byte ranges from the private
mount. It enforces global/user concurrency and optional bandwidth limits and logs
allow/refuse/start/complete/abort. There is no permanent public recording URL.

## Resource gate

```bash
sudo services/infra/resource-measure.sh --label baseline --seconds 120
sudo services/infra/resource-measure.sh --label live --seconds 120
```

Capture must remain disabled until a staging load test with one active two-person lesson measures
CPU steal, PSI/memory pressure, OOM events, disk latency, packet loss/RTT/jitter and media quality.
Default recommendation is a separate Egress worker; do not co-locate Egress on the 2-vCPU/4-GB VPS
without proven headroom.

## Tailscale / Pi

Apply only the reviewed ACL in `tailscale-acl.example.hujson`. No public SSH/SMB/NFS/storage port,
no home-router port forwarding, no general Orchestra credential. Playback mounts the archive through
a read-only restricted identity; Pi is never exposed through Caddy or WordPress.

## Monitoring

WordPress Settings → Recordings shows recording/finalising/transfer/retry/verification/archive/
deletion states, consent blocks and storage warnings, plus retry/reconcile/legal-hold operations.
The recorder health callback carries storage free bytes/percent, archive mount identity/availability,
Tailscale state, oldest queue age, Egress CPU/RAM and queue counts.

## Rollback

1. Disable the staging capture constant/cohort and stop the recorder unit.
2. Leave all recording evidence, outbox rows, archive confirmations and media in place.
3. Do not delete VPS or Pi media; do not revoke shared credentials.
4. Revert the plugin/migration only through the normal additive migration rollback policy; never
   drop audit tables.
5. Playback can be disabled independently by rotating the playback key (which invalidates all
   outstanding grants) without touching archive data.
