<?php
/**
 * Standalone regression coverage for the identity boundary that joins an account
 * claim to the authenticated Student portal.  It deliberately uses no WordPress
 * runtime or database so the fail-closed resolver paths remain locally testable.
 */
$root = dirname(__DIR__);

$dzn_portal_user_id = 41;
function get_current_user_id(): int { global $dzn_portal_user_id; return $dzn_portal_user_id; }
function current_time(string $type, bool $gmt = false): string { return '2026-10-12 00:00:00'; }

final class DznPortalPrincipalFakeDatabase {
    public string $prefix = 'wp_';
    /** @var list<object> */
    public array $studentLinks = array();
    public ?object $student = null;
    public string $linkedAt = '2026-10-01 00:00:00';

    public function prepare(string $query, mixed ...$arguments): string { return $query; }
    public function get_results(string $query): array {
        if (str_contains($query, 'student_principal_links')) return $this->studentLinks;
        return array();
    }
    public function get_row(string $query): ?object {
        if (!str_contains($query, 'dzn_students')) return null;
        if (str_contains($query, "status='active'") && $this->student && $this->student->status !== 'active') return null;
        return $this->student;
    }
    public function get_var(string $query): string { return $this->linkedAt; }
}

function dzn_portal_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function dzn_portal_refusal(string $reason, callable $action): void {
    try {
        $action();
    } catch (InvalidArgumentException $error) {
        dzn_portal_assert($error->getMessage() === $reason, 'Expected '.$reason.', got '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected portal refusal '.$reason);
}

require $root.'/src/Portals/PortalRule.php';
require $root.'/src/Portals/PortalPrincipalResolver.php';

global $wpdb;
$wpdb = new DznPortalPrincipalFakeDatabase();
$resolver = new \Delnavazan\Platform\Portals\PortalPrincipalResolver();

// A single active Student link authenticates to its own canonical identity.
$wpdb->studentLinks = array((object) array('id' => 17));
$wpdb->student = (object) array('uid' => 'studentuid000000000000000001', 'reference_code' => 'DZN-STU-17', 'status' => 'active');
$principal = $resolver->resolve('student');
dzn_portal_assert($principal === array('kind' => 'student', 'id' => 17, 'uid' => 'studentuid000000000000000001', 'display_reference' => 'DZN-STU-17', 'linked_at' => '2026-10-01 00:00:00'), 'Linked Student did not resolve to only its canonical portal identity');

// Incomplete or inactive identities and competing links are refusals, never fallback identities.
$wpdb->student = (object) array('uid' => 'studentuid000000000000000001', 'reference_code' => '', 'status' => 'active');
dzn_portal_refusal('portal_principal_unresolved', fn() => $resolver->resolve('student'));
$wpdb->student = null;
dzn_portal_refusal('portal_principal_unresolved', fn() => $resolver->resolve('student'));
$wpdb->student = (object) array('uid' => 'studentuid000000000000000001', 'reference_code' => 'DZN-STU-17', 'status' => 'inactive');
dzn_portal_refusal('portal_principal_unresolved', fn() => $resolver->resolve('student'));
$wpdb->student = (object) array('uid' => 'studentuid000000000000000001', 'reference_code' => 'DZN-STU-17', 'status' => 'active');
$wpdb->studentLinks = array((object) array('id' => 17), (object) array('id' => 18));
dzn_portal_refusal('portal_principal_ambiguous', fn() => $resolver->resolve('student'));
$wpdb->studentLinks = array();
dzn_portal_refusal('portal_principal_unresolved', fn() => $resolver->resolve('student'));
$dzn_portal_user_id = 0;
dzn_portal_refusal('portal_principal_required', fn() => $resolver->resolve('student'));

// Keep all claim boundaries and the portal resolver aligned on a complete, active identity.
$identity = file_get_contents($root.'/src/Core/Application/StudentIdentityResolutionService.php');
$records = file_get_contents($root.'/src/Core/Infrastructure/Repository/StudentIdentityAuthorityRepository.php');
$issuance = file_get_contents($root.'/src/Core/Application/StudentAccountClaimInvitationService.php');
$validation = file_get_contents($root.'/src/Core/Application/StudentAccountClaimValidationService.php');
$acceptance = file_get_contents($root.'/src/Core/Application/StudentAcceptanceAuthorityService.php');
$source = file_get_contents($root.'/src/Portals/PortalPrincipalResolver.php');
foreach (array('assignStudentReference($newId,Identifier::reference(\'DZN-STU-\',$newId))', "array('id'=>\$studentId,'reference_code'=>null)") as $needle) {
    dzn_portal_assert(str_contains($identity.$records, $needle), 'Created Student identity must receive its canonical reference before resolution: '.$needle);
}
foreach (array($issuance, $validation, $acceptance) as $boundary) {
    foreach (array("\$student->status!=='active'", "trim((string)\$student->uid)===''", "trim((string)\$student->reference_code)===''" ) as $needle) {
        dzn_portal_assert(str_contains($boundary, $needle), 'Claim boundary does not fail closed for an incomplete Student identity: '.$needle);
    }
}
dzn_portal_assert(str_contains($acceptance, 'wordpressUserForUpdate($userId)'), 'Final claim acceptance must re-check the authenticated WordPress principal under its authority transaction');
foreach (array("status='active' AND archived_at IS NULL", "AND revoked_at IS NULL AND active_slot=1") as $needle) {
    dzn_portal_assert(str_contains($source, $needle), 'Portal resolution must re-prove current entity/link authority: '.$needle);
}

echo "Student claim portal authentication contract passed\n";
