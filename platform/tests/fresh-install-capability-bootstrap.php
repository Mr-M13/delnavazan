<?php
/** Isolated executable check for fresh-install capability bootstrap convergence. */
final class FreshInstallRoleMock {
    public array $caps = array();
    public int $adds = 0;
    public int $removes = 0;
    public function add_cap(string $cap): void { $this->caps[$cap] = true; $this->adds++; }
    public function remove_cap(string $cap): void { unset($this->caps[$cap]); $this->removes++; }
    public function has_cap(string $cap): bool { return ! empty($this->caps[$cap]); }
}

$fiOptions = array();
$fiAdmin = new FreshInstallRoleMock();
$fiRoles = array( 'administrator' => $fiAdmin );
function get_option(string $key, mixed $default = false): mixed { global $fiOptions; return $fiOptions[$key] ?? $default; }
function update_option(string $key, mixed $value, mixed $autoload = null): bool { global $fiOptions; $fiOptions[$key] = $value; return true; }
function get_role(string $name): ?FreshInstallRoleMock { global $fiRoles; return $fiRoles[$name] ?? null; }
function add_role(string $name, string $display, array $caps): ?FreshInstallRoleMock {
    global $fiRoles;
    if ($name === '' || isset($fiRoles[$name])) return null;
    $role = new FreshInstallRoleMock();
    foreach ($caps as $cap => $grant) {
        if (is_int($cap)) { $role->add_cap((string)$grant); continue; }
        if ($grant) $role->add_cap((string)$cap);
    }
    $fiRoles[$name] = $role;
    return $role;
}

require dirname(__DIR__) . '/src/Core/Infrastructure/Migration/Migrator.php';
$fiMethod = new ReflectionMethod('Delnavazan\Platform\Core\Infrastructure\Migration\Migrator', 'ensure_capabilities');
$fiMethod->setAccessible(true);
$fiClass = new ReflectionClass('Delnavazan\Platform\Core\Infrastructure\Migration\Migrator');
$fiConst = static function (string $name) use ($fiClass): string { return (string) $fiClass->getConstant($name); };

$fiTeacherOwned = array(
    'read',
    'dzn_record_own_availability_assent',
    'dzn_accept_own_teacher_assignments',
    'dzn_submit_own_delivery_claim',
    'dzn_submit_own_continuation_match_exception',
);
$fiTeacherReserved = array(
    'dzn_ingest_canonical_attendance_evidence',
    'dzn_submit_own_attendance_claim',
    'dzn_manage_canonical_attendance_review',
    'dzn_view_canonical_attendance_review',
    'dzn_manage_canonical_attendance_identity',
    'dzn_manage_canonical_continuation',
    'dzn_view_canonical_continuation',
    'dzn_manage_commercial_catalogue',
    'dzn_manage_commercial_promotions',
    'dzn_manage_commercial_adjustments',
    'dzn_issue_commercial_offers',
    'dzn_ingest_commercial_payment_evidence',
    'dzn_bind_commercial_term_funding',
    'dzn_manage_commercial_capacity',
    'dzn_manage_commercial_policies',
    'dzn_manage_commercial_exceptions',
    'dzn_view_commercial_authority',
);
$fiAdminGrants = array_merge(
    array('dzn_manage_canonical_lesson_delivery'),
    array('dzn_ingest_canonical_attendance_evidence', 'dzn_submit_own_attendance_claim', 'dzn_submit_own_delivery_claim', 'dzn_manage_canonical_attendance_review', 'dzn_view_canonical_attendance_review', 'dzn_manage_canonical_attendance_identity'),
    array('dzn_manage_canonical_continuation', 'dzn_view_canonical_continuation'),
    array('dzn_manage_commercial_catalogue', 'dzn_manage_commercial_promotions', 'dzn_manage_commercial_adjustments', 'dzn_issue_commercial_offers', 'dzn_ingest_commercial_payment_evidence', 'dzn_bind_commercial_term_funding', 'dzn_manage_commercial_capacity', 'dzn_manage_commercial_policies', 'dzn_manage_commercial_exceptions', 'dzn_view_commercial_authority')
);

// 1. No dzn_teacher role: a brand-new install must converge and grant only the Teacher-owned caps.
$fiMethod->invoke(null);
$fiTeacher = get_role('dzn_teacher');
if (!$fiTeacher) throw new RuntimeException('Fresh install did not create the dzn_teacher role');
foreach ($fiTeacherOwned as $cap) if (!$fiTeacher->has_cap($cap)) throw new RuntimeException('Fresh install missing Teacher grant: ' . $cap);
foreach ($fiTeacherReserved as $cap) if ($fiTeacher->has_cap($cap)) throw new RuntimeException('Fresh install leaked reserved capability to Teacher: ' . $cap);
foreach ($fiAdminGrants as $cap) if (!$fiAdmin->has_cap($cap)) throw new RuntimeException('Fresh install missing Administrator grant: ' . $cap);
foreach (array('CAPABILITY_OPTION', 'CAPABILITY_OPTION_O', 'CAPABILITY_OPTION_P', 'CAPABILITY_OPTION_Q', 'CAPABILITY_OPTION_R1') as $option) {
    $marker = $fiConst($option);
    $version = $fiConst(str_replace('OPTION', 'VERSION', $option));
    if (get_option($marker) !== $version) throw new RuntimeException('Fresh install version marker not installed: ' . $marker);
}

// 2. Complete role: a second run must be a harmless no-op (idempotent).
$fiAdminAdds = $fiAdmin->adds;
$fiTeacherAdds = $fiTeacher->adds;
$fiMethod->invoke(null);
if ($fiAdmin->adds !== $fiAdminAdds || $fiTeacher->adds !== $fiTeacherAdds) throw new RuntimeException('Complete capability state was not idempotent');

// 3. Partially missing Teacher Phase-P/Phase-Q grants must be repaired.
$fiTeacher->remove_cap('dzn_submit_own_delivery_claim');
$fiTeacher->remove_cap('dzn_submit_own_continuation_match_exception');
$fiMethod->invoke(null);
if (!$fiTeacher->has_cap('dzn_submit_own_delivery_claim') || !$fiTeacher->has_cap('dzn_submit_own_continuation_match_exception')) throw new RuntimeException('Partial Teacher capability was not repaired');

// 4. Reserved review/adjudication/commercial authority must be removed from Teacher after repair.
$fiTeacher->caps['dzn_manage_canonical_attendance_review'] = true;
$fiTeacher->caps['dzn_manage_canonical_continuation'] = true;
$fiTeacher->caps['dzn_manage_commercial_catalogue'] = true;
$fiMethod->invoke(null);
foreach ($fiTeacherReserved as $cap) if ($fiTeacher->has_cap($cap)) throw new RuntimeException('Reserved capability remained on Teacher after repair: ' . $cap);
foreach ($fiTeacherOwned as $cap) if (!$fiTeacher->has_cap($cap)) throw new RuntimeException('Repair removed a Teacher-owned grant: ' . $cap);

echo "Fresh-install capability bootstrap passed\n";
