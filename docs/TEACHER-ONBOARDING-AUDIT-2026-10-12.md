# Teacher onboarding audit — 2026-10-12

Scope: the isolated local acceptance environment only. This audit created no production records, used only `@example.invalid` identities, made no external transport calls, and never exposes an invitation secret in output.

## Result

The invitation-led v2 lifecycle is intact. Invitation creation records a delivery intent; a local environment returns `queued` before it can call mail. A claim binds only one active generation to its frozen recipient and establishes a linked, not-ready Teacher. A complete canonical profile plus an active matching availability profile and a usable recurring rule activates the Teacher. The authenticated portal independently re-proves that same availability evidence.

The audit found and fixed a readiness-projection drift: retiring or changing a ready Teacher's only usable recurring rule could previously leave the stored onboarding row as `active / ready`. The portal gate now also requires live matching availability evidence, and ready-Teacher recurring-rule mutations recompute onboarding progress. Removing the final usable rule now produces `in_progress / not_ready` and denies the portal.

## Reproducible acceptance procedure

The Docker images named in [acceptance/README.md](../acceptance/README.md) must already be cached. From the repository root:

```sh
cd acceptance
make check-cache
make teacher-onboarding
make destroy CONFIRM=YES
```

`make teacher-onboarding` resets its disposable WordPress/MariaDB state and invokes `acceptance/fixtures/teacher-onboarding.php` through local WP-CLI. It is deliberately local-only and fails if WordPress does not report the `local` environment.

| Case | Expected evidence |
| --- | --- |
| Admin invitation creation / email dispatch | New synthetic Teacher plus one queued delivery intent. `dispatchNow()` returns `queued` locally and no mail transport is invoked. The fixture prepares a secret only as an in-process delivery substitute, then records `failed / acceptance_local_no_send` before use. |
| New-account claim and identity binding | A controlled synthetic account finalizes exactly one Teacher principal link and starts at `linked_pending / not_ready`. |
| Incomplete and profile-only onboarding | `PortalAuthenticatedReadService::teacher()` refuses before profile and then again before usable availability exists. |
| Readiness and portal gate success | Profile, matching availability profile, and requestable recurring rule produce `active / ready`; the portal read opens. |
| Readiness revocation | Retiring the final rule produces `in_progress / not_ready`; `requiresOnboarding()` becomes true and the portal read refuses. |
| Claim fail-closed cases | Unknown secret and replayed claimed secret refuse. An authenticated account with a different email cannot claim an invitation frozen to another account. |
| Admin approve / return / reject | Not an operational v2 workflow. The invitation is the approval decision and the admin screen is diagnostic; `teacher-onboarding-readiness-gate-contract.php` asserts retired approve/return/reject actions are not reachable. Legacy stored review states are reconciled into the invitation-led lifecycle on read. |

## Source-level checks

Run these without Docker:

```sh
php platform/tests/teacher-onboarding-v2-contract.php
php platform/tests/teacher-onboarding-readiness-gate-contract.php
php platform/tests/teacher-invitation-dispatch-regression-contract.php
php platform/tests/onboarding-admin-authority-contract.php
```

Executed in this worktree on 2026-10-12: all four commands above passed, along
with `teacher-onboarding-v1-contract.php`, `teacher-admin-principal-link-contract.php`,
and `teacher-portal-phase2-contract.php`; changed PHP and the acceptance fixture
also passed `php -l`, and the shell runner passed `bash -n`. The Docker acceptance
command was not executed because `make check-cache` stopped at its intended
no-pull guard: `mariadb:11.4` is not cached locally.

## Remaining product decision

There is no admin approve, return, or reject action in v2 by design. If those actions are a newly required product workflow rather than an audit of legacy behavior, they need a separate lifecycle/authority decision before implementation; silently adding them here would contradict the documented invitation-is-approval model.
