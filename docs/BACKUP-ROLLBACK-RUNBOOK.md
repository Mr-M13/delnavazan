# Production backup and rollback runbook

**Status: installable through one audited dispatch. `install-production-host-tooling.yml` installs
the host tooling and takes the first rollback point; the rclone remote and every restore remain owner
actions. Nothing here deploys, migrates or changes site data by itself.**

Owner decision recorded for 2026-11-16 (no staging environment exists):

- A nightly rollback point plus one before every `wp-admin` update is enough protection for the
  production site.
- **Two points are enough.** Older points are deleted: `DZN_BACKUP_KEEP=2` is a constant in
  `deployment/qa/backup-point.sh` and in `deployment/qa/restore-point.sh`, easy to raise later.
- Off-site copies are optional, encrypted, and pushed with rclone only when
  `DZN_BACKUP_REMOTE` is set.
- Rollback is one operator command with an exact confirmation token; it is never automatic and
  never runs from CI.

Agents prepare and review this tooling. The guarded install is a release-coordinator dispatch, never
an agent-side production write, and no agent restores a point or runs the drill against production
(`AGENTS.md` §2, §3). Two offline source contracts prove the guards in the PR gate:
`deployment/qa/backup-rollback-contract.sh` for the tools and
`deployment/qa/install-host-tooling-contract.sh` for the install workflow.

## 1. What the tooling is

| File | Runs where | Purpose |
| --- | --- | --- |
| `deployment/qa/backup-point.sh` | production host (cron or SSH) | take one verified rollback point, prune to the newest two |
| `deployment/qa/restore-point.sh` | production host (operator) | restore the newest or a named point, fail closed |
| `.github/workflows/install-production-host-tooling.yml` | GitHub Actions (`production`) | install both host scripts, install the nightly cron, take the first point |
| `deployment/qa/backup-rollback-contract.sh` | CI and any workstation | source contract: no network, no real WP-CLI |
| `deployment/qa/install-host-tooling-contract.sh` | CI and any workstation | source contract for the install workflow: no network |
| `docs/BACKUP-ROLLBACK-RUNBOOK.md` | — | this document |

A point is a directory under `$DZN_BACKUP_ROOT/points/<UTC-of-take>/`:

```
database.sql.gz          full WordPress database export, --add-drop-table
database.sql.gz.sha256   "<sha256>  database.sql.gz"
public_html.tar.gz       the whole WordPress tree, minus wp-content/cache
public_html.tar.gz.sha256
manifest.json            point, created_utc, build_id (main-<12 SHA>),
                         schema_version (dzn_platform_schema_version), sizes and SHA-256s
```

`$DZN_BACKUP_ROOT` defaults to `$HOME/backups/delnavazan-rollback-points` and is created `0700`;
every file is `0600`. **The archives contain `wp-config.php`, so they contain the live database
credentials and salts.** Never place the backup root under `public_html`, never unpack a point
into a web-served directory, and only push to an encrypted rclone remote.

`wp-content/cache` is excluded on purpose: it is regenerated, and it is the one directory that
changes constantly. Uploads and everything else are inside the archive.

## 2. Operating rules

1. **Take a point before every `wp-admin` core, plugin or theme update**, on the host, and keep
   the output. `wp-admin` updates are not deployed by the release workflow, so they have no other
   rollback path.
2. **Take a point after every production deploy** that changed the site, before touching any data
   in `wp-admin`. The deploy workflow already keeps a cutover backup, but that copy is pruned by
   `deployment/qa/prune-production-backups.sh` and is not the one-step points below.
3. **Never restore without reading the point first.** Run `--dry-run`; it verifies the manifest,
   both checksums, gzip/tar integrity and the schema pairing, and changes nothing.
4. **Read the recorded build identity.** A point belongs to the code that produced it. If the
   point is older than the deployed code, the tool refuses unless `--force`, and it prints the
   `main-<12 SHA>` to redeploy.
5. **Production is closed while a restore runs.** The tool enables maintenance mode itself,
   preserves an existing maintenance window, and only deactivates it after the restore verifies.
   If any step fails it leaves or re-enables maintenance mode; do not disable it by hand.
6. **One restore at a time, and no deploy at the same time.** Both share the same site.
7. A point is only worth what its last verification proved: run the monthly verification in §7.

## 3. Install on the host

Installing is an audited workflow dispatch, so it needs no operator workstation and no local
checkout. From `main`, with the exact current `main` SHA:

