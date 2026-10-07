<?php
namespace Delnavazan\Platform\Admin\Controller;

/** Shared, capability-aware Delnavazan application chrome inside wp-admin. */
final class AdminShellController {
    /** The visible primary navigation follows academy work, never table layout. */
    private const TABS = [
        'academy' => ['Academy', [['dzn-academy-dashboard', 'dzn_view_booking_requests'], ['dzn-booking-request-coordination', 'dzn_manage_booking_request_coordination']]],
        'teachers' => ['Teachers', [['dzn-teachers-menu', 'dzn_manage_teachers']]],
        'students' => ['Students', [['dzn-student', 'dzn_manage_students'], ['dzn-student-identity-authority', 'dzn_view_student_acceptance_eligibility']]],
        'courses' => ['Courses', [['dzn-course', 'dzn_manage_courses'], ['dzn-term', 'dzn_manage_terms']]],
        'enrolments_classes' => ['Enrolments / Classes', [['dzn-enrolment', 'dzn_manage_enrolments'], ['dzn-lesson', 'dzn_manage_lessons'], ['dzn-attendance-review', 'dzn_manage_canonical_attendance_review'], ['dzn-core-dataset-readiness', 'dzn_convert_service_arrangements_to_enrolments'], ['dzn-core-dataset-readiness', 'dzn_issue_student_account_claims'], ['dzn-core-dataset-readiness', 'dzn_manage_teacher_assignments'], ['dzn-core-dataset-readiness', 'dzn_manage_canonical_terms'], ['dzn-core-dataset-readiness', 'dzn_manage_canonical_lessons'], ['dzn-core-dataset-readiness', 'dzn_manage_canonical_lesson_delivery'], ['dzn-core-dataset-readiness', 'dzn_manage_canonical_lesson_schedules']]],
        'finance' => ['Finance', [['dzn-finance-statements', 'dzn_view_finance_authority'], ['dzn-payment-execution', 'dzn_view_payment_execution_authority'], ['dzn-finance-cashbook', 'dzn_view_cash_ledger']]],
        'communications' => ['Communications', [['dzn-communications', 'dzn_view_notification_authority'], ['dzn-communications-templates', 'dzn_manage_provider_integrations']]],
        'settings' => ['Settings', [['dzn-integrations', 'dzn_access_integrations_overview'], ['dzn-exception', 'dzn_manage_exceptions'], ['dzn-system-health', 'dzn_access_system_health'], ['dzn-staff-access', 'manage_options']]],
    ];

    /** Pages share their tab while retaining their original, protected callback. */
    private const PAGE_TABS = [
        'dzn-platform' => 'academy', 'dzn-academy-dashboard' => 'academy', 'dzn-booking-requests' => 'academy', 'dzn-booking-request-coordination' => 'academy',
        'dzn-teachers-menu' => 'teachers',
        'dzn-student' => 'students', 'dzn-student-identity-authority' => 'students',
        'dzn-course' => 'courses', 'dzn-term' => 'courses', 'dzn-instrument' => 'courses', 'dzn-commercial-catalogue' => 'courses',
        'dzn-enrolment' => 'enrolments_classes', 'dzn-lesson' => 'enrolments_classes', 'dzn-attendance-review' => 'enrolments_classes', 'dzn-core-dataset-readiness' => 'enrolments_classes',
        'dzn-finance-statements' => 'finance', 'dzn-finance-rates' => 'finance', 'dzn-finance-payability' => 'finance', 'dzn-finance-policies' => 'finance', 'dzn-finance-reconciliation' => 'finance', 'dzn-payment-execution' => 'finance', 'dzn-finance-cashbook' => 'finance',
        'dzn-communications' => 'communications', 'dzn-communications-templates' => 'communications',
        'dzn-integrations' => 'settings', 'dzn-integration-email' => 'settings', 'dzn-integration-whatsapp' => 'settings', 'dzn-integration-sms' => 'settings', 'dzn-integration-google' => 'settings', 'dzn-stripe-settings' => 'settings', 'dzn-system-health' => 'settings', 'dzn-exception' => 'settings', 'dzn-staff-access' => 'settings', 'dzn-portal-capabilities' => 'settings',
    ];

