<?php
/**
 * Clearly labelled God Mode test classes for a participating teacher.
 *
 * Presentation only: every action URL and nonce is issued by the platform, and joining still
 * re-proves teacher ownership, the join window and provider readiness on the server.
 */
$items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
if ( ! $items ) { return; }
$timezone = (string) ( $args['timezone'] ?? 'UTC' );
$calendar = (string) ( $args['calendar'] ?? 'gregorian' );
$locale   = (string) ( $args['locale'] ?? 'fa_IR' );
$format_when = static function ( array $item ) use ( $timezone, $calendar, $locale ): string {
	$utc = (string) ( $item['starts_at_utc'] ?? '' );
	if ( '' === $utc ) { return ''; }
	if ( function_exists( 'dzn_theme_platform_calendar_time' ) ) {
		return dzn_theme_platform_calendar_time( $utc, $timezone, $calendar, $locale, 'datetime' );
	}
	return $utc;
};
?>
<section class="dzn-tp-section dzn-tp-test-classes" aria-labelledby="tp-test-classes" dir="rtl">
	<div class="dzn-tp-heading">
		<h2 id="tp-test-classes">کلاس‌های آزمایشی</h2>
		<span class="dzn-tp-test-badge">آزمایشی · بدون تأثیر مالی</span>
	</div>
	<p class="dzn-tp-help">این کلاس‌ها بخشی از محیط تست دائمی هستند. در فهرست کلاس‌های واقعی شما شمرده نمی‌شوند، درآمدی ایجاد نمی‌کنند و روی ظرفیت یا برنامهٔ واقعی شما اثر نمی‌گذارند.</p>
	<ol class="dzn-tp-class-list">
		<?php foreach ( $items as $item ) : ?>
			<?php
			if ( ! is_array( $item ) || empty( $item['session_id'] ) ) { continue; }
			$title = trim( (string) ( $item['title'] ?? '' ) );
			if ( '' === $title ) { $title = 'کلاس آزمایشی'; }
			$when = $format_when( $item );
			$ready = ! empty( $item['join_available'] );
			$reason = (string) ( $item['join_reason'] ?? '' );
			$reason_label = 'outside_join_window' === $reason ? 'زمان ورود به کلاس نرسیده است.' : ( 'classroom_not_ready' === $reason ? 'کلاس آنلاین هنوز آماده نشده است؛ جزئیات در پرتال مدیر بررسی می‌شود.' : '' );
			?>
			<li>
				<article class="dzn-tp-class is-upcoming is-test">
					<div class="dzn-tp-class__info-col">
						<div class="dzn-tp-class__title-row">
							<h3 class="dzn-tp-class__student"><?php echo esc_html( $title ); ?></h3>
							<span class="dzn-tp-test-badge">TEST</span>
						</div>
						<p class="dzn-tp-class__schedule-summary"><span aria-hidden="true">◷</span> <?php echo esc_html( $when ); ?> · <?php echo esc_html( $timezone ); ?></p>
						<p class="dzn-tp-help">شناسهٔ تست: <code dir="ltr"><?php echo esc_html( (string) ( $item['reference'] ?? '' ) ); ?></code> · وضعیت: <?php echo esc_html( (string) ( $item['status'] ?? '' ) ); ?></p>
						<?php if ( '' !== $reason_label ) : ?><p class="dzn-tp-help"><?php echo esc_html( $reason_label ); ?></p><?php endif; ?>
					</div>
					<div class="dzn-tp-actions">
						<?php if ( $ready ) : ?>
							<form method="post" action="<?php echo esc_url( (string) ( $item['join_action_url'] ?? '' ) ); ?>">
								<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) ( $item['session_id'] ?? 0 ) ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['join_nonce'] ?? '' ) ); ?>">
								<button class="dzn-button dzn-tp-btn-join" type="submit">ورود به کلاس آزمایشی</button>
							</form>
						<?php endif; ?>
						<?php if ( ! empty( $item['acknowledge_available'] ) ) : ?>
							<form method="post" action="<?php echo esc_url( (string) ( $item['acknowledge_action_url'] ?? '' ) ); ?>">
								<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) ( $item['session_id'] ?? 0 ) ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['acknowledge_nonce'] ?? '' ) ); ?>">
								<button class="dzn-button dzn-button--secondary" type="submit">پذیرش کلاس آزمایشی</button>
							</form>
						<?php endif; ?>
						<?php if ( ! empty( $item['attendance_available'] ) ) : ?>
							<form method="post" action="<?php echo esc_url( (string) ( $item['attendance_action_url'] ?? '' ) ); ?>">
								<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) ( $item['session_id'] ?? 0 ) ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['attendance_nonce'] ?? '' ) ); ?>">
								<input type="hidden" name="attended" value="1">
								<button class="dzn-button dzn-button--secondary" type="submit">تأیید برگزاری آزمایشی</button>
							</form>
						<?php endif; ?>
						<?php if ( ! empty( $item['reschedule_available'] ) ) : ?>
							<details class="dzn-tp-test-reschedule">
								<summary>تغییر زمان کلاس آزمایشی</summary>
								<form method="post" action="<?php echo esc_url( (string) ( $item['reschedule_action_url'] ?? '' ) ); ?>">
									<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) ( $item['session_id'] ?? 0 ) ); ?>">
									<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['reschedule_nonce'] ?? '' ) ); ?>">
									<input type="hidden" name="timezone" value="<?php echo esc_attr( $timezone ); ?>">
									<input type="hidden" name="duration_minutes" value="<?php echo esc_attr( (string) ( (int) ( $item['duration_minutes'] ?? 30 ) ) ); ?>">
									<label>تاریخ <input type="date" name="local_date" value="<?php echo esc_attr( (string) ( $item['reschedule_local_date'] ?? '' ) ); ?>" required></label>
									<label>ساعت <input type="time" name="local_time" value="<?php echo esc_attr( (string) ( $item['reschedule_local_time'] ?? '' ) ); ?>" required></label>
									<button class="dzn-button dzn-button--secondary" type="submit">ثبت زمان تازه</button>
								</form>
							</details>
						<?php endif; ?>
						<?php if ( ! empty( $item['cancel_available'] ) ) : ?>
							<form method="post" action="<?php echo esc_url( (string) ( $item['cancel_action_url'] ?? '' ) ); ?>" onsubmit="return confirm('این کلاس آزمایشی لغو شود؟');">
								<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) ( $item['session_id'] ?? 0 ) ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['cancel_nonce'] ?? '' ) ); ?>">
								<button class="dzn-button dzn-button--secondary" type="submit">لغو کلاس آزمایشی</button>
							</form>
						<?php endif; ?>
					</div>
				</article>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
