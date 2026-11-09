# Delnavazan deployment optimisation audit

## Current state

The production workflow dispatches the reusable `PR runtime gate` and then runs
the deployment package, migration, identity verification and read-only smoke
checks. The PR gate has 15 parallel jobs: one source-contract job and 14
runtime/database jobs. Several jobs independently pull the same MariaDB and
WordPress images, and the source-contract job runs the production tooling
contract plus a duplicated `production-google-oauth-diagnostic-contract.sh`
invocation.

Measured GitHub Actions runs on 8–9 November 2026 show:

| Stage | Recent measured duration |
| --- | ---: |
| PR runtime gate | 4m 19s–5m 05s |
| Production deploy after source gate | 1m 18s–2m 29s |
| Current production workflow end-to-end | 3m 58s–6m 58s |
| Longest PR job | Finance, 2m 42s–3m 48s |

The production workflow therefore repeats the full PR gate for the exact main
commit. This is the largest safe saving available without weakening required PR
checks.

## Workflow and dependency inventory

PR validation is `pr-runtime-gate.yml` plus the path-triggered
`stripe-live-mode.yml`. The runtime gate checks PHP lint/whitespace, source
contracts, deployment-tooling contracts, recording migration and behaviour,
recording choices/archive, recorder/archive/playback services, Stripe E2E,
post-intro lifecycle, attendance, student workspace, catalogue, finance,
promotion, booking release and the OAuth-scope MariaDB regression. Its 14
runtime jobs are independent and already run in parallel; each runtime job
repeats checkout and pulls disposable MariaDB/WordPress images.

The production deployment checks dispatch-from-main, exact current SHA, source
and deployment contracts, pinned SSH secrets, package completeness and
checksums, PHP syntax, database backup, forward-only migration, schema/build
identity, atomic file replacement, read-only smoke checks, independent smoke
confirmation and fail-closed maintenance activation. The production workflow
also retains serialized concurrency and the protected `production` environment.

Other workflow files are intentionally operational rather than release gates:
the three read-only production diagnostics, calendar projection dispatch, three
explicitly confirmed provisioning workflows, one confirmed calendar recovery
workflow, and Stripe live-mode guards. They were not broadened by this change.

Critical dependencies are ordered: the Platform plugin and its Migrator must be
installed before schema verification; the theme and asset checks depend on the
Platform package boundary; booking, enrolment, attendance, payment, OAuth,
Google Calendar/Meet, notification and recording paths share runtime contracts;
and production smoke must run only after migration and identity verification.

## Optimised architecture

1. Every PR and main push continues to run the existing required PR runtime
   gate. No required check is removed or made conditional.
2. The deployment workflow verifies, through the GitHub Actions API, that the
   exact requested SHA has a completed successful `PR runtime gate` run. It no
   longer runs that gate a second time.
3. The existing deployment script still packages only the exact SHA, stamps the
   build identity, verifies package and asset checksums, exports the database,
   runs the forward-only migrator, and performs the existing read-only smoke
   and fail-closed maintenance logic.
4. `deployment-risk-classifier.sh` classifies changed paths as Fast, Standard
   or Critical. Shared infrastructure, sensitive lifecycle/integration paths,
   workflow changes, and the classifier itself are Critical by default.
   Classification is advisory in this first implementation; it does not bypass
   branch protection or critical checks.

The current production package is itself reconstructed from the validated exact
SHA by `production-deploy.sh`; its archive and asset hashes are recorded in the
deployment evidence. A future artifact hand-off can reuse that deterministic
package, but this branch does not claim an unverified cross-workflow artifact
transfer.

## Safety and recoverability

Concurrency protection, protected-environment approval, exact-main-SHA checks,
SSH host pinning, package exclusion checks, PHP linting, database export,
forward-only migration, atomic file replacement, application-file recovery,
maintenance-mode fail-closed behaviour, identity/checksum verification and
read-only smoke confirmation remain in place. Incompatible database migrations
still require an operator decision; the workflow never restores a database
automatically.

## Before/after estimate

On the measured runs, removing the duplicate source gate reduces the normal
production workflow from roughly 4–7 minutes to the existing deploy and smoke
portion of roughly 1–2.5 minutes, plus a short API verification. The PR and
main-push validation time is unchanged, so branch protection retains the same
coverage. The exact post-change timing must be recorded from the first approved
workflow run.

## Remaining follow-up

The runtime jobs can later share preloaded container images or a reusable
runtime fixture, but that requires runner-level validation and is intentionally
not bundled with this safety-first change. The risk level should become an
explicit deployment approval input only after repository branch-protection
check names and the artifact hand-off have been verified by maintainers.
