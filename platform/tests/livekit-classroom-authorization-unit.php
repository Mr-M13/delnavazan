<?php
$root = dirname(__DIR__);
require_once $root . '/src/Integrations/LiveKit/LiveKitClassroomGrantPolicy.php';
require_once $root . '/src/Integrations/LiveKit/LiveKitLaunchTicket.php';
require_once $root . '/src/Integrations/LiveKit/LiveKitWebhookVerifier.php';
require_once $root . '/src/Integrations/LiveKit/LiveKitRoomServiceClient.php';
require_once $root . '/src/Integrations/LiveKit/LiveKitJwtIssuer.php';
require_once $root . '/src/Integrations/LiveKit/LiveKitConfiguration.php';
require_once $root . '/src/Integrations/LiveKit/LiveKitMeetFallbackService.php';

$GLOBALS['dzn_livekit_test_user'] = 42;
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return (int)($GLOBALS['dzn_livekit_test_user'] ?? 0); }
}
$assert = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$refuse = static function (callable $fn, string $reason) use ($assert): void {
    try { $fn(); throw new RuntimeException('Expected refusal: ' . $reason); }
    catch (InvalidArgumentException $error) { $assert($error->getMessage() === $reason, 'Unexpected refusal: ' . $error->getMessage() . ' expected ' . $reason); }
};

$secret = 'unit-test-only-server-salt';
$policy = new \Delnavazan\Platform\Integrations\LiveKit\LiveKitClassroomGrantPolicy();
$now = strtotime('2026-10-31 10:00:00 UTC');
$facts = array(
    'kind' => 'lesson',
    'resource_id' => 101,
    'resource_uid' => str_repeat('L', 26),
    'schedule_key' => str_repeat('S', 26),
    'requested_schedule_key' => str_repeat('S', 26),
    'teacher_id' => 7,
    'student_id' => 9,
    'teacher_name' => 'مدرس نمونه',
    'student_name' => 'هنرجوی نمونه',
    'course_name' => 'سه‌تار',
    'starts_at_utc' => '2026-10-31 10:00:00',
    'ends_at_utc' => '2026-10-31 11:00:00',
    'joinable' => true,
    'meet_available' => true,
);
$teacherPrincipal = array('kind' => 'teacher', 'id' => 7, 'uid' => 'T-UID', 'display_reference' => 'T-1', 'linked_at' => '2026-01-01 00:00:00');
$studentPrincipal = array('kind' => 'student', 'id' => 9, 'uid' => 'S-UID', 'display_reference' => 'S-1', 'linked_at' => '2026-01-01 00:00:00');

$teacher = $policy->authorize($facts, 'teacher', $teacherPrincipal, $now, $secret);
$student = $policy->authorize($facts, 'student', $studentPrincipal, $now, $secret);
$assert($teacher['identity'] !== $student['identity'], 'Teacher and Student must have distinct identities.');
$assert($teacher['room'] === $student['room'], 'Both authorised participants must join the same lesson room.');
$assert($teacher['peer_identity'] === $student['identity'] && $student['peer_identity'] === $teacher['identity'], 'Peer identity binding is incorrect.');
$assert($teacher['display_name'] === 'مدرس نمونه' && $student['display_name'] === 'هنرجوی نمونه', 'Display names are not role-correct.');
$assert($teacher['course_name'] === 'سه‌تار' && $teacher['meet_available'] === true, 'Course or Meet fallback availability was lost.');
$assert(preg_match('/^dzn-[A-Za-z0-9_-]{40}$/D', $teacher['room']) === 1, 'Room name is not opaque and bounded.');
$assert(preg_match('/^[ts]-[A-Za-z0-9_-]{24}$/D', $teacher['identity']) === 1, 'Participant identity is not opaque and bounded.');

// Same participant reconnecting or opening a duplicate tab receives the same identity; LiveKit
// replaces the prior connection instead of admitting a third person.
$again = $policy->authorize($facts, 'teacher', $teacherPrincipal, $now + 30, $secret);
$assert($again['identity'] === $teacher['identity'] && $again['room'] === $teacher['room'], 'Reconnect produced a new participant identity.');

