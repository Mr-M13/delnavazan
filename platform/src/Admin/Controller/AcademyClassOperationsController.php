<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\AdminAttendanceConfirmationService;
use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleService;

/**
 * Thin HTTP boundary for the Overview's class attendance and schedule actions.
 *
 * It translates a nonce-protected administrator request into the existing canonical attendance or
 * lesson-schedule command. No attendance, schedule, notification or capacity fact is written here.
 */
final class AcademyClassOperationsController {
    public static function register(): void {
        add_action('admin_post_dzn_academy_mark_attended', array(__CLASS__, 'markAttended'));
        add_action('admin_post_dzn_academy_reschedule_class', array(__CLASS__, 'rescheduleClass'));
    }

    public static function markAttended(): never {
        if (!current_user_can(AdminAttendanceConfirmationService::CAPABILITY)) wp_die('Forbidden', 'Delnavazan', array('response' => 403));
        check_admin_referer('dzn_academy_mark_attended');
        $post = wp_unslash($_POST);
        $lessonId = absint($post['lesson_id'] ?? 0);
        $scheduleVersionId = absint($post['schedule_version_id'] ?? 0);
        $key = sanitize_text_field((string) ($post['idempotency_key'] ?? ''));
        if ($key === '') $key = wp_generate_uuid4();
        try {
            (new AdminAttendanceConfirmationService())->confirm($lessonId, $scheduleVersionId, array(
                'evidence_reference' => sanitize_text_field((string) ($post['evidence_reference'] ?? '')),
                'evidence_channel' => sanitize_key((string) ($post['evidence_channel'] ?? 'staff_record')),
                'observed_at' => sanitize_text_field((string) ($post['evidence_at'] ?? gmdate('Y-m-d H:i:s'))),
            ), $key);
            self::redirect('attendance_confirmed');
        } catch (\Throwable) {
            self::redirect('attendance_error');
        }
    }

    public static function rescheduleClass(): never {
        if (!current_user_can('dzn_manage_canonical_lesson_schedules')) wp_die('Forbidden', 'Delnavazan', array('response' => 403));
        check_admin_referer('dzn_academy_reschedule_class');
        $post = wp_unslash($_POST);
        $lessonId = absint($post['lesson_id'] ?? 0);
        $assignmentId = absint($post['expected_assignment_id'] ?? 0);
        $expectedVersionId = absint($post['expected_schedule_version_id'] ?? 0);
        $key = sanitize_text_field((string) ($post['idempotency_key'] ?? ''));
        if ($key === '') $key = wp_generate_uuid4();
        $localTime = trim((string) ($post['local_wall_time'] ?? ''));
        if (preg_match('/^\d{2}:\d{2}$/D', $localTime) === 1) $localTime .= ':00';
        try {
            (new CanonicalLessonScheduleService())->revise($lessonId, $assignmentId, array(
                'schedule_timezone' => sanitize_text_field((string) ($post['schedule_timezone'] ?? '')),
                'local_wall_date' => sanitize_text_field((string) ($post['local_wall_date'] ?? '')),
                'local_wall_time' => $localTime,
                'duration_minutes' => sanitize_text_field((string) ($post['duration_minutes'] ?? '')),
                'reason_code' => sanitize_key((string) ($post['reason_code'] ?? '')),
                'expected_schedule_version_id' => $expectedVersionId,
                'evidence_channel' => sanitize_key((string) ($post['evidence_channel'] ?? 'staff_record')),
                'evidence_reference' => sanitize_text_field((string) ($post['evidence_reference'] ?? '')),
                'evidence_at' => sanitize_text_field((string) ($post['evidence_at'] ?? gmdate('Y-m-d H:i:s'))),
            ), $key);
            self::redirect('schedule_changed');
        } catch (\Throwable) {
            self::redirect('schedule_error');
        }
    }

    private static function redirect(string $status): never {
        $url = add_query_arg(array(
            'page' => 'dzn-academy-dashboard',
            'class_action_status' => $status,
        ), admin_url('admin.php')) . '#dzn-academy-today-upcoming';
        nocache_headers();
        if (wp_safe_redirect($url)) exit;
        wp_die('The class action result could not be returned to the Overview.', 'Delnavazan', array('response' => 500));
    }
}
