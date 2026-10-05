<?php
/** Requests submitted by this signed-in account. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$items = is_array( $args['items'] ?? null ) ? $args['items'] : array();
if ( ! $items ) { return; }
$states = array(
	'submitted' => 'ثبت شده',
	'accepted' => 'پذیرفته شده',
	'declined' => 'پذیرفته نشده',
	'cancelled' => 'لغو شده',
);
?>
<section class="dzn-portal__booking-requests" aria-labelledby="dzn-booking-requests-title">
	<h2 id="dzn-booking-requests-title">درخواست‌های کلاس من</h2>
	<p>درخواست‌های این حساب و سابقهٔ ارسال پیام‌هایی که سامانه ثبت کرده است را اینجا ببینید. متن پیام و پیام‌های دستی کارکنان در این بخش موجود نیست.</p>
	<?php foreach ( $items as $item ) : ?>
		<?php if ( ! is_array( $item ) ) { continue; } ?>
		<article class="dzn-portal__booking-request">
			<h3><?php echo esc_html( (string) ( $item['reference'] ?? '' ) ); ?></h3>
			<p>وضعیت: <?php echo esc_html( $states[ $item['request_state'] ?? '' ] ?? 'در حال بررسی' ); ?></p>
			<?php $messages = is_array( $item['messages'] ?? null ) ? $item['messages'] : array(); ?>
			<?php if ( $messages ) : ?>
				<h4>سابقهٔ ارسال پیام سامانه</h4>
				<ul>
					<?php foreach ( $messages as $message ) : ?>
						<li>پیام خودکار دلنوازان — <?php echo esc_html( dzn_theme_platform_local_time( $message['delivered_at'] ?? '', 'j F Y، H:i' ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p>هنوز سابقهٔ ارسال پیام سامانه برای این درخواست ثبت نشده است.</p>
			<?php endif; ?>
		</article>
	<?php endforeach; ?>
</section>
