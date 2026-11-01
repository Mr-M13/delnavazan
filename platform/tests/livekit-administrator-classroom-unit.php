<?php
/**
 * Unit contract for the administrator classroom grant and admin launch ticket.
 *
 * The administrator may enter outside the participant window, but receives a distinct identity in
 * the same canonical room. Participant window refusals remain unchanged, and no API secret or
 * privileged token is part of the administrator grant.
 */
$root = dirname(__DIR__);
require_once $root . '/src/Integrations/LiveKit/LiveKitClassroomGrantPolicy.php';
require_once $root . '/src/Integrations/LiveKit/LiveKitLaunchTicket.php';

$GLOBALS['dzn_livekit_admin_test_user'] = 77;
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return (int) ($GLOBALS['dzn_livekit_admin_test_user'] ?? 0); }
}

$assert = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$refuse = static function (callable $fn, string $reason) use ($assert): void {
    try { $fn(); throw new RuntimeException('Expected refusal: ' . $reason); }
    catch (InvalidArgumentException $error) { $assert($error->getMessage() === $reason, 'Unexpected refusal: ' . $error->getMessage()); }
};

$secret = 'administrator-unit-test-salt';
$policy = new \Delnavazan\Platform\Integrations\LiveKit\LiveKitClassroomGrantPolicy();
$start = strtotime('2026-11-02 10:00:00 UTC');
$end = $start + 3600;
$facts = array(
    'kind' => 'lesson',
    'resource_id' => 501,
    'resource_uid' => str_repeat('L', 26),
    'schedule_key' => str_repeat('S', 26),
    'teacher_id' => 11,
    'student_id' => 12,
    'teacher_name' => 'Teacher',
    'student_name' => 'Student',
    'course_name' => 'Setar',
    'starts_at_utc' => gmdate('Y-m-d H:i:s', $start),
    'ends_at_utc' => gmdate('Y-m-d H:i:s', $end),
    'joinable' => true,
    'meet_available' => true,
);
$teacherPrincipal = array('kind' => 'teacher', 'id' => 11, 'uid' => 'T', 'display_reference' => 'T', 'linked_at' => '2026-01-01 00:00:00');
$studentPrincipal = array('kind' => 'student', 'id' => 12, 'uid' => 'S', 'display_reference' => 'S', 'linked_at' => '2026-01-01 00:00:00');

// Participant policy: two hours before class is outside the unchanged one-hour join window.
$refuse(static fn() => $policy->authorize($facts, 'teacher', $teacherPrincipal, $start - 7200, $secret), 'livekit_outside_window');
$refuse(static fn() => $policy->authorize($facts, 'student', $studentPrincipal, $start - 7200, $secret), 'livekit_outside_window');

// Administrator pre-class entry: permitted two hours before, and also the next day for testing.
$before = $policy->authorizeAdministrator($facts, 77, $start - 7200, $secret);
$later = $policy->authorizeAdministrator($facts, 77, $start + 86400, $secret);
$assert($before['role'] === 'administrator' && $before['administrator'] === true, 'Administrator grant role is missing.');
$assert($before['room'] === $later['room'], 'Administrator testing must resolve the same stable lesson room.');
$assert(preg_match('/^a-[A-Za-z0-9_-]{24}$/D', $before['identity']) === 1, 'Administrator identity is not distinct and bounded.');
$assert($before['identity'] !== $before['teacher_identity'] && $before['identity'] !== $before['student_identity'], 'Administrator identity collided with a participant.');
$assert(!array_key_exists('secret', $before) && !array_key_exists('token', $before), 'Administrator grant must not contain an API secret or token.');
$assert($before['meet_available'] === true, 'Administrator Meet availability was lost.');

// Within the participant window the administrator still joins the exact same canonical room.
$inWindow = $policy->authorize($facts, 'student', $studentPrincipal, $start, $secret);
$assert($policy->authorizeAdministrator($facts, 77, $start, $secret)['room'] === $inWindow['room'], 'Administrator and Student room names diverged.');

// A cancelled/unjoinable resource is still refused outside the window.
$refuse(static fn() => $policy->authorizeAdministrator(array_merge($facts, array('joinable' => false)), 77, $start - 7200, $secret), 'livekit_lesson_not_joinable');
$refuse(static fn() => $policy->authorizeAdministrator($facts, 0, $start, $secret), 'livekit_admin_not_authorized');

// Admin launch ticket is user-bound and verifies; participant roles remain accepted.
$ticket = new \Delnavazan\Platform\Integrations\LiveKit\LiveKitLaunchTicket($secret, static fn(): int => 1000);
$raw = $ticket->issue('administrator', array('kind' => 'lesson', 'lesson_uid' => str_repeat('A', 26), 'schedule_version_uid' => str_repeat('B', 26), 'meet_available' => true));
$claims = $ticket->verify($raw);
$assert($claims['role'] === 'administrator' && $claims['user_id'] === 77, 'Administrator ticket is not user-bound.');
$GLOBALS['dzn_livekit_admin_test_user'] = 78;
try { $ticket->verify($raw); throw new RuntimeException('Administrator ticket was accepted for another user.'); }
catch (InvalidArgumentException $error) { $assert($error->getMessage() === 'livekit_ticket_user_mismatch', 'Administrator ticket user binding failed.'); }
$GLOBALS['dzn_livekit_admin_test_user'] = 77;
$teacherTicket = $ticket->issue('teacher', array('kind' => 'lesson', 'lesson_uid' => str_repeat('A', 26), 'schedule_version_uid' => str_repeat('B', 26)));
$assert($ticket->verify($teacherTicket)['role'] === 'teacher', 'Participant ticket role changed.');

echo "LiveKit administrator classroom unit passed\n";
