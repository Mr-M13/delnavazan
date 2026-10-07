<?php
namespace Delnavazan\Platform\Admin\Controller;

/** Shared, capability-aware Delnavazan application chrome inside wp-admin. */
final class AdminShellController {
    /** The visible primary navigation follows academy work, never table layout. */
    private const TABS = [
        'academy' => ['Academy', [
            ['Dashboard', 'dzn-academy-dashboard', 'dzn_view_booking_requests'],
            ['Booking requests', 'dzn-booking-requests', 'dzn_view_booking_requests'],
            ['Request coordination', 'dzn-booking-request-coordination', 'dzn_manage_booking_request_coordination'],
        ]],
        'teachers' => ['Teachers', [['Teacher directory', 'dzn-teachers-menu', 'dzn_manage_teachers']]],
        'students' => ['Students', [
            ['Student directory', 'dzn-student', 'dzn_manage_students'],
            ['Identity and acceptance', 'dzn-student-identity-authority', 'dzn_view_student_acceptance_eligibility'],
        ]],
        'courses' => ['Courses', [
            ['Courses', 'dzn-course', 'dzn_manage_courses'],
            ['Terms', 'dzn-term', 'dzn_manage_terms'],
            ['Instruments', 'dzn-instrument', 'dzn_manage_courses'],
            ['Course catalogue and pricing', 'dzn-commercial-catalogue', 'dzn_manage_commercial_catalogue'],
        ]],
        'enrolments_classes' => ['Enrolments / Classes', [
            ['Enrolments', 'dzn-enrolment', 'dzn_manage_enrolments'],
            ['Classes', 'dzn-lesson', 'dzn_manage_lessons'],
            ['Attendance review', 'dzn-attendance-review', 'dzn_manage_canonical_attendance_review'],
            ['Operational actions', 'dzn-core-dataset-readiness', 'dzn_convert_service_arrangements_to_enrolments'],
            ['Operational actions', 'dzn-core-dataset-readiness', 'dzn_issue_student_account_claims'],
            ['Operational actions', 'dzn-core-dataset-readiness', 'dzn_manage_teacher_assignments'],
            ['Operational actions', 'dzn-core-dataset-readiness', 'dzn_manage_canonical_terms'],
            ['Operational actions', 'dzn-core-dataset-readiness', 'dzn_manage_canonical_lessons'],
            ['Operational actions', 'dzn-core-dataset-readiness', 'dzn_manage_canonical_lesson_delivery'],
            ['Operational actions', 'dzn-core-dataset-readiness', 'dzn_manage_canonical_lesson_schedules'],
        ]],
        'finance' => ['Finance', [
            ['Teacher statements', 'dzn-finance-statements', 'dzn_view_finance_authority'],
            ['Teacher rates', 'dzn-finance-rates', 'dzn_view_finance_authority'],
            ['Class payability', 'dzn-finance-payability', 'dzn_view_finance_authority'],
            ['Finance policies', 'dzn-finance-policies', 'dzn_view_finance_authority'],
            ['Reconciliation', 'dzn-finance-reconciliation', 'dzn_view_finance_authority'],
            ['Payment execution', 'dzn-payment-execution', 'dzn_view_payment_execution_authority'],
            ['Academy account balances', 'dzn-finance-cashbook', 'dzn_view_cash_ledger'],
        ]],
        'communications' => ['Communications', [
            ['Communications health', 'dzn-communications', 'dzn_view_notification_authority'],
            ['Notification templates', 'dzn-communications-templates', 'dzn_manage_provider_integrations'],
        ]],
        'settings' => ['Settings', [
            ['Integrations overview', 'dzn-integrations', 'dzn_access_integrations_overview'],
            ['Email', 'dzn-integration-email', 'dzn_access_provider_integration_settings'],
            ['WhatsApp', 'dzn-integration-whatsapp', 'dzn_access_provider_integration_settings'],
            ['SMS fallback', 'dzn-integration-sms', 'dzn_access_provider_integration_settings'],
            ['Google Calendar', 'dzn-integration-google', 'dzn_access_provider_integration_settings'],
            ['Stripe setup', 'dzn-stripe-settings', ['dzn_view_payment_execution_authority', 'dzn_manage_payment_providers']],
            ['Portal capabilities', 'dzn-portal-capabilities', 'dzn_view_portal_capabilities'],
            ['Exceptions', 'dzn-exception', 'dzn_manage_exceptions'],
            ['System health', 'dzn-system-health', 'dzn_access_system_health'],
            ['Staff access', 'dzn-staff-access', 'manage_options'],
        ]],
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
        echo '</nav>';
        if (isset(self::TABS[$active])) {
            echo '<nav class="dzn-admin-shell__subtabs" aria-label="' . esc_attr(self::TABS[$active][0]) . ' pages">';
            $seen = [];
            foreach (self::TABS[$active][1] as [$routeLabel, $slug, $requiredCapabilities]) {
                if (isset($seen[$slug]) || !self::hasRequiredCapabilities($requiredCapabilities)) continue;
                $seen[$slug] = true;
                $current = $slug === $page || ($page === Menu::ROOT_SLUG && $slug === 'dzn-academy-dashboard');
                echo '<a class="dzn-admin-shell__subtab' . ($current ? ' is-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=' . rawurlencode($slug))) . '"' . ($current ? ' aria-current="page"' : '') . '>' . esc_html($routeLabel) . '</a>';
            }
            echo '</nav>';
        }
        echo '</div>';
    }

    public static function styles(): void {
        if (!str_starts_with(self::currentPage(), 'dzn-')) return;
        echo '<style>.dzn-admin-shell{margin:18px 20px 0 2px;padding:14px 16px 0;background:#fff;border:1px solid #dcdcde;border-radius:4px 4px 0 0}.dzn-admin-shell__brand{display:flex;gap:10px;align-items:baseline;margin:0 0 12px}.dzn-admin-shell__brand strong{font-size:16px}.dzn-admin-shell__brand span{color:#646970}.dzn-admin-shell__tabs,.dzn-admin-shell__subtabs{display:flex;gap:4px;flex-wrap:wrap}.dzn-admin-shell__tab,.dzn-admin-shell__subtab{padding:8px 10px;text-decoration:none;border-bottom:3px solid transparent}.dzn-admin-shell__subtabs{padding-top:5px;border-top:1px solid #dcdcde}.dzn-admin-shell__subtab{font-size:13px}.dzn-admin-shell__tab:hover,.dzn-admin-shell__subtab:hover{background:#f0f0f1}.dzn-admin-shell__tab.is-active,.dzn-admin-shell__subtab.is-active{font-weight:600;color:#1d2327;border-bottom-color:#2271b1}@media(max-width:782px){.dzn-admin-shell{margin-right:10px}.dzn-admin-shell__tab,.dzn-admin-shell__subtab{padding:9px 7px}}</style>';
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
        foreach ($destinations as [, $slug, $requiredCapabilities]) if (self::hasRequiredCapabilities($requiredCapabilities)) return $slug;
        return null;
    }

    private static function hasRequiredCapabilities($requiredCapabilities): bool {
        $requiredCapabilities = is_array($requiredCapabilities) ? $requiredCapabilities : [$requiredCapabilities];
        foreach ($requiredCapabilities as $capability) if (!current_user_can($capability)) return false;
        return true;
    }
}
