<?php
/**
 * Synthetic fixture helpers for the introductory-booking engine stress audit.
 *
 * Safety: this file is a library, not a test entry point. Every caller must pass
 * the runtime guard below. It only runs on a disposable WordPress install whose
 * environment type is local/development and whose home URL is loopback.
 *
 * All rows are synthetic and tagged with a caller-supplied suffix. No provider,
 * payment, mail, WhatsApp or SMS call is made.
 */

use Delnavazan\Platform\Core\Application\BookingTeacherDeliveryService;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestRepository;
use Delnavazan\Platform\Core\Support\Identifier;

require_once __DIR__ . '/booking-engine-capacity-toolkit.php';

function dzn_stress_guard(): void {
    if (!defined('WP_CLI') || !WP_CLI) {
        fwrite(STDERR, "Refused: WP-CLI is required.\n");
        exit(1);
    }
    if (!in_array(wp_get_environment_type(), array('local', 'development'), true)) {
        fwrite(STDERR, "Refused: environment must be local or development.\n");
        exit(1);
    }
    $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    if (!in_array($host, array('127.0.0.1', 'localhost', '::1'), true)) {
        fwrite(STDERR, "Refused: site URL is not loopback (" . (string) $host . ").\n");
        exit(1);
    }
}

function dzn_stress_suffix(string $label): string {
    return strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label)) . '-' . strtolower(wp_generate_password(8, false, false));
}

/**
 * A local calendar date that is always far enough ahead to be allocatable.
 *
 * Initial offers require the start to be beyond the 25-hour lead time. A fixed
 * calendar date therefore turns every scenario that passes through allocation
 * into a time bomb: it starts failing for calendar reasons rather than for an
 * application regression. Offsets of three days or more keep the gap above
 * 48 hours whatever the current wall-clock time, and the weekday is irrelevant
 * because these fixtures declare availability on every day.
 *
 * Pure DST and Jalali *conversion* assertions keep their fixed dates; only
 * dates that flow through allocation are derived here.
 */
function dzn_stress_allocation_date(int $offsetDays = 4, string $timezone = 'UTC'): string {
    if ($offsetDays < 3) {
        throw new RuntimeException('An allocation date must be at least 3 days ahead to clear the 25-hour lead time, got +' . $offsetDays . ' days');
    }
    return (new DateTimeImmutable('today', new DateTimeZone($timezone)))
        ->modify('+' . $offsetDays . ' days')->format('Y-m-d');
}

/**
 * The next real DST transition date in a zone, so the DST cases stay valid in
 * later years instead of pinning one calendar date.
 *
 * Returns the local date on which the offset moves, e.g. for
 * America/New_York in 2027 the 2027-03-14 spring-forward or the 2027-11-07
 * fall-back. Fails closed if the zone has no such transition in the window,
 * rather than silently testing a non-transition day.
 */
function dzn_stress_dst_transition_date(string $timezone, bool $springForward, ?string $afterUtc = null): string {
    $zone = new DateTimeZone($timezone);
    $after = new DateTimeImmutable($afterUtc ?? 'now', new DateTimeZone('UTC'));
    $from = $after->getTimestamp();
    $transitions = $zone->getTransitions($from, $from + 86400 * 900);
    if (!is_array($transitions) || count($transitions) < 2) {
        throw new RuntimeException('No DST transitions available for ' . $timezone);
    }
    $previous = null;
    foreach ($transitions as $transition) {
        $offset = (int) $transition['offset'];
        if ($previous !== null) {
            $delta = $offset - $previous;
            if (($springForward && $delta > 0) || (!$springForward && $delta < 0)) {
                return (new DateTimeImmutable('@' . (int) $transition['ts']))->setTimezone($zone)->format('Y-m-d');
            }
        }
        $previous = $offset;
    }
    throw new RuntimeException('No ' . ($springForward ? 'spring-forward' : 'fall-back') . ' transition found for ' . $timezone);
}


function dzn_stress_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('ASSERT: ' . $message);
}

function dzn_stress_near(array $values, int $a, int $b): bool {
    return abs($a - $b) <= 1;
}

