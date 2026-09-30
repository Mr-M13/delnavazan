# Platform Phase 0 — Existing System Audit & Architecture: Migration Map

## 1. Purpose

This document records the completed architectural audit of the current
`delnavazan-enhancements` operational plugin. It classifies behaviours and data
so migration can preserve proven outcomes without preserving accidental
coupling.

Status: **COMPLETE**

Post-audit decision: the automated Amelia import, repeatable mapping, and
shadow/parity tooling considered in Phase 0 were superseded on 3 September 2026
by controlled manual recreation of the small active dataset. The component
classifications and runtime strangler direction remain valid. Interpret any
historical import/parity rationale in this audit through
[PRODUCT-DECISIONS.md](PRODUCT-DECISIONS.md); it does not authorize an importer,
synchronizer, parity engine, or new Core Amelia data-model dependency.

The classifications mean:

- **KEEP** — preserve the behaviour and its business invariant;
- **EXTRACT** — move the capability behind a dedicated module boundary with
  minimal behavioural change;
- **REFACTOR** — preserve the outcome but redesign ownership or data shape;
- **ADAPT** — retain as a transitional bridge while another system remains
  authoritative;
- **REPLACE** — introduce a Core-owned capability and transfer authority under
  explicit cutover gates;
- **RETIRE** — remove only after every dependent workflow has moved and rollback
  no longer needs it.

Classification describes the target treatment, not permission to change the
live plugin. Amelia remains installed, authoritative, and readable throughout
the early migration.

## 2. Audited source baseline

The audit used the Delnavazan Enhancements 2.0.17 repository/source baseline,
including Attendance database schema 9 and its bundled operational
documentation. Delnavazan Enhancements 2.0.16 with Attendance schema 8 remains
an earlier production acceptance milestone recorded in the operational history;
it is not the source baseline for this Platform audit. The current system treats
Amelia as authoritative for appointments, booking approval, employees,
customers, services, scheduled times, and Meet URLs. Delnavazan Enhancements
owns its attendance table, signed actions, Meet reconciliation, WhatsApp
workflow/delivery records, soft archive, and teacher payment reporting.

## 3. Component map

