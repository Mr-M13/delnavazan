<?php
/**
 * Student-owned reschedule proposals awaiting acceptance or rejection.
 *
 * @package DelnavazanTheme
 */

$items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$status = sanitize_key( (string) ( $_GET['reschedule_status'] ?? '' ) );
if ( ! $items && ! in_array( $status, array( 'ok', 'failed' ), true ) ) {
	return;
}
?>
<section class="dzn-portal-section dzn-reschedule" aria-labelledby="dzn-reschedule-title">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php esc_html_e( 'پیشنهاد زمان تازه', 'delnavazan-theme' ); ?></p>
			<h2 id="dzn-reschedule-title"><?php esc_html_e( 'درخواست تغییر زمان کلاس', 'delnavazan-theme' ); ?></h2>
		</div>
	</div>
	<?php if ( 'ok' === $status ) : ?><p class="dzn-portal-action-status" role="status"><?php esc_html_e( 'پاسخ شما به پیشنهاد تغییر زمان ثبت شد.', 'delnavazan-theme' ); ?></p><?php elseif ( 'failed' === $status ) : ?><p class="dzn-portal-action-status" role="alert"><?php esc_html_e( 'پاسخ شما ثبت نشد یا این پیشنهاد دیگر معتبر نیست. صفحه را تازه کنید.', 'delnavazan-theme' ); ?></p><?php endif; ?>
	<?php if ( ! $items ) : ?>
		<p class="dzn-portal-empty"><?php esc_html_e( 'در حال حاضر پیشنهاد تغییر زمانی در انتظار پاسخ شما نیست.', 'delnavazan-theme' ); ?></p>
	<?php else : ?>
		<ul class="dzn-reschedule-list">
			<?php foreach ( $items as $item ) : ?>
				<li class="dzn-reschedule-card">
					<div class="dzn-reschedule-card__facts">
						<h3><?php echo esc_html( (string) ( $item['instrument'] ?? '' ) ); ?></h3>
						<p><?php echo esc_html( sprintf( 'مدرس: %s', (string) ( $item['teacher'] ?? '' ) ) ); ?></p>
						<p><time datetime="<?php echo esc_attr( (string) ( $item['starts_at_iso'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $item['date'] ?? '' ) . ' · ' . (string) ( $item['time'] ?? '' ) ); ?></time></p>
						<?php if ( ! empty( $item['timezone_label'] ) ) : ?><p class="dzn-timezone-label"><span aria-hidden="true">◷</span> <?php echo esc_html( (string) $item['timezone_label'] ); ?></p><?php endif; ?>
					</div>
					<div class="dzn-reschedule-card__actions">
						<form method="post" action="<?php echo esc_url( (string) ( $item['action_url'] ?? '' ) ); ?>">
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['nonce'] ?? '' ) ); ?>">
							<input type="hidden" name="proposal_uid" value="<?php echo esc_attr( (string) ( $item['proposal_uid'] ?? '' ) ); ?>">
							<input type="hidden" name="decision_key" value="<?php echo esc_attr( (string) ( $item['decision_key'] ?? '' ) ); ?>">
							<input type="hidden" name="decision" value="accept">
							<button class="dzn-button" type="submit"><?php esc_html_e( 'پذیرش زمان جدید', 'delnavazan-theme' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( (string) ( $item['action_url'] ?? '' ) ); ?>">
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['nonce'] ?? '' ) ); ?>">
							<input type="hidden" name="proposal_uid" value="<?php echo esc_attr( (string) ( $item['proposal_uid'] ?? '' ) ); ?>">
							<input type="hidden" name="decision_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
							<input type="hidden" name="decision" value="reject">
							<button class="dzn-button dzn-button--secondary" type="submit"><?php esc_html_e( 'رد پیشنهاد', 'delnavazan-theme' ); ?></button>
						</form>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
