<?php
/** First-time Teacher setup in the same account workspace used after activation. */
$model = $args['model'] ?? array();
$onboarding = (array) ( $model['onboarding'] ?? array() );
$profile_complete = 'complete' === (string) ( $onboarding['profile_state'] ?? '' );
$availability_complete = 'complete' === (string) ( $onboarding['availability_state'] ?? '' );
$state = (string) ( $onboarding['state'] ?? 'linked_pending' );
$ready = 'active' === $state && 'ready' === (string) ( $onboarding['readiness_state'] ?? '' );
$recording_terms = (array) ( $model['recording_terms'] ?? array() );
$consent_forms = (array) ( $recording_terms['forms'] ?? array() );
$pick_consent_form = static function ( array $forms ): array {
	foreach ( $forms as $form ) { if ( is_array( $form ) && 'fa' === (string) ( $form['language'] ?? '' ) ) { return $form; } }
	foreach ( $forms as $form ) { if ( is_array( $form ) ) { return $form; } }
	return array();
};
$terms_consent_form = $pick_consent_form( (array) ( $consent_forms['terms'] ?? array() ) );
$recording_consent_form = $pick_consent_form( (array) ( $consent_forms['recording'] ?? array() ) );
$consent_available = ! empty( $terms_consent_form ) && ! empty( $recording_consent_form );
$state_labels = array(
	'linked_pending' => 'در حال راه‌اندازی', 'in_progress' => 'در حال تکمیل', 'pending_review' => 'در انتظار بررسی مدیر',
	'returned' => 'نیازمند اصلاح', 'rejected' => 'پذیرفته نشد', 'active' => 'تأییدشده و فعال', 'offboarded' => 'غیرفعال شده',
);
$missing = array();
if ( ! $profile_complete ) { $missing[] = 'اطلاعات و منطقهٔ زمانی'; }
if ( ! $availability_complete ) { $missing[] = 'دست‌کم یک زمان واقعی برای تدریس'; }
?>

<section class="dzn-tp-section dzn-tp-account-setup" aria-labelledby="tp-onboarding">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">حساب مدرس دلنوازان</p><h2 id="tp-onboarding">راه‌اندازی حساب شما</h2></div>
		<span><?php echo esc_html( $state_labels[ $state ] ?? 'وضعیت نامشخص' ); ?></span>
	</div>
	<p>همهٔ اطلاعات حساب و زمان‌های تدریس در همین صفحه قرار دارد. موارد لازم را تکمیل کنید و در پایان برای بررسی مدیر بفرستید.</p>
	<div class="dzn-tp-setup-progress" role="list" aria-label="وضعیت راه‌اندازی حساب">
		<a class="<?php echo $profile_complete ? 'is-complete' : 'is-current'; ?>" href="#tp-profile" role="listitem"><span aria-hidden="true"><?php echo $profile_complete ? '✓' : '۱'; ?></span><strong>اطلاعات حساب</strong><small><?php echo $profile_complete ? 'کامل' : 'نیازمند تکمیل'; ?></small></a>
		<a class="<?php echo $availability_complete ? 'is-complete' : ( $profile_complete ? 'is-current' : 'is-pending' ); ?>" href="#tp-availability" role="listitem"><span aria-hidden="true"><?php echo $availability_complete ? '✓' : '۲'; ?></span><strong>زمان‌های تدریس</strong><small><?php echo $availability_complete ? 'کامل' : 'نیازمند تکمیل'; ?></small></a>
	</div>
	<p class="dzn-tp-help">کلاس‌ها به‌صورت متمرکز توسط دلنوازان در Google Meet ساخته می‌شوند و نیازی به اتصال حساب Google شما نیست.</p>
	<?php if ( isset( $_GET['dzn_notice'] ) ) : ?><p class="dzn-tp-notice <?php echo ( $_GET['dzn_error'] ?? '' ) === '1' ? 'is-error' : 'is-success'; ?>" role="status"><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['dzn_notice'] ) ) ); ?></p><?php endif; ?>
	<?php if ( in_array( $state, array( 'returned', 'rejected' ), true ) ) : ?><div class="dzn-tp-review-note is-warning"><strong>یادداشت بررسی</strong><p><?php echo esc_html( (string) ( $onboarding['review_note'] ?? 'اطلاعات برای اصلاح برگردانده شده است.' ) ); ?></p></div><?php endif; ?>
</section>

