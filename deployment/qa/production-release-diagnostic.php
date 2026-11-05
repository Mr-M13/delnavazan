<?php
/**
 * Read-only production release diagnostic.
 *
 * Executed through `wp eval` on the production host. It performs SELECT/read operations only and
 * never writes production state. It never prints a secret value; Stripe output is limited to
 * presence/scope booleans.
 */
global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$count = static function (string $table, string $where = '1=1') use ($wpdb, $p): int {
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table} WHERE {$where}");
};
$group = static function (string $table, string $column) use ($wpdb, $p): array {
    $rows = $wpdb->get_results("SELECT {$column} label,COUNT(*) total FROM {$p}{$table} GROUP BY {$column} ORDER BY {$column}", ARRAY_A) ?: array();
    $out = array();
    foreach ($rows as $row) $out[(string) $row['label']] = (int) $row['total'];
    return $out;
};
$meetWorkerClass = 'Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\GoogleWorkspaceWorker';
$meetHook = class_exists($meetWorkerClass) ? (string) $meetWorkerClass::HOOK : 'dzn_google_meet_classroom_worker';
$meetOccurrences = 0;
$meetRecurrences = array();
$meetNextRun = null;
$cron = _get_cron_array();
$hooks = array();
foreach ((array) $cron as $timestamp => $events) {
    foreach ((array) $events as $hook => $unused) {
        if ((string) $hook === $meetHook) {
            $meetOccurrences += is_array($unused) ? count($unused) : 1;
            if ($meetNextRun === null || (int) $timestamp < (int) $meetNextRun) $meetNextRun = (int) $timestamp;
            foreach ((array) $unused as $event) {
                $schedule = is_array($event) ? (string) ($event['schedule'] ?? '') : '';
                if ($schedule !== '') $meetRecurrences[$schedule] = true;
            }
        }
        if (in_array((string) $hook, array('dzn_post_intro_activation_dispatch', 'dzn_lesson_completion_automation_dispatch', 'dzn_lesson_reschedule_dispatch', 'dzn_google_calendar_projection_dispatch', $meetHook), true)) {
            $hooks[(string) $hook] = gmdate('Y-m-d H:i:s', (int) $timestamp);
        }
    }
}
$meetFlagReport = static function (string $name, bool $default, bool $emptyConstantIsFalse, ?string $option): array {
    if (defined($name)) {
        $value = strtolower(trim((string) constant($name)));
        $effective = $emptyConstantIsFalse && $value === '' ? false : !in_array($value, array('0', 'false', 'off', 'no'), true);
        return array('effective' => $effective, 'source' => 'constant');
    }
    $environment = getenv($name);
    if (is_string($environment) && trim($environment) !== '') {
        $value = strtolower(trim($environment));
        $effective = $emptyConstantIsFalse && $value === '' ? false : !in_array($value, array('0', 'false', 'off', 'no'), true);
        return array('effective' => $effective, 'source' => 'environment');
    }
    if ($option !== null) {
        $value = get_option($option, null);
        if ($value !== null) return array('effective' => (string) $value === '1', 'source' => 'application_option');
    }
    return array('effective' => $default, 'source' => 'default');
};
$meetFeatureClass = 'Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\GoogleWorkspaceFeatureFlags';
$meetFlags = class_exists($meetFeatureClass)
    ? $meetFeatureClass::report()
    : array(
        'classroom_enabled' => $meetFlagReport('DZN_MEET_CLASSROOM_ENABLED', true, false, null),
        'auto_reconcile' => $meetFlagReport('DZN_MEET_AUTO_RECONCILE', false, true, 'dzn_meet_auto_reconcile'),
    );
