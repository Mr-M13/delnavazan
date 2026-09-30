<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\Diagnostic\NonceLifecycleDiagnostic;

final class Menu {
    public static function register(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
    }

    public static function menu(): void {
        add_menu_page('Delnavazan', 'Delnavazan', 'dzn_view_diagnostics', 'dzn-platform', [ScreenController::class, 'status'], 'dashicons-networking', 58);
        add_submenu_page('dzn-platform', 'Core Status', 'Core Status', 'dzn_view_diagnostics', 'dzn-platform', [ScreenController::class, 'status']);
        foreach ([
            'Teachers' => ['teacher', 'dzn_manage_teachers'],
            'Students' => ['student', 'dzn_manage_students'],
            'Instruments' => ['instrument', 'dzn_manage_courses'],
            'Courses' => ['course', 'dzn_manage_courses'],
            'Enrolments' => ['enrolment', 'dzn_manage_enrolments'],
            'Terms' => ['term', 'dzn_manage_terms'],
            'Lessons' => ['lesson', 'dzn_manage_lessons'],
            'Exceptions' => ['exception', 'dzn_manage_exceptions'],
        ] as $label => [$screen, $capability]) {
            $hook = add_submenu_page('dzn-platform', $label, $label, $capability, 'dzn-' . $screen, static function () use ($screen): void {
                ScreenController::screen($screen);
            });
            NonceLifecycleDiagnostic::watchLoadHook($hook);
            if ($hook) {
                // WordPress fires load-$hook before admin-header.php. Mutations
                // must run here so their post/redirect/get response can still
                // send headers; the submenu callback is rendering-only.
                add_action('load-' . $hook, static function () use ($screen): void {
                    ScreenController::handlePost($screen);
                });
            }
        }
		$hook = add_submenu_page('dzn-platform', 'Teaching eligibility', 'Teaching eligibility', 'dzn_manage_teaching_eligibility', 'dzn-teaching-eligibility', [TeachingEligibilityController::class, 'screen']);
		if ($hook) add_action('load-' . $hook, [TeachingEligibilityController::class, 'handlePost']);
		$hook = add_submenu_page('dzn-platform', 'Teacher availability', 'Teacher availability', 'dzn_manage_teacher_availability', 'dzn-teacher-availability', [TeacherAvailabilityController::class, 'screen']);
		if ($hook) add_action('load-' . $hook, [TeacherAvailabilityController::class, 'handlePost']);
		$hook = add_submenu_page('dzn-platform', 'Booking Requests', 'Booking Requests', 'dzn_view_booking_requests', 'dzn-booking-requests', [BookingRequestController::class, 'screen']);
		if ($hook) add_action('load-' . $hook, [BookingRequestController::class, 'handlePost']);
		$hook = add_submenu_page('dzn-platform', 'Request coordination', 'Request coordination', 'dzn_manage_booking_request_coordination', 'dzn-booking-request-coordination', [CoordinationCaseController::class, 'screen']);
		if ($hook) add_action('load-' . $hook, [CoordinationCaseController::class, 'handlePost']);
		$hook = add_submenu_page('dzn-platform', 'Student identity & authority', 'Student identity & authority', 'dzn_view_student_acceptance_eligibility', 'dzn-student-identity-authority', [StudentIdentityAuthorityController::class, 'screen']);
		if ($hook) add_action('load-' . $hook, [StudentIdentityAuthorityController::class, 'handlePost']);
        $hook = add_submenu_page('dzn-platform', 'Teacher onboarding', 'Teacher onboarding', 'dzn_manage_onboarding', 'dzn-onboarding', [OnboardingController::class, 'screen']);
        if ($hook) add_action('load-' . $hook, [OnboardingController::class, 'handlePost']);
    }
}
