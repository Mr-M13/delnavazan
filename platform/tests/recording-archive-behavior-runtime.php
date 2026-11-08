<?php
/**
 * Disposable WordPress/MariaDB behavioural proof for recording authority, lifecycle, archive
 * reconciliation, withdrawal and playback.
 */
if (getenv('DZN_RECORDING_ARCHIVE_BEHAVIOR_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Recording archive behavior runtime refused.\n");
    exit(1);
}
use Delnavazan\Platform\Core\Application\ConsentPurposeRegistry;
use Delnavazan\Platform\Core\Application\ConsentWithdrawalService;
use Delnavazan\Platform\Core\Application\MlDatasetEligibilityService;
use Delnavazan\Platform\Core\Application\Recording\RecordingControlPlane;
use Delnavazan\Platform\Core\Application\Recording\RecordingFeatureFlags;
use Delnavazan\Platform\Core\Application\Recording\RecordingInvalidationListener;
use Delnavazan\Platform\Core\Application\Recording\RecordingLifecycle;
use Delnavazan\Platform\Core\Application\Recording\RecordingPlaybackGrantService;
use Delnavazan\Platform\Core\Application\Recording\RecordingSignature;
use Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService;
use Delnavazan\Platform\Core\Application\RecordingNoticePublicationService;
use Delnavazan\Platform\Core\Support\Identifier;

defined('DZN_RECORDING_CAPTURE_STAGING_ENABLED') || define('DZN_RECORDING_CAPTURE_STAGING_ENABLED', true);
defined('DZN_RECORDING_SERVICE_SECRET') || define('DZN_RECORDING_SERVICE_SECRET', str_repeat('r', 64));
defined('DZN_RECORDING_SERVICE_KEY_ID') || define('DZN_RECORDING_SERVICE_KEY_ID', 'test-recorder-v1');
defined('DZN_RECORDING_PLAYBACK_SECRET') || define('DZN_RECORDING_PLAYBACK_SECRET', str_repeat('p', 64));
defined('DZN_RECORDING_PLAYBACK_KEY_ID') || define('DZN_RECORDING_PLAYBACK_KEY_ID', 'test-playback-v1');
defined('DZN_RECORDING_PLAYBACK_GATEWAY_URL') || define('DZN_RECORDING_PLAYBACK_GATEWAY_URL', 'https://playback.test');
defined('DZN_MBOX_CONFIRMATION_SECRET') || define('DZN_MBOX_CONFIRMATION_SECRET', str_repeat('m', 64));
defined('DZN_MBOX_CONFIRMATION_KEY_ID') || define('DZN_MBOX_CONFIRMATION_KEY_ID', 'mbox-archive-v1');

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$now = gmdate('Y-m-d H:i:s');
function dzn_ra_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_ra_user(string $label): int {
    $id = wp_insert_user(array('user_login' => 'dzn-ra-' . $label . '-' . substr(Identifier::uid(), 0, 8), 'user_pass' => wp_generate_password(24), 'user_email' => 'dzn-ra-' . $label . '-' . substr(Identifier::uid(), 0, 8) . '@example.invalid', 'role' => 'subscriber'));
    if (is_wp_error($id) || (int) $id < 1) throw new RuntimeException('fixture user failed');
    return (int) $id;
}
function dzn_ra_teacher(string $name): int {
    global $wpdb; $p = $wpdb->prefix . 'dzn_'; $now = gmdate('Y-m-d H:i:s');
    if ($wpdb->insert($p . 'teachers', array('uid' => Identifier::uid(), 'reference_code' => 'T-' . substr(Identifier::uid(), 0, 12), 'status' => 'active', 'display_name' => $name, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1)) === false) throw new RuntimeException('teacher fixture: ' . $wpdb->last_error);
    return (int) $wpdb->insert_id;
}
function dzn_ra_student(string $name, string $capacity): int {
    global $wpdb; $p = $wpdb->prefix . 'dzn_'; $now = gmdate('Y-m-d H:i:s');
    if ($wpdb->insert($p . 'students', array('uid' => Identifier::uid(), 'reference_code' => 'S-' . substr(Identifier::uid(), 0, 12), 'status' => 'active', 'display_name' => $name, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1)) === false) throw new RuntimeException('student fixture: ' . $wpdb->last_error);
    $id = (int) $wpdb->insert_id;
    if ($wpdb->insert($p . 'student_acceptance_capacity_classifications', array('uid' => Identifier::uid(), 'student_id' => $id, 'classification' => $capacity, 'verification_basis' => 'synthetic_runtime_fixture', 'evidence_channel' => 'runtime_fixture', 'evidence_reference' => 'recording-archive', 'evidence_at' => $now, 'classified_at' => $now, 'classified_by' => 1, 'created_at' => $now, 'created_by' => 1)) === false) throw new RuntimeException('capacity fixture: ' . $wpdb->last_error);
    $wpdb->update($p . 'students', array('current_acceptance_capacity_classification_id' => (int) $wpdb->insert_id), array('id' => $id));
    return $id;
}
function dzn_ra_link_teacher(int $teacher, int $user): void {
    global $wpdb; $p = $wpdb->prefix . 'dzn_'; $now = gmdate('Y-m-d H:i:s');
    if ($wpdb->insert($p . 'teacher_principal_links', array('teacher_id' => $teacher, 'wordpress_user_id' => $user, 'status' => 'active', 'linked_at' => $now, 'linked_by' => 1)) === false) throw new RuntimeException('teacher link: ' . $wpdb->last_error);
}
function dzn_ra_link_student(int $student, int $user): void {
    global $wpdb; $p = $wpdb->prefix . 'dzn_'; $now = gmdate('Y-m-d H:i:s');
    if ($wpdb->insert($p . 'student_principal_links', array('student_id' => $student, 'wordpress_user_id' => $user, 'status' => 'active', 'linked_at' => $now, 'linked_by' => 1, 'link_sequence' => 1, 'active_slot' => 1, 'version' => 1, 'verification_basis' => 'synthetic_runtime_fixture', 'evidence_channel' => 'runtime_fixture', 'evidence_reference' => 'recording-archive', 'evidence_at' => $now, 'updated_at' => $now, 'updated_by' => 1)) === false) throw new RuntimeException('student link: ' . $wpdb->last_error);
}
function dzn_ra_lesson(int $teacher, int $student): array {
    global $wpdb; $p = $wpdb->prefix . 'dzn_'; $now = gmdate('Y-m-d H:i:s');
    $start = gmdate('Y-m-d H:i:s', time() + 300); $end = gmdate('Y-m-d H:i:s', time() + 360);
    $lessonUid = Identifier::uid();
    if ($wpdb->insert($p . 'lessons', array('uid' => $lessonUid, 'student_id' => $student, 'teacher_id' => $teacher, 'course_id' => 1, 'lesson_type' => 'standard', 'status' => 'scheduled', 'record_model' => 'canonical_term_lesson_v1', 'lifecycle_state' => 'authorised', 'current_schedule_version_id' => 0, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1)) === false) throw new RuntimeException('lesson fixture ' . $wpdb->last_error);
    $lessonId = (int) $wpdb->insert_id;
    $scheduleUid = Identifier::uid();
    if ($wpdb->insert($p . 'canonical_lesson_schedule_versions', array(
        'uid' => $scheduleUid, 'lesson_id' => $lessonId, 'version_number' => 1, 'applicable_slot' => 1,
        'enrolment_id' => 1, 'term_id' => 1, 'teacher_assignment_id' => 1, 'teacher_id' => $teacher,
        'starts_at_utc' => $start, 'ends_at_utc' => $end, 'duration_minutes' => 1, 'buffer_minutes' => 0,
        'duration_source' => 'course_default', 'occupied_ends_at_utc' => $end, 'schedule_timezone' => 'UTC',
        'local_wall_date' => substr($start, 0, 10), 'local_wall_time' => substr($start, 11, 8),
        'availability_basis' => 'teacher_availability', 'reason_code' => 'scheduled', 'evidence_channel' => 'runtime_fixture',
        'evidence_reference_digest' => hash('sha256', 'schedule-' . $lessonId), 'evidence_at' => $now, 'created_at' => $now, 'created_by' => 1,
    )) === false) throw new RuntimeException('schedule fixture ' . $wpdb->last_error);
    $scheduleId = (int) $wpdb->insert_id;
    $wpdb->update($p . 'lessons', array('current_schedule_version_id' => $scheduleId), array('id' => $lessonId));
    return array('lesson_id' => $lessonId, 'lesson_uid' => $lessonUid, 'schedule_id' => $scheduleId, 'schedule_uid' => $scheduleUid);
}
function dzn_ra_health(array $overrides = array()): void {
    update_option(RecordingFeatureFlags::SERVICE_HEALTH_OPTION, array_merge(array(
        'received_at' => time(), 'ready' => true, 'storage_free_bytes' => 107374182400, 'storage_free_percent' => 50.0,
        'archive_mount_identity' => 'mbox-test-node', 'archive_available' => true, 'archive_free_bytes' => 1099511627776,
        'tailscale_connected' => true, 'secure_transport' => 'tailscale', 'secure_transport_connected' => true, 'egress_cpu_percent' => 4.0, 'egress_memory_bytes' => 134217728,
        'oldest_queue_age_seconds' => 0,
    ), $overrides), false);
}
function dzn_ra_event(RecordingControlPlane $control, array $payload): array {
    $envelope = RecordingSignature::sign($payload, DZN_RECORDING_SERVICE_SECRET, RecordingFeatureFlags::serviceKeyId(), RecordingControlPlane::CALLBACK_AUDIENCE, time(), 300);
    return $control->handleCallback($envelope);
}
function dzn_ra_media_payload(string $uid, string $key, string $sha, int $bytes, int $durationMs = 60000): array {
    return array(
        'event' => 'media_verified', 'recording_uid' => $uid, 'content_version' => 1, 'idempotency_key' => $key, 'source' => 'recorder',
        'media' => array('egress_complete' => true, 'file_closed' => true, 'format' => 'mp4', 'video_streams' => 1, 'audio_streams' => 1,
            'expected_video_streams' => 1, 'expected_audio_streams' => 1, 'video_codec' => 'h264', 'audio_codec' => 'aac',
            'duration_ms' => $durationMs, 'width' => 1280, 'height' => 720, 'source_bytes' => $bytes, 'size_stable' => true, 'sha256' => $sha, 'sync_offset_ms' => 0),
        'scheduled_duration_seconds' => 60,
    );
}

dzn_ra_assert((string) DZN_PLATFORM_SCHEMA_VERSION === '60', 'behavior runtime requires the current package schema');
$noticeService = new RecordingNoticePublicationService();
$notice = array();
foreach (array(
    RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS => 'Teacher terms runtime',
    RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RECORDING => 'Teacher recording runtime',
    RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RESEARCH => 'Teacher research runtime',
    RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING => 'Student recording runtime',
    RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH => 'Student research runtime',
) as $audience => $body) $notice[$audience] = $noticeService->publish($audience, 'en', $body, 1);

$authority = new RecordingDecisionAuthorityService();
$control = new RecordingControlPlane();
$teacherUser = dzn_ra_user('teacher'); $teacher = dzn_ra_teacher('Recording Teacher'); dzn_ra_link_teacher($teacher, $teacherUser);
$studentUser = dzn_ra_user('student'); $student = dzn_ra_student('Recording Student', 'adult'); dzn_ra_link_student($student, $studentUser);
wp_set_current_user($teacherUser);

// Teacher consent is required before any lesson can request capture.
$lessonNoTeacher = dzn_ra_lesson($teacher, $student);
update_option(RecordingFeatureFlags::STAGING_COHORT_OPTION, array($lessonNoTeacher['lesson_id']), false); dzn_ra_health();
$refused = false;
try { $control->requestRecording($lessonNoTeacher['lesson_id'], 'no-teacher-consent-0001', 1); } catch (RuntimeException $error) { $refused = str_starts_with($error->getMessage(), 'recording_not_authorised:teacher_terms_missing'); }
dzn_ra_assert($refused, 'missing teacher terms must deny capture');
$authority->acceptTeacherTerms($notice[RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS]['uid'], 'en', 'ra-teacher-terms-0001');
$authority->recordTeacherChoice(ConsentPurposeRegistry::TEACHER_RECORDING, 'allow', $notice[RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RECORDING]['uid'], 'en', 'ra-teacher-recording-0001');
$authority->recordTeacherChoice(ConsentPurposeRegistry::TEACHER_RESEARCH, 'opt_in', $notice[RecordingDecisionAuthorityService::AUDIENCE_TEACHER_RESEARCH]['uid'], 'en', 'ra-teacher-research-0001');
$refused = false;
try { $control->requestRecording($lessonNoTeacher['lesson_id'], 'no-student-consent-0001', 1); } catch (RuntimeException $error) { $refused = str_starts_with($error->getMessage(), 'recording_not_authorised:student_recording_missing'); }
dzn_ra_assert($refused, 'missing student recording consent must deny capture even after teacher consent');

wp_set_current_user($studentUser);
$authority->recordOwnStudentChoice(ConsentPurposeRegistry::STUDENT_RECORDING, 'allow', $notice[RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING]['uid'], 'en', 'ra-student-recording-0001');
$authority->recordOwnStudentChoice(ConsentPurposeRegistry::STUDENT_RESEARCH, 'opt_in', $notice[RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH]['uid'], 'en', 'ra-student-research-0001');

// A minor without guardian authority is denied.
$minorUser = dzn_ra_user('minor'); $minor = dzn_ra_student('Minor Student', 'minor'); dzn_ra_link_student($minor, $minorUser);
$lessonMinor = dzn_ra_lesson($teacher, $minor);
update_option(RecordingFeatureFlags::STAGING_COHORT_OPTION, array($lessonNoTeacher['lesson_id'], $lessonMinor['lesson_id']), false);
$refused = false;
try { $control->requestRecording($lessonMinor['lesson_id'], 'no-guardian-0001', 1); } catch (RuntimeException $error) { $refused = str_starts_with($error->getMessage(), 'recording_not_authorised:guardian_authority_missing'); }
dzn_ra_assert($refused, 'minor without guardian authority must be denied');

// Authorised request, immutable evidence and durable start command.
$first = $control->requestRecording($lessonNoTeacher['lesson_id'], 'authorised-request-0001', 1);
dzn_ra_assert($first['state'] === RecordingLifecycle::RECORDING_REQUESTED && $first['content_version'] === 1, 'authorised request must enter recording_requested');
$recording = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recordings WHERE id=%d", $first['recording_id']));
dzn_ra_assert($recording !== null && (int) $recording->authorisation_evidence_id > 0, 'authorisation evidence must be persisted');
dzn_ra_assert(preg_match('/^[a-f0-9]{64}$/D', (string) $recording->authorisation_digest) === 1, 'authorisation digest must be persisted');
dzn_ra_assert((string) $recording->schedule_version_id === (string) $lessonNoTeacher['schedule_id'], 'exact schedule version must be snapshotted');
$commands = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_commands WHERE recording_id=%d AND command_type='start'", $first['recording_id']));
dzn_ra_assert($commands === 1, 'one durable start command must be written');
$events = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_events WHERE recording_id=%d", $first['recording_id']));
dzn_ra_assert($events >= 2, 'authorised/request events must be append-only');
// A fresh WordPress process (new control-plane instance) can claim the durable outbox command.
$claimed = (new RecordingControlPlane())->claimCommands(10);
dzn_ra_assert(count($claimed) >= 1, 'WordPress restart must still expose the pending command');
$claimedType = '';
foreach ($claimed as $envelope) if (($envelope['payload']['command_type'] ?? '') === 'start') $claimedType = 'start';
dzn_ra_assert($claimedType === 'start', 'pending start command must survive a WordPress restart');
$wpdb->update($p . 'recording_commands', array('state' => 'pending', 'lease_token_digest' => null, 'lease_expires_at' => null, 'available_at' => $now), array('recording_id' => $first['recording_id'], 'command_type' => 'start'));
$replay = $control->requestRecording($lessonNoTeacher['lesson_id'], 'authorised-request-0001', 1);
dzn_ra_assert($replay['idempotent'] === true && $replay['recording_id'] === $first['recording_id'], 'duplicate request must replay');
dzn_ra_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recordings WHERE lesson_id=%d", $lessonNoTeacher['lesson_id'])) === 1, 'duplicate request must not append a recording');

// Disabled cohort and stale readiness fail closed.
$uncohort = dzn_ra_lesson($teacher, $student);
dzn_ra_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}recordings") === 1, 'only the authorised recording exists so far');

