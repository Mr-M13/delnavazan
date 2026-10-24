<?php
/**
 * Post-introductory continuation card.
 *
 * @package DelnavazanTheme
 */

$items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$status = sanitize_key( (string) ( $_GET['post_intro_status'] ?? '' ) );
$messages = array(
	'adult_attestation_required' => 'برای ادامهٔ ثبت‌نام، تأیید سن هنرجو لازم است.',
	'guardian_required' => 'برای هنرجوی زیر ۱۸ سال، تأیید ولی یا سرپرست لازم است. لطفاً با پشتیبانی تماس بگیرید.',
	'post_intro_invitation_expired' => 'مهلت نگه‌داشتن زمان کلاس گذشته است. لطفاً با پشتیبانی تماس بگیرید.',
	'post_intro_invitation_not_found' => 'این دعوت ثبت‌نام در دسترس نیست.',
	'post_intro_invitation_not_ready' => 'این دعوت ثبت‌نام هنوز آمادهٔ پرداخت نیست.',
	'checkout_temporarily_unavailable' => 'پرداخت موقتاً در دسترس نیست. لطفاً چند دقیقهٔ دیگر دوباره تلاش کنید.',
	'checkout_pending' => 'پرداخت شما در انتظار تکمیل است. برای ادامه دوباره تلاش کنید.',
	'payment_received' => 'پرداخت شما تأیید شد و ثبت‌نام در حال فعال‌سازی است.',
	'activated' => 'ثبت‌نام شما فعال شد.',
	'post_intro_unavailable' => 'انجام این اقدام در حال حاضر ممکن نیست. لطفاً با پشتیبانی تماس بگیرید.',
);
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
					<form method="post" action="<?php echo esc_url( (string) $item['action_url'] ); ?>">
						<input type="hidden" name="action" value="dzn_post_intro_continue">
						<input type="hidden" name="preparation_uid" value="<?php echo esc_attr( (string) ( $item['reference'] ?? '' ) ); ?>">
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['nonce'] ?? '' ) ); ?>">
						<?php if ( ! empty( $item['adult_attestation_required'] ) ) : ?>
							<label class="dzn-booking__privacy">
								<input type="checkbox" name="adult_attested" value="1" required>
								تأیید می‌کنم هنرجو ۱۸ سال یا بیشتر دارد و می‌تواند این ثبت‌نام را بپذیرد.
							</label>
						<?php endif; ?>
						<button class="dzn-button" type="submit"><?php echo esc_html( (string) ( $item['action_label'] ?? 'تکمیل پرداخت و تأیید ثبت‌نام' ) ); ?></button>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</article>
	<?php endforeach; ?>
</section>
