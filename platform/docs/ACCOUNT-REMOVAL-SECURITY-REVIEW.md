# Account removal security review and operating boundary

## Current behavior

The Persian administrator screen accepts only a canonical Student or Teacher numeric ID. It uses a POST request, a WordPress nonce, and `manage_options`. Its service reads the canonical root, scans installed Delnavazan tables for direct owner columns, checks linked WordPress identities, posts and comments, and returns counts and blockers with a manifest digest. It performs no mutation and registers no deletion action.

The screen accepts test and other Student or Teacher accounts by canonical ID. It always reports `indirect_and_external_dependencies_unreviewed` as a blocker. The digest is an inspection fingerprint, **not** an execution token.

## Threat review

| Threat | Current control | Remaining work |
| --- | --- | --- |
| Student/Teacher access or cross-site request | Administrator capability and POST nonce | Add a separate capability for a future destructive command; fresh reauthentication and a typed confirmation. |
| Administrator-provided SQL or table name | Only a fixed kind and integer ID enter the service; table/column names come from database metadata and are syntax checked | Freeze a reviewed dependency allowlist for any destructive operation. |
| Shared WordPress user | Both canonical roots and linked posts/comments are inspected | Check multisite and all plugin user ownership; use supported WordPress deletion API only when exclusive. |
| Mistaken eligibility classification | No flag or email is trusted; execution is absent | Apply the same complete ownership and retention checks to test and other accounts. |
| Financial or consent evidence loss | Every direct related record blocks; execution is absent | Walk indirect parent chains and classify retained evidence before any deletion. |
| Remote resource orphaning | Execution is absent | Build provider-specific inventory and reconciliation for calendars, scheduling, notifications, payments and recordings. |
| Concurrent change after review | No execution exists | Recompute a complete manifest immediately before execution; bind a short-lived, atomic single-use token to its digest. |
| Partial WordPress or provider failure | No execution exists | Persist recoverable stages, retry idempotently, verify absence, and emit a minimal audit receipt only after completion. |

## Operational instructions

Administrators can inspect a canonical ID under **Delnavazan → بررسی حذف حساب**. A manifest may contain sensitive association counts and should be shared only with authorized staff. The fingerprint contains no email or name, but it is not proof of eligibility. The screen does not offer a deletion button. Use the existing archive/deactivation workflows for operational removal until the full executor is separately reviewed and approved for deployment.

No automated test in this branch uses production data. No production deletion or deployment was performed.

## Release gate

Do not merge or deploy this branch as fulfillment of permanent account removal. The required executor, complete indirect graph, retained-evidence policy, remote reconciliation, disposable database tests, and privacy receipt are outstanding. A future PR must verify all of them and independently review the destructive path.
