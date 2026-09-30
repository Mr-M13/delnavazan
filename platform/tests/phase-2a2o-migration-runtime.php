<?php
/** Disposable Schema 21 -> 22 migration, repeat safety, retained-022 fail-closed and capability proof. */
if(getenv('DZN_PHASE_2A2O_RUNTIME_TEST')!=='migration'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-O migration runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityService;
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
use Delnavazan\Platform\Core\Support\Identifier;

global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_om_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$tables = array('canonical_lesson_delivery_outcomes', 'canonical_lesson_delivery_commands', 'canonical_academy_obligations');
$tableExists = static function (string $table) use ($wpdb, $p): bool {
    return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . $table)) === $p . $table;
};
$engine = static function (string $table) use ($wpdb, $p): string {
    return strtolower((string) $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $p . $table)));
};
$count = static function (string $table) use ($wpdb, $p): int { return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}"); };

// 1. Current Schema 22 state: identity, storage, indexes, capability and remedy classification.
dzn_om_assert((int) DZN_PLATFORM_SCHEMA_VERSION >= 22, 'Expected Phase O or later schema identity');
dzn_om_assert((string) get_option('dzn_platform_schema_version') === (string) DZN_PLATFORM_SCHEMA_VERSION, 'Schema option must match the current schema identity');
$completed = (array) get_option('dzn_platform_completed_migrations', array());
dzn_om_assert(in_array('022_canonical_lesson_delivery_attendance_authority', $completed, true), 'Migration 022 must be recorded');
dzn_om_assert(in_array('021_canonical_lesson_schedule_authority', $completed, true), 'Migration 021 must remain recorded');
foreach ($tables as $table) {
    dzn_om_assert($tableExists($table), 'Missing canonical delivery table ' . $table);
    dzn_om_assert($engine($table) === 'innodb', 'Canonical delivery table must use InnoDB: ' . $table);
}
dzn_om_assert((bool) $wpdb->get_row("SHOW INDEX FROM {$p}canonical_lesson_delivery_outcomes WHERE Key_name='lesson_applicable_outcome'"), 'Missing applicable-outcome arbitration index');
dzn_om_assert((bool) $wpdb->get_row("SHOW INDEX FROM {$p}canonical_lesson_delivery_commands WHERE Key_name='command_key_digest'"), 'Missing delivery command digest index');
dzn_om_assert(!$wpdb->get_row("SHOW COLUMNS FROM {$p}lessons LIKE 'canonical_remedy_class'"), 'Academy debt must not be modelled as a Phase-M replacement classification');
foreach (array('schedule_version_id' => 'NO', 'occurrence_ends_at_utc' => 'NO', 'reconciles_completion_event_id' => 'YES') as $column => $nullability) {
    $row = $wpdb->get_row("SHOW COLUMNS FROM {$p}canonical_lesson_delivery_outcomes LIKE '{$column}'");
    dzn_om_assert($row && $row->Null === $nullability, 'Phase-O delivery column missing or wrongly nullable: ' . $column);
}
dzn_om_assert((bool) $wpdb->get_row("SHOW INDEX FROM {$p}canonical_academy_obligations WHERE Key_name='source_lesson'"), 'Academy obligation must be bounded to one per source occurrence');
$role = get_role('administrator');
dzn_om_assert($role && $role->has_cap('dzn_manage_canonical_lesson_delivery'), 'Administrator must hold the canonical delivery capability');
dzn_om_assert(!$role->has_cap('dzn_manage_canonical_lesson_delivery') || !get_role('dzn_teacher') || !get_role('dzn_teacher')->has_cap('dzn_manage_canonical_lesson_delivery'), 'Delivery authority must not leak to the Teacher role');

