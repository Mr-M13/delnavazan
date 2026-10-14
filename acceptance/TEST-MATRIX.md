# Delnavazan acceptance matrix (isolated, no promotion)

Status on 2026-10-13 for the bounded Teacher lifecycle candidate based on local `origin/main` commit `87f660a`. This matrix does not claim deployment, production state, recipient delivery, or a remote GitHub PR result.

These are different evidence levels, not an overall release approval. `SOURCE PASS` means provider-free PHP/source contracts passed; it does not imply database-backed runtime or recipient delivery.

| Area | Status | Evidence / next requirement |
| --- | --- | --- |
| Local WordPress + MariaDB | RUNTIME BLOCKED | This worker cannot access the Docker API socket, and the cache check reports the required `mariadb:11.4` image unavailable. The harness uses `--pull=never`, so it did not fetch an image. Re-run on a Docker-capable isolated worker with all three documented images preloaded. |
| Student, teacher, dual login routes | NOT RE-RUN | The merged acceptance foundation remains present. Re-run the HTTP role-routing smoke on the Docker-capable worker. |
| Teacher invitation cron registration | SOURCE PASS | `teacher-invitation-worker-scheduling-unit.php`. |
| Teacher invitation safe failure categories | SOURCE PASS | `teacher-invitation-issuance-failure-unit.php` verifies bounded categories and category-only logging. Dispatch failure remains a separate durable outbox outcome. |
| Teacher registration, claim, review, activation, archive/restore and re-invitation | SOURCE PASS; RUNTIME BLOCKED | `teacher-registration-lifecycle-overhaul-contract.php` and `teacher-onboarding-production-blockers-contract.php` pass. The expanded `teacher-onboarding` fixture covers principal projection, profile/availability submission, return, approve, reject, archive revocation, other-role preservation, retained lifecycle history, access-safe restore and matching-account reactivation. Re-run `make -C acceptance teacher-onboarding` with cached images; no real email. |
| Student claim through class association | SOURCE PASS; RUNTIME BLOCKED | `student-account-claim-acceptance-harness-contract.php` verifies a fixture-only workflow/template, mail interception, exact teardown, successful claim, recipient mismatch, expiry, replay, Teacher invitation/claim/readiness, booking acceptance, canonical Enrolment conversion, and initial Teacher Assignment. Run `make -C acceptance student-account-claim` on a Docker-capable worker. |
| Availability, timezone, blocked slots, booking | PARTIAL; RUNTIME BLOCKED | The integrated fixture covers one ready-Teacher recurring window and canonical booking-to-assignment path. Blocked-slot and full HTTP/API scheduling remain separate tests. |
| Stripe vault schema | SOURCE PASS; RUNTIME BLOCKED | Schema 39 additively widens `payment_provider_secrets.cipher_version` to `varchar(32)` without changing stored values or weakening cipher checks. Run the fresh/retained migration and offline Stripe fixtures against disposable MariaDB; no provider calls or charges. |
| Email / WhatsApp / SMS recipient delivery | BLOCKED | Provider mocks or explicitly authorised sandbox evidence are still required. A WordPress mail handoff is not recipient delivery. Never send live notifications from this harness. |
| Security, admin, finance, deployment | PARTIAL | Existing source contracts only; isolated runtime, permission, finance, protected-release, and authenticated post-deploy evidence remain separate gates. |

## Candidate-only offline checks

The targeted teacher-failure, student-claim harness, student-claim source-contract, migration, cashbook, and shell-syntax checks are intended to run without credentials, provider access, real mail, or real payments. The broad standalone discovery script is diagnostic only; failures from fixture-dependent contracts are not a CI verdict.

`platform/runtime/bin/verify-current-schema.sh` invokes the production migrator's full current-schema verification rather than the frozen Schema 33 checker. Runtime proof still requires the disposable database gate above.