// A third authenticated principal cannot enter the lesson.
$third = array('kind' => 'teacher', 'id' => 8, 'uid' => 'T-2', 'display_reference' => 'T-2', 'linked_at' => '2026-01-01 00:00:00');
$refuse(static fn() => $policy->authorize($facts, 'teacher', $third, $now, $secret), 'livekit_participant_not_authorized');
$wrongRole = array('kind' => 'student', 'id' => 7, 'uid' => 'S-X', 'display_reference' => 'S-X', 'linked_at' => '2026-01-01 00:00:00');
$refuse(static fn() => $policy->authorize($facts, 'student', $wrongRole, $now, $secret), 'livekit_participant_not_authorized');

// Cancelled/completed, wrong lesson schedule (rescheduled), replacement, and window boundaries.
$refuse(static fn() => $policy->authorize(array_merge($facts, array('joinable' => false)), 'teacher', $teacherPrincipal, $now, $secret), 'livekit_lesson_not_joinable');
$refuse(static fn() => $policy->authorize(array_merge($facts, array('requested_schedule_key' => str_repeat('X', 26))), 'teacher', $teacherPrincipal, $now, $secret), 'livekit_stale_schedule');
$refuse(static fn() => $policy->authorize($facts, 'teacher', $teacherPrincipal, $now - 3601, $secret), 'livekit_outside_window');
$refuse(static fn() => $policy->authorize($facts, 'teacher', $teacherPrincipal, $now + 10801, $secret), 'livekit_outside_window');
$replacement = $policy->authorize(array_merge($facts, array('is_replacement' => true)), 'student', $studentPrincipal, $now, $secret);
$assert($replacement['is_replacement'] === true, 'Replacement lesson flag was lost.');
$assert($policy->authorize(array_merge($facts, array('meet_available' => false)), 'student', $studentPrincipal, $now, $secret)['meet_available'] === false, 'Missing Meet fallback was not propagated.');

// Launch ticket: user-bound, signed, replayable for reconnects, and expiring.
$ticket = new \Delnavazan\Platform\Integrations\LiveKit\LiveKitLaunchTicket($secret, static fn(): int => 1000);
$raw = $ticket->issue('teacher', array('kind' => 'lesson', 'lesson_uid' => str_repeat('A', 26), 'schedule_version_uid' => str_repeat('B', 26), 'meet_available' => true));
$claims = $ticket->verify($raw);
$assert($claims['role'] === 'teacher' && $claims['user_id'] === 42 && $claims['meet_available'] === true, 'Ticket claims are incomplete.');
$assert($ticket->verify($raw)['lesson_uid'] === str_repeat('A', 26), 'Ticket is not reusable for a legitimate reconnect.');
try { $ticket->verify($raw . 'x'); throw new RuntimeException('Tampered ticket was accepted.'); }
catch (InvalidArgumentException $error) { $assert($error->getMessage() === 'livekit_ticket_invalid', 'Tampered ticket did not fail closed.'); }
$GLOBALS['dzn_livekit_test_user'] = 43;
try { $ticket->verify($raw); throw new RuntimeException('Ticket was accepted for another WordPress user.'); }
catch (InvalidArgumentException $error) { $assert($error->getMessage() === 'livekit_ticket_user_mismatch', 'Ticket user binding did not fail closed.'); }
$GLOBALS['dzn_livekit_test_user'] = 42;
$expired = new \Delnavazan\Platform\Integrations\LiveKit\LiveKitLaunchTicket($secret, static fn(): int => 1000 + \Delnavazan\Platform\Integrations\LiveKit\LiveKitLaunchTicket::TTL_SECONDS + 1);
try { $expired->verify($raw); throw new RuntimeException('Expired ticket was accepted.'); }
catch (InvalidArgumentException $error) { $assert($error->getMessage() === 'livekit_ticket_expired', 'Expired ticket did not fail closed.'); }

