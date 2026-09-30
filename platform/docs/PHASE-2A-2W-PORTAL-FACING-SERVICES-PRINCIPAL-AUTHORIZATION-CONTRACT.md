# Phase 2A.2-W — Portal-Facing Services: Session-to-Core-Principal Resolution, Object-Level Authorization, Stable Read Models & Purpose-Bound Signed Public Join/Absence Capabilities (Schema 031)

**Status:** implementation contract with a current-source reconciliation. The Schema-031 access-artefact
boundary and a capability-attributed Phase-P absence seam are present in current `main`; Theme
integration, external publication, deployment, production access and public cutover are not thereby
authorised or evidenced.
**Schema:** 031 (`031_portal_facing_services_principal_authorization`).
**Current package:** local `main` declares `DZN_PLATFORM_SCHEMA_VERSION` = `33` and build
`phase2a2s-notification-communications-authority-20260924.1`. The W slice remains the historical
Schema-031 candidate stamped `phase2a2w-portal-facing-services-20260927.2`; Schema 32 is historical
Notifications S storage, and Schema 33 adds bounded readiness storage only.
**Current reviewed source baseline:** local `main` at
`5bd376881769b15a27f5b20a5c02cef5bad0397c` (tree `f6dac0bd978e62ecef3247a5f64e8957b1b96d5a`), with
the reviewed W correction, the finalized operational-readiness runtime correction, this docs/portal
correction and the additive Schema-32 Notifications S re-land merged as explicit non-fast-forward
merges. `4ccd43c62d1fd8311cce75bac55681b81b85eda9` atop `origin/main`
`6673e7bb6b0dc267d455b61220c3da77eb1b4356` is a historical review snapshot, not the current HEAD or
checkout. The historical base statement in §0.1 is retained as provenance.
**Owner brief this document answers:** *"Prepare Phase 2A.2-W / Schema 031 contract for
Student/Teacher/Admin portal-facing services, session-to-Core-principal resolution, object-level
authorization, stable read models and purpose-bound signed public Join/Absence capabilities. All
business authority must remain in owning modules. No Theme implementation, external publication,
production or deployment."*

## 0. Current implementation-state reconciliation — read this before anything else

This section supersedes only the stale implementation-state assertions in the pre-implementation
reconciliation that follows. It does not rewrite the historical 2026-09-26 inspection or convert an
implementation presence into an approval, deployment or product decision.

| Current source fact | Evidence |
| --- | --- |
| Schema and build | `delnavazan-platform.php` declares `DZN_PLATFORM_SCHEMA_VERSION = '33'` and build `phase2a2s-notification-communications-authority-20260924.1`. Schema 31 is the W slice's historical identity (`phase2a2w-portal-facing-services-20260927.2`); Schema 32 is historical Notifications S storage. |
| Migration and capability marker | `Migrator` installs and verifies historical `031_portal_facing_services_principal_authorization` and `032_notification_communications_authority`, then current `033_core_dataset_technical_prerequisites`. Migration 033 verifies its five readiness tables before recording its ledger entry and before advancing the schema option; it does not change 031 portal capability markers. |
| Portal surface | `src/Portals/` exists, the owner ports are configured during `plugins_loaded`, and `PortalPublicActionController` is registered on `rest_api_init`. |
| Public routes | Current source registers the three routes listed in [the implementation-derived registry](PORTAL-AUTHORIZATION-REGISTRY.md). Each is separately gated by an exact option-value check. |
| Absence handoff | `PortalPublicActionService` calls the configured attendance port, whose Core implementation calls `CanonicalAttendanceIntakeService::submitCapabilityClaim()`. It is not deferred in current source. |
| Not implemented as an HTTP surface | There is no current authenticated Student/Teacher portal REST controller. The current registered W REST surface is the three public capability routes only. |

Sections describing the original contract's proposed directory layout, declared future read models or
pre-implementation inspection remain historical contract/provenance rather than a claim that those exact
paths or all planned surfaces exist. The registry is the canonical source-derived implementation inventory.

## 0.1 Historical schema-number reconciliation — preserved provenance

The owner brief says **Schema 031**. In this repository the highest declared migration is **030**
(`030_finance_payability_rate_statement_authority`, owned by the Phase 2A.2-U candidate whose tree this
workspace materialises), the authoritative `main` merge is Schema 25 (Phase 2A.2-R1), and
`DZN_PLATFORM_SCHEMA_VERSION` in this tree is `30`. Schema 031 is **not declared anywhere** in `src/`,
`tests/` or `docs/` in this checkout, and no migration identifier beginning `031_` exists.

Therefore this contract targets:

| Field | Value |
| --- | --- |
| Phase | 2A.2-W — Portal-Facing Services (principal resolution, object-level authorization, stable read models, signed public capabilities) |
| Schema | **031** |
| Migration identifier | **`031_portal_facing_services_principal_authorization`** |
| Previous schema | 030 (Phase 2A.2-U candidate tree) |
| Next free identifier after this phase | 032 |

Two consequences follow and are binding on the implementation candidate:

1. **031 is a *next* free identifier, not a re-assignment.** No existing migration identifier is
   deleted, renumbered or re-applied, and no committed migration ledger entry changes meaning. The
   number is confirmed free in §1; confirming it against the owner's authoritative ledger is
   prerequisite 1 of §20.
2. **The candidate's base must be recorded, not assumed.** Phase W verifies Phase-P, Phase-O,
   Phase-N and Phase-M structures that exist in this tree only because the Phase-R1/R2/T/U/V
   candidates are materialised above the authoritative Schema 25. A W candidate reviewed against a
   base that does not contain Schema 030 would verify tables no migration in that base creates. Base
   selection is prerequisite 2 of §20 and nothing else in this contract depends on it.

## 1. Verified authoritative state

Historical read-only inspection at contract authoring, 2026-09-26. No file was written by that
inspection. Its values describe the pre-W checkout and are superseded for current implementation-state
questions by §0 and the portal authorization registry.

| Fact | Verified value (this checkout) |
| --- | --- |
| Repository root | `git rev-parse --show-toplevel` = this workspace |
| Branch / remote | `main...origin/main` (`origin` = `https://github.com/Mr-M13/delnavazan-platform.git`); `git status --short` empty |
| HEAD | `773e13e2bf148f6e9ff250b58c7bd6ee4b135f48`, tree `00adddb809dcf183b88346378db57f07b3158f5c` |
| Nearest history | five `PLATFORM-U-FINANCE-IMPLEMENTATION: host-materialized candidate` commits above the Phase-T candidate commits |
| Platform version | `DZN_PLATFORM_VERSION` = `0.1.0` |
| Schema / build | `DZN_PLATFORM_SCHEMA_VERSION` = `30`; `DZN_PLATFORM_BUILD_ID` = `phase2a2u-finance-payability-rate-statement-20260925.1` |
| Migration ledger | 001–030 declared in `Migrator::maybe_upgrade()`, `verify_current_schema()` and the required list; latest `030_finance_payability_rate_statement_authority`; **no `031_*` identifier exists** |
| Capability markers today | base `dzn_platform_capability_version` = `2a2n`, plus `_2a2o`, `_2a2p`, `_2a2q`, `_2a2r`, `_2a2v`, `_2a2t`, `_2a2u` (there is no `_2a2w` marker) |
| Principal identity storage | `dzn_teacher_principal_links` (legacy singleton `UNIQUE teacher_id` / `wordpress_user_id`, with `status` and revocation columns but **no `active_slot`**) and `dzn_student_principal_links` (with `status`, `active_slot` and supersession columns), plus `dzn_student_acceptance_authority_grants` (acceptance and guardian authority), `dzn_student_account_invitations` and `dzn_account_claim_attempts` (account claim) |
| Canonical facts a portal may read | The PII-minimised §8 projection of Phase-M Lesson, Phase-N applicable schedule, Phase-O effective delivery, Phase-P attendance summary, Phase-J current Assignment, Phase-M0 Enrolment and Phase-L Term facts, only through §10.0's owner-implemented ports; no Phase-W class reads those tables directly |
| Existing owning-module seams inspected | `CanonicalLessonScheduleReadService` requires `dzn_manage_canonical_lesson_schedules`; `CanonicalLessonDeliveryReadService` requires `dzn_manage_canonical_lesson_delivery`; `CanonicalAttendanceReadService` requires `dzn_view_canonical_attendance_review`; and `TeacherAssignmentReadService` requires `dzn_manage_teacher_assignments`. Those administrator/reviewer seams are **not callable by a Student or Teacher portal** and are not widened by Phase W. `CanonicalAttendanceIntakeService::submitClaim()` also requires a session-backed claimant today; §10.1 records its separately bounded public-capability extension. |
| Narrow owning-module seams Phase W requires | The four read-only, owner-implemented ports of §10.0 — `TeacherAssignmentPortalReadPort`, `CanonicalLessonSchedulePortalReadPort`, `CanonicalLessonDeliveryPortalReadPort` and `CanonicalAttendancePortalReadPort` — plus §10.1's Phase-P capability-attributed absence intake. No port grants a WordPress capability or permits a portal to read an owning table directly. |
| Existing public surface | exactly two `register_rest_route()` calls: `delnavazan-platform/v1/booking-requests` (`src/Public/BookingRequestRestController.php`, with `src/Public/BookingRequestRateLimiter.php`) and the Stripe webhook (`src/Integrations/Payment/Stripe/StripeWebhookController.php`) |
| Portal storage today | **none** — no `portal_*` table, class, constant, capability, option or route exists anywhere under `src/` |
| `src/Portals/` today | **does not exist**; `src/` holds `Admin/`, `Core/`, `Integrations/`, `Public/` |
| Join-target storage today | Phase-V `dzn_provider_meeting_mappings` stores `conference_digest` and `join_uri_digest` **only**, and `ProviderIntegrationReadService::lessonIntegrations()` returns mapping metadata and **no** join URI. Phase V's own deferral list names "no public join action or signed capability" |
| Runtime availability | **PHP is absent in this environment** (`php: command not found`); no disposable WordPress + MariaDB runtime is available to this contract-authoring session |
| Surface size | 43 documents under `docs/`; 261 PHP files under `src/`; 218 entries under `tests/` (198 PHP files, 36 of them contract suites) |

Five consequences are structural, not incidental:

1. Phase W introduces the **first Platform surface that a person with no Platform capability may reach
   on purpose** — a purpose-bound signed public capability. It must therefore be the *narrowest*
   surface in the Platform: it grants exactly one declared action on exactly one declared Lesson and
   never a business decision (§7, §9, §22).
2. Phase W **owns no business authority**. Every portal command delegates to the owning module's own
   application service, and every portal read hydrates through the subject-scoped owner ports and
   validators of §10.0 (§2, §10).
3. Phase W **adds no column to any existing table**, including `platform_audit_events` and
   `platform_outbox`, which it never creates, alters or touches (§13).
4. Phase W **writes no `platform_outbox` row at all**, makes **no provider call**, holds **no
   credential** and decrypts **no provider secret** (§15.7, §17, §22).
5. Phase W introduces **no Theme code, template, stylesheet, script, shortcode, block or asset**, and
   performs no external publication of any kind — in particular it never sends, mails, notifies or
   publishes a capability link (§9.10, §22).

## 2. What this phase owns

### In scope

1. **Session-to-Core-principal resolution** — one read-only resolution from the current WordPress
   session to exactly one Core principal (`administrator`, `teacher`, `student`, `guardian`), failing
   closed on zero or ambiguous authority and using only the Platform's own identity facts (§6).
2. **Object-level authorization** — one declared, per-call check that the exact Core record a portal
   surface names belongs to the resolved principal, proved through the narrow owning-module portal
   ports of §10.0 and never from a request parameter, an email, a display name, a reference code or a
   provider mapping (§7).
3. **Stable read models** — versioned, PII-minimised, fail-closed projections for the Student and
   Teacher portals, hydrated from the scoped owner ports of §10.0, with a declared field set and a
   declared stability rule (§8).
4. **Purpose-bound signed public Join/Absence capabilities** — the capability registry, its sealing,
   verification, rotation, revocation, redemption evidence and safe non-enumerating responses, for
   exactly two purposes (§9).
5. **Delegation seams** — the declared, bounded way a verified capability reaches the owning authority
   that decides the business consequence (§10).
6. **An administrator surface** for capability administration and diagnostics, under the existing
   Platform menu (§11, §12).
7. **Schema 031** — additive storage for the above with its fail-closed verifier (§13).

### Out of scope (this phase creates none of them)

- any business state transition: Lesson, schedule, delivery outcome, attendance case, evidence or
  decision, academy obligation, Enrolment, Term, Teacher Assignment, commercial, recurring, payment,
  provider integration, finance or notification state;
- any Theme, template, block, shortcode, stylesheet, script, asset, menu-page redesign or visual
  identity work, and any change to the existing live public pages;
- any external publication, distribution, sending, mailing, messaging or notification of a capability
  link, and any `platform_outbox` write (§17);
- any provider API call, OAuth flow, webhook, credential use, credential decryption, Google, Meet,
  Zoom or Teams client, calendar mutation, or meet join/leave evidence ingestion;
- any Amelia session bridge, Amelia query, Amelia write or Amelia removal; the live Amelia Employee
  and Customer panels remain the operational portal until a later, separately authorised cutover;
- any student payment, invoice, statement, payability, refund or finance self-service (Phase U remains
  administrator-only and this phase exposes no finance field to any portal principal);
- any notification template, translation, RTL rendering, channel or delivery lifecycle (Phase S);
- any self-service that changes commercial position, acceptance, capacity, assignment or renewal;
- deployment, production access, production data, production cutover and any migration of live portal
  traffic.

### Authority-preservation rule

Phase W is an **access and presentation layer**, never a second source of anything. Every fact a portal
shows is read from the module that owns it; every command a portal offers is executed by the module
that owns it; every refusal is recorded without changing anything. Where an owning aggregate is
missing, ambiguous, contradictory or corrupt, Phase W **fails closed, records the blocker and shows
nothing** — it never falls back to a legacy panel value, a provider payload, a cache, a request
parameter or a computed guess (W-D2, W-D8, W-D17).

## 3. Preserved invariants (must not regress)

Phase W is additive and must not weaken a single existing invariant. In particular:

- **Identity is never inferred.** An email, phone, display name, username, Amelia id, provider
  subject, reference code or WordPress user meta never establishes a Teacher or Student identity
  (ARCHITECTURE §4, MODULE-BOUNDARIES §8).
- **A mapping is not an authorization.** An external or legacy mapping, or a manual Platform mapping,
  never grants portal access and never authorizes a public action (DATA-MODEL §19).
