<?php
/** Disposable Schema 20 -> 21 migration, repeat safety, capability repair and legacy-preservation proof. */
if(getenv('DZN_PHASE_2A2N_RUNTIME_TEST')!=='migration'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-N migration runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\LessonScheduleService;
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
use Delnavazan\Platform\Core\Support\Identifier;

global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_nm_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$tables = array('teacher_schedule_roots', 'canonical_lesson_schedule_versions', 'canonical_lesson_schedule_events', 'canonical_lesson_schedule_commands');
$tableExists = static function (string $table) use ($wpdb, $p): bool {
    return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . $table)) === $p . $table;
};
$engine = static function (string $table) use ($wpdb, $p): string {
    return strtolower((string) $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $p . $table)));
};
$count = static function (string $table) use ($wpdb, $p): int { return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}"); };

// 1. Fresh Schema-21 state: identity, tables, indexes, capability and root backfill.
dzn_nm_assert((int) DZN_PLATFORM_SCHEMA_VERSION >= 21, 'Expected Phase N or later schema identity');
dzn_nm_assert((string) get_option('dzn_platform_schema_version') === (string) DZN_PLATFORM_SCHEMA_VERSION, 'Schema option must match the current schema identity');
$completed = (array) get_option('dzn_platform_completed_migrations', array());
dzn_nm_assert(in_array('021_canonical_lesson_schedule_authority', $completed, true), 'Migration 021 must be recorded');
dzn_nm_assert(in_array('020_canonical_lesson_authority', $completed, true), 'Migration 020 must remain recorded');
foreach ($tables as $table) {
    dzn_nm_assert($tableExists($table), 'Missing canonical scheduling table ' . $table);
    dzn_nm_assert($engine($table) === 'innodb', 'Canonical scheduling table must use InnoDB: ' . $table);
}
dzn_nm_assert((bool) $wpdb->get_row("SHOW INDEX FROM {$p}canonical_lesson_schedule_versions WHERE Key_name='lesson_applicable'"), 'Missing applicable-slot arbitration index');
dzn_nm_assert((bool) $wpdb->get_row("SHOW INDEX FROM {$p}canonical_lesson_schedule_versions WHERE Key_name='teacher_occupancy'"), 'Missing teacher occupancy index');
$role = get_role('administrator');
dzn_nm_assert($role && $role->has_cap('dzn_manage_canonical_lesson_schedules'), 'Administrator must hold the canonical scheduling capability');
dzn_nm_assert($role->has_cap('dzn_override_canonical_lesson_schedule_availability'), 'Administrator must hold the availability override capability');
$teachers = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}teachers");
dzn_nm_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}teacher_schedule_roots GROUP BY teacher_id HAVING COUNT(*) > 1 LIMIT 1") === 0, 'Teacher scheduling roots must be unique');

// 2. Legacy preservation: the Phase-1 scheduling path keeps working and canonical storage stays empty.
$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_nm_assert(is_array($fixture) && !empty($fixture['sources'][0]), 'Phase-J fixture required for the legacy coexistence proof');
$source = $fixture['sources'][0];
$now = gmdate('Y-m-d H:i:s');
$canonicalBefore = $count('canonical_lesson_schedule_versions');
dzn_nm_assert($wpdb->insert($p . 'lessons', array('uid' => Identifier::uid(), 'student_id' => (int) $source['student_id'], 'teacher_id' => (int) $source['teacher_id'], 'course_id' => (int) $source['course_id'], 'lesson_type' => 'standard', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now, 'created_by' => get_current_user_id(), 'updated_by' => get_current_user_id())) === 1, 'Legacy Lesson fixture failed');
$legacyLesson = (int) $wpdb->insert_id;
$legacyVersion = (new LessonScheduleService())->initial($legacyLesson, array('schedule_timezone' => 'UTC', 'local_wall_date' => gmdate('Y-m-d', strtotime('+20 days')), 'local_wall_time' => '10:00:00', 'reason' => 'legacy coexistence'));
dzn_nm_assert($legacyVersion > 0, 'Legacy scheduling no longer works for legacy Lessons');
dzn_nm_assert((string) $wpdb->get_var($wpdb->prepare("SELECT record_model FROM {$p}lessons WHERE id=%d", $legacyLesson)) === 'legacy_phase1', 'Legacy Lesson classification changed');
dzn_nm_assert($count('canonical_lesson_schedule_versions') === $canonicalBefore, 'Canonical scheduling storage must not be backfilled from legacy rows');
$legacyHistoryBefore = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lesson_schedule_versions WHERE lesson_id=%d", $legacyLesson));

