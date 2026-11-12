<?php
declare(strict_types=1);
/**
 * The custom account-removal settings surface is retired.
 *
 * No deletion route, admin handler or settings panel remains. The read-only discovery service is
 * retained for lawful privacy/retention review; it must not gain a destructive operation. WordPress
 * core account administration and legally required data handling remain untouched.
 */
$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/delnavazan-platform.php');
$general = file_get_contents($root . '/src/Admin/Controller/GeneralSettingsController.php');
$servicePath = $root . '/src/Core/Application/AccountRemovalDiscoveryService.php';

if (file_exists($root . '/src/Admin/Controller/AccountRemovalController.php')) throw new RuntimeException('Account-removal controller still exists');
if (str_contains($plugin, 'AccountRemovalController::register()')) throw new RuntimeException('Account-removal route is still registered');
if (str_contains($general, 'dzn-account-removal') || str_contains($general, 'بررسی حذف حساب')) throw new RuntimeException('Account-removal panel is still exposed in Settings');
if (str_contains($plugin, 'dzn_account_removal')) throw new RuntimeException('Account-removal handler is still registered');

if (is_file($servicePath)) {
    $service = (string) file_get_contents($servicePath);
    if (!str_contains($service, 'discoverByReference') || !str_contains($service, 'WHERE reference_code=%s')) {
        throw new RuntimeException('The preserved privacy-review lookup lost its canonical reference query');
    }
    foreach (array('DELETE FROM', 'TRUNCATE ', 'DROP TABLE', 'wp_delete_user(', 'admin_post_') as $forbidden) {
        if (str_contains($service, $forbidden)) throw new RuntimeException('Destructive operation introduced into the preserved privacy helper: ' . $forbidden);
    }
}
echo "account_removal_security_contract_ok\n";
