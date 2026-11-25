# Architecture and operational boundaries

## Verified repository facts (main README, checked 2026-11-12)
- `platform/`: canonical WordPress plugin and backend/application services.
- `theme/`: user-facing WordPress frontend within the monorepo.
- `deployment/`: guarded production releases and read-only QA tooling.
- `legacy-review/`: notes for superseded plugins.
- Feature branch -> PR review/checks -> main -> protected manual deployment.

## Reported integrations (revalidate)
- WordPress, DirectAdmin Sydney, Cloudflare DNS.
- Google OAuth, Calendar, Meet; teacher OAuth callback `/wp-admin/admin-post.php?action=dzn_google_oauth_callback`.
- Stripe test/payment processing, finance statements.
- Telnyx WhatsApp and SMS; SMTP/Brevo email.
- Google Meet recording and Drive, future SpaceIn storage.
- Pi local infrastructure, with Pixelbox used for Google Photos backup.
- Planned public-facing WhatsApp AI agent using OpenAI, human escalation and Brisbane-timezone roster. Planning does not imply deployment.

## Authority
Keep booking, attendance, continuation, payment, teacher assignment, notifications and permissions under their canonical platform services. Presentation belongs in theme; avoid duplicating business logic in UI, endpoints or workers. Inspect current code for actual ownership before edits.

## Deployment and data safety
Follow `deployment/README.md` and current workflows, not historical assumptions. Never perform production writes, destructive fixture cleanup, migration changes, credential rotation, or live payment attempts without specific permission. Use synthetic test data and record the cleanup manifest.
