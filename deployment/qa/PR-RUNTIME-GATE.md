# PR runtime gate

`.github/workflows/pr-runtime-gate.yml` is a non-deploying pull-request gate. It runs with
read-only repository contents permission, has no secrets, makes no HTTP requests, and never
touches staging or production.

## Covered

The workflow uses PHP 8.3 and runs:

1. `php -l` for every tracked PHP file.
2. Every existing top-level `platform/tests/*-contract.php` source contract.
3. `platform/tests/phase-2a3-iran-quiet-hours.php`, which is a pure PHP policy test.
4. `platform/tests/phase-student-portal-state-contract.php` when that file is present. This
   means the student state contract from PR #8 becomes part of the gate when it reaches the
   branch under test; before then the workflow states explicitly that it is absent.
5. `bash -n deployment/qa/staging-smoke.sh`.

Obsolete runs for the same PR are cancelled. This workflow deliberately uses
`pull_request`, not `pull_request_target`.

## Equivalent local commands

Use PHP 8.3 or later:

```bash
while IFS= read -r -d '' file; do
  php -l "$file"
done < <(git ls-files -z '*.php')

find platform/tests -maxdepth 1 -type f -name '*-contract.php' \
  ! -name 'phase-student-portal-state-contract.php' -print | sort | \
  xargs -r -n1 php
php platform/tests/phase-2a3-iran-quiet-hours.php
test ! -f platform/tests/phase-student-portal-state-contract.php || \
  php platform/tests/phase-student-portal-state-contract.php
bash -n deployment/qa/staging-smoke.sh
```

## Intentionally not covered

This gate does not start WordPress, MySQL, a web server, browser automation, or a disposable
runtime. Runtime, migration, concurrency, REST and authentication scripts in
`platform/tests/` remain for the disposable WordPress/MySQL verification environment.
The staging smoke runner is syntax-checked here only; its live read-only checks must run after
a staging deployment. Booking submission, payments, account writes and all deployments remain
outside CI.
