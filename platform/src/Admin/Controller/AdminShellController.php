<?php
namespace Delnavazan\Platform\Admin\Controller;

/** Shared, capability-aware Delnavazan application chrome inside wp-admin. */
final class AdminShellController {
    /** The visible primary navigation follows academy work, never table layout. */
    private const TABS = [
        'academy' => ['Academy', [
            ['Overview', 'dzn-academy-dashboard', 'dzn_view_booking_requests', ['view' => 'overview']],
            ['Today and upcoming lessons', 'dzn-academy-dashboard', 'dzn_manage_lessons', ['view' => 'upcoming']],
            ['Attention needed', 'dzn-academy-dashboard', 'dzn_manage_lessons', ['view' => 'attention']],
        ]],
        'teachers' => ['Teachers', [
            ['Teacher directory and profiles', 'dzn-teachers-menu', Menu::TEACHER_OPERATIONS_CAP],
            ['Teacher-specific rates', 'dzn-finance-rates', 'dzn_view_finance_authority'],
            ['Lesson payability', 'dzn-finance-payability', 'dzn_view_finance_authority'],
            ['Teacher reconciliation', 'dzn-finance-statements', 'dzn_view_finance_authority'],
        ]],
        'students' => ['Students', [
            ['Student directory', 'dzn-student', 'dzn_manage_students'],
            ['Profiles and learning progress', 'dzn-student-identity-authority', 'dzn_view_student_acceptance_eligibility'],
        ]],
        'enrolment' => ['Enrolment', [
            ['Classes and schedule', 'dzn-lesson', 'dzn_manage_lessons'],
            ['Booking requests', 'dzn-booking-requests', 'dzn_view_booking_requests'],
            ['Request coordination', 'dzn-booking-request-coordination', 'dzn_manage_booking_request_coordination'],
            ['Enrolments', 'dzn-enrolment', 'dzn_manage_enrolments'],
            ['Terms', 'dzn-term', 'dzn_manage_terms'],
            ['Courses and instruments', 'dzn-course', 'dzn_manage_courses'],
            ['Instruments', 'dzn-instrument', 'dzn_manage_courses'],
            ['Attendance review', 'dzn-attendance-review', 'dzn_manage_canonical_attendance_review'],
            ['Catalogue and operational exceptions', 'dzn-exception', 'dzn_manage_exceptions'],
            ['Canonical workflow actions', 'dzn-core-dataset-readiness', Menu::CORE_WORKFLOW_CAP],
            ['Post-intro automation', 'dzn-post-intro', 'dzn_manage_post_intro_automation'],
        ]],
        'finance' => ['Finance', [
            ['Student pricing', 'dzn-commercial-catalogue', 'dzn_manage_commercial_catalogue'],
            ['Payments', 'dzn-payment-execution', 'dzn_view_payment_execution_authority'],
            ['Central ledger', 'dzn-finance-cashbook', 'dzn_view_cash_ledger'],
            ['Reconciliation', 'dzn-finance-reconciliation', 'dzn_view_finance_authority'],
            ['Finance policies', 'dzn-finance-policies', 'dzn_view_finance_authority'],
            ['Stripe integration', 'dzn-stripe-settings', ['dzn_view_payment_execution_authority', 'dzn_manage_payment_providers']],
        ]],
        'settings' => ['Settings', [
            ['General', 'dzn-general', 'manage_options'],
            ['Email', 'dzn-integration-email', 'dzn_access_provider_integration_settings'],
            ['Meta WhatsApp Cloud API', 'dzn-integration-whatsapp', 'dzn_access_provider_integration_settings'],
            ['SMS fallback', 'dzn-integration-sms', 'dzn_access_provider_integration_settings'],
            ['Communications health', 'dzn-communications', 'dzn_view_notification_authority'],
            ['Notification templates', 'dzn-communications-templates', 'dzn_manage_provider_integrations'],
            ['Integrations', 'dzn-integrations', 'dzn_access_integrations_overview'],
            ['Google OAuth / Calendar', 'dzn-integration-google', 'dzn_access_provider_integration_settings'],
            ['Staff access', 'dzn-staff-access', 'manage_options'],
            ['System health and technical diagnostics', 'dzn-system-health', 'dzn_access_system_health'],
            ['Portal capabilities', 'dzn-portal-capabilities', 'dzn_view_portal_capabilities'],
        ]],
    ];

