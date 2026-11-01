<?php
/**
 * Student account status: concise enrolment, payment, lesson and request facts.
 *
 * All values are supplied by the Student view model. The template never derives
 * an entitlement, a term total or a payment amount. The discontinuation request
 * remains a support-backed action; it is not a cancellation command.
 *
 * @package DelnavazanTheme
 */

$status = isset( $args['status'] ) && is_array( $args['status'] ) ? $args['status'] : array();
if ( empty( $status['active'] ) ) {
	return;
}
$support_message = 'درود، درخواست بررسی توقف ثبت‌نام و ادامهٔ کلاس را دارم.';
$enrolment_label = (string) ( $status['enrolment_label'] ?? 'فعال' );
$payment_label = (string) ( $status['payment_label'] ?? '' );
$instalment_label = (string) ( $status['instalment_label'] ?? '' );
$next_instalment_label = (string) ( $status['next_instalment_label'] ?? '' );
$progress_label = (string) ( $status['progress_label'] ?? '' );
$remaining_label = (string) ( $status['remaining_label'] ?? '' );
$cancelled_label = (string) ( $status['cancelled_label'] ?? '' );
$replacement_label = (string) ( $status['replacement_label'] ?? '' );
$reschedule_label = (string) ( $status['reschedule_label'] ?? '' );
?>
<section class="dzn-portal-section dzn-account-status" aria-labelledby="dzn-account-status-title">
	<div class="dzn-portal-section__heading">
		<div><p class="dzn-portal-kicker"><?php esc_html_e( 'وضعیت حساب', 'delnavazan-theme' ); ?></p><h2 id="dzn-account-status-title"><?php esc_html_e( 'وضعیت ثبت‌نام', 'delnavazan-theme' ); ?></h2></div>
		<span class="dzn-status dzn-status--success"><?php echo esc_html( $enrolment_label ); ?></span>
	</div>
	<dl class="dzn-account-status__facts">
		<div>
			<dt><?php esc_html_e( 'وضعیت پرداخت', 'delnavazan-theme' ); ?></dt>
			<dd><?php echo esc_html( $payment_label ); ?></dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'اقساط', 'delnavazan-theme' ); ?></dt>
			<dd>
				<?php echo esc_html( '' !== $instalment_label ? $instalment_label : 'اطلاعات اقساط در دسترس نیست.' ); ?>
				<?php if ( '' !== $next_instalment_label ) : ?><small class="dzn-account-status__note"><?php echo esc_html( sprintf( 'قسط بعدی: %s', $next_instalment_label ) ); ?></small><?php endif; ?>
			</dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'جلسه‌های دوره', 'delnavazan-theme' ); ?></dt>
			<dd>
				<?php if ( '' !== $progress_label ) : ?>
					<?php echo esc_html( $progress_label ); ?><?php if ( '' !== $remaining_label ) : ?> · <?php echo esc_html( $remaining_label ); ?><?php endif; ?>
				<?php else : ?>
					<?php esc_html_e( 'اطلاعات جلسه‌های ترم در دسترس نیست.', 'delnavazan-theme' ); ?>
				<?php endif; ?>
			</dd>
		</div>
		<?php if ( '' !== $cancelled_label ) : ?>
			<div>
				<dt><?php esc_html_e( 'جلسه‌های لغو‌شده', 'delnavazan-theme' ); ?></dt>
				<dd><?php echo esc_html( $cancelled_label ); ?><small class="dzn-account-status__note"><?php esc_html_e( 'لغو کلاس از سهمیهٔ جبرانی و از غیبت جدا است.', 'delnavazan-theme' ); ?></small></dd>
			</div>
		<?php endif; ?>
		<div>
			<dt><?php esc_html_e( 'جبرانی', 'delnavazan-theme' ); ?></dt>
			<dd><?php echo esc_html( '' !== $replacement_label ? $replacement_label : 'جبرانی: در دسترس نیست' ); ?></dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'درخواست‌های تغییر زمان', 'delnavazan-theme' ); ?></dt>
			<dd><?php echo esc_html( $reschedule_label ); ?></dd>
		</div>
	</dl>
	<?php if ( ! empty( $status['replacement_limit_message'] ) ) : ?>
		<p class="dzn-portal-action-status dzn-portal-action-status--warning" role="status"><?php echo esc_html( (string) $status['replacement_limit_message'] ); ?></p>
	<?php endif; ?>
	<div class="dzn-account-status__actions">
		<a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( dzn_theme_whatsapp_link( $support_message ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'درخواست توقف ثبت‌نام', 'delnavazan-theme' ); ?></a>
		<p class="dzn-portal-help"><?php esc_html_e( 'درخواست توقف از طریق پشتیبانی بررسی می‌شود تا سابقهٔ کلاس‌ها و پرداخت‌ها سالم بماند.', 'delnavazan-theme' ); ?></p>
	</div>
</section>
