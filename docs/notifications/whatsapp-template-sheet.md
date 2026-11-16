# WhatsApp provider-template sheet (Telnyx WABA)

Operator runbook for Delnavazan business-initiated WhatsApp templates. All sample data below is
synthetic; no real student, teacher or payment data appears anywhere in this sheet or in the
manifest it references.

## Source of truth

The machine-readable manifest is `deployment/notifications/whatsapp-templates.json`
(37 templates). Every entry is `language: fa`, `category: UTILITY` (transactional only),
with a positional `{{1}}…{{n}}` body derived from the catalogue's reviewed Persian WhatsApp copy and a
synthetic Persian `samples` object. The `TelnyxTemplateRegistry` mirrors the same `variables` order as
`variable_order`, so a template the workflow creates maps through discovery unchanged.

## Creation path (audited workflow)

1. The owner adds `TELNYX_API_KEY` and `TELNYX_WABA_ID` as GitHub **environment secrets**.
2. The creation workflow **dry-runs by default**; review the plan before running the live pass.
3. Templates are created in the Telnyx WABA with `language=fa` and `category=UTILITY`.
4. Wait for each template to reach `APPROVED` in the WABA / Meta.
5. In **Integrations → WhatsApp → “Refresh approved templates”**, run discovery; it maps each
   `delnavazan_*_fa` name back to its event through the registry.

Do not add credentials to the repository; the workflow reads them only from the environment secrets.

## Template table (name → event → audience → positional variables)

| Template name | Event key | Audience | Positional variables |
|---|---|---|---|
| `delnavazan_intro_confirmed_fa` | `booking.confirmed.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=teacher, `{{4}}`=when, `{{5}}`=portal_url |
| `delnavazan_teacher_new_booking_fa` | `booking.new.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=course, `{{3}}`=student, `{{4}}`=when, `{{5}}`=response_window, `{{6}}`=teacher_portal_url |
| `delnavazan_booking_confirmation_fa` | `booking.requested.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=when, `{{4}}`=portal_url |
| `delnavazan_class_access_fa` | `lesson.access.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=teacher, `{{4}}`=when, `{{5}}`=portal_url |
| `delnavazan_class_cancelled_fa` | `lesson.cancelled.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=teacher, `{{4}}`=when, `{{5}}`=portal_url |
| `delnavazan_class_reminder_fa` | `lesson.reminder.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=teacher, `{{4}}`=when, `{{5}}`=portal_url |
| `delnavazan_payment_failed_student_fa` | `payment.failed.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=amount, `{{4}}`=portal_url |
| `delnavazan_payment_received_fa` | `payment.recovered.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=amount, `{{4}}`=paid_date, `{{5}}`=portal_url |
| `delnavazan_enrolment_confirmation_fa` | `post_intro.activated.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=teacher, `{{4}}`=portal_url |
| `delnavazan_refund_resolved_student_fa` | `refund.resolved.student` | student | `{{1}}`=name, `{{2}}`=amount, `{{3}}`=portal_url |
| `delnavazan_refund_review_required_student_fa` | `refund.review_required.student` | student | `{{1}}`=name, `{{2}}`=amount, `{{3}}`=portal_url |
| `delnavazan_renewal_automatic_charged_student_fa` | `renewal.automatic_charged.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=amount, `{{4}}`=paid_date, `{{5}}`=portal_url |
| `delnavazan_renewal_automatic_failed_student_fa` | `renewal.automatic_failed.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=amount, `{{4}}`=due_date, `{{5}}`=portal_url |
| `delnavazan_renewal_reminder_fa` | `renewal.automatic_upcoming.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=due_date, `{{4}}`=amount, `{{5}}`=portal_url |
| `delnavazan_renewal_guarantee_deadline_approaching_student_fa` | `renewal.guarantee_deadline_approaching.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=due_date, `{{4}}`=amount, `{{5}}`=portal_url |
| `delnavazan_payment_due_fa` | `renewal.manual_payment_required.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=amount, `{{4}}`=due_date, `{{5}}`=portal_url |
| `delnavazan_renewal_term_lapsed_student_fa` | `renewal.term_lapsed.student` | student | `{{1}}`=name, `{{2}}`=course, `{{3}}`=portal_url |
| `delnavazan_class_rescheduled_fa` | `reschedule.change.accepted.student` | student | `{{1}}`=name, `{{2}}`=when, `{{3}}`=timezone, `{{4}}`=portal_url |
| `delnavazan_teacher_schedule_change_fa` | `reschedule.change.accepted.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=when, `{{3}}`=timezone, `{{4}}`=teacher_portal_url |
| `delnavazan_reschedule_change_cancelled_student_fa` | `reschedule.change.cancelled.student` | student | `{{1}}`=name, `{{2}}`=portal_url |
| `delnavazan_reschedule_change_cancelled_teacher_fa` | `reschedule.change.cancelled.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=teacher_portal_url |
| `delnavazan_reschedule_change_failed_student_fa` | `reschedule.change.failed.student` | student | `{{1}}`=name, `{{2}}`=portal_url |
| `delnavazan_reschedule_change_failed_teacher_fa` | `reschedule.change.failed.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=teacher_portal_url |
| `delnavazan_reschedule_request_fa` | `reschedule.change.proposed.student` | student | `{{1}}`=name, `{{2}}`=when, `{{3}}`=timezone, `{{4}}`=portal_url |
| `delnavazan_reschedule_change_proposed_teacher_fa` | `reschedule.change.proposed.teacher` | teacher | `{{1}}`=teacher, `{{2}}`=when, `{{3}}`=timezone, `{{4}}`=teacher_portal_url |
| `delnavazan_reschedule_rejected_fa` | `reschedule.change.rejected.student` | student | `{{1}}`=name, `{{2}}`=when, `{{3}}`=portal_url |
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

`{{1}}` is the first code in `variables`, `{{2}}` the second, and so on. The registry stores the same
sequence as `variable_order`, and the discovery service compares the provider template's placeholder
count to that order, so the operator must not reorder or omit placeholders when authoring the body.

Example — `delnavazan_class_reminder_fa` (`lesson.reminder.student`): `{{1}}`=name, `{{2}}`=course,
`{{3}}`=teacher, `{{4}}`=when, and the body reads «درود {{1}} عزیز، کلاس {{2}} شما با {{3}} در {{4}}
برگزار میشود. برای ورود به کلاس به حساب کاربری بروید: {{5}}».
