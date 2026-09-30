# Portal authorization registry

## Scope, status and authority order

This is the canonical, implementation-derived registry for the Phase 2A.2-W portal-facing services
(`src/Portals/`, migration `031_portal_facing_services_principal_authorization`). It records what this
checkout registers and calls. It approves no provider activation, public enablement, Theme work,
deployment, production access or cutover.

The portal slice's own historical base is `2ab0c71f5cc53ca8aa4241db0b2f7d100de65997`
(tree `4f1b7273f823149688ca3b75f9ed8b268a01d802`, inspected 2026-09-27) — the **historical Schema-31
portal base**, which carried the portal-facing services together with the additive correction that
materialises the public rate-limit admission in `PortalPublicActionController` on each registered route's
own fixed surface and with its declared fail-open scope. That revision is provenance for the portal rules
recorded below; it is not the identity of this candidate.

**Current package identity.** `delnavazan-platform.php` declares
`DZN_PLATFORM_SCHEMA_VERSION` = **33** and build
`phase2a2s-notification-communications-authority-20260924.1`. Schema 33 adds
`033_core_dataset_technical_prerequisites`: five bounded readiness-evidence tables
(`core_dataset_provenance`, `core_dataset_reconciliation_runs`,
`core_dataset_reconciliation_findings`, `core_dataset_operator_commands` and
`core_dataset_corrections`). The migration is verified before its completed-ledger record and before the
schema option advances. It changes no portal table, portal rule or portal reason code described below,
creates no domain rows and performs no backfill.

**Historical Schema-32 integration identity.** Schema 32 / build
`phase2a2s-notification-communications-authority-20260924.1` was the additive
`032_notification_communications_authority` slice on Schema 031. It added notification storage and the
`platform_outbox` extension, not portal authority. The W slice's own historical candidate stamp remains
`phase2a2w-portal-facing-services-20260927.2`. These facts are provenance; Schema 32 is not the current
package schema.

**Authority order (highest first).** A conflict is resolved in this order and never by older prose:

1. Current `main` source and migrations.
2. Completed merge/review records where applicable.
3. This registry.
4. Historical contracts and prose.

If this registry and current source disagree, source wins and this registry is corrected in the same
change. This document is a derived record: it may not introduce a rule, route, capability, purpose or
reason code that current source does not contain.

**Execution evidence in this environment (2026-09-27).** The only executed validation this registry
cites for the current candidate is its PHP-only suite set, run under a local PHP 8.3/8.5 CLI WebAssembly
runtime with no WordPress and no database: the static source guard `tests/phase-2a2w-contract.php` with
both embedded behavioural proofs (`tests/phase-2a2w-public-rate-limit-unit.php` and
`tests/phase-2a2w-persistence-failure-unit.php`, whose §15.6 coverage now includes the checked capability
transaction boundaries), the source guards `tests/phase-2a2w-remediation-contract.php` and
`tests/phase-2a2w-theme-isolation-contract.php`, the PHP-free
`tests/phase-2a2w-blocking-findings-contract.sh`, and the revocation-replay
behavioural proof `tests/phase-2a2w-replay-runtime.php`, which renders and consumes a one-time Student
absence confirmation through the real `PortalPublicActionService::confirmAbsence()`, revokes the Student's
principal link, then replays the same confirmation through `verifyConsumed()` and asserts one converged
`submitted` outcome with no duplicate evidence — driving the real service classes against an in-memory
stub of the portal tables and owner ports. Each exits 0 under both versions. Both anchors that name that
guard record it. No runtime, migration, concurrency or browser evidence exists for this candidate, and
none is claimed.

**Completeness rule.** Every W `register_rest_route()` registration has exactly one row below; every W
capability grant and every public option gate is accounted for; every `PortalCapabilityService`
operation is classified as exposed or internal-only; all owner seams and durable-evidence tables are
named; and no row asserts an authenticated portal route, admin mutation UI, provider call, outbox write,
Theme surface, activation or S-on-main state that current source/topology does not support. A reviewer
runs this check against the closed column set below.

## Immutable registry columns

The rows in the registry section below use exactly these columns. The schema is closed: a new fact
requires a column-schema decision, never an ad-hoc field.

| Column | Meaning and closed domain |
| --- | --- |
| `registry_id` | Stable identifier `portal.<surface>.<verb>.v1`; never reused for a different surface. |
| `status` | One of `exposed`, `internal-only`, `unbound`, `not-implemented` for the current source. |
| `entrypoint/method` | The exact registered route and HTTP method, or the internal/none entrypoint. |
| `caller credential` | What the caller must possess: WordPress session + capability, a public handle+token, or internal PHP. |
| `feature gate` | The exact runtime gate that must pass before the row can act. |
| `required capability` | The capability the row requires, named exactly; `none` for the public rows. |
| `principal model` | Session principal resolution, public-capability subject, WordPress role grant, or none. |
| `object binding/owner proof` | The owner-supplied proof binding the action to its exact Lesson/schedule/state. |
| `allowed effect` | The single bounded effect the row may perform. |
| `durable evidence` | The exact table(s) and state the row appends. |
| `outward failure` | The exact outward result and refusal handling on failure. |
| `data prohibited` | Data the row may never return or persist. |
| `owning seams` | The owner port(s)/service(s) the row delegates to. |
| `source/test anchors` | Primary source path(s) and the guard/test that asserts the row. |