/** Create one instrument plus its active introductory course. */
function dzn_stress_course(string $suffix, string $key, string $nameEn, string $nameFa = ''): array {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    $now = gmdate('Y-m-d H:i:s');
    $ok = $wpdb->insert($p . 'instruments', array(
        'uid' => Identifier::uid(), 'reference_code' => 'DZN-AI-' . strtoupper($key) . '-' . strtoupper(substr(md5($suffix), 0, 8)),
        'slug' => 'audit-' . $key . '-' . $suffix, 'name_fa' => $nameFa !== '' ? $nameFa : $nameEn,
        'name_en' => $nameEn, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ));
    if ($ok === false) throw new RuntimeException('Instrument insert failed: ' . $wpdb->last_error);
    $instrument = (int) $wpdb->insert_id;
    $ok = $wpdb->insert($p . 'courses', array(
        'uid' => Identifier::uid(), 'reference_code' => 'DZN-AC-' . strtoupper($key) . '-' . strtoupper(substr(md5($suffix), 0, 8)),
        'instrument_id' => $instrument, 'name_fa' => ($nameFa !== '' ? $nameFa : $nameEn) . ' Intro',
        'name_en' => $nameEn . ' Intro', 'course_type' => 'introductory', 'status' => 'active',
        'default_duration_minutes' => 30, 'default_buffer_minutes' => 15,
        'created_at' => $now, 'updated_at' => $now,
    ));
    if ($ok === false) throw new RuntimeException('Course insert failed: ' . $wpdb->last_error);
    return array('instrument_id' => $instrument, 'course_id' => (int) $wpdb->insert_id);
}

/**
 * Create one active ready Teacher with a loopback synthetic WordPress user.
 *
 * Options:
 *  - timezone, courses (list of course ids), accepting_state
 *  - days: list of weekday numbers (1=Mon..7=Sun) or 'all'
 *  - ranges: list of [start,end] local wall times applied to every selected day
 *  - leave: true to add an open-ended temporary unavailability from today
 *  - prior_offers: accepted/unfinished historical offer count is created by the caller
 */
