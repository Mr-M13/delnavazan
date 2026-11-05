<?php
/** Pure retry/readiness rules for organizer-owned Meet provisioning and booking recovery. */
$root = dirname(__DIR__);
require_once $root . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceClassroomRule.php';
require_once $root . '/src/Integrations/GoogleWorkspace/MeetCoHostRule.php';
require_once $root . '/src/Integrations/GoogleWorkspace/MeetProvisioningRule.php';

use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomRule as ClassroomRule;
use Delnavazan\Platform\Integrations\GoogleWorkspace\MeetCoHostRule as CoHost;
use Delnavazan\Platform\Integrations\GoogleWorkspace\MeetProvisioningRule as Rule;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

foreach (array(0, 408, 425, 429, 500, 502, 503, 504) as $status) {
    $assert(Rule::isRetryableResponse($status), 'Transient HTTP status must retry: ' . $status);
}
foreach (array(400, 401, 403, 404, 409, 410, 422) as $status) {
    $assert(!Rule::isRetryableResponse($status), 'Permanent HTTP status must not retry: ' . $status);
}
$assert(Rule::isRetryableResponse(400, 'rate_limit_exceeded'), 'A retryable provider error class must override a generic 400.');
$assert(!Rule::isRetryableResponse(403, 'permission_denied'), 'A permission refusal must remain permanent.');

$assert(Rule::failureCode('calendar_projection', true) === 'meet_calendar_projection_retryable', 'Transient calendar failure code changed.');
$assert(Rule::failureCode('calendar_projection', false) === 'meet_calendar_projection_refused', 'Permanent calendar failure code changed.');
$assert(Rule::failureCode('space_config', true) === 'meet_space_config_retryable', 'Transient config failure code changed.');
$assert(Rule::failureCode('space_config', false) === 'meet_space_config_refused', 'Permanent config failure code changed.');

$assert(Rule::isRetryableCode('meet_conference_pending'), 'A pending Meet conference must be retryable.');
$assert(Rule::isRetryableCode('meet_cohost_assignment_retryable'), 'A transient co-host failure must be retryable.');
$assert(Rule::isPermanentCode('meet_cohost_assignment_blocked'), 'A permanent co-host block must be terminal.');
$assert(Rule::isPermanentCode('meet_space_config_refused'), 'A permanent config refusal must be terminal.');
$assert(Rule::isRetryableBookingFailure('meet_conference_pending'), 'A pending conference must keep the accepted booking recoverable.');
$assert(Rule::isRetryableBookingFailure('meet_organizer_not_connected'), 'A temporarily unavailable organizer must keep the accepted booking recoverable.');
$assert(Rule::isRetryableBookingFailure('meet_classroom_paused'), 'A deliberate provisioning pause must keep the accepted booking recoverable.');
$assert(!Rule::isRetryableBookingFailure('meet_cohost_assignment_blocked'), 'A blocked co-host must require an explicit administrator action.');
$assert(!Rule::isRetryableBookingFailure('meet_booking_ended'), 'A booking that has ended must not be revived.');
$assert(!Rule::isRetryableBookingFailure('meet_classroom_history_not_retryable'), 'Cancelled/retired classroom history must not be revived.');
$assert(Rule::isRetryableBookingFailure('provider_projection_retryable'), 'Unknown transient provider failure must stay recoverable.');

$now = 1_800_000_000;
$assert(Rule::mayAutoRetryClassroom('provisioning', CoHost::PENDING, null, null, 0, $now), 'A new provisioning row must be retryable.');
$assert(Rule::mayAutoRetryClassroom('provisioning', CoHost::RETRYABLE, 'meet_cohost_assignment_retryable', gmdate('Y-m-d H:i:s', $now - 60), 2, $now), 'A due transient co-host retry must run.');
$assert(!Rule::mayAutoRetryClassroom('provisioning', CoHost::RETRYABLE, 'meet_cohost_assignment_retryable', gmdate('Y-m-d H:i:s', $now + 60), 2, $now), 'A future co-host backoff must be respected.');
$assert(!Rule::mayAutoRetryClassroom('blocked', CoHost::BLOCKED, 'meet_cohost_assignment_blocked', null, 3, $now), 'A permanent co-host refusal must never auto-retry.');
$assert(!Rule::mayAutoRetryClassroom('blocked', CoHost::VERIFIED, 'meet_space_config_refused', null, 1, $now), 'A permanent access-config refusal must not auto-retry.');
$assert(!Rule::mayAutoRetryClassroom('ready', CoHost::VERIFIED, null, null, 1, $now), 'A ready verified classroom must remain idempotent.');
$assert(!Rule::mayAutoRetryClassroom('provisioning', CoHost::RETRYABLE, 'meet_cohost_assignment_retryable', null, ClassroomRule::MAX_ATTEMPTS, $now), 'The retry bound must become terminal.');
$assert(!Rule::mayAutoRetryClassroom('cancelled', CoHost::VERIFIED, null, null, 1, $now), 'Cancelled history must never be retried.');
$assert(Rule::cohostAttemptExhausted(ClassroomRule::MAX_ATTEMPTS), 'The co-host attempt bound must be enforceable.');
$assert(!Rule::cohostAttemptExhausted(ClassroomRule::MAX_ATTEMPTS - 1), 'A retry before the bound must remain available.');

echo "Google Meet provisioning rule unit passed\n";
