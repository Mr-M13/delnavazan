<?php
/**
 * Authorised dispatch of pending Google Calendar/Meet projections through the existing worker.
 *
 * The worker is a projection of canonical scheduling authority; it never changes a Lesson or
 * schedule. This payload prints safe state/count booleans only, never references, Meet URLs,
 * credentials, tokens or personal data.
 */
$fail = static function (string $reason): void {
    echo json_encode(array('status' => 'refused', 'reason' => $reason), JSON_UNESCAPED_SLASHES), "\n";
    exit(1);
};
$workerClass = 'Delnavazan\\Platform\\Integrations\\GoogleCalendarProjectionWorker';
$contextClass = 'Delnavazan\\Platform\\Integrations\\ProviderProjectionWorkerContext';
$oauthClass = 'Delnavazan\\Platform\\Integrations\\GoogleOAuthController';
if (!class_exists($workerClass)) $fail('projection_worker_missing');
$workerReady = class_exists($contextClass) && $contextClass::principalId() > 0;
$oauthConfigured = class_exists($oauthClass) && $oauthClass::configured();
if (!$workerReady) $fail('projection_worker_principal_required');
if (!$oauthConfigured) $fail('google_oauth_not_configured');
global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$pendingBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}provider_calendar_event_mappings WHERE provider_code='google_calendar' AND projection_state='pending'");
$workerError = null;
try {
    $workerClass::run();
} catch (\Throwable $error) {
    $workerError = preg_match('/^[a-z0-9_]{1,64}$/D', (string) $error->getMessage()) ? (string) $error->getMessage() : 'projection_worker_failed';
}
$pendingAfter = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}provider_calendar_event_mappings WHERE provider_code='google_calendar' AND projection_state='pending'");
$verified = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}provider_calendar_event_mappings WHERE provider_code='google_calendar' AND projection_state='verified'");
$joinSecrets = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}provider_calendar_event_mappings m INNER JOIN {$p}provider_projection_secrets s ON s.mapping_kind='calendar_event' AND s.mapping_id=m.id AND s.state='active' AND s.active_slot=1 WHERE m.provider_code='google_calendar' AND m.projection_state='verified' AND s.ciphertext IS NOT NULL");
$result = array(
    'status' => $pendingAfter === 0 ? 'dispatched' : 'pending_remaining',
    'pending_before' => $pendingBefore,
    'pending_after' => $pendingAfter,
    'verified_total' => $verified,
    'verified_join_reference_count' => $joinSecrets,
    'projection_worker_error' => $workerError,
);
echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
if ($result['status'] !== 'dispatched') exit(1);
