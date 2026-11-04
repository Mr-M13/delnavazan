# Teacher quick-start (induction v2)

Teacher induction is a **one page**, read-only review of the Delnavazan teaching workflow, not a
course. It gives an approved Teacher nine concise steps in plain Persian, each pairing the Student
journey with the Teacher’s responsibility. The guide
does not jump to portal sections, highlight controls or ask the Teacher to complete anything.

It is not a second identity, activation, access, scheduling, absence, finance or notification
authority. Reading or skipping the guide never changes the canonical onboarding lifecycle or portal
access.

## The nine steps

The page follows the lifecycle of a Student and Teacher together:

1. **از شروع همکاری تا برنامهٔ کلاس**
   - **آماده‌سازی حساب و زمان‌ها** — approved profile and truthful availability against the Student’s chosen time; the classroom is managed centrally.
   - **از درخواست تا کلاس آشنایی** — the Student requests an introduction; acceptance alone does not confirm a class or term enrolment.
   - **برنامهٔ روز و کلاس‌های آینده** — each side sees the scheduled class in its own timezone; the Teacher checks today and upcoming work.
2. **برگزاری کلاس**
   - **برگزاری کلاس** — both parties join the scheduled session; the Teacher uses the available Meet backup when offered.
   - **ثبت نتیجهٔ جلسه** — the Teacher records delivery and attendance for review; presumed completion is distinct.
3. **تغییرها و موارد خاص**
   - **غیبت و جبران** — the Student reports an absence and sees remaining replacements; the Teacher proposes a make-up time only from an eligible card.
   - **تغییر زمان با توافق** — the Student accepts or declines the Teacher’s proposal; three rejections escalate.
4. **مالی و پیگیری**
   - **ساعت‌ها و امور مالی** — the Teacher checks hours and statements; payment and tuition illustrations have separate meanings.
   - **ساعت‌های پرتقاضا** — optional availability and potential bonus, never guaranteed income.

Content is code-owned in `platform/content/induction/*.php`. Each step declares a stable id, title,
short Teacher responsibility, required Student perspective, content version and acknowledgement
version. Rendering
never defines policy.

## Presentation

- The guide is a static list grouped by workflow stage. Each card explicitly names the Student perspective and Teacher responsibility. It contains no
  action buttons, POST forms or
  per-step completion state.
- Each step keeps a stable `#tp-step-<id>` anchor so contextual help can link to the explanation.
- The dashboard card and the Settings entry are compact links to the one-page guide. They do not show
  progress counts. The full guide is never repeated on every dashboard visit.
- The guide is not a substitute for the real portal screens. Actions are taken only on the screen
  that owns them.

## Persistence and security

The additive migration `064_teacher_induction_progress` and the
`{prefix}dzn_teacher_induction_progress` table remain in place for compatibility. The read-only guide
does not write to that table.

A legacy completion endpoint remains nonce-protected and fails closed. It may only name a canonical
step, resolves the Teacher from the authenticated principal and the same live readiness proof used by
the Teacher Portal, then redirects back to the guide. Cross-teacher access fails closed. No
invitation secret, provider credential, finance value or free-text Teacher content is stored. The
protected portal route sends no-store/private headers.

## Tests

- `platform/tests/teacher-induction-unit.php` — nine steps, workflow order, content accuracy,
  idempotency, re-acknowledgement and cross-teacher refusal.
- `platform/tests/teacher-induction-contract.php` — authority, additive migration, the absence of
  action controls and the one-page presentation.
- `platform/tests/teacher-induction-presentation.php` — rendered read-only guide, workflow grouping
  and compact entry card.
- Existing onboarding, portal, reschedule, replacement, finance and notification contracts remain
  the runtime authority for operational behaviour.
