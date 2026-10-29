<?php
namespace Delnavazan\Platform\Admin\Controller;

/** Shared, capability-aware Delnavazan application chrome inside wp-admin. */
final class AdminShellController {
    /** The visible primary navigation follows academy work, never table layout. */
    private const TABS = [
        'academy' => ['Academy', [
            ['Academy', 'dzn-academy-dashboard', 'dzn_view_booking_requests'],
        ]],
        'teachers' => ['Teachers', [
            ['Overview', 'dzn-teachers-menu', 'dzn_manage_teachers'],
            ['Onboarding & Invitations', 'dzn-teachers-menu', Menu::TEACHER_ONBOARDING_CAP, ['view' => 'onboarding']],
        ]],
        'students' => ['Students', [
            ['Student directory', 'dzn-student', 'dzn_manage_students'],
            ['Profiles and learning progress', 'dzn-student-identity-authority', 'dzn_view_student_acceptance_eligibility'],
        ]],
        'enrolment' => ['Enrolment', [
            ['Classes & Schedules', 'dzn-lesson', 'dzn_manage_lessons'],
            ['Bookings & Enrolments', 'dzn-enrolment-workspace', Menu::ENROLMENT_WORKSPACE_CAP],
            ['Courses & Instruments', 'dzn-catalogue-workspace', Menu::CATALOGUE_WORKSPACE_CAP],
            ['Attendance & Post-intro', 'dzn-attendance-workspace', Menu::ATTENDANCE_WORKSPACE_CAP],
        ]],
        'finance' => ['Finance', [
            ['Finance', 'dzn-finance', Menu::FINANCE_WORKSPACE_CAP, ['tab' => 'payments']],
        ]],
        'settings' => ['Settings', [
            ['General', 'dzn-general', 'manage_options'],
            ['Email', 'dzn-integration-email', 'dzn_access_provider_integration_settings'],
            ['Meta WhatsApp Cloud API', 'dzn-integration-whatsapp', 'dzn_access_provider_integration_settings'],
            ['SMS fallback', 'dzn-integration-sms', 'dzn_access_provider_integration_settings'],
            ['اعلان‌ها و پیام‌رسانی', 'dzn-notifications', Menu::NOTIFICATION_MANAGEMENT_CAP],
            ['Communications health', 'dzn-communications', 'dzn_view_notification_authority'],
            ['Notification templates', 'dzn-communications-templates', 'dzn_manage_provider_integrations'],
            ['Integrations', 'dzn-integrations', 'dzn_access_integrations_overview'],
            ['Google OAuth / Calendar', 'dzn-integration-google', 'dzn_access_provider_integration_settings'],
            ['Staff access', 'dzn-staff-access', 'manage_options'],
            ['System health and technical diagnostics', 'dzn-system-health', 'dzn_access_system_health'],
            ['Portal capabilities', 'dzn-portal-capabilities', 'dzn_view_portal_capabilities'],
            ['Advanced Recovery', 'dzn-core-dataset-readiness', Menu::CORE_WORKFLOW_CAP],
        ]],
    ];

