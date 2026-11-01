<?php
/**
 * Teacher support contact on the first portal tab.
 *
 * @package DelnavazanTheme
 */

$brand   = dzn_theme_brand_identity();
$message = 'درود، از پرتال مدرس دلنوازان پیام می‌دهم و به راهنمایی نیاز دارم.';
?>
<section class="dzn-tp-section dzn-tp-support" aria-labelledby="dzn-tp-support-title">
	<div class="dzn-tp-heading">
		<div>
			<p class="dzn-tp-kicker"><?php echo esc_html( 'پشتیبانی دلنوازان' ); ?></p>
			<h2 id="dzn-tp-support-title"><?php echo esc_html( 'نیاز به کمک دارید؟' ); ?></h2>
		</div>
	</div>
	<p class="dzn-tp-help"><?php echo esc_html( 'برای هماهنگی زمان کلاس، جلسهٔ جبرانی، حضور هنرجو یا هر پرسش دربارهٔ حساب، از واتس‌اپ با پشتیبانی در تماس باشید.' ); ?></p>
	<p><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( dzn_theme_whatsapp_link( $message ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( 'گفتگو در واتس‌اپ' ); ?></a></p>
	<p class="dzn-tp-help" dir="ltr"><a href="<?php echo esc_url( $brand['phone_href'] ); ?>"><?php echo esc_html( $brand['phone_display'] ); ?></a> · <a href="<?php echo esc_url( $brand['support_mailto'] ); ?>"><?php echo esc_html( $brand['support_email'] ); ?></a></p>
</section>
