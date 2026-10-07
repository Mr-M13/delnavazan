# Staging QA

Run: `bash deployment/qa/staging-smoke.sh`

This is read-only and never sends credentials, writes data or submits a booking.

## Coverage

- Public: HTTPS homepage, booking, enrol, login, 404, noindex and protected WordPress and Rank Math sitemaps.
- Redirect safety: followed redirects must finish on the supplied staging origin; dashboard must redirect to that origin's `/login/`.
- Logged-out access: student and teacher portals redirect to `/login/` with a return target; operations keeps its controlled login-required page.
- REST availability: the WordPress API index advertises the Platform namespace and its public booking-options endpoint returns JSON without submitting a request.
- Visible PHP errors on every fetched HTML route.

## Launch checklist

- [ ] Smoke runner passes after final staging deployment.
- [ ] Deployment record identifies the exact source SHA, stamped Platform build, schema, backup directory, and pre/post runtime state.
- [ ] Browser review at 375px, 768px and 1440px for homepage, booking and login.
- [ ] No console errors, failed assets, PHP errors or unexpected 404/500 responses.
- [ ] Disposable staging accounts verify student, teacher and staff login, role routing and logout.
- [ ] Teacher onboarding is exercised from invitation claim through automatic profile-and-availability activation with disposable staging data.
- [ ] Booking instrument, timezone, availability, contact validation and summary are tested without a real enrolment/submission unless an explicitly disposable test path is used.
- [ ] Teacher canonical availability add/remove behaviour is verified with a disposable approved teacher.
- [ ] Academy Operations and staff Workspace are verified with least-privilege operator roles.
- [ ] Notification diagnostics and provider integration surfaces fail closed when credentials/transports are unavailable.
- [ ] Staging noindex, X-Robots-Tag and both sitemap protections pass.
- [ ] Production remains unchanged.