    public static function register(): void {
        add_action('in_admin_header', [__CLASS__, 'renderNavigation']);
        add_action('admin_head', [__CLASS__, 'styles']);
    }

    /** The top-level click is the Academy dashboard whenever that authority is available. */
    public static function screen(): void {
        if (current_user_can('dzn_view_booking_requests')) { AcademyOperationsController::screen(); return; }
        if (!self::hasVisibleTab()) wp_die('Forbidden', 403);
        echo '<div class="wrap"><h1>Delnavazan</h1><p>Select an available area above to continue.</p></div>';
    }

    /** Hide WordPress submenu sprawl after it has registered all protected routes. */
    public static function pruneWordPressSubmenu(): void {
        global $submenu;
        if (!self::hasVisibleTab()) { remove_menu_page(Menu::ROOT_SLUG); unset($submenu[Menu::ROOT_SLUG]); return; }
        // Do not unregister pages: direct URLs must retain WordPress's own capability checks.
        $submenu[Menu::ROOT_SLUG] = [];
    }

    public static function renderNavigation(): void {
        $page = self::currentPage();
        if (!str_starts_with($page, 'dzn-') || !self::hasVisibleTab()) return;
        $active = self::PAGE_TABS[$page] ?? '';
        echo '<div class="dzn-admin-shell" aria-label="Delnavazan"><div class="dzn-admin-shell__brand"><strong>Delnavazan</strong><span>Academy operations</span></div><nav class="dzn-admin-shell__tabs" aria-label="Delnavazan primary navigation">';
        foreach (self::TABS as $key => [$label, $destinations]) {
            $slug = self::firstAvailableDestination($destinations);
            if ($slug === null) continue;
            $class = $key === $active ? ' is-active' : '';
            echo '<a class="dzn-admin-shell__tab' . esc_attr($class) . '" href="' . esc_url(admin_url('admin.php?page=' . rawurlencode($slug))) . '"' . ($key === $active ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav></div>';
    }

    public static function styles(): void {
        if (!str_starts_with(self::currentPage(), 'dzn-')) return;
        echo '<style>.dzn-admin-shell{margin:18px 20px 0 2px;padding:14px 16px 0;background:#fff;border:1px solid #dcdcde;border-radius:4px 4px 0 0}.dzn-admin-shell__brand{display:flex;gap:10px;align-items:baseline;margin:0 0 12px}.dzn-admin-shell__brand strong{font-size:16px}.dzn-admin-shell__brand span{color:#646970}.dzn-admin-shell__tabs{display:flex;gap:4px;flex-wrap:wrap}.dzn-admin-shell__tab{padding:8px 10px;text-decoration:none;border-bottom:3px solid transparent}.dzn-admin-shell__tab:hover{background:#f0f0f1}.dzn-admin-shell__tab.is-active{font-weight:600;color:#1d2327;border-bottom-color:#2271b1}@media(max-width:782px){.dzn-admin-shell{margin-right:10px}.dzn-admin-shell__tab{padding:9px 7px}}</style>';
    }

    private static function currentPage(): string {
        $page = $_GET['page'] ?? '';
        return is_scalar($page) ? sanitize_key(wp_unslash((string) $page)) : '';
    }

    private static function hasVisibleTab(): bool {
        foreach (self::TABS as [, $destinations]) if (self::firstAvailableDestination($destinations) !== null) return true;
        return false;
    }

    private static function firstAvailableDestination(array $destinations): ?string {
        foreach ($destinations as [$slug, $capability]) if (current_user_can($capability)) return $slug;
        return null;
    }
}
