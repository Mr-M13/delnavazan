# Delnavazan acceptance matrix (isolated, no promotion)

Status on 2026-10-12. These are **different evidence levels**, not an overall release approval.

| Area | Status | Evidence / next requirement |
| --- | --- | --- |
| Local WordPress + MariaDB | PASS | Cached images, local-only Docker Compose, repeat clean startup and safe volume teardown |
| Student, teacher, dual login routes | PASS | HTTP role-routing smoke for three synthetic identities |
| Teacher invitation cron registration | PASS | `teacher-invitation-worker-scheduling-unit.php` |
| Teacher onboarding v2 source contracts | PASS | 14 source contracts |
| Teacher invitation issuance + claim + activation | PASS | Isolated ready-teacher fixture now verifies canonical issuance, principal claim, readiness, automatic activation and cleanup; legacy manual submit removed and administrator context restored. |
| New-teacher password reset notification | NOT COVERED | Verify WordPress reset-key creation and intercepted `wp_mail` locally; no real email |
| Student account invitation / claim | NOT COVERED | Exercise canonical service and expiry/replay protections in isolated DB |
| Availability, timezone, blocked slots, booking | NOT COVERED | Synthetic schedule and booking HTTP/API lifecycle |
| Stripe checkout and reconciliation | FAIL (vault schema mismatch) | Current Schema 38 migrator verification passes and Stripe fixture prepares, but secret insertion fails: `payment_provider_secrets.cipher_version` is `varchar(16)` while `PaymentExecutionRule::CIPHER` is `sodium_secretbox_v1` (19 characters). Requires additive migration with compatibility for existing databases. No Stripe HTTP requests or charges. |
| Email / WhatsApp / SMS | BLOCKED | Provider mocks or sandbox required; never send live notifications |
| Security, admin, finance, deployment | PARTIAL | Existing source contracts; isolated runtime, permission, finance and protected deploy checks still needed |

Discovery sweep (not a supported full CI runner): 103 standalone PHP contracts passed, 22 failed, 1 skipped. Several failures explicitly require build identities or fixtures. Do not treat those 22 as confirmed application defects or the 103 as comprehensive runtime coverage. Run the repository's curated CI workflow for authoritative source checks.

## Additional offline runtime investigation

The older `platform/runtime` harness had two boot problems: it linked the monorepo root rather than the `platform/` plugin and checked for `wp-load.php` before WP-CLI readiness. Those are corrected in this branch. The Stripe offline E2E still stops at the runtime's Schema 33 assertion; no payment settlement verdict is available.

The remaining email/WhatsApp/SMS provider deliveries, branded password email interception, student claim lifecycle, booking lifecycle and finance reconciliation need purpose-built runtime fixtures; existing PHP source contracts are not substitutes for those end-to-end tests.

## Schema 38 verifier result

`platform/runtime/bin/verify-current-schema.sh` now invokes the production migrator's full current-schema validation via reflection. The fresh-install check passed. Stripe offline E2E reached its fixture and failed on a genuine vault column length mismatch; the failure must not be bypassed by changing encryption identifiers or relaxing the test.
