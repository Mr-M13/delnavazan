<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\AcademyDashboardScreen;

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
            ['Directory', 'dzn-student', 'dzn_manage_students'],
            ['Identity & accounts', 'dzn-student-identity-authority', 'dzn_view_student_acceptance_eligibility'],
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
            ['Notifications & Messaging', 'dzn-notifications', Menu::NOTIFICATION_MANAGEMENT_CAP],
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

    /**
     * Settings is a single area with five work groups. The individual protected
     * routes stay registered; this map only decides how the same routes are
     * presented inside the shell. A group is visible when at least one of its
     * member routes is permitted.
     */
    private const SETTINGS_GROUPS = [
        'general' => ['General', ['dzn-general']],
        'messaging' => ['Messaging', ['dzn-notifications', 'dzn-integration-email', 'dzn-integration-whatsapp', 'dzn-integration-sms', 'dzn-communications', 'dzn-communications-templates']],
        'integrations' => ['Integrations', ['dzn-integrations', 'dzn-integration-google']],
        'access' => ['Access', ['dzn-staff-access', 'dzn-portal-capabilities']],
        'system' => ['System', ['dzn-system-health', 'dzn-core-dataset-readiness']],
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
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueAssets']);
        add_filter('admin_body_class', [__CLASS__, 'bodyClass']);
        AcademyDashboardScreen::register();
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

    /** Load the shared Delnavazan admin design layer only on Delnavazan pages. */
    public static function enqueueAssets(): void {
        $page = self::currentPage();
        if (!str_starts_with($page, 'dzn-')) return;
        $url = plugins_url('src/Admin/Assets/admin.css', DZN_PLATFORM_FILE);
        $path = defined('DZN_PLATFORM_DIR') ? DZN_PLATFORM_DIR . 'src/Admin/Assets/admin.css' : '';
        $version = $path !== '' && file_exists($path) ? (string) filemtime($path) : (defined('DZN_PLATFORM_VERSION') ? DZN_PLATFORM_VERSION : null);
        wp_enqueue_style('dzn-admin', $url, [], $version);
        $font = function_exists('get_theme_file_uri') ? get_theme_file_uri('assets/fonts/Vazirmatn-Variable.woff2') : '';
        if ($font !== '') {
            wp_add_inline_style('dzn-admin', "@font-face{font-family:Vazirmatn;src:url('" . esc_url_raw($font) . "') format('woff2');font-weight:100 900;font-style:normal;font-display:swap}");
        }
    }

    public static function bodyClass($classes): string {
        if (str_starts_with(self::currentPage(), 'dzn-')) $classes .= ' dzn-admin-page';
        return $classes;
    }

    public static function renderNavigation(): void {
        $page = self::currentPage();
        if (!str_starts_with($page, 'dzn-') || !self::hasVisibleTab()) return;
        $active = self::PAGE_TABS[$page] ?? '';
        $today = function_exists('wp_date') ? wp_date('l, j F Y') : gmdate('l, j F Y');
        echo '<div class="dzn-admin-shell" aria-label="Delnavazan"><div class="dzn-admin-shell__brand">';
        echo '<span class="dzn-admin-shell__mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 17c3.2-6.4 5.4-9.6 7.6-9.6S15 10.6 15.8 14.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M15.8 14.4c.8 3.8 2 5.6 3.8 5.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="6.1" cy="5.9" r="1.4" fill="currentColor"/><path d="M18.6 4.2v6.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>';
        echo '<span class="dzn-admin-shell__brand-copy"><strong>Delnavazan</strong><span>Academy operations</span></span>';
        $siteUrl = function_exists('home_url') ? home_url('/') : '#';
        echo '<span class="dzn-admin-shell__context"><span>' . esc_html($today) . '</span><a href="' . esc_url($siteUrl) . '">View site</a></span></div>';
        echo '<nav class="dzn-admin-shell__tabs" aria-label="Delnavazan primary navigation">';
        foreach (self::TABS as $key => [$label, $destinations]) {
            $url = self::firstAvailableDestinationUrl($destinations);
            if ($url === null) continue;
            $class = $key === $active ? ' is-active' : '';
            echo '<a class="dzn-admin-shell__tab' . esc_attr($class) . '" href="' . esc_url($url) . '"' . ($key === $active ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        if (!isset(self::TABS[$active])) { echo '</div>'; return; }
        if ($active === 'settings') {
            self::renderSettingsNavigation($page);
        } else {
            self::renderAreaNavigation($active, $page);
        }
        echo '</div>';
    }

    private static function renderAreaNavigation(string $active, string $page): void {
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
        if (count($visible) <= 1) return;
        echo '<nav class="dzn-admin-shell__subtabs" aria-label="' . esc_attr(self::TABS[$active][0]) . ' pages">';
        foreach ($visible as [$routeLabel, $slug, $args]) {
            $current = $slug === $page && ($args['view'] ?? '') === $requestedView;
            if ($page === Menu::ROOT_SLUG && $slug === 'dzn-academy-dashboard' && ($args['view'] ?? '') === '') $current = true;
            $url = admin_url('admin.php?' . http_build_query(array_merge(array('page' => $slug), $args)));
            echo '<a class="dzn-admin-shell__subtab' . ($current ? ' is-active' : '') . '" href="' . esc_url($url) . '"' . ($current ? ' aria-current="page"' : '') . '>' . esc_html($routeLabel) . '</a>';
        }
        echo '</nav>';
    }

    /** Settings is grouped by work, not by provider or table. */
    private static function renderSettingsNavigation(string $page): void {
        $members = [];
        foreach (self::TABS['settings'][1] as $destination) {
            [$label, $slug, $capabilities] = $destination;
            if (!self::hasRequiredCapabilities($capabilities)) continue;
            $members[$slug] = $destination;
        }
        if (!$members) return;
        $activeGroup = self::settingsGroupForPage($page);
        if ($activeGroup === '') $activeGroup = array_key_first(self::SETTINGS_GROUPS);
        $visibleGroups = [];
        foreach (self::SETTINGS_GROUPS as $key => [$groupLabel, $slugs]) {
            $groupMembers = [];
            foreach ($slugs as $slug) if (isset($members[$slug])) $groupMembers[] = $members[$slug];
            if ($groupMembers) $visibleGroups[$key] = [$groupLabel, $groupMembers];
        }
        if (!isset($visibleGroups[$activeGroup])) {
            $activeGroup = (string) array_key_first($visibleGroups);
        }
        echo '<nav class="dzn-admin-shell__subtabs" aria-label="Settings groups">';
        foreach ($visibleGroups as $key => [$groupLabel, $groupMembers]) {
            [$label, $slug, $capabilities] = $groupMembers[0];
            $args = isset($groupMembers[0][3]) && is_array($groupMembers[0][3]) ? $groupMembers[0][3] : [];
            $url = admin_url('admin.php?' . http_build_query(array_merge(array('page' => $slug), $args)));
            $current = $key === $activeGroup;
            echo '<a class="dzn-admin-shell__subtab' . ($current ? ' is-active' : '') . '" href="' . esc_url($url) . '"' . ($current ? ' aria-current="page"' : '') . '>' . esc_html($groupLabel) . '</a>';
        }
        echo '</nav>';
        if (!isset($visibleGroups[$activeGroup])) return;
        $activeMembers = $visibleGroups[$activeGroup][1];
        if (count($activeMembers) === 1 && $activeMembers[0][0] === $visibleGroups[$activeGroup][0]) return;
        echo '<nav class="dzn-admin-shell__subtabs dzn-admin-shell__subtabs--pages" aria-label="' . esc_attr($visibleGroups[$activeGroup][0]) . ' pages">';
        foreach ($visibleGroups[$activeGroup][1] as $destination) {
            [$label, $slug, $capabilities] = $destination;
            $args = isset($destination[3]) && is_array($destination[3]) ? $destination[3] : [];
            $url = admin_url('admin.php?' . http_build_query(array_merge(array('page' => $slug), $args)));
            $current = $slug === $page;
            echo '<a class="dzn-admin-shell__subtab' . ($current ? ' is-active' : '') . '" href="' . esc_url($url) . '"' . ($current ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private static function settingsGroupForPage(string $page): string {
        foreach (self::SETTINGS_GROUPS as $key => [, $slugs]) {
            if (in_array($page, $slugs, true)) return $key;
        }
        return '';
    }

    public static function styles(): void {
        // The WordPress registrations remain the route/capability authority, but their
        // generated submenu is never a second navigation system -- including on other
        // wp-admin screens where the Delnavazan menu can be expanded.
        echo '<style>#adminmenu #toplevel_page_dzn-platform .wp-submenu{display:none}</style>';
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
