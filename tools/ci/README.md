# CI checks for agents

`check.sh` runs the checks from `.github/workflows/pr-runtime-gate.yml` on PHP 8.3.

## Native (default)

```bash
tools/ci/check.sh doctor        # PHP version, extensions, repo state
tools/ci/check.sh lint          # php -l every tracked PHP file + git diff --check
tools/ci/check.sh contracts platform/tests/<area>-contract.php
```

Native runs require PHP 8.3 and the extensions `curl`, `mbstring`, `pdo_sqlite`, `sqlite3`,
`json`, `openssl`. `.tool-versions` pins the major/minor version for asdf/mise users. If the host
PHP does not match, `check.sh` stops instead of producing a false signal.

## Container (strict parity, optional)

```bash
tools/ci/run-in-container.sh doctor
tools/ci/run-in-container.sh lint
tools/ci/run-in-container.sh contracts platform/tests/<area>-contract.php
```

The image is built from `.devcontainer/Dockerfile` and tagged with that file's hash, so a
Dockerfile change rebuilds instead of reusing a stale image. Opening the repository in the
devcontainer gives the same environment for a whole session.

## What still requires Docker

Only the runtime suites and their databases:

- `platform/runtime/bin/run-*-runtime.sh` pulls `mariadb:11.4`, `wordpress:php8.3-apache` and
  `wordpress:cli-php8.3`.
- `services/tests/run-all.sh` needs PHP with `pdo_sqlite`, `sqlite3`, `curl`, `mbstring` (CI runs
  it on the runner, no Docker).

Lint and source contracts no longer need Docker. Retiring Docker entirely means replacing the
runtime harness with a native MariaDB/WordPress setup or declaring those suites CI-only. Until
then, the PR runtime gate stays authoritative and a green native run never substitutes for CI.

## Boundaries

- Never weaken or delete a contract to make a check pass.
- These scripts do not deploy, merge, or touch production.