```sh
gh workflow run install-production-host-tooling.yml \
  --ref main \
  -f commit_sha="$(git rev-parse origin/main)" \
  -f confirm=INSTALL_HOST_TOOLING
gh run watch
```

The workflow refuses to run unless all of these hold:

- it is dispatched from `main` and runs in the protected `production` environment;
- `commit_sha` is one full 40-character SHA that is still the exact current `origin/main`;
- `confirm` is the literal `INSTALL_HOST_TOOLING`;
- the checkout is the run's own commit, proven equal to `commit_sha` and still the exact current
  `origin/main` — no rolling branch ref and no dispatch input selects a ref.

It then installs, over SSH with only the three protected production secrets, and nothing else:

1. `~/bin` and the default rollback-point root, both `0700`;
2. `deployment/qa/backup-point.sh` as `~/bin/dzn-backup-point.sh` and
   `deployment/qa/restore-point.sh` as `~/bin/dzn-restore-point.sh`, both `0700`, each written to a
   sibling temporary file and moved into place so host cron never sees a partial script, then
   proven complete (byte count) and syntactically whole (`bash -n`);
3. the daily 03:07 UTC cron line below — **appended only when its marker `dzn-backup-point.sh` is
   absent from `crontab -l`**, so a re-run never duplicates it and never drops or rewrites an
   existing line, and the run then proves exactly one such line exists;
4. one rollback point with the tool it just installed.

Host cron, one line, daily at 03:07 UTC (the existing `prune-production-backups` host cron runs at
03:17 and only touches `$HOME/backups/delnavazan-production-cutover`). The workflow installs exactly
this line:

```cron
7 3 * * * /bin/bash "$HOME/bin/dzn-backup-point.sh" >> "$HOME/backups/delnavazan-rollback-points/cron.log" 2>&1
```

The first point is taken with `DZN_BACKUP_REMOTE` deliberately empty, so the script prints
`OFFSITE=skipped` and exits 0 and no off-site credential is needed. Add the off-site copy in §4 to
the host crontab as its own environment line when rclone is configured:

```cron
DZN_BACKUP_REMOTE=rclone-crypt:delnavazan-rollback-points
```

Leave that line out to stay local-only. With it set, a failed push prints `OFFSITE=failed`, exits
non-zero and therefore mails cron, because a silent off-site gap defeats the copy. The local point is
valid either way and is named in the output. Setting up rclone stays an **owner action**: neither
the workflow nor an agent configures a remote or stores a crypt password.

Re-dispatching the workflow is safe by design: the script copies are rewritten from the pinned SHA,
the cron line is left alone because its marker is present, and the run simply takes another point
(retention keeps the newest two). Do not dispatch it while a deploy is running — both share the
`delnavazan-production-deploy` concurrency group, so GitHub serializes them, but a queued deploy is a
reason to wait.

**Restore remains operator-only and never runs from CI.** The workflow copies
`restore-point.sh` to the host so the operator can use it by hand; it never invokes it, never opens a
second maintenance window, and never imports a database. A workflow run can only copy two reviewed
scripts, append one cron line and take a point — it cannot restore, delete, deploy, migrate, purge or
read a credential.

Every run appends one PII-free line to `$HOME/backups/delnavazan-rollback-points/backup-point.log`
and, on restore, `restore.log`.

## 4. Off-site copy with rclone (optional)

The copy must be encrypted at rest and readable only by the owner. Use a `crypt` remote over the
storage provider, with the rclone configuration **outside** the repository and outside
`public_html` (default `$HOME/.config/rclone/rclone.conf`, mode `0600`).

1. Install rclone on the host (static binary in `$HOME/bin` is enough; no root needed).
2. Create the storage remote, for example Google Drive over the owner's own account:

   ```sh
   rclone config create gdrive drive scope drive
   rclone config reconnect gdrive:
   rclone mkdir gdrive:delnavazan-rollback
   ```

   `reconnect` prints an authorisation URL; complete it in a browser as the owner. No client
   secret is stored in this repository and none may be committed.

3. Create the encrypted layer on top of it:

   ```sh
   rclone config create rclone-crypt crypt \
     remote gdrive:delnavazan-rollback \
     filename_encryption standard \
     directory_name_encryption true
   ```

   Store the generated crypt password and salt in the owner's password manager. **Losing them
   loses every off-site point**, because the remote contains no plaintext names or contents.

