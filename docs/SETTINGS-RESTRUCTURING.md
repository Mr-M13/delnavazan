# Settings restructuring, delegated access and diagnostics

**Branch:** `feat/settings-rbac-diagnostics-20261110`
**Scope:** Delnavazan Admin Dashboard → Settings. No production data, credentials, deployments or merges are part of this change.

## 1. Initial architecture findings

- Visible Settings navigation was rendered by `AdminShellController`; `Menu.php` remained the WordPress route/capability authority.
- `GeneralSettingsController` owned site identity, contact details, a WordPress page browser, legal-page drafts, a catalogue shortcut, the retired LiveKit recording-capture switch, the old work-area access panel and a link to account-removal discovery.
- `PoliciesController` already owned consent evidence, consent jobs, notice versions and the introductory booking horizon, but also carried an introductory search/jump-navigation block and several separate consent tables.
- `StaffAccessService` granted existing capabilities at work-area level (`view`/`manage`) plus a small sensitive-action list. `StaffAccessController` embedded that panel inside General and re-registered the same editor at `dzn-staff-access`.
- `DiagnosticsController` rendered one flat status table driven by `DiagnosticsReadService`, with a separate notification evidence surface elsewhere.
- Google Workspace OAuth, Meet classroom provisioning and classroom reconciliation were already one backend authority but were presented as two cards: “Google Workspace classroom” and “Google Meet classroom”.
- The LiveKit recording-capture architecture is retired from active routes. Google Meet + Google Drive recording artefacts, consent notices and historical/legal-hold evidence remain active or required for audit.
- The custom account-removal area was a read-only dependency discovery screen. It had no deletion command. No scheduled deletion job, deletion REST endpoint or Delnavazan deletion service existed.
- The notification workspace had already been redesigned; this change does not rebuild it.

## 2. Inventory classification

| Area | Frontend | Backend / data | Classification |
| --- | --- | --- | --- |
| Pages and project browser | `GeneralSettingsController::renderPages` | WordPress `get_pages`, `dzn_project_page_id` | Remove UI; preserve pages and URLs |
| Courses & instruments shortcut | General card | `dzn-catalogue-workspace`, course/instrument services | Remove from General; catalogue lives under Enrolment |
| Legal-page drafts | General section, `LegalPagesController` | `LegalPageProvisioner`, WordPress pages | Migrate UI to Settings → Policy; preserve drafts, titles, slugs and URLs |
| Recording capture switch | General section | `RecordingControlPlane`, `RecordingFeatureFlags` | Remove UI/handler; preserve durable control plane and historical state |
| Work-area access | General embedded panel, `dzn-staff-access` | `StaffAccessService`, user meta, audit table | Replace with granular roles; keep legacy route as a redirect; reuse capability mutation seam |
| Account removal | General link, `dzn-account-removal` | `AccountRemovalDiscoveryService` | Remove controller/route/UI; preserve read-only discovery helper for lawful privacy review |
| Operational policy jump nav | Policies | `CommercialPolicyService` | Remove; replace with cards and versioned controls |
| Consent operations | Policies | `ConsentOperationsReadService`, append-only decision/evidence tables | Consolidate presentation only; no change to decisions, versions or enforcement |
| Recording operations tab | `dzn-recordings` | `RecordingOperationsController`, `RecordingControlPlane` | Remove admin route and emergency-stop UI; preserve archive/legal-hold backend |
| Google classroom cards | Integrations | `GoogleWorkspaceOAuthService`, projection/classroom tables | Consolidate to one “Google Workspace & Meet” card |
| Diagnostics | Settings → Diagnostics | `DiagnosticsReadService`, `NotificationDiagnosticsReadService` | Replace flat table with registry + lazy accordion; preserve support bundle and recovery links |
| Notifications | Settings → Notifications | Catalogue, template and dispatch services | Preserve unchanged; only navigation consistency |

## 3. Authorisation model

`PermissionRegistry` defines **module → functional area → action** permissions. Every action names one or more canonical capabilities that controllers and services already enforce server-side. `AccessRoleService` stores:

