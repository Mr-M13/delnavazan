# Deployment guide for successor AI agents

This is a safety-oriented navigation guide, **not** a substitute for live deployment runbooks.

## Canonical references
Read repository `deployment/README.md`, deployment workflow definitions, environment-specific runbooks and current production release evidence. Main README describes protected manual production deployment after reviewed PRs land on `main`.

## Pre-deployment evidence
- Identify exact intended commit SHA, PR approvals, tests and migrations.
- Confirm the deployed SHA and health of production and staging independently.
- Identify database backup/restore procedure, rollback commit/package and release owner.
- Validate Google OAuth/Calendar/Meet, Stripe, notification delivery, WordPress routing, student/teacher portals and RTL/Jalali flows in appropriate non-production environments.
- Never print credentials or personally identifying data into CI logs or AI transcripts.

## Release authority
Creating documentation and draft PRs does not authorise deployment. Request explicit approval for merge, production release, migrations, data changes and cleanup separately.

## Incident response
If deployment fails, do not blindly retry or run local `wp` commands on a Mac: the production WordPress path is on the remote DirectAdmin host, not necessarily local. Use read-only logs and documented host access, preserve evidence, then propose a rollback or fix for approval.

## Known historical incident
A deployment after PR #551 reportedly failed and caused downtime on 2026-11-07. This is historical, not proof of current site state. Confirm present production status.
