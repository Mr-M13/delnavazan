<?php
/**
 * Post-introductory continuation card.
 *
 * @package DelnavazanTheme
 */

$items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$recording = isset( $args['recording'] ) && is_array( $args['recording'] ) ? $args['recording'] : array();
$status = sanitize_key( (string) ( $_GET['post_intro_status'] ?? '' ) );
$messages = array(
	'adult_attestation_required' => 'برای ادامهٔ ثبت‌نام، تأیید سن هنرجو لازم است.',
	'guardian_required' => 'برای هنرجوی زیر ۱۸ سال، تأیید ولی یا سرپرست لازم است. لطفاً با پشتیبانی تماس بگیرید.',
	'post_intro_invitation_expired' => 'مهلت نگه‌داشتن زمان کلاس گذشته است. لطفاً با پشتیبانی تماس بگیرید.',
	'post_intro_invitation_not_found' => 'این دعوت ثبت‌نام در دسترس نیست.',
	'post_intro_invitation_not_ready' => 'این دعوت ثبت‌نام هنوز آمادهٔ پرداخت نیست.',
	'checkout_temporarily_unavailable' => 'پرداخت موقتاً در دسترس نیست. لطفاً چند دقیقهٔ دیگر دوباره تلاش کنید.',
	'checkout_pending' => 'پرداخت شما در انتظار تکمیل است. برای ادامه دوباره تلاش کنید.',
	'plan_choice_conflict' => 'طرح پرداخت این ثبت‌نام قبلاً نهایی شده است و قابل تغییر نیست.',
	'plan_quote_stale' => 'مبلغ طرح پرداخت تغییر کرده است. لطفاً صفحه را تازه‌سازی کنید و دوباره انتخاب کنید.',
	'plan_quote_required' => 'برای ادامه، لطفاً یکی از طرح‌های پرداخت را انتخاب کنید.',
	'plan_unavailable' => 'این طرح پرداخت اکنون برای شما در دسترس نیست. لطفاً طرح دیگر را انتخاب کنید یا با پشتیبانی تماس بگیرید.',
	'plan_selection_in_progress' => 'انتخاب طرح پرداخت شما در حال ثبت است. لطفاً چند لحظهٔ دیگر دوباره تلاش کنید.',
	'instalment_due_date_policy_invalid' => 'تنظیم سررسید قسط دوم در حال حاضر معتبر نیست. لطفاً با پشتیبانی تماس بگیرید.',
	'instalment_due_date_schedule_unavailable' => 'محاسبهٔ سررسید قسط دوم برای برنامهٔ زمانی شما ممکن نشد. لطفاً با پشتیبانی تماس بگیرید.',
	'payment_received' => 'پرداخت شما تأیید شد و ثبت‌نام در حال فعال‌سازی است.',
	'activated' => 'ثبت‌نام شما فعال شد.',
	'post_intro_unavailable' => 'انجام این اقدام در حال حاضر ممکن نیست. لطفاً با پشتیبانی تماس بگیرید.',
);
$items = array_values( array_filter( $items, static function ( $item ): bool {
	return is_array( $item ) && ! empty( $item['actionable'] );
} ) );
if ( ! $items && ! isset( $messages[ $status ] ) ) {
	return;
}
?>
<section class="dzn-portal-section dzn-post-intro" aria-labelledby="dzn-post-intro-title">
	<div class="dzn-upcoming__heading">
		<div>
			<p class="dzn-portal-kicker">پس از کلاس آشنایی</p>
			<h2 id="dzn-post-intro-title">ادامهٔ کلاس با همین مدرس</h2>
		</div>
	</div>
	<?php if ( isset( $messages[ $status ] ) ) : ?>
		<p class="dzn-portal-action-status" aria-live="polite"><?php echo esc_html( $messages[ $status ] ); ?></p>
	<?php endif; ?>
	<?php if ( $recording ) : ?>
		<?php dzn_theme_portal_component( 'recording-choices', array( 'recording' => $recording ) ); ?>
	<?php endif; ?>
	<?php foreach ( $items as $item ) : ?>
		<article class="dzn-post-intro__card">
			<h3><?php echo esc_html( (string) ( $item['title'] ?? '' ) ); ?></h3>
			<p class="dzn-portal-help">
				<?php echo esc_html( trim( (string) ( $item['teacher_name'] ?? '' ) . ' · ' . (string) ( $item['course_name'] ?? '' ) ) ); ?>
				<?php if ( ! empty( $item['intro_date'] ) ) : ?>
					· <?php echo esc_html( (string) $item['intro_date'] . ' ' . (string) $item['intro_time'] . ' ' . (string) ( $item['timezone'] ?? '' ) ); ?>
				<?php endif; ?>
			</p>
			<p><?php echo esc_html( (string) ( $item['message'] ?? '' ) ); ?></p>
			<?php if ( ! empty( $item['actionable'] ) && ! empty( $item['action_url'] ) ) : ?>
				<?php if ( ! empty( $item['guardian_required'] ) ) : ?>
					<p class="dzn-portal-help">برای ادامه، تأیید ولی یا سرپرست لازم است.</p>
				<?php else : ?>
					<?php
					$format_money = static function ( $minor, $currency ) {
						$class = '\\Delnavazan\\Platform\\Core\\Application\\Finance\\Pricing\\PricingMoney';
						return class_exists( $class ) ? $class::format( (int) $minor, (string) $currency, true ) : number_format( (int) $minor ) . ' ' . (string) $currency;
					};
					$options = array();
					foreach ( (array) ( $item['plan_options'] ?? array() ) as $option ) {
						if ( is_array( $option ) && ! empty( $option['plan_kind'] ) ) { $options[ (string) $option['plan_kind'] ] = $option; }
					}
					$full = $options['full'] ?? null;
					$two = $options['two_instalments'] ?? null;
					$locked = ! empty( $item['plan_locked'] );
					$selected = (string) ( $item['selected_plan_kind'] ?? '' );
					$currency = (string) ( $item['currency'] ?? '' );
					$attestation = ! empty( $item['adult_attestation_required'] );
					?>
					<?php if ( $locked ) : ?>
						<p class="dzn-post-intro__selected"><strong>طرح انتخابی:</strong> <?php echo esc_html( 'two_instalments' === $selected ? 'پرداخت در دو قسط' : 'پرداخت کامل ترم' ); ?></p>
						<form method="post" action="<?php echo esc_url( (string) $item['action_url'] ); ?>">
							<input type="hidden" name="action" value="dzn_post_intro_continue">
							<input type="hidden" name="preparation_uid" value="<?php echo esc_attr( (string) ( $item['reference'] ?? '' ) ); ?>">
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['nonce'] ?? '' ) ); ?>">
							<?php if ( $attestation ) : ?>
								<label class="dzn-booking__privacy"><input type="checkbox" name="adult_attested" value="1" required> تأیید می‌کنم هنرجو ۱۸ سال یا بیشتر دارد و می‌تواند این ثبت‌نام را بپذیرد.</label>
							<?php endif; ?>
							<button class="dzn-button" type="submit"><?php echo esc_html( (string) ( $item['action_label'] ?? 'ادامهٔ پرداخت' ) ); ?></button>
						</form>
					<?php elseif ( $full && $two && ! empty( $item['quote_token'] ) ) : ?>
						<div class="dzn-post-intro__plans">
							<div class="dzn-post-intro__plan dzn-post-intro__plan--full">
								<h4>پرداخت کامل ترم</h4>
								<p class="dzn-post-intro__amount"><?php echo esc_html( $format_money( (int) $full['amount_minor'], $currency ) ); ?></p>
								<p class="dzn-portal-help">۱۲ جلسه · کل ترم</p>
								<form method="post" action="<?php echo esc_url( (string) $item['action_url'] ); ?>">
									<input type="hidden" name="action" value="dzn_post_intro_continue">
									<input type="hidden" name="preparation_uid" value="<?php echo esc_attr( (string) ( $item['reference'] ?? '' ) ); ?>">
									<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['nonce'] ?? '' ) ); ?>">
									<input type="hidden" name="plan_kind" value="full">
									<input type="hidden" name="quote_token" value="<?php echo esc_attr( (string) $item['quote_token'] ); ?>">
									<?php if ( $attestation ) : ?>
										<label class="dzn-booking__privacy"><input type="checkbox" name="adult_attested" value="1" required> تأیید می‌کنم هنرجو ۱۸ سال یا بیشتر دارد و می‌تواند این ثبت‌نام را بپذیرد.</label>
									<?php endif; ?>
									<button class="dzn-button" type="submit">پرداخت کامل ترم و ادامهٔ ثبت‌نام</button>
								</form>
							</div>
							<details class="dzn-post-intro__plan dzn-post-intro__plan--instalments">
								<summary>پرداخت در دو قسط (اختیاری)</summary>
								<p class="dzn-portal-help">مبلغ کل: <?php echo esc_html( $format_money( (int) $two['amount_minor'], $currency ) ); ?></p>
								<ul class="dzn-post-intro__instalment-list">
									<?php foreach ( (array) $two['instalments'] as $instalment ) : ?>
										<li>
											<strong>قسط <?php echo esc_html( (string) ( $instalment['sequence'] ?? '' ) ); ?> از ۲</strong>
											<span><?php echo esc_html( $format_money( (int) ( $instalment['amount_minor'] ?? 0 ), $currency ) ); ?></span>
											<small>جلسات <?php echo esc_html( (string) ( $instalment['sessions_from'] ?? '' ) . ' تا ' . (string) ( $instalment['sessions_to'] ?? '' ) ); ?> · سررسید: پیش از شروع جلسهٔ ۷</small>
										</li>
									<?php endforeach; ?>
								</ul>
								<form method="post" action="<?php echo esc_url( (string) $item['action_url'] ); ?>">
									<input type="hidden" name="action" value="dzn_post_intro_continue">
									<input type="hidden" name="preparation_uid" value="<?php echo esc_attr( (string) ( $item['reference'] ?? '' ) ); ?>">
									<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['nonce'] ?? '' ) ); ?>">
									<input type="hidden" name="plan_kind" value="two_instalments">
									<input type="hidden" name="quote_token" value="<?php echo esc_attr( (string) $item['quote_token'] ); ?>">
									<label class="dzn-booking__privacy"><input type="checkbox" name="instalments_opt_in" value="1" required> پرداخت در دو قسط را انتخاب می‌کنم و مبلغ و زمان‌بندی آن را پذیرفته‌ام.</label>
									<?php if ( $attestation ) : ?>
										<label class="dzn-booking__privacy"><input type="checkbox" name="adult_attested" value="1" required> تأیید می‌کنم هنرجو ۱۸ سال یا بیشتر دارد و می‌تواند این ثبت‌نام را بپذیرد.</label>
									<?php endif; ?>
									<button class="dzn-button dzn-button--secondary" type="submit">ادامه با دو قسط</button>
								</form>
							</details>
						</div>
					<?php else : ?>
						<p class="dzn-portal-help" role="status">امکان نمایش طرح پرداخت اکنون در دسترس نیست. لطفاً کمی بعد دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.</p>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>
		</article>
	<?php endforeach; ?>
</section>
