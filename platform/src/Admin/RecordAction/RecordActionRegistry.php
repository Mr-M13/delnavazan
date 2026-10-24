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
            'teacher-archive' => self::run(static function () use ($archive, $context): void {
                $archive->archive('teacher', $context->recordId());
            }, 'Teacher archived.'),
            'teacher-restore' => self::run(static function () use ($archive, $context): void {
                $archive->restore('teacher', $context->recordId());
            }, 'Teacher restored.'),
            default => throw new RecordActionException('unsupported_record_action'),
        };
    }

    private static function run(callable $handler, string $message): array {
        $handler();
        return array('message' => $message);
    }
}
