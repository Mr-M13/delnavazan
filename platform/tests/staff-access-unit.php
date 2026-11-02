<?php
/**
 * Staff access: narrow grants, preserved unrelated capabilities, self/admin protection,
 * immediate revocation and audit evidence. Runs without WordPress.
 */
namespace {
    if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__, 2) . '/');
    if (!function_exists('wp_json_encode')) { function wp_json_encode($value) { return json_encode($value); } }
    if (!function_exists('wp_salt')) { function wp_salt($scheme = 'auth') { return 'test-salt:' . $scheme; } }
    if (!function_exists('get_option')) { function get_option($key, $default = false) { return $GLOBALS['dzn_staff_options'][$key] ?? $default; } }
    if (!function_exists('update_option')) { function update_option($key, $value, $autoload = null) { $GLOBALS['dzn_staff_options'][$key] = $value; return true; } }
    if (!function_exists('update_user_meta')) { function update_user_meta($userId, $key, $value) { $GLOBALS['dzn_staff_meta'][$userId][$key] = $value; return true; } }
    if (!function_exists('get_user_meta')) { function get_user_meta($userId, $key, $single = false) { return $GLOBALS['dzn_staff_meta'][$userId][$key] ?? ''; } }
    if (!function_exists('get_userdata')) { function get_userdata($userId) { return $GLOBALS['dzn_staff_users'][$userId] ?? false; } }
    if (!function_exists('get_user_by')) { function get_user_by($field, $value) { return $GLOBALS['dzn_staff_users'][$value] ?? false; } }
    if (!function_exists('current_user_can')) { function current_user_can($capability) { return $capability === 'manage_options' && !empty($GLOBALS['dzn_staff_actor_admin']); } }
    if (!function_exists('user_can')) { function user_can($user, $capability) { return method_exists($user, 'has_cap') && $user->has_cap($capability); } }
    if (!function_exists('get_current_user_id')) { function get_current_user_id() { return (int) ($GLOBALS['dzn_staff_actor_id'] ?? 1); } }

    final class DznStaffTestUser {
        public int $ID;
        public string $display_name;
        public string $user_email;
        public array $roles;
        public array $caps = array();
        public function __construct(int $id, string $name, string $email, array $roles, array $caps) {
            $this->ID = $id; $this->display_name = $name; $this->user_email = $email; $this->roles = $roles; $this->caps = $caps;
        }
        public function has_cap(string $cap): bool { return isset($this->caps[$cap]) && $this->caps[$cap]; }
        public function add_cap(string $cap): void { $this->caps[$cap] = true; }
        public function remove_cap(string $cap): void { unset($this->caps[$cap]); }
    }

    $GLOBALS['dzn_staff_options'] = array();
    $GLOBALS['dzn_staff_meta'] = array();
    $GLOBALS['dzn_staff_actor_admin'] = true;
    $GLOBALS['dzn_staff_actor_id'] = 1;
    $GLOBALS['dzn_staff_audit'] = array();
    $GLOBALS['dzn_staff_users'] = array(
        1 => new DznStaffTestUser(1, 'Actor', 'actor@example.invalid', array('administrator'), array('manage_options' => true)),
        2 => new DznStaffTestUser(2, 'Staff', 'staff@example.invalid', array('subscriber'), array(
            'dzn_view_booking_requests' => true,
            'dzn_manage_booking_request_coordination' => true,
            'dzn_view_finance_authority' => true,
            'dzn_manage_finance_statements' => true,
            'unrelated_capability' => true,
        )),
        3 => new DznStaffTestUser(3, 'Teacher', 'teacher@example.invalid', array('dzn_teacher'), array(
            'dzn_record_own_availability_assent' => true,
        )),
        4 => new DznStaffTestUser(4, 'Admin', 'admin@example.invalid', array('administrator'), array('manage_options' => true)),
    );
}

namespace Delnavazan\Platform\Tests {
    use Delnavazan\Platform\Core\Application\StaffAccessService;

    require_once dirname(__DIR__) . '/src/Core/Application/StaffAccessService.php';

    final class FakeStaffWpdb {
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        public function insert($table, $data) { if (!empty($GLOBALS['dzn_staff_fail_audit'])) return false; $GLOBALS['dzn_staff_audit'][] = compact('table', 'data'); return 1; }
        public function get_results($sql) { return $GLOBALS['dzn_staff_audit_rows'] ?? array(); }
        public function prepare($sql, ...$args) { return $sql; }
    }

