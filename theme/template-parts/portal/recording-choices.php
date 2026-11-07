<?php
/**
 * Student unified consent: recording, research/AI and privacy in a single tick.
 *
 * Only renders when the authority has resolved one active student/guardian link and current
 * published notices exist. No decision is written by rendering.
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
$recording_state = isset( $recording['recording'] ) && is_array( $recording['recording'] ) ? $recording['recording'] : array();
$information_url = (string) ( $recording['information_url'] ?? home_url( '/recording-and-research/' ) );
$authority = (string) ( $recording['authority'] ?? '' );
$status = sanitize_key( (string) ( $recording['status'] ?? '' ) );
$messages = array(
	'unified_saved' => array( 'success', 'رضایت شما ذخیره شد. ضبط کلاس، پژوهش و حریم خصوصی پذیرفته شد.' ),
	'unified_skipped' => array( 'success', 'رضایت ثبت نشد. ضبط و پژوهش اختیاری است.' ),
	'unified_withdrawn' => array( 'success', 'رضایت شما پس گرفته شد. ضبط و پژوهش جدید متوقف شد.' ),
	'recording_saved' => array( 'success', 'انتخاب ضبط کلاس شما ذخیره شد.' ),
	'recording_declined' => array( 'success', 'کلاس‌های شما ضبط نخواهد شد.' ),
	'research_saved' => array( 'success', 'انتخاب پژوهش شما ذخیره شد.' ),
	'research_withdrawn' => array( 'success', 'مشارکت در پژوهش پس گرفته شد.' ),
	'privacy_saved' => array( 'success', 'تأیید حریم خصوصی شما ذخیره شد.' ),
	'recording_unavailable' => array( 'error', 'انتخاب ذخیره نشد. هیچ اجازهٔ ضبطی ثبت نشد.' ),
);
$unified = isset( $recording['unified'] ) && is_array( $recording['unified'] ) ? $recording['unified'] : array();
$consent_active = ! empty( $recording['consent_active'] ) || ! empty( $unified['consent_active'] );
$policy_urls = isset( $recording['policy_urls'] ) && is_array( $recording['policy_urls'] ) ? $recording['policy_urls'] : array(
	'privacy' => home_url( '/privacy-policy/' ),
	'recording_ai' => home_url( '/recording-and-ai-policy/' ),
	'terms' => home_url( '/terms-and-conditions/' ),
	'recording_research' => home_url( '/recording-and-research/' ),
);
$unified_available = ! empty( $unified['available'] );
$format_date = static function ( string $value ): string {
	if ( '' === $value ) { return ''; }
	$timestamp = strtotime( $value . ' UTC' );
	return $timestamp ? wp_date( 'Y-m-d H:i', $timestamp ) : $value;
};
?>
<section class="dzn-portal-section dzn-recording-choices dzn-recording-choices--compact" id="dzn-recording-choices" aria-labelledby="dzn-recording-choices-title">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php echo 'enrolment' === $context ? 'پیش از تکمیل ثبت‌نام' : 'حریم خصوصی و رضایت'; ?></p>
			<h2 id="dzn-recording-choices-title"><?php esc_html_e( 'ضبط کلاس، پژوهش و حریم خصوصی', 'delnavazan-theme' ); ?></h2>
			<p class="dzn-portal-help"><?php esc_html_e( 'این رضایت اختیاری است و هر زمان می‌توانید آن را در تنظیمات حساب پس بگیرید.', 'delnavazan-theme' ); ?></p>
		</div>
	</div>
	<?php if ( 'guardian' === $authority ) : ?>
		<p class="dzn-portal-help" role="status"><strong>تصمیم از سوی سرپرست دارای اختیار ثبت می‌شود.</strong> این حساب برای هنرجوی زیر ۱۸ سال به یک اختیار سرپرستی فعال متصل است.</p>
	<?php endif; ?>
	<?php if ( isset( $messages[ $status ] ) ) : ?>
		<?php $message = $messages[ $status ]; ?>
		<p class="dzn-portal-action-status<?php echo 'error' === $message[0] ? ' is-error' : ''; ?>" role="<?php echo 'error' === $message[0] ? 'alert' : 'status'; ?>"><span lang="fa" dir="rtl"><?php echo esc_html( $message[1] ); ?></span></p>
	<?php endif; ?>
	<?php if ( ! $available ) : ?>
		<p class="dzn-portal-help" role="status">انتخاب ضبط و پژوهش برای این حساب در دسترس نیست. هیچ تصمیمی دربارهٔ ضبط ثبت نشده است.</p>
		<p><a href="<?php echo esc_url( $information_url ); ?>" target="_blank" rel="noopener">اطلاعات ضبط و پژوهش</a></p>
		<?php return; ?>
	<?php endif; ?>
	<?php if ( $unified_available ) : ?>
		<?php if ( $consent_active ) : ?>
			<p class="dzn-portal-help" role="status"><strong>رضایت شما ثبت شده است.</strong> ضبط و پژوهش فعال است<?php echo ! empty( $recording_state['decided_at'] ) ? ' · ' . esc_html( $format_date( (string) $recording_state['decided_at'] ) ) : ''; ?>. · Consent saved. You can withdraw it anytime below.</p>
		<?php else : ?>
			<form class="dzn-recording-choices__form" method="post" action="<?php echo esc_url( (string) ( $unified['action_url'] ?? '' ) ); ?>">
				<?php dzn_theme_nonce_field( 'dzn_unified_student_consent' ); ?>
				<input type="hidden" name="idempotency_key" value="<?php echo esc_attr( (string) ( $unified['idempotency_key'] ?? '' ) ); ?>">
				<input type="hidden" name="return_to" value="<?php echo esc_attr( (string) ( $unified['return_to'] ?? 'account' ) ); ?>">
				<label class="dzn-unified-consent"><input type="checkbox" name="unified_agree" value="1" checked> <span>I accept the <a href="<?php echo esc_url( $policy_urls['privacy'] ); ?>" target="_blank" rel="noopener">Privacy Policy</a>, <a href="<?php echo esc_url( $policy_urls['terms'] ); ?>" target="_blank" rel="noopener">Terms and Conditions</a>, and the <a href="<?php echo esc_url( $policy_urls['recording_ai'] ); ?>" target="_blank" rel="noopener">Recording &amp; AI Policy</a> — my classes may be recorded and used for teaching quality, research and AI model training. Please read them fully before continuing. · <span lang="fa" dir="rtl">سیاست حریم خصوصی، شرایط و ضوابط و سیاست ضبط و هوش مصنوعی را می‌پذیرم؛ کلاس‌هایم ممکن است ضبط شوند و برای بهبود کیفیت آموزش، پژوهش و آموزش مدل هوش مصنوعی استفاده شوند. لطفاً پیش از ادامه آن‌ها را کامل بخوانید. این رضایت اختیاری است.</span></span></label>
				<p class="dzn-portal-help">این رضایت اختیاری است؛ بدون آن هم ثبت‌نام شما ادامه پیدا می‌کند، اما ضبط و پژوهش انجام نمی‌شود. · This consent is optional; uncheck it to continue without recording.</p>
				<button class="dzn-button" type="submit">I agree · موافقم</button>
			</form>
		<?php endif; ?>
	<?php else : ?>
		<p class="dzn-portal-help" role="status">ثبت رضایت در دسترس نیست؛ هیچ سابقه‌ای ساخته نمی‌شود.</p>
	<?php endif; ?>
	<?php if ( 'account' === $context && $consent_active && $unified_available ) : ?>
		<hr>
		<h3 class="dzn-recording-choices__subhead">پس گرفتن رضایت</h3>
		<form class="dzn-recording-choices__form" method="post" action="<?php echo esc_url( (string) ( $unified['withdraw_action_url'] ?? '' ) ); ?>">
			<?php dzn_theme_nonce_field( 'dzn_unified_student_withdraw' ); ?>
			<input type="hidden" name="idempotency_key" value="<?php echo esc_attr( (string) ( $unified['withdraw_idempotency_key'] ?? '' ) ); ?>">
			<input type="hidden" name="return_to" value="account">
			<p class="dzn-portal-help">با پس گرفتن رضایت، ضبط کلاس و پژوهش آینده متوقف می‌شود و داده‌های پژوهش شما قرنطینه می‌شوند. این کار اختیاری است و ثبت‌نام شما را لغو نمی‌کند.</p>
			<button class="dzn-button dzn-button--secondary" type="submit">پس گرفتن رضایت · Withdraw consent</button>
		</form>
	<?php endif; ?>
	<p><a href="<?php echo esc_url( $information_url ); ?>" target="_blank" rel="noopener">اطلاعات ضبط و پژوهش</a></p>
</section>
