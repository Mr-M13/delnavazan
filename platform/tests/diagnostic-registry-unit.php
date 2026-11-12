<?php
/**
 * Diagnostic registry: categories, check metadata, failure isolation and default-deny.
 */
final class DznDiagWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public function get_var(string $sql) { $this->last_error = 'diagnostic_query_failed'; return null; }
    public function get_results(string $sql): array { $this->last_error = 'diagnostic_query_failed'; return array(); }
}
$GLOBALS['wpdb'] = new DznDiagWpdb();
$GLOBALS['dzn_caps'] = array('manage_options');

function current_user_can(string $cap): bool { return $GLOBALS['dzn_caps'] === array('manage_options') || in_array($cap, $GLOBALS['dzn_caps'], true); }
function get_current_user_id(): int { return 1; }
function get_option(string $key, $default = false) { return $default; }
function get_users(array $args = array()): array { return array(); }
function user_can($user, string $cap): bool { return false; }

require_once dirname(__DIR__) . '/src/Core/Application/PermissionRegistry.php';
require_once dirname(__DIR__) . '/src/Core/Application/StaffAccessService.php';
require_once dirname(__DIR__) . '/src/Core/Application/AccessRoleService.php';
require_once dirname(__DIR__) . '/src/Core/Application/Diagnostics/DiagnosticRegistry.php';

use Delnavazan\Platform\Core\Application\Diagnostics\DiagnosticRegistry;

$fail = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$registry = new DiagnosticRegistry();
$categories = $registry->categories();
$fail(count($categories) === 12, 'Expected twelve supported diagnostic categories, found ' . count($categories));
foreach (['platform','booking','classes','google','finance','notifications','portals','consent','jobs','database','security','integrations'] as $key) {
    $fail(isset($categories[$key]), 'Missing diagnostic category: ' . $key);
    $fail(isset($categories[$key]['label'], $categories[$key]['description'], $categories[$key]['owner']), 'Category metadata incomplete: ' . $key);
    $fail($categories[$key]['checks'] !== array(), 'Category has no checks: ' . $key);
    foreach ($categories[$key]['checks'] as $check) {
        $fail(isset($check['id'], $check['label'], $check['description'], $check['permission'], $check['check']), 'Check metadata incomplete in ' . $key);
        $fail(is_array($check['check']) && method_exists($check['check'][0], (string) $check['check'][1]), 'Check is not callable: ' . $check['id']);
        $fail(str_contains((string) $check['permission'], 'dzn_') || $check['permission'] === 'manage_options', 'Check has no canonical permission: ' . $check['id']);
    }
}

/* A failed database read must be represented as unknown, never as healthy. */
$security = $registry->category('security');
$fail(isset($security['summary']['overall']), 'Category summary missing');
$states = array_column($security['checks'], 'state', 'id');
$fail(in_array('security.audit', array_keys($states), true), 'Audit check did not run');
$fail($states['security.audit'] === 'unknown', 'A failed audit query must be unknown, not healthy');
$fail($states['security.denials'] === 'unknown', 'A failed denial query must be unknown, not healthy');
$fail($states['security.catalogue'] === 'healthy', 'The non-database catalogue check should still complete');
$unknown = array_values(array_filter($security['checks'], static fn(array $check): bool => $check['state'] === 'unknown'));
$fail($unknown !== array(), 'Failure isolation did not produce an unknown check');
$fail($unknown[0]['technical_code'] !== '', 'Unknown checks must carry a meaningful technical code');
$fail(str_contains($unknown[0]['detail'], 'unknown'), 'Unknown check detail must say the state is unknown');

/* Unknown category and permission filtering. */
try { $registry->category('not-a-category'); throw new RuntimeException('Unknown category must throw'); }
catch (InvalidArgumentException $error) { $fail($error->getMessage() === 'diagnostic_category_unknown', 'Wrong unknown-category error'); }

$GLOBALS['dzn_caps'] = array('dzn_view_diagnostics');
$visible = $registry->category('security');
$ids = array_column($visible['checks'], 'id');
$fail(in_array('security.catalogue', $ids, true), 'Diagnostics read permission must see platform checks');
$fail(in_array('security.roles', $ids, true), 'Diagnostics read permission must see role integrity');

$GLOBALS['dzn_caps'] = array('dzn_manage_students');
try { $registry->category('security'); throw new RuntimeException('An unrelated capability must not read diagnostics'); }
catch (RuntimeException $error) { $fail($error->getMessage() === 'diagnostics_forbidden', 'Diagnostics must default-deny unrelated capabilities'); }

/* Redaction and safety of the registry source. */
$source = (string) file_get_contents(dirname(__DIR__) . '/src/Core/Application/Diagnostics/DiagnosticRegistry.php');
foreach (array('SELECT *', 'message_body', 'access_token', 'phone_number', 'recipient_contact') as $forbidden) {
    $fail(!str_contains($source, $forbidden), 'Diagnostic registry must not read or expose: ' . $forbidden);
}
$fail(!str_contains($source, 'shell_exec') && !str_contains($source, 'eval('), 'Diagnostic registry must not execute commands');

echo "Diagnostic registry unit passed\n";
