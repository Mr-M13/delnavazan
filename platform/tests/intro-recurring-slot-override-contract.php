<?php
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$migration = $read('src/Core/Infrastructure/Migration/Migrator.php');
$lifecycle = $read('src/Core/Application/IntroLessonLifecycleService.php');
$preparation = $read('src/Core/Application/PostIntroPreparationService.php');
$repository = $read('src/Core/Infrastructure/Repository/PostIntroRepository.php');
$policy = $read('src/Core/Application/IntroRecurringSlotOverridePolicy.php');
$expect = static function (bool $ok, string $message): void { if (! $ok) throw new RuntimeException($message); };

$expect(str_contains($migration, '072_intro_recurring_slot_override') && str_contains($migration, 'booking_request_recurring_slot_overrides') && str_contains($migration, 'confirmation_slot'), 'The additive exact-slot override migration is missing.');
$expect(str_contains($policy, "BASIS = 'teacher_rescheduled_outside_availability'") && str_contains($policy, 'function matches'), 'The controlled Teacher override policy is missing.');
$expect(str_contains($lifecycle, 'coveredByPublishedAvailability') && str_contains($lifecycle, 'recordRecurringSlotOverride') && str_contains($lifecycle, 'The Teacher must explicitly choose a time outside published availability.'), 'Intro rescheduling must gate non-Teacher outside-hours moves and persist Teacher authority.');
$expect(str_contains($lifecycle, 'evidence_reference_digest') && str_contains($lifecycle, 'ON DUPLICATE KEY UPDATE id=id'), 'Teacher override persistence must be digest-only and idempotent.');
$expect(str_contains($repository, 'recurringSlotOverrideForConfirmation') && str_contains($preparation, 'IntroRecurringSlotOverridePolicy::matches') && str_contains($preparation, 'authorisedTeacherOverride'), 'Post-intro preparation must consume only an exact persisted Teacher override.');
echo "Intro recurring-slot override contract passed\n";
