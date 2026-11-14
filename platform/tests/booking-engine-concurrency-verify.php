<?php
/**
 * Verify one concurrency level from committed rows and worker artefacts.
 */
if (getenv('DZN_BOOKING_STRESS_RUNTIME') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) exit(1);
require_once __DIR__ . '/booking-engine-stress-fixture.php';
dzn_stress_guard();

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$level = (int) getenv('DZN_STRESS_LEVEL');
$action = (string) getenv('DZN_STRESS_ACTION');
$gate = (string) getenv('DZN_STRESS_GATE');
$state = get_option('dzn_booking_stress_concurrency_v1');
if (!is_array($state)) throw new RuntimeException('Concurrency state missing');

$results = array();
for ($i = 0; $i < $level; $i++) {
    $path = $gate . '/' . $i . '.result';
    if (!is_file($path)) throw new RuntimeException('Missing worker result ' . $i);
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) throw new RuntimeException('Malformed worker result ' . $i);
    $results[] = $decoded;
}
$successes = array_values(array_filter($results, static fn(array $r): bool => !empty($r['ok'])));
$failures = array_values(array_filter($results, static fn(array $r): bool => empty($r['ok'])));
$messages = array_count_values(array_map(static fn(array $r): string => (string) ($r['message'] ?? ''), $failures));
$latencies = array_map(static fn(array $r): float => (float) ($r['elapsed'] ?? 0), $results);
$connections = count(array_unique(array_map(static fn(array $r): int => (int) ($r['db_connection'] ?? 0), $results)));
$payload = array(
    'level' => $level, 'action' => $action,
    'workers' => $level, 'successes' => count($successes), 'failures' => count($failures),
    'failure_messages' => $messages, 'distinct_db_connections' => $connections,
    'latency_from_release' => dzn_stress_latency($latencies),
);

