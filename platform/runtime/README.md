# Offline Schema-33 disposable runtime

This is the bounded WordPress/MariaDB evidence harness for the Schema-33 candidate —
Schema 031 (`031_portal_facing_services_principal_authorization`, the Phase-2A.2-W
portal slice), the additive Schema 032 (`032_notification_communications_authority`,
the Phase-2A.2-S notification slice) and the additive Schema 033
(`033_core_dataset_technical_prerequisites`, the bounded Core-dataset readiness
slice) this package declares. It is not a deployment tool and it never reads from,
checks out, or symlinks the shared checkout into a running WordPress instance.

Every run creates detached Git worktrees beneath `DZN_RUNTIME_STATE_DIR`
(default: `/tmp/dzn-platform-schema33-local`). Path boundaries are enforced
physically, never lexically. The state directory, the worktree paths
(`candidate`, `schema30`, `schema31`), the database and WordPress bind mounts and
`DZN_PLUGIN_SOURCE` are each resolved with `realpath` and refused unless they
land inside the canonical state directory, so a symlink, a relative alias or a
`..` component cannot redirect a run; the state directory itself is also
refused when it resolves inside this checkout. A pre-existing worktree path
that is a symlink is refused outright — including
`state/candidate -> <shared checkout>`, which would otherwise be reused as the
mounted plugin source whenever its Git `HEAD` happened to match the wanted ref.
Those checks run when `bin/common.sh` is sourced and again at every container
entry point (`dzn_compose`, `dzn_wp`, `dzn_wp_env`, `dzn_php`), so a script that
retargets `DZN_PLUGIN_SOURCE` after sourcing — or a tampered
`historical-source` file — still cannot reach docker with non-disposable
source. The only writable plugin source is therefore disposable. WordPress
binds only to `127.0.0.1`; MariaDB has no published port.

The state directory must itself be a dedicated, narrowly scoped disposable area.
`/`, `/tmp`, `/var`, `/var/tmp`, `/etc`, `/usr`, `/opt`, `/srv`, `/mnt`,
`/media`, `/Volumes`, `/System`, `/Library`, `/Applications`, `/Users`, `/home`,
`/root` and the equivalent `/private/...` spellings are refused, as are the home
directory (or any ancestor of it), a directory that contains this checkout, a
path shallower than two levels below the root and a mount point. Consistently,
`dzn_assert_disposable_path` accepts only a path *strictly below* the canonical
state directory — the state directory is never itself a disposable path, so no
cleanup can be aimed at a broad directory by treating it as its own child.

## Offline contract

All three required images must already be present:

- `mariadb:11.4`
- `wordpress:php8.3-apache`
- `wordpress:cli-php8.3`

The compose definition uses `pull_policy: never`, compose starts use
`--pull never`, and direct CLI containers use `--pull=never`.
`bin/check-cache.sh` fails before any lifecycle action if a required image is
not cached. The harness does not run `wp core download`; the cached
WordPress image supplies core to the disposable bind mount.

Copy `.env.example` to `.env` only to choose a different disposable state
directory, project name, or loopback port. It contains no real credentials.
`runtime/bin/common.sh` sources `.env` before it applies its defaults, so the
example itself carries the Schema-33 isolation names: copying it unchanged
keeps this candidate separate from any earlier Schema-32 disposable state
directory and Compose project. The fixed administrator values are local
synthetic values and email delivery is skipped.

## Evidence commands

`../tests/runtime-path-guards.sh` (also `make path-guards`) is the offline
boundary regression test for the guards above. It needs no cached image, no
container and no network: `docker` is replaced by a recording stub. It proves,
for each clause of the contract, that a symlinked worktree path, a symlinked or
aliased database/WordPress/plugin-source path, an unresolved symlink and a path
escaping the state directory are refused, that a retarget introduced after
sourcing still never reaches docker, and that genuinely disposable paths keep
working. It also re-asserts the offline transport policy (`pull_policy: never`,
`docker run --pull=never`, `dzn_compose up --pull never`) and that this harness
targets the Schema 33 the package declares: the fresh-install, retained and diff
checks all assert Schema 33, both declared upgrade rehearsals exist, and no
Schema-32 isolation name survives anywhere in `runtime/` — the guarded
copy-to-`.env` workflow is exercised and resolves to the Schema-33 state
directory, Compose project and network.

For destructive scope the same test proves that `/`, `/tmp`, `/var`, `/var/tmp`,
`/etc`, `/opt`, `/Users`, the home directory, a directory containing this
checkout and the state directory itself are refused, records the cleanup command
stream to show that `bin/destroy.sh YES` targets only validated children and
removes the state directory itself with `rmdir` (never `rm -rf`), proves that a
state directory holding unrecognised content makes the command refuse without
deleting anything, and still removes a dedicated state directory end to end.

```sh
cd runtime
bin/check-cache.sh
bin/run-fresh-install.sh
bin/run-retained-migration.sh
bin/run-schema30-to-32-rehearsal.sh
bin/run-schema31-to-32-rehearsal.sh
bin/run-pure-tests.sh
```