// Webhook JWT signature and body digest.
$webhookToken = static function (array $payload, string $apiSecret): string {
    $enc = static fn(string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    $header = $enc(json_encode(array('alg' => 'HS256', 'typ' => 'JWT')));
    $body = $enc(json_encode($payload));
    return $header . '.' . $body . '.' . $enc(hash_hmac('sha256', $header . '.' . $body, $apiSecret, true));
};
$verifier = new \Delnavazan\Platform\Integrations\LiveKit\LiveKitWebhookVerifier();
$body = '{"event":"participant_joined"}';
$signed = $webhookToken(array('iss' => 'devkey', 'exp' => 2000, 'sha256' => hash('sha256', $body)), 'not-a-real-secret');
$assert($verifier->verify('Bearer ' . $signed, $body, 'devkey', 'not-a-real-secret', 1000)['iss'] === 'devkey', 'Valid webhook signature was rejected.');
try { $verifier->verify('Bearer ' . $signed, $body . ' ', 'devkey', 'not-a-real-secret', 1000); throw new RuntimeException('Webhook body digest was not verified.'); }
catch (InvalidArgumentException $error) { $assert($error->getMessage() === 'livekit_webhook_unauthorized', 'Webhook body tampering did not fail closed.'); }
try { $verifier->verify('Bearer ' . $signed, $body, 'devkey', 'wrong-secret', 1000); throw new RuntimeException('Webhook signature was not verified.'); }
catch (InvalidArgumentException $error) { $assert($error->getMessage() === 'livekit_webhook_unauthorized', 'Webhook signature failure did not fail closed.'); }

// RoomService enforcement: max_participants=2 must be created and verified.
$calls = array();
$room = str_repeat('r', 12);
$transport = static function (string $url, array $body, array $headers) use (&$calls, $room): array {
    $calls[] = array($url, $body);
    if (str_ends_with($url, '/ListRooms')) {
        $exists = count(array_filter($calls, static fn(array $call): bool => str_ends_with($call[0], '/CreateRoom'))) > 0;
        return array('status' => 200, 'body' => json_encode(array('rooms' => $exists ? array(array('name' => $body['names'][0], 'max_participants' => 2)) : array())));
    }
    return array('status' => 200, 'body' => json_encode(array('name' => $room, 'max_participants' => 2)));
};
$client = new \Delnavazan\Platform\Integrations\LiveKit\LiveKitRoomServiceClient(array('api_url' => 'https://rtc.example.test', 'key' => 'devkey', 'secret' => 'not-a-real-secret'), null, $transport, static fn(): int => 1000);
$client->ensureRoom('dzn-' . $room);
$assert(count(array_filter($calls, static fn(array $call): bool => str_ends_with($call[0], '/CreateRoom'))) === 1, 'Room was not created once.');
$create = array_values(array_filter($calls, static fn(array $call): bool => str_ends_with($call[0], '/CreateRoom')))[0][1];
$assert((int)$create['max_participants'] === 2, 'Room was not capped at two participants.');
$assert(str_contains($create['name'], 'dzn-'), 'Room name namespace was lost.');
$assert(!str_contains(json_encode($create), 'not-a-real-secret'), 'API secret leaked into a RoomService request.');

$failing = new \Delnavazan\Platform\Integrations\LiveKit\LiveKitRoomServiceClient(
    array('api_url' => 'https://rtc.example.test', 'key' => 'devkey', 'secret' => 'not-a-real-secret'),
    null,
    static fn(): array => array('status' => 503, 'body' => '{}'),
    static fn(): int => 1000
);
try { $failing->ensureRoom('dzn-' . $room); throw new RuntimeException('LiveKit outage did not fail closed.'); }
catch (RuntimeException $error) { $assert($error->getMessage() === 'livekit_room_api_unavailable', 'LiveKit outage produced the wrong failure.'); }

$meet = new \Delnavazan\Platform\Integrations\LiveKit\LiveKitMeetFallbackService();
$assert($meet->bookingAvailable(12, 'active') === true && $meet->bookingAvailable(null, '') === false, 'Meet fallback availability is incorrect.');

echo "LiveKit classroom authorization unit passed\n";
