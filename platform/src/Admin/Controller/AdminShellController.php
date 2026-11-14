<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Admin\AcademyDashboardScreen;

/** Shared, capability-aware Delnavazan application chrome inside wp-admin. */
final class AdminShellController {
    /** The visible primary navigation follows academy work, never table layout. */
    private const TABS = [
        'overview' => ['Overview', [
            ['Overview', 'dzn-academy-dashboard', 'dzn_view_booking_requests'],
        ]],
        'teachers' => ['Teachers', [
            ['Teachers', 'dzn-teachers-menu', Menu::TEACHER_OPERATIONS_CAP],
        ]],
        'students' => ['Students', [
            ['Directory', 'dzn-student', 'dzn_manage_students'],
        ]],
        'enrolment' => ['Enrolment', [
            ['Enrolment', 'dzn-enrolment-workspace', Menu::ENROLMENT_WORKSPACE_CAP],
            // Catalogue management belongs with enrolment work, not Settings → General.
            ['Courses & instruments', 'dzn-catalogue-workspace', Menu::CATALOGUE_WORKSPACE_CAP],
        ]],
        'notifications' => ['Notifications', [
            ['Notifications', 'dzn-notifications', Menu::NOTIFICATION_MANAGEMENT_CAP],
        ]],
        'finance' => ['Finance', [
            ['Finance', 'dzn-finance', Menu::FINANCE_WORKSPACE_CAP, ['tab' => 'payments']],
        ]],
        'settings' => ['Settings', [
            ['General', 'dzn-general', 'manage_options'],
            ['Administration & Delegated Access', 'dzn-delegated-access', 'manage_options'],
            ['Policy', 'dzn-policies', Menu::POLICY_ACCESS_CAP],
            ['Integrations', 'dzn-integrations', 'dzn_access_integrations_overview'],
            ['Diagnostics', 'dzn-system-health', 'dzn_access_system_health'],
        ]],
        'godmode' => ['God Mode', [
            ['God Mode', 'dzn-god-mode', 'dzn_access_god_mode'],
        ]],
    ];

    /**
     * The visible Settings navigation is exactly five sections. Legacy protected routes that still
     * belong to a section (staff access, recording notices, provider details, recovery controls)
     * remain resolvable and highlight their canonical section. Retired recording-operations and
     * account-removal routes are no longer registered.
     */
    private const SETTINGS_SECTIONS = [
        'general' => ['General', ['dzn-general']],
        'access' => ['Administration & Delegated Access', ['dzn-delegated-access', 'dzn-staff-access']],
        'policy' => ['Policy', ['dzn-policies', 'dzn-recording-notices']],
        // Notifications is a primary workspace. These routes remain protected aliases.
        'integrations' => ['Integrations', [
            'dzn-integrations', 'dzn-integration-email', 'dzn-integration-whatsapp',
            'dzn-integration-sms', 'dzn-integration-google',
        ]],
        'diagnostics' => ['Diagnostics', ['dzn-system-health', 'dzn-portal-capabilities', 'dzn-core-dataset-readiness']],
    ];

    /** Alias retained for older source contracts; the visible shape is the six sections above. */
    private const SETTINGS_GROUPS = self::SETTINGS_SECTIONS;

    /** Capability for every registered Settings route, including protected legacy routes. */
    private const SETTINGS_ROUTE_CAPABILITIES = [
        'dzn-general' => 'manage_options',
        'dzn-delegated-access' => 'manage_options',
        'dzn-policies' => Menu::POLICY_ACCESS_CAP,
        'dzn-staff-access' => 'manage_options',
        'dzn-recording-notices' => 'manage_options',
        'dzn-notifications' => Menu::NOTIFICATION_MANAGEMENT_CAP,
        'dzn-communications' => 'dzn_view_notification_authority',
        'dzn-communications-templates' => 'dzn_manage_provider_integrations',
        'dzn-integrations' => 'dzn_access_integrations_overview',
        'dzn-integration-email' => 'dzn_access_provider_integration_settings',
        'dzn-integration-whatsapp' => 'dzn_access_provider_integration_settings',
        'dzn-integration-sms' => 'dzn_access_provider_integration_settings',
        'dzn-integration-google' => 'dzn_access_provider_integration_settings',
        'dzn-system-health' => 'dzn_access_system_health',
        'dzn-portal-capabilities' => 'dzn_view_portal_capabilities',
        'dzn-core-dataset-readiness' => Menu::CORE_WORKFLOW_CAP,
        'dzn-god-mode' => 'dzn_access_god_mode',
    ];