function dzn_stress_teacher(string $suffix, string $label, array $courses, array $opts = array()): array {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    $now = gmdate('Y-m-d H:i:s');
    $timezone = (string) ($opts['timezone'] ?? 'UTC');
    $accepting = (string) ($opts['accepting_state'] ?? 'accepting');
    $login = 'aud-' . $suffix . '-' . strtolower(preg_replace('/[^a-z0-9]+/i', '', $label)) . '-' . wp_rand(1000, 9999);
    $email = $login . '@example.invalid';
    $userId = wp_insert_user(array(
        'user_login' => $login, 'user_pass' => wp_generate_password(32, true, true),
        'user_email' => $email, 'role' => 'subscriber', 'display_name' => 'Synthetic ' . $label,
    ));
    if (is_wp_error($userId)) throw new RuntimeException('Synthetic Teacher user failed: ' . $userId->get_error_message());
    $userId = (int) $userId;

    static $teacherSequence = 0; $teacherSequence++;
    $ok = $wpdb->insert($p . 'teachers', array(
        'uid' => Identifier::uid(), 'reference_code' => 'DZN-AT-' . strtoupper(substr(md5($suffix), 0, 8)) . '-' . $teacherSequence,
        'status' => 'active', 'display_name' => 'Synthetic ' . $label, 'english_name' => 'Synthetic ' . $label,
        'timezone' => $timezone, 'timezone_source' => 'synthetic', 'locale' => 'en',
        'email' => $email, 'wordpress_user_id' => $userId, 'created_at' => $now, 'updated_at' => $now,
    ));
    if ($ok === false) throw new RuntimeException('Teacher insert failed: ' . $wpdb->last_error);
    $teacherId = (int) $wpdb->insert_id;

    $wpdb->insert($p . 'teacher_principal_links', array(
        'teacher_id' => $teacherId, 'wordpress_user_id' => $userId, 'status' => 'active',
        'linked_at' => $now, 'linked_by' => 1,
    ));
    $wpdb->insert($p . 'teacher_onboarding_states', array(
        'teacher_id' => $teacherId, 'state' => 'active', 'readiness_state' => 'ready',
        'profile_state' => 'complete', 'availability_state' => 'complete', 'agreement_state' => 'not_required',
        'activated_at' => $now, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        'created_by' => 1, 'updated_by' => 1,
    ));
    $wpdb->insert($p . 'teacher_accepting_states', array(
        'teacher_id' => $teacherId, 'state' => $accepting, 'version' => 1,
        'reason_code' => 'synthetic_audit', 'created_at' => $now, 'updated_at' => $now,
        'created_by' => 1, 'updated_by' => 1,
    ));
    foreach ($courses as $courseId) {
        $wpdb->insert($p . 'teacher_course_eligibilities', array(
            'teacher_id' => $teacherId, 'course_id' => (int) $courseId, 'status' => 'active',
            'version' => 1, 'reason_code' => 'synthetic_audit', 'created_at' => $now,
            'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1,
        ));
    }
    $wpdb->insert($p . 'teacher_availability_profiles', array(
        'teacher_id' => $teacherId, 'timezone' => $timezone, 'status' => 'active', 'version' => 1,
        'reason_code' => 'synthetic_audit', 'created_at' => $now, 'updated_at' => $now,
        'created_by' => 1, 'updated_by' => 1,
    ));
    $profileId = (int) $wpdb->insert_id;

    $days = $opts['days'] ?? 'all';
    if ($days === 'all') $days = array(1, 2, 3, 4, 5, 6, 7);
    $ranges = $opts['ranges'] ?? array(array('00:00:00', '24:00:00'));
    foreach ($days as $weekday) {
        foreach ($ranges as $range) {
            $wpdb->insert($p . 'teacher_availability_rules', array(
                'profile_id' => $profileId, 'teacher_id' => $teacherId, 'instrument_id' => $opts['instrument_id'] ?? null,
                'weekday' => (int) $weekday, 'local_start_time' => $range[0], 'local_end_time' => $range[1],
                'state' => (string) ($opts['state'] ?? 'preferred'), 'timezone' => $timezone, 'status' => 'active',
                'version' => 1, 'reason_code' => 'synthetic_audit', 'created_at' => $now, 'updated_at' => $now,
                'created_by' => 1, 'updated_by' => 1,
            ));
        }
    }
    if (!empty($opts['leave'])) {
        $wpdb->insert($p . 'teacher_availability_exceptions', array(
            'profile_id' => $profileId, 'teacher_id' => $teacherId,
            'local_date' => (new DateTimeImmutable('today', new DateTimeZone($timezone)))->format('Y-m-d'),
            'all_day' => 1, 'local_start_time' => '00:00:00', 'local_end_time' => '00:00:00',
            'state' => 'blocked', 'timezone' => $timezone, 'status' => 'active', 'version' => 1,
            'reason_code' => 'temporary_unavailability_open', 'created_at' => $now, 'updated_at' => $now,
            'created_by' => 1, 'updated_by' => 1,
        ));
    }
    return array('teacher_id' => $teacherId, 'user_id' => $userId, 'timezone' => $timezone, 'profile_id' => $profileId);
}

function dzn_stress_student(string $suffix, string $label, string $timezone = 'UTC'): int {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    $now = gmdate('Y-m-d H:i:s');
    static $studentSequence = 0; $studentSequence++;
    $ok = $wpdb->insert($p . 'students', array(
        'uid' => Identifier::uid(), 'reference_code' => 'DZN-AS-' . strtoupper(substr(md5($suffix), 0, 8)) . '-' . $studentSequence,
        'status' => 'active', 'display_name' => 'Synthetic ' . $label,
        'email' => 'aud-' . $suffix . '-' . strtolower(preg_replace('/[^a-z0-9]+/i', '', $label)) . wp_rand(1000, 9999) . '@example.invalid',
        'phone' => '+61400000000', 'country_code' => 'AU', 'city' => 'Brisbane', 'timezone' => $timezone,
        'timezone_source' => 'synthetic', 'locale' => 'en', 'calendar_preference' => 'auto',
        'created_at' => $now, 'updated_at' => $now,
    ));
    if ($ok === false) throw new RuntimeException('Student insert failed: ' . $wpdb->last_error);
    return (int) $wpdb->insert_id;
}

