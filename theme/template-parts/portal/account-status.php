<?php
/**
 * Student account status: active enrolment, payment state and a safe discontinuation request.
 *
 * @package DelnavazanTheme
 */

$status = isset( $args['status'] ) && is_array( $args['status'] ) ? $args['status'] : array();
if ( empty( $status['active'] ) ) {
	return;
}
$paid = ! empty( $status['payment_verified'] );
$support_message = rawurlencode( 'سلام، درخواست بررسی توقف ثبت‌نام و ادامهٔ کلاس را دارم.' );
?>
<section class="dzn-portal-section dzn-account-status" aria-labelledby="dzn-account-status-title">
	<div class="dzn-portal-section__heading">
		<div><p class="dzn-portal-kicker">وضعیت حساب</p><h2 id="dzn-account-status-title">ثبت‌نام فعال</h2></div>
		<span class="dzn-status dzn-status--success">فعال</span>
	</div>
	<p class="dzn-account-status__summary">
		<?php if ( $paid ) : ?>
			<?php esc_html_e( 'حساب شما فعال است و پرداخت ترم تکمیل شده است.', 'delnavazan-theme' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'حساب شما فعال است؛ وضعیت پرداخت از منبع مالی معتبر در حال بررسی است.', 'delnavazan-theme' ); ?>
		<?php endif; ?>
	</p>
	<?php if ( ! empty( $status['course'] ) || ! empty( $status['teacher'] ) ) : ?>
		<p class="dzn-account-status__course"><?php echo esc_html( trim( (string) ( $status['course'] ?? '' ) . ( ! empty( $status['teacher'] ) ? ' · ' . (string) $status['teacher'] : '' ) ) ); ?></p>
	<?php endif; ?>
	<?php if ( (int) ( $status['replacement_allowance'] ?? 0 ) > 0 ) : ?>
		<p class="dzn-account-status__replacement">
			<?php echo esc_html( sprintf( 'جایگزین‌های ترم: %d از %d استفاده شده · %d در انتظار · %d قابل استفاده', (int) ( $status['replacement_used'] ?? 0 ), (int) $status['replacement_allowance'], (int) ( $status['replacement_pending_allocated'] ?? 0 ), (int) ( $status['replacement_available'] ?? $status['replacement_remaining'] ?? 0 ) ) ); ?>
			<?php if ( ! empty( $status['replacement_pending'] ) ) : ?>
				· <?php echo esc_html( sprintf( '%d جایگزین در انتظار تعیین زمان', (int) $status['replacement_pending'] ) ); ?>
			<?php endif; ?>
		</p>
		<?php if ( ! empty( $status['replacement_limit_message'] ) ) : ?>
			<p class="dzn-portal-action-status dzn-portal-action-status--warning" role="status"><?php echo esc_html( (string) $status['replacement_limit_message'] ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	<div class="dzn-account-status__actions">
		<a class="dzn-button" href="<?php echo esc_url( home_url( '/student-portal/' ) ); ?>"><?php esc_html_e( 'ادامهٔ کلاس‌ها', 'delnavazan-theme' ); ?></a>
		<a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( 'https://wa.me/61413413004?text=' . $support_message ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'درخواست توقف ثبت‌نام', 'delnavazan-theme' ); ?></a>
		<p class="dzn-portal-help"><?php esc_html_e( 'درخواست توقف از طریق پشتیبانی بررسی می‌شود تا سابقهٔ کلاس‌ها و پرداخت‌ها سالم بماند.', 'delnavazan-theme' ); ?></p>
	</div>
</section>