## Registry rows

### `portal.public.join.v1`

| Column | Value |
| --- | --- |
| `registry_id` | `portal.public.join.v1` |
| `status` | `exposed` (route registered; refuses unless the exact option gate is enabled) |
| `entrypoint/method` | `GET /wp-json/delnavazan-platform/v1/portal/join?handle={64-hex}&token={token}` — registered by `PortalPublicActionController::register()` with `permission_callback = __return_true` |
| `caller credential` | Possession of a valid, unexpired, active `lesson_join` handle + token; no WordPress session principal |
| `feature gate` | `get_option('dzn_platform_portal_actions', '') === 'enabled'`; a missing or any other value refuses `portal_route_disabled`. Migration 031 does not seed this option. Every request first passes the best-effort rate-limit admission on this row's own `portal_public_join` surface — the constant its registered callback passes, never request text — which refuses `portal_rate_limited`. |
| `required capability` | none (public); the `lesson_join` capability is an access artefact, not a WordPress capability |
| `principal model` | No session principal. The action is attributed to the capability's immutable owner binding. |
| `object binding/owner proof` | `PortalCapabilityOwnerPort::binding()` re-proves purpose, Lesson integrity/lifecycle, schedule ownership and applicability; the sealed token binds owner facts + generation + expiry and must reproduce exactly; handle must match `^[a-f0-9]{64}$`; token must be at least 64 characters; capability must be active and unexpired; the join target must be a sealed allowlisted HTTPS host. |
| `allowed effect` | Append one `redirected` action event and return `302 Location` to the unsealed, allowlisted join target. Nothing else. |
| `durable evidence` | `portal_public_action_events` row with `action_state = 'redirected'` (unique `redemption_key_digest`). |
| `outward failure` | Uniform non-enumerating `404 {"code":"portal_action_unavailable"}` with `Cache-Control: no-store` and no `Referrer-Policy`; the reason is recorded in `portal_access_denials` when that table exists. Success sends `Cache-Control: no-store` and `Referrer-Policy: no-referrer`. |
| `data prohibited` | Any attendance, provider, outbox, Theme or business-state write; plaintext handle/token, provider secret, join-target plaintext or PII. |
| `owning seams` | `PortalOwnerPorts::capability()` → `CanonicalLessonPortalCapabilityOwner`; `PortalCapabilityService::join()`; `PortalCapabilityService::unseal()`. |
| `source/test anchors` | `src/Portals/PortalPublicActionController.php` (`join()`), `src/Portals/PortalCapabilityService.php` (`join()`); `tests/phase-2a2w-contract.php`, including its embedded `tests/phase-2a2w-public-rate-limit-unit.php` proof (executed against this candidate under a local PHP 8.3/8.5 CLI WebAssembly runtime — no WordPress, no database — and passes; a source guard, not runtime, migration or concurrency evidence). |

### `portal.public.absence.render.v1`

| Column | Value |
| --- | --- |
| `registry_id` | `portal.public.absence.render.v1` |
| `status` | `exposed` (route registered; refuses unless the exact option gate is enabled) |
| `entrypoint/method` | `GET /wp-json/delnavazan-platform/v1/portal/absence?handle={64-hex}&token={token}` — same controller and public callback |
| `caller credential` | Possession of a valid, unexpired, active `lesson_absence` handle + token; no WordPress session principal |
| `feature gate` | Same exact-value gate as `portal.public.join.v1`; otherwise refuses `portal_route_disabled`. The same best-effort rate-limit admission runs first on the `portal_public_absence` surface — the constant this route's registered callback passes, never request text — and refuses `portal_rate_limited`. |
| `required capability` | none (public); the `lesson_absence` capability is an access artefact |
| `principal model` | No session principal; the capability's bound Student and owner facts apply. |
| `object binding/owner proof` | Same owner binding as the join row, plus the absence path requires a live Student and ordinarily exactly one active principal link; a mismatch fails closed. |
| `allowed effect` | Render a one-time absence-confirmation form and append `confirmation_rendered` with a fresh confirmation digest. No business-state write. |
| `durable evidence` | `portal_public_action_events` row with `action_state = 'confirmation_rendered'` and `confirmation_digest`. |
| `outward failure` | Uniform non-enumerating `404 {"code":"portal_action_unavailable"}` with `Cache-Control: no-store` and no `Referrer-Policy`; denial recorded when the table exists. Success is `200 text/html` with `Cache-Control: no-store` and `Referrer-Policy: no-referrer`. |
| `data prohibited` | Any settlement of attendance or change to Lesson, schedule, finance or commercial state; raw tokens or PII. |
| `owning seams` | `PortalCapabilityService::verify()`; `CanonicalAttendancePortalReadPort::assertCapabilityClaimAdmissible()`; `PortalPublicActionService::renderAbsenceConfirmation()`. |
| `source/test anchors` | `src/Portals/PortalPublicActionService.php` (`renderAbsenceConfirmation()`), `src/Portals/PortalPublicActionController.php` (`absence()`); `tests/phase-2a2w-contract.php`. |