/** Convert one UTC start plus duration/buffer into persisted requested-time facts. */
function dzn_stress_time(string $startUtc, int $duration = 30, int $buffer = 15, string $timezone = 'UTC'): array {
    $start = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));
    $instructionalEnd = $start->modify('+' . $duration . ' minutes');
    $occupiedEnd = $instructionalEnd->modify('+' . $buffer . ' minutes');
    $local = $start->setTimezone(new DateTimeZone($timezone));
    return array(
        'starts_at_utc' => $start->format('Y-m-d H:i:s'),
        'instructional_ends_at_utc' => $instructionalEnd->format('Y-m-d H:i:s'),
        'occupied_ends_at_utc' => $occupiedEnd->format('Y-m-d H:i:s'),
        'local_date' => $local->format('Y-m-d'),
        'local_start_time' => $local->format('H:i:s'),
        'timezone' => $timezone,
        'instructional_duration_minutes' => $duration,
        'buffer_minutes' => $buffer,
    );
}

/**
 * Create one synthetic submitted/unresolved request with contact snapshot and
 * ordered requested-time rows. No allocation is performed here.
 *
 * @param list<array> $times output of dzn_stress_time()
 */
function dzn_stress_request(int $studentId, int $instrumentId, int $courseId, array $times, string $suffix, string $label = 'request', int $createdOffsetSeconds = 0): array {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    static $referenceSequence = 0; $referenceSequence++;
    $now = gmdate('Y-m-d H:i:s', time() + $createdOffsetSeconds);
    $ok = $wpdb->insert($p . 'booking_requests', array(
        'uid' => Identifier::uid(), 'reference_code' => 'REQ-AUD-' . strtoupper(substr(md5($suffix), 0, 8)) . '-' . $referenceSequence,
        'student_id' => $studentId, 'requested_instrument_id' => $instrumentId,
        'selected_intro_course_id' => $courseId, 'lifecycle_status' => 'submitted',
        'resolution_state' => 'unresolved',
        'retention_due_at' => gmdate('Y-m-d H:i:s', strtotime('+24 months', strtotime($now . ' UTC'))),
        'version' => 1, 'created_at' => $now, 'updated_at' => $now,
    ));
    if ($ok === false) throw new RuntimeException('Request insert failed: ' . $wpdb->last_error);
    $requestId = (int) $wpdb->insert_id;
    $wpdb->insert($p . 'booking_request_contact_snapshots', array(
        'booking_request_id' => $requestId, 'snapshot_sequence' => 1,
        'full_name' => 'Synthetic Student', 'email' => 'aud-' . $suffix . '-' . $requestId . '@example.invalid',
        'mobile' => '+61400123456', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'UTC',
        'communication_language' => 'en', 'whatsapp_same_as_mobile' => 1, 'whatsapp_number' => '+61400123456',
        'privacy_notice_version' => '2026-09-05', 'privacy_notice_accepted' => 1,
        'privacy_notice_accepted_at' => $now, 'submission_source' => 'synthetic_booking_audit',
        'created_at' => $now,
    ));
    $ids = array();
    foreach ($times as $sequence => $time) {
        $wpdb->insert($p . 'booking_request_requested_times', array(
            'booking_request_id' => $requestId, 'sequence_number' => $sequence + 1,
            'local_date' => $time['local_date'], 'local_start_time' => $time['local_start_time'],
            'timezone' => $time['timezone'], 'starts_at_utc' => $time['starts_at_utc'],
            'instructional_ends_at_utc' => $time['instructional_ends_at_utc'],
            'occupied_ends_at_utc' => $time['occupied_ends_at_utc'],
            'instructional_duration_minutes' => $time['instructional_duration_minutes'],
            'buffer_minutes' => $time['buffer_minutes'], 'version' => 1, 'created_at' => $now,
        ));
        $ids[] = (int) $wpdb->insert_id;
    }
    return array('request_id' => $requestId, 'time_ids' => $ids, 'reference' => 'REQ-AUD-' . $requestId, 'uid' => (string) $wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}booking_requests WHERE id=%d", $requestId)));
}

/**
 * Run the real allocation authority inside a READ COMMITTED transaction.
 * Returns null when no viable Teacher was found (referral path).
 */
