<?php
namespace Delnavazan\Platform\Admin\RecordAction;

use Delnavazan\Platform\Core\Application\CoreReadService;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherRepository;

/**
 * Re-resolves the record from its server-side type and id.
 *
 * The record id is signed into RecordActionToken and never trusted as a target
 * on its own. Every resolver also enforces the record's own read capability.
 */
final class RecordActionResolver {
    private const READ_CAPABILITIES = array(
        'student' => array('dzn_manage_students'),
        'teacher' => array('dzn_manage_teachers', 'dzn_manage_onboarding'),
        'lesson' => array('dzn_manage_lessons'),
        'enrolment' => array('dzn_manage_enrolments'),
        'term' => array('dzn_manage_terms'),
        'course' => array('dzn_manage_courses'),
        'instrument' => array('dzn_manage_courses'),
    );

    public function resolve(string $recordType, int $recordId): object {
        if ($recordId < 1 || !isset(self::READ_CAPABILITIES[$recordType])) {
            throw new RecordActionException('unsupported_record_context');
        }
        $allowed = false;
        foreach (self::READ_CAPABILITIES[$recordType] as $capability) {
            if (current_user_can($capability)) { $allowed = true; break; }
        }
        if (!$allowed) throw new RecordActionException('record_read_forbidden');
        $read = new CoreReadService();
        $record = match ($recordType) {
            'student' => $read->find('student', $recordId),
            'teacher' => current_user_can('dzn_manage_teachers') ? $read->teacherAdminProfile($recordId) : (new TeacherRepository())->adminProfile($recordId),
            'lesson' => $read->lessonOperatorRecord($recordId),
            default => $read->find($recordType, $recordId),
        };
        if (!$record) throw new RecordActionException('record_not_found');
        return $record;
    }
}
