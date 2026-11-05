<?php
/** Effective Meet flags and configuration-source reporting. */
$root = dirname(__DIR__);
require_once $root . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceFeatureFlags.php';

use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceFeatureFlags as Flags;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$GLOBALS['dzn_test_options'] = array();
if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed {
        return array_key_exists($key, $GLOBALS['dzn_test_options'])
            ? $GLOBALS['dzn_test_options'][$key]
            : $default;
    }
}

$originalEnabled = getenv('DZN_MEET_CLASSROOM_ENABLED');
$originalAuto = getenv('DZN_MEET_AUTO_RECONCILE');
$restore = static function () use ($originalEnabled, $originalAuto): void {
    $originalEnabled === false ? putenv('DZN_MEET_CLASSROOM_ENABLED') : putenv('DZN_MEET_CLASSROOM_ENABLED=' . $originalEnabled);
    $originalAuto === false ? putenv('DZN_MEET_AUTO_RECONCILE') : putenv('DZN_MEET_AUTO_RECONCILE=' . $originalAuto);
};

try {
    putenv('DZN_MEET_CLASSROOM_ENABLED');
    putenv('DZN_MEET_AUTO_RECONCILE');
    unset($GLOBALS['dzn_test_options']['dzn_meet_auto_reconcile']);

    $report = Flags::report();
    $assert($report['classroom_enabled']['effective'] === true, 'Provisioning must default to enabled.');
    $assert($report['classroom_enabled']['source'] === 'default', 'Provisioning default source is wrong.');
    $assert($report['auto_reconcile']['effective'] === false, 'Automatic reconciliation must default to disabled.');
    $assert($report['auto_reconcile']['source'] === 'default', 'Automatic reconciliation default source is wrong.');

    putenv('DZN_MEET_CLASSROOM_ENABLED=0');
    putenv('DZN_MEET_AUTO_RECONCILE=1');
    $report = Flags::report();
    $assert($report['classroom_enabled']['effective'] === false && $report['classroom_enabled']['source'] === 'environment', 'Environment pause source is wrong.');
    $assert($report['auto_reconcile']['effective'] === true && $report['auto_reconcile']['source'] === 'environment', 'Environment auto-reconcile source is wrong.');

    putenv('DZN_MEET_CLASSROOM_ENABLED');
    putenv('DZN_MEET_AUTO_RECONCILE');
    $GLOBALS['dzn_test_options']['dzn_meet_auto_reconcile'] = '1';
    $report = Flags::report();
    $assert($report['auto_reconcile']['effective'] === true && $report['auto_reconcile']['source'] === 'application_option', 'Application option enablement is wrong.');

    $GLOBALS['dzn_test_options']['dzn_meet_auto_reconcile'] = '0';
    $report = Flags::report();
    $assert($report['auto_reconcile']['effective'] === false && $report['auto_reconcile']['source'] === 'application_option', 'Application option disablement is wrong.');
} finally {
    $restore();
    unset($GLOBALS['dzn_test_options']);
}

echo "Google Meet feature flags unit passed\n";
