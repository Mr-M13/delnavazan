<?php
/**
 * Read-only production booking/notification blocker diagnostic.
 * SELECT/read operations only. No recipient addresses, names or secrets are printed.
 */
global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$now = gmdate('Y-m-d H:i:s');
$refs = array('REQ-01M6FJEGCVR4W7HWB9SCFGN802', 'REQ-01M6FJZ54R6WJJC6JYNT3ZX7QV');
$requests = array();
foreach ($refs as $ref) {
    $row = $wpdb->get_row($wpdb->prepare("SELECT id,reference_code,student_id,lifecycle_status,resolution_state,created_at,updated_at FROM {$p}booking_requests WHERE reference_code=%s LIMIT 1", $ref), ARRAY_A);
    if (!$row) { $requests[$ref] = array('found' => false); continue; }
    $id = (int) $row['id'];
    $times = $wpdb->get_results($wpdb->prepare("SELECT sequence_number,starts_at_utc,occupied_ends_at_utc,timezone FROM {$p}booking_request_requested_times WHERE booking_request_id=%d ORDER BY sequence_number", $id), ARRAY_A) ?: array();
    $deliveries = $wpdb->get_results($wpdb->prepare("SELECT id,teacher_id,state,match_strength,created_at,updated_at FROM {$p}booking_request_teacher_deliveries WHERE booking_request_id=%d ORDER BY id", $id), ARRAY_A) ?: array();
    $notifications = $wpdb->get_results($wpdb->prepare("SELECT id,notification_kind,state,attempt_count,last_error_code,handed_off_at,updated_at FROM {$p}booking_request_student_notifications WHERE delivery_id IN (SELECT id FROM {$p}booking_request_teacher_deliveries WHERE booking_request_id=%d) ORDER BY id", $id), ARRAY_A) ?: array();
    $student = $wpdb->get_row($wpdb->prepare("SELECT email FROM {$p}students WHERE id=%d", (int) $row['student_id']), ARRAY_A);
    $teacher = $wpdb->get_row($wpdb->prepare("SELECT email FROM {$p}teachers WHERE id=%d", (int) ($deliveries[0]['teacher_id'] ?? 0)), ARRAY_A);
    $requests[$ref] = array(
        'found' => true,
        'id' => $id,
        'student_id' => (int) $row['student_id'],
        'lifecycle_status' => (string) $row['lifecycle_status'],
        'resolution_state' => (string) $row['resolution_state'],
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
        'student_email_usable' => function_exists('is_email') && $student !== null && is_email((string) $student['email']),
        'teacher_email_usable' => function_exists('is_email') && $teacher !== null && is_email((string) $teacher['email']),
        'requested_times' => $times,
        'deliveries' => $deliveries,
        'notifications' => $notifications,
        'confirmations' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE booking_request_id=%d", $id)),
        'cancellations' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_cancellations WHERE booking_request_id=%d", $id)),
        'coordination_cases' => $wpdb->get_results($wpdb->prepare("SELECT id,state,state_reason_code FROM {$p}coordination_cases WHERE booking_request_id=%d ORDER BY id", $id), ARRAY_A) ?: array(),
        'projection_secrets' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}provider_projection_secrets WHERE mapping_kind='booking_calendar' AND mapping_id IN (SELECT id FROM {$p}booking_request_confirmations WHERE booking_request_id=%d) AND state='active'", $id)),
    );
}

