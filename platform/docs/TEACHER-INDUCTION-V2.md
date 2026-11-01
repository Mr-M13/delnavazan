# Teacher quick-start (induction v2)

Teacher induction is a **one page** progress-style quick-start, not a course. It gives an approved
Teacher nine short, practical steps. Each step explains one thing in plain Persian, records that the
Teacher has seen it, then jumps to the real portal section and highlights it for a few seconds.

It is not a second identity, activation, access, scheduling, absence, finance or notification
authority. Completing or skipping it never changes the canonical onboarding lifecycle or portal
access.

## The nine steps

1. **کلاس‌های امروز** — start on Today; today's classes and urgent tasks are there.
2. **ورود به کلاس** — the join button, and Google Meet as the available backup.
3. **حضور و پایان کلاس** — when to record delivery/attendance and what presumed completion means.
4. **هنرجو غیبت کرد** — the absence card, replacement proposal, term allowance and when to escalate.
5. **تغییر زمان کلاس** — propose a new time; nothing changes until the Student accepts; three
   rejections escalate.
6. **تغییر اطلاعات و زمان‌های تدریس** — Settings, weekly availability and date-specific leave.
7. **مالی و ساعت‌ها** — teaching hours, statements and the illustrative-only tuition wording.
8. **ساعت طلایی** — potential bonus, not guaranteed income.
9. **درخواست کلاس آشنایی** — accept or decline an intro request; acceptance alone is not a
   confirmed class.

Content is code-owned in `platform/content/induction/*.php`. Each step declares a stable id, title,
short description, target screen, highlight selector, action label, optional short points, content
version and acknowledgement version. Rendering never defines policy.

## Progress and highlighting

- Step click is a nonce-protected POST. The request may name only a canonical step id.
- The Teacher is resolved from the authenticated principal and the same live readiness proof used by
  the Teacher Portal; cross-teacher access fails closed.
- A click records `completed` in `{prefix}dzn_teacher_induction_progress`, then redirects to the real
  target screen with `induction-highlight=<step>`.
- The target page shows one compact banner explaining the purpose and highlights the matching
  element (`#tp-classes`, the join area, attendance panel, absence/intro card, reschedule form,
  Settings availability, finance summary or Golden Hours panel).
- If the target element is not present on that visit, the banner still explains the step and the
  Teacher can return to the guide. No fake element or dead tab is created.
- The dashboard card is compact and only links to the one-page guide. The full guide is never
  repeated on every dashboard visit.
- Progress is idempotent at the unique `(teacher_id,module_id)` key. Minor copy edits reuse the same
  version. A material policy change can raise `acknowledgement_version` and show an explicit review
  state without changing canonical activation.

## Persistence and security

Additive migration `064_teacher_induction_progress` creates
`{prefix}dzn_teacher_induction_progress` with `teacher_id`, `module_id`, `state`,
`content_version`, `acknowledged_version`, `completed_at`, `last_visited_at`, standard audit
columns and a unique `(teacher_id,module_id)` key.

The existing onboarding tables were insufficient because they describe one lifecycle state and
coarse readiness, not per-step educational progress and versioned acknowledgement.

The controller requires authentication and `check_admin_referer`. The service re-proves the live
readiness authority. No invitation secret, provider credential, finance value or free-text Teacher
content is stored. The protected portal route sends no-store/private headers.

## Tests

- `platform/tests/teacher-induction-unit.php` — nine steps, content accuracy, idempotency,
  re-acknowledgement and cross-teacher refusal.
- `platform/tests/teacher-induction-contract.php` — authority, additive migration, jump targets and
  one-page presentation.
- `platform/tests/teacher-induction-presentation.php` — rendered one-page guide and banner.
- Existing onboarding, portal, reschedule, replacement, finance and notification contracts remain
  the runtime authority for operational behaviour.