- **Identifiers are not credentials.** A numeric id, an opaque UID and a human reference code are
  identifiers only (PRODUCT-DECISIONS §8).
- **Public actions use independent opaque or signed, purpose-bound capabilities**
  (PRODUCT-DECISIONS §8, DATA-MODEL §19, SECURITY §8). Phase W implements exactly that and nothing
  broader.
- **Attendance truth is not portal truth.** A redeemed Join action records evidence and proves nothing
  about participation; a submitted Absence claim is evidence and never settles, completes, cancels or
  creates entitlement (SECURITY §8, Phase P non-authority list).
- **Delivery, attendance, remedy and archive remain separate** (Phase O), and payability remains a
  Finance decision derived from them (Phase U); Phase W reads and cannot rewrite any of it.
- **Capacity succession is unchanged:** the Phase-Q hold, R1 claim and Phase-N schedule chain is never
  touched by a portal command, and Phase W holds no capacity lock.
- **An archive is reversible metadata, never an erasure** (Phase 0 archive decision): archiving a
  Lesson makes its capabilities refuse, and silently removes nothing already recorded.
- **Provider neutrality of storage is preserved:** Phase W adds no column to any `provider_*`,
  `integration_*`, `commercial_*`, `recurring_*`, `payment_*`, `finance_*` or `canonical_*` table.
- **Phase-P attestation stays Phase-P.** A portal never becomes an attendance adjudication surface,
  never closes a case, never records a settlement and never writes a Phase-P decision row.
- **The live academy is not disturbed.** No live page, Theme option, menu, notification, provider
  connection or Amelia record is read or written by this phase.

## 4. Locked Phase W decisions (W-D1 … W-D25)

| Decision | Locked meaning |
| --- | --- |
| **W-D1 no business authority** | No Phase-W code path may create, update, delete, complete, cancel, reschedule, archive, settle or price any Lesson, schedule version, delivery outcome, attendance case, evidence or decision, academy obligation, Enrolment, Term, Teacher Assignment, provider mapping, commercial, recurring, payment or finance row, or notification record. Every portal command is a delegation to the owning module's own application service, which performs its own authorization, transaction, idempotency and validation. Portal storage holds access artefacts only. |
| **W-D2 the principal is resolved, never asserted** | Every authenticated portal call begins from the current WordPress session (a user id) and resolves to exactly one Core principal through `PortalPrincipalResolver`. No request parameter, body field, header, cookie, local storage, referrer or client-supplied claim participates. Zero authority refuses `portal_principal_unresolved`; more than one candidate for the **surface's own** required kind refuses `portal_principal_ambiguous`; a kind the surface does not accept refuses `portal_principal_kind_not_permitted`. |
| **W-D3 resolution writes nothing** | `PortalPrincipalResolver` is read-only and holds no durable cache. It issues no SQL write, sets no option, stores no transient as authority, and cannot be the source of a later decision. Its answer is used only inside the call that asked for it. |
| **W-D4 object-level authorization is per call and server-side** | Every portal read and every portal command proves, inside the request, that the exact record it names belongs to the resolved principal. For Assignment, Schedule, Delivery and Attendance that proof is performed by the narrow owning-module portal-read ports of §10.0, not by calling the existing administrator-only read services, trusting a Phase-W assertion or querying an owning table. A capability, a role, a menu page, a hidden control, a browser flag or a previously successful call never substitutes for the check. |
| **W-D5 read models are versioned projections, not new truth** | A portal read model carries a declared version (`portal_lesson_v1`, `portal_enrolment_v1`, `portal_principal_v1`), is produced by a named read class, hydrates only through the owning-module portal-read ports and validators declared in §10.0, and fails closed with `portal_upstream_aggregate_invalid` rather than degrading to partial, stale or defaulted data. Within a version, fields may only be added; changing or removing a field requires a new version constant. |
| **W-D6 PII minimisation is a correctness rule, not a preference** | No portal read model or response may carry an email address, phone number, postal address, national or tax identifier, bank, IBAN or card detail, provider subject, reference or mapping id, provider credential, secret, sealed value, raw command key, raw evidence payload, another person's data, a teacher's contact details to a student, or any finance amount. `email`, `phone`, `provider_*`, `secret`, `ciphertext`, `nonce`, `iban` and `card` must not appear as a portal read-model field name. |
| **W-D7 the capability is access authority only** | A verified capability authorizes exactly one declared purpose on exactly one declared Lesson. It is not identity, not attendance, not completion, not entitlement, not a commercial instrument and not a reusable session. It never establishes a WordPress user, never creates a principal link, never grants a Platform capability and never survives a rotation. |
| **W-D8 the capability is one-way stored and keyed-verified** | The public handle is high-entropy random material whose **keyed digest** is the only stored lookup key; the presented token's keyed digest is recomputed and compared with `hash_equals()` against the recorded digest. No plaintext handle and no plaintext token is ever written to storage, logged, exported or echoed. |
| **W-D9 the capability is bound to the canonical occurrence it names** | Every capability records the exact `lesson_id` **and** the exact `schedule_version_id` it was minted against, plus its `purpose` and `generation`. Verification requires that the named schedule version is still the Lesson's applicable version; a reschedule therefore makes the old capability refuse `portal_capability_stale_schedule` even before any administrative rotation. |
| **W-D10 rotation, not mutation** | Authority is never widened and history is never rewritten. A rotation allocates the next `generation` for `(lesson, purpose)`, revokes the predecessor with its reason code and supersession link, and is the only way a new handle comes into existence for a Lesson and purpose. A capability row's `expires_at`, `generation`, `handle_digest`, `token_digest`, `lesson_id`, `schedule_version_id` and `purpose` are immutable. |
| **W-D11 expiry is absolute and stored** | Every capability carries an absolute UTC `expires_at` recorded at mint. Expiry is never extended, never recomputed from "now", and never inferred from a schedule time; a request at or after `expires_at` refuses `portal_capability_expired`. |
| **W-D12 the Join action is evidence and a redirect** | A redeemed Join capability (a) appends one redemption evidence row, then (b) redirects `302` to the capability's own declared, sealed, allowlisted HTTPS join target, or refuses `portal_join_target_not_allowlisted` or `portal_join_target_unavailable`. It writes no delivery, attendance, participation or meet/leave fact anywhere, and it never proves attendance. Join redemption is repeatable while the capability is `active`. |
| **W-D13 the Absence action is two-step, evidence-only and single-submission** | `GET` renders a confirmation page and changes nothing; `POST` requires the one-time confirmation token and is the only state-changing public action. On confirm, Phase W records its own redemption evidence and delegates the claim to the owning attendance authority (§10.1), which retains every business decision. A capability confirmed once is `consumed` and refuses `portal_capability_consumed`; a capability whose owning authority reports a final attended outcome refuses `portal_absence_outcome_final`; a claim outside the owner's declared window refuses `portal_absence_window_closed`. |
| **W-D14 the portal never fabricates a session** | A public capability carries no WordPress session and Phase W never creates, impersonates or elevates one: it never calls `wp_set_current_user()`, never mints a user token and never runs the owning service as a synthetic administrator. The delegated claim is identified by the capability's own proof, and the owning authority's recorded attribution member for that path is `public_capability_on_behalf` (§10.1, §16.2). |
| **W-D15 no distribution** | Phase W never sends, mails, messages, notifies, prints, publishes, indexes or externally exposes a capability link, and never writes a `platform_outbox` row. The plaintext link is returned exactly once, to the authenticated administrator whose mint command created it, in that command's own response. Delivering a link to a Student, Teacher or guardian is a **later, separately authorised decision** and is not enabled by this phase (§9.10, §17, §21). |
| **W-D16 public actions are disabled until an owner decision enables them** | The three public routes are registered but refuse `portal_route_disabled` unless the recorded option `dzn_platform_portal_actions` is exactly `enabled`. Migration 031 never writes that option, so a fresh install and every upgrade leave public actions **disabled**. Enabling them is an explicit owner decision (§21) and is an external-publication event outside this contract. |
| **W-D17 fail closed and visible** | Every refusal is a durable, reason-coded record — a refused `portal_public_capability_commands` row for an administrative command, a `portal_public_action_events` refusal row for a public action, and a `portal_access_denials` row for a principal or object-level refusal — and no refusal is silently swallowed, coerced into a success, retried into a different answer or hidden behind a generic page. Unknown, expired, revoked, rotated and never-issued handles produce the **same** non-enumerating outward shape (§9.9). |
| **W-D18 exactly one serialisation root, chosen from the Lesson** | Every capability write and every redemption takes the **per-Lesson** `portal_lesson_capability_roots` row first (lazily created, `UNIQUE lesson_id`), so mint, rotate, revoke, verify-and-claim and every redemption of one Lesson are totally ordered and two Lessons never contend. The fixed order of §15.2 is never inverted, and no portal code path takes a Phase-L, M, M0, N, O, P, Q, R1, R2, T, U or V row lock while holding a portal root. |
| **W-D19 delegation happens outside the portal transaction** | Phase W verifies and claims a redemption in its own committed transaction, **then** invokes the owning module (which owns its own transaction, roots and idempotency), and **then** records the handoff outcome in a second committed transaction. A crash between them leaves a durable `confirmed_submitting` claim that the exact replay of the same redemption key converges; Phase W never reports success before the owning module's own durable fact exists, and never leaves a claim permanently unresolvable. |
| **W-D20 the Join target is a sealed, capability-scoped, revocable portal artefact** | The declared safe join resource is stored **sealed** with the Platform's existing authenticated-encryption convention under a Phase-W key class (`dzn_portal_join_target`, never a provider secret class), with `key_version` and `cipher_version`, never autoloaded, never logged and never returned except by a verified redemption of that exact capability. It is a copy for one redirect, never conference truth: Phase V's mapping remains the provider-side fact, and the target is rotated or revoked with the canonical facts. |
| **W-D21 no Theme, no template, no asset** | Phase W writes no file under a Theme, no template, no block, no shortcode, no stylesheet, no script and no image. The only rendering Phase W performs is the plain, server-rendered confirmation and refusal HTML produced inside its own controller, escaping every value for its exact context, with no Theme template, no global shortcode execution and no unrelated content filter. Presentation design, RTL and Persian copy for a *portal* remain a later, separately authorised Theme and design slice. |
| **W-D22 least privilege, and no student role** | Two administrator-only capabilities and one administrator plus Teacher capability exist (§14.2). No WordPress role is created by this phase. Student portal authority derives from an **active principal link** plus the object-level check, never from a WordPress capability, because no student role exists and none may be granted finance, review, delivery, admission or scheduling authority. |
| **W-D23 the operator sees the blockers** | Capability state, generation, expiry, rotation reason, redemption outcomes and every refusal code are visible to `dzn_view_portal_capabilities` through the read surface and the §12 diagnostics. A refusal that cannot be seen cannot be operated, so there is no silent failure mode. |
| **W-D24 additive, repeat-safe and reversible** | Schema 031 is additive and repeat-safe from Schema 30; it backfills nothing, infers no capability, mints nothing at migration time and writes no option other than its own capability marker. Every write path is a declared command on `portal_*` tables plus insert-only digest-only `platform_audit_events` evidence. Removing the phase leaves the canonical Platform untouched. |
| **W-D25 owner-gated portal reads, not capability widening** | The existing Schedule, Delivery, Attendance and Assignment read services remain protected by their administrator/reviewer capabilities. Their owners add only the four internal, read-only portal ports of §10.0. Each port accepts a typed request-local subject issued by the resolver or capability verifier, re-proves the exact subject-to-object relationship from owner-approved facts, runs the owning validator and returns only the §8 field subset. The ports expose no unrestricted lookup, recent/list-all operation, history, raw evidence, Teacher-only note or review data; they write nothing and cannot be invoked merely because the caller holds `dzn_view_own_portal_schedule`. |

## 5. Module layout and locked vocabulary

### 5.0 Current implementation layout

The table in §5.1 is the original proposed layout, preserved as contract provenance. Current source does
not materialise that exact layout: the W classes are directly under `src/Portals/`; the public controller
is `src/Portals/PortalPublicActionController.php`; the public best-effort abuse control ships as the
existing `src/Portals/PortalRateLimiter.php`, which that controller invokes on every public request before
the option gate, and not at the §5.1 `src/Public/PortalPublicRateLimiter.php` path; the owner-read
implementations are consolidated in `src/Core/Application/PortalOwnerReadPorts.php`; and no
`src/Portals/Application/`, `src/Portals/Integrity/` or
`src/Core/Infrastructure/Repository/Portal*Repository.php` directory exists.
Current source also has no authenticated portal REST controller. Its only W REST registrations are the
three public capability routes. This is an implementation-state correction, not a decision to expand or
approve a surface; [the registry](PORTAL-AUTHORIZATION-REGISTRY.md) is authoritative for the current
inventory.

### 5.1 Layout

**Historical proposed layout (not a current-path assertion).** The contract proposed one new module
directory and three public route registrations. It introduced no provider or Theme surface:

| Path | Contents |
| --- | --- |
| `src/Portals/PortalRule.php` | Locked constants and vocabularies: `PURPOSES`, `CAPABILITY_STATES`, `CAPABILITY_EVENT_TYPES`, `ACTION_STATES`, `HANDOFF_TARGETS`, `SURFACES`, `PRINCIPAL_KINDS`, `READ_MODEL_VERSIONS`, `REASON_CODES`, `EXCEPTION_REASON_CODES`, `OPERATOR_REASON_CODES`, `SAFE_JOIN_HOSTS`, `PUBLIC_ACTION_OPTION`, `CAPABILITY_BINDING_VERSION`, `HANDLE_ENTROPY_BYTES`, `MAX_CAPABILITY_TTL_SECONDS`, `JOIN_ACTION_MODE`, `ABSENCE_ACTION_MODE` |
| `src/Portals/Application/` | `PortalPrincipalResolver`, `PortalAccessPolicy`, `PortalCapabilityService` (mint, rotate, revoke), `PortalCapabilityVerifier`, `PortalPublicActionService` (join and absence redemption), `PortalHandoffService` (the §10 delegation) |
| `src/Portals/Application/Read/` | `PortalStudentLessonReadModel`, `PortalStudentEnrolmentReadModel`, `PortalTeacherLessonReadModel`, `PortalTeacherAssignmentReadModel`, `PortalPrincipalReadModel` — one class per declared read-model version |
| owning Core application modules | The four internal read-only ports of §10.0. They live beside the authority and validator they protect; they are not Phase-W repositories, controllers or alternate sources of truth. |
| `src/Portals/Integrity/` | `PortalCapabilityIntegrity`, `PortalActionIntegrity`, `PortalReadModelIntegrity` — pure, non-mutating, repository-hydrated validators |
| `src/Core/Infrastructure/Repository/Portal*Repository.php` | `PortalCapabilityRootRepository`, `PortalCapabilityRepository`, `PortalActionRepository`, `PortalAccessDenialRepository` — each with the established `begin()` / `commit()` / `rollback()` wrapper, named-index duplicate arbitration and insert-only methods for append-only tables |
| `src/Public/PortalPublicActionController.php` | The three registered public routes (§9.6, §9.7) — verification, safe responses, and nothing else |
| `src/Portals/PortalRateLimiter.php` | Best-effort abuse control using only an owner-supplied budget; the cache contract is fail-open and Phase W declares no threshold or keying decision |
| `src/Admin/Controller/PortalCapabilityController.php` | The capability administration and diagnostics screen under the existing Platform menu, registered like `FinancePolicyController` (§11) |