function dzn_stress_allocate(int $requestId, int $courseId, array $times, ?string $now = null): ?array {
    $now = $now ?? gmdate('Y-m-d H:i:s');
    $repo = new BookingRequestRepository();
    $repo->begin();
    try {
        $result = (new BookingTeacherDeliveryService())->deliver($requestId, $courseId, $times, $now);
        $repo->commit();
        return $result;
    } catch (Throwable $error) {
        $repo->rollback();
        throw $error;
    }
}

function dzn_stress_times_for_allocation(int $requestId): array {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    $rows = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT id,sequence_number,starts_at_utc,occupied_ends_at_utc FROM {$p}booking_request_requested_times WHERE booking_request_id=%d ORDER BY sequence_number,id",
        $requestId
    ), ARRAY_A);
    return array_map(static fn(array $row): array => array(
        'id' => (int) $row['id'], 'sequence' => (int) $row['sequence_number'],
        'starts_at_utc' => (string) $row['starts_at_utc'],
        'occupied_ends_at_utc' => (string) $row['occupied_ends_at_utc'],
    ), $rows);
}

function dzn_stress_request_times(int $requestId): array {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    return array_map(static fn(array $row): array => array(
        'id' => (int) $row['id'], 'starts_at_utc' => (string) $row['starts_at_utc'],
        'occupied_ends_at_utc' => (string) $row['occupied_ends_at_utc'],
    ), (array) $wpdb->get_results($wpdb->prepare(
        "SELECT id,starts_at_utc,occupied_ends_at_utc FROM {$p}booking_request_requested_times WHERE booking_request_id=%d ORDER BY sequence_number,id",
        $requestId
    ), ARRAY_A));
}

/** Distinct initial-offer counts for one course, optionally across a request-id range. */
function dzn_stress_offer_distribution(int $courseId, int $fromRequestId = 0, int $toRequestId = 0): array {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    $where = "o.offer_sequence=1 AND r.selected_intro_course_id=%d";
    $args = array($courseId);
    if ($fromRequestId > 0) { $where .= ' AND r.id>=%d'; $args[] = $fromRequestId; }
    if ($toRequestId > 0) { $where .= ' AND r.id<=%d'; $args[] = $toRequestId; }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT o.teacher_id, COUNT(*) total FROM {$p}booking_allocation_offers o INNER JOIN {$p}booking_requests r ON r.id=o.booking_request_id WHERE {$where} GROUP BY o.teacher_id ORDER BY o.teacher_id",
        $args
    ), ARRAY_A);
    $result = array();
    foreach ((array) $rows as $row) $result[(int) $row['teacher_id']] = (int) $row['total'];
    return $result;
}

/** Initial-offer sequence for one course, in allocation/request order. */
function dzn_stress_offer_sequence(int $courseId, int $fromRequestId = 0): array {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    $where = 'o.offer_sequence=1 AND r.selected_intro_course_id=%d';
    $args = array($courseId);
    if ($fromRequestId > 0) { $where .= ' AND r.id>=%d'; $args[] = $fromRequestId; }
    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT o.teacher_id FROM {$p}booking_allocation_offers o INNER JOIN {$p}booking_requests r ON r.id=o.booking_request_id WHERE {$where} ORDER BY r.id",
        $args
    )));
}

function dzn_stress_fairness(array $counts): array {
    $values = array_values($counts);
    if (!$values) return array('n' => 0, 'min' => 0, 'max' => 0, 'spread' => 0, 'gini' => null, 'cv' => null);
    $n = count($values); $sum = array_sum($values); $min = min($values); $max = max($values);
    $mean = $sum / $n;
    $variance = 0.0; foreach ($values as $v) $variance += ($v - $mean) ** 2; $variance /= $n;
    $absolute = 0.0;
    foreach ($values as $a) foreach ($values as $b) $absolute += abs($a - $b);
    $gini = $sum > 0 ? $absolute / (2 * $n * $sum) : 0.0;
    return array('n' => $n, 'min' => $min, 'max' => $max, 'spread' => $max - $min, 'gini' => round($gini, 6), 'cv' => $sum > 0 ? round(sqrt($variance) / $mean, 6) : null);
}

