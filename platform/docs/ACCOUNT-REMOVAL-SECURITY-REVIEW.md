# Account removal security review and operating boundary

## Current behavior

The Persian administrator screen accepts only a canonical Student (`DZN-STU-...`) or Teacher (`DZN-TCH-...`) reference code. It resolves that code to the canonical numeric ID server-side. It uses a POST request, a WordPress nonce, and `manage_options`. Its service reads the canonical root, scans installed Delnavazan tables for direct owner columns, checks linked WordPress identities, posts and comments, and returns counts and blockers with a manifest digest. It performs no mutation and registers no deletion action.

The screen accepts test and other Student or Teacher accounts by canonical reference code. Directly related rows appear as an inventory, not as tasks for an administrator to delete. It always reports `indirect_and_external_dependencies_unreviewed` as a blocker because no complete executor exists. The digest is an inspection fingerprint, **not** an execution token.

## Threat review

| Threat | Current control | Remaining work |
| --- | --- | --- |
| Student/Teacher access or cross-site request | Administrator capability and POST nonce | Add a separate capability for a future destructive command; fresh reauthentication and a typed confirmation. |
| Administrator-provided SQL or table name | Only a validated canonical reference enters the service; kind and ID are resolved server-side. Table/column names come from database metadata and are syntax checked | Freeze a reviewed dependency allowlist for any destructive operation. |
| Shared WordPress user | Both canonical roots and linked posts/comments are inspected | Check multisite and all plugin user ownership; use supported WordPress deletion API only when exclusive. |
| Mistaken eligibility classification | No flag or email is trusted; execution is absent | Apply the same complete ownership and retention checks to test and other accounts. |
| Financial or consent evidence loss | Execution is absent; discovered rows are listed without suggesting manual removal | Walk indirect parent chains and classify retained evidence before any deletion. |
| Remote resource orphaning | Execution is absent | Build provider-specific inventory and reconciliation for calendars, scheduling, notifications, payments and recordings. |
| Concurrent change after review | No execution exists | Recompute a complete manifest immediately before execution; bind a short-lived, atomic single-use token to its digest. |
| Partial WordPress or provider failure | No execution exists | Persist recoverable stages, retry idempotently, verify absence, and emit a minimal audit receipt only after completion. |

## Operational instructions

Administrators can inspect a canonical reference code under **Delnavazan → Settings → General → بررسی حذف حساب**. A manifest may contain sensitive association counts and should be shared only with authorized staff. The fingerprint contains no email or name, but it is not proof of eligibility. The screen does not offer a deletion button. Use the existing archive/deactivation workflows for operational removal until the full executor is separately reviewed and approved for deployment.

## Execution protocol for the future destructive service

1. Recompute the complete dependency graph from the canonical reference. Classify every row as owned and deletable, shared, retained, or external. Refuse if any active booking, real financial activity, protected consent evidence, shared identity, or unresolved external resource is present.
2. Show the exact deletion manifest and counts. Require a fresh administrator password check, nonce and typed reference-code confirmation. Issue a short-lived token bound to the manifest digest and administrator ID; consume it atomically once.
3. Rebuild the manifest under an execution lock immediately before any mutation. Refuse if its digest or eligibility changed. Persist an execution record with stages and reconciliation state before calling WordPress hooks or external providers.
4. Cancel or remove only verified owned external resources through provider-specific adapters. Keep retryable state and never mark completion while a provider resource remains unresolved.
5. Delete explicitly classified disposable child records in dependency order. Preserve retained evidence and refuse complete deletion when preservation conflicts with account removal. Never disable foreign keys or use uncontrolled cascade deletion.
6. Delete an exclusively owned WordPress user with `wp_delete_user()` after content ownership has been resolved. Treat hook failures or partial completion as recoverable, not successful.
7. Verify canonical rows, portal access, WordPress identity, owned children and external resources are absent. Only then issue a minimal audit receipt containing reference digest, counts, actor ID, timestamps and final state, without names or email.

The current read-only manifest is **not** sufficient for step 1. No executor or execution token has been added yet.

No automated test in this branch uses production data. No production deletion or deployment was performed.

## Release gate

Do not merge or deploy this branch as fulfillment of permanent account removal. The required executor, complete indirect graph, retained-evidence policy, remote reconciliation, disposable database tests, and privacy receipt are outstanding. A future PR must verify all of them and independently review the destructive path.
