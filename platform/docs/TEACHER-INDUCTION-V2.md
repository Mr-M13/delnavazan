# Teacher induction v2

Teacher induction v2 is the educational companion to invitation-led onboarding. It teaches an
approved Teacher how to use the existing portal; it is not a second identity, activation, access,
scheduling, absence, finance or notification authority.

## Two separate experiences

| Experience | Authority | Purpose |
| --- | --- | --- |
| Account onboarding | `TeacherOnboardingService` + `TeacherAvailabilityService` | identity, profile, availability, Google readiness and administrator review |
| Teacher induction | `TeacherInductionService` | progressive education and versioned acknowledgement |

The canonical onboarding lifecycle remains exactly:

`linked_pending` → `in_progress` → `pending_review` → `returned` / `rejected` → `active` → `offboarded`

Induction uses a separate progress model with three presentation states:

- `not_started`
- `in_progress`
- `completed`

Completing or skipping induction never changes `teacher_onboarding_states`, Teacher readiness,
principal authority or portal access. An active, ready Teacher keeps normal portal access with
unfinished induction. Completed induction stays available for review.

## Canonical modules

Content is code-owned and versioned in `platform/content/induction/*.php`; the loader is
`TeacherInductionCurriculum`. Rendering never defines policy.

1. `portal` — actual tabs, notifications and where actions live.
2. `availability` — positive-only weekly availability, exceptions, timezone and Golden Hours.
3. `responsibilities` — daily checks, joining, connection issues and outcome evidence.
4. `schedule-changes` — proposal, student acceptance/rejection, three-rejection escalation,
   completed occurrences and canonical scheduling conflicts.
5. `absence-replacement` — the Term replacement allowance, excess absence, allocation, rejected
   proposals, teacher non-delivery, disputes and linked replacement Lessons.
6. `finance` — summary, statements, Golden Hours, illustrative tuition and discrepancy reporting.
7. `escalation` — twelve operational scenarios and the administrator/support route.

Each module declares a stable id, title, description, objectives, portal screen, sections,
scenario cards, optional safe knowledge checks, completion criteria and a content version.
`acknowledgement_version` is zero unless a material policy change requires an explicit versioned
re-acknowledgement. Minor copy edits should reuse the same content version and never invalidate
prior completion.

## Persistence

Additive migration `064_teacher_induction_progress` creates
`{prefix}dzn_teacher_induction_progress`:

- `teacher_id`, `module_id`, `state`
- `content_version`, `acknowledged_version`
- `completed_at`, `last_visited_at`
- `version`, `created_at`, `updated_at`, `created_by`, `updated_by`
- unique `teacher_module(teacher_id,module_id)`

One row per Teacher/module makes completion idempotent. The repository writes through
`ON DUPLICATE KEY UPDATE`; completion time is preserved across repeated requests. No invitation
secret, provider credential, finance value or free-text Teacher content is stored.

The existing onboarding tables were not sufficient because they describe one lifecycle state and
three coarse readiness facts, not per-module educational progress and versioned acknowledgement.

## Security

- The controller requires an authenticated WordPress user and `check_admin_referer`.
- The request may name only a canonical curriculum module; it cannot name a Teacher.
- The service resolves the linked Teacher from the authenticated principal and re-proves the same
  live readiness authority used by `PortalAuthenticatedReadService`.
- Rejected, offboarded, unlinked or no-longer-ready Teachers fail closed before any read or write.
- Progress rows are read and written only for the resolved Teacher; there is no cross-teacher id.
- The induction screen and hub use `nocache_headers()` through the existing protected portal route.

## Presentation

- Compact dashboard card `راهنمای شروع تدریس در دلنوازان` with progress
  `۲ از ۷ بخش تکمیل شده`, continue, view-all, skip and help links.
- Dedicated `teacher-view=induction` screen with module list and module reader.
- Inline, dismissible `<details>` guidance on availability, rescheduling, absence, notifications
  and finance. It links to the full module and never replaces the existing portal tour.
- The existing tour no longer auto-opens when the induction card is present.
- Desktop/mobile layout, RTL, native controls, visible focus and reduced-motion support.
- Knowledge checks are local-only and never submit a scheduling or finance mutation.

## Tests

- `platform/tests/teacher-induction-unit.php` — curriculum, progress, idempotency and refusal.
- `platform/tests/teacher-induction-contract.php` — authority, migration, presentation and
  no-duplicate-authority guards.
- Existing onboarding, portal, reschedule, replacement, finance and notification contracts remain
  the runtime authority for operational behaviour.
