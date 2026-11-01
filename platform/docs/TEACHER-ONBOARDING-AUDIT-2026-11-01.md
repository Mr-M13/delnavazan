# Teacher onboarding and induction audit — 2026-11-01

Scope: current `main` at the start of the induction change. This audit inspected the Theme,
Platform application services, repositories, migrations, source contracts and release documents.
No production data or external provider was touched.

## Confirmed current state

### Account lifecycle

The canonical lifecycle is invitation-led and stored in `teacher_onboarding_states` with an
append-only `teacher_onboarding_events` ledger:

`linked_pending → in_progress → pending_review → returned / rejected → active → offboarded`

- Claim creates `linked_pending / not_ready`; it never activates a Teacher.
- `TeacherOnboardingService` requires an active, non-archived Teacher, a valid display name,
  email, country, IANA timezone, locale, calendar preference, an active matching availability
  profile and at least one active `preferred`/`requestable` recurring rule.
- Runtime readiness **also requires a usable Google Calendar connection** with an active
  credential, verified identity and the exact calendar scope. `TeacherOnboardingService::progress()`
  returns `ready_for_activation = profile && availability && google`.
- Submission moves the Teacher to `pending_review / not_ready`. Only
  `dzn_manage_onboarding` may approve, return or reject. Approval moves to `active / ready`.
- A returned Teacher may edit and resubmit. A rejected Teacher remains denied; a new controlled
  invitation/offboarding path is required.
- An active Teacher whose last usable availability rule is retired is demoted to
  `in_progress / not_ready` and the portal fails closed to onboarding.
- `can_edit` is true for `linked_pending`, `in_progress`, `returned` and `active`; `can_submit` is
  true only when readiness is complete.
- The active Teacher may edit profile fields through Settings; availability mutations continue to
  use `TeacherAvailabilityService`.

### Teacher Portal screens and real authority

| Visible area | Actual authority | Teacher actions | Preconditions | Success / failure | Student interaction | Admin involvement | Notifications | Production |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| امروز (Home) | `PortalAuthenticatedReadService::teacher` + readiness gate | read tasks, open classes, notifications | `active / ready` and live availability proof | honest empty/error states | none directly | approval only | in-app inbox | enabled |
| کلاس‌ها + تقویم | canonical lessons/schedules and booking classes | open details, join, export, propose time | active lesson, valid schedule version, join window | join/proposal refusals are explicit | student accepts/rejects proposals | escalation after three rejections | proposal/rejection/admin rows | enabled |
| تنظیمات | `TeacherOnboardingService` + `TeacherAvailabilityService` | profile, instruments, recurring availability, day off, Google, preferences | editable lifecycle state; active availability profile for self-service | overlap/timezone refusals | none | none for ordinary edits | none | enabled |
| مالی | `TeacherStatementReadService` + `TeacherFinanceSummaryReadService` + illustrative allocation | read only | active/ready Teacher | truthful unavailable/empty states | none | finance review outside portal | statement/service notifications | enabled where data exists |
| اعلان‌ها | portal notification read model | read one/all | authenticated teacher | read state only | none | none | inbox rows | enabled |
| تغییر زمان | `LessonRescheduleService` + canonical schedule store | propose future time | teacher owns lesson, future instant, availability, no conflict, no pending proposal | proposal pending; student decision commits/refuses | required before canonical change | three rejections → exception queue | proposal/accept/reject/admin | enabled |
| غیبت و جلسهٔ جبرانی | attendance cases + `TermReplacementAllocationRule` + replacement lifecycle | propose replacement time only when an allocated slot exists | eligible student-origin absence, allocated slot, no dispute/delivery outcome | excess absence creates no teacher task | student accepts/rejects | exception review | absence/replacement rows | enabled |
| ثبت برگزاری/حضور | Phase-P attendance evidence + interim completion policy | submit delivery evidence | same class/version, permitted state | evidence recorded; not final finance | student may report/dispute | administrator adjudicates | workflow notifications | enabled |
| کلاس آشنایی و نتیجه | post-intro outcome repository/controller | record outcome | pending outcome for teacher | stored for follow-up | student/continuation workflow | admin exceptions | post-intro notifications | enabled |
| Google Calendar/Meet | provider integration authority | connect, sync, disconnect | configured OAuth + actor | explicit unavailable/needs-attention states | join link only when verified | operator recovery | provider/workflow rows | enabled when configured |
| پشتیبانی/استثنا | Theme support link + operational exception queue | contact support | none | no direct mutation | none | administrator/operator | existing notification transport | enabled |

