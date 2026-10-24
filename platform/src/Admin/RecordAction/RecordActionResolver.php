<?php
namespace Delnavazan\Platform\Admin\RecordAction;

use Delnavazan\Platform\Core\Application\CoreReadService;

/**
 * Re-resolves the record from its server-side type and id.
 *
 * The record id is signed into RecordActionToken and never trusted as a target
 * on its own. Every resolver also enforces the record's own read capability.
 */
final class RecordActionResolver {
    private const READ_CAPABILITIES = array(
        'student' => 'dzn_manage_students',
        'teacher' => 'dzn_manage_teachers',
        'lesson' => 'dzn_manage_lessons',
        'enrolment' => 'dzn_manage_enrolments',
        'term' => 'dzn_manage_terms',
        'course' => 'dzn_manage_courses',
        'instrument' => 'dzn_manage_courses',
    );

    public function resolve(string $recordType, int $recordId): object {
        if ($recordId < 1 || !isset(self::READ_CAPABILITIES[$recordType])) {
            throw new RecordActionException('unsupported_record_context');
        }
        if (!current_user_can(self::READ_CAPABILITIES[$recordType])) {
            throw new RecordActionException('record_read_forbidden');
        }
        $read = new CoreReadService();
        $record = match ($recordType) {
            'student' => $read->find('student', $recordId),
            'teacher' => $read->teacherAdminProfile($recordId),
            'lesson' => $read->lessonOperatorRecord($recordId),
            default => $read->find($recordType, $recordId),
        };
        if (!$record) throw new RecordActionException('record_not_found');
        return $record;
    }
}