<?php if ( ! $ready ) : ?>
	<?php dzn_theme_teacher_portal_component( 'account', array( 'model' => $model, 'show_recording_terms' => false ) ); ?>
	<?php dzn_theme_teacher_portal_component( 'recording-terms', array( 'recording' => $model['recording_terms'] ?? array() ) ); ?>
	<?php if ( $availability_complete ) : ?>
	<section class="dzn-tp-section" aria-labelledby="tp-copy-day">
		<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">میان‌بر برنامهٔ هفتگی</p><h2 id="tp-copy-day">کپی زمان‌های یک روز</h2></div></div>
		<form class="dzn-tp-form" method="post" action="<?php echo esc_url( $model['actions']['copy_day'] ?? '' ); ?>"><?php dzn_theme_nonce_field( 'dzn_teacher_onboarding_copy_day' ); ?>
			<label>روز مبدأ<select name="source_weekday"><?php foreach ( array( 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه', 7 => 'یکشنبه' ) as $day => $label ) : ?><option value="<?php echo (int) $day; ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<fieldset><legend>روزهای مقصد</legend><?php foreach ( array( 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه', 7 => 'یکشنبه' ) as $day => $label ) : ?><label><input type="checkbox" name="target_weekdays[]" value="<?php echo (int) $day; ?>"> <?php echo esc_html( $label ); ?></label> <?php endforeach; ?></fieldset>
			<button class="dzn-button dzn-button--secondary" type="submit">کپی به روزهای مقصد</button>
		</form>
	</section>
	<?php endif; ?>
	<section class="dzn-tp-section dzn-tp-account-submit" aria-labelledby="tp-submit">
		<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">مرحلهٔ نهایی</p><h2 id="tp-submit">ارسال برای بررسی مدیر</h2></div></div>
		<?php if ( $missing ) : ?><div class="dzn-tp-review-note is-warning"><strong>هنوز باید تکمیل شود</strong><ul><?php foreach ( $missing as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
		<?php if ( ! empty( $onboarding['can_submit'] ) ) : ?>
			<?php if ( $consent_available ) : ?>
				<form method="post" action="<?php echo esc_url( $model['actions']['submit'] ?? '' ); ?>" data-dzn-tp-recording-consent data-dzn-tp-consent-key="dzn-teacher-onboarding-recording-<?php echo (int) ( $onboarding['teacher_id'] ?? 0 ); ?>">
					<?php dzn_theme_nonce_field( 'dzn_teacher_onboarding_submit' ); ?>
					<input type="hidden" name="terms_notice_uid" value="<?php echo esc_attr( (string) $terms_consent_form['notice_uid'] ); ?>"><input type="hidden" name="terms_language" value="<?php echo esc_attr( (string) $terms_consent_form['language'] ); ?>"><input type="hidden" name="recording_notice_uid" value="<?php echo esc_attr( (string) $recording_consent_form['notice_uid'] ); ?>"><input type="hidden" name="recording_language" value="<?php echo esc_attr( (string) $recording_consent_form['language'] ); ?>"><input type="hidden" name="recording_consent_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
					<label class="dzn-tp-recording-consent"><input type="checkbox" name="recording_consent_accepted" value="1" checked> با <a href="<?php echo esc_url( home_url( '/terms-and-conditions/' ) ); ?>">شرایط همکاری</a>، <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">حریم خصوصی</a>، <a href="<?php echo esc_url( home_url( '/recording-and-ai-policy/' ) ); ?>">سیاست ضبط و هوش مصنوعی</a> و <a href="<?php echo esc_url( home_url( '/recording-and-research/' ) ); ?>">اطلاعات پژوهش</a> موافقم.</label>
					<p class="dzn-tp-help">نسخهٔ شرایط و زمان پذیرش همراه درخواست شما ثبت می‌شود.</p><button class="dzn-button" type="submit">ارسال برای بررسی مدیر</button>
				</form>
			<?php else : ?><p class="dzn-tp-notice is-warning">شرایط ضبطِ قابل ثبت در دسترس نیست؛ تا انتشار نسخهٔ فعلی، امکان ارسال درخواست وجود ندارد.</p><?php endif; ?>
		<?php elseif ( 'pending_review' === $state ) : ?>
			<p class="dzn-tp-notice is-success">اطلاعات شما ارسال شده است. پس از بررسی مدیر، دسترسی کامل پرتال فعال می‌شود.</p>
		<?php elseif ( ! $missing ) : ?>
			<p class="dzn-tp-notice is-warning">اطلاعات کامل است، اما ارسال در وضعیت فعلی در دسترس نیست. با مدیر آموزشگاه تماس بگیرید.</p>
		<?php else : ?><p class="dzn-tp-help">پس از تکمیل موارد بالا، دکمهٔ ارسال فعال می‌شود.</p><?php endif; ?>
	</section>
<?php else : ?>
	<section class="dzn-tp-section dzn-tp-status-card is-approved" aria-labelledby="tp-active">
		<h2 id="tp-active">حساب شما فعال است</h2><p>راه‌اندازی کامل شده و نسخهٔ جدید پرتال مدرس آمادهٔ استفاده است.</p>
		<p><a class="dzn-button" href="<?php echo esc_url( home_url( '/teacher-portal/' ) ); ?>">رفتن به پرتال مدرس</a></p>
	</section>
<?php endif; ?>