    /** Pages share their tab while retaining their original, protected callback. */
    private const PAGE_TABS = [
        'dzn-platform' => 'academy', 'dzn-academy-dashboard' => 'academy',
        'dzn-teachers-menu' => 'teachers',
        'dzn-student' => 'students', 'dzn-student-identity-authority' => 'students',
        'dzn-lesson' => 'enrolment', 'dzn-enrolment-workspace' => 'enrolment',
        'dzn-catalogue-workspace' => 'enrolment', 'dzn-attendance-workspace' => 'enrolment',
        'dzn-booking-requests' => 'enrolment', 'dzn-booking-request-coordination' => 'enrolment',
        'dzn-enrolment' => 'enrolment', 'dzn-term' => 'enrolment', 'dzn-course' => 'enrolment', 'dzn-instrument' => 'enrolment',
        'dzn-attendance-review' => 'enrolment', 'dzn-exception' => 'enrolment', 'dzn-post-intro' => 'enrolment',
        'dzn-commercial-catalogue' => 'finance', 'dzn-payment-execution' => 'finance', 'dzn-finance-cashbook' => 'finance',
        'dzn-finance' => 'finance', 'dzn-finance-reconciliation' => 'finance', 'dzn-finance-policies' => 'finance', 'dzn-stripe-settings' => 'finance',
        'dzn-finance-rates' => 'finance', 'dzn-finance-statements' => 'finance', 'dzn-finance-payability' => 'finance',
        'dzn-general' => 'settings', 'dzn-integration-email' => 'settings', 'dzn-integration-whatsapp' => 'settings',
        'dzn-integration-sms' => 'settings', 'dzn-notifications' => 'settings', 'dzn-communications' => 'settings', 'dzn-communications-templates' => 'settings',
        'dzn-integrations' => 'settings', 'dzn-integration-google' => 'settings', 'dzn-staff-access' => 'settings',
        'dzn-system-health' => 'settings', 'dzn-portal-capabilities' => 'settings', 'dzn-core-dataset-readiness' => 'settings',
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
            $url = self::firstAvailableDestinationUrl($destinations);
            if ($url === null) continue;
            $class = $key === $active ? ' is-active' : '';
            echo '<a class="dzn-admin-shell__tab' . esc_attr($class) . '" href="' . esc_url($url) . '"' . ($key === $active ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        if (isset(self::TABS[$active])) {
            $requestedView = isset($_GET['view']) && is_scalar($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : '';
            $visible = [];
            $seen = [];
            foreach (self::TABS[$active][1] as $destination) {
                [$routeLabel, $slug, $requiredCapabilities] = $destination;
                $args = isset($destination[3]) && is_array($destination[3]) ? $destination[3] : [];
                $key = $slug . '?' . http_build_query($args);
                if (isset($seen[$key]) || !self::hasRequiredCapabilities($requiredCapabilities)) continue;
                $seen[$key] = true;
                $visible[] = [$routeLabel, $slug, $args];
            }
            // A single destination needs no second navigation row (Academy is one page).
            if (count($visible) > 1) {
                echo '<nav class="dzn-admin-shell__subtabs" aria-label="' . esc_attr(self::TABS[$active][0]) . ' pages">';
                foreach ($visible as [$routeLabel, $slug, $args]) {
                    $current = $slug === $page && ($args['view'] ?? '') === $requestedView;
                    if ($page === Menu::ROOT_SLUG && $slug === 'dzn-academy-dashboard' && ($args['view'] ?? '') === '') $current = true;
                    $url = admin_url('admin.php?' . http_build_query(array_merge(array('page' => $slug), $args)));
                    echo '<a class="dzn-admin-shell__subtab' . ($current ? ' is-active' : '') . '" href="' . esc_url($url) . '"' . ($current ? ' aria-current="page"' : '') . '>' . esc_html($routeLabel) . '</a>';
                }
                echo '</nav>';
            }
        }
        echo '</div>';
    }

    public static function styles(): void {
        // The WordPress registrations remain the route/capability authority, but their
        // generated submenu is never a second navigation system -- including on other
        // wp-admin screens where the Delnavazan menu can be expanded.
        echo '<style>#adminmenu #toplevel_page_dzn-platform .wp-submenu{display:none}</style>';
        if (!str_starts_with(self::currentPage(), 'dzn-')) return;
        echo '<style>.dzn-admin-shell{margin-block-start:18px;margin-inline-start:2px;margin-inline-end:20px;padding:14px 16px 0;background:#fff;border:1px solid #dcdcde;border-radius:4px 4px 0 0}.dzn-admin-shell__brand{display:flex;gap:10px;align-items:baseline;margin:0 0 12px}.dzn-admin-shell__brand strong{font-size:16px}.dzn-admin-shell__brand span{color:#646970}.dzn-admin-shell__tabs,.dzn-admin-shell__subtabs{display:flex;gap:4px;flex-wrap:wrap}.dzn-admin-shell__tab,.dzn-admin-shell__subtab{padding:8px 10px;text-decoration:none;border-bottom:3px solid transparent}.dzn-admin-shell__subtabs{padding-top:5px;border-top:1px solid #dcdcde}.dzn-admin-shell__subtab{font-size:13px}.dzn-admin-shell__tab:hover,.dzn-admin-shell__subtab:hover{background:#f0f0f1}.dzn-admin-shell__tab.is-active,.dzn-admin-shell__subtab.is-active{font-weight:600;color:#1d2327;border-bottom-color:#2271b1}.dzn-workspace-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-block-start:12px}.dzn-workspace-card{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:14px}.dzn-workspace-card h2{margin-block-start:0;font-size:16px}.dzn-workspace-card .button{margin-block-start:6px}.dzn-workspace-note{margin-block-start:16px;color:#646970}@media(max-width:782px){.dzn-admin-shell{margin-inline-end:10px}.dzn-admin-shell__tab,.dzn-admin-shell__subtab{padding:9px 7px}}</style>';
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

    private static function firstAvailableDestinationUrl(array $destinations): ?string {
        foreach ($destinations as $destination) {
            [, $slug, $requiredCapabilities] = $destination;
            if (!self::hasRequiredCapabilities($requiredCapabilities)) continue;
            $args = isset($destination[3]) && is_array($destination[3]) ? $destination[3] : [];
            return admin_url('admin.php?' . http_build_query(array_merge(array('page' => $slug), $args)));
        }
        return null;
    }

    private static function hasRequiredCapabilities($requiredCapabilities): bool {
        $requiredCapabilities = is_array($requiredCapabilities) ? $requiredCapabilities : [$requiredCapabilities];
        foreach ($requiredCapabilities as $capability) if (!current_user_can($capability)) return false;
        return true;
    }
}
