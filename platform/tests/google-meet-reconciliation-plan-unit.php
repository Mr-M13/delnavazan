<?php
/** Pure deterministic plan, total limit, ordering, dedupe and approval digest. */
require_once dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/MeetReconciliationPlan.php';

use Delnavazan\Platform\Integrations\GoogleWorkspace\MeetReconciliationPlan as Plan;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$op = static fn(string $kind,int $id,string $operation,string $start,int $priority,string $fingerprint,string $mode='automatic'): array => array(
    'key'=>$kind.':' . $id, 'kind'=>$kind, 'record_id'=>$id, 'operation'=>$operation,
    'starts_at_utc'=>$start, 'priority'=>$priority, 'fingerprint'=>$fingerprint, 'mode'=>$mode,
);

$operations = array(
    $op('lesson', 3, 'retry', '2026-11-05 09:00:00', 30, 'l3a'),
    $op('lesson', 1, 'create', '2026-11-04 12:00:00', 10, 'l1'),
    $op('booking', 7, 'resume', '2026-11-04 10:00:00', 25, 'b7'),
    $op('lesson', 2, 'reschedule', '2026-11-04 11:00:00', 20, 'l2'),
    $op('lesson', 1, 'retry', '2026-11-04 12:00:00', 30, 'l1b'),
);
$normalised = Plan::normalise($operations, 10);
$assert(count($normalised) === 4, 'Duplicate operations on one record must collapse to one operation.');
$assert($normalised[0]['key'] === 'booking:7' && $normalised[1]['key'] === 'lesson:2', 'Operations must be ordered by imminent UTC start.');
$assert($normalised[2]['key'] === 'lesson:1' && $normalised[2]['operation'] === 'create', 'The lower-priority create must win over a duplicate retry on the same record.');
$limited = Plan::normalise($operations, 2);
$assert(count($limited) === 2 && $limited[0]['key'] === 'booking:7' && $limited[1]['key'] === 'lesson:2', 'The operation limit must be total across categories, not per category.');

$digest = Plan::digest($normalised, 10, true);
$assert(strlen($digest) === 64 && $digest === Plan::digest($normalised, 10, true), 'The plan digest must be deterministic.');
$assert($digest !== Plan::digest($normalised, 9, true), 'The digest must bind the approved total limit.');
$assert($digest !== Plan::digest($normalised, 10, false), 'The digest must bind discovery mode.');
$changed = $normalised;
$changed[0]['fingerprint'] = 'changed';
$assert($digest !== Plan::digest($changed, 10, true), 'The digest must bind every record fingerprint.');

Plan::assertApproved($digest, $digest);
$stale = false;
try { Plan::assertApproved($digest, str_repeat('0', 64)); }
catch (RuntimeException $e) { $stale = $e->getMessage() === 'meet_reconciliation_plan_stale'; }
$assert($stale, 'A different approved digest must be rejected as stale.');

$intervention = $op('booking', 8, 'retry_booking', '2026-11-04 09:00:00', 90, 'b8', 'requires_admin');
$assert($intervention['mode'] === 'requires_admin', 'Administrator-required interventions must not be labelled automatic.');

echo "Google Meet reconciliation plan unit passed\n";
