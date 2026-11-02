<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\CommercialPolicyService;
use Delnavazan\Platform\Core\Application\CommercialRule;
use Delnavazan\Platform\Core\Application\DiagnosticsService;

/** General platform identity plus academy scheduling rules that belong outside Finance. */
final class GeneralSettingsController {
    public const MENU_SLUG = 'dzn-general';
    public const CAPABILITY = 'manage_options';
    public const BOOKING_HORIZON_ACTION = 'dzn_general_booking_horizon';

    public static function register(): void {
        add_action('admin_post_' . self::BOOKING_HORIZON_ACTION, array(__CLASS__, 'handleBookingHorizon'));
    }

    public static function handleBookingHorizon(): void {
        if (!current_user_can('dzn_manage_commercial_policies')) wp_die('Forbidden', 403);
        check_admin_referer(self::BOOKING_HORIZON_ACTION);
        $value = sanitize_text_field(wp_unslash((string) ($_POST['booking_horizon_value'] ?? '')));
        $type = sanitize_key(wp_unslash((string) ($_POST['booking_horizon_type'] ?? 'weeks')));
        $result = 'booking_horizon_saved';
        try {
            if ($value === '') throw new \InvalidArgumentException('booking_horizon_required');
            $now = gmdate('Y-m-d H:i:s');
            (new CommercialPolicyService())->set('INTRO_BOOKING_HORIZON', array(
                'policy_value' => $value,
                'value_type' => in_array($type, array('weeks', 'duration'), true) ? $type : 'weeks',
                'evidence_channel' => 'authenticated_platform',
                'evidence_reference' => 'general-settings:booking-horizon:' . $now,
                'evidence_at' => $now,
            ), 'general-settings-booking-horizon-' . $now . '-' . wp_generate_uuid4());
        } catch (\Throwable $error) {
            $result = 'booking_horizon_error';
        }
        wp_safe_redirect(add_query_arg(array('page' => self::MENU_SLUG, 'general_result' => $result), admin_url('admin.php')) . '#booking-horizon');
        exit;
    }

    public static function render(): void {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', 403);
        $status = (new DiagnosticsService())->status();
        $environment = function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'unknown';
        echo '<div class="wrap"><h1>General</h1>';
        echo '<p>Read-only platform identity and environment. Credentials and operational records are managed in their owning screens.</p>';
        echo '<table class="widefat striped"><tbody>';
        foreach (array(
            'Site' => function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '',
            'Home URL' => function_exists('home_url') ? (string) home_url('/') : '',
            'Environment' => $environment,
            'Platform version' => (string) $status['platform_version'],
            'Build' => defined('DZN_PLATFORM_BUILD_ID') ? (string) DZN_PLATFORM_BUILD_ID : '',
            'Schema' => (string) $status['schema_version'],
            'Schema current' => $status['schema_current'] ? 'yes' : 'no',
            'Core tables healthy' => $status['tables_healthy'] ? 'yes' : 'no',
        ) as $label => $value) {
            echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html((string) $value).'</td></tr>';
        }
        echo '</tbody></table>';
        self::renderBookingHorizon();
        self::renderGoldenHourRules();
        self::renderLegalPages();
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-system-health')).'">Open system health</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-integrations')).'">Open integrations</a></p>';
        echo '</div>';
    }

