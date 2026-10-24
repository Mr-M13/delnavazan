<?php
declare(strict_types=1);

/** Regression guard for manual class visibility and canonical operator completion. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$screen = $read('src/Admin/Controller/ScreenController.php');
$portal = $read('src/Portals/PortalAuthenticatedReadService.php');
$authority = $read('src/Core/Application/CanonicalLessonAuthorityService.php');

$assert(str_contains($screen, "'complete_canonical_lesson' => self::completeCanonicalLesson(\$post)") && str_contains($screen, "? 'dzn_manage_canonical_lessons'"), 'Canonical completion must dispatch only behind its dedicated capability.');
$assert(str_contains($screen, 'private static function completeCanonicalLesson(array $post): int') && str_contains($screen, "->complete(") && str_contains($screen, "'class_not_finished'"), 'Completion must use canonical authority and refuse an unelapsed occurrence.');
$assert(str_contains($screen, 'private static function canonicalLessonCompletionForm(object $lesson): void') && str_contains($screen, "'Mark class as completed'") && str_contains($screen, 'self::canonicalEvidenceFields();'), 'The completed action must be contextual and evidence-backed.');
$assert(str_contains($authority, 'CanonicalLessonDeliveryGuard())->assertCompletable($lessonId)'), 'The canonical service must retain its delivery-integrity completion guard.');

$assert(str_contains($portal, "l.record_model='legacy_phase1' AND l.status IN ('scheduled','completed')") && str_contains($portal, 'INNER JOIN {$legacySchedules} v ON v.id=l.current_schedule_version_id'), 'Only scheduled or completed legacy classes with their exact current schedule may enter a portal read.');
$assert(str_contains($portal, 'private function legacyLessonFor(string $kind, int $lessonId, int $principalId): array') && str_contains($portal, 'l.{$column}=%d') && str_contains($portal, "'legacy_class_read_only'"), 'Legacy visibility must remain principal-scoped and read-only.');
$assert(str_contains($portal, "'lifecycle_state' => (string) \$row->status === 'completed' ? 'completed' : 'scheduled'") && str_contains($portal, "'absence_available' => false") && str_contains($portal, "'join_available' => false"), 'Legacy projections must not gain canonical public actions.');

echo "PASS class portal visibility and canonical completion contract\n";
