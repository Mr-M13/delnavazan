<?php
/**
 * Teacher consent: recorded-work terms, lesson recording and machine-learning research.
 *
 * Every form is rendered only when an active Teacher link and a current approved publication
 * exist for that independent purpose. No historic Teacher is treated as accepted.
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
$forms = isset( $recording['forms'] ) && is_array( $recording['forms'] ) ? $recording['forms'] : array();
$recording_state = isset( $recording['recording'] ) && is_array( $recording['recording'] ) ? $recording['recording'] : array();
$research_state = isset( $recording['research'] ) && is_array( $recording['research'] ) ? $recording['research'] : array();
$terms_state = isset( $recording['terms'] ) && is_array( $recording['terms'] ) ? $recording['terms'] : array();
$information_url = (string) ( $recording['information_url'] ?? home_url( '/recording-and-research/' ) );
$status = sanitize_key( (string) ( $recording['status'] ?? '' ) );
$language_label = static function ( string $language ): string { return 'fa' === $language ? 'فارسی' : 'English'; };
$format_date = static function ( string $value ): string {
	if ( '' === $value ) { return ''; }
	$timestamp = strtotime( $value . ' UTC' );
	return $timestamp ? wp_date( 'Y-m-d H:i', $timestamp ) : $value;
};
$iso_datetime = static function ( string $value ): string { return '' === $value ? '' : str_replace( ' ', 'T', $value ) . 'Z'; };
$decision_label = static function ( string $purpose, array $state ): string {
	$decision = (string) ( $state['decision'] ?? '' );
	if ( 'teacher_terms' === $purpose ) {
		return 'accepted' === $decision ? 'پذیرفته شده' : 'پذیرفته نشده';
	}
	if ( 'teacher_lesson_recording' === $purpose ) {
		return 'allow' === $decision ? 'ضبط کلاس مجاز است' : ( 'decline' === $decision ? 'ضبط کلاس مجاز نیست' : 'تصمیمی ثبت نشده است' );
	}
	return 'opt_in' === $decision ? 'مشارکت در پژوهش: فعال' : ( 'withdraw' === $decision ? 'مشارکت در پژوهش: پس گرفته شده' : 'تصمیمی ثبت نشده است' );
};
$status_messages = array(
	'terms_saved' => array( 'success', 'Teacher recorded-work terms accepted.', 'شرایط کار ضبط‌شده پذیرفته شد.' ),
	'teacher_recording_saved' => array( 'success', 'Teacher recording choice saved.', 'انتخاب ضبط کلاس مدرس ذخیره شد.' ),
	'teacher_recording_declined' => array( 'success', 'Your lessons will not be recorded.', 'کلاس‌های شما ضبط نخواهد شد.' ),
	'teacher_research_saved' => array( 'success', 'Teacher research choice saved.', 'انتخاب پژوهش مدرس ذخیره شد.' ),
	'teacher_research_withdrawn' => array( 'success', 'Research participation was withdrawn.', 'مشارکت در پژوهش پس گرفته شد.' ),
	'privacy_saved' => array( 'success', 'Privacy acknowledgement saved.', 'تأیید حریم خصوصی ذخیره شد.' ),
	'terms_unavailable' => array( 'error', 'The choice could not be saved. No record was created.', 'انتخاب ذخیره نشد؛ هیچ سابقه‌ای ساخته نشد.' ),
);
$renderPurpose = static function ( string $title, string $faTitle, string $purpose, array $formsForPurpose, array $state, array $choices ) use ( $language_label, $decision_label, $format_date, $iso_datetime ): void {
	echo '<article class="dzn-teacher-recording-terms__notice" id="dzn-teacher-consent-'.esc_attr( $purpose ).'">';
	echo '<h3>' . esc_html( $title ) . ' <span lang="fa" dir="rtl">· ' . esc_html( $faTitle ) . '</span></h3>';
	echo '<p class="dzn-tp-help"><strong>' . esc_html( $decision_label( $purpose, $state ) ) . '</strong>';
	if ( ! empty( $state['decided_at'] ) ) {
		echo ' · <time datetime="' . esc_attr( $iso_datetime( (string) $state['decided_at'] ) ) . '">' . esc_html( $format_date( (string) $state['decided_at'] ) ) . '</time>';
	}
	if ( ! empty( $state['notice_version'] ) ) {
		echo ' · ' . esc_html( $language_label( (string) ( $state['language'] ?? 'en' ) ) ) . ' v' . esc_html( (string) $state['notice_version'] );
	}
	echo '</p>';
	if ( $formsForPurpose === array() ) {
		echo '<p class="dzn-tp-help" role="status">اطلاعیهٔ تأییدشدهٔ این موضوع منتشر نشده است؛ هیچ تصمیمی ثبت نمی‌شود. · No approved publication is available for this purpose, so no decision can be recorded.</p></article>';
		return;
	}
	foreach ( $formsForPurpose as $form ) {
		if ( ! is_array( $form ) ) {
			continue;
		}
		$language = (string) ( $form['language'] ?? 'en' );
		echo '<div class="dzn-teacher-recording-terms__language">';
		echo '<p class="dzn-tp-help"><strong>' . esc_html( $language_label( $language ) ) . ' · v' . esc_html( (string) ( $form['notice_version'] ?? '' ) ) . '</strong></p>';
		echo '<div lang="' . ( 'fa' === $language ? 'fa' : 'en' ) . '" dir="' . ( 'fa' === $language ? 'rtl' : 'ltr' ) . '">' . wp_kses_post( wpautop( (string) ( $form['body'] ?? '' ) ) ) . '</div>';
		echo '<form class="dzn-tp-form" method="post" action="' . esc_url( (string) ( $form['action_url'] ?? '' ) ) . '">';
		if ( 'teacher_terms' === $purpose ) {
			dzn_theme_nonce_field( 'dzn_recording_teacher_terms' );
		} else {
			dzn_theme_nonce_field( 'dzn_recording_teacher_choice' );
		}
		echo '<input type="hidden" name="purpose" value="' . esc_attr( $purpose ) . '">';
		echo '<input type="hidden" name="notice_uid" value="' . esc_attr( (string) ( $form['notice_uid'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="language" value="' . esc_attr( $language ) . '">';
		echo '<input type="hidden" name="idempotency_key" value="' . esc_attr( (string) ( $form['idempotency_key'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="return_to" value="' . esc_attr( (string) ( $form['return_to'] ?? 'onboarding' ) ) . '">';
		echo '<fieldset><legend>انتخاب شما · Your choice</legend>';
		foreach ( $choices as $value => $label ) {
			$id = 'dzn-consent-' . $purpose . '-' . $language . '-' . $value;
			echo '<label for="' . esc_attr( $id ) . '"><input type="radio" id="' . esc_attr( $id ) . '" name="choice" value="' . esc_attr( $value ) . '" required> ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset><p class="dzn-tp-help">هیچ گزینه‌ای از پیش انتخاب نشده است. هر انتخاب یک سابقهٔ تازه می‌سازد و تصمیم‌های قبلی را بازنویسی نمی‌کند.</p>';
		echo '<button class="dzn-button" type="submit">ذخیرهٔ انتخاب · Save choice</button></form></div>';
	}
	echo '</article>';
};
?>
<section class="dzn-tp-section dzn-teacher-recording-terms" id="dzn-teacher-recording-terms" aria-labelledby="tp-recording-terms">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">حریم خصوصی، ضبط و پژوهش</p><h2 id="tp-recording-terms">Consent and recording</h2></div>
		<span><?php echo esc_html( $accepted ? 'شرایط پذیرفته شده' : ( ( $available && $forms ) ? 'نیازمند بررسی' : 'ثبت نشده' ) ); ?></span>
	</div>
	<?php if ( isset( $status_messages[ $status ] ) ) : ?>
		<?php $message = $status_messages[ $status ]; ?>
		<p class="dzn-tp-notice<?php echo 'error' === $message[0] ? ' is-error' : ' is-success'; ?>" role="<?php echo 'error' === $message[0] ? 'alert' : 'status'; ?>"><span lang="en" dir="ltr"><?php echo esc_html( $message[1] ); ?></span> <span lang="fa" dir="rtl"><?php echo esc_html( $message[2] ); ?></span></p>
	<?php endif; ?>
	<?php if ( ! $linked ) : ?>
		<p class="dzn-tp-help" role="status">این بخش فقط برای حساب مدرس فعال در دسترس است. هیچ رضایتی ثبت نشده است. · Teacher consent is available only to an active Teacher account. No consent has been recorded.</p>
	<?php elseif ( ! $available || ! $forms ) : ?>
		<p class="dzn-tp-help" role="status"><strong>ثبت رضایت در دسترس نیست.</strong> اطلاعیهٔ تأییدشدهٔ شرایط کار ضبط‌شده منتشر نشده است؛ هیچ سابقه‌ای ساخته نمی‌شود.</p>
	<?php else : ?>
		<p class="dzn-tp-help">این سه تصمیم جداگانه‌اند: شرایط کار ضبط‌شده، اجازهٔ ضبط کلاس، و مشارکت اختیاری در پژوهش و آموزش مدل. اجازهٔ ضبط هیچ‌گاه به معنی اجازهٔ پژوهش نیست.</p>
		<?php $renderPurpose( 'Recorded-work terms', 'شرایط کار ضبط‌شده', 'teacher_terms', (array) ( $forms['terms'] ?? array() ), $terms_state, array( 'accepted' => 'این شرایط را می‌پذیرم · I accept these terms' ) ); ?>
		<?php $renderPurpose( 'Lesson recording', 'ضبط کلاس', 'teacher_lesson_recording', (array) ( $forms['recording'] ?? array() ), $recording_state, array( 'allow' => 'ضبط کلاس‌های آینده مجاز است · Allow future lesson recording', 'decline' => 'ضبط کلاس مجاز نیست · Do not record my lessons' ) ); ?>
		<?php $renderPurpose( 'Research and model training', 'پژوهش و آموزش مدل', 'teacher_research_model_training', (array) ( $forms['research'] ?? array() ), $research_state, array( 'opt_in' => 'در پژوهش مشارکت می‌کنم · I opt in to research', 'withdraw' => 'مشارکت نمی‌کنم · I do not participate' ) ); ?>
		<?php if ( ! empty( $forms['privacy'] ) ) : ?>
			<?php $renderPurpose( 'Privacy acknowledgement', 'تأیید حریم خصوصی', 'privacy_terms', (array) $forms['privacy'], array(), array( 'accepted' => 'این نسخه را تأیید می‌کنم · I acknowledge this version' ) ); ?>
		<?php endif; ?>
	<?php endif; ?>
	<p><a href="<?php echo esc_url( $information_url ); ?>">Recording and research information · اطلاعات ضبط و پژوهش</a></p>
</section>
