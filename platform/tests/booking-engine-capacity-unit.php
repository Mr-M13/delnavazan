<?php
/**
 * Capacity-interval regressions for the intro-booking stress audit.
 *
 * These are the cases Codex review finding 3 named: adjacent lessons, buffer
 * collisions, overlapping pending offers and confirmed double bookings. They run
 * on a plain PHP 8.3 host because the overlap authority is pure; the runtime
 * suite additionally asserts the same rules against committed MariaDB rows.
 *
 * php platform/tests/booking-engine-capacity-unit.php
 */
require_once __DIR__ . '/booking-engine-capacity-toolkit.php';

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    if ($ok) { echo "ok   $label\n"; return; }
    $failures++;
    echo "FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
};

$confirmed = static fn(int $id, int $teacher, int $request, string $start, string $occupiedEnd): array => array(
    'id' => $id, 'teacher_id' => $teacher, 'booking_request_id' => $request,
    'starts_at_utc' => $start, 'occupied_ends_at_utc' => $occupiedEnd,
);
$offer = static fn(int $id, int $teacher, int $request, string $start, string $occupiedEnd, string $state = 'pending'): array => array(
    'id' => $id, 'teacher_id' => $teacher, 'booking_request_id' => $request,
    'starts_at_utc' => $start, 'occupied_ends_at_utc' => $occupiedEnd, 'state' => $state,
);

// 1. Adjacent lessons: 10:00-10:30 then 10:30-11:00 with no buffer. Half-open
//    intervals touch but do not overlap, so this must never be a violation.
$result = dzn_capacity_violations(
    array($confirmed(1, 7, 100, '2026-12-04 10:00:00', '2026-12-04 10:30:00'),
          $confirmed(2, 7, 101, '2026-12-04 10:30:00', '2026-12-04 11:00:00')),
    array()
);
$check('adjacent lessons are not a capacity violation', $result['hard_count'] === 0, wp_json($result));

// 2. Buffer collision: a 10:00-10:30 lesson occupies capacity until 10:45, so a
//    10:30 start inside that buffer is a genuine overlap. Reading the
//    confirmation's instructional end (10:30) would have missed it.
$result = dzn_capacity_violations(
    array($confirmed(1, 7, 100, '2026-12-04 10:00:00', '2026-12-04 10:45:00'),
          $confirmed(2, 7, 101, '2026-12-04 10:30:00', '2026-12-04 11:00:00')),
    array()
);
$check('a lesson starting inside the previous buffer is a violation', count($result['confirmed_overlaps']) === 1, wp_json($result));
$check('the buffer collision is reported against the authoritative occupied end',
    ($result['confirmed_overlaps'][0]['a']['occupied_ends_at_utc'] ?? '') === '2026-12-04 10:45:00', wp_json($result['confirmed_overlaps'] ?? array()));

// 3. Confirmed double booking: identical intervals for one Teacher.
$result = dzn_capacity_violations(
    array($confirmed(1, 7, 100, '2026-12-04 10:00:00', '2026-12-04 10:45:00'),
          $confirmed(2, 7, 101, '2026-12-04 10:00:00', '2026-12-04 10:45:00')),
    array()
);
$check('an exact confirmed double booking is a violation', count($result['confirmed_overlaps']) === 1, wp_json($result));

// 4. The same instant at two different Teachers is not a capacity violation.
$result = dzn_capacity_violations(
    array($confirmed(1, 7, 100, '2026-12-04 10:00:00', '2026-12-04 10:45:00'),
          $confirmed(2, 9, 101, '2026-12-04 10:00:00', '2026-12-04 10:45:00')),
    array()
);
$check('two Teachers booked at the same instant is not a violation', $result['hard_count'] === 0, wp_json($result));