// Emergency stop is durable, blocks new capture and queues a stop for active/requested work.
$emergencyLesson = dzn_ra_lesson($teacher, $student);
update_option(RecordingFeatureFlags::STAGING_COHORT_OPTION, array($emergencyLesson['lesson_id']), false);
dzn_ra_health();
$emergencyRecording = $control->requestRecording($emergencyLesson['lesson_id'], 'emergency-stop-request-0001', 1);
$engaged = $control->engageEmergencyStop(1, 'classroom_performance');
dzn_ra_assert(($engaged['engaged'] ?? false) === true, 'emergency stop must persist');
dzn_ra_assert(RecordingFeatureFlags::emergencyStop()['engaged'] === true, 'emergency stop must be visible to the capture gate');
dzn_ra_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_commands WHERE recording_id=%d AND command_type='stop'", $emergencyRecording['recording_id'])) >= 1, 'emergency stop must queue a stop command');
$blockedLesson = dzn_ra_lesson($teacher, $student);
update_option(RecordingFeatureFlags::STAGING_COHORT_OPTION, array($blockedLesson['lesson_id']), false);
$refused = false;
try { $control->requestRecording($blockedLesson['lesson_id'], 'emergency-blocked-0001', 1); } catch (RuntimeException $error) { $refused = $error->getMessage() === 'recording_capture_disabled'; }
dzn_ra_assert($refused, 'emergency stop must refuse every new capture');
$control->releaseEmergencyStop(1);
dzn_ra_assert(RecordingFeatureFlags::emergencyStop()['engaged'] === false, 'emergency stop must be releasable');
// Restore the cohort used by the following readiness assertions.
update_option(RecordingFeatureFlags::STAGING_COHORT_OPTION, array($lessonNoTeacher['lesson_id'], $lessonMinor['lesson_id'], $emergencyLesson['lesson_id']), false);

