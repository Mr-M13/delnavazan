# Comprehensive code audit — Phase 1 baseline (2026-11-12)

**Scope and limits:** Read-only inspection of a separate snapshot of the handover branch at `251461bf5f1b9059ad5506a78044924623b35efa`. This is the **initial audit baseline**, not a claim that every line was semantically reviewed. No production, existing dirty worktree, PR, or database modifications.

## Inventory
- 2,158 tracked files; 1,581 PHP files; 14 JavaScript/TypeScript files; 11 CSS files.
- About 219,242 lines across tracked PHP, JS/TS and CSS (includes generated/vendor code where tracked).
- Repository includes PHP platform, WordPress theme, deployment scripts, acceptance/runtime tests and recorder services.
- **Syntax:** all 1,581 tracked PHP files passed `php -l` (8 parallel workers). `node --check` on tracked `*.js` produced no errors, but emitted no per-file success lines; record this as no reported JS syntax errors, not a counted validation of all JS/TS.
- No Composer executable was found in PATH; no dependency audit has been run yet.
- GitHub CI for handover commit previously passed; this does not replace end-to-end workflow verification.

## Priority integration audit
1. Map frontend fetch calls and portal forms to WordPress REST/AJAX handlers; verify route, method, authentication, nonce, input validation, response schema, error handling, timezone, and permissions for every journey.
2. Trace canonical business-state changes through worker/outbox, database, provider (Google Meet, Stripe, Telnyx) and UI refresh; verify eventual consistency and recovery paths.
3. Inspect REST controllers with `permission_callback='__return_true'`, including `RecordingServiceRestController`. **This is a review flag, not a demonstrated vulnerability**: confirm signed token/HMAC/secret verification and replay protection inside callbacks.
4. Review frontend/backend state drift: booking acceptance, teacher availability, intro survey, payments, attendance, calendar links, rescheduling, recording, notifications, and admin actions.
5. Audit dependencies and vendored LiveKit JS bundle against current recording architecture; determine whether it is actively used before proposing removal.
6. Examine duplicated policy/authority logic and tests that may encode legacy rules.

## Preliminary findings
- **Verified:** no PHP parse failures in the tracked snapshot.
- **Review flag:** publicly routable recording-service POST handlers rely on internal authorization; validate signatures, replay/expiry and rate limits before declaring safe.
- **Review flag:** a bundled LiveKit client and classroom JavaScript still exist despite the documented move to Google Meet recording; determine actual runtime use and supported classroom path before cleanup.
- **Review flag:** test runner documents an intentionally excluded historical `schema-contract.php` guard; audit the current replacement guard and avoid interpreting exclusion as an automatic defect.
- **Known issue:** PR #600 remains draft/conflicting, with a concurrency barrier fix requiring booking runtime verification.
- **No verified exploit or production regression established in this phase.**

## Next phases
- Phase 2: endpoint/permission/frontend contract matrix with file:line references, concrete mismatches and severity.
- Phase 3: business workflow state-machine and integration failure-mode audit.
- Phase 4: dependency/security/static-analysis review and targeted runtime tests.
- Phase 5: UX/accessibility/RTL/calendar consistency and duplication/refactoring opportunities.
- Phase 6: prioritised actionable report, reproducible test cases, safe draft PR plan and successor-AI handover update.

Preserve all local uncommitted changes; no automatic fixes or merges.
