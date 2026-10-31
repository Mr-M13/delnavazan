<?php
namespace Delnavazan\Platform\Admin;

/**
 * Native WordPress Screen Options for the Academy dashboard.
 *
 * The dashboard is deliberately section-based. Operators choose which sections
 * they need; the preference is stored per WordPress user through the native
 * Screen Options form, so it follows the user without adding a parallel
 * settings screen. No business data or capability is changed here.
 */
final class AcademyDashboardScreen {
    public const OPTION = 'dzn_academy_sections';
    public const DISMISSED_OPTION = 'dzn_academy_dismissed_actions';

    /** @return array<string, string> */
    public static function sections(): array {
        return array(
            'quick' => 'Quick actions',
            'kpis' => 'Key numbers',
            'actions' => 'Action centre',
            'schedule' => 'Today and upcoming classes',
            'pipeline' => 'Learner pipeline',
            'activity' => '14-day activity',
            'finance' => 'Finance snapshot',
            'attention' => 'Needs attention',
            'queue' => 'Learner journey queue',
            'reschedules' => 'Class time changes',
        );
    }

    public static function register(): void {
        add_action('current_screen', array(__CLASS__, 'configure'));
        add_filter('screen_settings', array(__CLASS__, 'render'), 10, 2);
        add_filter('set_screen_option_' . self::OPTION, array(__CLASS__, 'save'), 10, 3);
        add_filter('screen_options_show_submit', static function ($show, $screen) {
            return $screen && self::isAcademyScreen((string) $screen->id) ? true : $show;
        }, 10, 2);
        add_action('admin_post_dzn_academy_dismiss_action', array(__CLASS__, 'dismissAction'));
        add_action('admin_post_dzn_academy_restore_action', array(__CLASS__, 'restoreAction'));
    }

    /**
     * Dismissing is a per-user presentation preference. It never resolves, approves or mutates
     * the underlying queue. A changed count brings the item back so new work cannot stay hidden.
     *
     * @return array<string,string>
     */
    public static function dismissed(): array {
        $stored = get_user_meta(get_current_user_id(), self::DISMISSED_OPTION, true);
        if (!is_array($stored)) return array();
        $dismissed = array();
        foreach ($stored as $key => $signature) {
            if (is_string($key) && is_scalar($signature)) $dismissed[$key] = (string) $signature;
        }
        return $dismissed;
    }

    public static function isDismissed(string $key, string $signature): bool {
        $dismissed = self::dismissed();
        return $key !== '' && array_key_exists($key, $dismissed) && hash_equals($dismissed[$key], $signature);
    }

    public static function dismissAction(): void {
        self::changeDismissal(true);
    }

    public static function restoreAction(): void {
        self::changeDismissal(false);
    }

    private static function changeDismissal(bool $dismiss): void {
        if (!current_user_can('dzn_view_booking_requests')) wp_die('Forbidden', 403);
        check_admin_referer($dismiss ? 'dzn_academy_dismiss_action' : 'dzn_academy_restore_action');
        $key = isset($_POST['action_key']) && is_scalar($_POST['action_key']) ? sanitize_key(wp_unslash((string) $_POST['action_key'])) : '';
        $signature = isset($_POST['signature']) && is_scalar($_POST['signature']) ? sanitize_text_field(wp_unslash((string) $_POST['signature'])) : '';
        $dismissed = self::dismissed();
        if ($key !== '') {
            if ($dismiss) $dismissed[$key] = substr($signature, 0, 64);
            else unset($dismissed[$key]);
            update_user_meta(get_current_user_id(), self::DISMISSED_OPTION, $dismissed);
        }
        $redirect = wp_get_referer();
        wp_safe_redirect($redirect !== false && $redirect !== '' ? $redirect : admin_url('admin.php?page=dzn-academy-dashboard'));
        exit;
    }

    public static function configure(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !self::isAcademyScreen((string) $screen->id)) return;
        add_screen_option(self::OPTION, array(
            'label' => 'Dashboard sections',
            'default' => array_fill_keys(array_keys(self::sections()), 1),
        ));
    }

    /**
     * @param string $settings Existing screen settings.
     * @param \WP_Screen $screen Current screen.
     */
    public static function render($settings, $screen): string {
        if (!$screen || !self::isAcademyScreen((string) $screen->id)) return (string) $settings;
        $visible = self::visible();
        $html = '<fieldset class="dzn-screen-options"><legend>Delnavazan dashboard sections</legend>';
        $html .= '<p class="description">Choose the sections shown on the Academy dashboard. Hidden sections remain available from the navigation.</p>';
        $html .= '<input type="hidden" name="wp_screen_options[option]" value="' . esc_attr(self::OPTION) . '">';
        foreach (self::sections() as $key => $label) {
            $html .= '<label class="dzn-screen-options__item"><input type="hidden" name="wp_screen_options[value][' . esc_attr($key) . ']" value="0">';
            $html .= '<input type="checkbox" name="wp_screen_options[value][' . esc_attr($key) . ']" value="1"' . checked($visible[$key] ?? true, true, false) . '> ';
            $html .= esc_html($label) . '</label>';
        }
        $html .= '</fieldset>';
        return (string) $settings . $html;
    }

    /**
     * @param mixed $value Submitted section map.
     * @param string $option Option name.
     * @param mixed $submitted Raw submitted value.
     * @return array<string, int>
     */
    public static function save($value, $option, $submitted): array {
        if ($option !== self::OPTION) return is_array($value) ? $value : array();
        $map = is_array($submitted) ? $submitted : array();
        $clean = array();
        foreach (array_keys(self::sections()) as $key) {
            $clean[$key] = !empty($map[$key]) ? 1 : 0;
        }
        return $clean;
    }

    /** @return array<string, bool> */
    public static function visible(): array {
        $stored = get_user_meta(get_current_user_id(), self::OPTION, true);
        $visible = array();
        foreach (array_keys(self::sections()) as $key) {
            $visible[$key] = !is_array($stored) || !array_key_exists($key, $stored) ? true : (bool) $stored[$key];
        }
        return $visible;
    }

    public static function shows(string $section): bool {
        $visible = self::visible();
        return $visible[$section] ?? true;
    }

    private static function isAcademyScreen(string $id): bool {
        return str_contains($id, 'dzn-academy-dashboard') || $id === 'toplevel_page_dzn-platform';
    }
}
