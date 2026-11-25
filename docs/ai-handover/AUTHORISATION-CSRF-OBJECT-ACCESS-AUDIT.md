# Administrator authorisation, CSRF and object access audit — Phase 6 (2026-11-12)

**Scope:** Read-only targeted inspection of handover snapshot `251461bf`. Not an exhaustive endpoint inventory or penetration test. No work on Codex notification or DeepSeek availability/golden-hour branches.

## Verified protections
- `platform/src/Admin/Controller/DelegatedAccessController.php:15-52` gates the delegated-access UI with WordPress `manage_options`; it describes server-side capability enforcement and primary-admin protection. The role editor emits action-specific nonce fields (`:91-120`). **Follow-up:** inspect each write handler and `AccessRoleService` for self-assignment, privilege escalation and role-transition invariants.
- `platform/src/Admin/Controller/GodModeController.php:68-93` checks POST method, invokes `GodModeAuthority::require()`, validates action nonce and writes audit records before dispatch. This is a positive initial control; check all secondary export/join endpoints and the God Mode teacher handlers separately.
- `platform/src/Admin/Controller/FinanceStatementController.php:22-25,96-126` registers a `nopriv` statement-download hook **but explicitly rejects anonymous users**, checks a nonce and selects from `TeacherStatementReadService::ownStatements()` before emitting an issued/finalised document. The nopriv registration is not by itself an information disclosure.
- `platform/src/Portals/PortalCalendarExportController.php:7-52` registers anonymous dispatch only to invoke an authentication redirect. Logged-in requests verify a context-bound nonce and use owner-scoped `PortalAuthenticatedReadService` before exporting; per-user rate limiting is present.
- `platform/src/Portals/PortalCapabilityService.php:19-40` uses random handles, HMAC-bound capability tokens, expiry checks, digest storage, encrypted join targets, and a host allowlist. Minting/rotation requires `dzn_manage_portal_capabilities`; join verifies the capability in a transaction.

## Risks requiring targeted tests
**AUTH-01 (high review priority, not confirmed vulnerability):** The application has many `admin_post` actions across finance, God Mode, delegation, student identity and other workspaces. Spot checks show protections, but each action must be enumerated and tested for method, nonce, capability, ownership, replay and state-transition validation. A count of nonce calls (113 matches in Admin/Portals) does not establish full coverage.

**AUTH-02 (medium):** `PortalPublicActionController::admit()` explicitly fails open when its application rate limiter errors. Check edge/WAF abuse controls and token-guessing tests; capability verification itself remains mandatory.

**AUTH-03 (medium):** Calendar export's transient rate limiter is best-effort. Verify high-volume behaviour and ensure owner scoping remains fail-closed when a cache is unavailable.

**AUTH-04 (high review priority):** Review delegated permission changes and God Mode actions for server-side isolation, financial exclusion of test sessions, audit completeness, and export permissions. Do not infer a flaw from a powerful test feature without proving a bypass.

## Suggested test matrix
1. Anonymous, student, teacher, delegated staff and administrator for every high-impact action.
2. Valid/invalid/expired nonce, GET-versus-POST, wrong-object ownership and cross-tenant identifiers.
3. Concurrent/replayed commands, revoked roles and session changes between render and submission.
4. Capability links expired/revoked/rotated; wrong lesson and wrong purpose; tampered redirect host.
5. Verify output escaping and prepared SQL in any action that accepts a dynamic selector or free-text field.

**No confirmed exploit from these spot checks. No application changes, merges or deployments.**
