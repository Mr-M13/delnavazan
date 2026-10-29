<?php
/**
 * Teacher recording-terms acceptance.
 *
 * The form is rendered only when an active Teacher link and a current approved
 * publication exist. No historic Teacher is treated as accepted.
 *
 * @package DelnavazanTheme
 */

$recording = isset( $args['recording'] ) && is_array( $args['recording'] ) ? $args['recording'] : array();
if ( ! $recording ) {
	return;
}
$available = ! empty( $recording['available'] );
$linked = ! empty( $recording['linked'] );
$accepted = ! empty( $recording['accepted'] );
$notices = isset( $recording['notices'] ) && is_array( $recording['notices'] ) ? $recording['notices'] : array();
$information_url = (string) ( $recording['information_url'] ?? home_url( '/recording-and-research/' ) );
$status = sanitize_key( (string) ( $recording['status'] ?? '' ) );
$language_label = static function ( string $language ): string { return 'fa' === $language ? 'فارسی' : 'English'; };
$format_date = static function ( string $value ): string {
	if ( '' === $value ) { return ''; }
	$timestamp = strtotime( $value . ' UTC' );
	return $timestamp ? wp_date( 'Y-m-d H:i', $timestamp ) : $value;
};
?>
<section class="dzn-tp-section dzn-teacher-recording-terms" id="dzn-teacher-recording-terms" aria-labelledby="tp-recording-terms">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">شرایط ضبط برای مدرسان</p><h2 id="tp-recording-terms">Teacher recording terms</h2></div>
		<span><?php echo $accepted ? 'پذیرفته شده' : 'نیازمند بررسی'; ?></span>
	</div>
	<?php if ( 'terms_saved' === $status ) : ?><p class="dzn-tp-notice is-success" role="status"><span lang="en" dir="ltr">Teacher recording terms accepted.</span> <span lang="fa" dir="rtl">شرایط ضبط برای مدرسان پذیرفته شد.</span></p><?php elseif ( 'terms_unavailable' === $status ) : ?><p class="dzn-tp-notice is-error" role="alert"><span lang="en" dir="ltr">The terms could not be accepted. No record was created.</span> <span lang="fa" dir="rtl">پذیرش شرایط ثبت نشد؛ هیچ سابقه‌ای ساخته نشد.</span></p><?php endif; ?>
	<?php if ( ! $linked ) : ?>
		<p class="dzn-tp-help" role="status">Teacher recording terms are available only to an active Teacher account. No acceptance has been recorded. · این بخش فقط برای حساب مدرس فعال در دسترس است.</p>
	<?php elseif ( ! $available || ! $notices ) : ?>
		<p class="dzn-tp-help" role="status"><strong>Acceptance is unavailable.</strong> No approved teacher-recording terms publication is currently available, so no record can be created. · پذیرش شرایط در دسترس نیست؛ هیچ سابقه‌ای ساخته نمی‌شود.</p>
	<?php else : ?>
		<?php if ( $accepted && ! empty( $recording['accepted_at'] ) ) : ?>
			<p class="dzn-tp-notice is-success">Recorded: <time datetime="<?php echo esc_attr( (string) $recording['accepted_at'] ); ?>"><?php echo esc_html( $format_date( (string) $recording['accepted_at'] ) ); ?></time> · version <?php echo esc_html( (string) ( $recording['notice_version'] ?? '' ) ); ?> · <?php echo esc_html( $language_label( (string) ( $recording['language'] ?? 'en' ) ) ); ?></p>
		<?php else : ?>
			<p class="dzn-tp-help">Read the approved publication below and accept it explicitly. Accepting one language version records that version; it does not backfill any historic Teacher record.</p>
		<?php endif; ?>
		<?php foreach ( $notices as $notice ) : ?>
			<?php if ( ! is_array( $notice ) ) { continue; } ?>
			<article class="dzn-teacher-recording-terms__notice">
				<h3><?php echo esc_html( $language_label( (string) ( $notice['language'] ?? 'en' ) ) ); ?> · version <?php echo esc_html( (string) ( $notice['notice_version'] ?? '' ) ); ?></h3>
				<div lang="<?php echo 'fa' === ( $notice['language'] ?? 'en' ) ? 'fa' : 'en'; ?>" dir="<?php echo 'fa' === ( $notice['language'] ?? 'en' ) ? 'rtl' : 'ltr'; ?>">
					<?php echo wp_kses_post( wpautop( (string) ( $notice['body'] ?? '' ) ) ); ?>
				</div>
				<?php if ( ! $accepted || (int) ( $recording['notice_version'] ?? 0 ) !== (int) ( $notice['notice_version'] ?? 0 ) || (string) ( $recording['language'] ?? '' ) !== (string) ( $notice['language'] ?? '' ) ) : ?>
					<form class="dzn-tp-form" method="post" action="<?php echo esc_url( (string) ( $notice['action_url'] ?? '' ) ); ?>">
						<?php dzn_theme_nonce_field( 'dzn_recording_teacher_terms' ); ?>
						<input type="hidden" name="notice_uid" value="<?php echo esc_attr( (string) ( $notice['notice_uid'] ?? '' ) ); ?>">
						<input type="hidden" name="language" value="<?php echo esc_attr( (string) ( $notice['language'] ?? '' ) ); ?>">
						<input type="hidden" name="idempotency_key" value="<?php echo esc_attr( (string) ( $notice['idempotency_key'] ?? '' ) ); ?>">
						<input type="hidden" name="return_to" value="<?php echo esc_attr( (string) ( $notice['return_to'] ?? 'onboarding' ) ); ?>">
						<button class="dzn-button" type="submit">Accept these teacher recording terms · پذیرش این شرایط</button>
					</form>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	<?php endif; ?>
	<p><a href="<?php echo esc_url( $information_url ); ?>">Recording and research information · اطلاعات ضبط و پژوهش</a></p>
</section>
