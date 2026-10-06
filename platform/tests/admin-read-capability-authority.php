<?php
/** Regression coverage for administrator and delegated read-only settings access. */

final class DznAdminAuthorityTestUser {
    public array $allcaps;
    public function __construct(array $allcaps) { $this->allcaps = $allcaps; }
}

$GLOBALS['dzn_test_users'] = array(
    1 => new DznAdminAuthorityTestUser(array('manage_options' => true)),
    2 => new DznAdminAuthorityTestUser(array('dzn_view_diagnostics' => true)),
    3 => new DznAdminAuthorityTestUser(array('dzn_manage_provider_integrations' => true)),
    4 => new DznAdminAuthorityTestUser(array()),
);

function get_userdata(int $userId): ?DznAdminAuthorityTestUser {
    return $GLOBALS['dzn_test_users'][$userId] ?? null;
}

require_once __DIR__ . '/../src/Admin/Controller/Menu.php';

use Delnavazan\Platform\Admin\Controller\Menu;

function dzn_assert_meta_cap(array $expected, string $capability, int $userId): void {
    $actual = Menu::mapMetaCap(array('unchanged'), $capability, $userId, array());
    if ($actual !== $expected) {
        throw new RuntimeException("Unexpected mapped authority for {$capability} and user {$userId}");
    }
}

foreach (array('dzn_access_integrations_overview', 'dzn_access_system_health') as $capability) {
    dzn_assert_meta_cap(array('exist'), $capability, 1);
    dzn_assert_meta_cap(array('exist'), $capability, 2);
    dzn_assert_meta_cap(array('do_not_allow'), $capability, 3);
    dzn_assert_meta_cap(array('do_not_allow'), $capability, 4);
}

dzn_assert_meta_cap(array('exist'), 'dzn_access_provider_integration_settings', 1);
dzn_assert_meta_cap(array('exist'), 'dzn_access_provider_integration_settings', 3);
dzn_assert_meta_cap(array('do_not_allow'), 'dzn_access_provider_integration_settings', 2);
dzn_assert_meta_cap(array('do_not_allow'), 'dzn_access_provider_integration_settings', 4);
dzn_assert_meta_cap(array('unchanged'), 'dzn_unrelated_capability', 1);

$diagnostics = file_get_contents(__DIR__ . '/../src/Core/Application/DiagnosticsService.php');
$integrations = file_get_contents(__DIR__ . '/../src/Core/Application/IntegrationControlPlaneReadService.php');
$settings = file_get_contents(__DIR__ . '/../src/Admin/Controller/IntegrationControlPlaneController.php');
if (!str_contains($diagnostics, "current_user_can('manage_options') && !current_user_can('dzn_view_diagnostics')")
    || !str_contains($integrations, "current_user_can('manage_options')&&!current_user_can(self::READ_CAPABILITY)")
    || !str_contains($settings, "private static function authorizeSettings():void{if(!current_user_can('manage_options')&&!current_user_can('dzn_manage_provider_integrations'))")) {
    throw new RuntimeException('Read and settings callbacks must allow site administrators and retain their delegated capability checks.');
}

echo "Administrator/delegated read authority contract passed\n";
