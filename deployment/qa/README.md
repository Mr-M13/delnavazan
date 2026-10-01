# Staging QA

Run: `bash deployment/qa/staging-smoke.sh`

This is read-only and never sends credentials, writes data or submits a booking.

## Coverage

- Public: HTTPS homepage, booking, enrol, login, 404, noindex and protected WordPress and Rank Math sitemaps.
- Redirect safety: followed redirects must finish on the supplied staging origin; dashboard must redirect to that origin's `/login/`.
- Logged-out access: student, teacher and operations portal controlled login-required pages.
- Visible PHP errors on every fetched HTML route.

## Launch checklist

- [ ] Smoke runner passes after final staging deployment.
- [ ] Browser review at 375px, 768px and 1440px for homepage, booking and login.
- [ ] No console errors, failed assets, PHP errors or unexpected 404/500 responses.
- [ ] Disposable staging account verifies login, dashboard routing and logout.
- [ ] Booking instrument, time and contact validation tested without real submission.
- [ ] Staging noindex, X-Robots-Tag and both sitemap protections pass.
- [ ] Teacher onboarding and content migration have their owners' QA results.
- [ ] Production remains unchanged.
