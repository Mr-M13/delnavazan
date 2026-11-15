# CI checks for agents

`check.sh` runs the checks from `.github/workflows/pr-runtime-gate.yml` on PHP 8.3.

## Native (default, no Docker)

```bash
tools/ci/check.sh doctor        # PHP version, extensions, repo state
tools/ci/check.sh lint          # php -l every tracked PHP file + git diff --check
tools/ci/check.sh contracts platform/tests/<area>-contract.php
tools/ci/check.sh services      # services/tests/run-all.sh
tools/ci/check.sh all           # lint, then services
```

Native runs require PHP 8.3 and the extensions `curl`, `mbstring`, `pdo_sqlite`, `sqlite3`,
`json`, `openssl`. `.tool-versions` pins the major/minor version for asdf/mise users. If the host
PHP does not match, `check.sh` stops instead of producing a false signal.

## Container (strict parity, optional)

```bash
tools/ci/run-in-container.sh doctor
tools/ci/run-in-container.sh lint
tools/ci/run-in-container.sh contracts platform/tests/<area>-contract.php
tools/ci/run-in-container.sh services
```

The image is built from `.devcontainer/Dockerfile` and tagged with that file's hash, so a
Dockerfile change rebuilds instead of reusing a stale image. Opening the repository in the
devcontainer gives the same environment for a whole session.

## Docker retirement status

The local agent path is Docker-free:

| Area | Local command | Docker |
|---|---|---|
| PHP lint | `tools/ci/check.sh lint` | No |
| Source contracts | `tools/ci/check.sh contracts …` | No |
| Recording services | `tools/ci/check.sh services` | No |
| Runtime suites | CI only | Yes — `mariadb:11.4`, `wordpress:php8.3-apache`, `wordpress:cli-php8.3` |

The runtime suites (`platform/runtime/bin/run-*-runtime.sh`) are the only remaining Docker
dependency. They are declared CI-only for agents: the PR runtime gate runs them, and a green
native run never substitutes for green CI. Removing Docker from the repository entirely would
mean replacing that harness with a native MariaDB/WordPress setup, or deleting the suites — both
need a Codex review and a workflow change.

## Boundaries

- Never weaken or delete a contract to make a check pass.
- These scripts do not deploy, merge, or touch production.
