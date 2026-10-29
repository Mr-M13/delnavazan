<?php
/**
 * Teacher reschedule form shared by today's expanded card and future compact cards.
 *
 * @package DelnavazanTheme
 */

$item     = isset( $args['item'] ) && is_array( $args['item'] ) ? $args['item'] : array();
$timezone = (string) ( $args['timezone'] ?? ( $item['reschedule_timezone'] ?? '' ) );
$index    = (int) ( $args['index'] ?? 0 );
$demo     = ! empty( $args['demo'] );
$calendar = (string) ( $item['reschedule_calendar'] ?? 'gregorian' );
$months   = array( 1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند' );
$now_parts = dzn_theme_platform_jalali_parts( gmdate( 'Y-m-d H:i:s' ), $timezone );
$current_year = (int) ( $now_parts['year'] ?? 0 );
$current_month = (int) ( $now_parts['month'] ?? 1 );
$current_day = (int) ( $now_parts['day'] ?? 1 );
?>
<section class="dzn-tp-reschedule" aria-labelledby="tp-reschedule-<?php echo esc_attr( $index ); ?>">
	<h4 id="tp-reschedule-<?php echo esc_attr( $index ); ?>">تغییر زمان کلاس</h4>
	<p class="dzn-tp-detail-help">زمان فعلی: <strong><?php echo esc_html( (string) ( $item['reschedule_current'] ?? $item['schedule_summary'] ?? '' ) ); ?></strong>. زمان جدید پس از پذیرش هنرجو قطعی می‌شود و مدت کلاس حفظ می‌شود.</p>
	<form method="post" action="<?php echo esc_url( (string) ( $item['reschedule_action_url'] ?? '' ) ); ?>" data-dzn-tp-submit>
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['reschedule_nonce'] ?? '' ) ); ?>">
		<input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $item['lesson_uid'] ?? '' ) ); ?>">
		<input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $item['schedule_version_uid'] ?? '' ) ); ?>">
		<input type="hidden" name="timezone" value="<?php echo esc_attr( $timezone ); ?>">
		<input type="hidden" name="calendar" value="<?php echo esc_attr( $calendar ); ?>">
		<input type="hidden" name="request_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
		<?php if ( 'persian' === $calendar ) : ?>
			<div class="dzn-tp-form-grid dzn-tp-reschedule__date">
				<label>سال<input type="number" required min="<?php echo esc_attr( (string) max( 1, $current_year ) ); ?>" max="<?php echo esc_attr( (string) ( $current_year + 2 ) ); ?>" name="jalali_year" value="<?php echo esc_attr( (string) $current_year ); ?>"></label>
				<label>ماه<select name="jalali_month" required><?php foreach ( $months as $number => $label ) : ?><option value="<?php echo esc_attr( (string) $number ); ?>"<?php selected( $current_month, $number ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<label>روز<select name="jalali_day" required><?php for ( $day = 1; $day <= 31; $day++ ) : ?><option value="<?php echo esc_attr( (string) $day ); ?>"<?php selected( $current_day, $day ); ?>><?php echo esc_html( (string) $day ); ?></option><?php endfor; ?></select></label>
			</div>
		<?php else : ?>
			<label>تاریخ جدید<input type="date" name="local_date" required min="<?php echo esc_attr( dzn_theme_platform_local_date_key( gmdate( 'Y-m-d H:i:s' ), $timezone ) ); ?>"></label>
		<?php endif; ?>
		<label>ساعت شروع<input type="time" name="local_time" required></label>
		<button class="dzn-button dzn-button--secondary" type="submit" data-dzn-tp-busy-label="در حال ارسال درخواست…">ارسال درخواست تغییر زمان</button>
	</form>
	<?php if ( $demo ) : ?><p class="dzn-tp-action-status" role="status" aria-live="polite"></p><?php endif; ?>
</section>
