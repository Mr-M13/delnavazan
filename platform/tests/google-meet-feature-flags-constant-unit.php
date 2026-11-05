<?php
/** A defined PHP constant always wins and is reported as the source. */
define('DZN_MEET_CLASSROOM_ENABLED', '0');
define('DZN_MEET_AUTO_RECONCILE', '1');

require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceFeatureFlags.php';

use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceFeatureFlags as Flags;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$report = Flags::report();
$assert($report['classroom_enabled']['effective'] === false, 'Constant pause was ignored.');
$assert($report['classroom_enabled']['source'] === 'constant', 'Constant pin source is wrong.');
$assert($report['auto_reconcile']['effective'] === true, 'Constant auto-reconcile enable was ignored.');
$assert($report['auto_reconcile']['source'] === 'constant', 'Constant auto-reconcile source is wrong.');

echo "Google Meet feature flags constant unit passed\n";