// 2. Legacy preservation and no dual-read: legacy Lessons gain no canonical delivery storage.
$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_om_assert(is_array($fixture) && !empty($fixture['sources'][0]), 'Phase-J fixture required for the legacy coexistence proof');
$source = $fixture['sources'][0];
$now = gmdate('Y-m-d H:i:s');
$deliveryBefore = $count('canonical_lesson_delivery_outcomes');
dzn_om_assert($wpdb->insert($p . 'lessons', array('uid' => Identifier::uid(), 'student_id' => (int) $source['student_id'], 'teacher_id' => (int) $source['teacher_id'], 'course_id' => (int) $source['course_id'], 'lesson_type' => 'standard', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now, 'created_by' => get_current_user_id(), 'updated_by' => get_current_user_id())) === 1, 'Legacy Lesson fixture failed');
$legacyLesson = (int) $wpdb->insert_id;
dzn_om_assert((string) $wpdb->get_var($wpdb->prepare("SELECT record_model FROM {$p}lessons WHERE id=%d", $legacyLesson)) === 'legacy_phase1', 'Legacy Lesson classification changed');
dzn_om_assert($count('canonical_lesson_delivery_outcomes') === $deliveryBefore, 'Canonical delivery storage must not be backfilled from legacy rows');
$obligationsBefore = $count('canonical_academy_obligations');

// 4. Exact Schema 21 -> 22 rehearsal and repeat safety.
foreach ($tables as $table) dzn_om_assert($wpdb->query("DROP TABLE IF EXISTS {$p}{$table}") !== false, 'Failed to simulate pre-022 state');
update_option('dzn_platform_completed_migrations', array_values(array_filter($completed, static fn($id) => $id !== '022_canonical_lesson_delivery_attendance_authority')), false);
update_option('dzn_platform_schema_version', '21', false);
Migrator::maybe_upgrade();
dzn_om_assert((string) get_option('dzn_platform_schema_version') === (string) DZN_PLATFORM_SCHEMA_VERSION, 'Rehearsed 21 -> 22 upgrade did not reach the current schema');
$replayed = (array) get_option('dzn_platform_completed_migrations', array());
dzn_om_assert(in_array('022_canonical_lesson_delivery_attendance_authority', $replayed, true), 'Rehearsed upgrade did not record migration 022');
foreach ($tables as $table) dzn_om_assert($tableExists($table) && $engine($table) === 'innodb', 'Rehearsed upgrade did not rebuild ' . $table);
dzn_om_assert($count('canonical_lesson_delivery_outcomes') === 0 && $count('canonical_academy_obligations') === 0, 'Rehearsed upgrade created unprompted delivery or academy obligation rows');
$outcomesAfterUpgrade = $count('canonical_lesson_delivery_outcomes');
$obligationsAfterUpgrade = 0;
Migrator::maybe_upgrade();
dzn_om_assert((string) get_option('dzn_platform_schema_version') === (string) DZN_PLATFORM_SCHEMA_VERSION && $count('canonical_lesson_delivery_outcomes') === $outcomesAfterUpgrade, 'Repeat migration changed durable delivery state');
dzn_om_assert($count('canonical_academy_obligations') === $obligationsAfterUpgrade, 'Repeat migration changed durable academy obligation state');
unset($obligationsBefore);

// 5. Capability repair after a stale marker.
$role = get_role('administrator');
$role->remove_cap('dzn_manage_canonical_lesson_delivery');
update_option('dzn_platform_capability_version_2a2o', 'stale', false);
Migrator::maybe_upgrade();
dzn_om_assert(get_role('administrator')->has_cap('dzn_manage_canonical_lesson_delivery'), 'Capability repair did not restore the canonical delivery capability');
dzn_om_assert((string) get_option('dzn_platform_capability_version_2a2o') === '2a2o', 'Phase O capability marker was not advanced');

