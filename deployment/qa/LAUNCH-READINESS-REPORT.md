# Pre-launch readiness report

## Repository status

The repository now contains the public site, booking flow, student and teacher portal surfaces, teacher onboarding, Academy Operations, staff Workspace, attendance review, communications diagnostics, commercial catalogue, teacher availability self-service and provider-integration infrastructure.

The PR runtime gate protects source-level contracts. `deployment/qa/site-smoke.sh` performs read-only site HTTP checks.

## PASS

- Pure/source-level platform tests exist under `platform/tests`.
- A generic read-only site smoke suite exists and does not authenticate, mutate data or submit bookings.
- Signed-out student and teacher portal behaviour is defined as a redirect to `/login/` with a return target.
- Operations keeps a controlled signed-out login-required surface.
- Production mutation is outside this QA workflow.

## STILL REQUIRED BEFORE LAUNCH

- Retire the staging hosting installation only after the host, database and storage boundaries are positively identified and proven separate from production.
- Confirm production contains no genuine academy data through authenticated database inspection before enabling any synthetic data run.
- Complete the synthetic fixture manifest and exact teardown for every workflow record before production acceptance testing.
- Browser review at 375px, 768px and 1440px for public and authenticated critical paths.
- Use reserved synthetic identities to verify student, teacher and least-privilege staff login/routing/logout after the production data gate passes.
- Exercise teacher invitation/onboarding, automatically activated teacher availability maintenance and Academy Operations only after every created record has deterministic teardown coverage.
- Exercise booking validation and timezone/availability behaviour without creating a real enrolment unless a disposable submission path is explicitly prepared.
- Verify notification/provider surfaces fail closed when external credentials or transports are unavailable.
- Confirm private operational and account surfaces are not indexable and production remains unchanged by the release under review.

## Launch blocker

Do not cut production over until the final site smoke and authenticated/browser checks above have recorded results.