There is deliberately **no** `src/Portals/Theme/`, **no** `src/Portals/templates/`, **no**
`src/Portals/Assets/` and **no** `src/Integrations/Portal/`. Presentation belongs to a later Theme
slice; portals have no provider and no transport.

### 5.2 Locked vocabularies

| Vocabulary | Locked members |
| --- | --- |
| Capability purpose (`PortalRule::PURPOSES`) | `lesson_join`, `lesson_absence` — the only two members in this phase |
| Capability state (`PortalRule::CAPABILITY_STATES`) | `active`, `consumed`, `revoked`, `superseded` — the member records which row is the *live, usable* row of `(lesson, purpose)` (`active_slot = 1`); it never substitutes for the `expires_at` check |
| Capability lifecycle event (`PortalRule::CAPABILITY_EVENT_TYPES`) | `minted`, `rotated`, `revoked`, `consumed` |
| Public action state (`PortalRule::ACTION_STATES`) | `confirmed_submitting`, `delegating`, `submitted`, `redirected`, `refused` — `delegating` is the durable single-delegator lease of §15.3 |
| Handoff target (`PortalRule::HANDOFF_TARGETS`) | `canonical_attendance_evidence` — the only member in this phase; a Join redemption has `handoff_target = NULL` |
| Portal surface (`PortalRule::SURFACES`) | `portal_public_join`, `portal_public_absence`, `portal_student_lesson`, `portal_student_enrolment`, `portal_teacher_lesson`, `portal_teacher_assignment`, `portal_principal`, `portal_capability_admin` |
| Principal kind (`PortalRule::PRINCIPAL_KINDS`) | `administrator`, `teacher`, `student`, `guardian` |
| Read-model version (`PortalRule::READ_MODEL_VERSIONS`) | `portal_lesson_v1`, `portal_enrolment_v1`, `portal_principal_v1` |
| Refusal and blocker reason (`PortalRule::EXCEPTION_REASON_CODES`) | the 35-member declared view of `REASON_CODES` written to `portal_access_denials.reason_code`, to a refused `portal_public_action_events.outcome_reason_code`, to a refused `portal_public_capability_commands` row's `reason_code`, and to a `revocation_reason_code` that records a refusal — exact list in §5.2.1 |
| Operator and migration reason (`PortalRule::OPERATOR_REASON_CODES`) | `phase_w_declared_default`, `operator_decision`, `operator_recorded_error`, `operator_suspected_leak`, `operator_reschedule_rotation`, `operator_cancellation_rotation`, `operator_archive_rotation` |
| Structural constants (`PortalRule`) | `CAPABILITY_BINDING_VERSION = 'portal_capability_binding_v1'`, `HANDLE_ENTROPY_BYTES = 32`, `MAX_CAPABILITY_TTL_SECONDS = 2592000` (30 days), `JOIN_ACTION_MODE = 'repeatable_evidence_and_redirect'`, `ABSENCE_ACTION_MODE = 'confirm_then_single_submission'`, `PUBLIC_ACTION_OPTION = 'dzn_platform_portal_actions'`, `PUBLIC_ACTION_ENABLED_VALUE = 'enabled'`, `SAFE_JOIN_HOSTS` (the closed allowlist of `meet.google.com` plus any host the owner explicitly adds under §21), `PROVIDER_CALLS = 0`, `PLATFORM_OUTBOX_WRITES = 0`, `THEME_WRITES = 0` |

### 5.2.1 The single reason-code allowlist

`PortalRule::REASON_CODES` is the **only** allowlist for every durable reason this phase can record: a
refused command result, a `portal_access_denials.reason_code`, a
`portal_public_action_events.outcome_reason_code`, a
`portal_public_capabilities.revocation_reason_code`, and the `reason_code` column of every capability
event and command row. Two sets are declared; every write uses a member of the set that owns its row,
and no code outside `REASON_CODES` is ever written anywhere.

**Durable refusal and blocker reasons — `PortalRule::EXCEPTION_REASON_CODES`** (36 members):
`portal_vocabulary_member_not_allowed`, `portal_parent_not_declared`, `portal_parent_not_live`,
`portal_principal_required`, `portal_principal_unresolved`, `portal_principal_ambiguous`,
`portal_principal_kind_not_permitted`, `portal_object_not_found`, `portal_object_not_owned`,
`portal_object_not_portal_visible`, `portal_upstream_aggregate_invalid`, `portal_capability_unknown`,
`portal_capability_handle_malformed`, `portal_capability_signature_invalid`,
`portal_capability_expired`, `portal_capability_revoked`, `portal_capability_superseded`,
`portal_capability_consumed`, `portal_capability_purpose_mismatch`,
`portal_capability_stale_schedule`, `portal_capability_expiry_missing`,
`portal_capability_ttl_not_allowed`, `portal_capability_binding_mismatch`,
`portal_capability_generation_conflict`, `portal_join_target_not_allowlisted`,
`portal_join_target_unavailable`, `portal_join_target_not_declared`, `portal_absence_window_closed`,
`portal_absence_outcome_final`, `portal_absence_late_evidence`, `portal_absence_submission_pending`,
`portal_confirmation_required`,
`portal_confirmation_invalid`, `command_replay_conflict`, `portal_rate_limited`,
`portal_route_disabled`.

**Operator and migration reasons — `PortalRule::OPERATOR_REASON_CODES`** (7 members, recorded on a
capability or event row as the administrator's or migration's stated reason, never as a refusal):
`phase_w_declared_default`, `operator_decision`, `operator_recorded_error`,
`operator_suspected_leak`, `operator_reschedule_rotation`, `operator_cancellation_rotation`,
`operator_archive_rotation`.

Rules that bind the allowlist:

- **Exhaustive and exclusive.** `PortalRule::REASON_CODES` is exactly the union of the two sets above.
  Every reason a portal service emits — a refusal, a denial, a redemption outcome, a revocation
  reason, the migration's declared reason and every operator-supplied reason — is a member of the set
  that owns the row it is written to. A member outside that set is refused
  `portal_vocabulary_member_not_allowed` and is never written to the database or to a command result.
- **One condition, one code.** A condition has exactly one spelling, used by every site that raises
  it: every expired capability raises `portal_capability_expired`, every signature mismatch
  `portal_capability_signature_invalid`, every non-applicable schedule version
  `portal_capability_stale_schedule`, every consumed absence capability `portal_capability_consumed`.
  No alias, abbreviation or caller-invented reason exists anywhere in the phase.
- **Two distinct subjects for "no authority".** `portal_principal_unresolved` means the session
  resolves to *no* principal; `portal_object_not_owned` means a principal exists but the exact record
  it named does not belong to it. They are never interchanged, and the second is never reported as the
  first (W-D17).
- **A refusal is never a business fact.** No reason code in this allowlist is written to any
  `lessons`, `canonical_*`, `commercial_*`, `recurring_*`, `payment_*`, `finance_*` or `provider_*`
  row, and no refusal changes any of them.
- **An in-flight delegation is not a verdict.** `portal_absence_submission_pending` means the exact
  confirmation found its own durable delegation lease still live, so this request delegated nothing,
  recorded nothing terminal and reports no outcome; it is the only reason a public absence request
  refuses while another delegator holds the lease, and it never claims that the owning authority
  recorded, accepted or refused the absence.

## 6. Session-to-Core-principal resolution

### 6.1 Inputs and refusals

`PortalPrincipalResolver::resolve(string $requiredKind, string $surface): array` is the only way a
portal surface learns who is acting.

| Input | Source | Rule |
| --- | --- | --- |
| session actor | `get_current_user_id()` | The **only** identity input. A value below 1 refuses `portal_principal_required`. |
| required kind | the calling surface | One member of `PRINCIPAL_KINDS` for that surface's own declared requirement; a kind not permitted for the surface refuses `portal_principal_kind_not_permitted` |
| surface | the calling surface | One member of `SURFACES`; recorded on every denial |

Resolution answers exactly one principal or one refusal. It never returns a *set*, never merges two
identities, never prefers one candidate by recency, and never widens a requirement to make a request
succeed.

### 6.2 Principal kinds

| Kind | Established by (read-only) | Notes |
| --- | --- | --- |
| `administrator` | the WordPress session holds the surface's own capability **and** `dzn_manage_platform` | Administrator reads are capability-gated; an administrator **write on behalf of** a Student or Teacher is recorded with attribution `administrator_on_behalf` by the owning module, never silently as the principal |
| `teacher` | exactly one active `dzn_teacher_principal_links` row for that user (`status = 'active'`, `revoked_at IS NULL`); the legacy singleton keys provide the one-row invariant because this table has no `active_slot` | Zero rows refuses `portal_principal_unresolved`; more than one live row refuses `portal_principal_ambiguous` |
| `student` | exactly one active `dzn_student_principal_links` row for that user with the same conditions and `active_slot = 1` | Same zero and ambiguous rule; the Student principal is the only Student identity a Student portal surface may name |
| `guardian` | an active, in-force `dzn_student_acceptance_authority_grants` row in exact `service_acceptance` scope for that user and the exact Student, evaluated through the Phase-F authority read service | A guardian is never a Student principal: the typed read subject carries the exact grant id and scope, and the owning module re-proves that same in-force grant against the Lesson's Student |

### 6.3 Per-surface requirements

| Surface | Required kind | Object-level check |
| --- | --- | --- |
| `portal_student_lesson`, `portal_student_enrolment` | `student`, or `guardian` with an in-force grant for that exact Student | the Lesson's Enrolment must be the principal's own (§7.1) |
| `portal_teacher_lesson`, `portal_teacher_assignment` | `teacher` | the Lesson's current Teacher Assignment must be the principal's own, proved by `TeacherAssignmentPortalReadPort`; the administrator-only `TeacherAssignmentReadService` is not called |
| `portal_principal` | the kind the surface declares | the principal may only read **its own** principal record |
| `portal_capability_admin` | `administrator` | capability administration is Lesson-scoped; the administrator's authority is the capability, not ownership |
| `portal_public_join`, `portal_public_absence` | none (anonymous) | the verified capability is the only authority; object-level ownership is proved by the capability's own binding (§7.2) |

### 6.4 What resolution never uses

Resolution never reads or trusts a query-string or body parameter; a cookie, header or local-storage
value supplied by the client; an email address, phone number, display name or username; a WordPress
user meta pair; an Amelia employee or customer id; a provider subject or mapping; a reference code or
UID; a nonce (a nonce protects an already-authorized form, it never establishes authority); a
previously issued capability; or a browser-only flag. A Legacy Adapter may later bridge an Amelia
employee session to a Core Teacher, but that bridge does not exist in this phase and is not simulated
here.

## 7. Object-level authorization

### 7.1 The one declared check

`PortalAccessPolicy::assertObject(string $surface, array $principal, string $targetKind, int $targetId): void`
is the only object-level check, and it is called inside every portal read and every portal command
after principal resolution. Its rule is fixed:

1. hydrate the exact target through the **narrow owning-module portal-read port** declared in §10.0;
2. require the owning module's own validator to accept the aggregate, or refuse
   `portal_upstream_aggregate_invalid`;
3. prove ownership against the principal's own link, grant or assignment facts — never against a
   request value and never against the target's own claim about itself;
4. refuse `portal_object_not_owned` when the record exists but is not the principal's, and
   `portal_object_not_found` when the owning port has no such record **only** where the
   surface is not public; a public surface always answers non-enumerating (§9.9).

A target that is not declared portal-visible for that surface (for example an archived Lesson on a
student list, or an unfinished draft nothing owns yet) refuses `portal_object_not_portal_visible`.
Portal-visibility is a declared rule of the surface, never a filter applied after reading.

### 7.2 Public surfaces

A public surface has no principal. Its object-level authority is the capability's own binding: the
capability names the exact `lesson_id`, `schedule_version_id`, `purpose` and `generation`, and Phase W
additionally re-proves, through the §10.0 owner ports using a `PublicCapabilityReadSubject`, that

- the named schedule version is still the Lesson's applicable version, or the request refuses
  `portal_capability_stale_schedule`;
- the Lesson is not cancelled or archived for that purpose, or the request refuses
  `portal_object_not_portal_visible`;
- for an Absence capability, the capability's recorded `subject_student_id` is the Student of the
  Lesson's own Enrolment, and that Student's principal link is still active, or the request refuses
  `portal_object_not_owned`.

### 7.3 Denials are durable and digest-only

Every refusal at principal resolution or object-level authorization appends exactly one
`portal_access_denials` row — inside the transaction of the work it refused, or in its own transaction
when it refused before any other write — carrying the surface, the principal kind and id when one
exists, the capability id when one exists, the `lesson_id`, target kind and target id when one exists,
the exact reason code, a keyed request-fingerprint digest (never the raw IP, user agent, path or body)
and the occurrence instant. Denial evidence is insert-only, digest-only and never contains a handle, a
token, a secret, a raw request value or a personal datum.

## 8. Stable read models

### 8.1 Stability contract

A read model is a **declared projection with a version**, not an ad-hoc query result:

- exactly one class per version, constructing only the declared fields;
- every field documented with its owning source and its rule, as in §8.2 to §8.4;
- additive-only within a version; a removed or re-typed field requires a new `READ_MODEL_VERSIONS`
  member and a new class;
- hydrated through the §10.0 owning-module portal-read ports and re-checked by that owner's validator; a corrupt or
  contradictory aggregate refuses `portal_upstream_aggregate_invalid` and returns **nothing**, never a
  partial list;
- deterministic ordering (start instant, then Lesson id) and bounded pagination for any list;
- no `SELECT` against another module's table from a portal class: the owning module's repository layer
  is the only path (MODULE-BOUNDARIES §1).

### 8.2 Declared Student read models

`portal_lesson_v1` — one entry per Lesson the Student's Enrolment owns:

