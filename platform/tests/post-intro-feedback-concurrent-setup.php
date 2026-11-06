<?php
/**
 * Reset one disposable preparation for the concurrent feedback submission proof.
 */
if (getenv('DZN_POST_INTRO_FEEDBACK_CONCURRENT_SETUP') !== 'authority' || !defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "Post-intro feedback concurrent setup refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\CanonicalContinuationRule;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$prep = $wpdb->get_row("SELECT * FROM {$p}post_intro_preparations WHERE state='prepared' AND slot_authority_id IS NOT NULL ORDER BY id DESC LIMIT 1");
if (!$prep) throw new RuntimeException('prepared survey candidate required');
$prepId = (int) $prep->id;
$confirmation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_request_confirmations WHERE booking_request_id=%d LIMIT 1", (int) $prep->booking_request_id));
if (!$confirmation) throw new RuntimeException('confirmation required');
$introEnd = gmdate('Y-m-d H:i:s', time() - 5 * 86400);
$introStart = gmdate('Y-m-d H:i:s', strtotime($introEnd . ' UTC') - 1800);
$slotStartTs = time() + 12 * 3600;
$localDate = gmdate('Y-m-d', $slotStartTs);
$localTime = gmdate('H:i:s', $slotStartTs);
$resolved = CanonicalContinuationRule::resolveWallClock('UTC', $localDate, $localTime, 30, 0);
$wpdb->update($p . 'booking_request_confirmations', array('starts_at_utc' => $introStart, 'ends_at_utc' => $introEnd), array('booking_request_id' => (int) $prep->booking_request_id));
$wpdb->update($p . 'canonical_continuation_slot_authorities', array(
    'schedule_timezone' => 'UTC', 'local_wall_date' => $localDate, 'local_wall_time' => $localTime,
    'duration_minutes' => 30, 'buffer_minutes' => 0,
    'starts_at_utc' => $resolved['starts_at_utc'], 'ends_at_utc' => $resolved['ends_at_utc'],
    'occupied_ends_at_utc' => $resolved['occupied_ends_at_utc'],
), array('id' => (int) $prep->slot_authority_id));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}post_intro_feedback WHERE preparation_id=%d", $prepId));
$caseIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}canonical_continuation_cases WHERE intro_lesson_id=%d", (int) $prep->intro_lesson_id));
foreach ((array) $caseIds as $caseId) {
    $caseId = (int) $caseId;
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}canonical_continuation_decisions WHERE continuation_case_id=%d", $caseId));
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}canonical_continuation_interventions WHERE continuation_case_id=%d", $caseId));
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}canonical_continuation_reservations WHERE continuation_case_id=%d", $caseId));
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}canonical_continuation_cases WHERE id=%d", $caseId));
}
$wpdb->query($wpdb->prepare("DELETE FROM {$p}canonical_continuation_commands WHERE intro_lesson_id=%d", (int) $prep->intro_lesson_id));
$wpdb->update($p . 'post_intro_preparations', array('state' => 'prepared'), array('id' => $prepId));
$studentUser = (int) $wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND revoked_at IS NULL AND active_slot=1 ORDER BY id DESC LIMIT 1", (int) $prep->student_id));
echo json_encode(array('prep_id' => $prepId, 'uid' => (string) $prep->uid, 'user' => $studentUser)) . "\n";
