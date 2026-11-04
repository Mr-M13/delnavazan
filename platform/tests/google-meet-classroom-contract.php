<?php
/**
 * Static contract for the LiveKit → Google Meet migration.
 *
 * This test proves the shipped source surface: no active LiveKit route, one organizer-owned Meet
 * workflow, official API seams (Calendar/Meet/Drive), consent-gated recording and no Teacher Google
 * authorization requirement in onboarding or the Teacher portal.
 */
$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException('Missing source file: ' . $relative);
    return (string) file_get_contents($path);
};
$check = static function (string $label, bool $condition): void {
    if (!$condition) throw new RuntimeException('Google Meet classroom contract failed: ' . $label);
};

$plugin = $read('platform/delnavazan-platform.php');
$check('organizer OAuth controller is registered', str_contains($plugin, 'GoogleWorkspaceOAuthController::register'));
$check('classroom worker is registered', str_contains($plugin, 'GoogleWorkspaceWorker::register'));
$check('recording playback controller is registered', str_contains($plugin, 'MeetRecordingPlaybackController::register'));
$check('LiveKit launch controller is retired from active routes', !str_contains($plugin, 'LiveKitLaunchController::register'));
$check('LiveKit token REST endpoint is retired', !str_contains($plugin, "LiveKitTokenRestController', 'register'"));
$check('LiveKit webhook REST endpoint is retired', !str_contains($plugin, "LiveKitWebhookController', 'register'"));
$check('LiveKit revocation listener is retired', !str_contains($plugin, 'LiveKitRoomRevocationListener::register'));
$check('migration command is registered', str_contains($plugin, 'MeetMigrationCommand::register'));

$rule = $read('platform/src/Integrations/GoogleWorkspace/GoogleWorkspaceClassroomRule.php');
foreach (array(
    'https://www.googleapis.com/auth/calendar.events',
    'https://www.googleapis.com/auth/meetings.space.created',
    'https://www.googleapis.com/auth/meetings.space.settings',
    'https://www.googleapis.com/auth/meetings.space.readonly',
    'https://www.googleapis.com/auth/drive.readonly',
    'openid',
    'email',
) as $scope) $check('least-privilege scope present: ' . $scope, str_contains($rule, $scope));
$check('full Drive scope is not requested', !str_contains($rule, "'https://www.googleapis.com/auth/drive'"));
$check('organizer defaults to classroom@delnavazan.com', str_contains($rule, "ORGANIZER_EMAIL_DEFAULT='classroom@delnavazan.com'"));

$service = $read('platform/src/Integrations/GoogleWorkspace/GoogleWorkspaceClassroomService.php');
$check('Calendar event id is deterministic', str_contains($service, 'GoogleWorkspaceClassroomRule::eventId($identity)'));
$check('Calendar event creates a Meet conference', str_contains($service, "'conferenceData'=>array('createRequest'"));
$check('Teacher and Student are Calendar attendees', str_contains($service, 'attendanceEmails($facts)'));
$check('Teacher is assigned COHOST', str_contains($service, "createMember(\$space,\$email,'COHOST')"));
$check('co-host refusal reports the exact provider response', str_contains($service, "'meet_cohost_assignment_refused'") && str_contains($service, "'response'=>\$response"));
$check('supported co-host fallback turns moderation off', str_contains($service, "'fallback_mode'=>'participant_with_moderation_off'"));
$check('organizer absence is supported by OPEN access', str_contains($rule, "return in_array(\$configured,array('OPEN','TRUSTED','RESTRICTED'),true)?\$configured:'OPEN';"));
$check('recording config is consent-gated', str_contains($service, 'lessonRecordingEligibility'));
$check('recording auto-generation is explicitly ON or OFF', str_contains($service, "'autoRecordingGeneration'=>\$recordingOn?'ON':'OFF'"));
$check('consent rejection forces recording OFF', str_contains($service, '$recordingOn=!empty($eligibility[\'eligible\'])'));
$check('reschedule reuses the existing event', str_contains($service, 'updateCalendarEvent'));
$check('cancellation preserves the classroom row', str_contains($service, "'classroom_state'=>'cancelled'"));
$check('teacher replacement removes other co-hosts', str_contains($service, 'removeOtherCoHosts'));

$recording = $read('platform/src/Integrations/GoogleWorkspace/MeetRecordingService.php');
$check('conference records are read through the official API', str_contains($recording, 'listConferenceRecords'));
$check('recordings are read through the official API', str_contains($recording, 'listRecordings') && str_contains($read('platform/src/Integrations/GoogleWorkspace/GoogleWorkspaceApiClient.php'), 'recordings?'));
$check('Drive file id is persisted for playback', str_contains($recording, "'drive_file_id'=>\$fileId"));
$check('recording playability is verified with a byte-range probe', str_contains($recording, 'driveProbe'));
$check('public Drive sharing is detected and alerted', str_contains($recording, 'recording_publicly_shared'));
$check('missing recording raises an administrator alert', str_contains($recording, 'recording_missing'));
$check('unexpected recording end raises an administrator alert', str_contains($recording, 'recording_ended_unexpectedly'));

