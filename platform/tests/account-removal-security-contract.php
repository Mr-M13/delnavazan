<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$controller = file_get_contents($root . '/src/Admin/Controller/AccountRemovalController.php');
$service = file_get_contents($root . '/src/Core/Application/AccountRemovalDiscoveryService.php');
$plugin = file_get_contents($root . '/delnavazan-platform.php');
foreach (array("'manage_options'", 'check_admin_referer', "'POST'", 'account_id', 'account_kind', 'esc_html') as $required) {
    if (!str_contains($controller, $required)) throw new RuntimeException('Missing admin inspection guard: ' . $required);
}
foreach (array('disposable_test_provenance_unverified', 'indirect_and_external_dependencies_unreviewed', 'shared_wordpress_identity', 'wordpress_content_ownership') as $required) {
    if (!str_contains($service, $required)) throw new RuntimeException('Missing blocker: ' . $required);
}
if (!str_contains($plugin, 'AccountRemovalController::register()')) throw new RuntimeException('Inspection route not registered');
foreach (array($controller, $service) as $source) {
    foreach (array('DELETE FROM', 'TRUNCATE ', 'DROP TABLE', 'wp_delete_user(', 'admin_post_') as $forbidden) {
        if (str_contains($source, $forbidden)) throw new RuntimeException('Destructive operation introduced: ' . $forbidden);
    }
}
echo "account_removal_security_contract_ok\n";
