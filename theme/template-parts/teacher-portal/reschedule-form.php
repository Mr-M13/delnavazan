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
// Prefer the freshly rendered model calendar when supplied; fall back to the
// per-item value. A missing per-item value must never silently force Gregorian
// for a teacher who chose Persian.
$calendar = (string) ( $args['calendar'] ?? $args['teacher_calendar'] ?? ( $item['reschedule_calendar'] ?? '' ) );
if ( function_exists( 'dzn_theme_platform_calendar_preference' ) ) {
	$calendar = dzn_theme_platform_calendar_preference( $calendar, (string) ( $item['reschedule_locale'] ?? $args['locale'] ?? '' ) );
} else {
	$calendar = in_array( strtolower( trim( $calendar ) ), array( 'persian', 'gregorian' ), true ) ? strtolower( trim( $calendar ) ) : 'gregorian';
}
$request_kind = (string) ( $item['request_kind'] ?? 'reschedule' );
$replacement  = 'replacement' === $request_kind;
$months   = array( 1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند' );
$current_year = 0;
$current_month = 1;
$current_day = 1;
if ( 'persian' === $calendar && function_exists( 'dzn_theme_platform_jalali_parts' ) ) {
	$now_parts = dzn_theme_platform_jalali_parts( gmdate( 'Y-m-d H:i:s' ), $timezone );
	$current_year = (int) ( $now_parts['year'] ?? 0 );
	$current_month = (int) ( $now_parts['month'] ?? 1 );
	$current_day = (int) ( $now_parts['day'] ?? 1 );
}
if ( 'persian' === $calendar && $current_year < 1300 ) {
	// Defensive fallback when the Jalali helper is unavailable (e.g. no ext-intl).
	// Jalali year ≈ Gregorian year − 621, so the selects remain submittable
	// instead of rendering an empty year (min 1 / max 2).
	$current_year = max( 1300, (int) gmdate( 'Y' ) - 621 );
	$current_month = 1;
	$current_day = 1;
}
?>
<section class="dzn-tp-reschedule" data-dzn-highlight="reschedule" aria-labelledby="tp-reschedule-<?php echo esc_attr( $index ); ?>">
	<h4 id="tp-reschedule-<?php echo esc_attr( $index ); ?>"><?php echo $replacement ? 'ثبت جلسهٔ جبرانی' : 'تغییر زمان کلاس'; ?></h4>
	<p class="dzn-tp-detail-help"><?php echo $replacement ? 'این جلسه برگزار نشده است؛ زمان جلسهٔ جبرانی را انتخاب کنید. جلسهٔ اصلی و شاهد غیبت حفظ می‌شوند و یک جلسهٔ جبرانی به پروندهٔ ترم اضافه می‌شود.' : 'زمان فعلی:'; ?> <strong><?php echo esc_html( (string) ( $item['reschedule_current'] ?? $item['schedule_summary'] ?? '' ) ); ?></strong></p>
	<p class="dzn-tp-detail-help dzn-tp-reschedule__calendar">تقویم انتخابی: <strong><?php echo 'persian' === $calendar ? 'شمسی' : 'میلادی'; ?></strong> · منطقهٔ زمانی: <strong><?php echo esc_html( $timezone ); ?></strong></p>
	<?php dzn_theme_teacher_portal_component( 'help-tip', array( 'title' => 'زمان فقط پس از پذیرش هنرجو تغییر می‌کند', 'text' => 'ارسال این فرم پیشنهاد است، نه تغییر قطعی. اگر هنرجو رد کند، زمان فعلی حفظ می‌شود و می‌توانید زمان تازه پیشنهاد دهید.', 'module' => 'reschedule' ) ); ?>
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
			<?php $min_date = function_exists( 'dzn_theme_platform_local_date_key' ) ? dzn_theme_platform_local_date_key( gmdate( 'Y-m-d H:i:s' ), $timezone ) : gmdate( 'Y-m-d' ); ?>
			<label>تاریخ جدید<input type="date" name="local_date" required min="<?php echo esc_attr( $min_date ); ?>"></label>
		<?php endif; ?>
		<label>ساعت شروع<input type="time" name="local_time" required></label>
		<p class="dzn-tp-detail-help">اگر زمان انتخابی خارج از ساعات در دسترس ثبت‌شدهٔ شماست، درخواست به‌طور خودکار ارسال نمی‌شود. برای ادامه، گزینهٔ زیر را انتخاب کنید.</p>
		<label class="dzn-tp-reschedule__confirm"><input type="checkbox" name="confirm_outside_availability" value="1"> تأیید زمان خارج از ساعات در دسترس — می‌خواهم این زمان برای این تاریخ به‌صورت استثنایی ثبت شود.</label>
		<button class="dzn-button dzn-button--secondary" type="submit" data-dzn-tp-busy-label="در حال ارسال درخواست…"><?php echo $replacement ? 'ارسال زمان جلسهٔ جبرانی' : 'ارسال درخواست تغییر زمان'; ?></button>
	</form>
	<?php if ( $demo ) : ?><p class="dzn-tp-action-status" role="status" aria-live="polite"></p><?php endif; ?>
</section>