$notificationStates = $wpdb->get_results("SELECT notification_kind,state,COUNT(*) total FROM {$p}booking_request_student_notifications GROUP BY notification_kind,state ORDER BY notification_kind,state", ARRAY_A) ?: array();
$failedCodes = $wpdb->get_results("SELECT last_error_code label,COUNT(*) total,MIN(updated_at) oldest,MAX(updated_at) newest FROM {$p}booking_request_student_notifications WHERE state='failed' GROUP BY last_error_code ORDER BY total DESC", ARRAY_A) ?: array();
$failedRecent = $wpdb->get_results("SELECT n.id,n.notification_kind,n.state,n.attempt_count,n.last_error_code,n.updated_at FROM {$p}booking_request_student_notifications n WHERE n.state='failed' ORDER BY n.updated_at DESC,n.id DESC LIMIT 10", ARRAY_A) ?: array();
$overdue = $wpdb->get_results("SELECT id,notification_kind,state,attempt_count,next_attempt_at,last_error_code,updated_at FROM {$p}booking_request_student_notifications WHERE state IN ('queued','retryable') AND (next_attempt_at IS NULL OR next_attempt_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)) ORDER BY updated_at ASC,id ASC LIMIT 10", ARRAY_A) ?: array();
$retraction = $wpdb->get_results("SELECT state,COUNT(*) total,MIN(updated_at) oldest,MAX(updated_at) newest FROM {$p}booking_request_provider_retraction_jobs GROUP BY state ORDER BY state", ARRAY_A) ?: array();
$recoveryAuditRows = $wpdb->get_results("SELECT id,aggregate_id,reason_code,safe_detail,occurred_at FROM {$p}platform_audit_events WHERE event_type='booking_request.notification_recovery' ORDER BY id DESC LIMIT 5", ARRAY_A) ?: array();
$deliveryEvidence = $wpdb->get_results("SELECT delivery_state,applied,COUNT(*) total,MIN(occurred_at) oldest,MAX(occurred_at) newest FROM {$p}notification_deliveries GROUP BY delivery_state,applied ORDER BY delivery_state,applied", ARRAY_A) ?: array();
$canonicalNotifications = $wpdb->get_results("SELECT audience,state,COUNT(*) total FROM {$p}notifications GROUP BY audience,state ORDER BY audience,state", ARRAY_A) ?: array();
$attemptOutcomes = $wpdb->get_results("SELECT state,outcome_code,failure_class,COUNT(*) total,MAX(updated_at) newest FROM {$p}notification_attempts GROUP BY state,outcome_code,failure_class ORDER BY total DESC LIMIT 20", ARRAY_A) ?: array();

$cron = _get_cron_array();
$bookingCron = array();
foreach ((array) $cron as $timestamp => $events) {
    if (isset($events['dzn_booking_student_notification_dispatch'])) $bookingCron[] = gmdate('Y-m-d H:i:s', (int) $timestamp);
}
$whatsapp = array('whatsapp_ready' => false, 'sms_ready' => false, 'telegram_ready' => false);
try {
    $settings = new \Delnavazan\Platform\Core\Application\IntegrationSettingsService();
    $config = new \Delnavazan\Platform\Integrations\Notifications\TelnyxConfiguration($settings);
    $whatsapp['whatsapp_ready'] = $config->whatsappReady();
    $whatsapp['sms_ready'] = $config->smsReady();
} catch (\Throwable $e) {
    $whatsapp['error'] = 'configuration_unavailable';
}
$google = false;
try { $google = class_exists('Delnavazan\\Platform\\Integrations\\GoogleOAuthController') && \Delnavazan\Platform\Integrations\GoogleOAuthController::configured(); } catch (\Throwable $e) { $google = false; }

echo json_encode(array(
    'checked_at_utc' => $now,
    'schema' => (string) get_option('dzn_platform_schema_version'),
    'build' => defined('DZN_PLATFORM_BUILD_ID') ? (string) DZN_PLATFORM_BUILD_ID : null,
    'maintenance_mode' => function_exists('wp_is_maintenance_mode') ? (wp_is_maintenance_mode() ? 'active' : 'inactive') : 'unknown',
    'cron_disabled' => defined('DISABLE_WP_CRON') ? (bool) DISABLE_WP_CRON : false,
    'booking_notification_cron' => $bookingCron,
    'booking_notification_worker_loaded' => class_exists('Delnavazan\\Platform\\Integrations\\BookingStudentNotificationWorker'),
    'requests' => $requests,
    'notifications_by_kind_state' => $notificationStates,
    'failed_by_code' => $failedCodes,
    'failed_recent' => $failedRecent,
    'overdue_queued_or_retryable' => $overdue,
    'retraction_jobs' => $retraction,
    'recovery_audit_events' => count($recoveryAuditRows),
    'recovery_audit_recent' => $recoveryAuditRows,
    'delivery_evidence' => $deliveryEvidence,
    'canonical_notifications' => $canonicalNotifications,
    'notification_attempt_outcomes' => $attemptOutcomes,
    'provider_readiness' => array('telnyx' => $whatsapp, 'google_oauth' => $google),
), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