    /** Pages share their tab while retaining their original, protected callback. */
    private const PAGE_TABS = [
        'dzn-platform' => 'overview', 'dzn-academy-dashboard' => 'overview', 'dzn-god-mode' => 'godmode',
        'dzn-teachers-menu' => 'teachers',
        'dzn-student' => 'students', 'dzn-student-identity-authority' => 'students',
        'dzn-lesson' => 'enrolment', 'dzn-attendance-workspace' => 'enrolment',
        'dzn-attendance-review' => 'enrolment', 'dzn-post-intro' => 'enrolment',
        'dzn-enrolment-workspace' => 'enrolment', 'dzn-catalogue-workspace' => 'enrolment',
        'dzn-booking-requests' => 'enrolment', 'dzn-booking-request-coordination' => 'enrolment',
        'dzn-enrolment' => 'enrolment', 'dzn-term' => 'enrolment', 'dzn-course' => 'enrolment', 'dzn-instrument' => 'enrolment',
        'dzn-exception' => 'enrolment',
        'dzn-commercial-catalogue' => 'finance', 'dzn-payment-execution' => 'finance', 'dzn-finance-cashbook' => 'finance',
        'dzn-finance' => 'finance', 'dzn-finance-reconciliation' => 'finance', 'dzn-finance-policies' => 'finance', 'dzn-stripe-settings' => 'finance',
        'dzn-finance-rates' => 'finance', 'dzn-finance-statements' => 'finance', 'dzn-finance-payability' => 'finance',
        'dzn-general' => 'settings', 'dzn-integration-email' => 'settings', 'dzn-integration-whatsapp' => 'settings',
        'dzn-policies' => 'settings', 'dzn-delegated-access' => 'settings',
        'dzn-integration-sms' => 'settings', 'dzn-notifications' => 'notifications', 'dzn-communications' => 'notifications', 'dzn-communications-templates' => 'notifications',
        'dzn-integrations' => 'settings', 'dzn-integration-google' => 'settings', 'dzn-staff-access' => 'settings',
        'dzn-system-health' => 'settings', 'dzn-portal-capabilities' => 'settings', 'dzn-core-dataset-readiness' => 'settings',
        'dzn-recording-notices' => 'settings',
    ];

    public static function register(): void {
        add_action('in_admin_header', [__CLASS__, 'renderNavigation']);
        add_action('admin_head', [__CLASS__, 'styles']);
        add_action('admin_head', [__CLASS__, 'viewport']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueAssets']);
        add_filter('admin_body_class', [__CLASS__, 'bodyClass']);
        add_filter('wp_redirect', [__CLASS__, 'preserveReturnContext'], 10, 2);
        AcademyDashboardScreen::register();
    }

    /**
     * Keep a validated in-admin return destination across POST/redirect/GET.
     * Central record workflows use this to return staff to the originating
     * student profile without accepting an open redirect.
     */
    public static function preserveReturnContext($location, $status) {
        $raw = $_REQUEST['dzn_return_to'] ?? '';
        if (!is_string($raw) || $raw === '') return $location;
        $safe = function_exists('wp_validate_redirect') ? wp_validate_redirect($raw, '') : '';
        if ($safe === '' || !is_string($location) || str_contains($location, 'dzn_return_to=')) return $location;
        return add_query_arg('dzn_return_to', $safe, $location);
    }

    /** The top-level click opens Overview whenever that authority is available. */
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
        $scriptUrl = plugins_url('src/Admin/Assets/admin.js', DZN_PLATFORM_FILE);
        $scriptPath = defined('DZN_PLATFORM_DIR') ? DZN_PLATFORM_DIR . 'src/Admin/Assets/admin.js' : '';
        $scriptVersion = $scriptPath !== '' && file_exists($scriptPath) ? (string) filemtime($scriptPath) : $version;
        wp_enqueue_script('dzn-admin', $scriptUrl, array(), $scriptVersion, true);
        $clockUrl = function_exists('get_theme_file_uri') ? get_theme_file_uri('assets/css/coverage-clock.css') : '';
        if ($clockUrl !== '') {
            $clockPath = function_exists('get_theme_file_path') ? get_theme_file_path('assets/css/coverage-clock.css') : '';
            wp_enqueue_style('dzn-coverage-clock', $clockUrl, array('dzn-admin'), $clockPath !== '' && file_exists($clockPath) ? (string) filemtime($clockPath) : $version);
        }
        $clockScriptUrl = function_exists('get_theme_file_uri') ? get_theme_file_uri('assets/js/coverage-clock.js') : '';
        if ($clockScriptUrl !== '') {
            $clockScriptPath = function_exists('get_theme_file_path') ? get_theme_file_path('assets/js/coverage-clock.js') : '';
            wp_enqueue_script('dzn-coverage-clock', $clockScriptUrl, array(), $clockScriptPath !== '' && file_exists($clockScriptPath) ? (string) filemtime($clockScriptPath) : $scriptVersion, true);
        }
        $font = function_exists('get_theme_file_uri') ? get_theme_file_uri('assets/fonts/Vazirmatn-Variable.woff2') : '';
        if ($font !== '') {
            wp_add_inline_style('dzn-admin', "@font-face{font-family:Vazirmatn;src:url('" . esc_url_raw($font) . "') format('woff2');font-weight:100 900;font-style:normal;font-display:swap}");
        }
    }