| Existing component | Current owner | Current dependency | Future module | Classification | Migration note | Retirement condition |
| --- | --- | --- | --- | --- | --- | --- |
| Attendance database business evidence | Delnavazan Enhancements attendance table | Amelia booking snapshots, Google evidence, WordPress database | Attendance | EXTRACT | Preserve the behaviour and required historical evidence in the existing system/archive while manually establishing active Core Lessons. Do not bulk-import source rows into operational Core. | Legacy writes stop only after controlled outcome comparisons, reports, support tools, and rollback reads are proven for the full retention window. |
| Amelia snapshot columns in attendance rows | Delnavazan Enhancements attendance table | Amelia customer booking, appointment, user, and service tables | Historical archive / transitional runtime | ADAPT | Keep required historical observations in an access-controlled read-only archive and existing runtime during coexistence; do not copy unnecessary raw provider data into Core. | Snapshot access can retire after operational dependencies and retention requirements are satisfied; original Amelia data remains preserved under the approved archive policy. |
| Signed Join/Absence links | Delnavazan Enhancements public routes | Attendance `public_id`, HMAC from WordPress salts, Amelia-derived Meet URL | Attendance + Portals | REFACTOR | Preserve opaque, action-bound, expiring links. Bind future capabilities to Core Lesson, purpose, expiry, and revocation version; keep GET confirm / POST mutate separation and safe error equivalence. | Old routes retire after every unexpired link has elapsed, replacement links are live, and notification templates no longer emit legacy URLs. |
| Meet overlap logic | Delnavazan Enhancements Google reconciliation | Google Meet API, participant display-name matching, Amelia teacher/student names | Attendance + Integrations/Google | KEEP | Preserve the rule that assigned teacher and booked student must overlap for the configured minimum, currently 20 minutes. Move provider calls to Google adapter and store normalized evidence against a Core Lesson. Ambiguity remains `review_needed`. | Legacy reconciler retires after controlled comparisons cover normal, missing-participant, duplicate-name, short-overlap, and API-failure cases. |
| Standalone Google OAuth | Delnavazan Enhancements attendance | WordPress callback, teacher identity inferred through Amelia provider/email | Integrations/Google | REFACTOR | Retain OAuth state, minimum scopes, offline access, exact teacher-account confirmation, and encrypted tokens. Start from authenticated Core Teacher identity rather than Amelia employee/email identity. | Legacy flow retires after all active connections are migrated or re-consented and Core portal connect/disconnect/revoke is verified. |
| Google token refresh | Delnavazan Enhancements attendance | Stored client credentials, encrypted refresh token, Google token endpoint | Integrations/Google | EXTRACT | Preserve proactive expiry handling, refresh errors, and durable invalid-connection state behind a provider interface. Never expose tokens to domain modules. | Old refresh code retires when every active account uses the new encrypted connection store and monitoring. |
| Google account storage | WordPress option `dln_attendance_google_accounts` | Email-indexed records, optional Amelia employee ID, WordPress salts | Integrations/Google | REFACTOR | Replace email as the storage key with stable `teacher_id`; record Google subject/account identifiers, granted scopes, source, expiry, and connection state. Migrate without logging plaintext tokens. | Option can be retained read-only, then archived, only after count/hash reconciliation and successful refresh or re-consent for each connection. |
| Amelia Employee Panel identity bridge | Delnavazan Enhancements teacher card/AJAX | Amelia cabinet token or login callback, Amelia `/users/current`, provider table, email match | Legacy Adapters + Portals | ADAPT | Keep only as a migration bridge. Resolve the verified Amelia employee to Core Teacher through `LegacyReference`; issue short-lived one-use grants exactly as today. | Retire when teacher portal authentication and Core Teacher linking no longer depend on Amelia sessions. |
| Amelia combined Google OAuth adapter | Delnavazan Enhancements Amelia hook adapter | Amelia Google Calendar OAuth hooks, matching OAuth client, renewable token exposure | Legacy Adapters + Integrations/Google | ADAPT | Preserve only while it reduces teacher re-consent and can prove scope/client/email correctness. New connections should use Core-owned OAuth once available. | Retire when no active teacher depends on Amelia-held Google consent and all connections have Core ownership. |
| WhatsApp Cloud API transport | Delnavazan Enhancements WhatsApp class | Meta Graph API, phone-number ID, access token, approved templates | Integrations/Meta | EXTRACT | Keep the proven HTTP contract, phone normalization, bounded Graph version, and accepted message ID capture. The adapter receives an authorised send command; it does not decide eligibility. | Legacy transport retires after the Notifications module uses the adapter for every active workflow and controlled delivery outcomes are verified. |
| Meta webhook status processing | Delnavazan Enhancements shared Amelia callback observer and plugin route | Meta webhook payload, query verify token, locally known Meta message IDs | Integrations/Meta + Notifications | REFACTOR | Verify with Meta's supported signature/token mechanisms, normalize provider events, update only known message IDs, enforce monotonic/event-time handling, and make duplicate delivery idempotent. | Legacy observer/route retires after Meta points to the new endpoint and sent/delivered/read/failed updates are observed in production with rollback available. |
| Notification delivery tracking | Attendance columns and term-renewal option history | Meta message IDs and webhook events | Notifications | EXTRACT | Create generic notification, attempt, provider-message, and delivery-event records. Preserve Accepted/Sent/Delivered/Read/Failed distinctions and error diagnostics without mixing them into attendance. | Legacy columns/options become read-only after all reports and support views read the new records and reconciliation shows no orphan message IDs. |
| Reminder workflow rules | Delnavazan Enhancements scheduled WhatsApp workflows | Amelia schedules/status, customer timezone, attendance link, configured send time | Notifications + Academy | REFACTOR | Express eligibility as versioned policies over Core Lesson/Enrolment state. Preserve disabled-by-default workflow settings, customer-timezone rendering, required-field checks, duplicate guards, and separate manual/test paths. | Amelia-based scans retire after controlled tests and an agreed observation period validate Core eligible/skipped outcomes. |
| Amelia notification hooks | Delnavazan Enhancements hooks for booking/status/scheduled notifications | Amelia WordPress hooks and cron trigger | Legacy Adapters | ADAPT | Translate documented Amelia events into versioned internal events during coexistence. Do not let Notifications query Amelia directly. | Retire after Core owns the triggering lesson/enrolment changes and no production workflow depends on Amelia hooks or cron. |
| Term-renewal counting | Delnavazan Enhancements renewal query/history | Amelia approved bookings, provider/service/customer IDs, 12-session heuristic | Academy + Notifications | REFACTOR | Replace inferred term blocks with separate explicit Enrolment and Term records and remaining-session state. Preserve the current 10-past/2-future production behaviour until approved Core rules take authority. | Heuristic retires after active Terms are manually established, edge cases are reviewed, and controlled eligibility differences are approved. |
| Context flattening and extraction | `DLN_Attendance_Context` | Variable Amelia hook payload shapes and suffix-based key matching | Legacy Adapters | RETIRE | Keep only inside the Amelia anti-corruption layer. Prefer typed adapter DTOs, explicit versioned field maps, and rejected/diagnostic outcomes over broad recursive matching. | Retire after every supported Amelia event/API path uses typed mapping and malformed-payload tests cover historical variants. |
| Payability logic | Attendance row `payable` and `payment_note` | Attendance outcome, Amelia service category 3, administrator override | Finance & Reporting | REFACTOR | Preserve separation of attendance, payability, and archive. Move automatic intro non-payable policy and manual override audit to Finance; snapshot teacher rate/currency at the relevant business boundary. | Legacy flags remain readable until all historical statements reproduce and override provenance is migrated. |
| Archive lifecycle | Delnavazan Enhancements attendance | `archived_at`, `archived_by`, retention setting, daily cleanup | Core infrastructure + owning modules | KEEP | Standardize reversible archive metadata per aggregate. Permanent purge remains a separate authorised retention job and may not delete active financial or attendance evidence. | Existing cleanup retires only after the new retention job targets equivalent records and a restore/purge audit proves scope. |
| Secret encryption | `DLN_Attendance_Secrets` | WordPress `secure_auth` salts, Sodium or AES-256-GCM | Core infrastructure | EXTRACT | Preserve authenticated encryption and non-autoloaded storage. Add key-version metadata, rotation/recovery procedure, failure visibility, and strict redaction. Salt changes require re-entry or migration planning. | Old ciphertext is removed only after every secret decrypts/re-encrypts successfully or the owner explicitly reauthorises the provider. |
| Teacher payment reporting | Delnavazan Enhancements administrator report/export | Attendance rows, teacher email, Amelia changes, date-range sync | Finance & Reporting | REFACTOR | Query Core Teacher, Lesson, Attendance, and effective-dated teacher-rate/currency snapshots. Preserve exact date ranges, distinct source-appointment counting during migration, zero-value intro visibility, archive exclusion, and audited corrections. | Legacy report retires after totals/statements match approved production periods and finance signs off on differences. |
| Amelia IDs and references | Scattered attendance columns, queries, options, and history markers | Amelia employee/customer/appointment/customer-booking/service IDs | Historical archive + required Core mappings | ADAPT | Manually record only identifiers required for active operation, with provider scope, target Core identity, provenance, uniqueness, and administrator review. Keep broader history in the read-only archive. Never authorize using an external ID alone. | Required mappings remain traceable after Amelia retirement; transitional runtime code retires when no workflow reads Amelia. |
| Password helper around Amelia | Delnavazan Enhancements enrolment helper | Amelia confirmation DOM and native password action | Portals + Legacy Adapters | RETIRE | Preserve current native handoff while Amelia owns customer accounts. Do not reproduce brittle private-state/DOM coupling in Core; create a deliberate Core account invitation/password workflow later. | Retire after Core student authentication is authoritative and new/repeat-student end-to-end tests pass without Amelia controls. |
| Current Amelia booking/customer/employee authority | Amelia | Amelia tables, UI, sessions, scheduling, and approval state | Core + Academy + Portals | REPLACE | Transfer one bounded authority at a time. Amelia remains authoritative during coexistence through the existing operational plugin; new Core code does not add an Amelia data-model dependency. Record cutover time and owner per capability. | Retire only when all exit gates in `MIGRATION-STRATEGY.md` pass; never delete Amelia tables at initial cutover. |