`bin/verify-schema33.sh` is the current stored-state check. `bin/verify-schema32.sh`
is retained as historical source evidence and is not a current acceptance route.
every one of those targets ends with. It requires the schema option *and*
`DZN_PLATFORM_SCHEMA_VERSION` to be `33`, the ledger to carry
`031_portal_facing_services_principal_authorization`,
`032_notification_communications_authority` and
`033_core_dataset_technical_prerequisites`, the six Schema-031 portal tables, the
eighteen Schema-032 notification tables and the five Schema-033 readiness tables
to be present, the eleven `platform_outbox` columns the Schema-032 slice adds to
be present, nullable and without a default, the pre-existing `platform_outbox`
`status`/`attempt_count` contract to be intact, and
`dzn_platform_portal_actions` to remain unseeded.

`bin/run-retained-migration.sh` runs the fresh install, repeats
`Migrator::maybe_upgrade()` against the same database, and requires the ledger
to be unchanged and complete: `33` recorded migrations at Schema 33.

`bin/run-pure-tests.sh` runs the guard that needs no WordPress database inside
the cached CLI image: the parse/lint sweep (`tests/static.php`) and the
Phase-2A.2-W contract guard (`tests/phase-2a2w-contract.php`, which embeds the
§15.6 refusal-versus-failure behavioural proof). The stale Phase-1
`tests/schema-contract.php` guard — it forbids the `finance` storage Schema 030
legitimately added and fails identically on authoritative `main` — is recorded
as a pre-existing failure in the integration manifest and is deliberately **not**
in that list, so the acceptance sequence is not aborted by a defect outside this
package.

`bin/schema-upgrade-rehearsal.sh <base-schema> <base-ref> <declared table>...`
is the shared in-place upgrade rehearsal. It creates the base schema from one
immutable commit, snapshots every pre-existing table's schema and data (with
only Migrator's two ledger options excluded), switches the disposable plugin
link to the candidate worktree, runs `Migrator::maybe_upgrade()`, snapshots
again, and requires:

- the only *added* tables to be exactly the declared ones;
- no pre-existing table to disappear; and
- every other pre-existing table to be byte-identical in schema and data.

The `platform_outbox` identity and rows are compared with the eleven added
Schema-032 columns projected out, because that slice only *adds* nullable
columns to that one existing table; `verify-schema33.sh` then re-asserts those
columns positively.

Two rehearsals are declared, and each ends by re-running `verify-schema33.sh`:

- `bin/run-schema30-to-32-rehearsal.sh` — Schema 30 from immutable commit
  `86d57606cabcddba15d076edfe14fb4e7257e60f`, upgraded in place by the candidate.
  The only additions must be the six Schema-031 portal tables, the eighteen
  Schema-032 notification tables, and all five Schema-033 readiness tables:
  `core_dataset_provenance`, `core_dataset_reconciliation_runs`,
  `core_dataset_reconciliation_findings`, `core_dataset_operator_commands`, and
  `core_dataset_corrections`.
- `bin/run-schema31-to-32-rehearsal.sh` — the integrated Schema 31 base from
  immutable commit `2ab0c71f5cc53ca8aa4241db0b2f7d100de65997` (the portal slice's
  tip the Schema-032 re-land was built on), upgraded in place by the candidate.
  The only additions must be the eighteen Schema-032 notification tables and
  those same five Schema-033 readiness tables; the six Schema-031 portal tables
  must be untouched.

The Phase-2A.2-S suites under `tests/` (for example
`tests/phase-2a2s-migration-runtime.php`) remain that phase's own acceptance
gates: this harness proves the Schema-032 migration's stored result and its
upgrade behaviour directly instead of re-driving another phase's runtime suite
from here. The Schema-032 S migration runtime remains a historical regression:
`run-regressions.sh s` uses immutable commit
`63f6b5b2eeaeebc194b07103d4a622d3fecf52ca` in its own disposable worktree and
database, and is never run against or used to verify the current Schema-033
candidate.

`bin/run-regressions.sh` and `bin/run-concurrency.sh` are opt-in hooks for
the pre-existing R2, V, T, and U suites. Their immutable refs and historical
schemas are recorded in
[`manifests/historical-suites.json`](manifests/historical-suites.json).
Each starts a fresh database and its own historical worktree, so those suites
retain their own exact version semantics rather than being run against Schema
32. The historical runner files are unmodified; a private disposable Docker
shim injects `--pull=never` into their worker containers.

Run `bin/destroy.sh YES` to remove only the declared disposable state and
worktrees. Destructive cleanup is scoped twice over: the state directory must
satisfy the dedicated-directory rule above, and only the children this harness
itself creates (`candidate`, `schema30`, `schema31`, `historical-<phase>`,
`mariadb`, `wordpress`, `no-pull-bin`, `historical-source`,
`rehearsal-before.json`, `rehearsal-after.json`) are deleted, each after its
physical path is re-validated. The state directory itself is then removed with
`rmdir` — never `rm -rf` — so it disappears only once nothing else remains; an
unrecognised entry makes the command refuse and delete nothing. No Theme file,
provider, credential, network call, deploy, or business-code remediation is
part of this package.
