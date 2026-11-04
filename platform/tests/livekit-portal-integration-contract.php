<?php
/**
 * Retirement contract for the archived LiveKit classroom.
 *
 * The historical implementation remains in source for recovery, but no LiveKit route, SDK, script or
 * portal action may be active. Google Meet is the primary classroom.
 */
$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException('Missing source file: ' . $relative);
    return (string) file_get_contents($path);
};
$check = static function (string $label, bool $condition): void {
    if (!$condition) throw new RuntimeException('LiveKit retirement contract failed: ' . $label);
};

$plugin = $read('platform/delnavazan-platform.php');
$check('LiveKit token endpoint is not registered', !str_contains($plugin, "LiveKitTokenRestController', 'register'"));
$check('LiveKit webhook endpoint is not registered', !str_contains($plugin, "LiveKitWebhookController', 'register'"));
$check('LiveKit launch boundary is not registered', !str_contains($plugin, 'LiveKitLaunchController::register'));
$check('LiveKit Meet fallback is not registered', !str_contains($plugin, 'LiveKitMeetFallbackController::register'));
$check('LiveKit revocation listener is not registered', !str_contains($plugin, 'LiveKitRoomRevocationListener::register'));

$routes = $read('theme/inc/routes.php');
$check('classroom route no longer loads the LiveKit SDK', !str_contains($routes, 'livekit-client.umd.min.js'));
$check('classroom route renders the Meet entry template', str_contains($routes, 'template-parts/classroom/meet'));

$component = $read('theme/template-parts/components/classroom-actions.php');
$check('portal no longer renders a LiveKit action', !str_contains(strtolower($component), 'livekit'));
$check('portal renders one Google Meet entry action', str_contains($component, 'ورود به کلاس Google Meet'));

$bridge = $read('theme/inc/platform-bridge.php');
$check('portal availability checks the central Meet classroom', str_contains($bridge, 'meet_classrooms'));
$check('retired LiveKit readiness probe is gone', !str_contains($bridge, 'function dzn_theme_platform_livekit_ready'));

foreach (array(
    'src/Integrations/LiveKit/LiveKitClassroomAuthorizer.php',
    'src/Integrations/LiveKit/LiveKitLaunchController.php',
    'src/Integrations/LiveKit/LiveKitJwtIssuer.php',
    'src/Integrations/LiveKit/LiveKitWebhookVerifier.php',
    'assets/js/vendor/livekit-client.umd.min.js',
) as $archived) {
    $check('archived LiveKit file remains: ' . $archived, is_file(dirname(__DIR__, 2) . '/platform/' . $archived) || is_file(dirname(__DIR__, 2) . '/theme/' . $archived));
}

echo "LiveKit retirement contract passed\n";