// The normal global switch is separate from the emergency stop and queues stops when disabled.
$globalOff = $control->setGlobalCapture(false, 1, 'runtime_test');
dzn_ra_assert(($globalOff['enabled'] ?? true) === false, 'global capture switch must disable capture');
dzn_ra_assert(RecordingFeatureFlags::captureEnabledForLesson($lessonNoTeacher['lesson_id']) === false, 'disabled global capture must refuse new capture');
dzn_ra_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_commands WHERE recording_id=%d AND command_type='stop'", $emergencyRecording['recording_id'])) >= 1, 'disabling global capture must queue a stop');
$globalOn = $control->setGlobalCapture(true, 1, 'runtime_test');
dzn_ra_assert(($globalOn['enabled'] ?? false) === true, 'global capture switch must re-enable capture');
dzn_ra_assert(RecordingFeatureFlags::captureEnabledForLesson($lessonNoTeacher['lesson_id']) === true, 'enabled global capture must restore the normal gates');
$refused = false;
try { $control->requestRecording($uncohort['lesson_id'], 'uncohort-request-0001', 1); } catch (RuntimeException $error) { $refused = $error->getMessage() === 'recording_capture_disabled'; }
dzn_ra_assert($refused, 'lesson outside the staging cohort must be refused');
update_option(RecordingFeatureFlags::SERVICE_HEALTH_OPTION, array('received_at' => time() - 3600, 'ready' => true), false);
$refused = false;
try { $control->requestRecording($lessonNoTeacher['lesson_id'], 'stale-health-0001', 1); } catch (RuntimeException $error) { $refused = str_starts_with($error->getMessage(), 'recording_not_authorised') || $error->getMessage() === 'recording_service_health_stale'; }
dzn_ra_assert($refused, 'stale recorder health must refuse readiness');
dzn_ra_health();

