<?php
/** Synthetic post-intro states for the disposable Enrolment workspace. */
if (getenv('DZN_ENROLMENT_POST_INTRO_FIXTURE') !== 'local_only' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Enrolment post-intro fixture refused.\n");
    exit(1);
}
wp_set_current_user(1);
$fixture = get_option('dzn_enrolment_workspace_fixture');
if (!is_array($fixture)) throw new RuntimeException('Enrolment workspace fixture required');
global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$now = gmdate('Y-m-d H:i:s');
$uid = static fn(string $prefix): string => substr(str_pad($prefix . strtoupper(substr(bin2hex(random_bytes(8)), 0, 12)), 26, 'X'), 0, 26);
$requestId = (int) $fixture['intro_request_id'];
$confirmation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_request_confirmations WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1", $requestId));
if (!$confirmation) throw new RuntimeException('Confirmed trial required');
$outcome = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_request_intro_outcomes WHERE booking_request_id=%d", $requestId));
if (!$outcome) {
    $wpdb->insert($p . 'booking_request_intro_outcomes', array(
        'uid' => $uid('OUT'), 'booking_request_id' => $requestId, 'confirmation_id' => (int) $confirmation->id,
        'teacher_id' => (int) $confirmation->teacher_id, 'student_id' => (int) $fixture['student_id'], 'outcome' => 'delivered',
        'note' => 'Synthetic delivered intro for workspace evidence', 'occurred_at' => $confirmation->ends_at_utc,
        'source' => 'admin', 'recorded_by' => 1, 'recorded_at' => $now, 'created_at' => $now,
    ));
    $outcome = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_request_intro_outcomes WHERE booking_request_id=%d", $requestId));
}
$prep = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}post_intro_preparations WHERE booking_request_id=%d", $requestId));
if (!$prep) {
    $slotUtc = gmdate('Y-m-d H:i:s', strtotime((string) $confirmation->starts_at_utc . ' UTC +7 days'));
    $caseUid = $uid('CON');
    $wpdb->insert($p . 'canonical_continuation_cases', array(
        'uid' => $caseUid, 'reference_code' => null, 'intro_lesson_id' => 999000 + $requestId,
        'intro_schedule_version_id' => 999000 + $requestId, 'intro_starts_at_utc' => (string) $confirmation->starts_at_utc,
        'intro_ends_at_utc' => (string) $confirmation->ends_at_utc, 'intro_timezone' => (string) $confirmation->schedule_timezone,
        'intro_local_wall_date' => (string) $confirmation->local_wall_date, 'intro_local_wall_time' => (string) $confirmation->local_wall_time,
        'student_id' => (int) $fixture['student_id'], 'teacher_id' => (int) $confirmation->teacher_id, 'course_id' => (int) $fixture['standard_course_id'],
        'rule_version' => 'fixture', 'current_decision' => 'continue', 'decision_at' => $now, 'case_version' => 2,
        'created_at' => $now, 'created_by' => 1, 'updated_at' => $now, 'updated_by' => 1,
    ));
    $caseId = (int) $wpdb->insert_id;
    $wpdb->insert($p . 'canonical_continuation_decisions', array(
        'uid' => $uid('DEC'), 'continuation_case_id' => $caseId, 'decision_sequence' => 1, 'decision' => 'continue',
        'source' => 'student', 'actor_basis' => 'student_principal', 'actor_user_id' => 1,
        'evidence_channel' => 'authenticated_platform', 'evidence_reference_digest' => hash('sha256', 'fixture-decision-' . $requestId),
        'evidence_at' => $now, 'occurred_at' => $now, 'recorded_at' => $now, 'created_at' => $now, 'created_by' => 1,
    ));
    $decisionId = (int) $wpdb->insert_id;
    $wpdb->update($p . 'canonical_continuation_cases', array('latest_decision_id' => $decisionId), array('id' => $caseId));
    $wpdb->insert($p . 'canonical_continuation_slot_authorities', array(
        'uid' => $uid('SLO'), 'intro_lesson_id' => 999000 + $requestId, 'intro_schedule_version_id' => 999000 + $requestId,
        'student_id' => (int) $fixture['student_id'], 'teacher_id' => (int) $confirmation->teacher_id, 'course_id' => (int) $fixture['standard_course_id'],
        'schedule_timezone' => (string) $confirmation->schedule_timezone, 'local_wall_date' => gmdate('Y-m-d', strtotime($slotUtc . ' UTC')),
        'local_wall_time' => gmdate('H:i:s', strtotime($slotUtc . ' UTC')), 'starts_at_utc' => $slotUtc,
        'ends_at_utc' => gmdate('Y-m-d H:i:s', strtotime($slotUtc . ' UTC +30 minutes')),
        'occupied_ends_at_utc' => gmdate('Y-m-d H:i:s', strtotime($slotUtc . ' UTC +45 minutes')),
        'duration_minutes' => 30, 'buffer_minutes' => 15, 'authority_basis' => 'administrator_attestation',
        'reason_code' => 'agreed_regular_slot', 'evidence_channel' => 'staff_record',
        'evidence_reference_digest' => hash('sha256', 'fixture-slot-' . $requestId), 'evidence_at' => $now,
        'rule_version' => 'fixture', 'recorded_at' => $now, 'recorded_by' => 1,
    ));
    $slotId = (int) $wpdb->insert_id;
    $expires = min(strtotime($slotUtc . ' UTC'), strtotime((string) $confirmation->ends_at_utc . ' UTC') + 6 * 86400);
    $wpdb->insert($p . 'canonical_continuation_reservations', array(
        'uid' => $uid('RES'), 'continuation_case_id' => $caseId, 'decision_id' => $decisionId, 'slot_authority_id' => $slotId,
        'teacher_id' => (int) $confirmation->teacher_id, 'student_id' => (int) $fixture['student_id'], 'course_id' => (int) $fixture['standard_course_id'],
        'source_intro_lesson_id' => 999000 + $requestId, 'source_intro_schedule_version_id' => 999000 + $requestId,
        'starts_at_utc' => $slotUtc, 'ends_at_utc' => gmdate('Y-m-d H:i:s', strtotime($slotUtc . ' UTC +30 minutes')),
        'occupied_ends_at_utc' => gmdate('Y-m-d H:i:s', strtotime($slotUtc . ' UTC +45 minutes')),
        'duration_minutes' => 30, 'buffer_minutes' => 15, 'schedule_timezone' => (string) $confirmation->schedule_timezone,
        'local_wall_date' => gmdate('Y-m-d', strtotime($slotUtc . ' UTC')), 'local_wall_time' => gmdate('H:i:s', strtotime($slotUtc . ' UTC')),
        'reserved_at' => $now, 'expires_at' => gmdate('Y-m-d H:i:s', $expires), 'state' => 'active', 'rule_version' => 'fixture',
        'reservation_version' => 1, 'created_at' => $now, 'created_by' => 1, 'updated_at' => $now, 'updated_by' => 1,
    ));
    $reservationId = (int) $wpdb->insert_id;
    $wpdb->insert($p . 'post_intro_preparations', array(
        'uid' => $uid('PRE'), 'booking_request_id' => $requestId, 'outcome_id' => (int) $outcome->id,
        'student_id' => (int) $fixture['student_id'], 'teacher_id' => (int) $confirmation->teacher_id,
        'standard_course_id' => (int) $fixture['standard_course_id'], 'continuation_case_id' => $caseId,
        'slot_authority_id' => $slotId, 'reservation_id' => $reservationId, 'state' => 'prepared',
        'prepared_at' => $now, 'last_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ));
    $wpdb->insert($p . 'booking_request_student_notifications', array(
        'uid' => $uid('NOT'), 'delivery_id' => (int) $confirmation->delivery_id, 'confirmation_id' => (int) $confirmation->id,
        'notification_kind' => 'post_intro_invitation', 'state' => 'failed', 'attempt_count' => 1,
        'next_attempt_at' => null, 'last_error_code' => 'fixture_transport_refused', 'created_at' => $now, 'updated_at' => $now,
    ));
}
update_option('dzn_enrolment_post_intro_fixture', array('request_id' => $requestId, 'confirmation_id' => (int) $confirmation->id), false);
echo "Enrolment post-intro fixture prepared.\n";