    /** Academy scheduling rule, deliberately outside Finance. */
    private static function renderBookingHorizon(): void {
        $current = array('set' => false, 'value' => null, 'value_type' => 'weeks');
        try {
            if (current_user_can('dzn_view_commercial_authority')) $current = (new CommercialPolicyService())->current('INTRO_BOOKING_HORIZON');
        } catch (\Throwable) {
            $current = array('set' => false, 'value' => null, 'value_type' => 'weeks');
        }
        $result = isset($_GET['general_result']) ? sanitize_key(wp_unslash((string) $_GET['general_result'])) : '';
        echo '<section id="booking-horizon"><h2>Booking horizon</h2>';
        echo '<p>This is an academy scheduling rule, not a Finance setting. It controls how far ahead an introductory booking may be requested. Recording a new version never changes an existing booking.</p>';
        if ($result === 'booking_horizon_saved') echo '<div class="notice notice-success inline"><p>The booking horizon was recorded as a new policy version.</p></div>';
        if ($result === 'booking_horizon_error') echo '<div class="notice notice-error inline"><p>The booking horizon was not saved. Check the value and try again.</p></div>';
        echo '<p><strong>Current:</strong> ' . ($current['set'] ? esc_html((string) $current['value']) . ' ' . esc_html((string) ($current['value_type'] ?? '')) : 'Unset') . '</p>';
        if (current_user_can('dzn_manage_commercial_policies')) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-finance-inline-form">';
            wp_nonce_field(self::BOOKING_HORIZON_ACTION);
            echo '<input type="hidden" name="action" value="' . esc_attr(self::BOOKING_HORIZON_ACTION) . '">';
            echo '<label>Horizon value<input name="booking_horizon_value" inputmode="numeric" required value="' . esc_attr($current['set'] ? (string) $current['value'] : '') . '" placeholder="6"></label>';
            echo '<label>Value type<select name="booking_horizon_type"><option value="weeks"' . selected((string) ($current['value_type'] ?? 'weeks'), 'weeks', false) . '>Weeks</option><option value="duration"' . selected((string) ($current['value_type'] ?? 'weeks'), 'duration', false) . '>Duration seconds</option></select></label>';
            echo '<button class="button button-primary">Record booking horizon</button></form>';
        }
        echo '</section>';
    }

    /** Golden-hour definitions stay finance-owned; Settings is the discoverable configuration entry. */
    private static function renderGoldenHourRules(): void {
        $policyClass = '\\Delnavazan\\Platform\\Core\\Application\\Finance\\GoldenHourPolicyService';
        $ruleClass = '\\Delnavazan\\Platform\\Core\\Application\\Finance\\GoldenHourRule';
        if (!class_exists($policyClass) || !class_exists($ruleClass)) return;
        $policy = array();
        try { $policy = (new $policyClass())->latest($ruleClass::GLOBAL_IRAN_KEY); } catch (\Throwable) { $policy = array(); }
        echo '<h2>Golden-hour definitions and rules</h2>';
        echo '<p>Golden-hour windows and bonus rules are finance-owned configuration. The academy-wide coverage overview is on the Academy Overview; individual coverage is in each Teacher profile. Configuration remains on the canonical Finance settings surface so committed lesson compensation is never rewritten by a presentation change.</p>';
        if ($policy) {
            echo '<table class="widefat striped"><tbody>';
            echo '<tr><th>Policy</th><td>'.esc_html((string) ($policy['label'] ?? 'Iran global golden hours')).'</td></tr>';
            echo '<tr><th>Status</th><td>'.(!empty($policy['enabled'])?'Enabled':'Disabled').'</td></tr>';
            echo '<tr><th>Bonus</th><td>'.esc_html(rtrim(rtrim(number_format(((int)($policy['bonus_basis_points'] ?? 0))/100,2,'.',''),'0'),'.').'%').'</td></tr>';
            echo '<tr><th>Timezone</th><td>'.esc_html((string) ($policy['timezone'] ?? $ruleClass::GLOBAL_IRAN_TIMEZONE)).'</td></tr>';
            echo '<tr><th>Effective from</th><td>'.esc_html((string) ($policy['effective_from'] ?? 'Not recorded')).'</td></tr>';
            echo '</tbody></table>';
        }
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=dzn-finance&tab=settings#golden-hour-pay')).'">Open golden-hour rules</a></p>';
    }

    /** Draft-only policy provisioning. Publication is a separate WordPress action. */
    private static function renderLegalPages(): void {
        $class = '\\Delnavazan\\Platform\\Core\\Application\\LegalPageProvisioner';
        if (!class_exists($class)) return;
        $result = isset($_GET['dzn_legal_result']) ? sanitize_key(wp_unslash((string) $_GET['dzn_legal_result'])) : '';
        echo '<h2>Legal and policy pages</h2>';
        echo '<p>These pages are created or refreshed as <strong>unpublished drafts</strong>. Nothing here publishes a legally consequential document. Confirm the operator identity, consumer terms, recording disclosures and jurisdiction-specific requirements before publishing a page in WordPress.</p>';
        if ($result !== '') {
            echo '<div class="notice notice-info inline"><p>'
                . esc_html(sprintf('Legal draft provisioning completed: %d created, %d updated, %d unchanged.', (int) ($_GET['dzn_legal_created'] ?? 0), (int) ($_GET['dzn_legal_updated'] ?? 0), (int) ($_GET['dzn_legal_skipped'] ?? 0)))
                . '</p></div>';
        }
        echo '<table class="widefat striped"><thead><tr><th>Document</th><th>Canonical slug</th><th>Version</th><th>Page status</th><th></th></tr></thead><tbody>';
        foreach ($class::status() as $row) {
            $status = (string) $row['status'];
            echo '<tr><td><strong>'.esc_html((string) $row['title']).'</strong></td>';
            echo '<td><code>'.esc_html((string) $row['slug']).'</code></td>';
            echo '<td>'.esc_html((string) $row['version']).'</td>';
            echo '<td>'.esc_html($status === 'missing' ? 'Not created' : $status).'</td>';
            echo '<td>';
            if (!empty($row['edit_url'])) {
                echo '<a class="button button-small" href="'.esc_url((string) $row['edit_url']).'">Open draft</a> ';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field(LegalPagesController::ACTION);
        echo '<input type="hidden" name="action" value="'.esc_attr(LegalPagesController::ACTION).'">';
        echo '<p><button class="button button-primary" type="submit">Create / refresh legal drafts</button> <span class="description">Does not publish any page and does not overwrite a published policy.</span></p>';
        echo '</form>';
    }
}