$uid = (string) $recording->uid; $sha = hash('sha256', 'recording-media-' . $uid); $bytes = 17202;
// Out-of-order completion must not skip required states.
$delayed = dzn_ra_event($control, array('event' => 'egress_completed', 'recording_uid' => $uid, 'content_version' => 1, 'idempotency_key' => hash('sha256', 'early-complete'), 'source' => 'recorder'));
dzn_ra_assert(($delayed['reason'] ?? '') === 'out_of_order', 'early completion must be recorded as out-of-order');
dzn_ra_assert((string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}recordings WHERE id=%d", $first['recording_id'])) === RecordingLifecycle::RECORDING_REQUESTED, 'out-of-order event must not advance state');
$started = dzn_ra_event($control, array('event' => 'egress_started', 'recording_uid' => $uid, 'content_version' => 1, 'provider_egress_id' => 'EG_TEST_1', 'idempotency_key' => hash('sha256', 'egress-start'), 'source' => 'recorder'));
dzn_ra_assert(($started['state'] ?? '') === RecordingLifecycle::RECORDING_ACTIVE, 'egress start must enter recording_active');
$duplicate = dzn_ra_event($control, array('event' => 'egress_started', 'recording_uid' => $uid, 'content_version' => 1, 'provider_egress_id' => 'EG_TEST_1', 'idempotency_key' => hash('sha256', 'egress-start'), 'source' => 'recorder'));
dzn_ra_assert(($duplicate['idempotent'] ?? false) === true, 'duplicate webhook must be idempotent');
dzn_ra_event($control, array('event' => 'egress_completed', 'recording_uid' => $uid, 'content_version' => 1, 'idempotency_key' => hash('sha256', 'egress-complete'), 'source' => 'recorder'));
dzn_ra_event($control, dzn_ra_media_payload($uid, hash('sha256', 'media-verified'), $sha, $bytes));
$state = (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}recordings WHERE id=%d", $first['recording_id']));
dzn_ra_assert($state === RecordingLifecycle::RECORDING_VERIFIED, 'media evidence must verify the recording');
dzn_ra_assert((int) $wpdb->get_var($wpdb->prepare("SELECT source_bytes FROM {$p}recordings WHERE id=%d", $first['recording_id'])) === $bytes, 'source bytes must be persisted');
$transfer = dzn_ra_event($control, array('event' => 'transfer_started', 'recording_uid' => $uid, 'content_version' => 1, 'idempotency_key' => hash('sha256', 'transfer-start'), 'source' => 'recorder'));
dzn_ra_assert(($transfer['state'] ?? '') === RecordingLifecycle::TRANSFERRING, 'transfer start must enter transferring');
dzn_ra_event($control, array('event' => 'transfer_failed', 'recording_uid' => $uid, 'content_version' => 1, 'idempotency_key' => hash('sha256', 'transfer-fail-1'), 'failure_class' => 'archive_mount_absent', 'source' => 'recorder'));
dzn_ra_assert((string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}recordings WHERE id=%d", $first['recording_id'])) === RecordingLifecycle::TRANSFER_RETRY_PENDING, 'transfer failure must enter retry pending');
dzn_ra_event($control, array('event' => 'transfer_started', 'recording_uid' => $uid, 'content_version' => 1, 'idempotency_key' => hash('sha256', 'transfer-start-2'), 'source' => 'recorder'));
dzn_ra_event($control, array('event' => 'transfer_verified', 'recording_uid' => $uid, 'content_version' => 1, 'idempotency_key' => hash('sha256', 'transfer-verified'), 'bytes' => $bytes, 'sha256' => $sha, 'source' => 'recorder'));
$confirmation = array('recording_uid' => $uid, 'content_version' => 1, 'source_bytes' => $bytes, 'destination_bytes' => $bytes, 'source_sha256' => $sha, 'destination_sha256' => $sha,
    'archive_node_identity' => 'mbox-test-node', 'destination_object_key' => 'L/' . $uid . '/1/' . $sha . '.mp4',
    'durable_commit_receipt' => 'dev=1;ino=2;size=' . $bytes, 'confirmation_time' => gmdate('Y-m-d H:i:s'));
$confirmation['confirmation_signature'] = hash_hmac('sha256', DZN_MBOX_CONFIRMATION_KEY_ID . '|' . RecordingSignature::canonicalJson(array(
    'recording_uid' => $confirmation['recording_uid'], 'content_version' => $confirmation['content_version'],
    'source_bytes' => $confirmation['source_bytes'], 'destination_bytes' => $confirmation['destination_bytes'],
    'source_sha256' => $confirmation['source_sha256'], 'destination_sha256' => $confirmation['destination_sha256'],
    'archive_node_identity' => $confirmation['archive_node_identity'], 'destination_object_key' => $confirmation['destination_object_key'],
    'durable_commit_receipt' => $confirmation['durable_commit_receipt'], 'confirmation_time' => $confirmation['confirmation_time'],
)), DZN_MBOX_CONFIRMATION_SECRET);
$archived = dzn_ra_event($control, array('event' => 'archive_confirmed', 'recording_uid' => $uid, 'content_version' => 1, 'idempotency_key' => hash('sha256', 'archive-confirmed'), 'confirmation' => $confirmation, 'source' => 'recorder'));
dzn_ra_assert(($archived['state'] ?? '') === RecordingLifecycle::ARCHIVED, 'archive confirmation must enter archived');
dzn_ra_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_archive_confirmations WHERE recording_id=%d", $first['recording_id'])) === 1, 'confirmation must be persisted');
dzn_ra_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_commands WHERE recording_id=%d AND command_type='source_deletion'", $first['recording_id'])) >= 1, 'deletion must be queued after confirmation');
dzn_ra_assert((string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}recordings WHERE id=%d", $first['recording_id'])) === RecordingLifecycle::VPS_DELETION_PENDING, 'deletion queue must enter vps_deletion_pending');

// Playback is granted to the owning student only after archive and no withdrawal.
dzn_ra_health();
$playback = (new RecordingPlaybackGrantService())->issueForRecording($uid, 'runtime');
dzn_ra_assert($playback['gateway_url'] === 'https://playback.test/', 'gateway URL must be the configured audience endpoint');
$verifiedGrant = RecordingSignature::verify($playback['grant'], DZN_RECORDING_PLAYBACK_SECRET, RecordingPlaybackGrantService::AUDIENCE, time());
dzn_ra_assert($verifiedGrant['payload']['recording_uid'] === $uid && (int) $verifiedGrant['payload']['exp'] - time() <= 300, 'playback grant must be short-lived and recording-bound');
$access = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_playback_access WHERE recording_id=%d AND event_type='allow'", $first['recording_id']));
dzn_ra_assert($access === 1, 'playback allow must be audited');
$otherUser = dzn_ra_user('other'); wp_set_current_user($otherUser);
$refused = false; try { (new RecordingPlaybackGrantService())->issueForRecording($uid, 'runtime'); } catch (RuntimeException $error) { $refused = str_starts_with($error->getMessage(), 'recording_playback_denied'); }
dzn_ra_assert($refused, 'unrelated principal playback must be denied');
wp_set_current_user($teacherUser);
$teacherPlayback = (new RecordingPlaybackGrantService())->issueForRecording($uid, 'runtime');
dzn_ra_assert($teacherPlayback['grant']['payload']['principal_type'] === 'teacher', 'assigned teacher playback must be authorised');
wp_set_current_user($studentUser);
dzn_ra_health(array('archive_available' => false, 'archive_mount_identity' => ''));
$refused = false; try { (new RecordingPlaybackGrantService())->issueForRecording($uid, 'runtime'); } catch (RuntimeException $error) { $refused = str_starts_with($error->getMessage(), 'recording_playback_denied:archive_unavailable'); }
dzn_ra_assert($refused, 'offline archive must deny playback grant');
dzn_ra_health();

// WordPress independently authorises deletion before the recorder may unlink the source.
$deletionRequest = RecordingSignature::sign(array('recording_uid' => $uid, 'content_version' => 1, 'destination_sha256' => $sha), DZN_RECORDING_SERVICE_SECRET, RecordingFeatureFlags::serviceKeyId(), RecordingControlPlane::DELETION_AUDIENCE, time(), 120);
$deletionResponse = RecordingSignature::verify($control->deletionAuthorisation($deletionRequest), DZN_RECORDING_SERVICE_SECRET, RecordingControlPlane::DELETION_RESPONSE_AUDIENCE, time());
dzn_ra_assert(($deletionResponse['payload']['authorised'] ?? false) === true, 'archived confirmation must authorise deletion');
$badDeletionRequest = RecordingSignature::sign(array('recording_uid' => $uid, 'content_version' => 1, 'destination_sha256' => hash('sha256', 'other')), DZN_RECORDING_SERVICE_SECRET, RecordingFeatureFlags::serviceKeyId(), RecordingControlPlane::DELETION_AUDIENCE, time(), 120);
$badDeletionResponse = RecordingSignature::verify($control->deletionAuthorisation($badDeletionRequest), DZN_RECORDING_SERVICE_SECRET, RecordingControlPlane::DELETION_RESPONSE_AUDIENCE, time());
dzn_ra_assert(($badDeletionResponse['payload']['authorised'] ?? true) === false, 'digest mismatch must refuse deletion authorisation');

// Legal hold prevents source deletion until released.
$job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recordings WHERE id=%d", $first['recording_id']));
$control->setLegalHold($first['recording_id'], true, 1, 'legal_review');
$heldDeletion = RecordingSignature::verify($control->deletionAuthorisation($deletionRequest), DZN_RECORDING_SERVICE_SECRET, RecordingControlPlane::DELETION_RESPONSE_AUDIENCE, time());
dzn_ra_assert(($heldDeletion['payload']['authorised'] ?? true) === false && ($heldDeletion['payload']['reason'] ?? '') === 'legal_hold_active', 'legal hold must refuse deletion authorisation');
$refused = false;
try { dzn_ra_event($control, array('event' => 'source_deleted', 'recording_uid' => $uid, 'content_version' => 1, 'idempotency_key' => hash('sha256', 'source-deleted-held'), 'deleted_source_bytes' => $bytes, 'deleted_source_sha256' => $sha, 'source' => 'recorder')); } catch (RuntimeException $error) { $refused = $error->getMessage() === 'recording_legal_hold_active'; }
dzn_ra_assert($refused, 'legal hold must refuse source deletion');
$control->setLegalHold($first['recording_id'], false, 1, 'legal_release');
$deleted = dzn_ra_event($control, array('event' => 'source_deleted', 'recording_uid' => $uid, 'content_version' => 1, 'idempotency_key' => hash('sha256', 'source-deleted'), 'deleted_source_bytes' => $bytes, 'deleted_source_sha256' => $sha, 'source' => 'recorder'));
dzn_ra_assert(($deleted['state'] ?? '') === RecordingLifecycle::ARCHIVED_VPS_DELETED, 'verified deletion must enter archived_vps_deleted');
dzn_ra_assert((string) $wpdb->get_var($wpdb->prepare("SELECT authoritative_location FROM {$p}recordings WHERE id=%d", $first['recording_id'])) === 'mbox', 'authoritative location must become the Pi archive');

// A reschedule before capture invalidates; a started recording is never re-bound.
$rescheduleLesson = dzn_ra_lesson($teacher, $student);
update_option(RecordingFeatureFlags::STAGING_COHORT_OPTION, array($rescheduleLesson['lesson_id']), false);
$rescheduleRecording = $control->requestRecording($rescheduleLesson['lesson_id'], 'reschedule-request-0001', 1);
$invalidated = RecordingInvalidationListener::invalidateUnstarted($rescheduleLesson['lesson_id'], 'schedule_revise');
dzn_ra_assert(count($invalidated) === 1 && $invalidated[0]['state'] === RecordingLifecycle::FAILED, 'reschedule must invalidate an unstarted recording');
dzn_ra_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_commands WHERE recording_id=%d AND command_type='start' AND state IN ('pending','leased')", $rescheduleRecording['recording_id'])) === 0, 'start command must be cancelled on invalidation');

// Withdrawal during capture queues stop and quarantines the affected media.
$withdrawLesson = dzn_ra_lesson($teacher, $student);
update_option(RecordingFeatureFlags::STAGING_COHORT_OPTION, array($withdrawLesson['lesson_id']), false);
$withdrawRecording = $control->requestRecording($withdrawLesson['lesson_id'], 'withdraw-request-0001', 1);
$withdrawRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recordings WHERE id=%d", $withdrawRecording['recording_id']));
dzn_ra_event($control, array('event' => 'egress_started', 'recording_uid' => $withdrawRow->uid, 'content_version' => 1, 'provider_egress_id' => 'EG_WITHDRAW', 'idempotency_key' => hash('sha256', 'withdraw-start'), 'source' => 'recorder'));
wp_set_current_user($studentUser);
$withdrawal = (new ConsentWithdrawalService())->withdrawStudentRecording($notice[RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING]['uid'], 'en', 'ra-student-withdraw-0001');
dzn_ra_assert($withdrawal['state'] === 'decline', 'withdrawal must append a decline decision');
dzn_ra_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_commands WHERE recording_id=%d AND command_type='stop'", $withdrawRecording['recording_id'])) >= 1, 'withdrawal during capture must queue a server-side stop');
dzn_ra_assert((string) $wpdb->get_var($wpdb->prepare("SELECT disposition_state FROM {$p}recordings WHERE id=%d", $withdrawRecording['recording_id'])) !== 'none', 'withdrawal must set a disposition');
dzn_ra_event($control, dzn_ra_media_payload((string) $withdrawRow->uid, hash('sha256', 'withdraw-media'), hash('sha256', 'withdraw-sha'), $bytes));
dzn_ra_assert((string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}recordings WHERE id=%d", $withdrawRecording['recording_id'])) === RecordingLifecycle::QUARANTINED, 'withdrawal-affected media must be quarantined');
$refused = false; try { (new RecordingPlaybackGrantService())->issueForRecording((string) $withdrawRow->uid, 'runtime'); } catch (RuntimeException $error) { $refused = str_starts_with($error->getMessage(), 'recording_playback_denied'); }
dzn_ra_assert($refused, 'withdrawn recording playback must be denied');
$refused = false; try { (new RecordingPlaybackGrantService())->issueForRecording((string) $recording->uid, 'runtime'); } catch (RuntimeException $error) { $refused = str_starts_with($error->getMessage(), 'recording_playback_denied'); }
dzn_ra_assert($refused, 'withdrawal must revoke playback of already archived material');
wp_set_current_user(1);

// A new request after withdrawal is denied; administrator cannot override.
update_option(RecordingFeatureFlags::STAGING_COHORT_OPTION, array($lessonNoTeacher['lesson_id'], $withdrawLesson['lesson_id']), false);
$refused = false;
try { $control->requestRecording($lessonNoTeacher['lesson_id'], 'post-withdrawal-0001', 1); } catch (RuntimeException $error) { $refused = str_starts_with($error->getMessage(), 'recording_not_authorised:student_recording_'); }
dzn_ra_assert($refused, 'administrator must not override a withdrawn student decision');

// Recording consent without current research consent cannot enter the ML dataset.
wp_set_current_user($studentUser);
(new ConsentWithdrawalService())->withdrawStudentResearch($notice[RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH]['uid'], 'en', 'ra-student-research-withdraw-0001');
$ml = new MlDatasetEligibilityService();
$evaluation = $ml->evaluate(array('source_recording_id' => (string) $recording->uid, 'lesson_id' => $lessonNoTeacher['lesson_id'], 'content_digest' => $sha));
dzn_ra_assert(($evaluation['eligible'] ?? true) === false, 'recording consent alone must not make material research-eligible');
dzn_ra_assert(RecordingDecisionAuthorityService::RESEARCH_EXPORT_ENABLED === false, 'research export must remain disabled');

echo "Recording archive behavior runtime: PASS\n";
