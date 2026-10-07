# Read-only site QA

Run against production by default: `bash deployment/qa/site-smoke.sh`

Choose another target with `bash deployment/qa/site-smoke.sh https://site.example`. Add `true` as the second argument when that target is expected to be noindex, for example a staging hostname: `bash deployment/qa/site-smoke.sh https://staging.example.test true`.

This is read-only and never sends credentials, writes data or submits a booking.

## Coverage

- Public: HTTPS homepage, booking, enrol, login, 404, public indexing state, and available or protected WordPress and Rank Math sitemaps. The optional noindex mode requires noindex headers/meta and unavailable sitemaps.
- Redirect safety: followed redirects must finish on the supplied site origin; dashboard must redirect to that origin's `/login/`.
- Logged-out access: student and teacher portals redirect to `/login/` with a return target; operations keeps its controlled login-required page.
- REST availability: the WordPress API index advertises the Platform namespace and its public booking-options endpoint returns JSON without submitting a request.
- Visible PHP errors on every fetched HTML route.

This runner performs read-only HTTP checks only. It does not deploy code or create test identities/data. Do not use it as evidence that a synthetic data lifecycle is safe to run.
