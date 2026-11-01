<?php
/** Student-owned introductory booking requests. */
$requests = isset( $args['requests'] ) && is_array( $args['requests'] ) ? $args['requests'] : array();
if ( ! $requests ) { return; }
?>
<section class="dzn-portal-section dzn-booking-requests" aria-labelledby="dzn-booking-requests-title">
	<div class="dzn-portal-section__heading">
		<div><p class="dzn-portal-kicker">درخواست‌های کلاس آشنایی</p><h2 id="dzn-booking-requests-title">در انتظار هماهنگی</h2></div>
	</div>
	<ul class="dzn-booking-requests__list">
		<?php
		foreach ( $requests as $request ) :
			$course = (string) ( $request['course'] ?? '' );
			$instrument = (string) ( $request['instrument'] ?? '' );
			$intro_payment = isset( $request['intro_payment'] ) && is_array( $request['intro_payment'] ) ? $request['intro_payment'] : null;
			$confirmation_state = (string) ( $request['confirmation_state'] ?? '' );
			$status_label = $confirmation_state === 'awaiting_payment' ? 'در انتظار پرداخت' : ( $confirmation_state === 'confirmed' ? 'تأیید شده' : ( in_array( $confirmation_state, array( 'pending_projection', 'awaiting_conference', 'retryable' ), true ) ? 'در حال ساخت تقویم' : 'در انتظار بررسی' ) );
			?>
		<li class="dzn-booking-requests__item">
			<div><strong><?php echo esc_html( $course !== '' ? $course : $instrument ); ?></strong><span class="dzn-booking-requests__status"><?php echo esc_html( $status_label ); ?></span></div>
			<p>شمارهٔ پیگیری: <bdi dir="ltr"><?php echo esc_html( (string) ( $request['reference'] ?? '' ) ); ?></bdi></p>
			<?php if ( ! empty( $request['requested_times'] ) ) : ?><ol><?php foreach ( (array) $request['requested_times'] as $time ) : ?><li><bdi dir="ltr"><?php echo esc_html( (string) ( $time['local_date'] ?? '' ) . ' ' . (string) ( $time['local_start_time'] ?? '' ) ); ?></bdi> — <?php echo esc_html( (string) ( $time['timezone'] ?? '' ) ); ?></li><?php endforeach; ?></ol><?php endif; ?>
			<?php if ( $intro_payment !== null ) : ?>
				<?php
				$amount = (int) ( $intro_payment['amount_minor'] ?? 0 );
				$currency = (string) ( $intro_payment['currency'] ?? '' );
				$formatted = class_exists( '\Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney' )
					? \Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney::format( $amount, $currency, true )
					: number_format( $amount ) . ' ' . $currency;
				$payment_state = (string) ( $intro_payment['state'] ?? 'pending' );
				$failure = (string) ( $intro_payment['failure_reason_code'] ?? '' );
				$payment_label = $payment_state === 'paid' ? 'پرداخت شده' : ( $payment_state === 'refunded' ? 'بازپرداخت شده' : ( $failure === 'payment_failed' ? 'پرداخت ناموفق — امکان تلاش دوباره' : 'در انتظار پرداخت' ) );
				?>
				<div class="dzn-booking-requests__payment" data-dzn-intro-payment>
					<p><strong>هزینهٔ کلاس آشنایی:</strong> <?php echo esc_html( $formatted ); ?> — <span><?php echo esc_html( $payment_label ); ?></span></p>
					<?php if ( ! empty( $intro_payment['payment_required'] ) && in_array( $payment_state, array( 'pending', 'failed' ), true ) ) : ?>
						<button type="button" class="dzn-button" data-dzn-intro-checkout data-obligation="<?php echo esc_attr( (string) $intro_payment['obligation_uid'] ); ?>">پرداخت کلاس آشنایی</button>
					<?php endif; ?>
					<p class="dzn-portal-help" data-dzn-intro-status aria-live="polite"></p>
				</div>
			<?php endif; ?>
			<p class="dzn-portal-help">این زمان‌ها پیشنهاد شما هستند و تا تأیید مدرس رزرو قطعی محسوب نمی‌شوند.</p>
		</li>
		<?php endforeach; ?>
	</ul>
</section>