$stripeClass = 'Delnavazan\\Platform\\Core\\Application\\PaymentExecution\\StripeTestConfigurationService';
$stripe = array();
$publishableTest = false;
$testEnvironmentAllowed = null;
$testActivationAllowed = null;
if (class_exists($stripeClass)) {
    $service = new $stripeClass();
    $stripe = $service->savedState();
    $publishableTest = $service->publishableKey() !== '';
    $testEnvironmentAllowed = $stripeClass::testEnvironmentAllowed();
    $adapter = 'Delnavazan\\Platform\\Integrations\\Payment\\Stripe\\StripeCheckoutAdapter';
    $testActivationAllowed = class_exists($adapter) ? $adapter::testModeActivationAllowed() : null;
}
$accounts = $wpdb->get_results("SELECT mode,state,execution_state,COUNT(*) total FROM {$p}payment_provider_accounts WHERE provider_key='stripe' GROUP BY mode,state,execution_state ORDER BY mode,state,execution_state", ARRAY_A) ?: array();
$policy = $wpdb->get_row("SELECT policy_version,value_type,policy_value,effective_from,status FROM {$p}commercial_policies WHERE policy_key='INSTALMENT_DUE_DATE_POLICY' ORDER BY policy_version DESC,id DESC LIMIT 1", ARRAY_A);
$twoStateRows = $wpdb->get_results("SELECT state label,COUNT(*) total FROM {$p}commercial_offers WHERE plan_kind='two_instalments' GROUP BY state ORDER BY state", ARRAY_A) ?: array();
$twoStates = array();
foreach ($twoStateRows as $row) $twoStates[(string) $row['label']] = (int) $row['total'];
$lessonRows = $wpdb->get_results("SELECT lifecycle_state label,COUNT(*) total FROM {$p}lessons WHERE record_model='canonical_term_lesson_v1' GROUP BY lifecycle_state ORDER BY lifecycle_state", ARRAY_A) ?: array();
$canonicalLessons = array();
foreach ($lessonRows as $row) $canonicalLessons[(string) $row['label']] = (int) $row['total'];
$failedJobs = $wpdb->get_results("SELECT last_error_code label,COUNT(*) total,MIN(created_at) oldest,MAX(updated_at) newest FROM {$p}post_intro_activation_jobs WHERE state='failed' GROUP BY last_error_code ORDER BY total DESC", ARRAY_A) ?: array();
$latestJobs = $wpdb->get_results("SELECT id,state,attempt_count,last_error_code,checkpoint,created_at,updated_at,completed_at FROM {$p}post_intro_activation_jobs ORDER BY updated_at DESC,id DESC LIMIT 5", ARRAY_A) ?: array();
$failedCalendarJobs = $wpdb->get_results("SELECT last_error_code label,COUNT(*) total,MIN(created_at) oldest,MAX(updated_at) newest FROM {$p}lesson_reschedule_calendar_jobs WHERE state='failed' GROUP BY last_error_code ORDER BY total DESC", ARRAY_A) ?: array();
$proposalKindRows = $wpdb->get_results("SELECT COALESCE((SELECT e.reason_code FROM {$p}lesson_reschedule_events e WHERE e.proposal_id=r.id AND e.event_type='proposed' ORDER BY e.id ASC LIMIT 1),'unknown') proposal_reason,r.state,COUNT(*) total FROM {$p}lesson_reschedule_proposals r GROUP BY proposal_reason,r.state ORDER BY proposal_reason,r.state", ARRAY_A) ?: array();
$failedCalendarDetail = $wpdb->get_results("SELECT j.id,j.proposal_id,j.lesson_id,j.from_schedule_version_id,j.to_schedule_version_id,j.attempt_count,j.last_error_code,j.updated_at,p.state proposal_state,l.lesson_type,l.lifecycle_state,v.applicable_slot to_applicable,v.superseded_at to_superseded FROM {$p}lesson_reschedule_calendar_jobs j INNER JOIN {$p}lesson_reschedule_proposals p ON p.id=j.proposal_id INNER JOIN {$p}lessons l ON l.id=j.lesson_id LEFT JOIN {$p}canonical_lesson_schedule_versions v ON v.id=j.to_schedule_version_id WHERE j.state='failed' ORDER BY j.updated_at DESC LIMIT 10", ARRAY_A) ?: array();
$recentCalendarProjections = $wpdb->get_results("SELECT m.lesson_id,m.schedule_version_id,m.projection_state,m.event_digest,m.provider_occurred_at_utc,m.updated_at,c.connection_state,c.identity_state,cr.state credential_state,cr.valid_until_utc credential_valid_until_utc,ps.ciphertext join_secret_ciphertext FROM {$p}provider_calendar_event_mappings m LEFT JOIN {$p}integration_connections c ON c.id=m.connection_id LEFT JOIN {$p}integration_credentials cr ON cr.connection_id=c.id AND cr.state='active' LEFT JOIN {$p}provider_projection_secrets ps ON ps.mapping_kind='calendar_event' AND ps.mapping_id=m.id AND ps.state='active' AND ps.active_slot=1 ORDER BY m.id DESC LIMIT 10", ARRAY_A) ?: array();
$recentMeetingProjections = $wpdb->get_results("SELECT lesson_id,schedule_version_id,projection_state,conference_digest,join_uri_digest,provider_occurred_at_utc,updated_at FROM {$p}provider_meeting_mappings ORDER BY id DESC LIMIT 10", ARRAY_A) ?: array();
$recentCalendarJobs = $wpdb->get_results("SELECT id,lesson_id,proposal_id,state,attempt_count,last_error_code,completed_at,updated_at FROM {$p}lesson_reschedule_calendar_jobs ORDER BY id DESC LIMIT 10", ARRAY_A) ?: array();
$staleCalendarJobs = $wpdb->get_results("SELECT id,state,attempt_count,next_attempt_at,last_error_code,created_at,updated_at FROM {$p}lesson_reschedule_calendar_jobs WHERE state IN ('queued','retry') AND (next_attempt_at IS NULL OR next_attempt_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)) ORDER BY updated_at ASC,id ASC LIMIT 5", ARRAY_A) ?: array();
$screenClass = 'Delnavazan\\Platform\\Admin\\Controller\\ScreenController';
$scheduleClass = 'Delnavazan\\Platform\\Core\\Application\\CanonicalLessonScheduleReadService';
$adminLessonScreen = array(
    'screen_controller_loaded' => class_exists($screenClass),
    'schedule_service_loaded' => class_exists($scheduleClass),
    'stale_admin_namespace_absent' => !class_exists('Delnavazan\\Platform\\Admin\\Controller\\CanonicalLessonScheduleReadService'),
    'schedule_methods' => array(),
);
foreach (array('forLesson', 'forLessonCompletion', 'forLessonDelivery') as $method) {
    $adminLessonScreen['schedule_methods'][$method] = class_exists($scheduleClass) && method_exists($scheduleClass, $method);
}
$actorInfo = static function (int $actorId): array {
    if ($actorId < 1) return array('configured' => false, 'active' => false, 'canonical_lessons' => false, 'canonical_schedules' => false, 'manage_options' => false, 'provider_integrations' => false);
    $user = get_user_by('id', $actorId);
    if (!$user instanceof WP_User) return array('configured' => true, 'active' => false, 'canonical_lessons' => false, 'canonical_schedules' => false, 'manage_options' => false, 'provider_integrations' => false);
    return array(
        'configured' => true,
        'active' => (int) ($user->user_status ?? 0) === 0,
        'canonical_lessons' => user_can($user, 'dzn_manage_canonical_lessons'),
        'canonical_schedules' => user_can($user, 'dzn_manage_canonical_lesson_schedules'),
        'manage_options' => user_can($user, 'manage_options'),
        'provider_integrations' => user_can($user, 'dzn_manage_provider_integrations'),
    );
};
$projectionWorkerClass = 'Delnavazan\\Platform\\Integrations\\ProviderProjectionWorkerContext';
$googleOAuthClass = 'Delnavazan\\Platform\\Integrations\\GoogleOAuthController';
$projectionWorkerReady = class_exists($projectionWorkerClass) && $projectionWorkerClass::principalId() > 0;
$googleOAuthConfigured = class_exists($googleOAuthClass) && $googleOAuthClass::configured();
$replacementActorId = (int) get_option('dzn_replacement_system_user_id', 0);
$rescheduleActorId = (int) get_option('dzn_reschedule_system_user_id', 0);
$calendarActorId = (int) get_option('dzn_reschedule_calendar_system_user_id', 0);

