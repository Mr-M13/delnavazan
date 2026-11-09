<?php
/** Granular permission catalogue: module → functional area → action. */
require_once dirname(__DIR__) . '/src/Core/Application/PermissionRegistry.php';

use Delnavazan\Platform\Core\Application\PermissionRegistry;

$fail = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$modules = PermissionRegistry::modules();
$all = PermissionRegistry::all();

$fail(count($modules) >= 12, 'The permission catalogue must cover every administrative module');
foreach (['overview','teachers','students','classes','bookings','enrolment','finance','notifications','policies','integrations','diagnostics','administration'] as $required) {
    $fail(isset($modules[$required]), 'Missing module: ' . $required);
}
foreach ($modules as $moduleKey => $module) {
    $fail(isset($module['label'], $module['areas']) && is_array($module['areas']), 'Module shape invalid: ' . $moduleKey);
    foreach ($module['areas'] as $areaKey => $area) {
        $fail(isset($area['label'], $area['actions']) && is_array($area['actions']), 'Area shape invalid: ' . $moduleKey . '.' . $areaKey);
        foreach ($area['actions'] as $actionKey => $action) {
            $key = $moduleKey . '.' . $areaKey . '.' . $actionKey;
            $fail(isset($all[$key]), 'Action is not flattened: ' . $key);
            $fail(is_array($action['caps']) && $action['caps'] !== [], 'Default-deny action has no canonical capability mapping: ' . $key);
            foreach ($action['caps'] as $cap) $fail(is_string($cap) && str_starts_with($cap, 'dzn_') || $cap === 'manage_options', 'Unrecognised capability: ' . $cap);
        }
    }
}

/* The Classes example must be expressible as independent actions. */
foreach (['classes.regular.view','classes.introductory.create','classes.introductory.coordinate','classes.scheduling.assign','classes.scheduling.reschedule','classes.regular.cancel','classes.attendance.confirm','classes.classroom.retry_meet','classes.regular.delete','classes.classroom.configure'] as $permission) {
    $fail(isset($all[$permission]), 'Classes permission missing: ' . $permission);
}
$fail($all['classes.regular.view']['caps'] !== $all['classes.regular.cancel']['caps'], 'View and cancel must not share one coarse capability');
$fail($all['classes.attendance.confirm']['caps'] !== $all['classes.scheduling.reschedule']['caps'], 'Attendance and reschedule must be independently grantable');

/* Capability projection round-trips for a single-capability permission. */
$permission = 'diagnostics.health.view';
$caps = PermissionRegistry::capabilitiesFor([$permission]);
$fail($caps === ['dzn_view_diagnostics'], 'Capability projection mismatch');
$roundTrip = PermissionRegistry::permissionsForCapabilities($caps);
$fail(in_array($permission, $roundTrip, true), 'Capability round-trip lost the permission');

/* Unknown permissions grant nothing. */
$fail(PermissionRegistry::capabilitiesFor(['classes.regular.fly']) === [], 'An unknown permission must grant no capability');
$fail(PermissionRegistry::find('classes.regular.fly') === null, 'An unknown permission must not resolve');

echo "Permission registry unit passed\n";
