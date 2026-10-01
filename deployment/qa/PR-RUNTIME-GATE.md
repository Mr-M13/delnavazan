# PR runtime gate

`.github/workflows/pr-runtime-gate.yml` is a non-deploying pull-request gate. It runs with
read-only repository contents permission, has no secrets, makes no HTTP requests, and never
touches staging or production.

## Covered

The workflow uses PHP 8.3 and runs:

1. `php -l` for every tracked PHP file.
2. `git diff --check HEAD^1 HEAD` for the proposed pull-request patch whitespace.
3. A curated list of verified, WordPress/MySQL-free Platform source contracts:
   Phase 1B, 1D–1F, 2A.0, 2A.1A–D, 2A.2A–F and the 2A.2F concurrency contract.
4. `platform/tests/phase-2a3-iran-quiet-hours.php`, which is a pure PHP policy test.
5. `platform/tests/phase-student-portal-state-contract.php` when that file is present.
6. `platform/tests/teacher-onboarding-v1-contract.php` when present. Its `offboarded`
   transition assertion checks the canonical owner, `PrincipalInvitationService`.
7. `bash -n deployment/qa/staging-smoke.sh`.

Obsolete runs for the same PR are cancelled. This workflow deliberately uses
`pull_request`, not `pull_request_target`.

The contract list is deliberately curated rather than a blanket `*-contract.php` sweep:
some historical contracts depend on a matching delivery/build identity that current `main`
does not carry. Those failures require integration/runtime remediation, not a false block on
unrelated PRs.

## Equivalent local commands

Use PHP 8.3 or later:

```bash
while IFS= read -r -d '' file; do
  php -l "$file"
done < <(git ls-files -z '*.php')

for contract in \
  platform/tests/phase-1b-contract.php \
  platform/tests/phase-1d-contract.php \
  platform/tests/phase-1e-contract.php \
  platform/tests/phase-1f-contract.php \
  platform/tests/phase-2a0-contract.php \
  platform/tests/phase-2a0-independent-review-contract.php \
  platform/tests/phase-2a1a-contract.php \
  platform/tests/phase-2a1b-contract.php \
  platform/tests/phase-2a1c-contract.php \
  platform/tests/phase-2a1d-contract.php \
  platform/tests/phase-2a2a-contract.php \
  platform/tests/phase-2a2b-contract.php \
  platform/tests/phase-2a2c-contract.php \
  platform/tests/phase-2a2d-contract.php \
  platform/tests/phase-2a2e-contract.php \
  platform/tests/phase-2a2f-concurrency-contract.php \
  platform/tests/phase-2a2f-contract.php; do
  php "$contract"
done
php platform/tests/phase-2a3-iran-quiet-hours.php
test ! -f platform/tests/phase-student-portal-state-contract.php || \
  php platform/tests/phase-student-portal-state-contract.php
test ! -f platform/tests/teacher-onboarding-v1-contract.php || \
  php platform/tests/teacher-onboarding-v1-contract.php
git diff --check HEAD^1 HEAD
bash -n deployment/qa/staging-smoke.sh
```

## Intentionally not covered

This gate does not start WordPress, MySQL, a web server, browser automation, or a disposable
runtime. Runtime, migration, concurrency, REST and authentication scripts in
`platform/tests/` remain for the disposable WordPress/MySQL verification environment.
The staging smoke runner is syntax-checked here only; its live read-only checks must run after
a staging deployment. Booking submission, payments, account writes and all deployments remain
outside CI.
