# WhatsApp provider-template sheet (Telnyx WABA)

Operator runbook for Delnavazan business-initiated WhatsApp templates. All sample data below is
synthetic; no real student, teacher or payment data appears anywhere in this sheet or in the
manifest it references.

## Source of truth

The machine-readable manifest is `deployment/notifications/whatsapp-templates.json`
(40 templates). Every entry is `language: fa`, `category: UTILITY` (transactional only),
with a positional `{{1}}…{{n}}` body derived from the catalogue's reviewed Persian WhatsApp copy and a
synthetic Persian `samples` object. The body ends with the static sign-off `— دلنوازان` after the
last parameter: Meta refuses a template that begins or ends with a parameter (error 2388299). The
local Persian copy carries the same sign-off, and the integration contract checks the manifest and
the local copy are identical after placeholder normalization. The `TelnyxTemplateRegistry` mirrors
the same `variables` order as `variable_order`, so a template the workflow creates maps through
discovery unchanged.

## Creation path (audited workflow)

1. The owner adds `TELNYX_API_KEY` and `TELNYX_WABA_ID` as GitHub **environment secrets**.
2. The creation workflow **dry-runs by default**; review the plan before running the live pass.
3. Templates are created in the Telnyx WABA with `language=fa` and `category=UTILITY`.
4. Wait for each template to reach `APPROVED` in the WABA / Meta.
5. In **Integrations → WhatsApp → “Refresh approved templates”**, run discovery; it maps each
   `delnavazan_*_fa` name back to its event through the registry.

Do not add credentials to the repository; the workflow reads them only from the environment secrets.

## Versioned migration (2026-11-21)

Twenty templates predate the current variable contract: fourteen booking/lesson/renewal templates
in the first batch and six refund/renewal templates found by the post-creation discovery refresh.
Meta does not allow the reviewed body to replace an existing approved template in place, so those
names carry a `_v2` segment (for example `delnavazan_booking_confirmation_v2_fa`). The creation
workflow treats them as missing and creates them; the 20 unchanged names are skipped. After the new
names reach `APPROVED`:

1. Refresh approved templates in **Integrations → WhatsApp**.
2. Confirm the registry maps the `_v2` names and a controlled test succeeds.
3. Delete the 20 superseded templates in Telnyx; they are no longer referenced by the registry.
4. Re-record or remove any manual provider mapping that still names an old template.

The old templates stay in the WABA until step 3, so they can be compared or used for a rollback
decision. They are not used for delivery once the registry maps the new names.

## What the notification editor shows

Open **Notifications → Templates → WhatsApp** for one event. The provider panel is read-only and
shows the evidence behind the channel state:

- **Linked template** — the discovered `delnavazan_*_fa` name and language. When nothing is usable
  it says **Not linked** and, for a Persian route, names the registered template the event expects.
- **Provider status** — the discovered `APPROVED` / `PENDING` / `REJECTED` state, category, parameter
  count/order and last verification time.
- **Provider body** — the exact static provider text (with positional placeholders), captured by
  the latest discovery refresh. It is labeled **Approved provider body** only when the provider
  status is `APPROVED`; a pending or rejected body is shown with that status instead. This is what
  Telnyx delivers. The editable copy above is the local Delnavazan representation; the two do not
  have to be word-for-word identical, but the parameter count and order must match. When the provider
  body differs from the local copy, the panel shows a review warning so the operator can reconcile
  the wording or update the Telnyx template.
- **Refresh provider templates** — re-runs the read-only Telnyx discovery from the editor and returns
  to the same event. It never sends a message and never changes a published local version.

A contract mismatch or an unapproved template is not a mapping problem an administrator can
override: correct the provider template in Telnyx, wait for approval, then refresh.

## Template table (name → event → audience → positional variables)

