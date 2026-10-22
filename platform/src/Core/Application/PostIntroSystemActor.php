<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Dedicated least-privilege principal for post-introductory automation.
 *
 * The actor exists only to re-enter the existing canonical authorities for a booking that the
 * Teacher already accepted. It is not an administrator, cannot log in through the normal UI, and
 * holds exactly the capabilities those authorities already require.
 *
 * Ordinary runtime execution is deliberately read-only with respect to identity: {@see run()}
 * adopts an already provisioned actor or fails closed. Creating or repairing the role/user is an
 * explicit administrator operation through {@see provision()}, never an implicit side effect of a
 * Teacher/Student request or a background worker.
 */
final class PostIntroSystemActor {
    public const USER_OPTION = 'dzn_post_intro_automation_user_id';
    public const USER_MARKER = 'dzn_post_intro_automation_actor';
    public const ROLE = 'dzn_post_intro_automation';
    public const LOGIN = 'dzn_post_intro_automation';
    /** The exact role capability set. Nothing outside this list may remain on the role. */
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
    /**
     * `$user->caps` also stores the role slug; `$user->allcaps` contains only capabilities.
     * The role slug is accepted solely in `caps`, never as an effective capability.
     */
    public const ALLOWED_CAPABILITIES = array(
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
        'dzn_post_intro_automation',
    );
    /** Capabilities that must never reach the automation identity. */
    public const FORBIDDEN_CAPABILITIES = array(
        'manage_options',
        'dzn_manage_post_intro_automation',
        'dzn_manage_canonical_lesson_delivery',
        'dzn_override_canonical_lesson_schedule_availability',
        'dzn_manage_payment_execution',
        'dzn_manage_payment_providers',
    );
    public const PROVISION_CHANNELS = array('administrator', 'admin_action', 'wp_cli');

    /**
     * Run one unit of work under the already provisioned principal.
     *
     * Nothing here creates a role, inserts a user, adds/removes a capability or writes the actor
     * option. A missing, widened, multi-role or directly granted identity is rejected before any
     * work executes.
     */
    public static function run(callable $work): mixed {
        $userId = self::ensure();
        $previous = get_current_user_id();
        wp_set_current_user($userId);
        try {
            // Prove the adopted identity is exactly the one validated above. This covers a
            // filter or an identity switch that silently denies one of the required capabilities.
            if ((int) get_current_user_id() !== $userId) {
                throw new \RuntimeException('post_intro_automation_identity_not_effective');
            }
            foreach (self::CAPABILITIES as $capability) {
                if (!current_user_can($capability)) {
                    throw new \RuntimeException('post_intro_automation_identity_not_effective');
                }
            }
            return $work($userId);
        } finally {
            wp_set_current_user($previous > 0 ? $previous : 0);
        }
    }

    /**
     * Resolve and validate the configured principal. This is read-only and fail-closed; it never
     * provisions or repairs the identity during ordinary runtime execution.
     */
    public static function ensure(): int {
        $userId = (int) get_option(self::USER_OPTION, 0);
        if ($userId < 1) {
            throw new \RuntimeException('post_intro_automation_unconfigured');
        }
        return self::validate($userId);
    }

    /**
     * Provision or repair the dedicated identity. Only an explicitly authorised administrator may
     * invoke this, and the caller must name the controlled channel that authorised the change.
     * No request, cron, webhook or background worker path calls this method.
     */
    public static function provision(int $administratorId, string $channel = 'administrator'): int {
        if (!in_array($channel, self::PROVISION_CHANNELS, true)) {
            throw new \RuntimeException('post_intro_automation_provision_channel_invalid');
        }
        self::assertAdministrator($administratorId);

        $role = get_role(self::ROLE);
        if (!$role) {
            $role = add_role(self::ROLE, 'Delnavazan Post-Intro Automation', array_fill_keys(self::CAPABILITIES, true));
            if (!$role) throw new \RuntimeException('post_intro_automation_role_unavailable');
        }
        // Repair a widened role by removing every grant outside the allowlist, then restoring any
        // missing allowlisted capability. The role is dedicated, so no other principal shares it.
        foreach (self::roleCapabilitySet($role) as $capability) {
            if (!in_array($capability, self::CAPABILITIES, true)) $role->remove_cap($capability);
        }
        foreach (self::CAPABILITIES as $capability) {
            if (!$role->has_cap($capability)) $role->add_cap($capability);
        }
        self::assertRoleCapabilities($role);

        $configuredUserId = (int) get_option(self::USER_OPTION, 0);
        $user = get_user_by('login', self::LOGIN);
        if ($user instanceof \WP_User) {
            // A predictable login must never be adopted from an unrelated account. Only the
            // plugin-created marker, or the already-bound actor option, proves this account is
            // the automation principal; a fresh unrelated account still conflicts.
            if (get_user_meta((int) $user->ID, self::USER_MARKER, true) !== '1' && $configuredUserId !== (int) $user->ID) {
                throw new \RuntimeException('post_intro_automation_actor_conflict');
            }
            $userId = (int) $user->ID;
            $user->set_role(self::ROLE);
            // `set_role()` retains direct user grants. Remove them explicitly so repair restores
            // exactly one role and no per-user capability override.
            foreach ((array) ($user->caps ?? array()) as $capability => $granted) {
                if ($granted && (string) $capability !== self::ROLE) $user->remove_cap((string) $capability);
            }
            update_user_meta($userId, self::USER_MARKER, '1');
        } else {
            $id = wp_insert_user(array(
                'user_login' => self::LOGIN,
                'user_pass' => wp_generate_password(64, true, true),
                'user_email' => self::LOGIN . '@example.invalid',
                'display_name' => 'Delnavazan Post-Intro Automation',
                'role' => self::ROLE,
            ));
            if (is_wp_error($id) || (int) $id < 1) throw new \RuntimeException('post_intro_automation_user_unavailable');
            $userId = (int) $id;
            update_user_meta($userId, self::USER_MARKER, '1');
        }
        update_option(self::USER_OPTION, $userId, false);
        return self::validate($userId);
    }

