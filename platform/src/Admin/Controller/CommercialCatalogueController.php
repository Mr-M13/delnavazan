<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialAuthorityRepository,CourseRepository};

/**
 * Compatibility read-only view for the former commercial catalogue route.
 *
 * Standard student prices are now configured once per region in Finance → Settings. This
 * route remains protected so older bookmarks resolve, but it exposes no technical evidence
 * fields, idempotency keys, minor units or staff-record selectors to ordinary administrators.
 */
final class CommercialCatalogueController {
    public const CAP = 'dzn_manage_commercial_catalogue';

    public static function register(): void { add_action('admin_menu', array(__CLASS__, 'menu')); }
    public static function menu(): void { add_submenu_page(Menu::ROOT_SLUG, 'Student pricing', 'Student pricing', self::CAP, 'dzn-commercial-catalogue', array(__CLASS__, 'screen')); }

    public static function post(): void {
        if (!current_user_can(self::CAP)) wp_die('Forbidden', 403);
        wp_safe_redirect(add_query_arg(array('page' => FinanceAdminController::MENU_SLUG, 'tab' => FinanceAdminController::TAB_SETTINGS, 'finance_result' => 'use_settings'), admin_url('admin.php')));
        exit;
    }

    public static function screen(): void {
        if (!current_user_can(self::CAP)) wp_die('Forbidden', 403);
        $repo = new CommercialAuthorityRepository();
        $products = $repo->products();
        $courses = new CourseRepository();
        echo '<div class="wrap"><h1>Student pricing</h1>';
        echo '<p><strong>One standard lesson price applies to every standard Course in each currency region.</strong> Prices are configured in Finance → Settings. Technical fields such as staff records, evidence references, UTC entry, idempotency keys and minor units are no longer entered by administrators; the system derives them from the authenticated session and the entered human amount.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(FinanceAdminController::tabUrl(FinanceAdminController::TAB_SETTINGS)) . '#regional-pricing">Configure regional prices</a></p>';
        echo '<h2>Current sellable products</h2><table class="widefat striped"><thead><tr><th>Product</th><th>Course</th><th>Regional price</th></tr></thead><tbody>';
        foreach ($products as $product) {
            $course = $courses->find((int) $product->course_id);
            $courseName = $course ? (string) (($course->name_en ?? '') !== '' ? $course->name_en : ($course->name_fa ?? '')) : '';
            $prices = $repo->pricesForProduct((int) $product->id);
            echo '<tr><td>#' . (int) $product->id . ' ' . esc_html((string) ($product->name_en ?: $product->name_fa)) . '</td><td>#' . (int) $product->course_id . ' ' . esc_html($courseName) . '</td><td>';
            $shown = false;
            foreach ($prices as $price) {
                if ((int) ($price->active_slot ?? 0) !== 1) continue;
                $shown = true;
                echo esc_html((string) $price->region_code . ' · ' . PricingMoney::format((int) $price->amount_minor, (string) $price->currency)) . '<br>';
            }
            if (!$shown) echo '<em>No active price; configure the region in Finance → Settings.</em>';
            echo '</td></tr>';
        }
        if (!$products) echo '<tr><td colspan="3">No sellable product exists yet. Create instruments and Courses in Courses &amp; Instruments.</td></tr>';
        echo '</tbody></table>';
        echo '<p class="description">Existing offers, payments and statements keep the exact price row they recorded. A later regional price change never rewrites them.</p></div>';
    }
}