4. Point the tooling at it and verify:

   ```sh
   export DZN_BACKUP_REMOTE=rclone-crypt:points
   rclone lsd rclone-crypt:          # empty but reachable
   rclone check "$HOME/backups/delnavazan-rollback-points/points/<UTC>" \
     rclone-crypt:points/<UTC> --one-way
   ```

The push copies the new point, lists it back and requires `manifest.json`,
`database.sql.gz` and `public_html.tar.gz` to be present before it prunes anything. Remote
retention is the same newest two points; only directory names matching `YYYYMMDDTHHMMSSZ` are
ever purged, so unrelated content on the remote is never deleted.

## 5. Taking a point by hand

```sh
export PRODUCTION_SSH_USER=<protected-production-ssh-user>
ssh "${PRODUCTION_SSH_USER}@cp119.hosting-cloud.net" 'bash -s' \
  < deployment/qa/backup-point.sh
```

Expected output:

```
POINT=/home/<user>/backups/delnavazan-rollback-points/points/20261116T031700Z
BUILD=main-<12 hex>
SCHEMA=<n>
DB_SHA256=<64 hex>
FILES_SHA256=<64 hex>
KB=<db>/<files>
RETENTION kept=2 pruned=<n> limit=2
OFFSITE=ok|skipped
ROLLBACK_POINT=ok
```

The tool refuses, changing nothing, when the WordPress root is not a production installation,
when WP-CLI is missing, when the deployed build identity is not `main-<12 hex>`, when the schema
option is not numeric, or when either archive fails gzip, tar or SHA-256 verification. A failed
run leaves no half-written point.

## 6. Restore procedure

Preconditions:

- [ ] The bad change is identified, and a point taken **before** it exists.
- [ ] `--dry-run` passed for the intended point.
- [ ] The point's `build_id` and `schema_version` are recorded in the change ticket.
- [ ] No deployment is queued or running, and no other restore is in progress.
- [ ] The owner accepts that the site is closed during the restore.

Verify without changing anything:

```sh
ssh "${PRODUCTION_SSH_USER}@cp119.hosting-cloud.net" 'bash -s' -- \
  --point 20261116T031700Z --dry-run --confirm RESTORE \
  < deployment/qa/restore-point.sh
```

Restore (omit `--point` for the newest complete point):

```sh
ssh "${PRODUCTION_SSH_USER}@cp119.hosting-cloud.net" 'bash -s' -- \
  --point 20261116T031700Z --confirm RESTORE \
  < deployment/qa/restore-point.sh
```

What it does, in order:

1. verifies the manifest, both sidecar checksums, both gzip streams and the tar listing, and
   compares the archive sizes with the manifest — a corrupt point aborts before anything changes;
2. reads `wp_get_environment_type()` and refuses unless it is `production`;
3. compares the point's schema with the deployed `DZN_PLATFORM_SCHEMA_VERSION` and refuses when
   they differ unless `--force`, printing the point's `main-<12 SHA>` to redeploy;
4. preserves an existing maintenance window, or enables one;
5. exports the **current** database to `$DZN_BACKUP_ROOT/pre-restore/` as a verified safety copy
   (kept newest two) and prints its path and SHA-256;
6. imports the point's database, then proves the restored `dzn_platform_schema_version` equals the
   manifest and that WordPress is installed — **before** any file is touched;
7. extracts the file archive into a staging directory beside `public_html`, checks it is a
   complete WordPress tree, moves the live tree to `public_html.dzn-pre-restore-<UTC>` and moves
   the restored tree into place;
8. flushes the object cache and proves the restored `DZN_PLATFORM_BUILD_ID` equals the manifest;
9. runs a read-only static HTTP probe (no auth, no cookies) that still answers 200 while
   maintenance mode is on, because the web server serves it without PHP;
10. deactivates maintenance mode and runs a read-only homepage smoke (HTTP 200, no auth).

Maintenance mode answers every anonymous PHP request with HTTP 503, so the homepage check can
only run after deactivation. If it fails, maintenance is re-activated immediately, the run fails,
and the site stays closed.

Expected output ends with:

```
RESTORE=ok point=<UTC> build=main-<12 hex> schema=<n> previous_tree=/home/<user>/domains/delnavazan.com/public_html.dzn-pre-restore-<UTC>
```

Then verify by hand:

