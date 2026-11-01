<?php
/**
 * Compact induction entry card for the Teacher dashboard.
 *
 * The full curriculum never renders here. This card only shows progress and a resumable entry
 * point; it is shown even after completion so guidance stays accessible.
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
$hub_url = (string) ( $model['induction_hub_url'] ?? '' );
?>
<section class="dzn-tp-section dzn-tp-induction-entry" aria-labelledby="tp-induction-card-title">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">راهنمای مدرس</p><h2 id="tp-induction-card-title">راهنمای شروع تدریس در دلنوازان</h2></div>
		<span class="dzn-tp-induction__count"><?php echo esc_html( sprintf( '%s از %s بخش تکمیل شده', number_format_i18n( $completed ), number_format_i18n( $total ) ) ); ?></span>
	</div>
	<div class="dzn-tp-induction__progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) $total ); ?>" aria-valuenow="<?php echo esc_attr( (string) $completed ); ?>" aria-label="پیشرفت راهنمای تدریس"><span style="inline-size:<?php echo esc_attr( (string) $percent ); ?>%"></span></div>
	<p class="dzn-tp-help"><?php echo esc_html( $all_done ? 'همهٔ بخش‌ها را تکمیل کرده‌اید. راهنما برای مرور همیشه در دسترس است.' : 'هفت بخش کوتاه دربارهٔ پرتال، زمان‌های تدریس، مسئولیت‌های روزانه، تغییر زمان، غیبت و جبران، مالی و حل مشکلات. می‌توانید فعلاً رد کنید و بعداً ادامه دهید؛ تکمیل این راهنما برای دسترسی لازم نیست.' ); ?></p>
	<div class="dzn-tp-actions">
		<?php if ( '' !== $continue_url ) : ?><a class="dzn-button" href="<?php echo esc_url( $continue_url ); ?>"><?php echo esc_html( $all_done ? 'مرور راهنما' : ( $completed > 0 ? 'ادامهٔ یادگیری' : 'شروع یادگیری' ) ); ?></a><?php endif; ?>
		<?php if ( '' !== $hub_url && $hub_url !== $continue_url ) : ?><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $hub_url ); ?>">همهٔ بخش‌ها</a><?php endif; ?>
		<button class="dzn-button dzn-button--secondary" type="button" data-dzn-tp-induction-skip>فعلاً رد کن</button>
	</div>
</section>