### `portal.public.absence.confirm.v1`

| Column | Value |
| --- | --- |
| `registry_id` | `portal.public.absence.confirm.v1` |
| `status` | `exposed` (route registered; refuses unless the exact option gate is enabled) |
| `entrypoint/method` | `POST /wp-json/delnavazan-platform/v1/portal/absence/confirm` with `handle`, `token`, `confirmation` |
| `caller credential` | A matching, one-time rendered confirmation plus the valid absence handle + token; no WordPress session principal |
| `feature gate` | Same exact-value gate as `portal.public.join.v1`; otherwise refuses `portal_route_disabled`. The same best-effort rate-limit admission runs first on the `portal_public_absence` surface — the constant the absence callback passes for both absence routes, never request text — and refuses `portal_rate_limited`. |
| `required capability` | none (public); the `lesson_absence` capability is an access artefact |
| `principal model` | No session principal. The handoff is attributed to the consumed capability, never a synthetic user. |
| `object binding/owner proof` | The rendered `confirmation_digest` must match; the capability must still reproduce its immutable Lesson/schedule/student binding; the owner admissibility preflight must pass; the capability is consumed atomically under the per-Lesson root. |
| `allowed effect` | Atomically consume the capability and record `confirmed_submitting`; then delegate evidence-only to `CanonicalAttendanceIntakeService::submitCapabilityClaim()` outside the portal transaction; finally record `submitted` or `refused`. It never settles attendance itself. |
| `durable evidence` | `portal_public_action_events` (`confirmed_submitting`, then `submitted`/`refused`), `portal_public_capability_events` (`consumed`), `portal_public_capability_commands` (`consume`), and the `portal_public_capabilities` row set to `state = 'consumed'`. |
| `outward failure` | A refused owner outcome is recorded as `refused` and rethrown, producing the uniform `404` shape with `Cache-Control: no-store` and no `Referrer-Policy`; a replay of a `submitted` claim converges from the recorded result. A successful response sends `Cache-Control: no-store` and `Referrer-Policy: no-referrer`. |
| `data prohibited` | Any settlement of attendance or change to Lesson, schedule, finance or commercial state; any administrator impersonation; raw confirmation/token storage. |
| `owning seams` | `PortalOwnerPorts::attendance()` → `CanonicalAttendancePortalReadPortImpl` → `CanonicalAttendanceIntakeService::submitCapabilityClaim()`; `PortalCapabilityService::verifyConsumed()`. |
| `source/test anchors` | `src/Portals/PortalPublicActionService.php` (`confirmAbsence()`), `src/Core/Application/PortalOwnerReadPorts.php`; `src/Core/Application/CanonicalAttendanceIntakeService.php`. |

### `portal.admin.capability.view.v1`

| Column | Value |
| --- | --- |
| `registry_id` | `portal.admin.capability.view.v1` |
| `status` | `exposed` (WordPress-admin screen; no REST route) |
| `entrypoint/method` | WP Admin submenu `dzn-portal-capabilities` under `dzn-platform` (`add_submenu_page`); no `register_rest_route()` |
| `caller credential` | WordPress admin session |
| `feature gate` | `admin_menu` registration; the render callback re-checks the capability |
| `required capability` | `dzn_view_portal_capabilities` |
| `principal model` | WordPress administrator (capability-based) |
| `object binding/owner proof` | none; read-only list |
| `allowed effect` | Render a read-only list of at most 100 capability rows (`ORDER BY id DESC LIMIT 100`). No mutation. |
| `durable evidence` | none (read-only) |
| `outward failure` | The render callback returns without output when the capability is absent; no data is exposed. |
| `data prohibited` | Any mutation; capability secrets; plaintext handle/token. |
| `owning seams` | `PortalCapabilityController` (reads `{prefix}dzn_portal_public_capabilities`) |
| `source/test anchors` | `src/Admin/Controller/PortalCapabilityController.php` |

### `portal.admin.capability.manage.v1`

