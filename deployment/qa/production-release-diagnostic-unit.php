<?php
/**
 * Pure output contract for the read-only production release diagnostic.
 *
 * The WordPress/WP-CLI surface is replaced with deterministic read-only stubs. No database,
 * provider, cron event, notification or booking is touched.
 */
namespace Delnavazan\Platform\Integrations\GoogleWorkspace {
    require_once __DIR__ . '/../../platform/src/Integrations/GoogleWorkspace/GoogleWorkspaceFeatureFlags.php';
    require_once __DIR__ . '/../../platform/src/Integrations/GoogleWorkspace/GoogleWorkspaceWorker.php';

    if (!class_exists(__NAMESPACE__ . '\\GoogleWorkspaceOAuthService')) {
        class GoogleWorkspaceOAuthService {
            public static function configured(): bool { return true; }
        }
    }
}

namespace {
    defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');

    $GLOBALS['dzn_diag_options'] = array(
        'active_plugins' => array('delnavazan-platform/delnavazan-platform.php'),
        'stylesheet' => 'delnavazan-theme',
        'dzn_platform_schema_version' => '58',
        'dzn_platform_completed_migrations' => array('052_payment_plan_choice'),
        'dzn_replacement_system_user_id' => 0,
        'dzn_reschedule_system_user_id' => 0,
        'dzn_reschedule_calendar_system_user_id' => 0,
    );
    $GLOBALS['dzn_diag_cron'] = array(
        time() + 300 => array(
            'dzn_google_meet_classroom_worker' => array(
                time() + 300 => array('schedule' => 'dzn_five_minutes', 'args' => array()),
            ),
            'dzn_post_intro_activation_dispatch' => array(
                time() + 600 => array('schedule' => 'dzn_five_minutes', 'args' => array()),
            ),
        ),
    );

    function get_option(string $key, mixed $default = false): mixed {
        return array_key_exists($key, $GLOBALS['dzn_diag_options'])
            ? $GLOBALS['dzn_diag_options'][$key]
            : $default;
    }
    function get_bloginfo(string $key = ''): string { return '6.4'; }
    function wp_is_maintenance_mode(): bool { return false; }
    function get_user_by(string $field, int $id): object|false { return false; }
    function user_can(int|object $user, string $capability): bool { return false; }
    function get_current_user_id(): int { return 0; }
    function has_action(string $hook): int|false {
        return $hook === 'dzn_google_meet_classroom_worker' ? 10 : false;
    }
    function _get_cron_array(): array { return $GLOBALS['dzn_diag_cron']; }

