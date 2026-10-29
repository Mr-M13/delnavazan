<?php
/**
 * Authorised recovery of one failed lesson-reschedule calendar hand-off.
 *
 * Runs through `wp eval` on production. It refuses unless the job is failed, the proposal is
 * accepted and the target schedule version is still the Lesson's applicable version. It then
 * requeues the exact job, dispatches it under the existing calendar actor, optionally runs the
 * Google projection worker, and prints safe state booleans. No personal data, event references,
 * Meet URLs, credentials or tokens are printed.
 */
$fail = static function (string $reason): void {
    echo json_encode(array('status' => 'refused', 'reason' => $reason), JSON_UNESCAPED_SLASHES), "\n";
    exit(1);
};
$jobId = (int) getenv('DZN_CALENDAR_JOB_ID');
if ($jobId < 1) $fail('job_id_required');
global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lesson_reschedule_calendar_jobs WHERE id=%d", $jobId));
if (!$job) $fail('job_not_found');
if ((string) $job->state !== 'failed') $fail('job_not_failed');
$proposal = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lesson_reschedule_proposals WHERE id=%d", (int) $job->proposal_id));
if (!$proposal || (string) $proposal->state !== 'accepted') $fail('proposal_not_accepted');
$lesson = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lessons WHERE id=%d", (int) $job->lesson_id));
if (!$lesson || $lesson->archived_at !== null) $fail('lesson_not_active');
$version = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d", (int) $job->to_schedule_version_id));
if (!$version || (int) $version->lesson_id !== (int) $lesson->id || (int) $version->applicable_slot !== 1 || $version->superseded_at !== null) $fail('target_schedule_not_current');
$now = gmdate('Y-m-d H:i:s');
$requeued = $wpdb->update($p . 'lesson_reschedule_calendar_jobs', array('state' => 'retry', 'next_attempt_at' => $now, 'updated_at' => $now), array('id' => $jobId, 'state' => 'failed'));
if ($requeued !== 1) $fail('requeue_conflict');
$dispatchError = null;
try {
    (new \Delnavazan\Platform\Integrations\LessonRescheduleCalendarService())->dispatchJob($jobId);
} catch (\Throwable $error) {
    $dispatchError = preg_match('/^[a-z0-9_]{1,64}$/D', (string) $error->getMessage()) ? (string) $error->getMessage() : 'calendar_recovery_failed';
}
$workerError = null;
$workerReady = false;
$oauthConfigured = false;
$contextClass = 'Delnavazan\\Platform\\Integrations\\ProviderProjectionWorkerContext';
$oauthClass = 'Delnavazan\\Platform\\Integrations\\GoogleOAuthController';
if (class_exists($contextClass)) $workerReady = $contextClass::principalId() > 0;
if (class_exists($oauthClass)) $oauthConfigured = $oauthClass::configured();
if ($dispatchError === null && $workerReady && $oauthConfigured && class_exists('Delnavazan\\Platform\\Integrations\\GoogleCalendarProjectionWorker')) {
    try {
        \Delnavazan\Platform\Integrations\GoogleCalendarProjectionWorker::run();
    } catch (\Throwable $error) {
        $workerError = preg_match('/^[a-z0-9_]{1,64}$/D', (string) $error->getMessage()) ? (string) $error->getMessage() : 'projection_worker_failed';
    }
}
$jobAfter = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lesson_reschedule_calendar_jobs WHERE id=%d", $jobId));
$calendar = $wpdb->get_row($wpdb->prepare("SELECT projection_state,event_digest FROM {$p}provider_calendar_event_mappings WHERE lesson_id=%d AND schedule_version_id=%d AND active_slot=1 LIMIT 1", (int) $job->lesson_id, (int) $job->to_schedule_version_id));
$meeting = $wpdb->get_row($wpdb->prepare("SELECT projection_state,join_uri_digest FROM {$p}provider_meeting_mappings WHERE lesson_id=%d AND schedule_version_id=%d AND active_slot=1 LIMIT 1", (int) $job->lesson_id, (int) $job->to_schedule_version_id));
$result = array(
    'status' => ($jobAfter && (string) $jobAfter->state === 'completed') ? 'handed_off' : 'not_completed',
    'job_state' => $jobAfter === null ? null : (string) $jobAfter->state,
    'attempt_count' => $jobAfter === null ? null : (int) $jobAfter->attempt_count,
    'last_error_code' => $jobAfter === null || $jobAfter->last_error_code === null ? null : (string) $jobAfter->last_error_code,
    'dispatch_error' => $dispatchError,
    'projection_worker_ready' => $workerReady,
    'google_oauth_configured' => $oauthConfigured,
    'projection_worker_error' => $workerError,
    'calendar_projection_state' => $calendar === null ? null : (string) $calendar->projection_state,
    'calendar_reference_present' => $calendar !== null && trim((string) ($calendar->event_digest ?? '')) !== '',
    'meeting_projection_state' => $meeting === null ? null : (string) $meeting->projection_state,
    'meeting_join_present' => $meeting !== null && trim((string) ($meeting->join_uri_digest ?? '')) !== '',
);
echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
if ($result['status'] !== 'handed_off') exit(1);