function dzn_stress_active_offer(int $requestId): ?object {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$p}booking_allocation_offers WHERE booking_request_id=%d AND active_slot=1",
        $requestId
    )) ?: null;
}

function dzn_stress_delivery(int $requestId): ?object {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$p}booking_request_teacher_deliveries WHERE booking_request_id=%d",
        $requestId
    )) ?: null;
}

function dzn_stress_count(string $sql, array $args = array()): int {
    global $wpdb;
    return (int) $wpdb->get_var($args ? $wpdb->prepare($sql, $args) : $sql);
}

/** Free-pricing closure used by the real acceptance path; no Stripe call. */
function dzn_stress_free_pricing(): Closure {
    return static fn(int $requestId): array => array(
        'configured' => false, 'mode' => 'free', 'amount_minor' => 0,
        'currency' => null, 'region_code' => null,
    );
}

function dzn_stress_noop_projector(): object {
    return new class {
        public function dispatch(int $confirmationId): array {
            return array('confirmation_id' => $confirmationId, 'state' => 'accepting', 'idempotent' => false);
        }
    };
}

/** Accept one active offer through the real Teacher-owned response authority. */
function dzn_stress_accept(int $teacherUserId, string $deliveryUid, string $commandKey, ?int $requestedTimeId = null, ?object $projector = null): array {
    $GLOBALS['current_user'] = new WP_User($teacherUserId);
    $service = new Delnavazan\Platform\Core\Application\BookingTeacherResponseService(
        null, $projector ?? dzn_stress_noop_projector(), dzn_stress_free_pricing()
    );
    return $service->respond($deliveryUid, 'accept', $commandKey, $requestedTimeId);
}

/** Decline one active offer through the real Teacher-owned response authority. */
function dzn_stress_decline(int $teacherUserId, string $deliveryUid, string $commandKey, string $reason = 'times_unsuitable'): array {
    $GLOBALS['current_user'] = new WP_User($teacherUserId);
    $service = new Delnavazan\Platform\Core\Application\BookingTeacherResponseService(
        null, dzn_stress_noop_projector(), dzn_stress_free_pricing()
    );
    return $service->respond($deliveryUid, 'decline', $commandKey, null, $reason);
}

function dzn_stress_process_allocation(int $requestId): void {
    (new Delnavazan\Platform\Core\Application\BookingAllocationService())->process($requestId, gmdate('Y-m-d H:i:s'));
}


/** Create a confirmed booking-confirmation occupancy row for one Teacher interval. */
function dzn_stress_confirmation_blocker(int $studentId, int $instrumentId, int $courseId, int $teacherId, string $startUtc, string $suffix, int $duration = 30, int $buffer = 15): array {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    $now = gmdate('Y-m-d H:i:s');
    $time = dzn_stress_time($startUtc, $duration, $buffer);
    $request = dzn_stress_request($studentId, $instrumentId, $courseId, array($time), $suffix, 'blocker');
    $requestId = $request['request_id'];
    $timeId = $request['time_ids'][0];
    $wpdb->insert($p . 'booking_request_teacher_deliveries', array(
        'uid' => Identifier::uid(), 'booking_request_id' => $requestId, 'requested_time_id' => $timeId,
        'teacher_id' => $teacherId, 'state' => 'accepting', 'match_strength' => 'strong',
        'created_at' => $now, 'updated_at' => $now, 'responded_at' => $now,
    ));
    $deliveryId = (int) $wpdb->insert_id;
    $wpdb->insert($p . 'booking_request_confirmations', array(
        'uid' => Identifier::uid(), 'delivery_id' => $deliveryId, 'booking_request_id' => $requestId,
        'requested_time_id' => $timeId, 'teacher_id' => $teacherId, 'connection_id' => 0,
        'state' => 'confirmed', 'version' => 1, 'starts_at_utc' => $time['starts_at_utc'],
        'ends_at_utc' => $time['instructional_ends_at_utc'], 'schedule_timezone' => 'UTC',
        'local_wall_date' => substr($time['starts_at_utc'], 0, 10), 'local_wall_time' => substr($time['starts_at_utc'], 11),
        'provider_code' => 'google_calendar', 'confirmed_at' => $now, 'created_at' => $now,
        'created_by' => 1, 'updated_at' => $now, 'updated_by' => 1,
    ));
    return array('request_id' => $requestId, 'confirmation_id' => (int) $wpdb->insert_id);
}


