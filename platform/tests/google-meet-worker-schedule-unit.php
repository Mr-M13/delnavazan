<?php
/** Deterministic cron scheduling for the Meet worker, without WordPress or provider calls. */
$GLOBALS['dzn_test_cron'] = array();
$GLOBALS['dzn_test_actions'] = array();
$GLOBALS['dzn_test_filters'] = array();
$GLOBALS['dzn_test_installing'] = false;

function wp_installing(bool $is_installing = false): bool {
    if ($is_installing) $GLOBALS['dzn_test_installing'] = true;
    return (bool) $GLOBALS['dzn_test_installing'];
}
function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
    $GLOBALS['dzn_test_actions'][$hook][$priority][] = $callback;
}
function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
    $GLOBALS['dzn_test_filters'][$hook][$priority][] = $callback;
}
function has_action(string $hook): int|false {
    if (empty($GLOBALS['dzn_test_actions'][$hook])) return false;
    $priorities = array_keys($GLOBALS['dzn_test_actions'][$hook]);
    sort($priorities);
    return (int) $priorities[0];
}
function _get_cron_array(): array|false {
    return $GLOBALS['dzn_test_cron'];
}
function wp_get_scheduled_event(string $hook): object|false {
    foreach ($GLOBALS['dzn_test_cron'] as $timestamp => $events) {
        if (!isset($events[$hook]) || !is_array($events[$hook])) continue;
        foreach ($events[$hook] as $event) {
            if (!is_array($event)) continue;
            return (object) array(
                'hook' => $hook,
                'timestamp' => (int) $timestamp,
                'schedule' => (string) ($event['schedule'] ?? ''),
                'args' => $event['args'] ?? array(),
            );
        }
    }
    return false;
}
function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = array()): bool {
    $GLOBALS['dzn_test_cron'][$timestamp][$hook][$timestamp] = array('schedule' => $recurrence, 'args' => $args);
    return true;
}
function wp_clear_scheduled_hook(string $hook, array $args = array()): int|false {
    $removed = 0;
    foreach ($GLOBALS['dzn_test_cron'] as $timestamp => $events) {
        if (isset($events[$hook])) {
            $removed += is_array($events[$hook]) ? count($events[$hook]) : 1;
            unset($GLOBALS['dzn_test_cron'][$timestamp][$hook]);
        }
    }
    return $removed;
}

require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceWorker.php';

use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceWorker;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$original = getenv('DZN_MEET_CLASSROOM_ENABLED');
putenv('DZN_MEET_CLASSROOM_ENABLED=0');

try {
    GoogleWorkspaceWorker::register();
    $assert(has_action(GoogleWorkspaceWorker::HOOK) !== false, 'The worker hook must be registered on normal plugin load.');
    $scheduleFilter = $GLOBALS['dzn_test_filters']['cron_schedules'][10][0];
    $schedules = $scheduleFilter(array());
    $assert(($schedules['dzn_five_minutes']['interval'] ?? 0) === 300, 'The worker recurrence must be five minutes.');

    GoogleWorkspaceWorker::ensureScheduled();
    $assert(GoogleWorkspaceWorker::scheduledOccurrences() === 1, 'The first schedule must create exactly one event.');
    $event = wp_get_scheduled_event(GoogleWorkspaceWorker::HOOK);
    $assert(is_object($event) && $event->schedule === 'dzn_five_minutes', 'The worker must use the five-minute recurrence.');
    $report = GoogleWorkspaceWorker::scheduleReport();
    $assert(($report['hook'] ?? '') === GoogleWorkspaceWorker::HOOK, 'The schedule report hook is wrong.');
    $assert(($report['hook_registered'] ?? false) === true, 'The schedule report must expose hook registration.');
    $assert(($report['scheduled'] ?? false) === true && ($report['occurrences'] ?? 0) === 1, 'The schedule report must expose exactly one event.');
    $assert(($report['event_recurrence'] ?? '') === 'dzn_five_minutes' && is_string($report['next_run_utc'] ?? null), 'The schedule report must expose recurrence and next run.');

    GoogleWorkspaceWorker::ensureScheduled();
    $assert(GoogleWorkspaceWorker::scheduledOccurrences() === 1, 'Repeated ensureScheduled calls must not duplicate the event.');

    // A stale duplicate is repaired to exactly one event.
    wp_schedule_event(time() + 600, 'dzn_five_minutes', GoogleWorkspaceWorker::HOOK);
    $assert(GoogleWorkspaceWorker::scheduledOccurrences() === 2, 'Test setup must create a duplicate.');
    GoogleWorkspaceWorker::ensureScheduled();
    $assert(GoogleWorkspaceWorker::scheduledOccurrences() === 1, 'Duplicate scheduling must be repaired.');

    // A stale recurrence is replaced, not silently left in place.
    wp_clear_scheduled_hook(GoogleWorkspaceWorker::HOOK);
    wp_schedule_event(time() + 600, 'hourly', GoogleWorkspaceWorker::HOOK);
    GoogleWorkspaceWorker::ensureScheduled();
    $event = wp_get_scheduled_event(GoogleWorkspaceWorker::HOOK);
    $assert(is_object($event) && $event->schedule === 'dzn_five_minutes', 'A mismatched recurrence must be replaced.');
    $assert(GoogleWorkspaceWorker::scheduledOccurrences() === 1, 'Recurrence repair must leave exactly one event.');

    // Disabled provisioning does not remove recording/attendance reconciliation from the worker.
    $source = (string) file_get_contents(dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceWorker.php');
    $scheduling = substr($source, (int) strpos($source, 'public static function register'), (int) strpos($source, '/** Deactivation') - (int) strpos($source, 'public static function register'));
    foreach (array('GoogleWorkspaceClassroomService', 'GoogleWorkspaceApiClient', 'wp_remote_', 'ensureForLesson', 'ensureForBooking', 'retryClassroom') as $forbidden) {
        $assert(!str_contains($scheduling, $forbidden), 'Registration/scheduling must not perform provider writes: ' . $forbidden);
    }
    $assert(str_contains($source, "if(GoogleWorkspaceClassroomService::enabled())"), 'Provisioning must remain behind the emergency switch.');
    $assert(str_contains($source, "do_action('dzn_meet_provisioning_paused')"), 'A paused provisioning window must be observable.');
    $assert(str_contains($source, 'dueReconciliation'), 'Recording/attendance reconciliation must remain in the worker.');

    $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/delnavazan-platform.php');
    $uninstall = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
    $assert(str_contains($bootstrap, 'GoogleWorkspaceWorker::register();'), 'The worker must register on normal plugin load.');
    $assert(str_contains($bootstrap, "register_deactivation_hook"), 'The plugin must expose a deactivation cleanup path.');
    $assert(str_contains($uninstall, "wp_clear_scheduled_hook( 'dzn_google_meet_classroom_worker' )"), 'Uninstall must clear only the Meet worker hook.');

    GoogleWorkspaceWorker::deactivate();
    $assert(GoogleWorkspaceWorker::scheduledOccurrences() === 0, 'Deactivation must clear the worker event.');
} finally {
    $original === false ? putenv('DZN_MEET_CLASSROOM_ENABLED') : putenv('DZN_MEET_CLASSROOM_ENABLED=' . $original);
}

echo "Google Meet worker schedule unit passed\n";
