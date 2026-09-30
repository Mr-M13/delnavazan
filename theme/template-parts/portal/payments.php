<?php
/**
 * Billing/subscription presentation with explicit authority separation.
 *
 * @package DelnavazanTheme
 */

$payments = isset( $args['payments'] ) && is_array( $args['payments'] ) ? $args['payments'] : array();
$items = isset( $payments['items'] ) && is_array( $payments['items'] ) ? $payments['items'] : array();
?>
<section class="dzn-portal-section dzn-payments" aria-labelledby="dzn-payments-title">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php esc_html_e( 'مالی', 'delnavazan-theme' ); ?></p>
			<h2 id="dzn-payments-title"><?php esc_html_e( 'پرداخت و اشتراک', 'delnavazan-theme' ); ?></h2>
		</div>
		<span class="dzn-status dzn-status--muted"><?php esc_html_e( 'نمایش آزمایشی', 'delnavazan-theme' ); ?></span>
	</div>
	<div class="dzn-payments__summary">
		<div><p class="dzn-status-row__label"><?php esc_html_e( 'وضعیت اشتراک', 'delnavazan-theme' ); ?></p><p><?php echo esc_html( $payments['status'] ?? 'از منبع معتبر دریافت نشده' ); ?></p></div>
		<div><p class="dzn-status-row__label"><?php esc_html_e( 'تمدید بعدی', 'delnavazan-theme' ); ?></p><p><?php echo esc_html( $payments['renewal_date'] ?? 'از منبع معتبر دریافت نشده' ); ?></p></div>
	</div>
	<?php if ( $items ) : ?>
		<div class="dzn-payment-list">
			<?php foreach ( $items as $item ) : ?>
				<div class="dzn-payment-row">
					<div><strong><?php echo esc_html( $item['title'] ?? '' ); ?></strong><span><?php echo esc_html( $item['date'] ?? '' ); ?></span></div>
					<span><?php echo esc_html( $item['amount'] ?? '' ); ?></span>
					<?php if ( ! empty( $item['receipt_url'] ) ) : ?><a href="<?php echo esc_url( $item['receipt_url'] ); ?>"><?php esc_html_e( 'دریافت رسید', 'delnavazan-theme' ); ?></a><?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<p class="dzn-portal-empty"><?php esc_html_e( 'سابقهٔ پرداخت پس از اتصال به منبع مالی معتبر نمایش داده می‌شود.', 'delnavazan-theme' ); ?></p>
	<?php endif; ?>
	<div class="dzn-payments__boundary">
		<p><strong><?php esc_html_e( 'لغو اشتراک، لغو ترم و لغو یک کلاس سه کار جدا هستند.', 'delnavazan-theme' ); ?></strong></p>
		<p><?php esc_html_e( 'هیچ‌کدام در این پوسته اجرا نمی‌شود و دکمهٔ لغو فقط پس از اتصال به اختیار معتبر نمایش داده خواهد شد.', 'delnavazan-theme' ); ?></p>
	</div>
</section>
