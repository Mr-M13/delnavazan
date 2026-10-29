<?php
/**
 * Student support contact on the first portal tab.
 *
 * @package DelnavazanTheme
 */

$message = rawurlencode( 'سلام، از پرتال هنرجو دلنوازان پیام می‌دهم و به راهنمایی نیاز دارم.' );
?>
<section class="dzn-portal-section dzn-support" aria-labelledby="dzn-support-title">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php esc_html_e( 'پشتیبانی دلنوازان', 'delnavazan-theme' ); ?></p>
			<h2 id="dzn-support-title"><?php esc_html_e( 'نیاز به کمک دارید؟', 'delnavazan-theme' ); ?></h2>
		</div>
	</div>
	<p class="dzn-portal-help"><?php esc_html_e( 'برای هماهنگی زمان کلاس، جلسهٔ جایگزین یا هر پرسش دربارهٔ حساب، از واتس‌اپ با پشتیبانی در تماس باشید.', 'delnavazan-theme' ); ?></p>
	<p><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( 'https://wa.me/61413413004?text=' . $message ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'گفتگو در واتس‌اپ', 'delnavazan-theme' ); ?></a></p>
</section>
