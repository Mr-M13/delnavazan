<?php
/**
 * Teacher unified consent: one line covering terms, recording, research and privacy.
 *
 * Renders a single pre-checked checkbox only when an active Teacher link and current
 * approved publications exist. No historic Teacher is treated as accepted.
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
$terms_state = isset( $recording['terms'] ) && is_array( $recording['terms'] ) ? $recording['terms'] : array();
$information_url = (string) ( $recording['information_url'] ?? home_url( '/recording-and-research/' ) );
$status = sanitize_key( (string) ( $recording['status'] ?? '' ) );
$format_date = static function ( string $value ): string {
	if ( '' === $value ) { return ''; }
	$timestamp = strtotime( $value . ' UTC' );
	return $timestamp ? wp_date( 'Y-m-d H:i', $timestamp ) : $value;
};
$iso_datetime = static function ( string $value ): string { return '' === $value ? '' : str_replace( ' ', 'T', $value ) . 'Z'; };
$status_messages = array(
	'unified_saved' => array( 'success', 'Teaching terms, lesson recording and privacy acknowledgement were saved. Research participation is optional.', 'شرایط تدریس، رضایت ضبط و تأیید حریم خصوصی ثبت شد. مشارکت در پژوهش اختیاری است.' ),
	'terms_unavailable' => array( 'error', 'Consent was not fully saved. Review the current decisions before retrying.', 'ثبت رضایت کامل نشد. پیش از تلاش دوباره، تصمیم‌های ثبت‌شده را بررسی کنید.' ),
);
$unified = isset( $recording['unified'] ) && is_array( $recording['unified'] ) ? $recording['unified'] : array();
$mandatory_accepted = ! empty( $unified['mandatory_accepted'] ) || ! empty( $recording['mandatory_accepted'] );
$unified_accepted = ! empty( $unified['consent_active'] ) || ! empty( $recording['unified_accepted'] );
$research_opted_in = ! empty( $recording['research_opted_in'] );
$research_notices = (array) ( $recording['forms']['research'] ?? array() );
$research_form = $research_notices[0] ?? null;
$policy_urls = isset( $recording['policy_urls'] ) && is_array( $recording['policy_urls'] ) ? $recording['policy_urls'] : array(
	'privacy' => home_url( '/privacy-policy/' ),
	'recording_ai' => home_url( '/recording-and-ai-policy/' ),
	'terms' => home_url( '/terms-and-conditions/' ),
	'recording_research' => home_url( '/recording-and-research/' ),
);
$unified_available = ! empty( $unified['available'] );
?>
<section class="dzn-tp-section dzn-teacher-recording-terms" id="dzn-teacher-recording-terms" aria-labelledby="tp-recording-terms">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">حریم خصوصی، ضبط و پژوهش</p><h2 id="tp-recording-terms">Consent and recording</h2></div>
		<span><?php echo esc_html( $unified_accepted ? 'شرایط تدریس و حریم خصوصی ثبت شده' : ( $mandatory_accepted ? 'شرایط تدریس پذیرفته شده؛ پژوهش اختیاری است' : 'نیازمند بررسی' ) ); ?></span>
	</div>
	<?php if ( isset( $status_messages[ $status ] ) ) : ?>
		<?php $message = $status_messages[ $status ]; ?>
		<p class="dzn-tp-notice<?php echo 'error' === $message[0] ? ' is-error' : ' is-success'; ?>" role="<?php echo 'error' === $message[0] ? 'alert' : 'status'; ?>"><span lang="en" dir="ltr"><?php echo esc_html( $message[1] ); ?></span> <span lang="fa" dir="rtl"><?php echo esc_html( $message[2] ); ?></span></p>
	<?php endif; ?>
	<?php if ( ! $linked ) : ?>
		<p class="dzn-tp-help" role="status">این بخش فقط برای حساب مدرس فعال در دسترس است. هیچ رضایتی ثبت نشده است. · Teacher consent is available only to an active Teacher account. No consent has been recorded.</p>
	<?php elseif ( $unified_available ) : ?>
		<?php if ( $unified_accepted ) : ?>
			<p class="dzn-tp-help" role="status"><strong>شرایط تدریس و حریم خصوصی ثبت شده است.</strong> مشارکت در پژوهش اختیاری است<?php echo ! empty( $terms_state['decided_at'] ) ? ' · ' . esc_html( $format_date( (string) $terms_state['decided_at'] ) ) : ''; ?>. · Consent saved on <time datetime="<?php echo esc_attr( $iso_datetime( (string) ( $terms_state['decided_at'] ?? '' ) ) ); ?>"><?php echo esc_html( $format_date( (string) ( $terms_state['decided_at'] ?? '' ) ) ); ?></time></p>
		<?php else : ?>
			<form class="dzn-tp-form" method="post" action="<?php echo esc_url( (string) ( $unified['action_url'] ?? '' ) ); ?>">
				<?php dzn_theme_nonce_field( 'dzn_unified_teacher_consent' ); ?>
				<input type="hidden" name="idempotency_key" value="<?php echo esc_attr( (string) ( $unified['idempotency_key'] ?? '' ) ); ?>">
				<input type="hidden" name="return_to" value="<?php echo esc_attr( (string) ( $unified['return_to'] ?? 'onboarding' ) ); ?>">
				<label class="dzn-tp-unified-consent"><input type="checkbox" name="unified_agree" value="1" checked required> <span>I accept the <a href="<?php echo esc_url( $policy_urls['privacy'] ); ?>" target="_blank" rel="noopener">Privacy Policy</a>, <a href="<?php echo esc_url( $policy_urls['terms'] ); ?>" target="_blank" rel="noopener">Terms and Conditions</a>, and the <a href="<?php echo esc_url( $policy_urls['recording_ai'] ); ?>" target="_blank" rel="noopener">Recording &amp; AI Policy</a>: my classes may be recorded for teaching purposes. Separate optional consent is required for research and AI model training. Please read them fully before continuing. · <span lang="fa" dir="rtl">سیاست حریم خصوصی، شرایط و ضوابط و سیاست ضبط و هوش مصنوعی را می‌پذیرم؛ کلاس‌هایم می‌توانند برای اهداف آموزشی ضبط شوند. استفاده پژوهشی و آموزش هوش مصنوعی نیازمند رضایت اختیاری جداگانه است. لطفاً پیش از ادامه آن‌ها را کامل بخوانید.</span></span></label>
				<p class="dzn-tp-help"><strong>پذیرش شرایط تدریس و ضبط الزامی است؛ مشارکت در پژوهش اختیاری است.</strong> بدون آن نمی‌توانید در دلنوازان کار کنید و ضبط کلاس انجام نمی‌شود. · <strong>Teaching terms and recording consent are mandatory to work; research participation is optional.</strong></p>
				<?php if ( $research_form && ! $research_opted_in ) : ?>
                    <label><input type="checkbox" name="research_opt_in" value="1"> Optional: I separately consent to research and AI model training. · موافقت جداگانه و اختیاری با پژوهش و آموزش هوش مصنوعی</label>
                <?php endif; ?>
				<button class="dzn-button" type="submit">I agree · موافقم</button>
			</form>
		<?php endif; ?>
	<?php elseif ( ! $available || ! $unified_available ) : ?>
		<p class="dzn-tp-help" role="status"><strong>ثبت رضایت در دسترس نیست.</strong> اطلاعیهٔ تأییدشدهٔ شرایط کار ضبط‌شده منتشر نشده است؛ هیچ سابقه‌ای ساخته نمی‌شود.</p>
	<?php else : ?>
		<p class="dzn-tp-help">رضایت یکپارچه در دسترس نیست. · Unified consent unavailable.</p>
	<?php endif; ?>
    <?php if ( $unified_accepted ) : ?>
        <p role="status"><?php echo esc_html( $research_opted_in ? 'Research opt-in recorded · رضایت پژوهش ثبت شده است.' : 'Not opted in to research · مشارکت در پژوهش انتخاب نشده است.' ); ?></p>
        <?php if ( is_array( $research_form ) && ! empty( $research_form['notice_uid'] ) ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=dzn_recording_teacher_choice' ) ); ?>">
                <?php dzn_theme_nonce_field( 'dzn_recording_teacher_choice' ); ?>
                <input type="hidden" name="return_to" value="<?php echo esc_attr( $unified['return_to'] ?? 'settings' ); ?>">
                <input type="hidden" name="purpose" value="teacher_research_model_training">
                <input type="hidden" name="choice" value="<?php echo esc_attr( $research_opted_in ? 'withdraw' : 'opt_in' ); ?>">
                <input type="hidden" name="notice_uid" value="<?php echo esc_attr( $research_form['notice_uid'] ); ?>">
                <input type="hidden" name="language" value="<?php echo esc_attr( $research_form['language'] ); ?>">
                <input type="hidden" name="idempotency_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
                <button class="dzn-button dzn-button--secondary" type="submit"><?php echo esc_html( $research_opted_in ? 'Withdraw research consent · لغو رضایت پژوهش' : 'Opt in to research · موافقت با پژوهش' ); ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
	<p><a href="<?php echo esc_url( $information_url ); ?>" target="_blank" rel="noopener">Recording and research information · اطلاعات ضبط و پژوهش</a></p>
</section>