1. `https://delnavazan.com` answers 200 and the homepage renders the expected theme.
2. `wp option get dzn_platform_schema_version` equals the manifest value.
3. Bookings, the catalogue and one Teacher/Student portal page load.
4. No `Briefly unavailable` page and no PHP error marker anywhere.
5. Only then delete `public_html.dzn-pre-restore-<UTC>` (it doubles the site's disk use) and
   record the restore in the change ticket.

If the restore itself must be undone, use the pre-restore export and the previous tree printed in
the output — that is exactly why they exist. The pre-restore export is a full, verified database
copy; import it with `wp db import` under maintenance mode, and move the previous tree back.

Schema mismatch: the tool refuses when the point's database is older or newer than the deployed
code expects. **Do not reach for `--force` first.** Redeploy the recorded build with the canonical
workflow, then restore; that keeps code and database paired and lets the forward-only migrator run
once, deliberately. `--force` exists for the case where the deployed code cannot even load (its
schema constant is unreadable), which is a recovery situation, not a routine one.

## 7. Monthly restore drill

Cheap, every month, on the host — verification only, nothing is written:

```sh
ssh "${PRODUCTION_SSH_USER}@cp119.hosting-cloud.net" 'bash -s' -- \
  --dry-run --confirm RESTORE < deployment/qa/restore-point.sh
```

Quarterly, prove a point actually imports, in the disposable runtime stack (operator action; the
Docker runtime suites are CI-only for agents):

```sh
# 1. Bring up a disposable MariaDB + WordPress stack.
platform/runtime/bin/reset-disposable.sh

# 2. Import the point's database into that stack, never into the host database.
set -a; . platform/runtime/bin/common.sh; set +a
gzip -dc "$HOME/backups/delnavazan-rollback-points/points/<UTC>/database.sql.gz" \
  | dzn_wp --skip-plugins --skip-themes db import -

# 3. Prove the restore landed.
dzn_wp --skip-plugins --skip-themes core is-installed
dzn_wp option get dzn_platform_schema_version      # must equal the manifest value

# 4. Optionally unpack the files over the disposable root and confirm the theme loads.
tar -C "$DZN_WP_DIR" --strip-components=1 -xzf "$HOME/backups/delnavazan-rollback-points/points/<UTC>/public_html.tar.gz"
```

The disposable stack sets `WP_ENVIRONMENT_TYPE=local` and its own database, so `restore-point.sh`
correctly refuses there; the drill uses the artifacts directly, which is the part that needs
proving. Finish with `platform/runtime/bin/destroy.sh YES`.

Never rehearse by copying `public_html` on the host and importing into it: the copy's
`wp-config.php` points at the **production** database. A throwaway host directory is only safe
with an edited `wp-config.php` targeting a scratch database and a different `$table_prefix`.

## 8. Rolling back schema migrations (code + database pairing)

- The Platform migrator is forward-only and records `dzn_platform_schema_version` plus the
  completed migration list. A point captures both the database and the code that produced it, so
  restoring a point restores a consistent pair.
- Restoring an older point therefore also rolls the schema back to the older value. The next
  deploy of `main` runs `Migrator::maybe_upgrade()` again and re-applies every migration after
  that value. That is safe because migrations are idempotent and verified, but it is a deliberate
  step: expect the deploy to take longer and re-check the diagnostics afterwards.
- Never restore only the database against newer code, and never point newer code at an old
  database outside that migration path. The schema guard exists to make that mistake impossible
  without an explicit `--force`.
- A point does not revert provider-side state. Google Calendar/Meet events, LiveKit rooms and
  Stripe objects created after the point still exist; reconcile them separately, exactly as the
  go-live purge runbook does.

## 9. Non-goals

- Not a replacement for the hosting provider's own backups or a disaster-recovery plan: a host
  loss takes the local points with it, which is why the encrypted off-site copy exists.
- No provider-side restore (Google, LiveKit, Stripe, Telnyx).
- No deployment, migration, purge or data edit: the restore only puts back a known point.
- No automatic restore on a failed deploy. A failed deploy keeps its own guarded path in
  `deployment/README.md`; a human decides when a rollback point is the right answer.
- No unattended restore, no restore from CI, no restore token stored anywhere in the repository.
- No secrets in the scripts or in the repository: `rclone` configuration stays on the host,
  outside `public_html`, and the archives are treated as secret material.
- `wp-content/cache` is not preserved; it is rebuilt by WordPress.