// 3. Exact Schema 20 -> 21 upgrade rehearsal and repeat safety.
foreach ($tables as $table) dzn_nm_assert($wpdb->query("DROP TABLE IF EXISTS {$p}{$table}") !== false, 'Failed to simulate pre-021 state');
update_option('dzn_platform_completed_migrations', array_values(array_filter($completed, static fn($id) => $id !== '021_canonical_lesson_schedule_authority')), false);
update_option('dzn_platform_schema_version', '20', false);
Migrator::maybe_upgrade();
dzn_nm_assert((string) get_option('dzn_platform_schema_version') === (string) DZN_PLATFORM_SCHEMA_VERSION, 'Rehearsed pre-021 upgrade did not reach the current schema');
$replayed = (array) get_option('dzn_platform_completed_migrations', array());
dzn_nm_assert(in_array('021_canonical_lesson_schedule_authority', $replayed, true), 'Rehearsed upgrade did not record migration 021');
foreach ($tables as $table) dzn_nm_assert($tableExists($table) && $engine($table) === 'innodb', 'Rehearsed upgrade did not rebuild ' . $table);
dzn_nm_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lesson_schedule_versions WHERE lesson_id=%d", $legacyLesson)) === $legacyHistoryBefore, 'Upgrade mutated legacy schedule history');
dzn_nm_assert($count('canonical_lesson_schedule_versions') === 0, 'Upgrade must not create canonical scheduling rows');
$rootsAfterUpgrade = $count('teacher_schedule_roots');
$teachersNow = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}teachers");
dzn_nm_assert($teachersNow > 0 && $rootsAfterUpgrade >= $teachersNow, 'Upgrade must create a serialization root for every existing Teacher');
Migrator::maybe_upgrade();
dzn_nm_assert((string) get_option('dzn_platform_schema_version') === (string) DZN_PLATFORM_SCHEMA_VERSION && $count('teacher_schedule_roots') === $rootsAfterUpgrade, 'Repeat migration changed durable scheduling state');
$teacherRow = $wpdb->get_row("SELECT * FROM {$p}teachers ORDER BY id LIMIT 1");
if ($teacherRow) dzn_nm_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_schedule_roots WHERE teacher_id=%d", (int) $teacherRow->id)) === 1, 'Repeat migration duplicated a Teacher scheduling root');

// 4. Capability repair after a stale marker.
$role = get_role('administrator');
$role->remove_cap('dzn_manage_canonical_lesson_schedules');
$role->remove_cap('dzn_override_canonical_lesson_schedule_availability');
update_option('dzn_platform_capability_version', 'stale', false);
Migrator::maybe_upgrade();
$role = get_role('administrator');
dzn_nm_assert($role->has_cap('dzn_manage_canonical_lesson_schedules') && $role->has_cap('dzn_override_canonical_lesson_schedule_availability'), 'Capability repair did not restore canonical scheduling capabilities');
dzn_nm_assert((string) get_option('dzn_platform_capability_version') === '2a2n', 'Capability marker was not advanced');