| Column | Value |
| --- | --- |
| `registry_id` | `portal.admin.capability.manage.v1` |
| `status` | `internal-only` — **internal-only / not externally exposed**. There is currently no registered WP-admin mutation controller and no REST route; do not read this row as a UI. |
| `entrypoint/method` | Internal PHP service only: `PortalCapabilityService::mint()`, `::revoke()`, `::rotate()`, `::transition()`. No HTTP or admin-screen entrypoint. |
| `caller credential` | WordPress admin session of the internal caller |
| `feature gate` | Each mutator checks `current_user_can('dzn_manage_portal_capabilities')` and throws `Unauthorized` otherwise. |
| `required capability` | `dzn_manage_portal_capabilities` |
| `principal model` | WordPress administrator (capability-based) |
| `object binding/owner proof` | Per-Lesson serialisation root `portal_lesson_capability_roots`; generation arbitration on `(lesson_id, purpose, generation)` and the one-active slot; command-key digest replay guard; owner binding via `PortalCapabilityOwnerPort::binding()`. `mint()` accepts only the two purposes, requires a future expiry within `PortalRule::MAX_CAPABILITY_TTL_SECONDS` (2592000 s), validates a HTTPS allowlisted join host for `lesson_join`, and seals the target. |
| `allowed effect` | Create, revoke or rotate portal capability access artefacts only. |
| `durable evidence` | `portal_public_capabilities`; `portal_public_capability_events` (`minted`/`rotated`/`revoked`); `portal_public_capability_commands` (`mint`/`rotate`/`revoke` here; `consume` is written only by the public absence-confirm path). |
| `outward failure` | A typed `\RuntimeException`/`\InvalidArgumentException` to the internal caller; a replayed command key with a different payload refuses `command_replay_conflict`. No outward HTTP surface exists to normalize. |
| `data prohibited` | Any business-state mutation; provider call; outbox write; Theme write; plaintext handle/token at rest (only digests are stored). |
| `owning seams` | `PortalCapabilityService`; `PortalOwnerPorts::capability()` (`CanonicalLessonPortalCapabilityOwner`). |
| `source/test anchors` | `src/Portals/PortalCapabilityService.php` (`mint()`, `revoke()`, `rotate()`, `transition()`); `tests/phase-2a2w-contract.php`. |

### `portal.authenticated.reads.v1`

| Column | Value |
| --- | --- |
| `registry_id` | `portal.authenticated.reads.v1` |
| `status` | `internal-only` — **implemented internal seam; no exposed surface** |
| `entrypoint/method` | **No HTTP entrypoint.** No authenticated Student/Teacher/principal portal route is registered. |
| `caller credential` | n/a (internal PHP only) |
| `feature gate` | n/a |
| `required capability` | n/a |
| `principal model` | `PortalPrincipalResolver::resolve()` maps a WordPress session to exactly one teacher, student or guardian principal link, failing closed on zero (`portal_principal_unresolved`) or more than one (`portal_principal_ambiguous`). |
| `object binding/owner proof` | `PortalAccessPolicy::assertObject()` plus the owner read ports re-prove the typed subject's ownership/visibility before returning a projection. |
| `allowed effect` | Internal read-model calls only (`PortalReadModels`, owner read ports). No mutation, no HTTP response. |
| `durable evidence` | A denied object check appends `portal_access_denials` when the table exists. |
| `outward failure` | A typed exception to the internal caller; there is no REST surface to render. |
| `data prohibited` | Email, phone, address, bank/card/tax data, provider account or mapping identifiers, credentials, raw tokens, raw evidence payloads, finance amounts, another person's data, or a raw owning-table response. |
| `owning seams` | `PortalPrincipalResolver`, `PortalAccessPolicy`, `PortalReadModels`, `PortalOwnerReadPorts` (`CanonicalTeacherAssignmentPortalReadPort`, `CanonicalLessonSchedulePortalReadPortImpl`, `CanonicalLessonDeliveryPortalReadPortImpl`, `CanonicalAttendancePortalReadPortImpl`) |
| `source/test anchors` | `src/Portals/PortalPrincipalResolver.php`, `src/Portals/PortalOwnerPorts.php`, `src/Core/Application/PortalOwnerReadPorts.php` |

### `portal.teacher.own-schedule.grant.v1`

| Column | Value |
| --- | --- |
| `registry_id` | `portal.teacher.own-schedule.grant.v1` |
| `status` | `unbound` — **unbound grant; not authorization for a surface** |
| `entrypoint/method` | **No consuming entrypoint.** No current route, controller or read port consumes this grant. |
| `caller credential` | n/a |
| `feature gate` | n/a |
| `required capability` | `dzn_view_own_portal_schedule` (repaired onto the administrator role and `dzn_teacher`) |
| `principal model` | WordPress role grant only |
| `object binding/owner proof` | none — the grant is not consumed |
| `allowed effect` | none; the capability grant exists but reaches no surface |
| `durable evidence` | none |
| `outward failure` | n/a |
| `data prohibited` | The grant must never be extended to `dzn_manage_portal_capabilities` or `dzn_view_portal_capabilities` on `dzn_teacher`. |
| `owning seams` | Capability repair in `Migrator::ensure_capabilities()` |
| `source/test anchors` | `src/Core/Infrastructure/Migration/Migrator.php` (`$phaseWGrants`); absence of a consuming route in `src/Portals/` |

## `PortalCapabilityService` operation classification

Every `PortalCapabilityService` operation is classified as exposed or internal-only. "Exposed" means a
registered W entrypoint reaches the operation as that entrypoint's action; "internal-only" means it is a
PHP seam with no entrypoint of its own, or is reached only beneath another row's entrypoint.

