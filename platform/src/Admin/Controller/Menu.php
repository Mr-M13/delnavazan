<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\Diagnostic\NonceLifecycleDiagnostic;
use Delnavazan\Platform\Core\Application\NotificationRule;

/**
 * Registers Delnavazan destinations under one WordPress application entry.
 *
 * The destination capability and callback are deliberately left with their
 * existing authority screen. The submenu registrations are hidden after WordPress
 * has registered them: they remain protected direct routes, while the visible
 * navigation is the academy-oriented shell rendered by AdminShellController.
 */
final class Menu {
    public const ROOT_SLUG = 'dzn-platform';
    private const INTEGRATIONS_OVERVIEW_CAP = 'dzn_access_integrations_overview';
    private const INTEGRATION_SETTINGS_CAP = 'dzn_access_provider_integration_settings';
    private const SYSTEM_HEALTH_CAP = 'dzn_access_system_health';

    public static function register(): void {
        add_filter('map_meta_cap', [__CLASS__, 'mapMetaCap'], 10, 4);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_menu', [AdminShellController::class, 'pruneWordPressSubmenu'], 999);
    }

    public static function mapMetaCap(array $caps, string $cap, int $userId, array $args): array {
        if ($cap === self::INTEGRATIONS_OVERVIEW_CAP) return self::mappedIntegrationCaps($userId, 'dzn_view_diagnostics');
        if ($cap === self::INTEGRATION_SETTINGS_CAP) return self::mappedIntegrationCaps($userId, 'dzn_manage_provider_integrations');
        if ($cap === self::SYSTEM_HEALTH_CAP) return self::mappedIntegrationCaps($userId, 'dzn_view_diagnostics');
        return $caps;
    }

    private static function mappedIntegrationCaps(int $userId, string $delegatedCap): array {
        $user = get_userdata($userId);
        if (!$user) return ['do_not_allow'];
        if ($user->has_cap('manage_options')) return ['manage_options'];
        if ($user->has_cap($delegatedCap)) return [$delegatedCap];
        return ['do_not_allow'];
    }

    public static function menu(): void {
        $hook = add_menu_page('Delnavazan', 'Delnavazan', 'read', self::ROOT_SLUG, [AdminShellController::class, 'screen'], 'dashicons-welcome-learn-more', 58);
        NonceLifecycleDiagnostic::watchLoadHook($hook);

        $pages = [
            ['Academy', 'dzn_view_booking_requests', 'dzn-academy-dashboard', [AcademyOperationsController::class, 'screen'], null],
            ['Booking requests', 'dzn_view_booking_requests', 'dzn-booking-requests', [BookingRequestController::class, 'screen'], [BookingRequestController::class, 'handlePost']],
            ['Request coordination', 'dzn_manage_booking_request_coordination', 'dzn-booking-request-coordination', [CoordinationCaseController::class, 'screen'], [CoordinationCaseController::class, 'handlePost']],

            ['Students', 'dzn_manage_students', 'dzn-student', static fn() => ScreenController::screen('student'), static fn() => ScreenController::handlePost('student')],
            ['Identity & authentication', 'dzn_view_student_acceptance_eligibility', 'dzn-student-identity-authority', [StudentIdentityAuthorityController::class, 'screen'], [StudentIdentityAuthorityController::class, 'handlePost']],
            ['Enrolments', 'dzn_manage_enrolments', 'dzn-enrolment', static fn() => ScreenController::screen('enrolment'), static fn() => ScreenController::handlePost('enrolment')],

            // Keep this mature URL stable: teacher list/profile links and their POST
            // handler are intentionally not moved as part of the shell work.
            ['Teachers', 'dzn_manage_teachers', 'dzn-teachers-menu', static fn() => ScreenController::screen('teacher'), static fn() => ScreenController::handlePost('teacher')],
            ['Courses', 'dzn_manage_courses', 'dzn-course', static fn() => ScreenController::screen('course'), static fn() => ScreenController::handlePost('course')],
            ['Terms', 'dzn_manage_terms', 'dzn-term', static fn() => ScreenController::screen('term'), static fn() => ScreenController::handlePost('term')],
            ['Instruments', 'dzn_manage_courses', 'dzn-instrument', static fn() => ScreenController::screen('instrument'), static fn() => ScreenController::handlePost('instrument')],
            ['Classes', 'dzn_manage_lessons', 'dzn-lesson', static fn() => ScreenController::screen('lesson'), static fn() => ScreenController::handlePost('lesson')],
            ['Attendance review', 'dzn_manage_canonical_attendance_review', 'dzn-attendance-review', [AttendanceReviewController::class, 'screen'], [AttendanceReviewController::class, 'handlePost']],

            ['Communications', NotificationRule::READ_CAPABILITY, 'dzn-communications', [NotificationDiagnosticsController::class, 'screen'], null],
            ['Notification templates', 'dzn_manage_provider_integrations', 'dzn-communications-templates', [CommunicationsTemplateController::class, 'screen'], null],
            ['Stripe setup', PaymentExecutionController::CAPABILITY, 'dzn-stripe-settings', [IntegrationControlPlaneController::class, 'stripeSettings'], null],
            ['Integrations', self::INTEGRATIONS_OVERVIEW_CAP, 'dzn-integrations', [IntegrationControlPlaneController::class, 'screen'], null],
            ['Email', self::INTEGRATION_SETTINGS_CAP, 'dzn-integration-email', [IntegrationControlPlaneController::class, 'emailSettings'], null],
            ['Meta WhatsApp Cloud API', self::INTEGRATION_SETTINGS_CAP, 'dzn-integration-whatsapp', [IntegrationControlPlaneController::class, 'whatsAppSettings'], null],
            ['SMS fallback', self::INTEGRATION_SETTINGS_CAP, 'dzn-integration-sms', [IntegrationControlPlaneController::class, 'smsSettings'], null],
            ['Google OAuth / Calendar', self::INTEGRATION_SETTINGS_CAP, 'dzn-integration-google', [IntegrationControlPlaneController::class, 'googleSettings'], null],
            ['System health', self::SYSTEM_HEALTH_CAP, 'dzn-system-health', [ScreenController::class, 'status'], null],
            ['Exceptions', 'dzn_manage_exceptions', 'dzn-exception', static fn() => ScreenController::screen('exception'), static fn() => ScreenController::handlePost('exception')],
        ];

        foreach ($pages as [$label, $capability, $slug, $callback, $postHandler]) {
            $hook = add_submenu_page(self::ROOT_SLUG, $label, $label, $capability, $slug, $callback);
            NonceLifecycleDiagnostic::watchLoadHook($hook);
            if ($hook && $postHandler !== null) add_action('load-' . $hook, $postHandler);
        }
    }
}
