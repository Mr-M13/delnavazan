<?php
require_once dirname(__DIR__) . '/src/Core/Application/IntroRecurringSlotOverridePolicy.php';

use Delnavazan\Platform\Core\Application\IntroRecurringSlotOverridePolicy;

$assert = static function (bool $ok, string $message): void { if (! $ok) throw new RuntimeException($message); };
$confirmation = array('id' => 14, 'teacher_id' => 10, 'starts_at_utc' => '2026-11-07 03:30:00', 'ends_at_utc' => '2026-11-07 04:00:00', 'schedule_timezone' => 'Asia/Tehran', 'local_wall_date' => '2026-11-07', 'local_wall_time' => '07:00:00');
$override = array('confirmation_id' => 14, 'teacher_id' => 10, 'actor_type' => 'teacher', 'authority_basis' => IntroRecurringSlotOverridePolicy::BASIS, 'starts_at_utc' => '2026-11-07 03:30:00', 'ends_at_utc' => '2026-11-07 04:00:00', 'schedule_timezone' => 'Asia/Tehran', 'local_wall_date' => '2026-11-07', 'local_wall_time' => '07:00:00', 'evidence_reference_digest' => str_repeat('a', 64));
$assert(IntroRecurringSlotOverridePolicy::requiresRecord('teacher', false), 'Teacher outside-hours reschedule must record explicit authority.');
$assert(!IntroRecurringSlotOverridePolicy::requiresRecord('teacher', true) && !IntroRecurringSlotOverridePolicy::requiresRecord('administrator', false), 'Only an authenticated Teacher outside published availability may create the override.');
$assert(IntroRecurringSlotOverridePolicy::matches($override, $confirmation), 'Exact Teacher override must authorise its matching confirmation slot.');
$override['local_wall_time'] = '08:00:00';
$assert(!IntroRecurringSlotOverridePolicy::matches($override, $confirmation), 'An override must never authorise a different recurring wall-clock slot.');
echo "Intro recurring-slot override policy unit passed\n";