    /** Alias for the controlled bootstrap entry point. */
    public static function bootstrap(int $administratorId, string $channel = 'administrator'): int {
        return self::provision($administratorId, $channel);
    }

    /** Reject any identity that is not exactly one dedicated role with no direct grants. */
    private static function validate(int $userId): int {
        $user = get_user_by('id', $userId);
        if (!$user instanceof \WP_User) {
            throw new \RuntimeException('post_intro_automation_actor_invalid');
        }
        if (isset($user->user_status) && (int) $user->user_status !== 0) {
            throw new \RuntimeException('post_intro_automation_actor_invalid');
        }
        if (get_user_meta($userId, self::USER_MARKER, true) !== '1') {
            throw new \RuntimeException('post_intro_automation_actor_unverified');
        }

        $roles = array_values(array_unique(array_map('strval', (array) ($user->roles ?? array()))));
        if ($roles !== array(self::ROLE)) {
            throw new \RuntimeException('post_intro_automation_actor_role_invalid');
        }

        // WordPress stores the role slug in `caps`; any additional entry is a direct user grant,
        // even when the granted capability also happens to be allowlisted on the role.
        foreach ((array) ($user->caps ?? array()) as $capability => $granted) {
            if ($granted && (string) $capability !== self::ROLE) {
                throw new \RuntimeException('post_intro_automation_actor_direct_grant');
            }
        }

        $role = get_role(self::ROLE);
        if (!$role) throw new \RuntimeException('post_intro_automation_role_missing');
        self::assertRoleCapabilities($role);

        $userCapabilities = self::effectiveCapabilities((array) ($user->allcaps ?? array()));
        foreach (self::CAPABILITIES as $capability) {
            if (!in_array($capability, $userCapabilities, true)) {
                throw new \RuntimeException('post_intro_automation_capability_missing');
            }
        }
        foreach ($userCapabilities as $capability) {
            if (in_array($capability, self::FORBIDDEN_CAPABILITIES, true)) {
                throw new \RuntimeException('post_intro_automation_capability_forbidden');
            }
            if (!in_array($capability, self::ALLOWED_CAPABILITIES, true)) {
                throw new \RuntimeException('post_intro_automation_capability_unexpected');
            }
        }
        return $userId;
    }

    private static function assertAdministrator(int $administratorId): void {
        if ($administratorId < 1) {
            throw new \RuntimeException('post_intro_automation_administrator_required');
        }
        $administrator = get_user_by('id', $administratorId);
        if (!$administrator instanceof \WP_User) {
            throw new \RuntimeException('post_intro_automation_administrator_required');
        }
        if (isset($administrator->user_status) && (int) $administrator->user_status !== 0) {
            throw new \RuntimeException('post_intro_automation_administrator_required');
        }
        if (!user_can($administrator, 'manage_options')) {
            throw new \RuntimeException('post_intro_automation_administrator_required');
        }
    }

    private static function assertRoleCapabilities(object $role): void {
        $granted = self::roleCapabilitySet($role);
        foreach (self::CAPABILITIES as $capability) {
            if (!in_array($capability, $granted, true)) {
                throw new \RuntimeException('post_intro_automation_capability_missing');
            }
        }
        foreach ($granted as $capability) {
            if (in_array($capability, self::FORBIDDEN_CAPABILITIES, true)) {
                throw new \RuntimeException('post_intro_automation_capability_forbidden');
            }
            if (!in_array($capability, self::CAPABILITIES, true)) {
                throw new \RuntimeException('post_intro_automation_role_capability_invalid');
            }
        }
    }

    /** @return list<string> */
    private static function roleCapabilitySet(object $role): array {
        $capabilities = array();
        if (isset($role->capabilities) && is_array($role->capabilities)) {
            foreach ($role->capabilities as $capability => $granted) {
                if ($granted) $capabilities[] = (string) $capability;
            }
        } else {
            // Defensive fallback for a WordPress-compatible role object without the public map.
            foreach (self::ALLOWED_CAPABILITIES as $capability) {
                if ($role->has_cap($capability)) $capabilities[] = $capability;
            }
        }
        $capabilities = array_values(array_unique($capabilities));
        sort($capabilities);
        return $capabilities;
    }

    /** @return list<string> */
    private static function effectiveCapabilities(array $capabilities): array {
        $granted = array();
        foreach ($capabilities as $capability => $value) {
            if ($value && (string) $capability !== self::ROLE) $granted[] = (string) $capability;
        }
        $granted = array_values(array_unique($granted));
        sort($granted);
        return $granted;
    }
}
