<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Authenticated launch boundary for the portal's primary "ورود به کلاس" action.
 *
 * The portal submits the canonical lesson/schedule identifiers with a role-specific nonce. The
 * server immediately re-proves access and then redirects with an opaque signed ticket in the URL
 * fragment, so the classroom page itself carries no lesson identifier or API credential.
 */
final class LiveKitLaunchController {
    public static function register(): void {
        add_action('admin_post_dzn_teacher_livekit_class', static fn() => self::launch('teacher'));
        add_action('admin_post_dzn_student_livekit_class', static fn() => self::launch('student'));
    }

    public static function launch(string $role): never {
        if (!is_user_logged_in()) auth_redirect();
        check_admin_referer('dzn_' . $role . '_livekit_class');
        $portal = home_url($role === 'teacher' ? '/teacher-portal/' : '/student-portal/');
        $failure = $role === 'teacher'
            ? array('teacher-view' => 'home', 'class_status' => 'livekit_unavailable')
            : array('join_status' => 'livekit_unavailable');
        try {
            $kind = sanitize_key((string)($_POST['kind'] ?? 'lesson'));
            if (!in_array($kind, array('lesson', 'booking'), true)) throw new \InvalidArgumentException('livekit_resource_invalid');
            $input = $kind === 'booking'
                ? array('kind' => 'booking', 'confirmation_uid' => sanitize_text_field((string)($_POST['confirmation_uid'] ?? '')))
                : array(
                    'kind' => 'lesson',
                    'lesson_uid' => sanitize_text_field((string)($_POST['lesson_uid'] ?? '')),
                    'schedule_version_uid' => sanitize_text_field((string)($_POST['schedule_version_uid'] ?? '')),
                );
            $grant = (new LiveKitClassroomAuthorizer())->authorize($role, $input);
            $ticket = (new LiveKitLaunchTicket())->issue($role, array_merge($input, array('kind' => $kind, 'meet_available' => !empty($grant['meet_available']))));
            nocache_headers();
            header('Referrer-Policy: no-referrer');
            wp_safe_redirect(home_url('/music-classroom/') . '#ticket=' . rawurlencode($ticket), 302, 'Delnavazan');
            exit;
        } catch (\Throwable) {
            wp_safe_redirect(add_query_arg($failure, $portal), 302, 'Delnavazan');
            exit;
        }
    }
}
