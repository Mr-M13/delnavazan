<?php
/** Verifies a normal, repeat-safe Schema 11 -> 12 upgrade in a disposable runtime. */
if (
    getenv('DZN_PHASE_2A2F_RUNTIME_TEST') !== 'migration'
    || !defined('WP_CLI')
    || !WP_CLI
    || !in_array(wp_get_environment_type(), array('local', 'development'), true)
) {
    fwrite(STDERR, "Phase 2A.2-F migration runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService;
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$fixture = get_option('dzn_phase_2a2f_schema11_fixture');
if (!is_array($fixture) || (string) get_option('dzn_platform_schema_version') !== '12') {
    throw new RuntimeException('Normal Schema 11 -> 12 upgrade did not complete');
}

function dzn_2a2f_migration_assert(bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function dzn_2a2f_index(string $table, string $name, bool $unique, array $columns): bool {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name=%s", $name));
    if (!$rows) {
        return false;
    }
    usort($rows, static fn($a, $b): int => (int) $a->Seq_in_index <=> (int) $b->Seq_in_index);
    return (!$unique || (int) $rows[0]->Non_unique === 0)
        && array_map(static fn($row): string => (string) $row->Column_name, $rows) === $columns;
}

$done = (array) get_option('dzn_platform_completed_migrations', array());
dzn_2a2f_migration_assert(
    count(array_keys($done, '012_student_identity_acceptance_authority', true)) === 1,
    'Migration 012 completion is missing or duplicated'
);
foreach (array(
    'booking_request_identity_resolution_events',
    'student_acceptance_capacity_classifications',
    'student_acceptance_authority_grants',
) as $table) {
    dzn_2a2f_migration_assert(
        $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . $table)) === $p . $table,
        'Missing migrated table ' . $table
    );
}

$active = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$p}student_principal_links WHERE id=%d",
    (int) $fixture['active_link_id']
));
$revoked = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$p}student_principal_links WHERE id=%d",
    (int) $fixture['revoked_link_id']
));
dzn_2a2f_migration_assert(
    $active
        && $active->status === 'active'
        && (int) $active->active_slot === 1
        && (int) $active->link_sequence === 1
        && (int) $active->version === 1
        && $active->verification_basis === 'legacy_reviewed_record'
        && $active->evidence_channel === 'legacy_migration'
        && $active->evidence_at === $active->linked_at
        && $active->updated_at !== null
        && $active->updated_by !== null,
    'Legacy active principal backfill is incomplete'
);
dzn_2a2f_migration_assert(
    $revoked
        && $revoked->status === 'revoked'
        && $revoked->active_slot === null
        && (int) $revoked->link_sequence === 1
        && (int) $revoked->version === 1
        && $revoked->verification_basis === 'legacy_reviewed_record',
    'Legacy inactive principal backfill is incomplete'
);

$link = $p . 'student_principal_links';
foreach (array('student_id', 'wordpress_user_id') as $old) {
    dzn_2a2f_migration_assert(!dzn_2a2f_index($link, $old, true, array($old)), 'Legacy uniqueness remains: ' . $old);
}
foreach (array(
    array('student_sequence', true, array('student_id', 'link_sequence')),
    array('student_active', true, array('student_id', 'active_slot')),
    array('principal_active', true, array('wordpress_user_id', 'active_slot')),
    array('principal_history', false, array('wordpress_user_id')),
    array('principal_supersession', false, array('superseded_by_link_id')),
) as [$name, $unique, $columns]) {
    dzn_2a2f_migration_assert(dzn_2a2f_index($link, $name, $unique, $columns), 'Incorrect principal index: ' . $name);
}
$foreign = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s
       AND CONSTRAINT_NAME='fk_student_principal_superseded_by'
       AND COLUMN_NAME='superseded_by_link_id'",
    $link
));
dzn_2a2f_migration_assert($foreign === 'id', 'Principal supersession foreign key is missing');

$acceptanceCount = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$p}proposal_acceptance_events WHERE uid=%s",
    $fixture['acceptance_uid']
));
dzn_2a2f_migration_assert($acceptanceCount === (int) $fixture['acceptance_count'], 'Phase 2A.2-E state changed');
dzn_2a2f_migration_assert(
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$link}") === (int) $fixture['principal_count'],
    'Principal history row count changed during migration'
);

$role = get_role('administrator');
dzn_2a2f_migration_assert(
    $role && $role->has_cap('dzn_manage_student_acceptance_authority'),
    'Phase 2A.2-F capability installation failed'
);
$role->remove_cap('dzn_manage_student_acceptance_authority');
Migrator::maybe_upgrade();
dzn_2a2f_migration_assert(
    $role->has_cap('dzn_manage_student_acceptance_authority'),
    'Capability repair failed with current schema marker'
);

$suffix = substr(hash('sha256', wp_generate_uuid4()), 0, 12);
$replacementUser = wp_insert_user(array(
    'user_login' => 'dzn-2a2f-post-migration-' . $suffix,
    'user_pass' => wp_generate_password(32, true, true),
    'user_email' => 'post-migration-' . $suffix . '@phase-2a2f.invalid',
));
dzn_2a2f_migration_assert(!is_wp_error($replacementUser), 'Post-migration principal fixture failed');
$service = new StudentAcceptanceAuthorityService();
$newLink = $service->establishPrincipal(
    (int) $fixture['revoked_student_id'],
    (int) $replacementUser,
    'synthetic_fixture',
    'synthetic_fixture',
    gmdate('Y-m-d H:i:s'),
    get_current_user_id()
);
dzn_2a2f_migration_assert($newLink > 0, 'Historical revoked link blocked a valid active replacement');
dzn_2a2f_migration_assert(
    (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$link} WHERE student_id=%d AND status='active' AND active_slot=1",
        (int) $fixture['revoked_student_id']
    )) === 1,
    'Post-migration active principal slot is incorrect'
);

$beforeRepeat = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$link}");
Migrator::maybe_upgrade();
$done = (array) get_option('dzn_platform_completed_migrations', array());
dzn_2a2f_migration_assert(
    count(array_keys($done, '012_student_identity_acceptance_authority', true)) === 1
        && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$link}") === $beforeRepeat,
    'Repeated upgrader execution changed history'
);
echo "Phase 2A.2-F Schema 11 -> 12 migration runtime passed\n";