## 4. Additional audited surfaces

The following Delnavazan Enhancements capabilities are real but are not early
Core migration targets:

| Surface | Phase 0 decision |
| --- | --- |
| Site presentation modules | Leave in Delnavazan Enhancements. Theme/Amelia selectors require page-by-page visual regression and do not belong in Core identity. |
| Meta Pixel | Leave with its current single-owner overlap guard until a separate analytics scope is approved. It is not part of Amelia-exit identity. |
| Password-reset email | Preserve operationally; move only with a future Portals identity cutover. |
| Administrator navigation | Preserve current URLs during coexistence. Future Platform menus must avoid duplicate ownership and redirect only under an approved admin migration. |

## 5. Cross-cutting invariants to preserve

- A link click is evidence, not proof of attendance.
- Attendance requires assigned teacher and booked student overlap for the
  configured minimum or an audited manual decision.
- Scheduling state, attendance outcome, notification delivery, payability, and
  archive state are distinct.
- A provider failure cannot undo a committed business fact.
- Production notification markers are written only after provider acceptance;
  test sends do not mutate production history.
- Source-system deletion or cancellation must not erase completed historical
  evidence.
- Schema migrations, domain operations, provider calls, and webhooks are
  idempotent where required; no Amelia importer is planned.
- Teacher/student identity never depends on email matching alone in the target
  architecture.
- Amelia remains readable and rollback-capable throughout coexistence.

## 6. Audit contradictions and risks

1. The current attendance row is simultaneously an Amelia snapshot, attendance
   aggregate, notification ledger, payment input, and archive record. The target
   separates these concerns without discarding the source row.
2. Current Google connections are keyed primarily by email and may also carry an
   Amelia employee ID. Both are mappings; neither is a stable teacher identity.
3. Current attendance `status` includes both attendance outcomes and Amelia
   cancellation reactions. Target scheduling and attendance states must be
   separate.
4. Current term renewal infers a term from twelve Amelia appointments. The target
   requires separate explicit Enrolment and Term records before that heuristic
   can be retired.
5. The current local Google disconnect removes stored credentials but does not
   demonstrate provider revocation. Target disconnect and revoke semantics need
   an explicit product and support policy.
6. Current webhook protection includes a secret URL key and shared Amelia
   callback observation. The target must implement the provider-supported
   verification contract before owning the endpoint.
7. Customer timezone may fall back to a phone-country heuristic. Core needs an
   explicit timezone with provenance and a documented fallback policy.
8. The audited Google storage assignment contains a duplicated
   `amelia_employee_id` key. It has no separate second meaning and should not be
   copied into the target model.

These are migration inputs, not authorisation to patch the current production
plugin during Phase 0.