$playback = $read('platform/src/Integrations/GoogleWorkspace/MeetRecordingPlaybackController.php');
$check('playback is authenticated', str_contains($playback, 'is_user_logged_in()'));
$check('playback is nonce-protected', str_contains($playback, "check_admin_referer('dzn_meet_recording_play')"));
$check('playback never returns a Drive URL or token to the browser', !str_contains($playback, 'webViewLink') && !str_contains($playback, 'exportUri'));

$attendance = $read('platform/src/Integrations/GoogleWorkspace/MeetAttendanceService.php');
$check('participant sessions are read from Meet', str_contains($attendance, 'listParticipantSessions'));
$check('anonymous guests are retained as unverified evidence', str_contains($attendance, "'type'=>'anonymous'"));
$check('only a verified Phase-P mapping may reach canonical intake', str_contains($attendance, 'verifiedAttendanceMapping'));
$check('attendance never writes completion directly', !str_contains($attendance, 'completed_at') && !str_contains($attendance, 'lifecycle_state'));

$migration = $read('platform/src/Core/Infrastructure/Migration/Migrator.php');
$check('migration 068 is wired', str_contains($migration, '068_google_meet_classroom_authority'));
foreach (array('meet_workspace_connections','meet_workspace_credentials','meet_workspace_authorizations','meet_classrooms','meet_classroom_references','meet_classroom_events','meet_conference_records','meet_recording_artifacts','meet_participant_sessions','meet_recording_alerts') as $table) {
    $check('migration creates ' . $table, str_contains($migration, $table));
}
$check('Meet storage rejects plaintext provider secrets', str_contains($migration, "Meet classroom storage must not persist plaintext provider secrets"));
$check('Meet evidence is append-only', str_contains($migration, "Meet classroom evidence must be append-only"));

$onboarding = $read('theme/template-parts/teacher-portal/onboarding.php');
$check('Teacher onboarding no longer requests Google authorization', !str_contains($onboarding, 'dzn_google_connect') && !str_contains($onboarding, 'google_action_url') && !str_contains($onboarding, 'اتصال Google Calendar'));
$check('Teacher onboarding states the classroom is centrally managed', str_contains($onboarding, 'به‌صورت متمرکز توسط دلنوازان'));
$account = $read('theme/template-parts/teacher-portal/account.php');
$check('Teacher settings no longer render the Google connection card', !str_contains($account, 'google-settings') && !str_contains($account, 'google_action_url'));
$attention = $read('theme/template-parts/teacher-portal/attention.php');
$check('Teacher acceptance copy no longer claims a personal Google event is required', !str_contains($attention, 'رویداد Google Calendar و پیوند Meet ساخته می‌شود'));

$teacherOnboarding = $read('platform/src/Core/Application/TeacherOnboardingService.php');
$check('Teacher activation does not require a Google grant', str_contains($teacherOnboarding, "'ready_for_activation' => \$profile && \$availability"));
$check('Teacher progress reports a centrally managed classroom', str_contains($teacherOnboarding, "'google_state' => 'managed_centrally'"));
$workspace = $read('platform/src/Core/Application/TeacherOnboardingWorkspaceReadService.php');
$check('Admin blockers no longer require a Teacher Google account', !str_contains($workspace, 'Google Calendar account is not connected'));

$actions = $read('theme/template-parts/components/classroom-actions.php');
$check('portal classroom action opens the branded entry page', str_contains($actions, "home_url( '/music-classroom/' )"));
$check('portal classroom action is labelled Google Meet', str_contains($actions, 'ورود به کلاس Google Meet'));
$check('portal classroom action contains no LiveKit action', !str_contains($actions, 'livekit'));
$check('portal classroom action never renders a Meet URL', !str_contains($actions, 'meet.google.com'));

$routes = $read('theme/inc/routes.php');
$check('classroom route renders the Meet entry template', str_contains($routes, "template-parts/classroom/meet"));
$check('classroom route no longer loads the LiveKit SDK', !str_contains($routes, 'livekit-client.umd.min.js'));
$meetTemplate = $read('theme/template-parts/classroom/meet.php');
$check('branded entry page shows teacher and student names', str_contains($meetTemplate, '$teacher_name') && str_contains($meetTemplate, '$student_name'));
$check('branded entry page shows scheduled lesson information', str_contains($meetTemplate, '$date_label') && str_contains($meetTemplate, '$time_label'));
$check('branded entry page has Persian music guidance', str_contains($meetTemplate, 'صدای موسیقی'));
$check('branded entry page posts to the authenticated join controller', str_contains($meetTemplate, 'wp_create_nonce') && str_contains($meetTemplate, 'admin-post.php'));

foreach (array('puppeteer','selenium','playwright','chrome-devtools','javascript:') as $forbidden) {
    $check('no browser automation or UI injection: ' . $forbidden, !str_contains(strtolower($service), $forbidden) && !str_contains(strtolower($recording), $forbidden));
}

foreach (array('LiveKitClassroomAuthorizer.php','LiveKitLaunchController.php','LiveKitJwtIssuer.php') as $archived) {
    $check('archived LiveKit source remains: ' . $archived, is_file($root . '/platform/src/Integrations/LiveKit/' . $archived));
}

echo "Google Meet classroom contract passed\n";