$meetConnection = $wpdb->get_row(
    "SELECT id,connection_state,identity_state,last_checked_at,failure_reason_code
     FROM {$p}meet_workspace_connections
     ORDER BY (active_slot=1) DESC,id DESC LIMIT 1",
    ARRAY_A
);
$meetCredential = $meetConnection
    ? $wpdb->get_row($wpdb->prepare(
        "SELECT state,valid_until_utc FROM {$p}meet_workspace_credentials
         WHERE connection_id=%d
         ORDER BY (state='active') DESC,credential_sequence DESC,id DESC LIMIT 1",
        (int) $meetConnection['id']
    ), ARRAY_A)
    : null;
$meetCredentialExpiration = 'missing';
if ($meetCredential) {
    if ($meetCredential['valid_until_utc'] === null) $meetCredentialExpiration = 'none';
    elseif ((int) strtotime((string) $meetCredential['valid_until_utc'] . ' UTC') <= time() + 60) $meetCredentialExpiration = 'expired';
    else $meetCredentialExpiration = 'valid';
}
$meetOrganizer = array(
    'configured' => class_exists('Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\GoogleWorkspaceOAuthService')
        && \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceOAuthService::configured(),
    'connection_state' => $meetConnection === null ? null : (string) $meetConnection['connection_state'],
    'identity_state' => $meetConnection === null ? null : (string) $meetConnection['identity_state'],
    'credential_state' => $meetCredential === null ? null : (string) $meetCredential['state'],
    'credential_expiration' => $meetCredentialExpiration,
    'last_checked_at' => $meetConnection === null ? null : $meetConnection['last_checked_at'],
    'failure_reason_code' => $meetConnection === null ? null : $meetConnection['failure_reason_code'],
);