| Operation | Classification | Reached by |
| --- | --- | --- |
| `join()` | exposed | `portal.public.join.v1` (Join route) |
| `mint()` | internal-only | `portal.admin.capability.manage.v1`; also beneath `rotate()` |
| `rotate()` | internal-only | `portal.admin.capability.manage.v1` |
| `revoke()` | internal-only | `portal.admin.capability.manage.v1` |
| `verify()` | internal-only | `portal.public.absence.render.v1` (absence-render route) |
| `verifyConsumed()` | internal-only | `portal.public.absence.confirm.v1` replay path |

`verify()` and `verifyConsumed()` are public PHP methods but not HTTP operations; they are implementation
seams used by the public action services. `verifyConsumed()` permits a replay to re-establish the
immutable proof without requiring a still-active principal link; it still checks the stored handle/token,
purpose and immutable owner binding. `transition()` is private and is reached only through `revoke()`.

## Controlled vocabularies

These values are copied from source, not aliased or paraphrased.

### Purposes (exactly two)

```text
lesson_join
lesson_absence
```

### Capability states (exactly four)

```text
active
consumed
revoked
superseded
```

### Current safe join hosts (exactly three)

```text
meet.google.com
zoom.us
teams.microsoft.com
```

`PortalRule::SAFE_JOIN_HOSTS` is the single source. A `lesson_join` mint requires scheme `https` and a
host from this exact list, otherwise `portal_join_target_not_allowlisted`; an empty target refuses
`portal_join_target_not_declared`.

### Feature gate

The option is `dzn_platform_portal_actions` and the only enabling value is `enabled`. An absent option
or any other value means every public route refuses with `portal_route_disabled`. Migration 031 does not
seed the option, so route registration is not an enabled public service.

### Administrator capabilities

```text
dzn_manage_portal_capabilities
dzn_view_portal_capabilities
```

Both are administrator-only and are removed from `dzn_teacher` if present. There is no current
WP-admin mutation screen for them; `dzn_manage_portal_capabilities` gates an internal service only.

### Teacher grant

```text
dzn_view_own_portal_schedule
```

Repaired onto the administrator role and `dzn_teacher`. The Teacher role must not receive either
administrator capability.

### Operator reason set (exactly seven)

```text
phase_w_declared_default
operator_decision
operator_recorded_error
operator_suspected_leak
operator_reschedule_rotation
operator_cancellation_rotation
operator_archive_rotation
```

### Exception reason codes (exactly `PortalRule::EXCEPTION_REASON_CODES`, 35)

Reproduced verbatim; the registry adds no reason of its own.

```text
portal_vocabulary_member_not_allowed
portal_parent_not_declared
portal_parent_not_live
portal_principal_required
portal_principal_unresolved
portal_principal_ambiguous
portal_principal_kind_not_permitted
portal_object_not_found
portal_object_not_owned
portal_object_not_portal_visible
portal_upstream_aggregate_invalid
portal_capability_unknown
portal_capability_handle_malformed
portal_capability_signature_invalid
portal_capability_expired
portal_capability_revoked
portal_capability_superseded
portal_capability_consumed
portal_capability_purpose_mismatch
portal_capability_stale_schedule
portal_capability_expiry_missing
portal_capability_ttl_not_allowed
portal_capability_binding_mismatch
portal_capability_generation_conflict
portal_join_target_not_allowlisted
portal_join_target_unavailable
portal_join_target_not_declared
portal_absence_window_closed
portal_absence_outcome_final
portal_absence_late_evidence
portal_confirmation_required
portal_confirmation_invalid
command_replay_conflict
portal_rate_limited
portal_route_disabled
```

`PortalRule::REASON_CODES` is the ordered concatenation of the seven operator codes above followed by
these 35 exception codes (42 total). No other value is accepted by `PortalRule::reason()`.

## Class A — structural invariants (never configurable)

These are fixed properties of the W boundary. A change is a platform change, not configuration. The
registry may only assert the value and point to its source.

| Invariant | Where it lives |
| --- | --- |
| The only public purposes are `lesson_join` and `lesson_absence` | `PortalRule::JOIN`, `PortalRule::ABSENCE`; `mint()` rejects any other member |
| A public action has no session principal and acts only through a bound capability | `PortalPublicActionController`; W contract §8 |
| The public-route gate is an exact value, and route registration never enables the service | `PortalRule::PUBLIC_ACTION_OPTION`, `PUBLIC_ACTION_ENABLED_VALUE`; migration 031 seeds nothing |
| A capability binds purpose + Lesson + schedule version + generation + expiry under one version | `PortalRule::CAPABILITY_BINDING_VERSION` (`portal_capability_binding_v1`), `PortalCapabilityService::bind()` |
| Handle entropy is 32 bytes (64 hex characters) | `PortalRule::HANDLE_ENTROPY_BYTES` |
| Only digests are persisted for handle, token, command key, redemption and evidence reference | Migration 031 digest columns; migration verifier digest-shape check |
| A Join target is sealed with AES-256-GCM and unsealed only for a verified Join redirect | `PortalCapabilityService::seal()` / `unseal()`; key version `dzn_portal_join_target`, cipher version `aes-256-gcm-v1` |
| A Join target host must be an allowlisted HTTPS host | `PortalRule::SAFE_JOIN_HOSTS`; `mint()` |
| Evidence tables are append-only (no `updated_at`, no mutable history) | Migration verifier append-only check |
| Public failures are non-enumerating | `PortalPublicActionController::refused()` |
| W performs no provider call, no outbox write and no Theme write | `PortalRule::PROVIDER_CALLS`, `PLATFORM_OUTBOX_WRITES`, `THEME_WRITES` all `0` |
| There is no authenticated portal HTTP surface and no admin mutation UI; capability mutation is internal-only | `portal.admin.capability.manage.v1`, `portal.authenticated.reads.v1` and `portal.teacher.own-schedule.grant.v1`; no such route in `src/Portals/` |
| Exactly one active capability per `(lesson, purpose)` | `UNIQUE lesson_purpose_active(lesson_id, purpose, active_slot)` |
| The absence handoff is evidence-only to the attendance owner | `CanonicalAttendanceIntakeService::submitCapabilityClaim()`; attribution `public_capability_on_behalf` |

