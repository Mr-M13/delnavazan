<?php
/** Pure preconditions for authorized classroom retry; no provider or database access. */
$root = dirname(__DIR__);
require_once $root . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceClassroomRule.php';
require_once $root . '/src/Integrations/GoogleWorkspace/MeetCoHostRule.php';
require_once $root . '/src/Integrations/GoogleWorkspace/MeetProvisioningRule.php';
require_once $root . '/src/Integrations/GoogleWorkspace/MeetClassroomRetryRule.php';

use Delnavazan\Platform\Integrations\GoogleWorkspace\MeetClassroomRetryRule as Rule;
use Delnavazan\Platform\Integrations\GoogleWorkspace\MeetCoHostRule as CoHost;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$now = strtotime('2026-11-04 12:00:00 UTC');

$assert(Rule::lessonEligibility('authorised', 'scheduled', false, '2026-11-04 13:00:00', $now, true) === Rule::ELIGIBLE, 'An authorised future lesson with a current schedule must be eligible.');
foreach (array(
    array('authorised','scheduled',true,'2026-11-04 13:00:00',true,'lesson_archived'),
    array('cancelled','scheduled',false,'2026-11-04 13:00:00',true,'lesson_not_authorised'),
    array('completed','completed',false,'2026-11-04 13:00:00',true,'lesson_not_authorised'),
    array('authorised','cancelled',false,'2026-11-04 13:00:00',true,'lesson_cancelled'),
    array('authorised','completed',false,'2026-11-04 13:00:00',true,'lesson_completed'),
    array('authorised','scheduled',false,'2026-11-04 13:00:00',false,'schedule_version_unusable'),
    array('authorised','scheduled',false,'2026-11-04 11:59:59',true,'lesson_ended'),
) as $case) {
    $assert(Rule::lessonEligibility($case[0],$case[1],$case[2],$case[3],$now,$case[4]) === $case[5], 'Unexpected eligibility decision for ' . $case[0] . '/' . $case[1]);
}

$ready = (object) array('classroom_state'=>'ready','cohost_state'=>CoHost::VERIFIED);
$blocked = (object) array('classroom_state'=>'blocked','cohost_state'=>CoHost::BLOCKED);
$cancelled = (object) array('classroom_state'=>'cancelled','cohost_state'=>CoHost::PENDING);
$failed = (object) array('classroom_state'=>'failed','cohost_state'=>CoHost::PENDING);
$assert(Rule::classroomSelection($ready,$ready) === 'already_ready', 'A ready verified classroom retry must be idempotent.');
$assert(Rule::classroomSelection($blocked,$blocked) === Rule::RESET_ACTIVE, 'An active blocked row may be reset by an explicit authorized retry.');
$assert(Rule::classroomSelection($cancelled,$cancelled) === 'reject_history', 'A cancelled active row must never be revived.');
$assert(Rule::classroomSelection(null,$cancelled) === 'reject_history', 'An inactive cancelled history row must never be reactivated.');
$assert(Rule::classroomSelection(null,$blocked) === Rule::RESET_LATEST, 'A blocked historical lesson row must be re-opened in place so an existing event/space is reused.');
$assert(Rule::classroomSelection(null,$failed) === Rule::RESET_LATEST, 'A failed historical lesson row must be re-opened in place so an existing event/space is reused.');
$assert(Rule::classroomSelection(null,null) === Rule::CREATE_NEW, 'A missing projection may create a new row.');

// Booking recovery reuses the exact blocked row and references instead of creating a duplicate.
$assert(Rule::bookingSelection($ready,$ready) === 'already_ready', 'A ready booking classroom must remain idempotent.');
$assert(Rule::bookingSelection($blocked,$blocked) === Rule::RESET_ACTIVE, 'An active blocked booking row must be reset in place.');
$retrying = (object) array('classroom_state'=>'provisioning','cohost_state'=>CoHost::RETRYABLE,'failure_reason_code'=>'meet_space_lookup_retryable');
$assert(Rule::bookingSelection($retrying,$retrying) === Rule::RETRY_EXISTING, 'An in-flight transient booking retry must preserve its bounded attempt count.');
$assert(Rule::bookingSelection(null,$blocked) === Rule::RESET_LATEST, 'A historical blocked booking row must be reset, not duplicated.');
$assert(Rule::bookingSelection(null,$failed) === Rule::RESET_LATEST, 'A historical failed booking row must be reset, not duplicated.');
$assert(Rule::bookingSelection(null,$ready) === Rule::REACTIVATE, 'A ready inactive booking row may be reactivated without a provider write.');
$assert(Rule::bookingSelection(null,$cancelled) === 'reject_history', 'A cancelled booking classroom must never be revived.');
$assert(Rule::bookingSelection(null,null) === Rule::CREATE_NEW, 'A genuinely missing booking classroom may create one row.');

$assert(Rule::isPermanentBlock('blocked',CoHost::VERIFIED,null), 'A blocked state must require explicit intervention.');
$assert(Rule::isPermanentBlock('provisioning',CoHost::BLOCKED,null), 'A blocked co-host must require explicit intervention.');
$assert(Rule::isPermanentBlock('failed',CoHost::VERIFIED,'meet_calendar_projection_refused'), 'A permanent provider refusal must require explicit intervention.');
$assert(!Rule::isPermanentBlock('provisioning',CoHost::RETRYABLE,'meet_cohost_assignment_retryable'), 'A transient retry must remain automatic.');

$assert(Rule::reasonRequired(1,'Policy corrected in the Workspace tenant'), 'A documented administrator reason must pass.');
foreach (array(0,1) as $actor) {
    $assert(!Rule::reasonRequired($actor,'short'), 'A missing actor or short reason must fail.');
}
$assert(!Rule::reasonRequired(1,str_repeat('x',256)), 'The audited reason must stay bounded.');

echo "Google Meet classroom retry rule unit passed\n";