    /** Pages share their tab while retaining their original, protected callback. */
    private const PAGE_TABS = [
        'dzn-platform' => 'academy', 'dzn-academy-dashboard' => 'academy',
        'dzn-teachers-menu' => 'teachers', 'dzn-finance-rates' => 'teachers', 'dzn-finance-payability' => 'teachers', 'dzn-finance-statements' => 'teachers',
        'dzn-student' => 'students', 'dzn-student-identity-authority' => 'students',
        'dzn-lesson' => 'enrolment', 'dzn-booking-requests' => 'enrolment', 'dzn-booking-request-coordination' => 'enrolment',
        'dzn-enrolment' => 'enrolment', 'dzn-term' => 'enrolment', 'dzn-course' => 'enrolment', 'dzn-instrument' => 'enrolment',
        'dzn-attendance-review' => 'enrolment', 'dzn-exception' => 'enrolment', 'dzn-core-dataset-readiness' => 'enrolment', 'dzn-post-intro' => 'enrolment',
        'dzn-commercial-catalogue' => 'finance', 'dzn-payment-execution' => 'finance', 'dzn-finance-cashbook' => 'finance',
        'dzn-finance-reconciliation' => 'finance', 'dzn-finance-policies' => 'finance', 'dzn-stripe-settings' => 'finance',
        'dzn-general' => 'settings', 'dzn-integration-email' => 'settings', 'dzn-integration-whatsapp' => 'settings',
        'dzn-integration-sms' => 'settings', 'dzn-communications' => 'settings', 'dzn-communications-templates' => 'settings',
        'dzn-integrations' => 'settings', 'dzn-integration-google' => 'settings', 'dzn-staff-access' => 'settings',
        'dzn-system-health' => 'settings', 'dzn-portal-capabilities' => 'settings',
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
        // WordPress resolves admin.php?page=... against this registration table when it
        // authorizes direct routes. Keep it intact; CSS hides only the legacy sidebar links.
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
            $requestedView = isset($_GET['view']) && is_scalar($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : '';
            foreach (self::TABS[$active][1] as $destination) {
                [$routeLabel, $slug, $requiredCapabilities] = $destination;
                $args = isset($destination[3]) && is_array($destination[3]) ? $destination[3] : [];
                $key = $slug . '?' . http_build_query($args);
                if (isset($seen[$key]) || !self::hasRequiredCapabilities($requiredCapabilities)) continue;
                $seen[$key] = true;
                $current = $slug === $page && ($args['view'] ?? '') === $requestedView;
                if ($page === Menu::ROOT_SLUG && $slug === 'dzn-academy-dashboard' && ($args['view'] ?? '') === 'overview') $current = true;
                $url = admin_url('admin.php?' . http_build_query(array_merge(array('page' => $slug), $args)));
                echo '<a class="dzn-admin-shell__subtab' . ($current ? ' is-active' : '') . '" href="' . esc_url($url) . '"' . ($current ? ' aria-current="page"' : '') . '>' . esc_html($routeLabel) . '</a>';
            }
            echo '</nav>';
        }
        echo '</div>';
    }

    public static function styles(): void {
        // The WordPress registrations remain the route/capability authority, but their
        // generated submenu is never a second navigation system -- including on other
        // wp-admin screens where the Delnavazan menu can be expanded.
        echo '<style>#adminmenu #toplevel_page_dzn-platform .wp-submenu{display:none}</style>';
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
