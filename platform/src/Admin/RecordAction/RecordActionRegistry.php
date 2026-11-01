<?php
namespace Delnavazan\Platform\Admin\RecordAction;

use Delnavazan\Platform\Core\Application\ArchiveService;
use Delnavazan\Platform\Core\Application\CoreReadService;
use Delnavazan\Platform\Core\Application\PrincipalInvitationService;
use Delnavazan\Platform\Core\Application\StudentAdministrationService;
use Delnavazan\Platform\Core\Application\StudentWorkspaceReadService;
use Delnavazan\Platform\Core\Application\TeacherAcceptingStateService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityService;
use Delnavazan\Platform\Core\Application\TeacherOnboardingService;
use Delnavazan\Platform\Core\Application\TeachingEligibilityService;

/**
 * Explicit registry of contextual record actions.
 *
 * Every action names its record type, read/action capabilities, safe handler and
 * human-facing consequence text. Handlers call an existing application service;
 * this class never writes to the database directly.
 */
final class RecordActionRegistry {
    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array {
        return array(
            'student-profile' => array(
                'label' => 'Edit student details',
                'description' => 'Update the public student identity and contact details used by the academy.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_students',
                'submit_label' => 'Save student details',
                'consequence' => 'Changes update the public student profile. They do not change the linked WordPress account, principal authority, guardian authority or historical identity evidence.',
                'fields' => static fn(object $record): array => self::studentProfileFields($record),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (string) ($record->status ?? '') !== 'archived',
            ),
            'student-deactivate' => array(
                'label' => 'Deactivate student',
                'description' => 'Pause this student’s current operational participation.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_students',
                'submit_label' => 'Deactivate student',
                'require_confirmation' => true,
                'confirmation' => 'I understand that deactivation pauses participation but does not delete history or the WordPress account.',
                'consequence' => 'The student status becomes inactive. Lessons, payments, attendance, consent and enrolment history remain. Future operational dependencies should be reviewed and resolved through their canonical workflows.',
                'fields' => array(),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (string) ($record->status ?? '') === 'active',
            ),
            'student-reactivate' => array(
                'label' => 'Reactivate student',
                'description' => 'Return this inactive student to the operational directory.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_students',
                'submit_label' => 'Reactivate student',
                'require_confirmation' => true,
                'confirmation' => 'I understand that portal access is not restored automatically.',
                'consequence' => 'The student becomes active for administrative and enrolment work. A portal account must still be verified and linked separately.',
                'fields' => array(),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (string) ($record->status ?? '') === 'inactive',
            ),
            'student-archive' => array(
                'label' => 'Archive student',
                'description' => 'Remove this student from normal active directories while preserving protected history.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_students',
                'submit_label' => 'Archive student',
                'require_confirmation' => true,
                'confirmation' => 'I understand that archiving is refused while operational enrolments still depend on this student.',
                'consequence' => 'The profile leaves normal directories. Historical lessons, payments, attendance, consent, enrolments and the WordPress user are preserved. Restore makes the profile eligible for a new enrolment but does not restore access.',
                'fields' => array(),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (string) ($record->status ?? '') !== 'archived',
            ),
            'student-restore' => array(
                'label' => 'Restore student',
                'description' => 'Restore this archived student profile.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_students',
                'submit_label' => 'Restore student',
                'require_confirmation' => true,
                'confirmation' => 'I understand that restoring does not automatically restore portal access.',
                'consequence' => 'The profile returns to the directory as inactive. Account and guardian access remain unchanged until explicitly reviewed.',
                'fields' => array(),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) !== null || (string) ($record->status ?? '') === 'archived',
            ),
            'student-send-password-reset' => array(
                'label' => 'Send password reset',
                'description' => 'Send the canonical WordPress password reset request to the linked account.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_students',
                'submit_label' => 'Send reset request',
                'require_confirmation' => true,
                'confirmation' => 'I understand that acceptance by the mail transport is not proof of delivery.',
                'consequence' => 'WordPress creates its normal reset request. No password is displayed, retrieved or set by this platform. Repeated requests are rate-limited.',
                'fields' => array(),
                'visible' => static fn(object $record): bool => self::studentHasActiveAccount($record) && ($record->archived_at ?? null) === null,
            ),
            'student-issue-account-claim' => array(
                'label' => 'Send account claim invitation',
                'description' => 'Invite this student to prove ownership of the contact email and create or link their WordPress account.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_issue_student_account_claims',
                'submit_label' => 'Send claim invitation',
                'require_confirmation' => true,
                'confirmation' => 'I have verified that the student email is the correct private contact address.',
                'consequence' => 'A single-use claim invitation is queued through the canonical notification authority. It does not create a principal link until the student completes the claim.',
                'fields' => array(),
                'visible' => static fn(object $record): bool => !self::studentHasActiveAccount($record) && ($record->archived_at ?? null) === null && (string) ($record->status ?? '') === 'active',
            ),
            'student-link-account' => array(
                'label' => 'Link verified account',
                'description' => 'Link an existing WordPress account only after verifying the person’s identity.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_student_acceptance_authority',
                'submit_label' => 'Link verified account',
                'require_confirmation' => true,
                'confirmation' => 'I verified the person and the WordPress account before linking.',
                'consequence' => 'The account becomes the Student principal. The canonical authority refuses a second active Student principal or an account already acting as a guardian.',
                'fields' => static fn(object $record): array => array(self::studentAccountField()),
                'visible' => static fn(object $record): bool => !self::studentHasActiveAccount($record) && ($record->archived_at ?? null) === null,
            ),
            'student-supersede-account' => array(
                'label' => 'Replace linked account',
                'description' => 'Atomically supersede the current Student principal link with a verified replacement account.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_student_acceptance_authority',
                'submit_label' => 'Replace linked account',
                'require_confirmation' => true,
                'confirmation' => 'I verified the replacement account and understand the current link will be superseded.',
                'consequence' => 'The current principal link is superseded and the verified account becomes the Student principal. Historical linkage evidence is retained.',
                'fields' => static fn(object $record): array => array(self::studentAccountField()),
                'visible' => static fn(object $record): bool => self::studentHasActiveAccount($record) && ($record->archived_at ?? null) === null,
            ),
            'student-deactivate-account' => array(
                'label' => 'Deactivate linked account',
                'description' => 'Revoke the linked account’s Student access without deleting the WordPress user.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_student_acceptance_authority',
                'submit_label' => 'Deactivate Student access',
                'require_confirmation' => true,
                'confirmation' => 'I understand that the WordPress user and all history are retained.',
                'consequence' => 'The Student principal link is revoked. Portal access ends. The WordPress account, its other roles, owned content and history remain.',
                'fields' => array(),
                'visible' => static fn(object $record): bool => self::studentHasActiveAccount($record) && ($record->archived_at ?? null) === null,
            ),
            'student-classify-capacity' => array(
                'label' => 'Record age / authority category',
                'description' => 'Record the reviewed acceptance capacity used to decide adult account or guardian authority.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_student_acceptance_authority',
                'submit_label' => 'Save reviewed category',
                'require_confirmation' => true,
                'confirmation' => 'I reviewed the evidence for this age and authority category.',
                'consequence' => 'The reviewed classification becomes current. It does not grant or revoke guardian authority by itself.',
                'fields' => static fn(object $record): array => array(self::selectField('classification', 'Reviewed category', array('' => 'Choose category', 'adult' => 'Adult', 'minor' => 'Minor', 'unknown' => 'Keep unknown with review'), self::studentCapacity((int) $record->id))),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null,
            ),
            'student-guardian-grant' => array(
                'label' => 'Grant guardian authority',
                'description' => 'Grant service-acceptance representative authority to a verified guardian account.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_student_acceptance_authority',
                'submit_label' => 'Grant guardian authority',
                'require_confirmation' => true,
                'confirmation' => 'I verified the guardian’s identity and relationship to this student.',
                'consequence' => 'The account becomes a representative for service acceptance. Authority is refused if it conflicts with an existing principal or guardian grant.',
                'fields' => static fn(object $record): array => array(
                    self::studentAccountField('guardian WordPress account'),
                    self::field('effective_until_date', 'Authority end date', '', false, 'date', 'Optional. Leave empty for an open-ended reviewed grant.'),
                ),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null,
            ),
            'student-guardian-revoke' => array(
                'label' => 'Revoke guardian authority',
                'description' => 'Revoke one active guardian representative grant.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_student_acceptance_authority',
                'submit_label' => 'Revoke guardian authority',
                'require_confirmation' => true,
                'confirmation' => 'I understand that historical authority evidence is retained.',
                'consequence' => 'The selected guardian loses representative authority. Historical evidence is not rewritten.',
                'fields' => static fn(object $record): array => array(self::activeGuardianField((int) $record->id)),
                'visible' => static fn(object $record): bool => self::hasActiveGuardian((int) $record->id),
            ),
            'student-guardian-supersede' => array(
                'label' => 'Replace guardian authority',
                'description' => 'Atomically supersede an active guardian grant with a verified replacement guardian.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_student_acceptance_authority',
                'submit_label' => 'Replace guardian authority',
                'require_confirmation' => true,
                'confirmation' => 'I verified the replacement guardian and understand the current grant will be superseded.',
                'consequence' => 'The current guardian grant is superseded and the verified replacement receives representative authority. Historical evidence remains.',
                'fields' => static fn(object $record): array => array(
                    self::activeGuardianField((int) $record->id),
                    self::studentAccountField('replacement guardian WordPress account'),
                    self::field('effective_until_date', 'Authority end date', '', false, 'date', 'Optional end date for the replacement grant.'),
                ),
                'visible' => static fn(object $record): bool => self::hasActiveGuardian((int) $record->id),
            ),
            'student-task-acknowledge' => array(
                'label' => 'Acknowledge task',
                'description' => 'Acknowledge an open student-specific operational exception.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_exceptions',
                'submit_label' => 'Acknowledge task',
                'consequence' => 'The task moves to acknowledged. It remains open until resolved or dismissed.',
                'fields' => static fn(object $record): array => array(self::openTaskField((int) $record->id)),
                'visible' => static fn(object $record): bool => self::hasOpenTask((int) $record->id),
            ),
            'student-task-resolve' => array(
                'label' => 'Resolve task',
                'description' => 'Resolve a student-specific operational exception with a note.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_exceptions',
                'submit_label' => 'Resolve task',
                'consequence' => 'The task is resolved with the recorded note. It does not repair an underlying record automatically.',
                'fields' => static fn(object $record): array => array(
                    self::openTaskField((int) $record->id),
                    self::field('resolution_note', 'Resolution note', '', true, 'textarea'),
                ),
                'visible' => static fn(object $record): bool => self::hasOpenTask((int) $record->id),
            ),
            'student-task-dismiss' => array(
                'label' => 'Dismiss task',
                'description' => 'Dismiss a student-specific operational exception with a reason.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_exceptions',
                'submit_label' => 'Dismiss task',
                'require_confirmation' => true,
                'confirmation' => 'I understand that dismissal does not repair the underlying record.',
                'consequence' => 'The task is dismissed with the recorded reason. No repair is performed.',
                'fields' => static fn(object $record): array => array(
                    self::openTaskField((int) $record->id),
                    self::field('resolution_note', 'Dismissal reason', '', true, 'textarea'),
                ),
                'visible' => static fn(object $record): bool => self::hasOpenTask((int) $record->id),
            ),
            'student-enrolment-transition' => array(
                'label' => 'Change enrolment lifecycle',
                'description' => 'Activate, pause, resume or close one canonical enrolment for this student.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_canonical_enrolment_lifecycle',
                'submit_label' => 'Save enrolment state',
                'require_confirmation' => true,
                'confirmation' => 'I understand that closing an enrolment is refused while applicable Terms, Lessons or future schedules exist.',
                'consequence' => 'The canonical enrolment lifecycle service validates the current state and dependencies. It does not create or delete Terms, Lessons, payments or history.',
                'fields' => static fn(object $record): array => array(
                    self::enrolmentActionField((int) $record->id),
                    self::selectField('operation', 'Action', array('' => 'Choose action', 'activate' => 'Activate', 'pause' => 'Pause', 'resume' => 'Resume', 'close' => 'Close'), ''),
                    self::field('reason', 'Internal reason', '', true, 'text', 'A short operational note for the audit record.'),
                ),
                'visible' => static fn(object $record): bool => self::hasEnrolmentAction((int) $record->id),
            ),
            'student-term-transition' => array(
                'label' => 'Change term lifecycle',
                'description' => 'Activate, close or cancel one canonical Term for this student.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_canonical_terms',
                'submit_label' => 'Save term state',
                'require_confirmation' => true,
                'confirmation' => 'I understand that closure is refused while authorised Lessons or future schedules exist.',
                'consequence' => 'The canonical Term authority validates the Term aggregate and funding before changing state. It never invents a Term or financial obligation.',
                'fields' => static fn(object $record): array => array(
                    self::termActionField((int) $record->id),
                    self::selectField('operation', 'Action', array('' => 'Choose action', 'activate' => 'Activate', 'close' => 'Close', 'cancel' => 'Cancel'), ''),
                    self::field('reason', 'Internal reason', '', true, 'text'),
                ),
                'visible' => static fn(object $record): bool => self::hasTermAction((int) $record->id),
            ),
            'student-lesson-reschedule' => array(
                'label' => 'Reschedule lesson',
                'description' => 'Revise the schedule of one authorised canonical Lesson for this student.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_canonical_lesson_schedules',
                'submit_label' => 'Revise lesson schedule',
                'require_confirmation' => true,
                'confirmation' => 'I checked Teacher availability, the student timezone and the resulting class time.',
                'consequence' => 'The canonical schedule authority validates the exact current schedule version, duration, buffers, Teacher availability and conflicts. The Lesson and its history are not recreated.',
                'fields' => static fn(object $record): array => array(
                    self::lessonActionField((int) $record->id),
                    self::field('local_date', 'New local date', '', true, 'date'),
                    self::field('local_time', 'New local start time', '', true, 'text', '24-hour time such as 16:30.'),
                    self::selectField('duration_minutes', 'Lesson length', array('30' => '30 minutes', '45' => '45 minutes', '60' => '60 minutes'), '30'),
                    self::field('reason', 'Internal reason', '', true, 'text'),
                ),
                'visible' => static fn(object $record): bool => self::hasLessonAction((int) $record->id),
            ),
            'student-attendance-review' => array(
                'label' => 'Review attendance',
                'description' => 'Reassess or adjudicate one open canonical attendance case for this student.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_canonical_attendance_review',
                'submit_label' => 'Apply reviewed outcome',
                'require_confirmation' => true,
                'confirmation' => 'I reviewed the exact case evidence and version.',
                'consequence' => 'The canonical attendance intake authority records a reviewed decision. A delivery outcome or completion is only created where the existing settlement authority allows it.',
                'fields' => static fn(object $record): array => array(
                    self::attendanceCaseField((int) $record->id),
                    self::selectField('operation', 'Reviewed outcome', array(
                        'reassess' => 'Reassess evidence',
                        'settle_delivered' => 'Settle delivered',
                        'record_no_change' => 'Close — no canonical change',
                        'review_required' => 'Publish review required',
                        'teacher_non_delivery' => 'Publish teacher non-delivery',
                    ), 'reassess'),
                ),
                'visible' => static fn(object $record): bool => self::hasAttendanceCase((int) $record->id),
            ),
            'student-reschedule-decision' => array(
                'label' => 'Decide reschedule proposal',
                'description' => 'Confirm or cancel one pending Teacher reschedule proposal for this student.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_lessons',
                'submit_label' => 'Save reschedule decision',
                'require_confirmation' => true,
                'confirmation' => 'I reviewed the proposed time and the student-specific evidence.',
                'consequence' => 'Confirmation uses the same proposal and schedule authority as Student acceptance. Cancellation only closes the pending proposal before a staff-initiated change.',
                'fields' => static fn(object $record): array => array(
                    self::rescheduleProposalField((int) $record->id),
                    self::selectField('decision', 'Decision', array('' => 'Choose decision', 'confirm' => 'Confirm proposed time', 'cancel' => 'Cancel proposal'), ''),
                ),
                'visible' => static fn(object $record): bool => self::hasRescheduleProposal((int) $record->id),
            ),
            'student-resolve-booking-identity' => array(
                'label' => 'Resolve booking to this student',
                'description' => 'Resolve one unresolved introductory booking that shares this student’s email or phone.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_resolve_booking_request_student_identity',
                'submit_label' => 'Resolve verified identity',
                'require_confirmation' => true,
                'confirmation' => 'I compared the request contact and evidence with the verified student identity.',
                'consequence' => 'The canonical identity-resolution authority records a resolved event and links the booking request to this student. It does not create a second student or rewrite identity history.',
                'fields' => static fn(object $record): array => array(self::pendingBookingField((int) $record->id)),
                'visible' => static fn(object $record): bool => self::hasPendingBookingMatch((int) $record->id),
            ),
            'student-booking-identity-outcome' => array(
                'label' => 'Record non-resolved identity outcome',
                'description' => 'Record a reviewed outcome when a candidate booking is not this student.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_resolve_booking_request_student_identity',
                'submit_label' => 'Record outcome',
                'require_confirmation' => true,
                'confirmation' => 'I reviewed the candidate evidence and this is not a verified match.',
                'consequence' => 'The canonical identity-resolution authority records ambiguous, unresolved or rejected evidence. It does not link or merge the student.',
                'fields' => static fn(object $record): array => array(
                    self::pendingBookingField((int) $record->id),
                    self::selectField('outcome', 'Reviewed outcome', array('ambiguous' => 'Ambiguous — more evidence needed', 'unresolved' => 'Unresolved', 'rejected' => 'Rejected — not this student'), 'ambiguous'),
                ),
                'visible' => static fn(object $record): bool => self::hasPendingBookingMatch((int) $record->id),
            ),
            'student-reassign-teacher' => array(
                'label' => 'Replace teacher',
                'description' => 'Record the new teacher’s agreement and replace the current canonical Teacher Assignment.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_teacher_assignments',
                'submit_label' => 'Replace teacher',
                'require_confirmation' => true,
                'confirmation' => 'I confirmed that the new teacher agreed and that future schedules have been released or rescheduled.',
                'consequence' => 'The current assignment is terminated and a successor assignment is created. The authority refuses the change while an active future schedule exists.',
                'fields' => static fn(object $record): array => self::teacherReplacementFields((int) $record->id),
                'visible' => static fn(object $record): bool => self::hasAssignmentOption((int) $record->id),
            ),
            'teacher-archive' => array(
                'label' => 'Archive teacher',
                'description' => 'Archive this teacher, revoke open invitations and Teacher-only access, and preserve teaching and audit history.',
                'record_type' => 'teacher',
                'record_capability' => 'dzn_manage_teachers',
                'capability' => 'dzn_manage_teachers',
                'submit_label' => 'Archive teacher',
                'require_confirmation' => true,
                'confirmation' => 'I understand that this revokes open invitations and Teacher-only access while preserving history.',
                'consequence' => 'Archiving preserves the Teacher and all teaching/audit records, revokes open invitations and the Teacher-only WordPress role, and leaves other roles unchanged. Current teaching assignments or active future schedules may block archiving.',
                'fields' => array(),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (string) ($record->status ?? '') !== 'archived',
            ),
            'teacher-restore' => array(
                'label' => 'Restore teacher',
                'description' => 'Restore this archived teacher profile so a new invitation can be issued.',
                'record_type' => 'teacher',
                'record_capability' => 'dzn_manage_teachers',
                'capability' => 'dzn_manage_teachers',
                'submit_label' => 'Restore teacher',
                'require_confirmation' => true,
                'confirmation' => 'I understand that restoring does not automatically restore Teacher access.',
                'consequence' => 'Restoring makes the profile eligible for a new invitation. It does not restore Teacher access automatically; a new invitation and onboarding decision are still required. Existing archival and audit history remains.',
                'fields' => array(),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) !== null || (string) ($record->status ?? '') === 'archived',
            ),
            'teacher-profile' => array(
                'label' => 'Edit teacher details',
                'description' => 'Update contact, location, timezone and calendar preferences for this teacher.',
                'record_type' => 'teacher',
                'record_capability' => 'dzn_manage_teachers',
                'capability' => 'dzn_manage_teachers',
                'submit_label' => 'Save teacher details',
                'consequence' => 'Changes update the profile used by onboarding and scheduling. Email must remain valid for account matching.',
                'fields' => static fn(object $record): array => self::teacherProfileFields($record),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (string) ($record->status ?? '') !== 'archived',
            ),
            'teacher-accepting-state' => array(
                'label' => 'Change student intake',
                'description' => 'Set whether this teacher is accepting students, has limited availability, or is paused.',
                'record_type' => 'teacher',
                'record_capability' => 'dzn_manage_teachers',
                'capability' => 'dzn_manage_teaching_eligibility',
                'submit_label' => 'Save student intake',
                'consequence' => 'Student intake affects matching and availability decisions. It does not change teaching eligibility or existing assignments.',
                'fields' => static fn(object $record): array => array(self::selectField('state', 'Student intake', array('' => 'Choose student intake', 'accepting' => 'Accepting students', 'limited' => 'Limited availability', 'paused' => 'Paused'), in_array((string) ($record->accepting_state ?? ''), array('accepting', 'limited', 'paused'), true) ? (string) $record->accepting_state : '', 'Paused stops new matching; existing lessons are unchanged.')),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (string) ($record->status ?? '') !== 'archived',
            ),
            'teacher-eligibility' => array(
                'label' => 'Add course eligibility',
                'description' => 'Record that this teacher may teach an active academy course.',
                'record_type' => 'teacher',
                'record_capability' => 'dzn_manage_teachers',
                'capability' => 'dzn_manage_teaching_eligibility',
                'submit_label' => 'Add eligibility',
                'consequence' => 'Eligibility makes the course available for matching. It does not assign a student, create a lesson or change existing eligibility.',
                'fields' => static fn(object $record): array => array(self::courseEligibilityField()),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (string) ($record->status ?? '') !== 'archived',
            ),
            'teacher-availability-profile' => array(
                'label' => 'Set availability timezone',
                'description' => 'Set the IANA timezone used by this teacher’s weekly availability.',
                'record_type' => 'teacher',
                'record_capability' => 'dzn_manage_teachers',
                'capability' => 'dzn_manage_teacher_availability',
                'submit_label' => 'Save availability timezone',
                'consequence' => 'The timezone governs how weekly availability is interpreted. It cannot change while availability facts exist.',
                'fields' => static fn(object $record): array => array(self::field('timezone', 'Availability timezone', (string) ($record->timezone ?? ''), true, 'text', 'Use an IANA timezone such as Asia/Tehran.')),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (string) ($record->status ?? '') !== 'archived',
            ),
            'teacher-availability-rule' => array(
                'label' => 'Add weekly availability',
                'description' => 'Add a recurring weekly availability window for this teacher. Set the availability timezone first if no profile exists.',
                'record_type' => 'teacher',
                'record_capability' => 'dzn_manage_teachers',
                'capability' => 'dzn_manage_teacher_availability',
                'submit_label' => 'Add weekly availability',
                'consequence' => 'The window becomes available for matching and scheduling decisions. Overlapping windows are refused.',
                'fields' => static fn(object $record): array => array(
                    self::selectField('weekday', 'Day', array('1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '7' => 'Sunday'), '1'),
                    self::field('local_start_time', 'From', '', true, 'text', 'Local time, for example 09:00.'),
                    self::field('local_end_time', 'To', '', true, 'text', 'Local time, for example 17:00.'),
                    self::selectField('state', 'Availability', array('requestable' => 'Available on request', 'preferred' => 'Preferred', 'blocked' => 'Unavailable'), 'requestable'),
                    self::field('timezone', 'Timezone', (string) ($record->availability_timezone ?? $record->timezone ?? ''), true, 'text', 'Must match the active availability profile timezone.'),
                ),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (string) ($record->status ?? '') !== 'archived',
            ),
            'teacher-offboard' => array(
                'label' => 'Deactivate linked account',
                'description' => 'Offboard this teacher’s linked WordPress principal without archiving the teaching record.',
                'record_type' => 'teacher',
                'record_capability' => 'dzn_manage_teachers',
                'capability' => 'dzn_relink_teacher_principal',
                'submit_label' => 'Deactivate linked account',
                'require_confirmation' => true,
                'confirmation' => 'I understand that this removes the linked account’s teacher access.',
                'consequence' => 'The linked principal loses Teacher access. The teacher record and history remain; a new verified account link is required to restore portal access.',
                'fields' => array(),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (int) ($record->wordpress_user_id ?? 0) > 0,
            ),
            'teacher-approve-onboarding' => array(
                'label' => 'Approve onboarding',
                'description' => 'Approve this submitted teacher profile and availability declaration.',
                'record_type' => 'teacher',
                'record_capability' => 'dzn_manage_onboarding',
                'capability' => 'dzn_manage_onboarding',
                'submit_label' => 'Approve and activate',
                'require_confirmation' => true,
                'confirmation' => 'I have reviewed the profile, availability and Google connection evidence.',
                'consequence' => 'Approval grants portal readiness. A Google account connection alone is not evidence that Google Meet works.',
                'fields' => array(),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (string) ($record->onboarding_state ?? '') === 'pending_review',
            ),
            'teacher-return-onboarding' => array(
                'label' => 'Return for changes',
                'description' => 'Return this onboarding submission for corrections.',
                'record_type' => 'teacher',
                'record_capability' => 'dzn_manage_onboarding',
                'capability' => 'dzn_manage_onboarding',
                'submit_label' => 'Return for changes',
                'consequence' => 'The teacher becomes editable again and readiness remains not ready.',
                'fields' => static fn(object $record): array => array(self::field('reason_code', 'Reason code', '', true, 'text', 'A short controlled reason for the teacher.')),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (string) ($record->onboarding_state ?? '') === 'pending_review',
            ),
            'teacher-reject-onboarding' => array(
                'label' => 'Reject onboarding',
                'description' => 'Reject this onboarding submission.',
                'record_type' => 'teacher',
                'record_capability' => 'dzn_manage_onboarding',
                'capability' => 'dzn_manage_onboarding',
                'submit_label' => 'Reject onboarding',
                'require_confirmation' => true,
                'confirmation' => 'I understand that rejection keeps the teacher not ready.',
                'consequence' => 'Rejection keeps the teacher not ready. A new submission or invitation may be required.',
                'fields' => static fn(object $record): array => array(self::field('reason_code', 'Reason code', '', true, 'text', 'A short controlled reason for the teacher.')),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (string) ($record->onboarding_state ?? '') === 'pending_review',
            ),
        );
    }

