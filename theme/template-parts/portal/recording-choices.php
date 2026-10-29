<?php
/**
 * Student recording and research choices.
 *
 * This surface only renders forms when the authority service has already
 * resolved one active adult Student link and a current published notice.
 * It never renders a representative form or a preselected recording choice.
 *
 * @package DelnavazanTheme
 */

$recording = isset( $args['recording'] ) && is_array( $args['recording'] ) ? $args['recording'] : array();
if ( ! $recording ) {
	return;
}
$context = (string) ( $recording['context'] ?? 'account' );
$context = 'enrolment' === $context ? 'enrolment' : 'account';
$available = ! empty( $recording['available'] );
$forms = isset( $recording['forms'] ) && is_array( $recording['forms'] ) ? $recording['forms'] : array();
$recording_state = isset( $recording['recording'] ) && is_array( $recording['recording'] ) ? $recording['recording'] : array();
$research_state = isset( $recording['research'] ) && is_array( $recording['research'] ) ? $recording['research'] : array();
$recording_notice = isset( $recording['recording_notice'] ) && is_array( $recording['recording_notice'] ) ? $recording['recording_notice'] : null;
$research_notice = isset( $recording['research_notice'] ) && is_array( $recording['research_notice'] ) ? $recording['research_notice'] : null;
$information_url = (string) ( $recording['information_url'] ?? home_url( '/recording-and-research/' ) );
$status = sanitize_key( (string) ( $recording['status'] ?? '' ) );
$messages = array(
	'recording_saved' => array( 'success', 'Your lesson recording choice was saved.', 'انتخاب ضبط کلاس شما ذخیره شد.' ),
	'recording_declined' => array( 'success', 'Your lessons will not be recorded.', 'کلاس‌های شما ضبط نخواهد شد.' ),
	'research_saved' => array( 'success', 'Your research choice was saved.', 'انتخاب پژوهش شما ذخیره شد.' ),
	'research_withdrawn' => array( 'success', 'Research participation was withdrawn.', 'مشارکت در پژوهش پس گرفته شد.' ),
	'recording_unavailable' => array( 'error', 'The choice could not be saved. No recording permission was recorded.', 'انتخاب ذخیره نشد. هیچ اجازهٔ ضبطی ثبت نشد.' ),
);
$provisional_recording = 'Delnavazan may record future online lessons and practice activities to improve teaching and learning. You can choose whether your lessons may be recorded, and you can change this choice later in Account Settings.';
$provisional_research = 'You may separately choose to contribute eligible recordings and related learning data to Persian music research and to the development of future learning-support tools. This choice is optional and can be withdrawn later.';
$current_recording = (string) ( $recording_state['decision'] ?? '' );
$current_research = (string) ( $research_state['decision'] ?? '' );
$decision_label = static function ( string $purpose, string $decision ): string {
	if ( 'lesson_recording' === $purpose ) {
		return 'allow' === $decision ? 'Lesson recording allowed' : ( 'decline' === $decision ? 'Lesson recording declined' : 'No recording decision recorded' );
	}
	return 'opt_in' === $decision ? 'Research participation: opted in' : ( 'withdraw' === $decision ? 'Research participation: withdrawn' : 'No research decision recorded' );
};
$language_label = static function ( string $language ): string { return 'fa' === $language ? 'فارسی' : 'English'; };
$format_date = static function ( string $value ): string {
	if ( '' === $value ) { return ''; }
	$timestamp = strtotime( $value . ' UTC' );
	return $timestamp ? wp_date( 'Y-m-d H:i', $timestamp ) : $value;
};
?>
<section class="dzn-portal-section dzn-recording-choices" id="dzn-recording-choices" aria-labelledby="dzn-recording-choices-title">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php echo 'enrolment' === $context ? 'پیش از تکمیل ثبت‌نام' : 'حساب کاربری'; ?></p>
			<h2 id="dzn-recording-choices-title">Recording and research choices</h2>
			<p class="dzn-portal-help" lang="fa" dir="rtl">انتخاب ضبط کلاس و مشارکت در پژوهش</p>
		</div>
	</div>
	<?php if ( isset( $messages[ $status ] ) ) : ?>
		<?php $message = $messages[ $status ]; ?>
		<p class="dzn-portal-action-status<?php echo 'error' === $message[0] ? ' is-error' : ''; ?>" role="<?php echo 'error' === $message[0] ? 'alert' : 'status'; ?>"><span lang="en" dir="ltr"><?php echo esc_html( $message[1] ); ?></span> <span lang="fa" dir="rtl"><?php echo esc_html( $message[2] ); ?></span></p>
	<?php endif; ?>
	<?php if ( ! $available ) : ?>
		<p class="dzn-portal-help" role="status"><span lang="en" dir="ltr">Self-service recording and research choices are not available for this account. No recording decision has been saved.</span> <span lang="fa" dir="rtl">انتخاب ضبط و پژوهش برای این حساب در دسترس نیست. هیچ تصمیمی دربارهٔ ضبط ثبت نشده است.</span></p>
		<p><a href="<?php echo esc_url( $information_url ); ?>">Recording and research information · اطلاعات ضبط و پژوهش</a></p>
		<?php return; ?>
	<?php endif; ?>

	<div class="dzn-recording-choices__current">
		<p><strong><?php echo esc_html( $decision_label( 'lesson_recording', $current_recording ) ); ?></strong>
			<?php if ( ! empty( $recording_state['decided_at'] ) ) : ?>· <time datetime="<?php echo esc_attr( (string) $recording_state['decided_at'] ); ?>"><?php echo esc_html( $format_date( (string) $recording_state['decided_at'] ) ); ?></time><?php endif; ?>
			<?php if ( ! empty( $recording_state['notice_version'] ) ) : ?>· <?php echo esc_html( $language_label( (string) ( $recording_state['language'] ?? 'en' ) ) ); ?> v<?php echo esc_html( (string) $recording_state['notice_version'] ); ?><?php endif; ?>
		</p>
		<p><strong><?php echo esc_html( $decision_label( 'research_model_training', $current_research ) ); ?></strong>
			<?php if ( ! empty( $research_state['decided_at'] ) ) : ?>· <time datetime="<?php echo esc_attr( (string) $research_state['decided_at'] ); ?>"><?php echo esc_html( $format_date( (string) $research_state['decided_at'] ) ); ?></time><?php endif; ?>
			<?php if ( ! empty( $research_state['notice_version'] ) ) : ?>· <?php echo esc_html( $language_label( (string) ( $research_state['language'] ?? 'en' ) ) ); ?> v<?php echo esc_html( (string) $research_state['notice_version'] ); ?><?php endif; ?>
		</p>
	</div>

	<?php if ( $recording_notice && isset( $forms['recording'] ) ) : ?>
		<?php $form = $forms['recording']; ?>
		<div class="dzn-recording-choices__notice">
			<p><strong><?php echo esc_html( $language_label( (string) ( $recording_notice['language'] ?? 'en' ) ) ); ?> · version <?php echo esc_html( (string) ( $recording_notice['version'] ?? '' ) ); ?></strong></p>
			<p lang="<?php echo 'fa' === ( $recording_notice['language'] ?? 'en' ) ? 'fa' : 'en'; ?>" dir="<?php echo 'fa' === ( $recording_notice['language'] ?? 'en' ) ? 'rtl' : 'ltr'; ?>"><?php echo esc_html( (string) ( $recording_notice['body'] ?? '' ) ); ?></p>
		</div>
		<form class="dzn-recording-choices__form" method="post" action="<?php echo esc_url( (string) $form['action_url'] ); ?>">
			<?php dzn_theme_nonce_field( 'dzn_recording_student_choice' ); ?>
			<input type="hidden" name="purpose" value="lesson_recording">
			<input type="hidden" name="notice_uid" value="<?php echo esc_attr( (string) $form['notice_uid'] ); ?>">
			<input type="hidden" name="language" value="<?php echo esc_attr( (string) $form['language'] ); ?>">
			<input type="hidden" name="idempotency_key" value="<?php echo esc_attr( (string) $form['idempotency_key'] ); ?>">
			<input type="hidden" name="return_to" value="<?php echo esc_attr( (string) $form['return_to'] ); ?>">
			<fieldset>
				<legend><?php echo 'enrolment' === $context ? 'Choose whether future lessons may be recorded · انتخاب ضبط درس‌های آینده' : 'Change your lesson recording choice · تغییر انتخاب ضبط کلاس'; ?></legend>
				<label><input type="radio" name="choice" value="allow"<?php echo 'account' === $context && 'allow' === $current_recording ? ' checked' : ''; ?>> Allow lesson recording <span lang="fa" dir="rtl">(اجازهٔ ضبط کلاس)</span></label>
				<label><input type="radio" name="choice" value="decline"<?php echo 'account' === $context && 'decline' === $current_recording ? ' checked' : ''; ?>> Do not allow lesson recording <span lang="fa" dir="rtl">(بدون ضبط کلاس)</span></label>
			</fieldset>
			<p class="dzn-portal-help"><?php echo 'enrolment' === $context ? 'No choice is preselected. If you do not choose, no recording decision is saved and future capture remains ineligible.' : 'A new choice is appended to your history; earlier decisions are never rewritten.'; ?></p>
			<button class="dzn-button" type="submit">Save recording choice · ذخیرهٔ انتخاب ضبط</button>
		</form>
	<?php else : ?>
		<p class="dzn-portal-help" role="status">A current approved recording notice is not published. Recording choices cannot be saved. · اطلاعیهٔ تأییدشدهٔ ضبط در دسترس نیست؛ انتخاب ضبط ذخیره نمی‌شود.</p>
		<?php if ( 'enrolment' === $context ) : ?>
			<div class="dzn-recording-choices__draft" role="note">
				<p><strong>Draft product copy — not legally approved yet; choices are unavailable.</strong></p>
				<p lang="en" dir="ltr"><?php echo esc_html( $provisional_recording ); ?></p>
				<p lang="fa" dir="rtl">دلنوازان ممکن است درس‌های آنلاین و تمرین‌های آینده را برای بهبود آموزش و یادگیری ضبط کند. شما می‌توانید انتخاب کنید که درس‌هایتان ضبط شود یا نه و این انتخاب را بعداً در تنظیمات حساب تغییر دهید.</p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<hr>
	<h3>Research and model-training participation <span lang="fa" dir="rtl">· مشارکت در پژوهش</span></h3>
	<?php if ( $research_notice && isset( $forms['research'] ) ) : ?>
		<?php $form = $forms['research']; ?>
		<div class="dzn-recording-choices__notice">
			<p><strong><?php echo esc_html( $language_label( (string) ( $research_notice['language'] ?? 'en' ) ) ); ?> · version <?php echo esc_html( (string) ( $research_notice['version'] ?? '' ) ); ?></strong></p>
			<p lang="<?php echo 'fa' === ( $research_notice['language'] ?? 'en' ) ? 'fa' : 'en'; ?>" dir="<?php echo 'fa' === ( $research_notice['language'] ?? 'en' ) ? 'rtl' : 'ltr'; ?>"><?php echo esc_html( (string) ( $research_notice['body'] ?? '' ) ); ?></p>
		</div>
		<?php if ( 'opt_in' === $current_research ) : ?>
			<form class="dzn-recording-choices__form" method="post" action="<?php echo esc_url( (string) $form['action_url'] ); ?>">
				<?php dzn_theme_nonce_field( 'dzn_recording_student_choice' ); ?>
				<input type="hidden" name="purpose" value="research_model_training">
				<input type="hidden" name="choice" value="withdraw">
				<input type="hidden" name="notice_uid" value="<?php echo esc_attr( (string) $form['notice_uid'] ); ?>">
				<input type="hidden" name="language" value="<?php echo esc_attr( (string) $form['language'] ); ?>">
				<input type="hidden" name="idempotency_key" value="<?php echo esc_attr( (string) $form['idempotency_key'] ); ?>">
				<input type="hidden" name="return_to" value="<?php echo esc_attr( (string) $form['return_to'] ); ?>">
				<button class="dzn-button dzn-button--secondary" type="submit">Withdraw participation · پس گرفتن مشارکت</button>
			</form>
		<?php else : ?>
			<form class="dzn-recording-choices__form" method="post" action="<?php echo esc_url( (string) $form['action_url'] ); ?>">
				<?php dzn_theme_nonce_field( 'dzn_recording_student_choice' ); ?>
				<input type="hidden" name="purpose" value="research_model_training">
				<input type="hidden" name="notice_uid" value="<?php echo esc_attr( (string) $form['notice_uid'] ); ?>">
				<input type="hidden" name="language" value="<?php echo esc_attr( (string) $form['language'] ); ?>">
				<input type="hidden" name="idempotency_key" value="<?php echo esc_attr( (string) $form['idempotency_key'] ); ?>">
				<input type="hidden" name="return_to" value="<?php echo esc_attr( (string) $form['return_to'] ); ?>">
				<fieldset>
					<legend>Optional research participation · مشارکت اختیاری در پژوهش</legend>
					<label><input type="radio" name="choice" value="opt_in"<?php echo 'account' === $context && 'opt_in' === $current_research ? ' checked' : ''; ?>> I want to participate in research <span lang="fa" dir="rtl">(می‌خواهم در پژوهش مشارکت کنم)</span></label>
					<label><input type="radio" name="choice" value="withdraw"<?php echo 'account' === $context && 'withdraw' === $current_research ? ' checked' : ''; ?>> No, thanks <span lang="fa" dir="rtl">(نه، ممنون)</span></label>
				</fieldset>
				<p class="dzn-portal-help">This choice is separate from lesson recording and is always optional. · این انتخاب جدا از ضبط کلاس و همیشه اختیاری است.</p>
				<button class="dzn-button" type="submit">Save research choice · ذخیرهٔ انتخاب پژوهش</button>
			</form>
		<?php endif; ?>
	<?php else : ?>
		<p class="dzn-portal-help" role="status">A current approved research notice is not published. Research participation cannot be changed here. · اطلاعیهٔ تأییدشدهٔ پژوهش در دسترس نیست.</p>
		<?php if ( 'enrolment' === $context ) : ?>
			<div class="dzn-recording-choices__draft" role="note"><p><strong>Draft product copy — not legally approved yet.</strong></p><p lang="en" dir="ltr"><?php echo esc_html( $provisional_research ); ?></p></div>
		<?php endif; ?>
	<?php endif; ?>
	<p><a href="<?php echo esc_url( $information_url ); ?>">Recording and research information · اطلاعات ضبط و پژوهش</a></p>
</section>
