<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\Diagnostic\NonceLifecycleDiagnostic;
use Delnavazan\Platform\Core\Application\NotificationRule;

/** The WordPress sidebar follows the academy's operator workflow, with authority kept per screen. */
final class Menu {
    public static function register(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_menu', [AdminNavigationController::class, 'pruneMenus'], 999);
    }

    public static function menu(): void {
        $groups = [
            ['Academy', 'dzn-platform', 'dashicons-calendar-alt', 58],
            ['Bookings', 'dzn-bookings-menu', 'dashicons-calendar', 59],
            ['Students', 'dzn-students-menu', 'dashicons-groups', 60],
            ['Teachers', 'dzn-teachers-menu', 'dashicons-welcome-learn-more', 61],
            ['Courses & Terms', 'dzn-courses-menu', 'dashicons-book-alt', 62],
            ['Classes / Schedule', 'dzn-classes-menu', 'dashicons-clock', 63],
            ['Finance', 'dzn-finance-menu', 'dashicons-money-alt', 64],
            ['Communications', 'dzn-notifications-menu', 'dashicons-email-alt', 65],
            ['Delnavazan Settings', 'dzn-settings-menu', 'dashicons-admin-generic', 66],
        ];
        foreach ($groups as [$label, $slug, $icon, $position]) {
            add_menu_page($label, $label, 'read', $slug, [AdminNavigationController::class, 'screen'], $icon, $position);
        }

        $pages = [
            ['dzn-settings-menu', 'Integrations', 'dzn_view_diagnostics', 'dzn-integrations', [IntegrationControlPlaneController::class, 'screen'], null],
            ['dzn-settings-menu', 'Email', 'dzn_manage_provider_integrations', 'dzn-integration-email', [IntegrationControlPlaneController::class, 'emailSettings'], null],
            ['dzn-settings-menu', 'Meta WhatsApp Cloud API', 'dzn_manage_provider_integrations', 'dzn-integration-whatsapp', [IntegrationControlPlaneController::class, 'whatsAppSettings'], null],
            ['dzn-settings-menu', 'SMS fallback', 'dzn_manage_provider_integrations', 'dzn-integration-sms', [IntegrationControlPlaneController::class, 'smsSettings'], null],
            ['dzn-settings-menu', 'Google OAuth / Calendar', 'dzn_manage_provider_integrations', 'dzn-integration-google', [IntegrationControlPlaneController::class, 'googleSettings'], null],
            ['dzn-platform', 'Dashboard', 'dzn_view_booking_requests', 'dzn-academy-dashboard', [AcademyOperationsController::class, 'screen'], null],
            ['dzn-bookings-menu', 'Booking requests', 'dzn_view_booking_requests', 'dzn-booking-requests', [BookingRequestController::class, 'screen'], [BookingRequestController::class, 'handlePost']],
            ['dzn-bookings-menu', 'Request coordination', 'dzn_manage_booking_request_coordination', 'dzn-booking-request-coordination', [CoordinationCaseController::class, 'screen'], [CoordinationCaseController::class, 'handlePost']],

            ['dzn-students-menu', 'Students', 'dzn_manage_students', 'dzn-student', static fn() => ScreenController::screen('student'), static fn() => ScreenController::handlePost('student')],
            ['dzn-students-menu', 'Identity & authentication', 'dzn_view_student_acceptance_eligibility', 'dzn-student-identity-authority', [StudentIdentityAuthorityController::class, 'screen'], [StudentIdentityAuthorityController::class, 'handlePost']],
            ['dzn-students-menu', 'Enrolments', 'dzn_manage_enrolments', 'dzn-enrolment', static fn() => ScreenController::screen('enrolment'), static fn() => ScreenController::handlePost('enrolment')],

            ['dzn-teachers-menu', 'Teachers', 'dzn_manage_teachers', 'dzn-teacher', static fn() => ScreenController::screen('teacher'), static fn() => ScreenController::handlePost('teacher')],
            ['dzn-teachers-menu', 'Teacher onboarding', 'dzn_manage_onboarding', 'dzn-onboarding', [OnboardingController::class, 'screen'], [OnboardingController::class, 'handlePost']],
            ['dzn-teachers-menu', 'Teacher eligibility', 'dzn_manage_teaching_eligibility', 'dzn-teaching-eligibility', [TeachingEligibilityController::class, 'screen'], [TeachingEligibilityController::class, 'handlePost']],
            ['dzn-teachers-menu', 'Teacher availability', 'dzn_manage_teacher_availability', 'dzn-teacher-availability', [TeacherAvailabilityController::class, 'screen'], [TeacherAvailabilityController::class, 'handlePost']],

            ['dzn-courses-menu', 'Courses', 'dzn_manage_courses', 'dzn-course', static fn() => ScreenController::screen('course'), static fn() => ScreenController::handlePost('course')],
            ['dzn-courses-menu', 'Terms', 'dzn_manage_terms', 'dzn-term', static fn() => ScreenController::screen('term'), static fn() => ScreenController::handlePost('term')],
            ['dzn-courses-menu', 'Instruments', 'dzn_manage_courses', 'dzn-instrument', static fn() => ScreenController::screen('instrument'), static fn() => ScreenController::handlePost('instrument')],
            ['dzn-classes-menu', 'Classes', 'dzn_manage_lessons', 'dzn-lesson', static fn() => ScreenController::screen('lesson'), static fn() => ScreenController::handlePost('lesson')],
            ['dzn-classes-menu', 'Attendance review', 'dzn_manage_canonical_attendance_review', 'dzn-attendance-review', [AttendanceReviewController::class, 'screen'], [AttendanceReviewController::class, 'handlePost']],

            ['dzn-settings-menu', 'Exceptions', 'dzn_manage_exceptions', 'dzn-exception', static fn() => ScreenController::screen('exception'), static fn() => ScreenController::handlePost('exception')],
            ['dzn-notifications-menu', 'Communications', NotificationRule::READ_CAPABILITY, 'dzn-communications', [NotificationDiagnosticsController::class, 'screen'], null],
            ['dzn-settings-menu', 'System health', 'dzn_view_diagnostics', 'dzn-system-health', [ScreenController::class, 'status'], null],
            ['dzn-settings-menu', 'Stripe', PaymentExecutionController::CAPABILITY, 'dzn-stripe-settings', [IntegrationControlPlaneController::class, 'stripeSettings'], null],
        ];

        foreach ($pages as [$parent, $label, $capability, $slug, $callback, $postHandler]) {
            $hook = add_submenu_page($parent, $label, $label, $capability, $slug, $callback);
            NonceLifecycleDiagnostic::watchLoadHook($hook);
            if ($hook && $postHandler !== null) {
                // WordPress fires load-$hook before admin-header.php. Mutations
                // must run here so POST/redirect/GET responses can still send headers.
                add_action('load-' . $hook, $postHandler);
            }
        }

        // These are section landing pages. Their operator links are capability-filtered
        // by AdminNavigationController; the target screens keep their own registered caps.
        foreach ([
            ['Integrations', 'dzn-settings-integrations'],
            ['Diagnostics', 'dzn-settings-diagnostics'],
        ] as [$label, $slug]) {
            $callback=$slug==='dzn-settings-integrations'?[IntegrationControlPlaneController::class,'screen']:[AdminNavigationController::class,'screen'];
            add_submenu_page('dzn-settings-menu', $label, $label, 'read', $slug, $callback);
        }
    }
}
