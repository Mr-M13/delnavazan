<?php
/**
 * Teacher actions for a confirmed introductory booking.
 *
 * The backend proves that the signed-in Teacher owns the confirmation and
 * revalidates every scheduling constraint. This template only renders the
 * backend-issued action URL, nonce and current facts.
 *
 * @package DelnavazanTheme
 */

$item = isset( $args['item'] ) && is_array( $args['item'] ) ? $args['item'] : array();
$timezone = (string) ( $item['timezone'] ?? '' );
$timezone_options = isset( $item['intro_timezone_options'] ) && is_array( $item['intro_timezone_options'] ) ? $item['intro_timezone_options'] : array();
if ( '' !== $timezone && ! isset( $timezone_options[ $timezone ] ) ) {
	$timezone_options = array( $timezone => $timezone ) + $timezone_options;
}
// Prefer the freshly rendered model calendar when supplied; fall back to the
// per-item value. A missing per-item value must never silently force Gregorian
// for a teacher who chose Persian.
$calendar = (string) ( $args['calendar'] ?? $args['teacher_calendar'] ?? ( $item['intro_calendar'] ?? $item['reschedule_calendar'] ?? '' ) );
if ( function_exists( 'dzn_theme_platform_calendar_preference' ) ) {
	$calendar = dzn_theme_platform_calendar_preference( $calendar, (string) ( $item['intro_locale'] ?? $item['reschedule_locale'] ?? $args['locale'] ?? '' ) );
} else {
	$calendar = in_array( strtolower( trim( $calendar ) ), array( 'persian', 'gregorian' ), true ) ? strtolower( trim( $calendar ) ) : 'gregorian';
}
$min_date = function_exists( 'dzn_theme_platform_local_date_key' ) ? dzn_theme_platform_local_date_key( gmdate( 'Y-m-d H:i:s' ), $timezone ) : gmdate( 'Y-m-d' );
$months   = array( 1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند' );
$jalali_year = 0;
$jalali_month = 1;
$jalali_day = 1;
if ( 'persian' === $calendar ) {
	$now_parts = null;
	if ( function_exists( 'dzn_theme_platform_jalali_from_local_date' ) ) {
		$now_parts = dzn_theme_platform_jalali_from_local_date( (string) ( $item['intro_local_date'] ?? '' ), $timezone );
	}
	if ( null === $now_parts && function_exists( 'dzn_theme_platform_jalali_parts' ) ) {
		$now_parts = dzn_theme_platform_jalali_parts( gmdate( 'Y-m-d H:i:s' ), $timezone );
	}
	$jalali_year = (int) ( $now_parts['year'] ?? 0 );
	$jalali_month = (int) ( $now_parts['month'] ?? 1 );
	$jalali_day = (int) ( $now_parts['day'] ?? 1 );
}
if ( 'persian' === $calendar && $jalali_year < 1300 ) {
	// Defensive fallback when the Jalali helper is unavailable (e.g. no ext-intl).
	// Jalali year ≈ Gregorian year − 621, so the selects remain submittable.
	$jalali_year = max( 1300, (int) gmdate( 'Y' ) - 621 );
	$jalali_month = 1;
	$jalali_day = 1;
}
?>
<details class="dzn-tp-intro-actions">
	<summary>مدیریت کلاس آشنایی</summary>
	<div class="dzn-tp-intro-actions__body">
		<section class="dzn-tp-intro-actions__card">
			<h4>تغییر زمان کلاس آشنایی</h4>
			<p class="dzn-tp-help">زمان جدید پس از بررسی تداخل مدرس و هنرجو ثبت می‌شود و به هنرجو اطلاع داده می‌شود.</p>
			<p class="dzn-tp-detail-help dzn-tp-reschedule__calendar">تقویم انتخابی: <strong><?php echo 'persian' === $calendar ? 'شمسی' : 'میلادی'; ?></strong></p>
			<form method="post" action="<?php echo esc_url( (string) ( $item['intro_reschedule_action_url'] ?? '' ) ); ?>" data-dzn-tp-submit>
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['intro_reschedule_nonce'] ?? '' ) ); ?>">
				<input type="hidden" name="confirmation_uid" value="<?php echo esc_attr( (string) ( $item['confirmation_uid'] ?? '' ) ); ?>">
				<input type="hidden" name="reason_code" value="teacher_requested">
				<input type="hidden" name="command_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
				<input type="hidden" name="calendar" value="<?php echo esc_attr( $calendar ); ?>">
				<?php if ( 'persian' === $calendar ) : ?>
					<div class="dzn-tp-form-grid dzn-tp-reschedule__date">
						<label>سال<input type="number" required min="<?php echo esc_attr( (string) max( 1, $jalali_year ) ); ?>" max="<?php echo esc_attr( (string) ( $jalali_year + 2 ) ); ?>" name="jalali_year" value="<?php echo esc_attr( (string) $jalali_year ); ?>"></label>
						<label>ماه<select name="jalali_month" required><?php foreach ( $months as $number => $label ) : ?><option value="<?php echo esc_attr( (string) $number ); ?>"<?php selected( $jalali_month, $number ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
						<label>روز<select name="jalali_day" required><?php for ( $day = 1; $day <= 31; $day++ ) : ?><option value="<?php echo esc_attr( (string) $day ); ?>"<?php selected( $jalali_day, $day ); ?>><?php echo esc_html( (string) $day ); ?></option><?php endfor; ?></select></label>
					</div>
				<?php else : ?>
					<label>تاریخ جدید<input type="date" name="local_date" required min="<?php echo esc_attr( $min_date ); ?>" value="<?php echo esc_attr( (string) ( $item['intro_local_date'] ?? '' ) ); ?>"></label>
				<?php endif; ?>
				<label>ساعت شروع<input type="time" name="local_start_time" required></label>
				<label>منطقهٔ زمانی<select name="schedule_timezone" required>
					<?php foreach ( $timezone_options as $option_timezone => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $option_timezone ); ?>"<?php selected( $timezone, (string) $option_timezone ); ?>><?php echo esc_html( (string) $label ); ?></option>
					<?php endforeach; ?>
				</select></label>
				<button class="dzn-button dzn-button--secondary" type="submit" data-dzn-tp-busy-label="در حال ثبت زمان جدید…">ثبت زمان جدید</button>
			</form>
		</section>
		<section class="dzn-tp-intro-actions__card dzn-tp-intro-actions__card--danger">
			<h4>لغو کلاس آشنایی</h4>
			<p class="dzn-tp-help">لغو کلاس به معنی ثبت حضور یا پرداخت نیست. هنرجو و مدیر آموزشگاه مطلع می‌شوند.</p>
			<form method="post" action="<?php echo esc_url( (string) ( $item['intro_cancel_action_url'] ?? '' ) ); ?>" data-dzn-tp-submit>
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['intro_cancel_nonce'] ?? '' ) ); ?>">
				<input type="hidden" name="confirmation_uid" value="<?php echo esc_attr( (string) ( $item['confirmation_uid'] ?? '' ) ); ?>">
				<input type="hidden" name="reason_code" value="teacher_requested">
				<input type="hidden" name="command_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
				<button class="dzn-button dzn-button--secondary" type="submit" onclick="return confirm('این کلاس آشنایی لغو شود؟')">لغو کلاس آشنایی</button>
			</form>
		</section>
	</div>
</details>
