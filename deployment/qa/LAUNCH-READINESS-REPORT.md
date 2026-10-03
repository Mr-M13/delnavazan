# Staging launch-readiness report

## Repository status

The repository now contains the public site, booking flow, student and teacher portal surfaces, teacher onboarding, Academy Operations, staff Workspace, attendance review, communications diagnostics, commercial catalogue, teacher availability self-service and provider-integration infrastructure.

The PR runtime gate protects source-level contracts. `deployment/qa/staging-smoke.sh` is the read-only live-staging HTTP gate.

## PASS

- Pure/source-level platform tests exist under `platform/tests`.
- A read-only staging smoke suite exists and does not authenticate, mutate data or submit bookings.
- Signed-out student and teacher portal behaviour is defined as a redirect to `/login/` with a return target.
- Operations keeps a controlled signed-out login-required surface.
- Production mutation is outside this QA workflow.

## STILL REQUIRED BEFORE LAUNCH

- Run the smoke suite against the final deployed staging build and record a clean pass.
- Browser review at 375px, 768px and 1440px for public and authenticated critical paths.
- Use disposable staging identities to verify student, teacher and least-privilege staff login/routing/logout.
- Exercise teacher invitation/onboarding, approved-teacher availability maintenance and Academy Operations with disposable data.
- Exercise booking validation and timezone/availability behaviour without creating a real enrolment unless a disposable submission path is explicitly prepared.
- Verify notification/provider surfaces fail closed when external credentials or transports are unavailable.
- Confirm staging remains noindex and production remains unchanged.

## Launch blocker

Do not cut production over until the final staging smoke and authenticated/browser checks above have recorded results.
