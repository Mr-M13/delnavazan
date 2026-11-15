<?php
/** Contract checks for fail-soft student portal read health and safe rendering. */
$root = dirname(__DIR__, 2);
$read = file_get_contents($root . '/platform/src/Portals/PortalAuthenticatedReadService.php');
$bridge = file_get_contents($root . '/theme/inc/platform-bridge.php');
$shell = file_get_contents($root . '/theme/template-parts/portal/shell.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
foreach (array('post_intro', 'reschedule_proposals') as $service) {
    $assert(strpos($read, "'" . $service . "' => array('state' => 'ok')") !== false, "Missing successful/empty read state: $service");
    $assert(strpos($read, "['" . $service . "'] = \$this->failedRead(") !== false, "Missing failed read state: $service");
    $assert(strpos($shell, "'" . $service . "' =>") !== false, "Missing Persian unavailable display: $service");
}
foreach (array('correlation_id', 'timestamp', 'severity', 'service', 'exception_class') as $field) {
    $assert(strpos($read, "'" . $field . "'") !== false, "Missing diagnostic field: $field");
}
$assert(strpos($bridge, "\$model['read_health'] = \$read_health") !== false, 'Read status not propagated to theme');
$assert(strpos($bridge, "\$read_unavailable && 'no_data' === \$state") !== false, 'Empty portal must not conceal read failure');
$assert(strpos($shell, 'role="alert" dir="rtl"') !== false, 'Accessible RTL warning missing');
$assert(strpos($shell, 'تلاش دوباره') !== false, 'Persian retry link missing');
$assert(strpos($shell, 'getMessage()') === false, 'Raw exception details in theme');
echo "Portal read health contract PASS\n";