$meetLessonIds = array(22, 23);
$meetLessonRows = $wpdb->get_results($wpdb->prepare(
    "SELECT l.id lesson_id,l.lifecycle_state lesson_lifecycle_state,
            v.id current_schedule_version_id,v.starts_at_utc,v.ends_at_utc,v.schedule_timezone,
            mc.id classroom_id,mc.classroom_state,mc.cohost_state,mc.cohost_error_code,mc.failure_reason_code,
            mc.schedule_version_id classroom_schedule_version_id,mc.updated_at classroom_updated_at,
            CASE WHEN mc.meet_uri_digest IS NULL OR mc.meet_uri_digest='' THEN 0 ELSE 1 END join_reference_present,
            CASE WHEN r.id IS NULL THEN 0 ELSE 1 END reference_present,
            pcm.projection_state provider_calendar_projection_state,pcm.updated_at provider_calendar_updated_at,
            pmm.projection_state provider_meeting_projection_state,pmm.updated_at provider_meeting_updated_at,
            CASE WHEN pmm.join_uri_digest IS NULL OR pmm.join_uri_digest='' THEN 0 ELSE 1 END provider_join_reference_present
     FROM {$p}lessons l
     LEFT JOIN {$p}canonical_lesson_schedule_versions v
       ON v.lesson_id=l.id AND v.applicable_slot=1 AND v.superseded_at IS NULL
     LEFT JOIN {$p}meet_classrooms mc
       ON mc.id=(SELECT mc2.id FROM {$p}meet_classrooms mc2
                  WHERE mc2.lesson_id=l.id
                  ORDER BY (mc2.active_slot=1) DESC,mc2.id DESC LIMIT 1)
     LEFT JOIN {$p}meet_classroom_references r
       ON r.classroom_id=mc.id AND r.active_slot=1 AND r.state='active'
     LEFT JOIN {$p}provider_calendar_event_mappings pcm
       ON pcm.lesson_id=l.id AND pcm.schedule_version_id=v.id AND pcm.active_slot=1
     LEFT JOIN {$p}provider_meeting_mappings pmm
       ON pmm.lesson_id=l.id AND pmm.schedule_version_id=v.id AND pmm.active_slot=1
     WHERE l.id IN (" . implode(',', array_fill(0, count($meetLessonIds), '%d')) . ")
     ORDER BY l.id",
    ...$meetLessonIds
), ARRAY_A) ?: array();
$meetLessons = array();
foreach ($meetLessonIds as $lessonId) {
    $meetLessons[$lessonId] = array('lesson_id' => $lessonId, 'found' => false);
}
foreach ($meetLessonRows as $row) {
    $lessonId = (int) $row['lesson_id'];
    $exists = (int) $row['classroom_id'] > 0;
    $classroomState = (string) ($row['classroom_state'] ?? '');
    $cohostState = (string) ($row['cohost_state'] ?? '');
    $referencePresent = (int) ($row['reference_present'] ?? 0) === 1;
    $joinReferencePresent = (int) ($row['join_reference_present'] ?? 0) === 1;
    $centralJoinReady = $exists && in_array($classroomState, array('ready', 'rescheduled'), true)
        && in_array($cohostState, array('verified', 'exception_approved'), true)
        && $referencePresent && $joinReferencePresent;
    $providerCalendar = (string) ($row['provider_calendar_projection_state'] ?? '');
    $providerMeeting = (string) ($row['provider_meeting_projection_state'] ?? '');
    $providerJoinReference = (int) ($row['provider_join_reference_present'] ?? 0) === 1;
    $providerJoinReady = $providerCalendar === 'verified' && $providerMeeting === 'verified' && $providerJoinReference;
    $meetLessons[$lessonId] = array(
        'lesson_id' => $lessonId,
        'found' => true,
        'lesson_lifecycle_state' => (string) ($row['lesson_lifecycle_state'] ?? ''),
        'schedule_version_id' => (int) ($row['current_schedule_version_id'] ?? 0),
        'starts_at_utc' => $row['starts_at_utc'] ?? null,
        'ends_at_utc' => $row['ends_at_utc'] ?? null,
        'schedule_timezone' => $row['schedule_timezone'] ?? null,
        'classroom_exists' => $exists,
        'classroom_state' => $exists ? $classroomState : null,
        'cohost_state' => $exists ? $cohostState : null,
        'active_schedule_version_match' => $exists
            && (int) ($row['classroom_schedule_version_id'] ?? 0) === (int) ($row['current_schedule_version_id'] ?? 0),
        'reference_present' => $referencePresent,
        'join_reference_present' => $joinReferencePresent,
        'central_join_ready' => $centralJoinReady,
        'provider_calendar_projection_state' => $providerCalendar !== '' ? $providerCalendar : null,
        'provider_meeting_projection_state' => $providerMeeting !== '' ? $providerMeeting : null,
        'provider_join_reference_present' => $providerJoinReference,
        'provider_join_ready' => $providerJoinReady,
        'projection_verified' => $centralJoinReady || $providerJoinReady,
        'join_ready' => $centralJoinReady || $providerJoinReady,
        'failure_code' => (string) ($row['failure_reason_code'] ?? '') ?: ((string) ($row['cohost_error_code'] ?? '') ?: null),
        'last_update_utc' => max(
            (string) ($row['classroom_updated_at'] ?? ''),
            (string) ($row['provider_calendar_updated_at'] ?? ''),
            (string) ($row['provider_meeting_updated_at'] ?? '')
        ) ?: null,
    );
}