## Class B — runtime configurable values (owner decisions, not registry-owned)

These are operational values the academy owns. This registry records no value and seeds nothing; an
unset value is the absence of a recorded value, never a fabricated default. Each is enforced by the
named source seam, not by this document.

| Value | Recorded default | Accepted value or bound | Enforced by |
| --- | --- | --- | --- |
| Capability TTL per purpose | none | a future instant no more than `PortalRule::MAX_CAPABILITY_TTL_SECONDS` (2592000 s) away; otherwise `portal_capability_ttl_not_allowed` / `portal_capability_expiry_missing` | `PortalCapabilityService::mint()` |
| Join lead/lag window | none | owner-enforced at verification | W contract §21.4; `PortalCapabilityService::check()` |
| Absence reporting window | none | owner-enforced by the owning authority; refusal `portal_absence_window_closed` | `CanonicalAttendanceIntakeService` admissibility |
| Public rate-limit budget and keying | declared best-effort limiter on every public request, fail-open | owner budget; the declared best-effort budget applies until the academy records one, and keying is a salted digest of the client network signal plus the presented handle | `PortalPublicActionController::admit()` invokes `PortalRateLimiter::allow($surface,$fingerprint)` before the option gate and before any verification, where `$surface` is the invoked route's own fixed constant (`SURFACE_JOIN` / `SURFACE_ABSENCE`) passed by its callback and never request text; refusal `portal_rate_limited` |
| Public limiter failure scope | declared fail-open | the request proceeds; nothing is refused as `portal_rate_limited` | `PortalPublicActionController::admit()` returns normally on any `\Throwable` from fingerprinting or `allow()`, so only a completed `allow()` returning `false` refuses |
| Rotation on reschedule/cancellation/archive | explicit administrator command with a declared reason | one of the seven operator reason codes | `PortalCapabilityService::rotate()` |
| Join-target provenance | administrator-supplied sealed target | Phase-V descriptor hydration remains an open owner decision | W contract §21.7; `mint()` |
| Retention and purge of redemption/denial evidence | append-only with no purge path in this phase | owner retention decision | Migration 031 append-only tables; W contract §21.11 |
| Distribution of a capability link | none; the link is returned only to the minting administrator | owner publication decision | W contract §21.1 |

`phase_w_declared_default` is the recorded reason for a mint and consume that reflects no other owner
decision; it is a member of the operator reason set above.

## Migration-031 storage contract (exactly six tables)

Migration `031_portal_facing_services_principal_authorization` creates exactly these six additive
access-artefact tables and no other. Logical names below are the audit names; the physical InnoDB table
is `{prefix}dzn_<logical>` where `{prefix}` is `$wpdb->prefix`. The migration verifier asserts the exact
column set, the `id bigint unsigned AUTO_INCREMENT` primary key, InnoDB engine, the named indexes,
`char(64)` digest shapes on the listed columns, append-only status (no `updated_at`) for the four
evidence tables, the one-active-capability and consumption-completeness relationships, and the absence
of a legacy `portal_actions` table.

