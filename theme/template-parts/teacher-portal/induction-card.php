<?php
/**
 * Compact quick-start entry card for the Teacher dashboard.
 * The full one-page guide is never rendered here.
 *
 * @package DelnavazanTheme
 */
$model = is_array( $args['model'] ?? null ) ? $args['model'] : array();
$hub = is_array( $model['induction'] ?? null ) ? $model['induction'] : array();
if ( ! $hub || empty( $hub['modules'] ) ) { return; }
$completed = (int) ( $hub['completed_count'] ?? 0 );
$total = (int) ( $hub['total_count'] ?? 0 );
$percent = (int) ( $hub['percent'] ?? 0 );
$all_done = $total > 0 && $completed >= $total;
$continue_url = (string) ( $model['induction_continue_url'] ?? $model['induction_hub_url'] ?? '' );
?>
<section class="dzn-tp-section dzn-tp-induction-entry" aria-labelledby="tp-induction-card-title">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">راهنمای سریع</p><h2 id="tp-induction-card-title">شروع تدریس در دلنوازان</h2></div>
		<span class="dzn-tp-induction__count"><?php echo esc_html( sprintf( '%s از %s دیده شده', number_format_i18n( $completed ), number_format_i18n( $total ) ) ); ?></span>
	</div>
	<div class="dzn-tp-induction__progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) $total ); ?>" aria-valuenow="<?php echo esc_attr( (string) $completed ); ?>" aria-label="پیشرفت راهنمای سریع"><span style="inline-size:<?php echo esc_attr( (string) $percent ); ?>%"></span></div>
	<p class="dzn-tp-help"><?php echo esc_html( $all_done ? 'همهٔ نکته‌ها را دیده‌اید. راهنما برای مرور همیشه در دسترس است.' : 'کلاس امروز، ورود، حضور، غیبت، تغییر زمان، تنظیمات، مالی، ساعت طلایی و درخواست جدید. کوتاه و مستقیم.' ); ?></p>
	<div class="dzn-tp-actions">
		<?php if ( '' !== $continue_url ) : ?><a class="dzn-button" href="<?php echo esc_url( $continue_url ); ?>"><?php echo esc_html( $all_done ? 'مرور راهنما' : 'باز کردن راهنمای سریع' ); ?></a><?php endif; ?>
		<button class="dzn-button dzn-button--secondary" type="button" data-dzn-tp-induction-skip>فعلاً رد کن</button>
	</div>
</section>