| Template name | Event key | Audience | Positional variables |
|---|---|---|---|
| `delnavazan_intro_confirmed_v2_fa` | `booking.confirmed.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=teacher, `{{4}}`=when, `{{5}}`=portal_url |
| `delnavazan_intro_cancelled_fa` | `booking.cancelled.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=teacher, `{{4}}`=when, `{{5}}`=portal_url |
| `delnavazan_intro_declined_fa` | `booking.declined.student` | student | `{{1}}`=name, `{{2}}`=teacher, `{{3}}`=course, `{{4}}`=portal_url |
| `delnavazan_intro_rescheduled_fa` | `booking.rescheduled.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=teacher, `{{4}}`=when, `{{5}}`=portal_url |
| `delnavazan_teacher_new_booking_v2_fa` | `booking.new.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=course, `{{3}}`=student, `{{4}}`=when, `{{5}}`=response_window, `{{6}}`=teacher_portal_url |
| `delnavazan_booking_confirmation_v2_fa` | `booking.requested.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=when, `{{4}}`=portal_url |
| `delnavazan_class_access_v2_fa` | `lesson.access.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=teacher, `{{4}}`=when, `{{5}}`=portal_url |
| `delnavazan_class_cancelled_v2_fa` | `lesson.cancelled.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=teacher, `{{4}}`=when, `{{5}}`=portal_url |
| `delnavazan_class_reminder_v2_fa` | `lesson.reminder.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=teacher, `{{4}}`=when, `{{5}}`=portal_url |
| `delnavazan_payment_failed_student_fa` | `payment.failed.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=amount, `{{4}}`=portal_url |
| `delnavazan_payment_received_v2_fa` | `payment.recovered.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=amount, `{{4}}`=paid_date, `{{5}}`=portal_url |
| `delnavazan_enrolment_confirmation_v2_fa` | `post_intro.activated.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=teacher, `{{4}}`=portal_url |
| `delnavazan_refund_resolved_student_v2_fa` | `refund.resolved.student` | student | `{{1}}`=name, `{{2}}`=amount, `{{3}}`=portal_url |
| `delnavazan_refund_review_required_student_v2_fa` | `refund.review_required.student` | student | `{{1}}`=name, `{{2}}`=amount, `{{3}}`=portal_url |
| `delnavazan_renewal_automatic_charged_student_v2_fa` | `renewal.automatic_charged.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=amount, `{{4}}`=paid_date, `{{5}}`=portal_url |
| `delnavazan_renewal_automatic_failed_student_v2_fa` | `renewal.automatic_failed.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=amount, `{{4}}`=due_date, `{{5}}`=portal_url |
| `delnavazan_renewal_reminder_v2_fa` | `renewal.automatic_upcoming.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=due_date, `{{4}}`=amount, `{{5}}`=portal_url |
| `delnavazan_renewal_guarantee_deadline_approaching_student_v2_fa` | `renewal.guarantee_deadline_approaching.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=due_date, `{{4}}`=amount, `{{5}}`=portal_url |
| `delnavazan_payment_due_v2_fa` | `renewal.manual_payment_required.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=amount, `{{4}}`=due_date, `{{5}}`=portal_url |
| `delnavazan_renewal_term_lapsed_student_v2_fa` | `renewal.term_lapsed.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=portal_url |
| `delnavazan_class_rescheduled_v2_fa` | `reschedule.change.accepted.student` | student | `{{1}}`=name, `{{2}}`=when, `{{3}}`=timezone, `{{4}}`=portal_url |
| `delnavazan_teacher_schedule_change_v2_fa` | `reschedule.change.accepted.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=when, `{{3}}`=timezone, `{{4}}`=teacher_portal_url |
| `delnavazan_reschedule_change_cancelled_student_fa` | `reschedule.change.cancelled.student` | student | `{{1}}`=name, `{{2}}`=portal_url |
| `delnavazan_reschedule_change_cancelled_teacher_fa` | `reschedule.change.cancelled.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=teacher_portal_url |
| `delnavazan_reschedule_change_failed_student_fa` | `reschedule.change.failed.student` | student | `{{1}}`=name, `{{2}}`=portal_url |
| `delnavazan_reschedule_change_failed_teacher_fa` | `reschedule.change.failed.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=teacher_portal_url |
| `delnavazan_reschedule_request_v2_fa` | `reschedule.change.proposed.student` | student | `{{1}}`=name, `{{2}}`=when, `{{3}}`=timezone, `{{4}}`=portal_url |
| `delnavazan_reschedule_change_proposed_teacher_fa` | `reschedule.change.proposed.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=when, `{{3}}`=timezone, `{{4}}`=teacher_portal_url |
| `delnavazan_reschedule_rejected_v2_fa` | `reschedule.change.rejected.student` | student | `{{1}}`=name, `{{2}}`=when, `{{3}}`=portal_url |
| `delnavazan_reschedule_change_rejected_teacher_fa` | `reschedule.change.rejected.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=when, `{{3}}`=teacher_portal_url |
| `delnavazan_reschedule_replacement_accepted_student_fa` | `reschedule.replacement.accepted.student` | student | `{{1}}`=name, `{{2}}`=when, `{{3}}`=timezone, `{{4}}`=portal_url |
| `delnavazan_reschedule_replacement_accepted_teacher_fa` | `reschedule.replacement.accepted.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=when, `{{3}}`=timezone, `{{4}}`=teacher_portal_url |
| `delnavazan_reschedule_replacement_cancelled_student_fa` | `reschedule.replacement.cancelled.student` | student | `{{1}}`=name, `{{2}}`=portal_url |
| `delnavazan_reschedule_replacement_cancelled_teacher_fa` | `reschedule.replacement.cancelled.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=teacher_portal_url |
| `delnavazan_reschedule_replacement_failed_student_fa` | `reschedule.replacement.failed.student` | student | `{{1}}`=name, `{{2}}`=portal_url |
| `delnavazan_reschedule_replacement_failed_teacher_fa` | `reschedule.replacement.failed.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=teacher_portal_url |
| `delnavazan_reschedule_replacement_proposed_student_fa` | `reschedule.replacement.proposed.student` | student | `{{1}}`=name, `{{2}}`=when, `{{3}}`=timezone, `{{4}}`=portal_url |
| `delnavazan_reschedule_replacement_proposed_teacher_fa` | `reschedule.replacement.proposed.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=when, `{{3}}`=timezone, `{{4}}`=teacher_portal_url |
| `delnavazan_reschedule_replacement_rejected_student_fa` | `reschedule.replacement.rejected.student` | student | `{{1}}`=name, `{{2}}`=when, `{{3}}`=portal_url |
| `delnavazan_reschedule_replacement_rejected_teacher_fa` | `reschedule.replacement.rejected.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=when, `{{3}}`=teacher_portal_url |

## Positional convention

`{{1}}` is the first code in `variables`, `{{2}}` the second, and so on. The placeholders must be
sequential and unique, and the body must have static text before and after them. The registry stores
the same sequence as `variable_order`, and the discovery service compares the provider template's
placeholder count to that order, so the operator must not reorder or omit placeholders when authoring
the body.

Example — `delnavazan_class_reminder_v2_fa` (`lesson.reminder.student`): `{{1}}`=name, `{{2}}`=course,
`{{3}}`=teacher, `{{4}}`=when, and the body reads «درود {{1}} عزیز، کلاس {{2}} شما با {{3}} در {{4}}
برگزار میشود. برای ورود به کلاس به حساب کاربری بروید: {{5}}».
