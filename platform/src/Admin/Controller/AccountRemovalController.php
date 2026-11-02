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
            $reference = (string) wp_unslash($_POST['account_reference'] ?? '');
            try { $manifest = (new AccountRemovalDiscoveryService())->discoverByReference($reference); }
            catch (\Throwable $exception) { $error = 'کد حساب پیدا نشد یا بررسی وابستگی‌ها انجام نشد. هیچ داده‌ای حذف نشده است.'; }
        }
        ?>
        <div class="wrap dzn-account-removal">
            <h1>بررسی حذف دائمی حساب</h1>
            <p>این صفحه فقط گزارش اولیهٔ وابستگی‌های حساب را نشان می‌دهد. لازم نیست ردیف‌های این فهرست را دستی حذف کنید. ابزار حذف دائمی هنوز ساخته نشده است.</p>
            <?php if ($error !== '') : ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">
                <?php wp_nonce_field(self::NONCE); ?>
                <label for="dzn-removal-reference">کد دانشجو یا مدرس</label>
                <input id="dzn-removal-reference" name="account_reference" type="text" maxlength="32" placeholder="DZN-STU-000003" pattern="DZN-(STU|TCH)-[0-9]{6,}" dir="ltr" required>
                <?php submit_button('نمایش وابستگی‌ها', 'primary', 'submit', false); ?>
            </form>
            <?php if (is_array($manifest)) : ?>
                <h2>رکوردهای مرتبطِ شناسایی‌شده</h2>
                <p>این ردیف‌ها کارِ بررسی و پاک‌سازی خودکارِ ابزار آینده هستند؛ وجود یک ردیف به‌تنهایی به معنای ممنوع بودن حذف نیست.</p>
                <p>کد حساب: <code><?php echo esc_html($manifest['reference_code']); ?></code> · نوع: <?php echo $manifest['kind'] === 'student' ? 'دانشجو' : 'مدرس'; ?> · اثر انگشت فهرست: <code><?php echo esc_html($manifest['digest']); ?></code></p>
                <table class="widefat striped"><thead><tr><th>جدول</th><th>رابطه</th><th>تعداد رکورد</th></tr></thead><tbody>
                <?php foreach ($manifest['relations'] as $relation) : ?><tr><td><?php echo esc_html($relation['table']); ?></td><td><?php echo esc_html($relation['column']); ?></td><td><?php echo esc_html((string) $relation['count']); ?></td></tr><?php endforeach; ?>
                </tbody></table>
                <h2>چرا حذف اکنون در دسترس نیست؟</h2><ul>
                <?php foreach ($manifest['blockers'] as $blocker) : ?><li><?php echo esc_html(self::blockerLabel($blocker)); ?></li><?php endforeach; ?>
                </ul><p>برای باز کردن امکان حذف، شما نباید جدول‌های پایگاه‌داده یا حساب وردپرس را دستی پاک کنید. ابزار آینده باید وابستگی‌های قابل‌حذف را خودش رسیدگی کند و در صورت وجود سوابق مالی یا رضایتِ محفوظ، حساب مشترک یا منابع بیرونیِ حل‌نشده، حذف کامل را رد کند.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function blockerLabel(string $blocker): string {
        return array(
            'indirect_and_external_dependencies_unreviewed' => 'بررسی کامل وابستگی‌های غیرمستقیم و سرویس‌های بیرونی هنوز در ابزار پیاده‌سازی نشده است؛ این مورد را نمی‌توان از داشبورد برطرف کرد.',
            'shared_wordpress_identity' => 'این حساب وردپرس با شخص یا نقش دیگری مشترک است و نباید حذف شود.',
            'privileged_wordpress_identity' => 'حساب وردپرس دارای دسترسی مدیریتی یا ویرایشی است.',
            'wordpress_content_ownership' => 'حساب وردپرس مالک نوشته یا محتوای سایت است و مالکیت آن باید بررسی شود.',
            'wordpress_comment_ownership' => 'حساب وردپرس نظر ثبت‌شده دارد و مالکیت آن باید بررسی شود.',
            'wordpress_user_missing' => 'پیوند حساب وردپرس ثبت شده، اما خود حساب پیدا نشد.',
            'wordpress_identity_unresolved' => 'پیوند حساب وردپرس مشخص نیست و باید بررسی شود.',
            'multisite_ownership_unreviewed' => 'مالکیت حساب در شبکهٔ چندسایتی وردپرس هنوز بررسی نشده است.',
        )[$blocker] ?? 'وابستگی نیازمند بررسی: ' . $blocker;
    }
}
