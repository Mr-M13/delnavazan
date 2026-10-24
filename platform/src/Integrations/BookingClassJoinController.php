<?php
namespace Delnavazan\Platform\Integrations;

final class BookingClassJoinController {
    public static function register(): void {
        add_action('admin_post_dzn_teacher_join_booking_class', static fn() => self::join('teacher'));
        add_action('admin_post_dzn_student_join_booking_class', static fn() => self::join('student'));
    }

    public static function join(string $role): never {
        if (!is_user_logged_in()) auth_redirect();
        $action = 'dzn_' . $role . '_join_booking_class';
        check_admin_referer($action);
        try {
            $target = (new BookingClassJoinService())->target((string) ($_POST['confirmation_uid'] ?? ''), $role);
            nocache_headers();
            header('Referrer-Policy: no-referrer');
            wp_redirect($target, 302, 'Delnavazan');
            exit;
        } catch (\Throwable) {
            $path = $role === 'teacher' ? '/teacher-portal/' : '/student-portal/';
            $query = $role === 'teacher'
                ? array('teacher-view' => 'home', 'class_status' => 'join_unavailable')
                : array('join_status' => 'join_unavailable');
            wp_safe_redirect(add_query_arg($query, home_url($path)));
            exit;
        }
    }
}
