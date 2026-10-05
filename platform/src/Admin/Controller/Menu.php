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
            ['Bookings', 'dzn-platform', 'dashicons-calendar-alt', 58],
            ['Students', 'dzn-students-menu', 'dashicons-groups', 59],
            ['Teachers', 'dzn-teachers-menu', 'dashicons-welcome-learn-more', 60],
            ['Courses', 'dzn-courses-menu', 'dashicons-book-alt', 61],
            ['Finance', 'dzn-finance-menu', 'dashicons-money-alt', 62],
            ['Notifications', 'dzn-notifications-menu', 'dashicons-email-alt', 63],
            ['Settings', 'dzn-settings-menu', 'dashicons-admin-generic', 64],
        ];
        foreach ($groups as [$label, $slug, $icon, $position]) {
            $callback = $slug === 'dzn-platform'
                ? [AcademyOperationsController::class, 'screen']
                : [AdminNavigationController::class, 'screen'];
            add_menu_page($label, $label, 'read', $slug, $callback, $icon, $position);
        }

        $pages = [
            ['dzn-platform', 'Booking requests', 'dzn_view_booking_requests', 'dzn-booking-requests', [BookingRequestController::class, 'screen'], [BookingRequestController::class, 'handlePost']],
            ['dzn-platform', 'Request coordination', 'dzn_manage_booking_request_coordination', 'dzn-booking-request-coordination', [CoordinationCaseController::class, 'screen'], [CoordinationCaseController::class, 'handlePost']],

            ['dzn-students-menu', 'Students', 'dzn_manage_students', 'dzn-student', static fn() => ScreenController::screen('student'), static fn() => ScreenController::handlePost('student')],
            ['dzn-students-menu', 'Identity & authentication', 'dzn_view_student_acceptance_eligibility', 'dzn-student-identity-authority', [StudentIdentityAuthorityController::class, 'screen'], [StudentIdentityAuthorityController::class, 'handlePost']],

            ['dzn-teachers-menu', 'Teachers', 'dzn_manage_teachers', 'dzn-teacher', static fn() => ScreenController::screen('teacher'), static fn() => ScreenController::handlePost('teacher')],
            ['dzn-teachers-menu', 'Teacher onboarding', 'dzn_manage_onboarding', 'dzn-onboarding', [OnboardingController::class, 'screen'], [OnboardingController::class, 'handlePost']],
            ['dzn-teachers-menu', 'Teacher eligibility', 'dzn_manage_teaching_eligibility', 'dzn-teaching-eligibility', [TeachingEligibilityController::class, 'screen'], [TeachingEligibilityController::class, 'handlePost']],
            ['dzn-teachers-menu', 'Teacher availability', 'dzn_manage_teacher_availability', 'dzn-teacher-availability', [TeacherAvailabilityController::class, 'screen'], [TeacherAvailabilityController::class, 'handlePost']],

            ['dzn-courses-menu', 'Courses', 'dzn_manage_courses', 'dzn-course', static fn() => ScreenController::screen('course'), static fn() => ScreenController::handlePost('course')],
            ['dzn-courses-menu', 'Terms', 'dzn_manage_terms', 'dzn-term', static fn() => ScreenController::screen('term'), static fn() => ScreenController::handlePost('term')],
            ['dzn-courses-menu', 'Enrolments', 'dzn_manage_enrolments', 'dzn-enrolment', static fn() => ScreenController::screen('enrolment'), static fn() => ScreenController::handlePost('enrolment')],
            ['dzn-courses-menu', 'Instruments', 'dzn_manage_courses', 'dzn-instrument', static fn() => ScreenController::screen('instrument'), static fn() => ScreenController::handlePost('instrument')],
            ['dzn-courses-menu', 'Lessons', 'dzn_manage_lessons', 'dzn-lesson', static fn() => ScreenController::screen('lesson'), static fn() => ScreenController::handlePost('lesson')],

            ['dzn-settings-menu', 'Exceptions', 'dzn_manage_exceptions', 'dzn-exception', static fn() => ScreenController::screen('exception'), static fn() => ScreenController::handlePost('exception')],
            ['dzn-finance-menu', 'Attendance', 'dzn_manage_canonical_attendance_review', 'dzn-attendance-review', [AttendanceReviewController::class, 'screen'], [AttendanceReviewController::class, 'handlePost']],
            ['dzn-notifications-menu', 'Communications', NotificationRule::READ_CAPABILITY, 'dzn-communications', [NotificationDiagnosticsController::class, 'screen'], null],
            ['dzn-settings-menu', 'System health', 'dzn_view_diagnostics', 'dzn-system-health', [ScreenController::class, 'status'], null],
            ['dzn-settings-menu', 'Google Console', 'dzn_view_diagnostics', 'dzn-google-console', [IntegrationControlPlaneController::class, 'googleSettings'], null],
            ['dzn-settings-menu', 'WhatsApp', 'dzn_view_diagnostics', 'dzn-whatsapp-settings', [IntegrationControlPlaneController::class, 'whatsAppSettings'], null],
            ['dzn-settings-menu', 'SMS', 'dzn_view_diagnostics', 'dzn-sms-settings', [IntegrationControlPlaneController::class, 'smsSettings'], null],
            ['dzn-settings-menu', 'Stripe', PaymentExecutionController::CAPABILITY, 'dzn-stripe-settings', [IntegrationControlPlaneController::class, 'stripeSettings'], null],
        ];

        foreach ($pages as [$parent, $label, $capability, $slug, $callback, $postHandler]) {
            $hook = add_submenu_page($parent, $label, $label, $capability, $slug, $callback);
            NonceLifecycleDiagnostic::watchLoadHook($hook);
            if ($hook && $postHandler !== null) add_action('load-' . $hook, $postHandler);
        }
    }
}
