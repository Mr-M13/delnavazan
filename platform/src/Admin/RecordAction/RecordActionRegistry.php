<?php
namespace Delnavazan\Platform\Admin\RecordAction;

use Delnavazan\Platform\Core\Application\ArchiveService;
use Delnavazan\Platform\Core\Application\CoreReadService;
use Delnavazan\Platform\Core\Application\PrincipalInvitationService;
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
