<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

use Delnavazan\Platform\Integrations\{BookingClassJoinService, StudentClassJoinService, TeacherClassJoinService};

/**
 * Explicit Google Meet fallback. Nothing redirects here automatically.
 *
 * The signed launch ticket is revalidated, then the existing canonical Meet join service performs
 * its own ownership and window checks before the vaulted reference is opened server-side. The Meet
 * URL is never rendered into the classroom DOM or returned by the JSON token endpoint.
 */
final class LiveKitMeetFallbackController {
    public static function register(): void {
        add_action('admin_post_dzn_livekit_meet_fallback', array(__CLASS__, 'open'));
    }

    public static function open(): never {
        $role = 'student';
        try {
            if (!is_user_logged_in()) auth_redirect();
            check_admin_referer('dzn_livekit_meet_fallback');
            $claims = (new LiveKitLaunchTicket())->verify((string)($_POST['ticket'] ?? ''));
            $role = (string)$claims['role'];
            $kind = (string)$claims['kind'];
            $input = $kind === 'booking'
                ? array('kind' => 'booking', 'confirmation_uid' => (string)$claims['confirmation_uid'])
                : array('kind' => 'lesson', 'lesson_uid' => (string)$claims['lesson_uid'], 'schedule_version_uid' => (string)$claims['schedule_version_uid']);
            (new LiveKitClassroomAuthorizer())->authorize($role, $input);
            $target = $kind === 'booking'
                ? (new BookingClassJoinService())->target((string)$claims['confirmation_uid'], $role)
                : ($role === 'teacher'
                    ? (new TeacherClassJoinService())->target((string)$claims['lesson_uid'], (string)$claims['schedule_version_uid'])
                    : (new StudentClassJoinService())->target((string)$claims['lesson_uid'], (string)$claims['schedule_version_uid']));
            nocache_headers();
            header('Referrer-Policy: no-referrer');
            wp_redirect($target, 302, 'Delnavazan');
            exit;
        } catch (\Throwable) {
            $portal = home_url($role === 'teacher' ? '/teacher-portal/' : '/student-portal/');
            $query = $role === 'teacher'
                ? array('teacher-view' => 'home', 'class_status' => 'meet_unavailable')
                : array('join_status' => 'meet_unavailable');
            wp_safe_redirect(add_query_arg($query, $portal), 302, 'Delnavazan');
            exit;
        }
    }
}
