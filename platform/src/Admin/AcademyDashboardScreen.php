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

    /** @return array<string, string> */
    public static function sections(): array {
        return array(
            'quick' => 'Quick actions',
            'kpis' => 'Key numbers',
            'actions' => 'Pending work',
            'schedule' => 'Today’s classes',
            'pipeline' => 'Learner pipeline',
            'activity' => '30-day activity',
            'finance' => 'Finance snapshot',
            'coverage' => 'Golden-hour coverage',
            'queue' => 'Learner journeys',
        );
    }

    public static function register(): void {
        add_action('current_screen', array(__CLASS__, 'configure'));
        add_filter('screen_settings', array(__CLASS__, 'render'), 10, 2);
        add_filter('set_screen_option_' . self::OPTION, array(__CLASS__, 'save'), 10, 3);
        add_filter('screen_options_show_submit', static function ($show, $screen) {
            return $screen && self::isAcademyScreen((string) $screen->id) ? true : $show;
        }, 10, 2);
    }

    public static function configure(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !self::isAcademyScreen((string) $screen->id)) return;
        add_screen_option(self::OPTION, array(
            'label' => 'Overview sections',
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
        $html = '<fieldset class="dzn-screen-options"><legend>Delnavazan Overview sections</legend>';
        $html .= '<p class="description">Choose the sections shown on the Overview dashboard. Hidden sections remain available from the navigation.</p>';
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
