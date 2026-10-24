<?php
/** Student-owned introductory booking requests. */
$requests = isset( $args['requests'] ) && is_array( $args['requests'] ) ? $args['requests'] : array();
if ( ! $requests ) { return; }
?>
<section class="dzn-portal-section dzn-booking-requests" aria-labelledby="dzn-booking-requests-title">
	<div class="dzn-portal-section__heading">
		<div><p class="dzn-portal-kicker">درخواست‌های جلسهٔ معارفه</p><h2 id="dzn-booking-requests-title">در انتظار هماهنگی</h2></div>
	</div>
	<ul class="dzn-booking-requests__list">
		<?php foreach ( $requests as $request ) : $course = (string) ( $request['course'] ?? '' ); $instrument = (string) ( $request['instrument'] ?? '' ); ?>
		<li class="dzn-booking-requests__item">
			<div><strong><?php echo esc_html( $course !== '' ? $course : $instrument ); ?></strong><span class="dzn-booking-requests__status">در انتظار بررسی</span></div>
			<p>شمارهٔ پیگیری: <bdi dir="ltr"><?php echo esc_html( (string) ( $request['reference'] ?? '' ) ); ?></bdi></p>
			<?php if ( ! empty( $request['requested_times'] ) ) : ?><ol><?php foreach ( (array) $request['requested_times'] as $time ) : ?><li><bdi dir="ltr"><?php echo esc_html( (string) ( $time['local_date'] ?? '' ) . ' ' . (string) ( $time['local_start_time'] ?? '' ) ); ?></bdi> — <?php echo esc_html( (string) ( $time['timezone'] ?? '' ) ); ?></li><?php endforeach; ?></ol><?php endif; ?>
			<p class="dzn-portal-help">این زمان‌ها پیشنهاد شما هستند و تا تأیید مدرس رزرو قطعی محسوب نمی‌شوند.</p>
		</li>
		<?php endforeach; ?>
	</ul>
</section>