/** Mark a synthetic request as identity-resolved so the opportunity board can see it. */
function dzn_stress_resolve_identity(int $requestId, int $studentId, string $suffix): int {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->insert($p . 'booking_request_identity_resolution_events', array(
        'uid' => Identifier::uid(), 'booking_request_id' => $requestId,
        'resolution_sequence' => 1, 'outcome' => 'resolved', 'student_id' => $studentId,
        'verification_basis' => 'synthetic_audit', 'evidence_channel' => 'synthetic',
        'evidence_reference' => $suffix, 'evidence_at' => $now, 'verified_at' => $now,
        'created_at' => $now, 'created_by' => 1,
    ));
    $id = (int) $wpdb->insert_id;
    $wpdb->update($p . 'booking_requests', array('current_identity_resolution_id' => $id), array('id' => $requestId));
    return $id;
}

/** Claim one unmatched request through the real Teacher-owned authority. */
function dzn_stress_claim(int $teacherUserId, string $requestUid, int $requestedTimeId, string $commandKey): array {
    $GLOBALS['current_user'] = new WP_User($teacherUserId);
    $service = new Delnavazan\Platform\Core\Application\BookingTeacherResponseService(
        null, dzn_stress_noop_projector(), dzn_stress_free_pricing()
    );
    return $service->claim($requestUid, $requestedTimeId, $commandKey);
}

/** Decline an unmatched opportunity without consuming an allocation offer. */
function dzn_stress_decline_opportunity(int $teacherUserId, string $requestUid, string $commandKey): array {
    wp_set_current_user($teacherUserId);
    $service = new Delnavazan\Platform\Core\Application\BookingTeacherResponseService(
        null, dzn_stress_noop_projector(), dzn_stress_free_pricing()
    );
    return $service->declineOpportunity($requestUid, 'times_unsuitable', '', $commandKey);
}

/** IDs visible on one Teacher's unmatched opportunity board. */
function dzn_stress_opportunity_ids(int $teacherUserId): array {
    $GLOBALS['current_user'] = new WP_User($teacherUserId);
    $rows = (new Delnavazan\Platform\Core\Application\TeacherOpenBookingRequestReadService())->own();
    return array_map(static fn(object $row): int => (int) $row->booking_request_id, $rows);
}

/** Count confirmed or in-flight confirmations that share one Teacher and exact start. */
function dzn_stress_teacher_double_bookings(): int {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    return (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM (
            SELECT c.teacher_id,c.starts_at_utc,COUNT(*) n
            FROM {$p}booking_request_confirmations c
            WHERE c.state NOT IN ('failed','cancelled')
            GROUP BY c.teacher_id,c.starts_at_utc HAVING n>1
         ) x"
    );
}

/**
 * Confirmed occupancy for every Teacher, using the authoritative occupied end.
 *
 * A confirmation's `ends_at_utc` is the instructional end, so reading it drops
 * the course buffer and hides a lesson that starts inside that buffer. The
 * requested-time row owns the real interval; the confirmation end is only a
 * fallback for a row that has no requested time at all (which the toolkit then
 * reports as malformed instead of ignoring).
 */
function dzn_stress_confirmed_occupancy(array $teacherIds = array()): array {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    $rows = (array) $wpdb->get_results(
        "SELECT c.id,c.teacher_id,c.booking_request_id,c.starts_at_utc,
                COALESCE(rt.occupied_ends_at_utc,c.ends_at_utc) occupied_ends_at_utc
         FROM {$p}booking_request_confirmations c
         LEFT JOIN {$p}booking_request_requested_times rt ON rt.id=c.requested_time_id AND rt.booking_request_id=c.booking_request_id
         WHERE c.state NOT IN ('failed','cancelled')"
    );
    return dzn_stress_scope_teachers($rows, $teacherIds);
}