| Logical table | Columns | Indexes |
| --- | --- | --- |
| `portal_lesson_capability_roots` | `id`, `lesson_id`, `created_at`, `created_by` | `PRIMARY(id)`, `UNIQUE lesson(lesson_id)` |
| `portal_public_capabilities` | `id`, `uid`, `lesson_id`, `schedule_version_id`, `subject_student_id`, `purpose`, `generation`, `handle_digest`, `token_digest`, `join_target_ciphertext`, `join_target_nonce`, `join_target_key_version`, `join_target_cipher_version`, `join_target_host`, `state`, `active_slot`, `issued_at`, `expires_at`, `consumed_at`, `consumed_action_event_id`, `revoked_at`, `revoked_by`, `revocation_reason_code`, `superseded_by_capability_id`, `reason_code`, `created_at`, `created_by` | `PRIMARY(id)`, `UNIQUE uid(uid)`, `UNIQUE handle_digest(handle_digest)`, `UNIQUE token_digest(token_digest)`, `UNIQUE lesson_purpose_generation(lesson_id,purpose,generation)`, `UNIQUE lesson_purpose_active(lesson_id,purpose,active_slot)`, `KEY lesson_purpose_state(lesson_id,purpose,state)`, `KEY subject_student(subject_student_id)`, `KEY expiry(expires_at)` |
| `portal_public_capability_events` | `id`, `uid`, `lesson_id`, `capability_id`, `event_sequence`, `event_type`, `purpose`, `generation`, `reason_code`, `evidence_channel`, `evidence_reference_digest`, `recorded_at`, `created_at`, `created_by` | `PRIMARY(id)`, `UNIQUE uid(uid)`, `UNIQUE lesson_sequence(lesson_id,event_sequence)`, `KEY capability_event(capability_id,event_type)` |
| `portal_public_capability_commands` | `id`, `uid`, `command_domain`, `operation`, `command_key_digest`, `command_payload_digest`, `lesson_id`, `purpose`, `expected_capability_id`, `expected_generation`, `expected_state`, `join_target_host`, `reason_code`, `result_capability_id`, `result_state`, `created_at`, `created_by` | `PRIMARY(id)`, `UNIQUE uid(uid)`, `UNIQUE command_key_digest(command_key_digest)`, `KEY lesson_operation(lesson_id,operation)`, `KEY result_capability(result_capability_id)` |
| `portal_public_action_events` | `id`, `uid`, `capability_id`, `lesson_id`, `purpose`, `action_sequence`, `action_state`, `resolved_student_id`, `confirmation_digest`, `redemption_key_digest`, `handoff_target`, `handoff_reference_id`, `outcome_reason_code`, `request_fingerprint_digest`, `occurred_at`, `created_at`, `created_by` | `PRIMARY(id)`, `UNIQUE uid(uid)`, `UNIQUE redemption_key_digest(redemption_key_digest)`, `UNIQUE capability_sequence(capability_id,action_sequence)`, `KEY lesson_action(lesson_id,action_state)`, `KEY capability_action(capability_id)` |
| `portal_access_denials` | `id`, `uid`, `surface`, `principal_kind`, `principal_id`, `capability_id`, `lesson_id`, `target_kind`, `target_id`, `reason_code`, `request_fingerprint_digest`, `occurred_at`, `created_at` | `PRIMARY(id)`, `UNIQUE uid(uid)`, `KEY surface_reason(surface,reason_code,occurred_at)`, `KEY principal_time(principal_id,occurred_at)` |

**Prohibited in persistence.** Plaintext handles and tokens, provider secrets, join targets in plaintext,
raw request payloads and PII are prohibited from every one of these tables. Handle, token, command-key,
redemption, confirmation, evidence-reference and request-fingerprint material is stored only as a
`char(64)` digest, and a Join target is stored only as AES-256-GCM ciphertext plus nonce and key/cipher
version.

## Owner seams and authority boundaries

| Seam | Current implementation | What it may do | What it does not grant |
| --- | --- | --- | --- |
| Capability binding | `CanonicalLessonPortalCapabilityOwner` via `PortalOwnerPorts::capability()` | Validates purpose, Lesson integrity/lifecycle, schedule ownership and applicability; absence additionally requires a live Student and ordinarily exactly one active principal link. | No WordPress capability, provider access, direct public business decision or mutation of canonical facts. |
| Assignment read | `CanonicalTeacherAssignmentPortalReadPort` | Returns scoped assignment projections for a typed authenticated subject. | No registered REST read, no public-capability assignment read, no unrestricted listing. |
| Schedule read | `CanonicalLessonSchedulePortalReadPortImpl` | Re-proves typed subject ownership/capability binding and returns a small Lesson/schedule projection. | No registered REST read; no raw owning-table response. |
| Delivery read | `CanonicalLessonDeliveryPortalReadPortImpl` | Derives a limited delivery-state summary after schedule ownership proof. | No registered REST read or delivery mutation. |
| Attendance read / handoff | `CanonicalAttendancePortalReadPortImpl` | Preflights a public absence claim and delegates a confirmed capability claim to `CanonicalAttendanceIntakeService`. | Phase W does not settle attendance, create a session or perform administrator impersonation. The attendance owner decides its own acceptance and writes its own canonical evidence. |

The absence path is implemented, not merely planned: it records the portal claim, then calls
`submitCapabilityClaim()` outside the portal transaction. That Core method checks the consumed capability,
capability-to-occurrence binding, Student link, window and finality conditions before recording evidence
with `public_capability_on_behalf` attribution.

## Evidence and failure registry