    public static function bodyClass($classes): string {
        if (str_starts_with(self::currentPage(), 'dzn-')) $classes .= ' dzn-admin-page';
        if (self::currentPage() === EnrolmentWorkspaceController::PAGE && isset($_GET['dzn_modal'])) $classes .= ' dzn-class-admin-frame';
        return $classes;
    }

    public static function renderNavigation(): void {
        $page = self::currentPage();
        if (!str_starts_with($page, 'dzn-') || !self::hasVisibleTab()) return;
        $active = self::PAGE_TABS[$page] ?? '';
        $today = function_exists('wp_date') ? wp_date('l, j F Y') : gmdate('l, j F Y');
        echo '<div class="dzn-admin-shell" aria-label="Delnavazan"><div class="dzn-admin-shell__brand">';
        $logo = function_exists('get_theme_file_uri') ? get_theme_file_uri('assets/images/delnavazan-logo.png') : '';
        if ($logo !== '') {
            echo '<span class="dzn-admin-shell__mark" aria-hidden="true"><img src="' . esc_url($logo) . '" alt=""></span>';
        }
        echo '<span class="dzn-admin-shell__brand-copy"><strong>Delnavazan</strong><span>Academy operations</span></span>';
        $siteUrl = function_exists('home_url') ? home_url('/') : '#';
        echo '<span class="dzn-admin-shell__context"><span>' . esc_html($today) . '</span><a href="' . esc_url($siteUrl) . '">View site</a></span></div>';
        self::renderReturnBar($page);
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

    private static function renderReturnBar(string $page): void {
        if ($page === 'dzn-student') return;
        $raw = $_GET['dzn_return_to'] ?? '';
        if (!is_string($raw) || $raw === '') return;
        $safe = function_exists('wp_validate_redirect') ? wp_validate_redirect($raw, '') : '';
        if ($safe === '') return;
        echo '<div class="dzn-admin-return"><a href="' . esc_url($safe) . '">← Back to student profile</a><span>The action below belongs to a central workflow and will return here when completed.</span></div>';
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
        // A single destination needs no second navigation row (Overview is one page).
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

    /** Settings routes stay resolvable while notifications remain a primary workspace. */
    private static function renderSettingsNavigation(string $page): void {
        $visible = [];
        foreach (self::SETTINGS_SECTIONS as $key => [$label, $routes]) {
            $route = self::firstAvailableSettingsRoute($routes);
            if ($route !== null) $visible[$key] = [$label, $route];
        }
        if ($visible === []) return;
        $active = self::settingsGroupForPage($page);
        if (!isset($visible[$active])) $active = (string) array_key_first($visible);
        echo '<nav class="dzn-admin-shell__subtabs" aria-label="Settings sections">';
        foreach ($visible as $key => [$label, $route]) {
            $url = admin_url('admin.php?page=' . rawurlencode($route));
            $current = $key === $active;
            echo '<a class="dzn-admin-shell__subtab' . ($current ? ' is-active' : '') . '" href="' . esc_url($url) . '"' . ($current ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    /** Prefer the canonical section route, then any protected legacy route the operator may open. */
    private static function firstAvailableSettingsRoute(array $routes): ?string {
        foreach ($routes as $route) {
            $capability = self::SETTINGS_ROUTE_CAPABILITIES[$route] ?? '';
            if ($capability !== '' && current_user_can($capability)) return $route;
        }
        return null;
    }

    private static function settingsGroupForPage(string $page): string {
        foreach (self::SETTINGS_SECTIONS as $key => [, $routes]) {
            if (in_array($page, $routes, true)) return $key;
        }
        return '';
    }

    /** Native wp-admin predates mobile viewport metadata; Delnavazan pages declare it explicitly. */
    public static function viewport(): void {
        if (!str_starts_with(self::currentPage(), 'dzn-')) return;
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
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
