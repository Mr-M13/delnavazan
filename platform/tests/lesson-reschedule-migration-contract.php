<?php
/** Schema-version uniqueness, clean-install table creation and upgrade registration. */
$root = dirname(__DIR__);
$plugin = (string) file_get_contents($root . '/delnavazan-platform.php');
$migration = (string) file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$assert(preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/", $plugin, $match) === 1, 'Schema constant is missing');
$schema = (int) $match[1];
$assert(substr_count($plugin, "define( 'DZN_PLATFORM_SCHEMA_VERSION'") === 1, 'Schema constant must be declared once');
$assert($schema === 55, 'This branch must retain Schema 54 and publish the additive Schema 55 decision authority');
$assert(str_contains($migration, "'054_lesson_reschedule_authority'"), 'Schema 54 migration id is missing');
$assert(!str_contains($migration, "'053_lesson_reschedule_authority'"), 'This branch must not claim PR #380 schema 53');
$assert(substr_count($migration, '054_lesson_reschedule_authority') >= 3, 'Migration must be registered, installed and verified');
$register = strpos($migration, "if(!in_array('054_lesson_reschedule_authority',\$done,true))");
$install = strpos($migration, 'self::install_lesson_reschedule_authority()');
$verify = strpos($migration, 'self::verify_lesson_reschedule_authority_schema()');
$advance = strpos($migration, 'update_option(self::OPTION,DZN_PLATFORM_SCHEMA_VERSION,false)');
$assert($register !== false && $install !== false && $verify !== false && $advance !== false, 'Migration registration/install/verify/advance is incomplete');
$assert($register < $install && $install < $verify && $verify < $advance, 'Migration must install and verify before advancing the schema option');

foreach (array('student_schedule_roots', 'lesson_reschedule_proposals', 'lesson_reschedule_events', 'lesson_reschedule_notifications', 'lesson_reschedule_calendar_jobs') as $table) {
    $assert(str_contains($migration, "CREATE TABLE {\$p}{$table}"), 'Clean install must create ' . $table);
}
foreach (array('student_schedule_roots', 'lesson_reschedule_proposals', 'lesson_reschedule_events', 'lesson_reschedule_notifications', 'lesson_reschedule_calendar_jobs') as $table) {
    $assert(str_contains($migration, "'{$table}' => array(") || str_contains($migration, "'{$table}'=>array("), 'Schema verification must inspect ' . $table);
}
$assert(str_contains($migration, "'student_schedule_roots','student_id',true"), 'Student scheduling root must have a durable unique key');
$assert(str_contains($migration, "'lesson_reschedule_notifications','idempotency_key',true"), 'Notification outbox idempotency must be database-unique');
$assert(str_contains($migration, "'lesson_reschedule_calendar_jobs','idempotency_key',true"), 'Calendar job idempotency must be database-unique');
$assert(str_contains($migration, "'lesson_reschedule_events','proposal_sequence',true"), 'Accepted proposal event must be database-unique per sequence');

$service = (string) file_get_contents($root . '/src/Core/Application/LessonRescheduleService.php');
$canonical = (string) file_get_contents($root . '/src/Core/Application/CanonicalLessonScheduleService.php');
$assert(str_contains($canonical, 'public function reviseWithinTransaction'), 'Canonical caller-owned transaction seam is missing');
$assert(str_contains($canonical, 'if(!$ownTransaction){') && str_contains($canonical, 'return $work($digest,$facts,$payload);'), 'Caller-owned transaction must not start/commit its own transaction');
$assert(str_contains($service, 'reviseWithinTransaction') && !str_contains($service, '->revise('), 'Acceptance must use the caller-owned canonical transaction seam');
$assert(str_contains($service, 'ensureAndLockStudentRoot') && str_contains($service, 'studentConflict'), 'Acceptance must lock the Student root before final conflict validation');
$ownerPorts = (string) file_get_contents($root . '/src/Core/Application/PortalOwnerReadPorts.php');
$assert(str_contains($ownerPorts, "'enrolment_lifecycle_state'=>") && !str_contains($ownerPorts, "'lifecycle_state'=>(string)\$enrolment->lifecycle_state"), 'Teacher lesson lifecycle must not be overwritten by enrolment lifecycle in the portal projection');
$assert(str_contains($service, 'LessonRescheduleSystemActor::run') && str_contains($service, 'reviseWithinTransaction'), 'Canonical commit must run under the least-privilege scheduling system actor');
$assert(str_contains($canonical, 'private function actor()') && str_contains($canonical, 'get_current_user_id()'), 'Canonical schedule versions must record the active system actor');
$repository = (string) file_get_contents($root . '/src/Core/Infrastructure/Repository/LessonRescheduleRepository.php');
$assert(str_contains($repository, 'l.student_id=%d') && str_contains($repository, 'v.occupied_ends_at_utc>%s'), 'Student conflicts must be checked across all Student Lessons with half-open occupied intervals');
$assert(str_contains($repository, 'DATE_ADD(v.ends_at_utc,INTERVAL 15 MINUTE)>%s') && str_contains($repository, "c.starts_at_utc<%s AND c.ends_at_utc>%s"), 'Legacy and booking occupancy must use half-open intervals too');

echo "Lesson reschedule migration contract passed\n";