// 5. Malformed Phase-N storage must fail closed: Schema 21 is never activated or retained merely
// because the schema option says 21. Each damaged state must reject before it can be repaired.
$failClosed = static function (string $label, callable $damage, callable $repair) use ($wpdb): void {
    dzn_nm_assert($damage() !== false, 'Failed to apply malformed canonical scheduling storage: ' . $label);
    $rejected = false;
    try { Migrator::maybe_upgrade(); } catch (RuntimeException $exception) { $rejected = str_contains($exception->getMessage(), 'Migration verification failed'); }
    dzn_nm_assert($repair() !== false, 'Failed to repair malformed canonical scheduling storage: ' . $label);
    dzn_nm_assert($rejected, 'Malformed canonical scheduling storage was accepted: ' . $label);
    Migrator::maybe_upgrade();
    dzn_nm_assert((string) get_option('dzn_platform_schema_version') === (string) DZN_PLATFORM_SCHEMA_VERSION, 'Repaired storage did not return to the current schema: ' . $label);
};
$failClosed('dropped applicable-slot index',
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_schedule_versions DROP INDEX lesson_applicable"),
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_schedule_versions ADD UNIQUE KEY lesson_applicable(lesson_id,applicable_slot)"));
$failClosed('dropped teacher occupancy index',
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_schedule_versions DROP INDEX teacher_occupancy"),
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_schedule_versions ADD KEY teacher_occupancy(teacher_id,starts_at_utc,occupied_ends_at_utc)"));
$failClosed('dropped Teacher scheduling root index',
    fn() => $wpdb->query("ALTER TABLE {$p}teacher_schedule_roots DROP INDEX teacher"),
    fn() => $wpdb->query("ALTER TABLE {$p}teacher_schedule_roots ADD UNIQUE KEY teacher(teacher_id)"));
$failClosed('mutable schedule evidence stamped on append-only history',
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_schedule_events ADD COLUMN updated_at datetime NULL"),
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_schedule_events DROP COLUMN updated_at"));
$failClosed('nullable non-digest evidence reference',
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_schedule_commands MODIFY command_key_digest varchar(64) NULL"),
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_schedule_commands MODIFY command_key_digest char(64) NOT NULL"));
$failClosed('canonical scheduling table moved to a non-transactional engine',
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_schedule_events ENGINE=MyISAM"),
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_schedule_events ENGINE=InnoDB"));
dzn_nm_assert($count('canonical_lesson_schedule_versions') === 0 && $count('canonical_lesson_schedule_commands') === 0, 'Malformed-storage regression must not leave canonical scheduling rows behind');

// 6. Retained-021 / stale-schema-version activation path: migration 021 is already recorded in the
// completed ledger while the schema option is still 20 (021 recorded, then execution stopped before
// the option advanced). maybe_upgrade() skips 021, so Phase-N storage must still be verified before
// the option is advanced to 21. Damaged storage must reject fail-closed and leave the option at 20.
dzn_nm_assert(in_array('021_canonical_lesson_schedule_authority', (array) get_option('dzn_platform_completed_migrations', array()), true), 'Retained-021 regression requires migration 021 to stay recorded');
dzn_nm_assert($wpdb->query("ALTER TABLE {$p}canonical_lesson_schedule_versions DROP INDEX lesson_applicable") !== false, 'Failed to damage Phase-N storage for the retained-021 regression');
update_option('dzn_platform_schema_version', '20', false);
$retainedRejected = false;
try { Migrator::maybe_upgrade(); } catch (RuntimeException $exception) { $retainedRejected = str_contains($exception->getMessage(), 'Migration verification failed'); }
dzn_nm_assert($retainedRejected, 'Retained-021/stale-schema-version activation accepted damaged Phase-N storage');
dzn_nm_assert((string) get_option('dzn_platform_schema_version') === '20', 'Rejected retained-021 activation must not advance the schema option to 21');
dzn_nm_assert(in_array('021_canonical_lesson_schedule_authority', (array) get_option('dzn_platform_completed_migrations', array()), true), 'Rejected retained-021 activation must not drop the completed marker');
dzn_nm_assert($wpdb->query("ALTER TABLE {$p}canonical_lesson_schedule_versions ADD UNIQUE KEY lesson_applicable(lesson_id,applicable_slot)") !== false, 'Failed to repair Phase-N storage after the retained-021 regression');
Migrator::maybe_upgrade();
dzn_nm_assert((string) get_option('dzn_platform_schema_version') === (string) DZN_PLATFORM_SCHEMA_VERSION, 'Repaired retained-021 storage did not recover to the current schema');
dzn_nm_assert(count(array_keys((array) get_option('dzn_platform_completed_migrations', array()), '021_canonical_lesson_schedule_authority', true)) === 1, 'Recovery must keep migration 021 recorded exactly once');

echo "fresh_schema_21=pass\nlegacy_preservation=pass\nno_canonical_backfill=pass\nschema_20_to_21_upgrade=pass\nrepeat_migration=pass\ncapability_repair=pass\nteacher_root_backfill=pass\nmalformed_storage_fail_closed=pass cases=6\nretained_021_preactivation_fail_closed=pass\nPhase 2A.2-N migration runtime passed\n";