### Operational policy confirmed in runtime

- Term `replacement_allowance` defaults to two and bounds eligible absence-related replacement or
  make-up arrangements.
- Reporting absence is separate from entitlement. An excess absence is recorded, the Student is
  told the Term limit is reached, an administrator exception is recorded, and no Teacher
  replacement card is created.
- Allocation is consumed by eligible student-origin absences, including pending ones. Two pending
  allocated absences can exhaust the allowance before a replacement Lesson is issued.
- Rejection or expiry of a proposal does **not** release the allocation. The Teacher may propose
  another time for the same occurrence.
- `teacher_non_delivery` is academy-owed and must not consume the Student's absence allowance.
- Disputed attendance goes to the administrator exception process.
- A replacement Lesson is a new linked occurrence; it never rewrites the original completed
  Lesson, its schedule version or attendance evidence.
- Ordinary Teacher rescheduling with no Student absence does not consume the allowance.

## Confirmed gaps and contradictions

1. **No educational induction model.** Onboarding ended at activation. There was no persistent
   progress, module versioning, hub, or progressive guidance.
2. **Google readiness documentation drift.** `TEACHER-ONBOARDING-V1.md` listed submission/approval
   requirements without Google, while runtime readiness requires a usable Google connection and
   the onboarding UI already presented it as required. Runtime is authoritative; documentation and
   UI wording are corrected.
3. **Misleading onboarding labels.** `pending_review` was presented as “اطلاعات ثبت شده” and
   `rejected` used the same label as `returned`. Both are corrected.
4. **Returned/rejected correction path was vague.** The raw reason code was stored but not shown in
   a safe, teacher-readable form. A server-side safe note mapping is added; raw codes are never
   echoed.
5. **No welcome step.** Teachers were dropped directly into profile fields without explaining the
   portal, the required information or the administrator review.
6. **No contextual guidance.** Availability, rescheduling, absence, notifications and finance had
   only general help/tour text.
7. **Tour overlap risk.** A first-visit modal tour could compete with a new induction card.
   Auto-open is suppressed when the induction card is present, while the tour stays available.
8. **No induction tests.** Existing contracts covered onboarding and portal authority but not
   educational progress, versioning, cross-teacher refusal or idempotency.
9. **Historical audit note drift.** `docs/TEACHER-ONBOARDING-AUDIT-2026-10-12.md` says admin
   approve/return/reject was retired, but current runtime exposes capability-gated review actions.
   Runtime is authoritative; this document records the actual implementation.
10. **Finance wording risk.** Illustrative allocation, accrued payability, issued statement and
    actual payment are distinct; the induction must never describe illustrative rows as income.

## Implementation response

- Added `TeacherInductionService`, `TeacherInductionRepository`, code-owned
  `TeacherInductionCurriculum` and seven versioned content files.
- Added additive migration `064_teacher_induction_progress` with a unique
  `(teacher_id,module_id)` key and no change to onboarding tables.
- Added nonce-protected completion controller; the Teacher is resolved from the authenticated
  principal and the same live readiness proof as the portal.
- Added a dedicated `teacher-view=induction` hub, compact home card, settings link and inline
  contextual `<details>` guidance.
- Reworked onboarding into five honest steps: welcome, profile, availability, review, status.
- Corrected labels, added a safe review note, made Google’s mandatory runtime status explicit and
  preserved every existing readiness and access restriction.
- Added unit and source-contract tests; no second absence counter, replacement store, scheduling
  authority, finance authority or notification transport was created.