- custom role definitions (`dzn_access_roles_v1` option);
- per-user role assignments and explicit allow/deny overrides (`dzn_access_role_assignment_v1` user meta);
- resolved effective permissions and their sources.

`StaffAccessService::syncCapabilities()` is the shared capability-mutation seam. It only touches capabilities named by the permission catalogue, preserves unrelated WordPress/portal capabilities, refuses administrator targets and self-grants, verifies the resulting grant set, and audits the change. A failed audit fails the assignment closed.

Safety properties:

- default deny: unknown roles/permissions grant nothing;
- least privilege: template roles do not include class deletion, payment execution or access administration;
- privilege escalation: management of access itself is reserved to WordPress administrators and cannot be delegated through a custom role;
- self-grant: refused;
- primary administrator: protected by `dzn_primary_administrator_id` (falls back to the first administrator);
- administrators: read-only in the local interface;
- legacy work-area grants: inferred read-only and shown as “legacy” until the account is saved into a role assignment;
- server-side enforcement: unchanged controllers continue to check the canonical capabilities projected by the role.

### Known boundary

Some existing business authorities expose one capability for several closely related actions (for example teacher record view and edit both use `dzn_manage_teachers`). The granular catalogue can only split actions where the canonical authority already distinguishes them. Splitting a capability further requires a separate controller/service change and was not fabricated inside the role layer. The interface displays the canonical capability beside each action so this boundary is explicit.

## 4. Policy consolidation

- Settings → **Policy** is the canonical interface for policy cards, versioned commercial values, legal-page drafts, consent evidence, notice versions and withdrawal processing.
- Legal-page provisioning moved unchanged from General. It still creates/refreshes **unpublished drafts** only; WordPress remains the publication authority.
- Consent presentation is one “Consent Management” section: summary, filterable register, guardian attention, withdrawals, exceptions, recording/AI evidence, policy versions, evidence inspector and durable jobs.
- No consent decision write path was added. Existing evidence, notice versions, guardian authority and enforcement services are untouched.

## 5. Diagnostics architecture

`DiagnosticRegistry` is an extensible registry. Each check declares an id, category, label, description, required permission, implementation, status mapping, safe detail, optional recovery link and related area. Categories are collapsed by default; detail is loaded through an authenticated `admin-post.php` request with a nonce. The top summary is cached for 60 seconds and can be refreshed explicitly.

Categories: Platform & System Health, Booking & Coordination, Classes & Attendance, Google Workspace & Meet, Payments & Finance, Notifications & Delivery, Teacher & Student Portals, Consent & Policies, Background Jobs & Queues, Database & Migrations, Security & Authorisation, External Integrations.

Reliability and safety:

- a failed check becomes `unknown`, never healthy;
- checks are aggregate/redacted and make no automatic provider network calls;
- no check mutates state;
- state-changing recovery is separated and links to existing authorities;
- support output contains technical codes and counts, not credentials, message bodies, contact data or financial amounts.

## 6. Recording and account-removal boundaries

Preserved deliberately:

- Google Workspace OAuth, Calendar events, Meet spaces, classroom lifecycle and playback.
- Google Drive recording references, recording artefacts, notice versions and recording consent evidence.
- Historical recording metadata, legal hold and archive/withdrawal workers.
- `RecordingControlPlane` durable safety primitives, including the emergency-stop state used by archive/legal-hold operations, even though the retired LiveKit administration UI and command are no longer exposed.
- `AccountRemovalDiscoveryService` as a read-only privacy/retention helper.

Removed deliberately:

- LiveKit recording-capture switch and its General Settings handler.
- `dzn-recordings` Settings tab and its admin route registration.
- Retired emergency-stop admin command/UI.
- `dzn-account-removal` controller and route.
- Duplicate work-area access editor; the legacy route now redirects to delegated access.

## 7. Database and compatibility

No new database table or destructive migration was introduced. New state uses existing WordPress options/user meta and the existing audit table. Merges/deploys are intentionally excluded from this workstream.
