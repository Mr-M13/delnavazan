<?php
/** Isolated fail-soft metadata test: no WordPress database or production writes. */
$events = array();
function do_action($hook, $payload) { global $events; $events[] = array($hook, $payload); }
require dirname(__DIR__) . '/src/Portals/PortalAuthenticatedReadService.php';
$service = new \Delnavazan\Platform\Portals\PortalAuthenticatedReadService();
$method = new ReflectionMethod($service, 'failedRead');
$failure = $method->invoke($service, 'post_intro', new RuntimeException('SECRET_SQL_AND_USER_DATA'));
if ($failure['state'] !== 'unavailable' || !preg_match('/^[0-9a-f]{16}$/', $failure['correlation_id'])) throw new RuntimeException('Invalid safe read status');
if (count($events) !== 1 || $events[0][0] !== 'dzn_portal_read_failed') throw new RuntimeException('Expected one operational event');
$payload = $events[0][1];
if ($payload['service'] !== 'post_intro' || $payload['severity'] !== 'error' || empty($payload['timestamp'])) throw new RuntimeException('Missing operational diagnostics');
if (strpos(json_encode(array($failure, $events)), 'SECRET_SQL_AND_USER_DATA') !== false) throw new RuntimeException('Leaked sensitive exception message');
$second = $method->invoke($service, 'reschedule_proposals', new RuntimeException('timeout'));
if ($second['correlation_id'] === $failure['correlation_id'] || count($events) !== 2) throw new RuntimeException('Retry/failure correlation invalid');
echo "Portal read health unit PASS\n";
