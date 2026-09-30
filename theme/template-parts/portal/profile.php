<?php
/**
 * Personal details and preference presentation.
 *
 * @package DelnavazanTheme
 */

$profile = isset( $args['profile'] ) && is_array( $args['profile'] ) ? $args['profile'] : array();
$monogram = 'ه';
if ( ! empty( $profile['full_name'] ) && preg_match( '/^./u', (string) $profile['full_name'], $monogram_match ) ) {
	$monogram = $monogram_match[0];
}
?>
<section class="dzn-portal-section dzn-profile" aria-labelledby="dzn-profile-title">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php esc_html_e( 'من کی هستم', 'delnavazan-theme' ); ?></p>
			<h2 id="dzn-profile-title"><?php esc_html_e( 'مشخصات شخصی', 'delnavazan-theme' ); ?></h2>
		</div>
	</div>
	<div class="dzn-profile__summary">
		<div class="dzn-profile__monogram" aria-hidden="true"><?php echo esc_html( $monogram ); ?></div>
		<div>
			<h3><?php echo esc_html( $profile['full_name'] ?? '' ); ?></h3>
			<p><?php esc_html_e( 'هنرجوی دلنوازان', 'delnavazan-theme' ); ?></p>
		</div>
	</div>
	<div class="dzn-profile__fields" role="group" aria-labelledby="dzn-profile-title">
		<div class="dzn-portal-control">
			<label for="dzn-profile-email"><?php esc_html_e( 'ایمیل', 'delnavazan-theme' ); ?></label>
			<input id="dzn-profile-email" type="email" dir="ltr" value="<?php echo esc_attr( $profile['email'] ?? '' ); ?>">
		</div>
		<div class="dzn-portal-control">
			<label for="dzn-profile-mobile"><?php esc_html_e( 'شمارهٔ واتساپ / همراه', 'delnavazan-theme' ); ?></label>
			<input id="dzn-profile-mobile" type="tel" dir="ltr" value="<?php echo esc_attr( $profile['mobile'] ?? '' ); ?>">
		</div>
		<div class="dzn-portal-control">
			<label for="dzn-profile-timezone"><?php esc_html_e( 'منطقهٔ زمانی', 'delnavazan-theme' ); ?></label>
			<select id="dzn-profile-timezone" data-timezone-value="<?php echo esc_attr( $profile['timezone'] ?? '' ); ?>">
				<option value="<?php echo esc_attr( $profile['timezone'] ?? '' ); ?>"><?php echo esc_html( $profile['timezone_label'] ?? 'منطقهٔ زمانی انتخاب نشده' ); ?></option>
			</select>
			<p class="dzn-portal-help"><?php esc_html_e( 'تغییر این ترجیح فقط شیوهٔ نمایش ساعت را عوض می‌کند؛ زمان کلاس را جابه‌جا نمی‌کند.', 'delnavazan-theme' ); ?></p>
		</div>
		<div class="dzn-profile__actions">
			<button class="dzn-button" type="button" data-dzn-presentation-action aria-describedby="dzn-profile-status"><?php esc_html_e( 'ذخیره در آینده', 'delnavazan-theme' ); ?></button>
			<?php if ( ! empty( $profile['password_url'] ) ) : ?>
				<a class="dzn-portal-text-action" href="<?php echo esc_url( $profile['password_url'] ); ?>"><?php esc_html_e( 'تغییر یا بازیابی رمز عبور', 'delnavazan-theme' ); ?></a>
			<?php else : ?>
				<button class="dzn-portal-text-action" type="button" disabled><?php esc_html_e( 'تغییر رمز پس از اتصال امن', 'delnavazan-theme' ); ?></button>
			<?php endif; ?>
			<p id="dzn-profile-status" class="dzn-portal-action-status" aria-live="polite"></p>
		</div>
	</div>
</section>
