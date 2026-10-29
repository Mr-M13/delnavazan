<?php
/**
 * Teacher support contact on the first portal tab.
 *
 * @package DelnavazanTheme
 */

$message = rawurlencode( 'سلام، از پرتال مدرس دلنوازان پیام می‌دهم و به راهنمایی نیاز دارم.' );
?>
<section class="dzn-tp-section dzn-tp-support" aria-labelledby="dzn-tp-support-title">
	<div class="dzn-tp-heading">
		<div>
			<p class="dzn-tp-kicker"><?php echo esc_html( 'پشتیبانی دلنوازان' ); ?></p>
			<h2 id="dzn-tp-support-title"><?php echo esc_html( 'نیاز به کمک دارید؟' ); ?></h2>
		</div>
	</div>
	<p class="dzn-tp-help"><?php echo esc_html( 'برای هماهنگی زمان کلاس، جلسهٔ جایگزین، حضور هنرجو یا هر پرسش دربارهٔ حساب، از واتس‌اپ با پشتیبانی در تماس باشید.' ); ?></p>
	<p><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( 'https://wa.me/61413413004?text=' . $message ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( 'گفتگو در واتس‌اپ' ); ?></a></p>
</section>
