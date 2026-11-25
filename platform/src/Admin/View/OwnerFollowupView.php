<?php
namespace Delnavazan\Platform\Admin\View;

use Delnavazan\Platform\Admin\Controller\OwnerFollowupController;
use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;

/** Mobile-first presentation for the single-operator follow-up screen. */
final class OwnerFollowupView {
    /**
     * @param array{intros:array<int,array<string,mixed>>,onboarding:array<int,array<string,mixed>>,payments:array<int,array<string,mixed>>,failed:array<string,bool>} $data
     * @param array{text:string,tone:string}|null $notice
     */
    public static function render(array $data, ?array $notice): void {
        $intros = (array) ($data['intros'] ?? array());
        $onboarding = (array) ($data['onboarding'] ?? array());
        $payments = (array) ($data['payments'] ?? array());
        $failed = (array) ($data['failed'] ?? array());
        $stages = array(
            'new' => array('بدون پیشنهاد مدرس', 'dzn-badge--gold'),
            'identity' => array('نیاز به بررسی هویت', 'dzn-badge--red'),
            'teacher_match' => array('در انتظار پاسخ مدرس', 'dzn-badge--blue'),
            'teacher_agreed' => array('مدرس موافقت کرده', 'dzn-badge--teal'),
        );
        $totals = array();
        foreach ($payments as $row) {
            $currency = (string) ($row['currency'] ?? '');
            $totals[ $currency ] = (int) ($totals[ $currency ] ?? 0) + (int) ($row['amount_minor'] ?? 0);
        }
        $digits = static fn ($value): string => function_exists('dzn_theme_platform_persian_digits')
            ? \dzn_theme_platform_persian_digits((string) $value)
            : (string) $value;
        ?>
        <div class="wrap dzn-settings-page dzn-owner-followups">
            <h1>پیگیری‌های نیما</h1>
            <p class="dzn-settings-lede">سه فهرست کوتاه برای کارهای امروز: کلاس‌های آشنایی که هنوز مدرس ندارند، پرونده‌های ناتمام مدرس‌ها و پرداخت‌های سررسیدشده.</p>
            <?php if (is_array($notice)) : ?>
                <div class="notice notice-<?php echo esc_attr((string) ($notice['tone'] ?? 'info')); ?> is-dismissible"><p><?php echo esc_html((string) ($notice['text'] ?? '')); ?></p></div>
            <?php endif; ?>

            <section class="dzn-settings-card">
                <div class="dzn-owner-followups__head">
                    <h2>کلاس‌های آشنایی بدون مدرس</h2>
                    <span class="dzn-badge dzn-badge--gold"><?php echo esc_html($digits(count($intros))); ?></span>
                </div>
                <p class="dzn-settings-note">درخواست‌هایی که هنوز به مدرسی سپرده نشده‌اند (بدون پاسخ، در انتظار پاسخ یا در هماهنگی).</p>
                <?php if (!empty($failed['intros'])) : ?>
                    <p class="dzn-empty">فهرست کلاس‌های آشنایی در این لحظه در دسترس نیست؛ صفحه را دوباره باز کنید.</p>
                <?php elseif (!$intros) : ?>
                    <p class="dzn-empty">همهٔ درخواست‌های آشنایی مدرس دارند. کاری باقی نمانده است.</p>
                <?php else : ?>
                    <ul class="dzn-list">
                        <?php foreach ($intros as $row) : ?>
                            <?php $stage = $stages[ (string) ($row['stage_key'] ?? '') ] ?? array('در جریان', 'dzn-badge--soft'); ?>
                            <li class="dzn-list__item">
                                <span class="dzn-list__time"><?php echo esc_html((string) ($row['age'] ?? '')); ?></span>
                                <div class="dzn-list__body">
                                    <p class="dzn-list__title"><?php echo esc_html(trim((string) ($row['learner'] ?? '') . ' · ' . (string) ($row['instrument'] ?? ''), ' ·')); ?></p>
                                    <p class="dzn-list__meta"><?php echo esc_html((string) ($row['when'] ?? '')); ?></p>
                                    <p class="dzn-list__meta"><span class="dzn-badge <?php echo esc_attr($stage[1]); ?>"><?php echo esc_html($stage[0]); ?></span> <?php echo esc_html((string) ($row['reference'] ?? '')); ?></p>
                                </div>
                                <div class="dzn-list__action">
                                    <?php if ((int) ($row['request_id'] ?? 0) > 0) : ?>
                                        <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'dzn-booking-requests', 'request_id' => (int) $row['request_id']), admin_url('admin.php'))); ?>">بررسی</a>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="dzn-settings-card">
                <div class="dzn-owner-followups__head">
                    <h2>مدرس‌هایی که پرونده‌شان کامل نشده</h2>
                    <span class="dzn-badge dzn-badge--blue"><?php echo esc_html($digits(count($onboarding))); ?></span>
                </div>
                <p class="dzn-settings-note">تا وقتی این موارد کامل نشوند، مدرس نمی‌تواند کلاس بگیرد.</p>
                <?php if (!empty($failed['onboarding'])) : ?>
                    <p class="dzn-empty">وضعیت پروندهٔ مدرس‌ها در این لحظه در دسترس نیست؛ صفحه را دوباره باز کنید.</p>
                <?php elseif (!$onboarding) : ?>
                    <p class="dzn-empty">همهٔ مدرس‌ها پروندهٔ کامل دارند.</p>
                <?php else : ?>
                    <ul class="dzn-list">
                        <?php foreach ($onboarding as $row) : ?>
                            <li class="dzn-list__item">
                                <span class="dzn-badge dzn-badge--soft"><?php echo esc_html((string) ($row['state'] ?? '')); ?></span>
                                <div class="dzn-list__body">
                                    <p class="dzn-list__title"><?php echo esc_html((string) ($row['name'] ?? '')); ?></p>
                                    <ul class="dzn-owner-followups__outstanding">
                                        <?php foreach ((array) ($row['outstanding'] ?? array()) as $item) : ?>
                                            <li><?php echo esc_html((string) $item); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <div class="dzn-list__action">
                                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=dzn-teachers-menu')); ?>">پروندهٔ مدرس</a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="dzn-settings-card">
                <div class="dzn-owner-followups__head">
                    <h2>پرداخت به مدرس‌ها</h2>
                    <span class="dzn-badge dzn-badge--teal"><?php echo esc_html($digits(count($payments))); ?></span>
                </div>
                <p class="dzn-settings-note">صورت‌حساب‌های صادرشده‌ای که هنوز پرداخت نشده‌اند. ثبت پرداخت در پرتال مالی مدرس و در بخش مالی نمایش داده می‌شود.</p>
                <?php if (!empty($failed['payments'])) : ?>
                    <p class="dzn-empty">فهرست پرداخت‌ها در این لحظه در دسترس نیست؛ صفحه را دوباره باز کنید.</p>
                <?php elseif (!$payments) : ?>
                    <p class="dzn-empty">پرداخت سررسیدشده‌ای باقی نمانده است.</p>
                <?php else : ?>
                    <ul class="dzn-list">
                        <?php foreach ($payments as $row) : ?>
                            <?php $amount = PricingMoney::format((int) ($row['amount_minor'] ?? 0), (string) ($row['currency'] ?? ''), true); ?>
                            <li class="dzn-list__item">
                                <span class="dzn-list__time"><?php echo esc_html($amount); ?></span>
                                <div class="dzn-list__body">
                                    <p class="dzn-list__title"><?php echo esc_html((string) ($row['teacher'] ?? '')); ?></p>
                                    <p class="dzn-list__meta"><?php echo esc_html((string) ($row['period'] ?? '')); ?><?php echo '' !== (string) ($row['teacher_reference'] ?? '') ? ' · ' . esc_html((string) $row['teacher_reference']) : ''; ?></p>
                                </div>
                                <div class="dzn-list__action">
                                    <details class="dzn-owner-pay">
                                        <summary class="button button-primary">پرداخت شد</summary>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dzn-owner-pay__form">
                                            <input type="hidden" name="action" value="<?php echo esc_attr(OwnerFollowupController::ACTION); ?>">
                                            <input type="hidden" name="statement_id" value="<?php echo esc_attr((string) (int) ($row['statement_id'] ?? 0)); ?>">
                                            <input type="hidden" name="command_key" value="<?php echo esc_attr('owner-followup-' . (int) ($row['statement_id'] ?? 0) . '-' . wp_generate_uuid4()); ?>">
                                            <?php wp_nonce_field(OwnerFollowupController::ACTION); ?>
                                            <label>کد پیگیری یا یادداشت (اختیاری)
                                                <input type="text" name="payment_reference" maxlength="120" autocomplete="off" placeholder="مثلاً حوالهٔ بانکی">
                                            </label>
                                            <button type="submit" class="button button-primary">ثبت پرداخت</button>
                                        </form>
                                    </details>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($totals) : ?>
                        <p class="dzn-settings-meta">جمع باقی‌مانده:
                            <?php $parts = array(); foreach ($totals as $currency => $minor) { $parts[] = PricingMoney::format((int) $minor, (string) $currency, true); } ?>
                            <?php echo esc_html(implode(' + ', $parts)); ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }
}
