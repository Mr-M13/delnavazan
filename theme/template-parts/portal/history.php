<?php
/**
 * Reusable recent/past class history.
 *
 * @package DelnavazanTheme
 */

$items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$account = ! empty( $args['account'] );
$issue_status = sanitize_key( (string) ( $_GET['absence_status'] ?? '' ) );
?>
<?php if ( 'lesson_issue_recorded' === $issue_status ) : ?>
	<p class="dzn-portal-action-status" aria-live="polite"><?php esc_html_e( 'گزارش شما برای بررسی مدیر ثبت شد.', 'delnavazan-theme' ); ?></p>
<?php elseif ( 'issue_unavailable' === $issue_status ) : ?>
	<p class="dzn-portal-action-status" aria-live="polite"><?php esc_html_e( 'ثبت گزارش برای این جلسه ممکن نشد. لطفاً صفحه را تازه کنید و دوباره تلاش کنید.', 'delnavazan-theme' ); ?></p>
<?php endif; ?>
<section class="dzn-portal-section dzn-history" aria-labelledby="<?php echo $account ? 'dzn-past-classes-title' : 'dzn-recent-history-title'; ?>">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php echo esc_html( $account ? 'سابقهٔ آموزشی' : 'مرور کوتاه' ); ?></p>
			<h2 id="<?php echo $account ? 'dzn-past-classes-title' : 'dzn-recent-history-title'; ?>"><?php echo esc_html( $account ? 'کلاس‌های گذشته' : 'کلاس‌های اخیر' ); ?></h2>
		</div>
	</div>
	<?php if ( ! $items ) : ?>
		<p class="dzn-portal-empty"><?php esc_html_e( 'هنوز سابقه‌ای برای نمایش دریافت نشده است.', 'delnavazan-theme' ); ?></p>
	<?php else : ?>
		<div class="dzn-history__list">
			<?php foreach ( $items as $item ) : ?>
				<article class="dzn-history-row">
					<div class="dzn-history-row__date"><?php if ( ! empty( $item['starts_at_utc'] ) ) : ?><time datetime="<?php echo esc_attr( (string) ( $item['starts_at_iso'] ?? '' ) ); ?>"><?php echo esc_html( $item['date'] ?? '' ); ?></time><?php else : ?><?php echo esc_html( $item['date'] ?? '' ); ?><?php endif; ?></div>
					<div class="dzn-history-row__main">
						<h3><?php echo esc_html( $item['title'] ?? '' ); ?></h3>
						<?php if ( ! empty( $item['detail'] ) ) : ?><p><?php echo esc_html( $item['detail'] ); ?></p><?php endif; ?>
					</div>
					<p class="dzn-status dzn-status--<?php echo esc_attr( sanitize_key( $item['tone'] ?? 'muted' ) ); ?>"><?php echo esc_html( $item['outcome'] ?? '' ); ?></p>
					<?php if ( ! empty( $item['report_issue_available'] ) ) : ?>
						<details class="dzn-history-row__report">
							<summary><?php esc_html_e( 'گزارش کلاس برگزارنشده یا مورد اعتراض', 'delnavazan-theme' ); ?></summary>
							<p class="dzn-portal-help"><?php esc_html_e( 'این گزارش برای بررسی مدیر ثبت می‌شود و به‌تنهایی وضعیت حضور یا مالی را تغییر نمی‌دهد.', 'delnavazan-theme' ); ?></p>
							<form method="post" action="<?php echo esc_url( (string) ( $item['report_issue_action_url'] ?? '' ) ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['report_issue_nonce'] ?? '' ) ); ?>">
								<input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $item['lesson_uid'] ?? '' ) ); ?>">
								<input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $item['schedule_version_uid'] ?? '' ) ); ?>">
								<label><select name="issue_reason"><option value="student_reports_missed_class"><?php esc_html_e( 'کلاس برگزار نشد', 'delnavazan-theme' ); ?></option><option value="student_disputes_class"><?php esc_html_e( 'به برگزاری یا حضور اعتراض دارم', 'delnavazan-theme' ); ?></option></select></label>
								<button class="dzn-button dzn-button--secondary" type="submit"><?php esc_html_e( 'ثبت گزارش برای بررسی', 'delnavazan-theme' ); ?></button>
							</form>
						</details>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>