if ($action === 'submit' || $action === 'submit_partial' || $action === 'submit_crash') {
    $fixture = $action === 'submit' ? $state['levels']['submit_' . $level] : ($action === 'submit_partial' ? $state['partial_25'] : $state['crash_5']);
    $studentIds = array_map('intval', $fixture['student_ids']);
    $in = implode(',', array_fill(0, count($studentIds), '%d'));
    $requestIds = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT id FROM {$p}booking_requests WHERE student_id IN ($in) AND selected_intro_course_id=%d ORDER BY id",
        array_merge($studentIds, array((int) $fixture['course_id']))
    )));
    $offerRows = array();
    if ($requestIds) {
        $rin = implode(',', array_fill(0, count($requestIds), '%d'));
        $offerRows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT booking_request_id,teacher_id,state,active_slot FROM {$p}booking_allocation_offers WHERE booking_request_id IN ($rin)",
            $requestIds
        ), ARRAY_A);
    }
    $activeOffers = array_values(array_filter($offerRows, static fn(array $r): bool => (int) $r['active_slot'] === 1 && $r['state'] === 'pending'));
    $referrals = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT c.booking_request_id) FROM {$p}coordination_cases c INNER JOIN {$p}booking_requests r ON r.id=c.booking_request_id WHERE r.selected_intro_course_id=%d AND c.state_reason_code='no_viable_teacher'",
        (int) $fixture['course_id']
    ));
    $confirmations = 0;
    if ($requestIds) {
        $rin = implode(',', array_fill(0, count($requestIds), '%d'));
        $confirmations = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE booking_request_id IN ($rin)", $requestIds));
    }
    dzn_stress_assert(count($failures) === 0, 'Submission workers failed: ' . wp_json_encode($messages));
    dzn_stress_assert(count($requestIds) === $level, 'Expected ' . $level . ' intake rows, got ' . count($requestIds));
    dzn_stress_assert(count($activeOffers) === 1, 'Expected exactly one active Teacher offer, got ' . count($activeOffers));
    dzn_stress_assert($referrals === $level - 1, 'Expected ' . ($level - 1) . ' referrals, got ' . $referrals);
    dzn_stress_assert($confirmations === 0, 'Intake created a confirmation');
    dzn_stress_assert(dzn_stress_teacher_double_bookings() === 0, 'Submission level produced a Teacher double booking');
    dzn_stress_assert(dzn_stress_capacity_violations() === 0, 'Submission level produced overlapping confirmations');
    $payload += array(
        'intake_requests' => count($requestIds), 'active_offers' => count($activeOffers),
        'referrals' => $referrals, 'confirmations' => $confirmations,
        'offer_history' => $offerRows, 'partial_overlap' => $action === 'submit_partial',
    );
} elseif ($action === 'accept') {
    $fixture = $state['levels']['accept_' . $level];
    $requestIds = array_map(static fn(array $r): int => (int) $r['request_id'], $fixture['requests']);
    $in = implode(',', array_fill(0, count($requestIds), '%d'));
    $confirmations = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE booking_request_id IN ($in) AND state NOT IN ('failed','cancelled')", $requestIds));
    $acceptedDeliveries = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_teacher_deliveries WHERE booking_request_id IN ($in) AND state='accepting'", $requestIds));
    $pendingDeliveries = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_teacher_deliveries WHERE booking_request_id IN ($in) AND state='pending_request'", $requestIds));
    dzn_stress_assert(count($successes) === 1, 'Expected exactly one successful acceptance, got ' . count($successes));
    dzn_stress_assert(count($failures) === $level - 1, 'Expected ' . ($level - 1) . ' refused acceptances, got ' . count($failures));
    dzn_stress_assert($confirmations === 1, 'Expected exactly one confirmation, got ' . $confirmations);
    dzn_stress_assert($acceptedDeliveries === 1, 'Expected exactly one accepting delivery, got ' . $acceptedDeliveries);
    dzn_stress_assert($pendingDeliveries === $level - 1, 'Expected the losing deliveries to remain pending, got ' . $pendingDeliveries);
    dzn_stress_assert(dzn_stress_teacher_double_bookings() === 0, 'Acceptance level produced a Teacher double booking');
    dzn_stress_assert(dzn_stress_capacity_violations() === 0, 'Acceptance level produced overlapping confirmations');
    foreach ($failures as $failure) {
        $message = (string) ($failure['message'] ?? '');
        dzn_stress_assert(in_array($message, array('teacher_slot_conflict', 'student_slot_conflict', 'Booking time is no longer available', 'Booking offer expired or reassigned'), true), 'Unexpected losing acceptance error: ' . $message);
    }
    $payload += array(
        'confirmations' => $confirmations, 'accepted_deliveries' => $acceptedDeliveries,
        'pending_losing_deliveries' => $pendingDeliveries,
    );
} elseif ($action === 'idempotent') {
    $fixture = $state['idempotency'];
    $email = (string) $fixture['input']['email'];
    $requestCount = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT r.id) FROM {$p}booking_requests r INNER JOIN {$p}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1 WHERE s.email=%s",
        $email
    ));
    $references = array_unique(array_map(static fn(array $r): string => (string) ($r['reference'] ?? ''), $successes));
    dzn_stress_assert(count($successes) === $level, 'Idempotent replay returned failures: ' . wp_json_encode($messages));
    dzn_stress_assert($requestCount === 1, 'Idempotent replay created ' . $requestCount . ' requests');
    dzn_stress_assert(count($references) === 1, 'Idempotent replay returned different references: ' . wp_json_encode($references));
    $payload += array(
        'requests_created' => $requestCount, 'distinct_references' => count($references),
        'reference' => $references ? reset($references) : null,
    );
} elseif ($action === 'process') {
    $fixture = $state['process_2'];
    $offers = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT id,teacher_id,offer_sequence,state,active_slot FROM {$p}booking_allocation_offers WHERE booking_request_id=%d ORDER BY offer_sequence,id",
        (int) $fixture['request_id']
    ), ARRAY_A);
    $active = array_values(array_filter($offers, static fn(array $row): bool => (int) $row['active_slot'] === 1));
    $cursor = (int) $wpdb->get_var($wpdb->prepare("SELECT last_teacher_id FROM {$p}booking_allocation_cursors WHERE course_id=%d", (int) $fixture['course_id']));
    dzn_stress_assert(count($offers) === 2, 'Expected two offer history rows, got ' . count($offers));
    dzn_stress_assert(count($active) === 1 && (int) $active[0]['offer_sequence'] === 2, 'Expected exactly one active second offer');
    dzn_stress_assert((int) $active[0]['teacher_id'] === (int) $fixture['teacher_ids'][1], 'Expiry did not advance to the second Teacher');
    dzn_stress_assert($cursor === (int) $fixture['teacher_ids'][0], 'Expiry unexpectedly advanced the course cursor');
    $payload += array('offers' => $offers, 'active_offers' => count($active), 'cursor' => $cursor);
} else {
    throw new RuntimeException('Unknown action');
}

echo 'AUDIT_RESULT ' . wp_json_encode($payload + array('scenario' => 'concurrency_' . $action . '_' . $level, 'pass' => true)) . "\n";
