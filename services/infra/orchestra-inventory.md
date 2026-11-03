# Orchestra Boundary and Decommission Inventory

**Status:** rehearsal only. Nothing was removed, revoked, disabled or deleted.

The repository contains no Orchestra service definition, timer, credential, tunnel, broker, dashboard
or backup configuration. This document therefore records the absence of evidence and the exact
commands required on the VPS/tailnet. It does not claim that Orchestra is unused.

## Shared until proven

| Resource | Current treatment | Proof required before any shutdown |
| --- | --- | --- |
| Tailscale tailnet and ACLs | Shared | Dedicated OVH recorder/playback and PixelBox archive identities exist, with ACL tests passing and Orchestra devices unchanged. |
| PixelBox M-Box mount `/mnt/mbox` | Shared | Mount UUID/source and the dedicated archive root are recorded; Delnavazan never writes outside `DelnavazanRecordings`. |
| Backup infrastructure | Shared | Delnavazan archive has its own retention/backup path; no Orchestra backup job owns it. |
| Credentials | Shared | Delnavazan has distinct keys; no Orchestra key is reused; no credential is revoked by this rehearsal. |
| LiveKit/Redis/Caddy | Shared host services | Delnavazan recording uses its own Egress credential/worker and does not change classroom audio publication. |

## Inventory procedure

Run on the OVH host (read-only):

```bash
bash services/infra/orchestra-decommission-rehearsal.sh inventory /tmp/orchestra-inventory.txt
```

The script records systemd units/timers, cron, listening ports, Tailscale status, mounts, containers
and credential file paths (not contents). It does not print secrets and does not mutate state.

## Reversible rehearsal plan

1. Capture the inventory and a fresh Tailscale status before any change.
2. Prove Delnavazan transfer/playback with the dedicated identities while Orchestra remains running.
3. Disable Orchestra **entry points only** in a maintenance window, one at a time, with rollback
   commands prepared.
4. Observe Delnavazan queue age, archive confirmations, playback and backups for the agreed period.
5. Only after owner/security approval, remove Orchestra packages/data/credentials.

The current run stops at step 1. Steps 3–5 are explicitly **not executed**.

## Rehearsal verification

```bash
bash services/infra/orchestra-decommission-rehearsal.sh plan /tmp/orchestra-plan.txt
bash services/infra/orchestra-decommission-rehearsal.sh verify /tmp/orchestra-plan.txt
```

The verification asserts that Tailscale and unrelated services remain declared shared and that the
output contains the no-destructive-action marker. It is not a production shutdown approval.
