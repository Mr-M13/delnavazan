<?php
/** Scheduled discovery defaults and the emergency provisioning pause switch. */
require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceFeatureFlags.php';
require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceClassroomReconciler.php';
require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceClassroomService.php';

use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomReconciler as Reconciler;
use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService as Service;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

putenv('DZN_MEET_AUTO_RECONCILE');
$assert(!Reconciler::automaticEnabled(), 'Scheduled missing-row discovery must be disabled by default.');
putenv('DZN_MEET_AUTO_RECONCILE=1');
$assert(Reconciler::automaticEnabled(), 'Scheduled discovery must work after explicit enablement.');
putenv('DZN_MEET_AUTO_RECONCILE=0');
$assert(!Reconciler::automaticEnabled(), 'Scheduled discovery must remain disabled when explicitly off.');
putenv('DZN_MEET_AUTO_RECONCILE');

putenv('DZN_MEET_CLASSROOM_ENABLED=0');
$assert(!Service::enabled(), 'The emergency pause must disable new provisioning.');
putenv('DZN_MEET_CLASSROOM_ENABLED=1');
$assert(Service::enabled(), 'Provisioning must be enabled by explicit true.');
putenv('DZN_MEET_CLASSROOM_ENABLED');

echo "Google Meet scheduled discovery unit passed\n";
