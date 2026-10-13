# Delnavazan acceptance matrix (isolated, no promotion)

Status on 2026-10-13 for the bounded integration candidate based on the locally recorded `origin/main` commit `0c17d926e57b7cedd33e0f229b1e838a7bce7190` and cached PR #309 head `4e32839643d61856aa699b8b2a01c867ec8977d4`. Remote refs could not be refreshed in this worker, so this matrix does not claim current GitHub PR status.

These are different evidence levels, not an overall release approval. `SOURCE PASS` means provider-free PHP/source contracts passed; it does not imply database-backed runtime or recipient delivery.

| Area | Status | Evidence / next requirement |
| --- | --- | --- |
| Local WordPress + MariaDB | RUNTIME BLOCKED | The worker sandbox cannot access Docker Desktop's Unix socket (`permission denied`). A permitted `docker pull mariadb:11.4` attempt also failed at the host credential boundary, and no local MySQL/MariaDB listener or server binary was available. Re-run on a Docker-capable isolated worker with the three documented images available. |
| Student, teacher, dual login routes | NOT RE-RUN | The merged acceptance foundation remains present. Re-run the HTTP role-routing smoke on the Docker-capable worker. |
| Teacher invitation cron registration | SOURCE PASS | `teacher-invitation-worker-scheduling-unit.php`. |
| Teacher invitation safe failure categories | SOURCE PASS | `teacher-invitation-issuance-failure-unit.php` verifies bounded categories and category-only logging. Dispatch failure remains a separate durable outbox outcome. |
| Teacher invitation issuance + claim + activation | NOT RE-RUN | Cached PR #309 contains the isolated ready-teacher fixture. Re-run `make -C acceptance teacher-onboarding`; no real email. |
| Student claim through class association | SOURCE PASS; RUNTIME BLOCKED | `student-account-claim-acceptance-harness-contract.php` verifies a fixture-only workflow/template, mail interception, exact teardown, successful claim, recipient mismatch, expiry, replay, Teacher invitation/claim/readiness, booking acceptance, canonical Enrolment conversion, and initial Teacher Assignment. Run `make -C acceptance student-account-claim` on a Docker-capable worker. |
| Availability, timezone, blocked slots, booking | PARTIAL; RUNTIME BLOCKED | The integrated fixture covers one ready-Teacher recurring window and canonical booking-to-assignment path. Blocked-slot and full HTTP/API scheduling remain separate tests. |
| Stripe vault schema | SOURCE PASS; RUNTIME BLOCKED | Schema 39 additively widens `payment_provider_secrets.cipher_version` to `varchar(32)` without changing stored values or weakening cipher checks. Run the fresh/retained migration and offline Stripe fixtures against disposable MariaDB; no provider calls or charges. |
| Email / WhatsApp / SMS recipient delivery | BLOCKED | Provider mocks or explicitly authorised sandbox evidence are still required. A WordPress mail handoff is not recipient delivery. Never send live notifications from this harness. |
| Security, admin, finance, deployment | PARTIAL | Existing source contracts only; isolated runtime, permission, finance, protected-release, and authenticated post-deploy evidence remain separate gates. |

## Candidate-only offline checks

The targeted teacher-failure, student-claim harness, student-claim source-contract, migration, cashbook, and shell-syntax checks are intended to run without credentials, provider access, real mail, or real payments. The broad standalone discovery script is diagnostic only; failures from fixture-dependent contracts are not a CI verdict.

`platform/runtime/bin/verify-current-schema.sh` invokes the production migrator's full current-schema verification rather than the frozen Schema 33 checker. Runtime proof still requires the disposable database gate above.
