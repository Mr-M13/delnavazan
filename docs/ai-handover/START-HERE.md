# Delnavazan — AI development handover
Snapshot prepared 2026-11-12. Read this before making changes.

## Source of truth
- Production monorepo: `Mr-M13/delnavazan`; active components `platform/`, `theme/`, `deployment/`. The root README says this is the sole code source for the live site.
- `Mr-M13/delnavazan-theme` is an older separate theme repository. Its README claims authority, conflicting with the monorepo README. **Do not develop/deploy from it without reconciling this conflict.**
- Production: delnavazan.com; WordPress on DirectAdmin Sydney; Cloudflare DNS. Verify live settings independently.
- These notes mix verified repository facts and historical conversation decisions. Never treat a discussion, PR, or test as proof of production deployment.

## First actions for a new AI
1. Read `README.md`, `deployment/README.md`, `docs/ai-handover/*`, and relevant existing platform/theme docs.
2. Fetch fresh `main`, open PRs, CI results, deployment logs, schema/migrations and production health **read-only**.
3. Check local `git status` and preserve unrelated uncommitted changes. On 2026-11-12 the local branch `feat/settings-rbac-diagnostics-20261110` had many modified platform/theme files; do not reset, stash, or overwrite them.
4. Report **verified / reported / uncertain**, plus completed, current, blockers and next actions.
5. Use isolated feature branches, tests and draft PRs. Do not merge or deploy without explicit approval.

## Project
Delnavazan is a Persian-first online music academy for students abroad and teachers mainly in Iran. It provides introductory lessons, paid terms, teacher matching, booking, portals, Meet classes, payments, notifications and administration.

## Read next
- `PROJECT-MEMORY.md`: business rules and product intent.
- `CURRENT-STATUS.md`: reported incidents, PR checkpoints and verification queue.
- `AI-AGENT-INSTRUCTIONS.md`: operating safeguards and development standards.
- `ARCHITECTURE.md`: systems and ownership boundaries.
- `DECISION-LOG.md`: important decisions and superseded proposals.

## Confidentiality
This repository may be public. Never commit tokens, passwords, private student/teacher data, production dumps, account IDs, private escalation contact details or unredacted conversations.
