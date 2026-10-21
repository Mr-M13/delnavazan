<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Dedicated least-privilege principal for post-introductory automation.
 *
 * The actor exists only to re-enter the existing canonical authorities for a booking that the
 * Teacher already accepted. It is not an administrator, cannot log in through the normal UI, and
 * holds exactly the capabilities those authorities already require.
 */
final class PostIntroSystemActor {
    public const USER_OPTION = 'dzn_post_intro_automation_user_id';
    public const ROLE = 'dzn_post_intro_automation';
    public const LOGIN = 'dzn_post_intro_automation';
    public const CAPABILITIES = array(
        'read',
        'dzn_manage_booking_request_coordination',
        'dzn_manage_teacher_availability_assent',
        'dzn_issue_booking_request_proposals',
        'dzn_record_booking_request_provisional_acceptance',
        'dzn_finalize_service_arrangements',
        'dzn_convert_service_arrangements_to_enrolments',
        'dzn_manage_canonical_enrolment_lifecycle',
        'dzn_manage_canonical_continuation',
        'dzn_view_canonical_continuation',
        'dzn_manage_commercial_capacity',
        'dzn_issue_commercial_offers',
        'dzn_bind_commercial_term_funding',
        'dzn_view_commercial_authority',
        'dzn_manage_canonical_terms',
        'dzn_manage_teacher_assignments',
        'dzn_manage_canonical_lessons',
        'dzn_manage_canonical_lesson_schedules',
        'dzn_manage_lessons',
        'dzn_manage_provider_integrations',
        'dzn_view_provider_integrations',
    );

    public static function run(callable $work): mixed {
        $userId = self::ensure();
        $previous = get_current_user_id();
        wp_set_current_user($userId);
        try {
            return $work($userId);
        } finally {
            wp_set_current_user($previous > 0 ? $previous : 0);
        }
    }

    public static function ensure(): int {
        $userId = (int) get_option(self::USER_OPTION, 0);
        if ($userId > 0 && self::usable($userId)) return $userId;

        $role = get_role(self::ROLE);
        if (!$role) {
            $role = add_role(self::ROLE, 'Delnavazan Post-Intro Automation', array('read' => true));
            if (!$role) throw new \RuntimeException('post_intro_automation_role_unavailable');
        }
        foreach (self::CAPABILITIES as $capability) {
            if (!$role->has_cap($capability)) $role->add_cap($capability);
        }

        $user = get_user_by('login', self::LOGIN);
        if (!$user instanceof \WP_User) {
            $id = wp_insert_user(array(
                'user_login' => self::LOGIN,
                'user_pass' => wp_generate_password(64, true, true),
                'user_email' => self::LOGIN . '@example.invalid',
                'display_name' => 'Delnavazan Post-Intro Automation',
                'role' => self::ROLE,
            ));
            if (is_wp_error($id) || (int) $id < 1) throw new \RuntimeException('post_intro_automation_user_unavailable');
            $userId = (int) $id;
        } else {
            $userId = (int) $user->ID;
            $user->set_role(self::ROLE);
        }
        update_option(self::USER_OPTION, $userId, false);
        if (!self::usable($userId)) throw new \RuntimeException('post_intro_automation_actor_invalid');
        return $userId;
    }

    private static function usable(int $userId): bool {
        $user = get_user_by('id', $userId);
        if (!$user instanceof \WP_User) return false;
        if (!in_array(self::ROLE, (array) $user->roles, true)) return false;
        foreach (self::CAPABILITIES as $capability) {
            if (!user_can($user, $capability)) return false;
        }
        return true;
    }
}