    $GLOBALS['wpdb'] = new FakeStaffWpdb();
    $GLOBALS['dzn_staff_audit_rows'] = array();
    $service = new StaffAccessService();
    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); };

    // Existing access is inferred before the first save, so migration does not revoke anything.
    $before = $service->selectionFor(2);
    $assert($before['areas']['overview']['view'] === true, 'Existing overview view must be inferred');
    $assert($before['areas']['overview']['manage'] === true, 'Existing overview manage must be inferred');
    $assert($before['areas']['finance']['view'] === false, 'A partial finance view bundle must not appear fully granted');
    $assert($before['areas']['finance']['manage'] === false, 'A partial finance bundle must not appear fully granted');
    $assert($service->partialBundlesFor(2) !== array(), 'Partial legacy permissions must be surfaced');
    try { $service->apply(2, $service->emptySelection(), 1); throw new \RuntimeException('Expected partial-grant refusal'); }
    catch (\InvalidArgumentException $error) { $assert($error->getMessage() === 'staff_access_partial_bundle_requires_review', 'Partial grants must require explicit review'); }
    $assert(!$GLOBALS['dzn_staff_users'][2]->has_cap('dzn_manage_finance_policies'), 'A routine save must not expand a partial Finance grant');

    // Grant students view/manage and finance view only, preserving unrelated capabilities.
    $selection = $service->emptySelection();
    $selection['areas']['students']['view'] = true;
    $selection['areas']['students']['manage'] = true;
    $selection['areas']['finance']['view'] = true;
    $selection['confirm_partial'] = true;
    $result = $service->apply(2, $selection, 1);
    $user = $GLOBALS['dzn_staff_users'][2];
    $assert($user->has_cap('dzn_view_student_acceptance_eligibility'), 'Students view grant missing');
    $assert($user->has_cap('dzn_manage_students'), 'Students manage grant missing');
    $assert($user->has_cap('dzn_view_finance_authority'), 'Finance view grant missing');
    $assert(!$user->has_cap('dzn_manage_finance_statements'), 'Finance manage must be revoked immediately');
    $assert(!$user->has_cap('dzn_manage_booking_request_coordination'), 'Overview manage must be revoked immediately');
    $assert($user->has_cap('unrelated_capability'), 'Unrelated capability must be preserved');
    $assert(count($GLOBALS['dzn_staff_audit']) === 1, 'Grant/revoke audit not written');
    $assert(str_contains((string) $GLOBALS['dzn_staff_audit'][0]['data']['safe_detail'], 'students'), 'Audit must describe the new area grants');

    $GLOBALS['dzn_staff_fail_audit'] = true;
    try { $service->apply(2, $service->emptySelection(), 1); throw new \RuntimeException('Expected audit failure'); }
    catch (\RuntimeException $error) { $assert($error->getMessage() === 'staff_access_audit_failed', 'Audit failure must refuse the change'); }
    $GLOBALS['dzn_staff_fail_audit'] = false;
    $assert($user->has_cap('dzn_manage_students'), 'Audit failure must restore effective grants');

    // A teacher portal identity stays separate from the administrative grant.
    $teacherSelection = $service->emptySelection();
    $teacherSelection['areas']['overview']['view'] = true;
    $service->apply(3, $teacherSelection, 1);
    $teacher = $GLOBALS['dzn_staff_users'][3];
    $assert($teacher->has_cap('dzn_view_booking_requests'), 'Teacher staff grant missing');
    $assert($teacher->has_cap('dzn_record_own_availability_assent'), 'Teacher portal capability must remain untouched');

    // Self-granting, administrator edits and privilege escalation are refused.
    foreach (array(
        array(1, $selection, 'staff_access_self_grant_forbidden'),
        array(4, $selection, 'staff_access_administrator_is_read_only'),
    ) as [$target, $input, $expected]) {
        try { $service->apply($target, $input, 1); throw new \RuntimeException('Expected refusal: ' . $expected); }
        catch (\InvalidArgumentException $error) { $assert($error->getMessage() === $expected, 'Wrong refusal: ' . $error->getMessage()); }
    }

    // Unknown keys and WordPress Administrator are not in the catalogue.
    $assert(!in_array('manage_options', $service->managedCaps(), true), 'Administrator capability must never be managed');
    $escalated = array('areas' => array('overview' => array('view' => true, 'manage' => true)), 'sensitive' => array('made_up' => true, 'payment_execution' => true));
    $clean = $service->sanitiseSelection($escalated);
    $assert($clean['sensitive']['payment_execution'] === true, 'Known sensitive grant must survive sanitisation');
    $assert(!array_key_exists('made_up', $clean['sensitive']), 'Unknown sensitive grant must be dropped');

    echo "Staff access unit passed\n";
}
