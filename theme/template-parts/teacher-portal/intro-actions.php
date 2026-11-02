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
$min_date = function_exists( 'dzn_theme_platform_local_date_key' ) ? dzn_theme_platform_local_date_key( gmdate( 'Y-m-d H:i:s' ), $timezone ) : gmdate( 'Y-m-d' );
?>
<details class="dzn-tp-intro-actions">
	<summary>مدیریت کلاس آشنایی</summary>
	<div class="dzn-tp-intro-actions__body">
		<section class="dzn-tp-intro-actions__card">
			<h4>تغییر زمان کلاس آشنایی</h4>
			<p class="dzn-tp-help">زمان جدید پس از بررسی تداخل مدرس و هنرجو ثبت می‌شود و به هنرجو اطلاع داده می‌شود.</p>
			<form method="post" action="<?php echo esc_url( (string) ( $item['intro_reschedule_action_url'] ?? '' ) ); ?>" data-dzn-tp-submit>
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['intro_reschedule_nonce'] ?? '' ) ); ?>">
				<input type="hidden" name="confirmation_uid" value="<?php echo esc_attr( (string) ( $item['confirmation_uid'] ?? '' ) ); ?>">
				<input type="hidden" name="reason_code" value="teacher_requested">
				<input type="hidden" name="command_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
				<label>تاریخ جدید<input type="date" name="local_date" required min="<?php echo esc_attr( $min_date ); ?>" value="<?php echo esc_attr( (string) ( $item['intro_local_date'] ?? '' ) ); ?>"></label>
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
