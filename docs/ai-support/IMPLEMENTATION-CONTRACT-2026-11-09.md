# Delnavazan AI customer support — implementation contract (draft)

Status: planning only. No deployment, production configuration, or customer-number changes authorised.

## Decisions
- OpenAI API; public knowledge only in first release.
- Natural Iranian Persian and Finglish comprehension.
- Chatwoot human inbox; Meta WhatsApp Cloud API on test number until separate approval.
- Preserve existing Telnyx WhatsApp notifications and SMS fallback.
- Roster timezone: Australia/Brisbane. Coverage 07:00–21:00 and 21:00–07:00 daily; staff unassigned initially.
- Editable shifts, overlapping staff, priority/specialist routing, leave overrides, least-active-case allocation.
- Immediate push, five-minute reminder, fifteen-minute backup escalation; configurable and audited.
- No production merge/deploy.

## Repository boundaries
Create a separate `delnavazan-ai` repository for the FastAPI application, OpenAI adapter, knowledge ingestion, conversation state, Chatwoot connector, roster engine, worker and tests. This existing platform repository should only receive independently reviewed read-only public-information contracts (future AI-04). Do not write chatbot code into archived `delnavazan-theme`.

## Verified existing integration seams
- `platform/src/Public/BookingOptionsRestController.php`: GET `/wp-json/delnavazan-platform/v1/booking-options`, delegates to `PublicBookingOptionsReadService`.
- `platform/src/Integrations/Notifications/NotificationWorkerBootstrap.php`: existing notification worker scheduled every five minutes, conditional on environment/readiness. Do not reuse as time-critical escalation scheduler.
- `platform/src/Integrations/TelnyxWhatsAppAdapter.php`: existing transactional notification transport; must remain unchanged.

## AI-01: separate-service scaffold
- Python FastAPI app with `/health/live` and `/health/ready`; readiness checks external dependencies without disclosing secrets.
- Pinned dependencies, environment-driven settings, .env.example, structured redacted logs, request correlation IDs.
- PostgreSQL migrations for conversation, message, escalation, roster assignment, availability exception, notification attempt and audit event. Idempotency keys and unique constraints on provider message IDs.
- Dedicated background worker with retry/backoff, lease/recovery, dead-letter handling and graceful shutdown.
- Mock OpenAI, Chatwoot, Meta and Telnyx interfaces for offline tests; no live provider credentials in CI.
- CI: lint, type checks, unit tests, migration up/down, integration tests against ephemeral Postgres, secret scanning.
- Deployment templates only; do not provision or connect production.

## AI-02: conversation engine
- Retrieval-grounded approved public knowledge with source version and effective dates; no fabricated fees or policies.
- Human takeover state prevents AI outgoing messages; idempotent inbound/outbound processing.
- Escalation for explicit human requests, complaints, sensitive/payment disputes, low-confidence/unsupported cases.
- Private student details prohibited in phase one.
- At least 150 Persian/Finglish evaluation scenarios; prompt-injection, privacy and hallucination tests.

## Human escalation contract
- Store UTC timestamps, resolve roster in Australia/Brisbane timezone; overnight shifts cross calendar dates.
- Multiple staff per shift: eligibility and specialist routing first, least active workload second, stable tie-breaker.
- Explicit acknowledgement distinct from push delivery or message read.
- Persist state transitions and deadlines transactionally; reminder at 5 min and backup at 15 min if unacknowledged.
- On handover at 07:00/21:00, reassign unresolved unacknowledged cases; retain acknowledged ownership unless explicitly handed over.
- Staff assignment remains blocked until real staff identities and backup destinations are configured.
- No customer conversation may be silently dropped if staff roster is empty; surface unassigned urgent case in supervisor queue.
- AI pauses on human takeover, resumes only on explicit handback.

## Hosting audit gates
Check available VPS RAM/CPU/storage, connectivity, uptime, OS support, reverse proxy, backups, TLS, Postgres/Redis capacity, Chatwoot mobile push reliability, secret storage, observability and recovery. Pi is not a production dependency without explicit reliability review.

## Draft PR sequence
AI-01 scaffold (new repository), AI-02 OpenAI/public knowledge, AI-03 ingestion, AI-04 platform public API (this repository), AI-05 Chatwoot, AI-06 roster, AI-07 notification escalation, AI-08 Meta test transport.

## Release gates
No merge/deploy without explicit approval. Verify no regressions to bookings, payments, Meet provisioning, existing notification worker and Telnyx. Conduct end-to-end tests on a test WhatsApp number and real mobile devices before proposing launch.

## Audit limitations
This document is based on targeted GitHub metadata/code inspection, not a complete local repository scan or live hosting audit. No server resource measurements have been collected. No new repository has been created.
