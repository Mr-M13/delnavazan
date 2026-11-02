<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$controller = file_get_contents($root . '/src/Admin/Controller/AccountRemovalController.php');
$service = file_get_contents($root . '/src/Core/Application/AccountRemovalDiscoveryService.php');
$plugin = file_get_contents($root . '/delnavazan-platform.php');
$general = file_get_contents($root . '/src/Admin/Controller/GeneralSettingsController.php');
foreach (array("'manage_options'", 'check_admin_referer', "'POST'", 'account_reference', 'esc_html') as $required) {
    if (!str_contains($controller, $required)) throw new RuntimeException('Missing admin inspection guard: ' . $required);
}
foreach (array('indirect_and_external_dependencies_unreviewed', 'shared_wordpress_identity', 'wordpress_content_ownership') as $required) {
    if (!str_contains($service, $required)) throw new RuntimeException('Missing blocker: ' . $required);
}
if (str_contains($service, 'is_test') || str_contains($service, 'example.invalid')) throw new RuntimeException('Account origin must not control discovery eligibility');
if (!str_contains($plugin, 'AccountRemovalController::register()')) throw new RuntimeException('Inspection route not registered');
if (!str_contains($service, 'discoverByReference') || !str_contains($service, 'WHERE reference_code=%s')) throw new RuntimeException('Canonical reference lookup is missing');
if (!str_contains($general, "admin.php?page=dzn-account-removal") || !str_contains($general, 'بررسی حذف حساب')) throw new RuntimeException('Inspection route is hidden from Settings');
foreach (array($controller, $service) as $source) {
    foreach (array('DELETE FROM', 'TRUNCATE ', 'DROP TABLE', 'wp_delete_user(', 'admin_post_') as $forbidden) {
        if (str_contains($source, $forbidden)) throw new RuntimeException('Destructive operation introduced: ' . $forbidden);
    }
}
echo "account_removal_security_contract_ok\n";
