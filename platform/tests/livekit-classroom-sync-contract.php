<?php
/**
 * Retirement contract for the archived LiveKit classroom sync surface.
 * The historical implementation remains in source; it must not be loaded by the active route.
 */
$root = dirname(__DIR__, 2);
$routes = (string) file_get_contents($root . '/theme/inc/routes.php');
$plugin = (string) file_get_contents($root . '/platform/delnavazan-platform.php');
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$check(!str_contains($routes, 'livekit-client.umd.min.js'), 'Active routes must not load the LiveKit SDK.');
$check(!str_contains($routes, 'music-classroom.js'), 'Active routes must not load the LiveKit classroom script.');
$check(str_contains($routes, 'template-parts/classroom/meet'), 'The classroom route must render the Google Meet entry template.');
$check(!str_contains($plugin, 'LiveKitLaunchController::register'), 'The LiveKit launch boundary must not be registered.');
$check(!str_contains($plugin, 'LiveKitAttendanceReconciliationWorker::register'), 'The LiveKit attendance worker must not be registered.');
$check(is_file($root . '/platform/src/Integrations/LiveKit/LiveKitAdministratorClassroomService.php'), 'Archived LiveKit source must remain recoverable.');
echo "LiveKit classroom sync retirement contract passed\n";