/** Live pending offers, which hold Teacher capacity before a confirmation exists. */
function dzn_stress_pending_occupancy(array $teacherIds = array()): array {
    global $wpdb;
    $p = $wpdb->prefix . 'dzn_';
    $rows = (array) $wpdb->get_results(
        "SELECT o.id,o.teacher_id,o.booking_request_id,rt.starts_at_utc,rt.occupied_ends_at_utc
         FROM {$p}booking_allocation_offers o
         LEFT JOIN {$p}booking_request_requested_times rt ON rt.id=o.requested_time_id AND rt.booking_request_id=o.booking_request_id
         WHERE o.state='pending' AND o.active_slot=1"
    );
    return dzn_stress_scope_teachers($rows, $teacherIds);
}

/**
 * Restrict occupancy rows to a Teacher population.
 *
 * A capacity violation can only exist inside one Teacher, so scoping by Teacher
 * is exact: it cannot hide a real overlap, because both halves of an overlap
 * always share that Teacher. It matters because some fixtures deliberately
 * inject overlapping offers to test a race; a global count would report those
 * intended faults as violations of an unrelated level.
 */
function dzn_stress_scope_teachers(array $rows, array $teacherIds): array {
    $want = array();
    foreach ($teacherIds as $id) { $id = (int) $id; if ($id > 0) $want[$id] = true; }
    if (!$want) return $rows;
    return array_values(array_filter($rows, static function ($row) use ($want): bool {
        $row = (array) $row;
        return isset($want[(int) ($row['teacher_id'] ?? 0)]);
    }));
}

/** Full classification: hard violations plus the informational offer counts. */
function dzn_stress_capacity_report(array $teacherIds = array()): array {
    return dzn_capacity_violations(dzn_stress_confirmed_occupancy($teacherIds), dzn_stress_pending_occupancy($teacherIds));
}

/**
 * Hard capacity violations: overlapping confirmed occupancy (including the
 * course buffer), a live offer still holding confirmed capacity, or two live
 * offers over-committing one Teacher. Competing offers across Teachers are not
 * a violation and are reported separately by dzn_stress_competing_pending_offers().
 */
function dzn_stress_capacity_violations(array $teacherIds = array()): int {
    return (int) dzn_stress_capacity_report($teacherIds)['hard_count'];
}

/** Live offers holding the same student slot open at more than one Teacher. */
function dzn_stress_competing_pending_offers(array $teacherIds = array()): int {
    return count(dzn_stress_capacity_report($teacherIds)['competing_pending']);
}

/** Privacy-safe description of every hard violation, for the audit record. */
function dzn_stress_capacity_violation_detail(array $teacherIds = array()): array {
    $report = dzn_stress_capacity_report($teacherIds);
    return array(
        'confirmed_overlaps' => array_map('dzn_capacity_describe', $report['confirmed_overlaps']),
        'pending_over_confirmed' => array_map('dzn_capacity_describe', $report['pending_over_confirmed']),
        'pending_overlaps' => array_map('dzn_capacity_describe', $report['pending_overlaps']),
        'orphan_pending' => $report['orphan_pending'],
        'malformed_confirmed' => $report['malformed_confirmed'],
        'competing_pending' => count($report['competing_pending']),
        'hard_count' => $report['hard_count'],
    );
}

function dzn_stress_latency(array $seconds): array {
    if (!$seconds) return array('n' => 0);
    sort($seconds);
    $pct = static function (float $p) use ($seconds): float {
        $index = (int) ceil($p * count($seconds)) - 1;
        return $seconds[max(0, min($index, count($seconds) - 1))];
    };
    return array(
        'n' => count($seconds),
        'min_ms' => round($seconds[0] * 1000, 2),
        'median_ms' => round($pct(0.50) * 1000, 2),
        'p95_ms' => round($pct(0.95) * 1000, 2),
        'p99_ms' => round($pct(0.99) * 1000, 2),
        'max_ms' => round($seconds[count($seconds) - 1] * 1000, 2),
    );
}

/** Emit one stable JSON line that the shell runner captures as evidence. */
function dzn_stress_emit(string $scenario, array $payload): void {
    echo 'AUDIT_RESULT ' . wp_json_encode(array_merge(array('scenario' => $scenario, 'at' => gmdate('c')), $payload)) . "\n";
}