// 6. Malformed Schema-22 storage must fail closed on every verifier path.
$failClosed = static function (string $label, callable $damage, callable $repair) use ($wpdb): void {
    dzn_om_assert($damage() !== false, 'Failed to apply malformed canonical delivery storage: ' . $label);
    $rejected = false;
    try { Migrator::maybe_upgrade(); } catch (RuntimeException $exception) { $rejected = str_contains($exception->getMessage(), 'Migration verification failed'); }
    dzn_om_assert($repair() !== false, 'Failed to repair malformed canonical delivery storage: ' . $label);
    dzn_om_assert($rejected, 'Malformed canonical delivery storage was accepted: ' . $label);
    Migrator::maybe_upgrade();
    dzn_om_assert((string) get_option('dzn_platform_schema_version') === (string) DZN_PLATFORM_SCHEMA_VERSION, 'Repaired storage did not return to the current schema: ' . $label);
};
$failClosed('dropped applicable-outcome index',
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_delivery_outcomes DROP INDEX lesson_applicable_outcome"),
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_delivery_outcomes ADD UNIQUE KEY lesson_applicable_outcome(lesson_id,applicable_slot)"));
$failClosed('mutable delivery evidence stamped on append-only history',
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_delivery_outcomes ADD COLUMN updated_at datetime NULL"),
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_delivery_outcomes DROP COLUMN updated_at"));
$failClosed('nullable delivery command digest',
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_delivery_commands MODIFY command_key_digest varchar(64) NULL"),
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_delivery_commands MODIFY command_key_digest char(64) NOT NULL"));
$failClosed('canonical delivery table moved to a non-transactional engine',
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_delivery_outcomes ENGINE=MyISAM"),
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_delivery_outcomes ENGINE=InnoDB"));
$failClosed('nullable occurrence end anchor on delivery outcomes',
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_delivery_outcomes MODIFY occurrence_ends_at_utc datetime NULL"),
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_lesson_delivery_outcomes MODIFY occurrence_ends_at_utc datetime NOT NULL"));
$failClosed('mutable academy obligation evidence',
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_academy_obligations ADD COLUMN updated_at datetime NULL"),
    fn() => $wpdb->query("ALTER TABLE {$p}canonical_academy_obligations DROP COLUMN updated_at"));

// 7. Retained-022 / stale-schema-version activation path: migration 022 is already recorded while
//    the schema option is still 21. Damaged storage must reject fail-closed and leave the option at 21.
dzn_om_assert(in_array('022_canonical_lesson_delivery_attendance_authority', (array) get_option('dzn_platform_completed_migrations', array()), true), 'Retained-022 regression requires migration 022 to stay recorded');
dzn_om_assert($wpdb->query("ALTER TABLE {$p}canonical_lesson_delivery_outcomes DROP INDEX lesson_applicable_outcome") !== false, 'Failed to damage Phase-O storage for the retained-022 regression');
update_option('dzn_platform_schema_version', '21', false);
$retainedRejected = false;
try { Migrator::maybe_upgrade(); } catch (RuntimeException $exception) { $retainedRejected = str_contains($exception->getMessage(), 'Migration verification failed'); }
dzn_om_assert($retainedRejected, 'Retained-022/stale-schema-version activation accepted damaged Phase-O storage');
dzn_om_assert((string) get_option('dzn_platform_schema_version') === '21', 'Rejected retained-022 activation must not advance the schema option');
dzn_om_assert(in_array('022_canonical_lesson_delivery_attendance_authority', (array) get_option('dzn_platform_completed_migrations', array()), true), 'Rejected retained-022 activation must not drop the completed marker');
dzn_om_assert($wpdb->query("ALTER TABLE {$p}canonical_lesson_delivery_outcomes ADD UNIQUE KEY lesson_applicable_outcome(lesson_id,applicable_slot)") !== false, 'Failed to repair Phase-O storage after the retained-022 regression');
Migrator::maybe_upgrade();
dzn_om_assert((string) get_option('dzn_platform_schema_version') === (string) DZN_PLATFORM_SCHEMA_VERSION, 'Repaired retained-022 storage did not recover to the current schema');
dzn_om_assert(count(array_keys((array) get_option('dzn_platform_completed_migrations', array()), '022_canonical_lesson_delivery_attendance_authority', true)) === 1, 'Recovery must keep migration 022 recorded exactly once');

echo "fresh_schema_22=pass\nschema_21_to_22_upgrade=pass\nrepeat_migration=pass\ncapability_repair=pass\nlegacy_preservation=pass\nno_delivery_backfill=pass\nno_invented_obligations=pass\nmalformed_storage_fail_closed=pass cases=6\nretained_022_preactivation_fail_closed=pass\nPhase 2A.2-O migration runtime passed\n";
