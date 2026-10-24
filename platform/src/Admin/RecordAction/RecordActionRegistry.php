<?php
namespace Delnavazan\Platform\Admin\RecordAction;

use Delnavazan\Platform\Core\Application\ArchiveService;

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
            'student-archive' => array(
                'label' => 'Archive student',
                'description' => 'Remove this student from active operational lists while preserving their history.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_students',
                'submit_label' => 'Archive student',
                'require_confirmation' => true,
                'confirmation' => 'I understand that the student will leave active lists and existing records will remain in history.',
                'consequence' => 'Archiving stops this student appearing in active lists. It does not delete the student, enrolments, lessons, payments or audit history. A dependent enrolment or schedule may block archiving.',
                'fields' => array(),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) === null && (string) ($record->status ?? '') !== 'archived',
            ),
            'student-restore' => array(
                'label' => 'Restore student',
                'description' => 'Return this archived student to the active student directory.',
                'record_type' => 'student',
                'record_capability' => 'dzn_manage_students',
                'capability' => 'dzn_manage_students',
                'submit_label' => 'Restore student',
                'require_confirmation' => true,
                'confirmation' => 'I understand that restoring changes the student status but does not recreate any deleted records.',
                'consequence' => 'Restoring returns this student to the active directory. Existing archival history and audit evidence remain.',
                'fields' => array(),
                'visible' => static fn(object $record): bool => ($record->archived_at ?? null) !== null || (string) ($record->status ?? '') === 'archived',
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

    /** @return array{message:string} */
    public static function handle(string $key, RecordActionContext $context, array $input): array {
        $definition = self::definition($key);
        if (!$definition || (string) $definition['record_type'] !== $context->recordType()) {
            throw new RecordActionException('unsupported_record_action');
        }
        $archive = new ArchiveService();
        return match ($key) {
            'student-archive' => self::run(static function () use ($archive, $context): void {
                $archive->archive('student', $context->recordId());
            }, 'Student archived.'),
            'student-restore' => self::run(static function () use ($archive, $context): void {
                $archive->restore('student', $context->recordId());
            }, 'Student restored.'),
            default => throw new RecordActionException('unsupported_record_action'),
        };
    }

    private static function run(callable $handler, string $message): array {
        $handler();
        return array('message' => $message);
    }
}