| Condition / operation | Durable portal evidence | Outward result or propagated internal refusal |
| --- | --- | --- |
| Mint, rotate or revoke | `portal_public_capabilities`, `portal_public_capability_events` and `portal_public_capability_commands` rows; `portal_lesson_capability_roots` serializes the operation | Internal caller receives a service result or exception. Command replay with a different payload refuses `command_replay_conflict`. |
| Join redemption | `portal_public_action_events` state `redirected` | `302` only after verification and unsealing; all controller failures use the uniform `404` body. |
| Absence confirmation rendered | `portal_public_action_events` state `confirmation_rendered` | `200` confirmation HTML, or uniform `404` if the public controller catches a failure. |
| Absence submission | `confirmed_submitting`, then `submitted` or `refused` in `portal_public_action_events`; the consumed `portal_public_capability_events` and `portal_public_capability_commands` rows | Successful owner result is `200`; replay can converge from the recorded claim/result. A refused owner outcome is not reported as success. |
| Public-route failure | `portal_access_denials` when the table exists, with a permitted reason code and request-fingerprint digest | Always `404 {"code":"portal_action_unavailable"}` and `Cache-Control: no-store`; the public response does not disclose whether the handle, token, state or target existed. |
| Public rate-limit refusal | `portal_access_denials` row with `reason_code = "portal_rate_limited"` and the invoked callback's own registered `surface` value when the table exists | The same uniform `404 {"code":"portal_action_unavailable"}` and `Cache-Control: no-store`; the admission runs before the option gate and before any capability verification or action-event append. |
| Persistence, corruption or infrastructure failure | **None** — the command's own transaction has already rolled back and no refusal record is appended beside it | The declared failure (`portal_action_evidence_persistence_failed`, `portal_capability_persistence_failed`) or the unexpected throwable is re-raised unchanged: both the public-action controller and the administrative capability command convert a throwable into refusal evidence only when it carries a declared refusal reason (`PortalRule::refusalReason()`), so a failure can never be followed by a durable refusal. The same split governs the post-delegation owner handoff: only a declared owner refusal is recorded as the refused outcome (with the owner's reason and its denial row in that transaction); an owner failure propagates and leaves only the already-committed redemption claim and delegation lease, which the §15.8 bound and an exact replay converge. |
| Authenticated internal object failure | `PortalAccessPolicy::deny()` writes `portal_access_denials` when the table exists | The internal caller receives the typed exception; there is no current REST exposure. |

Only a throwable whose message is a declared refusal reason (`PortalRule::EXCEPTION_REASON_CODES`) is a
business refusal: it is normalized onto the uniform outward shape above and its exact reason code is
recorded durably. A throwable that carries no declared refusal reason — an unexpected error, or one of the
declared persistence codes, which are deliberately **not** refusal-reason vocabulary members — is
propagated unchanged after its rollback and writes no denial row, no refused action row and no refusal
command row (§15.6).

## Prohibited data and non-authorised behaviour

No current W portal read model returns email, phone, address, bank/card/tax data, provider account or
mapping identifiers, credentials, raw tokens, raw command/redemption keys, sealed join-target ciphertext
or nonce, raw evidence payloads, finance amounts, or another person's data. The public link itself is not
durably stored in plaintext: the capability table stores handle/token digests, while join targets are
AES-256-GCM sealed and only unsealed for a verified Join redirect.

The implementation does not activate providers, call a provider, decrypt a provider credential, write a
`platform_outbox` notification, send or publish a link, modify Theme assets/templates, alter canonical
Lesson/schedule/delivery/attendance truth directly, create a WordPress user/session, deploy, or perform a
production cutover. `SAFE_JOIN_HOSTS` is an allowlist for a sealed redirect target; it is not provider
activation or provider authority.

## Evidence index

| Fact | Primary source |
| --- | --- |
| Schema, build, registration and owner configuration | `delnavazan-platform.php` lines 11–14 and 30–46 |
| Migration 031, six table declarations, verifier and capability repair | `src/Core/Infrastructure/Migration/Migrator.php` |
| Route registration, rate-limit admission, gate, headers and uniform public failure | `src/Portals/PortalPublicActionController.php` |
| Best-effort public abuse control | `src/Portals/PortalRateLimiter.php`, invoked by `PortalPublicActionController::admit()` on the invoked route's fixed surface constant; guarded statically by `tests/phase-2a2w-contract.php` and behaviourally by `tests/phase-2a2w-public-rate-limit-unit.php` (embedded by that guard) |
| Capability lifecycle, verification, sealing and redirection | `src/Portals/PortalCapabilityService.php` |
| Two-step absence evidence and handoff sequencing | `src/Portals/PortalPublicActionService.php` |
| Owner-port contracts and denial recording | `src/Portals/PortalOwnerPorts.php` and `src/Core/Application/PortalOwnerReadPorts.php` |
| Capability-attributed attendance decision | `src/Core/Application/CanonicalAttendanceIntakeService.php` |
| Purposes, states, hosts, gate and reason codes | `src/Portals/PortalRule.php` |
| Static source guard | `tests/phase-2a2w-contract.php`, including the behavioural proof it embeds from `tests/phase-2a2w-public-rate-limit-unit.php` (executed in this environment against this candidate under a local PHP 8.3/8.5 CLI WebAssembly runtime — no WordPress, no database — and passes under both; no runtime, migration, concurrency or browser evidence is claimed) |