| Field | Source | Rule |
| --- | --- | --- |
| `lesson_uid`, `reference_code` | Phase-M facts projected by `CanonicalLessonSchedulePortalReadPort` | identifier only; never an authorization input |
| `lesson_kind` | Phase-M fact projected by `CanonicalLessonSchedulePortalReadPort` | declared kind member |
| `lifecycle_state` | Phase-M fact projected by `CanonicalLessonSchedulePortalReadPort` | declared member; a cancelled Lesson is reported as cancelled, never hidden |
| `enrolment_uid`, `course_reference`, `term_reference` | Phase-M0/L facts projected by `CanonicalLessonSchedulePortalReadPort` | the Student's own Enrolment, Course and Term |
| `starts_at_utc`, `ends_at_utc`, `schedule_timezone` | `CanonicalLessonSchedulePortalReadPort` | UTC instants plus the recorded IANA zone; wall-clock rendering belongs to presentation |
| `schedule_version_uid` | `CanonicalLessonSchedulePortalReadPort` | the exact applicable version a capability would bind |
| `delivery_state_summary`, `attendance_state_summary` | `CanonicalLessonDeliveryPortalReadPort` and `CanonicalAttendancePortalReadPort` | declared summary members only; never an adjudication, a Teacher-only note, an academy obligation or a finance consequence |
| `join_available` (bool), `join_refusal_reason` | Phase-W capability state for that Lesson | whether *this Student* currently has a usable Join capability; the reason is a §5.2.1 member. The handle, token and target are never included. |
| `absence_available` (bool), `absence_refusal_reason` | Phase-W capability state plus `CanonicalAttendancePortalReadPort`'s window/finality answer | same rule |

`portal_enrolment_v1` — one entry per Enrolment the Student owns: `enrolment_uid`, `reference_code`,
`course_reference`, `term_reference`, `lifecycle_state`, `assigned_teacher_display_reference` (the
Teacher's platform display reference only — no contact detail, no email, no phone) and
`schedule_summary` (declared frequency and timezone, never another Student's data). The schedule and
Enrolment fields come from `CanonicalLessonSchedulePortalReadPort`; the current Teacher display
reference comes from `TeacherAssignmentPortalReadPort` after the Student relationship is re-proved.

### 8.3 Declared Teacher read models

`portal_lesson_v1` (Teacher side) — one entry per Lesson whose **current Teacher Assignment** is the
Teacher's, proved through `TeacherAssignmentPortalReadPort`: the same fields as §8.2 plus
`assignment_state` and `student_display_reference` (the Student's platform display reference only). It
never includes a Student contact detail, another Teacher's Lesson, a capacity or commercial fact, a
statement or a payability figure.

The `portal_teacher_assignment` surface returns one entry per current Assignment: `assignment_uid`,
`enrolment_uid`, `course_reference`, `term_reference`, `assignment_state`, `accepted_at` and
`student_display_reference`.

### 8.4 Declared principal read model

`portal_principal_v1` — the acting principal's own record only: `principal_kind`, `principal_id`,
`principal_uid`, `display_reference`, `linked_at` and, for a guardian, the grant's exact scope and
effective window. It exposes no other principal's row and no inventory of principals.

### 8.5 Explicit read-model exclusions

No portal read model may expose an email address, a phone number, a postal address, a national or tax
identifier, a bank, IBAN or card detail, a provider subject, reference, mapping id, conference digest
or join URI digest, a credential, a sealed value, a nonce, a key or cipher version, a raw command key,
payload or evidence digest, an internal exception detail, a finance amount, a rate, a statement, a
payability disposition, an academy obligation, another Student's or Teacher's data, or any
administrator-only field. A read-model field whose only source is an administrator-only read service
is not declared at all.

## 9. Purpose-bound signed public Join/Absence capabilities

### 9.1 Purposes

Exactly two, and nothing else in this phase:

| Purpose | Action | Consequence |
| --- | --- | --- |
| `lesson_join` | open the declared safe join target | one redemption evidence row and a `302` redirect; **no** business fact, **no** attendance, **no** provider call |
| `lesson_absence` | report an advance absence for the exact occurrence | one redemption evidence row, then a delegated evidence-only claim through the owning attendance authority (§10.1) retaining every decision |

### 9.2 Handle and token shape

A capability has three parts, only one of which is stored in a recoverable form:

1. **the public handle** — `HANDLE_ENTROPY_BYTES = 32` bytes from the Platform's CSPRNG, rendered
   base32-url into the link. Only its keyed digest is stored (`handle_digest`, `UNIQUE`).
2. **the binding** — the canonical string
   `portal_capability_binding_v1|purpose|lesson_uid|schedule_version_uid|generation|expires_at`.
3. **the signature** — `hash_hmac('sha256', binding, wp_salt('dzn_portal_capability_public'))`,
   base32-url rendered and appended to the handle in the link. Only the keyed digest of the **whole
   presented token** is stored (`token_digest`).

Verification recomputes the token digest from the presented value and the binding from the row, then
compares with `hash_equals()`. A wrong length, a malformed handle, a truncated token, a token for a
different Lesson, a different purpose, a different generation or a changed `expires_at` all fail with
`portal_capability_signature_invalid` or `portal_capability_binding_mismatch`, and the outward answer
is identical to a never-issued handle (§9.9).

### 9.3 Binding, expiry and generation

- A capability binds exactly one Lesson **and** exactly one `schedule_version_id`; there is no
  Lesson-wide capability and no principal-independent "any occurrence" capability.
- `expires_at` is recorded at mint, is absolute UTC, is at most `MAX_CAPABILITY_TTL_SECONDS` after
  mint, and is immutable.
- `generation` starts at 1 for each `(lesson_id, purpose)` and increases by exactly one per rotation.
  Exactly one row per `(lesson_id, purpose)` may hold `active_slot = 1` (`UNIQUE lesson_purpose_active`),
  and every `(lesson_id, purpose, generation)` is unique (`UNIQUE lesson_purpose_generation`).

### 9.4 Mint

`PortalCapabilityService::mint()` — capability `dzn_manage_portal_capabilities`, writes one
`portal_public_capabilities` row (`state = 'active'`, `active_slot = 1`, `generation` = next for that
pair), one `portal_public_capability_events` row (`minted`) and one digest-only
`portal_public_capability_commands` row with its typed `result_capability_id`. It:

- requires the exact Lesson, the exact applicable schedule version and a declared purpose;
- requires an `expires_at` inside the allowed TTL and otherwise refuses
  `portal_capability_expiry_missing` or `portal_capability_ttl_not_allowed`;
- for `lesson_join`, requires a declared safe join resource whose host is in `SAFE_JOIN_HOSTS`, seals
  it (W-D20) and otherwise refuses `portal_join_target_not_declared` or
  `portal_join_target_not_allowlisted`;
- for `lesson_absence`, requires the Lesson's own Student (recorded as `subject_student_id` from the
  owning read path, never from a request) and one eligible Student principal link;
- refuses `portal_capability_generation_conflict` when the pair's live row cannot be resolved, and
  refuses `portal_capability_binding_mismatch` when any supplied binding value disagrees with the
  owning modules;
- returns the plaintext link **once**, in the command's own response, to the minting administrator
  (W-D15; nothing else ever receives it).

There is no bulk mint, no scheduled mint, no automatic mint on Lesson creation and no mint on behalf of
another administrator.

### 9.5 Verify

`PortalCapabilityVerifier::verify(string $presentedToken, string $purpose, array $context): array` is
the only verification path. It runs the checks in this fixed order and refuses on the first failure:
route enabled, or `portal_route_disabled`; handle shape, or `portal_capability_handle_malformed`; row
lookup by `handle_digest`, or `portal_capability_unknown`; token digest comparison, or
`portal_capability_signature_invalid`; purpose match, or `portal_capability_purpose_mismatch`; state,
or `portal_capability_revoked`, `portal_capability_superseded` or `portal_capability_consumed`; expiry,
or `portal_capability_expired`; schedule applicability, or `portal_capability_stale_schedule`;
object-level re-proof (§7.2); and, for absence, the owner's declared window and the owner's reported
outcome state.

### 9.6 Redeem — Join

`GET /wp-json/delnavazan-platform/v1/portal/join/{handle}`:

1. verify (§9.5); on failure return the §9.9 non-enumerating answer;
2. open one Phase-W transaction under the Lesson root, decrypt the capability's sealed join target
   **inside that transaction** and re-check the host allowlist, append one
   `portal_public_action_events` row (`action_state = 'redirected'`, `handoff_target = NULL`) with its
   redemption key digest, and commit;
3. if the sealed value is absent, unreadable or off-allowlist, roll the transaction back and record the
   refusal (`portal_join_target_unavailable` or `portal_join_target_not_allowlisted`) with no redirect;
4. answer `302` to that exact target with `Referrer-Policy: no-referrer` and no body.

Join redemption is repeatable while the capability is `active` (W-D12). It writes no delivery,
attendance, participation, calendar, provider or notification fact, and it makes no provider call.

### 9.7 Redeem — Absence

`GET /wp-json/delnavazan-platform/v1/portal/absence/{handle}` — verify (§9.5), then render the
confirmation page: purpose, Lesson reference, occurrence start in the recorded zone, the Student's own
display reference, the consequence wording, and a one-time confirmation token. The GET is
non-mutating: it appends no action row. It renders a random nonce with a keyed proof bound
to the verified capability, and the POST proves that one-time token before appending
`confirmed_submitting` evidence; it never fabricates prior `confirmation_rendered` evidence.

`POST /wp-json/delnavazan-platform/v1/portal/absence/{handle}` — requires the one-time confirmation
token from that page (otherwise `portal_confirmation_required` or `portal_confirmation_invalid`),
then:

1. verify (§9.5) including the owner's declared absence window (otherwise
   `portal_absence_window_closed`) and the owner's reported outcome state (otherwise
   `portal_absence_outcome_final`);
2. open one Phase-W transaction under the Lesson root, append one `portal_public_action_events` row
   (`action_state = 'confirmed_submitting'`) keyed by the confirmation token digest, stamp the
   capability `consumed` with its `consumed_at` and `consumed_action_event_id` exactly once, append a
   `consumed` capability event, the digest-only command row **and the durable
   `action_state = 'delegating'` lease row keyed to that confirmation** (§15.3), and commit;
3. delegate to the owning attendance authority (§10.1) with the capability proof, **after** that
   commit and outside it (W-D19);
