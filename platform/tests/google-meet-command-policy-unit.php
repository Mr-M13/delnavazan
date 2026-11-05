<?php
/** Pure manual-command approval and operator policy. */
namespace Delnavazan\Platform\Admin\Cli {
    require_once dirname(__DIR__) . '/src/Admin/Cli/MeetCommandPolicy.php';

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $digest = str_repeat('a', 64);

    $dry = MeetCommandPolicy::repair(true, null);
    $assert($dry['mode'] === 'dry_run', 'Repair dry-run must be read-only and need no digest.');

    $missing = MeetCommandPolicy::repair(false, null);
    $assert($missing['mode'] === 'rejected' && $missing['reason'] === 'plan_required', 'Repair apply without a plan must be rejected.');

    $invalid = MeetCommandPolicy::repair(false, 'not-a-digest');
    $assert($invalid['mode'] === 'rejected' && $invalid['reason'] === 'plan_invalid', 'Repair apply with an invalid digest must be rejected.');

    $valid = MeetCommandPolicy::repair(false, $digest);
    $assert($valid['mode'] === 'apply' && $valid['plan'] === $digest, 'Repair apply with a valid reviewed digest must be accepted.');

    $discovery = MeetCommandPolicy::worker(false, true, $digest);
    $assert($discovery['mode'] === 'rejected' && $discovery['reason'] === 'discovery_requires_repair', 'Manual worker discovery must never apply, even with a digest.');

    $workerMissing = MeetCommandPolicy::worker(false, false, null);
    $assert($workerMissing['mode'] === 'rejected' && $workerMissing['reason'] === 'plan_required', 'Manual worker apply without a plan must be rejected.');

    $workerValid = MeetCommandPolicy::worker(false, false, $digest);
    $assert($workerValid['mode'] === 'apply' && $workerValid['plan'] === $digest, 'Manual existing-row worker apply must require and accept a valid digest.');

    $workerDry = MeetCommandPolicy::worker(true, true, null);
    $assert($workerDry['mode'] === 'dry_run', 'Manual worker discovery dry-run must remain read-only.');

    $assert(MeetCommandPolicy::operatorAllowed(7, true, false), 'A manage_options operator must be allowed.');
    $assert(MeetCommandPolicy::operatorAllowed(7, false, true), 'A lesson/provider operator must be allowed.');
    $assert(!MeetCommandPolicy::operatorAllowed(0, true, true), 'An unauthenticated CLI process must not be an operator.');
    $assert(!MeetCommandPolicy::operatorAllowed(7, false, false), 'A user without a relevant capability must not be an operator.');

    echo "Google Meet command policy unit passed\n";
}
