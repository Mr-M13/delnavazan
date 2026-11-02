<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\AccountRemovalDiscoveryService;

/** Read-only administrator inspection. No deletion route is registered. */
final class AccountRemovalController {
    private const CAPABILITY = 'manage_options';
    private const SLUG = 'dzn-account-removal';
    private const NONCE = 'dzn_account_removal_discovery';

    public static function register(): void {
        add_action('admin_menu', array(__CLASS__, 'menu'));
    }

    public static function menu(): void {
        add_submenu_page(Menu::ROOT_SLUG, 'بررسی حذف دائمی حساب', 'بررسی حذف حساب', self::CAPABILITY, self::SLUG, array(__CLASS__, 'screen'));
    }

    public static function screen(): void {
        if (!current_user_can(self::CAPABILITY)) wp_die('دسترسی مجاز نیست.', 'دلنوازان', array('response' => 403));
        $manifest = null;
        $error = '';
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            check_admin_referer(self::NONCE);
            $kind = sanitize_key((string) wp_unslash($_POST['account_kind'] ?? ''));
            $rawId = (string) wp_unslash($_POST['account_id'] ?? '');
            if (!preg_match('/^[1-9][0-9]*$/D', $rawId)) $error = 'شناسه حساب نامعتبر است.';
            else {
                try { $manifest = (new AccountRemovalDiscoveryService())->discover($kind, (int) $rawId); }
                catch (\Throwable $exception) { $error = 'بررسی وابستگی‌ها انجام نشد. هیچ داده‌ای حذف نشده است.'; }
            }
        }
        ?>
        <div class="wrap dzn-account-removal">
            <h1>بررسی حذف دائمی حساب</h1>
            <p>این صفحه وابستگی‌های هر حساب دانشجو یا مدرس، آزمایشی یا واقعی، را بررسی می‌کند. اجرای حذف غیرفعال است و سوابق مالی، رضایت و منابع بیرونی باید پیش از هر حذف بررسی شوند.</p>
            <?php if ($error !== '') : ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">
                <?php wp_nonce_field(self::NONCE); ?>
                <label for="dzn-removal-kind">نوع حساب</label>
                <select id="dzn-removal-kind" name="account_kind" required>
                    <option value="student">دانشجو</option><option value="teacher">مدرس</option>
                </select>
                <label for="dzn-removal-id">شناسه اصلی حساب</label>
                <input id="dzn-removal-id" name="account_id" type="number" min="1" step="1" required>
                <?php submit_button('نمایش وابستگی‌ها', 'primary', 'submit', false); ?>
            </form>
            <?php if (is_array($manifest)) : ?>
                <h2>فهرست وابستگی‌ها</h2>
                <p>شناسه: <?php echo esc_html((string) $manifest['id']); ?> · نوع: <?php echo $manifest['kind'] === 'student' ? 'دانشجو' : 'مدرس'; ?> · اثر انگشت فهرست: <code><?php echo esc_html($manifest['digest']); ?></code></p>
                <table class="widefat striped"><thead><tr><th>جدول</th><th>رابطه</th><th>تعداد رکورد</th></tr></thead><tbody>
                <?php foreach ($manifest['relations'] as $relation) : ?><tr><td><?php echo esc_html($relation['table']); ?></td><td><?php echo esc_html($relation['column']); ?></td><td><?php echo esc_html((string) $relation['count']); ?></td></tr><?php endforeach; ?>
                </tbody></table>
                <h2>موانع حذف</h2><ul>
                <?php foreach ($manifest['blockers'] as $blocker) : ?><li><code><?php echo esc_html($blocker); ?></code></li><?php endforeach; ?>
                </ul><p>تا زمانی که همه موانع بررسی و برطرف نشده‌اند، حذف ممکن نیست.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}
