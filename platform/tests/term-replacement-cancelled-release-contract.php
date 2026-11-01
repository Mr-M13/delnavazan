<?php
/** Contract for releasing a cancelled replacement Lesson from the Student Term allowance. */
$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/src/Core/Infrastructure/Repository/LessonRescheduleRepository.php');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert(str_contains($source, "lesson_type='replacement' AND lifecycle_state<>'cancelled'"), 'A cancelled replacement Lesson must not consume the Term allowance.');
$assert(str_contains($source, "termReplacementAllocation"), 'Allocation must remain owned by the canonical rule.');
$assert(str_contains($source, "TermReplacementAllocationRule::allocate"), 'The repository must delegate allocation to the pure rule.');
echo "term-replacement-cancelled-release-contract: OK\n";