    /** @return array<string, mixed>|null */
    public static function definition(string $key): ?array {
        return self::definitions()[$key] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function forRecord(string $recordType, object $record): array {
        $actions = array();
        foreach (self::definitions() as $key => $definition) {
            if ((string) $definition['record_type'] !== $recordType) continue;
            $visible = $definition['visible'] ?? null;
            if (is_callable($visible) && !$visible($record)) continue;
            $actions[$key] = $definition;
        }
        return $actions;
    }

    /**
     * Evaluate visibility only for the actions a caller intends to render.
     * This keeps directory rows bounded when one row needs only archive/restore.
     *
     * @param array<int,string> $keys
     * @return array<string, array<string, mixed>>
     */
    public static function forRecordSelected(string $recordType, object $record, array $keys): array {
        $actions = array();
        foreach ($keys as $key) {
            $definition = self::definition($key);
            if (!$definition || (string) $definition['record_type'] !== $recordType) continue;
            $visible = $definition['visible'] ?? null;
            if (is_callable($visible) && !$visible($record)) continue;
            $actions[$key] = $definition;
        }
        return $actions;
    }

    /** @return array<int, array<string, mixed>> */
    public static function fieldsFor(string $key, object $record): array {
        $definition = self::definition($key);
        if (!$definition) return array();
        $fields = $definition['fields'] ?? array();
        if (is_callable($fields)) $fields = $fields($record);
        return is_array($fields) ? $fields : array();
    }

    /** @return array{message:string} */
    public static function handle(string $key, RecordActionContext $context, array $input): array {
        $definition = self::definition($key);
        if (!$definition || (string) $definition['record_type'] !== $context->recordType()) {
            throw new RecordActionException('unsupported_record_action');
        }
        $archive = new ArchiveService();
        return match ($key) {
            'student-profile' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->updateProfile($context->recordId(), $input);
            }, 'Student details saved.'),
            'student-deactivate' => self::run(static function () use ($context): void {
                (new StudentAdministrationService())->deactivate($context->recordId());
            }, 'Student deactivated.'),
            'student-reactivate' => self::run(static function () use ($context): void {
                (new StudentAdministrationService())->reactivate($context->recordId());
            }, 'Student reactivated.'),
            'student-archive' => self::run(static function () use ($archive, $context): void {
                $archive->archive('student', $context->recordId());
            }, 'Student archived.'),
            'student-restore' => self::run(static function () use ($archive, $context): void {
                $archive->restore('student', $context->recordId());
            }, 'Student restored.'),
            'student-send-password-reset' => self::run(static function () use ($context): void {
                (new StudentAdministrationService())->sendPasswordReset($context->recordId());
            }, 'Password reset request accepted for delivery.'),
            'student-issue-account-claim' => self::run(static function () use ($context): void {
                (new StudentAdministrationService())->issueAccountClaim($context->recordId(), $context->idempotencyKey());
            }, 'Account claim invitation accepted for delivery.'),
            'student-link-account' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->linkAccount($context->recordId(), (int) ($input['wordpress_user_id'] ?? 0));
            }, 'Verified account linked.'),
            'student-supersede-account' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->supersedeLinkedAccount($context->recordId(), (int) ($input['wordpress_user_id'] ?? 0));
            }, 'Linked account replaced.'),
            'student-deactivate-account' => self::run(static function () use ($context): void {
                (new StudentAdministrationService())->deactivateLinkedAccount($context->recordId());
            }, 'Student account access deactivated.'),
            'student-classify-capacity' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->classifyCapacity($context->recordId(), (string) ($input['classification'] ?? ''));
            }, 'Reviewed age and authority category saved.'),
            'student-guardian-grant' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->grantGuardian($context->recordId(), (int) ($input['wordpress_user_id'] ?? 0), self::effectiveUntil($input));
            }, 'Guardian authority granted.'),
            'student-guardian-revoke' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->revokeGuardian($context->recordId(), (int) ($input['grant_id'] ?? 0));
            }, 'Guardian authority revoked.'),
            'student-guardian-supersede' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->supersedeGuardian($context->recordId(), (int) ($input['grant_id'] ?? 0), (int) ($input['wordpress_user_id'] ?? 0), self::effectiveUntil($input));
            }, 'Guardian authority replaced.'),
            'student-task-acknowledge' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->transitionTask($context->recordId(), (int) ($input['exception_id'] ?? 0), 'acknowledged', null);
            }, 'Task acknowledged.'),
            'student-task-resolve' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->transitionTask($context->recordId(), (int) ($input['exception_id'] ?? 0), 'resolved', (string) ($input['resolution_note'] ?? ''));
            }, 'Task resolved.'),
            'student-task-dismiss' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->transitionTask($context->recordId(), (int) ($input['exception_id'] ?? 0), 'dismissed', (string) ($input['resolution_note'] ?? ''));
            }, 'Task dismissed.'),
            'student-enrolment-transition' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->transitionEnrolment(
                    $context->recordId(),
                    (int) ($input['enrolment_id'] ?? 0),
                    (string) ($input['operation'] ?? ''),
                    (string) ($input['reason'] ?? ''),
                    $context->idempotencyKey()
                );
            }, 'Enrolment lifecycle updated.'),
            'student-term-transition' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->transitionTerm(
                    $context->recordId(),
                    (int) ($input['term_id'] ?? 0),
                    (string) ($input['operation'] ?? ''),
                    (string) ($input['reason'] ?? ''),
                    $context->idempotencyKey()
                );
            }, 'Term lifecycle updated.'),
            'student-lesson-reschedule' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->rescheduleLesson(
                    $context->recordId(),
                    (int) ($input['lesson_id'] ?? 0),
                    (string) ($input['local_date'] ?? ''),
                    (string) ($input['local_time'] ?? ''),
                    (int) ($input['duration_minutes'] ?? 0),
                    (string) ($input['reason'] ?? ''),
                    $context->idempotencyKey()
                );
            }, 'Lesson schedule revised.'),
            'student-attendance-review' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->reviewAttendance(
                    $context->recordId(),
                    (int) ($input['case_id'] ?? 0),
                    (string) ($input['operation'] ?? ''),
                    $context->idempotencyKey()
                );
            }, 'Attendance reviewed.'),
            'student-reschedule-decision' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->decideRescheduleProposal(
                    $context->recordId(),
                    (string) ($input['proposal_uid'] ?? ''),
                    (string) ($input['decision'] ?? ''),
                    $context->idempotencyKey()
                );
            }, 'Reschedule decision saved.'),
            'student-resolve-booking-identity' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->resolveBookingIdentity(
                    $context->recordId(),
                    (int) ($input['booking_request_id'] ?? 0),
                    $context->idempotencyKey()
                );
            }, 'Booking identity resolved.'),
            'student-booking-identity-outcome' => self::run(static function () use ($context, $input): void {
                (new StudentAdministrationService())->recordBookingIdentityOutcome(
                    $context->recordId(),
                    (int) ($input['booking_request_id'] ?? 0),
                    (string) ($input['outcome'] ?? ''),
                    $context->idempotencyKey()
                );
            }, 'Reviewed identity outcome recorded.'),
            'student-reassign-teacher' => self::run(static function () use ($context, $input): void {
                $assignmentKey = (string) ($input['assignment_key'] ?? '');
                $parts = explode('-', $assignmentKey, 2);
                (new StudentAdministrationService())->reassignTeacher(
                    $context->recordId(),
                    (int) ($parts[0] ?? 0),
                    (int) ($parts[1] ?? 0),
                    (int) ($input['teacher_id'] ?? 0),
                    (string) ($input['channel'] ?? ''),
                    (string) ($input['reference'] ?? ''),
                    $context->idempotencyKey()
                );
            }, 'Teacher assignment replaced.'),
            'teacher-archive' => self::run(static function () use ($archive, $context): void {
                $archive->archive('teacher', $context->recordId());
            }, 'Teacher archived.'),
            'teacher-restore' => self::run(static function () use ($archive, $context): void {
                $archive->restore('teacher', $context->recordId());
            }, 'Teacher restored.'),
            'teacher-profile' => self::run(static function () use ($context, $input): void {
                (new TeacherOnboardingService())->updateByAdministrator($context->recordId(), $input);
            }, 'Teacher details saved.'),
            'teacher-accepting-state' => self::run(static function () use ($context, $input): void {
                (new TeacherAcceptingStateService())->set(array(
                    'teacher_id' => $context->recordId(),
                    'state' => (string) ($input['state'] ?? ''),
                    'reason_code' => 'teacher_admin',
                ));
            }, 'Student intake updated.'),
            'teacher-eligibility' => self::run(static function () use ($context, $input): void {
                (new TeachingEligibilityService())->setEligibility(array(
                    'teacher_id' => $context->recordId(),
                    'course_id' => (int) ($input['course_id'] ?? 0),
                    'status' => 'active',
                    'reason_code' => 'teacher_admin',
                ));
            }, 'Course eligibility added.'),
            'teacher-availability-profile' => self::run(static function () use ($context, $input): void {
                (new TeacherAvailabilityService())->setProfile(array(
                    'teacher_id' => $context->recordId(),
                    'timezone' => (string) ($input['timezone'] ?? ''),
                    'status' => 'active',
                    'reason_code' => 'teacher_admin',
                ));
            }, 'Availability timezone saved.'),
            'teacher-availability-rule' => self::run(static function () use ($context, $input): void {
                (new TeacherAvailabilityService())->setRecurringRule(array(
                    'teacher_id' => $context->recordId(),
                    'weekday' => (int) ($input['weekday'] ?? 0),
                    'local_start_time' => (string) ($input['local_start_time'] ?? ''),
                    'local_end_time' => (string) ($input['local_end_time'] ?? ''),
                    'state' => (string) ($input['state'] ?? ''),
                    'timezone' => (string) ($input['timezone'] ?? ''),
                    'status' => 'active',
                    'reason_code' => 'teacher_admin',
                ));
            }, 'Weekly availability added.'),
            'teacher-offboard' => self::run(static function () use ($context): void {
                (new PrincipalInvitationService())->offboard($context->recordId(), 'admin_offboarded');
            }, 'Teacher account deactivated.'),
            'teacher-approve-onboarding' => self::run(static function () use ($context): void {
                (new TeacherOnboardingService())->review($context->recordId(), 'approve', null);
            }, 'Teacher onboarding approved.'),
            'teacher-return-onboarding' => self::run(static function () use ($context, $input): void {
                (new TeacherOnboardingService())->review($context->recordId(), 'return', (string) ($input['reason_code'] ?? ''));
            }, 'Teacher onboarding returned for changes.'),
            'teacher-reject-onboarding' => self::run(static function () use ($context, $input): void {
                (new TeacherOnboardingService())->review($context->recordId(), 'reject', (string) ($input['reason_code'] ?? ''));
            }, 'Teacher onboarding rejected.'),
            default => throw new RecordActionException('unsupported_record_action'),
        };
    }

    private static function run(callable $handler, string $message): array {
        $handler();
        return array('message' => $message);
    }

    /** @return array<string, mixed> */
    private static function field(string $name, string $label, string $value = '', bool $required = false, string $type = 'text', string $description = ''): array {
        return array('name' => $name, 'label' => $label, 'value' => $value, 'required' => $required, 'type' => $type, 'description' => $description);
    }

    /** @param array<string, string> $options @return array<string, mixed> */
    private static function selectField(string $name, string $label, array $options, string $value = '', string $description = ''): array {
        return array('name' => $name, 'label' => $label, 'type' => 'select', 'options' => $options, 'value' => $value, 'required' => true, 'description' => $description);
    }

    /** @return array<int, array<string, mixed>> */
    private static function teacherProfileFields(object $record): array {
        return array(
            self::field('display_name', 'Display name', (string) ($record->display_name ?? ''), true),
            self::field('persian_name', 'Persian name', (string) ($record->persian_name ?? '')),
            self::field('english_name', 'English name', (string) ($record->english_name ?? '')),
            self::field('email', 'Email', (string) ($record->email ?? ''), true, 'email'),
            self::field('phone', 'Phone', (string) ($record->phone ?? '')),
            self::field('whatsapp_phone', 'WhatsApp', (string) ($record->whatsapp_phone ?? '')),
            self::field('country_code', 'Country code', (string) ($record->country_code ?? ''), true),
            self::field('city', 'City', (string) ($record->city ?? '')),
            self::field('timezone', 'Timezone', (string) ($record->timezone ?? ''), true, 'text', 'IANA timezone such as Asia/Tehran.'),
            self::field('locale', 'Locale', (string) ($record->locale ?? ''), true),
            self::selectField('calendar_preference', 'Calendar', array('auto' => 'Automatic', 'gregorian' => 'Gregorian', 'persian' => 'Persian'), (string) ($record->calendar_preference ?? 'auto')),
        );
    }

    private static function hasEnrolmentAction(int $studentId): bool {
        if ($studentId < 1 || !function_exists('current_user_can') || !current_user_can('dzn_manage_students')) return false;
        try { return (new StudentWorkspaceReadService())->enrolmentActionOptions($studentId) !== array(); }
        catch (\Throwable) { return false; }
    }

    /** @return array<string,mixed> */
    private static function enrolmentActionField(int $studentId): array {
        $options = array('' => 'Choose current enrolment');
        try {
            foreach ((new StudentWorkspaceReadService())->enrolmentActionOptions($studentId) as $row) {
                $options[(string) $row->id] = trim((string) ($row->course_name ?? 'Course') . ' · ' . ucfirst(str_replace('_', ' ', (string) $row->lifecycle_state)) . ' · ' . (string) ($row->reference_code ?: 'No public reference'));
            }
        } catch (\Throwable) {}
        return self::selectField('enrolment_id', 'Current enrolment', $options, '', 'Only canonical enrolments in an active, current or paused state are listed.');
    }

    private static function hasTermAction(int $studentId): bool {
        if ($studentId < 1 || !function_exists('current_user_can') || !current_user_can('dzn_manage_students')) return false;
        try { return (new StudentWorkspaceReadService())->termActionOptions($studentId) !== array(); }
        catch (\Throwable) { return false; }
    }

    /** @return array<string,mixed> */
    private static function termActionField(int $studentId): array {
        $options = array('' => 'Choose current term');
        try {
            foreach ((new StudentWorkspaceReadService())->termActionOptions($studentId) as $row) {
                $options[(string) $row->id] = 'Term ' . (int) $row->sequence_number . ' · ' . (string) ($row->course_name ?? 'Course') . ' · ' . ucfirst(str_replace('_', ' ', (string) $row->lifecycle_state));
            }
        } catch (\Throwable) {}
        return self::selectField('term_id', 'Current term', $options, '', 'Only canonical Terms in an authorised or current state are listed.');
    }

    private static function hasLessonAction(int $studentId): bool {
        if ($studentId < 1 || !function_exists('current_user_can') || !current_user_can('dzn_manage_students')) return false;
        try { return (new StudentWorkspaceReadService())->lessonActionOptions($studentId) !== array(); }
        catch (\Throwable) { return false; }
    }

    /** @return array<string,mixed> */
    private static function lessonActionField(int $studentId): array {
        $options = array('' => 'Choose authorised lesson');
        try {
            foreach ((new StudentWorkspaceReadService())->lessonActionOptions($studentId) as $row) {
                $options[(string) $row->id] = trim((string) ($row->course_name ?? 'Class') . ' · ' . (string) ($row->reference_code ?: 'No public reference') . ' · current ' . (string) ($row->starts_at_utc ?: 'not scheduled'));
            }
        } catch (\Throwable) {}
        return self::selectField('lesson_id', 'Lesson', $options, '', 'Only authorised canonical Lessons with a current Teacher assignment are listed.');
    }

    private static function hasAttendanceCase(int $studentId): bool {
        if ($studentId < 1 || !function_exists('current_user_can') || !current_user_can('dzn_manage_students')) return false;
        try { return (new StudentWorkspaceReadService())->attendanceCaseOptions($studentId) !== array(); }
        catch (\Throwable) { return false; }
    }

    /** @return array<string,mixed> */
    private static function attendanceCaseField(int $studentId): array {
        $options = array('' => 'Choose attendance case');
        try {
            foreach ((new StudentWorkspaceReadService())->attendanceCaseOptions($studentId) as $row) {
                $options[(string) $row->id] = (string) $row->label;
            }
        } catch (\Throwable) {}
        return self::selectField('case_id', 'Attendance case', $options, '', 'Only cases awaiting an operator outcome are listed.');
    }

    private static function hasRescheduleProposal(int $studentId): bool {
        if ($studentId < 1 || !function_exists('current_user_can') || !current_user_can('dzn_manage_students')) return false;
        try { return (new StudentWorkspaceReadService())->rescheduleProposalOptions($studentId) !== array(); }
        catch (\Throwable) { return false; }
    }

    /** @return array<string,mixed> */
    private static function rescheduleProposalField(int $studentId): array {
        $options = array('' => 'Choose pending proposal');
        try {
            foreach ((new StudentWorkspaceReadService())->rescheduleProposalOptions($studentId) as $row) {
                $options[(string) $row->uid] = (string) $row->label;
            }
        } catch (\Throwable) {}
        return self::selectField('proposal_uid', 'Pending reschedule proposal', $options, '', 'Only pending or committing proposals for this student are listed.');
    }

    private static function hasPendingBookingMatch(int $studentId): bool {
        if ($studentId < 1 || !function_exists('current_user_can') || !current_user_can('dzn_manage_students')) return false;
        try { return (new StudentWorkspaceReadService())->pendingBookingIdentityMatches($studentId) !== array(); }
        catch (\Throwable) { return false; }
    }

    /** @return array<string,mixed> */
    private static function pendingBookingField(int $studentId): array {
        $options = array('' => 'Choose matching booking request');
        try {
            foreach ((new StudentWorkspaceReadService())->pendingBookingIdentityMatches($studentId) as $row) {
                $options[(string) $row->id] = (string) ($row->reference_code ?: 'Introductory request') . ' · ' . (string) ($row->instrument_name ?? 'Instrument') . ' · ' . (string) $row->created_at;
            }
        } catch (\Throwable) {}
        return self::selectField('booking_request_id', 'Matching booking request', $options, '', 'Only unresolved requests with a matching student contact are listed.');
    }

    /** @return array<int, array<string, mixed>> */
    private static function studentProfileFields(object $record): array {
        return array(
            self::field('first_name', 'First name', (string) ($record->first_name ?? '')),
            self::field('last_name', 'Last name', (string) ($record->last_name ?? '')),
            self::field('display_name', 'Display / preferred name', (string) ($record->display_name ?? ''), true),
            self::field('email', 'Email', (string) ($record->email ?? ''), false, 'email'),
            self::field('phone', 'Phone', (string) ($record->phone ?? '')),
            self::field('whatsapp_phone', 'WhatsApp', (string) ($record->whatsapp_phone ?? '')),
            self::field('country_code', 'Country code', (string) ($record->country_code ?? ''), false, 'text', 'Two-letter country code.'),
            self::field('city', 'City', (string) ($record->city ?? '')),
            self::field('timezone', 'Time zone', (string) ($record->timezone ?? ''), true, 'text', 'IANA timezone such as Australia/Brisbane.'),
            self::field('locale', 'Preferred language', (string) ($record->locale ?? ''), false, 'text', 'Language and locale, for example en_AU or fa_IR.'),
            self::selectField('calendar_preference', 'Calendar', array('auto' => 'Automatic', 'gregorian' => 'Gregorian', 'persian' => 'Persian'), (string) ($record->calendar_preference ?? 'auto')),
        );
    }

    private static function studentHasActiveAccount(object $record): bool {
        $id = (int) ($record->id ?? 0);
        if ($id < 1 || !function_exists('current_user_can') || !current_user_can('dzn_manage_students')) return false;
        try { return (new StudentWorkspaceReadService())->hasActiveAccount($id); }
        catch (\Throwable) { return false; }
    }

    /** @return array<string,mixed> */
    private static function studentAccountField(string $label = 'Verified WordPress account'): array {
        $options = array('' => 'Choose a verified account');
        $users = function_exists('get_users') ? get_users(array(
            'number' => 200,
            'orderby' => 'display_name',
            'order' => 'ASC',
            'role__not_in' => array('administrator'),
            'fields' => array('ID', 'display_name', 'user_login', 'user_email'),
        )) : array();
        foreach ($users as $user) {
            $option = trim((string) ($user->display_name ?? ''));
            $login = trim((string) ($user->user_login ?? ''));
            $email = trim((string) ($user->user_email ?? ''));
            $options[(string) $user->ID] = trim($option . ($login !== '' ? ' · ' . $login : '') . ($email !== '' ? ' · ' . $email : ''));
        }
        return self::selectField('wordpress_user_id', $label, $options, '', 'Only existing WordPress accounts are listed. Identity must be verified before linking.');
    }

    private static function studentCapacity(int $studentId): string {
        if ($studentId < 1 || !function_exists('current_user_can') || !current_user_can('dzn_manage_students')) return '';
        try { return (new StudentWorkspaceReadService())->capacityForStudent($studentId); }
        catch (\Throwable) { return ''; }
    }

    private static function hasActiveGuardian(int $studentId): bool {
        if ($studentId < 1 || !function_exists('current_user_can') || !current_user_can('dzn_manage_students')) return false;
        try { return (new StudentWorkspaceReadService())->activeGuardianOptions($studentId) !== array(); }
        catch (\Throwable) { return false; }
    }

    /** @return array<string,mixed> */
    private static function activeGuardianField(int $studentId): array {
        $options = array('' => 'Choose active guardian authority');
        try {
            foreach ((new StudentWorkspaceReadService())->activeGuardianOptions($studentId) as $grant) {
                $options[(string) $grant->id] = trim((string) ($grant->display_name ?? 'Guardian') . ' · ' . (string) ($grant->user_email ?? ''));
            }
        } catch (\Throwable) {}
        return self::selectField('grant_id', 'Current guardian authority', $options, '', 'Only active representative grants for this student are listed.');
    }

    private static function hasOpenTask(int $studentId): bool {
        if ($studentId < 1 || !function_exists('current_user_can') || !current_user_can('dzn_manage_students')) return false;
        try { return (new StudentWorkspaceReadService())->openTaskOptions($studentId) !== array(); }
        catch (\Throwable) { return false; }
    }

    /** @return array<string,mixed> */
    private static function openTaskField(int $studentId): array {
        $options = array('' => 'Choose open task');
        try {
            foreach ((new StudentWorkspaceReadService())->openTaskOptions($studentId) as $task) {
                $options[(string) $task->id] = (string) $task->summary;
            }
        } catch (\Throwable) {}
        return self::selectField('exception_id', 'Open task', $options, '', 'Only operational exceptions attached to this student or its enrolments and lessons are listed.');
    }

    private static function hasAssignmentOption(int $studentId): bool {
        if ($studentId < 1 || !function_exists('current_user_can') || !current_user_can('dzn_manage_students')) return false;
        try { return (new StudentWorkspaceReadService())->assignmentOptions($studentId) !== array(); }
        catch (\Throwable) { return false; }
    }

    /** @return array<int, array<string, mixed>> */
    private static function teacherReplacementFields(int $studentId): array {
        $assignmentOptions = array('' => 'Choose current enrolment and assignment');
        $currentTeacher = '';
        try {
            foreach ((new StudentWorkspaceReadService())->assignmentOptions($studentId) as $option) {
                if ($option->assignment_id === null) continue;
                $label = trim((string) ($option->course_name ?? 'Course') . ' · current teacher: ' . (string) ($option->teacher_name ?? 'not recorded'));
                $assignmentOptions[(int) $option->enrolment_id . '-' . (int) $option->assignment_id] = $label;
                if ($currentTeacher === '') $currentTeacher = (string) ($option->teacher_name ?? '');
            }
        } catch (\Throwable) {}
        $teacherOptions = array('' => 'Choose a replacement teacher');
        try {
            foreach ((new StudentWorkspaceReadService())->activeTeacherOptions() as $teacher) {
                $teacherOptions[(string) $teacher->id] = (string) $teacher->display_name;
            }
        } catch (\Throwable) {}
        return array(
            self::selectField('assignment_key', 'Current enrolment and assignment', $assignmentOptions, '', $currentTeacher !== '' ? 'Current teacher: ' . $currentTeacher : 'Only canonical enrolments with a current assignment are listed.'),
            self::selectField('teacher_id', 'Replacement teacher', $teacherOptions),
            self::selectField('channel', 'How was the new teacher’s agreement confirmed?', array(
                'phone' => 'Phone',
                'whatsapp' => 'WhatsApp',
                'email' => 'Email',
                'video_call' => 'Video call',
                'in_person' => 'In person',
                'other_verified' => 'Other verified evidence',
            )),
            self::field('reference', 'Agreement reference or note', '', true, 'text', 'A short internal reference. It is hashed by the assignment authority.'),
        );
    }

    private static function effectiveUntil(array $input): ?string {
        $date = trim((string) ($input['effective_until_date'] ?? ''));
        if ($date === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) throw new RecordActionException('invalid_effective_date');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' 23:59:59', new \DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RecordActionException('invalid_effective_date');
        return $parsed->format('Y-m-d H:i:s');
    }

    /** @return array<string, mixed> */
    private static function courseEligibilityField(): array {
        $options = array('' => 'Choose an active course');
        foreach ((new CoreReadService())->courseOptionsForTeachingEligibility() as $course) {
            $label = trim((string) ($course->name_en ?? ''));
            if ($label === '') $label = trim((string) ($course->name_fa ?? ''));
            $options[(string) $course->id] = $label !== '' ? $label : 'Course';
        }
        return self::selectField('course_id', 'Course', $options, '', 'Only active academy courses are listed.');
    }
}