// 5. Overlapping pending offers for one Teacher over-commit that Teacher.
$result = dzn_capacity_violations(
    array(),
    array($offer(1, 7, 100, '2026-12-04 10:00:00', '2026-12-04 10:45:00'),
          $offer(2, 7, 101, '2026-12-04 10:30:00', '2026-12-04 11:15:00'))
);
$check('overlapping pending offers for one Teacher are a violation', count($result['pending_overlaps']) === 1, wp_json($result));

// 6. A live offer that still holds capacity a confirmed booking occupies.
$result = dzn_capacity_violations(
    array($confirmed(1, 7, 100, '2026-12-04 10:00:00', '2026-12-04 10:45:00')),
    array($offer(2, 7, 101, '2026-12-04 10:15:00', '2026-12-04 11:00:00'))
);
$check('a pending offer overlapping a confirmed booking is a violation', count($result['pending_over_confirmed']) === 1, wp_json($result));

// 7. Legitimate competing offers: one student slot held open at two Teachers.
//    Informational, never a capacity violation.
$result = dzn_capacity_violations(
    array(),
    array($offer(1, 7, 100, '2026-12-04 10:00:00', '2026-12-04 10:45:00'),
          $offer(2, 9, 100, '2026-12-04 10:00:00', '2026-12-04 10:45:00'))
);
$check('competing offers across Teachers are not a capacity violation', $result['hard_count'] === 0, wp_json($result));
$check('competing offers are still reported for the audit', count($result['competing_pending']) === 1, wp_json($result));

// 8. Buffer-aware adjacency for offers: 10:00-10:45 occupied then 10:45-11:30 is
//    adjacent and allowed; 10:44 would not be.
$adjacent = dzn_capacity_violations(
    array(),
    array($offer(1, 7, 100, '2026-12-04 10:00:00', '2026-12-04 10:45:00'),
          $offer(2, 7, 101, '2026-12-04 10:45:00', '2026-12-04 11:30:00'))
);
$check('buffer-aware adjacency for offers is allowed', $adjacent['hard_count'] === 0, wp_json($adjacent));
$inside = dzn_capacity_violations(
    array(),
    array($offer(1, 7, 100, '2026-12-04 10:00:00', '2026-12-04 10:45:00'),
          $offer(2, 7, 101, '2026-12-04 10:44:00', '2026-12-04 11:29:00'))
);
$check('one minute inside the buffer is a violation', $inside['hard_count'] === 1, wp_json($inside));

// 9. The same booking seen twice (offer retained beside its confirmation) counts
//    once, so a lifecycle transition cannot fake a conflict.
$result = dzn_capacity_violations(
    array($confirmed(1, 7, 100, '2026-12-04 10:00:00', '2026-12-04 10:45:00')),
    array($offer(9, 7, 100, '2026-12-04 10:00:00', '2026-12-04 10:45:00'))
);
$check('one booking is not counted twice across lifecycle states', $result['hard_count'] === 0, wp_json($result));

// 10. An unusable interval must never be silently ignored.
$result = dzn_capacity_violations(array(), array(array('id' => 1, 'teacher_id' => 7, 'booking_request_id' => 100, 'starts_at_utc' => '', 'occupied_ends_at_utc' => '')));
$check('an offer without a usable interval is reported, not dropped', $result['orphan_pending'] === 1 && $result['hard_count'] === 1, wp_json($result));

// 11. Malformed confirmed rows are reported too.
$result = dzn_capacity_violations(array($confirmed(1, 7, 100, '2026-12-04 10:00:00', '2026-12-04 10:00:00')), array());
$check('a zero-length confirmed interval is reported', $result['malformed_confirmed'] === 1 && $result['hard_count'] === 1, wp_json($result));

if ($failures > 0) {
    fwrite(STDERR, "Booking capacity unit failed: $failures assertion(s)\n");
    exit(1);
}
echo "Booking capacity unit passed\n";

function wp_json(mixed $value): string { return json_encode($value, JSON_UNESCAPED_SLASHES) ?: 'null'; }