4. open a second Phase-W transaction, append the outcome row (`submitted`, with
   `handoff_target = 'canonical_attendance_evidence'` and its typed `handoff_reference_id`, or
   `refused` with the owner's reason), and commit. That second transaction takes the Lesson root
   first and re-reads the capability under it like every other capability write (§15.1), and it is
   idempotent: if the confirmation's terminal outcome is already recorded it returns that recorded
   outcome instead of appending a second one;
5. render the bounded result page — recorded, or not recorded with the declared reason. It never
   states an attendance verdict, never shows a Teacher note and never claims a completion.

The exact replay of the same confirmation token converges on the recorded outcome and performs no
second delegation: it re-reads the recorded terminal outcome under the Lesson root before it
delegates anything, and the durable lease of §15.3 decides whether this request may delegate at all.
A replay that arrives while another delegator still holds a live lease delegates nothing, records
nothing terminal and refuses `portal_absence_submission_pending`; a replay after a crash — a claim
whose lease has passed the declared bound with no recorded outcome — takes that lease over, re-proves
the capability state and binding, completes the delegation and records the outcome. A distinct
confirmation for an already-consumed capability refuses `portal_capability_consumed`.

### 9.8 Revocation, rotation and suspected leak

`PortalCapabilityService::revoke()` and `::rotate()` — capability `dzn_manage_portal_capabilities`,
each requiring an `OPERATOR_REASON_CODES` member (an operator-supplied reason outside that set is
refused `portal_vocabulary_member_not_allowed`):

- **revoke** moves `active` to `revoked` with `revoked_at`, `revoked_by` and `revocation_reason_code`,
  releases `active_slot`, and appends the `revoked` event. It is the terminal move for that row.
- **rotate** allocates the next generation, inserts the successor, moves the predecessor `active` to
  `superseded` with `superseded_by_capability_id`, releases `active_slot`, and appends the `rotated`
  event. It is the only way authority is re-issued for that `(lesson, purpose)`.
- **suspected leak** uses `revoke` with `operator_suspected_leak` and, unless the same administrator
  explicitly rotates, leaves the pair without a live capability. A revoked or superseded capability is
  never re-activated and never re-signed.
- **rotation triggers.** The canonical facts are authoritative: a reschedule that produces a new
  applicable schedule version already makes the old capability refuse
  `portal_capability_stale_schedule` (§9.5), and cancellation or archival already refuses
  `portal_object_not_portal_visible` (§7.2). The operator-facing rotation for those events exists so
  that the *stored* row also records the revocation with its reason
  (`operator_reschedule_rotation`, `operator_cancellation_rotation`, `operator_archive_rotation`)
  instead of leaving a stale row that is only refused at verification time. Phase W performs no
  automatic rotation in this phase (§21, open decision 6).

### 9.9 Safe, non-enumerating responses

A refused public request answers with one identical outward shape — the same HTTP status (`404`), the
same bounded generic wording, the same absence of timing signal — for a malformed handle, an unknown
handle, a wrong signature, an expired capability, a revoked capability, a superseded capability, a
stale schedule version, a disabled route and a non-portal-visible Lesson. It never echoes the
presented handle or token, never states which check failed, never redirects, and never includes the
Lesson, Student, Course, Teacher or target. The **exact** reason code is recorded durably
(`portal_access_denials` or a refusal action row) and is visible only to
`dzn_view_portal_capabilities` (W-D17, W-D23).

### 9.10 No distribution

Phase W stores no recipient, address, channel, template or message and writes no outbox row (W-D15,
§17). The plaintext link exists in exactly two places: the mint command's own response to the
authenticated administrator, and the administrator's own copy. Every other appearance of a link —
email, WhatsApp, SMS, notification, file export, printed copy or public page — belongs to a later,
separately authorised delivery slice, and enabling it is §21 open decision 1.

## 10. Delegation: the owning-module seams this phase uses

### 10.0 Narrow owner-module portal-read ports

The existing protected read services are administrator/reviewer surfaces. Phase W must **not** call
them under a fabricated administrator, remove their capability checks, grant their capabilities to a
Teacher or Student, or copy their repository queries. Instead the implementation candidate requires
these four additive, internal application ports, each implemented and tested beside its owning
authority:

| Owning authority | Required internal port | Bounded operations and result |
| --- | --- | --- |
| Phase J Teacher Assignment | `TeacherAssignmentPortalReadPort` | `forSubject(subject, enrolmentId)` and bounded `pageForSubject(subject, cursor, limit)`; re-proves the current Assignment and returns only the §8 Assignment fields and the identifiers needed to bind the exact Enrolment/Lesson relationship |
| Phase N canonical schedule | `CanonicalLessonSchedulePortalReadPort` | `forSubject(subject, lessonId)` and bounded `pageForSubject(subject, cursor, limit)`; validates the canonical Lesson and schedule aggregate and returns only the applicable schedule version plus the §8 Lesson/Enrolment/Course/Term identifiers and display references |
| Phase O canonical delivery | `CanonicalLessonDeliveryPortalReadPort` | `summaryForSubject(subject, lessonId)`; validates the delivery aggregate and returns only `delivery_state_summary`, never outcome history, academy-obligation detail or a Teacher-only note |
| Phase P canonical attendance | `CanonicalAttendancePortalReadPort` | `summaryForSubject(subject, lessonId, scheduleVersionId)`; validates the occurrence aggregate and returns only `attendance_state_summary`, absence-window availability/finality and a declared refusal code, never evidence, intervals, claims, provider facts, decisions, anomaly detail or review data |

`subject` is one of two typed, request-local values. `AuthenticatedPortalReadSubject` is constructed
only by `PortalPrincipalResolver` from the current WordPress user and contains the surface,
WordPress-user id, principal kind and resolved Core id. `PublicCapabilityReadSubject` is constructed
only by `PortalCapabilityVerifier` after successful cryptographic and state verification and contains
the capability id, purpose, Lesson, schedule version, generation and bound Student where applicable.
Neither value is accepted from a controller parameter, array payload, cookie, header or persisted
cache, and neither is reusable outside the request.

Every port independently enforces all of the following:

1. It re-proves the supplied subject against authoritative facts. For a Teacher this includes the
   active principal link and the exact current Assignment; for a Student it includes the active
   principal link and the exact Lesson → Term → Enrolment → Student chain; for a guardian it includes
   the exact in-force grant and Student; for a public capability it includes the exact canonical
   Lesson, applicable schedule version and bound Student named by the verified binding.
2. It obtains any cross-authority fact through that authority's application read seam or a typed
   result from another port in this table. Phase-W code never supplies a claimed `teacher_id`,
   `student_id`, `enrolment_id` or `schedule_version_id` as proof and never reads an owner repository.
3. It runs the owner's existing integrity validator before returning. Missing, stale, ambiguous or
   corrupt facts fail closed using the §5.2.1 mapping; no partial projection is returned.
4. It is read-only: no database write, option, transient, cache-as-authority, lock or audit side
   effect. Its result contains only the declared fields above and cannot expose the broader result of
   `CanonicalLessonScheduleReadService`, `CanonicalLessonDeliveryReadService`,
   `CanonicalAttendanceReadService` or `TeacherAssignmentReadService`.
5. It has no unrestricted `find`, `recent`, list-all, history or arbitrary-principal operation. A
   bounded page is scoped by the re-proved subject before rows are returned, with deterministic
   ordering and a maximum page size declared by the owner.

`dzn_view_own_portal_schedule` gates entry to a Teacher Phase-W surface only. It is not accepted by an
owner port as proof of ownership. Students continue to need no WordPress capability, and none of
`dzn_manage_canonical_lesson_schedules`, `dzn_manage_canonical_lesson_delivery`,
`dzn_view_canonical_attendance_review` or `dzn_manage_teacher_assignments` is granted or implied.
Until all four ports exist and their owner-specific authorization tests pass, every authenticated
Student/Teacher read surface remains unregistered; Phase W may not fall back to an administrator
service or a direct table read.

### 10.1 Absence to the owning attendance authority

Phase P owns attendance intake, evidence, assessment, review and adjudication, and it already declares
the claim kinds (`advance_absence_claim`, `attendance_claim`, `delivery_claim`, `review_request`), the
`advance_absence_claim` semantics (evidence only; never settles, completes, cancels or creates
entitlement) and the Student-claim capability `dzn_submit_own_attendance_claim`. Its evidence table
already carries a nullable `attribution varchar(32)` column, and its validator already accepts
`own_principal` and `administrator_on_behalf`.

What Phase P does **not** yet admit is a claim with **no WordPress session**: its `actor()` requires a
current user id and its ownership proof resolves that user's principal link. Phase W therefore
declares, as a **required upstream seam extension owned by Phase P** (prerequisite 2 of §20), exactly
one additive intake path:

| Element | Declared requirement |
| --- | --- |
| Entry | one bounded Phase-P intake operation that accepts a Phase-W capability proof instead of a session |
| Proof | `capability_id`, `purpose = lesson_absence`, `lesson_id`, `schedule_version_id`, `generation`, the resolved `student_id`, the W redemption and claim reference, and the capability's `expires_at` — all re-proved by Phase P against its own facts and against the capability row |
| Attribution | the recorded `attribution` member **`public_capability_on_behalf`** — a new declared member of Phase P's own vocabulary, written only on that path |
| Authority retained by Phase P | the claim kind remains `advance_absence_claim`; Phase P still decides whether the claim is admissible, still records evidence only, still refuses a late claim, still refuses when a final attended outcome exists, and still never settles attendance from a claim |
| Phase-W obligations | Phase W writes no Phase-P row, adds no column to any Phase-P table, never calls the path as a synthetic administrator, and reports only what Phase P returned |

Until that seam exists and is independently reviewed, the `lesson_absence` purpose is **declared but
not enabled**: minting an Absence capability refuses `portal_parent_not_live`, and the Join purpose is
unaffected. This contract authorises no Phase-P change; it records the seam the absence action
requires.

### 10.2 Join target: declared allowlist plus a sealed portal artefact

Phase V deliberately stores `conference_digest` and `join_uri_digest` only, exposes no join URI through
`ProviderIntegrationReadService`, and lists "no public join action or signed capability" among its own
deferrals. Phase W therefore does **not** read a join URI from a provider mapping, and it makes no
provider call and decrypts no provider secret. Instead:

- the declared safe join resource is supplied by the minting administrator and must parse as `https`
  with a host in `PortalRule::SAFE_JOIN_HOSTS`; a non-HTTPS or off-allowlist value is refused
  `portal_join_target_not_allowlisted`;
- Phase W seals it with the Platform's authenticated-encryption convention under its own key class
  `dzn_portal_join_target` (W-D20), storing `join_target_ciphertext`, `join_target_nonce`,
  `join_target_key_version`, `join_target_cipher_version` and the non-secret `join_target_host`;
- the sealed value is decrypted only inside a verified redemption, only for that capability, and only
  to build the `302`;
- the host is recorded separately so a redirected request can be audited without decrypting anything.

The sealed value is never conference truth: it never creates or repairs a Phase-V mapping, never
influences a calendar event, never enters attendance or delivery, and is rotated or revoked with the
capability. When the owner later authorises Phase V to expose an approved descriptor, the same column
set can be hydrated from it without a schema change (§21, open decision 7).

### 10.3 What the portals never do

A portal code path never writes another module's table; calls another module's commands without that
module's own authorization; re-implements an owner's rule (for example an attendance threshold, a
capacity rule, an acceptance rule or a payability rule); reads another module's table directly at a
controller; caches an owner's answer as authority; treats a successful read as a permission; or
substitutes a default for an owner's refusal.

## 11. Administrator-facing surface

Exactly one administrator surface, registered under the **existing** Platform menu the way
`FinancePolicyController` is, capability-gated and nonce-protected:

`src/Admin/Controller/PortalCapabilityController.php` — capability `dzn_manage_portal_capabilities` to
mint, rotate and revoke, and `dzn_view_portal_capabilities` to read. It shows, per Lesson and purpose,
the live capability's generation, state, expiry, the recorded rotation or revocation reason, the
redemption count and last outcome, and the refusal codes — and it shows the plaintext link exactly
once, in the response to the mint request that created it.

The screen renders no Theme template and no front-end asset; it is a plain administrator form in the
established Platform style, escapes every value for its context, and exposes no provider value, no
secret, no finance field and no raw digest. Phase W adds **no** new administrator read model beyond the
capability read surface and the §12 diagnostics, and it changes no existing administrator screen.

## 12. Diagnostics

Counts and states only, never a handle, token, secret, payload, raw key, request value or personal
datum:

- capabilities by purpose and state, live generations per Lesson, and capabilities expired but not yet
  rotated;
- redemptions by purpose and outcome, with confirmed-but-unresolved claims (`confirmed_submitting`
  past the declared delegation lease bound with no recorded terminal outcome) as a first-class count,
  alongside in-flight (`delegating` inside the bound) and abandoned (`delegating` past the bound with
  no recorded terminal outcome) delegation leases;
- refusals by surface and reason code, denial counts by principal kind and reason code, and the
  enabled or disabled state of the public-action option;
- read-model failures by version and surface (`portal_upstream_aggregate_invalid` counts);
- the declared structural invariants `PROVIDER_CALLS = 0`, `PLATFORM_OUTBOX_WRITES = 0` and
  `THEME_WRITES = 0` as the source-scan assertions of §18.

## 13. Schema 031 data model and migration

### 13.1 Tables

Six additive tables, all new, none altering an existing table:

| Table | Purpose | Mutability |
| --- | --- | --- |
| `dzn_portal_lesson_capability_roots` | The single per-Lesson serialisation root (W-D18); insert-or-resolve on `UNIQUE lesson_id`; never deleted | immutable after insert |
| `dzn_portal_public_capabilities` | The capability registry: binding, sealed join target, state, generation, expiry and one-time consumption | mutable only in the declared state, consumption, revocation and supersession columns |
| `dzn_portal_public_capability_events` | Append-only capability lifecycle: `minted`, `rotated`, `revoked`, `consumed` | append-only |
| `dzn_portal_public_capability_commands` | Digest-only command evidence for every administrative capability command, with its typed result | append-only |
| `dzn_portal_public_action_events` | Append-only redemption evidence: confirmation, claim, delegation lease, submission, redirect and refusal | append-only |
| `dzn_portal_access_denials` | Append-only, digest-only principal, object-level and public-action refusal evidence | append-only |

### 13.2 Declared columns

`portal_public_capabilities`: `id`, `uid char(26)`, `lesson_id`, `schedule_version_id`,
`subject_student_id bigint NULL`, `purpose varchar(24)`, `generation int unsigned`,
`handle_digest char(64)`, `token_digest char(64)`, `join_target_ciphertext blob NULL`,
`join_target_nonce varchar(64) NULL`, `join_target_key_version varchar(16) NULL`,
`join_target_cipher_version varchar(16) NULL`, `join_target_host varchar(255) NULL`,
`state varchar(16)`, `active_slot tinyint unsigned NULL`, `issued_at datetime`, `expires_at datetime`,
`consumed_at datetime NULL`, `consumed_action_event_id bigint unsigned NULL`,
`revoked_at datetime NULL`, `revoked_by bigint unsigned NULL`, `revocation_reason_code varchar(64) NULL`,
`superseded_by_capability_id bigint unsigned NULL`, `reason_code varchar(64)`, `created_at datetime`,
`created_by bigint unsigned`.

Unique keys: `uid`, `handle_digest`, `token_digest`, `lesson_purpose_generation`
(`lesson_id`, `purpose`, `generation`) and `lesson_purpose_active` (`lesson_id`, `purpose`,
`active_slot`). Indexes: `lesson_purpose_state` (`lesson_id`, `purpose`, `state`),
`subject_student` (`subject_student_id`) and `expiry` (`expires_at`). The only mutable columns are
`state`, `active_slot`, `consumed_at`, `consumed_action_event_id`, `revoked_at`, `revoked_by`,
`revocation_reason_code` and `superseded_by_capability_id`.

`portal_public_capability_events`: `id`, `uid char(26)`, `lesson_id`, `capability_id`,
`event_sequence int unsigned`, `event_type varchar(16)`, `purpose varchar(24)`,
`generation int unsigned`, `reason_code varchar(64)`, `evidence_channel varchar(32)`,
`evidence_reference_digest char(64)`, `recorded_at datetime`, `created_at datetime`,
`created_by bigint unsigned`. Unique keys: `uid` and `lesson_sequence`
(`lesson_id`, `event_sequence`). Index: `capability_event` (`capability_id`, `event_type`).

`portal_public_capability_commands`: `id`, `uid char(26)`, `command_domain varchar(48)`,
`operation varchar(32)`, `command_key_digest char(64)`, `command_payload_digest char(64)`,
`lesson_id`, `purpose varchar(24)`, `expected_capability_id bigint unsigned NULL`,
`expected_generation int unsigned NULL`, `expected_state varchar(16) NULL`,
`join_target_host varchar(255) NULL`, `reason_code varchar(64) NULL`,
`result_capability_id bigint unsigned NULL`, `result_state varchar(24)`, `created_at datetime`,
`created_by bigint unsigned`. Unique keys: `uid` and `command_key_digest`. Indexes:
`lesson_operation` (`lesson_id`, `operation`) and `result_capability` (`result_capability_id`).
`result_state` is one of `active`, `revoked`, `superseded`, `consumed` or `refused`. A refused
administrator command stores its exact exception reason and a `NULL` typed result. If validation
fails before a capability purpose can be accepted, the command evidence uses the command-only
member `unknown`; it is never a capability purpose. The same stable command key may replay that
same refusal, but changed operation, payload, Lesson or purpose facts refuse
`command_replay_conflict` and create no second command row.

`portal_public_action_events`: `id`, `uid char(26)`, `capability_id NULL`, `lesson_id NULL`,
`purpose varchar(24)`, `action_sequence int unsigned`, `action_state varchar(24)`,
`resolved_student_id bigint NULL`, `confirmation_digest char(64) NULL`,
`redemption_key_digest char(64)`, `handoff_target varchar(32) NULL`,
`handoff_reference_id bigint unsigned NULL`, `outcome_reason_code varchar(64) NULL`,
`request_fingerprint_digest char(64)`, `occurred_at datetime`, `created_at datetime`,
`created_by bigint unsigned`. Unique keys: `uid`, `redemption_key_digest` and `capability_sequence`
(`capability_id`, `action_sequence`). Indexes: `lesson_action` (`lesson_id`, `action_state`) and
`capability_action` (`capability_id`).

`capability_id` and `lesson_id` are NULL only for a refusal whose malformed or never-issued handle
has no capability parent; its route-declared purpose, reason code and request digest remain durable.
A non-NULL action capability id must name an existing capability.

`action_state` is one of `confirmed_submitting`, `delegating`, `submitted`, `redirected` or `refused`.
A `delegating` row is the append-only delegation lease of §15.3: it carries the same `capability_id`,
`lesson_id`, `confirmation_digest` and `resolved_student_id` as the claim it belongs to, its
`redemption_key_digest` is that confirmation's own delegation key for its lease epoch, and its
`occurred_at` is the instant the lease began. It is the confirmation's single-delegator key: at most
one live lease exists per confirmation, and a further lease row exists only for the epoch that took
over an abandoned one under the declared bound. No column is ever updated, so the table stays
append-only.

`portal_access_denials`: `id`, `uid char(26)`, `surface varchar(32)`,
`principal_kind varchar(16) NULL`, `principal_id bigint unsigned NULL`,
`capability_id bigint unsigned NULL`, `lesson_id bigint unsigned NULL`,
`target_kind varchar(32) NULL`, `target_id bigint unsigned NULL`, `reason_code varchar(64)`,
`request_fingerprint_digest char(64)`, `occurred_at datetime`, `created_at datetime`. Unique key:
`uid`. Indexes: `surface_reason` (`surface`, `reason_code`, `occurred_at`) and
`principal_time` (`principal_id`, `occurred_at`). Every row is appended inside the transaction of the
refusal it evidences (§7.3, §15.6): a public-action refusal carries its route-declared `surface`, its
resolved `capability_id` and `lesson_id` when the handle resolves to a capability, and never a handle,
token, secret or raw request value.

`portal_lesson_capability_roots`: `id`, `lesson_id`, `created_at datetime`,
`created_by bigint unsigned`. Unique key: `lesson` (`lesson_id`). It holds no authority state of any
kind — it is a serialisation anchor only, exactly like `teacher_schedule_roots` and
`finance_teacher_roots`.

### 13.3 Declared parents

Every declared `*_id` column is the leading column of a declared named index, every parent is inside
this set, and no polymorphic `result_id` exists — every command result is a typed `result_*_id` with a
declared parent:

- **written parents:** `portal_lesson_capability_roots`, `portal_public_capabilities`,
  `portal_public_capability_events`, `portal_public_action_events`, `portal_access_denials`;
- **read-only parents:** `lessons`, `canonical_lesson_schedule_versions`,
  `canonical_lesson_delivery_outcomes`, `canonical_attendance_cases`, `enrolments`, `terms`,
  `students`, `teachers`, `teacher_assignments`, `teacher_principal_links`,
  `student_principal_links`, `student_acceptance_authority_grants`.

The legacy `lesson_schedule_versions` table is **not** a parent anywhere in this phase.
“Read-only parent” declares referential provenance for Schema 031; it does not authorise a Phase-W
repository or read model to query that parent's table. Assignment, schedule, delivery and attendance
facts reach Phase W only through §10.0.

### 13.4 Verifier rules

`verify_portal_facing_services_schema()` runs after migration 031, on current-schema verification and
unconditionally before the schema option may advance to 31 — including the retained-031 and
stale-version path — and it rejects:

- a missing or undeclared table, a non-InnoDB table, or a missing
  `id bigint unsigned NOT NULL AUTO_INCREMENT` and `PRIMARY KEY (id)`;
- a missing `uid` on a declared handle table, or a `uid`/`reference_code` column outside the declared
  set;
- a mutable column beyond the declared set of §13.2, or **any** mutable column on an append-only
  table;
- a missing declared index, or a `*_id` that is not the leftmost column of a named index;
- a parent outside §13.3, including any reference to `lesson_schedule_versions`;
- a malformed or incompletely declared digest column;
- a plaintext `handle`-, `token`-, `secret`- or `ciphertext`-named column outside the three declared
  sealed-target columns, and any `provider_*`-named, `email`-named, `phone`-named, `iban`-named or
  `card`-named column;
- a capability row whose `active_slot = 1` disagrees with `state = 'active'`;
- two live capabilities for one `(lesson_id, purpose)`, or a duplicated `(lesson_id, purpose,
  generation)`;
- a `consumed_at` without its `consumed_action_event_id`, or an action event with a non-NULL
  capability that does not exist;
- a reason code outside `REASON_CODES`;
- a Lesson that has a capability but no `portal_lesson_capability_roots` row;
- a `join_target_ciphertext` without its `nonce`, `key_version` and `cipher_version`, or the reverse;
- any attempt to smuggle Theme, template, notification, provider, finance, payment, commercial or
  canonical storage into the phase.

### 13.5 Migration behaviour

Migration `031_portal_facing_services_principal_authorization` is additive on Schema 30, repeat-safe,
and creates only the six tables above with their declared keys. It:

- seeds **no** capability, **no** registry row, and no Lesson root for a Lesson that has no capability;
- writes **no** option other than its own capability marker, and specifically never writes
  `dzn_platform_portal_actions` (W-D16);
- performs **no** `ALTER` on any existing table, including `platform_audit_events` and
  `platform_outbox`, which it never creates, alters or touches;
- repairs its three capabilities per capability, exactly like Phases O to U:
  `dzn_manage_portal_capabilities` and `dzn_view_portal_capabilities` are granted to the administrator
  role and **removed** from `dzn_teacher` and from any role that ever held one, while
  `dzn_view_own_portal_schedule` is granted to the administrator role and to `dzn_teacher`; the
  verifier refuses an installation in which a Teacher role holds either administrator-only capability;
- adds `CAPABILITY_OPTION_W = 'dzn_platform_capability_version_2a2w'` and
  `CAPABILITY_VERSION_W = '2a2w'` with the corresponding marker check, mirroring `_U`;
- requires the base's Phase-U, Phase-T, Phase-V, Phase-P, Phase-O, Phase-N and Phase-M verifiers to
  pass unchanged rather than duplicating them.

## 14. Services, capabilities, read models, diagnostics

### 14.1 Services

| Surface | Capability | Notes |
| --- | --- | --- |
| `PortalPrincipalResolver::resolve` | none (read-only) | §6; writes nothing, caches nothing, always refuses rather than widening |
| `PortalAccessPolicy::assertObject` | none (read-only, called by others) | §7; dispatches to the scoped §10.0 owner port and appends one denial row on refusal |
| `PortalCapabilityService::mint`, `rotate`, `revoke` | `dzn_manage_portal_capabilities` | §9.4, §9.8; each takes the per-Lesson root exclusively, appends one typed `portal_public_capability_commands` row and one capability event, and returns the plaintext link only from `mint` |
| `PortalCapabilityVerifier::verify` | none (public path) | §9.5; read-only; never mutates a capability row |
| `PortalPublicActionService::join`, `renderAbsenceConfirmation`, `confirmAbsence` | none (public path; the verified capability is the authority) | §9.6, §9.7; two-phase redemption under the Lesson root; delegates only through §10 |
| `PortalHandoffService::submitAbsenceClaim` | none (delegated) | §10.1; the only place a portal calls an owning module's intake, and only after its own claim has committed |
| `TeacherAssignmentPortalReadPort`, `CanonicalLessonSchedulePortalReadPort`, `CanonicalLessonDeliveryPortalReadPort`, `CanonicalAttendancePortalReadPort` | none; internal owner ports, with the typed subject and owner re-proof as authority | §10.0; read-only, subject-scoped and field-bounded; no administrator capability is inherited, bypassed or granted |
| `PortalStudentLessonReadModel`, `PortalStudentEnrolmentReadModel`, `PortalTeacherLessonReadModel`, `PortalTeacherAssignmentReadModel`, `PortalPrincipalReadModel` | `dzn_view_own_portal_schedule` gates the Teacher surface; principal-link-derived authority gates the Student surface; §10.0 still re-proves the exact object | §8; PII-minimised, versioned, fail closed |

### 14.2 Capabilities

Exactly three new capabilities:

| Capability | Administrator | `dzn_teacher` | Student |
| --- | --- | --- | --- |
| `dzn_manage_portal_capabilities` | yes | no (removed if present) | no |
| `dzn_view_portal_capabilities` | yes | no (removed if present) | no |
| `dzn_view_own_portal_schedule` | yes | yes | no |

- `Migrator` gains `CAPABILITY_OPTION_W` and `CAPABILITY_VERSION_W`, repaired **per capability**
  exactly like Phases O to U, so a partially granted installation is repaired deterministically and a
  fresh installation never fails closed on a partially granted state.
- The two administrator-only capabilities are absent from every other role, and the verifier refuses
  an installation in which a Teacher role holds one (W-D22, §13.5).
- **No student role is created and no student capability exists.** Student portal authority is the
  active principal link plus the per-call object-level check.
- No public route is capability-gated: the public surfaces are gated by the verified capability and by
  W-D16.

### 14.3 Diagnostics

As §12. Diagnostics read only portal tables and the scoped §10.0 owner ports; they never query another
module's table, call an administrator-only owner read service as a portal principal or expose a raw
value.

## 15. Concurrency, idempotency and serialisation

### 15.1 One serialisation root, chosen from the target

Phase W declares exactly one root family: the **per-Lesson** `portal_lesson_capability_roots` row,
created lazily by the first capability command for that Lesson through an insert-or-resolve on
`UNIQUE lesson_id` and never deleted. Every capability write, every verification that leads to a write,
and every redemption of that Lesson takes it **first** and `FOR UPDATE`. Two Lessons never contend,
two commands for one Lesson never interleave, and a read-only verification that writes nothing takes
no lock.

Every portal write has a Lesson of its own — a capability always binds one — so no portal write is ever
root-less; a command that cannot resolve its Lesson refuses `portal_parent_not_live` rather than
inventing a root.

### 15.2 Fixed lock order

`portal_lesson_capability_roots` → `portal_public_capabilities` (the target row and its
`(lesson_id, purpose)` siblings, ascending `id`) → `portal_public_capability_events`,
`portal_public_capability_commands`, `portal_public_action_events` and `portal_access_denials`
(insert order).

Phase W takes **no** Phase-L, M, M0, N, O, P, Q, R1, R2, T, U or V lock, holds no capacity lock, and
holds no owning-module transaction open across a delegation. The order is never inverted and no portal
code path upgrades a shared lock.

### 15.3 Two-phase redemption and the durable single-delegator lease

Verified public actions use the declared two-phase shape of W-D19: claim in Phase W, delegate to the
owning module in the owner's own transaction, then record the outcome in Phase W. Every one of those
Phase-W writes is a capability write: it begins its transaction by taking the Lesson root `FOR UPDATE`,
re-reads the capability under that lock, and only then appends (§15.1, §15.2). The rejection evidence
of a refusal whose handle resolves to a capability, the `confirmed_submitting` claim, the `delegating`
lease and the terminal outcome therefore all append inside the one declared serialisation root, and a
redemption, a refusal and a rotation for one Lesson are totally ordered.

A crash between the phases leaves a durable `confirmed_submitting` claim, and the exact replay of the
same confirmation converges. The declared mechanism is the **durable, root-serialized, append-only
`delegating` lease keyed to the confirmation**:

1. inside the claim transaction, and therefore under the Lesson root, Phase W first re-reads the
   confirmation's recorded terminal outcome; if one exists it commits and returns it without
   delegating anything (idempotent terminal recording);
2. with no terminal outcome it reads the confirmation's latest `delegating` lease. A lease younger
   than `PortalRule::DELEGATION_LEASE_SECONDS` belongs to a live delegator, so this request appends
   nothing, delegates nothing and refuses `portal_absence_submission_pending`;
3. otherwise — no lease, or a lease past the declared bound — this request appends its own lease row
   (the next epoch, with its own `redemption_key_digest`), commits, and is then the one delegator;
4. after delegating, the outcome transaction takes the Lesson root first and re-reads the
   confirmation's terminal evidence; a terminal outcome already recorded by another delegator is
   returned instead of appended, so a converging replay can never fail the unique redemption key or
   report a persistence failure for an outcome that already exists.

The lease is append-only evidence rather than a mutable flag, so a delegator never renews or releases
it; the bound is what makes a crashed delegation recoverable, and the owning module's own idempotency
on the capability proof is the backstop for a delegation that outlives the bound. Phase W never reports
success before the owning module's own durable fact exists, never permanently strands a claim, and
never delegates twice for one confirmation (W-D13, W-D19, §12).

### 15.4 Declared outcome table — which commits first

| Which commits first | Declared outcome |
| --- | --- |
| a `rotate` or `revoke` | a later redemption of the predecessor refuses `portal_capability_revoked` or `portal_capability_superseded`; an in-flight claim that already recorded `confirmed_submitting` before the rotation completes its delegation against the generation it recorded, because it was valid at claim time |
| a redemption claim | the rotation's predecessor move still succeeds; the recorded claim is not retroactively cancelled, and the successor generation is a new handle |
| two exact replays of one confirmation | the durable lease admits one delegator; the second either converges on the recorded terminal outcome or refuses `portal_absence_submission_pending`, and neither outcome can produce a second delegation, a second terminal row or a persistence failure |
| a reschedule (Phase N) | the old capability refuses `portal_capability_stale_schedule` at verification; the operator's rotation additionally records `operator_reschedule_rotation` |
| a cancellation or archive (Phase M) | the capability refuses `portal_object_not_portal_visible` at verification; the operator's rotation additionally records the matching reason |
| the owning attendance authority | Phase W reports exactly what the owner returned — `submitted` with the owner's typed reference, or `refused` with the owner's reason — and never substitutes its own verdict |

### 15.5 Idempotency

- `UNIQUE handle_digest` and `UNIQUE token_digest` make a mint collision impossible and a duplicated
  administrative command converge on the replay rule of §15.6.
- `UNIQUE redemption_key_digest` makes a replayed absence confirmation converge instead of
  double-submitting, and keys the claim, each `delegating` lease epoch and the terminal outcome apart,
  so one confirmation can never append a second claim, a second lease for the same epoch or a second
  terminal outcome; the lease epoch is allocated under the Lesson root, so two delegators cannot
  allocate the same one.
- `UNIQUE (lesson_id, purpose, generation)` and `UNIQUE (lesson_id, purpose, active_slot)` make a
  rotation race arithmetically impossible rather than merely unlikely.
- Every administrative command records `command_key_digest` (unique) and `command_payload_digest`, and
  `replay()` re-loads the recorded typed result under the root it already holds and re-proves the
  operation's declared result shape before converging: an identical replay converges, a contradicting
  replay refuses `command_replay_conflict`, and a corrupted or substituted result fails closed instead
  of reporting success.

### 15.6 Refusal and evidence transaction

A **business refusal** — an unknown target, a revoked capability, a closed window, an off-allowlist
host, an inadmissible reason code — commits exactly its refusal evidence: the refused command row, the
denial row and their digest-only audit rows, while the attempted mutation rolls back.
A **persistence or corruption failure** rolls the whole command back: no capability row, no command
row, no action row, no denial and no audit row, failing closed and visibly rather than repairing,
defaulting or retrying into a different answer.

Refusal evidence is never split across two transactions. A public-action refusal whose handle resolves
to a capability is a capability write (§15.3), so its refused `portal_public_action_events` row **and**
its `portal_access_denials` row append together inside the one Lesson-root transaction, in the §15.2
order, and both fail closed: a failure of either evidence insert — or of the commit — rolls both back.
The public controller therefore appends no denial of its own, and the refusal the post-delegation
outcome transaction records commits its outcome row and its denial row in that same transaction. A
converging replay re-reads that committed evidence under the root and appends neither a second refusal
row nor a second denial (§15.5); the route-declared surface of each public purpose (`portal_public_join`
or `portal_public_absence`) is carried on the denial row, with the resolved `capability_id` and
`lesson_id` when the handle resolves to a capability (§7.3, §13.2).

The two classes are told apart by the declaration, not by the catch: a controller converts a throwable
into refusal evidence only when that throwable carries a declared refusal reason
(`PortalRule::refusalReason()`, i.e. a member of `PortalRule::EXCEPTION_REASON_CODES`). A persistence,
corruption or infrastructure failure — `portal_action_evidence_persistence_failed`,
`portal_capability_persistence_failed`, or any unexpected throwable — carries no declared refusal reason,
so it is **re-raised unchanged** by both the public-action controller and the administrative capability
command and is never followed by a durable refusal record: the refused evidence of one command is
all-or-nothing, and a command that failed to persist its own evidence never acquires a refusal row, a
denial row or a refusal audit row. The declared persistence codes themselves are never refusal-reason
vocabulary members. The same split governs the post-delegation owner handoff (§15.3): a declared owner
refusal is recorded as the refused outcome with the owner's own reason and its denial row in that one
transaction, while an owner persistence, corruption or infrastructure failure propagates unchanged and
records neither a refused outcome nor a denial — the redemption claim and the delegation lease committed
before the delegation stay durable, so the §15.8 bound and an exact replay converge the abandoned claim.

### 15.7 Declared write allowlist

The only tables a Phase-W code path may write are the six declared portal tables plus **insert-only,
digest-only** `platform_audit_events` evidence, written inside the transaction of the portal row it
evidences and carrying identifiers and digests only. Phase W writes **no** `platform_outbox` row
(`PLATFORM_OUTBOX_WRITES = 0`), **no** row in any other module's table, and **no** option other than
`dzn_platform_capability_version_2a2w` (written by the migrator). It only ever **reads**
`dzn_platform_portal_actions` in this phase.

### 15.8 Abandoned-claim bound

The declared bound is `PortalRule::DELEGATION_LEASE_SECONDS`, and it is the age of the confirmation's
`delegating` lease, not merely of its claim. A `confirmed_submitting` claim whose lease has passed that
bound with no recorded terminal outcome is reported by the §12 diagnostics with its capability, Lesson
and age, and the exact replay of its redemption key converges it by taking the lease over. A lease
inside the bound is a live delegation and is reported as in flight instead. Phase W adds no scheduled
task, no cron event and no background worker in this phase; convergence is driven by the replay, by the
administrator surface, or by a later, explicitly authorised worker.

## 16. Upstream fact extension map and legacy compatibility

### 16.1 What Phase W consumes, and what it adds

| Upstream authority | Fact Phase W consumes (read-only) | Fact Phase W adds | Invariant |
| --- | --- | --- | --- |
| Phase M Lesson | Lesson identity, kind, lifecycle, Enrolment and Term links | none on the Lesson | Phase W never creates, edits, completes, cancels or archives a Lesson |
| Phase N schedule | the applicable schedule version and its anchors, through `CanonicalLessonSchedulePortalReadPort` | the exact `schedule_version_id` the capability binds | Phase W never reschedules and never materialises an occurrence |
| Phase O delivery | effective outcome state for the declared summary, through `CanonicalLessonDeliveryPortalReadPort` | none | Phase W never records or reinterprets a delivery outcome |
| Phase P attendance | the portal-safe visible summary and absence finality/window answer, through `CanonicalAttendancePortalReadPort` | the capability-attributed claim of §10.1, recorded by Phase P in **Phase-P** storage | Phase W never reads review evidence, adjudicates, settles or writes a Phase-P row itself |
| Phase J Assignment | the current subject-scoped Teacher Assignment, through `TeacherAssignmentPortalReadPort` | none | Phase W never replaces or reassigns a Teacher |
| Phase M0 and L | Enrolment, Term and Student identity | none | Phase W never changes an Enrolment's or Term's lifecycle |
| Phase B and C identity | `teacher_principal_links`, `student_principal_links`, account claim | none | Phase W never links, relinks, revokes or claims an identity |
| Phase F authority | acceptance and guardian grants | none | Phase W never grants, revokes or supersedes an authority |
| Phase V provider integration | mapping metadata only, never a join URI | the sealed portal join target of §10.2, in **Phase-W** storage | Phase W makes no provider call, holds no credential and decrypts no provider secret |
| Phase U finance | nothing at all | nothing | no portal surface exposes a finance fact |

### 16.2 The declared upstream seam extensions

Phase W requires exactly two categories of additive owner extension, neither of which transfers
business authority to Phase W:

1. The four read-only, subject-scoped owner ports of §10.0. They preserve the existing administrator
   services unchanged and provide only the fields needed by §8 after the owner re-proves object
   authority. Until all four exist and their owner-specific authorization tests pass, authenticated
   Student/Teacher read surfaces remain unregistered.
2. §10.1's capability-attributed attendance intake path with the declared
   `public_capability_on_behalf` attribution member. It extends **Phase P's own authority**. Until it
   exists, `lesson_absence` minting refuses `portal_parent_not_live`; the Join purpose is unaffected.

These are prerequisite 2 of §20. This contract records their required shape but authorises no runtime
implementation in this preflight correction.

### 16.3 Legacy compatibility

- The live Amelia Employee and Customer panels remain the operational portal. Phase W replaces
  nothing, reads no Amelia table, bridges no Amelia session, performs no import, synchronisation or
  parity comparison, and writes nothing to Amelia (§22).
- The legacy `customerCabinetUrl` and the operational plugin's own portal links remain operational
  concerns. They are evidence about the legacy system; they never become Platform authority, and a
  Platform capability never derives from them.
- Manual Platform mappings and external or legacy references never grant portal access and never
  authorize a public action (DATA-MODEL §19), and Phase W never treats a WordPress author, an Amelia
  employee id or a Hamnavaz profile link as a principal.
- Coexistence is expected: a Student may hold an Amelia cabinet account and no Platform principal
  link, in which case the Student portal surface refuses `portal_principal_unresolved` and the legacy
  panel keeps operating. No dual-write, no shadow account and no automatic link is created by this
  phase.

## 17. Notification intents — deliberately none

Phase W publishes **no** intent and writes **no** `platform_outbox` row
(`PortalRule::PLATFORM_OUTBOX_WRITES = 0`, W-D15, §15.7). It renders no message, stores no template,
channel, recipient or address, and performs no delivery. The reason is explicit and load-bearing: a
capability link is a bearer credential, and distributing it is exactly the external-publication
decision this phase must not take. Any future intent that names a portal capability — for example a
later delivery of a Join link — is a new contract decision with its own channel, consent, expiry,
rotation and revocation rules, and it must not be inferred from this phase (§21, open decision 1).

For the same reason Phase W defines no notification-adjacent seam: the owning modules' existing
intents (Phase U's statement intents, Phase S's delivery surface and the live operational-plugin
notifications) are untouched, and no portal state is ever hydrated into one.

## 18. Test matrix

Every suite that needs the disposable WordPress and MariaDB runtime runs on it from a fresh clone, with
no network access and no real credential; the static source guards need only a PHP interpreter; and every
existing adjacent suite (L, M, M0, N, O, P, Q, R1, R2, T, U, V and `schema-contract.php`) must stay
green.

The matrix separates the two classes of evidence, because they have different prerequisites and only the
first is available in this environment.

- **Executed here — the PHP-only source guard and behavioural proofs.** A PHP interpreter is the only
  prerequisite. `tests/phase-2a2w-contract.php`, together with the behavioural proof it embeds from
  `tests/phase-2a2w-public-rate-limit-unit.php` and the §15.6 refusal-versus-failure proof it embeds from
  `tests/phase-2a2w-persistence-failure-unit.php`, passes under a local PHP 8.3/8.5 CLI WebAssembly runtime
  with no WordPress and no database; both embedded behavioural suites and
  `tests/phase-2a2w-replay-runtime.php`, `tests/phase-2a2w-remediation-contract.php` and
  `tests/phase-2a2w-theme-isolation-contract.php` also pass standalone under both versions; the PHP-free
  `tests/phase-2a2w-blocking-findings-contract.sh` passes as well; and 306 of
  306 `src/**/*.php` files parse clean under `token_get_all(..., TOKEN_PARSE)` under both versions. This is
  source-guard and PHP-level behavioural evidence only: it is not runtime, migration, concurrency, browser
  or WordPress evidence.
- **Not executed here — every suite that needs the disposable WordPress/MariaDB runtime.** A row's status
  is **not present, not executed** when this contract requires that suite, the file is absent from this
  candidate's tree, and no execution evidence exists for it; each such row is an acceptance gate this
  candidate has not met.

| Suite | Proves | Status in this environment |
| --- | --- | --- |
| `tests/phase-2a2w-contract.php` | source contract: the six tables, the migration identifier and its three call sites, the retained-031 verification, the single reason-code allowlist, the declared read-model versions, all four §10.0 owner ports, no portal call to the four administrator-only read services, no portal repository read of their tables, the fixed lock order's call sites, the declared capabilities and their Teacher-role absence, the §15.6 `PortalRule::refusalReason()`-before-`recordRefusal()` classification in both the public-action and administrative controllers, and the `PROVIDER_CALLS = 0`, `PLATFORM_OUTBOX_WRITES = 0`, `THEME_WRITES = 0` scans | written and **executed** — passes under a local PHP 8.3/8.5 CLI WebAssembly runtime with no WordPress and no database, including the behavioural proofs it embeds from `tests/phase-2a2w-public-rate-limit-unit.php` and `tests/phase-2a2w-persistence-failure-unit.php`; a static source guard, so this is not runtime, migration, concurrency or browser evidence |
| `tests/phase-2a2w-persistence-failure-unit.php` | the §15.6 refusal-versus-failure split: a declared business refusal still commits exactly one refused action row and one denial row in one transaction; a failed refused-action insert, a failed denial insert and a failed commit each roll the whole refusal evidence back and re-raise `portal_action_evidence_persistence_failed` with no committed row; a failing capability/action evidence write inside the invoked service propagates unchanged out of both registered public callbacks (`join` and `absence`) and appends no subsequent refusal row or denial because the controller opens no second transaction; the post-delegation owner handoff records a declared owner refusal as the refused outcome with the owner's own reason plus its denial row, while an owner persistence failure propagates unchanged and leaves only the committed redemption claim and delegation lease; only a declared refusal reason classifies as a refusal, never a declared persistence code, an unexpected exception or a PHP error; and every capability transaction boundary is checked — a failed `START TRANSACTION` or `COMMIT` through the real public Join callback or through `mint()`/`revoke()` re-raises the declared `portal_capability_persistence_failed` with no response object, no minted capability and no committed evidence, and the rollback runs only for an opened transaction | written and **executed** — passes standalone and embedded in the contract guard under a local PHP 8.3/8.5 CLI WebAssembly runtime with no WordPress and no database; a PHP-level behavioural proof against a stubbed store and stubbed owner ports, so this is not runtime, migration, concurrency or browser evidence |
| `tests/phase-2a2w-replay-runtime.php` | consumed-confirmation replay after principal revocation: the suite renders a one-time Student absence confirmation through the real `PortalPublicActionService::renderAbsenceConfirmation()`, consumes it through the real `::confirmAbsence()` (which appends the `confirmed_submitting` claim, the `consumed` capability event, the `consume` command and the `submitted` outcome), revokes the Student's principal link so a fresh `PortalCapabilityOwnerPort::binding()` with `requirePrincipal=true` refuses `portal_principal_required`, and then replays the exact same handle/token/confirmation through `::confirmAbsence()`/`verifyConsumed()`: the replay converges on the recorded `submitted` outcome with `replayed=true` because `verifyConsumed()` re-resolves the immutable Lesson/schedule/student proof with `requirePrincipal=false` instead of re-proving mutable owner state, and it appends no second confirmed-submitting claim, consumed event or consume command. The suite drives the real service classes against an in-memory stub of the portal evidence tables and the two owner ports it reads; the contract guard asserts its `renderAbsenceConfirmation(`, `confirmAbsence(`, `verifyConsumed(`, `requirePrincipal`, `portal_principal_required`, `replayed` and `consumed_action_event_id` seams | written and **executed** — the suite prints `Phase-W revoked-principal replay coverage passed` and exits 0 standalone under a local PHP 8.3/8.5 CLI WebAssembly runtime with no WordPress and no database; a PHP-level behavioural proof against a stubbed store, so this is not runtime, migration, concurrency or browser evidence |
| `tests/phase-2a2w-migration-runtime.php` | Schema 30 to 31 additive and repeat-safe, no existing row or column changed, no option seeded but the marker, and `dzn_platform_portal_actions` absent | written, **not executed** |
| `tests/phase-2a2w-principal-runtime.php` | resolution for each principal kind; zero, ambiguous and kind-mismatch refusals; a multi-role user resolving per surface; and that no parameter, cookie, header, email or meta changes the answer | written, **not executed** |
| `tests/phase-2a2w-authorization-runtime.php` | every §10.0 port re-proves the typed subject and object relationship for Student, guardian, Teacher and verified capability reads; another Student's Lesson, another Teacher's Assignment, a forged/mismatched subject, a capability-only or role-only attempt, an archived Lesson and a corrupt upstream aggregate all fail closed, with the Phase-W caller recording the durable denial | written, **not executed** |
| `tests/phase-2a2w-capability-runtime.php` | mint for both purposes and every refusal, binding, expiry, generation, rotation, revocation, suspected leak, one-way storage (no plaintext handle or token retrievable), sealed join target decrypted once, and the replayed-command convergence | written, **not executed** |
| `tests/phase-2a2w-public-action-runtime.php` | the three routes, the disabled-route refusal, the identical non-enumerating answer for every failure mode, the join redirect and its allowlist, and the absence two-step flow with the one-time confirmation, the `consumed` transition, the durable `delegating` lease, the delegated claim carrying `public_capability_on_behalf`, the outcome recorded after the owner's decision, the `portal_absence_submission_pending` refusal of a replay that arrives while another delegator holds the lease, and the refusal evidence — the refused action row **and** its `portal_access_denials` row — committing together in the one Lesson-root transaction | written, **not executed** |
| `tests/phase-2a2w-concurrency-runner.sh` (plus `-concurrency-setup.php`, `-concurrency-worker.php`, `-concurrency-wait.php` and `-concurrency-verify.php`) | `mint_vs_mint` (one winner per pair), `rotate_vs_redeem`, `revoke_vs_redeem`, `two_redemptions_one_confirmation`, `replay_during_delegation` (one delegation, one terminal outcome, no second delegation), `replay_after_crash` (an abandoned lease past the bound is taken over and converges), `refusal_vs_redeem` (the refused action row and its durable denial row commit together under the Lesson root), `outcome_vs_rotation`, `stale_schedule_vs_rotate` and `two_lessons_disjoint` (no contention); every contending mode additionally attributes the waiting worker to the Lesson's `portal_lesson_capability_roots` row — its `PRIMARY` row lock, or the declared `UNIQUE lesson_id` key where the insert-or-resolve creates it — through Performance Schema | written, **not executed** |
| `tests/phase-2a2w-corruption-runtime.php` | fail-closed behaviour for a truncated handle, a substituted token digest, a forged signature, a moved `expires_at`, a deleted action row, a corrupted sealed target and a substituted replay result | written, **not executed** |
| `tests/phase-2a2w-failure-runtime.php` | a persistence failure rolls the whole command back; a failed public refusal-evidence write rolls the refused action row and its denial row back together; a crash between the two redemption phases leaves a resolvable claim that the exact replay converges after the declared lease bound; a delegation refusal records the owner's reason and changes nothing; and an outcome recorded by a taken-over lease is returned rather than appended a second time | written, **not executed** |
| `tests/phase-2a2w-theme-isolation-contract.php` | source scan: no file written under any Theme path, no template, asset, block or shortcode registration, no `platform_outbox` write, no `wp_remote_*` or `curl_*`, no `wp_set_current_user`, and no Amelia table reference | written and **executed** — prints `Phase-W Theme/provider isolation contract passed` and exits 0 under a local PHP 8.3/8.5 CLI WebAssembly runtime with no WordPress and no database; a static source scan, so this is not runtime, migration, concurrency or browser evidence |

The executed suites above are source-guard and PHP-level behavioural evidence and settle nothing about the
disposable runtime: the WordPress/MariaDB suites above must be written and run on that runtime before
merge, and no candidate may claim the runtime, migration, concurrency or browser evidence it does not
have.

## 19. Recommended implementation task identity

| Field | Value |
| --- | --- |
| Task ID | `PLATFORM-W-PORTAL-FACING-SERVICES-IMPLEMENTATION` |
| Branch | `phase-2a2w-portal-facing-services` |
| Base | `main` with the Phase 2A.2-U candidate merged and closed (Schema 30), or an explicitly recorded base that contains Schema 30 |
| Dependency | `PLATFORM-LOCAL-TEST-RUNTIME` green (fresh install, migration, runtime, corruption, failure and concurrency) |
| Schema | 031 / `031_portal_facing_services_principal_authorization` |
| Build | `phase2a2w-portal-facing-services-20260927.2` |
| Review posture | single coherent candidate, independent review, additive-only descendant corrections |

## 20. Pre-implementation prerequisites

These gate execution, not the writing of this contract.

1. **Confirm Schema 031 and the migration identifier.** The owner brief says Schema 031; this
   checkout's highest declared migration is 030 and no `031_*` identifier exists (§0, §1). Confirm
   `031_portal_facing_services_principal_authorization`, or explicitly re-scope the ledger. Nothing
   may be implemented on an ambiguous number.
2. **Settle the base and the owning-authority seams.** Record the exact base (a tree that contains
   Schema 30); confirm the four narrow owner read ports of §10.0 and their typed subject contract; and
   either confirm the §10.1 capability-attributed attendance intake path with its
   `public_capability_on_behalf` attribution member or declare `lesson_absence` deferred for the first
   candidate. Phase W may not register authenticated Student/Teacher reads until all four owner-port
   authorization suites pass, may not be reviewed against a base lacking the tables its verifier
   inspects, and may not enable the absence action before Phase P admits it.
3. **Green disposable runtime.** The local test runtime must be able to run a fresh install, an upgrade
   from Schema 30, and the concurrency runner before Phase-W suites are added.
4. **Owner decisions on the four timing and exposure values** (§21, decisions 2 to 5): the capability
   TTL, the absence-report window, the join lead and lag window, and the public rate-limit budget. The
   contract locks the mechanism and the bounds; the values are the academy's.
5. **Owner confirmation of the public-route posture.** Confirm that public actions stay **disabled**
   (`dzn_platform_portal_actions` unset) in the first candidate, or authorise enabling them as a
   separate, explicitly reviewed decision with its own abuse-control and monitoring plan (W-D16).
6. **Owner confirmation of the distribution boundary.** Confirm that Phase W distributes nothing
   (W-D15, §17) and that delivering a capability link to a Student, Teacher or guardian is a later,
   separately authorised slice.
7. **Owner decision on the meeting-host allowlist and the join target's provenance** — an
   administrator-supplied value in the first candidate, or a Phase-V-approved descriptor later
   (§10.2).
8. **Confirm the capability repair contract** for the three new capabilities, including the removal of
   the two administrator-only ones from `dzn_teacher`, and the fresh-install path.
9. **Close the documentation debt in the same candidate** so a reviewer never reads stale prose:
   `README.md` (active and previous candidate rows), `docs/DELNAVAZAN-CORE-CONTINUITY.md` (state table
   and next action), `docs/ARCHITECTURE.md`, `docs/MODULE-BOUNDARIES.md` (a Phase 2A.2-W boundary block
   plus §8's owned, may-use and must-not lists and §11's service-boundary table),
   `docs/MIGRATION-STRATEGY.md` (a `031_portal_facing_services_principal_authorization` entry),
   `docs/DATA-MODEL.md` (portal storage, a Portal row in the state-separation matrix, and §20's
   remaining-design-work list), `docs/PRODUCT-DECISIONS.md` (§8's public-capability paragraph gains the
   implemented decision and keeps the deferred ones deferred), `docs/SECURITY.md` (§8 gains the
   implemented shape of §9 and the no-distribution boundary), `docs/CHANGELOG.md`, and a new
   `docs/PORTAL-AUTHORIZATION-REGISTRY.md` mirroring the finance registry's structure for the two
   purposes, the eight surfaces and the structural invariants of §5.
10. **Record the coexistence owner.** Confirm in writing that the Amelia Employee and Customer panels
    remain the operational portal for every Student and Teacher who has no Platform principal link,
    and that no live traffic is moved to a Platform portal by this phase.

## 21. Open owner decisions (deliberately not chosen here)

| Decision | Why this phase must not choose it | What ships instead |
| --- | --- | --- |
| 1. Whether and how a capability link is delivered to a Student, Teacher or guardian | Distribution is an external-publication decision with consent, channel and monitoring consequences | No distribution, no outbox write and no template; the link is returned only to the minting administrator (W-D15, §17) |
| 2. The capability TTL for each purpose | An operational exposure window, not a domain rule | `MAX_CAPABILITY_TTL_SECONDS` bounds it; the value is an owner decision, recorded on every mint |
| 3. The absence-report window — how far in advance, and whether after the occurrence | A policy and fairness decision that touches Phase P's admissibility | The mechanism and its refusal codes ship; the window itself is an owner decision, enforced by the owning authority |
| 4. The join lead and lag window | A usability decision for a public link | The sealed target and allowlist mechanism ship; the window is an owner decision enforced at verification |
| 5. The public rate-limit budget and its keying | An abuse-control tuning decision for a public surface | The declared best-effort limiter and its fail-open cache contract ship; the budget is an owner decision |
| 6. Whether rotation is automatic on reschedule, cancellation or archive | Automatic rotation needs a worker or a hook into an owning module's transition, and that hook is itself a contract decision | Verification already refuses a stale or non-visible capability; rotation remains an explicit administrator command with its declared reason |
| 7. Whether the join target is administrator-supplied or hydrates from a Phase-V-approved descriptor | A provider-relationship and boundary decision | The sealed-target column set ships unchanged; the provenance is an owner decision |
| 8. Whether a guardian may redeem a Student's absence capability | A consent and safeguarding decision | The guardian principal kind and its grant check exist; a public absence claim requires the owning authority's own admissibility, and this phase does not extend it to guardians |
| 9. Whether the Student portal shows any commercial, finance or payment field | A product and privacy decision | Nothing financial is exposed to any portal principal in this phase (§8.5) |
| 10. Whether the Join capability becomes single-use or occurrence-scoped | A usability decision with a security trade-off | Join redemption is repeatable evidence within the window; absence is single-submission (W-D12, W-D13) |
| 11. Retention and purge policy for redemption and denial evidence | Retention is a separate owner decision (PRODUCT-DECISIONS §11 and §15) | Append-only evidence with no purge path in this phase |
| 12. Whether a Platform portal ever replaces the Amelia panel, and when | That is the Phase 7 and Phase 10 cutover decision | No cutover, no dual-write, no shadow account and no traffic move |

## 22. Exclusions and explicit non-authorisation

This contract authorises no schema, migration, service, capability, route, template, asset, test,
option or configuration to be written, and no implementation, review, merge, deployment, production
access, production cutover, Amelia write or Amelia removal.

It creates no business authority: no Lesson, schedule version, delivery outcome, attendance case,
evidence or decision, academy obligation, Enrolment, Term, Teacher Assignment, principal link,
guardian grant, commercial, recurring, payment, finance, provider, integration or notification row is
created, changed or deleted by it.

It performs **no** Theme work: no template, block, shortcode, stylesheet, script, image, menu redesign
or visual-identity change, and no write under any Theme directory. It performs **no** external
publication: it sends nothing, notifies nobody, writes no `platform_outbox` row, publishes no link,
exposes no public page beyond the three declared and disabled-by-default routes, and makes no provider
call. It holds no credential and decrypts no provider secret, performs no deployment and touches no
production data, page, option or migration.

It schedules no event, creates no background worker, and decides none of the open decisions in §21.

## 23. Definition of done

- Schema 031 and migration `031_portal_facing_services_principal_authorization` are additive and
  repeat-safe from Schema 30, leave every existing row and column unchanged, create exactly the six
  declared tables, seed no capability and no option but its own capability marker, leave
  `dzn_platform_portal_actions` absent, and are proved by their own verifier running at all three call
  sites including the retained-031 and stale-version path.
- `PortalPrincipalResolver` resolves exactly one principal from the WordPress session for each declared
  kind, writes nothing, caches nothing, and refuses `portal_principal_required`,
  `portal_principal_unresolved`, `portal_principal_ambiguous` and
  `portal_principal_kind_not_permitted` without ever widening a requirement.
- Every portal read and command proves object-level ownership through the scoped owner ports of
  §10.0; each owner re-proves the typed subject and exact target, while another principal's record, an
  archived Lesson, a non-portal-visible target and a corrupt upstream aggregate each refuse with their
  declared code and cause Phase W to append one digest-only `portal_access_denials` row.
- Every declared read model carries its declared version, exposes exactly its declared fields, hydrates
  only through `TeacherAssignmentPortalReadPort`, `CanonicalLessonSchedulePortalReadPort`,
  `CanonicalLessonDeliveryPortalReadPort` and `CanonicalAttendancePortalReadPort`, fails closed rather
  than degrading, and exposes no email, phone, address, provider reference, secret, sealed value, raw
  digest, finance amount or another person's data.
- The four existing administrator/reviewer read services retain their capability checks unchanged;
  no Teacher receives a schedule, delivery, attendance-review or assignment-management capability,
  no Student capability or role is created, no owner port treats
  `dzn_view_own_portal_schedule` as object authority, and no Phase-W class reads an owning table
  directly.
- Capabilities exist for exactly the two declared purposes, are bound to an exact Lesson and an exact
  applicable schedule version, use a high-entropy handle whose keyed digest is the only stored lookup
  key, are verified in constant time, carry an absolute immutable expiry, and have exactly one live
  generation per `(lesson, purpose)` enforced by unique indexes.
- Rotation, revocation and the suspected-leak path are append-only in meaning, name their reason from
  the declared operator set, never re-activate or re-sign a row, and leave a stale, cancelled or
  archived capability refusing at verification even before any administrative rotation.
- The Join action appends evidence and answers one `302` to an allowlisted HTTPS target decrypted once
  from the capability's own sealed value, or refuses; it writes no delivery, attendance, participation,
  calendar, provider or notification fact and makes no provider call.
- The Absence action is two-step and single-submission, changes nothing on `GET`, records its claim
  before delegating, delegates only through the owning attendance authority, records only the owner's
  outcome, converges on an exact replay, and never states an attendance verdict of its own. The
  confirmation's own durable lease makes it exactly one delegation: a replay during a live delegation
  delegates nothing and refuses `portal_absence_submission_pending`, a replay after a crash takes the
  abandoned lease over past the declared bound, and an already-recorded terminal outcome is returned
  idempotently instead of appended again.
- A refused public request is outwardly identical for a malformed, unknown, forged, expired, revoked,
  superseded, stale-schedule, disabled-route and non-visible case, while the exact reason is durably
  recorded and visible to `dzn_view_portal_capabilities`.
- Exactly three capabilities exist, repaired per capability; the two administrator-only capabilities
  are absent from `dzn_teacher` and every other role and the verifier refuses an installation in which
  a Teacher role holds one; no WordPress role is created; and no student capability exists.
- Every portal write is serialised under `portal_lesson_capability_roots` and arbitrated by a declared
  command row; the only tables a portal code path writes outside the six portal tables are insert-only
  digest-only `platform_audit_events` evidence; and `platform_outbox` writes, provider calls, Theme
  writes and Amelia references are each proved zero by the §18 source scans.
- Every public refusal whose handle resolves to a capability, and every post-delegation outcome, takes
  the Lesson root `FOR UPDATE` and re-reads the capability under that lock before it appends; no
  portal path appends a capability-bearing evidence row outside the declared serialisation root.
- Every reason written anywhere in this phase is a member of `PortalRule::REASON_CODES`, and every
  refusal is durable — a refused command row, a refusal action row or a denial row — never silent.
- Every §18 suite passes on the disposable runtime from a fresh clone with no network access and no
  real credential, the concurrency matrix passes, and the adjacent L, M, M0, N, O, P, Q, R1, R2, T, U,
  V and `schema-contract.php` suites stay green.
- No Theme file, template, asset, shortcode, block, public publication, notification, provider call,
  credential, deployment or production access occurred, and every decision this phase must not take
  remains recorded as open in §21.
