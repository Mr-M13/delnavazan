<?php
/**
 * Billing/subscription presentation with explicit authority separation.
 *
 * When the owner-bound commercial read returns obligations, the existing
 * checkout script renders the interactive plan and posts to the existing REST
 * controller. The server-rendered list remains available without JavaScript.
 * The Theme never calculates a plan, fee or instalment.
 *
 * @package DelnavazanTheme
 */

$payments = isset( $args['payments'] ) && is_array( $args['payments'] ) ? $args['payments'] : array();
$items = isset( $payments['items'] ) && is_array( $payments['items'] ) ? $payments['items'] : array();
$checkout_enabled = ! empty( $payments['checkout_enabled'] );
$unavailable = 'unavailable' === (string) ( $payments['state'] ?? ( $checkout_enabled ? 'ok' : 'unavailable' ) );
$render_items = static function ( array $rows ): void {
	if ( ! $rows ) {
		echo '<p class="dzn-portal-empty">' . esc_html__( 'سابقهٔ پرداخت تازه‌ای برای نمایش ثبت نشده است.', 'delnavazan-theme' ) . '</p>';
		return;
	}
	echo '<div class="dzn-payment-list">';
	foreach ( $rows as $item ) {
		echo '<div class="dzn-payment-row">';
		echo '<div><strong>' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</strong><span>' . esc_html( (string) ( $item['date'] ?? '' ) ) . '</span></div>';
		echo '<span>' . esc_html( (string) ( $item['amount'] ?? '' ) ) . '</span>';
		if ( ! empty( $item['receipt_url'] ) ) {
			echo '<a href="' . esc_url( (string) $item['receipt_url'] ) . '">' . esc_html__( 'دریافت رسید', 'delnavazan-theme' ) . '</a>';
		}
		echo '<span class="dzn-status dzn-status--muted">' . esc_html( (string) ( $item['state'] ?? '' ) ) . '</span>';
		echo '</div>';
	}
	echo '</div>';
};
?>
<section class="dzn-portal-section dzn-payments" aria-labelledby="dzn-payments-title">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php esc_html_e( 'مالی', 'delnavazan-theme' ); ?></p>
			<h2 id="dzn-payments-title"><?php esc_html_e( 'پرداخت و اشتراک', 'delnavazan-theme' ); ?></h2>
		</div>
		<span class="dzn-status dzn-status--muted"><?php echo $unavailable ? esc_html__( 'در دسترس نیست', 'delnavazan-theme' ) : esc_html__( 'وضعیت پرداخت ترم', 'delnavazan-theme' ); ?></span>
	</div>
	<?php if ( $unavailable ) : ?>
		<p class="dzn-portal-empty"><?php echo esc_html( (string) ( $payments['status'] ?? 'وضعیت پرداخت هنوز از منبع مالی معتبر خوانده نشده است.' ) ); ?></p>
	<?php else : ?>
	<div class="dzn-payments__summary">
		<div><p class="dzn-status-row__label"><?php esc_html_e( 'وضعیت پرداخت ترم', 'delnavazan-theme' ); ?></p><p><?php echo esc_html( (string) ( $payments['status'] ?? 'از منبع معتبر دریافت نشده' ) ); ?></p></div>
	</div>
	<?php if ( $checkout_enabled ) : ?>
		<div class="dzn-checkout" data-dzn-student-checkout dir="rtl">
			<p class="dzn-checkout__status" data-checkout-status aria-live="polite"><?php esc_html_e( 'در حال دریافت وضعیت پرداخت…', 'delnavazan-theme' ); ?></p>
			<div class="dzn-checkout__list" data-checkout-list></div>
		</div>
		<noscript><?php $render_items( $items ); ?></noscript>
	<?php else : ?>
		<?php $render_items( $items ); ?>
	<?php endif; ?>
	<div class="dzn-payments__boundary">
		<p><strong><?php esc_html_e( 'لغو اشتراک، لغو ترم و لغو یک کلاس سه کار جدا هستند.', 'delnavazan-theme' ); ?></strong></p>
		<p><?php esc_html_e( 'هیچ‌کدام در این پوسته اجرا نمی‌شود و دکمهٔ لغو فقط پس از اتصال به اختیار معتبر نمایش داده خواهد شد.', 'delnavazan-theme' ); ?></p>
	</div>
	<?php endif; ?>
</section>
