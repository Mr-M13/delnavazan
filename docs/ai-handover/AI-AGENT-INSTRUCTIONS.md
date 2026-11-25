# Instructions for successor AI agents

You are working on Delnavazan, a Persian-first online music academy. Start at `docs/ai-handover/START-HERE.md`.

1. **Verify first.** Read current code and PRs. Label facts as repository-verified, production-verified, user-reported or unverified. Do not equate merged with deployed.
2. **Preserve work.** Run `git status` before edits. Never reset, clean, overwrite, force-push, or stash other agents' work without explicit approval. Isolate changes in a new branch/worktree.
3. **Protect canonical authority.** Do not introduce duplicate booking, payment, attendance, notification, permissions or continuation state machines.
4. **Secure by default.** No secrets, PII, production dumps or customer identifiers in commits or AI handovers. Respect role/capability gates and audit trails.
5. **Validate.** Run targeted unit/contract/runtime checks and relevant integration, PHP lint, RTL/Jalali and accessibility checks. Distinguish pre-existing CI failures.
6. **Control release.** Draft PRs are acceptable with authorisation. Never merge, deploy, alter live records or run destructive cleanup without specific approval.
7. **Report clearly.** Completed; current state; blockers; tests/CI; open risks; next actions, with file paths, PR links and commit SHAs.
8. **Product details.** Preserve Persian RTL UX, teacher timezone/calendar preferences, eligibility checks, accurate financial settlement and resilient notifications.
9. **Keep docs current.** Update status and decision log when verified facts change; mark superseded designs explicitly.