$meetBookingId = 9;
$meetBookingRow = $wpdb->get_row($wpdb->prepare(
    "SELECT c.id,c.state confirmation_state,c.starts_at_utc,c.ends_at_utc,c.schedule_timezone,
            c.failure_reason_code confirmation_failure_reason_code,c.attempt_count,c.next_attempt_at,
            c.updated_at confirmation_updated_at,
            mc.id classroom_id,mc.classroom_state,mc.cohost_state,mc.cohost_error_code,
            mc.failure_reason_code classroom_failure_reason_code,mc.updated_at classroom_updated_at,
            CASE WHEN mc.meet_uri_digest IS NULL OR mc.meet_uri_digest='' THEN 0 ELSE 1 END join_reference_present,
            CASE WHEN r.id IS NULL THEN 0 ELSE 1 END reference_present
     FROM {$p}booking_request_confirmations c
     LEFT JOIN {$p}meet_classrooms mc
       ON mc.id=(SELECT mc2.id FROM {$p}meet_classrooms mc2
                  WHERE mc2.booking_confirmation_id=c.id
                  ORDER BY (mc2.active_slot=1) DESC,mc2.id DESC LIMIT 1)
     LEFT JOIN {$p}meet_classroom_references r
       ON r.classroom_id=mc.id AND r.active_slot=1 AND r.state='active'
     WHERE c.id=%d",
    $meetBookingId
), ARRAY_A);
$meetBooking = array('confirmation_id' => $meetBookingId, 'found' => false);
if ($meetBookingRow) {
    $exists = (int) $meetBookingRow['classroom_id'] > 0;
    $classroomState = (string) ($meetBookingRow['classroom_state'] ?? '');
    $cohostState = (string) ($meetBookingRow['cohost_state'] ?? '');
    $referencePresent = (int) ($meetBookingRow['reference_present'] ?? 0) === 1;
    $joinReferencePresent = (int) ($meetBookingRow['join_reference_present'] ?? 0) === 1;
    $confirmationUpdated = (string) ($meetBookingRow['confirmation_updated_at'] ?? '');
    $classroomUpdated = (string) ($meetBookingRow['classroom_updated_at'] ?? '');
    $meetBooking = array(
        'confirmation_id' => $meetBookingId,
        'found' => true,
        'confirmation_state' => (string) ($meetBookingRow['confirmation_state'] ?? ''),
        'starts_at_utc' => $meetBookingRow['starts_at_utc'] ?? null,
        'ends_at_utc' => $meetBookingRow['ends_at_utc'] ?? null,
        'schedule_timezone' => $meetBookingRow['schedule_timezone'] ?? null,
        'attempt_count' => (int) ($meetBookingRow['attempt_count'] ?? 0),
        'next_attempt_at' => $meetBookingRow['next_attempt_at'] ?? null,
        'classroom_exists' => $exists,
        'classroom_state' => $exists ? $classroomState : null,
        'cohost_state' => $exists ? $cohostState : null,
        'reference_present' => $referencePresent,
        'join_reference_present' => $joinReferencePresent,
        'join_ready' => $exists && in_array($classroomState, array('ready', 'rescheduled'), true)
            && in_array($cohostState, array('verified', 'exception_approved'), true)
            && $referencePresent && $joinReferencePresent,
        'failure_code' => (string) ($meetBookingRow['confirmation_failure_reason_code'] ?? '')
            ?: ((string) ($meetBookingRow['classroom_failure_reason_code'] ?? '')
                ?: ((string) ($meetBookingRow['cohost_error_code'] ?? '') ?: null)),
        'last_update_utc' => max($confirmationUpdated, $classroomUpdated) ?: null,
    );
}
$out = array(
    'schema' => (string) get_option('dzn_platform_schema_version'),
    'build' => defined('DZN_PLATFORM_BUILD_ID') ? (string) DZN_PLATFORM_BUILD_ID : null,
    'platform_version' => defined('DZN_PLATFORM_VERSION') ? (string) DZN_PLATFORM_VERSION : null,
    'wordpress_version' => (string) get_bloginfo('version'),
    'php_version' => PHP_VERSION,
    'plugin_active' => in_array('delnavazan-platform/delnavazan-platform.php', (array) get_option('active_plugins', array()), true),
    'stylesheet' => (string) get_option('stylesheet'),
    'maintenance_mode' => (function (): string {
        if (!function_exists('wp_is_maintenance_mode')) return 'unknown';
        return wp_is_maintenance_mode() ? 'active' : 'inactive';
    })(),
    'admin_lesson_screen' => $adminLessonScreen,
    'replacement_actor' => $actorInfo($replacementActorId),
    'reschedule_actor' => $actorInfo($rescheduleActorId),
    'calendar_actor' => $actorInfo($calendarActorId),
    'projection_worker_ready' => $projectionWorkerReady,
    'google_oauth_configured' => $googleOAuthConfigured,
    'replacement_workflow' => array(
        'proposals' => $group('lesson_reschedule_proposals', 'state'),
        'replacement_lessons' => $count('lessons', "record_model='canonical_term_lesson_v1' AND lesson_type='replacement' AND archived_at IS NULL"),
        'calendar_jobs' => $group('lesson_reschedule_calendar_jobs', 'state'),
        'failed_calendar_jobs' => $failedCalendarJobs,
        'stale_calendar_jobs' => $staleCalendarJobs,
        'notifications' => $group('lesson_reschedule_notifications', 'state'),
        'proposals_by_kind_state' => $proposalKindRows,
        'recent_calendar_projections' => array_map(static function (array $row): array {
            $row['event_reference_present'] = trim((string) ($row['event_digest'] ?? '')) !== '';
            $row['credential_expired'] = $row['credential_valid_until_utc'] !== null && strtotime((string) $row['credential_valid_until_utc'] . ' UTC') <= time() + 60;
            $row['join_reference_present'] = trim((string) ($row['join_secret_ciphertext'] ?? '')) !== '';
            $row['portal_join_ready'] = (string) ($row['projection_state'] ?? '') === 'verified' && $row['join_reference_present'];
            unset($row['event_digest'], $row['credential_valid_until_utc'], $row['join_secret_ciphertext']);
            return $row;
        }, $recentCalendarProjections),
        'recent_meeting_projections' => array_map(static function (array $row): array {
            $row['conference_reference_present'] = trim((string) ($row['conference_digest'] ?? '')) !== '';
            $row['join_reference_present'] = trim((string) ($row['join_uri_digest'] ?? '')) !== '';
            unset($row['conference_digest'], $row['join_uri_digest']);
            return $row;
        }, $recentMeetingProjections),
        'recent_calendar_jobs' => $recentCalendarJobs,
        'failed_calendar_detail' => array_map(static function (array $row) use ($wpdb, $p): array {
            $lessonId = (int) ($row['lesson_id'] ?? 0);
            $fromVersion = (int) ($row['from_schedule_version_id'] ?? 0);
            $toVersion = (int) ($row['to_schedule_version_id'] ?? 0);
            $row['to_applicable'] = (int) ($row['to_applicable'] ?? 0) === 1;
            $row['recoverable'] = (string) ($row['proposal_state'] ?? '') === 'accepted' && $row['to_applicable'] && $row['to_superseded'] === null;
            unset($row['to_superseded']);
            try {
                $applicable = \Delnavazan\Platform\Core\Application\ProviderIntegrationValidator::projectionApplicable($lessonId, $toVersion);
                $row['projection_applicable'] = !empty($applicable['applicable']);
                $row['projection_reason'] = $applicable['reason'] ?? null;
                $row['occurrence_aggregate_valid'] = \Delnavazan\Platform\Core\Application\ProviderIntegrationValidator::occurrenceAggregateValid($lessonId);
            } catch (\Throwable $e) {
                $row['projection_applicable'] = false;
                $row['projection_reason'] = 'projection_validation_exception';
                $row['occurrence_aggregate_valid'] = false;
            }
            $repository = new \Delnavazan\Platform\Core\Infrastructure\Repository\ProviderIntegrationRepository();
            $row['from_calendar_mapping_active'] = $repository->activeCalendarMapping($lessonId, $fromVersion) !== null;
            $toCalendar = $wpdb->get_row($wpdb->prepare("SELECT projection_state FROM {$p}provider_calendar_event_mappings WHERE lesson_id=%d AND schedule_version_id=%d ORDER BY id DESC LIMIT 1", $lessonId, $toVersion));
            $toMeeting = $wpdb->get_row($wpdb->prepare("SELECT projection_state FROM {$p}provider_meeting_mappings WHERE lesson_id=%d AND schedule_version_id=%d ORDER BY id DESC LIMIT 1", $lessonId, $toVersion));
            $teacherId = (int) $wpdb->get_var($wpdb->prepare("SELECT teacher_id FROM {$p}lessons WHERE id=%d", $lessonId));
            $connection = $wpdb->get_row($wpdb->prepare("SELECT id,connection_state,identity_state,active_slot FROM {$p}provider_connections WHERE provider_code='google_calendar' AND teacher_id=%d ORDER BY id DESC LIMIT 1", $teacherId));
            $credential = $connection ? $wpdb->get_row($wpdb->prepare("SELECT state,valid_until_utc FROM {$p}provider_credentials WHERE connection_id=%d ORDER BY id DESC LIMIT 1", (int) $connection->id)) : null;
            $row['to_calendar_mapping_state'] = $toCalendar === null ? null : (string) $toCalendar->projection_state;
            $row['to_meeting_mapping_state'] = $toMeeting === null ? null : (string) $toMeeting->projection_state;
            $row['teacher_connection_state'] = $connection === null ? null : (string) $connection->connection_state;
            $row['teacher_connection_identity_state'] = $connection === null ? null : (string) $connection->identity_state;
            $row['teacher_credential_state'] = $credential === null ? null : (string) $credential->state;
            $row['teacher_credential_expired'] = $credential !== null && $credential->valid_until_utc !== null && strtotime((string) $credential->valid_until_utc . ' UTC') <= time() + 60;
            return $row;
        }, $failedCalendarDetail),
    ),
    'meet_operational' => array(
        'read_only' => true,
        'flags' => $meetFlags,
        'worker' => array(
            'class_loaded' => class_exists($meetWorkerClass),
            'hook' => $meetHook,
            'hook_registered' => class_exists($meetWorkerClass) && has_action($meetHook) !== false,
            'scheduled' => $meetOccurrences > 0,
            'occurrences' => $meetOccurrences,
            'recurrence' => $meetRecurrences === array() ? null : implode(',', array_keys($meetRecurrences)),
            'next_run_utc' => $meetNextRun === null ? null : gmdate('Y-m-d H:i:s', (int) $meetNextRun),
            'cron_disabled' => defined('DISABLE_WP_CRON') ? (bool) DISABLE_WP_CRON : false,
        ),
        'organizer_oauth' => $meetOrganizer,
        'lessons' => $meetLessons,
        'booking_confirmation' => $meetBooking,
    ),
    'completed_052' => in_array('052_payment_plan_choice', (array) get_option('dzn_platform_completed_migrations', array()), true),
    'cron_disabled' => defined('DISABLE_WP_CRON') ? (bool) DISABLE_WP_CRON : false,
    'cron_hooks' => $hooks,
    'activation_jobs' => $group('post_intro_activation_jobs', 'state'),
    'failed_jobs' => $failedJobs,
    'latest_jobs' => $latestJobs,
    'preparation_states' => $group('post_intro_preparations', 'state'),
    'plan_choices' => $count('post_intro_plan_choices'),
    'attendance_cases' => $group('canonical_attendance_cases', 'state'),
    'attendance_conflicts' => $count('canonical_attendance_conflicts'),
    'payability' => array(
        'pending_total' => $count('finance_payability_evaluations', "disposition='pending' AND applicable_slot=1"),
        'presumed_completion_review' => $count('finance_payability_evaluations', "basis_code='presumed_completion_review' AND applicable_slot=1"),
    ),
    'canonical_lessons' => $canonicalLessons,
    'two_instalment_offers' => array(
        'total' => $count('commercial_offers', "plan_kind='two_instalments'"),
        'states' => $twoStates,
    ),
    'settlements' => $count('commercial_obligation_settlements'),
    'instalment_policy' => array(
        'present' => $policy !== null,
        'status' => $policy === null ? null : (string) $policy['status'],
        'policy_version' => $policy === null ? null : (int) $policy['policy_version'],
        'value_type' => $policy === null ? null : (string) $policy['value_type'],
        'configured' => $policy !== null && $policy['policy_value'] !== null,
        'effective_from' => $policy === null ? null : $policy['effective_from'],
    ),
    'stripe' => array(
        'account_id' => (int) ($stripe['account_id'] ?? 0),
        'execution_enabled' => !empty($stripe['execution_enabled']),
        'publishable_test_key' => $publishableTest,
        'secret_present' => !empty($stripe['secret']),
        'webhook_present' => !empty($stripe['webhook']),
        'test_environment_allowed' => $testEnvironmentAllowed,
        'test_activation_allowed' => $testActivationAllowed,
        'accounts' => $accounts,
    ),
);
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
