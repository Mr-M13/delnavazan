<?php
/**
 * Student-owned profile and presentation preferences.
 *
 * @package DelnavazanTheme
 */

$profile = isset( $args['profile'] ) && is_array( $args['profile'] ) ? $args['profile'] : array();
$status = sanitize_key( (string) ( $_GET['profile_status'] ?? '' ) );
$monogram = 'ه';
if ( ! empty( $profile['full_name'] ) && preg_match( '/^./u', (string) $profile['full_name'], $monogram_match ) ) {
	$monogram = $monogram_match[0];
}
$calendar_preference = (string) ( $profile['calendar_preference'] ?? 'auto' );
?>
<section class="dzn-portal-section dzn-profile" aria-labelledby="dzn-profile-title">
	<div class="dzn-portal-section__heading">
		<div><p class="dzn-portal-kicker"><?php esc_html_e( 'من کی هستم', 'delnavazan-theme' ); ?></p><h2 id="dzn-profile-title"><?php esc_html_e( 'مشخصات شخصی', 'delnavazan-theme' ); ?></h2></div>
	</div>
	<?php if ( 'profile_saved' === $status ) : ?><p class="dzn-portal-action-status" role="status"><?php esc_html_e( 'تغییرات حساب ذخیره شد.', 'delnavazan-theme' ); ?></p><?php elseif ( 'profile_unavailable' === $status ) : ?><p class="dzn-portal-action-status" role="alert"><?php esc_html_e( 'ذخیرهٔ تغییرات انجام نشد. لطفاً مقادیر را بررسی کنید.', 'delnavazan-theme' ); ?></p><?php endif; ?>
	<div class="dzn-profile__summary">
		<div class="dzn-profile__monogram" aria-hidden="true"><?php echo esc_html( $monogram ); ?></div>
		<div><h3><?php echo esc_html( $profile['full_name'] ?? '' ); ?></h3><p><?php esc_html_e( 'هنرجوی دلنوازان', 'delnavazan-theme' ); ?></p></div>
	</div>
	<form class="dzn-profile__fields" method="post" action="<?php echo esc_url( (string) ( $profile['action_url'] ?? '' ) ); ?>" aria-labelledby="dzn-profile-title">
		<?php wp_nonce_field( 'dzn_student_profile_update' ); ?>
		<div class="dzn-portal-control">
			<label for="dzn-profile-email"><?php esc_html_e( 'ایمیل', 'delnavazan-theme' ); ?></label>
			<input id="dzn-profile-email" class="is-readonly" type="email" dir="ltr" readonly aria-describedby="dzn-profile-note" aria-disabled="true" value="<?php echo esc_attr( $profile['email'] ?? '' ); ?>">
		</div>
		<div class="dzn-portal-control">
			<label for="dzn-profile-phone"><?php esc_html_e( 'شمارهٔ همراه', 'delnavazan-theme' ); ?></label>
			<input id="dzn-profile-phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" name="phone" value="<?php echo esc_attr( $profile['mobile'] ?? '' ); ?>">
		</div>
		<div class="dzn-portal-control">
			<label for="dzn-profile-whatsapp"><?php esc_html_e( 'شمارهٔ واتس‌اپ', 'delnavazan-theme' ); ?></label>
			<input id="dzn-profile-whatsapp" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" name="whatsapp_phone" value="<?php echo esc_attr( $profile['whatsapp_phone'] ?? '' ); ?>">
		</div>
		<div class="dzn-portal-control">
			<label for="dzn-profile-timezone"><?php esc_html_e( 'منطقهٔ زمانی', 'delnavazan-theme' ); ?></label>
			<select id="dzn-profile-timezone" name="timezone" required><?php echo wp_timezone_choice( (string) ( $profile['timezone'] ?? 'UTC' ), get_user_locale() ); ?></select>
			<p class="dzn-portal-help"><?php esc_html_e( 'ما زمان کلاس‌ها را با زمان شما و زمان هنرجو/مدرس هماهنگ می‌کنیم؛ تغییر منطقهٔ زمانی فقط نمایش را عوض می‌کند.', 'delnavazan-theme' ); ?></p>
		</div>
		<div class="dzn-portal-control">
			<label for="dzn-profile-calendar"><?php esc_html_e( 'تقویم نمایش', 'delnavazan-theme' ); ?></label>
			<select id="dzn-profile-calendar" name="calendar_preference">
				<option value="auto" <?php selected( $calendar_preference, 'auto' ); ?>><?php esc_html_e( 'خودکار (میلادی)', 'delnavazan-theme' ); ?></option>
				<option value="gregorian" <?php selected( $calendar_preference, 'gregorian' ); ?>><?php esc_html_e( 'میلادی', 'delnavazan-theme' ); ?></option>
				<option value="persian" <?php selected( $calendar_preference, 'persian' ); ?>><?php esc_html_e( 'شمسی', 'delnavazan-theme' ); ?></option>
			</select>
		</div>
		<div class="dzn-profile__actions">
			<p id="dzn-profile-note" class="dzn-portal-help"><?php esc_html_e( 'ایمیل حساب قابل تغییر نیست. شماره تماس، واتس‌اپ، منطقهٔ زمانی و تقویم نمایش را می‌توانید خودتان به‌روز کنید.', 'delnavazan-theme' ); ?></p>
			<button class="dzn-button" type="submit"><?php esc_html_e( 'ذخیرهٔ تغییرات', 'delnavazan-theme' ); ?></button>
			<?php if ( ! empty( $profile['password_url'] ) ) : ?><a class="dzn-portal-text-action" href="<?php echo esc_url( $profile['password_url'] ); ?>"><?php esc_html_e( 'تغییر یا بازیابی رمز عبور', 'delnavazan-theme' ); ?></a><?php endif; ?>
		</div>
	</form>
</section>