    final class DznDiagnosticWpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public function prepare(string $sql, mixed ...$args): string { return $sql; }
        public function get_var(string $sql): mixed {
            return str_contains($sql, 'COUNT(*)') ? 0 : null;
        }
        public function get_row(string $sql, mixed $mode = null): ?array {
            if (str_contains($sql, 'meet_workspace_connections')) {
                return array(
                    'id' => 4,
                    'connection_state' => 'connected',
                    'identity_state' => 'verified',
                    'last_checked_at' => '2026-11-05 01:00:00',
                    'failure_reason_code' => null,
                );
            }
            if (str_contains($sql, 'meet_workspace_credentials')) {
                return array('state' => 'active', 'valid_until_utc' => gmdate('Y-m-d H:i:s', time() + 3600));
            }
            if (str_contains($sql, 'booking_request_confirmations c')) {
                return array(
                    'id' => 9,
                    'confirmation_state' => 'failed',
                    'starts_at_utc' => '2026-11-05 05:45:00',
                    'ends_at_utc' => '2026-11-05 06:15:00',
                    'schedule_timezone' => 'Asia/Tehran',
                    'confirmation_failure_reason_code' => 'meet_cohost_assignment_blocked',
                    'attempt_count' => 6,
                    'next_attempt_at' => null,
                    'confirmation_updated_at' => '2026-11-05 01:30:00',
                    'classroom_id' => 901,
                    'classroom_state' => 'blocked',
                    'cohost_state' => 'blocked',
                    'cohost_error_code' => 'meet_cohost_assignment_refused',
                    'classroom_failure_reason_code' => 'meet_cohost_assignment_blocked',
                    'classroom_updated_at' => '2026-11-05 01:29:00',
                    'join_reference_present' => 0,
                    'reference_present' => 1,
                );
            }
            return null;
        }
        public function get_results(string $sql, mixed $mode = null): array {
            if (str_contains($sql, 'lessons l') && str_contains($sql, 'meet_classrooms')) {
                return array(
                    array(
                        'lesson_id' => 22,
                        'lesson_lifecycle_state' => 'authorised',
                        'current_schedule_version_id' => 26,
                        'starts_at_utc' => '2026-12-08 07:00:00',
                        'ends_at_utc' => '2026-12-08 07:30:00',
                        'schedule_timezone' => 'Asia/Tehran',
                        'classroom_id' => 2201,
                        'classroom_state' => 'ready',
                        'cohost_state' => 'verified',
                        'cohost_error_code' => null,
                        'failure_reason_code' => null,
                        'classroom_schedule_version_id' => 26,
                        'classroom_updated_at' => '2026-11-05 00:45:00',
                        'join_reference_present' => 1,
                        'reference_present' => 1,
                        'provider_calendar_projection_state' => 'verified',
                        'provider_calendar_updated_at' => '2026-11-05 00:40:00',
                        'provider_meeting_projection_state' => 'verified',
                        'provider_meeting_updated_at' => '2026-11-05 00:41:00',
                        'provider_join_reference_present' => 1,
                    ),
                    array(
                        'lesson_id' => 23,
                        'lesson_lifecycle_state' => 'authorised',
                        'current_schedule_version_id' => 27,
                        'starts_at_utc' => '2026-12-15 07:00:00',
                        'ends_at_utc' => '2026-12-15 07:30:00',
                        'schedule_timezone' => 'Asia/Tehran',
                        // Central classroom is intentionally absent; a verified provider
                        // projection must still be reported and must not look "missing".
                        'classroom_id' => 0,
                        'classroom_state' => null,
                        'cohost_state' => null,
                        'cohost_error_code' => null,
                        'failure_reason_code' => null,
                        'classroom_schedule_version_id' => null,
                        'classroom_updated_at' => null,
                        'join_reference_present' => 0,
                        'reference_present' => 0,
                        'provider_calendar_projection_state' => 'verified',
                        'provider_calendar_updated_at' => '2026-11-05 00:42:00',
                        'provider_meeting_projection_state' => 'verified',
                        'provider_meeting_updated_at' => '2026-11-05 00:43:00',
                        'provider_join_reference_present' => 1,
                    ),
                );
            }
            return array();
        }
    }

    $wpdb = new DznDiagnosticWpdb();

    ob_start();
    require __DIR__ . '/production-release-diagnostic.php';
    $json = (string) ob_get_clean();

    $assert = static function (bool $ok, string $message): void {
        if (!$ok) throw new RuntimeException($message);
    };
    $out = json_decode($json, true);
    $assert(is_array($out), 'The diagnostic must emit valid JSON.');
    $meet = $out['meet_operational'] ?? null;
    $assert(is_array($meet) && ($meet['read_only'] ?? false) === true, 'The Meet diagnostic section must be present and explicitly read-only.');

    $flags = $meet['flags'] ?? array();
    $assert(($flags['classroom_enabled']['effective'] ?? null) === true, 'Provisioning effective state is wrong.');
    $assert(($flags['classroom_enabled']['source'] ?? '') === 'default', 'Provisioning source is wrong.');
    $assert(($flags['auto_reconcile']['effective'] ?? null) === false, 'Automatic reconciliation must remain disabled by default.');
    $assert(($flags['auto_reconcile']['source'] ?? '') === 'default', 'Automatic reconciliation source is wrong.');

    $worker = $meet['worker'] ?? array();
    $assert(($worker['class_loaded'] ?? false) === true, 'Worker class presence is missing.');
    $assert(($worker['hook_registered'] ?? false) === true, 'Worker hook registration is missing.');
    $assert(($worker['scheduled'] ?? false) === true, 'Worker scheduling is missing.');
    $assert(($worker['occurrences'] ?? 0) === 1, 'Worker occurrence count is wrong.');
    $assert(($worker['recurrence'] ?? '') === 'dzn_five_minutes', 'Worker recurrence is wrong.');
    $assert(is_string($worker['next_run_utc'] ?? null), 'Worker next run is missing.');

    $oauth = $meet['organizer_oauth'] ?? array();
    $assert(($oauth['configured'] ?? false) === true, 'Organizer configured state is missing.');
    $assert(($oauth['connection_state'] ?? '') === 'connected', 'Organizer connection state is wrong.');
    $assert(($oauth['identity_state'] ?? '') === 'verified', 'Organizer identity state is wrong.');
    $assert(($oauth['credential_state'] ?? '') === 'active', 'Organizer credential state is wrong.');
    $assert(($oauth['credential_expiration'] ?? '') === 'valid', 'Organizer credential expiration is wrong.');

    $lesson22 = $meet['lessons'][22] ?? array();
    $assert(($lesson22['found'] ?? false) === true, 'Lesson 22 row is missing.');
    $assert(($lesson22['classroom_exists'] ?? false) === true, 'Lesson 22 central classroom is missing.');
    $assert(($lesson22['classroom_state'] ?? '') === 'ready' && ($lesson22['cohost_state'] ?? '') === 'verified', 'Lesson 22 central readiness is wrong.');
    $assert(($lesson22['active_schedule_version_match'] ?? false) === true, 'Lesson 22 schedule match is wrong.');
    $assert(($lesson22['reference_present'] ?? false) === true && ($lesson22['join_reference_present'] ?? false) === true, 'Lesson 22 reference presence is wrong.');
    $assert(($lesson22['join_ready'] ?? false) === true && ($lesson22['projection_verified'] ?? false) === true, 'Lesson 22 join readiness is wrong.');

    $lesson23 = $meet['lessons'][23] ?? array();
    $assert(($lesson23['found'] ?? false) === true, 'Lesson 23 row is missing.');
    $assert(($lesson23['classroom_exists'] ?? true) === false, 'Lesson 23 central classroom scenario is wrong.');
    $assert(($lesson23['provider_calendar_projection_state'] ?? '') === 'verified' && ($lesson23['provider_meeting_projection_state'] ?? '') === 'verified', 'Lesson 23 provider projection state is wrong.');
    $assert(($lesson23['provider_join_reference_present'] ?? false) === true, 'Lesson 23 provider join reference is missing.');
    $assert(($lesson23['provider_join_ready'] ?? false) === true && ($lesson23['projection_verified'] ?? false) === true, 'Lesson 23 verified provider projection must not look missing.');
    $assert(($lesson23['join_ready'] ?? false) === true, 'Lesson 23 join readiness is wrong.');

    $booking = $meet['booking_confirmation'] ?? array();
    $assert(($booking['found'] ?? false) === true, 'Booking #9 row is missing.');
    $assert(($booking['confirmation_state'] ?? '') === 'failed', 'Booking #9 confirmation state is wrong.');
    $assert(($booking['classroom_exists'] ?? false) === true, 'Booking #9 classroom is missing.');
    $assert(($booking['classroom_state'] ?? '') === 'blocked' && ($booking['cohost_state'] ?? '') === 'blocked', 'Booking #9 classroom state is wrong.');
    $assert(($booking['join_ready'] ?? true) === false, 'Booking #9 join readiness must be false.');
    $assert(($booking['failure_code'] ?? '') === 'meet_cohost_assignment_blocked', 'Booking #9 failure code is wrong.');
    $assert(($booking['attempt_count'] ?? 0) === 6 && array_key_exists('next_attempt_at', $booking) && $booking['next_attempt_at'] === null, 'Booking #9 retry state is wrong.');

    $meetJson = json_encode($meet, JSON_UNESCAPED_SLASHES);
    foreach (array('@', 'ciphertext', 'access_token', 'refresh_token', 'client_secret', 'client_id', 'organizer_email', 'teacher_email', 'student_email', 'teacher_email_digest', 'student_email_digest', 'event_digest', 'meet_uri_digest', 'conference_digest', 'space_name', 'calendar_id', 'provider_payload', 'meeting_code') as $forbidden) {
        $assert(strpos($meetJson, $forbidden) === false, 'Meet diagnostic leaked a sensitive field or raw address: ' . $forbidden);
    }

    echo "Production release diagnostic output unit passed\n";
}
