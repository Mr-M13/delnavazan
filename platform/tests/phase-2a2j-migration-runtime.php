<?php
/** Disposable Schema 15 -> 16 migration, uniqueness and capability repair proof. */
if (getenv('DZN_PHASE_2A2J_RUNTIME_TEST') !== 'migration' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Phase 2A.2-J migration runtime refused.\n"); exit(1);
}
if ((string) DZN_PLATFORM_SCHEMA_VERSION !== '16' || DZN_PLATFORM_BUILD_ID !== 'phase2a2j-teacher-assignment-foundation-20260915.1') throw new RuntimeException('Exact Phase J candidate required');
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_2a2j_m_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_2a2j_m_index(string $table, string $name, bool $unique, array $columns): bool {
    global $wpdb; $rows = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name=%s", $name));
    if (!$rows || ($unique && (int) $rows[0]->Non_unique !== 0)) return false;
    usort($rows, static fn($a, $b) => (int) $a->Seq_in_index <=> (int) $b->Seq_in_index);
    return array_map(static fn($row) => (string) $row->Column_name, $rows) === $columns;
}

// Reconstruct an exact pre-J database shape from the fresh disposable install.
foreach (array('teacher_assignment_commands', 'teacher_assignment_lifecycle_events', 'teacher_assignments') as $table) $wpdb->query("DROP TABLE IF EXISTS {$p}{$table}");
$done = array_values(array_filter((array) get_option('dzn_platform_completed_migrations', array()), static fn($id) => $id !== '016_teacher_assignment_foundation'));
update_option('dzn_platform_completed_migrations', $done, false);
update_option('dzn_platform_schema_version', '15', false);
update_option('dzn_platform_capability_version', '2a2i', false);
$admin = get_role('administrator'); $teacher = get_role('dzn_teacher');
if ($admin) $admin->remove_cap('dzn_manage_teacher_assignments');
if ($teacher) $teacher->remove_cap('dzn_accept_own_teacher_assignments');

Migrator::maybe_upgrade();
dzn_2a2j_m_assert((string) get_option('dzn_platform_schema_version') === '16', 'Schema 15 -> 16 failed');
$done = (array) get_option('dzn_platform_completed_migrations', array());
dzn_2a2j_m_assert(count(array_keys($done, '016_teacher_assignment_foundation', true)) === 1, 'Migration 016 ledger invalid');
foreach (array('teacher_assignments', 'teacher_assignment_lifecycle_events', 'teacher_assignment_commands') as $table) {
    $physical = $p . $table;
    dzn_2a2j_m_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $physical)) === $physical, 'Missing ' . $table);
    dzn_2a2j_m_assert(strcasecmp((string) $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $physical)), 'InnoDB') === 0, 'Non-InnoDB ' . $table);
}
foreach (array(
    array('teacher_assignments', 'enrolment_sequence', true, array('enrolment_id', 'assignment_sequence')),
    array('teacher_assignments', 'enrolment_applicable', true, array('enrolment_id', 'applicable_slot')),
    array('teacher_assignments', 'predecessor_assignment_id', true, array('predecessor_assignment_id')),
    array('teacher_assignments', 'teacher_applicable', false, array('teacher_id', 'applicable_slot')),
    array('teacher_assignment_lifecycle_events', 'assignment_sequence', true, array('assignment_id', 'event_sequence')),
    array('teacher_assignment_commands', 'command_key_digest', true, array('command_key_digest')),
) as [$table, $index, $unique, $columns]) dzn_2a2j_m_assert(dzn_2a2j_m_index($p . $table, $index, $unique, $columns), 'Incorrect index ' . $table . '.' . $index);
foreach (array('teacher_assignment_lifecycle_events', 'teacher_assignment_commands') as $table) dzn_2a2j_m_assert(!$wpdb->get_row("SHOW COLUMNS FROM {$p}{$table} LIKE 'updated_at'"), 'Mutable evidence table ' . $table);
dzn_2a2j_m_assert($admin && $admin->has_cap('dzn_manage_teacher_assignments'), 'Assignment management capability installation failed');
dzn_2a2j_m_assert($teacher && $teacher->has_cap('dzn_accept_own_teacher_assignments'), 'Teacher acceptance capability installation failed');
$admin->remove_cap('dzn_manage_teacher_assignments'); $teacher->remove_cap('dzn_accept_own_teacher_assignments');
Migrator::maybe_upgrade();
dzn_2a2j_m_assert($admin->has_cap('dzn_manage_teacher_assignments') && $teacher->has_cap('dzn_accept_own_teacher_assignments'), 'Capability repair failed');
$before = array_map(static fn($table) => (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}dzn_{$table}"), array('teacher_assignments', 'teacher_assignment_lifecycle_events', 'teacher_assignment_commands'));
Migrator::maybe_upgrade();
$after = array_map(static fn($table) => (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}dzn_{$table}"), array('teacher_assignments', 'teacher_assignment_lifecycle_events', 'teacher_assignment_commands'));
dzn_2a2j_m_assert($before === $after, 'Repeated Migration 016 changed state');
echo "schema_15_to_16=pass\nmigration_016=pass\ncapability_install_repair=pass\nrepeat_upgrade=pass\nPhase 2A.2-J migration runtime passed\n";
